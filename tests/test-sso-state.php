<?php
/**
 * Regression checks for the Microsoft SSO OAuth state.
 *
 * The state used to be wp_create_nonce('azure_sso_state'). A nonce changes
 * when the visitor's sign-in status changes, so anyone whose session ended
 * (or began) between clicking "Sign in with Microsoft" and returning from
 * Microsoft hit a dead-end "Invalid state parameter" page.
 *
 * Run:  php tests/test-sso-state.php
 */

require __DIR__ . '/wp-shim.php';

define('AZURE_PLUGIN_PATH', __DIR__ . '/../Azure Plugin/');
if (!defined('COOKIEPATH')) {
    define('COOKIEPATH', '/');
}
if (!defined('COOKIE_DOMAIN')) {
    define('COOKIE_DOMAIN', '');
}

class Test_Redirect extends Exception {}

if (!function_exists('wp_generate_password')) {
    function wp_generate_password($length = 12, $special = true, $extra = false) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $out;
    }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) { return (string) $url; }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
}
if (!function_exists('wp_validate_redirect')) {
    function wp_validate_redirect($url, $default = '') {
        $host = parse_url($url, PHP_URL_HOST);
        return ($host === null || $host === 'example.test') ? $url : $default;
    }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg($key, $value = null, $url = null) {
        if (is_array($key)) {
            return '';
        }
        return $url . (strpos($url, '?') === false ? '?' : '&') . $key . '=' . $value;
    }
}
if (!function_exists('is_ssl')) {
    function is_ssl() { return true; }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() { return !empty($GLOBALS['sso_test_logged_in']); }
}
if (!function_exists('wp_login_url')) {
    function wp_login_url() { return 'https://example.test/wp-login.php'; }
}
if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect($location) { throw new Test_Redirect($location); }
}
if (!function_exists('wp_die')) {
    function wp_die($message = '') { throw new Exception('wp_die: ' . $message); }
}

require __DIR__ . '/../Azure Plugin/includes/class-sso-auth.php';

$t = new TestRunner('Azure_SSO_Auth state');

function make_sso() {
    WP_Shim::reset();
    $_COOKIE = array();
    $GLOBALS['sso_test_logged_in'] = false;
    return new Azure_SSO_Auth();
}

/** Run handle_callback() and return where it redirected to. */
function callback_redirect($sso, $state) {
    $_GET = array('code' => 'fake-code', 'state' => $state);
    try {
        $sso->handle_callback();
    } catch (Test_Redirect $r) {
        return $r->getMessage();
    } catch (Exception $e) {
        return $e->getMessage();
    }
    return '(no redirect)';
}

// 1. A new state is random, stored server-side, and keeps its return address.
$sso = make_sso();
$state = call_private($sso, 'create_state', array('https://example.test/leaders/'));
$t->check((bool) preg_match('/^[A-Za-z0-9]{32}$/', $state), 'state is a 32-char random token', $state);
$stored = get_transient('azure_sso_state_' . $state);
$t->equals('https://example.test/leaders/', $stored['redirect'] ?? null, 'return address is stored with the state');
$t->equals(Azure_SSO_Auth::STATE_TTL, WP_Shim::$transient_ttls['azure_sso_state_' . $state] ?? null, 'state expires after STATE_TTL');
$t->check($state !== call_private($sso, 'create_state'), 'each sign-in gets a different state');

// 2. The matching browser can use it once.
$sso = make_sso();
$state = call_private($sso, 'create_state', array('https://example.test/leaders/'));
$_COOKIE[Azure_SSO_Auth::STATE_COOKIE] = $state;
$t->equals(array('redirect' => 'https://example.test/leaders/'), call_private($sso, 'consume_state', array($state)), 'state is accepted from the browser that started sign-in');
$t->equals(null, call_private($sso, 'consume_state', array($state)), 'state cannot be replayed');

// 3. Sign-in status doesn't matter: the state still validates after it flips.
$sso = make_sso();
$state = call_private($sso, 'create_state');
$_COOKIE[Azure_SSO_Auth::STATE_COOKIE] = $state;
$GLOBALS['sso_test_logged_in'] = true;
$t->check(call_private($sso, 'consume_state', array($state)) !== null, 'state survives a sign-in status change');

// 4. Rejected: other browsers, expired states, junk.
$sso = make_sso();
$state = call_private($sso, 'create_state');
$t->equals(null, call_private($sso, 'consume_state', array($state)), 'rejected without the browser cookie');
$_COOKIE[Azure_SSO_Auth::STATE_COOKIE] = str_repeat('a', 32);
$t->equals(null, call_private($sso, 'consume_state', array($state)), 'rejected when the cookie belongs to another sign-in');
$_COOKIE[Azure_SSO_Auth::STATE_COOKIE] = $state;
WP_Shim::$transients['azure_sso_state_' . $state][1] = time() - 1;
$t->equals(null, call_private($sso, 'consume_state', array($state)), 'rejected once expired');
$_COOKIE[Azure_SSO_Auth::STATE_COOKIE] = 'b65a0cc64b';
$t->equals(null, call_private($sso, 'consume_state', array('b65a0cc64b')), 'old nonce-style state is rejected');

// 5. A bad state sends people somewhere useful instead of a dead end.
$sso = make_sso();
$t->equals('https://example.test/wp-login.php?sso_error=expired', callback_redirect($sso, 'b65a0cc64b'), 'signed-out visitor goes back to the login page with the expired notice');
$sso = make_sso();
$GLOBALS['sso_test_logged_in'] = true;
$t->equals('https://example.test/wp-admin/', callback_redirect($sso, 'b65a0cc64b'), 'already signed-in visitor just carries on');

// 6. Login links start sign-in on click, so they can sit on cached pages.
$sso = make_sso();
$t->equals('https://example.test/wp-admin/admin-ajax.php?action=azure_sso_start', $sso->get_login_url(), 'login link points at the start endpoint');
$t->equals(
    'https://example.test/wp-admin/admin-ajax.php?action=azure_sso_start&redirect_to=' . rawurlencode('https://example.test/leaders/'),
    $sso->get_login_url('https://example.test/leaders/'),
    'login link carries the return address'
);
$t->equals(array(), WP_Shim::$transients, 'building a login link creates no state');

// 7. Nothing still relies on the nonce.
$source = file_get_contents(AZURE_PLUGIN_PATH . 'includes/class-sso-auth.php');
$t->check(strpos($source, "'azure_sso_state')") === false, 'no wp_create_nonce/wp_verify_nonce for azure_sso_state remains');
$t->check(strpos($source, 'Invalid state parameter') === false, 'the dead-end "Invalid state parameter" page is gone');

$t->finish();
