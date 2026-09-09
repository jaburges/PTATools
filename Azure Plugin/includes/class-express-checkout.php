<?php
/**
 * Express wallets (Apple Pay / Google Pay / Amazon Pay via Stripe ECE).
 *
 * Product-page wallets add the item without PTA product fields and without
 * a calculated fulfillment rate. Stripe then asks for a shipping address,
 * gets no Free shipping / pickup rate, rejects every address, and
 * clear_cart on cancel empties the session.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Express_Checkout {

    const ZERO_METHODS = array('free_shipping', 'local_pickup');

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('woocommerce_package_rates', array($this, 'keep_membership_zero_fulfillment'), 25, 2);
        add_filter('woocommerce_shipping_chosen_method', array($this, 'prefer_zero_fulfillment_method'), 10, 3);
        add_filter('wc_stripe_express_checkout_params', array($this, 'filter_stripe_express_params'), 20);
        add_filter('wc_stripe_payment_request_params', array($this, 'filter_stripe_express_params'), 20);
        add_filter('wc_stripe_payment_request_product_data', array($this, 'filter_stripe_product_data'), 20, 2);
    }

    /**
     * Wallets on the product page never POST azure_pf_* fields.
     */
    public static function hide_product_page_wallets($has_required_product_fields) {
        return (bool) $has_required_product_fields;
    }

    /**
     * Membership is fulfilled at school (Free shipping / pickup), not zoned
     * parcel delivery. Wallet addresses must still see that $0 rate.
     *
     * @param string[] $types
     */
    public static function keep_zero_fulfillment_for_types(array $types) {
        foreach ($types as $type) {
            $type = strtolower(trim((string) $type));
            if (in_array($type, array('family', 'individual', 'staff'), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string[] $method_ids
     * @return string[]
     */
    public static function preferred_zero_rate_keys(array $method_ids) {
        $kept = array();
        foreach ($method_ids as $key => $method_id) {
            if (in_array((string) $method_id, self::ZERO_METHODS, true)) {
                $kept[$key] = (string) $method_id;
            }
        }
        return $kept;
    }

    /**
     * @param array  $params
     * @param array  $option {id,displayName,amount}
     * @return array
     */
    public static function apply_fulfillment_option_to_stripe_params($params, array $option) {
        if (!is_array($params) || empty($option['id'])) {
            return is_array($params) ? $params : array();
        }
        if (isset($params['checkout']) && is_array($params['checkout'])) {
            $params['checkout']['default_shipping_option'] = $option;
        }
        if (!empty($params['cart']) && is_array($params['cart'])) {
            $params['cart']['shippingOptions'] = array($option);
        }
        if (!empty($params['product']) && is_array($params['product'])) {
            $params['product']['shippingOptions'] = array($option);
        }
        return $params;
    }

    public static function stripe_fulfillment_option($rate_id, $label, $amount = 0) {
        return array(
            'id'          => (string) $rate_id,
            'displayName' => (string) $label,
            'amount'      => (int) $amount,
        );
    }

    public function keep_membership_zero_fulfillment($rates, $package = array()) {
        unset($package);
        if (!is_array($rates) || !class_exists('Azure_Membership_Module')) {
            return $rates;
        }
        if (!self::keep_zero_fulfillment_for_types(Azure_Membership_Module::cart_membership_types())) {
            return $rates;
        }

        $method_ids = array();
        foreach ($rates as $key => $rate) {
            $method_ids[$key] = (is_object($rate) && method_exists($rate, 'get_method_id'))
                ? (string) $rate->get_method_id()
                : '';
        }
        $preferred = self::preferred_zero_rate_keys($method_ids);
        if (!empty($preferred)) {
            $kept = array();
            foreach ($preferred as $key => $method_id) {
                unset($method_id);
                $kept[$key] = $rates[$key];
            }
            return $kept;
        }

        if (class_exists('WC_Shipping_Rate')) {
            $id = 'free_shipping:membership';
            $rates[$id] = new WC_Shipping_Rate(
                $id,
                __('Free shipping', 'azure-plugin'),
                0,
                array(),
                'free_shipping'
            );
        }
        return $rates;
    }

    public function prefer_zero_fulfillment_method($method, $available, $package_key = 0) {
        unset($package_key);
        if (!class_exists('Azure_Membership_Module')) {
            return $method;
        }
        if (!self::keep_zero_fulfillment_for_types(Azure_Membership_Module::cart_membership_types())) {
            return $method;
        }
        if (!is_array($available)) {
            return $method;
        }
        foreach ($available as $rate_id => $rate) {
            $method_id = (is_object($rate) && method_exists($rate, 'get_method_id'))
                ? (string) $rate->get_method_id()
                : '';
            if (in_array($method_id, self::ZERO_METHODS, true)) {
                return (string) $rate_id;
            }
        }
        return $method;
    }

    public function filter_stripe_express_params($params) {
        $option = $this->selected_stripe_fulfillment_option();
        if (!$option) {
            return $params;
        }
        return self::apply_fulfillment_option_to_stripe_params($params, $option);
    }

    public function filter_stripe_product_data($data, $product = null) {
        unset($product);
        $option = $this->selected_stripe_fulfillment_option();
        if (!$option || !is_array($data)) {
            return $data;
        }
        $data['shippingOptions'] = array($option);
        return $data;
    }

    /**
     * @return array|null
     */
    private function selected_stripe_fulfillment_option() {
        if (!function_exists('WC')) {
            return null;
        }
        $wc = WC();
        if (!is_object($wc) || !isset($wc->cart) || !is_object($wc->cart) || !method_exists($wc->cart, 'get_shipping_packages')) {
            return null;
        }
        if (class_exists('Azure_Membership_Module')
            && !self::keep_zero_fulfillment_for_types(Azure_Membership_Module::cart_membership_types($wc->cart))) {
            return null;
        }

        $packages = $wc->cart->get_shipping_packages();
        if (empty($packages) && isset($wc->shipping) && is_object($wc->shipping) && method_exists($wc->shipping, 'get_packages')) {
            $packages = $wc->shipping->get_packages();
        }
        foreach ((array) $packages as $package) {
            $rates = isset($package['rates']) ? $package['rates'] : array();
            foreach ((array) $rates as $rate) {
                if (!is_object($rate) || !method_exists($rate, 'get_method_id')) {
                    continue;
                }
                if (!in_array((string) $rate->get_method_id(), self::ZERO_METHODS, true)) {
                    continue;
                }
                $id = method_exists($rate, 'get_id') ? $rate->get_id() : '';
                $label = method_exists($rate, 'get_label') ? $rate->get_label() : __('Free shipping', 'azure-plugin');
                $cost = method_exists($rate, 'get_cost') ? (float) $rate->get_cost() : 0.0;
                if ($id === '') {
                    continue;
                }
                return self::stripe_fulfillment_option($id, $label, (int) round($cost * 100));
            }
        }

        if (class_exists('Azure_Membership_Module')
            && self::keep_zero_fulfillment_for_types(Azure_Membership_Module::cart_membership_types($wc->cart))) {
            return self::stripe_fulfillment_option(
                'free_shipping:membership',
                __('Free shipping', 'azure-plugin'),
                0
            );
        }
        return null;
    }
}
