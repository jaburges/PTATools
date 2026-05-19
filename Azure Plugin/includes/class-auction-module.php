<?php
/**
 * Auction Module - Main Module Class
 *
 * WooCommerce auction products with bidding, max bid, Buy It Now, and winner checkout.
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

        add_action('woocommerce_single_product_summary', array($this, 'remove_auction_add_to_cart'), 5);
        add_action('woocommerce_single_product_summary', array($this, 'maybe_process_ended_auction'), 1);
        add_action('woocommerce_single_product_summary', array($this, 'render_single_product_auction'), 35);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_auction_scripts'));

        add_action('admin_post_azure_auction_create_te_runner_up_orders', array($this, 'handle_create_te_runner_up_orders'));
        add_action('admin_post_azure_auction_resend_invoice',             array($this, 'handle_resend_invoice'));
        add_action('admin_post_azure_auction_resend_all_unpaid',          array($this, 'handle_resend_all_unpaid'));
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
     * Posts to admin-post.php with action=azure_auction_resend_invoice,
     * order_id, _wpnonce (nonce action: azure_auction_resend_invoice_<order_id>).
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
     * admin-post handler: bulk-resend WC Customer Invoice email to every
     * unpaid auction order (main winners + TE runner-ups).
     * Nonce action: azure_auction_resend_all_unpaid
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
     * admin-post.php handler: creates wc-pending orders + invoice emails for
     * the 2nd and 3rd place bidders on a single Teacher Experience product.
     *
     * Posts to: admin-post.php?action=azure_auction_create_te_runner_up_orders
     * Required POST: product_id, _wpnonce (nonce action: azure_auction_te_runner_up_<product_id>)
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
            $redirect = admin_url('admin.php?page=azure-plugin-auction');
        }
        $redirect = add_query_arg(array(
            'azure_te_msg'   => rawurlencode($msg),
            'azure_te_state' => $totals['errors'] > 0 ? 'error' : 'success',
            'azure_te_pid'   => $product_id,
        ), $redirect);

        wp_safe_redirect($redirect);
        exit;
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
                    <input type="number" id="auction-bid-amount" class="auction-bid-amount" min="0" step="0.01" value="<?php echo esc_attr($current_price + 5); ?>" />
                    <div class="auction-quick-buttons">
                        <button type="button" class="button auction-quick-bid" data-increment="5">+$5</button>
                        <button type="button" class="button auction-quick-bid" data-increment="10">+$10</button>
                        <button type="button" class="button auction-quick-bid" data-increment="20">+$20</button>
                    </div>
                </div>
                <p class="auction-max-bid-row">
                    <label><input type="checkbox" class="auction-use-max-bid" /> <?php _e('Set max bid (auto-bid up to this amount)', 'azure-plugin'); ?></label>
                    <input type="number" class="auction-max-bid-amount" min="0" step="0.01" style="display:none; width:100px;" />
                </p>
                <button type="button" class="button alt auction-place-bid"><?php _e('Place bid', 'azure-plugin'); ?></button>
                <span class="auction-bid-message" style="display:none;"></span>
            </div>
            <?php else : ?>
            <p class="auction-login-required">
                <?php printf(
                    __('Please %slog in%s or %sregister%s to place a bid.', 'azure-plugin'),
                    '<a href="' . esc_url(wp_login_url(get_permalink())) . '">',
                    '</a>',
                    '<a href="' . esc_url(wp_registration_url()) . '">',
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
