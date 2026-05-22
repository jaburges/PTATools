<?php
/**
 * Plugin Name: One-off — Inspect payment metadata for specific orders
 * Description: READ-ONLY. Returns gateway slug + all payment-related
 *              postmeta for a comma-separated list of order IDs so we
 *              can see exactly which gateway processed each order and
 *              whether refund hooks are reachable.
 *
 * Trigger: GET /?pta_order_pay_inspect=<TOKEN>&ids=31899,31901
 */

if (!defined('ABSPATH')) {
    return;
}

add_action('init', function () {
    if (empty($_GET['pta_order_pay_inspect'])) {
        return;
    }

    $expected_token = 'd4d4306c0c8ddc80312c3554aefbedbd';
    $provided_token = (string) $_GET['pta_order_pay_inspect'];
    if (!hash_equals($expected_token, $provided_token)) {
        status_header(403);
        nocache_headers();
        header('Content-Type: text/plain');
        echo 'forbidden';
        exit;
    }

    if (!function_exists('wc_get_order')) {
        nocache_headers();
        header('Content-Type: application/json');
        echo wp_json_encode(array('error' => 'WC not loaded'), JSON_PRETTY_PRINT);
        exit;
    }

    $ids = array();
    if (!empty($_GET['ids'])) {
        foreach (explode(',', (string) $_GET['ids']) as $piece) {
            $piece = (int) trim($piece);
            if ($piece > 0) $ids[] = $piece;
        }
    }

    $out = array();
    foreach ($ids as $oid) {
        $order = wc_get_order($oid);
        if (!$order) {
            $out[$oid] = array('error' => 'order not found');
            continue;
        }

        $entry = array(
            'order_id'              => (int) $order->get_id(),
            'status'                => $order->get_status(),
            'total'                 => (float) $order->get_total(),
            'currency'              => $order->get_currency(),
            'payment_method'        => $order->get_payment_method(),
            'payment_method_title'  => $order->get_payment_method_title(),
            'transaction_id'        => $order->get_transaction_id(),
            'billing_email'         => $order->get_billing_email(),
            'billing_name'          => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'date_created'          => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : '',
            'date_paid'             => $order->get_date_paid() ? $order->get_date_paid()->date('Y-m-d H:i:s') : '',
        );

        // Pull gateway-specific postmeta keys that hint at where the
        // refund button should come from.
        $gateway_keys = array(
            // Stripe
            '_stripe_source_id', '_stripe_intent_id', '_stripe_charge_captured',
            '_stripe_fee', '_stripe_net', '_stripe_currency', '_stripe_customer_id',
            // Amazon Pay (classic + Advanced)
            'amazon_charge_id', 'amazon_charge_permission_id', 'amazon_authorization_id',
            'amazon_capture_id', 'amazon_refund_id',
            '_amazon_charge_id', '_amazon_charge_permission_id',
            // WC base
            '_payment_method', '_payment_method_title', '_transaction_id',
        );
        $gateway_meta = array();
        foreach ($gateway_keys as $mk) {
            $v = $order->get_meta($mk, true);
            if ($v !== '' && $v !== null) {
                $gateway_meta[$mk] = (string) $v;
            }
        }
        $entry['gateway_meta'] = $gateway_meta;

        // Which refund APIs are reachable for this order's gateway?
        $gateway_slug = $order->get_payment_method();
        $available_gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
        $gateway = isset($available_gateways[$gateway_slug]) ? $available_gateways[$gateway_slug] : null;
        $entry['gateway_supports_refunds'] = $gateway ? $gateway->supports('refunds') : false;
        $entry['gateway_class']            = $gateway ? get_class($gateway) : null;

        $out[$oid] = $entry;
    }

    nocache_headers();
    header('Content-Type: application/json');
    echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
});
