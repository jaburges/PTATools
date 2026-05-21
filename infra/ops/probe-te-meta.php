<?php
/**
 * Plugin Name: One-off — Probe TE postmeta + force-flush cache
 * Description: READ + cache-flush only. Reads the
 *              _auction_te_{second,third}_* postmeta for known TE
 *              products directly from wp_postmeta, also reads via
 *              get_post_meta() to see if the cache is serving stale
 *              data, and on ?flush=1 invalidates W3TC + WP post cache
 *              for those products.
 *
 * Trigger: GET /?pta_probe_te_meta=<TOKEN>[&flush=1]
 */

if (!defined('ABSPATH')) {
    return;
}

add_action('init', function () {
    if (empty($_GET['pta_probe_te_meta'])) {
        return;
    }
    $expected_token = 'd4d4306c0c8ddc80312c3554aefbedbd';
    if (!hash_equals($expected_token, (string) $_GET['pta_probe_te_meta'])) {
        status_header(403);
        header('Content-Type: text/plain');
        echo 'forbidden';
        exit;
    }

    $flush = !empty($_GET['flush']);
    global $wpdb;
    $product_ids = array(31944, 31942, 31940, 31938, 31936);

    $keys = array(
        '_auction_te_second_user_id',
        '_auction_te_second_order_id',
        '_auction_te_second_amount',
        '_auction_te_second_created_at',
        '_auction_te_second_emailed_at',
        '_auction_te_third_user_id',
        '_auction_te_third_order_id',
        '_auction_te_third_amount',
        '_auction_te_third_created_at',
        '_auction_te_third_emailed_at',
    );

    $out = array('flush' => $flush, 'items' => array());

    foreach ($product_ids as $pid) {
        if ($flush) {
            wp_cache_delete($pid, 'post_meta');
            if (function_exists('clean_post_cache')) clean_post_cache($pid);
            if (function_exists('w3tc_flush_post')) @w3tc_flush_post($pid);
        }
        $item = array('product_id' => $pid, 'meta' => array());
        foreach ($keys as $k) {
            $db_val  = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
                $pid, $k
            ));
            $api_val = get_post_meta($pid, $k, true);
            $item['meta'][$k] = array(
                'db'      => $db_val,
                'wp_api'  => is_array($api_val) ? '(array)' : (string) $api_val,
                'match'   => ($db_val !== null) && ((string) $db_val === (string) $api_val),
            );
        }
        $out['items'][] = $item;
    }

    if ($flush) {
        if (function_exists('w3tc_objectcache_flush')) @w3tc_objectcache_flush();
        if (function_exists('w3tc_dbcache_flush'))     @w3tc_dbcache_flush();
        if (function_exists('wp_cache_flush_group'))   @wp_cache_flush_group('post_meta');
    }

    nocache_headers();
    header('Content-Type: application/json');
    echo wp_json_encode($out, JSON_PRETTY_PRINT);
    exit;
});
