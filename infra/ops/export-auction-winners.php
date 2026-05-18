<?php
/**
 * Plugin Name: One-off — Export Auction Winners + Order Payment Status
 * Description: Single-use MU-plugin. Pulls every auction with status
 *              "ended" or "sold", joins to its WooCommerce winner order,
 *              and outputs a CSV (or JSON) backup with payment state.
 *
 *              Read-only — does NOT modify any data. Safe to re-run.
 *
 * Deploy to:   wp-content/mu-plugins/export-auction-winners.php
 * Trigger:
 *     GET /?pta_export_auction_winners=<TOKEN>            (CSV download, default)
 *     GET /?pta_export_auction_winners=<TOKEN>&format=json (JSON in browser)
 *
 *              The MU-plugin does NOT self-delete (unlike the destructive
 *              restart-lego-auction-31613.php) because you may want to re-run
 *              it after winners pay. Delete manually from wp-content/mu-plugins/
 *              when you're done.
 *
 * Replace TOKEN_PLACEHOLDER below before deploying.
 */

if (!defined('ABSPATH')) {
    return;
}

add_action('init', function () {
    if (empty($_GET['pta_export_auction_winners'])) {
        return;
    }

    $expected_token = 'TOKEN_PLACEHOLDER';
    $provided_token = (string) $_GET['pta_export_auction_winners'];
    if (!hash_equals($expected_token, $provided_token)) {
        status_header(403);
        nocache_headers();
        header('Content-Type: text/plain');
        echo 'forbidden';
        exit;
    }

    $format = isset($_GET['format']) ? strtolower((string) $_GET['format']) : 'csv';
    if (!in_array($format, array('csv', 'json'), true)) {
        $format = 'csv';
    }

    global $wpdb;
    $bids_table = $wpdb->prefix . 'azure_auction_bids';

    // 1. Find every auction product with status 'ended' or 'sold'.
    $product_ids = $wpdb->get_col("
        SELECT pm.post_id
        FROM {$wpdb->postmeta} pm
        JOIN {$wpdb->posts} p
          ON p.ID = pm.post_id
         AND p.post_type = 'product'
        WHERE pm.meta_key = '_auction_status'
          AND pm.meta_value IN ('ended','sold')
        ORDER BY pm.post_id
    ");

    $rows = array();

    foreach ($product_ids as $pid) {
        $pid = (int) $pid;
        $product = function_exists('wc_get_product') ? wc_get_product($pid) : null;
        $title   = $product ? $product->get_name() : get_the_title($pid);

        $auction_status   = get_post_meta($pid, '_auction_status', true);
        $ended_at         = get_post_meta($pid, '_auction_ended_at', true);
        $sold_at          = get_post_meta($pid, '_auction_sold_at', true);
        $winner_user_id   = (int) get_post_meta($pid, '_auction_winner_user_id', true);
        $winning_amount   = get_post_meta($pid, '_auction_winning_amount', true);
        $winner_order_id  = (int) get_post_meta($pid, '_auction_winner_order_id', true);
        $sold_order_id    = (int) get_post_meta($pid, '_auction_sold_order_id', true);
        $order_id         = $winner_order_id ?: $sold_order_id;

        // Canonical winner from the bids table (sanity check).
        $top = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id, bid_amount, created_at
             FROM {$bids_table}
             WHERE product_id = %d
             ORDER BY bid_amount DESC, created_at DESC
             LIMIT 1",
            $pid
        ));

        // Winner user details.
        $user_login = $user_email = $user_display = '';
        if ($winner_user_id) {
            $u = get_userdata($winner_user_id);
            if ($u) {
                $user_login   = $u->user_login;
                $user_email   = $u->user_email;
                $user_display = $u->display_name;
            }
        }

        // Order + payment via WC APIs (handles both classic CPT and HPOS).
        $order_status      = '';
        $order_total       = '';
        $paid_date         = '';
        $transaction_id    = '';
        $payment_method    = '';
        $payment_method_t  = '';
        $is_paid           = null;
        $payment_state     = 'NO ORDER CREATED';

        if ($order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order_status     = $order->get_status();         // without "wc-" prefix
                $order_total      = $order->get_total();
                $payment_method   = $order->get_payment_method();
                $payment_method_t = $order->get_payment_method_title();
                $transaction_id   = $order->get_transaction_id();

                $date_paid_obj = $order->get_date_paid();
                $paid_date     = $date_paid_obj ? $date_paid_obj->date('Y-m-d H:i:s') : '';

                // WC's own paid-status check (covers processing/completed and
                // any custom paid statuses registered via
                // woocommerce_order_is_paid_statuses filter).
                $paid_statuses = function_exists('wc_get_is_paid_statuses')
                    ? wc_get_is_paid_statuses()
                    : array('processing', 'completed');
                $is_paid = in_array($order_status, $paid_statuses, true)
                        || ($paid_date !== '');

                if ($is_paid) {
                    $payment_state = 'PAID';
                } elseif ($order_status === 'pending') {
                    $payment_state = 'NOT PAID (pending)';
                } elseif ($order_status === 'on-hold') {
                    $payment_state = 'NOT PAID (on hold)';
                } elseif ($order_status === 'cancelled') {
                    $payment_state = 'NOT PAID (cancelled)';
                } elseif ($order_status === 'failed') {
                    $payment_state = 'NOT PAID (failed)';
                } elseif ($order_status === 'refunded') {
                    $payment_state = 'REFUNDED';
                } else {
                    $payment_state = 'UNKNOWN (' . $order_status . ')';
                }
            } else {
                $payment_state = 'ORDER ID SET BUT ORDER MISSING (' . $order_id . ')';
            }
        }

        $rows[] = array(
            'product_id'              => $pid,
            'auction_title'           => $title,
            'auction_status'          => $auction_status,
            'ended_or_sold_at'        => $ended_at ?: $sold_at,
            'winner_user_id'          => $winner_user_id,
            'winner_username'         => $user_login,
            'winner_email'            => $user_email,
            'winner_name'             => $user_display,
            'winning_amount_cached'   => $winning_amount,
            'top_bid_from_table'      => $top ? $top->bid_amount : '',
            'top_bidder_from_table'   => $top ? (int) $top->user_id : '',
            'top_bid_at'              => $top ? $top->created_at : '',
            'order_id'                => $order_id ?: '',
            'order_status'            => $order_status,
            'order_total'             => $order_total,
            'payment_method'          => $payment_method,
            'payment_method_title'    => $payment_method_t,
            'transaction_id'          => $transaction_id,
            'paid_date'               => $paid_date,
            'payment_state'           => $payment_state,
        );
    }

    nocache_headers();

    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode(array(
            'generated_at' => current_time('mysql'),
            'count'        => count($rows),
            'rows'         => $rows,
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // CSV download.
    $filename = 'auction-winners-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    // Excel-friendly UTF-8 BOM
    fwrite($out, "\xEF\xBB\xBF");

    if (!empty($rows)) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
    } else {
        fputcsv($out, array('no_ended_or_sold_auctions_found'));
    }

    fclose($out);
    exit;
});
