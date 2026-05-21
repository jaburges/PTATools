<?php
/**
 * WooCommerce Auction Product Class
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WC_Product')) {
    return;
}

class WC_Product_Auction extends WC_Product {

    protected $product_type = 'auction';

    public function __construct($product = 0) {
        parent::__construct($product);
    }

    public function get_type() {
        return 'auction';
    }

    /**
     * Auction products are not purchasable through the normal cart/checkout flow.
     * Purchases happen via bidding or Buy It Now, which create orders directly.
     */
    public function is_purchasable() {
        return false;
    }

    public function is_virtual() {
        return false;
    }

    public function is_sold_individually() {
        return true;
    }

    /**
     * Return the starting/regular price so WooCommerce price display doesn't break.
     */
    public function get_price($context = 'view') {
        $price = parent::get_price($context);
        if ($price === '' || $price === null) {
            $price = $this->get_regular_price($context);
        }
        return $price;
    }

    public function add_to_cart_text() {
        if ($this->is_auction_ended() || in_array($this->get_auction_status(), array('ended', 'sold'), true)) {
            return __('Auction ended', 'azure-plugin');
        }
        return __('View auction', 'azure-plugin');
    }

    public function add_to_cart_url() {
        return get_permalink($this->get_id());
    }

    public function single_add_to_cart_text() {
        return $this->add_to_cart_text();
    }

    /**
     * Bidding end datetime (Y-m-d H:i:s or stored as timestamp/string)
     */
    public function get_auction_bidding_end() {
        return get_post_meta($this->get_id(), '_auction_bidding_end', true);
    }

    /**
     * Whether bidding has ended
     */
    public function is_auction_ended() {
        $end = $this->get_auction_bidding_end();
        if (empty($end)) {
            return false;
        }
        $end_ts = is_numeric($end) ? (int) $end : strtotime($end);
        return $end_ts && time() >= $end_ts;
    }

    public function is_buy_it_now_enabled() {
        return get_post_meta($this->get_id(), '_auction_buy_it_now_enabled', true) === 'yes';
    }

    public function get_buy_it_now_price() {
        $p = get_post_meta($this->get_id(), '_auction_buy_it_now_price', true);
        return $p !== '' ? (float) $p : 0;
    }

    public function is_buy_it_now_pay_immediately() {
        return get_post_meta($this->get_id(), '_auction_buy_it_now_pay_immediately', true) === 'yes';
    }

    /**
     * Auction status (e.g. ended, sold via buy it now)
     */
    public function get_auction_status() {
        return get_post_meta($this->get_id(), '_auction_status', true) ?: '';
    }
}
