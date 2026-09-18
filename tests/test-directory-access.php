<?php
/**
 * /directory is for logged-in PTSA members; listed rows stay opt-in only.
 *
 * Run: php tests/test-directory-access.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-membership-module.php';

class PtaFakeDirectoryUser {
    public $ID;
    public $roles;
    public $caps;

    public function __construct($id, $roles = array(), $caps = array()) {
        $this->ID = (int) $id;
        $this->roles = $roles;
        $this->caps = $caps;
    }

    public function exists() {
        return $this->ID > 0;
    }

    public function has_cap($cap) {
        return !empty($this->caps[$cap]);
    }
}

if (!function_exists('user_can')) {
    function user_can($user, $cap) {
        if (is_object($user) && method_exists($user, 'has_cap')) {
            return $user->has_cap($cap);
        }
        return false;
    }
}

$t = new TestRunner('Parent directory access');

$range = Azure_Membership_Module::school_year_range();
$ver = (int) get_option('azure_membership_map_ver', 1);
$cache_key = 'azure_membership_map_' . $ver . '_g2_' . md5($range['from'] . '|' . implode(',', Azure_Membership_Module::get_family_product_ids()) . '|' . implode(',', Azure_Membership_Module::get_individual_product_ids()));
set_transient($cache_key, array(
    42 => array('type' => 'family', 'order_id' => 9, 'paid_at' => '2026-09-01 00:00:00'),
), HOUR_IN_SECONDS);

$guest = new PtaFakeDirectoryUser(0);
$t->equals(false, Azure_Membership_Module::user_can_view_directory($guest), 'unsigned users cannot view the directory');

$parent = new PtaFakeDirectoryUser(7, array('parent'));
$t->equals(false, Azure_Membership_Module::user_can_view_directory($parent), 'a logged-in parent without membership cannot view');

$staff = new PtaFakeDirectoryUser(8, array('school_staff'));
$t->equals(false, Azure_Membership_Module::user_can_view_directory($staff), 'school staff without membership cannot view');

$member = new PtaFakeDirectoryUser(42, array('parent'));
$t->equals(true, Azure_Membership_Module::user_can_view_directory($member), 'a paid member this year can view');

$admin = new PtaFakeDirectoryUser(1, array('administrator'), array('manage_options' => true));
$t->equals(true, Azure_Membership_Module::user_can_view_directory($admin), 'site admins can preview without a membership order');

$t->equals(true, Azure_Membership_Module::user_is_member(42), 'member map marks user 42 as a member');
$t->equals(false, Azure_Membership_Module::user_is_member(7), 'non-members stay off the member map');

exit($t->finish() === 0 ? 0 : 1);
