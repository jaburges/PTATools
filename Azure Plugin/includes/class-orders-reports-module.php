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
        $name = isset($_POST['report_name']) ? (string) $_POST['report_name']
              : (isset($_POST['name']) ? (string) $_POST['name'] : '');
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
     * Autocomplete endpoint for the Products filter.
     */
    public function ajax_search_products() {
        $this->require_cap_ajax();
        check_ajax_referer('azure_or_search_products', 'nonce');
        $q = isset($_GET['q']) ? sanitize_text_field((string) $_GET['q']) : '';
        if (strlen($q) < 2) {
            wp_send_json_success(array('items' => array()));
        }
        $ids = get_posts(array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            's'              => $q,
            'posts_per_page' => 20,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ));
        $items = array();
        foreach ($ids as $pid) {
            $items[] = array(
                'id'   => (int) $pid,
                'text' => get_the_title($pid) . ' (#' . $pid . ')',
            );
        }
        wp_send_json_success(array('items' => $items));
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
        // Counting before streaming so we can persist last_exported_rows.
        $query  = new Azure_Orders_Reports_Query();
        $rows   = $query->count($loaded['config']);
        Azure_Orders_Reports_Storage::mark_exported($report_id, $rows);
        (new Azure_Orders_Reports_Export())->stream_download($loaded['config'], $loaded['name']);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * Pull the report config out of $_POST. Tolerant to either flat
     * (date_from, date_to, statuses[], …) or nested encoding.
     */
    private function config_from_post() {
        $statuses     = isset($_POST['statuses'])     ? (array) $_POST['statuses']     : array();
        $product_ids  = isset($_POST['product_ids'])  ? (array) $_POST['product_ids']  : array();
        $category_ids = isset($_POST['category_ids']) ? (array) $_POST['category_ids'] : array();
        $tag_ids      = isset($_POST['tag_ids'])      ? (array) $_POST['tag_ids']      : array();
        $columns      = isset($_POST['columns'])      ? (array) $_POST['columns']      : array();
        $granularity  = isset($_POST['granularity'])  ? (string) $_POST['granularity'] : 'line_item';

        $preset = isset($_POST['date_preset']) ? (string) $_POST['date_preset'] : '';
        $from   = isset($_POST['date_from'])   ? (string) $_POST['date_from']   : '';
        $to     = isset($_POST['date_to'])     ? (string) $_POST['date_to']     : '';

        return array(
            'date_range' => array(
                'from'   => $from,
                'to'     => $to,
                'preset' => $preset,
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
