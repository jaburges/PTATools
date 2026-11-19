<?php
/**
 * Ticket dispensers at the till: a self-service tablet in the PTSA Board
 * app that pays out arcade-style tickets after the card goes through.
 *
 * A product says how many machine tickets one of it pays out (Machine
 * tickets on the General tab). Once a sale is paid, the website signs a
 * voucher for the order and its ticket count with the dispenser secret
 * (WooCommerce → Settings → Products → Tap to Pay), and the dispenser,
 * which holds the same secret, only pays out against a valid voucher it
 * hasn't seen before. The app never holds the secret.
 *
 * Sales the app makes while the website is out of reach can't get a
 * voucher, so each event also has an offline key derived from the secret.
 * The dispenser derives the same key and accepts it only for sale IDs with
 * the top bit set, which WooCommerce order IDs never reach.
 *
 * The app reports what came out (POST /pos/orders/{id}/dispensed), and a
 * short payout is noted on the order so a volunteer can make it up.
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_POS_Dispenser {

    const OPTION_SECRET = 'pta_pos_dispenser_secret';
    const META_TICKETS = '_pta_machine_tickets';
    const ORDER_DISPENSED = '_pta_pos_dispensed';

    /** The dispenser refuses more than this in one payout. */
    const MAX_PER_SALE = 100;

    /** Reasons the app reports for a short payout, as written on the order. */
    const REASONS = array(
        'jam'          => 'the dispenser jammed',
        'empty'        => 'the dispenser ran out of tickets',
        'stopped'      => 'the payout was stopped',
        'interrupted'  => 'the dispenser lost power',
        'disconnected' => 'the dispenser was unplugged',
        'none'         => '',
    );

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('woocommerce_product_options_general_product_data', array($this, 'render_field'), 20);
        add_action('woocommerce_admin_process_product_object', array($this, 'save_field'));
        add_filter('woocommerce_get_settings_products', array($this, 'settings'), 20, 2);
    }

    /** The shared secret as 64 hex digits, made on first use. */
    public static function secret() {
        $secret = strtolower(trim((string) get_option(self::OPTION_SECRET, '')));
        if (!preg_match('/^[0-9a-f]{64}$/', $secret)) {
            $secret = bin2hex(random_bytes(32));
            update_option(self::OPTION_SECRET, $secret, false);
        }
        return $secret;
    }

    /**
     * Machine tickets one of the product pays out; a variation follows its product.
     *
     * @param WC_Product|null $product
     */
    public static function tickets($product) {
        if (!is_object($product)) {
            return 0;
        }
        if ($product->get_parent_id() && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                $product = $parent;
            }
        }
        return max(0, (int) $product->get_meta(self::META_TICKETS));
    }

    /** Machine tickets for the whole order. */
    public static function order_tickets($order) {
        $total = 0;
        foreach ($order->get_items() as $item) {
            if (!method_exists($item, 'get_product_id')) {
                continue;
            }
            $id = $item->get_variation_id() ?: $item->get_product_id();
            $total += self::tickets(wc_get_product($id)) * (int) $item->get_quantity();
        }
        return $total;
    }

    /** HMAC-SHA256 over "dispense:<id>:<qty>", as 64 hex digits. */
    public static function voucher($id, $qty) {
        return hash_hmac('sha256', 'dispense:' . (int) $id . ':' . (int) $qty, hex2bin(self::secret()));
    }

    /** The key the app signs offline payouts with at this event. */
    public static function offline_key($event_id) {
        return hash_hmac('sha256', 'offline:' . (int) $event_id, hex2bin(self::secret()));
    }

    /**
     * What the dispenser should pay out for a paid order, or null when the
     * order has no machine tickets.
     *
     * @return array{id:int,qty:int,voucher:string}|null
     */
    public static function for_order($order) {
        $qty = self::order_tickets($order);
        if ($qty < 1 || $qty > self::MAX_PER_SALE) {
            return null;
        }
        return array('id' => (int) $order->get_id(), 'qty' => $qty, 'voucher' => self::voucher($order->get_id(), $qty));
    }

    /**
     * Notes what the dispenser paid out. Reporting the same result again
     * changes nothing.
     *
     * @param int|null $given null when the dispenser can't say (it lost power mid-payout).
     */
    public static function record($order, $id, $qty, $given, $reason) {
        $qty = max(0, (int) $qty);
        $given = $given === null ? null : max(0, min((int) $given, $qty));
        $reason = isset(self::REASONS[$reason]) ? $reason : 'none';
        $result = array('id' => (int) $id, 'qty' => $qty, 'given' => $given, 'reason' => $reason);
        if ($order->get_meta(self::ORDER_DISPENSED) === $result) {
            return;
        }
        $order->update_meta_data(self::ORDER_DISPENSED, $result);
        $why = self::REASONS[$reason] !== '' ? ' (' . self::REASONS[$reason] . ')' : '';
        if ($given === $qty) {
            $order->add_order_note(sprintf('The dispenser paid out %d machine ticket(s).', $qty));
        } elseif ($given === null) {
            $order->add_order_note(sprintf('The dispenser stopped partway through %d machine ticket(s)%s and can\'t say how many came out. Check with the buyer and make up the rest by hand.', $qty, $why));
        } else {
            $order->add_order_note(sprintf('The dispenser paid out %d of %d machine ticket(s)%s: %d owed, to give by hand.', $given, $qty, $why, $qty - $given));
        }
        $order->save();
    }

    // ── Product editor ────────────────────────────────────────────────

    public function render_field() {
        global $product_object;
        $value = is_object($product_object) ? (int) $product_object->get_meta(self::META_TICKETS) : 0;
        echo '<div class="options_group">';
        woocommerce_wp_text_input(array(
            'id'                => self::META_TICKETS,
            'label'             => __('Machine tickets', 'azure-plugin'),
            'type'              => 'number',
            'value'             => $value ?: '',
            'placeholder'       => '0',
            'custom_attributes' => array('min' => '0', 'max' => (string) self::MAX_PER_SALE, 'step' => '1'),
            'desc_tip'          => true,
            'description'       => __('How many tickets one of these pays out from a ticket dispenser at a self-service till in the PTSA Board app. Leave empty for products the dispenser doesn\'t pay out.', 'azure-plugin'),
        ));
        echo '</div>';
    }

    public function save_field($product) {
        if (!isset($_POST[self::META_TICKETS])) {
            return;
        }
        $tickets = min(self::MAX_PER_SALE, max(0, (int) wp_unslash($_POST[self::META_TICKETS])));
        if ($tickets > 0) {
            $product->update_meta_data(self::META_TICKETS, $tickets);
        } else {
            $product->delete_meta_data(self::META_TICKETS);
        }
    }

    // ── Settings: WooCommerce → Settings → Products → Tap to Pay ─────

    public function settings($settings, $section) {
        if ($section !== 'pta_pos' || !is_array($settings)) {
            return $settings;
        }
        self::secret();
        $field = array(
            'title'    => __('Ticket dispenser secret', 'azure-plugin'),
            'desc'     => __('64 letters and digits shared with every ticket dispenser, so a dispenser only pays out for sales the website has confirmed. Set it on each dispenser with its setup tool. Clear it and save to make a new one (then set the new one on every dispenser).', 'azure-plugin'),
            'id'       => self::OPTION_SECRET,
            'type'     => 'text',
            'css'      => 'width: 36em; font-family: monospace;',
            'desc_tip' => false,
        );
        $end = array_pop($settings);
        $settings[] = $field;
        $settings[] = $end;
        return $settings;
    }
}
