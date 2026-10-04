<?php
/**
 * Temporary-password accounts: getting out of the forced password change.
 *
 * Imported parents carry _pta_force_password_change until they choose their
 * own password. Resetting through "Lost password" used to leave the flag in
 * place, so they were sent straight back to Account details and asked for the
 * temporary password they no longer had.
 *
 * Run: php tests/test-parent-force-password.php
 */

require __DIR__ . '/wp-shim.php';

class Force_State {
    public static $meta = array();
    public static $keys = 0;
    public static $key_error = false;
}

if (!class_exists('WP_User')) {
    class WP_User {
        public $ID = 0;
        public $user_login = '';
        public function exists() { return $this->ID > 0; }
    }
}

function get_user_meta($id, $key, $single = false) { return Force_State::$meta[$id][$key] ?? ''; }
function update_user_meta($id, $key, $value) { Force_State::$meta[$id][$key] = $value; return true; }
function delete_user_meta($id, $key) { unset(Force_State::$meta[$id][$key]); return true; }
function get_password_reset_key($user) {
    if (Force_State::$key_error) {
        return new WP_Error('no_key', 'nope');
    }
    Force_State::$keys++;
    return 'key' . Force_State::$keys;
}
function wc_get_page_permalink($page) { return 'https://example.test/my-account/'; }
function wc_get_endpoint_url($endpoint, $value = '', $permalink = '') { return $permalink . $endpoint . '/'; }
if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-parent-role.php';

$t = new TestRunner('Parent forced password change');

$user = new WP_User();
$user->ID = 995;
$user->user_login = 'parent995';

Force_State::$meta[995][Azure_Parent_Role::META_FORCE_PW_RESET] = 1;
Azure_Parent_Role::clear_force_pw_on_reset($user);
$t->check(!isset(Force_State::$meta[995][Azure_Parent_Role::META_FORCE_PW_RESET]), 'a password reset clears the temporary-password flag');

Force_State::$meta[996][Azure_Parent_Role::META_FORCE_PW_RESET] = 1;
Azure_Parent_Role::clear_force_pw_on_reset($user);
$t->equals(1, Force_State::$meta[996][Azure_Parent_Role::META_FORCE_PW_RESET], 'only the user who reset is cleared');
Azure_Parent_Role::clear_force_pw_on_reset(null);
$t->equals(1, Force_State::$meta[996][Azure_Parent_Role::META_FORCE_PW_RESET], 'a missing user is ignored');

$url = Azure_Parent_Role::set_password_url($user);
$t->equals('https://example.test/my-account/lost-password/?key=key1&id=995', $url, 'flagged users go to the choose-a-password form with a fresh key');
$t->check(strpos(Azure_Parent_Role::set_password_url($user), 'key=key2') !== false, 'each visit issues a new key');

Force_State::$key_error = true;
$t->equals('', Azure_Parent_Role::set_password_url($user), 'no link when WordPress cannot issue a key');

exit($t->finish() === 0 ? 0 : 1);
