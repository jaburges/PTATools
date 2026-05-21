<?php
/**
 * Auction lifecycle - end auction, determine winner, create order, send email.
 *
 * Two entry paths:
 *   1. Natural end via the per-auction one-shot cron (`azure_auction_finalize`)
 *      scheduled at the auction's bidding-end timestamp.
 *   2. Buy It Now via the AJAX endpoint in Azure_Auction_Module.
 *
 * Both paths produce the same artifact: a `wc-pending` WooCommerce order for
 * the winner with a payment link, plus an email through Azure_Auction_Emails.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Auction_Lifecycle {

    /** Daily safety-net cron query batch size. */
    const ORPHAN_BATCH_SIZE = 50;

    /**
     * If auction end time has passed and not yet processed, set ended,
     * determine winner, create order, send email.
     *
     * Called from:
     *   - the per-auction one-shot cron (`azure_auction_finalize`)
     *   - the daily safety-net cron (`azure_auction_finalize_orphans`)
     *
     * Idempotent. Atomic on the "claim this auction" step so two simultaneous
     * callers (cron + admin viewer, two cron runs racing) can't both create
     * orders.
     *
     * @param int $product_id
     * @return void
     */
    public function maybe_process_ended_auction($product_id) {
        $product = wc_get_product($product_id);
        if (!$product || $product->get_type() !== 'auction') {
            return;
        }
        if ($product->get_auction_status() === 'sold') {
            return;
        }
        if (!$product->is_auction_ended()) {
            return;
        }
        if ($product->get_auction_status() === 'ended') {
            return;
        }

        // Atomic claim: add_post_meta() with $unique=true returns false if a
        // row already exists. This makes the "I'm processing this auction"
        // step race-safe between concurrent callers without needing a
        // transaction.
        $claimed = add_post_meta($product_id, '_auction_status', 'ended', true);
        if (!$claimed) {
            return;
        }
        update_post_meta($product_id, '_auction_ended_at', current_time('mysql'));

        $high = $this->get_high_bid_row($product_id);
        if (!$high) {
            // No bids — leave the auction marked 'ended' with no order/email.
            if (class_exists('Azure_Logger')) {
                Azure_Logger::info('Auction ended with no bids', array('product_id' => $product_id));
            }
            return;
        }

        $winner_user_id = (int) $high->user_id;
        $winning_amount = (float) $high->bid_amount;
        update_post_meta($product_id, '_auction_winner_user_id', $winner_user_id);
        update_post_meta($product_id, '_auction_winning_amount', $winning_amount);

        $order = $this->create_winner_order($product_id, $winner_user_id, $winning_amount);
        if ($order) {
            update_post_meta($product_id, '_auction_winner_order_id', $order->get_id());
            $this->send_payment_email($order, $product_id, $winning_amount);
        }
    }

    /**
     * Cron entry point: per-auction one-shot finalize.
     * Bound to `azure_auction_finalize` in Azure_Auction_Module.
     *
     * @param int $product_id
     */
    public function cron_finalize($product_id) {
        try {
            $this->maybe_process_ended_auction((int) $product_id);
        } catch (\Throwable $e) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::error('Auction cron_finalize error: ' . $e->getMessage(), array(
                    'module' => 'Auction',
                    'product_id' => $product_id,
                ));
            }
        }
    }

    /**
     * Daily safety-net: finalize any auctions whose end time has passed but
     * never got their per-auction one-shot event (legacy data, lost cron rows,
     * env restores). Bounded so a backlog can't time out a single tick.
     *
     * Bound to `azure_auction_finalize_orphans` in Azure_Auction_Module.
     */
    public function finalize_orphans() {
        try {
            $orphans = $this->find_orphan_auction_ids(self::ORPHAN_BATCH_SIZE);
            foreach ($orphans as $product_id) {
                $this->maybe_process_ended_auction((int) $product_id);
            }
            if (!empty($orphans) && class_exists('Azure_Logger')) {
                Azure_Logger::info('Auction orphan sweep finalized ' . count($orphans) . ' auctions', array(
                    'module' => 'Auction',
                ));
            }
        } catch (\Throwable $e) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::error('Auction finalize_orphans error: ' . $e->getMessage(), array('module' => 'Auction'));
            }
        }
    }

    /**
     * Find auction product IDs whose bidding end has passed and that are not
     * yet marked sold/ended. Single indexed postmeta query.
     *
     * @param int $limit
     * @return array<int>
     */
    private function find_orphan_auction_ids($limit) {
        global $wpdb;
        $now_mysql = current_time('mysql');
        $now_ts    = (int) current_time('timestamp');

        // Two clauses because _auction_bidding_end is stored either as a
        // mysql datetime ('2026-05-01 18:00:00') or as a unix timestamp
        // string. Both forms must be compared.
        $sql = $wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_end
                 ON pm_end.post_id = p.ID AND pm_end.meta_key = '_auction_bidding_end'
             LEFT JOIN {$wpdb->postmeta} pm_status
                 ON pm_status.post_id = p.ID AND pm_status.meta_key = '_auction_status'
             WHERE p.post_type = 'product'
               AND p.post_status IN ('publish','private')
               AND pm_end.meta_value <> ''
               AND (pm_status.meta_value IS NULL OR pm_status.meta_value = '')
               AND (
                    (pm_end.meta_value REGEXP '^[0-9]+$' AND CAST(pm_end.meta_value AS UNSIGNED) <= %d)
                 OR (pm_end.meta_value NOT REGEXP '^[0-9]+$' AND pm_end.meta_value <= %s)
               )
             LIMIT %d",
            $now_ts,
            $now_mysql,
            (int) $limit
        );
        $ids = $wpdb->get_col($sql);
        return is_array($ids) ? array_map('intval', $ids) : array();
    }

    private function get_high_bid_row($product_id) {
        global $wpdb;
        $table = Azure_Database::get_table_name('auction_bids');
        if (!$table) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare(
            "SELECT user_id, bid_amount FROM {$table} WHERE product_id = %d ORDER BY bid_amount DESC, created_at DESC LIMIT 1",
            $product_id
        ));
    }

    /**
     * Create WooCommerce order for auction winner.
     *
     * @param int   $product_id
     * @param int   $winner_user_id
     * @param float $winning_amount
     * @return WC_Order|null
     */
    public function create_winner_order($product_id, $winner_user_id, $winning_amount) {
        if (!function_exists('wc_create_order')) {
            return null;
        }
        $product = wc_get_product($product_id);
        if (!$product) {
            return null;
        }
        $order = wc_create_order(array('customer_id' => $winner_user_id));
        if (!$order || is_wp_error($order)) {
            return null;
        }
        $order->add_product($product, 1, array(
            'subtotal' => $winning_amount,
            'total'    => $winning_amount,
        ));
        $this->ensure_billing_from_user($order, $winner_user_id);
        $order->set_status('wc-pending');
        $order->add_order_note(__('Auction win. Customer must complete payment.', 'azure-plugin'));
        $order->calculate_totals();
        $order->save();
        return $order;
    }

    /**
     * Create order for Buy It Now and mark auction as sold. Sends the same
     * "your auction order is ready to pay" email so BIN buyers don't have to
     * remember the checkout URL from the AJAX redirect.
     *
     * @param int $product_id
     * @param int $user_id
     * @return WC_Order|null
     */
    public function create_buy_it_now_order($product_id, $user_id) {
        $product = wc_get_product($product_id);
        if (!$product || $product->get_type() !== 'auction') {
            return null;
        }
        if ($product->is_auction_ended() || $product->get_auction_status() !== '') {
            return null;
        }
        $price = $product->get_buy_it_now_price();
        if ($price <= 0) {
            return null;
        }
        if (!function_exists('wc_create_order')) {
            return null;
        }
        $order = wc_create_order(array('customer_id' => $user_id));
        if (!$order || is_wp_error($order)) {
            return null;
        }
        $order->add_product($product, 1, array(
            'subtotal' => $price,
            'total'    => $price,
        ));
        $this->ensure_billing_from_user($order, $user_id);
        $order->set_status('wc-pending');
        $order->add_order_note(__('Buy It Now - auction item. Customer must complete payment.', 'azure-plugin'));
        $order->calculate_totals();
        $order->save();

        update_post_meta($product_id, '_auction_status', 'sold');
        update_post_meta($product_id, '_auction_sold_at', current_time('mysql'));
        update_post_meta($product_id, '_auction_sold_order_id', $order->get_id());

        // Per-auction finalize cron is now redundant — clear it so it
        // doesn't fire after the auction is already sold.
        $this->clear_finalize_event($product_id);

        $this->send_payment_email($order, $product_id, (float) $price);

        return $order;
    }

    /**
     * Schedule (or reschedule) the per-auction one-shot finalize event.
     * Idempotent: clears any previously-scheduled event for this product
     * before scheduling the new one, so editing the end time works.
     *
     * @param int    $product_id
     * @param string $bidding_end Mysql datetime or unix timestamp string.
     */
    public static function schedule_finalize_event($product_id, $bidding_end) {
        $product_id = (int) $product_id;
        if ($product_id <= 0 || empty($bidding_end)) {
            self::clear_finalize_event_static($product_id);
            return;
        }
        $end_ts = is_numeric($bidding_end) ? (int) $bidding_end : strtotime($bidding_end);
        if (!$end_ts) {
            self::clear_finalize_event_static($product_id);
            return;
        }

        // Clear any existing event for this product first (end time might
        // have changed). wp_clear_scheduled_hook clears all events bound to
        // the hook with these exact args.
        wp_clear_scheduled_hook('azure_auction_finalize', array($product_id));

        // If end time is already in the past, schedule for "now + 60s" so
        // the orphan-sweep doesn't have to wait until tomorrow's daily run
        // to pick it up. WP-Cron will fire it on the next page hit anyway.
        $first_run = max($end_ts, time() + 60);
        wp_schedule_single_event($first_run, 'azure_auction_finalize', array($product_id));
    }

    /**
     * Instance shim for callers that already have a lifecycle instance.
     */
    public function clear_finalize_event($product_id) {
        self::clear_finalize_event_static((int) $product_id);
    }

    public static function clear_finalize_event_static($product_id) {
        wp_clear_scheduled_hook('azure_auction_finalize', array((int) $product_id));
    }

    /**
     * Backfill: schedule one-shot finalize events for every existing auction
     * with a future bidding end and no winner yet. Called once after a
     * version bump (gated by the azure_auction_finalize_backfill_done option).
     */
    public static function backfill_finalize_events() {
        if (get_option('azure_auction_finalize_backfill_done')) {
            return;
        }
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT p.ID, pm_end.meta_value AS bidding_end
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_end
                 ON pm_end.post_id = p.ID AND pm_end.meta_key = '_auction_bidding_end'
             LEFT JOIN {$wpdb->postmeta} pm_status
                 ON pm_status.post_id = p.ID AND pm_status.meta_key = '_auction_status'
             WHERE p.post_type = 'product'
               AND p.post_status IN ('publish','private','draft')
               AND pm_end.meta_value <> ''
               AND (pm_status.meta_value IS NULL OR pm_status.meta_value = '')"
        );
        if (is_array($rows)) {
            foreach ($rows as $row) {
                self::schedule_finalize_event((int) $row->ID, (string) $row->bidding_end);
            }
        }
        update_option('azure_auction_finalize_backfill_done', 1, false);
    }

    /**
     * Make sure the order has a billing email/name so wp_mail can deliver.
     * wc_create_order(['customer_id' => $X]) does not always copy the
     * customer's WP user email into the order — first-time auction bidders
     * who've never checked out before will have an empty billing_email and
     * the winner email will silently fail.
     *
     * @param WC_Order $order
     * @param int      $user_id
     */
    private function ensure_billing_from_user($order, $user_id) {
        if (!$order || !$user_id) {
            return;
        }
        $user = get_userdata((int) $user_id);
        if (!$user) {
            return;
        }
        if (!$order->get_billing_email()) {
            $order->set_billing_email($user->user_email);
        }
        if (!$order->get_billing_first_name()) {
            $first = get_user_meta($user_id, 'first_name', true);
            if (!$first) {
                $first = $user->display_name ?: $user->user_login;
            }
            $order->set_billing_first_name($first);
        }
        if (!$order->get_billing_last_name()) {
            $last = get_user_meta($user_id, 'last_name', true);
            if ($last) {
                $order->set_billing_last_name($last);
            }
        }
    }

    /**
     * Send the payment-request email through Azure_Auction_Emails. Same
     * template for both natural-end winners and BIN buyers — the customer
     * gets a "you won the auction" message with a Pay now button.
     *
     * @param WC_Order $order
     * @param int      $product_id
     * @param float    $amount
     */
    private function send_payment_email($order, $product_id, $amount) {
        if (!class_exists('Azure_Auction_Emails')) {
            return;
        }
        $emails = new Azure_Auction_Emails();
        $emails->send_winner_email($order, $product_id, $amount);
    }
}
