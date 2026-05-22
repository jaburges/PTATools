<?php
/**
 * Plugin Name: One-off — Add offline bid (Denise Gentry, product 31942, $160)
 * Description: Records a $160 bid from Denise Gentry (Dk6k@yahoo.com) on
 *              auction product 31942, on behalf of an offline request from
 *              the PTSA president. Creates the WP user if they don't exist
 *              and sets their WC billing fields so the eventual Teacher
 *              Experience runner-up invoice email can be sent.
 *
 *              Idempotent — re-running won't insert a second bid.
 *              Self-deletes after a successful insertion.
 *
 * Deploy to:   wp-content/mu-plugins/add-offline-bid-31942-denise.php
 * Trigger:     GET /?pta_add_offline_bid_denise=<TOKEN>
 *              GET /?pta_add_offline_bid_denise=<TOKEN>&dryrun=1   (no insert)
 */

if (!defined('ABSPATH')) {
    return;
}

add_action('init', function () {
    if (empty($_GET['pta_add_offline_bid_denise'])) {
        return;
    }

    $expected_token = '4f4dccb153422a55bfe3122e357ea83580b2e5a59b7db4eb';
    $provided_token = (string) $_GET['pta_add_offline_bid_denise'];
    if (!hash_equals($expected_token, $provided_token)) {
        status_header(403);
        nocache_headers();
        header('Content-Type: text/plain');
        echo 'forbidden';
        exit;
    }

    $dryrun = !empty($_GET['dryrun']);

    $product_id = 31942;
    $email      = 'Dk6k@yahoo.com';
    $first_name = 'Denise';
    $last_name  = 'Gentry';
    $bid_amount = 160.00;

    $result = array(
        'ok'              => false,
        'dryrun'          => $dryrun,
        'product_id'      => $product_id,
        'product_title'   => '',
        'product_type'    => '',
        'is_te'           => false,
        'auction_status'  => '',
        'recorded_winner' => array(),
        'user_id'         => 0,
        'user_action'     => '',
        'bid_amount'      => $bid_amount,
        'bid_action'      => '',
        'bid_id'          => 0,
        'current_top_bid' => null,
        'rank_after'      => null,
        'top_bidders'     => array(),
        'self_deleted'    => false,
        'errors'          => array(),
    );

    $finish = function () use (&$result) {
        $result['ok'] = empty($result['errors']);
        nocache_headers();
        header('Content-Type: application/json');
        echo wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    };

    global $wpdb;
    $bids_table = $wpdb->prefix . 'azure_auction_bids';

    // -----------------------------------------------------------------
    // 1) Validate product
    // -----------------------------------------------------------------
    $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
    if (!$product) {
        $result['errors'][] = 'Product ' . $product_id . ' not found.';
        $finish();
    }
    $title = $product->get_name();
    $result['product_title']  = $title;
    $result['product_type']   = $product->get_type();
    $result['is_te']          = (strpos($title, 'Teacher Experience') === 0);
    $result['auction_status'] = (string) get_post_meta($product_id, '_auction_status', true);

    $winner_id = (int) get_post_meta($product_id, '_auction_winner_user_id', true);
    $winner_amt = get_post_meta($product_id, '_auction_winning_amount', true);
    $winner_order_id = (int) get_post_meta($product_id, '_auction_winner_order_id', true);
    $winner_user = $winner_id ? get_userdata($winner_id) : null;
    $result['recorded_winner'] = array(
        'user_id'        => $winner_id,
        'login'          => $winner_user ? $winner_user->user_login : '',
        'name'           => $winner_user ? $winner_user->display_name : '',
        'email'          => $winner_user ? $winner_user->user_email : '',
        'winning_amount' => $winner_amt !== '' ? (float) $winner_amt : null,
        'order_id'       => $winner_order_id,
    );

    // Teacher Experience items are usually WC "simple" products that carry
    // auction-style postmeta (_auction_status, _auction_winner_user_id, …)
    // rather than being WC "auction" product types. Don't hard-fail on type;
    // just verify there's auction-status meta so we know we're not on a
    // random product page.
    if (!in_array($product->get_type(), array('auction', 'simple'), true)) {
        $result['errors'][] = 'Unexpected product type=' . $product->get_type() . ' (expected auction or simple).';
        $finish();
    }
    if ($result['auction_status'] === '') {
        $result['errors'][] = 'Product has no _auction_status postmeta — not being managed as an auction.';
        $finish();
    }

    // -----------------------------------------------------------------
    // 2) Find or create user (by email — WP lookup is case-insensitive)
    // -----------------------------------------------------------------
    $user = get_user_by('email', $email);
    if ($user) {
        $user_id = (int) $user->ID;
        $result['user_action'] = 'existing';
    } else {
        if ($dryrun) {
            $result['user_action'] = 'would_create';
            $result['user_id'] = 0;
        } else {
            $local = strstr($email, '@', true) ?: 'user';
            $base  = sanitize_user($local, true);
            if (empty($base)) {
                $base = 'dgentry';
            }
            $username = $base;
            if (username_exists($username)) {
                $username = $base . '_' . wp_generate_password(4, false);
            }
            $password = wp_generate_password(24, true, true);
            $user_id = wp_create_user($username, $password, $email);
            if (is_wp_error($user_id)) {
                $result['errors'][] = 'wp_create_user failed: ' . $user_id->get_error_message();
                $finish();
            }
            wp_update_user(array(
                'ID'           => $user_id,
                'display_name' => trim($first_name . ' ' . $last_name),
                'first_name'   => $first_name,
                'last_name'    => $last_name,
            ));
            $result['user_action'] = 'created';
        }
    }

    if (!$dryrun || $user) {
        $result['user_id'] = (int) ($user_id ?? 0);
    }

    // -----------------------------------------------------------------
    // 3) Set WC billing fields so the future invoice email routes
    //    correctly (Azure_Auction_Emails reads $order->get_billing_email()).
    // -----------------------------------------------------------------
    if (!$dryrun && !empty($user_id)) {
        update_user_meta($user_id, 'billing_email',      $email);
        update_user_meta($user_id, 'billing_first_name', $first_name);
        update_user_meta($user_id, 'billing_last_name',  $last_name);
    }

    // -----------------------------------------------------------------
    // 4) Idempotency check + insert
    // -----------------------------------------------------------------
    if (!empty($user_id)) {
        $existing_bid_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$bids_table}
             WHERE product_id = %d AND user_id = %d AND bid_amount = %s
             LIMIT 1",
            $product_id,
            $user_id,
            number_format($bid_amount, 2, '.', '')
        ));

        if ($existing_bid_id > 0) {
            $result['bid_action'] = 'skipped_duplicate';
            $result['bid_id'] = $existing_bid_id;
        } elseif ($dryrun) {
            $result['bid_action'] = 'would_insert';
        } else {
            $inserted = $wpdb->insert($bids_table, array(
                'product_id'  => $product_id,
                'user_id'     => $user_id,
                'bid_amount'  => $bid_amount,
                'max_bid'     => null,
                'is_auto_bid' => 0,
                'ip_address'  => null,
            ), array('%d', '%d', '%f', '%f', '%d', '%s'));
            if ($inserted === false) {
                $result['errors'][] = 'wpdb->insert failed: ' . $wpdb->last_error;
                $finish();
            }
            $result['bid_action'] = 'inserted';
            $result['bid_id'] = (int) $wpdb->insert_id;
        }
    } else {
        $result['bid_action'] = 'skipped_no_user';
    }

    // -----------------------------------------------------------------
    // 5) Compute current top bid + Denise's rank + top-5 bidders snapshot
    // -----------------------------------------------------------------
    $top_bid = $wpdb->get_var($wpdb->prepare(
        "SELECT MAX(bid_amount) FROM {$bids_table} WHERE product_id = %d",
        $product_id
    ));
    $result['current_top_bid'] = $top_bid !== null ? (float) $top_bid : null;

    if (!empty($user_id)) {
        $rank = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM (
                 SELECT user_id, MAX(bid_amount) AS max_bid
                 FROM {$bids_table}
                 WHERE product_id = %d
                 GROUP BY user_id
                 HAVING max_bid >= %s
             ) t",
            $product_id,
            number_format($bid_amount, 2, '.', '')
        ));
        $result['rank_after'] = $rank;
    }

    $top_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT user_id, MAX(bid_amount) AS max_bid, MAX(created_at) AS last_bid_at
         FROM {$bids_table}
         WHERE product_id = %d
         GROUP BY user_id
         ORDER BY max_bid DESC, last_bid_at DESC
         LIMIT 5",
        $product_id
    ));
    $position = 0;
    foreach ($top_rows as $row) {
        $position++;
        $u = get_userdata((int) $row->user_id);
        $result['top_bidders'][] = array(
            'position'    => $position,
            'user_id'     => (int) $row->user_id,
            'login'       => $u ? $u->user_login : '',
            'name'        => $u ? $u->display_name : '',
            'email'       => $u ? $u->user_email : '',
            'max_bid'     => (float) $row->max_bid,
            'last_bid_at' => (string) $row->last_bid_at,
        );
    }

    // -----------------------------------------------------------------
    // 6) Log + self-delete on clean success
    // -----------------------------------------------------------------
    if (class_exists('Azure_Logger')) {
        Azure_Logger::info('[offline-bid] ' . wp_json_encode($result));
    }

    if (!$dryrun && empty($result['errors']) && $result['bid_action'] === 'inserted') {
        @unlink(__FILE__);
        $result['self_deleted'] = !file_exists(__FILE__);
    }

    $finish();
});
