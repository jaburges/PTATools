<?php
/**
 * Cast & crew seats assigned by hand: reading the pasted list, matching
 * seats to sections, and keeping inventory and tickets in step on save.
 *
 * Run: php tests/test-tickets-assign.php
 */

require_once __DIR__ . '/wp-shim.php';
require_once __DIR__ . '/sqlite-wpdb.php';

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($s) { return strip_tags((string) $s); }
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-layout.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-inventory.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-config.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-module.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-assign.php';

class TA_Order {
    private $id;
    public function __construct($id) { $this->id = $id; }
    public function get_id() { return $this->id; }
    public function get_billing_email() { return 'family@example.com'; }
}

$GLOBALS['wpdb'] = tickets_test_wpdb();
$wpdb = $GLOBALS['wpdb'];
if ($wpdb->is_mysql()) {
    preg_match('/\$sql_tickets = "CREATE TABLE \$table_tickets \((.*?)\) \$charset_collate;"/s', file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-module.php'), $ddl);
    $wpdb->exec('DROP TABLE IF EXISTS wp_azure_tickets');
    $wpdb->exec('CREATE TABLE wp_azure_tickets (' . $ddl[1] . ') DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci');
} else {
    $wpdb->exec("CREATE TABLE wp_azure_tickets (
        id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, product_id INTEGER, event_id INTEGER, venue_id INTEGER,
        section_id TEXT, row_letter TEXT, seat_number INTEGER, attendee_name TEXT, attendee_email TEXT,
        ticket_code TEXT UNIQUE, qr_data TEXT, status TEXT DEFAULT 'active', created_at TEXT)");
}
Azure_Tickets_Inventory::$now = strtotime('2026-10-25 18:00:00 UTC');
Azure_Tickets_Config::$now = Azure_Tickets_Inventory::$now;

$VENUE = 900;
$FRI = 501;
WP_Shim::$post_meta[$VENUE]['_azure_seating_layout'] = json_encode(array('blocks' => array(
    array('id' => 'fl', 'type' => 'rectangle', 'name' => 'Front left', 'price' => 15, 'rowCount' => 3, 'seatsPerRow' => 8),
    array('id' => 'fr', 'type' => 'rectangle', 'name' => 'Front right', 'price' => 15, 'rowCount' => 3, 'seatsPerRow' => 8, 'startSeat' => 9, 'seatTypes' => array('A-16' => 'none')),
)));
WP_Shim::$post_meta[$FRI] = array(
    '_ticket_venue_id' => $VENUE,
    '_ticket_sections' => json_encode(array(
        'fl' => array('price' => '', 'limit' => 0, 'on_sale' => true, 'from' => '2026-10-30T09:00', 'access' => 'all'),
        'fr' => array('price' => '', 'limit' => 0, 'on_sale' => true, 'from' => '2026-10-30T09:00', 'access' => 'all'),
    )),
);

$t = new TestRunner('Tickets seat assignment');
$A = 'Azure_Tickets_Assign';

// ── Reading the pasted list ────────────────────────────────────────────
$p = $A::parse("D4 Jane Smith\n  d-12,  Leon Tsai \n\nA 9\nB4 – Sam\nhello\nD4 again");
$t->equals(array('D-4' => 'Jane Smith', 'D-12' => 'Leon Tsai', 'A-9' => '', 'B-4' => 'Sam'), $p['seats'], 'seat then name, with or without a dash, any case, name optional');
$t->equals(array('Line 6: start with a seat, like D4.', 'Line 7: D4 is listed twice.'), $p['errors'], 'bad and repeated lines are reported by line number');
$t->equals(array(), $A::parse("A1234 Bob")['seats'], 'a four-digit seat number is not read as A123');

// ── Matching seats to sections ─────────────────────────────────────────
$sections = Azure_Tickets_Config::sections($FRI);
$t->equals('fl', $A::locate($sections, 'A-8'), 'A8 is on the left of the aisle');
$t->equals('fr', $A::locate($sections, 'A-9'), 'A9 is on the right (numbering continues across the aisle)');
$t->equals('', $A::locate($sections, 'A-16'), 'a "no seat" space is not a seat');
$t->equals('', $A::locate($sections, 'D-1'), 'neither is a row the venue does not have');

// ── Saving ─────────────────────────────────────────────────────────────
$o = new TA_Order(4001);
$r = $A::save($o, $FRI, "A8 Jane\nA9 Leon", 3);
$t->check($r['ok'] && $r['added'] === 2, 'seats are assigned to the order');
$t->equals(array('A-8' => 'taken', 'A-9' => 'taken'), array_merge(Azure_Tickets_Inventory::taken($FRI)['fl'], Azure_Tickets_Inventory::taken($FRI)['fr']), 'and come off the seat map');
$t->equals("A8 Jane\nA9 Leon", $A::as_text($A::assigned(4001, $FRI)), 'the box shows what is assigned');
$codes = $wpdb->get_col("SELECT ticket_code FROM wp_azure_tickets WHERE order_id = 4001 AND status = 'active'");
$t->check(count($codes) === 2 && strlen($codes[0]) === 10, 'each seat gets a ticket with a code');

$r = $A::save($o, $FRI, "A9 Leon Tsai\nB1 Amy", 3);
$t->check($r['ok'] && $r['added'] === 1 && $r['removed'] === 1 && $r['renamed'] === 1, 'saving again adds, removes and renames');
$t->check(!isset(Azure_Tickets_Inventory::taken($FRI)['fl']['A-8']), 'a removed seat is free again');
$t->equals('1', (string) $wpdb->get_var("SELECT COUNT(*) FROM wp_azure_tickets WHERE order_id = 4001 AND status = 'cancelled'"), 'and its ticket is cancelled');
$t->equals($codes[1], $wpdb->get_var("SELECT ticket_code FROM wp_azure_tickets WHERE order_id = 4001 AND row_letter = 'A' AND seat_number = 9 AND status = 'active'"), 'a kept seat keeps its ticket code');

$r = $A::save($o, $FRI, "A9\nB1\nB2\nB3", 3);
$t->check(!$r['ok'] && strpos($r['errors'][0], 'paid for 3 tickets but 4') !== false, 'more seats than tickets bought is refused');
$r = $A::save($o, $FRI, "Z1 Nobody", 3);
$t->check(!$r['ok'] && strpos($r['errors'][0], 'Z1 isn\'t a seat') !== false, 'an unknown seat is refused');

Azure_Tickets_Inventory::claim($FRI, array(array('section_id' => 'fl', 'seat_key' => 'C-1')), 'someoneCart');
$r = $A::save(new TA_Order(4002), $FRI, "C1 Pat\nC2 Pat", 2);
$t->check(!$r['ok'] && strpos($r['errors'][0], 'Front left · Row C, Seat 1') !== false, 'a seat someone else holds is refused by name');
$t->check(!isset(Azure_Tickets_Inventory::taken($FRI)['fl']['C-2']) && $A::assigned(4002, $FRI) === array(), 'and nothing from that save was kept');
$t->check(!$A::save(new TA_Order(4002), $FRI, "B1 Pat", 1)['ok'], 'another order\'s seat cannot be assigned');

$free = $A::free_seats($FRI);
$t->check(!in_array(9, $free['fr']['A'], true) && !in_array(16, $free['fr']['A'], true) && in_array(8, $free['fl']['A'], true), 'free seats leave out taken and "no seat" places');

// ── CSV template and upload ────────────────────────────────────────────
$paid = array(4001 => 3, 4002 => 2);
$numbers = array('4001' => 4001, '4002' => 4002);
$get = function ($id) { return new TA_Order($id); };
$template = $A::csv_template(array(
    4001 => array('number' => '4001', 'family' => 'Jane Smith', 'paid' => 3, 'assigned' => $A::assigned(4001, $FRI)),
    4002 => array('number' => '4002', 'family' => '=Pat Doe', 'paid' => 2, 'assigned' => array()),
));
$t->equals("\xEF\xBB\xBFOrder,Family,Seat,Name\n4001,\"Jane Smith\",A9,\"Leon Tsai\"\n4001,\"Jane Smith\",B1,Amy\n4001,\"Jane Smith\",,\"Jane Smith\"\n4002,\"'=Pat Doe\",,\"'=Pat Doe\"\n4002,\"'=Pat Doe\",,\"'=Pat Doe\"\n", $template,
    'the template has a row per ticket bought: assigned seats first, then blanks with the family name');
$p = $A::parse_csv($template, $numbers);
$t->equals(array(4001 => array('A-9' => 'Leon Tsai', 'B-1' => 'Amy')), $p['orders'], 'reading it back gives the seats assigned; orders with no seats are left out');
$r = $A::import($FRI, $p['orders'], $paid, $get);
$t->check($r['ok'] && $r['added'] + $r['removed'] + $r['renamed'] === 0, 'uploading the template unchanged changes nothing');

$p = $A::parse_csv("Seat,Name,Order #\nA9,Leon Tsai,4001\na-10,Amy,4001\nB1,Pat,#4002\n B 2 ,'=Sam,4002\n,,4002\n", $numbers);
$t->equals(array(4001 => array('A-9' => 'Leon Tsai', 'A-10' => 'Amy'), 4002 => array('B-1' => 'Pat', 'B-2' => '=Sam')), $p['orders'], 'columns can be in any order; seats like a-10 and B 2 are read; a leading apostrophe is dropped');
$r = $A::import($FRI, $p['orders'], $paid, $get);
$t->check($r['ok'] && $r['orders'] === 2 && $r['added'] === 3 && $r['removed'] === 1, 'B1 moves from one order to another in the same upload');
$t->equals("A9 Leon Tsai\nA10 Amy", $A::as_text($A::assigned(4001, $FRI)), 'each order gets exactly the seats listed');
$t->equals("B1 Pat\nB2 =Sam", $A::as_text($A::assigned(4002, $FRI)), 'including the order that took the seat');

$bad = $A::parse_csv("Order,Seat\n4001,A9\n9999,A1\n4002,hello\n4001,A9\n", $numbers);
$t->equals(array(
    'Row 3: #9999 isn\'t a paid cast & crew order for this performance.',
    'Row 4: "hello" isn\'t a seat. Write it like D4.',
    'Row 5: A9 is listed twice for #4001.',
), $bad['errors'], 'unknown orders, bad seats and repeats are reported by row');
$t->equals(array('The first row must have "Order" and "Seat" columns. Download the template to start from.'), $A::parse_csv("Seat,Party\nA1,Smith\n", $numbers)['errors'], 'a sheet without Order and Seat columns is refused');

$before = array($A::as_text($A::assigned(4001, $FRI)), $A::as_text($A::assigned(4002, $FRI)));
$r = $A::import($FRI, array(4001 => array('A-9' => '', 'Z-1' => ''), 4002 => array('C-1' => '', 'A-9' => '', 'A-1' => '')), $paid, $get, array(4001 => '4001', 4002 => '4002'));
$t->equals(array(
    'Z1 (#4001) isn\'t a seat in this venue.',
    '#4002 paid for 2 tickets but 3 seats are listed.',
    'A9 is listed for both #4001 and #4002.',
    'C1 (#4002) has already been bought or is in someone\'s cart.',
), $r['errors'], 'the whole file is checked: shared seats, unknown seats, too many seats, seats bought by others');
$t->check(!$r['ok'] && empty($r['partial']) && $before === array($A::as_text($A::assigned(4001, $FRI)), $A::as_text($A::assigned(4002, $FRI))), 'and nothing is saved when any of it is wrong');
$r = $A::import($FRI, array(4002 => array('A-9' => 'Pat')), $paid, $get, array(4001 => '4001', 4002 => '4002'));
$t->equals(array('A9 (#4002) is already assigned to #4001.'), $r['errors'], 'a seat held by an order not in the file stays with it');

exit($t->finish() > 0 ? 1 : 0);
