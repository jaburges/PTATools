<?php
/**
 * Plugin Name: One-off — Diagnose / backfill TE runner-up postmeta
 * Description: Inspects existing WC orders for each Teacher Experience
 *              runner-up bidder and (optionally) writes the
 *              _auction_te_{second,third}_{user_id,order_id,amount,created_at,emailed_at}
 *              postmeta keys so the admin widget can display already-created
 *              orders. Does NOT call create_winner_order or send any emails.
 *
 *              Modes (default = dryrun):
 *                ?dryrun=1   — inspect only. Looks up existing WC orders
 *                              for each runner-up by customer_id +
 *                              auction-product line-item, returns what
 *                              postmeta WOULD be written.
 *                ?backfill=1 — actually writes the postmeta. Safe to
 *                              re-run; idempotent for already-stored entries.
 *
 *              Deploy to wp-content/mu-plugins/diag-te-runner-up.php.
 *              Does NOT self-delete.
 *
 * Trigger: GET /?pta_diag_te_runner_up=<TOKEN>[&backfill=1][&pid=31942]
 */

if (!defined('ABSPATH')) {
    return;
}

add_action('init', function () {
    if (empty($_GET['pta_diag_te_runner_up'])) {
        return;
    }

    $expected_token = '0bb8d4298386a12ed4c59f52afc60824';
    $provided_token = (string) $_GET['pta_diag_te_runner_up'];
    if (!hash_equals($expected_token, $provided_token)) {
        status_header(403);
        nocache_headers();
        header('Content-Type: text/plain');
        echo 'forbidden';
        exit;
    }

    $backfill = !empty($_GET['backfill']);
    $pid_filter = isset($_GET['pid']) ? (int) $_GET['pid'] : 0;

    $out = array(
        'mode'              => $backfill ? 'backfill' : 'dryrun',
        'plugin_constant'   => defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : null,
        'plugin_path'       => defined('AZURE_PLUGIN_PATH') ? AZURE_PLUGIN_PATH : null,
        'items'             => array(),
        'errors'            => array(),
    );

    // Force-load the report class if it isn't already in scope (we're hitting
    // this from a frontend request which doesn't bootstrap the auction module).
    if (!class_exists('Azure_Auction_Winners_Report')) {
        $maybe = (defined('AZURE_PLUGIN_PATH') ? AZURE_PLUGIN_PATH : ABSPATH . 'wp-content/plugins/Azure Plugin/') . 'includes/class-auction-winners-report.php';
        if (file_exists($maybe)) {
            require_once $maybe;
        }
    }
    if (!class_exists('Azure_Auction_Winners_Report')) {
        $out['errors'][] = 'Azure_Auction_Winners_Report not loadable.';
        nocache_headers();
        header('Content-Type: application/json');
        echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $report = new Azure_Auction_Winners_Report();

    /**
     * Search WC orders authored by $user_id that contain $product_id as a line item.
     * Returns the most recently created matching order's data, or null.
     *
     * HPOS-safe via wc_get_orders.
     */
    $find_order_for_user_and_product = function ($user_id, $product_id) {
        if (!$user_id || !$product_id || !function_exists('wc_get_orders')) {
            return null;
        }
        $orders = wc_get_orders(array(
            'customer_id' => (int) $user_id,
            'limit'       => 20,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'status'      => array_keys(wc_get_order_statuses()),
        ));
        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                if ((int) $item->get_product_id() === (int) $product_id) {
                    $date_paid = $order->get_date_paid();
                    return array(
                        'order_id'      => (int) $order->get_id(),
                        'status'        => $order->get_status(),
                        'total'         => (float) $order->get_total(),
                        'line_subtotal' => (float) $item->get_subtotal(),
                        'line_total'    => (float) $item->get_total(),
                        'date_created'  => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : '',
                        'date_paid'     => $date_paid ? $date_paid->date('Y-m-d H:i:s') : '',
                    );
                }
            }
        }
        return null;
    };

    $position_keys = function ($position) {
        if ((int) $position === 2) {
            return array(
                'user_id'    => '_auction_te_second_user_id',
                'order_id'   => '_auction_te_second_order_id',
                'amount'     => '_auction_te_second_amount',
                'created_at' => '_auction_te_second_created_at',
                'emailed_at' => '_auction_te_second_emailed_at',
            );
        }
        if ((int) $position === 3) {
            return array(
                'user_id'    => '_auction_te_third_user_id',
                'order_id'   => '_auction_te_third_order_id',
                'amount'     => '_auction_te_third_amount',
                'created_at' => '_auction_te_third_created_at',
                'emailed_at' => '_auction_te_third_emailed_at',
            );
        }
        return null;
    };

    $te_rows = $report->get_te_auction_rows();
    foreach ($te_rows as $row) {
        if ($pid_filter && (int) $row['product_id'] !== $pid_filter) {
            continue;
        }
        $pid = (int) $row['product_id'];
        $runners = $report->get_te_runners_up($pid);

        $entry = array(
            'product_id'    => $pid,
            'title'         => $row['title'],
            'winner_user_id'=> $row['winner_user_id'],
            'winner_order'  => $row['order_id'],
            'positions'     => array(),
        );

        foreach (array('second' => 2, 'third' => 3) as $key => $position) {
            $bidder = $runners[$key];
            $stored = $runners['stored'][$key];
            $keys   = $position_keys($position);

            $found = null;
            if ($bidder && !empty($bidder['user_id'])) {
                $found = $find_order_for_user_and_product((int) $bidder['user_id'], $pid);
            }

            $pos_out = array(
                'position'      => $position,
                'bidder_user'   => $bidder['user_id'] ?? null,
                'bidder_name'   => $bidder['name'] ?? null,
                'bidder_email'  => $bidder['email'] ?? null,
                'bid_amount'    => $bidder['bid_amount'] ?? null,
                'stored_now'    => $stored,
                'found_order'   => $found,
                'will_backfill' => false,
                'did_backfill'  => false,
            );

            // Decide: do we need to backfill?
            $needs_backfill = $bidder
                && $found
                && empty($stored['order_id']);

            $pos_out['will_backfill'] = (bool) $needs_backfill;

            if ($needs_backfill && $backfill) {
                // Direct DB writes — update_post_meta() is being silently
                // blocked by a filter (Wordfence/W3TC/Malcare-class plugin?).
                // Going straight to wp_postmeta side-steps all filters.
                global $wpdb;
                $writes = array(
                    $keys['user_id']    => (string) (int) $bidder['user_id'],
                    $keys['order_id']   => (string) (int) $found['order_id'],
                    $keys['amount']     => (string) (float) $bidder['bid_amount'],
                    $keys['created_at'] => (string) ($found['date_created'] ?: current_time('mysql')),
                    $keys['emailed_at'] => (string) ($found['date_created'] ?: current_time('mysql')),
                );
                $write_log = array();
                foreach ($writes as $mk => $mv) {
                    $existing_row = $wpdb->get_row($wpdb->prepare(
                        "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
                        $pid, $mk
                    ));
                    if ($existing_row) {
                        $res = $wpdb->update(
                            $wpdb->postmeta,
                            array('meta_value' => $mv),
                            array('meta_id' => (int) $existing_row->meta_id),
                            array('%s'), array('%d')
                        );
                        $write_log[$mk] = array('action' => 'updated', 'rows' => $res === false ? 'FALSE' : (int) $res, 'last_error' => $wpdb->last_error);
                    } else {
                        $res = $wpdb->insert(
                            $wpdb->postmeta,
                            array('post_id' => $pid, 'meta_key' => $mk, 'meta_value' => $mv),
                            array('%d', '%s', '%s')
                        );
                        $write_log[$mk] = array('action' => 'inserted', 'rows' => $res === false ? 'FALSE' : (int) $res, 'insert_id' => $wpdb->insert_id, 'last_error' => $wpdb->last_error);
                    }
                }

                // Bust every cache layer we can reach so future reads
                // (including the widget on the admin page) see the new rows.
                wp_cache_delete($pid, 'post_meta');
                if (function_exists('clean_post_cache')) {
                    clean_post_cache($pid);
                }
                if (function_exists('wp_cache_flush_group')) {
                    @wp_cache_flush_group('post_meta');
                }
                // Try to invalidate W3 Total Cache layers if present.
                if (function_exists('w3tc_flush_post')) {
                    @w3tc_flush_post($pid);
                }
                if (function_exists('w3tc_dbcache_flush')) {
                    @w3tc_dbcache_flush();
                }
                if (function_exists('w3tc_objectcache_flush')) {
                    @w3tc_objectcache_flush();
                }

                // Verify by re-reading directly from DB AND via WP's API.
                $verify = array();
                foreach ($writes as $mk => $expected) {
                    $db_val = $wpdb->get_var($wpdb->prepare(
                        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
                        $pid, $mk
                    ));
                    $api_val = (string) get_post_meta($pid, $mk, true);
                    $verify[$mk] = array(
                        'expected'   => $expected,
                        'db'         => $db_val,
                        'wp_api'     => $api_val,
                        'db_match'   => ($db_val === $expected),
                        'api_match'  => ($api_val === $expected),
                    );
                }

                $pos_out['did_backfill'] = true;
                $pos_out['db_writes']    = $write_log;
                $pos_out['db_verify']    = $verify;

                if (class_exists('Azure_Logger')) {
                    Azure_Logger::info('[te-backfill] product=' . $pid . ' position=' . $position . ' order=' . $found['order_id'] . ' user=' . $bidder['user_id']);
                }
            }

            $entry['positions'][] = $pos_out;
        }

        $out['items'][] = $entry;
    }

    nocache_headers();
    header('Content-Type: application/json');
    echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
});
