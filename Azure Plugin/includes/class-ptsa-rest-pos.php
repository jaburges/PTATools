<?php
/**
 * Tap to Pay for the PTSA Board app: /wp-json/ptsa/v1/pos/*
 *
 * Volunteers pick an event that is taking payments (GET /pos/events, no
 * sign-in), type its PIN (POST /pos/open) and send the payment token they
 * get back as `Authorization: POS <token>`. Signed-in shop managers and
 * administrators can use any event taking payments without the PIN.
 *
 * A sale: the app sends product IDs and quantities (POST /pos/orders).
 * The website prices them from WooCommerce, checks stock, creates a
 * pending order and a card-present PaymentIntent on its own Stripe
 * account, and answers with the intent's client secret. The phone
 * collects the tap with the Stripe Terminal SDK, then POST
 * /pos/orders/{id}/complete checks the intent with Stripe before marking
 * the order paid. A sale given up on is cancelled with
 * /pos/orders/{id}/cancel; ones left pending are settled by reconcile().
 *
 * Payment tokens can't see or change anything else: no other orders,
 * refunds or products.
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-tickets-door.php';
require_once __DIR__ . '/class-pos-channel.php';
require_once __DIR__ . '/class-pos-events.php';
require_once __DIR__ . '/class-pos-stripe.php';
require_once __DIR__ . '/class-pos-tickets.php';

class Azure_PTSA_REST_POS {

    const CREATED_VIA = 'ptsa-pos';
    const PAYMENT_METHOD = 'ptsa_tap_to_pay';

    const ORDER_EVENT = '_pta_pos_event';
    const ORDER_INTENT = '_pta_pos_intent';
    const ORDER_AMOUNT = '_pta_pos_amount';
    const ORDER_SELLER = '_pta_pos_seller';

    /** Shop managers and administrators can sell without the PIN. */
    const CAP = 'edit_shop_orders';

    const MAX_LINES = 50;
    const MAX_QUANTITY = 99;

    /** Pending sales older than this are settled by reconcile(). */
    const ABANDONED_AFTER = 15 * MINUTE_IN_SECONDS;
    const CRON_HOOK = 'pta_pos_reconcile';

    /** Product types the till sells; tickets are sold through the event's performance. */
    const SELLABLE_TYPES = array('simple', 'variable');

    /** @var int Event the current request's payment token is for; 0 for signed-in callers. */
    private static $event = 0;

    public static function register($ns, $auth) {
        $pos_or_auth = function ($request) use ($auth) {
            return self::authorize($auth, $request);
        };
        register_rest_route($ns, '/pos/events', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'list_events'), 'permission_callback' => '__return_true',
        ));
        register_rest_route($ns, '/pos/open', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'open'), 'permission_callback' => '__return_true',
        ));
        register_rest_route($ns, '/pos/events/(?P<id>\d+)/catalog', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'catalog'), 'permission_callback' => $pos_or_auth,
        ));
        register_rest_route($ns, '/pos/events/(?P<id>\d+)/seats', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'seats'), 'permission_callback' => $pos_or_auth,
        ));
        register_rest_route($ns, '/pos/connection-token', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'connection_token'), 'permission_callback' => $pos_or_auth,
        ));
        register_rest_route($ns, '/pos/orders', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'create_order'), 'permission_callback' => $pos_or_auth,
        ));
        register_rest_route($ns, '/pos/orders/(?P<id>\d+)/complete', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'complete_order'), 'permission_callback' => $pos_or_auth,
        ));
        register_rest_route($ns, '/pos/orders/(?P<id>\d+)/cancel', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'cancel_order'), 'permission_callback' => $pos_or_auth,
        ));
    }

    /**
     * `Authorization: POS <token>` lets the request through for its event;
     * anything else needs the normal sign-in and the shop capability.
     */
    public static function authorize($auth, $request) {
        self::$event = 0;
        $header = method_exists($request, 'get_header') ? (string) $request->get_header('authorization') : '';
        if (stripos($header, 'POS ') === 0) {
            $id = Azure_POS_Events::verify(trim(substr($header, 4)));
            if (!$id) {
                return new WP_Error('ptsa_pos_expired', 'This payment session has ended. Choose the event and enter its PIN again.', array('status' => 401));
            }
            self::$event = $id;
            return true;
        }
        $signed_in = call_user_func($auth, $request);
        if ($signed_in !== true) {
            return $signed_in;
        }
        if (!current_user_can(self::CAP)) {
            return new WP_Error('ptsa_pos_forbidden', 'Only shop managers and administrators can take payments without an event PIN.', array('status' => 403));
        }
        return true;
    }

    // ── Volunteers in ─────────────────────────────────────────────────

    /** Events taking payments right now: name, time and venue only. */
    public static function list_events(WP_REST_Request $req) {
        if ($off = self::off()) {
            return $off;
        }
        return rest_ensure_response(array_map(array('Azure_POS_Events', 'payload'), Azure_POS_Events::open_events()));
    }

    /** Body: {event_id, pin}. */
    public static function open(WP_REST_Request $req) {
        if ($off = self::off()) {
            return $off;
        }
        if (Azure_Tickets_Door::locked_out()) {
            return new WP_Error('ptsa_pos_locked', 'Too many wrong PINs. Wait 15 minutes, or ask a board member.', array('status' => 429));
        }
        $body = $req->get_json_params() ?: array();
        $event = (int) ($body['event_id'] ?? 0);
        $pin = Azure_Tickets_Door::clean_pin((string) ($body['pin'] ?? ''));
        $own = $event ? Azure_POS_Events::pin($event) : '';
        $session = ($pin !== '' && $own !== '' && hash_equals($own, $pin) && Azure_POS_Events::is_open($event)) ? Azure_POS_Events::issue($event) : null;
        if (!$session) {
            Azure_Tickets_Door::record_failure();
            return new WP_Error('ptsa_pos_pin', 'That PIN isn\'t right for this event, or it isn\'t taking payments right now. Check with a board member.', array('status' => 401));
        }
        return rest_ensure_response(array(
            'token'      => $session['token'],
            'expires_at' => $session['expires_at'],
            'expires'    => wp_date('c', $session['expires_at']),
            'event'      => Azure_POS_Events::payload($event),
        ));
    }

    // ── The till ──────────────────────────────────────────────────────

    public static function catalog(WP_REST_Request $req) {
        if ($off = self::off()) {
            return $off;
        }
        $event = self::event_for((int) $req['id']);
        if (is_wp_error($event)) {
            return $event;
        }
        $products = array();
        foreach (self::catalog_products($event) as $product) {
            $products[] = self::product_payload($product);
        }
        return rest_ensure_response(array(
            'event'       => Azure_POS_Events::payload($event),
            'currency'    => get_woocommerce_currency(),
            'location_id' => Azure_POS_Stripe::location_id(),
            'test_mode'   => Azure_POS_Stripe::test_mode(),
            'products'    => $products,
            'tickets'     => Azure_POS_Tickets::summary($event, get_woocommerce_currency()),
        ));
    }

    /** Free and taken seats for the event's performance; never who has them. */
    public static function seats(WP_REST_Request $req) {
        if ($off = self::off()) {
            return $off;
        }
        $event = self::event_for((int) $req['id']);
        if (is_wp_error($event)) {
            return $event;
        }
        $map = Azure_POS_Tickets::seat_map($event);
        return is_wp_error($map) ? $map : rest_ensure_response($map);
    }

    public static function connection_token(WP_REST_Request $req) {
        if ($off = self::off()) {
            return $off;
        }
        $token = Azure_POS_Stripe::connection_token();
        if (is_wp_error($token)) {
            return $token;
        }
        return rest_ensure_response(array('secret' => (string) ($token['secret'] ?? '')));
    }

    /**
     * Body: {event_id, lines: [{product_id, variation_id?, quantity}],
     * tickets?: {seats: [{section_id, seat_key}], general: [{section_id, count}]}}.
     * Answers with the order and the PaymentIntent's client secret.
     */
    public static function create_order(WP_REST_Request $req) {
        if ($off = self::off()) {
            return $off;
        }
        $body = $req->get_json_params() ?: array();
        $event = self::event_for((int) ($body['event_id'] ?? 0));
        if (is_wp_error($event)) {
            return $event;
        }
        $lines = self::clean_lines($body['lines'] ?? array());
        if (is_wp_error($lines)) {
            return $lines;
        }
        $allowed = array();
        foreach (self::catalog_products($event) as $product) {
            $allowed[(int) $product->get_id()] = $product;
        }
        $order_lines = array();
        foreach ($lines as $line) {
            $sold = self::line_product($line, $allowed);
            if (is_wp_error($sold)) {
                return $sold;
            }
            $order_lines[] = array($sold, $line['quantity']);
        }

        $hold_key = Azure_POS_Tickets::hold_key();
        $ticket_lines = Azure_POS_Tickets::reserve($event, $body['tickets'] ?? null, $hold_key);
        if (is_wp_error($ticket_lines)) {
            return $ticket_lines;
        }
        if (!$order_lines && !$ticket_lines) {
            return new WP_Error('ptsa_pos_empty', 'The cart is empty.', array('status' => 400));
        }
        $performance = $ticket_lines ? $ticket_lines[0]['performance'] : 0;

        $order = wc_create_order(array('status' => 'pending', 'created_via' => self::CREATED_VIA, 'customer_id' => self::$event ? 0 : get_current_user_id()));
        if (is_wp_error($order) || !$order) {
            Azure_POS_Tickets::release($performance, $hold_key);
            return new WP_Error('ptsa_pos_order', 'Couldn\'t start the order. Try again.', array('status' => 500));
        }
        foreach ($order_lines as $order_line) {
            $order->add_product($order_line[0], $order_line[1]);
        }
        if ($ticket_lines && !Azure_POS_Tickets::add_to_order($order, $ticket_lines, $hold_key)) {
            $order->delete(true);
            Azure_POS_Tickets::release($performance, $hold_key);
            return new WP_Error('ptsa_pos_order', 'Couldn\'t add the tickets to the order. Try again.', array('status' => 500));
        }
        $event_name = Azure_POS_Events::payload($event)['name'];
        $order->set_payment_method(self::PAYMENT_METHOD);
        $order->set_payment_method_title(__('Tap to Pay (PTSA Board app)', 'azure-plugin'));
        $order->update_meta_data(self::ORDER_EVENT, $event);
        $order->update_meta_data(self::ORDER_SELLER, self::seller());
        $order->calculate_totals();

        $currency = $order->get_currency() ?: get_woocommerce_currency();
        $amount = Azure_POS_Stripe::to_minor($order->get_total(), $currency);
        if ($amount <= 0) {
            $order->delete(true);
            Azure_POS_Tickets::release($performance, $hold_key);
            return new WP_Error('ptsa_pos_free', 'This sale comes to nothing, so there\'s nothing to charge.', array('status' => 400));
        }
        $order->update_meta_data(self::ORDER_AMOUNT, $amount);
        if ($ticket_lines) {
            Azure_POS_Tickets::attach($order, $ticket_lines, $hold_key);
        }
        $order->save();

        $intent = Azure_POS_Stripe::create_intent(
            $amount,
            $currency,
            sprintf('Order #%s – %s', $order->get_order_number(), $event_name),
            array('order_id' => $order->get_id(), 'event_id' => $event, 'site' => home_url())
        );
        if (is_wp_error($intent)) {
            $order->update_status('cancelled', 'Tap to Pay: Stripe refused the payment before the tap: ' . $intent->get_error_message());
            return $intent;
        }
        $order->update_meta_data(self::ORDER_INTENT, (string) $intent['id']);
        $order->add_order_note(sprintf('Tap to Pay sale at %s, by %s.', $event_name, self::seller()));
        $order->save();

        return rest_ensure_response(array(
            'order'         => self::order_payload($order),
            'client_secret' => (string) ($intent['client_secret'] ?? ''),
            'intent_id'     => (string) $intent['id'],
        ));
    }

    /**
     * After the tap. Checks the PaymentIntent with Stripe and, once it has
     * succeeded for the order's amount, marks the order paid. Safe to
     * repeat: a paid order answers the same way again.
     */
    public static function complete_order(WP_REST_Request $req) {
        if ($off = self::off()) {
            return $off;
        }
        $order = self::pos_order((int) $req['id']);
        if (is_wp_error($order)) {
            return $order;
        }
        $result = self::settle($order);
        if (is_wp_error($result)) {
            return $result;
        }
        if ($result !== 'paid') {
            return new WP_Error('ptsa_pos_unpaid', 'The payment hasn\'t gone through yet.', array('status' => 409, 'intent_status' => $result));
        }
        return rest_ensure_response(array('status' => 'paid', 'order' => self::order_payload($order), 'card' => self::card($order), 'tickets' => self::ticket_labels($order)));
    }

    public static function cancel_order(WP_REST_Request $req) {
        if ($off = self::off()) {
            return $off;
        }
        $order = self::pos_order((int) $req['id']);
        if (is_wp_error($order)) {
            return $order;
        }
        if ($order->is_paid()) {
            return new WP_Error('ptsa_pos_paid', 'This sale has been paid, so it can\'t be cancelled here. A board member can refund it.', array('status' => 409));
        }
        $result = self::settle($order);
        if (is_wp_error($result)) {
            return $result;
        }
        if ($result === 'paid') {
            return new WP_Error('ptsa_pos_paid', 'This sale has been paid, so it can\'t be cancelled here. A board member can refund it.', array('status' => 409));
        }
        self::abandon($order, 'Tap to Pay: the volunteer cancelled the sale.');
        return rest_ensure_response(array('status' => 'cancelled', 'order' => self::order_payload($order)));
    }

    /**
     * Sales left pending (the phone lost signal, the app closed): paid ones
     * are completed, the rest cancelled.
     */
    public static function reconcile() {
        if (!function_exists('wc_get_orders') || Azure_POS_Stripe::secret_key() === '') {
            return;
        }
        $orders = wc_get_orders(array(
            'status'       => array('pending'),
            'created_via'  => self::CREATED_VIA,
            'date_created' => '<' . (time() - self::ABANDONED_AFTER),
            'limit'        => 50,
        ));
        foreach ((array) $orders as $order) {
            $result = self::settle($order);
            if ($result !== 'paid' && !is_wp_error($result)) {
                self::abandon($order, 'Tap to Pay: the sale was never paid, so it was cancelled.');
            }
        }
    }

    /** woocommerce_email_enabled_new_order: no email to the shop for every sale at the till. */
    public static function skip_new_order_email($enabled, $order) {
        return (is_object($order) && method_exists($order, 'get_created_via') && $order->get_created_via() === self::CREATED_VIA) ? false : $enabled;
    }

    public static function schedule() {
        if (function_exists('wp_next_scheduled') && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * @return string|WP_Error 'paid', or the PaymentIntent's status when it isn't.
     */
    private static function settle($order) {
        if ($order->is_paid()) {
            return 'paid';
        }
        $intent_id = (string) $order->get_meta(self::ORDER_INTENT);
        if ($intent_id === '') {
            return 'none';
        }
        $intent = Azure_POS_Stripe::retrieve_intent($intent_id);
        if (is_wp_error($intent)) {
            return $intent;
        }
        if (($intent['status'] ?? '') === 'requires_capture') {
            $intent = Azure_POS_Stripe::capture_intent($intent_id);
            if (is_wp_error($intent)) {
                return $intent;
            }
        }
        $status = (string) ($intent['status'] ?? '');
        if ($status !== 'succeeded') {
            return $status;
        }
        $expected = (int) $order->get_meta(self::ORDER_AMOUNT);
        $received = (int) ($intent['amount_received'] ?? 0);
        $currency = strtolower((string) ($intent['currency'] ?? ''));
        if ($received !== $expected || $currency !== strtolower((string) $order->get_currency())) {
            $order->add_order_note(sprintf('Tap to Pay: Stripe received %d %s but the order is for %d — check this payment in Stripe (%s).', $received, strtoupper($currency), $expected, $intent_id));
            $order->update_status('on-hold');
            return new WP_Error('ptsa_pos_mismatch', 'The amount paid doesn\'t match the order. A board member needs to check it.', array('status' => 409));
        }
        $charge = is_array($intent['latest_charge'] ?? null) ? $intent['latest_charge'] : array();
        $card = $charge['payment_method_details']['card_present'] ?? array();
        if ($card) {
            $order->update_meta_data('_pta_pos_card', array('brand' => (string) ($card['brand'] ?? ''), 'last4' => (string) ($card['last4'] ?? '')));
        }
        $order->payment_complete($intent_id);
        $order->add_order_note(sprintf(
            'Paid by Tap to Pay%s. Stripe payment %s.',
            $card ? sprintf(' (%s •••• %s)', ucfirst((string) ($card['brand'] ?? 'card')), (string) ($card['last4'] ?? '')) : '',
            $intent_id
        ));
        if ($order->get_status() === 'processing') {
            $order->update_status('completed', 'Handed over at the event.');
        }
        Azure_POS_Tickets::check_in_order($order);
        return 'paid';
    }

    private static function abandon($order, $note) {
        $intent_id = (string) $order->get_meta(self::ORDER_INTENT);
        if ($intent_id !== '') {
            Azure_POS_Stripe::cancel_intent($intent_id);
        }
        if ($order->get_status() !== 'cancelled') {
            $order->update_status('cancelled', $note);
        }
    }

    /**
     * The event this caller may sell for: a payment token's own event, or
     * for signed-in callers any event taking payments.
     *
     * @return int|WP_Error
     */
    private static function event_for($event_id) {
        if (self::$event) {
            return $event_id === self::$event ? $event_id : new WP_Error('ptsa_pos_scope', 'This phone is only taking payments for one event.', array('status' => 403));
        }
        if (!$event_id || !Azure_POS_Events::enabled($event_id)) {
            return new WP_Error('ptsa_pos_event', 'That event isn\'t taking payments.', array('status' => 404));
        }
        return $event_id;
    }

    /** @return WC_Order|WP_Error A sale made by the app, for an event this caller may sell for. */
    private static function pos_order($order_id) {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order || $order->get_created_via() !== self::CREATED_VIA) {
            return new WP_Error('ptsa_pos_no_order', 'Sale not found.', array('status' => 404));
        }
        $event = self::event_for((int) $order->get_meta(self::ORDER_EVENT));
        return is_wp_error($event) ? $event : $order;
    }

    /**
     * @return WC_Product[] What the till sells at the event.
     */
    public static function catalog_products($event_id) {
        $chosen = Azure_POS_Events::product_ids($event_id);
        $args = array('status' => 'publish', 'limit' => 200, 'type' => self::SELLABLE_TYPES, 'orderby' => 'title', 'order' => 'ASC');
        if ($chosen) {
            $args['include'] = $chosen;
        }
        $out = array();
        foreach ((array) wc_get_products($args) as $product) {
            if (self::sellable($product)) {
                $out[] = $product;
            }
        }
        return $out;
    }

    private static function sellable($product) {
        if (!is_object($product) || !in_array($product->get_type(), self::SELLABLE_TYPES, true) || !Azure_POS_Channel::sells_in_pos($product)) {
            return false;
        }
        if (class_exists('Azure_Product_Fields_Module') && Azure_Product_Fields_Module::product_needs_product_page($product->get_id())) {
            return false;
        }
        return true;
    }

    /** @return array<int,array{product_id:int,variation_id:int,quantity:int}>|WP_Error */
    private static function clean_lines($raw) {
        if (!is_array($raw)) {
            return new WP_Error('ptsa_pos_line', 'The cart couldn\'t be read. Try again.', array('status' => 400));
        }
        if (count($raw) > self::MAX_LINES) {
            return new WP_Error('ptsa_pos_too_many', 'That\'s too many items for one sale.', array('status' => 400));
        }
        $merged = array();
        foreach ($raw as $line) {
            $product_id = (int) ($line['product_id'] ?? 0);
            $variation_id = (int) ($line['variation_id'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);
            if (!$product_id || $quantity < 1 || $quantity > self::MAX_QUANTITY) {
                return new WP_Error('ptsa_pos_line', 'Each item needs a quantity from 1 to ' . self::MAX_QUANTITY . '.', array('status' => 400));
            }
            $key = $product_id . ':' . $variation_id;
            if (!isset($merged[$key])) {
                $merged[$key] = array('product_id' => $product_id, 'variation_id' => $variation_id, 'quantity' => 0);
            }
            $merged[$key]['quantity'] += $quantity;
        }
        return array_values($merged);
    }

    /**
     * The product (or variation) a cart line buys, checked against the
     * event's catalog, its price, stock and pack size.
     *
     * @param array<int,WC_Product> $allowed
     * @return WC_Product|WP_Error
     */
    private static function line_product(array $line, array $allowed) {
        $parent = $allowed[$line['product_id']] ?? null;
        if (!$parent) {
            return new WP_Error('ptsa_pos_not_sold', 'Something in the cart isn\'t sold at this event any more. Refresh the products.', array('status' => 409));
        }
        $product = $parent;
        if ($parent->is_type('variable')) {
            $variation = $line['variation_id'] ? wc_get_product($line['variation_id']) : null;
            if (!$variation || (int) $variation->get_parent_id() !== (int) $parent->get_id() || $variation->get_status() !== 'publish') {
                return new WP_Error('ptsa_pos_option', 'Choose an option for ' . $parent->get_name() . '.', array('status' => 400));
            }
            $product = $variation;
        }
        if ($product->get_price() === '') {
            return new WP_Error('ptsa_pos_price', $product->get_name() . ' has no price on the website.', array('status' => 409));
        }
        $stock = self::stock($product);
        if ($stock !== null && $stock < $line['quantity']) {
            return new WP_Error('ptsa_pos_stock', $stock > 0
                ? sprintf('Only %d of %s left.', $stock, $product->get_name())
                : sprintf('%s is sold out.', $product->get_name()), array('status' => 409));
        }
        $pack = class_exists('Azure_Product_Packs') ? Azure_Product_Packs::size($product) : 1;
        if ($pack > 1 && $line['quantity'] % $pack !== 0) {
            return new WP_Error('ptsa_pos_pack', sprintf('%s is sold in multiples of %d.', $product->get_name(), $pack), array('status' => 400));
        }
        return $product;
    }

    /** @return int|null How many can be sold; null when unlimited. */
    private static function stock($product) {
        if (!$product->is_in_stock()) {
            return 0;
        }
        if ($product->managing_stock() && !$product->backorders_allowed()) {
            return max(0, (int) $product->get_stock_quantity());
        }
        return null;
    }

    private static function product_payload($product) {
        $currency = get_woocommerce_currency();
        $row = array(
            'id'          => (int) $product->get_id(),
            'name'        => html_entity_decode($product->get_name(), ENT_QUOTES, 'UTF-8'),
            'image'       => self::image($product),
            'price'       => (string) wc_get_price_to_display($product),
            'price_cents' => Azure_POS_Stripe::to_minor(wc_get_price_to_display($product), $currency),
            'stock'       => self::stock($product),
            'step'        => class_exists('Azure_Product_Packs') ? Azure_Product_Packs::size($product) : 1,
            'options'     => array(),
        );
        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $child_id) {
                $variation = wc_get_product($child_id);
                if (!$variation || $variation->get_status() !== 'publish' || $variation->get_price() === '') {
                    continue;
                }
                $row['options'][] = array(
                    'id'          => (int) $child_id,
                    'name'        => html_entity_decode(wc_get_formatted_variation($variation, true, false, false), ENT_QUOTES, 'UTF-8'),
                    'price'       => (string) wc_get_price_to_display($variation),
                    'price_cents' => Azure_POS_Stripe::to_minor(wc_get_price_to_display($variation), $currency),
                    'stock'       => self::stock($variation),
                    'image'       => self::image($variation) ?: $row['image'],
                );
            }
        }
        return $row;
    }

    private static function image($product) {
        $id = (int) $product->get_image_id();
        $url = $id ? wp_get_attachment_image_url($id, 'woocommerce_thumbnail') : '';
        return $url ? (string) $url : '';
    }

    private static function order_payload($order) {
        $currency = $order->get_currency();
        return array(
            'id'          => (int) $order->get_id(),
            'number'      => (string) $order->get_order_number(),
            'status'      => (string) $order->get_status(),
            'total'       => (string) $order->get_total(),
            'total_cents' => Azure_POS_Stripe::to_minor($order->get_total(), $currency),
            'currency'    => (string) $currency,
        );
    }

    /** @return array{brand:string,last4:string}|null */
    private static function card($order) {
        $card = $order->get_meta('_pta_pos_card');
        return is_array($card) && !empty($card['last4']) ? array('brand' => (string) $card['brand'], 'last4' => (string) $card['last4']) : null;
    }

    /** @return string[] Seats and places on the order, for the "Let them in" screen. */
    private static function ticket_labels($order) {
        $labels = array();
        if (!class_exists('Azure_Tickets_Cart')) {
            return $labels;
        }
        foreach ($order->get_items() as $item) {
            foreach ((array) $item->get_meta(Azure_Tickets_Cart::ITEM_SEATS) as $s) {
                if (is_array($s) && isset($s['label'])) {
                    $labels[] = (string) $s['label'];
                }
            }
        }
        return $labels;
    }

    private static function seller() {
        if (self::$event) {
            return 'a volunteer (event PIN)';
        }
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        return $user && !empty($user->display_name) ? (string) $user->display_name : 'a board member';
    }

    private static function off() {
        if (!function_exists('wc_create_order')) {
            return new WP_Error('ptsa_pos_off', 'The shop is turned off on the website.', array('status' => 404));
        }
        if (!Azure_POS_Stripe::ready()) {
            return new WP_Error('ptsa_pos_off', 'Tap to Pay isn\'t set up on this website yet. A board member can set it up under WooCommerce → Settings → Products → Tap to Pay.', array('status' => 404));
        }
        return null;
    }
}
