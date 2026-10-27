<?php
/**
 * Teen volunteers: email-only accounts that can't sign in, get a private
 * cancel link instead of a calendar subscription, are left out of
 * newsletters, and are deleted after the school year ends.
 *
 * Run: php tests/test-teen-volunteer.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

class Teen_State {
    public static $users = array();
    public static $meta = array();
    public static $inserted = array();
    public static $deleted = array();
    public static $roles = array();
}

function teen_user($id, $email, $roles) {
    $u = (object) array('ID' => $id, 'user_email' => $email, 'display_name' => 'Someone', 'roles' => $roles);
    Teen_State::$users[$id] = $u;
    return $u;
}

function get_userdata($id) { return Teen_State::$users[(int) $id] ?? false; }
function get_user_by($field, $value) {
    foreach (Teen_State::$users as $u) {
        if (strcasecmp($u->user_email, $value) === 0) {
            return $u;
        }
    }
    return false;
}
function get_user_meta($id, $key, $single = false) { return Teen_State::$meta[$id][$key] ?? ''; }
function update_user_meta($id, $key, $value) { Teen_State::$meta[$id][$key] = $value; return true; }
function get_users($args) {
    if (($args['role'] ?? '') === 'teen_volunteer') {
        $ids = array();
        foreach (Teen_State::$users as $u) {
            if (in_array('teen_volunteer', $u->roles, true)) {
                $ids[] = (string) $u->ID;
            }
        }
        return $ids;
    }
    return array();
}
function get_role($r) { return Teen_State::$roles[$r] ?? null; }
function add_role($r, $name, $caps) { Teen_State::$roles[$r] = $name; }
function sanitize_email($e) { return trim((string) $e); }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function wp_generate_password($len = 12, $special = true, $extra = false) { return str_repeat('a', $len); }
function wp_insert_user($data) {
    $id = 900 + count(Teen_State::$inserted);
    Teen_State::$inserted[] = $data;
    teen_user($id, $data['user_email'], array($data['role']));
    return $id;
}
function wp_delete_user($id) { Teen_State::$deleted[] = (int) $id; unset(Teen_State::$users[(int) $id]); return true; }
function wp_salt($scheme = 'auth') { return 'test-salt'; }
function add_query_arg($key, $value, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . $key . '=' . rawurlencode($value); }

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-volunteer-signup.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-teen-volunteer.php';

$t = new TestRunner('Teen volunteers');

$parent = teen_user(10, 'parent@example.org', array('parent'));
$teen = teen_user(20, 'teen@example.org', array('teen_volunteer'));

// Accounts
$t->check(Azure_Teen_Volunteer::is_teen_id(20), 'a Teen Volunteer account is recognised');
$t->check(!Azure_Teen_Volunteer::is_teen_id(10), 'a parent is not a teen');
$t->check(is_wp_error(Azure_Teen_Volunteer::block_login($teen)), 'teens cannot sign in');
$t->check(Azure_Teen_Volunteer::block_login($parent) === $parent, 'other accounts sign in as before');

$t->equals(20, Azure_Teen_Volunteer::find_or_create('teen@example.org'), 'a returning teen reuses their account');
$taken = Azure_Teen_Volunteer::find_or_create('parent@example.org');
$t->check(is_wp_error($taken) && $taken->get_error_code() === 'account_exists', 'an email with a regular account is asked to sign in');
$t->check(is_wp_error(Azure_Teen_Volunteer::find_or_create('not an email')), 'an invalid email is refused');

$new_id = Azure_Teen_Volunteer::find_or_create('new.teen@example.org');
$made = Teen_State::$inserted[0] ?? array();
$t->check(is_int($new_id) && $new_id > 0, 'a new teen gets an account');
$t->equals('teen_volunteer', $made['role'] ?? '', 'with the Teen Volunteer role');
$t->equals('new.teen@example.org', $made['user_email'] ?? '', 'holding their email');
$t->check(!isset($made['first_name']) && !isset($made['last_name']), 'and no name');
$t->check(strpos($made['user_login'] ?? '', 'new.teen') === false, 'the login name does not repeat the email');
$t->check(isset(Teen_State::$roles['teen_volunteer']), 'the role is registered');

// Cancel link
$url = Azure_Teen_Volunteer::cancel_url(20, 7);
$t->check(preg_match('/pta_teen_cancel=([^&]+)/', $url, $m) === 1, 'the cancel link carries a token');
$value = rawurldecode($m[1] ?? '');
$t->equals(array(20, 7), Azure_Teen_Volunteer::parse_cancel_value($value), 'the link identifies the teen and the shift');
$t->equals(null, Azure_Teen_Volunteer::parse_cancel_value(str_replace('20.7.', '20.8.', $value)), 'it cannot be reused for another shift');
$t->equals(null, Azure_Teen_Volunteer::parse_cancel_value(str_replace('20.7.', '21.7.', $value)), 'or for another person');
$t->equals(null, Azure_Teen_Volunteer::parse_cancel_value('20.7.zzz'), 'a malformed link is refused');

// Emails
$sheet = (object) array('title' => 'Fall Festival', 'event_location' => 'Gym', 'event_date' => '2026-10-30 16:00:00');
$act = (object) array('id' => 7, 'name' => 'Setup', 'slot_start' => '2026-10-30 16:00:00', 'slot_end' => '2026-10-30 18:00:00');

$t->equals('', Azure_Volunteer_Signup::calendar_subscribe_url(20), 'teens get no calendar subscribe link');
list(, $confirm) = Azure_Volunteer_Signup::compose_volunteer_message('volunteer_confirmation', 'Teen volunteer', $sheet, array($act), 'Fall Festival', '', 20);
$t->check(strpos($confirm, '>Subscribe</a>') === false, 'the confirmation has no Subscribe button');
$t->check(strpos($confirm, 'pta_volunteer_subscribe') === false, 'nor a subscribe link');
$t->check(strpos($confirm, htmlspecialchars($url, ENT_QUOTES)) !== false, 'it carries this shift\'s cancel link');
$t->check(strpos($confirm, 'Cancel my sign-up') !== false, 'labelled Cancel my sign-up');
$t->check(strpos($confirm, 'Hi there,') !== false, 'and greets them without a name');
$t->check(strpos($confirm, 'Gym') !== false && strpos($confirm, 'Setup') !== false, 'with the shift and location');
list(, $remind) = Azure_Volunteer_Signup::compose_volunteer_message('volunteer_reminder', 'Teen volunteer', $sheet, array($act), 'Fall Festival', '', 20);
$t->check(strpos($remind, htmlspecialchars($url, ENT_QUOTES)) !== false && strpos($remind, '>Subscribe</a>') === false, 'reminders carry the cancel link, not Subscribe');
list(, $parent_mail) = Azure_Volunteer_Signup::compose_volunteer_message('volunteer_confirmation', 'Pat', $sheet, array($act), 'Fall Festival', '', 10);
$t->check(strpos($parent_mail, '>Subscribe</a>') !== false && strpos($parent_mail, 'pta_teen_cancel') === false, 'parents still get Subscribe and no teen link');

// Retention
$t->equals('2026-07-01', Azure_Teen_Volunteer::school_year_start('2026-10-22'), 'October is in the school year that began July 1');
$t->equals('2026-07-01', Azure_Teen_Volunteer::school_year_start('2027-06-30'), 'June 30 is still the same school year');
$t->equals('2027-07-01', Azure_Teen_Volunteer::school_year_start('2027-07-01'), 'July 1 starts a new one');

class Teen_Fake_Db {
    public $prefix = 'wp_';
    public $keep = array();
    public $sql = '';
    public $deleted = array();
    public function prepare($sql, ...$args) { return vsprintf(str_replace('%s', "'%s'", $sql), $args); }
    public function get_col($sql) { $this->sql = $sql; return $this->keep; }
    public function delete($table, $where) { $this->deleted[] = array($table, $where); return 1; }
}
teen_user(21, 'old@example.org', array('teen_volunteer'));
teen_user(22, 'current@example.org', array('teen_volunteer'));
$real_db = $GLOBALS['wpdb'] ?? null;
$GLOBALS['wpdb'] = new Teen_Fake_Db();
$GLOBALS['wpdb']->keep = array('22');
$before = array_keys(Teen_State::$users);
$removed = Azure_Teen_Volunteer::purge('2027-07-02');
$t->check(strpos($GLOBALS['wpdb']->sql, "'2027-07-01 00:00:00'") !== false, 'after June 30 only shifts from the new school year keep an account');
$t->check(in_array(21, Teen_State::$deleted, true), 'a teen with no current shift is deleted');
$t->check(!in_array(22, Teen_State::$deleted, true), 'a teen with an upcoming shift is kept');
$t->check(!in_array(10, Teen_State::$deleted, true), 'parents are never touched');
$t->check(in_array(array('wp_azure_volunteer_signups', array('user_id' => 21)), $GLOBALS['wpdb']->deleted, true), 'their past sign-ups go with them');
$t->equals(count(Teen_State::$deleted), $removed, 'the count of deleted accounts is returned');
$GLOBALS['wpdb'] = $real_db;

// Wiring
$root = dirname(__DIR__) . '/Azure Plugin/';
$vs = file_get_contents($root . 'includes/class-volunteer-signup.php');
$t->check(strpos($vs, "add_action('wp_ajax_nopriv_azure_volunteer_teen_signup', array(\$this, 'ajax_teen_signup'))") !== false, 'signed-out visitors can reach the teen sign-up');
preg_match('/public function ajax_teen_signup\(\).*?\n    }\n/s', $vs, $fn);
$fn = $fn[0] ?? '';
$t->check(strpos($fn, "'party_size'  => 1") !== false && strpos($fn, 'party_sizes') === false, 'teens always sign up one person, even on group sheets');
$t->check(strpos($fn, 'sheet_allows_teen($sheet)') !== false, 'only on sheets that allow teens');
$t->check(strpos($fn, "empty(\$_POST['attest_age']) || empty(\$_POST['attest_training'])") !== false, 'both attestations are required');
$t->check(strpos($fn, 'TEEN_RATE_LIMIT') !== false && strpos($fn, 'check_signup') !== false, 'rate limited and spam checked');
$t->check(strpos($fn, 'max_party_size(') !== false, 'a full activity refuses the sign-up');
$t->check(strpos($vs, "azure-vs-signup-btn<?php echo \$allow_teen ? ' azure-vs-guest-btn' : ''; ?>") !== false, 'the signed-out Sign Up opens the chooser only where teens are allowed');

$js = file_get_contents($root . 'js/volunteer-frontend.js');
$t->check(strpos($js, "'a.azure-vs-guest-btn'") !== false && strpos($js, 'azure-vs-teen-start') !== false, 'the chooser offers sign in or Teen volunteer');
$t->check(strpos($js, 'attest_age') !== false && strpos($js, 'attest_training') !== false && strpos($js, 'name="email"') !== false, 'the teen form asks for both ticks and an email');
preg_match("/action: 'azure_volunteer_teen_signup'.*?\}\)/s", $js, $post);
$t->check(!empty($post[0]) && strpos($post[0], 'party') === false, 'the teen request sends no group size');

$queue = file_get_contents($root . 'includes/class-newsletter-queue.php');
$t->check(strpos($queue, "'role__not_in' => array(Azure_Teen_Volunteer::ROLE)") !== false, 'the all-users newsletter skips teens');
$lists = file_get_contents($root . 'includes/class-newsletter-lists.php');
$t->equals(2, substr_count($lists, "'role__not_in' => array(Azure_Teen_Volunteer::ROLE)"), 'the All Users list and its sync skip teens');
$t->check(strpos(file_get_contents($root . 'admin/newsletter-lists.php'), 'unset($roles[Azure_Teen_Volunteer::ROLE]);') !== false, 'Teen Volunteer is not offered as a newsletter role');
$t->check(strpos(file_get_contents($root . 'includes/class-newsletter-ajax.php'), "count_users()['total_users']") === false, 'newsletter counts leave teens out');

$main = file_get_contents($root . 'azure-plugin.php');
$t->check(strpos($main, "require_once AZURE_PLUGIN_PATH . 'includes/class-teen-volunteer.php';") !== false, 'the teen rules load on every request');

exit($t->finish() === 0 ? 0 : 1);
