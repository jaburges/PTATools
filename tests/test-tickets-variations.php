<?php
/**
 * Ticket products with variations: each variation is a performance with its
 * own date, sales close and linked event, sharing the product's venue and
 * section settings, with seats sold per performance.
 *
 * Run: php tests/test-tickets-variations.php
 */

require_once __DIR__ . '/wp-shim.php';
require_once __DIR__ . '/sqlite-wpdb.php';

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($s) { return strip_tags((string) $s); }
}
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function _n($single, $plural, $n, $domain = null) { return $n === 1 ? $single : $plural; }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }

class TV_Product {
    public $id; public $type; public $parent; public $children; public $status;
    public function __construct($id, $type, $parent = 0, array $children = array(), $status = 'publish') {
        $this->id = $id; $this->type = $type; $this->parent = $parent; $this->children = $children; $this->status = $status;
    }
    public function get_id() { return $this->id; }
    public function get_type() { return $this->type; }
    public function get_parent_id() { return $this->parent; }
    public function get_visible_children() { return $this->children; }
    public function get_status() { return $this->status; }
}
class TV {
    public static $products = array();
    public static $attributes = array();
    public static $post_status = array();
}
function wc_get_product($id) { return TV::$products[(int) $id] ?? false; }
function get_post_type($id) {
    $p = TV::$products[(int) $id] ?? null;
    return $p ? ($p->type === 'variation' ? 'product_variation' : 'product') : false;
}
function wp_get_post_parent_id($id) { return (int) (TV::$products[(int) $id]->parent ?? 0); }
function get_post_status($id) { return TV::$post_status[(int) $id] ?? 'publish'; }
function wc_get_product_variation_attributes($id) { return TV::$attributes[(int) $id] ?? array(); }

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-layout.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-inventory.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-config.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-access.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-cart.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-module.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-assign.php';

$GLOBALS['wpdb'] = tickets_test_wpdb();
WP_Shim::$timezone = 'America/Los_Angeles';
Azure_Tickets_Inventory::$now = strtotime('2026-11-10 12:00:00 America/Los_Angeles');
Azure_Tickets_Config::$now = Azure_Tickets_Inventory::$now;

$VENUE = 900;
$SHOW = 600;
$FRI = 601;
$SAT = 602;
$OFF = 603;
$SINGLE = 501;
TV::$products = array(
    $SHOW   => new TV_Product($SHOW, 'ticket', 0, array($SAT, $FRI)),
    $FRI    => new TV_Product($FRI, 'variation', $SHOW),
    $SAT    => new TV_Product($SAT, 'variation', $SHOW),
    $OFF    => new TV_Product($OFF, 'variation', $SHOW, array(), 'private'),
    $SINGLE => new TV_Product($SINGLE, 'ticket'),
    777     => new TV_Product(777, 'simple'),
    778     => new TV_Product(778, 'variation', 777),
);
WP_Shim::$post_meta[$VENUE]['_azure_seating_layout'] = json_encode(array('blocks' => array(
    array('id' => 'reg', 'type' => 'rectangle', 'name' => 'Regular', 'price' => 12, 'rowCount' => 2, 'seatsPerRow' => 4),
)));
WP_Shim::$post_meta[$SHOW] = array(
    '_ticket_venue_id'         => $VENUE,
    '_ticket_sections'         => json_encode(array('reg' => array('price' => '15', 'limit' => 0, 'on_sale' => true, 'access' => 'all'))),
    '_tickets_per_order_limit' => 4,
    '_ticket_event_date'       => '2026-01-01T19:00',
);
WP_Shim::$post_meta[$FRI] = array('_ticket_event_date' => '2026-11-13T19:00', '_ticket_event_id' => 4100);
WP_Shim::$post_meta[$SAT] = array('_ticket_event_date' => '2026-11-14T14:00', '_ticket_sales_close' => '2026-11-14T12:00', '_ticket_event_id' => 4101);
WP_Shim::$post_meta[$SINGLE] = array('_ticket_venue_id' => $VENUE, '_ticket_event_date' => '2026-11-20T19:00');

$t = new TestRunner('Tickets with variations');
$C = 'Azure_Tickets_Config';
$seat = function ($k) { return array('section_id' => 'reg', 'seat_key' => $k, 'name' => ''); };

// ── Which id holds what ────────────────────────────────────────────────
$t->check($C::owner($FRI) === $SHOW && $C::owner($SHOW) === $SHOW && $C::owner($SINGLE) === $SINGLE, 'a performance variation takes its settings from the product');
$t->check($C::is_ticket_product(wc_get_product($FRI)) && $C::is_ticket_product(wc_get_product($SHOW)) && !$C::is_ticket_product(wc_get_product(778)), 'a variation of a ticket product is a ticket; other variations are not');
$t->equals(array($FRI, $SAT), $C::performances($SHOW), 'the performances are the enabled variations, earliest first');
$t->equals(array($FRI, $SAT), $C::performances($SAT), 'and the same seen from any of them');
$t->check($C::is_performance($FRI) && !$C::is_performance($SHOW) && !$C::is_performance($OFF), 'seats are sold for a variation, not for the product or a disabled variation');
$t->check($C::is_performance($SINGLE) && $C::performances($SINGLE) === array($SINGLE), 'a ticket product without variations is its own single performance');

// ── Shared settings, per-performance dates ─────────────────────────────
$t->check($C::venue_id($FRI) === $VENUE && $C::sections($SAT)['reg']['price'] === 15.0 && $C::order_limit($FRI) === 4, 'venue, section prices and the order limit come from the product');
$t->check($C::event_date($FRI) === '2026-11-13T19:00' && $C::event_id($SAT) === 4101, 'date and linked event are the variation\'s own');
$t->equals('', $C::event_date($OFF), 'a variation without a date does not borrow the product\'s');

// ── Seats belong to the performance ────────────────────────────────────
$r = Azure_Tickets_Cart::prepare($FRI, array('seats' => array($seat('A-1'))), 9, 'cartFri');
$t->check($r['ok'] && $r['lines'][0]['price'] === 15.0, 'a Friday seat is sold at the product\'s section price');
$t->check(Azure_Tickets_Cart::prepare($SAT, array('seats' => array($seat('A-1'))), 9, 'cartSat')['ok'], 'the same seat is still free on Saturday');
$t->check(!Azure_Tickets_Cart::prepare($FRI, array('seats' => array($seat('A-1'))), 8, 'cartOther')['ok'], 'but taken on Friday');
$t->equals(array('A-1' => 'mine'), Azure_Tickets_Inventory::taken($FRI, 'cartFri')['reg'], 'the hold is recorded against the Friday variation');
$t->equals('You can buy up to 4 tickets for this performance.', Azure_Tickets_Cart::prepare($FRI, array('seats' => array($seat('B-1'))), 9, 'cartFri', 4)['error'] ?? '', 'the product\'s order limit applies per performance');

// ── Cart and order lines ───────────────────────────────────────────────
$t->equals($FRI, Azure_Tickets_Cart::item_performance(array('product_id' => $SHOW, 'variation_id' => $FRI)), 'a cart line is for its variation');
$t->equals($SINGLE, Azure_Tickets_Cart::item_performance(array('product_id' => $SINGLE, 'variation_id' => 0)), 'or for the product when it has no variations');

// ── Sales close per performance ────────────────────────────────────────
$cart = Azure_Tickets_Cart::get_instance();
$t->check($cart->is_purchasable(false, wc_get_product($FRI)) && $cart->is_purchasable(false, wc_get_product($SHOW)), 'performances are sellable without a variation price');
$C::$now = strtotime('2026-11-13 08:00:00 America/Los_Angeles');
$t->check($C::sales_closed($FRI) && !$C::sales_closed($SAT), 'Friday closes at midnight before it; Saturday is still open');
$t->equals(array($SAT), $C::open_performances($SHOW), 'so only Saturday is open');
$t->check(!$cart->is_purchasable(true, wc_get_product($FRI)) && $cart->is_purchasable(false, wc_get_product($SAT)) && $cart->is_purchasable(false, wc_get_product($SHOW)), 'Friday can no longer be bought; Saturday and the product can');
$t->equals(array(15.0, 15.0), $C::listed_price_range($SHOW), 'the shop still shows a price');
$C::$now = strtotime('2026-11-14 12:30:00 America/Los_Angeles');
$t->check($C::sales_closed($SAT), 'Saturday closes at the time set on it');
$t->check(!$cart->is_purchasable(true, wc_get_product($SHOW)) && $C::listed_price_range($SHOW) === null, 'with every performance closed the product is not for sale and shows no price');
TV::$post_status[$SHOW] = 'draft';
$C::$now = Azure_Tickets_Inventory::$now;
$t->check(!$cart->is_purchasable(true, wc_get_product($FRI)), 'a performance of a draft product is not for sale');
unset(TV::$post_status[$SHOW]);

// ── Cast & crew lines go to their night ────────────────────────────────
TV::$products[701] = new TV_Product(701, 'variation', 700);
TV::$products[702] = new TV_Product(702, 'variation', 700);
TV::$products[703] = new TV_Product(703, 'variation', 700);
TV::$attributes = array(
    $FRI => array('attribute_pa_performance' => 'friday'),
    $SAT => array('attribute_pa_performance' => 'saturday-matinee'),
    701  => array('attribute_night' => 'Friday'),
    702  => array('attribute_night' => 'Saturday 2pm'),
    703  => array('attribute_night' => 'Cast party'),
);
$A = 'Azure_Tickets_Assign';
$t->equals(array($FRI), $A::line_performances(701, array($FRI, $SAT)), 'a VIP variation with the same option as a performance goes to it');
$t->equals(array($SAT), $A::line_performances(702, array($FRI, $SAT)), 'otherwise it goes to the performance on the weekday it names');
$t->equals(array($FRI, $SAT), $A::line_performances(703, array($FRI, $SAT)), 'one that matches nothing shows on every performance');
$t->equals(array($FRI, $SAT), $A::line_performances(0, array($FRI, $SAT)), 'as does a VIP product without variations');

exit($t->finish() > 0 ? 1 : 0);
