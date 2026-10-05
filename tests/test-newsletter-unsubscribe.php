<?php
/**
 * Newsletter unsubscribe links must actually unsubscribe.
 *
 * The emailed link carried a one-way HMAC tracking token, but the handler
 * base64-decoded it looking for "email|id". Real links failed with
 * "Invalid unsubscribe link", or "succeeded" for a binary garbage address
 * when the decoded bytes happened to contain "|". Role and all-user lists
 * also ignored unsubscribes entirely, since they come from wp_users.
 *
 * Run: php tests/test-newsletter-unsubscribe.php
 */

require_once __DIR__ . '/wp-shim.php';
ini_set('error_log', '/dev/null');

if (!function_exists('wp_salt')) {
    // Fresh salts on every container start; tokens must not depend on them.
    function wp_salt($scheme = 'auth') { return $GLOBALS['test_salt'] . $scheme; }
}
if (!function_exists('is_email')) {
    function is_email($e) { return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : false; }
}
if (!function_exists('rest_url')) {
    function rest_url($path = '') { return 'https://example.test/wp-json/' . ltrim($path, '/'); }
}
if (!function_exists('esc_url')) {
    function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_html__')) {
    function esc_html__($t, $d = null) { return esc_html($t); }
}
if (!function_exists('wp_parse_args')) {
    function wp_parse_args($a, $d = array()) { return array_merge($d, (array) $a); }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($len = 12, $special = true, $extra = false) { return str_repeat('p', $len); }
}
if (!function_exists('get_user_by')) {
    function get_user_by($field, $value) {
        return $GLOBALS['test_users'][strtolower($value)] ?? false;
    }
}
$GLOBALS['test_salt'] = 'salt-a-';
$GLOBALS['test_users'] = array();

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-module.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-lists.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-queue.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-sender.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-tracking.php';

class Unsub_WPDB extends Fake_WPDB {
    public $queries = array();
    public $opted_out = array();
    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        return array('sql' => $sql, 'args' => $args);
    }
    public function query($q) { $this->queries[] = $q; return 1; }
    public function get_col($q) {
        $sql = is_array($q) ? $q['sql'] : $q;
        return strpos($sql, 'list_id = %d AND unsubscribed_at IS NOT NULL') !== false ? $this->opted_out : array();
    }
    public function get_var($q) { return null; }
    public function sql_like($needle) {
        return array_values(array_filter($this->queries, function ($q) use ($needle) {
            return strpos($q['sql'], $needle) !== false;
        }));
    }
}

function fresh_db() {
    global $wpdb;
    $wpdb = new Unsub_WPDB();
    return $wpdb;
}

$t = new TestRunner('Newsletter unsubscribe');
WP_Shim::reset();
WP_Shim::$options['azure_newsletter_click_key'] = str_repeat('k', 64);
fresh_db();

// --- token ---------------------------------------------------------------
$token = Azure_Newsletter_Lists::unsubscribe_token('Danish@Example.com', 27);
$t->check(preg_match('/^[a-zA-Z0-9_-]+$/', $token) === 1, 'token fits the REST route pattern');
$data = Azure_Newsletter_Lists::verify_unsubscribe_token($token);
$t->equals('danish@example.com', $data['email'] ?? null, 'token carries the recipient address');
$t->equals(27, $data['newsletter_id'] ?? null, 'token carries the newsletter id');

$GLOBALS['test_salt'] = 'salt-b-';
$t->check(Azure_Newsletter_Lists::verify_unsubscribe_token($token) !== null, 'token survives a container restart (new wp_salt)');

$forged = Azure_Newsletter_Lists::unsubscribe_token('victim@example.com', 27);
$t->check(
    Azure_Newsletter_Lists::verify_unsubscribe_token(substr($forged, 0, -22) . substr($token, -22)) === null,
    'another address with a copied signature is refused'
);
$t->check(Azure_Newsletter_Lists::verify_unsubscribe_token(substr($token, 0, -1) . (substr($token, -1) === 'A' ? 'B' : 'A')) === null, 'altered signature is refused');
$t->check(Azure_Newsletter_Lists::verify_unsubscribe_token(rtrim(strtr(base64_encode('x@y.com|1|abc'), '+/', '-_'), '=')) === null, 'old unsigned base64 format is refused');

$legacy = rtrim(strtr(base64_encode(hash_hmac('sha256', 'x', 'y', true)), '+/', '-_'), '=');
$t->check(Azure_Newsletter_Lists::verify_unsubscribe_token($legacy) === null, 'already-sent HMAC tracking token is not mistaken for a signed one');

WP_Shim::$options['azure_newsletter_click_key'] = str_repeat('z', 64);
$t->check(Azure_Newsletter_Lists::verify_unsubscribe_token($token) === null, 'token is bound to the stored site key');
WP_Shim::$options['azure_newsletter_click_key'] = str_repeat('k', 64);

// --- request handling ----------------------------------------------------
$lists = new Azure_Newsletter_Lists();

$db = fresh_db();
$r = $lists->handle_unsubscribe_request($token, 'GET');
$t->equals('confirm', $r['state'], 'GET with a valid link shows a confirm page');
$t->check(empty($db->queries) && empty($db->writes), 'GET writes nothing (link scanners prefetch GETs)');

$db = fresh_db();
$r = $lists->handle_unsubscribe_request($legacy, 'GET');
$t->equals('ask', $r['state'], 'GET with an already-sent link asks for the address instead of erroring');
$t->check(empty($db->queries) && empty($db->writes), 'legacy GET writes nothing');

$db = fresh_db();
$GLOBALS['test_users']['danish@example.com'] = (object) array('ID' => 1191);
$r = $lists->handle_unsubscribe_request($token, 'POST');
$t->equals('done', $r['state'], 'POST with a valid link unsubscribes');
$update = $db->sql_like('UPDATE wp_azure_newsletter_list_members SET unsubscribed_at');
$t->check(!empty($update) && $update[0]['args'][1] === 'danish@example.com' && $update[0]['args'][2] === 1191, 'list rows stored by email or by user id are marked');
$optout = $db->sql_like('INSERT INTO wp_azure_newsletter_list_members');
$t->check(!empty($optout) && $optout[0]['args'][0] === Azure_Newsletter_Lists::OPT_OUT_LIST_ID && $optout[0]['args'][2] === 'danish@example.com', 'site-wide opt-out row is written for role-based recipients');
$t->check(!empty($db->sql_like("DELETE FROM wp_azure_newsletter_queue WHERE status = 'pending'")), 'already-queued sends to the address are dropped');
$stat = $db->writes[0]['data'] ?? array();
$t->check(($stat['email'] ?? '') === 'danish@example.com' && ($stat['event_type'] ?? '') === 'unsubscribed', 'stats record the real address, not decoded bytes');

$db = fresh_db();
$r = $lists->handle_unsubscribe_request($token, 'POST', 'someone-else@example.com');
$t->equals('danish@example.com', $r['email'], 'a posted email cannot override a signed link');

$db = fresh_db();
$r = $lists->handle_unsubscribe_request($legacy, 'POST', ' Parent@Example.com ');
$t->equals('done', $r['state'], 'already-sent link + typed address unsubscribes');
$t->equals('parent@example.com', $r['email'], 'typed address is normalised');

$db = fresh_db();
$r = $lists->handle_unsubscribe_request($legacy, 'POST', 'not-an-email');
$t->equals('invalid_email', $r['state'], 'invalid typed address is rejected');
$t->check(empty($db->queries) && empty($db->writes), 'invalid typed address writes nothing');

// --- page ----------------------------------------------------------------
$action = rest_url('azure-plugin/v1/newsletter/unsubscribe/' . $token);
$confirm = Azure_Newsletter_Module::unsubscribe_page_html('confirm', 'danish@example.com', $action);
$t->check(strpos($confirm, '<form method="post" action="' . esc_url($action) . '"') !== false, 'confirm page posts back to the same link');
$t->check(strpos($confirm, 'danish@example.com') !== false, 'confirm page names the address');
$ask = Azure_Newsletter_Module::unsubscribe_page_html('ask', '', $action);
$t->check(strpos($ask, 'type="email"') !== false && strpos($ask, 'name="email"') !== false, 'ask page has an email field');
$done = Azure_Newsletter_Module::unsubscribe_page_html('done', '<b>x@y.com</b>', $action);
$t->check(strpos($done, '<b>x@y.com</b>') === false, 'address is escaped');
$t->check(strpos($done, '<!DOCTYPE html>') === 0, 'page is HTML, not a JSON-encoded string');

// --- queue filter --------------------------------------------------------
$db = fresh_db();
$db->opted_out = array('danish@example.com');
$queue = new Azure_Newsletter_Queue();
$res = call_private($queue, 'filter_blocked_recipients_with_stats', array(array(
    array('user_id' => 1191, 'email' => 'Danish@Example.com', 'name' => 'D'),
    array('user_id' => 7, 'email' => 'keep@example.com', 'name' => 'K'),
)));
$t->equals(array('keep@example.com'), array_column($res['recipients'], 'email'), 'opted-out address is dropped from a role/all-user list');
$t->equals(1, $res['unsubscribed'], 'filter reports the unsubscribed count');

// --- sender --------------------------------------------------------------
$db = fresh_db();
WP_Shim::$settings = array(
    'newsletter_sending_service' => 'mailgun',
    'newsletter_mailgun_api_key' => 'key',
    'newsletter_mailgun_domain' => 'mg.example.test',
);
WP_Shim::on_request('mailgun', 200, array('id' => 'm1'));
$sender = new Azure_Newsletter_Sender();
$sender->send(array(
    'to' => 'parent@example.com',
    'from' => 'news@example.test',
    'subject' => 'S',
    'html' => '<html><body><a href="https://example.test/story">Story</a> <a href="{{unsubscribe_url}}">Unsubscribe</a></body></html>',
    'newsletter_id' => 27,
));
$body = WP_Shim::$http_calls[0]['args']['body'] ?? array();
preg_match('#newsletter/unsubscribe/([A-Za-z0-9_-]+)#', $body['html'] ?? '', $m);
$sent = Azure_Newsletter_Lists::verify_unsubscribe_token($m[1] ?? '');
$t->equals('parent@example.com', $sent['email'] ?? null, 'footer link in a real send verifies to the recipient');
$t->check(strpos($body['h:List-Unsubscribe'] ?? '', '<' . rest_url('azure-plugin/v1/newsletter/unsubscribe/')) === 0, 'Mailgun send has a List-Unsubscribe header');
$t->equals('List-Unsubscribe=One-Click', $body['h:List-Unsubscribe-Post'] ?? null, 'Mailgun send advertises RFC 8058 one-click');

// --- provider webhook ----------------------------------------------------
$db = fresh_db();
call_private(new Azure_Newsletter_Tracking(), 'process_event', array('unsubscribed', 'hooked@example.com', 27, array()));
$t->check(!empty($db->sql_like('INSERT INTO wp_azure_newsletter_list_members')), 'Mailgun unsubscribed webhook opts the address out');

exit($t->finish() === 0 ? 0 : 1);
