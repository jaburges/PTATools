<?php
/**
 * User Children Profiles
 *
 * Allows parents to maintain child profiles that auto-populate
 * Product Fields at checkout. Children are saved/updated automatically
 * from completed orders and can be managed via My Account.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_User_Children {

    private static $instance = null;

    /** @var array<int, array<object>> Per-request memo for get_children_for_user(). */
    private static $children_for_user_cache = array();

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!class_exists('WooCommerce')) {
            return;
        }
        $this->init_hooks();
    }

    private function init_hooks() {
        // My Account tab
        add_filter('woocommerce_account_menu_items', array($this, 'add_my_account_tab'));
        add_action('woocommerce_account_profile_endpoint', array($this, 'render_my_account_page'));
        add_action('woocommerce_account_my-children_endpoint', array($this, 'render_my_account_page'));
        // Endpoint registration is done via the static helper below — see
        // bootstrap_endpoints() — so it fires regardless of when the
        // singleton is constructed during the init action.

        // AJAX handlers (logged-in users)
        add_action('wp_ajax_azure_uc_get_children', array($this, 'ajax_get_children'));
        add_action('wp_ajax_azure_uc_save_child', array($this, 'ajax_save_child'));
        add_action('wp_ajax_azure_uc_delete_child', array($this, 'ajax_delete_child'));
        add_action('wp_ajax_azure_uc_get_child_meta', array($this, 'ajax_get_child_meta'));
        add_action('wp_ajax_azure_uc_save_profile', array($this, 'ajax_save_profile'));
        add_action('wp_ajax_azure_uc_save_family', array($this, 'ajax_save_family'));

        // Frontend assets on product pages and My Account
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));

        // Auto-save child from completed orders
        add_action('woocommerce_order_status_completed', array($this, 'auto_save_from_order'), 20);
        add_action('woocommerce_payment_complete', array($this, 'auto_save_from_order'), 20);
    }

    /**
     * Register the WC My Account endpoints used by this module.
     *
     * Called directly from the main plugin's init() method (priority 10,
     * mainline — not via a nested add_action) so the endpoint is always
     * present in the live rewrite globals before any flush runs.
     *
     * Performance: `add_rewrite_endpoint` is a single registration into
     * a singleton; cheap enough to run on every request.
     */
    public static function bootstrap_endpoints() {
        add_rewrite_endpoint('profile', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('my-children', EP_ROOT | EP_PAGES);

        // Self-heal: if the persisted rewrite_rules option doesn't yet
        // include the `profile` endpoint pattern, schedule one flush at
        // wp_loaded (priority 999, after every plugin's init has fired).
        // Gated by an option so subsequent requests pay nothing.
        if (get_option('azure_pf_profile_endpoint_flushed') !== 'yes') {
            $rules = get_option('rewrite_rules');
            $needs_flush = true;
            if (is_array($rules)) {
                foreach (array_keys($rules) as $pattern) {
                    if (strpos($pattern, '/profile') !== false) {
                        $needs_flush = false;
                        break;
                    }
                }
            }
            if ($needs_flush) {
                add_action('wp_loaded', array(__CLASS__, 'flush_and_mark'), 999);
            } else {
                update_option('azure_pf_profile_endpoint_flushed', 'yes', false);
            }
        }
    }

    public static function flush_and_mark() {
        flush_rewrite_rules(false);
        update_option('azure_pf_profile_endpoint_flushed', 'yes', false);
    }

    // ─── Data Access ───────────────────────────────────────────────────

    /**
     * Family-aware child lookup. Returns every child attached to any
     * connected_family the user is a member of (primary or secondary).
     * Falls back to the legacy `user_id` column for installs that haven't
     * been backfilled yet.
     *
     * Per-request memoized — multiple callers on the same pageload share one
     * query. Resource-efficiency rule: avoids N+1 on My Account + product page
     * loads when both render path callers ask for the same user's children.
     *
     * @param int $user_id
     * @return array<object>
     */
    public static function get_children_for_user($user_id) {
        $user_id = (int) $user_id;
        if (!$user_id) {
            return array();
        }
        if (isset(self::$children_for_user_cache[$user_id])) {
            return self::$children_for_user_cache[$user_id];
        }

        global $wpdb;
        $children_table = Azure_Database::get_table_name('user_children');
        $family_table   = Azure_Database::get_table_name('connected_family');
        if (!$children_table) {
            return array();
        }

        if ($family_table) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT c.* FROM {$children_table} c
                 INNER JOIN {$family_table} f ON c.family_id = f.id
                 WHERE (f.primary_user_id = %d OR f.secondary_user_id = %d)
                   AND c.is_active = 1
                 ORDER BY c.child_name ASC",
                $user_id, $user_id
            ));
            // Augment with legacy rows that haven't been family-backfilled yet
            // (defensive — backfill normally covers these on plugin upgrade).
            $legacy = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$children_table}
                 WHERE user_id = %d AND family_id = 0 AND is_active = 1
                 ORDER BY child_name ASC",
                $user_id
            ));
            self::$children_for_user_cache[$user_id] = array_merge((array) $rows, (array) $legacy);
        } else {
            self::$children_for_user_cache[$user_id] = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$children_table} WHERE user_id = %d AND is_active = 1 ORDER BY child_name ASC",
                $user_id
            ));
        }

        return self::$children_for_user_cache[$user_id];
    }

    /**
     * Drop the per-request children memo so a save/delete is visible
     * to the Parent/Alumni role sync on the same request.
     *
     * @param int $user_id 0 clears every cached user.
     */
    public static function clear_children_cache($user_id = 0) {
        if ($user_id) {
            unset(self::$children_for_user_cache[(int) $user_id]);
            return;
        }
        self::$children_for_user_cache = array();
    }

    /**
     * Known child-grade meta keys. The live site uses pta_pf_childsgrade;
     * the others are legacy aliases from earlier field slugs.
     *
     * @return string[]
     */
    public static function child_grade_meta_keys() {
        return array(
            'pta_pf_childsgrade',
            'pta_pf_child_grade',
            'pta_pf_childs_grade',
            'childsgrade',
            'child_grade',
        );
    }

    /**
     * Known child-teacher meta keys.
     *
     * @return string[]
     */
    public static function child_teacher_meta_keys() {
        return array(
            'pta_pf_child_teacher',
            'pta_pf_childsteacher',
            'pta_pf_teacher',
            'child_teacher',
            'teacher',
        );
    }

    /**
     * First non-empty grade value from a child meta map.
     *
     * @param array $meta
     * @return string
     */
    public static function grade_from_meta($meta) {
        if (!is_array($meta)) {
            return '';
        }
        foreach (self::child_grade_meta_keys() as $key) {
            if (!empty($meta[$key])) {
                return trim((string) $meta[$key]);
            }
        }
        return '';
    }

    /**
     * First non-empty teacher value from a child meta map.
     *
     * @param array $meta
     * @return string
     */
    public static function teacher_from_meta($meta) {
        if (!is_array($meta)) {
            return '';
        }
        foreach (self::child_teacher_meta_keys() as $key) {
            if (!empty($meta[$key])) {
                return trim((string) $meta[$key]);
            }
        }
        return '';
    }

    /**
     * Family membership auto-includes PreK–5 and ungraded children.
     * Past-5th (alumni) kids are left off.
     *
     * @param array $meta
     * @return bool
     */
    public static function include_on_family_membership($meta) {
        $grade = self::grade_from_meta(is_array($meta) ? $meta : array());
        if ($grade === '') {
            return true;
        }
        return !self::is_alumni_grade($grade);
    }

    /**
     * True when a stored grade is PreK through 5th.
     *
     * @param mixed $grade
     * @return bool
     */
    public static function is_elementary_grade($grade) {
        if ($grade === null) {
            return false;
        }
        $normalized = strtoupper(trim((string) $grade));
        if ($normalized === '') {
            return false;
        }
        $normalized = preg_replace('/[\s\-]+/', '', $normalized);
        if ($normalized === 'K' || $normalized === 'PREK' || $normalized === 'PREK|K') {
            return true;
        }
        return (bool) preg_match('/^[0-5](TH)?$/', $normalized);
    }

    /**
     * True when a stored grade is past 5th (6, 6th, 7, …).
     *
     * @param mixed $grade
     * @return bool
     */
    public static function is_alumni_grade($grade) {
        if ($grade === null) {
            return false;
        }
        $normalized = strtoupper(trim((string) $grade));
        if ($normalized === '') {
            return false;
        }
        $normalized = preg_replace('/[\s\-]+/', '', $normalized);
        if (!preg_match('/^(\d+)(ST|ND|RD|TH)?$/', $normalized, $m)) {
            return false;
        }
        return ((int) $m[1]) > 5;
    }

    /**
     * Parent = any PreK–5 grade. Alumni = only past-5th grades (blanks ignored).
     * Null when nothing classifiable is present.
     *
     * @param array $grades
     * @return string|null 'parent'|'alumni'|null
     */
    public static function classify_population_from_grades($grades) {
        $has_k5 = false;
        $has_alumni = false;
        foreach ((array) $grades as $grade) {
            if (self::is_elementary_grade($grade)) {
                $has_k5 = true;
            } elseif (self::is_alumni_grade($grade)) {
                $has_alumni = true;
            }
        }
        if ($has_k5) {
            return 'parent';
        }
        if ($has_alumni) {
            return 'alumni';
        }
        return null;
    }

    /**
     * True when any known grade meta key on a child is PreK–5.
     *
     * @param array $meta
     * @return bool
     */
    public static function child_meta_has_elementary_grade($meta) {
        if (!is_array($meta)) {
            return false;
        }
        foreach (self::child_grade_meta_keys() as $key) {
            if (isset($meta[$key]) && self::is_elementary_grade($meta[$key])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Family-aware: the user qualifies if any active child on their
     * connected family is in PreK–5.
     *
     * @param int $user_id
     * @return bool
     */
    public static function user_has_k5_child($user_id) {
        foreach (self::get_children_for_user($user_id) as $child) {
            if (empty($child->id)) {
                continue;
            }
            if (self::child_meta_has_elementary_grade(self::get_child_meta($child->id))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Classify a user from their family's active children.
     *
     * @param int $user_id
     * @return string|null 'parent'|'alumni'|null
     */
    public static function classify_user_population($user_id) {
        $grades = array();
        foreach (self::get_children_for_user($user_id) as $child) {
            if (empty($child->id)) {
                continue;
            }
            $meta = self::get_child_meta($child->id);
            foreach (self::child_grade_meta_keys() as $key) {
                if (isset($meta[$key])) {
                    $grades[] = $meta[$key];
                }
            }
        }
        return self::classify_population_from_grades($grades);
    }

    /**
     * Family-aware single-child lookup. When a $user_id is supplied, the
     * lookup additionally checks that the child belongs to any family the
     * user is a member of (primary or secondary). The legacy `user_id ==
     * $user_id` path remains for back-compat.
     */
    public static function get_child($child_id, $user_id = null) {
        global $wpdb;
        $table = Azure_Database::get_table_name('user_children');
        $family_table = Azure_Database::get_table_name('connected_family');
        if (!$table) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $child_id
        ));
        if (!$row) {
            return null;
        }
        if (!$user_id) {
            return $row;
        }
        $user_id = (int) $user_id;
        if ((int) $row->user_id === $user_id) {
            return $row;
        }
        if ((int) $row->family_id > 0 && $family_table) {
            $is_member = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$family_table}
                 WHERE id = %d AND (primary_user_id = %d OR secondary_user_id = %d)",
                (int) $row->family_id, $user_id, $user_id
            ));
            if ($is_member) {
                return $row;
            }
        }
        return null;
    }

    // ─── Connected Family Data Access ──────────────────────────────────

    /**
     * Get the connected_family row a user belongs to. Returns null if the
     * user has no family yet. Per-request memoized.
     */
    public static function get_family_for_user($user_id) {
        static $cache = array();
        $user_id = (int) $user_id;
        if (!$user_id) {
            return null;
        }
        if (array_key_exists($user_id, $cache)) {
            return $cache[$user_id];
        }
        global $wpdb;
        $family_table = Azure_Database::get_table_name('connected_family');
        if (!$family_table) {
            $cache[$user_id] = null;
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$family_table}
             WHERE primary_user_id = %d OR secondary_user_id = %d
             ORDER BY id ASC LIMIT 1",
            $user_id, $user_id
        ));
        $cache[$user_id] = $row ?: null;
        return $cache[$user_id];
    }

    /**
     * Get or create a connected_family for $user_id (the user becomes the
     * primary if no family exists). Returns the family id.
     */
    public static function ensure_family_for_user($user_id) {
        $user_id = (int) $user_id;
        if (!$user_id) {
            return 0;
        }
        $existing = self::get_family_for_user($user_id);
        if ($existing) {
            return (int) $existing->id;
        }
        global $wpdb;
        $family_table = Azure_Database::get_table_name('connected_family');
        if (!$family_table) {
            return 0;
        }
        $display = '';
        if (class_exists('Azure_Database')) {
            $display = Azure_Database::derive_family_display_name($user_id);
        }
        $wpdb->insert($family_table, array(
            'display_name'    => $display,
            'primary_user_id' => $user_id,
        ), array('%s', '%d'));
        return (int) $wpdb->insert_id;
    }

    public static function get_family_meta($family_id) {
        global $wpdb;
        $table = Azure_Database::get_table_name('connected_family_meta');
        if (!$table) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$table} WHERE family_id = %d",
            (int) $family_id
        ));
        $meta = array();
        foreach ($rows as $row) {
            $meta[$row->meta_key] = $row->meta_value;
        }
        return $meta;
    }

    public static function update_family_meta($family_id, $meta_array) {
        global $wpdb;
        $table = Azure_Database::get_table_name('connected_family_meta');
        if (!$table || !$family_id || !is_array($meta_array)) {
            return;
        }
        foreach ($meta_array as $key => $value) {
            $key = sanitize_text_field($key);
            $value = sanitize_text_field($value);
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE family_id = %d AND meta_key = %s",
                (int) $family_id, $key
            ));
            if ($existing) {
                $wpdb->update($table, array('meta_value' => $value), array('id' => $existing));
            } else {
                $wpdb->insert($table, array(
                    'family_id'  => (int) $family_id,
                    'meta_key'   => $key,
                    'meta_value' => $value,
                ));
            }
        }
    }

    public static function get_child_meta($child_id) {
        global $wpdb;
        $table = Azure_Database::get_table_name('user_children_meta');
        if (!$table) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$table} WHERE child_id = %d",
            $child_id
        ));
        $meta = array();
        foreach ($rows as $row) {
            $meta[$row->meta_key] = $row->meta_value;
        }
        return $meta;
    }

    public static function save_child($user_id, $data) {
        global $wpdb;
        $table = Azure_Database::get_table_name('user_children');
        if (!$table) {
            return false;
        }

        $id = intval($data['id'] ?? 0);
        $child_name = sanitize_text_field($data['child_name'] ?? '');

        if (empty($child_name)) {
            return false;
        }

        // Always attach to a family. If the caller passed an explicit
        // family_id (importer), use that; otherwise resolve from the user.
        $family_id = isset($data['family_id']) ? (int) $data['family_id'] : 0;
        if (!$family_id && $user_id) {
            $family_id = self::ensure_family_for_user((int) $user_id);
        }

        if ($id > 0) {
            $existing = self::get_child($id, $user_id);
            if (!$existing) {
                return false;
            }
            $update = array(
                'child_name' => $child_name,
                'date_of_birth' => !empty($data['date_of_birth']) ? sanitize_text_field($data['date_of_birth']) : null,
                'updated_at' => current_time('mysql'),
            );
            if ($family_id && (int) $existing->family_id !== $family_id) {
                $update['family_id'] = $family_id;
            }
            $wpdb->update($table, $update, array('id' => $id));
        } else {
            $wpdb->insert($table, array(
                'user_id' => $user_id,
                'family_id' => $family_id,
                'child_name' => $child_name,
                'date_of_birth' => !empty($data['date_of_birth']) ? sanitize_text_field($data['date_of_birth']) : null,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ));
            $id = $wpdb->insert_id;
        }

        if ($id && !empty($data['meta']) && is_array($data['meta'])) {
            self::update_child_meta($id, $data['meta']);
        }

        if ($id) {
            self::clear_children_cache();
            if (function_exists('do_action')) {
                do_action('azure_user_children_changed', (int) $user_id, (int) $id);
            }
        }

        return $id;
    }

    public static function update_child_meta($child_id, $meta_array) {
        global $wpdb;
        $table = Azure_Database::get_table_name('user_children_meta');
        if (!$table) {
            return;
        }

        foreach ($meta_array as $key => $value) {
            $key = sanitize_text_field($key);
            $value = sanitize_text_field($value);

            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE child_id = %d AND meta_key = %s",
                $child_id, $key
            ));

            if ($existing) {
                $wpdb->update($table, array('meta_value' => $value), array('id' => $existing));
            } else {
                $wpdb->insert($table, array(
                    'child_id' => $child_id,
                    'meta_key' => $key,
                    'meta_value' => $value,
                ));
            }
        }
    }

    public static function delete_child($child_id, $user_id) {
        global $wpdb;
        $table = Azure_Database::get_table_name('user_children');
        $meta_table = Azure_Database::get_table_name('user_children_meta');

        $existing = self::get_child($child_id, $user_id);
        if (!$existing) {
            return false;
        }

        $wpdb->delete($meta_table, array('child_id' => $child_id));
        $wpdb->delete($table, array('id' => $child_id));
        self::clear_children_cache();
        if (function_exists('do_action')) {
            do_action('azure_user_children_changed', (int) $user_id, (int) $child_id);
        }
        return true;
    }

    /**
     * Find an existing child by name within the user's connected family.
     * Falls back to a direct user_id match for legacy non-family rows.
     */
    public static function find_child_by_name($user_id, $child_name) {
        global $wpdb;
        $table = Azure_Database::get_table_name('user_children');
        $family_table = Azure_Database::get_table_name('connected_family');
        if (!$table) {
            return null;
        }
        if ($family_table) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT c.* FROM {$table} c
                 INNER JOIN {$family_table} f ON c.family_id = f.id
                 WHERE (f.primary_user_id = %d OR f.secondary_user_id = %d)
                   AND c.child_name = %s
                   AND c.is_active = 1
                 LIMIT 1",
                $user_id, $user_id, $child_name
            ));
            if ($row) {
                return $row;
            }
        }
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d AND child_name = %s AND is_active = 1",
            $user_id, $child_name
        ));
    }

    // ─── Auto-save from completed orders ───────────────────────────────

    public function auto_save_from_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $user_id = $order->get_user_id();
        if (!$user_id) {
            return;
        }

        foreach ($order->get_items() as $item) {
            $raw = $item->get_meta('_azure_product_fields_raw', true);
            $roster = $item->get_meta('_azure_pf_children', true);
            if ((!is_array($raw) || empty($raw)) && !(is_array($roster) && !empty($roster))) {
                continue;
            }
            if (!is_array($raw)) {
                $raw = array();
            }

            // Family-scope meta (emergency contact, etc.) is shared across
            // both co-parents and every child, so write it once per item.
            $family_meta = $this->build_family_meta($raw);
            if (!empty($family_meta)) {
                $family_id = self::ensure_family_for_user($user_id);
                if ($family_id) {
                    self::update_family_meta($family_id, $family_meta);
                }
            }

            if (is_array($roster) && !empty($roster)) {
                $this->auto_save_family_children($user_id, $roster);
                continue;
            }

            // Selected child id (set on the product page) is the most reliable
            // source. Falls back to a "child name" field present in the form.
            $selected_child_id = (int) $item->get_meta('_azure_pf_child_id', true);
            $child_name = $this->extract_child_name($raw);

            $meta = $this->build_child_meta($raw);
            if (empty($meta) && $selected_child_id <= 0 && empty($child_name)) {
                continue;
            }

            $child = null;
            if ($selected_child_id > 0) {
                $child = self::get_child($selected_child_id, $user_id);
            }
            if (!$child && !empty($child_name)) {
                $child = self::find_child_by_name($user_id, $child_name);
            }

            if (!$child && empty($child_name)) {
                // No way to resolve which child this line item refers to.
                continue;
            }

            if ($child) {
                self::update_child_meta($child->id, $meta);
            } else {
                self::save_child($user_id, array(
                    'child_name' => $child_name,
                    'meta'       => $meta,
                ));
            }
        }
    }

    /**
     * Write each family-membership roster child back to the profile.
     *
     * @param int   $user_id
     * @param array $roster
     */
    private function auto_save_family_children($user_id, $roster) {
        $grade_key = 'pta_pf_childsgrade';
        $teacher_key = 'pta_pf_child_teacher';
        if (class_exists('Azure_Product_Fields_Module')) {
            $keys = Azure_Product_Fields_Module::get_child_profile_field_keys();
            if (!empty($keys['grade'])) {
                $grade_key = 'pta_pf_' . $keys['grade'];
            }
            if (!empty($keys['teacher'])) {
                $teacher_key = 'pta_pf_' . $keys['teacher'];
            }
        }

        foreach ($roster as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            $name = isset($row['name']) ? trim((string) $row['name']) : '';
            $grade = isset($row['grade']) ? trim((string) $row['grade']) : '';
            $teacher = isset($row['teacher']) ? trim((string) $row['teacher']) : '';
            if ($id <= 0 && $name === '') {
                continue;
            }

            $meta = array();
            if ($grade !== '') {
                $meta[$grade_key] = $grade;
            }
            if ($teacher !== '') {
                $meta[$teacher_key] = $teacher;
            }

            $child = $id > 0 ? self::get_child($id, $user_id) : null;
            if (!$child && $name !== '') {
                $child = self::find_child_by_name($user_id, $name);
            }

            if ($child) {
                if (!empty($meta)) {
                    self::update_child_meta($child->id, $meta);
                }
            } elseif ($name !== '') {
                self::save_child($user_id, array(
                    'child_name' => $name,
                    'meta'       => $meta,
                ));
            }
        }
    }

    /**
     * Find the value entered into the field that semantically represents
     * the child's name (`field_key === 'child_name'` or label match).
     */
    private function extract_child_name($raw) {
        foreach ($raw as $field) {
            $key = isset($field['field_key']) ? strtolower($field['field_key']) : '';
            if ($key === 'child_name') {
                return isset($field['value']) ? $field['value'] : '';
            }
        }
        foreach ($raw as $field) {
            $label_lower = isset($field['label']) ? strtolower($field['label']) : '';
            if (strpos($label_lower, 'child') !== false && strpos($label_lower, 'name') !== false) {
                return isset($field['value']) ? $field['value'] : '';
            }
        }
        return '';
    }

    /**
     * Build the child meta map from a line item's raw field array, using
     * canonical `pta_pf_<field_key>` keys when available and falling back
     * to label-keyed entries for legacy data (which the consolidation tool
     * later rewrites to canonical keys).
     */
    private function build_child_meta($raw) {
        $meta = array();
        foreach ($raw as $field) {
            $scope = isset($field['scope']) ? $field['scope'] : 'child';
            if ($scope !== 'child' || empty($field['save_to_profile'])) {
                continue;
            }
            if (!isset($field['value']) || $field['value'] === '') {
                continue;
            }
            if (!empty($field['field_key'])) {
                $meta['pta_pf_' . $field['field_key']] = $field['value'];
            } elseif (!empty($field['label'])) {
                // Legacy raw entries without field_key: keep label so the
                // value is not lost; consolidation rewrites these later.
                $meta[$field['label']] = $field['value'];
            }
        }
        return $meta;
    }

    /**
     * Build the family-scope meta map from a line item's raw field array.
     * Returns canonical `pta_pf_<field_key>` entries only — family-scope is
     * a v3.67 concept so legacy label fallbacks don't apply.
     */
    private function build_family_meta($raw) {
        $meta = array();
        foreach ($raw as $field) {
            $scope = isset($field['scope']) ? $field['scope'] : 'child';
            if ($scope !== 'family' || empty($field['save_to_profile'])) {
                continue;
            }
            if (!isset($field['value']) || $field['value'] === '') {
                continue;
            }
            if (!empty($field['field_key'])) {
                $meta['pta_pf_' . $field['field_key']] = $field['value'];
            }
        }
        return $meta;
    }

    // ─── My Account Tab ────────────────────────────────────────────────

    public function add_my_account_tab($items) {
        $new_items = array();
        $inserted = false;
        foreach ($items as $key => $label) {
            $new_items[$key] = $label;
            if ($key === 'orders' && !$inserted) {
                $new_items['profile'] = __('Family Info', 'azure-plugin');
                $inserted = true;
            }
        }
        // Themes that strip the orders item still get our entry — fall through
        // to insert before edit-account if the orders anchor was missing.
        if (!$inserted) {
            $rebuilt = array();
            foreach ($new_items as $key => $label) {
                if ($key === 'edit-account' && !$inserted) {
                    $rebuilt['profile'] = __('Family Info', 'azure-plugin');
                    $inserted = true;
                }
                $rebuilt[$key] = $label;
            }
            $new_items = $rebuilt;
        }
        if (!$inserted) {
            $new_items['profile'] = __('Family Info', 'azure-plugin');
        }
        return $new_items;
    }

    public function render_my_account_page() {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }

        $module          = $this;
        $children        = self::get_children_for_user($user_id);
        $parent_meta     = self::get_parent_meta_fields();
        $parent_vals     = self::get_parent_meta_values($user_id, $parent_meta);
        $child_fields    = self::get_child_meta_fields();
        $child_label_map = self::build_child_field_label_map($child_fields);
        $family          = self::get_family_for_user($user_id);
        $family_meta     = self::get_family_meta_fields();
        $family_vals     = $family ? self::get_family_meta_values($family->id, $family_meta) : array();

        $template = AZURE_PLUGIN_PATH . 'templates/my-account-profile.php';
        if (file_exists($template)) {
            include $template;
        }
    }

    /**
     * Render a profile form input for a parent-scope field definition.
     */
    public function render_profile_input($field, $value, $name) {
        $type = isset($field['type']) ? $field['type'] : 'text';
        switch ($type) {
            case 'textarea':
                echo '<textarea name="' . esc_attr($name) . '" rows="3">' . esc_textarea($value) . '</textarea>';
                break;
            case 'select':
                $options = isset($field['options']) ? (array) $field['options'] : array();
                echo '<select name="' . esc_attr($name) . '">';
                echo '<option value="">-- Select --</option>';
                foreach ($options as $opt) {
                    $selected = ($value === $opt) ? ' selected' : '';
                    echo '<option value="' . esc_attr($opt) . '"' . $selected . '>' . esc_html($opt) . '</option>';
                }
                echo '</select>';
                break;
            case 'checkbox':
                $checked = ($value === 'Yes' || $value === '1' || $value === 'true') ? ' checked' : '';
                echo '<label><input type="checkbox" name="' . esc_attr($name) . '" value="Yes"' . $checked . ' /> Yes</label>';
                break;
            default:
                echo '<input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" />';
                break;
        }
    }

    /**
     * Get all child-scope field definitions (deduped) for the My Children form.
     * Derived from `azure_product_fields` rows where scope='child'.
     */
    public static function get_child_meta_fields() {
        return self::get_meta_fields_by_scope('child', self::get_default_child_meta_fields());
    }

    /**
     * Get all parent-scope field definitions for the My Profile form.
     */
    public static function get_parent_meta_fields() {
        $fields = self::get_meta_fields_by_scope('parent', array());
        foreach (self::get_meta_fields_by_scope('family', array()) as $field) {
            if (self::is_directory_opt_in_field($field)) {
                $fields[] = $field;
            }
        }
        return $fields;
    }

    /**
     * Get all family-scope field definitions for the My Family form.
     * Directory opt-in checkboxes belong next to the parent contact fields
     * even if they were originally saved as family-scope.
     */
    public static function get_family_meta_fields() {
        $fields = self::get_meta_fields_by_scope('family', array());
        $out = array();
        foreach ($fields as $field) {
            if (!self::is_directory_opt_in_field($field)) {
                $out[] = $field;
            }
        }
        return $out;
    }

    /**
     * True when a product-field definition is a parent-directory opt-in box.
     */
    public static function is_directory_opt_in_field($field) {
        $key = strtolower(isset($field['key']) ? (string) $field['key'] : '');
        $label = strtolower(isset($field['label']) ? (string) $field['label'] : '');
        if ($key !== '' && (strpos($key, 'opt_in') !== false || strpos($key, 'opt-in') !== false)) {
            return true;
        }
        if ($label === '') {
            return false;
        }
        if (strpos($label, 'directory') !== false) {
            return true;
        }
        return (strpos($label, 'opt in') !== false || strpos($label, 'opt-in') !== false);
    }

    /**
     * Pair parent_1_* / parent_2_* fields into two-column rows (Name | Name,
     * Email | Email, Cell | Cell, opt-in | opt-in). Unpaired extras go in $tail.
     *
     * @param array $parent_meta Field defs from get_parent_meta_fields().
     * @return array{pairs: array, tail: array}
     */
    public static function pair_parent_profile_fields($parent_meta) {
        $parent_pairs = array();
        $parent_tail  = array();
        $by_key = array();
        foreach ((array) $parent_meta as $f) {
            if (empty($f['key'])) {
                continue;
            }
            $by_key[$f['key']] = $f;
        }

        $take_parent_field = function ($slot, $sub) use (&$by_key) {
            $suffix = 'parent_' . $slot . '_' . $sub;
            $canonical = 'pta_pf_' . $suffix;
            if (isset($by_key[$canonical])) {
                $field = $by_key[$canonical];
                unset($by_key[$canonical]);
                return $field;
            }
            foreach ($by_key as $key => $field) {
                if (substr($key, -strlen($suffix)) === $suffix) {
                    unset($by_key[$key]);
                    return $field;
                }
            }
            if ($sub !== 'opt_in') {
                return null;
            }
            foreach ($by_key as $key => $field) {
                if (!self::is_directory_opt_in_field($field)) {
                    continue;
                }
                $label = strtolower(isset($field['label']) ? $field['label'] : '');
                $is_p2 = (strpos($label, 'parent 2') !== false || strpos($key, 'parent_2') !== false);
                if (($slot === '2' && $is_p2) || ($slot === '1' && !$is_p2)) {
                    unset($by_key[$key]);
                    return $field;
                }
            }
            return null;
        };

        foreach (array('name', 'email', 'cell', 'opt_in') as $sub) {
            $left  = $take_parent_field('1', $sub);
            $right = $take_parent_field('2', $sub);
            if (!$left && !$right) {
                continue;
            }
            $parent_pairs[] = array(
                'left'  => $left,
                'right' => $right,
            );
        }

        foreach ($by_key as $field) {
            $label = strtolower(isset($field['label']) ? $field['label'] : '');
            $key   = isset($field['key']) ? $field['key'] : '';
            if (strpos($key, 'opt_in') !== false || strpos($label, 'directory') !== false) {
                continue;
            }
            $parent_tail[] = $field;
        }

        return array(
            'pairs' => $parent_pairs,
            'tail'  => $parent_tail,
        );
    }

    /**
     * Read the saved family-scope meta values keyed by `pta_pf_<field_key>`.
     */
    public static function get_family_meta_values($family_id, $family_fields) {
        if (!$family_id) {
            return array();
        }
        $stored = self::get_family_meta($family_id);
        $values = array();
        foreach ($family_fields as $f) {
            if (empty($f['key'])) {
                continue;
            }
            if (isset($stored[$f['key']]) && $stored[$f['key']] !== '') {
                $values[$f['key']] = $stored[$f['key']];
            }
        }
        return $values;
    }

    /**
     * Field definitions used by the My Account profile/family/children forms.
     *
     * Deduplication strategy:
     *  1. Pull all rows by scope, sorted by sort_order then id.
     *  2. Compute a normalized label for each row (lowercase, strip
     *     punctuation, collapse "child s name" → "child name") so that
     *     "Child Name" / "Childs name" / "Child(s) name" all share a key.
     *  3. Keep one row per normalized label. When multiple rows share a
     *     normalized label, prefer the one whose `field_key` is in the
     *     canonical v3.67 set — that way the user sees exactly one
     *     "Child Name" row regardless of how many legacy variants exist.
     *
     * The full Product Fields admin still sees every row in the database.
     */
    private static function get_meta_fields_by_scope($scope, $fallback) {
        global $wpdb;
        $fld_table = Azure_Database::get_table_name('product_fields');
        if (!$fld_table) {
            return $fallback;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT label, field_key, field_type, options_json
             FROM {$fld_table}
             WHERE scope = %s
             ORDER BY sort_order ASC, id ASC",
            $scope
        ));

        if (empty($rows)) {
            return $fallback;
        }

        $canonical_keys = self::get_canonical_field_keys();
        $by_norm = array();

        foreach ($rows as $r) {
            $norm = self::normalize_field_label($r->label);
            $is_canonical = !empty($r->field_key) && in_array($r->field_key, $canonical_keys, true);

            if (!isset($by_norm[$norm])) {
                $by_norm[$norm] = array('row' => $r, 'canonical' => $is_canonical);
                continue;
            }
            // Replace the held entry only if the new row is canonical and
            // the held one isn't — otherwise the first-encountered row wins
            // (sort_order asc + id asc gives us the earliest, most-stable
            // configuration first).
            if ($is_canonical && !$by_norm[$norm]['canonical']) {
                $by_norm[$norm] = array('row' => $r, 'canonical' => true);
            }
        }

        $out = array();
        foreach ($by_norm as $entry) {
            $r = $entry['row'];
            $key = !empty($r->field_key) ? ('pta_pf_' . $r->field_key) : ('pta_pf_' . sanitize_key($r->label));
            $def = array(
                'key'   => $key,
                'label' => $r->label,
                'type'  => $r->field_type,
            );
            if ($r->field_type === 'select' && !empty($r->options_json)) {
                $def['options'] = json_decode($r->options_json, true) ?: array();
            }
            $out[] = $def;
        }
        return $out;
    }

    /**
     * Normalize a field label for duplicate detection. Examples:
     *   "Child Name"         → "child name"
     *   "Childs name"        → "child name"
     *   "Child(s) name"      → "child name"
     *   "Self Carry Epi Pen" → "self carry epi pen"
     *   "EpiPen"             → "epipen"
     */
    private static function normalize_field_label($label) {
        $norm = strtolower(trim((string) $label));
        $norm = preg_replace('/[^a-z0-9 ]+/', ' ', $norm);
        $norm = preg_replace('/\s+/', ' ', $norm);
        // Collapse "childs", "child s" → "child" so "Childs Grade",
        // "Child's Grade", "Child(s) Grade", "Child Grade" all bucket
        // together for runtime dedup.
        $norm = preg_replace('/\bchild(s|\s+s)?\b/', 'child', $norm);
        $norm = preg_replace('/\s+/', ' ', $norm);
        return trim($norm);
    }

    /**
     * Canonical v3.67 field_keys. When multiple rows share a normalized
     * label, the one with a key in this list wins. Filterable so future
     * additions don't require editing this class.
     */
    private static function get_canonical_field_keys() {
        return apply_filters('azure_uc_canonical_field_keys', array(
            // Child Core
            'child_name', 'child_grade', 'child_teacher',
            // Parent Core
            'parent_1_name', 'parent_1_email', 'parent_1_cell', 'parent_1_opt_in',
            'parent_2_name', 'parent_2_email', 'parent_2_cell', 'parent_2_opt_in',
            // Enrichment
            'allergies', 'photos_ok', 'epi_pen', 'ymca', 'other_notes_instructor',
            // Family / Emergency Contact
            'emergency_contact_name', 'emergency_contact_email', 'emergency_contact_cell',
        ));
    }

    /**
     * Build a [meta_key => Display Label] map for child fields, used to label
     * meta rows on the My Children list when displaying values.
     */
    public static function build_child_field_label_map($child_fields) {
        $map = array();
        foreach ($child_fields as $f) {
            if (!empty($f['key']) && !empty($f['label'])) {
                $map[$f['key']] = $f['label'];
            }
        }
        return $map;
    }

    /**
     * Read the current saved value for each parent-scope field for a user.
     */
    public static function get_parent_meta_values($user_id, $parent_fields) {
        $values = array();
        $family_stored = array();
        $family = self::get_family_for_user($user_id);
        if ($family) {
            $family_stored = self::get_family_meta($family->id);
        }
        foreach ($parent_fields as $f) {
            if (empty($f['key'])) {
                continue;
            }
            $val = get_user_meta($user_id, $f['key'], true);
            if ($val === '' && isset($family_stored[$f['key']]) && $family_stored[$f['key']] !== '') {
                $val = $family_stored[$f['key']];
            }
            if ($val !== '') {
                $values[$f['key']] = $val;
            }
        }
        return $values;
    }

    /**
     * Make a meta key human-friendly for display when a label lookup fails.
     */
    public static function humanize_meta_key($key) {
        $stripped = preg_replace('/^pta_pf_/', '', $key);
        $stripped = str_replace(array('_', '-'), ' ', $stripped);
        return ucwords(trim($stripped));
    }

    private static function get_default_child_meta_fields() {
        // Emergency contact moved to family scope in v3.67. The remaining
        // fallback set is kept for installs that haven't seeded the
        // canonical Child Core / Enrichment groups yet.
        return array(
            array('key' => 'pta_pf_child_grade', 'label' => 'Grade', 'type' => 'text'),
            array('key' => 'pta_pf_child_teacher', 'label' => 'Teacher', 'type' => 'text'),
            array('key' => 'pta_pf_allergies', 'label' => 'Allergies', 'type' => 'textarea'),
        );
    }

    // ─── AJAX Handlers ─────────────────────────────────────────────────

    public function ajax_get_children() {
        check_ajax_referer('azure_uc_nonce', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('Not logged in');
        }

        $children = self::get_children_for_user($user_id);
        $result = array();

        foreach ($children as $child) {
            $result[] = array(
                'id' => $child->id,
                'child_name' => $child->child_name,
                'meta' => self::get_child_meta($child->id),
            );
        }

        wp_send_json_success($result);
    }

    public function ajax_save_child() {
        check_ajax_referer('azure_uc_nonce', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('Not logged in');
        }

        $meta = array();
        if (!empty($_POST['meta']) && is_array($_POST['meta'])) {
            foreach ($_POST['meta'] as $key => $value) {
                $meta[sanitize_text_field($key)] = sanitize_text_field($value);
            }
        }

        $id = self::save_child($user_id, array(
            'id' => intval($_POST['child_id'] ?? 0),
            'child_name' => sanitize_text_field($_POST['child_name'] ?? ''),
            'meta' => $meta,
        ));

        if ($id) {
            wp_send_json_success(array('id' => $id));
        } else {
            wp_send_json_error('Could not save child profile');
        }
    }

    public function ajax_delete_child() {
        check_ajax_referer('azure_uc_nonce', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('Not logged in');
        }

        $child_id = intval($_POST['child_id'] ?? 0);
        if (self::delete_child($child_id, $user_id)) {
            wp_send_json_success('Deleted');
        } else {
            wp_send_json_error('Could not delete');
        }
    }

    public function ajax_get_child_meta() {
        check_ajax_referer('azure_uc_nonce', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('Not logged in');
        }

        $child_id = intval($_POST['child_id'] ?? 0);
        $child = self::get_child($child_id, $user_id);
        if (!$child) {
            wp_send_json_error('Child not found');
        }

        wp_send_json_success(array(
            'id' => $child->id,
            'child_name' => $child->child_name,
            'meta' => self::get_child_meta($child->id),
        ));
    }

    /**
     * Save the parent-scope profile section. Each value is written to
     * `pta_pf_<field_key>` user meta. Only keys that match a known parent
     * field definition are accepted.
     */
    public function ajax_save_profile() {
        check_ajax_referer('azure_uc_nonce', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('Not logged in');
        }

        $parent_fields = self::get_parent_meta_fields();
        $allowed_keys = array();
        foreach ($parent_fields as $f) {
            if (!empty($f['key'])) {
                $allowed_keys[$f['key']] = true;
            }
        }

        $posted = isset($_POST['meta']) && is_array($_POST['meta']) ? $_POST['meta'] : array();
        $saved = 0;
        foreach ($posted as $key => $value) {
            $key = sanitize_key($key);
            if (!isset($allowed_keys[$key])) {
                continue;
            }
            update_user_meta($user_id, $key, sanitize_text_field($value));
            $saved++;
        }

        wp_send_json_success(array('saved' => $saved));
    }

    /**
     * Save the family-scope profile section. Each value is written to
     * `azure_connected_family_meta` keyed by `pta_pf_<field_key>`. Only
     * keys that match a known family field definition are accepted, and
     * the calling user must belong to the family.
     */
    public function ajax_save_family() {
        check_ajax_referer('azure_uc_nonce', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('Not logged in');
        }

        $family_id = self::ensure_family_for_user($user_id);
        if (!$family_id) {
            wp_send_json_error('Could not resolve family');
        }

        $family_fields = self::get_family_meta_fields();
        $allowed_keys = array();
        foreach ($family_fields as $f) {
            if (!empty($f['key'])) {
                $allowed_keys[$f['key']] = true;
            }
        }

        $posted = isset($_POST['meta']) && is_array($_POST['meta']) ? $_POST['meta'] : array();
        $accepted = array();
        foreach ($posted as $key => $value) {
            $key = sanitize_key($key);
            if (!isset($allowed_keys[$key])) {
                continue;
            }
            $accepted[$key] = sanitize_text_field($value);
        }

        if (!empty($accepted)) {
            self::update_family_meta($family_id, $accepted);
        }

        wp_send_json_success(array('saved' => count($accepted)));
    }

    // ─── Frontend Assets ───────────────────────────────────────────────

    public function enqueue_assets() {
        if (is_account_page() || is_product()) {
            wp_enqueue_style(
                'azure-user-children',
                AZURE_PLUGIN_URL . 'css/user-children.css',
                array(),
                AZURE_PLUGIN_VERSION
            );
        }
    }
}
