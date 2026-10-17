<?php
/**
 * #19: a volunteer sign-up sends the confirmation email (and says so on
 * screen) for single, multi-option and group sign-ups, and the reply
 * carries the donate prompt when the option asks for one (#18).
 *
 * Drives the real ajax_signup() handler against the fake $wpdb.
 *
 * Run: php tests/test-volunteer-signup-confirmation.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

class Signup_State {
    public static $user_id = 7;
    public static $mail = array();
    public static $users = array();
    public static $meta = array();
}

class Json_Reply extends Exception {
    public $ok;
    public $data;
    public function __construct($ok, $data) {
        parent::__construct('json');
        $this->ok = $ok;
        $this->data = $data;
    }
}

function _n($single, $plural, $n, $domain = null) { return $n == 1 ? $single : $plural; }
function add_shortcode($tag, $cb) { return true; }
define('EP_ROOT', 64);
define('EP_PAGES', 4096);
function add_rewrite_endpoint($name, $places) { return true; }
function check_ajax_referer($action, $field = false) { return true; }
function get_current_user_id() { return Signup_State::$user_id; }
function wp_unslash($v) { return $v; }
function absint($v) { return abs((int) $v); }
function get_userdata($id) { return Signup_State::$users[(int) $id] ?? false; }
function get_post($id) { return null; }
function get_user_meta($id, $key, $single = false) { return Signup_State::$meta[$id][$key] ?? ''; }
function update_user_meta($id, $key, $value) { Signup_State::$meta[$id][$key] = $value; return true; }
function wp_mail($to, $subject, $body, $headers = array()) {
    Signup_State::$mail[] = array('to' => $to, 'subject' => $subject, 'body' => $body);
    return true;
}
function wp_send_json_success($data = null) { throw new Json_Reply(true, $data); }
function wp_send_json_error($data = null) { throw new Json_Reply(false, $data); }

/** Sums party sizes the way count_signups() asks MySQL to. */
class Signup_WPDB extends Fake_WPDB {
    public function get_col($sql, $x = 0) { return array(); }
    public function query($sql) { return 0; }
    public function get_var($sql) {
        if (stripos($sql, 'SUM(GREATEST(party_size') !== false) {
            $args = $this->prepare_args;
            $this->prepare_args = array();
            $sum = 0;
            foreach ($this->tables['wp_azure_volunteer_signups'] ?? array() as $row) {
                if ((string) $row['activity_id'] === (string) ($args[0] ?? '')) {
                    $sum += max(1, (int) ($row['party_size'] ?? 1));
                }
            }
            return $sum;
        }
        return parent::get_var($sql);
    }
}

$GLOBALS['wpdb'] = new Signup_WPDB();
WP_Shim::$timezone = 'America/Los_Angeles';

require_once AZURE_PLUGIN_PATH . 'includes/class-email-messages.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-volunteer-signup.php';

$t = new TestRunner('Volunteer sign-up confirmation');
global $wpdb;

Signup_State::$users[7] = (object) array('ID' => 7, 'display_name' => 'Priya Parent', 'user_email' => 'priya@example.test');
$wpdb->tables['wp_azure_volunteer_sheets'][] = (array(
    'id' => 1, 'title' => 'Culture Night performances', 'status' => 'open', 'allow_group' => 1,
    'event_date' => '2026-11-06 18:00:00', 'event_location' => 'Wilder gym', 'pta_event_id' => 0,
    'donate_url' => '', 'donate_label' => '', 'donate_amounts' => '',
));
$wpdb->tables['wp_azure_volunteer_activities'][] = (array('id' => 21, 'sheet_id' => 1, 'name' => 'Dance performance', 'spots_needed' => 20, 'slot_start' => null, 'slot_end' => null, 'donate_prompt' => 0));
$wpdb->tables['wp_azure_volunteer_activities'][] = (array('id' => 22, 'sheet_id' => 1, 'name' => 'Music performance', 'spots_needed' => 20, 'slot_start' => null, 'slot_end' => null, 'donate_prompt' => 0));

$vs = Azure_Volunteer_Signup::get_instance();

function run_signup($vs, array $post) {
    $_POST = $post;
    try {
        $vs->ajax_signup();
    } catch (Json_Reply $r) {
        return $r;
    }
    return null;
}

// One performance
$r = run_signup($vs, array('activity_ids' => array(21)));
$t->check($r && $r->ok, 'the sign-up succeeds');
$t->equals('You signed up for: Dance performance', $r->data['message'] ?? '', 'the page says what they signed up for');
$t->equals(1, count(Signup_State::$mail), 'one confirmation email is sent');
$mail = Signup_State::$mail[0] ?? array('to' => '', 'subject' => '', 'body' => '');
$t->equals('priya@example.test', $mail['to'], 'to the parent who signed up');
$t->check($mail['subject'] !== '', 'with a subject');
$t->check(strpos($mail['body'], 'Dance performance') !== false, 'naming the performance');
$t->check(strpos($mail['body'], 'Culture Night performances') !== false, 'and the event');
$t->check(strpos($mail['body'], 'pta_volunteer_subscribe=') !== false, 'with the https Subscribe link (#21)');
$t->check(strpos($mail['body'], 'webcal://') === false, 'and no webcal:// link in the email');
$t->equals(null, $r->data['donate'] ?? null, 'no donate prompt when the sheet has none');

// Signing up again for the same thing does not email twice
Signup_State::$mail = array();
$r = run_signup($vs, array('activity_ids' => array(21), 'party_sizes' => array(21 => '1')));
$t->check($r && !$r->ok, 'a repeat sign-up is refused');
$t->equals(0, count(Signup_State::$mail), 'and sends no second email');

// Two performances in one go
$wpdb->delete('wp_azure_volunteer_signups', array('activity_id' => 21));
Signup_State::$mail = array();
$r = run_signup($vs, array('activity_ids' => array(21, 22)));
$t->check($r && $r->ok, 'signing up for two options at once succeeds');
$t->equals(1, count(Signup_State::$mail), 'one email covers both');
$body = Signup_State::$mail[0]['body'] ?? '';
$t->check(strpos($body, 'Dance performance') !== false && strpos($body, 'Music performance') !== false, 'and lists both');

// A group: changing the party size sends an updated confirmation
Signup_State::$mail = array();
$r = run_signup($vs, array('activity_ids' => array(22), 'party_sizes' => array(22 => '3')));
$t->check($r && $r->ok, 'resizing to a group of 3 succeeds');
$t->equals(1, count(Signup_State::$mail), 'and emails a confirmation');
$t->check(strpos(Signup_State::$mail[0]['body'] ?? '', 'Music performance (3 people)') !== false, 'naming the group size');

// #18: an option that asks for a donation
$wpdb->update('wp_azure_volunteer_sheets', array('donate_url' => 'https://example.test/donate/', 'donate_label' => 'Donate to bulbs'), array('id' => 1));
$wpdb->tables['wp_azure_volunteer_activities'][] = (array('id' => 23, 'sheet_id' => 1, 'name' => 'Bring bulbs and donate', 'spots_needed' => 20, 'slot_start' => null, 'slot_end' => null, 'donate_prompt' => 1));
Signup_State::$mail = array();
$r = run_signup($vs, array('activity_ids' => array(23)));
$t->check($r && $r->ok, 'signing up for a donate option succeeds');
$t->equals('Donate to bulbs', $r->data['donate']['label'] ?? '', 'the reply carries the donate prompt');
$t->equals('https://example.test/donate/?donate_amount=5', $r->data['donate']['amounts'][0]['url'] ?? '', 'with amount buttons from $5');
$t->equals(1, count(Signup_State::$mail), 'and the confirmation email still goes out');

$_POST = array();
exit($t->finish() > 0 ? 1 : 0);
