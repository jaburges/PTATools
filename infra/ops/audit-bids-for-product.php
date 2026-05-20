<?php
/**
 * Plugin Name: One-off — Audit auction bids for a single product
 * Description: READ-ONLY. Given a product slug or numeric ID, dumps every
 *              row from the auction bids table for that product plus the
 *              postmeta + lifecycle order state. Used to investigate
 *              "was this auction correctly processed?" questions for the
 *              PTSA admin.
 *
 *              Defaults to the slug requested by the admin today:
 *              class-project-paper-art-wolf-johnson.
 *
 * Trigger:  GET /?pta_audit_bids=<TOKEN>[&slug=...|&id=NNN]
 *
 * Does NOT modify any data. Does NOT self-delete (re-run as needed).
 */

if (!defined('ABSPATH')) {
    return;
}

add_action('init', function () {
    if (empty($_GET['pta_audit_bids'])) {
        return;
    }
    $expected = 'd4d4306c0c8ddc80312c3554aefbedbd';
    if (!hash_equals($expected, (string) $_GET['pta_audit_bids'])) {
        status_header(403);
        header('Content-Type: text/plain');
        echo 'forbidden';
        exit;
    }

    $slug = isset($_GET['slug']) ? sanitize_title((string) $_GET['slug']) : 'class-project-paper-art-wolf-johnson';
    $id   = isset($_GET['id'])   ? (int) $_GET['id'] : 0;

    if ($id === 0) {
        $p = get_page_by_path($slug, OBJECT, 'product');
        $id = $p ? (int) $p->ID : 0;
    }

    $out = array(
        'as_of'      => current_time('mysql'),
        'slug'       => $slug,
        'product_id' => $id,
        'errors'     => array(),
    );

    if (!$id) {
        $out['errors'][] = "No product found for slug '{$slug}'.";
        respond($out);
    }

    $product = function_exists('wc_get_product') ? wc_get_product($id) : null;
    $out['product'] = array(
        'id'             => $id,
        'title'          => get_the_title($id),
        'type'           => $product ? $product->get_type() : '?',
        'status'         => get_post_status($id),
        'regular_price'  => $product ? $product->get_regular_price() : null,
    );

    // Auction metadata
    $meta_keys = array(
        '_auction_status',
        '_auction_bidding_end',
        '_auction_ended_at',
        '_auction_winner_user_id',
        '_auction_winning_amount',
        '_auction_winner_order_id',
        '_auction_sold_at',
        '_auction_sold_order_id',
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
        '_auction_buy_it_now_enabled',
        '_auction_buy_it_now_price',
    );
    $meta = array();
    foreach ($meta_keys as $k) {
        $v = get_post_meta($id, $k, true);
        if ($v !== '' && $v !== null) {
            $meta[$k] = is_array($v) ? wp_json_encode($v) : (string) $v;
        }
    }
    $out['postmeta'] = $meta;

    // Resolve referenced user IDs to logins + emails
    foreach (array(
        'winner'  => '_auction_winner_user_id',
        'sold_by' => '_auction_sold_order_id',
        'te2'     => '_auction_te_second_user_id',
        'te3'     => '_auction_te_third_user_id',
    ) as $label => $mk) {
        $uid = (int) get_post_meta($id, $mk, true);
        if ($uid > 0) {
            $u = get_userdata($uid);
            $out['users'][$label] = $u ? array(
                'id'    => $uid,
                'login' => $u->user_login,
                'email' => $u->user_email,
                'name'  => $u->display_name,
            ) : array('id' => $uid, 'note' => 'user not found');
        }
    }

    // Resolve orders
    $order_ids_to_show = array(
        'winner_order'    => (int) get_post_meta($id, '_auction_winner_order_id', true),
        'sold_order'      => (int) get_post_meta($id, '_auction_sold_order_id', true),
        'te_second_order' => (int) get_post_meta($id, '_auction_te_second_order_id', true),
        'te_third_order'  => (int) get_post_meta($id, '_auction_te_third_order_id', true),
    );
    foreach ($order_ids_to_show as $label => $oid) {
        if ($oid <= 0 || !function_exists('wc_get_order')) continue;
        $o = wc_get_order($oid);
        if (!$o) {
            $out['orders'][$label] = array('order_id' => $oid, 'note' => 'order not found');
            continue;
        }
        $u = $o->get_customer_id() ? get_userdata($o->get_customer_id()) : null;
        $date_paid = $o->get_date_paid();
        $out['orders'][$label] = array(
            'order_id'        => (int) $o->get_id(),
            'status'          => $o->get_status(),
            'total'           => (float) $o->get_total(),
            'customer_id'     => (int) $o->get_customer_id(),
            'customer_login'  => $u ? $u->user_login : '',
            'billing_email'   => $o->get_billing_email(),
            'billing_name'    => trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()),
            'payment_method'  => $o->get_payment_method_title(),
            'date_created'    => $o->get_date_created() ? $o->get_date_created()->date('Y-m-d H:i:s') : '',
            'date_paid'       => $date_paid ? $date_paid->date('Y-m-d H:i:s') : '',
            'transaction_id'  => (string) $o->get_transaction_id(),
            'invoice_resent'  => (string) $o->get_meta('_azure_last_invoice_resent_at', true),
        );
    }

    // ── Raw bids table ─────────────────────────────────────────────────
    global $wpdb;
    $table = class_exists('Azure_Database') ? Azure_Database::get_table_name('auction_bids') : ($wpdb->prefix . 'azure_auction_bids');
    if (!$table) {
        $out['errors'][] = 'auction_bids table name not resolvable.';
        respond($out);
    }
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, product_id, user_id, bid_amount, max_bid, is_auto_bid, ip_address, created_at
         FROM {$table}
         WHERE product_id = %d
         ORDER BY created_at ASC, id ASC",
        $id
    ));
    $bids = array();
    foreach ($rows as $r) {
        $u = get_userdata((int) $r->user_id);
        $bids[] = array(
            'id'          => (int) $r->id,
            'created_at'  => (string) $r->created_at,
            'user_id'     => (int) $r->user_id,
            'login'       => $u ? $u->user_login : '',
            'email'       => $u ? $u->user_email : '',
            'name'        => $u ? $u->display_name : '',
            'bid_amount'  => (float) $r->bid_amount,
            'max_bid'     => $r->max_bid === null ? null : (float) $r->max_bid,
            'is_auto_bid' => (int) $r->is_auto_bid,
            'ip_address'  => $r->ip_address,
        );
    }
    $out['bids_total_count'] = count($bids);
    $out['bids'] = $bids;

    // Top distinct bidders, max-per-user
    $by_user = array();
    foreach ($bids as $b) {
        $uid = (int) $b['user_id'];
        if ($uid === 0) continue;
        if (!isset($by_user[$uid]) || $b['bid_amount'] > $by_user[$uid]['max_bid']) {
            $by_user[$uid] = array(
                'user_id'     => $uid,
                'login'       => $b['login'],
                'email'       => $b['email'],
                'name'        => $b['name'],
                'max_bid'     => $b['bid_amount'],
                'last_bid_at' => $b['created_at'],
                'bid_count'   => isset($by_user[$uid]) ? $by_user[$uid]['bid_count'] + 1 : 1,
            );
        } else {
            $by_user[$uid]['bid_count']++;
            if (strcmp($b['created_at'], $by_user[$uid]['last_bid_at']) > 0) {
                $by_user[$uid]['last_bid_at'] = $b['created_at'];
            }
        }
    }
    usort($by_user, function ($a, $b) {
        if ($b['max_bid'] === $a['max_bid']) {
            return strcmp($a['last_bid_at'], $b['last_bid_at']);
        }
        return $b['max_bid'] <=> $a['max_bid'];
    });
    $out['distinct_bidders_ranked'] = $by_user;

    respond($out);
});

function respond($out) {
    nocache_headers();
    header('Content-Type: application/json');
    echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
