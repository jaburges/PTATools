<?php
/**
 * Seat inventory: one buyer per seat per performance, holds that expire,
 * orders that sell or release, general admission counts.
 *
 * Run: php tests/test-tickets-inventory.php
 */

require_once __DIR__ . '/wp-shim.php';
require_once __DIR__ . '/sqlite-wpdb.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-inventory.php';

$GLOBALS['wpdb'] = tickets_test_wpdb();
$wpdb = $GLOBALS['wpdb'];

$t = new TestRunner('Tickets inventory');
$I = 'Azure_Tickets_Inventory';
$I::$now = strtotime('2026-11-13 18:00:00 UTC');
$seat = function ($s, $k) { return array('section_id' => $s, 'seat_key' => $k); };
$FRI = 501;
$SAT = 502;

// ── Holding seats ──────────────────────────────────────────────────────
$r = $I::claim($FRI, array($seat('reg', 'A-1'), $seat('reg', 'A-2')), 'cartA', 7);
$t->check($r['ok'], 'a free pair of seats can be held');
$r = $I::claim($FRI, array($seat('reg', 'A-3'), $seat('reg', 'A-2')), 'cartB');
$t->check(!$r['ok'] && $r['taken'] === array($seat('reg', 'A-2')), 'a seat already held is refused and reported');
$t->equals(array('reg' => array('A-1' => 'taken', 'A-2' => 'taken')), $I::taken($FRI, 'cartB'), 'all or nothing: A-3 was not left held by the failed attempt');
$t->equals(array('reg' => array('A-1' => 'mine', 'A-2' => 'mine')), $I::taken($FRI, 'cartA'), 'the holder sees its own seats as mine');
$t->check($I::claim($SAT, array($seat('reg', 'A-1')), 'cartB')['ok'], 'the same seat at another performance is separate');
$t->equals($I::$now + $I::HOLD_TTL, $I::hold_expires(array('cartA')), 'the hold runs 10 minutes from when it was taken');
$t->equals(0, $I::hold_expires(array('nobody')), 'nothing held, no expiry');
$I::$now += 300;
$r = $I::claim($FRI, array($seat('reg', 'A-1')), 'cartA');
$t->check($r['ok'] && $r['renewed'] === 0, 'claiming your own seat again keeps it');
$t->equals($I::$now - 300 + $I::HOLD_TTL, $I::hold_expires(array('cartA')), 'without extending the hold');

// ── One window per buyer and performance ───────────────────────────────
$t->check($I::claim($FRI, array($seat('reg', 'A-9')), 'cartA')['ok'], 'a seat tapped later in the window can be held');
$t->equals($I::$now - 300 + $I::HOLD_TTL, $I::window_ends($FRI, 'cartA'), 'and it ends with the first one, not 10 minutes from now');
$t->equals(array($seat('reg', 'A-1'), $seat('reg', 'A-2'), $seat('reg', 'A-9')), $I::held_by($FRI, 'cartA'), 'held_by lists what the buyer holds, oldest first');
$t->equals(array(), $I::held_by($SAT, 'cartA'), 'per performance');
$t->equals(0, $I::window_ends($SAT, 'cartA'), 'no window where nothing is held');
$t->equals(array(), $I::held_by($FRI, ''), 'no hold key, nothing held');
$I::release('cartA', $FRI, array($seat('reg', 'A-9')));

// ── Expiry ─────────────────────────────────────────────────────────────
$I::$now += $I::HOLD_TTL - 300 + 1;
$t->equals(array(), $I::taken($FRI), 'holds lapse after 10 minutes, however often the cart is seen');
$t->equals(0, $I::hold_expires(array('cartA')), 'and no longer count down');
$t->equals(array(), $I::held_by($FRI, 'cartA'), 'a lapsed window holds nothing');
$t->check($I::claim($FRI, array($seat('reg', 'A-2')), 'cartB')['ok'], 'and a lapsed seat can be taken by someone else');
$t->equals($I::$now + $I::HOLD_TTL, $I::window_ends($FRI, 'cartB'), 'a new window runs 10 minutes');
$r = $I::claim($FRI, array($seat('reg', 'A-1'), $seat('reg', 'A-2')), 'cartA');
$t->check(!$r['ok'] && $r['taken'] === array($seat('reg', 'A-2')), 'the first cart learns it lost A-2');

// ── Orders ─────────────────────────────────────────────────────────────
$I::claim($FRI, array($seat('par', 'A-1'), $seat('par', 'A-2')), 'cartC', 9);
$t->equals(2, $I::attach_order(900, 'cartC', $FRI, array($seat('par', 'A-1'), $seat('par', 'A-2'))), 'held seats move onto the order');
$t->equals(0, $I::attach_order(901, 'cartZ', $FRI, array($seat('reg', 'A-2'))), 'a cart that does not hold the seat cannot order it');
$I::$now += $I::HOLD_TTL + 1;
$t->equals(array('A-1' => 'taken', 'A-2' => 'taken'), $I::taken($FRI)['par'], 'ordered seats stay taken while payment is pending');
$t->equals(array(), $I::mark_sold(900, array($FRI => array($seat('par', 'A-1'), $seat('par', 'A-2')))), 'paying sells them');
$I::$now += 86400;
$t->equals(array('A-1' => 'taken', 'A-2' => 'taken'), $I::taken($FRI)['par'], 'sold seats never lapse');

$I::claim($FRI, array($seat('par', 'B-1')), 'cartD');
$I::attach_order(902, 'cartD', $FRI, array($seat('par', 'B-1')));
$I::release_order(902);
$t->check(!isset($I::taken($FRI)['par']['B-1']), 'a cancelled order frees its seats');

// Payment arrives after the order's seats were let go.
$I::claim($FRI, array($seat('par', 'C-1'), $seat('par', 'C-2')), 'cartE');
$I::attach_order(903, 'cartE', $FRI, array($seat('par', 'C-1'), $seat('par', 'C-2')));
$I::release_order(903);
$I::claim($FRI, array($seat('par', 'C-2')), 'cartF');
$lost = $I::mark_sold(903, array($FRI => array($seat('par', 'C-1'), $seat('par', 'C-2'))));
$t->equals(array($seat('par', 'C-2') + array('product_id' => $FRI)), $lost, 'a late payment re-claims what it can and reports double-booked seats');
$t->equals('taken', $I::taken($FRI, 'cartF')['par']['C-1'], 'C-1 went back to the paid order');

// ── Cleanup of stale orders ────────────────────────────────────────────
foreach (array(910 => 'paid', 911 => 'open', 912 => 'gone') as $order => $state) {
    $I::claim($SAT, array($seat('reg', 'R-' . $order)), 'k' . $order);
    $I::attach_order($order, 'k' . $order, $SAT, array($seat('reg', 'R-' . $order)));
}
$I::claim($SAT, array($seat('reg', 'H-1')), 'kHold');
$I::$now += $I::ORDER_TTL + 1;
$states = array(910 => 'paid', 911 => 'open', 912 => 'failed');
$I::cleanup(function ($id) use ($states) { return $states[$id]; });
$rows = $wpdb->get_results("SELECT seat_key, status FROM wp_azure_ticket_inventory WHERE product_id = $SAT ORDER BY seat_key", ARRAY_A);
$t->equals(array(array('seat_key' => 'R-910', 'status' => 'sold'), array('seat_key' => 'R-911', 'status' => 'ordered')), $rows, 'cleanup: paid orders sell, open ones wait, failed ones and lapsed holds go');

// ── General admission ──────────────────────────────────────────────────
$keys = $I::claim_general($FRI, 'ga', 3, 5, 'cartG', 0);
$t->equals(array('1', '2', '3'), $keys, 'general admission takes the lowest free places');
$t->check($I::claim_general($FRI, 'ga', 3, 5, 'cartH') === false, 'and refuses more than are left');
$t->equals(3, $I::count_taken($FRI, array('ga')), 'the failed request left nothing behind');
$t->equals(array('4', '5'), $I::claim_general($FRI, 'ga', 2, 5, 'cartH'), 'the last two places');

// ── Counting for limits ────────────────────────────────────────────────
$I::claim($FRI, array($seat('par', 'D-1'), $seat('par', 'D-2')), 'cartP', 42);
$t->equals(3 + 2, $I::count_taken($FRI, array('par')), 'section count includes sold (A-1, A-2, C-1) and held seats');
$t->equals(3, $I::count_taken($FRI, array('par'), 'cartP'), 'a cart does not count against itself');
$t->equals(2, $I::count_taken($FRI, array('par', 'reg'), '', 42), 'per-buyer count');
$t->equals(0, $I::count_taken($FRI, array()), 'no sections, nothing counted');

// ── Seats assigned by hand to a VIP order ──────────────────────────────
$VIP = 777;
$t->check($I::assign($VIP, $FRI, array($seat('front', 'A-5'), $seat('front', 'A-6')))['ok'], 'free seats can be assigned to a VIP order');
$t->equals(array('A-5' => 'taken', 'A-6' => 'taken'), $I::taken($FRI)['front'], 'assigned seats are taken');
$t->check($I::assign($VIP, $FRI, array($seat('front', 'A-5')))['ok'], 'assigning a seat the order already has is fine');
$I::claim($FRI, array($seat('front', 'A-7')), 'cartV');
$r = $I::assign($VIP, $FRI, array($seat('front', 'A-8'), $seat('front', 'A-7')));
$t->check(!$r['ok'] && $r['taken'] === array($seat('front', 'A-7')), 'a seat in someone\'s cart cannot be assigned');
$t->check(!isset($I::taken($FRI)['front']['A-8']), 'all or nothing: A-8 was not left assigned');
$t->check(!$I::assign(778, $FRI, array($seat('front', 'A-5')))['ok'], 'another order cannot be given an assigned seat');
$I::unassign($VIP, $FRI, array($seat('front', 'A-6')));
$t->equals(array('A-5' => 'taken', 'A-7' => 'taken'), $I::taken($FRI)['front'], 'unassigning frees just that seat');
$I::$now += 86400 * 30;
$I::cleanup(function () { return 'failed'; });
$t->equals('taken', $I::taken($FRI)['front']['A-5'] ?? '', 'assigned seats survive cleanup');
$I::release_order($VIP);
$t->check(!isset($I::taken($FRI)['front']['A-5']), 'cancelling the VIP order frees its seats');

// ── Seat Reservation Timeout setting ───────────────────────────────────
$t->equals(600, $I::hold_ttl(), 'unset, a buyer has 10 minutes');
WP_Shim::$settings['tickets_reservation_timeout'] = 15;
$t->equals(900, $I::hold_ttl(), 'the setting changes the window');
$I::claim($SAT, array($seat('reg', 'Z-1')), 'cartT');
$t->equals($I::$now + 900, $I::window_ends($SAT, 'cartT'), 'and new holds use it');
WP_Shim::$settings['tickets_reservation_timeout'] = 1;
$t->equals(300, $I::hold_ttl(), 'never less than 5 minutes');
WP_Shim::$settings['tickets_reservation_timeout'] = 600;
$t->equals(3600, $I::hold_ttl(), 'or more than 60');
WP_Shim::$settings = array();

exit($t->finish() > 0 ? 1 : 0);
