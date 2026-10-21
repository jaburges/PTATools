<?php
/**
 * Ticket QR codes: signed image URLs, the bundled encoder, and reading
 * whatever the door scanner hands back.
 *
 * Run: php tests/test-tickets-qr.php
 */

require_once __DIR__ . '/wp-shim.php';
if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}
if (!function_exists('wp_salt')) {
    function wp_salt($scheme = 'auth') { return 'test-salt-' . $scheme; }
}
if (!function_exists('rest_url')) {
    function rest_url($path = '') { return 'https://example.test/wp-json/' . ltrim($path, '/'); }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg($key, $value, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . $key . '=' . rawurlencode($value); }
}
require_once AZURE_PLUGIN_PATH . 'includes/class-tickets-qr.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-tickets-module.php';

$t = new TestRunner('Tickets QR');

// ── Signed URLs ────────────────────────────────────────────────────────
$sig = Azure_Tickets_QR::signature('ABCD234567');
$t->equals(20, strlen($sig), 'signatures are 20 hex characters');
$t->equals($sig, Azure_Tickets_QR::signature('abcd234567'), 'codes are signed case-insensitively');
$t->check($sig !== Azure_Tickets_QR::signature('ABCD234568'), 'each code has its own signature');
$t->equals('https://example.test/wp-json/pta-tickets/v1/qr/ABCD234567?sig=' . $sig, Azure_Tickets_QR::url('abcd234567'), 'the email image URL carries the code and its signature');

$route = Azure_Tickets_QR::get_instance();
$fake = new class(array('code' => 'ABCD234567')) implements ArrayAccess {
    private $p; public $sig = 'nope';
    public function __construct($p) { $this->p = $p; }
    public function offsetExists($k): bool { return isset($this->p[$k]); }
    public function offsetGet($k): mixed { return $this->p[$k]; }
    public function offsetSet($k, $v): void {}
    public function offsetUnset($k): void {}
    public function get_param($k) { return $this->sig; }
};
$res = $route->serve($fake);
$t->check($res instanceof WP_Error && $res->get_error_message() === 'Not found', 'a wrong signature is refused, so codes cannot be guessed through the image endpoint');

// ── Encoder ────────────────────────────────────────────────────────────
$m = Azure_Tickets_QR::matrix('ABCD234567');
$n = count($m);
$t->check($n >= 21 && ($n - 17) % 4 === 0 && count($m[0]) === $n, 'the matrix is a valid QR size (' . $n . ' modules)');
$finder = function ($r0, $c0) use ($m) {
    for ($r = 0; $r < 7; $r++) {
        for ($c = 0; $c < 7; $c++) {
            $ring = $r === 0 || $r === 6 || $c === 0 || $c === 6;
            $core = $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4;
            if ($m[$r0 + $r][$c0 + $c] !== ($ring || $core)) {
                return false;
            }
        }
    }
    return true;
};
$t->check($finder(0, 0) && $finder(0, $n - 7) && $finder($n - 7, 0), 'the three corner finder patterns are drawn');
if (function_exists('imagecreate')) {
    $png = Azure_Tickets_QR::png('ABCD234567');
    $size = getimagesizefromstring($png);
    $t->check($size !== false && $size['mime'] === 'image/png' && $size[0] === ($n + 8) * 8, 'the PNG is the matrix plus a 4-module quiet zone at 8px a module');
}

// ── Scanned text ───────────────────────────────────────────────────────
$t->equals('ABCD234567', Azure_Tickets_Module::scanned_code(' abcd234567 '), 'a bare code (what the QR now holds), trimmed and upper-cased');
$t->equals('ABCD2345', Azure_Tickets_Module::scanned_code('{"ticket_id":4,"code":"ABCD2345","event":1}'), 'the first version\'s JSON QR still scans');
$t->equals('ABCD2345', Azure_Tickets_Module::scanned_code('{"v":1,"t":4,"c":"abcd2345","cs":"x"}'), 'and so does the short JSON form');
$t->equals('', Azure_Tickets_Module::scanned_code('{"nope":1}'), 'JSON without a code reads as nothing');
$t->equals('ABCD2345', Azure_Tickets_Module::scanned_code("ABCD-2345'\""), 'only letters and digits survive');
$t->equals('', Azure_Tickets_Module::scanned_code(str_repeat('A', 21)), 'over-long input reads as nothing');

// ── Codes ──────────────────────────────────────────────────────────────
$codes = array();
for ($i = 0; $i < 200; $i++) {
    $codes[] = Azure_Tickets_Module::generate_ticket_code();
}
$t->check(count(array_unique($codes)) === 200, '200 generated codes are all different');
$t->check(!preg_grep('/[^ABCDEFGHJKMNPQRSTUVWXYZ23456789]/', $codes) && strlen($codes[0]) === 10, 'codes are 10 characters with no look-alikes (0/O, 1/I/L)');

exit($t->finish() > 0 ? 1 : 0);
