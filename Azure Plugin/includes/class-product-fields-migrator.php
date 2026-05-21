<?php
/**
 * Product Fields – legacy data consolidation
 *
 * Reads the live `wp_woocommerce_order_itemmeta` table (and any equivalent
 * line-item meta added by historic plugins like Product Input Fields), groups
 * the visible labels by frequency, and lets the admin map each variation to a
 * canonical `field_key` defined in `wp_azure_product_fields`.
 *
 * On Apply: rewrites order item meta to add the canonical `_pta_<field_key>`
 * key (the human label is preserved), and copies child-scope values into
 * `azure_user_children_meta` when the line item identifies a child.
 *
 * Dry-run is the default. Old labels are NEVER deleted by this tool — they
 * are kept until you remove the Product Input Fields plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Product_Fields_Migrator {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!is_admin()) {
            return;
        }
        add_action('wp_ajax_azure_pf_mig_scan',  array($this, 'ajax_scan'));
        add_action('wp_ajax_azure_pf_mig_apply', array($this, 'ajax_apply'));
    }

    /**
     * Scan the order itemmeta table for distinct visible meta keys (i.e. those
     * not starting with `_`) and return per-key counts plus a sample value.
     *
     * @return array<int, array{key:string,count:int,sample:string}>
     */
    public function scan_legacy_keys() {
        global $wpdb;

        $itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $itemmeta)) !== $itemmeta) {
            return array();
        }

        $rows = $wpdb->get_results(
            "SELECT meta_key, COUNT(*) AS cnt
             FROM {$itemmeta}
             WHERE meta_key NOT LIKE '\\_%'
               AND meta_key <> ''
             GROUP BY meta_key
             ORDER BY cnt DESC
             LIMIT 500"
        );

        if (empty($rows)) {
            return array();
        }

        $out = array();
        foreach ($rows as $r) {
            $sample = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$itemmeta} WHERE meta_key = %s AND meta_value <> '' LIMIT 1",
                $r->meta_key
            ));
            $out[] = array(
                'key'    => $r->meta_key,
                'count'  => (int) $r->cnt,
                'sample' => mb_substr($sample, 0, 80),
            );
        }
        return $out;
    }

    /**
     * Return the canonical field definitions admin can map TO.
     */
    public function get_canonical_fields() {
        global $wpdb;
        $fld_table = Azure_Database::get_table_name('product_fields');
        if (!$fld_table) {
            return array();
        }
        $rows = $wpdb->get_results(
            "SELECT id, label, field_key, scope FROM {$fld_table}
             WHERE field_key <> ''
             ORDER BY scope, label ASC"
        );
        return is_array($rows) ? $rows : array();
    }

    /**
     * Apply a consolidation map. Each entry is:
     *   legacy_label => array('field_key' => string, 'scope' => 'child|parent')
     *
     * Two-pass design so child-scope writes (EpiPen, Grade, etc.) can be
     * attached to the right child even on legacy multi-child orders:
     *
     *   Pass 1: walk every legacy label mapped to `child_name` (scope=child)
     *           and build  order_item_id => child_id  by find-or-create on
     *           (parent_user_id, name).
     *   Pass 2: for every other mapping, look up child_id in the Pass 1 map
     *           (child-scope) or write directly to the parent's user meta
     *           (parent-scope). Parent identity comes from the order's user
     *           id, falling back to the billing email.
     *
     * In both passes, the canonical `_pta_<field_key>` line-item meta is
     * inserted alongside the existing legacy label (legacy is never deleted).
     *
     * @param array $map
     * @param bool  $dry_run
     * @return array Summary counts + per-mapping detail.
     */
    public function apply_map(array $map, $dry_run = true) {
        $map = $this->sanitize_map($map);
        if (empty($map)) {
            return $this->empty_summary($dry_run);
        }

        // Per-run cache so the same parent isn't re-queried for every line.
        $parent_cache    = array();
        $resolved_child  = array();   // order_item_id => child_id
        $children_created = 0;
        $children_matched = 0;

        // ── Pass 1: resolve which child each line item belongs to ────────
        $child_name_labels = array();
        foreach ($map as $legacy => $entry) {
            if ($entry['scope'] === 'child' && $entry['field_key'] === 'child_name') {
                $child_name_labels[] = $legacy;
            }
        }

        if (!empty($child_name_labels) && class_exists('Azure_User_Children')) {
            $name_rows = $this->fetch_legacy_rows($child_name_labels);
            foreach ($name_rows as $row) {
                $parent_id = $this->resolve_parent_user_id((int) $row->order_id, $parent_cache);
                if (!$parent_id) {
                    continue;
                }
                $name = trim((string) $row->meta_value);
                if ($name === '') {
                    continue;
                }
                $existing = Azure_User_Children::find_child_by_name($parent_id, $name);
                if ($existing) {
                    $resolved_child[(int) $row->order_item_id] = (int) $existing->id;
                    $children_matched++;
                    continue;
                }
                if ($dry_run) {
                    // Mark resolvable but do not create yet; subsequent
                    // child-scope writes will count toward `would_write`.
                    $resolved_child[(int) $row->order_item_id] = -1;
                    $children_created++;
                } else {
                    $new_id = (int) Azure_User_Children::save_child($parent_id, array(
                        'child_name' => $name,
                    ));
                    if ($new_id > 0) {
                        $resolved_child[(int) $row->order_item_id] = $new_id;
                        $children_created++;
                    }
                }
            }
        }

        // ── Pass 2: write the canonical line-item meta + profile writes ──
        $summary = $this->empty_summary($dry_run);
        $summary['children_created'] = $children_created;
        $summary['children_matched'] = $children_matched;

        foreach ($map as $legacy_label => $entry) {
            $field_key = $entry['field_key'];
            $scope     = $entry['scope'];
            $canonical = '_pta_' . $field_key;

            $rows = $this->fetch_legacy_rows(array($legacy_label));

            $detail = array(
                'legacy_label'       => $legacy_label,
                'field_key'          => $field_key,
                'scope'              => $scope,
                'rows'               => count($rows),
                'meta_added'         => 0,
                'parent_meta_writes' => 0,
                'child_meta_writes'  => 0,
                'unresolvable'       => 0,
                'unresolvable_items' => array(),
            );

            foreach ($rows as $row) {
                $summary['items_touched']++;
                $value = (string) $row->meta_value;
                $oi_id = (int) $row->order_item_id;

                // Always inject the canonical line-item meta if it's missing.
                if ($this->insert_canonical_meta($oi_id, $canonical, $value, $dry_run)) {
                    $detail['meta_added']++;
                    $summary['meta_added']++;
                }

                if ($scope === 'parent') {
                    $parent_id = $this->resolve_parent_user_id((int) $row->order_id, $parent_cache);
                    if ($parent_id) {
                        if (!$dry_run) {
                            update_user_meta($parent_id, 'pta_pf_' . $field_key, $value);
                        }
                        $detail['parent_meta_writes']++;
                        $summary['parent_meta_writes']++;
                    } else {
                        $detail['unresolvable']++;
                        $summary['unresolvable']++;
                        if (count($detail['unresolvable_items']) < 20) {
                            $detail['unresolvable_items'][] = $oi_id;
                        }
                    }
                    continue;
                }

                // scope === 'child'
                $child_id = isset($resolved_child[$oi_id]) ? (int) $resolved_child[$oi_id] : 0;
                if ($child_id > 0 && !$dry_run && class_exists('Azure_User_Children')) {
                    Azure_User_Children::update_child_meta($child_id, array(
                        'pta_pf_' . $field_key => $value,
                    ));
                }
                if ($child_id !== 0) {
                    // child_id > 0 (real) or -1 (dry-run resolvable)
                    $detail['child_meta_writes']++;
                    $summary['child_meta_writes']++;
                } else {
                    $detail['unresolvable']++;
                    $summary['unresolvable']++;
                    if (count($detail['unresolvable_items']) < 20) {
                        $detail['unresolvable_items'][] = $oi_id;
                    }
                }
            }

            $summary['mappings'][] = $detail;

            if (!$dry_run && class_exists('Azure_Logger')) {
                Azure_Logger::info(sprintf(
                    'Product Fields consolidation: "%s" -> %s (scope=%s) — %d rows, %d parent / %d child meta writes, %d unresolvable',
                    $legacy_label, $field_key, $scope,
                    $detail['rows'], $detail['parent_meta_writes'], $detail['child_meta_writes'], $detail['unresolvable']
                ), array('module' => 'ProductFields'));
            }
        }

        return $summary;
    }

    /**
     * Resolve the parent (WP user id) for an order. Prefers the linked user,
     * falling back to a billing-email lookup for guest checkouts. Cached per
     * apply_map() invocation so the same lookup isn't repeated for every line.
     */
    private function resolve_parent_user_id($order_id, array &$cache) {
        if (isset($cache[$order_id])) {
            return $cache[$order_id];
        }
        $cache[$order_id] = 0;

        $order = wc_get_order($order_id);
        if (!$order) {
            return 0;
        }
        $user_id = (int) $order->get_user_id();
        if ($user_id > 0) {
            return $cache[$order_id] = $user_id;
        }

        $email = $order->get_billing_email();
        if (!empty($email)) {
            $user = get_user_by('email', $email);
            if ($user) {
                return $cache[$order_id] = (int) $user->ID;
            }
        }

        return 0;
    }

    /**
     * Fetch every line-item row whose meta_key matches one of the supplied
     * legacy labels and that does not yet have a canonical _pta_* twin for
     * the same item (we still want to attempt profile writes for already-
     * canonicalized rows; that filtering happens in `insert_canonical_meta`).
     */
    private function fetch_legacy_rows(array $labels) {
        global $wpdb;
        if (empty($labels)) {
            return array();
        }
        $itemmeta   = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $orderitems = $wpdb->prefix . 'woocommerce_order_items';
        $placeholders = implode(',', array_fill(0, count($labels), '%s'));

        $sql = $wpdb->prepare(
            "SELECT m.order_item_id, m.meta_value, oi.order_id
             FROM {$itemmeta} m
             INNER JOIN {$orderitems} oi ON oi.order_item_id = m.order_item_id
             WHERE m.meta_key IN ({$placeholders})
               AND m.meta_value <> ''",
            ...$labels
        );
        $rows = $wpdb->get_results($sql);
        return is_array($rows) ? $rows : array();
    }

    /**
     * Insert _pta_<field_key> for an order item if one is not already present.
     * Returns true when (or would have) inserted a row.
     */
    private function insert_canonical_meta($order_item_id, $canonical_meta_key, $value, $dry_run) {
        global $wpdb;
        $itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';

        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$itemmeta} WHERE order_item_id = %d AND meta_key = %s",
            $order_item_id,
            $canonical_meta_key
        ));
        if ($exists > 0) {
            return false;
        }
        if (!$dry_run) {
            $wpdb->insert($itemmeta, array(
                'order_item_id' => $order_item_id,
                'meta_key'      => $canonical_meta_key,
                'meta_value'    => $value,
            ));
        }
        return true;
    }

    private function sanitize_map(array $map) {
        $out = array();
        foreach ($map as $legacy => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $legacy = (string) $legacy;
            $field_key = isset($entry['field_key']) ? sanitize_key($entry['field_key']) : '';
            $scope = (isset($entry['scope']) && $entry['scope'] === 'parent') ? 'parent' : 'child';
            if ($legacy === '' || $field_key === '') {
                continue;
            }
            $out[$legacy] = array('field_key' => $field_key, 'scope' => $scope);
        }
        return $out;
    }

    private function empty_summary($dry_run) {
        return array(
            'dry_run'            => $dry_run,
            'mappings'           => array(),
            'items_touched'      => 0,
            'meta_added'         => 0,
            'parent_meta_writes' => 0,
            'child_meta_writes'  => 0,
            'children_created'   => 0,
            'children_matched'   => 0,
            'unresolvable'       => 0,
        );
    }

    // ─── AJAX endpoints ────────────────────────────────────────────────

    public function ajax_scan() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        wp_send_json_success(array(
            'legacy'    => $this->scan_legacy_keys(),
            'canonical' => $this->get_canonical_fields(),
        ));
    }

    public function ajax_apply() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $dry_run = !isset($_POST['apply']) || $_POST['apply'] !== '1';
        $raw_map = isset($_POST['map']) && is_array($_POST['map']) ? $_POST['map'] : array();

        $map = array();
        foreach ($raw_map as $legacy => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $field_key = isset($entry['field_key']) ? sanitize_key($entry['field_key']) : '';
            $scope     = isset($entry['scope']) && $entry['scope'] === 'parent' ? 'parent' : 'child';
            if ($field_key === '') {
                continue;
            }
            $map[(string) $legacy] = array(
                'field_key' => $field_key,
                'scope'     => $scope,
            );
        }

        if (empty($map)) {
            wp_send_json_error('No valid mappings supplied');
        }

        $result = $this->apply_map($map, $dry_run);
        wp_send_json_success($result);
    }
}
