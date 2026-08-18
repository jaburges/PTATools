<?php
/**
 * Donations Module
 *
 * Round-up at checkout, custom donation amounts, gift products, and shortcodes.
 * Cash donations are WooCommerce cart fees. Gift products are real line items
 * marked `_pta_donated_product` so product fields and membership credit are skipped.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Donations_Module {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Only run table existence + admin hook registration when this request
        // could plausibly need them. On a front-end cart/checkout pageload we
        // still need the frontend hooks (the donation widget, fee calculator,
        // checkout record), but we never need the admin AJAX handlers and the
        // SHOW TABLES query is wasted I/O.
        $is_admin_like = (function_exists('is_admin') && is_admin())
            || (function_exists('wp_doing_ajax') && wp_doing_ajax())
            || (defined('DOING_AJAX') && DOING_AJAX)
            || (defined('DOING_CRON') && DOING_CRON);

        if ($is_admin_like) {
            $this->ensure_tables();
            $this->init_admin_hooks();
        }

        if (!class_exists('WooCommerce')) {
            if ($is_admin_like) {
                add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            }
            return;
        }

        $this->init_frontend_hooks();

        if (class_exists('Azure_Logger')) {
            Azure_Logger::debug_module('Donations', 'Donations module initialized');
        }
    }

    public function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p><strong>' . esc_html__('Donations Module:', 'azure-plugin') . '</strong> ' . esc_html__('WooCommerce is required.', 'azure-plugin') . '</p></div>';
    }

    /**
     * Verify the donation_campaigns table exists; create it on first miss.
     *
     * Caches the "exists" answer in a transient so the SHOW TABLES query
     * runs at most once per 6 hours (or until activation explicitly resets
     * the flag). Without this the query fires on every admin request.
     */
    private function ensure_tables() {
        if (get_transient('azure_donations_tables_v3')) {
            return;
        }
        global $wpdb;
        $table = Azure_Database::get_table_name('donation_campaigns');
        if ($table && $wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            Azure_Database::create_tables();
        }
        $this->ensure_record_columns();
        set_transient('azure_donations_tables_v3', 1, 6 * HOUR_IN_SECONDS);
    }

    /**
     * dbDelta adds columns on version bump; this covers a frontend thank-you
     * that lands before an admin request has run the migration.
     */
    private function ensure_record_columns() {
        global $wpdb;
        $table = Azure_Database::get_table_name('donation_records');
        if (!$table) {
            return;
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            Azure_Database::create_tables();
        }
        $col = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'product_id'");
        if (!empty($col)) {
            return;
        }
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN product_id bigint(20) UNSIGNED DEFAULT 0 AFTER donation_type");
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN product_name varchar(255) DEFAULT '' AFTER product_id");
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN donor_role varchar(50) DEFAULT '' AFTER product_name");
    }

    private function init_admin_hooks() {
        add_action('wp_ajax_azure_donations_save_campaign', array($this, 'ajax_save_campaign'));
        add_action('wp_ajax_azure_donations_delete_campaign', array($this, 'ajax_delete_campaign'));
        add_action('wp_ajax_azure_donations_get_records', array($this, 'ajax_get_records'));
        add_action('wp_ajax_azure_donations_save_settings', array($this, 'ajax_save_settings'));
    }

    private function init_frontend_hooks() {
        add_action('wp_ajax_azure_donations_update_fee', array($this, 'ajax_update_fee'));
        add_action('wp_ajax_nopriv_azure_donations_update_fee', array($this, 'ajax_update_fee'));
        add_action('woocommerce_cart_calculate_fees', array($this, 'apply_donation_fee'));
        add_action('woocommerce_review_order_before_submit', array($this, 'render_checkout_widget'));
        add_action('woocommerce_after_cart_totals', array($this, 'render_cart_widget'));
        add_action('wp_footer', array($this, 'render_blocks_checkout_widget'));
        add_action('woocommerce_thankyou', array($this, 'record_donation'), 10, 1);
        add_action('woocommerce_payment_complete', array($this, 'record_donation'), 10, 1);
        add_action('woocommerce_order_status_processing', array($this, 'record_donation'), 10, 1);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_donated_item_meta'), 10, 4);
        add_filter('woocommerce_get_item_data', array($this, 'display_donated_item_data'), 10, 2);
        add_action('wp_ajax_azure_donations_add_gift_product', array($this, 'ajax_add_gift_product'));
        add_action('wp_ajax_nopriv_azure_donations_add_gift_product', array($this, 'ajax_add_gift_product'));
        add_shortcode('pta-donate', array($this, 'shortcode_donate'));
        add_shortcode('donations-list', array($this, 'shortcode_donations_list'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
    }

    // ─── Campaign Helpers ────────────────────────────────────────────

    public static function get_active_campaigns() {
        global $wpdb;
        $table = Azure_Database::get_table_name('donation_campaigns');
        if (!$table) return array();
        return $wpdb->get_results("SELECT * FROM {$table} WHERE is_active = 1 ORDER BY name ASC");
    }

    public static function get_all_campaigns() {
        global $wpdb;
        $table = Azure_Database::get_table_name('donation_campaigns');
        if (!$table) return array();
        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC");
    }

    public static function get_default_campaign() {
        $default_id = Azure_Settings::get_setting('donations_default_campaign', 0);
        if ($default_id) {
            global $wpdb;
            $table = Azure_Database::get_table_name('donation_campaigns');
            if (!$table) return null;
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $default_id));
        }
        $campaigns = self::get_active_campaigns();
        return !empty($campaigns) ? $campaigns[0] : null;
    }

    // ─── Cart Fee Logic ──────────────────────────────────────────────

    public function apply_donation_fee($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;

        $session = WC()->session;
        if (!$session) return;

        $roundup = $session->get('pta_donation_roundup');
        $custom  = $session->get('pta_donation_custom');

        if ($roundup) {
            $subtotal = $cart->get_subtotal() + $cart->get_subtotal_tax();
            foreach ($cart->get_fees() as $fee) {
                if (strpos($fee->name, 'Donation') === false) {
                    $subtotal += $fee->amount;
                }
            }
            $rounded = ceil($subtotal);
            $diff = round($rounded - $subtotal, 2);
            if ($diff > 0 && $diff < 1) {
                $campaign = self::get_default_campaign();
                $label = $campaign ? 'Donation - ' . $campaign->name : 'Round-Up Donation';
                $cart->add_fee($label, $diff, false);
            }
        }

        if ($custom && floatval($custom) > 0) {
            $amount = round(floatval($custom), 2);
            $campaign = self::get_default_campaign();
            $label = $campaign ? 'Donation - ' . $campaign->name : 'Donation';
            $cart->add_fee($label, $amount, false);
        }
    }

    public function ajax_update_fee() {
        check_ajax_referer('pta_donations_nonce', 'nonce');

        $type   = sanitize_text_field($_POST['type'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $active = !empty($_POST['active']);

        $session = WC()->session;
        if (!$session) {
            wp_send_json_error('No session');
            return;
        }

        if ($type === 'roundup') {
            $session->set('pta_donation_roundup', $active);
            if ($active) {
                $session->set('pta_donation_custom', 0);
            }
        } elseif ($type === 'custom') {
            $session->set('pta_donation_custom', $active ? $amount : 0);
            if ($active && $amount > 0) {
                $session->set('pta_donation_roundup', false);
            }
        } elseif ($type === 'clear') {
            $session->set('pta_donation_roundup', false);
            $session->set('pta_donation_custom', 0);
        }

        wp_send_json_success(array('message' => 'Updated'));
    }

    // ─── Checkout Widget ─────────────────────────────────────────────

    public function render_checkout_widget() {
        $this->render_donation_widget('checkout');
    }

    public function render_cart_widget() {
        $this->render_donation_widget('cart');
    }

    private function render_donation_widget($context = 'checkout') {
        $settings = Azure_Settings::get_all_settings();
        if (empty($settings['enable_donations'])) return;

        $campaign = self::get_default_campaign();
        if (!$campaign) return;

        $enable_roundup = !empty($settings['donations_enable_roundup']);
        $enable_custom  = !empty($settings['donations_enable_custom']);
        $quick_amounts  = array_filter(array_map('floatval', explode(',', $settings['donations_quick_amounts'] ?? '1,5,10')));

        if (!$enable_roundup && !$enable_custom) return;

        $session = WC()->session;
        $roundup_active = $session ? $session->get('pta_donation_roundup', false) : false;
        $custom_active  = $session ? floatval($session->get('pta_donation_custom', 0)) : 0;

        $widget_id = 'pta-donations-widget-' . $context;
        $nonce = wp_create_nonce('pta_donations_nonce');
        ?>
        <div class="pta-donations-checkout-widget" id="<?php echo esc_attr($widget_id); ?>">
            <div class="pta-donations-header">
                <span class="dashicons dashicons-heart"></span>
                <strong><?php echo esc_html($campaign->name); ?></strong>
            </div>
            <?php if ($campaign->description): ?>
                <p class="pta-donations-desc"><?php echo esc_html($campaign->description); ?></p>
            <?php endif; ?>

            <?php if ($enable_roundup): ?>
            <div class="pta-donations-row">
                <label class="pta-donations-toggle">
                    <input type="checkbox" class="pta-roundup-toggle" <?php checked($roundup_active); ?> />
                    <span><?php _e('Round up my total to the nearest dollar', 'azure-plugin'); ?></span>
                </label>
            </div>
            <?php endif; ?>

            <?php if ($enable_custom): ?>
            <div class="pta-donations-row pta-donations-custom-row">
                <span class="pta-donations-label"><?php _e('Or add a donation:', 'azure-plugin'); ?></span>
                <div class="pta-donations-buttons">
                    <?php foreach ($quick_amounts as $amt): ?>
                        <button type="button" class="pta-donate-quick button <?php echo ($custom_active == $amt) ? 'active' : ''; ?>"
                                data-amount="<?php echo esc_attr($amt); ?>">
                            $<?php echo number_format($amt, 0); ?>
                        </button>
                    <?php endforeach; ?>
                    <div class="pta-donate-custom-wrap">
                        <span>$</span>
                        <input type="number" class="pta-donate-custom-input" min="0" step="0.01" placeholder="Other"
                               value="<?php echo ($custom_active && !in_array($custom_active, $quick_amounts)) ? esc_attr($custom_active) : ''; ?>" />
                    </div>
                </div>
                <?php if ($custom_active > 0): ?>
                    <button type="button" class="pta-donate-clear button-link" style="margin-top:4px; font-size:12px;">Remove donation</button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <script>
        jQuery(function($) {
            var $w = $('#<?php echo esc_js($widget_id); ?>');
            var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            var nonce = '<?php echo esc_js($nonce); ?>';
            var ctx = '<?php echo esc_js($context); ?>';

            function refreshTotals() {
                if (ctx === 'checkout') {
                    $(document.body).trigger('update_checkout');
                } else if (ctx === 'blocks-checkout') {
                    // Blocks checkout: dispatch a cart update via Store API
                    if (wp && wp.data && wp.data.dispatch) {
                        var store = wp.data.dispatch('wc/store/cart');
                        if (store && store.invalidateResolutionForStore) {
                            store.invalidateResolutionForStore();
                        }
                    }
                    // Fallback: trigger WC Blocks to refetch cart data
                    $(document.body).trigger('wc-blocks_added_to_cart');
                } else {
                    $('[name="update_cart"]').prop('disabled', false).trigger('click');
                }
            }

            function updateDonation(type, amount, active) {
                $.post(ajaxUrl, {
                    action: 'azure_donations_update_fee',
                    nonce: nonce,
                    type: type,
                    amount: amount,
                    active: active ? 1 : 0
                }, function() {
                    refreshTotals();
                });
            }

            $w.find('.pta-roundup-toggle').on('change', function() {
                var on = $(this).is(':checked');
                if (on) {
                    $w.find('.pta-donate-quick').removeClass('active');
                    $w.find('.pta-donate-custom-input').val('');
                }
                updateDonation('roundup', 0, on);
            });

            $w.find('.pta-donate-quick').on('click', function() {
                var amt = parseFloat($(this).data('amount'));
                var wasActive = $(this).hasClass('active');
                $w.find('.pta-donate-quick').removeClass('active');
                $w.find('.pta-donate-custom-input').val('');
                $w.find('.pta-roundup-toggle').prop('checked', false);

                if (wasActive) {
                    updateDonation('clear', 0, false);
                } else {
                    $(this).addClass('active');
                    updateDonation('custom', amt, true);
                }
            });

            var customTimer;
            $w.find('.pta-donate-custom-input').on('input', function() {
                clearTimeout(customTimer);
                var val = parseFloat($(this).val());
                customTimer = setTimeout(function() {
                    $w.find('.pta-donate-quick').removeClass('active');
                    $w.find('.pta-roundup-toggle').prop('checked', false);
                    if (val > 0) {
                        updateDonation('custom', val, true);
                    } else {
                        updateDonation('clear', 0, false);
                    }
                }, 500);
            });

            $w.find('.pta-donate-clear').on('click', function() {
                $w.find('.pta-donate-quick').removeClass('active');
                $w.find('.pta-donate-custom-input').val('');
                $w.find('.pta-roundup-toggle').prop('checked', false);
                updateDonation('clear', 0, false);
            });
        });
        </script>
        <?php
    }

    /**
     * Render donation widget for WooCommerce Blocks checkout.
     * Outputs a hidden container in the footer; JS relocates it into the order summary.
     */
    public function render_blocks_checkout_widget() {
        if (!is_checkout()) return;
        if (!function_exists('has_block') || !has_block('woocommerce/checkout')) return;

        $settings = Azure_Settings::get_all_settings();
        if (empty($settings['enable_donations'])) return;

        $campaign = self::get_default_campaign();
        if (!$campaign) return;

        $enable_roundup = !empty($settings['donations_enable_roundup']);
        $enable_custom  = !empty($settings['donations_enable_custom']);
        if (!$enable_roundup && !$enable_custom) return;

        echo '<div id="pta-donations-blocks-staging" style="display:none;">';
        $this->render_donation_widget('blocks-checkout');
        echo '</div>';
        ?>
        <script>
        (function() {
            function placeDonationWidget() {
                var staging = document.getElementById('pta-donations-blocks-staging');
                if (!staging) return;
                var widget = staging.firstElementChild;
                if (!widget) return;

                var target = document.querySelector('.wc-block-components-totals-coupon')
                          || document.querySelector('.wc-block-components-totals-item');
                if (target) {
                    target.parentNode.insertBefore(widget, target);
                    staging.remove();
                    return true;
                }
                return false;
            }

            if (document.readyState === 'complete') {
                if (!placeDonationWidget()) {
                    var attempts = 0;
                    var iv = setInterval(function() {
                        if (placeDonationWidget() || ++attempts > 40) clearInterval(iv);
                    }, 250);
                }
            } else {
                window.addEventListener('load', function() {
                    if (!placeDonationWidget()) {
                        var attempts = 0;
                        var iv = setInterval(function() {
                            if (placeDonationWidget() || ++attempts > 40) clearInterval(iv);
                        }, 250);
                    }
                });
            }
        })();
        </script>
        <?php
    }

    // ─── Record Donation After Order ─────────────────────────────────

    public function record_donation($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        if ($order->get_meta('_pta_donation_recorded')) {
            return;
        }

        $this->ensure_record_columns();

        global $wpdb;
        $records_table = Azure_Database::get_table_name('donation_records');
        $campaigns_table = Azure_Database::get_table_name('donation_campaigns');
        if (!$records_table) {
            return;
        }

        $campaign = self::get_default_campaign();
        $campaign_id = $campaign ? (int) $campaign->id : 0;
        $user_id = (int) $order->get_user_id();
        $donor_role = self::donor_role_for_user($user_id);
        $recorded = 0;

        foreach ($order->get_fees() as $fee) {
            if (strpos($fee->get_name(), 'Donation') === false) {
                continue;
            }
            $amount = abs(floatval($fee->get_total()));
            if ($amount <= 0) {
                continue;
            }
            $type = (strpos($fee->get_name(), 'Round') !== false) ? 'roundup' : 'custom';
            if ($this->insert_donation_record($records_table, array(
                'campaign_id'   => $campaign_id,
                'order_id'      => (int) $order_id,
                'user_id'       => $user_id,
                'amount'        => $amount,
                'donation_type' => $type,
                'product_id'    => 0,
                'product_name'  => '',
                'donor_role'    => $donor_role,
                'created_at'    => current_time('mysql'),
            ))) {
                $recorded++;
                $this->bump_campaign_raised($campaigns_table, $campaign_id, $amount);
                $this->send_admin_donation_email($order, '$' . number_format($amount, 2));
            }
        }

        foreach ($order->get_items() as $item) {
            if (!is_object($item) || !method_exists($item, 'get_meta')) {
                continue;
            }
            if (!$item->get_meta('_pta_donated_product')) {
                continue;
            }
            $amount = abs(floatval($item->get_total()));
            $product_id = method_exists($item, 'get_product_id') ? (int) $item->get_product_id() : 0;
            $product_name = $item->get_name();
            if ($this->insert_donation_record($records_table, array(
                'campaign_id'   => $campaign_id,
                'order_id'      => (int) $order_id,
                'user_id'       => $user_id,
                'amount'        => $amount,
                'donation_type' => 'product',
                'product_id'    => $product_id,
                'product_name'  => $product_name,
                'donor_role'    => $donor_role,
                'created_at'    => current_time('mysql'),
            ))) {
                $recorded++;
                $this->bump_campaign_raised($campaigns_table, $campaign_id, $amount);
                $this->send_admin_donation_email($order, $product_name);
            }
        }

        if ($recorded === 0) {
            return;
        }

        $order->update_meta_data('_pta_donation_recorded', 1);
        $order->save();

        if (WC()->session) {
            WC()->session->set('pta_donation_roundup', false);
            WC()->session->set('pta_donation_custom', 0);
        }
    }

    private function insert_donation_record($table, array $row) {
        global $wpdb;
        $ok = $wpdb->insert($table, $row);
        return $ok !== false;
    }

    private function bump_campaign_raised($campaigns_table, $campaign_id, $amount) {
        if (!$campaigns_table || !$campaign_id || $amount <= 0) {
            return;
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$campaigns_table} SET raised_amount = raised_amount + %f, updated_at = %s WHERE id = %d",
            $amount,
            current_time('mysql'),
            $campaign_id
        ));
    }

    /**
     * Public list stores only parent / staff / guest — never a name or email.
     */
    public static function donor_role_for_user($user_id) {
        if (!$user_id) {
            return 'guest';
        }
        $user = get_userdata((int) $user_id);
        if (!$user) {
            return 'guest';
        }
        $roles = (array) $user->roles;
        if (in_array('school_staff', $roles, true)) {
            return 'staff';
        }
        if (in_array('parent', $roles, true)) {
            return 'parent';
        }
        return 'guest';
    }

    public static function donor_role_label($role) {
        if ($role === 'staff') {
            return __('Staff', 'azure-plugin');
        }
        if ($role === 'parent') {
            return __('Parent', 'azure-plugin');
        }
        return __('Guest', 'azure-plugin');
    }

    /**
     * Admin email only: Parent 1 name when the buyer has an account,
     * otherwise the order billing / Stripe email.
     */
    private function donor_admin_name($order) {
        $user_id = (int) $order->get_user_id();
        if ($user_id) {
            $parent1 = trim((string) get_user_meta($user_id, 'pta_pf_parent_1_name', true));
            if ($parent1 !== '') {
                return $parent1;
            }
            $first = trim((string) get_user_meta($user_id, 'first_name', true));
            $last  = trim((string) get_user_meta($user_id, 'last_name', true));
            $full  = trim($first . ' ' . $last);
            if ($full !== '') {
                return $full;
            }
        }
        $email = trim((string) $order->get_billing_email());
        if ($email !== '') {
            return $email;
        }
        return __('A supporter', 'azure-plugin');
    }

    private function send_admin_donation_email($order, $gift_label) {
        $to = get_option('admin_email');
        if (!is_email($to)) {
            return;
        }
        $who = $this->donor_admin_name($order);
        $gift = wp_strip_all_tags(html_entity_decode((string) $gift_label, ENT_QUOTES, 'UTF-8'));
        $subject = __('Congrats on the donation', 'azure-plugin');
        $body = sprintf(
            '<p>%s</p><p>%s</p>',
            esc_html($subject),
            sprintf(
                /* translators: 1: Parent 1 name or billing email, 2: product name or amount */
                esc_html__('%1$s has kindly donated %2$s.', 'azure-plugin'),
                esc_html($who),
                esc_html($gift)
            )
        );
        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($to, $subject, $body, $headers);
    }

    public function save_donated_item_meta($item, $cart_item_key, $values, $order) {
        if (empty($values['_pta_donated_product'])) {
            return;
        }
        $item->update_meta_data('_pta_donated_product', 1);
        $item->update_meta_data(__('Donation', 'azure-plugin'), __('Gift item', 'azure-plugin'));
    }

    public function display_donated_item_data($item_data, $cart_item) {
        if (empty($cart_item['_pta_donated_product'])) {
            return $item_data;
        }
        $item_data[] = array(
            'key'   => __('Donation', 'azure-plugin'),
            'value' => __('Gift item — product fields skipped', 'azure-plugin'),
        );
        return $item_data;
    }

    public static function get_gift_products() {
        $raw = Azure_Settings::get_setting('donations_gift_products', array());
        if (!is_array($raw)) {
            return array();
        }
        $out = array();
        foreach ($raw as $row) {
            $pid = isset($row['product_id']) ? (int) $row['product_id'] : 0;
            $label = isset($row['label']) ? sanitize_text_field($row['label']) : '';
            if ($pid > 0 && $label !== '') {
                $out[] = array('label' => $label, 'product_id' => $pid);
            }
        }
        return $out;
    }

    public function ajax_add_gift_product() {
        check_ajax_referer('pta_donations_nonce', 'nonce');
        if (!class_exists('WooCommerce')) {
            wp_send_json_error(array('message' => __('WooCommerce is required.', 'azure-plugin')));
        }

        $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $allowed = array();
        foreach (self::get_gift_products() as $row) {
            $allowed[$row['product_id']] = true;
        }
        if ($product_id <= 0 || empty($allowed[$product_id])) {
            wp_send_json_error(array('message' => __('That gift is not available.', 'azure-plugin')));
        }

        $product = wc_get_product($product_id);
        if (!$product || !$product->is_purchasable()) {
            wp_send_json_error(array('message' => __('That product cannot be purchased.', 'azure-plugin')));
        }

        if (null === WC()->cart) {
            wc_load_cart();
        }

        $_POST['pta_donated_product'] = 1;
        $key = WC()->cart->add_to_cart($product_id, 1, 0, array(), array(
            '_pta_donated_product' => 1,
        ));
        if (!$key) {
            wp_send_json_error(array('message' => __('Could not add that gift to your cart.', 'azure-plugin')));
        }

        wp_send_json_success(array(
            'message'      => __('Gift added to your cart.', 'azure-plugin'),
            'checkout_url' => wc_get_checkout_url(),
        ));
    }

    // ─── Shortcode [pta-donate] ──────────────────────────────────────

    public function shortcode_donate($atts) {
        $atts = shortcode_atts(array(
            'campaign_id' => 0,
            'amounts'     => '5,10,25,50',
            'show_custom' => 'yes',
            'button_text' => 'Donate Now',
        ), $atts, 'pta-donate');

        if (!class_exists('WooCommerce')) return '<p>WooCommerce is required for donations.</p>';

        $campaign = null;
        if ($atts['campaign_id']) {
            global $wpdb;
            $table = Azure_Database::get_table_name('donation_campaigns');
            if ($table) {
                $campaign = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND is_active = 1", intval($atts['campaign_id'])));
            }
        }
        if (!$campaign) {
            $campaign = self::get_default_campaign();
        }

        $amounts = array_filter(array_map('floatval', explode(',', $atts['amounts'])));
        $show_custom = ($atts['show_custom'] === 'yes');
        $nonce = wp_create_nonce('pta_donations_nonce');

        ob_start();
        ?>
        <div class="pta-donate-form" data-campaign="<?php echo $campaign ? esc_attr($campaign->id) : '0'; ?>">
            <?php if ($campaign): ?>
                <h3 class="pta-donate-title"><?php echo esc_html($campaign->name); ?></h3>
                <?php if ($campaign->description): ?>
                    <p class="pta-donate-desc"><?php echo esc_html($campaign->description); ?></p>
                <?php endif; ?>
                <?php if ($campaign->goal_amount > 0): ?>
                    <div class="pta-donate-progress">
                        <?php
                        $pct = min(100, round(($campaign->raised_amount / $campaign->goal_amount) * 100));
                        ?>
                        <div class="pta-donate-progress-bar">
                            <div class="pta-donate-progress-fill" style="width: <?php echo $pct; ?>%"></div>
                        </div>
                        <span class="pta-donate-progress-text">
                            $<?php echo number_format($campaign->raised_amount, 2); ?> raised of $<?php echo number_format($campaign->goal_amount, 2); ?> goal
                        </span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="pta-donate-amounts">
                <?php foreach ($amounts as $amt): ?>
                    <button type="button" class="pta-donate-amount-btn" data-amount="<?php echo esc_attr($amt); ?>">
                        $<?php echo number_format($amt, 0); ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <?php if ($show_custom): ?>
            <div class="pta-donate-custom-input-wrap">
                <label for="pta-donate-other-<?php echo $campaign ? $campaign->id : 0; ?>">Custom amount:</label>
                <div class="pta-donate-input-group">
                    <span>$</span>
                    <input type="number" class="pta-donate-other" id="pta-donate-other-<?php echo $campaign ? $campaign->id : 0; ?>"
                           min="1" step="0.01" placeholder="0.00" />
                </div>
            </div>
            <?php endif; ?>

            <button type="button" class="pta-donate-submit button"><?php echo esc_html($atts['button_text']); ?></button>
            <?php
            $gifts = self::get_gift_products();
            if (!empty($gifts)):
            ?>
            <div class="pta-donate-gifts">
                <p class="pta-donate-gifts-label"><?php esc_html_e('Or donate a membership', 'azure-plugin'); ?></p>
                <?php foreach ($gifts as $gift): ?>
                    <button type="button" class="pta-donate-gift-btn button" data-product-id="<?php echo (int) $gift['product_id']; ?>">
                        <?php echo esc_html($gift['label']); ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="pta-donate-message" style="display:none;"></div>
        </div>

        <script>
        jQuery(function($) {
            var $form = $('.pta-donate-form[data-campaign="<?php echo $campaign ? $campaign->id : 0; ?>"]');
            var selectedAmount = 0;

            $form.find('.pta-donate-amount-btn').on('click', function() {
                $form.find('.pta-donate-amount-btn').removeClass('active');
                $(this).addClass('active');
                selectedAmount = parseFloat($(this).data('amount'));
                $form.find('.pta-donate-other').val('');
            });

            $form.find('.pta-donate-other').on('input', function() {
                $form.find('.pta-donate-amount-btn').removeClass('active');
                selectedAmount = parseFloat($(this).val()) || 0;
            });

            $form.find('.pta-donate-submit').on('click', function() {
                var $btn = $(this);
                var $msg = $form.find('.pta-donate-message');
                var otherVal = parseFloat($form.find('.pta-donate-other').val());
                var amount = otherVal > 0 ? otherVal : selectedAmount;

                if (!amount || amount <= 0) {
                    $msg.text('Please select or enter a donation amount.').css('color', '#d63638').show();
                    return;
                }

                $btn.prop('disabled', true).text('Adding...');
                $msg.hide();

                $.post('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
                    action: 'azure_donations_update_fee',
                    nonce: '<?php echo esc_js($nonce); ?>',
                    type: 'custom',
                    amount: amount,
                    active: 1
                }, function(resp) {
                    $btn.prop('disabled', false).text('<?php echo esc_js($atts['button_text']); ?>');
                    if (resp.success) {
                        $msg.html('$' + amount.toFixed(2) + ' donation added to your cart! <a href="<?php echo esc_js(wc_get_checkout_url()); ?>">Proceed to checkout</a>').css('color', '#00a32a').show();
                    } else {
                        $msg.text('Failed to add donation.').css('color', '#d63638').show();
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('<?php echo esc_js($atts['button_text']); ?>');
                    $msg.text('Network error.').css('color', '#d63638').show();
                });
            });

            $form.find('.pta-donate-gift-btn').on('click', function() {
                var $gbtn = $(this);
                var $msg = $form.find('.pta-donate-message');
                $gbtn.prop('disabled', true);
                $msg.hide();
                $.post('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
                    action: 'azure_donations_add_gift_product',
                    nonce: '<?php echo esc_js($nonce); ?>',
                    product_id: $gbtn.data('product-id'),
                    pta_donated_product: 1
                }, function(resp) {
                    $gbtn.prop('disabled', false);
                    if (resp.success) {
                        var url = (resp.data && resp.data.checkout_url) ? resp.data.checkout_url : '<?php echo esc_js(wc_get_checkout_url()); ?>';
                        $msg.html('Gift added to your cart! <a href="' + url + '">Proceed to checkout</a>').css('color', '#00a32a').show();
                    } else {
                        $msg.text((resp.data && resp.data.message) ? resp.data.message : 'Could not add that gift.').css('color', '#d63638').show();
                    }
                }).fail(function() {
                    $gbtn.prop('disabled', false);
                    $msg.text('Network error.').css('color', '#d63638').show();
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Public recap: date, role type, product or amount. No names or emails.
     */
    public function shortcode_donations_list($atts) {
        $atts = shortcode_atts(array(
            'limit' => 25,
        ), $atts, 'donations-list');

        $this->ensure_record_columns();

        global $wpdb;
        $table = Azure_Database::get_table_name('donation_records');
        if (!$table) {
            return '';
        }

        $limit = max(1, min(100, (int) $atts['limit']));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT created_at, donor_role, product_name, amount, donation_type
             FROM {$table}
             ORDER BY created_at DESC
             LIMIT %d",
            $limit
        ));

        ob_start();
        ?>
        <div class="pta-donations-list">
            <table class="pta-donations-list-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'azure-plugin'); ?></th>
                        <th><?php esc_html_e('Role', 'azure-plugin'); ?></th>
                        <th><?php esc_html_e('Gift', 'azure-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="3"><?php esc_html_e('No donations yet.', 'azure-plugin'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($row->created_at))); ?></td>
                                <td><?php echo esc_html(self::donor_role_label($row->donor_role)); ?></td>
                                <td><?php echo esc_html(self::public_gift_label($row)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function public_gift_label($row) {
        $name = isset($row->product_name) ? trim((string) $row->product_name) : '';
        if ($name !== '') {
            return $name;
        }
        $amount = isset($row->amount) ? floatval($row->amount) : 0;
        return '$' . number_format($amount, 2);
    }

    // ─── Frontend Assets ─────────────────────────────────────────────

    public function enqueue_frontend_assets() {
        $post = get_post();
        $content = $post ? ($post->post_content ?? '') : '';
        $has_shortcode = $post && (
            has_shortcode($content, 'pta-donate')
            || has_shortcode($content, 'donations-list')
        );
        if (!is_checkout() && !is_cart() && !$has_shortcode) {
            return;
        }

        wp_enqueue_style('dashicons');
        wp_enqueue_style(
            'pta-donations-frontend',
            AZURE_PLUGIN_URL . 'css/donations-frontend.css',
            array('dashicons'),
            AZURE_PLUGIN_VERSION
        );
    }

    // ─── Admin AJAX: Save Campaign ───────────────────────────────────

    public function ajax_save_campaign() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        global $wpdb;
        $table = Azure_Database::get_table_name('donation_campaigns');
        if (!$table) {
            wp_send_json_error('Table not found');
            return;
        }

        $id          = intval($_POST['id'] ?? 0);
        $name        = sanitize_text_field($_POST['name'] ?? '');
        $description = sanitize_textarea_field($_POST['description'] ?? '');
        $goal_amount = floatval($_POST['goal_amount'] ?? 0);
        $is_active   = intval($_POST['is_active'] ?? 1);

        if (empty($name)) {
            wp_send_json_error('Campaign name is required');
            return;
        }

        $data = array(
            'name'        => $name,
            'description' => $description,
            'goal_amount' => $goal_amount,
            'is_active'   => $is_active,
            'updated_at'  => current_time('mysql'),
        );

        if ($id > 0) {
            $result = $wpdb->update($table, $data, array('id' => $id));
            if ($result === false) {
                wp_send_json_error('DB update failed: ' . $wpdb->last_error);
                return;
            }
        } else {
            $data['raised_amount'] = 0;
            $data['created_at'] = current_time('mysql');
            $result = $wpdb->insert($table, $data);
            if ($result === false) {
                wp_send_json_error('DB insert failed: ' . $wpdb->last_error);
                return;
            }
            $id = $wpdb->insert_id;
        }

        wp_send_json_success(array('id' => $id, 'message' => 'Campaign saved'));
    }

    public function ajax_delete_campaign() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        global $wpdb;
        $table = Azure_Database::get_table_name('donation_campaigns');
        $id = intval($_POST['id'] ?? 0);
        if (!$id || !$table) {
            wp_send_json_error('Invalid request');
            return;
        }

        $wpdb->delete($table, array('id' => $id));
        wp_send_json_success(array('message' => 'Campaign deleted'));
    }

    public function ajax_get_records() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        global $wpdb;
        $records_table = Azure_Database::get_table_name('donation_records');
        $campaigns_table = Azure_Database::get_table_name('donation_campaigns');
        if (!$records_table) {
            wp_send_json_error('Table not found');
            return;
        }

        $campaign_id = intval($_POST['campaign_id'] ?? 0);
        $where = $campaign_id ? $wpdb->prepare("WHERE r.campaign_id = %d", $campaign_id) : '';

        $records = $wpdb->get_results(
            "SELECT r.*, c.name as campaign_name
             FROM {$records_table} r
             LEFT JOIN {$campaigns_table} c ON r.campaign_id = c.id
             {$where}
             ORDER BY r.created_at DESC
             LIMIT 100"
        );

        $totals = $wpdb->get_row(
            "SELECT COUNT(*) as total_count, COALESCE(SUM(amount),0) as total_amount
             FROM {$records_table} r {$where}"
        );

        wp_send_json_success(array('records' => $records, 'totals' => $totals));
    }

    public function ajax_save_settings() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $fields = array(
            'donations_enable_roundup',
            'donations_enable_custom',
            'donations_default_campaign',
            'donations_quick_amounts',
        );

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                Azure_Settings::update_setting($field, sanitize_text_field($_POST[$field]));
            }
        }

        if (isset($_POST['donations_gift_products'])) {
            $raw = json_decode(wp_unslash($_POST['donations_gift_products']), true);
            Azure_Settings::update_setting('donations_gift_products', self::sanitize_gift_products($raw));
        }

        wp_send_json_success(array('message' => 'Settings saved'));
    }

    public static function sanitize_gift_products($raw) {
        $out = array();
        if (!is_array($raw)) {
            return $out;
        }
        foreach ($raw as $row) {
            $pid = isset($row['product_id']) ? (int) $row['product_id'] : 0;
            $label = isset($row['label']) ? sanitize_text_field($row['label']) : '';
            if ($pid > 0 && $label !== '') {
                $out[] = array(
                    'label'      => $label,
                    'product_id' => $pid,
                );
            }
        }
        return $out;
    }
}
