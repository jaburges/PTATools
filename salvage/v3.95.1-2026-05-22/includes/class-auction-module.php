<?php
/**
 * Auction Module - Main Module Class
 *
 * WooCommerce auction products with manual bidding, Buy It Now, and winner checkout.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Auction_Module {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        $this->load_dependencies();
        $this->init_hooks();

        if (class_exists('Azure_Logger')) {
            Azure_Logger::debug_module('Auction', 'Auction module initialized');
        }
    }

    public function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p><strong>' . esc_html__('Auction Module:', 'azure-plugin') . '</strong> ' . esc_html__('WooCommerce is required.', 'azure-plugin') . '</p></div>';
    }

    private function load_dependencies() {
        $files = array(
            'class-auction-product.php',
            'class-auction-product-type.php',
            'class-auction-bids.php',
            'class-auction-emails.php',
            'class-auction-lifecycle.php',
            'class-auction-winners-report.php',
        );
        foreach ($files as $file) {
            $path = AZURE_PLUGIN_PATH . 'includes/' . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (class_exists('Azure_Auction_Product_Type')) {
            new Azure_Auction_Product_Type();
        }
    }

    private function init_hooks() {
        add_action('wp_ajax_azure_auction_place_bid', array($this, 'ajax_place_bid'));
        add_action('wp_ajax_nopriv_azure_auction_place_bid', array($this, 'ajax_place_bid_guest'));
        add_action('wp_ajax_azure_auction_get_bid_history', array($this, 'ajax_get_bid_history'));
        add_action('wp_ajax_nopriv_azure_auction_get_bid_history', array($this, 'ajax_get_bid_history_public'));
        add_action('wp_ajax_azure_auction_buy_it_now', array($this, 'ajax_buy_it_now'));
        add_action('wp_ajax_nopriv_azure_auction_buy_it_now', array($this, 'ajax_buy_it_now_guest'));

        // Auction night display page (shortcode + batched price refresh)
        add_shortcode('auction-display', array($this, 'shortcode_auction_display'));
        add_action('wp_ajax_azure_auction_display_prices', array($this, 'ajax_display_prices'));
        add_action('wp_ajax_nopriv_azure_auction_display_prices', array($this, 'ajax_display_prices'));

        add_action('admin_post_azure_auction_create_te_runner_up_orders', array($this, 'handle_create_te_runner_up_orders'));
        add_action('admin_post_azure_auction_resend_invoice',             array($this, 'handle_resend_invoice'));
        add_action('admin_post_azure_auction_resend_all_unpaid',          array($this, 'handle_resend_all_unpaid'));

        add_action('woocommerce_single_product_summary', array($this, 'remove_auction_add_to_cart'), 5);
        add_action('woocommerce_single_product_summary', array($this, 'maybe_process_ended_auction'), 1);
        add_action('woocommerce_single_product_summary', array($this, 'render_single_product_auction'), 35);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_auction_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_display_scripts'));

        // WP-Cron handlers. Per-auction one-shot is scheduled at the auction's
        // bidding-end timestamp by Azure_Auction_Product_Type::save_product_meta.
        // The orphan sweep is a daily safety-net for auctions that never got a
        // one-shot scheduled (legacy data, lost cron rows, env restores).
        // Both bind globally so WP-Cron can reach them on any context.
        add_action('azure_auction_finalize', array($this, 'cron_finalize'), 10, 1);
        add_action('azure_auction_finalize_orphans', array($this, 'cron_finalize_orphans'));

        // Admin dashboard widget (Active / Staged / Bids / Total $$). Only
        // registered on the dashboard screen so it costs nothing elsewhere.
        if (is_admin()) {
            add_action('wp_dashboard_setup', array($this, 'register_dashboard_widget'));
        }
    }

    /**
     * Shared cap+nonce check for the resend-invoice handlers.
     */
    private function check_invoice_cap_or_die($nonce_action) {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'azure-plugin'), 403);
        }
        $nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, $nonce_action)) {
            wp_die(esc_html__('Security check failed.', 'azure-plugin'), 403);
        }
    }

    /**
     * admin-post handler: resend WC Customer Invoice email for ONE order.
     */
    public function handle_resend_invoice() {
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $this->check_invoice_cap_or_die('azure_auction_resend_invoice_' . $order_id);

        if (!$order_id) {
            wp_die(esc_html__('Missing order_id.', 'azure-plugin'), 400);
        }
        if (!class_exists('Azure_Auction_Winners_Report')) {
            wp_die(esc_html__('Winners report class not loaded.', 'azure-plugin'), 500);
        }

        $report = new Azure_Auction_Winners_Report();
        $r      = $report->resend_customer_invoice($order_id);

        $state = $r['result'] === 'sent' ? 'success' : 'error';
        if ($r['result'] === 'sent') {
            $msg = sprintf(__('Invoice email sent to %s for order #%d.', 'azure-plugin'), $r['to'], $order_id);
        } else {
            $msg = sprintf(__('Could not send invoice for order #%1$d: %2$s', 'azure-plugin'), $order_id, $r['error'] ?? $r['result']);
        }

        $redirect = wp_get_referer() ?: admin_url('admin.php?page=azure-plugin-selling&tab=auction');
        $redirect = add_query_arg(array(
            'azure_invoice_msg'   => rawurlencode($msg),
            'azure_invoice_state' => $state,
        ), $redirect);
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * admin-post handler: bulk-resend WC Customer Invoice email to every unpaid auction order.
     */
    public function handle_resend_all_unpaid() {
        $this->check_invoice_cap_or_die('azure_auction_resend_all_unpaid');

        if (!class_exists('Azure_Auction_Winners_Report')) {
            wp_die(esc_html__('Winners report class not loaded.', 'azure-plugin'), 500);
        }

        $report  = new Azure_Auction_Winners_Report();
        $summary = $report->resend_invoices_for_unpaid_auctions();
        $t       = $summary['totals'];

        $msg = sprintf(
            __('Bulk invoice resend: eligible=%1$d sent=%2$d skipped=%3$d errors=%4$d', 'azure-plugin'),
            (int) $t['eligible'], (int) $t['sent'], (int) $t['skipped'], (int) $t['errors']
        );
        $state = $t['errors'] > 0 ? 'error' : ($t['sent'] > 0 ? 'success' : 'warning');

        $redirect = wp_get_referer() ?: admin_url('admin.php?page=azure-plugin-selling&tab=auction');
        $redirect = add_query_arg(array(
            'azure_invoice_msg'   => rawurlencode($msg),
            'azure_invoice_state' => $state,
        ), $redirect);
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * admin-post handler: create wc-pending orders + invoice emails for TE 2nd/3rd place.
     */
    public function handle_create_te_runner_up_orders() {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'azure-plugin'), 403);
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $nonce      = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';

        if (!$product_id || !wp_verify_nonce($nonce, 'azure_auction_te_runner_up_' . $product_id)) {
            wp_die(esc_html__('Security check failed.', 'azure-plugin'), 403);
        }

        if (!class_exists('Azure_Auction_Winners_Report')) {
            wp_die(esc_html__('Winners report class not loaded.', 'azure-plugin'), 500);
        }

        $report  = new Azure_Auction_Winners_Report();
        $summary = $report->create_runner_up_orders($product_id);

        $totals = $summary['totals'];
        $msg = sprintf(
            __('TE runner-up run for #%1$d: created=%2$d emailed=%3$d skipped=%4$d errors=%5$d', 'azure-plugin'),
            $product_id,
            (int) $totals['created'],
            (int) $totals['emailed'],
            (int) $totals['skipped'],
            (int) $totals['errors']
        );

        $redirect = wp_get_referer();
        if (!$redirect) {
            $redirect = admin_url('admin.php?page=azure-plugin-selling&tab=auction');
        }
        $redirect = add_query_arg(array(
            'azure_te_msg'   => rawurlencode($msg),
            'azure_te_state' => $totals['errors'] > 0 ? 'error' : 'success',
            'azure_te_pid'   => $product_id,
        ), $redirect);

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Register the auction dashboard widget on wp-admin/index.php. Cheap
     * — the four COUNT/SUM queries only run when the widget actually
     * renders.
     */
    public function register_dashboard_widget() {
        if (!current_user_can('manage_options')) {
            return;
        }
        wp_add_dashboard_widget(
            'azure_auction_stats',
            __('Auctions', 'azure-plugin'),
            array($this, 'render_dashboard_widget')
        );
    }

    /**
     * Render the Auctions dashboard widget.
     *
     * Status definitions:
     *   - Active: published auction product whose bidding window is still
     *     open (bidding_end empty or > NOW).
     *   - Staged: scheduled-future auction product (post_status = 'future')
     *     — same definition the Auction admin page uses.
     *   - Bids: total rows in azure_auction_bids.
     *   - Total $$: running fundraising tally =
     *       (a) MAX(bid_amount) per still-running auction product, plus
     *       (b) `_auction_winning_amount` for naturally-ended auctions, plus
     *       (c) `_auction_buy_it_now_price` for sold Buy-It-Now lots.
     *     Before any auctions end, (a) is the live in-flight total; once
     *     auctions close it rolls into (b)/(c) so the number never goes
     *     backwards.
     *
     * All four counters are derived from existing post_meta / custom-table
     * data — no new schema needed.
     */
    public function render_dashboard_widget() {
        global $wpdb;

        $stats = array(
            'active'  => 0,
            'staged'  => 0,
            'bids'    => 0,
            'revenue' => 0.0,
        );

        $bids_table = Azure_Database::get_table_name('auction_bids');
        $bids_table_exists = $bids_table
            && $wpdb->get_var("SHOW TABLES LIKE '{$bids_table}'") === $bids_table;

        if (class_exists('WooCommerce')) {
            $stats['active'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_auction_bidding_end'
                 LEFT JOIN {$wpdb->postmeta} pms ON pms.post_id = p.ID AND pms.meta_key = '_auction_status'
                 WHERE p.post_type = 'product' AND p.post_status = 'publish'
                   AND (pm.meta_value = '' OR pm.meta_value > NOW())
                   AND (pms.meta_value IS NULL OR pms.meta_value NOT IN ('ended','sold'))"
            );

            $stats['staged'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_auction_bidding_end'
                 WHERE p.post_type = 'product' AND p.post_status = 'future'"
            );

            // Total $$: running tally that includes both completed
            // revenue (ended + sold) and the highest current bid on
            // every still-open auction. Without the live component,
            // the widget reads "$26" until the night the auction
            // closes — useless during the bidding window. One UNION
            // query keeps this to a single round-trip.
            $live_select = '';
            if ($bids_table_exists) {
                $live_select = "
                    SELECT MAX(b.bid_amount) AS amt
                      FROM {$bids_table} b
                      INNER JOIN {$wpdb->posts} p
                          ON p.ID = b.product_id
                         AND p.post_type = 'product'
                         AND p.post_status = 'publish'
                      INNER JOIN {$wpdb->postmeta} pm_end
                          ON pm_end.post_id = p.ID AND pm_end.meta_key = '_auction_bidding_end'
                      LEFT JOIN {$wpdb->postmeta} pm_status
                          ON pm_status.post_id = p.ID AND pm_status.meta_key = '_auction_status'
                      WHERE (pm_end.meta_value = '' OR pm_end.meta_value > NOW())
                        AND (pm_status.meta_value IS NULL OR pm_status.meta_value NOT IN ('ended','sold'))
                      GROUP BY b.product_id
                    UNION ALL
                ";
            }
            $revenue = (float) $wpdb->get_var(
                "SELECT COALESCE(SUM(amt), 0) FROM (
                    {$live_select}
                    SELECT CAST(pm.meta_value AS DECIMAL(12,2)) AS amt
                      FROM {$wpdb->postmeta} pm
                      INNER JOIN {$wpdb->postmeta} pms
                          ON pms.post_id = pm.post_id AND pms.meta_key = '_auction_status' AND pms.meta_value = 'ended'
                      WHERE pm.meta_key = '_auction_winning_amount'
                    UNION ALL
                    SELECT CAST(pm.meta_value AS DECIMAL(12,2)) AS amt
                      FROM {$wpdb->postmeta} pm
                      INNER JOIN {$wpdb->postmeta} pms
                          ON pms.post_id = pm.post_id AND pms.meta_key = '_auction_status' AND pms.meta_value = 'sold'
                      WHERE pm.meta_key = '_auction_buy_it_now_price'
                ) revenue_rows"
            );
            $stats['revenue'] = $revenue;
        }

        if ($bids_table_exists) {
            $stats['bids'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$bids_table}");
        }

        $admin_url = admin_url('admin.php?page=azure-plugin-selling&tab=auction');
        ?>
        <style>
            .azure-auction-widget .stat-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 12px; }
            .azure-auction-widget .stat-card { background: #f9f9f9; padding: 12px; text-align: center; border-radius: 4px; border-left: 3px solid #0078d4; }
            .azure-auction-widget .stat-card .stat-number { font-size: 22px; font-weight: 700; color: #1d2327; line-height: 1.2; }
            .azure-auction-widget .stat-card .stat-label { font-size: 11px; color: #646970; text-transform: uppercase; letter-spacing: 0.3px; }
            .azure-auction-widget .stat-card.active { border-left-color: #00a32a; }
            .azure-auction-widget .stat-card.staged { border-left-color: #dba617; }
            .azure-auction-widget .stat-card.revenue { border-left-color: #2271b1; }
            .azure-auction-widget .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        </style>
        <div class="azure-auction-widget">
            <div class="stat-grid">
                <div class="stat-card active">
                    <div class="stat-number"><?php echo number_format($stats['active']); ?></div>
                    <div class="stat-label"><?php _e('Active', 'azure-plugin'); ?></div>
                </div>
                <div class="stat-card staged">
                    <div class="stat-number"><?php echo number_format($stats['staged']); ?></div>
                    <div class="stat-label"><?php _e('Staged', 'azure-plugin'); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['bids']); ?></div>
                    <div class="stat-label"><?php _e('Total Bids', 'azure-plugin'); ?></div>
                </div>
                <div class="stat-card revenue" title="<?php esc_attr_e('Includes the highest current bid on every still-open auction plus completed sales (winning amount + buy-it-now). Updates as bids come in.', 'azure-plugin'); ?>">
                    <div class="stat-number"><?php echo function_exists('wc_price') ? wp_kses_post(wc_price($stats['revenue'])) : '$' . number_format($stats['revenue'], 2); ?></div>
                    <div class="stat-label"><?php _e('Total $$', 'azure-plugin'); ?></div>
                </div>
            </div>
            <div class="actions">
                <a href="<?php echo esc_url($admin_url); ?>" class="button button-primary"><?php _e('Manage Auctions', 'azure-plugin'); ?></a>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=product')); ?>" class="button"><?php _e('Products', 'azure-plugin'); ?></a>
            </div>
        </div>
        <?php
    }

    public function cron_finalize($product_id) {
        if (class_exists('Azure_Auction_Lifecycle')) {
            (new Azure_Auction_Lifecycle())->cron_finalize((int) $product_id);
        }
    }

    public function cron_finalize_orphans() {
        if (class_exists('Azure_Auction_Lifecycle')) {
            (new Azure_Auction_Lifecycle())->finalize_orphans();
        }
    }

    public function maybe_process_ended_auction() {
        try {
            global $product;
            if (!$product instanceof WC_Product || $product->get_type() !== 'auction') {
                return;
            }
            if (class_exists('Azure_Auction_Lifecycle')) {
                (new Azure_Auction_Lifecycle())->maybe_process_ended_auction($product->get_id());
            }
        } catch (\Throwable $e) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::error('Auction maybe_process_ended_auction error: ' . $e->getMessage());
            }
        }
    }

    public function remove_auction_add_to_cart() {
        global $product;
        if ($product instanceof WC_Product && $product->get_type() === 'auction') {
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
        }
    }

    public function render_single_product_auction() {
        global $product;
        if (!$product instanceof WC_Product || $product->get_type() !== 'auction') {
            return;
        }
        $product_id = $product->get_id();
        if ($product->is_auction_ended() || in_array($product->get_auction_status(), array('ended', 'sold'), true)) {
            echo '<p class="auction-ended">' . esc_html__('This auction has ended.', 'azure-plugin') . '</p>';
            return;
        }
        $logged_in = is_user_logged_in();
        $bids = class_exists('Azure_Auction_Bids') ? (new Azure_Auction_Bids())->get_masked_bid_history($product_id) : array('bids' => array(), 'current_price' => $product->get_regular_price());
        $current_price = isset($bids['current_price']) ? (float) $bids['current_price'] : (float) $product->get_regular_price();
        $has_bids = !empty($bids['bids']);
        $price_label = $has_bids ? __('Current bid:', 'azure-plugin') : __('Starting bid:', 'azure-plugin');

        $end_raw = $product->get_auction_bidding_end();
        $end_ts = 0;
        if ($end_raw) {
            $end_ts = is_numeric($end_raw) ? (int) $end_raw : strtotime($end_raw);
        }

        // Compute tiered next-min + quick-bid increments for the bid form.
        // First-bid case: required = starting bid (the regular price). Otherwise:
        // required = current_price + tier increment.
        $next_increment = class_exists('Azure_Auction_Bids')
            ? Azure_Auction_Bids::get_increment($current_price)
            : 5.0;
        $next_min_bid = $has_bids ? ($current_price + $next_increment) : $current_price;
        $quick_increments = class_exists('Azure_Auction_Bids')
            ? Azure_Auction_Bids::get_quick_bid_increments($current_price)
            : array(5.0, 10.0, 20.0);
        ?>
        <div class="azure-auction-bid-wrapper" data-product-id="<?php echo esc_attr($product_id); ?>">
            <div class="auction-info-bar">
                <div class="auction-current-price">
                    <span class="auction-price-label"><?php echo esc_html($price_label); ?></span>
                    <span class="auction-price-value"><?php echo wc_price($current_price); ?></span>
                </div>
                <?php if ($end_ts > 0) : ?>
                <div class="auction-countdown" data-end="<?php echo esc_attr($end_ts); ?>">
                    <span class="auction-countdown-label"><?php _e('Ends in:', 'azure-plugin'); ?></span>
                    <span class="auction-countdown-timer"></span>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($product->is_buy_it_now_enabled() && $product->get_buy_it_now_price() > 0) : ?>
            <p class="auction-buy-it-now">
                <button type="button" class="button auction-buy-it-now-btn"><?php printf(__('Buy It Now for %s', 'azure-plugin'), wc_price($product->get_buy_it_now_price())); ?></button>
            </p>
            <?php endif; ?>
            <?php if ($logged_in) : ?>
            <div class="auction-bid-form">
                <label for="auction-bid-amount"><?php _e('Your bid', 'azure-plugin'); ?></label>
                <div class="auction-bid-controls">
                    <input type="number" id="auction-bid-amount" class="auction-bid-amount" min="0" step="0.01" value="<?php echo esc_attr($next_min_bid); ?>" />
                    <div class="auction-quick-buttons">
                        <?php foreach ($quick_increments as $qi) : ?>
                        <button type="button" class="button auction-quick-bid" data-increment="<?php echo esc_attr($qi); ?>">+$<?php echo esc_html(rtrim(rtrim(number_format((float) $qi, 2, '.', ''), '0'), '.')); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="button" class="button alt auction-place-bid"><?php _e('Place bid', 'azure-plugin'); ?></button>
                <span class="auction-bid-message" style="display:none;"></span>
            </div>
            <?php else : ?>
            <p class="auction-login-required">
                <?php
                // wp_registration_url() does not accept a redirect target,
                // but wp-login.php's register handler honors a redirect_to
                // query arg and propagates it through register -> login on
                // the user's behalf. Append the auction permalink so a new
                // signup lands back on this product page instead of a
                // generic "registered" screen.
                $register_url = add_query_arg(
                    'redirect_to',
                    urlencode(get_permalink()),
                    wp_registration_url()
                );
                printf(
                    __('Please %slog in%s or %sregister%s to place a bid.', 'azure-plugin'),
                    '<a href="' . esc_url(wp_login_url(get_permalink())) . '">',
                    '</a>',
                    '<a href="' . esc_url($register_url) . '">',
                    '</a>'
                ); ?>
            </p>
            <?php endif; ?>
            <div class="auction-bid-history">
                <h4><?php _e('Bid history', 'azure-plugin'); ?></h4>
                <table class="auction-bid-table" <?php echo !$has_bids ? 'style="display:none;"' : ''; ?>>
                    <thead>
                        <tr>
                            <th><?php _e('Bidder', 'azure-plugin'); ?></th>
                            <th><?php _e('Amount', 'azure-plugin'); ?></th>
                            <th><?php _e('Time', 'azure-plugin'); ?></th>
                        </tr>
                    </thead>
                    <tbody class="auction-bid-list">
                        <?php foreach (isset($bids['bids']) ? $bids['bids'] : array() as $b) : ?>
                        <tr>
                            <td><?php echo esc_html(isset($b['bidder']) ? $b['bidder'] : '***'); ?></td>
                            <td><?php echo wc_price(isset($b['amount']) ? $b['amount'] : 0); ?></td>
                            <td class="bid-time"><?php echo esc_html(isset($b['time']) ? $b['time'] : ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="no-bids" <?php echo $has_bids ? 'style="display:none;"' : ''; ?>><?php _e('No bids yet. Be the first to bid!', 'azure-plugin'); ?></p>
            </div>
        </div>
        <?php
    }

    public function enqueue_auction_scripts() {
        if (!is_product()) {
            return;
        }
        global $product;
        if (!$product instanceof WC_Product) {
            $product = wc_get_product(get_queried_object_id());
        }
        if (!$product instanceof WC_Product || $product->get_type() !== 'auction') {
            return;
        }
        wp_enqueue_style(
            'azure-auction-frontend',
            AZURE_PLUGIN_URL . 'css/auction-frontend.css',
            array(),
            defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : '1.0'
        );
        wp_enqueue_script(
            'azure-auction-bid',
            AZURE_PLUGIN_URL . 'js/auction-bid.js',
            array('jquery'),
            defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : '1.0',
            true
        );
        wp_localize_script('azure-auction-bid', 'azureAuction', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('azure_auction_bid'),
            'productId' => $product->get_id(),
            'i18n'    => array(
                'buyItNowConfirm' => __('Create order and go to checkout?', 'azure-plugin'),
            ),
        ));
    }

    public function ajax_place_bid_guest() {
        wp_send_json_error(array('message' => __('You must be logged in to bid.', 'azure-plugin')));
    }

    public function ajax_place_bid() {
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('You must be logged in to bid.', 'azure-plugin')));
        }
        if (!class_exists('Azure_Auction_Bids')) {
            wp_send_json_error(array('message' => __('Auction bids not available.', 'azure-plugin')));
        }
        $bids = new Azure_Auction_Bids();
        $result = $bids->place_bid();
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        wp_send_json_success($result);
    }

    public function ajax_get_bid_history_public() {
        $this->ajax_get_bid_history();
    }

    public function ajax_get_bid_history() {
        if (!class_exists('Azure_Auction_Bids')) {
            wp_send_json_success(array('bids' => array(), 'current_price' => 0));
        }
        $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
        if (!$product_id) {
            wp_send_json_error(array('message' => __('Invalid product.', 'azure-plugin')));
        }
        $bids = new Azure_Auction_Bids();
        $data = $bids->get_masked_bid_history($product_id);
        wp_send_json_success($data);
    }

    public function ajax_buy_it_now_guest() {
        wp_send_json_error(array('message' => __('You must be logged in to use Buy It Now.', 'azure-plugin')));
    }

    /**
     * Shortcode: [auction-display]
     *
     * Renders a card grid of every active auction (bidding-end in the future or
     * empty) with title, image, masked top bidder, and current/starting bid.
     *
     * Visibility:
     *  - Always visible to users with manage_options (preview).
     *  - Otherwise gated by setting `auction_display_live` (false by default);
     *    public users see a "Coming soon" placeholder until the toggle flips.
     */
    public function shortcode_auction_display($atts) {
        if (!class_exists('WooCommerce')) {
            return '';
        }

        $settings = class_exists('Azure_Settings') ? Azure_Settings::get_all_settings() : array();
        $live = !empty($settings['auction_display_live']);
        $is_admin_user = current_user_can('manage_options');

        if (!$live && !$is_admin_user) {
            return '<div class="auction-display-coming-soon"><p>'
                . esc_html__('The auction goes live soon. Check back on auction night!', 'azure-plugin')
                . '</p></div>';
        }

        $items = $this->get_active_auction_display_items();

        $cfg = $this->get_display_layout_settings($settings);

        ob_start();
        ?>
        <div class="auction-display-wrapper"
             data-live="<?php echo $live ? '1' : '0'; ?>"
             data-cards-wide="<?php echo (int) $cfg['cards_wide']; ?>"
             data-cards-tall="<?php echo (int) $cfg['cards_tall']; ?>"
             data-slide-seconds="<?php echo (int) $cfg['slide_seconds']; ?>"
             style="--auction-cards-wide: <?php echo (int) $cfg['cards_wide']; ?>; --auction-cards-tall: <?php echo (int) $cfg['cards_tall']; ?>; --auction-card-scale: <?php echo number_format($cfg['card_scale'] / 100, 2, '.', ''); ?>;">
            <?php if ($is_admin_user && !$live) : ?>
            <div class="auction-display-preview-banner">
                <strong><?php esc_html_e('Preview mode', 'azure-plugin'); ?>:</strong>
                <?php esc_html_e('the public sees a "Coming soon" message. Toggle live in Selling > Auction.', 'azure-plugin'); ?>
            </div>
            <?php endif; ?>

            <?php if (empty($items)) : ?>
            <p class="auction-display-empty"><?php esc_html_e('No active auctions yet.', 'azure-plugin'); ?></p>
            <?php else :
                $per_page  = max(1, (int) $cfg['cards_wide'] * (int) $cfg['cards_tall']);
                $total     = count($items);
                $page_count = (int) ceil($total / $per_page);
            ?>
            <div class="auction-display-grid" data-page-count="<?php echo (int) $page_count; ?>">
                <?php foreach ($items as $idx => $item) :
                    $page_idx = (int) floor($idx / $per_page);
                ?>
                <a class="auction-card<?php echo $page_idx === 0 ? ' is-current' : ''; ?>"
                   data-product-id="<?php echo (int) $item['id']; ?>"
                   data-page="<?php echo (int) $page_idx; ?>"
                   href="<?php echo esc_url($item['link']); ?>">
                    <div class="auction-card-image">
                        <?php if (!empty($item['image'])) : ?>
                            <?php echo $item['image']; // Already escaped by wp_get_attachment_image ?>
                        <?php else : ?>
                            <div class="auction-card-no-image"></div>
                        <?php endif; ?>
                    </div>
                    <h3 class="auction-card-title"><?php echo esc_html($item['title']); ?></h3>
                    <div class="auction-card-bid">
                        <span class="auction-card-label">
                            <?php echo $item['has_bids']
                                ? esc_html__('Current bid', 'azure-plugin')
                                : esc_html__('Starting bid', 'azure-plugin'); ?>
                        </span>
                        <?php if ($item['has_bids']) : ?>
                        <span class="auction-card-bidder">(<span class="auction-card-bidder-name"><?php echo esc_html($item['bidder']); ?></span>)</span>
                        <?php endif; ?>
                        <span class="auction-card-price"><?php echo wp_kses_post(wc_price($item['price'])); ?></span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if ($page_count > 1) : ?>
            <div class="auction-display-pager" role="tablist" aria-label="<?php esc_attr_e('Auction pages', 'azure-plugin'); ?>">
                <?php for ($p = 0; $p < $page_count; $p++) : ?>
                <button type="button"
                        class="auction-display-dot<?php echo $p === 0 ? ' is-current' : ''; ?>"
                        data-page="<?php echo (int) $p; ?>"
                        aria-label="<?php echo esc_attr(sprintf(__('Show page %d of %d', 'azure-plugin'), $p + 1, $page_count)); ?>"></button>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Resolve display-layout settings with sane bounds and defaults.
     *
     * @param array $settings Output of Azure_Settings::get_all_settings().
     * @return array{card_scale:int,cards_wide:int,cards_tall:int,slide_seconds:int}
     */
    private function get_display_layout_settings($settings) {
        $card_scale    = isset($settings['auction_display_card_scale'])    ? (int) $settings['auction_display_card_scale']    : 80;
        $cards_wide    = isset($settings['auction_display_cards_wide'])    ? (int) $settings['auction_display_cards_wide']    : 4;
        $cards_tall    = isset($settings['auction_display_cards_tall'])    ? (int) $settings['auction_display_cards_tall']    : 3;
        $slide_seconds = isset($settings['auction_display_slide_seconds']) ? (int) $settings['auction_display_slide_seconds'] : 5;

        return array(
            'card_scale'    => max(30, min(100, $card_scale)),
            'cards_wide'    => max(1, min(8, $cards_wide)),
            'cards_tall'    => max(1, min(6, $cards_tall)),
            'slide_seconds' => max(2, min(120, $slide_seconds)),
        );
    }

    /**
     * AJAX: return a batched snapshot of current bid info for all active auctions.
     *
     * Single GROUP BY-style query (no per-product wc_get_product calls), so
     * 30s polling stays cheap even with 20+ items.
     */
    public function ajax_display_prices() {
        if (!class_exists('WooCommerce')) {
            wp_send_json_success(array('items' => array(), 'live' => false));
        }

        $settings = class_exists('Azure_Settings') ? Azure_Settings::get_all_settings() : array();
        $live = !empty($settings['auction_display_live']);
        $is_admin_user = current_user_can('manage_options');

        if (!$live && !$is_admin_user) {
            wp_send_json_success(array('items' => array(), 'live' => false));
        }

        $items = $this->get_active_auction_display_items();
        $payload = array();
        foreach ($items as $item) {
            $payload[] = array(
                'id'        => (int) $item['id'],
                'price'     => (float) $item['price'],
                'price_html' => wc_price($item['price']),
                'bidder'    => $item['bidder'],
                'has_bids'  => (bool) $item['has_bids'],
            );
        }

        wp_send_json_success(array(
            'items' => $payload,
            'live'  => $live,
        ));
    }

    /**
     * Build the active-auction display rows used by both the shortcode and the
     * AJAX refresh endpoint.
     *
     * Performs at most:
     *   1 WP_Query for active auction posts (post_type=product, type=auction,
     *     bidding-end in the future or empty)
     *   1 SELECT against the bids table for ALL of those product_ids
     *   1 IN-batched user lookup for masking
     *
     * No N+1 — explicitly avoids `wc_get_product` per item.
     *
     * @return array<int,array{id:int,title:string,image:string,price:float,has_bids:bool,bidder:string,link:string}>
     */
    public function get_active_auction_display_items() {
        global $wpdb;

        $now_mysql = current_time('mysql');

        $query_args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'tax_query'      => array(
                array(
                    'taxonomy' => 'product_type',
                    'field'    => 'slug',
                    'terms'    => 'auction',
                ),
            ),
            'meta_query'     => array(
                'relation' => 'OR',
                array(
                    'key'     => '_auction_bidding_end',
                    'value'   => $now_mysql,
                    'compare' => '>',
                    'type'    => 'DATETIME',
                ),
                array(
                    'key'     => '_auction_bidding_end',
                    'value'   => '',
                    'compare' => '=',
                ),
                array(
                    'key'     => '_auction_bidding_end',
                    'compare' => 'NOT EXISTS',
                ),
            ),
        );

        $q = new WP_Query($query_args);
        if (empty($q->posts)) {
            return array();
        }

        $product_ids = wp_list_pluck($q->posts, 'ID');
        $product_ids = array_map('intval', $product_ids);

        $bids_by_pid = $this->get_high_bids_for_products($product_ids);

        $user_ids = array();
        foreach ($bids_by_pid as $row) {
            if (!empty($row['user_id'])) {
                $user_ids[(int) $row['user_id']] = true;
            }
        }

        $logins_by_uid = $this->get_user_logins(array_keys($user_ids));

        $items = array();
        foreach ($q->posts as $post) {
            $pid = (int) $post->ID;

            $starting = (float) get_post_meta($pid, '_regular_price', true);
            if ($starting <= 0) {
                $starting = (float) get_post_meta($pid, '_price', true);
            }

            $bid = isset($bids_by_pid[$pid]) ? $bids_by_pid[$pid] : null;
            $has_bids = !empty($bid);
            $price = $has_bids ? (float) $bid['bid_amount'] : $starting;

            $bidder = '';
            if ($has_bids) {
                $uid = (int) $bid['user_id'];
                $login = isset($logins_by_uid[$uid]) ? $logins_by_uid[$uid] : '';
                $bidder = $login !== '' ? substr($login, 0, 2) . '***' : '***';
            }

            $thumb_id = (int) get_post_thumbnail_id($pid);
            $image_html = '';
            if ($thumb_id) {
                $image_html = wp_get_attachment_image(
                    $thumb_id,
                    'medium',
                    false,
                    array('class' => 'auction-card-thumb', 'loading' => 'lazy', 'alt' => esc_attr(get_the_title($post)))
                );
            }

            $items[] = array(
                'id'       => $pid,
                'title'    => get_the_title($post),
                'image'    => $image_html,
                'price'    => $price,
                'has_bids' => $has_bids,
                'bidder'   => $bidder,
                'link'     => get_permalink($post),
            );
        }

        return $items;
    }

    /**
     * Single batched query: highest bid per product, with bidder user_id.
     *
     * Strategy: pull all bids for the supplied product_ids (a small set,
     * indexed by product_id) sorted by amount desc / created_at desc, then
     * keep only the first row per product in PHP. With ~20 products and a
     * few bids each this is one fast indexed scan rather than N queries.
     *
     * @param int[] $product_ids
     * @return array<int,array{user_id:int,bid_amount:float}>
     */
    private function get_high_bids_for_products(array $product_ids) {
        if (empty($product_ids) || !class_exists('Azure_Database')) {
            return array();
        }

        $table = Azure_Database::get_table_name('auction_bids');
        if (!$table) {
            return array();
        }

        global $wpdb;
        $product_ids = array_values(array_unique(array_map('intval', $product_ids)));
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "SELECT product_id, user_id, bid_amount
             FROM {$table}
             WHERE product_id IN ({$placeholders})
             ORDER BY product_id ASC, bid_amount DESC, created_at DESC",
            $product_ids
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (empty($rows)) {
            return array();
        }

        $by_pid = array();
        foreach ($rows as $row) {
            $pid = (int) $row['product_id'];
            if (isset($by_pid[$pid])) {
                continue;
            }
            $by_pid[$pid] = array(
                'user_id'    => (int) $row['user_id'],
                'bid_amount' => (float) $row['bid_amount'],
            );
        }
        return $by_pid;
    }

    /**
     * Bulk-fetch user_login for a set of user IDs in one query.
     *
     * @param int[] $user_ids
     * @return array<int,string> uid => user_login
     */
    private function get_user_logins(array $user_ids) {
        if (empty($user_ids)) {
            return array();
        }
        global $wpdb;
        $user_ids = array_values(array_unique(array_map('intval', $user_ids)));
        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "SELECT ID, user_login FROM {$wpdb->users} WHERE ID IN ({$placeholders})",
            $user_ids
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $out = array();
        foreach ($rows as $r) {
            $out[(int) $r['ID']] = (string) $r['user_login'];
        }
        return $out;
    }

    /**
     * Enqueue the auction-display assets only when [auction-display] is on
     * the rendered post (matches the [up-next] pattern). Keeps every other
     * front-end page free of these scripts.
     */
    public function enqueue_display_scripts() {
        if (is_admin()) {
            return;
        }
        global $post;
        if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'auction-display')) {
            return;
        }

        $settings = class_exists('Azure_Settings') ? Azure_Settings::get_all_settings() : array();
        $live = !empty($settings['auction_display_live']);
        $cfg  = $this->get_display_layout_settings($settings);

        wp_enqueue_style(
            'azure-auction-display',
            AZURE_PLUGIN_URL . 'css/auction-display.css',
            array(),
            defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : '1.0'
        );
        wp_enqueue_script(
            'azure-auction-display',
            AZURE_PLUGIN_URL . 'js/auction-display.js',
            array('jquery'),
            defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : '1.0',
            true
        );
        wp_localize_script('azure-auction-display', 'azureAuctionDisplay', array(
            'ajaxurl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('azure_auction_display'),
            'live'           => $live ? 1 : 0,
            'isAdmin'        => current_user_can('manage_options') ? 1 : 0,
            'refreshMs'      => 30000,
            'slideMs'        => (int) $cfg['slide_seconds'] * 1000,
            'cardsPerPage'   => (int) $cfg['cards_wide'] * (int) $cfg['cards_tall'],
        ));
    }

    public function ajax_buy_it_now() {
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('You must be logged in to use Buy It Now.', 'azure-plugin')));
        }
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if (!$product_id) {
            wp_send_json_error(array('message' => __('Invalid product.', 'azure-plugin')));
        }
        if (!wp_verify_nonce(isset($_POST['nonce']) ? $_POST['nonce'] : '', 'azure_auction_bid')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'azure-plugin')));
        }
        if (!class_exists('Azure_Auction_Lifecycle')) {
            wp_send_json_error(array('message' => __('Auction not available.', 'azure-plugin')));
        }
        $lifecycle = new Azure_Auction_Lifecycle();
        $order = $lifecycle->create_buy_it_now_order($product_id, get_current_user_id());
        if (!$order) {
            wp_send_json_error(array('message' => __('Could not create order. The item may no longer be available.', 'azure-plugin')));
        }
        wp_send_json_success(array('checkout_url' => $order->get_checkout_payment_url()));
    }
}
