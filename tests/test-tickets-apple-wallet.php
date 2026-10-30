<?php
/**
 * Apple Wallet passes: certificate checks, pass contents (row and seat),
 * and a signed .pkpass that OpenSSL verifies against its manifest.
 *
 * Uses a throwaway self-signed certificate shaped like Apple's (UID = Pass
 * Type ID, OU = Team ID); Apple's servers aren't involved.
 *
 * Run: php tests/test-tickets-apple-wallet.php
 */

require_once __DIR__ . '/wp-shim.php';
if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}
if (!function_exists('wp_salt')) {
    function wp_salt($scheme = 'auth') { return ($GLOBALS['test_salt'] ?? 'test-salt-') . $scheme; }
}
if (!function_exists('rest_url')) {
    function rest_url($path = '') { return 'https://example.test/wp-json/' . ltrim($path, '/'); }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg($key, $value, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . $key . '=' . rawurlencode($value); }
}
if (!function_exists('get_temp_dir')) {
    function get_temp_dir() { return sys_get_temp_dir() . '/'; }
}
require_once AZURE_PLUGIN_PATH . 'includes/class-tickets-qr.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-tickets-apple-wallet.php';

$t = new TestRunner('Tickets Apple Wallet');
$W = 'Azure_Tickets_Apple_Wallet';

if (!function_exists('openssl_cms_sign') || !class_exists('ZipArchive')) {
    echo "Skipped: needs OpenSSL CMS and ZipArchive.\n";
    exit(0);
}

$key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
$make_p12 = function ($dn) use ($key) {
    $cert = openssl_csr_sign(openssl_csr_new($dn, $key), null, $key, 365);
    openssl_pkcs12_export($cert, $p12, $key, 'secret pw');
    return $p12;
};
$p12 = $make_p12(array('UID' => 'pass.net.example.tickets', 'CN' => 'Pass Type ID: pass.net.example.tickets', 'OU' => 'TEAM123456', 'O' => 'Example PTSA', 'C' => 'US'));

// ── Certificate ────────────────────────────────────────────────────────
$t->check(!$W::is_configured(), 'not configured to begin with');
$r = $W::save_certificate($p12, 'wrong');
$t->check(!$r['ok'] && strpos($r['error'], 'password') !== false, 'a wrong password is explained');
$r = $W::save_certificate($make_p12(array('CN' => 'Some other cert', 'O' => 'X')), 'secret pw');
$t->check(!$r['ok'] && strpos($r['error'], 'Pass Type ID') !== false, 'a certificate that is not a Pass Type ID one is refused');
$r = $W::save_certificate($p12, 'secret pw');
$t->check($r['ok'], 'a Pass Type ID certificate is accepted');
$t->check($W::pass_type_id() === 'pass.net.example.tickets' && $W::team_id() === 'TEAM123456', 'the Pass Type ID and Team ID are read from it');
$stored = json_decode(WP_Shim::$options[$W::CERT_OPTION], true);
$t->check($W::is_configured() && strpos($stored['cert'], 'BEGIN CERTIFICATE') !== false, 'the certificate is kept in an option');
$t->check(strpos($stored['key'], 'ENCRYPTED') !== false && strpos(WP_Shim::$options[$W::CERT_OPTION], 'secret pw') === false, 'the private key is stored encrypted, and the .p12 password is not stored');
$t->check($W::certificate_info()['expires'] > time() + 300 * 86400, 'its expiry date is shown');
$GLOBALS['test_salt'] = 'rotated-';
$info = $W::certificate_info();
$t->check(!$info['ok'] && strpos($info['error'], 'upload it again') !== false, 'after the site\'s salts change, it asks for the certificate again');
unset($GLOBALS['test_salt']);

openssl_pkcs12_read($p12, $parts, 'secret pw');
openssl_pkey_export($key, $enc_key, 'pem pw');
$t->check($W::read_upload("Bag Attributes\n" . $parts['cert'] . $enc_key, 'pem pw')['ok'], 'a PEM file with the certificate and key is accepted too');

// ── Pass contents ──────────────────────────────────────────────────────
$ticket = (object) array('id' => 7, 'order_id' => 4001, 'product_id' => 501, 'section_id' => 'fr', 'row_letter' => 'D', 'seat_number' => 12,
    'attendee_name' => 'Leon Tsai', 'ticket_code' => 'ABCD234567', 'qr_data' => 'ABCD234567', 'status' => 'active');
$starts = strtotime('2026-11-14 03:00:00 UTC');
$info = array('event' => 'Peter Pan', 'starts' => $starts, 'section' => 'Right of aisle', 'venue' => 'Wilder Gym', 'org' => 'Wilder PTSA', 'order' => '4001');
$pass = $W::pass_data($ticket, $info);
$fields = function ($group) use ($pass) { return array_column($pass['eventTicket'][$group], 'value', 'key'); };
$t->equals(array('row' => 'D', 'seat' => '12', 'section' => 'Right of aisle'), $fields('secondaryFields'), 'the pass shows the row letter and seat number');
$t->equals('ABCD234567', $pass['barcodes'][0]['message'], 'the QR holds the ticket code the door scanner reads');
$t->check($pass['passTypeIdentifier'] === 'pass.net.example.tickets' && $pass['teamIdentifier'] === 'TEAM123456' && $pass['serialNumber'] === 'ABCD234567', 'identifiers come from the certificate; the serial is the ticket code');
$t->equals('2026-11-14T03:00:00Z', $pass['relevantDate'], 'the pass surfaces on the lock screen around the performance');
$t->equals('Leon Tsai', $fields('auxiliaryFields')['name'], 'the attendee name is on the front');
$ga = $W::pass_data((object) array_merge((array) $ticket, array('row_letter' => null, 'seat_number' => null)), array_merge($info, array('section' => '')));
$t->equals(array('section' => 'General admission'), array_column($ga['eventTicket']['secondaryFields'], 'value', 'key'), 'general admission has no row or seat');

// ── Signed .pkpass ─────────────────────────────────────────────────────
$pkpass = $W::build($ticket, $info);
$t->check(is_string($pkpass) && substr($pkpass, 0, 2) === 'PK', 'a .pkpass (zip) is built in memory');
$zip_path = tempnam(sys_get_temp_dir(), 'pkt');
file_put_contents($zip_path, $pkpass);
$zip = new ZipArchive();
$zip->open($zip_path);
$names = array();
for ($i = 0; $i < $zip->numFiles; $i++) {
    $names[] = $zip->getNameIndex($i);
}
sort($names);
$t->equals(array('icon.png', 'icon@2x.png', 'icon@3x.png', 'manifest.json', 'pass.json', 'signature'), $names, 'it holds the pass, icons, manifest and signature');
$manifest = json_decode($zip->getFromName('manifest.json'), true);
$ok = true;
foreach ($manifest as $name => $hash) {
    $ok = $ok && sha1($zip->getFromName($name)) === $hash;
}
$t->check($ok && count($manifest) === 4, 'the manifest lists every file with its SHA-1');

$dir = sys_get_temp_dir() . '/pkt-' . getmypid();
@mkdir($dir);
file_put_contents("$dir/manifest.json", $zip->getFromName('manifest.json'));
file_put_contents("$dir/signature", $zip->getFromName('signature'));
$zip->close();
unlink($zip_path);
exec('openssl cms -verify -binary -noverify -inform DER -in ' . escapeshellarg("$dir/signature") . ' -content ' . escapeshellarg("$dir/manifest.json") . ' -out /dev/null 2>&1', $out, $code);
$t->check($code === 0, 'the detached DER signature verifies against the manifest');
exec('openssl pkcs7 -inform DER -in ' . escapeshellarg("$dir/signature") . ' -print_certs -noout 2>&1', $certs);
$t->check((bool) preg_grep('/Worldwide Developer Relations/', $certs), 'the signature carries Apple\'s WWDR intermediate');
array_map('unlink', glob("$dir/*"));
rmdir($dir);

// ── Download link ──────────────────────────────────────────────────────
$t->equals('https://example.test/wp-json/pta-tickets/v1/wallet/ABCD234567?sig=' . $W::signature('ABCD234567'), $W::url('abcd234567'), 'the email links to a signed download');
$t->check($W::signature('ABCD234567') !== Azure_Tickets_QR::signature('ABCD234567'), 'a QR image link can\'t be turned into a pass download');

// ── Whole-order bundle ─────────────────────────────────────────────────
$t->equals('https://example.test/wp-json/pta-tickets/v1/wallet-order/4001/501/0?sig=' . $W::order_signature(4001, 501, 0), $W::order_url(4001, 501), 'the email links to a signed bundle of the order\'s tickets for the performance');
$sigs = array($W::order_signature(4001, 501, 0), $W::order_signature(4002, 501, 0), $W::order_signature(4001, 502, 0), $W::order_signature(4001, 501, 1));
$t->equals(4, count(array_unique($sigs)), 'the signature only opens that order, performance and part');
$t->check($W::order_signature(4001, 501, 0) !== $W::signature('4001'), 'a single-ticket link can\'t be turned into a bundle link');
$t->check($W::order_link_valid(4001, 501, 0, $W::order_signature(4001, 501, 0)) && !$W::order_link_valid(4001, 501, 0, 'nope'), 'a link is opened by its current signature only');
update_option($W::ISSUED_LINKS_OPTION, array('4001/501/0' => 'aaaaaaaaaaaaaaaaaaaa'));
$t->check($W::order_link_valid(4001, 501, 0, 'aaaaaaaaaaaaaaaaaaaa'), 'a link already emailed under old security keys still opens');
$t->check(!$W::order_link_valid(4002, 501, 0, 'aaaaaaaaaaaaaaaaaaaa') && !$W::order_link_valid(4001, 502, 0, 'aaaaaaaaaaaaaaaaaaaa') && !$W::order_link_valid(4001, 501, 0, ''), 'but only for that order, performance and part');
update_option($W::ISSUED_LINKS_OPTION, array());
$second = $W::build((object) array_merge((array) $ticket, array('seat_number' => 13, 'ticket_code' => 'EFGH234567', 'qr_data' => 'EFGH234567')), $info);
$bundle = $W::bundle(array('ABCD234567' => $pkpass, 'EFGH234567' => $second));
$zip_path = tempnam(sys_get_temp_dir(), 'pkb');
file_put_contents($zip_path, $bundle);
$zip = new ZipArchive();
$zip->open($zip_path);
$t->check($zip->numFiles === 2 && $zip->getFromName('ticket-ABCD234567.pkpass') === $pkpass && $zip->getFromName('ticket-EFGH234567.pkpass') === $second, 'the .pkpasses bundle holds each signed pass unchanged');
$zip->close();
unlink($zip_path);

$W::remove_certificate();
$t->check(!$W::is_configured(), 'removing the certificate turns passes off');

exit($t->finish() > 0 ? 1 : 0);
