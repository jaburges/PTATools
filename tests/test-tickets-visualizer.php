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

// ── The app's seat map ─────────────────────────────────────────────────
$near = function ($a, $b) {
    return abs((float) $a - (float) $b) < 0.06;
};
$sized = array_merge($block, array('x' => 100, 'y' => 50, 'width' => 216, 'height' => 81, 'angle' => 0, 'stagger' => false, 'hideNumbers' => false));
$geo = $V::seat_cells($sized);
$a1 = $geo['cells']['A-1'];
$t->check($near($a1['x'], 16) && $near($a1['y'], 15), 'the first seat sits after the padding, the row letter and the seat-number line', json_encode($a1));
$t->check($near($a1['h'], (81 - 6 - 13 - 4) / 3), 'rows share the height left, less the 2px gaps');
$t->check($near($geo['cells']['A-5']['w'], 2 * $a1['w']), 'a wheelchair space beside "no seat" is two seats wide');
$t->check($near($geo['cells']['A-5']['x'] + $geo['cells']['A-5']['w'], 216 - 4), '…and the row still ends at the block\'s right padding');
$t->check(!isset($geo['cells']['C-3']) && $near($geo['cells']['C-6']['x'] + $geo['cells']['C-6']['w'], 212), '"no seat" leaves a gap, not a seat');
$t->check($near($geo['cells']['B-1']['y'], 15 + $a1['h'] + 2), 'the next row starts a gap below');
$t->equals(array('A', 'B', 'C'), array_column($geo['rows'], 'label'), 'each row has its letter');
$bare = $V::seat_cells(array_merge($sized, array('hideNumbers' => true)));
$t->check($near($bare['cells']['A-1']['y'], 2), 'without seat numbers the rows start at the top padding');
$stag = $V::seat_cells(array_merge($sized, array('stagger' => true)));
$pad = 0.5 * 208 / 6;
$t->check($near($stag['cells']['A-1']['x'], 16) && $near($stag['cells']['B-1']['x'], 16 + $pad), 'staggered rows: every other row moves half a seat right');

$app_occ = $V::merge($inventory, $tickets, array('GA'));
$app_snap = array(
    'layout'   => array('canvas' => array('width' => 800, 'height' => 600), 'blocks' => array(
        $sized,
        array('id' => 'st', 'type' => 'stage', 'x' => 100, 'y' => 200, 'width' => 216, 'height' => 30, 'color' => '', 'label' => 'STAGE'),
        array('id' => 'GA', 'type' => 'general_admission', 'name' => 'Floor', 'x' => 100, 'y' => 240, 'width' => 100, 'height' => 40, 'color' => '#a00'),
    )),
    'sections' => $sections,
    'occ'      => $app_occ,
    'stats'    => $V::stats($sections, $app_occ),
    'venue'    => 'School Gym',
    'orders'   => array(7 => array('number' => '1007', 'buyer' => 'Mary Darling', 'email' => 'mary@example.com', 'phone' => '', 'seats' => array('A1', 'A2'), 'ga' => array())),
);
$map = $V::app_map($app_snap);
$by_key = array_column($map['seats'], null, 'k');
$t->equals(16, count($map['seats']), 'every seat is sent: 18 places less the merged wheelchair cell and the gap');
$t->check($near($by_key['A-1']['x'], 100 + 16 + $a1['w'] / 2 - 88) && $near($by_key['A-1']['y'], 50 + 15 + $a1['h'] / 2 - 38), 'seats are placed by their centre, from the top-left of the drawn area', json_encode($by_key['A-1']));
$t->equals('checked_in', $by_key['A-1']['st'], 'with who has them: checked in');
$t->equals('Wendy Darling', $by_key['A-1']['name'], '…their name');
$t->equals(7, $by_key['A-1']['order'], '…and their order');
$t->equals('ltb', $by_key['A-1']['sides'], 'and which sides of the bought-together outline to draw (A2 is on the same order)');
$t->equals('free', $by_key['A-3']['st'], 'a seat no one has is free');
$t->equals('held', $by_key['B-4']['st'], 'a seat in a cart is held');
$t->equals('accessible', $by_key['A-5']['t'], 'wheelchair spaces are marked');
$t->equals('Left · Row A, Seat 1', $by_key['A-1']['label'], 'each seat has a spoken label');
$t->check(strpos(json_encode($map), 'secret-hold-key') === false, 'hold keys are not sent');
$t->equals(array(array('id' => 7, 'number' => '1007', 'buyer' => 'Mary Darling', 'email' => 'mary@example.com', 'phone' => '', 'seats' => array('A1', 'A2'), 'ga' => array())), $map['orders'], 'orders are a list carrying their id');
$kinds = array_column($map['shapes'], null, 'kind');
$t->equals('STAGE', $kinds['stage']['text'], 'the stage is drawn with its label');
$t->equals(array('capacity' => 10, 'sold' => 2, 'checked_in' => 1, 'free' => 7, 'enabled' => false), $kinds['ga']['ga'], 'general admission is a box with its counts');
$t->equals(3, count($map['rows']), 'row letters are placed too');

$turned = $V::app_map(array_merge($app_snap, array('layout' => array('canvas' => array('width' => 800, 'height' => 600), 'blocks' => array(array_merge($sized, array('angle' => 90)))))));
$t1 = array_column($turned['seats'], null, 'k')['A-1'];
$t->check($t1['x'] > $turned['width'] / 2 && $t1['y'] < $turned['height'] / 2 && $t1['a'] == 90, 'a block turned 90° clockwise puts its first seat top right, and says the angle', json_encode($t1));

// ── Checking an order in from the map ──────────────────────────────────
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-checkin.php';
global $wpdb;
$wpdb = new Sqlite_WPDB();
$wpdb->exec("CREATE TABLE wp_azure_tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, product_id INTEGER, section_id TEXT, row_letter TEXT, seat_number INTEGER,
    attendee_name TEXT, ticket_code TEXT, status TEXT, checked_in_at TEXT, checked_in_by INTEGER)");
$wpdb->exec("CREATE TABLE wp_azure_ticket_checkins (
    id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER, action TEXT, user_id INTEGER, device_info TEXT, created_at TEXT)");
$add = function ($order, $perf, $row, $n, $status = 'active', $name = '') use ($wpdb) {
    $wpdb->insert('wp_azure_tickets', array('order_id' => $order, 'product_id' => $perf, 'section_id' => $row === null ? 'GA' : 'left',
        'row_letter' => $row, 'seat_number' => $n, 'attendee_name' => $name, 'ticket_code' => 'c' . $order . $row . $n . $perf, 'status' => $status));
    return $wpdb->insert_id;
};
$m3 = $add(40, 100, 'M', 3, 'active', 'Wendy');
$m4 = $add(40, 100, 'M', 4);
$m10 = $add(40, 100, 'M', 10);
$floor = $add(40, 100, null, null);
$add(40, 100, 'M', 5, 'cancelled');
$sat = $add(40, 200, 'M', 3);
$other = $add(41, 100, 'M', 6);

$list = $V::order_tickets(100, 40);
$t->equals(array('M3', 'M4', 'M10', ''), array_column($list, 'seat'), 'the box lists the order\'s live tickets for that performance only, seats in number order');
$t->equals('M-3', $list[0]['key'], 'each carries the map\'s seat key, so the map can be updated');
$t->equals('Wendy', $list[0]['name'], '…and the ticket name');
$t->equals('', $list[3]['key'], 'general admission has no seat key');
$t->equals('General Admission', $list[3]['label'], '…and is labelled as such');

$r = $V::check_in_order(100, 40, array($m4, $other, $sat), 9);
$t->equals(1, $r['checked_in'], 'checking in chosen seats: only this order\'s tickets for this performance count');
$t->equals('used', $wpdb->get_var("SELECT status FROM wp_azure_tickets WHERE id = $m4"), 'the chosen seat is in');
$t->equals('active', $wpdb->get_var("SELECT status FROM wp_azure_tickets WHERE id = $other"), 'another order\'s ticket is left alone');
$t->equals('active', $wpdb->get_var("SELECT status FROM wp_azure_tickets WHERE id = $sat"), 'so is the same order\'s ticket for another night');
$t->equals('active', $wpdb->get_var("SELECT status FROM wp_azure_tickets WHERE id = $m3"), 'and the seats not chosen');
$t->equals(9, (int) $wpdb->get_var("SELECT checked_in_by FROM wp_azure_tickets WHERE id = $m4"), 'it is recorded against whoever clicked');
$t->equals('checkin', $wpdb->get_var("SELECT action FROM wp_azure_ticket_checkins WHERE ticket_id = $m4"), '…and logged like a scan');
$t->equals(1, $r['total_in'], 'the performance\'s checked-in count comes back for the summary line');
$t->check($r['tickets'][1]['status'] === 'used' && $r['tickets'][1]['time'] !== '', 'the fresh ticket list shows the time it went in');

$r = $V::check_in_order(100, 40, array($m4), 9);
$t->equals(array(0, 1), array($r['checked_in'], $r['already']), 'choosing a seat already in says so instead of checking it in twice');

$r = $V::check_in_order(100, 40, null, 9);
$t->equals(array(3, 0), array($r['checked_in'], $r['already']), 'all: the rest of the order goes in (M3, M10 and the floor ticket), the one already in is not counted again');
$t->equals(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_azure_tickets WHERE order_id = 40 AND product_id = 100 AND status = 'active'"), 'nothing on the order is left to check in');
$t->equals('cancelled', $wpdb->get_var("SELECT status FROM wp_azure_tickets WHERE order_id = 40 AND seat_number = 5"), 'a cancelled ticket is never checked in');
$t->equals(4, $r['total_in'], 'four checked in for the night');
$t->equals('active', $wpdb->get_var("SELECT status FROM wp_azure_tickets WHERE id = $sat"), 'all still means this performance only');

$r = $V::undo_seat(100, 40, 'left', 'M-4', 9);
$t->equals(1, $r['undone'], 'undo takes back one seat\'s check-in');
$t->equals('M4 is no longer checked in.', $r['message'], '…and says which');
$t->equals('active', $wpdb->get_var("SELECT status FROM wp_azure_tickets WHERE id = $m4"), 'the ticket is live again, so its QR code scans');
$t->equals('used', $wpdb->get_var("SELECT status FROM wp_azure_tickets WHERE id = $m3"), 'the rest of the order stays in');
$t->equals('undo_checkin', $wpdb->get_var("SELECT action FROM wp_azure_ticket_checkins WHERE ticket_id = $m4 ORDER BY id DESC LIMIT 1"), 'the undo is logged');
$t->equals(3, $r['total_in'], 'the count goes down');
$r = $V::undo_seat(100, 40, 'left', 'M-4', 9);
$t->equals(0, $r['undone'], 'undoing a seat that isn\'t in does nothing');
$r = $V::undo_seat(100, 41, 'left', 'M-3', 9);
$t->equals(array(0, 'used'), array($r['undone'], $wpdb->get_var("SELECT status FROM wp_azure_tickets WHERE id = $m3")), 'another order can\'t undo this order\'s seat');
$r = $V::undo_seat(100, 40, 'GA', '', 9);
$t->equals(0, $r['undone'], 'general admission has no seat to undo from the map');

$cancel = $add(42, 100, 'N', 1);
$wpdb->query("UPDATE wp_azure_tickets SET status = 'cancelled' WHERE id = $cancel");
$r = $V::check_in_order(100, 42, array($cancel), 9);
$t->equals(array(0, array()), array($r['checked_in'], $r['problems']), 'a cancelled ticket is not offered at all, so there is nothing to refuse');

$t->finish();
