<?php
/**
 * Donations Module
 *
 * Round-up at checkout, custom donation amounts, gift products, WAG giving
 * levels, and shortcodes. Cash donations are WooCommerce cart fees. Gift
 * products are real line items marked `_pta_donated_product` so product fields
 * and membership credit are skipped.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Donations_Module {

    const WAG_DEFAULT_BG = '#0B2545';
    const WAG_DEFAULT_FG = '#FFFFFF';
    const WAG_DEFAULT_LABEL = 'Suggested Giving Levels';
    const WAG_DEFAULT_FOOTER = 'Every gift of any amount is welcome, honored, and recognized.';
    const CUSTOM_AMOUNT_MIN = 5;

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Administrators and Finance. Shop Manager can open the Selling tab
     * but donation AJAX previously required manage_options.
     */
    public static function current_user_can_manage() {
        if (class_exists('Azure_Finance_Role')) {
            return Azure_Finance_Role::user_can();
        }
        return current_user_can('manage_options');
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
        add_action('wp_ajax_azure_donations_get_variations', array($this, 'ajax_get_variations'));
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
        add_shortcode('wag', array($this, 'shortcode_wag'));
        add_shortcode('WAG', array($this, 'shortcode_wag'));
        add_shortcode('donation-progress', array($this, 'shortcode_donation_progress'));
        add_shortcode('Donation-progress', array($this, 'shortcode_donation_progress'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('woocommerce_before_add_to_cart_button', array($this, 'render_custom_amount_field'));
        add_filter('woocommerce_available_variation', array($this, 'flag_custom_amount_variation'), 10, 3);
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_custom_amount'), 10, 5);
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_custom_amount_cart_data'), 10, 3);
        add_filter('woocommerce_add_to_cart_quantity', array($this, 'force_custom_amount_quantity'), 10, 2);
        add_action('woocommerce_before_calculate_totals', array($this, 'apply_custom_amount_price'), 25, 1);
        add_filter('woocommerce_cart_item_quantity', array($this, 'lock_custom_amount_cart_qty'), 10, 3);
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

    public static function get_campaign_by_id($id) {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        global $wpdb;
        $table = Azure_Database::get_table_name('donation_campaigns');
        if (!$table) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
    }

    public static function get_campaign_by_name($name) {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        global $wpdb;
        $table = Azure_Database::get_table_name('donation_campaigns');
        if (!$table) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE LOWER(name) = LOWER(%s) LIMIT 1",
            $name
        ));
    }

    public static function get_wag_campaign_id() {
        return max(0, (int) Azure_Settings::get_setting('donations_wag_campaign', 0));
    }

    /**
     * Parse [Donation-progress campaign="…"] into a lookup type.
     * "WAG" (and donation-items aliases) use the Donation Items campaign dropdown.
     *
     * @return array{type:string,id?:int,name?:string}
     */
    public static function normalize_progress_campaign_attr($attr) {
        $attr = trim((string) $attr);
        $alias = strtolower($attr);
        if (in_array($alias, array('wag', 'donation-items', 'donation_items', 'donation items'), true)) {
            return array('type' => 'wag');
        }
        if ($attr !== '' && ctype_digit($attr)) {
            return array('type' => 'id', 'id' => (int) $attr);
        }
        return array('type' => 'name', 'name' => $attr);
    }

    public static function resolve_progress_campaign($attr) {
        $parsed = self::normalize_progress_campaign_attr($attr);
        if ($parsed['type'] === 'wag') {
            $campaign = self::get_campaign_by_id(self::get_wag_campaign_id());
            if ($campaign) {
                return $campaign;
            }
            return self::get_campaign_by_name('WAG');
        }
        if ($parsed['type'] === 'id') {
            return self::get_campaign_by_id($parsed['id']);
        }
        if (!empty($parsed['name'])) {
            return self::get_campaign_by_name($parsed['name']);
        }
        return self::get_default_campaign();
    }

    public static function format_progress_totals($raised, $goal) {
        $raised = max(0.0, (float) $raised);
        $goal = max(0.0, (float) $goal);
        $pct = 0;
        if ($goal > 0) {
            $pct = (int) min(100, round(($raised / $goal) * 100));
        } elseif ($raised > 0) {
            $pct = 100;
        }
        return array(
            'raised' => $raised,
            'goal'   => $goal,
            'pct'    => $pct,
        );
    }

    /**
     * A line item matches Donation Items when its variation is mapped, or
     * (if that row has no variation) when the parent product is mapped.
     */
    public static function is_wag_mapped_item($product_id, $variation_id = 0) {
        $product_id = (int) $product_id;
        $variation_id = (int) $variation_id;
        foreach (self::get_wag_levels() as $level) {
            $pid = (int) $level['product_id'];
            $vid = (int) $level['variation_id'];
            if ($vid > 0) {
                if ($variation_id === $vid) {
                    return true;
                }
                continue;
            }
            if ($pid > 0 && ($product_id === $pid || $variation_id === $pid)) {
                return true;
            }
        }
        return false;
    }

    public static function wag_mapped_ids() {
        $variations = array();
        $products = array();
        foreach (self::get_wag_levels() as $level) {
            $pid = (int) $level['product_id'];
            $vid = (int) $level['variation_id'];
            if ($vid > 0) {
                $variations[$vid] = true;
            } elseif ($pid > 0) {
                $products[$pid] = true;
            }
        }
        return array(
            'variations' => array_map('intval', array_keys($variations)),
            'products'   => array_map('intval', array_keys($products)),
        );
    }

    public static function get_campaign_raised($campaign) {
        $campaign_id = is_object($campaign) ? (int) $campaign->id : (int) $campaign;
        if ($campaign_id <= 0) {
            return 0.0;
        }
        $raised = self::sum_recorded_for_campaign($campaign_id);
        if ($campaign_id === self::get_wag_campaign_id()) {
            $raised += self::sum_unrecorded_wag_sales($campaign_id);
        }
        return round(max(0.0, (float) $raised), 2);
    }

    public static function sum_recorded_for_campaign($campaign_id) {
        $campaign_id = (int) $campaign_id;
        if ($campaign_id <= 0) {
            return 0.0;
        }
        global $wpdb;
        $table = Azure_Database::get_table_name('donation_records');
        if (!$table || !isset($wpdb) || !is_object($wpdb)) {
            return 0.0;
        }
        $sum = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM {$table} WHERE campaign_id = %d",
            $campaign_id
        ));
        return (float) $sum;
    }

    /**
     * Paid WooCommerce sales of mapped Donation Items that are not already
     * in donation_records for this campaign (covers shop purchases that
     * never went through [WAG]).
     */
    public static function sum_unrecorded_wag_sales($campaign_id) {
        $ids = self::wag_mapped_ids();
        if (empty($ids['variations']) && empty($ids['products'])) {
            return 0.0;
        }
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return 0.0;
        }
        $lookup = $wpdb->prefix . 'wc_order_product_lookup';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lookup)) !== $lookup) {
            return 0.0;
        }

        $match = array();
        if (!empty($ids['variations'])) {
            $match[] = 'l.variation_id IN (' . implode(',', $ids['variations']) . ')';
        }
        if (!empty($ids['products'])) {
            $match[] = 'l.product_id IN (' . implode(',', $ids['products']) . ')';
        }
        $match_sql = implode(' OR ', $match);

        $records = Azure_Database::get_table_name('donation_records');
        $exclude = '0';
        if ($records && $campaign_id > 0) {
            $exclude = $wpdb->prepare(
                "SELECT order_id FROM {$records} WHERE campaign_id = %d AND order_id > 0 AND product_id > 0",
                $campaign_id
            );
        }

        $hpos = $wpdb->prefix . 'wc_orders';
        $paid = "'wc-completed','wc-processing'";
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos)) === $hpos) {
            $sql = "SELECT COALESCE(SUM(l.product_net_revenue),0)
                    FROM {$lookup} l
                    INNER JOIN {$hpos} o ON o.id = l.order_id
                    WHERE o.status IN ({$paid})
                      AND ({$match_sql})
                      AND l.order_id NOT IN ({$exclude})";
        } else {
            $sql = "SELECT COALESCE(SUM(l.product_net_revenue),0)
                    FROM {$lookup} l
                    INNER JOIN {$wpdb->posts} o ON o.ID = l.order_id
                    WHERE o.post_status IN ({$paid})
                      AND ({$match_sql})
                      AND l.order_id NOT IN ({$exclude})";
        }

        return (float) $wpdb->get_var($sql);
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
            $cart->add_fee($this->get_custom_donation_fee_label(), $amount, false);
        }
    }

    /**
     * Cart fee name for a selected quick-amount entry (or campaign fallback).
     */
    private function get_custom_donation_fee_label() {
        $session = WC()->session;
        $entry_label = $session ? trim((string) $session->get('pta_donation_label', '')) : '';
        if ($entry_label !== '') {
            return $entry_label;
        }
        $campaign = self::get_default_campaign();
        return $campaign ? 'Donation - ' . $campaign->name : 'Donation';
    }

    public function ajax_update_fee() {
        check_ajax_referer('pta_donations_nonce', 'nonce');

        $type   = sanitize_text_field($_POST['type'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $active = !empty($_POST['active']);
        $label  = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
        if (strlen($label) > 200) {
            $label = substr($label, 0, 200);
        }

        $session = WC()->session;
        if (!$session) {
            wp_send_json_error('No session');
            return;
        }

        if ($type === 'roundup') {
            $session->set('pta_donation_roundup', $active);
            if ($active) {
                $session->set('pta_donation_custom', 0);
                $session->set('pta_donation_label', '');
            }
        } elseif ($type === 'custom') {
            $session->set('pta_donation_custom', $active ? $amount : 0);
            $session->set('pta_donation_label', ($active && $amount > 0) ? $label : '');
            if ($active && $amount > 0) {
                $session->set('pta_donation_roundup', false);
            }
        } elseif ($type === 'clear') {
            $session->set('pta_donation_roundup', false);
            $session->set('pta_donation_custom', 0);
            $session->set('pta_donation_label', '');
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
        $enable_custom  = self::amounts_enabled();
        $quick_entries  = self::get_quick_amount_entries();

        if (!$enable_roundup && !$enable_custom) return;

        $session = WC()->session;
        $roundup_active = $session ? $session->get('pta_donation_roundup', false) : false;
        $custom_active  = $session ? floatval($session->get('pta_donation_custom', 0)) : 0;
        $session_label  = $session ? trim((string) $session->get('pta_donation_label', '')) : '';

        $named_amounts = array();
        $has_custom_entry = false;
        foreach ($quick_entries as $entry) {
            if (!empty($entry['custom'])) {
                $has_custom_entry = true;
            } else {
                $named_amounts[] = floatval($entry['amount']);
            }
        }
        $custom_amount_is_other = $custom_active > 0 && !in_array($custom_active, $named_amounts, true);
        $custom_wrap_open = false;
        foreach ($quick_entries as $entry) {
            if (empty($entry['custom'])) {
                continue;
            }
            if ($session_label === $entry['label'] || ($session_label === '' && $custom_amount_is_other)) {
                $custom_wrap_open = true;
            }
        }

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
                    <?php foreach ($quick_entries as $entry):
                        $is_custom_entry = !empty($entry['custom']);
                        if ($session_label !== '') {
                            $is_active = ($session_label === $entry['label']);
                        } else {
                            $is_active = $is_custom_entry
                                ? $custom_amount_is_other
                                : ($custom_active > 0 && abs($custom_active - floatval($entry['amount'])) < 0.001);
                        }
                        ?>
                        <button type="button" class="pta-donate-quick button<?php echo $is_active ? ' active' : ''; ?>"
                                data-amount="<?php echo esc_attr($is_custom_entry ? '' : $entry['amount']); ?>"
                                data-label="<?php echo esc_attr($entry['label']); ?>"
                                data-custom="<?php echo $is_custom_entry ? '1' : '0'; ?>">
                            <?php echo esc_html($entry['label']); ?>
                        </button>
                    <?php endforeach; ?>
                    <?php if ($has_custom_entry): ?>
                    <div class="pta-donate-custom-wrap"<?php echo $custom_wrap_open ? '' : ' hidden'; ?>>
                        <span>$</span>
                        <input type="number" class="pta-donate-custom-input" min="0" step="0.01" placeholder="<?php esc_attr_e('Amount', 'azure-plugin'); ?>"
                               value="<?php echo $custom_wrap_open && $custom_active > 0 ? esc_attr($custom_active) : ''; ?>" />
                    </div>
                    <?php endif; ?>
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

            function updateDonation(type, amount, active, label) {
                $.post(ajaxUrl, {
                    action: 'azure_donations_update_fee',
                    nonce: nonce,
                    type: type,
                    amount: amount,
                    active: active ? 1 : 0,
                    label: label || ''
                }, function() {
                    refreshTotals();
                });
            }

            function customLabel() {
                var $customBtn = $w.find('.pta-donate-quick[data-custom="1"]').first();
                return $customBtn.length ? String($customBtn.data('label') || 'Custom') : 'Custom';
            }

            $w.find('.pta-roundup-toggle').on('change', function() {
                var on = $(this).is(':checked');
                if (on) {
                    $w.find('.pta-donate-quick').removeClass('active');
                    $w.find('.pta-donate-custom-input').val('');
                    $w.find('.pta-donate-custom-wrap').prop('hidden', true);
                }
                updateDonation('roundup', 0, on);
            });

            $w.find('.pta-donate-quick').on('click', function() {
                var $btn = $(this);
                var isCustom = String($btn.data('custom')) === '1';
                var amt = parseFloat($btn.data('amount')) || 0;
                var label = String($btn.data('label') || '');
                var wasActive = $btn.hasClass('active');
                $w.find('.pta-donate-quick').removeClass('active');
                $w.find('.pta-roundup-toggle').prop('checked', false);

                if (wasActive) {
                    $w.find('.pta-donate-custom-wrap').prop('hidden', true);
                    $w.find('.pta-donate-custom-input').val('');
                    updateDonation('clear', 0, false);
                    return;
                }

                $btn.addClass('active');
                if (isCustom) {
                    $w.find('.pta-donate-custom-wrap').prop('hidden', false);
                    $w.find('.pta-donate-custom-input').focus();
                    var existing = parseFloat($w.find('.pta-donate-custom-input').val());
                    if (existing > 0) {
                        updateDonation('custom', existing, true, label);
                    }
                } else {
                    $w.find('.pta-donate-custom-wrap').prop('hidden', true);
                    $w.find('.pta-donate-custom-input').val('');
                    updateDonation('custom', amt, true, label);
                }
            });

            var customTimer;
            $w.find('.pta-donate-custom-input').on('input', function() {
                clearTimeout(customTimer);
                var val = parseFloat($(this).val());
                var label = customLabel();
                customTimer = setTimeout(function() {
                    $w.find('.pta-donate-quick').removeClass('active');
                    $w.find('.pta-donate-quick[data-custom="1"]').addClass('active');
                    $w.find('.pta-roundup-toggle').prop('checked', false);
                    if (val > 0) {
                        updateDonation('custom', val, true, label);
                    } else {
                        updateDonation('clear', 0, false);
                    }
                }, 500);
            });

            $w.find('.pta-donate-clear').on('click', function() {
                $w.find('.pta-donate-quick').removeClass('active');
                $w.find('.pta-donate-custom-input').val('');
                $w.find('.pta-donate-custom-wrap').prop('hidden', true);
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
        $enable_custom  = self::amounts_enabled();
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

        $wag_campaign_id = self::get_wag_campaign_id();

        foreach ($order->get_items() as $item) {
            if (!is_object($item) || !method_exists($item, 'get_total')) {
                continue;
            }
            $amount = abs(floatval($item->get_total()));
            if ($amount <= 0) {
                continue;
            }
            $product_id = method_exists($item, 'get_product_id') ? (int) $item->get_product_id() : 0;
            $variation_id = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
            $product_name = method_exists($item, 'get_name') ? $item->get_name() : '';
            $is_gift = method_exists($item, 'get_meta') && $item->get_meta('_pta_donated_product');
            $is_wag = self::is_wag_mapped_item($product_id, $variation_id);

            if (!$is_gift && !$is_wag) {
                continue;
            }

            $item_campaign = $is_wag && $wag_campaign_id > 0 ? $wag_campaign_id : $campaign_id;
            $type = $is_wag ? 'wag' : 'product';
            if ($this->insert_donation_record($records_table, array(
                'campaign_id'   => $item_campaign,
                'order_id'      => (int) $order_id,
                'user_id'       => $user_id,
                'amount'        => $amount,
                'donation_type' => $type,
                'product_id'    => $variation_id > 0 ? $variation_id : $product_id,
                'product_name'  => $product_name,
                'donor_role'    => $donor_role,
                'created_at'    => current_time('mysql'),
            ))) {
                $recorded++;
                $this->bump_campaign_raised($campaigns_table, $item_campaign, $amount);
                if ($is_gift && !$is_wag) {
                    $this->send_admin_donation_email($order, $product_name);
                }
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
            WC()->session->set('pta_donation_label', '');
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
        if (!empty($values['_pta_donated_product'])) {
            $item->update_meta_data('_pta_donated_product', 1);
            $item->update_meta_data(__('Donation', 'azure-plugin'), __('Gift item', 'azure-plugin'));
        }
        if (!empty($values['_pta_custom_donation_amount'])) {
            $amount = (float) $values['_pta_custom_donation_amount'];
            $item->update_meta_data('_pta_custom_donation_amount', $amount);
            $item->update_meta_data(
                __('Donation amount', 'azure-plugin'),
                function_exists('wc_price') ? wp_strip_all_tags(wc_price($amount)) : ('$' . number_format($amount, 2))
            );
        }
    }

    public function display_donated_item_data($item_data, $cart_item) {
        if (!empty($cart_item['_pta_donated_product'])) {
            $item_data[] = array(
                'key'   => __('Donation', 'azure-plugin'),
                'value' => __('Gift item — product fields skipped', 'azure-plugin'),
            );
        }
        if (!empty($cart_item['_pta_custom_donation_amount'])) {
            $amount = (float) $cart_item['_pta_custom_donation_amount'];
            $item_data[] = array(
                'key'   => __('Donation amount', 'azure-plugin'),
                'value' => function_exists('wc_price') ? wp_strip_all_tags(wc_price($amount)) : ('$' . number_format($amount, 2)),
            );
        }
        return $item_data;
    }

    public static function amounts_enabled() {
        return !empty(Azure_Settings::get_setting('donations_enable_custom', ''));
    }

    public static function gift_products_enabled() {
        $settings = Azure_Settings::get_all_settings();
        if (!array_key_exists('donations_enable_gift_products', $settings)) {
            return true;
        }
        return !empty($settings['donations_enable_gift_products']);
    }

    public static function default_quick_amount_entries() {
        return array(
            array(
                'label'  => 'Wolf Pack - $150 Per student',
                'amount' => 150,
                'custom' => false,
            ),
            array(
                'label'  => 'Helpful Howler - $250 per student',
                'amount' => 250,
                'custom' => false,
            ),
            array(
                'label'  => 'Positive Paw - $500 per student',
                'amount' => 500,
                'custom' => false,
            ),
            array(
                'label'  => 'Custom',
                'amount' => 0,
                'custom' => true,
            ),
        );
    }

    public static function sanitize_quick_amount_entries($raw) {
        $out = array();
        if (!is_array($raw)) {
            return $out;
        }
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = isset($row['label']) ? sanitize_text_field($row['label']) : '';
            if ($label === '') {
                continue;
            }
            $custom = !empty($row['custom']);
            $amount = isset($row['amount']) ? round(floatval($row['amount']), 2) : 0;
            if (!$custom && $amount <= 0) {
                continue;
            }
            $out[] = array(
                'label'  => $label,
                'amount' => $custom ? 0 : $amount,
                'custom' => $custom,
            );
        }
        return $out;
    }

    /**
     * Named checkout / shortcode donation options.
     * Accepts the new [{label, amount, custom}] array, JSON, or legacy "10,50,100".
     */
    public static function get_quick_amount_entries() {
        $raw = Azure_Settings::get_setting('donations_quick_amounts', null);

        if ($raw === null || $raw === '' || $raw === '1,5,10') {
            return self::default_quick_amount_entries();
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }

        if (is_array($raw)) {
            $entries = self::sanitize_quick_amount_entries($raw);
            return !empty($entries) ? $entries : self::default_quick_amount_entries();
        }

        if (is_string($raw) && preg_match('/^[0-9.,\s]+$/', $raw)) {
            $entries = array();
            foreach (array_filter(array_map('floatval', explode(',', $raw))) as $amt) {
                $decimals = (fmod($amt, 1) === 0.0) ? 0 : 2;
                $entries[] = array(
                    'label'  => '$' . number_format($amt, $decimals),
                    'amount' => $amt,
                    'custom' => false,
                );
            }
            return !empty($entries) ? $entries : self::default_quick_amount_entries();
        }

        return self::default_quick_amount_entries();
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

    public static function wag_enabled() {
        return !empty(Azure_Settings::get_setting('donations_enable_wag', ''));
    }

    public static function wag_progress_enabled() {
        return !empty(Azure_Settings::get_setting('donations_wag_show_progress', ''));
    }

    public static function default_wag_heading() {
        $org = '';
        if (class_exists('Azure_Settings')) {
            $org = trim((string) Azure_Settings::get_setting('org_name', ''));
        }
        if ($org === '' && function_exists('get_bloginfo')) {
            $org = trim((string) get_bloginfo('name'));
        }
        if ($org === '') {
            $org = 'PTSA';
        }
        return sprintf('Fund the %s budget and help us reach our $40,000 goal for our kids.', $org);
    }

    public static function default_wag_levels() {
        return array(
            array(
                'amount'       => 500,
                'name'         => 'Pack Leader',
                'suffix'       => 'per student',
                'product_id'   => 0,
                'variation_id' => 0,
            ),
            array(
                'amount'       => 250,
                'name'         => 'Helpful Howler',
                'suffix'       => 'per student',
                'product_id'   => 0,
                'variation_id' => 0,
            ),
            array(
                'amount'       => 150,
                'name'         => 'Positive Paw',
                'suffix'       => 'per student',
                'product_id'   => 0,
                'variation_id' => 0,
            ),
        );
    }

    public static function sanitize_wag_color($value, $fallback) {
        $value = is_string($value) ? trim($value) : '';
        if (function_exists('sanitize_hex_color')) {
            $clean = sanitize_hex_color($value);
            if (is_string($clean) && $clean !== '') {
                return $clean;
            }
        }
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
            return $value;
        }
        return $fallback;
    }

    public static function sanitize_wag_levels($raw) {
        $defaults = self::default_wag_levels();
        $rows = is_array($raw) ? array_values($raw) : array();
        $out = array();
        for ($i = 0; $i < 3; $i++) {
            $row = (isset($rows[$i]) && is_array($rows[$i])) ? $rows[$i] : array();
            $d = $defaults[$i];
            $amount = isset($row['amount']) ? round(floatval($row['amount']), 2) : $d['amount'];
            if ($amount < 0) {
                $amount = 0;
            }
            $name = isset($row['name']) ? sanitize_text_field($row['name']) : $d['name'];
            if ($name === '') {
                $name = $d['name'];
            }
            $suffix = isset($row['suffix']) ? sanitize_text_field($row['suffix']) : $d['suffix'];
            $out[] = array(
                'amount'       => $amount,
                'name'         => $name,
                'suffix'       => $suffix,
                'product_id'   => isset($row['product_id']) ? max(0, (int) $row['product_id']) : 0,
                'variation_id' => isset($row['variation_id']) ? max(0, (int) $row['variation_id']) : 0,
            );
        }
        return $out;
    }

    public static function get_wag_levels() {
        return self::sanitize_wag_levels(Azure_Settings::get_setting('donations_wag_levels', array()));
    }

    public static function get_wag_bg() {
        return self::sanitize_wag_color(
            Azure_Settings::get_setting('donations_wag_bg', self::WAG_DEFAULT_BG),
            self::WAG_DEFAULT_BG
        );
    }

    public static function get_wag_fg() {
        return self::sanitize_wag_color(
            Azure_Settings::get_setting('donations_wag_fg', self::WAG_DEFAULT_FG),
            self::WAG_DEFAULT_FG
        );
    }

    public static function custom_amount_min() {
        return (float) self::CUSTOM_AMOUNT_MIN;
    }

    /**
     * True when a variation attribute/name is the typed-amount "Custom" option.
     * "Customer" does not match.
     */
    public static function is_custom_amount_label($label) {
        $n = strtolower(trim(preg_replace('/[\s_\-]+/', ' ', strip_tags((string) $label))));
        return $n === 'custom' || $n === 'custom amount';
    }

    /**
     * Parse a typed donation. Null when empty, non-numeric, or below the minimum.
     *
     * @param mixed $raw
     * @return float|null
     */
    public static function sanitize_custom_donation_amount($raw) {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_string($raw)) {
            $raw = str_replace(array('$', ',', ' '), '', $raw);
        }
        if (!is_numeric($raw)) {
            return null;
        }
        $amount = round((float) $raw, 2);
        if ($amount < self::custom_amount_min()) {
            return null;
        }
        return $amount;
    }

    public static function is_wag_parent_product($product_id) {
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            return false;
        }
        foreach (self::get_wag_levels() as $level) {
            if ((int) $level['product_id'] === $product_id) {
                return true;
            }
        }
        return false;
    }

    public static function is_wag_donation_product($product_id) {
        $product_id = (int) $product_id;
        if (self::is_wag_parent_product($product_id)) {
            return true;
        }
        if (!function_exists('wc_get_product')) {
            return false;
        }
        $product = wc_get_product($product_id);
        if (!$product) {
            return false;
        }
        $slug = method_exists($product, 'get_slug') ? (string) $product->get_slug() : '';
        if ($slug === 'wag-donation') {
            return true;
        }
        $sku = method_exists($product, 'get_sku') ? (string) $product->get_sku() : '';
        return (stripos($sku, 'WAG-') === 0);
    }

    public static function variation_uses_typed_amount($variation, $parent = null) {
        if (!is_object($variation)) {
            return false;
        }
        $parent_id = 0;
        if (is_object($parent) && method_exists($parent, 'get_id')) {
            $parent_id = (int) $parent->get_id();
        } elseif (method_exists($variation, 'get_parent_id')) {
            $parent_id = (int) $variation->get_parent_id();
        }
        if ($parent_id > 0 && !self::is_wag_donation_product($parent_id)) {
            return false;
        }
        $attrs = method_exists($variation, 'get_attributes') ? (array) $variation->get_attributes() : array();
        foreach ($attrs as $val) {
            if (is_array($val)) {
                $val = implode(' ', $val);
            }
            if (self::is_custom_amount_label($val)) {
                return true;
            }
        }
        $name = method_exists($variation, 'get_name') ? (string) $variation->get_name() : '';
        return (bool) preg_match('/(?:^|[\s\-])custom(?:$|[\s\-])/i', $name);
    }

    public static function format_wag_amount($amount) {
        $amount = (float) $amount;
        $decimals = (fmod($amount, 1.0) === 0.0) ? 0 : 2;
        return '$' . number_format($amount, $decimals);
    }

    /**
     * Product page URL with the mapped variation pre-selected when possible.
     */
    public static function wag_level_url($level) {
        $vid = isset($level['variation_id']) ? (int) $level['variation_id'] : 0;
        $pid = isset($level['product_id']) ? (int) $level['product_id'] : 0;

        if (function_exists('wc_get_product')) {
            if ($vid > 0) {
                $product = wc_get_product($vid);
                if ($product && is_callable(array($product, 'is_type')) && $product->is_type('variation')) {
                    return $product->get_permalink();
                }
            }
            if ($pid > 0) {
                $product = wc_get_product($pid);
                if ($product && is_callable(array($product, 'get_permalink'))) {
                    return $product->get_permalink();
                }
            }
        }

        if ($pid > 0 && function_exists('get_permalink')) {
            $url = get_permalink($pid);
            return $url ? $url : '';
        }

        return '';
    }

    public function ajax_get_variations() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        if (!self::current_user_can_manage()) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        if ($product_id <= 0 || !function_exists('wc_get_product')) {
            wp_send_json_success(array());
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product || !is_callable(array($product, 'is_type')) || !$product->is_type('variable')) {
            wp_send_json_success(array());
            return;
        }

        $out = array();
        $children = $product->get_children();
        foreach ($children as $variation_id) {
            $variation = wc_get_product((int) $variation_id);
            if (!$variation || !$variation->exists()) {
                continue;
            }
            $label = is_callable(array($variation, 'get_formatted_name'))
                ? wp_strip_all_tags($variation->get_formatted_name())
                : wp_strip_all_tags($variation->get_name());
            $out[] = array(
                'id'    => (int) $variation->get_id(),
                'label' => $label,
            );
        }

        wp_send_json_success($out);
    }

    public function ajax_add_gift_product() {
        check_ajax_referer('pta_donations_nonce', 'nonce');
        if (!class_exists('WooCommerce')) {
            wp_send_json_error(array('message' => __('WooCommerce is required.', 'azure-plugin')));
        }

        if (!self::gift_products_enabled()) {
            wp_send_json_error(array('message' => __('Gift products are not available.', 'azure-plugin')));
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
            'amounts'     => '',
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

        $amounts_enabled = self::amounts_enabled();
        $gifts_enabled = self::gift_products_enabled();
        $show_custom = ($atts['show_custom'] === 'yes');
        $override_amounts = trim((string) $atts['amounts']);
        $entries = array();
        if ($amounts_enabled && $override_amounts !== '') {
            foreach (array_filter(array_map('floatval', explode(',', $override_amounts))) as $amt) {
                $entries[] = array(
                    'label'  => '$' . number_format($amt, (fmod($amt, 1) === 0.0) ? 0 : 2),
                    'amount' => $amt,
                    'custom' => false,
                );
            }
            if ($show_custom) {
                $entries[] = array('label' => 'Custom', 'amount' => 0, 'custom' => true);
            }
        } elseif ($amounts_enabled) {
            $entries = self::get_quick_amount_entries();
            if (!$show_custom) {
                $entries = array_values(array_filter($entries, function ($entry) {
                    return empty($entry['custom']);
                }));
            }
        }
        $has_custom_entry = false;
        foreach ($entries as $entry) {
            if (!empty($entry['custom'])) {
                $has_custom_entry = true;
                break;
            }
        }
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
                        $donate_raised = Azure_Donations_Module::get_campaign_raised($campaign);
                        $pct = min(100, round(($donate_raised / $campaign->goal_amount) * 100));
                        ?>
                        <div class="pta-donate-progress-bar">
                            <div class="pta-donate-progress-fill" style="width: <?php echo $pct; ?>%"></div>
                        </div>
                        <span class="pta-donate-progress-text">
                            $<?php echo number_format($donate_raised, 2); ?> raised of $<?php echo number_format($campaign->goal_amount, 2); ?> goal
                        </span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($amounts_enabled && !empty($entries)): ?>
            <div class="pta-donate-amounts">
                <?php foreach ($entries as $entry):
                    $is_custom_entry = !empty($entry['custom']);
                    ?>
                    <button type="button" class="pta-donate-amount-btn"
                            data-amount="<?php echo esc_attr($is_custom_entry ? '' : $entry['amount']); ?>"
                            data-label="<?php echo esc_attr($entry['label']); ?>"
                            data-custom="<?php echo $is_custom_entry ? '1' : '0'; ?>">
                        <?php echo esc_html($entry['label']); ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <?php if ($has_custom_entry): ?>
            <div class="pta-donate-custom-input-wrap" hidden>
                <label for="pta-donate-other-<?php echo $campaign ? $campaign->id : 0; ?>"><?php esc_html_e('Custom amount:', 'azure-plugin'); ?></label>
                <div class="pta-donate-input-group">
                    <span>$</span>
                    <input type="number" class="pta-donate-other" id="pta-donate-other-<?php echo $campaign ? $campaign->id : 0; ?>"
                           min="1" step="0.01" placeholder="0.00" />
                </div>
            </div>
            <?php endif; ?>

            <button type="button" class="pta-donate-submit button"><?php echo esc_html($atts['button_text']); ?></button>
            <?php endif; ?>
            <?php
            $gifts = $gifts_enabled ? self::get_gift_products() : array();
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
            var selectedLabel = '';

            $form.find('.pta-donate-amount-btn').on('click', function() {
                var $btn = $(this);
                $form.find('.pta-donate-amount-btn').removeClass('active');
                $btn.addClass('active');
                selectedLabel = String($btn.data('label') || '');
                if (String($btn.data('custom')) === '1') {
                    selectedAmount = 0;
                    $form.find('.pta-donate-custom-input-wrap').prop('hidden', false);
                    $form.find('.pta-donate-other').focus();
                } else {
                    selectedAmount = parseFloat($btn.data('amount')) || 0;
                    $form.find('.pta-donate-other').val('');
                    $form.find('.pta-donate-custom-input-wrap').prop('hidden', true);
                }
            });

            $form.find('.pta-donate-other').on('input', function() {
                $form.find('.pta-donate-amount-btn').removeClass('active');
                $form.find('.pta-donate-amount-btn[data-custom="1"]').addClass('active');
                selectedAmount = parseFloat($(this).val()) || 0;
                selectedLabel = String($form.find('.pta-donate-amount-btn[data-custom="1"]').first().data('label') || 'Custom');
            });

            $form.find('.pta-donate-submit').on('click', function() {
                var $btn = $(this);
                var $msg = $form.find('.pta-donate-message');
                var otherVal = parseFloat($form.find('.pta-donate-other').val());
                var amount = otherVal > 0 ? otherVal : selectedAmount;
                var label = otherVal > 0
                    ? (String($form.find('.pta-donate-amount-btn[data-custom="1"]').first().data('label') || 'Custom'))
                    : selectedLabel;

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
                    active: 1,
                    label: label
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

    /**
     * Horizontal campaign thermometer. campaign="WAG" uses the Donation Items mapping.
     */
    public function shortcode_donation_progress($atts = array()) {
        $atts = shortcode_atts(array(
            'campaign' => 'WAG',
        ), $atts, 'donation-progress');

        $campaign = self::resolve_progress_campaign($atts['campaign']);
        if (!$campaign) {
            return '';
        }

        $this->enqueue_wag_styles();
        return self::render_progress_html($campaign, array('show_heading' => true, 'embedded' => false));
    }

    /**
     * @param object $campaign Campaign row.
     * @param array  $args     {show_heading:bool, embedded:bool}
     */
    public static function render_progress_html($campaign, $args = array()) {
        $show_heading = !isset($args['show_heading']) || !empty($args['show_heading']);
        $embedded = !empty($args['embedded']);
        $goal = isset($campaign->goal_amount) ? (float) $campaign->goal_amount : 0;
        $totals = self::format_progress_totals(self::get_campaign_raised($campaign), $goal);
        $bg = self::get_wag_bg();
        $fg = self::get_wag_fg();
        $name = isset($campaign->name) ? (string) $campaign->name : '';

        $raised_label = '$' . number_format($totals['raised'], $totals['raised'] == floor($totals['raised']) ? 0 : 2);
        $goal_label = '$' . number_format($totals['goal'], $totals['goal'] == floor($totals['goal']) ? 0 : 2);
        $class = 'pta-donation-progress';
        if ($embedded) {
            $class .= ' pta-donation-progress--embedded';
        }

        ob_start();
        ?>
        <div class="<?php echo esc_attr($class); ?>"<?php echo $embedded ? '' : ' style="--wag-bg: ' . esc_attr($bg) . '; --wag-fg: ' . esc_attr($fg) . ';"'; ?>>
            <?php if ($show_heading && $name !== ''): ?>
                <p class="pta-donation-progress-heading"><?php echo esc_html($name); ?></p>
            <?php endif; ?>
            <div class="pta-donation-progress-meter">
                <span class="pta-donation-progress-bulb" aria-hidden="true"></span>
                <div class="pta-donation-progress-track" role="progressbar"
                     aria-valuemin="0"
                     aria-valuemax="<?php echo $totals['goal'] > 0 ? (int) $totals['goal'] : 100; ?>"
                     aria-valuenow="<?php echo (int) round($totals['raised']); ?>"
                     aria-label="<?php echo esc_attr($name !== '' ? $name : __('Donation progress', 'azure-plugin')); ?>">
                    <div class="pta-donation-progress-fill" style="width: <?php echo (int) $totals['pct']; ?>%;"></div>
                </div>
            </div>
            <p class="pta-donation-progress-total">
                <strong><?php echo esc_html($raised_label); ?></strong>
                <?php if ($totals['goal'] > 0): ?>
                    <?php echo esc_html(sprintf(__('raised of %s goal', 'azure-plugin'), $goal_label)); ?>
                    <span class="pta-donation-progress-pct"><?php echo (int) $totals['pct']; ?>%</span>
                <?php else: ?>
                    <?php esc_html_e('raised', 'azure-plugin'); ?>
                <?php endif; ?>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Suggested giving levels mapped to a WooCommerce product variation.
     * Disabled (or unmapped) output is empty so the shortcode is safe to leave on a page.
     */
    public function shortcode_wag($atts = array()) {
        unset($atts);
        if (!self::wag_enabled()) {
            return '';
        }

        $this->enqueue_wag_styles();

        $heading = trim((string) Azure_Settings::get_setting('donations_wag_heading', ''));
        if ($heading === '') {
            $heading = self::default_wag_heading();
        }
        $label = trim((string) Azure_Settings::get_setting('donations_wag_label', ''));
        if ($label === '') {
            $label = self::WAG_DEFAULT_LABEL;
        }
        $footer = trim((string) Azure_Settings::get_setting('donations_wag_footer', ''));
        if ($footer === '') {
            $footer = self::WAG_DEFAULT_FOOTER;
        }

        $bg = self::get_wag_bg();
        $fg = self::get_wag_fg();
        $levels = self::get_wag_levels();

        ob_start();
        ?>
        <div class="pta-wag" style="--wag-bg: <?php echo esc_attr($bg); ?>; --wag-fg: <?php echo esc_attr($fg); ?>;">
            <h2 class="pta-wag-heading"><?php echo esc_html($heading); ?></h2>
            <div class="pta-wag-accent" aria-hidden="true"></div>
            <p class="pta-wag-label"><?php echo esc_html($label); ?></p>
            <div class="pta-wag-levels">
                <?php foreach ($levels as $i => $level): ?>
                    <?php
                    $url = self::wag_level_url($level);
                    $featured = ($i === 0) ? ' is-featured' : '';
                    $amount = self::format_wag_amount($level['amount']);
                    ?>
                    <?php if ($url !== ''): ?>
                        <a class="pta-wag-level<?php echo esc_attr($featured); ?>" href="<?php echo esc_url($url); ?>">
                            <span class="pta-wag-amount"><?php echo esc_html($amount); ?></span>
                            <span class="pta-wag-name"><?php echo esc_html($level['name']); ?></span>
                            <?php if ($level['suffix'] !== ''): ?>
                                <span class="pta-wag-suffix"><?php echo esc_html($level['suffix']); ?></span>
                            <?php endif; ?>
                        </a>
                    <?php else: ?>
                        <span class="pta-wag-level<?php echo esc_attr($featured); ?>">
                            <span class="pta-wag-amount"><?php echo esc_html($amount); ?></span>
                            <span class="pta-wag-name"><?php echo esc_html($level['name']); ?></span>
                            <?php if ($level['suffix'] !== ''): ?>
                                <span class="pta-wag-suffix"><?php echo esc_html($level['suffix']); ?></span>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php
            if (self::wag_progress_enabled()) {
                $progress_campaign = self::resolve_progress_campaign('WAG');
                if ($progress_campaign) {
                    echo self::render_progress_html($progress_campaign, array(
                        'show_heading' => false,
                        'embedded'     => true,
                    ));
                }
            }
            ?>
            <?php if ($footer !== ''): ?>
                <p class="pta-wag-footer"><?php echo esc_html($footer); ?></p>
            <?php endif; ?>
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

    public function render_custom_amount_field() {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        $product_id = (int) get_the_ID();
        if (!self::is_wag_donation_product($product_id)) {
            return;
        }
        $min = self::custom_amount_min();
        ?>
        <div class="pta-custom-donation-amount" hidden>
            <label for="pta_custom_donation_amount"><?php esc_html_e('Donation amount', 'azure-plugin'); ?></label>
            <div class="pta-custom-donation-input-wrap">
                <span class="pta-custom-donation-prefix" aria-hidden="true">$</span>
                <input type="number" name="pta_custom_donation_amount" id="pta_custom_donation_amount"
                       min="<?php echo esc_attr($min); ?>" step="0.01" inputmode="decimal"
                       placeholder="<?php echo esc_attr(number_format($min, 0)); ?>" />
            </div>
            <p class="pta-custom-donation-hint"><?php echo esc_html(sprintf(__('Enter any amount of $%s or more.', 'azure-plugin'), number_format($min, 0))); ?></p>
        </div>
        <?php
    }

    public function flag_custom_amount_variation($data, $product, $variation) {
        if (self::variation_uses_typed_amount($variation, $product)) {
            $data['pta_custom_amount'] = true;
            $data['pta_custom_amount_min'] = self::custom_amount_min();
            $data['price_html'] = '<span class="price">' . esc_html__('Enter an amount', 'azure-plugin') . '</span>';
        }
        return $data;
    }

    public function validate_custom_amount($passed, $product_id, $quantity, $variation_id = 0, $variations = array()) {
        if (!$passed || (int) $variation_id <= 0 || !function_exists('wc_get_product')) {
            return $passed;
        }
        $variation = wc_get_product((int) $variation_id);
        $parent = wc_get_product((int) $product_id);
        if (!self::variation_uses_typed_amount($variation, $parent)) {
            return $passed;
        }
        $amount = self::sanitize_custom_donation_amount(isset($_POST['pta_custom_donation_amount']) ? wp_unslash($_POST['pta_custom_donation_amount']) : '');
        if ($amount === null) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice(
                    sprintf(
                        __('Please enter a donation of at least $%s.', 'azure-plugin'),
                        number_format(self::custom_amount_min(), 0)
                    ),
                    'error'
                );
            }
            return false;
        }
        return $passed;
    }

    public function add_custom_amount_cart_data($cart_item_data, $product_id, $variation_id) {
        if ((int) $variation_id <= 0 || !function_exists('wc_get_product')) {
            return $cart_item_data;
        }
        $variation = wc_get_product((int) $variation_id);
        $parent = wc_get_product((int) $product_id);
        if (!self::variation_uses_typed_amount($variation, $parent)) {
            return $cart_item_data;
        }
        $amount = self::sanitize_custom_donation_amount(isset($_POST['pta_custom_donation_amount']) ? wp_unslash($_POST['pta_custom_donation_amount']) : '');
        if ($amount !== null) {
            $cart_item_data['_pta_custom_donation_amount'] = $amount;
        }
        return $cart_item_data;
    }

    public function force_custom_amount_quantity($quantity, $product_id) {
        $variation_id = isset($_POST['variation_id']) ? (int) $_POST['variation_id'] : 0;
        if ($variation_id <= 0 || !function_exists('wc_get_product')) {
            return $quantity;
        }
        $variation = wc_get_product($variation_id);
        $parent = wc_get_product((int) $product_id);
        if (self::variation_uses_typed_amount($variation, $parent)) {
            return 1;
        }
        return $quantity;
    }

    public function apply_custom_amount_price($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        if (!$cart || !is_object($cart) || !method_exists($cart, 'get_cart')) {
            return;
        }
        foreach ($cart->get_cart() as $cart_item) {
            if (empty($cart_item['_pta_custom_donation_amount']) || empty($cart_item['data'])) {
                continue;
            }
            $amount = (float) $cart_item['_pta_custom_donation_amount'];
            if ($amount < self::custom_amount_min()) {
                continue;
            }
            if (method_exists($cart_item['data'], 'set_price')) {
                $cart_item['data']->set_price($amount);
            }
        }
    }

    public function lock_custom_amount_cart_qty($product_quantity, $cart_item_key, $cart_item) {
        if (!empty($cart_item['_pta_custom_donation_amount'])) {
            return '1';
        }
        return $product_quantity;
    }

    // ─── Frontend Assets ─────────────────────────────────────────────

    public function enqueue_frontend_assets() {
        $post = get_post();
        $content = $post ? ($post->post_content ?? '') : '';
        $has_shortcode = $post && (
            has_shortcode($content, 'pta-donate')
            || has_shortcode($content, 'donations-list')
            || has_shortcode($content, 'wag')
            || has_shortcode($content, 'WAG')
            || has_shortcode($content, 'donation-progress')
            || has_shortcode($content, 'Donation-progress')
        );
        $is_wag_product = function_exists('is_product') && is_product() && self::is_wag_donation_product((int) get_the_ID());
        if (!is_checkout() && !is_cart() && !$has_shortcode && !$is_wag_product) {
            return;
        }

        $this->enqueue_wag_styles();

        if ($is_wag_product) {
            wp_enqueue_script(
                'pta-donations-custom-amount',
                AZURE_PLUGIN_URL . 'js/donations-custom-amount.js',
                array('jquery'),
                AZURE_PLUGIN_VERSION,
                true
            );
            wp_localize_script('pta-donations-custom-amount', 'ptaCustomDonation', array(
                'min' => self::custom_amount_min(),
            ));
        }
    }

    private function enqueue_wag_styles() {
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
        if (!self::current_user_can_manage()) {
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
        if (!self::current_user_can_manage()) {
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
        if (!self::current_user_can_manage()) {
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
        if (!self::current_user_can_manage()) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $fields = array(
            'donations_enable_roundup',
            'donations_enable_custom',
            'donations_enable_gift_products',
            'donations_enable_wag',
            'donations_wag_show_progress',
            'donations_default_campaign',
            'donations_wag_campaign',
            'donations_wag_heading',
            'donations_wag_label',
            'donations_wag_footer',
        );

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                Azure_Settings::update_setting($field, sanitize_text_field($_POST[$field]));
            }
        }

        if (isset($_POST['donations_wag_bg'])) {
            Azure_Settings::update_setting(
                'donations_wag_bg',
                self::sanitize_wag_color(wp_unslash($_POST['donations_wag_bg']), self::WAG_DEFAULT_BG)
            );
        }
        if (isset($_POST['donations_wag_fg'])) {
            Azure_Settings::update_setting(
                'donations_wag_fg',
                self::sanitize_wag_color(wp_unslash($_POST['donations_wag_fg']), self::WAG_DEFAULT_FG)
            );
        }

        if (isset($_POST['donations_quick_amounts'])) {
            $raw = json_decode(wp_unslash($_POST['donations_quick_amounts']), true);
            Azure_Settings::update_setting('donations_quick_amounts', self::sanitize_quick_amount_entries($raw));
        }

        if (isset($_POST['donations_gift_products'])) {
            $raw = json_decode(wp_unslash($_POST['donations_gift_products']), true);
            Azure_Settings::update_setting('donations_gift_products', self::sanitize_gift_products($raw));
        }

        if (isset($_POST['donations_wag_levels'])) {
            $raw = json_decode(wp_unslash($_POST['donations_wag_levels']), true);
            Azure_Settings::update_setting('donations_wag_levels', self::sanitize_wag_levels($raw));
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
