<?php
/**
 * Door check-in from the PTSA Board app: the performance list, scanning a
 * code against the night at the door, and undoing a scan.
 *
 * Run: php tests/test-ptsa-rest-tickets.php
 */

require_once __DIR__ . '/wp-shim.php';
require_once __DIR__ . '/sqlite-wpdb.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($s) { return strip_tags((string) $s); }
}
function rest_ensure_response($data) { return $data; }
function register_rest_route($ns, $route, $args) { PRT::$routes[$ns . $route] = $args; return true; }
function get_current_user_id() { return PRT::$user; }
function get_userdata($id) { return $id === 7 ? (object) array('display_name' => 'Door Dana') : false; }
function get_the_title($id) { return $id === 900 ? 'Wilder Gym' : ''; }
function wc_get_product($id) { return PRT::$products[$id] ?? null; }
function wc_get_products($args) { return array_keys(PRT::$products); }

class WP_REST_Request implements ArrayAccess {
    private $params; private $json;
    public function __construct(array $params = array(), array $json = array()) { $this->params = $params; $this->json = $json; }
    public function get_json_params() { return $this->json; }
    public function offsetExists($k): bool { return isset($this->params[$k]); }
    public function offsetGet($k): mixed { return $this->params[$k] ?? null; }
    public function offsetSet($k, $v): void { $this->params[$k] = $v; }
    public function offsetUnset($k): void { unset($this->params[$k]); }
}

class PRT_Product {
    private $name; private $type;
    public function __construct($name, $type = 'ticket') { $this->name = $name; $this->type = $type; }
    public function get_name() { return $this->name; }
    public function get_type() { return $this->type; }
}

class PRT {
    public static $routes = array();
    public static $products = array();
    public static $user = 7;
}

require_once AZURE_PLUGIN_PATH . 'includes/class-tickets-layout.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-tickets-config.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-tickets-module.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-tickets-checkin.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-ptsa-rest-tickets.php';

$GLOBALS['wpdb'] = tickets_test_wpdb();
$wpdb = $GLOBALS['wpdb'];
if ($wpdb->is_mysql()) {
    $src = file_get_contents(AZURE_PLUGIN_PATH . 'includes/class-tickets-module.php');
    preg_match('/\$sql_tickets = "CREATE TABLE \$table_tickets \((.*?)\) \$charset_collate;"/s', $src, $t_ddl);
    preg_match('/\$sql_checkins = "CREATE TABLE \$table_checkins \((.*?)\) \$charset_collate;"/s', $src, $c_ddl);
    foreach (array('wp_azure_tickets' => $t_ddl[1], 'wp_azure_ticket_checkins' => $c_ddl[1]) as $table => $ddl) {
        $wpdb->exec("DROP TABLE IF EXISTS $table");
        $wpdb->exec("CREATE TABLE $table ($ddl) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci");
    }
} else {
    $wpdb->exec("CREATE TABLE wp_azure_tickets (
        id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, product_id INTEGER, event_id INTEGER, venue_id INTEGER,
        section_id TEXT, row_letter TEXT, seat_number INTEGER, attendee_name TEXT, attendee_email TEXT,
        ticket_code TEXT UNIQUE, qr_data TEXT, status TEXT DEFAULT 'active', checked_in_at TEXT, checked_in_by INTEGER, created_at TEXT)");
    $wpdb->exec("CREATE TABLE wp_azure_ticket_checkins (
        id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER, action TEXT, user_id INTEGER, device_info TEXT, notes TEXT, created_at TEXT)");
}

Azure_Tickets_Config::$now = strtotime('2026-11-13 18:30:00 America/Los_Angeles');

$VENUE = 900;
$FRI = 501;
$SAT = 502;
$PAST = 503;
PRT::$products = array(
    $FRI  => new PRT_Product('Peter Pan – Fri 13 Nov'),
    $SAT  => new PRT_Product('Peter Pan – Sat 14 Nov'),
    $PAST => new PRT_Product('Spring Show'),
    77    => new PRT_Product('Hoodie', 'simple'),
);
WP_Shim::$post_meta[$VENUE]['_azure_seating_layout'] = json_encode(array('blocks' => array(
    array('id' => 'left', 'type' => 'rectangle', 'name' => 'Left', 'price' => 15, 'startRow' => 'D', 'rowCount' => 2, 'seatsPerRow' => 8),
)));
foreach (array($FRI => '2026-11-13T19:00', $SAT => '2026-11-14T19:00', $PAST => '2026-05-01T19:00') as $id => $when) {
    WP_Shim::$post_meta[$id]['_ticket_venue_id'] = $VENUE;
    WP_Shim::$post_meta[$id]['_ticket_event_date'] = $when;
}

$ticket = function ($code, $product, $row, $seat, $name, $status = 'active') use ($wpdb) {
    $wpdb->insert('wp_azure_tickets', array(
        'order_id' => 1, 'product_id' => $product, 'section_id' => 'left', 'row_letter' => $row, 'seat_number' => $seat,
        'attendee_name' => $name, 'ticket_code' => $code, 'qr_data' => $code, 'status' => $status, 'created_at' => '2026-11-01 10:00:00',
    ));
};
$ticket('FRIAAAAAA2', $FRI, 'D', 4, 'Jane Smith');
$ticket('FRIBBBBBB3', $FRI, 'D', 5, '');
$ticket('FRICCCCCC4', $FRI, 'E', 1, 'Gone', 'cancelled');
$ticket('SATAAAAAA2', $SAT, 'D', 4, 'Sam');

$t = new TestRunner('PTSA REST tickets');
$R = 'Azure_PTSA_REST_Tickets';

// ── Routes ─────────────────────────────────────────────────────────────
$R::register('ptsa/v1', '__auth__');
$t->equals(
    array('ptsa/v1/tickets/performances', 'ptsa/v1/tickets/performances/(?P<id>\d+)', 'ptsa/v1/tickets/checkin', 'ptsa/v1/tickets/(?P<id>\d+)/undo'),
    array_keys(PRT::$routes),
    'the four door routes are registered'
);
$t->check(!array_filter(PRT::$routes, function ($r) { return $r['permission_callback'] !== '__auth__'; }), 'every route uses the app sign-in check');

// ── Performance list ───────────────────────────────────────────────────
$list = $R::list_performances(new WP_REST_Request());
$t->equals(array($FRI, $SAT), array_column($list, 'id'), 'upcoming performances only (not last spring, not the hoodie), earliest first');
$t->equals('Fri 13 Nov, 7:00pm', $list[0]['when'], 'with the time in the site timezone');
$t->equals('Wilder Gym', $list[0]['venue'], 'and the venue name');
$t->equals(array('sold' => 2, 'checked_in' => 0), $list[0]['stats'], 'sold counts active tickets, not cancelled ones');

// ── Scanning ───────────────────────────────────────────────────────────
$scan = function ($code, $product) use ($R) {
    return $R::checkin(new WP_REST_Request(array(), array('code' => $code, 'product_id' => $product)));
};
$r = $scan('friaaaaaa2', $FRI);
$t->check($r['success'] && $r['status'] === 'success', 'a valid ticket for tonight checks in (case does not matter)');
$t->equals('Left · Row D, Seat 4', $r['ticket']['seat'], 'the door sees the seat');
$t->equals('Jane Smith', $r['ticket']['attendee'], 'and the name');
$t->equals(array('sold' => 2, 'checked_in' => 1), $r['stats'], 'the counter moves');
$t->equals('7', (string) $wpdb->get_var("SELECT checked_in_by FROM wp_azure_tickets WHERE ticket_code = 'FRIAAAAAA2'"), 'the scan is recorded against the signed-in user');
$t->equals('checkin', $wpdb->get_var("SELECT action FROM wp_azure_ticket_checkins WHERE ticket_id = 1"), 'and logged');

$r = $scan('FRIAAAAAA2', $FRI);
$t->check(!$r['success'] && $r['status'] === 'already_used', 'a second scan is refused');
$t->equals('Door Dana', $r['ticket']['checked_in_by'], 'saying who scanned it');
$t->check($r['ticket']['checked_in_time'] !== '', 'and when');

$r = $scan('SATAAAAAA2', $FRI);
$t->check(!$r['success'] && $r['status'] === 'wrong_performance', 'Saturday\'s ticket is refused on Friday');
$t->equals('This ticket is for Peter Pan – Sat 14 Nov', $r['message'], 'naming the right night');
$t->equals('active', $wpdb->get_var("SELECT status FROM wp_azure_tickets WHERE ticket_code = 'SATAAAAAA2'"), 'and it stays valid for Saturday');

$r = $scan('FRICCCCCC4', $FRI);
$t->check(!$r['success'] && $r['status'] === 'invalid' && $r['message'] === 'Ticket has been cancelled', 'a cancelled ticket says so');

$r = $scan('NOPE234567', $FRI);
$t->check(!$r['success'] && $r['status'] === 'not_found', 'an unknown code is not found');
$r = $scan('{"nope":1}', $FRI);
$t->check(!$r['success'] && $r['status'] === 'not_found', 'a QR that is not a ticket reads as not found');

$r = $scan('FRIBBBBBB3', 77);
$t->check($r instanceof WP_Error && $r->get_error_message() === 'Choose a performance first.', 'scanning needs a ticket performance');
$t->equals('active', $wpdb->get_var("SELECT status FROM wp_azure_tickets WHERE ticket_code = 'FRIBBBBBB3'"), 'and checks nothing in without one');

$r = $scan('FRIBBBBBB3', $FRI);
$t->equals('Guest', $r['ticket']['attendee'], 'a ticket without a name shows Guest');

// ── Door list and undo ─────────────────────────────────────────────────
$perf = $R::get_performance(new WP_REST_Request(array('id' => $FRI)));
$t->equals(array('D4', 'D5'), array_map(function ($x) { return preg_replace('/.*Row (\w), Seat (\d+).*/', '$1$2', $x['seat']); }, $perf['tickets']), 'the door list has tonight\'s tickets by seat, without the cancelled one');
$t->equals(array('used', 'used'), array_column($perf['tickets'], 'status'), 'with their status');
$t->check($R::get_performance(new WP_REST_Request(array('id' => 77))) instanceof WP_Error, 'a non-ticket product is not a performance');

$id = (int) $perf['tickets'][0]['id'];
$r = $R::undo(new WP_REST_Request(array('id' => $id)));
$t->check($r['success'] && $r['ticket']['status'] === 'active', 'undo puts the ticket back');
$t->equals(array('sold' => 2, 'checked_in' => 1), $r['stats'], 'and the counter goes down');
$t->check($R::undo(new WP_REST_Request(array('id' => $id))) instanceof WP_Error, 'undoing a ticket that is not checked in is refused');
$r = $scan('FRIAAAAAA2', $FRI);
$t->check($r['success'], 'and it can be scanned again');

exit($t->finish() > 0 ? 1 : 0);
