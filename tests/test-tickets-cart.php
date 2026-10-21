<?php
/**
 * Selling seats: section prices and limits, eligible-family sections,
 * accessible seats, per-order limit, holds, cart refresh.
 *
 * Run: php tests/test-tickets-cart.php
 */

require_once __DIR__ . '/wp-shim.php';
require_once __DIR__ . '/sqlite-wpdb.php';

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($s) { return strip_tags((string) $s); }
}
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function _n($single, $plural, $n, $domain = null) { return $n === 1 ? $single : $plural; }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }

class CT_State {
    public static $users = array();
    public static $orders = array();
    public static $order_queries = array();
}
function get_user_by($field, $id) { return CT_State::$users[(int) $id] ?? false; }
function get_user_meta($id, $key, $single = false) { return CT_State::$users[(int) $id]->meta[$key] ?? ''; }

class CT_Item {
    private $p; private $v;
    public function __construct($p, $v = 0) { $this->p = $p; $this->v = $v; }
    public function get_product_id() { return $this->p; }
    public function get_variation_id() { return $this->v; }
}
class CT_Order {
    public $customer; public $date; public $items;
    public function __construct($customer, $date, $items) { $this->customer = $customer; $this->date = $date; $this->items = $items; }
    public function get_items() { return $this->items; }
}
function wc_get_orders($args) {
    CT_State::$order_queries[] = $args;
    $since = substr($args['date_created'], 2);
    return array_values(array_filter(CT_State::$orders, function ($o) use ($args, $since) {
        return in_array($o->customer, $args['customer'], true) && $o->date >= $since;
    }));
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-layout.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-inventory.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-config.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-access.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-cart.php';

$GLOBALS['wpdb'] = tickets_test_wpdb();
Azure_Tickets_Inventory::$now = strtotime('2026-11-01 18:00:00 UTC');

$VENUE = 900;
$FRI = 501;
WP_Shim::$post_meta[$VENUE]['_azure_seating_layout'] = json_encode(array('blocks' => array(
    array('id' => 'par', 'type' => 'rectangle', 'name' => 'Parents of performers', 'price' => 10, 'rowCount' => 2, 'seatsPerRow' => 4, 'seatTypes' => array('A-1' => 'accessible', 'B-4' => 'none')),
    array('id' => 'reg', 'type' => 'rectangle', 'name' => 'Regular', 'price' => 8, 'rowCount' => 3, 'seatsPerRow' => 5, 'startRow' => 'C'),
    array('id' => 'ga', 'type' => 'general_admission', 'name' => 'Back wall', 'price' => 5, 'capacity' => 3),
    array('id' => 'vip', 'type' => 'rectangle', 'name' => 'Reserved for staff', 'price' => 0, 'rowCount' => 1, 'seatsPerRow' => 2),
    array('id' => 'mat', 'type' => 'area', 'label' => 'Gym mat'),
)));
WP_Shim::$post_meta[$FRI] = array(
    '_ticket_venue_id'        => $VENUE,
    '_ticket_sections'        => json_encode(array(
        'par' => array('price' => '', 'limit' => 0, 'on_sale' => true, 'access' => 'families'),
        'reg' => array('price' => '9.5', 'limit' => 6, 'on_sale' => true, 'access' => 'all'),
        'vip' => array('price' => '', 'limit' => 0, 'on_sale' => false, 'access' => 'all'),
    )),
    '_ticket_family_emails'   => "Name,Email\nAda Lovelace, ADA@example.com\nnot-an-email\nbob@example.com;",
    '_ticket_family_products' => array(34228),
    '_ticket_family_since'    => '2026-07-01',
    '_ticket_family_limit'    => 4,
    '_tickets_per_order_limit'=> 8,
);
CT_State::$users = array(
    7 => (object) array('ID' => 7, 'user_email' => 'Ada@Example.com', 'meta' => array()),
    8 => (object) array('ID' => 8, 'user_email' => 'kev@example.com', 'meta' => array('billing_email' => 'tara@example.com')),
    9 => (object) array('ID' => 9, 'user_email' => 'nobody@example.com', 'meta' => array()),
    10 => (object) array('ID' => 10, 'user_email' => 'old@example.com', 'meta' => array()),
);
CT_State::$orders = array(
    new CT_Order('tara@example.com', '2026-09-14', array(new CT_Item(34228, 34230))),
    new CT_Order(10, '2026-03-01', array(new CT_Item(34228))),
    new CT_Order(9, '2026-09-01', array(new CT_Item(777))),
);

$t = new TestRunner('Tickets cart');
$C = 'Azure_Tickets_Cart';
$seat = function ($s, $k, $name = '') { return array('section_id' => $s, 'seat_key' => $k, 'name' => $name); };

// ── Settings ───────────────────────────────────────────────────────────
$sections = Azure_Tickets_Config::sections($FRI);
$t->equals(array('par', 'reg', 'ga', 'vip'), array_keys($sections), 'sellable sections come from the venue layout; the gym mat is not one');
$t->check($sections['par']['price'] === 10.0 && $sections['reg']['price'] === 9.5 && $sections['ga']['price'] === 5.0, 'a blank price uses the layout, a set one overrides it, unset sections use the layout');
$t->check($sections['ga']['on_sale'] && !$sections['vip']['on_sale'], 'sections are on sale unless switched off');
$t->equals(array('ada@example.com', 'bob@example.com'), Azure_Tickets_Config::family_rules($FRI)['emails'], 'emails are picked out of pasted CSV text');
$t->equals(array(5.0, 10.0), Azure_Tickets_Config::price_range($FRI), 'price range covers sections on sale');
$t->equals('2026-07-01', Azure_Tickets_Config::school_year_start(strtotime('2026-10-21')), 'school year starts 1 July');
$t->equals('2025-07-01', Azure_Tickets_Config::school_year_start(strtotime('2026-03-02')), 'and before July it is last year');
$stored = json_decode(Azure_Tickets_Config::sanitize_sections(array('par' => array('price' => '12', 'limit' => '-3', 'on_sale' => '1', 'access' => 'families'), 'zzz' => array('price' => 1)), Azure_Tickets_Config::layout($FRI)), true);
$t->check($stored['par'] == array('price' => 12, 'limit' => 0, 'on_sale' => true, 'access' => 'families') && !isset($stored['zzz']) && $stored['reg']['on_sale'] === false, 'saved settings keep only layout sections; an unticked box is off');

// ── Eligible families ──────────────────────────────────────────────────
$t->check(Azure_Tickets_Access::is_family(7, $FRI), 'on the uploaded list (case-insensitive)');
$t->check(Azure_Tickets_Access::is_family(8, $FRI), 'bought Theater Roles this school year, matched by billing email on a guest order');
$t->check(!Azure_Tickets_Access::is_family(9, $FRI), 'other purchases do not count');
$t->check(!Azure_Tickets_Access::is_family(10, $FRI), 'last school year\'s purchase does not count');
$t->check(!Azure_Tickets_Access::is_family(0, $FRI), 'signed-out visitors are never eligible');
$q = CT_State::$order_queries[0];
$t->check($q['customer'] === array(8, 'kev@example.com', 'tara@example.com') && $q['date_created'] === '>=2026-07-01' && $q['status'] === array('wc-processing', 'wc-completed'), 'the order lookup covers the account id and both emails, paid orders since the start date');

// ── Reserved seats ─────────────────────────────────────────────────────
$r = $C::prepare($FRI, array('seats' => array($seat('reg', 'C-1', ' Leon <b>Tsai</b> '), $seat('reg', 'C-2'))), 9, 'cartA');
$t->check($r['ok'], 'regular seats can be bought by anyone');
$t->equals(array(array('section_id' => 'reg', 'seat_key' => 'C-1', 'label' => 'Regular · Row C, Seat 1', 'name' => 'Leon Tsai'), array('section_id' => 'reg', 'seat_key' => 'C-2', 'label' => 'Regular · Row C, Seat 2', 'name' => '')), $r['lines'][0]['seats'], 'the cart line lists seats with labels and attendee names');
$t->equals(9.5, $r['lines'][0]['price'], 'the line is priced from the section, not the browser');
$r = $C::prepare($FRI, array('seats' => array($seat('reg', 'C-2'))), 8, 'cartB');
$t->check(!$r['ok'] && strpos($r['error'], 'someone else just took Regular · Row C, Seat 2') !== false && !empty($r['taken']), 'a held seat is refused with its label');
$t->check(!$C::prepare($FRI, array('seats' => array($seat('reg', 'Z-9'))), 9, 'cartA')['ok'], 'a seat not in the layout is refused');
$t->check(!$C::prepare($FRI, array('seats' => array($seat('par', 'B-4'))), 7, 'cartA')['ok'], 'a "no seat" space is refused');
$t->check(!$C::prepare($FRI, array('seats' => array($seat('vip', 'A-1'))), 9, 'cartA')['ok'], 'a section off sale is refused');
$t->check(!$C::prepare($FRI, array('seats' => array($seat('reg', 'C-3'), $seat('reg', 'C-3'))), 9, 'cartA')['ok'], 'the same seat twice is refused');
$t->check(!$C::prepare($FRI, array('seats' => array($seat('mat', '1'))), 9, 'cartA')['ok'], 'the gym mat area cannot be bought');
$t->equals('Choose at least one seat.', $C::prepare($FRI, array(), 9, 'cartA')['error'], 'an empty selection');

// ── Limits ─────────────────────────────────────────────────────────────
$r = $C::prepare($FRI, array('seats' => array($seat('reg', 'D-1'), $seat('reg', 'D-2'), $seat('reg', 'D-3'), $seat('reg', 'D-4'), $seat('reg', 'D-5'))), 9, 'cartC');
$t->check(!$r['ok'] && $r['error'] === 'Only 4 seats are left in Regular.', 'a section limit counts seats already held (6 - 2 = 4)');
$r = $C::prepare($FRI, array('seats' => array($seat('reg', 'D-1'))), 9, 'cartC', 8);
$t->equals('You can buy up to 8 tickets for this performance.', $r['error'] ?? '', 'the per-order limit includes tickets already in the cart');

// ── Family section ─────────────────────────────────────────────────────
$r = $C::prepare($FRI, array('seats' => array($seat('par', 'A-2'))), 0, 'guest');
$t->check(!$r['ok'] && !empty($r['login']) && strpos($r['error'], 'sign in') !== false, 'signed-out buyers are asked to sign in for the parents section');
$r = $C::prepare($FRI, array('seats' => array($seat('par', 'A-2'))), 9, 'cartN');
$t->check(!$r['ok'] && strpos($r['error'], 'cast and crew families') !== false, 'non-families are refused');
$t->check($C::prepare($FRI, array('seats' => array($seat('par', 'A-2'), $seat('par', 'A-3'), $seat('par', 'A-4'))), 8, 'cartK')['ok'], 'a cast family can buy parent seats');
$r = $C::prepare($FRI, array('seats' => array($seat('par', 'B-1'), $seat('par', 'B-2'))), 8, 'cartK2');
$t->check(!$r['ok'] && strpos($r['error'], 'up to 4 seats in Parents of performers') !== false && strpos($r['error'], 'already have 3') !== false, 'the family limit counts seats the family already holds, across carts');
$t->check($C::prepare($FRI, array('seats' => array($seat('par', 'B-1'))), 8, 'cartK2')['ok'], 'up to the limit is fine');

// ── Accessible seats ───────────────────────────────────────────────────
$r = $C::prepare($FRI, array('seats' => array($seat('par', 'A-1'))), 7, 'cartAda');
$t->check(!$r['ok'] && strpos($r['error'], 'accessible') !== false, 'an accessible space needs the buyer to confirm the need');
$t->check($C::prepare($FRI, array('seats' => array($seat('par', 'A-1'))), 7, 'cartAda', 0)['ok'] === false, 'still refused without confirmation');
$t->check($C::prepare($FRI, array('seats' => array($seat('par', 'A-1')), 'accessible_ok' => 1), 7, 'cartAda')['ok'], 'and allowed with it');
WP_Shim::$post_meta[$FRI]['_ticket_release_accessible'] = 'yes';
Azure_Tickets_Inventory::release('cartAda', $FRI, array(array('section_id' => 'par', 'seat_key' => 'A-1')));
$t->check($C::prepare($FRI, array('seats' => array($seat('par', 'A-1'))), 7, 'cartAda')['ok'], 'once released to general sale, no confirmation is needed');

// ── General admission ──────────────────────────────────────────────────
$r = $C::prepare($FRI, array('general' => array(array('section_id' => 'ga', 'count' => 2, 'names' => array('Amy', 'Ben')))), 9, 'cartG');
$t->check($r['ok'] && count($r['lines'][0]['seats']) === 2 && $r['lines'][0]['seats'][1]['name'] === 'Ben' && $r['lines'][0]['price'] === 5.0, 'general admission places are held with names and priced');
$r = $C::prepare($FRI, array('seats' => array($seat('reg', 'E-1')), 'general' => array(array('section_id' => 'ga', 'count' => 2))), 9, 'cartH');
$t->check(!$r['ok'] && strpos($r['error'], 'enough places left in Back wall') !== false, 'too few places left is refused');
$t->check(!isset(Azure_Tickets_Inventory::taken($FRI)['reg']['E-1']), 'and the seat in the same request was let go');
$r = $C::prepare($FRI, array('seats' => array($seat('reg', 'E-1')), 'general' => array(array('section_id' => 'ga', 'count' => 1))), 9, 'cartH');
$t->check($r['ok'] && count($r['lines']) === 2, 'mixed reserved and general admission make one line per section');
WP_Shim::$post_meta[$FRI]['_ticket_require_names'] = 'yes';
$r = $C::prepare($FRI, array('seats' => array($seat('reg', 'E-3', 'Cara'), $seat('reg', 'E-4', '  '))), 9, 'cartNm');
$t->equals('Please enter a name for each ticket.', $r['error'] ?? '', 'with names required, a blank name is refused');
$r = $C::prepare($FRI, array('seats' => array($seat('reg', 'E-3', 'Cara'))), 9, 'cartNm');
$t->check($r['ok'] && !isset(Azure_Tickets_Inventory::taken($FRI)['reg']['E-4']), 'named seats go through, and the refused request held nothing');
Azure_Tickets_Inventory::release('cartNm', $FRI, array(array('section_id' => 'reg', 'seat_key' => 'E-3')));
unset(WP_Shim::$post_meta[$FRI]['_ticket_require_names']);

// ── Refreshing a cart ──────────────────────────────────────────────────
$line = $C::prepare($FRI, array('seats' => array($seat('reg', 'E-2'))), 9, 'cartR')['lines'][0]['seats'];
Azure_Tickets_Inventory::$now += Azure_Tickets_Inventory::HOLD_TTL + 60;
$t->equals('', $C::refresh($FRI, $line, 'cartR', 9), 'a lapsed hold is taken back if the seat is still free');
Azure_Tickets_Inventory::$now += Azure_Tickets_Inventory::HOLD_TTL + 60;
$C::prepare($FRI, array('seats' => array($seat('reg', 'E-2'))), 7, 'cartThief');
$t->check(strpos($C::refresh($FRI, $line, 'cartR', 9), 'someone else took') !== false, 'a lost seat removes the cart line with an explanation');
$fam = $C::prepare($FRI, array('seats' => array($seat('par', 'B-2'))), 7, 'cartF')['lines'][0]['seats'];
WP_Shim::$post_meta[$FRI]['_ticket_family_emails'] = 'bob@example.com';
Azure_Tickets_Access::flush();
$t->check(strpos($C::refresh($FRI, $fam, 'cartF', 7), 'for cast and crew families') !== false, 'removing someone from the family list removes their parent seats from the cart');
$fam_ok = $C::prepare($FRI, array('seats' => array($seat('reg', 'E-3'))), 7, 'cartS')['lines'][0]['seats'];
WP_Shim::$post_meta[$FRI]['_ticket_sections'] = json_encode(array('reg' => array('price' => '', 'limit' => 0, 'on_sale' => false, 'access' => 'all')));
$t->check(strpos($C::refresh($FRI, $fam_ok, 'cartS', 7), 'no longer on sale') !== false, 'a section taken off sale removes its seats from carts');

exit($t->finish() > 0 ? 1 : 0);
