<?php
/**
 * Stripe for Tap to Pay: Terminal connection tokens and card-present
 * PaymentIntents, on the site's own Stripe account.
 *
 * It uses the secret key the WooCommerce Stripe Gateway already holds
 * (live or test, following the gateway's test mode), so each PTA's
 * payments go straight to its own Stripe account and no new key is
 * stored. The Terminal location (Stripe Dashboard → Terminal →
 * Locations) is set under WooCommerce → Settings → Products → Tap to Pay.
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_POS_Stripe {

    const API = 'https://api.stripe.com/v1/';
    const API_VERSION = '2024-06-20';

    const OPTION_LOCATION = 'pta_pos_location_id';

    /** Stripe amounts for these are whole units, not cents. */
    const ZERO_DECIMAL = array('bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf');

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('woocommerce_get_sections_products', array($this, 'add_section'));
        add_filter('woocommerce_get_settings_products', array($this, 'settings'), 10, 2);
    }

    public static function location_id() {
        $id = trim((string) get_option(self::OPTION_LOCATION, ''));
        return preg_match('/^tml_[A-Za-z0-9]+$/', $id) ? $id : '';
    }

    /** Whether the gateway is in test mode. */
    public static function test_mode() {
        $settings = (array) get_option('woocommerce_stripe_settings', array());
        return ($settings['testmode'] ?? 'no') === 'yes';
    }

    public static function secret_key() {
        if (class_exists('WC_Stripe_API') && method_exists('WC_Stripe_API', 'get_secret_key')) {
            $key = (string) WC_Stripe_API::get_secret_key();
            if ($key !== '') {
                return $key;
            }
        }
        $settings = (array) get_option('woocommerce_stripe_settings', array());
        return (string) (self::test_mode() ? ($settings['test_secret_key'] ?? '') : ($settings['secret_key'] ?? ''));
    }

    /** Tap to Pay is set up: a Stripe key and a Terminal location. */
    public static function ready() {
        return self::secret_key() !== '' && self::location_id() !== '';
    }

    public static function to_minor($amount, $currency) {
        $factor = in_array(strtolower((string) $currency), self::ZERO_DECIMAL, true) ? 1 : 100;
        return (int) round(((float) $amount) * $factor);
    }

    public static function connection_token() {
        return self::request('POST', 'terminal/connection_tokens', array('location' => self::location_id()));
    }

    /**
     * @param array<string,string|int> $metadata
     */
    public static function create_intent($amount_minor, $currency, $description, array $metadata) {
        return self::request('POST', 'payment_intents', array(
            'amount'               => (int) $amount_minor,
            'currency'             => strtolower((string) $currency),
            'payment_method_types' => array('card_present'),
            'capture_method'       => 'automatic',
            'description'          => $description,
            'metadata'             => $metadata,
        ));
    }

    public static function retrieve_intent($id) {
        return self::request('GET', 'payment_intents/' . rawurlencode($id), array('expand' => array('latest_charge')));
    }

    public static function capture_intent($id) {
        return self::request('POST', 'payment_intents/' . rawurlencode($id) . '/capture', array());
    }

    public static function cancel_intent($id) {
        return self::request('POST', 'payment_intents/' . rawurlencode($id) . '/cancel', array());
    }

    /**
     * @return array|WP_Error Stripe's object, or an error carrying Stripe's message.
     */
    public static function request($method, $path, array $params) {
        $key = self::secret_key();
        if ($key === '') {
            return new WP_Error('ptsa_pos_no_stripe', 'Stripe isn\'t connected on the website (WooCommerce → Settings → Payments → Stripe).', array('status' => 503));
        }
        $query = self::encode($params);
        $url = self::API . $path;
        $args = array(
            'method'  => $method,
            'timeout' => 30,
            'headers' => array(
                'Authorization'  => 'Bearer ' . $key,
                'Stripe-Version' => self::API_VERSION,
                'Content-Type'   => 'application/x-www-form-urlencoded',
            ),
        );
        if ($method === 'GET') {
            $url .= $query !== '' ? '?' . $query : '';
        } else {
            $args['body'] = $query;
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return new WP_Error('ptsa_pos_stripe_unreachable', 'Couldn\'t reach Stripe. Check the connection and try again.', array('status' => 502));
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code >= 200 && $code < 300 && is_array($body)) {
            return $body;
        }
        $message = is_array($body) && !empty($body['error']['message']) ? (string) $body['error']['message'] : 'Stripe refused the request.';
        if (class_exists('Azure_Logger')) {
            Azure_Logger::error('Tap to Pay: Stripe ' . $method . ' ' . $path . ' failed (' . $code . '): ' . $message, array('module' => 'POS'));
        }
        return new WP_Error('ptsa_pos_stripe', $message, array('status' => 502));
    }

    /** Stripe's form encoding: metadata[key]=v, payment_method_types[]=v. */
    public static function encode(array $params, $prefix = '') {
        $parts = array();
        foreach ($params as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '[' . (is_int($key) ? '' : $key) . ']';
            if (is_array($value)) {
                $nested = self::encode($value, $name);
                if ($nested !== '') {
                    $parts[] = $nested;
                }
            } elseif ($value !== null) {
                $parts[] = rawurlencode($name) . '=' . rawurlencode(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            }
        }
        return implode('&', $parts);
    }

    // ── Settings: WooCommerce → Settings → Products → Tap to Pay ─────

    public function add_section($sections) {
        $sections['pta_pos'] = __('Tap to Pay', 'azure-plugin');
        return $sections;
    }

    public function settings($settings, $section) {
        if ($section !== 'pta_pos') {
            return $settings;
        }
        $key = self::secret_key();
        $status = $key === ''
            ? __('Stripe isn\'t connected yet. Connect it under WooCommerce → Settings → Payments → Stripe.', 'azure-plugin')
            : (self::test_mode() ? __('Using the Stripe gateway\'s test keys (test mode is on).', 'azure-plugin') : __('Using the Stripe gateway\'s live keys.', 'azure-plugin'));
        return array(
            array(
                'title' => __('Tap to Pay in the PTSA Board app', 'azure-plugin'),
                'type'  => 'title',
                'desc'  => __('Volunteers take card and phone payments at events on their own phones. Payments go to the Stripe account the shop already uses.', 'azure-plugin') . ' ' . $status,
                'id'    => 'pta_pos_settings',
            ),
            array(
                'title'       => __('Stripe Terminal location ID', 'azure-plugin'),
                'desc'        => __('Starts with tml_. Create one in the Stripe Dashboard under Terminal → Locations, with the school\'s address. Leave empty to turn Tap to Pay off.', 'azure-plugin'),
                'id'          => self::OPTION_LOCATION,
                'type'        => 'text',
                'placeholder' => 'tml_…',
                'desc_tip'    => false,
            ),
            array('type' => 'sectionend', 'id' => 'pta_pos_settings'),
        );
    }
}
