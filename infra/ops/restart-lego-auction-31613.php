<?php
/**
 * Plugin Name: One-off — Restart Lego Color Your World Basket auction (31613)
 * Description: Single-use MU-plugin. Wipes bids, clears "ended" state, sets
 *              starting bid to $300, and self-deletes after running. Trigger
 *              once via:
 *                  GET /?pta_restart_auction_31613=<TOKEN>
 *              The pending Stripe-paid winner order #31911 is NOT touched —
 *              site admin refunds it manually via WooCommerce.
 *
 * Deploy to:   wp-content/mu-plugins/restart-lego-auction-31613.php
 * After successful run the file unlinks itself, so it is safe to leave on the
 * server until triggered.
 */

if (!defined('ABSPATH')) {
    return;
}

add_action('init', function () {
    if (empty($_GET['pta_restart_auction_31613'])) {
        return;
    }

    $expected_token = 'TOKEN_PLACEHOLDER';
    $provided_token = (string) $_GET['pta_restart_auction_31613'];
    if (!hash_equals($expected_token, $provided_token)) {
        status_header(403);
        nocache_headers();
        header('Content-Type: text/plain');
        echo 'forbidden';
        exit;
    }

    $product_id        = 31613;
    $new_starting_bid  = '300';
    $expected_end_meta = '2026-05-17 19:00:00';

    global $wpdb;
    $bids_table = $wpdb->prefix . 'azure_auction_bids';

    $result = array(
        'ok'                 => false,
        'product_id'         => $product_id,
        'deleted_bids'       => 0,
        'cleared_meta_keys'  => array(),
        'starting_bid'       => null,
        'bidding_end'        => null,
        'self_deleted'       => false,
        'errors'             => array(),
    );

    $bid_count_before = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$bids_table} WHERE product_id = %d",
        $product_id
    ));
    $result['bid_count_before'] = $bid_count_before;

    $deleted = $wpdb->delete($bids_table, array('product_id' => $product_id), array('%d'));
    if ($deleted === false) {
        $result['errors'][] = 'wpdb->delete failed: ' . $wpdb->last_error;
    } else {
        $result['deleted_bids'] = (int) $deleted;
    }

    $clear_keys = array(
        '_auction_status',
        '_auction_ended_at',
        '_auction_winner_user_id',
        '_auction_winning_amount',
        '_auction_winner_order_id',
    );
    foreach ($clear_keys as $key) {
        if (delete_post_meta($product_id, $key)) {
            $result['cleared_meta_keys'][] = $key;
        }
    }

    $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
    if ($product instanceof WC_Product) {
        $product->set_regular_price($new_starting_bid);
        $product->set_price($new_starting_bid);
        $product->save();
        $result['starting_bid'] = $product->get_regular_price();
    } else {
        update_post_meta($product_id, '_regular_price', $new_starting_bid);
        update_post_meta($product_id, '_price', $new_starting_bid);
        $result['starting_bid'] = $new_starting_bid;
        $result['errors'][] = 'wc_get_product unavailable; updated meta directly';
    }

    $current_end = get_post_meta($product_id, '_auction_bidding_end', true);
    $result['bidding_end'] = $current_end;
    if ($current_end !== $expected_end_meta) {
        $result['errors'][] = sprintf(
            'Bidding end is "%s", expected "%s" — not modified by this script.',
            $current_end,
            $expected_end_meta
        );
    }

    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients($product_id);
    }
    clean_post_cache($product_id);

    if (class_exists('Azure_Logger')) {
        Azure_Logger::info('[lego-auction-restart] ' . wp_json_encode($result));
    }

    $self = __FILE__;
    @unlink($self);
    $result['self_deleted'] = !file_exists($self);

    $result['ok'] = empty($result['errors']) && $result['self_deleted'];

    nocache_headers();
    header('Content-Type: application/json');
    echo wp_json_encode($result, JSON_PRETTY_PRINT);
    exit;
});
