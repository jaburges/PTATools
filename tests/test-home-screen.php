<?php
/**
 * Web Push round-trip and home-screen audience matching.
 *
 * Run: php tests/test-home-screen.php
 */

require_once __DIR__ . '/wp-shim.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-web-push.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-home-screen.php';

$t = new TestRunner('Home screen push');

$ua = openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
$as = openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
$ua_public = Azure_Web_Push::public_raw_from_key($ua);
$auth = random_bytes(16);
$plain = '{"title":"Carnival","body":"Friday"}';
$record = Azure_Web_Push::encrypt_payload($plain, $ua_public, $auth, $as);
$t->check(strlen($record) > 100, 'encrypted record has a header and ciphertext');
$t->equals($plain, Azure_Web_Push::decrypt_payload($record, $ua, $auth), 'the browser key decrypts the payload');

$keys = Azure_Web_Push::generate_vapid_keys();
$raw = Azure_Web_Push::b64url_decode($keys['public']);
$t->equals(65, strlen($raw), 'VAPID public key is an uncompressed P-256 point');
$t->equals("\x04", $raw[0], 'the public key starts with the uncompressed marker');
$authz = Azure_Web_Push::vapid_authorization('https://fcm.googleapis.com/fcm/send/abc', $keys['public'], $keys['private'], 'mailto:board@wilderptsa.net');
$t->check(strpos($authz, 'vapid t=') === 0, 'VAPID header is present');

$kids = array(
    array('grade' => '1', 'teacher' => 'Congdon'),
    array('grade' => '4', 'teacher' => 'Newell'),
);
$t->check(Azure_Home_Screen::subscription_matches(649, $kids, 'all', array()), 'everyone includes a signed-in parent');
$t->check(Azure_Home_Screen::subscription_matches(0, array(), 'all', array()), 'everyone includes a signed-out device');
$t->check(!Azure_Home_Screen::subscription_matches(0, array(), 'grade', array('1')), 'a signed-out device is left out of a grade send');
$t->check(Azure_Home_Screen::subscription_matches(649, $kids, 'grade', array('1')), 'grade 1 matches the first child');
$t->check(Azure_Home_Screen::subscription_matches(649, $kids, 'teacher', array('Newell')), 'a second child can match the teacher');
$t->check(!Azure_Home_Screen::subscription_matches(649, $kids, 'teacher', array('Sigel')), 'another teacher does not match');
$t->check(Azure_Home_Screen::grade_matches('4', array('4/5')), 'a child in grade 4 matches a mixed 4/5 class');
$t->check(Azure_Home_Screen::grade_matches('4/5', array('5')), 'a mixed 4/5 class matches a child stored as 5');
$t->check(!Azure_Home_Screen::grade_matches('3', array('4/5')), 'grade 3 does not overlap 4/5');

exit($t->finish() === 0 ? 0 : 1);
