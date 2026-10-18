<?php
/**
 * Sheet organizer notices: the sheet's creator (created_by) is emailed on
 * each sign-up and withdrawal, except when they are the volunteer or the
 * sheet has no creator. Also: the #18 donate prompt is opt-in, so a sheet
 * without a donation link or without "Ask to donate" options shows and
 * sends nothing donate-related.
 *
 * Drives the real ajax_signup() / ajax_withdraw() handlers.
 *
 * Run: php tests/test-volunteer-organizer-notice.php
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
function esc_html_e($t, $d = null) { echo htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function _e($t, $d = null) { echo $t; }
function esc_attr_e($t, $d = null) { echo htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_html__($t, $d = null) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_attr__($t, $d = null) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES, 'UTF-8'); }
function wp_login_url($r = '') { return 'https://example.test/wp-login.php'; }
function is_user_logged_in() { return Signup_State::$user_id > 0; }
function current_user_can($c) { return false; }
function date_i18n($f, $ts) { return gmdate($f, (int) $ts); }
function get_user_meta($id, $key, $single = false) { return Signup_State::$meta[$id][$key] ?? ''; }
function update_user_meta($id, $key, $value) { Signup_State::$meta[$id][$key] = $value; return true; }
function wp_mail($to, $subject, $body, $headers = array()) {
    Signup_State::$mail[] = array('to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => (array) $headers);
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

$t = new TestRunner('Volunteer organizer notices');
global $wpdb;

Signup_State::$users[7] = (object) array('ID' => 7, 'display_name' => 'Priya Parent', 'user_email' => 'priya@example.test');
Signup_State::$users[9] = (object) array('ID' => 9, 'display_name' => 'Olivia Organizer', 'user_email' => 'olivia@example.test');
Signup_State::$users[11] = (object) array('ID' => 11, 'display_name' => 'No Email', 'user_email' => '');

function add_sheet($id, $created_by, array $extra = array()) {
    $GLOBALS['wpdb']->tables['wp_azure_volunteer_sheets'][] = array_merge(array(
        'id' => $id, 'title' => 'Bulb Planting Party', 'description' => '', 'status' => 'open', 'allow_group' => 1,
        'event_date' => '2026-10-19 10:00:00', 'event_location' => 'Front garden', 'pta_event_id' => 0,
        'created_by' => $created_by, 'donate_url' => '', 'donate_label' => '', 'donate_amounts' => '',
    ), $extra);
}
function add_activity($id, $sheet_id, $name, $donate = 0) {
    $GLOBALS['wpdb']->tables['wp_azure_volunteer_activities'][] = array(
        'id' => $id, 'sheet_id' => $sheet_id, 'name' => $name, 'description' => '', 'spots_needed' => 30,
        'slot_start' => null, 'slot_end' => null, 'donate_prompt' => $donate, 'sort_order' => 0,
    );
}
function run($vs, $method, array $post) {
    $_POST = $post;
    try {
        $vs->$method();
    } catch (Json_Reply $r) {
        return $r;
    }
    return null;
}
function mail_to($addr) {
    return array_values(array_filter(Signup_State::$mail, function ($m) use ($addr) { return $m['to'] === $addr; }));
}

add_sheet(1, 9);
add_activity(31, 1, 'Attending the bulb planting party');
add_activity(32, 1, 'Bringing bulbs to plant');

$vs = Azure_Volunteer_Signup::get_instance();

// Sign-up: volunteer and organizer both get mail
$r = run($vs, 'ajax_signup', array('activity_ids' => array(31, 32), 'party_sizes' => array(31 => '3', 32 => '1')));
$t->check($r && $r->ok, 'the sign-up succeeds');
$t->equals(2, count(Signup_State::$mail), 'two emails: the volunteer and the organizer');
$t->equals(1, count(mail_to('priya@example.test')), 'the volunteer still gets their confirmation');
$org = mail_to('olivia@example.test');
$t->equals(1, count($org), 'the sheet creator gets one notice');
$o = $org[0] ?? array('subject' => '', 'body' => '');
$t->equals('New sign-up: Priya Parent — Bulb Planting Party', $o['subject'], 'subject names who and which sheet');
$t->check(strpos($o['body'], 'Hi Olivia Organizer,') !== false, 'addressed to the organizer');
$t->check(strpos($o['body'], '<strong>Priya Parent</strong> (priya@example.test) signed up for:') !== false, 'says who signed up');
$t->check(strpos($o['body'], 'Attending the bulb planting party (3 people)') !== false, 'lists the option with the group size');
$t->check(strpos($o['body'], 'Bringing bulbs to plant') !== false, 'and every other option picked');
$t->check(strpos($o['body'], 'href="https://example.test/wp-admin/admin.php?page=azure-plugin-volunteer&amp;edit_sheet=1"') !== false, 'links to the sheet in admin');
$t->check(strpos($o['body'], 'pta_volunteer_subscribe') === false, 'no calendar Subscribe button for the organizer');

// Group resize
Signup_State::$mail = array();
$r = run($vs, 'ajax_signup', array('activity_ids' => array(31), 'party_sizes' => array(31 => '5')));
$t->check($r && $r->ok, 'resizing the group succeeds');
$o = mail_to('olivia@example.test')[0]['body'] ?? '';
$t->check(strpos($o, 'changed their group size for:') !== false && strpos($o, '(5 people)') !== false, 'the organizer hears about the new group size');

// A refused sign-up sends nothing
Signup_State::$mail = array();
run($vs, 'ajax_signup', array('activity_ids' => array(31), 'party_sizes' => array(31 => '5')));
$t->equals(0, count(Signup_State::$mail), 'an unchanged sign-up emails nobody');

// Withdraw
Signup_State::$mail = array();
$r = run($vs, 'ajax_withdraw', array('activity_id' => 31));
$t->check($r && $r->ok, 'withdrawing succeeds');
$c = mail_to('olivia@example.test');
$t->equals(1, count($c), 'the organizer is told about the cancellation');
$t->equals('Cancelled: Priya Parent — Bulb Planting Party', $c[0]['subject'] ?? '', 'cancellation subject');
$t->check(strpos($c[0]['body'] ?? '', 'withdrew from:') !== false && strpos($c[0]['body'] ?? '', 'Attending the bulb planting party (5 people)') !== false, 'naming the option and group size they withdrew');
$t->check(in_array('Reply-To: priya@example.test', $c[0]['headers'] ?? array(), true), 'replies go to the volunteer');
Signup_State::$mail = array();
run($vs, 'ajax_withdraw', array('activity_id' => 31));
$t->equals(0, count(Signup_State::$mail), 'withdrawing from something you were not signed up for emails nobody');

// Skips
add_sheet(2, 7);
add_activity(41, 2, 'Setup crew');
Signup_State::$mail = array();
run($vs, 'ajax_signup', array('activity_ids' => array(41)));
$t->equals(array('priya@example.test'), array_column(Signup_State::$mail, 'to'), 'no organizer notice when the creator signs themselves up');
Signup_State::$mail = array();
run($vs, 'ajax_withdraw', array('activity_id' => 41));
$t->equals(0, count(Signup_State::$mail), 'nor when they withdraw themselves');

add_sheet(3, 0);
add_activity(51, 3, 'Cleanup');
Signup_State::$mail = array();
run($vs, 'ajax_signup', array('activity_ids' => array(51)));
$t->equals(array('priya@example.test'), array_column(Signup_State::$mail, 'to'), 'no organizer notice when created_by is 0');

add_sheet(4, 404);
add_activity(61, 4, 'Snacks');
Signup_State::$mail = array();
run($vs, 'ajax_signup', array('activity_ids' => array(61)));
$t->equals(array('priya@example.test'), array_column(Signup_State::$mail, 'to'), 'no organizer notice when the creator account is gone');

add_sheet(5, 11);
add_activity(71, 5, 'Tables');
Signup_State::$mail = array();
run($vs, 'ajax_signup', array('activity_ids' => array(71)));
$t->equals(array('priya@example.test'), array_column(Signup_State::$mail, 'to'), 'no organizer notice when the creator has no email');

$t->equals(null, Azure_Volunteer_Signup::organizer_for_sheet((object) array('id' => 9), 7), 'sheets from before created_by have no organizer');
$t->equals(null, Azure_Volunteer_Signup::organizer_for_sheet(null, 7), 'no sheet, no organizer');

// The messages are editable under Emails → Messages
$cat = Azure_Email_Messages::catalog();
$t->check(isset($cat['volunteer_organizer_signup'], $cat['volunteer_organizer_cancel']), 'both organizer notices are in the message catalog');

// ── #18: donate is opt-in ─────────────────────────────────────────────
// The PTSA-funded bulb planting sheet: no donation link, nothing ticked.
add_sheet(6, 9);
add_activity(81, 6, 'Attending the bulb planting party');
add_activity(82, 6, 'Bringing bulbs to plant');
Signup_State::$mail = array();
$r = run($vs, 'ajax_signup', array('activity_ids' => array(81, 82)));
$t->check($r && $r->ok, 'signing up on a sheet with no donation set up works');
$t->equals(null, $r->data['donate'] ?? null, 'no donate prompt in the reply, so no donate panel on screen');
$all = implode("\n", array_column(Signup_State::$mail, 'body')) . implode("\n", array_column(Signup_State::$mail, 'subject'));
$t->equals(2, count(Signup_State::$mail), 'volunteer and organizer emails sent');
$t->check(stripos($all, 'donat') === false, 'neither email mentions donating');

// A donation link alone is not enough: an option must ask for it.
add_sheet(7, 9, array('donate_url' => 'https://example.test/donate/', 'donate_label' => 'Donate to bulbs'));
add_activity(91, 7, 'Attending the bulb planting party', 0);
$r = run($vs, 'ajax_signup', array('activity_ids' => array(91)));
$t->equals(null, $r->data['donate'] ?? null, 'a sheet with a link but no "Ask to donate" option shows no donate panel');
// And an option that asks, on a sheet with no link, shows nothing either.
add_sheet(8, 9);
add_activity(95, 8, 'Donating $5+', 1);
$r = run($vs, 'ajax_signup', array('activity_ids' => array(95)));
$t->equals(null, $r->data['donate'] ?? null, 'an "Ask to donate" option without a donation link shows no donate panel');

// The sheet itself renders no donate markup.
$sheet6 = Azure_Volunteer_Signup::get_sheet(6);
$render = new ReflectionMethod('Azure_Volunteer_Signup', 'render_frontend');
$render->setAccessible(true);
ob_start();
$acts6 = array_values(array_filter(Azure_Volunteer_Signup::get_activities(6), function ($a) { return (int) $a->sheet_id === 6; }));
$render->invoke($vs, $sheet6, $acts6, 7);
$page = ob_get_clean();
$t->check(strpos($page, 'Attending the bulb planting party') !== false, 'the sheet renders');
$t->check(stripos($page, 'donat') === false, 'the sheet page has nothing donate-related');
$js = file_get_contents(AZURE_PLUGIN_PATH . 'js/volunteer-frontend.js');
$t->check(strpos($js, 'if (res.data.donate && res.data.donate.url) {') !== false, 'the donate panel only opens when the reply carries a donate prompt');
$conf = file_get_contents(AZURE_PLUGIN_PATH . 'includes/class-email-messages.php');
$t->check(stripos($conf, 'donat') === false, 'no email template has donate content');

$_POST = array();
exit($t->finish() > 0 ? 1 : 0);
