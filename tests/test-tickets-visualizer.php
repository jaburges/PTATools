<?php
/**
 * Tickets → Visualizer: who has each seat, the outline around seats bought
 * together, the counts, and the four-page printout.
 *
 * Run: php tests/test-tickets-visualizer.php
 */

require_once __DIR__ . '/wp-shim.php';
require_once __DIR__ . '/sqlite-wpdb.php';

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($s) { return strip_tags((string) $s); }
}
if (!function_exists('esc_url')) {
    function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES); }
}
if (!function_exists('esc_html__')) {
    function esc_html__($s) { return esc_html($s); }
}
if (!function_exists('esc_html_e')) {
    function esc_html_e($s) { echo esc_html($s); }
}
if (!function_exists('get_the_title')) {
    function get_the_title($id) { return 'School Gym'; }
}
if (!function_exists('mysql2date')) {
    function mysql2date($format, $date) { return date($format, strtotime($date)); }
}
if (!function_exists('get_user_by')) {
    function get_user_by($field, $id) { return (object) array('display_name' => 'Pat Buyer'); }
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-layout.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-inventory.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-config.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-visualizer.php';

$V = 'Azure_Tickets_Visualizer';
$t = new TestRunner('Tickets visualizer');

// ── Rows, outlines ─────────────────────────────────────────────────────
$block = Azure_Tickets_Layout::sanitize(array('blocks' => array(array(
    'id' => 'L', 'type' => 'rectangle', 'name' => 'Left', 'rowCount' => 3, 'seatsPerRow' => 6,
    'seatTypes' => array('A-5' => 'accessible', 'A-6' => 'none', 'C-3' => 'none'),
))))['blocks'][0];

$cells = $V::row_cells($block, 'A');
$t->equals(array('A-1', 'A-2', 'A-3', 'A-4', 'A-5'), array_column($cells, 'key'), 'a wheelchair space beside "no seat" is one cell');
$t->equals(2, $cells[4]['span'], '…two chairs wide');

// Order 7 has A1, A2, B1, B2 (a square); order 8 has A3 next to it; C2 and C4 sit either side of a gap.
$groups = array('A-1' => 'o7', 'A-2' => 'o7', 'B-1' => 'o7', 'B-2' => 'o7', 'A-3' => 'o8', 'C-2' => 'o9', 'C-4' => 'o9');
$sides = $V::outlines($block, $groups);
$t->equals('lt', $sides['A-1'], 'top-left of the square: outline left and top only');
$t->equals('tr', $sides['A-2'], 'top-right: top and right (A3 belongs to another order)');
$t->equals('lb', $sides['B-1'], 'bottom-left: left and bottom');
$t->equals('rb', $sides['B-2'], 'bottom-right: right and bottom');
$t->equals('ltrb', $sides['A-3'], 'a single seat next to another order is outlined all round');
$t->equals('ltrb', $sides['C-2'], 'seats either side of a "no seat" gap are not joined');
$t->check(!isset($sides['A-4']), 'free seats get no outline');

// ── Merging inventory and tickets ──────────────────────────────────────
$inventory = array(
    array('section_id' => 'L', 'seat_key' => 'A-1', 'status' => 'sold', 'hold_key' => '', 'user_id' => 0, 'order_id' => 7, 'expires_at' => null),
    array('section_id' => 'L', 'seat_key' => 'A-2', 'status' => 'sold', 'hold_key' => '', 'user_id' => 0, 'order_id' => 7, 'expires_at' => null),
    array('section_id' => 'L', 'seat_key' => 'B-4', 'status' => 'held', 'hold_key' => 'secret-hold-key', 'user_id' => 3, 'order_id' => 0, 'expires_at' => '2026-11-13 02:10:00'),
    array('section_id' => 'L', 'seat_key' => 'B-5', 'status' => 'held', 'hold_key' => 'secret-hold-key', 'user_id' => 3, 'order_id' => 0, 'expires_at' => '2026-11-13 02:10:00'),
    array('section_id' => 'L', 'seat_key' => 'C-1', 'status' => 'ordered', 'hold_key' => 'k2', 'user_id' => 0, 'order_id' => 12, 'expires_at' => '2026-11-13 03:00:00'),
    array('section_id' => 'GA', 'seat_key' => '1', 'status' => 'sold', 'hold_key' => '', 'user_id' => 0, 'order_id' => 20, 'expires_at' => null),
    array('section_id' => 'GA', 'seat_key' => '2', 'status' => 'sold', 'hold_key' => '', 'user_id' => 0, 'order_id' => 20, 'expires_at' => null),
    array('section_id' => 'GA', 'seat_key' => '3', 'status' => 'held', 'hold_key' => 'k3', 'user_id' => 0, 'order_id' => 0, 'expires_at' => '2026-11-13 02:10:00'),
);
$tickets = array(
    array('order_id' => 7, 'section_id' => 'L', 'row_letter' => 'A', 'seat_number' => 1, 'attendee_name' => 'Wendy Darling', 'status' => 'used', 'checked_in_at' => '2026-11-13 18:55:00'),
    array('order_id' => 7, 'section_id' => 'L', 'row_letter' => 'A', 'seat_number' => 2, 'attendee_name' => ' John Darling ', 'status' => 'active', 'checked_in_at' => null),
    array('order_id' => 5, 'section_id' => 'L', 'row_letter' => 'A', 'seat_number' => 4, 'attendee_name' => 'Old Sale', 'status' => 'active', 'checked_in_at' => null),
    array('order_id' => 20, 'section_id' => 'GA', 'row_letter' => null, 'seat_number' => null, 'attendee_name' => 'Floor', 'status' => 'used', 'checked_in_at' => '2026-11-13 18:50:00'),
);
$occ = $V::merge($inventory, $tickets, array('GA'));
$seats = $occ['seats']['L'];
$t->equals('checked_in', $seats['A-1']['state'], 'a used ticket shows as checked in');
$t->equals('Wendy Darling', $seats['A-1']['name'], 'with the ticket\'s name');
$t->equals('sold', $seats['A-2']['state'], 'an active ticket on a sold seat is sold');
$t->equals('John Darling', $seats['A-2']['name'], 'names are trimmed');
$t->equals('o7', $seats['A-1']['group'], 'seats on one order share a group');
$t->equals($seats['A-1']['group'], $seats['A-2']['group'], '…both of them');
$t->equals('sold', $seats['A-4']['state'], 'a ticket with no inventory row still shows as sold');
$t->equals(5, $seats['A-4']['order'], '…to its order');
$t->equals('held', $seats['B-4']['state'], 'a cart hold shows as held');
$t->equals($seats['B-4']['group'], $seats['B-5']['group'], 'one cart\'s seats are grouped');
$t->check(strpos(json_encode($occ), 'secret-hold-key') === false, 'hold keys are not carried into the result');
$t->equals((int) strtotime('2026-11-13 02:10:00 UTC'), $seats['B-4']['until'], 'hold expiry is read as UTC');
$t->equals(0, $seats['B-4']['order'], 'a hold has no order');
$t->equals('ordered', $seats['C-1']['state'], 'a seat on an unpaid order is awaiting payment');
$t->equals(array('sold' => 2, 'checked_in' => 1, 'ordered' => 0, 'held' => 1, 'orders' => array(20 => 2)), $occ['general']['GA'], 'general admission is counted, not drawn seat by seat');

// ── Counts ─────────────────────────────────────────────────────────────
$sections = array(
    'L'  => array('type' => 'rectangle', 'name' => 'Left', 'enabled' => true, 'capacity' => 16, 'block' => $block),
    'GA' => array('type' => 'general_admission', 'name' => 'Floor', 'enabled' => false, 'capacity' => 10, 'block' => array()),
);
$st = $V::stats($sections, $occ);
$t->equals(26, $st['capacity'], 'capacity counts every seat and general admission place');
$t->equals(5, $st['sold'], 'sold includes checked in: A1, A2, A4 and two on the floor');
$t->equals(2, $st['checked_in'], 'checked in: A1 and one on the floor');
$t->equals(3, $st['held'], 'held: B4, B5 and a floor place');
$t->equals(1, $st['ordered'], 'awaiting payment: C1');
$t->equals(10, $st['free'], 'free seats in a section on sale');
$t->equals(7, $st['free_closed'], 'free places in a section switched off are counted apart');

// ── Names and the area drawn ───────────────────────────────────────────
$t->equals(array('O.', 'Fowler'), $V::name_parts('Olivia Botello Fowler'), 'printed seats get initial and surname');
$t->equals(array('', 'Nana'), $V::name_parts(' Nana '), 'a single name is kept as the surname line');
$t->equals(array('', ''), $V::name_parts(''), 'no name, nothing printed');

$layout = array('canvas' => array('width' => 800, 'height' => 600), 'blocks' => array(
    array('id' => 's', 'type' => 'stage', 'x' => 100, 'y' => 20, 'width' => 400, 'height' => 40, 'color' => '', 'label' => 'STAGE'),
    array('id' => 'a', 'type' => 'aisle', 'x' => 0, 'y' => 0, 'width' => 790, 'height' => 590, 'color' => ''),
    array('id' => 'r', 'type' => 'rectangle', 'x' => 200, 'y' => 200, 'width' => 100, 'height' => 100, 'color' => '', 'angle' => 45),
));
$box = $V::bounds($layout, 0);
$t->equals(array('x' => 100, 'y' => 20), array('x' => $box['x'], 'y' => $box['y']), 'the drawing is cropped to its blocks (aisles are not drawn)');
$t->check(abs($box['w'] - 400) <= 1 && abs($box['h'] - (250 + 70.71 - 20)) <= 2, 'a rotated block counts with its rotated corners', json_encode($box));

// ── Map HTML ───────────────────────────────────────────────────────────
$occ['seats']['L']['A-2']['name'] = 'John <script>alert(1)</script>';
$html = $V::map_html(array('canvas' => array('width' => 800, 'height' => 600), 'blocks' => array($block)), $sections, $occ, array('names' => true, 'buyers' => array(12 => 'Unpaid Person')));
$t->check(strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;') !== false, 'names are escaped');
$t->check(preg_match('/class="ptv-seat is-checked-in ptv-g ptv-gl ptv-gt ptv-gb"[^>]*data-k="A-1"/', $html) === 1, 'the checked-in seat carries its outline sides', '');
$t->check(strpos($html, '<b>1 <small>W.</small></b><i>Darling</i>') !== false, 'the seat number carries the initial, the surname has its own line');
$t->check(strpos($html, '<small>U.</small></b><i>Person</i>') !== false, 'a seat with no ticket name prints the buyer');
$t->check(preg_match('/class="ptv-seat is-free"[^>]*data-k="A-3"/', $html) === 1, 'a free seat in a section on sale is free');
$t->check(strpos($html, 'data-g="h1"') !== false && strpos($html, 'secret-hold-key') === false, 'holds are grouped by number only');
$t->check(strpos($html, 'tabindex') === false, 'printed seats are not focusable');
$t->check(strpos($V::map_html(array('canvas' => array('width' => 800, 'height' => 600), 'blocks' => array($block)), $sections, $occ, array('focusable' => true)), 'tabindex="0"') !== false, 'on screen they are');
$t->check(strpos($V::map_html(array('canvas' => array('width' => 800, 'height' => 600), 'blocks' => array($block)), $sections, $occ), '<i>') === false, 'names are only written when asked for');

// ── Printout ───────────────────────────────────────────────────────────
$geo = $V::print_geometry(array('x' => 0, 'y' => 0, 'w' => 800, 'h' => 560), 'letter');
$t->equals(279.4, $geo['page_w'], 'letter sheets are landscape');
$t->check($geo['tile_w'] === 279.4 - 16 && $geo['tile_h'] === 215.9 - 16, 'each sheet keeps an 8mm border');
$map_w = 800 * $geo['scale'] / Azure_Tickets_Visualizer::PX_PER_MM;
$map_h = 560 * $geo['scale'] / Azure_Tickets_Visualizer::PX_PER_MM;
$t->check($map_w <= 2 * $geo['tile_w'] - 6 + 0.01 && $map_h <= 2 * $geo['tile_h'] - Azure_Tickets_Visualizer::HEADER_MM - 6 + 0.01, 'the map fits the four sheets under the title');
$t->check($map_w > 2 * $geo['tile_w'] - 7 || $map_h > 2 * $geo['tile_h'] - Azure_Tickets_Visualizer::HEADER_MM - 7, '…and fills them one way');
$t->check($geo['top'] >= Azure_Tickets_Visualizer::HEADER_MM, 'the map starts below the title band');
$t->equals(431.8, $V::print_geometry($box, 'tabloid')['page_w'], 'tabloid is 17″ wide');
$t->equals(279.4, $V::print_geometry($box, 'nonsense')['page_w'], 'an unknown paper falls back to letter');

$snap = array(
    'layout' => array('canvas' => array('width' => 800, 'height' => 600), 'blocks' => array($block)),
    'sections' => $sections, 'occ' => $occ, 'stats' => $st, 'venue' => 'School Gym',
    'orders' => array(7 => array('buyer' => 'Wendy Darling')),
);
$page = $V::print_html($snap, 'letter', array('title' => 'Peter & Wendy', 'when' => 'Friday 13 November', 'printed' => 'now', 'names' => true, 'autoprint' => true, 'css' => 'x.css'));
$t->equals(4, substr_count($page, '<section class="page">'), 'four pages');
$t->check(strpos($page, '@page { size: 279.4mm 215.9mm; margin: 0; }') !== false, 'the sheet size is set for the print dialog');
$t->check(strpos($page, 'Peter &amp; Wendy') !== false && strpos($page, 'Peter & Wendy') === false, 'the title is escaped');
$t->check(strpos($page, 'Page 4 of 4') !== false, 'pages are numbered for taping together');
$t->check(strpos($page, 'window.print()') !== false, 'autoprint opens the print dialog');

$t->finish();
