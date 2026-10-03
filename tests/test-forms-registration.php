<?php
/**
 * Forms: parent self-registration (account, family, children, emails,
 * validation and the unactivated-account purge).
 *
 * Run: php tests/test-forms-registration.php
 */

require __DIR__ . '/wp-shim.php';

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

function sanitize_text_field($s) {
    return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $s)));
}
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function is_email($e) { return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : false; }
function wp_kses_post($s) { return (string) $s; }

class Reg_State {
    public static $users = array();
    public static $meta = array();
    public static $mail = array();
    public static $deleted = array();
    public static $children = array();
    public static $family_meta = array();
    public static $logged_in = false;

    public static function reset() {
        self::$users = self::$meta = self::$mail = self::$deleted = self::$children = self::$family_meta = array();
        self::$logged_in = false;
        WP_Shim::reset();
    }

    public static function add_user($id, $email, $roles = array('parent'), $meta = array()) {
        self::$users[$id] = (object) array('ID' => $id, 'user_email' => $email, 'user_login' => $email, 'roles' => $roles);
        self::$meta[$id] = $meta;
    }
}

function get_user_by($field, $value) {
    foreach (Reg_State::$users as $u) {
        if (($field === 'email' && strtolower($u->user_email) === strtolower($value)) || ($field === 'id' && (int) $u->ID === (int) $value)) {
            return $u;
        }
    }
    return false;
}
function get_userdata($id) { return Reg_State::$users[$id] ?? false; }
function get_user_meta($id, $key, $single = false) { return Reg_State::$meta[$id][$key] ?? ''; }
function update_user_meta($id, $key, $value) { Reg_State::$meta[$id][$key] = $value; return true; }
function delete_user_meta($id, $key) { unset(Reg_State::$meta[$id][$key]); return true; }
function wp_mail($to, $subject, $body, $headers = array()) {
    Reg_State::$mail[] = array('to' => $to, 'subject' => $subject, 'body' => $body);
    return true;
}
function wp_delete_user($id) {
    Reg_State::$deleted[] = $id;
    unset(Reg_State::$users[$id]);
    return true;
}
function wp_lostpassword_url() { return 'https://example.test/my-account/lost-password/'; }
function wp_login_url() { return 'https://example.test/wp-login.php'; }
function is_user_logged_in() { return Reg_State::$logged_in; }
function get_post_status($id) { return $id === 77 ? 'publish' : 'draft'; }
function get_permalink($id) { return 'https://example.test/register/'; }
function esc_url($u) { return (string) $u; }
function esc_html__($s) { return $s; }

class Azure_Parent_Role {
    const ROLE_SLUG = 'parent';
    const META_LOGIN_DISABLED = '_pta_login_disabled';
    const META_FORCE_PW_RESET = '_pta_force_password_change';
}
class Azure_Parent_Activation {
    const META_IMPORT_SOURCE = '_pta_imported_source';
    const SOURCE_FORM_REGISTRATION = 'form_registration';
    public static $issued = array();
    public static function issue_url($uid, $ttl) {
        self::$issued[] = array($uid, $ttl);
        return 'https://example.test/?pta-activate=' . $uid . ':tok';
    }
}
class Azure_Parent_Migration {
    public static $created = array();
    public static function create_parent_user($email, $display, $source, $extra) {
        $id = 100 + count(Reg_State::$users);
        $meta = array(
            Azure_Parent_Role::META_LOGIN_DISABLED => 1,
            Azure_Parent_Role::META_FORCE_PW_RESET => 1,
            Azure_Parent_Activation::META_IMPORT_SOURCE => $source,
        );
        foreach ($extra as $k => $v) {
            if ($v !== '' && $v !== null) {
                $meta[$k] = $v;
            }
        }
        Reg_State::add_user($id, $email, array('parent'), $meta);
        self::$created[] = array($email, $display, $source);
        return $id;
    }
}
class Azure_Email_Messages {
    public static function site_name() { return 'Test PTA'; }
    public static function render($key, $vars) {
        return array($key, json_encode($vars));
    }
}
class Azure_User_Children {
    public static function ensure_family_for_user($uid) { return 500 + $uid; }
    public static function save_child($uid, $data) {
        Reg_State::$children[] = array('user' => $uid) + $data;
        return count(Reg_State::$children);
    }
    public static function update_family_meta($family_id, $meta) {
        Reg_State::$family_meta[$family_id] = $meta;
    }
    public static function get_family_for_user($uid) { return null; }
    public static function clear_children_cache($uid = 0) {}
}
class Azure_Settings {
    public static $values = array();
    public static function get_setting($key, $default = '') { return self::$values[$key] ?? $default; }
}

require __DIR__ . '/../Azure Plugin/includes/class-forms-schema.php';
require __DIR__ . '/../Azure Plugin/includes/class-forms-registration.php';

$t = new TestRunner('Forms registration');

Azure_Forms_Schema::set_option_source('grade', function () { return array('K', '1', '2'); });
Azure_Forms_Schema::set_option_source('teacher', function () { return array('Ms. A', 'Mr. B'); });

$schema = Azure_Forms_Schema::sanitize_schema(array(
    array('type' => 'text', 'label' => 'First name', 'required' => true, 'profile' => 'first_name'),
    array('type' => 'text', 'label' => 'Last name', 'required' => true, 'profile' => 'last_name'),
    array('type' => 'email', 'label' => 'Email', 'required' => true, 'profile' => 'email'),
    array('type' => 'phone', 'label' => 'Cell', 'required' => true, 'profile' => 'phone'),
    array('type' => 'children', 'label' => 'Children', 'required' => true),
    array('type' => 'text', 'label' => 'P2 first', 'profile' => 'parent_2_first_name'),
    array('type' => 'text', 'label' => 'P2 last', 'profile' => 'parent_2_last_name'),
    array('type' => 'email', 'label' => 'P2 email', 'profile' => 'parent_2_email'),
    array('type' => 'text', 'label' => 'Emergency name', 'required' => true, 'profile' => 'emergency_name'),
    array('type' => 'phone', 'label' => 'Emergency cell', 'required' => true, 'profile' => 'emergency_phone'),
    array('type' => 'email', 'label' => 'Emergency email', 'profile' => 'emergency_email'),
));
$form = array('id' => 9, 'schema' => $schema, 'settings' => array('registration' => true));

$t->equals(array(), Azure_Forms_Registration::missing_requirements($schema), 'the full form meets the requirements');
$t->equals(array('email', 'children'), Azure_Forms_Registration::missing_requirements(array_slice($schema, 0, 2)), 'missing email and children are reported');

$input = array(
    'first_name' => 'Mary Ann', 'last_name' => 'Smith', 'email' => 'Mary@Example.com', 'cell' => '206-555-0100',
    'children' => array(
        array('name' => 'Sam Smith', 'grade' => '1', 'teacher' => 'Ms. A'),
        array('name' => 'Ava Smith', 'grade' => 'K', 'teacher' => 'Mr. B'),
    ),
    'p2_first' => 'Joe', 'p2_last' => 'Smith', 'p2_email' => 'joe@example.com',
    'emergency_name' => 'Gran Smith', 'emergency_cell' => '206-555-0199', 'emergency_email' => '',
);
$checked = Azure_Forms_Schema::validate_submission($schema, $input);
$t->equals(array(), $checked['errors'], 'a complete registration validates');

$r = Azure_Forms_Registration::extract($schema, $checked['data']);
$t->equals('Mary@Example.com', $r['email'], 'extract maps the email field');
$t->equals(2, count($r['children']), 'extract maps the children');

// ─── Validation filter ───────────────────────────────────────────────

Reg_State::reset();
$reg = Azure_Forms_Registration::get_instance();
$t->equals(array(), $reg->validate(array(), $form, $checked['data'], 0), 'a guest registration passes the extra checks');
$t->check(isset($reg->validate(array(), $form, $checked['data'], 5)['email']), 'a signed-in member cannot register');
$plain = array('id' => 1, 'schema' => $schema, 'settings' => array());
$t->equals(array(), $reg->validate(array(), $plain, $checked['data'], 5), 'non-registration forms are untouched');
Azure_Settings::$values['org_domain'] = 'example.com';
$sso = $reg->validate(array(), $form, $checked['data'], 0);
$t->check(strpos($sso['email'] ?? '', 'Microsoft') !== false, 'staff addresses are sent to Microsoft sign-in');
Azure_Settings::$values = array();

// ─── New account ─────────────────────────────────────────────────────

Reg_State::reset();
$t->equals('created', Azure_Forms_Registration::register($r, 42), 'a new address creates an account');
$uid = 100;
$meta = Reg_State::$meta[$uid];
$t->equals('mary@example.com', Reg_State::$users[$uid]->user_email, 'the email is lower-cased');
$t->equals(array('mary@example.com', 'Mary Ann Smith', 'form_registration'), Azure_Parent_Migration::$created[0], 'the account is created as a form registration');
$t->equals('Mary Ann', $meta['first_name'] ?? null, 'first name keeps its space');
$t->equals('Smith', $meta['last_name'] ?? null, 'last name is stored');
$t->equals('206-555-0100', $meta['pta_pf_parent_1_cell'] ?? null, 'parent 1 cell is stored');
$t->equals('206-555-0100', $meta['billing_phone'] ?? null, 'billing phone is filled for checkout');
$t->equals('Joe Smith', $meta['pta_pf_parent_2_name'] ?? null, 'parent 2 name is stored');
$t->equals('joe@example.com', $meta['pta_pf_parent_2_email'] ?? null, 'parent 2 email is stored');
$t->check(!isset($meta['pta_pf_parent_2_cell']), 'an unanswered parent 2 cell is not stored');
$t->equals(42, $meta[Azure_Forms_Registration::META_ENTRY] ?? null, 'the account remembers its form entry');
$t->equals(1, $meta[Azure_Parent_Role::META_LOGIN_DISABLED] ?? null, 'the account stays locked');
$t->check(!isset($meta[Azure_Parent_Role::META_FORCE_PW_RESET]), 'no temporary-password step');
$t->check(Azure_Forms_Registration::is_pending($uid), 'a locked registration is pending');

$t->equals(2, count(Reg_State::$children), 'both children are saved');
$t->equals(array('user' => $uid, 'child_name' => 'Sam Smith', 'family_id' => 600, 'meta' => array('pta_pf_childsgrade' => '1', 'pta_pf_child_teacher' => 'Ms. A')), Reg_State::$children[0], 'children use the store grade and teacher keys');
$t->equals(array('pta_pf_emergency_contact_name' => 'Gran Smith', 'pta_pf_emergency_contact_cell' => '206-555-0199'), Reg_State::$family_meta[600] ?? null, 'emergency contact goes on the family, skipping the empty email');

$t->equals(1, count(Reg_State::$mail), 'one email is sent');
$t->equals('parent_registration', Reg_State::$mail[0]['subject'], 'it is the activation email');
$t->check(strpos(Reg_State::$mail[0]['body'], 'pta-activate=100:tok') !== false, 'it carries the activation link');
$t->equals(array(100, Azure_Forms_Registration::LINK_TTL), end(Azure_Parent_Activation::$issued), 'the link lasts 7 days');

// ─── Existing addresses ──────────────────────────────────────────────

WP_Shim::$transients = array();
Reg_State::$mail = array();
$t->equals('resent', Azure_Forms_Registration::register($r, 43), 'registering again while pending resends the link');
$t->equals('parent_registration', Reg_State::$mail[0]['subject'] ?? null, 'the resend is the activation email');
$t->equals(1, count(Azure_Parent_Migration::$created), 'no second account');
$t->equals('throttled', Azure_Forms_Registration::register($r, 44), 'a second attempt within the hour sends nothing');
$t->equals(1, count(Reg_State::$mail), 'still only one email');

Reg_State::reset();
Reg_State::add_user(7, 'mary@example.com', array('customer'), array('first_name' => 'Mary'));
$t->equals('exists', Azure_Forms_Registration::register($r, 45), 'an existing active account is not touched');
$t->equals('parent_registration_exists', Reg_State::$mail[0]['subject'] ?? null, 'the owner is told they already have an account');
$t->check(strpos(Reg_State::$mail[0]['body'], 'lost-password') !== false, 'with a reset link');
$t->equals(array('first_name' => 'Mary'), Reg_State::$meta[7], 'the existing account is unchanged');
$t->equals(0, count(Reg_State::$children), 'no children are added to an existing account');

// ─── Submission hook ─────────────────────────────────────────────────

Reg_State::reset();
Azure_Parent_Migration::$created = array();
$reg->handle_submission($plain, 1, $checked['data'], 0);
$t->equals(0, count(Azure_Parent_Migration::$created), 'non-registration forms create nothing');
$reg->handle_submission($form, 1, $checked['data'], 3);
$t->equals(0, count(Azure_Parent_Migration::$created), 'signed-in submissions create nothing');
$broken = array('id' => 9, 'schema' => array_slice($schema, 0, 4), 'settings' => array('registration' => true));
$reg->handle_submission($broken, 1, $checked['data'], 0);
$t->equals(0, count(Azure_Parent_Migration::$created), 'a form missing required mappings creates nothing');
$t->check(WP_Shim::logged('missing children'), 'and says why in the log');
$reg->handle_submission($form, 2, $checked['data'], 0);
$t->equals(1, count(Azure_Parent_Migration::$created), 'a guest registration creates the account');

// ─── Purge ───────────────────────────────────────────────────────────

class Purge_WPDB {
    public $users = 'wp_users';
    public $usermeta = 'wp_usermeta';
    public $ids = array();
    public $args = array();
    public function prepare($sql, ...$args) { $this->args = $args; return $sql; }
    public function get_col($sql) { return $this->ids; }
}
Reg_State::reset();
$GLOBALS['wpdb'] = new Purge_WPDB();
Reg_State::add_user(1, 'a@example.com', array('parent'), array('_pta_imported_source' => 'form_registration', '_pta_login_disabled' => '1'));
Reg_State::add_user(2, 'b@example.com', array('parent'), array('_pta_imported_source' => 'form_registration'));
Reg_State::add_user(3, 'c@example.com', array('administrator'), array('_pta_imported_source' => 'form_registration', '_pta_login_disabled' => '1'));
Reg_State::add_user(4, 'd@example.com', array('parent'), array('_pta_imported_source' => 'csv', '_pta_login_disabled' => '1'));
$GLOBALS['wpdb']->ids = array('1', '2', '3', '4');
$now = strtotime('2026-10-10 12:00:00 UTC');
$t->equals(1, Azure_Forms_Registration::purge_unactivated($now), 'only the unactivated registration is deleted');
$t->equals(array(1), Reg_State::$deleted, 'activated, non-parent and imported accounts are kept even if the query returns them');
$t->equals('2026-10-02 12:00:00', $GLOBALS['wpdb']->args[3] ?? null, 'the cutoff is 8 days back');

// ─── Register link ───────────────────────────────────────────────────

WP_Shim::$options[Azure_Forms_Registration::PAGE_OPTION] = 77;
ob_start();
$reg->render_register_link();
$t->check(strpos(ob_get_clean(), 'https://example.test/register/') !== false, 'the sign-in form links to the registration page');
Reg_State::$logged_in = true;
ob_start();
$reg->render_register_link();
$t->equals('', ob_get_clean(), 'no link for signed-in members');
Reg_State::$logged_in = false;
WP_Shim::$options[Azure_Forms_Registration::PAGE_OPTION] = 78;
ob_start();
$reg->render_register_link();
$t->equals('', ob_get_clean(), 'no link to an unpublished page');

exit($t->finish() > 0 ? 1 : 0);
