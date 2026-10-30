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
Azure_Tickets_Config::$now = Azure_Tickets_Inventory::$now;

$VENUE = 900;
$FRI = 501;
WP_Shim::$post_meta[$VENUE]['_azure_seating_layout'] = json_encode(array('blocks' => array(
    array('id' => 'par', 'type' => 'rectangle', 'name' => 'Parents of performers', 'price' => 10, 'rowCount' => 2, 'seatsPerRow' => 4, 'seatTypes' => array('A-1' => 'accessible', 'B-4' => 'none')),
    array('id' => 'reg', 'type' => 'rectangle', 'name' => 'Regular', 'price' => 8, 'rowCount' => 3, 'seatsPerRow' => 5, 'startRow' => 'C'),
    array('id' => 'ga', 'type' => 'general_admission', 'name' => 'Back wall', 'price' => 5, 'capacity' => 3),
    array('id' => 'vip', 'type' => 'rectangle', 'name' => 'Reserved for staff', 'price' => 0, 'rowCount' => 1, 'seatsPerRow' => 2),
    array('id' => 'mat', 'type' => 'area', 'label' => 'Gym mat'),
    array('id' => 'wc', 'type' => 'rectangle', 'name' => 'Aisle', 'price' => 10, 'rowCount' => 1, 'seatsPerRow' => 4, 'startRow' => 'F', 'seatTypes' => array('F-1' => 'accessible', 'F-2' => 'companion', 'F-3' => 'companion')),
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
$t->equals(array('par', 'reg', 'ga', 'vip', 'wc'), array_keys($sections), 'sellable sections come from the venue layout; the gym mat is not one');
$t->check($sections['par']['price'] === 10.0 && $sections['reg']['price'] === 9.5 && $sections['ga']['price'] === 5.0, 'a blank price uses the layout, a set one overrides it, unset sections use the layout');
$t->check($sections['ga']['on_sale'] && !$sections['vip']['on_sale'], 'sections are on sale unless switched off');
$t->equals(array('ada@example.com', 'bob@example.com'), Azure_Tickets_Config::family_rules($FRI)['emails'], 'emails are picked out of pasted CSV text');
$t->equals(array(5.0, 10.0), Azure_Tickets_Config::price_range($FRI), 'price range covers sections on sale');
$t->equals('2026-07-01', Azure_Tickets_Config::school_year_start(strtotime('2026-10-21')), 'school year starts 1 July');
$t->equals('2025-07-01', Azure_Tickets_Config::school_year_start(strtotime('2026-03-02')), 'and before July it is last year');
$stored = json_decode(Azure_Tickets_Config::sanitize_sections(array('par' => array('price' => '12', 'limit' => '-3', 'on_sale' => '1', 'access' => 'families'), 'zzz' => array('price' => 1)), Azure_Tickets_Config::layout($FRI)), true);
$t->check($stored['par'] == array('price' => 12, 'limit' => 0, 'on_sale' => true, 'from' => '', 'access' => 'families') && !isset($stored['zzz']) && $stored['reg']['on_sale'] === false, 'saved settings keep only layout sections; an unticked box is off');

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
$t->check(!$r['ok'] && strpos($r['error'], 'wheelchair space') !== false, 'an accessible space needs the buyer to confirm the need');
$t->check($C::prepare($FRI, array('seats' => array($seat('par', 'A-1'))), 7, 'cartAda', 0)['ok'] === false, 'still refused without confirmation');
$t->check($C::prepare($FRI, array('seats' => array($seat('par', 'A-1')), 'accessible_ok' => 1), 7, 'cartAda')['ok'], 'and allowed with it');

// ── Wheelchair spaces, companion seats, ADA tickets ────────────────────
$r = $C::prepare($FRI, array('seats' => array($seat('wc', 'F-2')), 'accessible_ok' => 1), 9, 'cartW');
$t->check(!$r['ok'] && strpos($r['error'], 'Companion seats') !== false, 'a companion seat on its own is refused');
$r = $C::prepare($FRI, array('seats' => array($seat('wc', 'F-1'), $seat('wc', 'F-2'), $seat('wc', 'F-3')), 'accessible_ok' => 1), 9, 'cartW');
$t->check(!$r['ok'] && strpos($r['error'], 'Companion seats') !== false, 'one wheelchair space brings one companion seat');
$r = $C::prepare($FRI, array('seats' => array($seat('wc', 'F-1'), $seat('wc', 'F-2'), $seat('wc', 'F-4')), 'accessible_ok' => 1), 9, 'cartW');
$kinds = $r['ok'] ? array_column($r['lines'], 'kind') : array();
$t->equals(array('ada', 'regular'), $kinds, 'a wheelchair space is an ADA ticket; the companion and the plain seat are Regular');
$t->check($r['ok'] && $r['lines'][0]['price'] === 10.0 && count($r['lines'][1]['seats']) === 2, 'with no ADA price set, ADA tickets cost the section price');
$t->check($r['ok'] && $r['lines'][0]['attested'] !== '' && $r['lines'][1]['attested'] === '', 'the attestation time goes on the ADA line only');
Azure_Tickets_Inventory::release('cartW', $FRI, array($seat('wc', 'F-1'), $seat('wc', 'F-2'), $seat('wc', 'F-4')));
WP_Shim::$post_meta[$FRI]['_ticket_ada_price'] = '7.5';
$r = $C::prepare($FRI, array('seats' => array($seat('wc', 'F-1')), 'accessible_ok' => 1), 9, 'cartW');
$t->check($r['ok'] && $r['lines'][0]['price'] === 7.5, 'an ADA price overrides the section price');
Azure_Tickets_Inventory::release('cartW', $FRI, array($seat('wc', 'F-1')));
unset(WP_Shim::$post_meta[$FRI]['_ticket_ada_price']);

// ── Sections that open later, performances that close ──────────────────
$opens = json_decode(WP_Shim::$post_meta[$FRI]['_ticket_sections'], true);
$opens['wc'] = array('price' => '', 'limit' => 0, 'on_sale' => true, 'from' => '2026-11-02T09:00', 'access' => 'all');
WP_Shim::$post_meta[$FRI]['_ticket_sections'] = json_encode($opens);
$s = Azure_Tickets_Config::sections($FRI)['wc'];
$t->check($s['enabled'] && !$s['on_sale'] && $s['opens_at'] > Azure_Tickets_Config::now(), 'a section with a future opening time is held back');
$t->check(!$C::prepare($FRI, array('seats' => array($seat('wc', 'F-4'))), 9, 'cartO')['ok'], 'and its seats cannot be bought yet');
Azure_Tickets_Config::$now = strtotime('2026-11-02 18:00:00 UTC');
$t->check(Azure_Tickets_Config::sections($FRI)['wc']['on_sale'], 'it opens on time');
Azure_Tickets_Config::$now = Azure_Tickets_Inventory::$now;
unset($opens['wc']);
WP_Shim::$post_meta[$FRI]['_ticket_sections'] = json_encode($opens);

WP_Shim::$post_meta[$FRI]['_ticket_event_date'] = '2026-11-02T19:00';
$t->check(!Azure_Tickets_Config::sales_closed($FRI), 'sales are open the day before the performance');
WP_Shim::$post_meta[$FRI]['_ticket_event_date'] = '2026-11-01T19:00';
$t->check(Azure_Tickets_Config::sales_closed($FRI), 'and close at midnight before it');
$t->check(strpos($C::prepare($FRI, array('seats' => array($seat('reg', 'E-5'))), 9, 'cartX')['error'] ?? '', 'closed') !== false, 'a closed performance sells nothing');
$t->equals(null, Azure_Tickets_Config::listed_price_range($FRI), 'and shows no price');
WP_Shim::$post_meta[$FRI]['_ticket_sales_close'] = '2026-11-01T20:00';
$t->check(!Azure_Tickets_Config::sales_closed($FRI), 'a closing time set on the product wins');
unset(WP_Shim::$post_meta[$FRI]['_ticket_event_date'], WP_Shim::$post_meta[$FRI]['_ticket_sales_close']);

// ── Seat-assignment (VIP) products stop selling when front rows open ───
update_option('azure_tickets_vip_close', array(34300 => strtotime('2026-10-30 09:00:00 UTC')));
$t->equals(strtotime('2026-10-30 09:00:00 UTC'), Azure_Tickets_Config::vip_close_ts(34301, 34300), 'a variation closes with its parent');
$t->equals(0, Azure_Tickets_Config::vip_close_ts(999), 'other products never close');
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
Azure_Tickets_Inventory::$now += Azure_Tickets_Inventory::HOLD_TTL - 60;
$t->equals('', $C::refresh($FRI, $line, 'cartR', 9), 'a cart line is kept while its hold has time left');
Azure_Tickets_Inventory::$now += 120;
$t->equals('Your 10 minutes to check out ran out, so your seats were let go and removed from your cart. Please choose them again.', $C::refresh($FRI, $line, 'cartR', 9),
    'once the hold runs out the line is removed, even though the seat is still free');
$t->check(!isset(Azure_Tickets_Inventory::taken($FRI)['reg']['E-2']), 'and the seat is not held again');
$line = $C::prepare($FRI, array('seats' => array($seat('reg', 'E-2'))), 9, 'cartR2')['lines'][0]['seats'];
Azure_Tickets_Inventory::$now += Azure_Tickets_Inventory::HOLD_TTL + 60;
$C::prepare($FRI, array('seats' => array($seat('reg', 'E-2'))), 7, 'cartThief');
$t->check(strpos($C::refresh($FRI, $line, 'cartR2', 9), 'ran out') !== false && isset(Azure_Tickets_Inventory::taken($FRI, 'cartThief')['reg']['E-2']), 'a lapsed line whose seat someone else took is removed, and they keep it');
Azure_Tickets_Inventory::release('cartThief', $FRI, array(array('section_id' => 'reg', 'seat_key' => 'E-2')));
$fam = $C::prepare($FRI, array('seats' => array($seat('par', 'B-2'))), 7, 'cartF')['lines'][0]['seats'];
WP_Shim::$post_meta[$FRI]['_ticket_family_emails'] = 'bob@example.com';
Azure_Tickets_Access::flush();
$t->check(strpos($C::refresh($FRI, $fam, 'cartF', 7), 'for cast and crew families') !== false, 'removing someone from the family list removes their parent seats from the cart');
$fam_ok = $C::prepare($FRI, array('seats' => array($seat('reg', 'E-3'))), 7, 'cartS')['lines'][0]['seats'];
WP_Shim::$post_meta[$FRI]['_ticket_sections'] = json_encode(array('reg' => array('price' => '', 'limit' => 0, 'on_sale' => false, 'access' => 'all')));
$t->check(strpos($C::refresh($FRI, $fam_ok, 'cartS', 7), 'no longer on sale') !== false, 'a section taken off sale removes its seats from carts');

// ── Holding seats as they are tapped ───────────────────────────────────
$SAT = 502;
WP_Shim::$post_meta[$SAT] = array(
    '_ticket_venue_id'        => $VENUE,
    '_ticket_sections'        => json_encode(array(
        'par' => array('price' => '', 'limit' => 0, 'on_sale' => true, 'access' => 'families'),
        'reg' => array('price' => '', 'limit' => 0, 'on_sale' => true, 'access' => 'all'),
        'vip' => array('price' => '', 'limit' => 0, 'on_sale' => false, 'access' => 'all'),
        'wc'  => array('price' => '', 'limit' => 0, 'on_sale' => true, 'access' => 'all'),
    )),
    '_ticket_family_emails'   => 'ada@example.com',
    '_ticket_family_limit'    => 2,
    '_tickets_per_order_limit'=> 4,
);
Azure_Tickets_Access::flush();
$tap = function ($s, $k) { return array('section_id' => $s, 'seat_key' => $k); };
$start = Azure_Tickets_Inventory::$now;

$t->check($C::pick($SAT, $tap('reg', 'C-1'), 0, 'tapA')['ok'], 'a guest can hold a regular seat by tapping it');
$t->equals(array($tap('reg', 'C-1')), $C::picked($SAT, 'tapA'), 'it is listed as theirs');
$map = $C::seat_map_data($SAT, 0, 'tapA', false);
$t->check(!isset($map['layout']) && $map['picked'] === array($tap('reg', 'C-1')) && !isset(((array) $map['taken'])['reg']['C-1']), 'the answer to their tap lists it as chosen, not taken, and skips the layout');
$shared = $C::shared_state($SAT);
$t->equals(array('taken', 'sections'), array_keys($shared), 'the shared refresh carries nothing about the asker');
$t->equals(array('C-1' => 'taken'), ((array) $shared['taken'])['reg'], 'and shows the held seat as taken to everyone');
$t->equals(array('on_sale' => true, 'left' => 14), $shared['sections']['reg'], 'with seats left per section (15 - 1)');
$t->equals(array('on_sale' => false, 'left' => 2), $shared['sections']['vip'], 'and which sections are on sale');
$t->equals(Azure_Tickets_Inventory::HOLD_TTL, $map['hold_left'], 'and starts their 10 minutes');
$t->equals(array('C-1' => 'taken'), ((array) $C::seat_map_data($SAT, 0, 'tapB', false)['taken'])['reg'], 'everyone else sees it taken straight away');
$r = $C::pick($SAT, $tap('reg', 'C-1'), 0, 'tapB');
$t->check(!$r['ok'] && !empty($r['taken']) && strpos($r['error'], 'someone else just took Regular · Row C, Seat 1') !== false, 'a second buyer tapping it is told at once');
$t->check($C::pick($SAT, $tap('reg', 'C-1'), 0, 'tapA')['ok'] && count($C::picked($SAT, 'tapA')) === 1, 'tapping your own seat again changes nothing');
$t->check(isset($C::seat_map_data($SAT, 0, 'tapA', true)['layout']), 'the first load still has the layout');

Azure_Tickets_Inventory::$now += 300;
$C::pick($SAT, $tap('reg', 'C-2'), 0, 'tapA');
$t->equals(Azure_Tickets_Inventory::HOLD_TTL - 300, $C::seat_map_data($SAT, 0, 'tapA', false)['hold_left'], 'a seat tapped 5 minutes in ends with the first: one 10-minute window');

$r = $C::pick($SAT, $tap('reg', 'C-3'), 0, 'tapA', array($tap('reg', 'D-1'), $tap('reg', 'D-2')));
$t->equals('You can buy up to 4 tickets for this performance.', $r['error'] ?? '', 'the order limit counts tapped seats and the cart');
$t->check(!$C::pick($SAT, $tap('vip', 'A-1'), 0, 'tapA')['ok'], 'a section off sale cannot be tapped');
$t->check(!$C::pick($SAT, $tap('reg', 'Z-9'), 0, 'tapA')['ok'], 'nor a seat that is not in the layout');
$t->check(!$C::pick($SAT, $tap('mat', '1'), 0, 'tapA')['ok'], 'nor the gym mat');

$r = $C::pick($SAT, $tap('wc', 'F-1'), 0, 'tapW');
$t->check(!$r['ok'] && strpos($r['error'], 'wheelchair space') !== false, 'an ADA space needs the buyer to confirm the need');
$r = $C::pick($SAT, $tap('wc', 'F-2'), 0, 'tapW');
$t->check(!$r['ok'] && strpos($r['error'], 'Choose the ADA space first') !== false, 'a companion seat on its own is refused');
$t->check($C::pick($SAT, $tap('wc', 'F-1'), 0, 'tapW', array(), true)['ok'], 'an ADA space can be held once confirmed');
$t->check($C::pick($SAT, $tap('wc', 'F-2'), 0, 'tapW')['ok'], 'and then its companion seat');
$t->check(!$C::pick($SAT, $tap('wc', 'F-3'), 0, 'tapW')['ok'], 'but not a second companion seat');

$r = $C::pick($SAT, $tap('par', 'A-2'), 0, 'tapG');
$t->check(!$r['ok'] && !empty($r['login']), 'signed-out buyers are asked to sign in for the parents section');
$t->check(strpos($C::pick($SAT, $tap('par', 'A-2'), 9, 'tapN')['error'] ?? '', 'cast and crew families') !== false, 'non-families cannot hold parent seats');
$t->check($C::pick($SAT, $tap('par', 'A-2'), 7, 'tapF')['ok'] && $C::pick($SAT, $tap('par', 'A-3'), 7, 'tapF')['ok'], 'a cast family can');
$t->check(strpos($C::pick($SAT, $tap('par', 'A-4'), 7, 'tapF')['error'] ?? '', 'up to 2 seats') !== false, 'up to the family limit');

$C::unpick($SAT, array($tap('reg', 'C-2')), 'tapA');
$t->equals(array($tap('reg', 'C-1')), $C::picked($SAT, 'tapA'), 'tapping a seat again lets it go');
$t->check($C::pick($SAT, $tap('reg', 'C-2'), 0, 'tapB')['ok'], 'and someone else can have it');
$C::unpick($SAT, array($tap('reg', 'C-2')), 'tapA');
$t->equals(array($tap('reg', 'C-2')), $C::picked($SAT, 'tapB'), 'nobody can let go of someone else\'s seat');
$C::unpick($SAT, array($tap('reg', 'C-1')), 'tapA', array($tap('reg', 'C-1')));
$t->equals(array($tap('reg', 'C-1')), Azure_Tickets_Inventory::held_by($SAT, 'tapA'), 'a seat already in the cart is not let go from the map');
$t->equals(array(), $C::picked($SAT, 'tapA', array($tap('reg', 'C-1'))), 'and does not count as tapped');

// Add to cart takes the tapped seats as they are.
$ends = Azure_Tickets_Inventory::window_ends($SAT, 'tapA');
$r = $C::prepare($SAT, array('seats' => array($seat('reg', 'C-1'), $seat('reg', 'C-3'))), 0, 'tapA');
$t->check($r['ok'] && Azure_Tickets_Inventory::window_ends($SAT, 'tapA') === $ends, 'adding tapped seats to the cart keeps the same countdown');
WP_Shim::$post_meta[$SAT]['_ticket_sections'] = json_encode(array(
    'reg' => array('price' => '', 'limit' => 0, 'on_sale' => true, 'access' => 'all'),
    'ga'  => array('price' => '', 'limit' => 1, 'on_sale' => true, 'access' => 'all'),
));
$C::pick($SAT, $tap('reg', 'E-1'), 0, 'tapH');
$r = $C::prepare($SAT, array('seats' => array($seat('reg', 'E-1'), $seat('reg', 'E-2')), 'general' => array(array('section_id' => 'ga', 'count' => 3))), 0, 'tapH');
$t->check(!$r['ok'] && $C::picked($SAT, 'tapH') === array($tap('reg', 'E-1')), 'a refused add to cart lets go of what it added but keeps the tapped seat');

// The window closes.
$t->equals($start + 300 + Azure_Tickets_Inventory::HOLD_TTL, Azure_Tickets_Inventory::window_ends($SAT, 'tapB'), 'each buyer has their own window');
Azure_Tickets_Inventory::$now = Azure_Tickets_Inventory::window_ends($SAT, 'tapB') + 1;
$t->equals(array(), $C::picked($SAT, 'tapB'), 'after 10 minutes the tapped seats are gone');
$map = $C::seat_map_data($SAT, 0, 'tapB', false);
$t->check($map['hold_left'] === 0 && $map['picked'] === array(), 'and the countdown stops');
$t->check($C::pick($SAT, $tap('reg', 'C-2'), 0, 'tapZ')['ok'], 'so others can take them');

exit($t->finish() > 0 ? 1 : 0);
