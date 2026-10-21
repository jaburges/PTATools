<?php
/**
 * Venue seating layouts: stable section ids, seat lists with accessible and
 * missing seats, display-only areas, capacity.
 *
 * Run: php tests/test-tickets-layout.php
 */

require_once __DIR__ . '/wp-shim.php';
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($s) { return strip_tags((string) $s); }
}
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-layout.php';

$t = new TestRunner('Tickets layout');

$raw = array(
    'canvas' => array('width' => 900, 'height' => 99999),
    'blocks' => array(
        array('id' => 'sec-parents-l', 'type' => 'rectangle', 'name' => 'Parents of performers <b>L</b>', 'price' => '12.5', 'rowCount' => 2, 'seatsPerRow' => 4, 'startRow' => 'A',
              'seatTypes' => array('A-1' => 'accessible', 'B-4' => 'none', 'C-1' => 'accessible', 'A-9' => 'none', 'A-2' => 'vip', 'x' => 'none')),
        array('type' => 'rectangle', 'name' => 'Regular', 'rowCount' => 40, 'seatsPerRow' => 500, 'startRow' => 'W'),
        array('id' => 'mat', 'type' => 'area', 'label' => 'Gym mat (kids floor seating)', 'color' => '#ABCDEF'),
        array('id' => 'ga1', 'type' => 'general_admission', 'name' => 'Standing', 'capacity' => 0, 'price' => -5),
        array('id' => 'sec-parents-l', 'type' => 'stage', 'label' => 'STAGE', 'color' => 'red'),
        array('type' => 'script', 'id' => 'evil'),
        'junk',
    ),
);
$l = Azure_Tickets_Layout::sanitize($raw);

$t->equals(5, count($l['blocks']), 'unknown block types and junk are dropped');
$t->equals(3000, $l['canvas']['height'], 'canvas size is clamped');
$ids = array_column($l['blocks'], 'id');
$t->equals('sec-parents-l', $ids[0], 'a saved section id is kept exactly');
$t->equals(count($ids), count(array_unique($ids)), 'every block ends up with a unique id');
$t->check($ids[1] !== '' && $ids[4] !== 'sec-parents-l', 'missing and duplicate ids are replaced');
$again = Azure_Tickets_Layout::sanitize($l);
$t->equals($ids, array_column($again['blocks'], 'id'), 'ids are stable across saves');

$p = $l['blocks'][0];
$t->equals('Parents of performers L', $p['name'], 'names are plain text');
$t->equals(12.5, $p['price'], 'price is a number');
$t->equals(array('A-1' => 'accessible', 'B-4' => 'none'), $p['seatTypes'], 'only real seats with known types keep a seat type');
$t->equals(array('A-1' => 'accessible', 'A-2' => 'normal', 'A-3' => 'normal', 'A-4' => 'normal', 'B-1' => 'normal', 'B-2' => 'normal', 'B-3' => 'normal'), Azure_Tickets_Layout::seats($p), 'seat list skips "no seat" and marks accessible seats');
$t->equals('accessible', Azure_Tickets_Layout::seat_type($p, 'A-1'), 'accessible seat');
$t->check(Azure_Tickets_Layout::seat_type($p, 'B-4') === null && Azure_Tickets_Layout::seat_type($p, 'C-1') === null && Azure_Tickets_Layout::seat_type($p, 'A-5') === null, 'missing, out-of-range and removed seats are not seats');
$t->equals(7, Azure_Tickets_Layout::section_capacity($p), 'capacity counts real seats');

$r = $l['blocks'][1];
$t->check($r['rowCount'] === 4 && $r['seatsPerRow'] === 100, 'rows stop at Z and seats per row at 100');
$t->equals(array('W', 'X', 'Y', 'Z'), Azure_Tickets_Layout::row_labels($r), 'row letters run from the start row');

$t->equals('#abcdef', $l['blocks'][2]['color'], 'colours are normalised');
$t->equals('', $l['blocks'][4]['color'], 'invalid colours are dropped');
$sections = Azure_Tickets_Layout::sections($l);
$t->equals(array('sec-parents-l', $ids[1], 'ga1'), array_keys($sections), 'only rectangles and general admission are sellable; the gym mat area is not');
$t->check($sections['ga1']['capacity'] === 1 && $sections['ga1']['price'] === 0.0, 'GA capacity and price are clamped');
$t->equals(7 + 400 + 1, Azure_Tickets_Layout::capacity($l), 'venue capacity');

$t->equals('Parents of performers L · Row A, Seat 3', Azure_Tickets_Layout::seat_label($p, 'A-3'), 'seat label');
$t->equals('Standing', Azure_Tickets_Layout::seat_label($sections['ga1'], '5'), 'GA label is the area name');

$t->check($p['startSeat'] === 1 && $p['angle'] === 0 && $p['stagger'] === false, 'older layouts get seat 1, no angle and no stagger');

// Right half of a row split by an aisle: seats 9-16, angled and staggered.
$right = Azure_Tickets_Layout::sanitize(array('blocks' => array(array(
    'id' => 'right', 'type' => 'rectangle', 'name' => 'Right', 'rowCount' => 2, 'seatsPerRow' => 8, 'startRow' => 'D',
    'startSeat' => 9, 'angle' => -80, 'stagger' => 1,
    'seatTypes' => array('D-9' => 'none', 'D-10' => 'accessible', 'D-11' => 'companion', 'D-1' => 'accessible', 'E-17' => 'none'),
))))['blocks'][0];
$t->equals(range(9, 16), Azure_Tickets_Layout::seat_numbers($right), 'seat numbers start at the start seat');
$t->check($right['angle'] === -45 && $right['stagger'] === true, 'angle is clamped to ±45 and stagger kept');
$t->equals(array('D-9' => 'none', 'D-10' => 'accessible', 'D-11' => 'companion'), $right['seatTypes'], 'seat types outside the numbering are dropped');
$t->equals('companion', Azure_Tickets_Layout::seat_type($right, 'D-11'), 'companion seat');
$t->check(Azure_Tickets_Layout::seat_type($right, 'D-1') === null && Azure_Tickets_Layout::seat_type($right, 'D-9') === null, 'seat 1 is not in this half, and no-seat is not a seat');
$t->equals(15, Azure_Tickets_Layout::section_capacity($right), 'capacity of the right half');
$t->equals('Right · Row D, Seat 10 (wheelchair space)', Azure_Tickets_Layout::seat_label($right, 'D-10'), 'wheelchair space label');
$t->equals('Right · Row D, Seat 11 (companion seat)', Azure_Tickets_Layout::seat_label($right, 'D-11'), 'companion seat label');
$t->equals(992, Azure_Tickets_Layout::sanitize(array('blocks' => array(array('type' => 'rectangle', 'seatsPerRow' => 8, 'startSeat' => 5000))))['blocks'][0]['startSeat'], 'the last seat number stays within 999');

$t->equals(Azure_Tickets_Layout::empty_layout(), Azure_Tickets_Layout::decode('not json'), 'bad JSON decodes to an empty layout');
$t->equals($ids, array_column(Azure_Tickets_Layout::decode(json_encode($raw))['blocks'], 'id'), 'decode sanitizes stored JSON the same way');

exit($t->finish() > 0 ? 1 : 0);
