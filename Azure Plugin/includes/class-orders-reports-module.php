<?php
/**
 * Orders Reports — Module bootstrap, AJAX handlers, and admin-post
 * handlers.
 *
 * Wires together the CPT + columns + query + export + storage layers.
 * The module is instantiated from azure-plugin.php when WC is active.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Orders_Reports_Module {

    private static $instance = null;

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
        $this->load_dependencies();
        $this->register_hooks();

        if (class_exists('Azure_Logger')) {
            Azure_Logger::debug_module('OrdersReports', 'Orders Reports module initialised');
        }
    }

    private function load_dependencies() {
        $files = array(
            'class-orders-reports-cpt.php',
            'class-orders-reports-columns.php',
            'class-orders-reports-query.php',
            'class-orders-reports-export.php',
            'class-orders-reports-storage.php',
        );
        foreach ($files as $f) {
            $path = AZURE_PLUGIN_PATH . 'includes/' . $f;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    private function register_hooks() {
        add_action('init',                 array(Azure_Orders_Reports_CPT::class, 'register'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        // AJAX endpoints.
        add_action('wp_ajax_azure_or_preview',         array($this, 'ajax_preview'));
        add_action('wp_ajax_azure_or_save',            array($this, 'ajax_save'));
        add_action('wp_ajax_azure_or_delete',          array($this, 'ajax_delete'));
        add_action('wp_ajax_azure_or_duplicate',       array($this, 'ajax_duplicate'));
        add_action('wp_ajax_azure_or_search_products', array($this, 'ajax_search_products'));

        // Form-driven endpoints.
        add_action('admin_post_azure_or_export',       array($this, 'handle_export'));
        add_action('admin_post_azure_or_export_saved', array($this, 'handle_export_saved'));

        // Admin dashboard widget — saved reports + one-click Export each.
        add_action('wp_dashboard_setup', array($this, 'register_dashboard_widget'));
    }

    /**
     * Register the Orders Reports dashboard widget. Hook fires only on
     * /wp-admin/index.php, so the cost on every other admin page is
     * zero. Inside, we further gate by capability so the SQL only
     * runs for users who can actually use the widget.
     */
    public function register_dashboard_widget() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        wp_add_dashboard_widget(
            'azure_orders_reports_widget',
            __('Orders Reports', 'azure-plugin'),
            array($this, 'render_dashboard_widget')
        );
    }

    /**
     * Render the dashboard widget. ONE database query for the saved
     * reports list (via Storage::list_all) plus a fixed amount of
     * markup per row — no resolver calls, no order iteration. Each
     * Export button is a self-contained POST form to the existing
     * `azure_or_export_saved` admin-post handler, so the widget needs
     * no JavaScript of its own and the heavy lifting only happens on
     * click, not on render.
     */
    public function render_dashboard_widget() {
        $reports   = Azure_Orders_Reports_Storage::list_all();
        $admin_url = admin_url('admin.php?page=azure-plugin-selling&tab=reports');
        ?>
        <style>
            #azure_orders_reports_widget .azure-or-w-list { max-height: 240px; overflow-y: auto; margin: 0 -12px; }
            #azure_orders_reports_widget .azure-or-w-row { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-bottom: 1px solid #f0f0f1; }
            #azure_orders_reports_widget .azure-or-w-row:last-child { border-bottom: none; }
            #azure_orders_reports_widget .azure-or-w-row:hover { background: #f6f7f7; }
            #azure_orders_reports_widget .azure-or-w-meta { flex: 1; min-width: 0; }
            #azure_orders_reports_widget .azure-or-w-name { font-weight: 600; color: #1d2327; }
            #azure_orders_reports_widget .azure-or-w-name a { text-decoration: none; }
            #azure_orders_reports_widget .azure-or-w-name a:hover { text-decoration: underline; }
            #azure_orders_reports_widget .azure-or-w-sub { font-size: 11px; color: #646970; }
            #azure_orders_reports_widget .azure-or-w-form { margin: 0; flex-shrink: 0; }
            #azure_orders_reports_widget .azure-or-w-footer { margin-top: 10px; text-align: right; }
        </style>
        <?php if (empty($reports)) : ?>
            <p style="margin: 4px 0 12px;">
                <?php esc_html_e('No saved reports yet.', 'azure-plugin'); ?>
                <a href="<?php echo esc_url($admin_url . '&subtab=new'); ?>"><?php esc_html_e('Build your first report.', 'azure-plugin'); ?></a>
            </p>
        <?php else : ?>
            <div class="azure-or-w-list">
                <?php foreach ($reports as $r) :
                    $edit_url = $admin_url . '&edit=' . (int) $r['id'];
                    $last_exp = !empty($r['last_exported_at']) ? $this->format_relative_time($r['last_exported_at']) : __('never', 'azure-plugin');
                    $rows_str = ($r['last_exported_rows'] > 0)
                        ? sprintf(esc_html__('%d rows', 'azure-plugin'), (int) $r['last_exported_rows'])
                        : '';
                ?>
                    <div class="azure-or-w-row">
                        <div class="azure-or-w-meta">
                            <div class="azure-or-w-name">
                                <a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($r['name']); ?></a>
                            </div>
                            <div class="azure-or-w-sub">
                                <?php
                                /* translators: 1: relative time like "2 days ago"; 2: optional "(N rows)" */
                                printf(
                                    esc_html__('Last exported: %1$s%2$s', 'azure-plugin'),
                                    esc_html($last_exp),
                                    $rows_str !== '' ? ' · ' . esc_html($rows_str) : ''
                                );
                                ?>
                            </div>
                        </div>
                        <form class="azure-or-w-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action"    value="azure_or_export_saved" />
                            <input type="hidden" name="report_id" value="<?php echo (int) $r['id']; ?>" />
                            <input type="hidden" name="run_as_of" value="today" />
                            <?php wp_nonce_field('azure_or_export_saved_' . (int) $r['id']); ?>
                            <button type="submit" class="button button-small button-primary">
                                <span class="dashicons dashicons-download" style="vertical-align:middle; font-size:14px; height:14px; width:14px; line-height:1; margin-right:2px;"></span>
                                <?php esc_html_e('Export', 'azure-plugin'); ?>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="azure-or-w-footer">
                <a href="<?php echo esc_url($admin_url . '&subtab=saved'); ?>"><?php esc_html_e('Manage reports', 'azure-plugin'); ?> →</a>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Cheap relative-time formatter for the widget. Uses human_time_diff
     * which is already in WP core and properly i18n'd ("2 days ago",
     * "5 minutes ago"). Falls back to the raw date string on failure.
     */
    private function format_relative_time($mysql_datetime) {
        $ts = strtotime((string) $mysql_datetime);
        if (!$ts) return (string) $mysql_datetime;
        return sprintf(
            /* translators: %s: time elapsed, e.g. "2 days" */
            esc_html__('%s ago', 'azure-plugin'),
            human_time_diff($ts, current_time('timestamp'))
        );
    }

    public function enqueue_assets($hook) {
        $page = isset($_GET['page']) ? (string) $_GET['page'] : '';
        $tab  = isset($_GET['tab'])  ? (string) $_GET['tab']  : '';
        if ($page !== 'azure-plugin-selling' || $tab !== 'reports') {
            return;
        }

        // File-mtime cache buster — matches the existing admin pattern in
        // class-admin.php which uses VERSION.time(). filemtime is gentler
        // (only busts when the file actually changes, not every page load)
        // but still survives the "I forgot to bump AZURE_PLUGIN_VERSION"
        // case that bit us between deploys.
        $base_v   = defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : '1.0';
        $css_path = AZURE_PLUGIN_PATH . 'css/orders-reports.css';
        $js_path  = AZURE_PLUGIN_PATH . 'js/orders-reports-builder.js';
        $css_v    = file_exists($css_path) ? $base_v . '.' . filemtime($css_path) : $base_v;
        $js_v     = file_exists($js_path)  ? $base_v . '.' . filemtime($js_path)  : $base_v;

        wp_enqueue_script('jquery-ui-sortable');

        // WooCommerce's standard product-search Select2 widget — the same
        // 3-letter autocomplete used everywhere else in WC admin. The
        // <select class="wc-product-search"> in our markup is picked up
        // automatically once these are enqueued.
        //
        // IMPORTANT: do NOT declare 'select2' / 'wc-enhanced-select' as
        // dependencies of OUR enqueued assets. WC registers those handles
        // on the same admin_enqueue_scripts hook at the same priority as
        // we do; if our callback fires first, WP will see the unresolved
        // dependency and silently drop our entire stylesheet from the
        // page. That manifested live as the broken-styling screenshot —
        // file fetches 200 OK from the public URL but no <link> tag in
        // the rendered <head>. Our CSS does not override any select2
        // styles, so cascade order doesn't matter.
        wp_enqueue_style('select2');
        wp_enqueue_script('wc-enhanced-select');

        wp_enqueue_style(
            'azure-orders-reports',
            AZURE_PLUGIN_URL . 'css/orders-reports.css',
            array(),
            $css_v
        );
        wp_enqueue_script(
            'azure-orders-reports-builder',
            AZURE_PLUGIN_URL . 'js/orders-reports-builder.js',
            array('jquery', 'jquery-ui-sortable'),
            $js_v,
            true
        );
        wp_localize_script('azure-orders-reports-builder', 'azureOR', array(
            'ajaxurl'        => admin_url('admin-ajax.php'),
            'adminpost'      => admin_url('admin-post.php'),
            'nonces'         => array(
                'preview'  => wp_create_nonce('azure_or_preview'),
                'save'     => wp_create_nonce('azure_or_save'),
                'delete'   => wp_create_nonce('azure_or_delete'),
                'dup'      => wp_create_nonce('azure_or_duplicate'),
                'export'   => wp_create_nonce('azure_or_export'),
            ),
        ));
    }

    // ── Shared cap check ───────────────────────────────────────────────

    private function require_cap_ajax() {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'azure-plugin')), 403);
        }
    }

    private function require_cap_die() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Insufficient permissions.', 'azure-plugin'), 403);
        }
    }

    // ── AJAX handlers ──────────────────────────────────────────────────

    public function ajax_preview() {
        $this->require_cap_ajax();
        check_ajax_referer('azure_or_preview', 'nonce');

        $config = $this->config_from_post();
        $config = Azure_Orders_Reports_Storage::sanitize_config($config);

        $registry = Azure_Orders_Reports_Columns::all();
        $selected = $this->resolve_selected_columns($config, $registry);

        $rows = array();
        $query = new Azure_Orders_Reports_Query();
        $total = 0;
        foreach ($query->iter($config) as $tuple) {
            $total++;
            if (count($rows) < 100) {
                list($order, $item) = $tuple;
                $row = array();
                foreach ($selected as $col) {
                    try {
                        $row[$col['key']] = (string) call_user_func($col['resolver'], $order, $item, array());
                    } catch (\Throwable $e) {
                        $row[$col['key']] = '';
                    }
                }
                $rows[] = $row;
            }
        }

        ob_start();
        $this->render_preview_html($selected, $rows, $total);
        $html = ob_get_clean();

        wp_send_json_success(array(
            'html'     => $html,
            'total'    => $total,
            'previewed'=> count($rows),
        ));
    }

    public function ajax_save() {
        $this->require_cap_ajax();
        check_ajax_referer('azure_or_save', 'nonce');

        // Form field is `report_name` (matches the builder UI + the export
        // handler). Falls back to `name` for any caller that still sends the
        // older key.
        $name = isset($_POST['report_name']) ? (string) wp_unslash($_POST['report_name'])
              : (isset($_POST['name']) ? (string) wp_unslash($_POST['name']) : '');
        $report_id = isset($_POST['report_id']) ? absint($_POST['report_id']) : 0;
        $config    = $this->config_from_post();
        $result    = Azure_Orders_Reports_Storage::save($name, $config, $report_id);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        wp_send_json_success(array(
            'report_id' => (int) $result,
            'message'   => __('Report saved.', 'azure-plugin'),
        ));
    }

    public function ajax_delete() {
        $this->require_cap_ajax();
        check_ajax_referer('azure_or_delete', 'nonce');
        $report_id = isset($_POST['report_id']) ? absint($_POST['report_id']) : 0;
        $ok = Azure_Orders_Reports_Storage::delete($report_id);
        if (!$ok) {
            wp_send_json_error(array('message' => __('Could not delete report.', 'azure-plugin')));
        }
        wp_send_json_success(array('message' => __('Report deleted.', 'azure-plugin')));
    }

    public function ajax_duplicate() {
        $this->require_cap_ajax();
        check_ajax_referer('azure_or_duplicate', 'nonce');
        $report_id = isset($_POST['report_id']) ? absint($_POST['report_id']) : 0;
        $result = Azure_Orders_Reports_Storage::duplicate($report_id);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        wp_send_json_success(array('report_id' => (int) $result));
    }

    /**
     * Autocomplete endpoint for the Reports → Products filter.
     *
     * Drop-in replacement for WC's stock
     * `woocommerce_json_search_products` AJAX, with three key
     * differences appropriate to an admin reporting screen:
     *
     *   1. **Includes non-publish products.** WC's stock endpoint
     *      hard-locks to post_status=publish, so a yearbook in
     *      Draft (typical when last year's product is unpublished
     *      pending the new year's variant) can't be selected as a
     *      filter even though orders referencing it still exist
     *      in the database. We include publish + draft + pending
     *      + private (everything except trash + auto-draft).
     *   2. **Searches title, excerpt, content, SKU, and post ID.**
     *      Same surfaces WC searches, plus exact post-ID match.
     *   3. **Tags non-publish results in the dropdown label** so
     *      admins can tell at a glance which products are live vs
     *      draft when picking filters.
     *
     * Reuses WC's `search-products` nonce so it works with the
     * stock `wc-enhanced-select` Select2 wrapper — meaning the
     * form just needs `data-action="azure_or_search_products"`
     * and everything else (Select2 init, multi-select, AJAX
     * polling, nonce) is provided by the WC admin asset bundle
     * that's already enqueued on this screen.
     *
     * Response shape matches WC's: a plain keyed JSON object of
     * `{ "<post_id>": "Display label" }` consumed by WC's
     * Select2 `processResults` adaptor.
     */
    public function ajax_search_products() {
        $this->require_cap_ajax();

        // WC's wc-enhanced-select bundle posts the nonce as
        // `security` and creates it with action 'search-products'.
        // Validate against that so the existing Select2 wiring on
        // the Reports page works without bespoke JS.
        check_ajax_referer('search-products', 'security');

        // WC's Select2 uses POST with key `term`. Tolerate `q` for
        // back-compat with any caller that might still hit the old
        // shape.
        $term = '';
        if (isset($_POST['term']))      $term = (string) $_POST['term'];
        elseif (isset($_GET['term']))   $term = (string) $_GET['term'];
        elseif (isset($_GET['q']))      $term = (string) $_GET['q'];
        $term = sanitize_text_field(wp_unslash($term));

        if (strlen($term) < 1) {
            wp_send_json(new stdClass()); // empty keyed object
        }

        global $wpdb;
        $like        = '%' . $wpdb->esc_like($term) . '%';
        $statuses    = array('publish', 'draft', 'pending', 'private');
        $status_in   = "'" . implode("','", array_map('esc_sql', $statuses)) . "'";
        $term_as_int = ctype_digit($term) ? (int) $term : 0;

        // Single query covering title / excerpt / content / SKU /
        // exact post_id. LEFT JOIN on _sku meta so products
        // without an SKU still match the other clauses.
        $sql = $wpdb->prepare(
            "SELECT DISTINCT p.ID, p.post_status
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
             WHERE p.post_type = 'product'
               AND p.post_status IN ({$status_in})
               AND (
                   p.post_title    LIKE %s
                   OR p.post_excerpt LIKE %s
                   OR p.post_content LIKE %s
                   OR sku.meta_value LIKE %s
                   OR p.ID = %d
               )
             ORDER BY (p.post_status = 'publish') DESC, p.post_title ASC
             LIMIT 30",
            $like, $like, $like, $like, $term_as_int
        );
        $rows = $wpdb->get_results($sql);

        $out = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $pid    = (int) $row->ID;
                $status = (string) $row->post_status;
                $title  = get_the_title($pid);
                if ($title === '') $title = '(no title)';

                $label = $title . ' (#' . $pid . ')';
                if ($status !== 'publish') {
                    // Surface status as a parenthetical so the
                    // dropdown doesn't silently mix Draft/Pending
                    // entries with live products.
                    $status_label = ucfirst($status);
                    $label = $title . ' — ' . $status_label . ' (#' . $pid . ')';
                }

                // Append SKU if present and not already shown.
                $sku = get_post_meta($pid, '_sku', true);
                if (is_string($sku) && $sku !== '' && stripos($label, $sku) === false) {
                    $label .= ' [' . $sku . ']';
                }

                $out[$pid] = wp_strip_all_tags($label);
            }
        }

        // Match WC's response shape exactly (plain keyed object,
        // not the success/data envelope) so wc-enhanced-select's
        // Select2 processResults adapter consumes it directly.
        wp_send_json($out);
    }

    // ── admin-post (form) handlers ─────────────────────────────────────

    public function handle_export() {
        $this->require_cap_die();
        if (!wp_verify_nonce(isset($_POST['_wpnonce']) ? $_POST['_wpnonce'] : '', 'azure_or_export')) {
            wp_die(esc_html__('Security check failed.', 'azure-plugin'), 403);
        }
        $config = Azure_Orders_Reports_Storage::sanitize_config($this->config_from_post());
        $name   = isset($_POST['report_name']) ? sanitize_text_field((string) $_POST['report_name']) : 'orders-report';
        (new Azure_Orders_Reports_Export())->stream_download($config, $name);
    }

    public function handle_export_saved() {
        $this->require_cap_die();
        $report_id = isset($_POST['report_id']) ? absint($_POST['report_id']) : 0;
        if (!wp_verify_nonce(isset($_POST['_wpnonce']) ? $_POST['_wpnonce'] : '', 'azure_or_export_saved_' . $report_id)) {
            wp_die(esc_html__('Security check failed.', 'azure-plugin'), 403);
        }
        $loaded = Azure_Orders_Reports_Storage::load($report_id);
        if (!$loaded) {
            wp_die(esc_html__('Report not found.', 'azure-plugin'), 404);
        }

        $config = $loaded['config'];

        // `run_as_of=today` (sent by the dashboard widget's Export button)
        // forces the report's end date to right-now, regardless of any
        // explicit `to` saved in the report config. Lets a parent click
        // Export on the dashboard and get a "through today" CSV without
        // having to open the builder and bump the date. Presets like
        // `this_school_year` / `last_7_days` already roll forward to
        // today by design, so this flag is only useful for reports with
        // explicit-date ranges.
        if (isset($_POST['run_as_of']) && (string) $_POST['run_as_of'] === 'today') {
            $now = current_time('mysql');
            if (!isset($config['date_range']) || !is_array($config['date_range'])) {
                $config['date_range'] = array('from' => null, 'to' => $now, 'preset' => null);
            } else {
                $config['date_range']['to']     = $now;
                $config['date_range']['preset'] = null; // explicit `to` always wins
            }
        }

        // Counting before streaming so we can persist last_exported_rows.
        $query  = new Azure_Orders_Reports_Query();
        $rows   = $query->count($config);
        Azure_Orders_Reports_Storage::mark_exported($report_id, $rows);
        (new Azure_Orders_Reports_Export())->stream_download($config, $loaded['name']);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * Pull the report config out of $_POST. Tolerant to either flat
     * (date_from, date_to, statuses[], …) or nested encoding.
     *
     * IMPORTANT: WordPress applies magic-quotes to all $_POST data on
     * every request, so apostrophes (and any other special character)
     * arrive escaped with a leading backslash. We must `wp_unslash()`
     * BEFORE comparing against the column registry — otherwise keys
     * like `product_field:Child's Name` arrive here as
     * `product_field:Child\'s Name` and fail every lookup, dropping
     * the column from the export silently.
     */
    private function config_from_post() {
        $statuses     = isset($_POST['statuses'])     ? wp_unslash((array) $_POST['statuses'])     : array();
        $product_ids  = isset($_POST['product_ids'])  ? wp_unslash((array) $_POST['product_ids'])  : array();
        $category_ids = isset($_POST['category_ids']) ? wp_unslash((array) $_POST['category_ids']) : array();
        $tag_ids      = isset($_POST['tag_ids'])      ? wp_unslash((array) $_POST['tag_ids'])      : array();
        $columns      = isset($_POST['columns'])      ? wp_unslash((array) $_POST['columns'])      : array();
        $granularity  = isset($_POST['granularity'])  ? (string) wp_unslash($_POST['granularity']) : 'line_item';

        $preset   = isset($_POST['date_preset'])   ? (string) wp_unslash($_POST['date_preset']) : '';
        $from     = isset($_POST['date_from'])     ? (string) wp_unslash($_POST['date_from'])   : '';
        $to       = isset($_POST['date_to'])       ? (string) wp_unslash($_POST['date_to'])     : '';
        $to_today = !empty($_POST['date_to_today']);

        return array(
            'date_range' => array(
                'from'     => $from,
                'to'       => $to,
                'preset'   => $preset,
                'to_today' => $to_today,
            ),
            'filters' => array(
                'statuses'     => $statuses,
                'product_ids'  => $product_ids,
                'category_ids' => $category_ids,
                'tag_ids'      => $tag_ids,
            ),
            'granularity' => $granularity,
            'columns'     => $columns,
        );
    }

    private function resolve_selected_columns(array $config, array $registry) {
        $granularity = isset($config['granularity']) ? (string) $config['granularity'] : 'line_item';
        $out = array();
        foreach ((array) ($config['columns'] ?? array()) as $entry) {
            $key = is_array($entry) ? (isset($entry['key']) ? (string) $entry['key'] : '') : (string) $entry;
            if (!isset($registry[$key])) continue;
            $col = $registry[$key];
            if (!in_array($granularity, $col['granularity'], true)) continue;
            $out[] = $col;
        }
        if (empty($out)) {
            foreach (Azure_Orders_Reports_Columns::default_columns_for_granularity($granularity) as $k) {
                if (isset($registry[$k])) {
                    $out[] = $registry[$k];
                }
            }
        }
        return $out;
    }

    private function render_preview_html(array $selected, array $rows, $total) {
        ?>
        <div class="azure-or-preview-summary">
            <strong><?php printf(esc_html__('Matched %d row(s) total.', 'azure-plugin'), (int) $total); ?></strong>
            <?php if ($total > count($rows)): ?>
                <em><?php printf(esc_html__('Showing first %d.', 'azure-plugin'), count($rows)); ?></em>
            <?php endif; ?>
        </div>
        <?php if (empty($rows)): ?>
            <p><em><?php _e('No matching orders. Adjust filters and try again.', 'azure-plugin'); ?></em></p>
            <?php return;
        endif; ?>
        <div style="overflow:auto; max-height:480px; border:1px solid #ddd;">
            <table class="wp-list-table widefat fixed striped" style="margin:0;">
                <thead>
                    <tr>
                    <?php foreach ($selected as $c): ?>
                        <th><?php echo esc_html($c['label']); ?></th>
                    <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <?php foreach ($selected as $c): ?>
                                <td><?php echo esc_html(isset($r[$c['key']]) ? $r[$c['key']] : ''); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
