<?php
/**
 * Membership module (v3.145)
 *
 * Two lists, kept separate on purpose:
 *   1. Paid members this school year — WooCommerce Family/Individual products.
 *      Powers the admin roster + the WA/LW CSV export.
 *   2. Parent directory — only parents whose own opt-in checkbox is truthy.
 *
 * No custom table. Membership is derived from orders; directory rows come
 * from existing `pta_pf_*` user meta on the purchaser / family-primary profile.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Membership_Module {

    const TRANSIENT_MAP     = 'azure_membership_map';
    const OPTION_FAMILY     = 'membership_family_product_ids';
    const OPTION_INDIVIDUAL = 'membership_individual_product_ids';
    const META_P1_OPT_IN    = 'pta_pf_parent_1_opt_in';
    const META_P2_OPT_IN    = 'pta_pf_parent_2_opt_in';
    const META_P1_NAME      = 'pta_pf_parent_1_name';
    const META_P2_NAME      = 'pta_pf_parent_2_name';
    const META_P1_EMAIL     = 'pta_pf_parent_1_email';
    const META_P2_EMAIL     = 'pta_pf_parent_2_email';
    const META_P1_CELL      = 'pta_pf_parent_1_cell';
    const META_P2_CELL      = 'pta_pf_parent_2_cell';
    const NONCE_ADMIN       = 'azure_membership_admin';
    const SHORTCODE_A       = 'parent-directory';
    const SHORTCODE_B       = 'Parent-directory';
    const META_MEMBER_DISCOUNT = '_pta_member_discount';

    private static $instance = null;
    private static $assets_enqueued = false;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (class_exists('Azure_Database')) {
            Azure_Database::seed_membership_optin_fields();
            Azure_Database::rehome_directory_optin_fields();
        }

        add_shortcode(self::SHORTCODE_A, array($this, 'render_directory_shortcode'));
        add_shortcode(self::SHORTCODE_B, array($this, 'render_directory_shortcode'));

        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_frontend'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_badge_assets'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_express_account'));
        add_filter('get_avatar', array($this, 'filter_member_avatar'), 20, 6);
        add_action('template_redirect', array($this, 'maybe_gate_directory_page'));

        add_action('woocommerce_order_status_changed', array($this, 'maybe_invalidate_map'), 10, 4);
        add_action('woocommerce_cart_calculate_fees', array($this, 'apply_cart_member_discount'));
        add_action('woocommerce_product_options_pricing', array($this, 'render_simple_discount_field'));
        add_action('woocommerce_product_options_general_product_data', array($this, 'render_variable_parent_discount_field'));
        add_action('woocommerce_variation_options_pricing', array($this, 'render_variation_discount_field'), 10, 3);
        add_action('woocommerce_process_product_meta', array($this, 'save_product_discount_field'));
        add_action('woocommerce_save_product_variation', array($this, 'save_variation_discount_field'), 10, 2);

        add_filter('woocommerce_checkout_registration_enabled', array($this, 'enable_registration_for_membership_cart'));
        add_filter('woocommerce_checkout_registration_required', array($this, 'require_registration_for_membership_cart'));
        add_filter('woocommerce_create_account_default_checked', array($this, 'check_create_account_for_membership_cart'));
        add_filter('option_woocommerce_enable_signup_and_login_from_checkout', array($this, 'force_signup_option_for_membership_cart'));
        add_filter('option_woocommerce_registration_generate_password', array($this, 'show_password_for_membership_cart'));
        add_filter('woocommerce_registration_generate_password', array($this, 'generate_password_when_express_omits_it'));
        add_filter('option_woocommerce_registration_generate_username', array($this, 'generate_username_for_membership_cart'));
        add_filter('woocommerce_new_customer_data', array($this, 'parent_role_for_membership_customer'));
        add_action('woocommerce_created_customer', array($this, 'ensure_parent_role_for_membership_customer'));
        add_action('woocommerce_after_checkout_validation', array($this, 'validate_membership_checkout_account'), 10, 2);
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'validate_store_api_membership_account'), 10, 2);
        add_action('woocommerce_store_api_checkout_order_processed', array($this, 'require_membership_order_customer'), 5);
        add_action('woocommerce_before_add_to_cart_form', array($this, 'render_product_account_notice'));
        add_action('woocommerce_before_cart', array($this, 'render_cart_account_notice'));
        add_action('woocommerce_before_checkout_form', array($this, 'render_checkout_account_notice'));
        add_filter('render_block_woocommerce/checkout', array($this, 'prepend_blocks_checkout_notice'), 10, 2);
        add_filter('wc_stripe_show_payment_request_on_product_page', array($this, 'allow_express_pay_when_account_optional'));
        add_filter('wc_stripe_show_payment_request_on_cart', array($this, 'allow_express_pay_when_account_optional'));
        add_filter('wc_stripe_show_payment_request_on_checkout', array($this, 'allow_express_pay_when_account_optional'));
        add_filter('wcpay_payment_request_is_product_supported', array($this, 'allow_express_pay_when_account_optional'));
        add_filter('wcpay_payment_request_is_cart_supported', array($this, 'allow_express_pay_when_account_optional'));
        add_filter('wcpay_payment_request_is_checkout_supported', array($this, 'allow_express_pay_when_account_optional'));
        add_filter('rest_pre_dispatch', array($this, 'force_store_api_membership_create_account'), 10, 3);

        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            add_action('admin_menu', array($this, 'register_admin_page'), 25);
            add_action('admin_init', array($this, 'maybe_export_csv'));
            add_action('wp_dashboard_setup', array($this, 'register_dashboard_widget'));
        }
    }

    // ─── School year + product settings ─────────────────────────────

    /**
     * Same window as Orders Reports `this_school_year`: Aug 1 of the
     * current year if today is Aug–Dec, otherwise Aug 1 of the prior year,
     * through end of today.
     *
     * @return array{from:string,to:string,label:string}
     */
    public static function school_year_range() {
        $tz    = wp_timezone();
        $now   = new DateTimeImmutable('now', $tz);
        $month = (int) $now->format('n');
        $year  = (int) $now->format('Y');
        $start_year = ($month >= 8) ? $year : ($year - 1);
        $from = sprintf('%04d-08-01 00:00:00', $start_year);
        $to   = $now->setTime(23, 59, 59)->format('Y-m-d H:i:s');
        $label = $start_year . '–' . ($start_year + 1);
        return array('from' => $from, 'to' => $to, 'label' => $label);
    }

    public static function get_family_product_ids() {
        return self::normalize_id_list(Azure_Settings::get_setting(self::OPTION_FAMILY, array()));
    }

    /**
     * True when this WooCommerce product (or its parent) is configured as
     * the Family PTSA membership item.
     *
     * @param int $product_id
     * @param int $parent_id
     * @return bool
     */
    public static function product_is_family($product_id, $parent_id = 0) {
        $ids = array_flip(self::get_family_product_ids());
        $product_id = (int) $product_id;
        $parent_id = (int) $parent_id;
        if ($product_id > 0 && isset($ids[$product_id])) {
            return true;
        }
        return $parent_id > 0 && isset($ids[$parent_id]);
    }

    public static function get_individual_product_ids() {
        return self::normalize_id_list(Azure_Settings::get_setting(self::OPTION_INDIVIDUAL, array()));
    }

    /**
     * True when this WooCommerce product (or its parent) is configured as
     * the Individual PTSA membership item.
     *
     * @param int $product_id
     * @param int $parent_id
     * @return bool
     */
    public static function product_is_individual($product_id, $parent_id = 0) {
        return self::ids_match(self::get_individual_product_ids(), $product_id, $parent_id);
    }

    /**
     * Family / Individual / Staff from configured product IDs, then the
     * product title so an unsaved Individual picker still matches sold
     * "PTSA Individual Membership" (and staff) rows.
     *
     * @param int    $product_id
     * @param int    $parent_id
     * @param string $name
     * @return string family|individual|staff|''
     */
    public static function classify_membership_product($product_id, $parent_id = 0, $name = '') {
        if (self::product_is_family($product_id, $parent_id)) {
            return 'family';
        }
        if (self::product_is_individual($product_id, $parent_id)) {
            return 'individual';
        }
        $name = strtolower(trim((string) $name));
        if ($name === '' && function_exists('wc_get_product')) {
            $product = wc_get_product((int) $product_id);
            if ($product) {
                $name = strtolower((string) $product->get_name());
                if ($name === '' && (int) $parent_id > 0) {
                    $parent = wc_get_product((int) $parent_id);
                    if ($parent) {
                        $name = strtolower((string) $parent->get_name());
                    }
                }
            }
        }
        return self::classify_membership_name($name);
    }

    /**
     * @param string $name
     * @return string family|individual|staff|''
     */
    public static function classify_membership_name($name) {
        $name = strtolower(trim((string) $name));
        if ($name === '') {
            return '';
        }
        $is_membership = strpos($name, 'membership') !== false
            || strpos($name, 'ptsa') !== false
            || (bool) preg_match('/\bpta\b/', $name);
        if (!$is_membership) {
            return '';
        }
        if (strpos($name, 'family') !== false) {
            return 'family';
        }
        if (strpos($name, 'staff') !== false) {
            return 'staff';
        }
        if (strpos($name, 'individual') !== false) {
            return 'individual';
        }
        return '';
    }

    /**
     * @param int[] $ids
     * @param int   $product_id
     * @param int   $parent_id
     * @return bool
     */
    private static function ids_match(array $ids, $product_id, $parent_id = 0) {
        $lookup = array_flip($ids);
        $product_id = (int) $product_id;
        $parent_id = (int) $parent_id;
        if ($product_id > 0 && isset($lookup[$product_id])) {
            return true;
        }
        return $parent_id > 0 && isset($lookup[$parent_id]);
    }

    public static function save_product_ids($family_ids, $individual_ids) {
        Azure_Settings::update_setting(self::OPTION_FAMILY, self::normalize_id_list($family_ids));
        Azure_Settings::update_setting(self::OPTION_INDIVIDUAL, self::normalize_id_list($individual_ids));
        self::flush_member_map();
    }

    private static function normalize_id_list($ids) {
        if (!is_array($ids)) {
            $ids = preg_split('/[\s,]+/', (string) $ids, -1, PREG_SPLIT_NO_EMPTY);
        }
        $out = array();
        foreach ((array) $ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[] = $id;
            }
        }
        return array_values(array_unique($out));
    }

    // ─── Member map ─────────────────────────────────────────────────

    /**
     * @return array<int, array{type:string,order_id:int,paid_at:string}>
     */
    public static function get_member_map() {
        $range = self::school_year_range();
        $ver = (int) get_option('azure_membership_map_ver', 1);
        $cache_key = self::TRANSIENT_MAP . '_' . $ver . '_g2_' . md5($range['from'] . '|' . implode(',', self::get_family_product_ids()) . '|' . implode(',', self::get_individual_product_ids()));
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $map = self::build_member_map($range);
        set_transient($cache_key, $map, HOUR_IN_SECONDS);
        return $map;
    }

    /**
     * Paid-member counts for the iOS memberships widget.
     *
     * @param array<int, array{type?:string}>|null $map
     * @return array{year:string,from:string,to:string,total:int,counts:array<string,int>}
     */
    public static function rest_summary($map = null) {
        $range = self::school_year_range();
        $map = is_array($map) ? $map : self::get_member_map();
        $counts = array(
            'family'     => 0,
            'individual' => 0,
            'staff'      => 0,
            'other'      => 0,
        );
        foreach ($map as $row) {
            $type = strtolower((string) (is_array($row) ? ($row['type'] ?? 'other') : 'other'));
            if (!isset($counts[$type])) {
                $type = 'other';
            }
            $counts[$type]++;
        }
        return array(
            'year'   => $range['label'],
            'from'   => $range['from'],
            'to'     => $range['to'],
            'total'  => count($map),
            'counts' => $counts,
        );
    }

    /**
     * Paid members for the current school year (searchable).
     *
     * @param string $search
     * @param int    $limit
     * @return array<int, array>
     */
    public static function rest_members($search = '', $limit = 200) {
        $map = self::get_member_map();
        $ids = array_map('intval', array_keys($map));
        $rows = self::build_roster_rows($ids);
        $search = strtolower(trim((string) $search));
        if ($search !== '') {
            $rows = array_values(array_filter($rows, function ($row) use ($search) {
                $hay = strtolower(
                    (string) ($row['name'] ?? '') . ' ' .
                    (string) ($row['email'] ?? '') . ' ' .
                    (string) ($row['membership'] ?? '')
                );
                return strpos($hay, $search) !== false;
            }));
        }
        $limit = max(1, min(500, (int) $limit));
        return array_slice($rows, 0, $limit);
    }

    /**
     * True when this user has a paid Family/Individual/Staff membership
     * this school year.
     */
    public static function user_is_member($user_id) {
        $user_id = (int) $user_id;
        if ($user_id < 1) {
            return false;
        }
        $map = self::get_member_map();
        return isset($map[$user_id]);
    }

    public static function member_badge_label() {
        return __('PTSA Member', 'azure-plugin');
    }

    /**
     * @param string $variant pill|avatar
     */
    public static function render_member_badge($variant = 'pill') {
        $label = self::member_badge_label();
        $class = $variant === 'avatar' ? 'pta-member-badge pta-member-badge--mark' : 'pta-member-badge pta-member-badge--pill';
        $html = '<span class="' . esc_attr($class) . '"';
        if ($variant === 'avatar') {
            $html .= ' title="' . esc_attr($label) . '" aria-label="' . esc_attr($label) . '"';
        }
        $visible = $variant === 'avatar' ? __('PTSA', 'azure-plugin') : $label;
        $html .= '>' . esc_html($visible) . '</span>';
        return $html;
    }

    public static function member_badge_html($user_id, $variant = 'pill') {
        if (!self::user_is_member($user_id)) {
            return '';
        }
        return self::render_member_badge($variant);
    }

    public static function decorate_avatar_html($avatar_html, $is_member) {
        $avatar_html = (string) $avatar_html;
        if ($avatar_html === '' || !$is_member) {
            return $avatar_html;
        }
        return '<span class="pta-member-avatar">' . $avatar_html . self::render_member_badge('avatar') . '</span>';
    }

    public static function wrap_member_avatar($avatar_html, $user_id) {
        return self::decorate_avatar_html($avatar_html, self::user_is_member($user_id));
    }

    public static function user_id_from_avatar_id($id_or_email) {
        if (is_numeric($id_or_email)) {
            return (int) $id_or_email;
        }
        if (is_object($id_or_email)) {
            if (isset($id_or_email->user_id) && (int) $id_or_email->user_id > 0) {
                return (int) $id_or_email->user_id;
            }
            if (isset($id_or_email->ID) && !isset($id_or_email->comment_ID) && !isset($id_or_email->comment_author_email)) {
                return (int) $id_or_email->ID;
            }
        }
        if (is_string($id_or_email) && $id_or_email !== '' && function_exists('get_user_by')) {
            $user = get_user_by('email', $id_or_email);
            return $user ? (int) $user->ID : 0;
        }
        return 0;
    }

    public function filter_member_avatar($avatar, $id_or_email, $size = 96, $default = '', $alt = '', $args = array()) {
        if (function_exists('is_admin') && is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) {
            return $avatar;
        }
        return self::wrap_member_avatar($avatar, self::user_id_from_avatar_id($id_or_email));
    }

    public function enqueue_badge_assets() {
        if (function_exists('is_admin') && is_admin()) {
            return;
        }
        $css = AZURE_PLUGIN_PATH . 'css/membership-badge.css';
        wp_enqueue_style(
            'pta-membership-badge',
            AZURE_PLUGIN_URL . 'css/membership-badge.css',
            array(),
            file_exists($css) ? (string) filemtime($css) : AZURE_PLUGIN_VERSION
        );
    }

    public static function sanitize_member_discount_amount($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^\s*-/', $value)) {
            return '';
        }
        if (function_exists('wc_format_decimal')) {
            $value = wc_format_decimal($value);
        } else {
            $value = preg_replace('/[^0-9.]/', '', $value);
        }
        $amount = (float) $value;
        if ($amount <= 0) {
            return '';
        }
        return (string) $amount;
    }

    /**
     * Variation amount wins when set; otherwise the parent product.
     */
    public static function resolve_member_discount_amount($variation_amount, $parent_amount) {
        $variation = self::sanitize_member_discount_amount($variation_amount);
        if ($variation !== '') {
            return (float) $variation;
        }
        $parent = self::sanitize_member_discount_amount($parent_amount);
        return $parent === '' ? 0.0 : (float) $parent;
    }

    /**
     * @param int $product_id
     * @param int $variation_id
     */
    public static function get_product_member_discount($product_id, $variation_id = 0) {
        $variation_amount = '';
        if ((int) $variation_id > 0 && function_exists('get_post_meta')) {
            $variation_amount = get_post_meta((int) $variation_id, self::META_MEMBER_DISCOUNT, true);
        }
        $parent_amount = '';
        if ((int) $product_id > 0 && function_exists('get_post_meta')) {
            $parent_amount = get_post_meta((int) $product_id, self::META_MEMBER_DISCOUNT, true);
        }
        return self::resolve_member_discount_amount($variation_amount, $parent_amount);
    }

    /**
     * @param array<int, array{discount:float,qty:int,line_subtotal:float,is_membership?:bool}> $items
     */
    public static function discount_for_cart_items(array $items, $is_member) {
        if (!$is_member) {
            return 0.0;
        }
        $total = 0.0;
        foreach ($items as $item) {
            if (!empty($item['is_membership'])) {
                continue;
            }
            $amount = isset($item['discount']) ? (float) $item['discount'] : 0.0;
            $qty = isset($item['qty']) ? (int) $item['qty'] : 0;
            $line = isset($item['line_subtotal']) ? (float) $item['line_subtotal'] : 0.0;
            if ($amount <= 0 || $qty < 1 || $line <= 0) {
                continue;
            }
            $total += min($amount * $qty, $line);
        }
        return round($total, 2);
    }

    public function apply_cart_member_discount($cart) {
        if (!is_object($cart) || !method_exists($cart, 'get_cart') || !method_exists($cart, 'add_fee')) {
            return;
        }
        if (function_exists('is_admin') && is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }
        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $items = array();
        foreach ($cart->get_cart() as $cart_item) {
            $product = isset($cart_item['data']) ? $cart_item['data'] : null;
            $product_id = isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
            $variation_id = isset($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : 0;
            $parent_id = 0;
            $name = '';
            if (is_object($product)) {
                if (method_exists($product, 'get_parent_id')) {
                    $parent_id = (int) $product->get_parent_id();
                }
                if (method_exists($product, 'get_name')) {
                    $name = (string) $product->get_name();
                }
            }
            $lookup_id = $parent_id ? $parent_id : $product_id;
            $items[] = array(
                'discount'       => self::get_product_member_discount($lookup_id, $variation_id),
                'qty'            => isset($cart_item['quantity']) ? (int) $cart_item['quantity'] : 1,
                'line_subtotal'  => isset($cart_item['line_subtotal']) ? (float) $cart_item['line_subtotal'] : 0.0,
                'is_membership'  => self::classify_membership_product($product_id, $parent_id, $name) !== '',
            );
        }
        $amount = self::discount_for_cart_items($items, self::user_is_member($user_id));
        if ($amount <= 0) {
            return;
        }
        $cart->add_fee(__('PTSA Member discount', 'azure-plugin'), -1 * $amount, false);
    }

    public function render_simple_discount_field() {
        $this->render_product_discount_field(0, array(
            'wrapper_class' => 'show_if_simple show_if_external',
        ));
    }

    public function render_variable_parent_discount_field() {
        $this->render_product_discount_field(0, array(
            'wrapper_class' => 'show_if_variable',
            'description'   => __('This is the amount taken off the final price. Variations can set their own amount; otherwise this value is used.', 'azure-plugin'),
        ));
    }

    /**
     * @param int   $product_id 0 = current admin product
     * @param array $args
     */
    public function render_product_discount_field($product_id = 0, array $args = array()) {
        if (!function_exists('woocommerce_wp_text_input')) {
            return;
        }
        $product_id = (int) $product_id;
        if ($product_id < 1 && function_exists('get_the_ID')) {
            $product_id = (int) get_the_ID();
        }
        $value = ($product_id && function_exists('get_post_meta'))
            ? get_post_meta($product_id, self::META_MEMBER_DISCOUNT, true)
            : '';
        $symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';
        $field = array_merge(array(
            'id'            => self::META_MEMBER_DISCOUNT,
            'label'         => __('PTSA Membership discount', 'azure-plugin') . ' (' . $symbol . ')',
            'description'   => __('This is the amount taken off the final price.', 'azure-plugin'),
            'desc_tip'      => false,
            'type'          => 'text',
            'data_type'     => 'price',
            'value'         => $value,
            'wrapper_class' => '',
        ), $args);
        woocommerce_wp_text_input($field);
    }

    public function render_variation_discount_field($loop, $variation_data, $variation) {
        if (!function_exists('woocommerce_wp_text_input')) {
            return;
        }
        $variation_id = is_object($variation) ? (int) $variation->ID : 0;
        $value = ($variation_id && function_exists('get_post_meta'))
            ? get_post_meta($variation_id, self::META_MEMBER_DISCOUNT, true)
            : '';
        $symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';
        woocommerce_wp_text_input(array(
            'id'            => 'pta_member_discount_' . (int) $loop,
            'name'          => 'pta_member_discount[' . (int) $loop . ']',
            'label'         => __('PTSA Membership discount', 'azure-plugin') . ' (' . $symbol . ')',
            'description'   => __('This is the amount taken off the final price.', 'azure-plugin'),
            'desc_tip'      => false,
            'type'          => 'text',
            'data_type'     => 'price',
            'value'         => $value,
            'wrapper_class' => 'form-row form-row-first',
        ));
    }

    public function save_product_discount_field($product_id) {
        $product_id = (int) $product_id;
        if ($product_id < 1 || !function_exists('update_post_meta')) {
            return;
        }
        $raw = isset($_POST[self::META_MEMBER_DISCOUNT]) ? $_POST[self::META_MEMBER_DISCOUNT] : '';
        $amount = self::sanitize_member_discount_amount($raw);
        if ($amount === '') {
            delete_post_meta($product_id, self::META_MEMBER_DISCOUNT);
            return;
        }
        update_post_meta($product_id, self::META_MEMBER_DISCOUNT, $amount);
    }

    public function save_variation_discount_field($variation_id, $i) {
        $variation_id = (int) $variation_id;
        if ($variation_id < 1 || !function_exists('update_post_meta')) {
            return;
        }
        $raw = '';
        if (isset($_POST['pta_member_discount']) && is_array($_POST['pta_member_discount']) && isset($_POST['pta_member_discount'][$i])) {
            $raw = $_POST['pta_member_discount'][$i];
        }
        $amount = self::sanitize_member_discount_amount($raw);
        if ($amount === '') {
            delete_post_meta($variation_id, self::META_MEMBER_DISCOUNT);
            return;
        }
        update_post_meta($variation_id, self::META_MEMBER_DISCOUNT, $amount);
    }

    /**
     * Family and Individual must be tied to a WP account. Staff can
     * still check out as a guest.
     *
     * @param string[] $types family|individual|staff
     */
    public static function membership_types_require_account(array $types) {
        foreach ($types as $type) {
            $type = strtolower(trim((string) $type));
            if ($type === 'family' || $type === 'individual') {
                return true;
            }
        }
        return false;
    }

    public static function product_requires_membership_account($product_id, $parent_id = 0, $name = '') {
        $type = self::classify_membership_product($product_id, $parent_id, $name);
        return $type === 'family' || $type === 'individual';
    }

    /**
     * @param bool     $is_logged_in
     * @param string[] $types
     */
    public static function guest_membership_checkout_allowed($is_logged_in, array $types) {
        if ($is_logged_in) {
            return true;
        }
        return !self::membership_types_require_account($types);
    }

    /**
     * Store API creates the draft order, then the WP user. A guest who
     * is creating an account this request is not logged in yet and the
     * order still has customer_id 0 — that must still be allowed.
     *
     * @param string[] $types
     */
    public static function guest_membership_checkout_can_proceed($is_logged_in, array $types, $will_create_account, $order_customer_id = 0) {
        if (self::guest_membership_checkout_allowed($is_logged_in, $types)) {
            return true;
        }
        if ((int) $order_customer_id > 0) {
            return true;
        }
        return (bool) $will_create_account;
    }

    /**
     * Classic checkout posts createaccount; Blocks Store API posts
     * create_account. Registration-required (which we force for Family
     * / Individual carts) also means WooCommerce will create the user
     * after this validation hook.
     *
     * @param array             $data    Classic checkout posted data.
     * @param object|null       $request Store API request.
     */
    public static function request_will_create_account($data = array(), $request = null) {
        if (is_array($data) && (!empty($data['createaccount']) || !empty($data['create_account']))) {
            return true;
        }
        if (is_object($request)) {
            $flag = null;
            if (method_exists($request, 'get_param')) {
                $flag = $request->get_param('create_account');
            } elseif (isset($request['create_account'])) {
                $flag = $request['create_account'];
            }
            if (filter_var($flag, FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }
        if (function_exists('WC')) {
            $wc = WC();
            if (is_object($wc) && method_exists($wc, 'checkout')) {
                $checkout = $wc->checkout();
                if (is_object($checkout) && method_exists($checkout, 'is_registration_required') && $checkout->is_registration_required()) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param object|null $cart Woo cart
     * @return string[]
     */
    public static function cart_membership_types($cart = null) {
        $types = array();
        if ($cart === null && function_exists('WC')) {
            $wc = WC();
            $cart = (is_object($wc) && isset($wc->cart)) ? $wc->cart : null;
        }
        if (!is_object($cart) || !method_exists($cart, 'get_cart')) {
            return $types;
        }
        foreach ($cart->get_cart() as $cart_item) {
            $product = isset($cart_item['data']) ? $cart_item['data'] : null;
            $product_id = isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
            $parent_id = 0;
            $name = '';
            if (is_object($product)) {
                if (method_exists($product, 'get_parent_id')) {
                    $parent_id = (int) $product->get_parent_id();
                }
                if (method_exists($product, 'get_name')) {
                    $name = (string) $product->get_name();
                }
            }
            $type = self::classify_membership_product($product_id, $parent_id, $name);
            if ($type !== '') {
                $types[] = $type;
            }
        }
        return $types;
    }

    public static function cart_requires_membership_account($cart = null) {
        return self::membership_types_require_account(self::cart_membership_types($cart));
    }

    public function enable_registration_for_membership_cart($enabled) {
        return $enabled || self::cart_requires_membership_account();
    }

    public function require_registration_for_membership_cart($required) {
        return $required || self::cart_requires_membership_account();
    }

    public function check_create_account_for_membership_cart($checked) {
        return $checked || self::cart_requires_membership_account();
    }

    public function force_signup_option_for_membership_cart($value) {
        return self::cart_requires_membership_account() ? 'yes' : $value;
    }

    public function show_password_for_membership_cart($value) {
        return self::cart_requires_membership_account() ? 'no' : $value;
    }

    /**
     * Card checkout and checkout-page wallets send a password. If a
     * wallet still omits one, generate it so the parent account is created.
     */
    public function generate_password_when_express_omits_it($generate) {
        if ($generate || !self::cart_requires_membership_account()) {
            return $generate;
        }
        return self::posted_account_password() === '';
    }

    /**
     * Classic checkout posts account_password. Blocks Store API posts
     * customer_password on the REST body (not $_POST).
     *
     * @param object|null $request Store API request.
     */
    public static function posted_account_password($request = null) {
        $from_request = self::password_from_store_request($request);
        if ($from_request !== '') {
            return $from_request;
        }
        foreach (array('account_password', 'password', 'account-password', 'customer_password') as $key) {
            if (!empty($_POST[$key]) && is_string($_POST[$key])) {
                return (string) $_POST[$key];
            }
        }
        return '';
    }

    /**
     * @param object|null $request
     */
    public static function password_from_store_request($request) {
        if (!is_object($request)) {
            return '';
        }
        $value = null;
        if (method_exists($request, 'get_param')) {
            $value = $request->get_param('customer_password');
        } elseif (isset($request['customer_password'])) {
            $value = $request['customer_password'];
        }
        return is_string($value) ? $value : '';
    }

    /**
     * Checkout wallets stay disabled until the shopper has typed an
     * email and a password for the account that membership requires.
     */
    public static function express_account_fields_ready($email, $password) {
        $email = strtolower(trim((string) $email));
        $password = (string) $password;
        if ($email === '' || $password === '') {
            return false;
        }
        if (function_exists('is_email')) {
            return (bool) is_email($email);
        }
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Wallets on the product or cart skip the create-account fields.
     * Checkout is where the password is collected, so express pay is
     * allowed there (and always allowed once signed in).
     *
     * @param string $context product|cart|checkout
     */
    public static function guest_may_use_express_pay($is_logged_in, $requires_account, $context) {
        if (!$requires_account || $is_logged_in) {
            return true;
        }
        return $context === 'checkout';
    }

    public function generate_username_for_membership_cart($value) {
        return self::cart_requires_membership_account() ? 'yes' : $value;
    }

    public function parent_role_for_membership_customer($data) {
        if (!is_array($data) || !self::cart_requires_membership_account()) {
            return $data;
        }
        $data['role'] = 'parent';
        return $data;
    }

    public function ensure_parent_role_for_membership_customer($customer_id) {
        $customer_id = (int) $customer_id;
        if ($customer_id < 1 || !self::cart_requires_membership_account() || !function_exists('get_userdata')) {
            return;
        }
        $user = get_userdata($customer_id);
        if (!$user) {
            return;
        }
        $protected = array('administrator', 'shop_manager', 'editor', 'school_staff', 'pta_manager', 'finance');
        foreach ($protected as $role) {
            if (in_array($role, (array) $user->roles, true)) {
                return;
            }
        }
        if (!in_array('parent', (array) $user->roles, true) && method_exists($user, 'set_role')) {
            $user->set_role('parent');
        }
    }

    public function validate_membership_checkout_account($data, $errors) {
        if (self::guest_membership_checkout_can_proceed(
            function_exists('is_user_logged_in') && is_user_logged_in(),
            self::cart_membership_types(),
            self::request_will_create_account(is_array($data) ? $data : array(), null),
            0
        )) {
            return;
        }
        if (is_object($errors) && method_exists($errors, 'add')) {
            $errors->add('pta_membership_account_required', self::account_required_message());
        }
    }

    /**
     * Fires while the draft order is still a guest. Do not require
     * customer_id yet — WooCommerce creates the user in process_customer
     * immediately after this hook.
     */
    public function validate_store_api_membership_account($order, $request) {
        $customer_id = (is_object($order) && method_exists($order, 'get_customer_id'))
            ? (int) $order->get_customer_id()
            : 0;
        if (self::guest_membership_checkout_can_proceed(
            function_exists('is_user_logged_in') && is_user_logged_in(),
            self::cart_membership_types(),
            self::request_will_create_account(array(), $request),
            $customer_id
        )) {
            return;
        }
        self::throw_membership_account_required();
    }

    /**
     * Express pay (Apple Pay / Google Pay) uses the same Store API
     * POST as Place order. Force create_account so a wallet that
     * omits the Blocks checkbox still creates the parent user.
     *
     * @param mixed            $result
     * @param object           $server
     * @param object           $request
     * @return mixed
     */
    public function force_store_api_membership_create_account($result, $server, $request) {
        unset($server);
        if (!is_object($request) || !method_exists($request, 'get_route')) {
            return $result;
        }
        $route = (string) $request->get_route();
        if (stripos($route, '/wc/store') === false || stripos($route, 'checkout') === false) {
            return $result;
        }
        $method = method_exists($request, 'get_method') ? strtoupper((string) $request->get_method()) : 'POST';
        if ($method !== 'POST' && $method !== 'PUT') {
            return $result;
        }
        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            return $result;
        }
        if (!self::cart_requires_membership_account()) {
            return $result;
        }
        if (method_exists($request, 'set_param')) {
            $request->set_param('create_account', true);
        }
        return $result;
    }

    /**
     * After process_customer: the membership order must be attached to
     * a WP user before payment runs.
     */
    public function require_membership_order_customer($order) {
        $customer_id = (is_object($order) && method_exists($order, 'get_customer_id'))
            ? (int) $order->get_customer_id()
            : 0;
        if (self::guest_membership_checkout_can_proceed(
            function_exists('is_user_logged_in') && is_user_logged_in(),
            self::cart_membership_types(),
            false,
            $customer_id
        )) {
            return;
        }
        self::throw_membership_account_required();
    }

    private static function throw_membership_account_required() {
        $message = self::account_required_message();
        if (class_exists('\Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                'pta_membership_account_required',
                $message,
                403
            );
        }
        throw new Exception($message);
    }

    public function allow_express_pay_when_account_optional($allowed) {
        $requires = self::cart_requires_membership_account()
            || self::current_product_requires_membership_account();
        $logged_in = function_exists('is_user_logged_in') && is_user_logged_in();
        if (self::guest_may_use_express_pay($logged_in, $requires, self::express_pay_context())) {
            return $allowed;
        }
        return false;
    }

    private static function express_pay_context() {
        if (function_exists('is_checkout') && is_checkout() && !(function_exists('is_order_received_page') && is_order_received_page())) {
            return 'checkout';
        }
        if (function_exists('is_cart') && is_cart()) {
            return 'cart';
        }
        return 'product';
    }

    public function render_product_account_notice() {
        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            return;
        }
        if (!self::current_product_requires_membership_account()) {
            return;
        }
        echo self::account_notice_html('product');
    }

    public function render_cart_account_notice() {
        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            return;
        }
        if (!self::cart_requires_membership_account()) {
            return;
        }
        echo self::account_notice_html('cart');
    }

    public function render_checkout_account_notice() {
        $this->print_checkout_account_notice();
    }

    public function prepend_blocks_checkout_notice($content, $block = null) {
        $notice = $this->print_checkout_account_notice(true);
        return $notice . $content;
    }

    private function print_checkout_account_notice($return = false) {
        static $printed = false;
        if ($printed) {
            return '';
        }
        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            return '';
        }
        if (!self::cart_requires_membership_account()) {
            return '';
        }
        $printed = true;
        $html = self::account_notice_html('checkout');
        if ($return) {
            return $html;
        }
        echo $html;
        return '';
    }

    private static function current_product_requires_membership_account() {
        global $product;
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return false;
        }
        $parent_id = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
        $name = method_exists($product, 'get_name') ? (string) $product->get_name() : '';
        return self::product_requires_membership_account((int) $product->get_id(), $parent_id, $name);
    }

    public static function account_required_message() {
        return __('Please create an account or log in to buy a PTSA membership. Membership has to be tied to an account so we can keep your family on the roster and apply member pricing.', 'azure-plugin');
    }

    public static function account_notice_html($context = 'checkout') {
        $login = self::membership_checkout_login_url();
        if ($context === 'product') {
            $text = __('You will create a free PTSA account at checkout — required for membership so we can apply member benefits to your family.', 'azure-plugin');
        } elseif ($context === 'cart') {
            $text = __('PTSA membership requires an account. Create one at checkout (it only takes a minute), or log in if you already have one.', 'azure-plugin');
        } else {
            $text = __('Enter your email and password below to create a free PTSA account (or log in if you already have one). Then you can pay with a card, Apple Pay, or Google Pay. Membership has to be tied to an account so we can keep your family on the roster.', 'azure-plugin');
        }
        $login_html = '';
        if ($login !== '') {
            $login_html = ' <a href="' . esc_url($login) . '">' . esc_html__('Log in', 'azure-plugin') . '</a>';
        }
        return '<div class="woocommerce-info pta-membership-account-notice">'
            . esc_html($text)
            . $login_html
            . '</div>';
    }

    public static function membership_checkout_login_url() {
        $redirect = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '';
        if ($redirect === '' && function_exists('home_url')) {
            $redirect = home_url('/checkout/');
        }
        if (function_exists('wc_get_page_permalink')) {
            $account = wc_get_page_permalink('myaccount');
            if ($account) {
                return $redirect !== '' ? add_query_arg('redirect', $redirect, $account) : $account;
            }
        }
        return function_exists('wp_login_url') ? wp_login_url($redirect) : $redirect;
    }

    /**
     * One CSV row per paid membership line item this school year —
     * Family, Individual, and Staff, including guest checkouts.
     *
     * @return array<int, array{name:string,email:string,parent_2_name:string,parent_2_email:string,membership:string,paid_at:string,children:string,role_types:string[]}>
     */
    public static function build_sold_membership_rows() {
        if (!function_exists('wc_get_orders')) {
            return array();
        }
        $range = self::school_year_range();
        $orders = wc_get_orders(array(
            'status'       => array('processing', 'completed'),
            'type'         => 'shop_order',
            'date_created' => $range['from'] . '...' . $range['to'],
            'limit'        => -1,
            'return'       => 'objects',
        ));
        return self::sold_membership_rows_from_orders($orders);
    }

    /**
     * @param object[] $orders
     * @return array<int, array{name:string,email:string,parent_2_name:string,parent_2_email:string,membership:string,paid_at:string,children:string,role_types:string[]}>
     */
    public static function sold_membership_rows_from_orders($orders) {
        $rows = array();
        foreach ((array) $orders as $order) {
            if (!is_object($order) || !method_exists($order, 'get_items')) {
                continue;
            }
            foreach ($order->get_items() as $item) {
                $type = self::item_membership_type($item);
                if ($type === '') {
                    continue;
                }
                $rows[] = self::export_row_from_order_item($order, $item, $type);
            }
        }
        usort($rows, function ($a, $b) {
            $cmp = strcmp((string) $a['paid_at'], (string) $b['paid_at']);
            return $cmp !== 0 ? $cmp : strcasecmp((string) $a['name'], (string) $b['name']);
        });
        return $rows;
    }

    public static function flush_member_map() {
        update_option('azure_membership_map_ver', (int) get_option('azure_membership_map_ver', 1) + 1, false);
    }

    /**
     * Drop the cached map when a membership product order changes status.
     */
    public function maybe_invalidate_map($order_id, $old_status, $new_status, $order) {
        if (!is_object($order) || !method_exists($order, 'get_items')) {
            return;
        }
        $watched = array_flip(array_merge(self::get_family_product_ids(), self::get_individual_product_ids()));
        if (empty($watched)) {
            return;
        }
        foreach ($order->get_items() as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product_id')) {
                continue;
            }
            $pid = (int) $item->get_product_id();
            $vid = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
            if (isset($watched[$pid]) || ($vid && isset($watched[$vid]))) {
                self::flush_member_map();
                return;
            }
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            if ($product && method_exists($product, 'get_parent_id')) {
                $parent = (int) $product->get_parent_id();
                if ($parent && isset($watched[$parent])) {
                    self::flush_member_map();
                    return;
                }
            }
        }
    }

    private static function build_member_map(array $range) {
        $map = array();
        if (!function_exists('wc_get_orders')) {
            return $map;
        }

        $orders = wc_get_orders(array(
            'status'       => array('processing', 'completed'),
            'type'         => 'shop_order',
            'date_created' => $range['from'] . '...' . $range['to'],
            'limit'        => -1,
            'return'       => 'objects',
        ));

        $identities = self::parent_match_identities();

        foreach ($orders as $order) {
            $type = self::order_membership_type($order);
            if (!$type) {
                continue;
            }
            $paid_at = self::order_paid_at($order);
            $entry = array(
                'type'     => $type,
                'order_id' => method_exists($order, 'get_id') ? (int) $order->get_id() : 0,
                'paid_at'  => $paid_at,
            );

            $user_id = method_exists($order, 'get_user_id') ? (int) $order->get_user_id() : 0;
            if ($user_id) {
                self::apply_member_entry($map, $user_id, $entry);
                if ($type === 'family') {
                    $other = self::co_parent_user_id($user_id);
                    if ($other) {
                        self::apply_member_entry($map, $other, $entry);
                    }
                    foreach (self::guest_order_parties($order, 'family') as $party) {
                        if ($party['slot'] !== 'parent_2') {
                            continue;
                        }
                        $hit = self::match_checkout_party($party, $identities);
                        if ($hit['status'] === 'confident' && !empty($hit['user_id']) && (int) $hit['user_id'] !== $user_id) {
                            self::apply_member_entry($map, (int) $hit['user_id'], $entry);
                        }
                    }
                }
                continue;
            }

            if ($type === 'staff') {
                continue;
            }

            $review = self::review_one_guest_order($order, $type, $identities);
            foreach ($review['matched'] as $hit) {
                self::apply_member_entry($map, (int) $hit['user_id'], $entry);
            }
            $p1 = isset($review['parent_1']) ? $review['parent_1'] : null;
            if ($p1 && $p1['status'] === 'confident' && !empty($p1['user_id'])) {
                self::maybe_link_guest_order($order, (int) $p1['user_id']);
            }
        }

        return $map;
    }

    /**
     * Guest Family/Individual memberships this school year: who we can
     * attach to an existing parent account, and who still needs one.
     *
     * @param object[]|null $orders
     * @param array|null    $identities
     * @return array{matched:array,unmatched:array,uncertain:array}
     */
    public static function review_guest_memberships($orders = null, $identities = null) {
        $out = array(
            'matched'    => array(),
            'unmatched'  => array(),
            'uncertain'  => array(),
        );
        if ($orders === null) {
            if (!function_exists('wc_get_orders')) {
                return $out;
            }
            $range = self::school_year_range();
            $orders = wc_get_orders(array(
                'status'       => array('processing', 'completed'),
                'type'         => 'shop_order',
                'date_created' => $range['from'] . '...' . $range['to'],
                'limit'        => -1,
                'return'       => 'objects',
            ));
        }
        if ($identities === null) {
            $identities = self::parent_match_identities();
        }

        foreach ((array) $orders as $order) {
            if (!is_object($order) || !method_exists($order, 'get_user_id')) {
                continue;
            }
            if ((int) $order->get_user_id() !== 0) {
                continue;
            }
            $type = self::order_membership_type($order);
            if ($type === '' || $type === 'staff') {
                continue;
            }
            $review = self::review_one_guest_order($order, $type, $identities);
            foreach (array('matched', 'unmatched', 'uncertain') as $bucket) {
                foreach ($review[$bucket] as $row) {
                    $out[$bucket][] = $row;
                }
            }
        }
        return $out;
    }

    /**
     * @param object $order
     * @param string $type family|individual
     * @param array  $identities
     * @return array{matched:array,unmatched:array,uncertain:array,parent_1:?array}
     */
    public static function review_one_guest_order($order, $type, array $identities) {
        $out = array(
            'matched'   => array(),
            'unmatched' => array(),
            'uncertain' => array(),
            'parent_1'  => null,
        );
        $parties = self::guest_order_parties($order, $type);
        foreach ($parties as $party) {
            $hit = self::match_checkout_party($party, $identities);
            $row = array_merge($party, $hit);
            if ($party['slot'] === 'parent_1') {
                $out['parent_1'] = $row;
            }
            if ($hit['status'] === 'confident' && !empty($hit['user_id'])) {
                $out['matched'][] = $row;
                foreach ((array) (isset($hit['extra_user_ids']) ? $hit['extra_user_ids'] : array()) as $extra) {
                    $out['matched'][] = array_merge($row, array(
                        'user_id'    => (int) $extra['user_id'],
                        'user_email' => (string) $extra['user_email'],
                        'reason'     => 'name_same_person_accounts',
                    ));
                }
            } elseif ($hit['status'] === 'uncertain') {
                $out['uncertain'][] = $row;
            } else {
                $out['unmatched'][] = $row;
            }
        }
        return $out;
    }

    /**
     * Parent 1 (billing) and, for Family, Parent 2 from the line item.
     *
     * @return array<int, array{slot:string,email:string,first:string,last:string,name:string,order_id:int,type:string,paid_at:string}>
     */
    public static function guest_order_parties($order, $type) {
        $order_id = method_exists($order, 'get_id') ? (int) $order->get_id() : 0;
        $paid_at = self::order_paid_at($order);
        $first = method_exists($order, 'get_billing_first_name') ? trim((string) $order->get_billing_first_name()) : '';
        $last = method_exists($order, 'get_billing_last_name') ? trim((string) $order->get_billing_last_name()) : '';
        $email = method_exists($order, 'get_billing_email') ? trim((string) $order->get_billing_email()) : '';
        $name = trim($first . ' ' . $last);
        $parties = array(
            array(
                'slot'     => 'parent_1',
                'email'    => $email,
                'first'    => $first,
                'last'     => $last,
                'name'     => $name,
                'order_id' => $order_id,
                'type'     => $type,
                'paid_at'  => $paid_at,
            ),
        );
        if ($type !== 'family' || !method_exists($order, 'get_items')) {
            return $parties;
        }
        foreach ($order->get_items() as $item) {
            if (self::item_membership_type($item) !== 'family') {
                continue;
            }
            $p2 = self::parent_2_from_order_item($order, $item, 0);
            if ($p2['name'] === '' && $p2['email'] === '') {
                break;
            }
            $split = self::split_person_name($p2['name']);
            $parties[] = array(
                'slot'     => 'parent_2',
                'email'    => trim((string) $p2['email']),
                'first'    => $split['first'],
                'last'     => $split['last'],
                'name'     => trim((string) $p2['name']),
                'order_id' => $order_id,
                'type'     => $type,
                'paid_at'  => $paid_at,
            );
            break;
        }
        return $parties;
    }

    /**
     * Match one checkout adult against known parent identities.
     *
     * Email is preferred. Stripe/checkout email may differ from the
     * WordPress email, so a close first+last name is enough when it
     * points at exactly one parent. An email hit whose first/last name
     * clearly disagrees is left uncertain.
     *
     * @param array $party
     * @param array $identities
     * @return array{status:string,user_id:int,user_email:string,reason:string}
     */
    public static function match_checkout_party(array $party, array $identities) {
        $empty = array(
            'status'         => 'none',
            'user_id'        => 0,
            'user_email'     => '',
            'reason'         => 'no_match',
            'extra_user_ids' => array(),
        );
        $email_hits = array();
        $name_hits = array();
        $conflicts = array();

        foreach ($identities as $identity) {
            $grade = self::identity_match_status($party, $identity);
            if ($grade === 'none') {
                continue;
            }
            $id = (int) $identity['user_id'];
            if ($grade === 'uncertain') {
                $conflicts[$id] = $identity;
                continue;
            }
            if (!empty($grade['email'])) {
                $email_hits[$id] = $identity;
            }
            if (!empty($grade['name'])) {
                $name_hits[$id] = $identity;
            }
        }

        if (count($email_hits) === 1) {
            $id = (int) array_key_first($email_hits);
            $identity = $email_hits[$id];
            return array(
                'status'         => 'confident',
                'user_id'        => $id,
                'user_email'     => (string) $identity['email'],
                'reason'         => isset($name_hits[$id]) ? 'email_and_name' : 'email',
                'extra_user_ids' => array(),
            );
        }
        if (count($email_hits) > 1) {
            return array(
                'status'         => 'uncertain',
                'user_id'        => 0,
                'user_email'     => '',
                'reason'         => 'multiple_email_matches',
                'extra_user_ids' => array(),
            );
        }
        if (!empty($conflicts)) {
            return array(
                'status'         => 'uncertain',
                'user_id'        => 0,
                'user_email'     => '',
                'reason'         => 'email_name_conflict',
                'extra_user_ids' => array(),
            );
        }
        if (count($name_hits) === 1) {
            $id = (int) array_key_first($name_hits);
            $identity = $name_hits[$id];
            return array(
                'status'         => 'confident',
                'user_id'        => $id,
                'user_email'     => (string) $identity['email'],
                'reason'         => 'name',
                'extra_user_ids' => array(),
            );
        }
        if (count($name_hits) > 1) {
            if (self::identities_are_same_person($name_hits)) {
                $chosen = self::prefer_identity_for_link($name_hits);
                $chosen_id = (int) $chosen['user_id'];
                $extras = array();
                foreach ($name_hits as $id => $identity) {
                    if ((int) $id !== $chosen_id) {
                        $extras[] = array(
                            'user_id'    => (int) $identity['user_id'],
                            'user_email' => (string) $identity['email'],
                        );
                    }
                }
                return array(
                    'status'         => 'confident',
                    'user_id'        => $chosen_id,
                    'user_email'     => (string) $chosen['email'],
                    'reason'         => 'name_same_person_accounts',
                    'extra_user_ids' => $extras,
                );
            }
            return array(
                'status'         => 'uncertain',
                'user_id'        => 0,
                'user_email'     => '',
                'reason'         => 'multiple_name_matches',
                'extra_user_ids' => array(),
            );
        }
        return $empty;
    }

    /**
     * @return array{email?:bool,name?:bool}|'uncertain'|'none'
     */
    public static function identity_match_status(array $party, array $identity) {
        $email_ok = self::emails_are_same(
            isset($party['email']) ? $party['email'] : '',
            isset($identity['email']) ? $identity['email'] : ''
        );
        $name_ok = self::names_are_close(
            isset($party['first']) ? $party['first'] : '',
            isset($party['last']) ? $party['last'] : '',
            isset($identity['first']) ? $identity['first'] : '',
            isset($identity['last']) ? $identity['last'] : '',
            isset($identity['display']) ? $identity['display'] : ''
        );
        $last_conflict = self::last_names_conflict(
            isset($party['last']) ? $party['last'] : '',
            isset($identity['last']) ? $identity['last'] : '',
            isset($identity['display']) ? $identity['display'] : ''
        );

        // Email is strong. A different first name (Divya vs Vineela) is
        // not enough to reject when the last name still lines up.
        if ($email_ok && $last_conflict) {
            return 'uncertain';
        }
        $out = array();
        if ($email_ok) {
            $out['email'] = true;
        }
        if ($name_ok) {
            $out['name'] = true;
        }
        return $out === array() ? 'none' : $out;
    }

    public static function emails_are_same($a, $b) {
        $a = self::normalize_email($a);
        $b = self::normalize_email($b);
        return $a !== '' && $a === $b;
    }

    public static function normalize_email($email) {
        return strtolower(trim((string) $email));
    }

    public static function normalize_name_part($value) {
        $value = strtolower(trim((string) $value));
        if (function_exists('remove_accents')) {
            $value = remove_accents($value);
        }
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return trim((string) $value);
    }

    /**
     * @return array{first:string,last:string}
     */
    public static function split_person_name($name) {
        $name = trim((string) $name);
        if ($name === '') {
            return array('first' => '', 'last' => '');
        }
        $parts = preg_split('/\s+/', $name);
        if (count($parts) === 1) {
            return array('first' => $parts[0], 'last' => '');
        }
        $last = array_pop($parts);
        return array(
            'first' => implode(' ', $parts),
            'last'  => $last,
        );
    }

    public static function names_are_close($a_first, $a_last, $b_first, $b_last, $b_display = '') {
        $a_first = self::normalize_name_part($a_first);
        $a_last = self::normalize_name_part($a_last);
        $b_first = self::normalize_name_part($b_first);
        $b_last = self::normalize_name_part($b_last);
        if ($b_first === '' && $b_last === '' && trim((string) $b_display) !== '') {
            $split = self::split_person_name($b_display);
            $b_first = self::normalize_name_part($split['first']);
            $b_last = self::normalize_name_part($split['last']);
        }
        if ($a_last === '' || $b_last === '') {
            return false;
        }
        if (!self::name_parts_close($a_last, $b_last)) {
            return false;
        }
        if ($a_first === '' || $b_first === '') {
            return true;
        }
        return self::name_parts_close($a_first, $b_first);
    }

    public static function last_names_conflict($a_last, $b_last, $b_display = '') {
        $a_last = self::normalize_name_part($a_last);
        $b_last = self::normalize_name_part($b_last);
        if ($b_last === '' && trim((string) $b_display) !== '') {
            $split = self::split_person_name($b_display);
            $b_last = self::normalize_name_part($split['last']);
        }
        if ($a_last === '' || $b_last === '') {
            return false;
        }
        return !self::name_parts_close($a_last, $b_last);
    }

    /**
     * Common first-name nicknames that are not string prefixes
     * (Nick / Nicholas, Becky / Rebecca).
     */
    public static function name_aliases_close($a, $b) {
        $a = self::normalize_name_part($a);
        $b = self::normalize_name_part($b);
        if ($a === '' || $b === '') {
            return false;
        }
        $map = array(
            'nick'     => array('nicholas', 'nicolas'),
            'nicholas' => array('nick'),
            'nicolas'  => array('nick', 'nicholas'),
            'becky'    => array('rebecca', 'rebekah'),
            'rebecca'  => array('becky'),
            'rebekah'  => array('becky', 'rebecca'),
        );
        return (isset($map[$a]) && in_array($b, $map[$a], true))
            || (isset($map[$b]) && in_array($a, $map[$b], true));
    }

    /**
     * True when every identity is the same first+last (duplicate accounts).
     *
     * @param array<int, array> $identities
     */
    public static function identities_are_same_person(array $identities) {
        $list = array_values($identities);
        if (count($list) < 2) {
            return false;
        }
        $has_sso = false;
        foreach ($list as $identity) {
            $email = self::normalize_email(isset($identity['email']) ? $identity['email'] : '');
            if (substr($email, -strlen('@wilderptsa.net')) === '@wilderptsa.net') {
                $has_sso = true;
                break;
            }
        }
        if (!$has_sso) {
            return false;
        }
        $first = $list[0];
        for ($i = 1; $i < count($list); $i++) {
            $other = $list[$i];
            if (!self::names_are_close(
                isset($first['first']) ? $first['first'] : '',
                isset($first['last']) ? $first['last'] : '',
                isset($other['first']) ? $other['first'] : '',
                isset($other['last']) ? $other['last'] : '',
                isset($other['display']) ? $other['display'] : ''
            )) {
                return false;
            }
        }
        return true;
    }

    /**
     * Prefer a personal email over an @wilderptsa.net SSO mailbox
     * when the same person has more than one account.
     *
     * @param array<int, array> $identities
     * @return array
     */
    public static function prefer_identity_for_link(array $identities) {
        $list = array_values($identities);
        foreach ($list as $identity) {
            $email = self::normalize_email(isset($identity['email']) ? $identity['email'] : '');
            if ($email !== '' && substr($email, -strlen('@wilderptsa.net')) !== '@wilderptsa.net') {
                return $identity;
            }
        }
        return $list[0];
    }

    public static function names_conflict($a_first, $a_last, $b_first, $b_last, $b_display = '') {
        $a_first = self::normalize_name_part($a_first);
        $a_last = self::normalize_name_part($a_last);
        $b_first = self::normalize_name_part($b_first);
        $b_last = self::normalize_name_part($b_last);
        if ($b_first === '' && $b_last === '' && trim((string) $b_display) !== '') {
            $split = self::split_person_name($b_display);
            $b_first = self::normalize_name_part($split['first']);
            $b_last = self::normalize_name_part($split['last']);
        }
        if ($a_last === '' || $b_last === '') {
            return false;
        }
        if (self::names_are_close($a_first, $a_last, $b_first, $b_last, '')) {
            return false;
        }
        return true;
    }

    public static function name_parts_close($a, $b) {
        $a = self::normalize_name_part($a);
        $b = self::normalize_name_part($b);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $a_compact = str_replace(' ', '', $a);
        $b_compact = str_replace(' ', '', $b);
        if ($a_compact === $b_compact) {
            return true;
        }
        if (strlen($a) >= 3 && strlen($b) >= 3 && (strpos($a, $b) === 0 || strpos($b, $a) === 0)) {
            return true;
        }
        if (self::name_aliases_close($a, $b)) {
            return true;
        }
        $a_tokens = preg_split('/\s+/', $a);
        $b_tokens = preg_split('/\s+/', $b);
        foreach ($a_tokens as $a_tok) {
            foreach ($b_tokens as $b_tok) {
                if (strlen($a_tok) < 3 || strlen($b_tok) < 3) {
                    continue;
                }
                if ($a_tok === $b_tok || strpos($a_tok, $b_tok) === 0 || strpos($b_tok, $a_tok) === 0) {
                    return true;
                }
            }
        }
        if (function_exists('similar_text')) {
            similar_text($a, $b, $pct);
            return $pct >= 88.0;
        }
        return false;
    }

    /**
     * Parent (and parent-like) accounts we can attach a guest sale to.
     *
     * @return array<int, array{user_id:int,email:string,first:string,last:string,display:string}>
     */
    public static function parent_match_identities() {
        if (!function_exists('get_users')) {
            return array();
        }
        $users = get_users(array(
            'role__in' => array('parent', 'alumni', 'customer'),
            'fields'   => array('ID', 'user_email', 'display_name'),
            'number'   => -1,
        ));
        $out = array();
        foreach ((array) $users as $user) {
            $uid = (int) $user->ID;
            $first = function_exists('get_user_meta') ? trim((string) get_user_meta($uid, 'first_name', true)) : '';
            $last = function_exists('get_user_meta') ? trim((string) get_user_meta($uid, 'last_name', true)) : '';
            $out[] = array(
                'user_id' => $uid,
                'email'   => (string) $user->user_email,
                'first'   => $first,
                'last'    => $last,
                'display' => (string) $user->display_name,
            );
        }
        return $out;
    }

    private static function order_paid_at($order) {
        if (method_exists($order, 'get_date_paid') && $order->get_date_paid()) {
            $paid = $order->get_date_paid();
            if (is_object($paid) && method_exists($paid, 'date')) {
                return $paid->date('Y-m-d H:i:s');
            }
            return (string) $paid;
        }
        if (method_exists($order, 'get_date_created') && $order->get_date_created()) {
            $created = $order->get_date_created();
            if (is_object($created) && method_exists($created, 'date')) {
                return $created->date('Y-m-d H:i:s');
            }
            return (string) $created;
        }
        return '';
    }

    /**
     * Point a guest order at the matched Parent 1 account so it shows
     * on My Account. No-op when Woo cannot save, or the order already
     * has a customer.
     */
    public static function maybe_link_guest_order($order, $user_id) {
        $user_id = (int) $user_id;
        if ($user_id < 1 || !is_object($order)) {
            return false;
        }
        if (method_exists($order, 'get_user_id') && (int) $order->get_user_id() > 0) {
            return false;
        }
        if (!method_exists($order, 'set_customer_id') || !method_exists($order, 'save')) {
            return false;
        }
        $order->set_customer_id($user_id);
        $order->save();
        return true;
    }

    /**
     * Welcome email for a parent we are about to create from a guest
     * membership checkout. Preview-safe: does not create a user.
     *
     * @param array $vars
     * @return array{subject:string,html:string,text:string}
     */
    public static function build_guest_account_email(array $vars) {
        $site = isset($vars['site_name']) ? (string) $vars['site_name'] : 'Wilder PTSA';
        $greeting_name = isset($vars['first_name']) && trim((string) $vars['first_name']) !== ''
            ? trim((string) $vars['first_name'])
            : 'there';
        $username = isset($vars['username']) ? (string) $vars['username'] : '';
        $password = isset($vars['password']) ? (string) $vars['password'] : '';
        $login_url = isset($vars['login_url']) ? (string) $vars['login_url'] : 'https://wilderptsa.net/my-account/';
        $support = isset($vars['support_email']) ? (string) $vars['support_email'] : 'info@wilderptsa.net';
        $preview = !empty($vars['preview']);

        $subject = $preview
            ? sprintf('[PREVIEW] Your %s account is ready', $site)
            : sprintf('Your %s account is ready', $site);

        $preview_banner = $preview
            ? '<p style="margin:0 0 16px 0;padding:10px 12px;background:#fff4ce;border:1px solid #dba617;border-radius:4px;font-size:13px;color:#3c434a;"><strong>Preview only.</strong> This is not a live account. No parent user was created. The username and password below are samples.</p>'
            : '';

        $site_h = htmlspecialchars($site, ENT_QUOTES, 'UTF-8');
        $greet_h = htmlspecialchars($greeting_name, ENT_QUOTES, 'UTF-8');
        $user_h = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $pass_h = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');
        $url_h = htmlspecialchars($login_url, ENT_QUOTES, 'UTF-8');
        $support_h = htmlspecialchars($support, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f6f6f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f6f6;padding:24px 0;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.05);overflow:hidden;">
        <tr><td style="padding:32px 32px 16px 32px;">
          {$preview_banner}
          <h1 style="margin:0 0 12px 0;font-size:22px;color:#1d2327;">Your {$site_h} account is ready</h1>
          <p style="margin:0 0 16px 0;font-size:15px;line-height:1.5;color:#3c434a;">Hi {$greet_h},</p>
          <p style="margin:0 0 16px 0;font-size:15px;line-height:1.5;color:#3c434a;">
            Thank you for joining the {$site_h}. We created an account from your membership checkout so you can sign in and use your member benefits.
          </p>
          <table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 20px 0;border-collapse:collapse;">
            <tr>
              <td style="padding:6px 16px 6px 0;font-size:15px;color:#646970;">Username</td>
              <td style="padding:6px 0;font-size:15px;color:#1d2327;"><strong>{$user_h}</strong></td>
            </tr>
            <tr>
              <td style="padding:6px 16px 6px 0;font-size:15px;color:#646970;">Password</td>
              <td style="padding:6px 0;font-size:15px;color:#1d2327;"><code style="display:inline-block;padding:6px 10px;background:#f1f3f5;border:1px solid #d1d5db;border-radius:4px;font-family:Consolas,Menlo,'SF Mono',monospace;font-size:16px;letter-spacing:0.4px;">{$pass_h}</code></td>
            </tr>
          </table>
          <p style="text-align:center;margin:24px 0;">
            <a href="{$url_h}" style="display:inline-block;padding:14px 28px;background:#0078d4;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;font-size:15px;">Sign in</a>
          </p>
          <p style="margin:0 0 12px 0;font-size:15px;line-height:1.5;color:#3c434a;">With this account you can:</p>
          <ol style="margin:0 0 16px 24px;padding:0;font-size:15px;line-height:1.6;color:#3c434a;">
            <li>Save your family profile for faster checkout next time</li>
            <li>Get member pricing in the store</li>
            <li>Join the member parent directory (optional — you choose whether to be listed)</li>
          </ol>
          <p style="margin:0 0 8px 0;font-size:15px;line-height:1.5;color:#3c434a;">
            Please change this password after you sign in. If the button does not work, open:<br>
            <span style="word-break:break-all;color:#0073aa;">{$url_h}</span>
          </p>
        </td></tr>
        <tr><td style="padding:16px 32px 32px 32px;border-top:1px solid #e0e0e0;">
          <p style="margin:0;font-size:12px;color:#646970;line-height:1.5;">
            Questions? Email <a href="mailto:{$support_h}" style="color:#0073aa;">{$support_h}</a>.
            <br>You are receiving this because you purchased a {$site_h} membership.
          </p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;

        $text = ($preview ? "[PREVIEW — not a live account]\n\n" : '')
            . "Your {$site} account is ready\n\n"
            . "Hi {$greeting_name},\n\n"
            . "Thank you for joining the {$site}. We created an account from your membership checkout so you can sign in and use your member benefits.\n\n"
            . "Username: {$username}\n"
            . "Password: {$password}\n"
            . "Sign in: {$login_url}\n\n"
            . "With this account you can:\n"
            . "1. Save your family profile for faster checkout next time\n"
            . "2. Get member pricing in the store\n"
            . "3. Join the member parent directory (optional — you choose whether to be listed)\n\n"
            . "Please change this password after you sign in.\n\n"
            . "Questions? Email {$support}.\n";

        return array(
            'subject' => $subject,
            'html'    => $html,
            'text'    => $text,
        );
    }

    /**
     * Sample payload for the operator preview. Does not create a user.
     *
     * @return array
     */
    public static function guest_account_preview_sample() {
        $password = function_exists('wp_generate_password')
            ? wp_generate_password(14, true, false)
            : 'Test-' . substr(md5(uniqid('', true)), 0, 10);
        return array(
            'site_name'     => function_exists('get_bloginfo') ? get_bloginfo('name') : 'Wilder PTSA',
            'first_name'    => 'Robert',
            'username'      => 'rjbaummer@gmail.com',
            'password'      => $password,
            'login_url'     => 'https://wilderptsa.net/my-account/',
            'support_email' => 'info@wilderptsa.net',
            'preview'       => true,
        );
    }

    /**
     * Send the preview welcome to an operator inbox. No WordPress user
     * is created or updated.
     *
     * @param string $to
     * @param array|null $vars
     * @return array{ok:bool,to:string,subject:string,username:string,password:string,error:string}
     */
    public static function send_guest_account_preview($to, $vars = null) {
        $to = function_exists('sanitize_email') ? sanitize_email($to) : strtolower(trim((string) $to));
        $out = array(
            'ok'       => false,
            'to'       => $to,
            'subject'  => '',
            'username' => '',
            'password' => '',
            'error'    => '',
        );
        if ($to === '' || (function_exists('is_email') && !is_email($to))) {
            $out['error'] = 'invalid_email';
            return $out;
        }
        $vars = is_array($vars) ? $vars : self::guest_account_preview_sample();
        $vars['preview'] = true;
        $email = self::build_guest_account_email($vars);
        $out['subject'] = $email['subject'];
        $out['username'] = isset($vars['username']) ? (string) $vars['username'] : '';
        $out['password'] = isset($vars['password']) ? (string) $vars['password'] : '';

        if (!function_exists('wp_mail')) {
            $out['error'] = 'wp_mail_unavailable';
            return $out;
        }
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: Wilder PTSA <info@wilderptsa.net>',
        );
        $sent = wp_mail($to, $email['subject'], $email['html'], $headers);
        $out['ok'] = (bool) $sent;
        if (!$sent) {
            $out['error'] = 'wp_mail_false';
        }
        return $out;
    }

    /**
     * Real (non-preview) welcome to a newly created guest-membership parent.
     *
     * @param string $to
     * @param array  $vars
     * @return array{ok:bool,to:string,subject:string,error:string}
     */
    public static function send_guest_account_email($to, array $vars) {
        $to = function_exists('sanitize_email') ? sanitize_email($to) : strtolower(trim((string) $to));
        $vars['preview'] = false;
        $email = self::build_guest_account_email($vars);
        $out = array(
            'ok'      => false,
            'to'      => $to,
            'subject' => $email['subject'],
            'error'   => '',
        );
        if ($to === '' || (function_exists('is_email') && !is_email($to))) {
            $out['error'] = 'invalid_email';
            return $out;
        }
        if (!function_exists('wp_mail')) {
            $out['error'] = 'wp_mail_unavailable';
            return $out;
        }
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: Wilder PTSA <info@wilderptsa.net>',
        );
        $sent = wp_mail($to, $email['subject'], $email['html'], $headers);
        $out['ok'] = (bool) $sent;
        if (!$sent) {
            $out['error'] = 'wp_mail_false';
        }
        return $out;
    }

    /**
     * Normalize and gate a create-account batch. Does not write users.
     *
     * @param array $candidates
     * @param array $existing_emails lowercase emails that already have a WP user
     * @return array{ok:bool,ready:array,skipped:array,errors:array}
     */
    public static function validate_guest_account_candidates(array $candidates, array $existing_emails = array()) {
        $existing = array();
        foreach ($existing_emails as $email) {
            $n = self::normalize_email($email);
            if ($n !== '') {
                $existing[$n] = true;
            }
        }
        $ready = array();
        $skipped = array();
        $errors = array();
        $seen = array();

        foreach ($candidates as $raw) {
            $email = self::normalize_email(isset($raw['email']) ? $raw['email'] : '');
            $first = trim((string) (isset($raw['first']) ? $raw['first'] : ''));
            $last = trim((string) (isset($raw['last']) ? $raw['last'] : ''));
            if ($last === '-') {
                $last = '';
            }
            $name = trim((string) (isset($raw['name']) ? $raw['name'] : ($first . ' ' . $last)));
            $order_id = isset($raw['order_id']) ? (int) $raw['order_id'] : 0;
            $row = array(
                'email'      => $email,
                'first'      => $first,
                'last'       => $last,
                'name'       => $name,
                'order_id'   => $order_id,
                'link_order' => !empty($raw['link_order']),
                'slot'       => isset($raw['slot']) ? (string) $raw['slot'] : '',
            );
            if ($email === '' || (function_exists('is_email') && !is_email($email))) {
                $row['reason'] = 'missing_or_invalid_email';
                $skipped[] = $row;
                continue;
            }
            if (isset($existing[$email])) {
                $row['reason'] = 'email_already_has_account';
                $skipped[] = $row;
                continue;
            }
            if (isset($seen[$email])) {
                $errors[] = array(
                    'email'  => $email,
                    'reason' => 'duplicate_email_in_batch',
                );
                continue;
            }
            $seen[$email] = true;
            $ready[] = $row;
        }

        return array(
            'ok'      => empty($errors),
            'ready'   => $ready,
            'skipped' => $skipped,
            'errors'  => $errors,
        );
    }

    /**
     * @param int $count
     * @return string[]
     */
    public static function generate_unique_passwords($count) {
        $count = max(0, (int) $count);
        $out = array();
        $guard = 0;
        while (count($out) < $count && $guard < ($count * 20) + 20) {
            $guard++;
            $password = function_exists('wp_generate_password')
                ? wp_generate_password(14, true, false)
                : ('Acct-' . substr(md5(uniqid((string) $guard, true)), 0, 10));
            if ($password === '' || isset($out[$password])) {
                continue;
            }
            $out[$password] = true;
        }
        return array_keys($out);
    }

    /**
     * @param array<int, array{email?:string,password?:string}> $rows
     * @return array{ok:bool,unique_emails:bool,unique_passwords:bool,email_count:int,password_count:int,errors:array}
     */
    public static function assert_unique_credentials(array $rows) {
        $emails = array();
        $passwords = array();
        $errors = array();
        foreach ($rows as $row) {
            $email = self::normalize_email(isset($row['email']) ? $row['email'] : '');
            $password = (string) (isset($row['password']) ? $row['password'] : '');
            if ($email === '') {
                $errors[] = 'empty_email';
                continue;
            }
            if ($password === '') {
                $errors[] = 'empty_password:' . $email;
                continue;
            }
            if (isset($emails[$email])) {
                $errors[] = 'duplicate_email:' . $email;
            }
            $emails[$email] = true;
            if (isset($passwords[$password])) {
                $errors[] = 'duplicate_password:' . $email;
            }
            $passwords[$password] = true;
        }
        return array(
            'ok'               => empty($errors),
            'unique_emails'    => count($emails) === count($rows) && empty($errors),
            'unique_passwords' => count($passwords) === count($rows) && empty($errors),
            'email_count'      => count($emails),
            'password_count'   => count($passwords),
            'errors'           => $errors,
        );
    }

    /**
     * Create parent accounts for unmatched guest memberships, verify
     * unique email+password, optionally send the welcome. Mail is never
     * sent until every created row has been verified.
     *
     * @param array $candidates
     * @param bool  $send
     * @return array
     */
    public static function provision_guest_membership_accounts(array $candidates, $send = false) {
        $existing = array();
        foreach ($candidates as $raw) {
            $email = self::normalize_email(isset($raw['email']) ? $raw['email'] : '');
            if ($email !== '' && function_exists('get_user_by') && get_user_by('email', $email)) {
                $existing[] = $email;
            }
        }
        $plan = self::validate_guest_account_candidates($candidates, $existing);
        $out = array(
            'ok'          => false,
            'send'        => (bool) $send,
            'ready'       => count($plan['ready']),
            'created'     => array(),
            'sent'        => array(),
            'skipped'     => $plan['skipped'],
            'errors'      => $plan['errors'],
            'credentials' => array(
                'unique_emails'    => false,
                'unique_passwords' => false,
                'email_count'      => 0,
                'password_count'   => 0,
            ),
            'accounts_verified_before_send' => false,
        );
        if (!$plan['ok']) {
            $out['errors'][] = 'batch_validation_failed';
            return $out;
        }
        if (empty($plan['ready'])) {
            $out['ok'] = true;
            return $out;
        }

        $passwords = self::generate_unique_passwords(count($plan['ready']));
        if (count($passwords) !== count($plan['ready'])) {
            $out['errors'][] = 'password_generation_failed';
            return $out;
        }

        $created = array();
        foreach ($plan['ready'] as $i => $row) {
            $password = $passwords[$i];
            $made = self::create_guest_membership_parent($row, $password);
            if (!empty($made['error'])) {
                $out['errors'][] = $made['error'] . ':' . $row['email'];
                continue;
            }
            $created[] = $made;
        }

        $cred = self::assert_unique_credentials($created);
        $out['credentials'] = $cred;
        $out['created'] = array_map(function ($row) {
            return array(
                'user_id'    => (int) $row['user_id'],
                'email'      => $row['email'],
                'username'   => $row['username'],
                'first'      => $row['first'],
                'last'       => $row['last'],
                'order_id'   => (int) $row['order_id'],
                'linked'     => !empty($row['linked']),
                'verified'   => !empty($row['verified']),
            );
        }, $created);

        $all_verified = !empty($created) && $cred['ok'];
        foreach ($created as $row) {
            if (empty($row['verified']) || (int) $row['user_id'] < 1) {
                $all_verified = false;
            }
        }
        $out['accounts_verified_before_send'] = $all_verified;

        if (!$all_verified) {
            $out['errors'][] = 'accounts_not_verified';
            return $out;
        }

        $out['ok'] = true;
        if (!$send) {
            return $out;
        }

        foreach ($created as $row) {
            $mail = self::send_guest_account_email($row['email'], array(
                'site_name'     => function_exists('get_bloginfo') ? get_bloginfo('name') : 'Wilder PTSA',
                'first_name'    => $row['first'] !== '' ? $row['first'] : $row['name'],
                'username'      => $row['username'],
                'password'      => $row['password'],
                'login_url'     => 'https://wilderptsa.net/my-account/',
                'support_email' => 'info@wilderptsa.net',
                'preview'       => false,
            ));
            if (!empty($mail['ok']) && function_exists('update_user_meta')) {
                update_user_meta((int) $row['user_id'], '_pta_guest_membership_welcome_sent', gmdate('Y-m-d\TH:i:s\Z'));
            }
            $out['sent'][] = array(
                'user_id' => (int) $row['user_id'],
                'email'   => $row['email'],
                'ok'      => !empty($mail['ok']),
                'error'   => $mail['error'],
            );
        }
        if (function_exists('update_option')) {
            self::flush_member_map();
        }
        return $out;
    }

    /**
     * @param array  $row
     * @param string $password
     * @return array
     */
    public static function create_guest_membership_parent(array $row, $password) {
        $email = self::normalize_email(isset($row['email']) ? $row['email'] : '');
        $out = array(
            'user_id'  => 0,
            'email'    => $email,
            'username' => $email,
            'password' => $password,
            'first'    => isset($row['first']) ? trim((string) $row['first']) : '',
            'last'     => isset($row['last']) ? trim((string) $row['last']) : '',
            'name'     => isset($row['name']) ? trim((string) $row['name']) : '',
            'order_id' => isset($row['order_id']) ? (int) $row['order_id'] : 0,
            'linked'   => false,
            'verified' => false,
            'error'    => '',
        );
        if ($email === '' || $password === '') {
            $out['error'] = 'missing_email_or_password';
            return $out;
        }
        if (!function_exists('wp_insert_user')) {
            $out['error'] = 'wp_insert_user_unavailable';
            return $out;
        }
        if (function_exists('get_user_by') && get_user_by('email', $email)) {
            $out['error'] = 'email_already_has_account';
            return $out;
        }

        $login = self::guest_account_user_login($email);
        $display = $out['name'] !== '' ? $out['name'] : trim($out['first'] . ' ' . $out['last']);
        if ($display === '') {
            $display = $email;
        }
        $user_id = wp_insert_user(array(
            'user_login'   => $login,
            'user_email'   => $email,
            'user_pass'    => $password,
            'first_name'   => $out['first'],
            'last_name'    => $out['last'],
            'display_name' => $display,
            'role'         => 'parent',
        ));
        if (is_wp_error($user_id)) {
            $out['error'] = $user_id->get_error_message();
            return $out;
        }
        $out['user_id'] = (int) $user_id;

        if (function_exists('delete_user_meta') && class_exists('Azure_Parent_Role')) {
            delete_user_meta($out['user_id'], Azure_Parent_Role::META_LOGIN_DISABLED);
            update_user_meta($out['user_id'], Azure_Parent_Role::META_FORCE_PW_RESET, 1);
        }
        if (function_exists('update_user_meta')) {
            update_user_meta($out['user_id'], '_pta_guest_membership_account', 1);
            if (class_exists('Azure_Parent_Activation')) {
                update_user_meta($out['user_id'], Azure_Parent_Activation::META_IMPORT_SOURCE, 'membership_guest');
            }
        }

        $should_link = !empty($row['link_order']);
        if ($should_link && $out['order_id'] > 0 && function_exists('wc_get_order')) {
            $order = wc_get_order($out['order_id']);
            if ($order) {
                $out['linked'] = self::maybe_link_guest_order($order, $out['user_id']);
            }
        }

        $user = function_exists('get_user_by') ? get_user_by('id', $out['user_id']) : false;
        $hash_ok = $user && function_exists('wp_check_password')
            ? wp_check_password($password, $user->user_pass, $out['user_id'])
            : (bool) $user;
        $email_ok = $user && self::emails_are_same($user->user_email, $email);
        $out['verified'] = $hash_ok && $email_ok && $out['user_id'] > 0;
        if (!$out['verified']) {
            $out['error'] = 'account_verify_failed';
        }
        return $out;
    }

    public static function guest_account_user_login($email) {
        $email = self::normalize_email($email);
        $local = strstr($email, '@', true);
        $base = function_exists('sanitize_user')
            ? sanitize_user(strtolower((string) $local), true)
            : preg_replace('/[^a-z0-9_]/', '', strtolower((string) $local));
        if ($base === '' || strlen($base) < 3) {
            $base = 'parent_' . substr(md5($email), 0, 8);
        }
        $username = $base;
        $i = 1;
        while (function_exists('username_exists') && username_exists($username)) {
            $username = $base . $i;
            $i++;
            if ($i > 999) {
                $username = $base . '_' . substr(md5($email . microtime(true)), 0, 6);
                break;
            }
        }
        return $username;
    }

    private static function order_membership_type($order) {
        $found = array();
        foreach ($order->get_items() as $item) {
            $type = self::item_membership_type($item);
            if ($type !== '') {
                $found[$type] = true;
            }
        }
        if (isset($found['family'])) {
            return 'family';
        }
        if (isset($found['staff'])) {
            return 'staff';
        }
        if (isset($found['individual'])) {
            return 'individual';
        }
        return '';
    }

    /**
     * @param object $item
     * @return string family|individual|staff|''
     */
    public static function item_membership_type($item) {
        if (!is_object($item) || !method_exists($item, 'get_product_id')) {
            return '';
        }
        if (method_exists($item, 'get_meta') && $item->get_meta('_pta_donated_product')) {
            return '';
        }
        $product_id = (int) $item->get_product_id();
        $parent_id = 0;
        $name = method_exists($item, 'get_name') ? (string) $item->get_name() : '';
        if (method_exists($item, 'get_variation_id')) {
            $vid = (int) $item->get_variation_id();
            if ($vid) {
                $parent_id = $product_id;
                $product_id = $vid;
            }
        }
        $product = method_exists($item, 'get_product') ? $item->get_product() : null;
        if ($product && method_exists($product, 'get_parent_id')) {
            $from_product = (int) $product->get_parent_id();
            if ($from_product) {
                $parent_id = $from_product;
            }
            if ($name === '' && method_exists($product, 'get_name')) {
                $name = (string) $product->get_name();
            }
        }
        return self::classify_membership_product($product_id, $parent_id, $name);
    }

    /**
     * @param object $order
     * @param object $item
     * @param string $type
     * @return array{name:string,email:string,parent_2_name:string,parent_2_email:string,membership:string,paid_at:string,children:string,role_types:string[]}
     */
    private static function export_row_from_order_item($order, $item, $type) {
        $name = '';
        $email = '';
        $role_types = array();
        $user_id = method_exists($order, 'get_user_id') ? (int) $order->get_user_id() : 0;
        if ($user_id && function_exists('get_userdata')) {
            $user = get_userdata($user_id);
            if ($user) {
                $name = (string) $user->display_name;
                $email = (string) $user->user_email;
                $roles = (array) $user->roles;
                if (in_array('parent', $roles, true)) {
                    $role_types[] = 'Parent';
                }
                if (in_array('school_staff', $roles, true)) {
                    $role_types[] = 'School staff';
                }
            }
        }
        if ($name === '' && method_exists($order, 'get_billing_first_name')) {
            $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        }
        if ($email === '' && method_exists($order, 'get_billing_email')) {
            $email = (string) $order->get_billing_email();
        }

        $p2 = ($type === 'family')
            ? self::parent_2_from_order_item($order, $item, $user_id)
            : array('name' => '', 'email' => '');

        $paid_at = '';
        if (method_exists($order, 'get_date_paid') && $order->get_date_paid()) {
            $paid = $order->get_date_paid();
            $paid_at = is_object($paid) && method_exists($paid, 'date')
                ? $paid->date('Y-m-d H:i:s')
                : (string) $paid;
        } elseif (method_exists($order, 'get_date_created') && $order->get_date_created()) {
            $created = $order->get_date_created();
            $paid_at = is_object($created) && method_exists($created, 'date')
                ? $created->date('Y-m-d H:i:s')
                : (string) $created;
        }

        return array(
            'name'           => $name,
            'email'          => $email,
            'parent_2_name'  => $p2['name'],
            'parent_2_email' => $p2['email'],
            'membership'     => $type,
            'paid_at'        => $paid_at,
            'children'       => self::children_label_from_item($item),
            'role_types'     => $role_types,
        );
    }

    /**
     * Parent 2 on a family sale: checkout fields first, then Family Info
     * user meta, then a paired co-parent account.
     *
     * @return array{name:string,email:string}
     */
    public static function parent_2_from_order_item($order, $item, $user_id = 0) {
        $name = self::item_product_field($item, 'parent_2_name', array('Parent 2 Name', 'Parent2 Name'));
        $email = self::item_product_field($item, 'parent_2_email', array('Parent 2 Email', 'Parent2 Email'));

        if (($name === '' || $email === '') && $user_id && function_exists('get_user_meta')) {
            if ($name === '') {
                $name = trim((string) get_user_meta($user_id, self::META_P2_NAME, true));
            }
            if ($email === '') {
                $email = trim((string) get_user_meta($user_id, self::META_P2_EMAIL, true));
            }
        }

        if (($name === '' || $email === '') && $user_id) {
            $other = self::co_parent_user_id($user_id);
            if ($other && function_exists('get_userdata')) {
                $other_user = get_userdata($other);
                if ($other_user) {
                    if ($name === '') {
                        $name = trim((string) $other_user->display_name);
                    }
                    if ($email === '') {
                        $email = trim((string) $other_user->user_email);
                    }
                }
            }
        }

        return array(
            'name'  => $name,
            'email' => $email,
        );
    }

    /**
     * @param object $item
     * @param string $field_key
     * @param string[] $labels
     */
    public static function item_product_field($item, $field_key, array $labels = array()) {
        if (!is_object($item) || !method_exists($item, 'get_meta')) {
            return '';
        }
        $v = trim((string) $item->get_meta('_pta_' . $field_key));
        if ($v === '') {
            $v = trim((string) $item->get_meta('_pta_' . $field_key . '_2'));
        }
        if ($v !== '') {
            return $v;
        }
        $raw = $item->get_meta('_azure_product_fields_raw');
        if (is_array($raw)) {
            foreach ($raw as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $f_key = isset($field['field_key']) ? (string) $field['field_key'] : '';
                $f_label = isset($field['label']) ? (string) $field['label'] : '';
                $matches = ($f_key !== '' && $f_key === $field_key)
                    || ($f_label !== '' && in_array($f_label, $labels, true));
                if ($matches && isset($field['value']) && trim((string) $field['value']) !== '') {
                    return trim((string) $field['value']);
                }
            }
        }
        foreach ($labels as $label) {
            $v = trim((string) $item->get_meta($label));
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    /**
     * @param object $item
     * @return string
     */
    public static function children_label_from_item($item) {
        if (!is_object($item) || !method_exists($item, 'get_meta')) {
            return '';
        }
        $roster = $item->get_meta('_azure_pf_children');
        if (is_array($roster) && $roster) {
            $bits = array();
            foreach ($roster as $child) {
                $child_name = isset($child['name']) ? trim((string) $child['name']) : '';
                $grade = isset($child['grade']) ? trim((string) $child['grade']) : '';
                if ($child_name === '') {
                    continue;
                }
                $bits[] = $grade !== '' ? $child_name . ' (' . $grade . ')' : $child_name;
            }
            if ($bits) {
                return implode('; ', $bits);
            }
        }
        $names = trim((string) $item->get_meta('_pta_child_name'));
        if ($names === '') {
            $names = trim((string) $item->get_meta('Child Name'));
        }
        $grades = trim((string) $item->get_meta('_pta_childsgrade'));
        if ($grades === '') {
            $grades = trim((string) $item->get_meta('_pta_child_grade'));
        }
        if ($names === '') {
            return '';
        }
        $name_list = array_map('trim', explode(',', $names));
        $grade_list = $grades === '' ? array() : array_map('trim', explode(',', $grades));
        $bits = array();
        foreach ($name_list as $i => $child_name) {
            if ($child_name === '') {
                continue;
            }
            $grade = isset($grade_list[$i]) ? $grade_list[$i] : '';
            $bits[] = $grade !== '' ? $child_name . ' (' . $grade . ')' : $child_name;
        }
        return implode('; ', $bits);
    }

    /**
     * Family wins over individual when the same person appears on both.
     */
    private static function apply_member_entry(array &$map, $user_id, array $entry) {
        $user_id = (int) $user_id;
        if (!$user_id) {
            return;
        }
        if (!isset($map[$user_id]) || ($entry['type'] === 'family' && $map[$user_id]['type'] !== 'family')) {
            $map[$user_id] = $entry;
        }
    }

    private static function co_parent_user_id($user_id) {
        if (!class_exists('Azure_User_Children')) {
            return 0;
        }
        $family = Azure_User_Children::get_family_for_user((int) $user_id);
        if (!$family) {
            return 0;
        }
        $primary   = (int) $family->primary_user_id;
        $secondary = (int) $family->secondary_user_id;
        if ($primary === (int) $user_id) {
            return $secondary;
        }
        if ($secondary === (int) $user_id) {
            return $primary;
        }
        return 0;
    }

    // ─── Opt-in helpers ─────────────────────────────────────────────

    public static function is_opted_in($value) {
        if ($value === true || $value === 1) {
            return true;
        }
        $v = strtolower(trim((string) $value));
        return in_array($v, array('1', 'yes', 'true', 'on'), true);
    }

    private static function opted_in_user_ids($meta_key) {
        global $wpdb;
        $placeholders = implode(',', array_fill(0, 5, '%s'));
        $sql = $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
             WHERE meta_key = %s AND meta_value IN ($placeholders)",
            $meta_key,
            '1',
            'Yes',
            'yes',
            'true',
            'on'
        );
        $ids = $wpdb->get_col($sql);
        return array_values(array_unique(array_map('intval', $ids)));
    }

    // ─── Directory visibility ───────────────────────────────────────

    public static function user_can_view_directory($user = null) {
        if ($user === null) {
            $user = wp_get_current_user();
        }
        if (!$user || !$user->exists()) {
            return false;
        }
        if (user_can($user, 'manage_options')) {
            return true;
        }
        if (user_can($user, 'azure_ad_user')) {
            return true;
        }
        $roles = (array) $user->roles;
        if (in_array('parent', $roles, true) || in_array('school_staff', $roles, true)) {
            return true;
        }
        self::ensure_sso_sync();
        if (class_exists('Azure_SSO_Sync')) {
            $sso = Azure_SSO_Sync::resolve_configured_role_slug();
            if ($sso && in_array($sso, $roles, true)) {
                return true;
            }
        }
        if (get_user_meta($user->ID, 'azure_object_id', true)) {
            return true;
        }
        return self::user_has_active_pta_role((int) $user->ID);
    }

    private static function user_has_active_pta_role($user_id) {
        $ids = self::active_pta_assignee_ids();
        return isset($ids[(int) $user_id]);
    }

    /**
     * @return array<int,true>
     */
    private static function active_pta_assignee_ids() {
        static $lookup = null;
        if ($lookup !== null) {
            return $lookup;
        }
        $lookup = array();
        self::ensure_pta_database();
        if (!class_exists('Azure_PTA_Database')) {
            return $lookup;
        }
        global $wpdb;
        $table = Azure_PTA_Database::get_table_name('assignments');
        if (!$table) {
            return $lookup;
        }
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$table} WHERE status = %s",
            'active'
        ));
        foreach ($ids as $id) {
            $lookup[(int) $id] = true;
        }
        return $lookup;
    }

    // ─── Admin roster universe ──────────────────────────────────────

    /**
     * Union of parents, school staff, PTA role-holders, and Azure AD users.
     *
     * @return int[]
     */
    public static function roster_user_ids() {
        $ids = array();

        foreach (array('parent', 'school_staff') as $role) {
            $users = get_users(array('role' => $role, 'fields' => 'ID'));
            foreach ($users as $id) {
                $ids[(int) $id] = true;
            }
        }

        $sso_roles = self::azure_ad_role_slugs();
        foreach ($sso_roles as $role) {
            $users = get_users(array('role' => $role, 'fields' => 'ID'));
            foreach ($users as $id) {
                $ids[(int) $id] = true;
            }
        }

        $ad_users = get_users(array(
            'meta_key'     => 'azure_object_id',
            'meta_compare' => '!=',
            'meta_value'   => '',
            'fields'       => 'ID',
        ));
        foreach ($ad_users as $id) {
            $ids[(int) $id] = true;
        }

        foreach (array_keys(self::active_pta_assignee_ids()) as $id) {
            $ids[(int) $id] = true;
        }

        return array_keys($ids);
    }

    private static function azure_ad_role_slugs() {
        $slugs = array();
        self::ensure_sso_sync();
        if (class_exists('Azure_SSO_Sync')) {
            $configured = Azure_SSO_Sync::resolve_configured_role_slug();
            if ($configured) {
                $slugs[] = $configured;
            }
        }
        global $wp_roles;
        if ($wp_roles instanceof WP_Roles) {
            foreach ($wp_roles->roles as $slug => $data) {
                if (!empty($data['capabilities']['azure_ad_user'])) {
                    $slugs[] = $slug;
                }
            }
        }
        return array_values(array_unique($slugs));
    }

    /**
     * Build hydrated roster rows for the given user IDs.
     *
     * @param int[] $user_ids
     * @return array<int, array>
     */
    public static function build_roster_rows(array $user_ids) {
        $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
        if (empty($user_ids)) {
            return array();
        }

        $users = get_users(array('include' => $user_ids, 'orderby' => 'display_name', 'order' => 'ASC'));
        $map   = self::get_member_map();
        $pta   = self::pta_roles_by_user($user_ids);
        $kids  = self::children_by_user($user_ids);
        $sso_slugs = array_flip(self::azure_ad_role_slugs());

        $rows = array();
        foreach ($users as $user) {
            $uid = (int) $user->ID;
            $roles = (array) $user->roles;
            $role_labels = array();
            if (in_array('parent', $roles, true)) {
                $role_labels[] = 'Parent';
            }
            if (in_array('school_staff', $roles, true)) {
                $role_labels[] = 'School staff';
            }
            if (isset($sso_slugs) && array_intersect(array_keys($sso_slugs), $roles)) {
                $role_labels[] = 'Azure AD';
            } elseif (user_can($user, 'azure_ad_user') || get_user_meta($uid, 'azure_object_id', true)) {
                $role_labels[] = 'Azure AD';
            }
            if (!empty($pta[$uid])) {
                foreach ($pta[$uid] as $role_name) {
                    $role_labels[] = $role_name;
                }
            }
            $role_labels = array_values(array_unique($role_labels));

            $membership = isset($map[$uid]) ? $map[$uid] : null;
            $children   = isset($kids[$uid]) ? $kids[$uid] : array();

            $rows[] = array(
                'user_id'      => $uid,
                'name'         => $user->display_name,
                'email'        => $user->user_email,
                'role_types'   => $role_labels,
                'membership'   => $membership ? $membership['type'] : 'none',
                'paid_at'      => $membership ? $membership['paid_at'] : '',
                'children'     => $children,
            );
        }
        return $rows;
    }

    /**
     * @param int[] $user_ids
     * @return array<int, string[]>
     */
    private static function pta_roles_by_user(array $user_ids) {
        $out = array();
        self::ensure_pta_manager();
        if (!class_exists('Azure_PTA_Manager')) {
            return $out;
        }
        $manager = Azure_PTA_Manager::get_instance();
        if (!method_exists($manager, 'get_assignments_for_users')) {
            return $out;
        }
        $rows = $manager->get_assignments_for_users($user_ids);
        foreach ($rows as $row) {
            $uid = (int) $row->user_id;
            $label = !empty($row->role_name) ? $row->role_name : '';
            if ($label === '') {
                continue;
            }
            $out[$uid][] = $label;
        }
        return $out;
    }

    /**
     * @param int[] $user_ids
     * @return array<int, array<int, array{name:string,grade:string}>>
     */
    private static function children_by_user(array $user_ids) {
        $out = array();
        if (!class_exists('Azure_User_Children') || !class_exists('Azure_Database')) {
            return $out;
        }
        global $wpdb;
        $fam_table = Azure_Database::get_table_name('connected_family');
        $kid_table = Azure_Database::get_table_name('user_children');
        $meta_table = Azure_Database::get_table_name('user_children_meta');
        if (!$fam_table || !$kid_table || empty($user_ids)) {
            return $out;
        }

        $id_list = implode(',', array_map('intval', $user_ids));
        $families = $wpdb->get_results(
            "SELECT id, primary_user_id, secondary_user_id
             FROM {$fam_table}
             WHERE primary_user_id IN ({$id_list}) OR secondary_user_id IN ({$id_list})"
        );
        if (empty($families)) {
            return $out;
        }

        $family_ids = array();
        $users_by_family = array();
        foreach ($families as $f) {
            $fid = (int) $f->id;
            $family_ids[] = $fid;
            foreach (array((int) $f->primary_user_id, (int) $f->secondary_user_id) as $uid) {
                if ($uid) {
                    $users_by_family[$fid][] = $uid;
                }
            }
        }
        $fid_list = implode(',', array_map('intval', array_unique($family_ids)));
        $kids = $wpdb->get_results(
            "SELECT id, family_id, child_name FROM {$kid_table}
             WHERE family_id IN ({$fid_list}) AND is_active = 1
             ORDER BY child_name ASC"
        );
        if (empty($kids)) {
            return $out;
        }

        $grade_by_child = array();
        if ($meta_table) {
            $grade_keys = self::grade_meta_keys();
            $kid_ids = array_map(function ($k) { return (int) $k->id; }, $kids);
            $kid_list = implode(',', $kid_ids);
            $key_in = implode(',', array_fill(0, count($grade_keys), '%s'));
            $sql = $wpdb->prepare(
                "SELECT child_id, meta_value FROM {$meta_table}
                 WHERE child_id IN ({$kid_list}) AND meta_key IN ($key_in)",
                $grade_keys
            );
            foreach ($wpdb->get_results($sql) as $row) {
                if ($row->meta_value !== '') {
                    $grade_by_child[(int) $row->child_id] = $row->meta_value;
                }
            }
        }

        foreach ($kids as $kid) {
            $entry = array(
                'name'  => $kid->child_name,
                'grade' => isset($grade_by_child[(int) $kid->id]) ? $grade_by_child[(int) $kid->id] : '',
            );
            $fid = (int) $kid->family_id;
            if (empty($users_by_family[$fid])) {
                continue;
            }
            foreach ($users_by_family[$fid] as $uid) {
                $out[$uid][] = $entry;
            }
        }
        return $out;
    }

    private static function grade_meta_keys() {
        $keys = array('pta_pf_child_grade', 'pta_pf_childsgrade', 'pta_pf_childs_grade');
        if (class_exists('Azure_Product_Fields_Module')) {
            $resolved = Azure_Product_Fields_Module::get_child_profile_field_keys();
            if (!empty($resolved['grade'])) {
                $keys[] = 'pta_pf_' . $resolved['grade'];
            }
        }
        return array_values(array_unique($keys));
    }

    // ─── Directory rows (opt-in only) ───────────────────────────────

    /**
     * Rows for the public-facing directory. Built only from opted-in
     * parent-1 / parent-2 meta. Missing or empty opt-in is excluded.
     *
     * @return array<int, array>
     */
    public static function build_directory_rows() {
        $p1_ids = self::opted_in_user_ids(self::META_P1_OPT_IN);
        $p2_ids = self::opted_in_user_ids(self::META_P2_OPT_IN);
        $owner_ids = array_values(array_unique(array_merge($p1_ids, $p2_ids)));
        if (empty($owner_ids)) {
            return array();
        }

        $p1_lookup = array_flip($p1_ids);
        $p2_lookup = array_flip($p2_ids);
        $kids_by_user = self::children_by_user($owner_ids);
        $rows = array();

        foreach ($owner_ids as $uid) {
            $user = get_userdata($uid);
            if (!$user) {
                continue;
            }
            $children = isset($kids_by_user[$uid]) ? $kids_by_user[$uid] : array();

            if (isset($p1_lookup[$uid]) && self::is_opted_in(get_user_meta($uid, self::META_P1_OPT_IN, true))) {
                $name = trim((string) get_user_meta($uid, self::META_P1_NAME, true));
                if ($name === '') {
                    $name = $user->display_name;
                }
                $rows[] = array(
                    'slot'      => 'parent_1',
                    'owner_id'  => $uid,
                    'name'      => $name,
                    'email'     => (string) get_user_meta($uid, self::META_P1_EMAIL, true),
                    'cell'      => (string) get_user_meta($uid, self::META_P1_CELL, true),
                    'children'  => $children,
                    'is_member' => self::user_is_member($uid),
                );
            }

            if (isset($p2_lookup[$uid]) && self::is_opted_in(get_user_meta($uid, self::META_P2_OPT_IN, true))) {
                $name = trim((string) get_user_meta($uid, self::META_P2_NAME, true));
                if ($name === '') {
                    continue;
                }
                $rows[] = array(
                    'slot'      => 'parent_2',
                    'owner_id'  => $uid,
                    'name'      => $name,
                    'email'     => (string) get_user_meta($uid, self::META_P2_EMAIL, true),
                    'cell'      => (string) get_user_meta($uid, self::META_P2_CELL, true),
                    'children'  => $children,
                    'is_member' => self::user_is_member($uid),
                );
            }
        }

        usort($rows, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        return $rows;
    }

    // ─── Shortcode ──────────────────────────────────────────────────

    public function render_directory_shortcode($atts = array()) {
        $atts = shortcode_atts(array(
            'show_email' => 'false',
            'show_cell'  => 'false',
        ), $atts, self::SHORTCODE_A);
        $show_email = filter_var($atts['show_email'], FILTER_VALIDATE_BOOLEAN);
        $show_cell  = filter_var($atts['show_cell'], FILTER_VALIDATE_BOOLEAN);

        $this->enqueue_frontend_assets();

        if (!is_user_logged_in()) {
            return $this->render_login_shell();
        }
        if (!self::user_can_view_directory()) {
            return '<div class="pta-parent-directory pta-parent-directory--denied">'
                . '<p>' . esc_html__('This directory is for Wilder families and staff.', 'azure-plugin') . '</p>'
                . '</div>';
        }

        $rows = self::build_directory_rows();
        return $this->render_directory_table($rows, $show_email, $show_cell);
    }

    private function render_login_shell() {
        $login_url = wp_login_url();
        $html  = '<div class="pta-parent-directory pta-parent-directory--login">';
        $html .= '<p>' . esc_html__('Sign in to view the parent directory.', 'azure-plugin') . '</p>';
        $html .= '<p><a class="pta-parent-directory__login" href="' . esc_url($login_url) . '">'
            . esc_html__('Sign in', 'azure-plugin') . '</a></p>';
        $html .= '</div>';
        return $html;
    }

    private function render_directory_table(array $rows, $show_email, $show_cell) {
        $grades = array();
        foreach ($rows as $row) {
            foreach ($row['children'] as $child) {
                if (!empty($child['grade'])) {
                    $grades[$child['grade']] = true;
                }
            }
        }
        ksort($grades, SORT_NATURAL | SORT_FLAG_CASE);

        ob_start();
        ?>
        <div class="pta-parent-directory" data-pta-parent-directory="1">
            <div class="pta-parent-directory__toolbar">
                <input type="search" class="pta-parent-directory__search" placeholder="<?php esc_attr_e('Search names or children…', 'azure-plugin'); ?>" />
                <?php if (!empty($grades)): ?>
                <label class="pta-parent-directory__grade-label">
                    <span><?php esc_html_e('Grade', 'azure-plugin'); ?></span>
                    <select class="pta-parent-directory__grade">
                        <option value=""><?php esc_html_e('All grades', 'azure-plugin'); ?></option>
                        <?php foreach (array_keys($grades) as $grade): ?>
                            <option value="<?php echo esc_attr($grade); ?>"><?php echo esc_html($grade); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php endif; ?>
                <span class="pta-parent-directory__count"></span>
            </div>
            <table class="pta-parent-directory__table">
                <thead>
                    <tr>
                        <th data-sort="name"><?php esc_html_e('Name', 'azure-plugin'); ?></th>
                        <th data-sort="children"><?php esc_html_e('Children', 'azure-plugin'); ?></th>
                        <?php if ($show_email): ?><th data-sort="email"><?php esc_html_e('Email', 'azure-plugin'); ?></th><?php endif; ?>
                        <?php if ($show_cell): ?><th data-sort="cell"><?php esc_html_e('Phone', 'azure-plugin'); ?></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr class="pta-parent-directory__empty"><td colspan="<?php echo $show_email && $show_cell ? 4 : ($show_email || $show_cell ? 3 : 2); ?>">
                        <?php esc_html_e('No parents have opted in to the directory yet.', 'azure-plugin'); ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row):
                        $child_bits = array();
                        $grade_bits = array();
                        foreach ($row['children'] as $child) {
                            $label = $child['name'];
                            if ($child['grade'] !== '') {
                                $label .= ' (' . $child['grade'] . ')';
                                $grade_bits[] = $child['grade'];
                            }
                            $child_bits[] = $label;
                        }
                        $children_text = implode(', ', $child_bits);
                        ?>
                    <tr data-name="<?php echo esc_attr($row['name']); ?>"
                        data-children="<?php echo esc_attr($children_text); ?>"
                        data-email="<?php echo esc_attr($row['email']); ?>"
                        data-cell="<?php echo esc_attr($row['cell']); ?>"
                        data-grades="<?php echo esc_attr(implode('|', $grade_bits)); ?>">
                        <td><span class="pta-parent-directory__name"><?php
                            echo esc_html($row['name']);
                            if (!empty($row['is_member'])) {
                                echo self::render_member_badge('pill');
                            }
                        ?></span></td>
                        <td><?php echo esc_html($children_text); ?></td>
                        <?php if ($show_email): ?><td><?php echo esc_html($row['email']); ?></td><?php endif; ?>
                        <?php if ($show_cell): ?><td><?php echo esc_html($row['cell']); ?></td><?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    public function enqueue_checkout_express_account() {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        if (function_exists('is_order_received_page') && is_order_received_page()) {
            return;
        }
        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            return;
        }
        if (!self::cart_requires_membership_account()) {
            return;
        }
        $css = AZURE_PLUGIN_PATH . 'css/membership-checkout-express.css';
        $js  = AZURE_PLUGIN_PATH . 'js/membership-checkout-express.js';
        wp_enqueue_style(
            'pta-membership-checkout-express',
            AZURE_PLUGIN_URL . 'css/membership-checkout-express.css',
            array(),
            file_exists($css) ? (string) filemtime($css) : AZURE_PLUGIN_VERSION
        );
        wp_enqueue_script(
            'pta-membership-checkout-express',
            AZURE_PLUGIN_URL . 'js/membership-checkout-express.js',
            array(),
            file_exists($js) ? (string) filemtime($js) : AZURE_PLUGIN_VERSION,
            true
        );
        wp_localize_script(
            'pta-membership-checkout-express',
            'ptaMembershipCheckout',
            array(
                'hint' => __('Enter your email and password first, then you can use Apple Pay or Google Pay.', 'azure-plugin'),
            )
        );
    }

    public function maybe_enqueue_frontend() {
        if (!$this->current_post_has_directory_shortcode()) {
            return;
        }
        $this->enqueue_frontend_assets();
    }

    private function enqueue_frontend_assets() {
        if (self::$assets_enqueued) {
            return;
        }
        self::$assets_enqueued = true;
        $css = AZURE_PLUGIN_PATH . 'css/membership-directory.css';
        $js  = AZURE_PLUGIN_PATH . 'js/membership-directory.js';
        wp_enqueue_style(
            'pta-membership-directory',
            AZURE_PLUGIN_URL . 'css/membership-directory.css',
            array(),
            file_exists($css) ? (string) filemtime($css) : AZURE_PLUGIN_VERSION
        );
        wp_enqueue_script(
            'pta-membership-directory',
            AZURE_PLUGIN_URL . 'js/membership-directory.js',
            array(),
            file_exists($js) ? (string) filemtime($js) : AZURE_PLUGIN_VERSION,
            true
        );
    }

    /**
     * Defense in depth: the shortcode already renders zero names to guests.
     * This keeps a guessed URL from serving a stale signed-in table if a
     * page cache ever ignored Cache-Control — guests still get the generic
     * login shell (cacheable); signed-in unauthorized users get the denial.
     */
    public function maybe_gate_directory_page() {
        if (is_admin() || !is_singular()) {
            return;
        }
        if (!$this->current_post_has_directory_shortcode()) {
            return;
        }
        if (is_user_logged_in() && !self::user_can_view_directory()) {
            // Shortcode renders the denial; nothing else to do.
            return;
        }
    }

    private function current_post_has_directory_shortcode() {
        $post = get_post();
        if (!$post || empty($post->post_content)) {
            return false;
        }
        return has_shortcode($post->post_content, self::SHORTCODE_A)
            || has_shortcode($post->post_content, self::SHORTCODE_B);
    }

    // ─── Dashboard widget ───────────────────────────────────────────

    public function register_dashboard_widget() {
        if (!self::current_user_can_manage()) {
            return;
        }
        wp_add_dashboard_widget(
            'azure_membership_stats',
            __('Membership', 'azure-plugin'),
            array($this, 'render_dashboard_widget')
        );
    }

    /**
     * @return array{parents:int,memberships:int,bought_week:int,year_label:string}
     */
    public static function dashboard_stats() {
        $counts  = function_exists('count_users') ? count_users() : array();
        $role    = class_exists('Azure_Parent_Role') ? Azure_Parent_Role::ROLE_SLUG : 'parent';
        $parents = isset($counts['avail_roles'][$role]) ? (int) $counts['avail_roles'][$role] : 0;

        $map = self::get_member_map();
        $range = self::school_year_range();

        return array(
            'parents'      => $parents,
            'memberships'  => count($map),
            'bought_week'  => self::count_membership_orders_since('-7 days'),
            'year_label'   => $range['label'],
        );
    }

    /**
     * Paid membership orders (not donated) created since a relative time.
     */
    public static function count_membership_orders_since($relative) {
        if (!function_exists('wc_get_orders')) {
            return 0;
        }

        $tz   = wp_timezone();
        $to   = new DateTimeImmutable('now', $tz);
        $from = $to->modify($relative);
        $orders = wc_get_orders(array(
            'status'       => array('processing', 'completed'),
            'type'         => 'shop_order',
            'date_created' => $from->format('Y-m-d H:i:s') . '...' . $to->format('Y-m-d H:i:s'),
            'limit'        => -1,
            'return'       => 'objects',
        ));

        $count = 0;
        foreach ($orders as $order) {
            if (self::order_membership_type($order)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Same nonce'd URL the Membership page uses for the WA/LW CSV.
     */
    public static function export_csv_url() {
        $url = admin_url('admin.php?page=azure-plugin-membership&export=csv');
        return function_exists('wp_nonce_url') ? wp_nonce_url($url, self::NONCE_ADMIN) : $url;
    }

    public function render_dashboard_widget() {
        $stats = self::dashboard_stats();
        $page  = admin_url('admin.php?page=azure-plugin-membership');
        $export_url = self::export_csv_url();
        ?>
        <style>
            .azure-membership-widget .stat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 12px; }
            .azure-membership-widget .stat-card { background: #f9f9f9; padding: 12px; text-align: center; border-radius: 4px; border-left: 3px solid #0078d4; }
            .azure-membership-widget .stat-card .stat-number { font-size: 22px; font-weight: 700; color: #1d2327; line-height: 1.2; }
            .azure-membership-widget .stat-card .stat-label { font-size: 11px; color: #646970; text-transform: uppercase; letter-spacing: 0.3px; }
            .azure-membership-widget .stat-card.warm { border-left-color: #2271b1; }
            .azure-membership-widget .stat-card.fresh { border-left-color: #00a32a; }
        </style>
        <div class="azure-membership-widget">
            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo esc_html(number_format_i18n($stats['parents'])); ?></div>
                    <div class="stat-label"><?php esc_html_e('Parents', 'azure-plugin'); ?></div>
                </div>
                <div class="stat-card warm">
                    <div class="stat-number"><?php echo esc_html(number_format_i18n($stats['memberships'])); ?></div>
                    <div class="stat-label"><?php esc_html_e('Memberships', 'azure-plugin'); ?></div>
                </div>
                <div class="stat-card fresh">
                    <div class="stat-number"><?php echo esc_html(number_format_i18n($stats['bought_week'])); ?></div>
                    <div class="stat-label"><?php esc_html_e('Bought last week', 'azure-plugin'); ?></div>
                </div>
            </div>
            <p class="description" style="margin:0 0 8px;font-size:11px;color:#646970;">
                <?php
                printf(
                    /* translators: %s: school year label like 2026–2027 */
                    esc_html__('Memberships are paid Family, Individual, or Staff products this school year (%s). Last week counts orders, not people. Donated memberships are excluded.', 'azure-plugin'),
                    esc_html($stats['year_label'])
                );
                ?>
            </p>
            <p class="azure-membership-widget-actions" style="margin:0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                <a class="button button-small button-primary" href="<?php echo esc_url($export_url); ?>">
                    <span class="dashicons dashicons-download" style="vertical-align:middle;font-size:14px;height:14px;width:14px;line-height:1;margin-right:2px;"></span>
                    <?php esc_html_e('Export', 'azure-plugin'); ?>
                </a>
                <a class="button button-small" href="<?php echo esc_url($page); ?>"><?php esc_html_e('Open Membership', 'azure-plugin'); ?></a>
            </p>
        </div>
        <?php
    }

    // ─── Admin page ─────────────────────────────────────────────────

    /**
     * Administrators and Finance. Shop Manager stays on Selling / WooCommerce.
     */
    public static function current_user_can_manage() {
        if (class_exists('Azure_Finance_Role')) {
            return Azure_Finance_Role::user_can();
        }
        return current_user_can('manage_options');
    }

    public function register_admin_page() {
        $cap = class_exists('Azure_Finance_Role') ? Azure_Finance_Role::CAP : 'manage_options';
        add_submenu_page(
            'azure-plugin',
            __('Membership', 'azure-plugin'),
            __('Membership', 'azure-plugin'),
            $cap,
            'azure-plugin-membership',
            array($this, 'render_admin_page')
        );
    }

    public function render_admin_page() {
        if (!self::current_user_can_manage()) {
            wp_die(esc_html__('Forbidden', 'azure-plugin'));
        }
        $page = AZURE_PLUGIN_PATH . 'admin/membership-page.php';
        if (file_exists($page)) {
            include $page;
            return;
        }
        echo '<div class="wrap"><h1>' . esc_html__('Membership', 'azure-plugin') . '</h1></div>';
    }

    public function maybe_export_csv() {
        if (!is_admin() || empty($_GET['page']) || $_GET['page'] !== 'azure-plugin-membership') {
            return;
        }
        if (empty($_GET['export']) || $_GET['export'] !== 'csv') {
            return;
        }
        if (!self::current_user_can_manage()) {
            wp_die(esc_html__('Forbidden', 'azure-plugin'));
        }
        check_admin_referer(self::NONCE_ADMIN);

        $rows = self::build_sold_membership_rows();

        $filename = 'wilderptsa-membership-' . gmdate('Y-m-d') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, array('Name', 'Email', 'Parent 2 Name', 'Parent 2 Email', 'Membership Type', 'Paid Date', 'Children', 'Role Types'));
        foreach ($rows as $row) {
            fputcsv($out, array(
                $row['name'],
                $row['email'],
                $row['parent_2_name'],
                $row['parent_2_email'],
                ucfirst($row['membership']),
                $row['paid_at'],
                $row['children'],
                implode('; ', $row['role_types']),
            ));
        }
        fclose($out);
        exit;
    }

    private static function ensure_sso_sync() {
        if (class_exists('Azure_SSO_Sync')) {
            return;
        }
        $path = AZURE_PLUGIN_PATH . 'includes/class-sso-sync.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }

    private static function ensure_pta_database() {
        if (class_exists('Azure_PTA_Database')) {
            return;
        }
        $path = AZURE_PLUGIN_PATH . 'includes/class-pta-database.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }

    private static function ensure_pta_manager() {
        self::ensure_pta_database();
        if (class_exists('Azure_PTA_Manager')) {
            return;
        }
        $path = AZURE_PLUGIN_PATH . 'includes/class-pta-manager.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }
}
