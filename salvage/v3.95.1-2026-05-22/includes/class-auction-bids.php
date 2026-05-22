<?php
/**
 * Auction Bids - Place bid, masked history
 *
 * Manual-bid only. Max-bid / auto-bid was removed in v3.74 because the
 * auto-bid loop only defended the current high bidder's max (not displaced
 * bidders), which produced surprising outcomes. Every bid is now a single
 * manual bid; out-bid notifications are emailed via Azure_Auction_Emails.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Auction_Bids {

    /**
     * Smart-tiered minimum bid increment based on the current high price.
     * Mirrors the typical live-auction pattern: smaller bumps at low prices
     * so opening rounds aren't gated by a $5 minimum, larger bumps at high
     * prices so the auction doesn't stall on $5 nudges past $500.
     *
     *   < $25     -> $1 increment
     *   < $100    -> $5
     *   < $500    -> $10
     *   >= $500   -> $25
     *
     * @param float $current_price
     * @return float
     */
    public static function get_increment($current_price) {
        $price = (float) $current_price;
        if ($price < 25)  return 1.00;
        if ($price < 100) return 5.00;
        if ($price < 500) return 10.00;
        return 25.00;
    }

    /**
     * Quick-bid button increments for the bid form. Always shows three
     * options scaled to the current tier (1x, 2x, 4x) so the buttons
     * always feel right relative to the current price.
     *
     * @param float $current_price
     * @return array<float>
     */
    public static function get_quick_bid_increments($current_price) {
        $base = self::get_increment($current_price);
        return array($base, $base * 2, $base * 4);
    }

    /**
     * Place a manual bid (AJAX entry point: reads POST product_id, amount, nonce).
     *
     * @return array|WP_Error { current_price, bids: masked list } or WP_Error
     */
    public function place_bid() {
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $amount = isset($_POST['amount']) ? self::parse_amount($_POST['amount']) : null;

        if (!$product_id || !is_numeric($amount)) {
            return new WP_Error('invalid', __('Invalid bid data.', 'azure-plugin'));
        }

        if (!wp_verify_nonce(isset($_POST['nonce']) ? $_POST['nonce'] : '', 'azure_auction_bid')) {
            return new WP_Error('nonce', __('Security check failed.', 'azure-plugin'));
        }

        $product = wc_get_product($product_id);
        if (!$product || $product->get_type() !== 'auction') {
            return new WP_Error('invalid', __('Product is not an auction.', 'azure-plugin'));
        }

        if ($product->is_auction_ended() || $product->get_auction_status() === 'ended' || $product->get_auction_status() === 'sold') {
            return new WP_Error('ended', __('Bidding has ended.', 'azure-plugin'));
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_Error('login', __('You must be logged in to bid.', 'azure-plugin'));
        }

        $starting_bid = $this->get_starting_bid($product_id);
        $high = $this->get_current_high($product_id);
        $current_price = $high ? (float) $high->bid_amount : $starting_bid;
        $increment = self::get_increment($current_price);

        $required = $high ? ($current_price + $increment) : $starting_bid;
        if ($amount < $required) {
            // wc_price() returns HTML markup; the JS shows error messages via
            // .text(), so we strip tags + decode entities for a clean "$6.00"
            // string that renders correctly in the bid form's error banner.
            $required_label = html_entity_decode(wp_strip_all_tags(wc_price($required)));
            return new WP_Error('invalid', sprintf(__('Minimum bid is %s.', 'azure-plugin'), $required_label));
        }
        $bid_amount = $amount;

        global $wpdb;
        $table = Azure_Database::get_table_name('auction_bids');
        if (!$table) {
            return new WP_Error('error', __('Bids table not available.', 'azure-plugin'));
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : null;

        // max_bid + is_auto_bid columns kept in the schema for backward compat
        // but always written as null/0 since v3.74 (max-bid feature removed).
        $wpdb->insert(
            $table,
            array(
                'product_id'  => $product_id,
                'user_id'     => $user_id,
                'bid_amount'  => $bid_amount,
                'max_bid'     => null,
                'is_auto_bid' => 0,
                'ip_address'  => $ip,
            ),
            array('%d', '%d', '%f', '%f', '%d', '%s')
        );

        if ($wpdb->last_error) {
            return new WP_Error('error', __('Could not save bid.', 'azure-plugin'));
        }

        // Outbid notification: a manual bid by user X always displaces any
        // previous high bidder Y (where Y != X) because there is no auto-bid
        // defense path. Email Y so they can come back and re-bid. Failure
        // here must not break the bid response — the bid already saved, the
        // email is best-effort.
        if ($high && (int) $high->user_id !== (int) $user_id) {
            if (class_exists('Azure_Auction_Emails')) {
                try {
                    $emails = new Azure_Auction_Emails();
                    $emails->send_outbid_email(
                        $product_id,
                        (int) $high->user_id,
                        (float) $high->bid_amount,
                        (float) $bid_amount
                    );
                } catch (\Throwable $e) {
                    if (class_exists('Azure_Logger')) {
                        Azure_Logger::error('Auction: outbid email exception: ' . $e->getMessage(), array(
                            'product_id' => $product_id,
                        ));
                    }
                }
            }
        }

        $data = $this->get_masked_bid_history($product_id);
        $data['current_price'] = $this->get_current_high_amount($product_id);
        if ($data['current_price'] === null) {
            $data['current_price'] = $starting_bid;
        }
        return $data;
    }

    private function get_starting_bid($product_id) {
        $p = wc_get_product($product_id);
        if (!$p) {
            return 0;
        }
        $price = $p->get_regular_price();
        return $price !== '' ? (float) $price : 0;
    }

    private function get_current_high($product_id) {
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

    private function get_current_high_amount($product_id) {
        $high = $this->get_current_high($product_id);
        return $high ? (float) $high->bid_amount : null;
    }

    private static function parse_amount($v) {
        if ($v === null || $v === '') {
            return null;
        }
        return is_numeric($v) ? (float) $v : null;
    }

    /**
     * Get masked bid history for display (bidder = first 2 chars of username + "***").
     *
     * @param int $product_id
     * @param int $limit
     * @return array { bids: array of { bidder, amount, time }, current_price }
     */
    public function get_masked_bid_history($product_id, $limit = 10) {
        global $wpdb;
        $table = Azure_Database::get_table_name('auction_bids');
        if (!$table) {
            return array('bids' => array(), 'current_price' => 0);
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, bid_amount, created_at FROM {$table} WHERE product_id = %d ORDER BY created_at DESC LIMIT %d",
            $product_id,
            $limit
        ), ARRAY_A);

        $bids = array();
        foreach ($rows as $row) {
            $login = '';
            $user = get_userdata((int) $row['user_id']);
            if ($user && !empty($user->user_login)) {
                $login = substr($user->user_login, 0, 2) . '***';
            } else {
                $login = '***';
            }
            $bids[] = array(
                'bidder' => $login,
                'amount' => (float) $row['bid_amount'],
                'time'   => $row['created_at'],
            );
        }

        $current = $this->get_current_high_amount($product_id);
        $product = wc_get_product($product_id);
        $starting = $product ? $this->get_starting_bid($product_id) : 0;
        $current_price = $current !== null ? $current : $starting;

        // Tiered next-min and quick-bid increments so the JS poller can
        // refresh the bid input + quick-bid buttons whenever the price
        // crosses a tier boundary without duplicating the tier table in JS.
        $next_increment = self::get_increment($current_price);
        $next_min       = $current !== null ? ($current_price + $next_increment) : $starting;

        return array(
            'bids'              => $bids,
            'current_price'     => $current_price,
            'next_min_bid'      => $next_min,
            'next_increment'    => $next_increment,
            'quick_increments'  => self::get_quick_bid_increments($current_price),
        );
    }
}
