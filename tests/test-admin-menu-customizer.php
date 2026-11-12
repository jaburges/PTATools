<?php
/**
 * Per-role admin menu visibility.
 *
 * Run: php tests/test-admin-menu-customizer.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-admin-menu-customizer.php';

$t = new TestRunner('Admin menu customizer');

$menu = array(
    array('Dashboard', 'read', 'index.php', '', 'menu-top', 'menu-dashboard', 'dashicons-dashboard'),
    array('', 'read', 'separator1', '', 'wp-menu-separator'),
    array('Posts <span class="update-plugins">3</span>', 'edit_posts', 'edit.php', '', 'menu-top', 'menu-posts', 'dashicons-admin-post'),
    array('PTA Tools', 'access_pta_tools', 'azure-plugin', '', 'menu-top', 'toplevel_page_azure-plugin', 'dashicons-admin-plugins'),
);
$submenu = array(
    'edit.php' => array(
        array('All Posts', 'edit_posts', 'edit.php'),
        array('Add New', 'edit_posts', 'post-new.php'),
    ),
    'azure-plugin' => array(
        array('Dashboard', 'manage_options', 'azure-plugin'),
        array('Calendar', 'access_pta_tools', 'azure-plugin-calendar'),
        array('System', 'manage_options', 'azure-plugin-system'),
    ),
);

$catalog = Azure_Admin_Menu_Customizer::catalog_from_globals($menu, $submenu);
$ids = Azure_Admin_Menu_Customizer::catalog_ids($catalog);
$labels = array_map(function ($n) { return $n['label']; }, $catalog);

$t->check(!in_array('separator1', $ids, true), 'separators are omitted from the catalog');
$t->check(in_array('index.php', $ids, true), 'Dashboard is in the catalog');
$t->check(in_array('edit.php', $ids, true), 'Posts is in the catalog');
$t->check(in_array('edit.php::post-new.php', $ids, true), 'submenu IDs use parent::child');
$t->check(in_array('azure-plugin::azure-plugin-system', $ids, true), 'System is a PTA Tools child');
$t->check(in_array('Posts 3', $labels, true) || in_array('Posts', $labels, true), 'Posts label is cleaned of HTML');
$t->check(strpos(implode(' ', $labels), '<span') === false, 'catalog labels have no HTML tags');

$t->equals(
    array(),
    Azure_Admin_Menu_Customizer::hidden_for_roles(array('azuread'), array(), false),
    'no stored hides means the role sees everything'
);

$stored = array(
    'azuread' => array('hidden' => array('edit.php', 'azure-plugin::azure-plugin-system')),
    'editor' => array('hidden' => array('edit.php')),
    'administrator' => array('hidden' => array('edit.php', 'azure-plugin', 'azure-plugin::azure-plugin-system')),
);

$t->equals(
    array('edit.php', 'azure-plugin::azure-plugin-system'),
    Azure_Admin_Menu_Customizer::hidden_for_roles(array('azuread'), $stored, false),
    'azuread hides every item stored for that role'
);

$t->equals(
    array('edit.php'),
    Azure_Admin_Menu_Customizer::hidden_for_roles(array('azuread', 'editor'), $stored, false),
    'multi-role users hide only items every role has unchecked'
);

$admin_hidden = Azure_Admin_Menu_Customizer::hidden_for_roles(array('administrator'), $stored, true);
$t->check(!in_array('azure-plugin', $admin_hidden, true), 'administrator cannot hide PTA Tools');
$t->check(!in_array('azure-plugin::azure-plugin-system', $admin_hidden, true), 'administrator cannot hide System');
$t->check(in_array('edit.php', $admin_hidden, true), 'administrator can still hide Posts');

$sanitized = Azure_Admin_Menu_Customizer::sanitize_hidden(
    array('edit.php', 'not-a-menu', 'azure-plugin', 'azure-plugin::azure-plugin-system'),
    $ids,
    'administrator'
);
$t->check(in_array('edit.php', $sanitized, true), 'known menu IDs are kept');
$t->check(!in_array('not-a-menu', $sanitized, true), 'unknown IDs are dropped');
$t->check(!in_array('azure-plugin', $sanitized, true), 'locked PTA Tools cannot be saved hidden for administrator');
$t->check(!in_array('azure-plugin::azure-plugin-system', $sanitized, true), 'locked System cannot be saved hidden for administrator');

$azuread_saved = Azure_Admin_Menu_Customizer::sanitize_hidden(
    array('azure-plugin::azure-plugin-system'),
    $ids,
    'azuread'
);
$t->equals(
    array('azure-plugin::azure-plugin-system'),
    $azuread_saved,
    'non-admin roles may hide System in their own menu'
);

// Save round trip. admin_menu never fires on admin-ajax.php, so the save
// request runs with no catalog snapshot.
class AMC_Json_Exit extends Exception {
    public $success;
    public $data;
    public function __construct($success, $data) {
        parent::__construct('json');
        $this->success = $success;
        $this->data = $data;
    }
}
function check_ajax_referer($action, $arg) { return true; }
function current_user_can($cap) { return true; }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
function wp_unslash($value) { return $value; }
function translate_user_role($name) { return $name; }
function wp_roles() {
    return (object) array('roles' => array(
        'administrator' => array('name' => 'Administrator'),
        'editor' => array('name' => 'Editor'),
        'azuread' => array('name' => 'Azure AD User'),
    ));
}
function wp_send_json_success($data = null) { throw new AMC_Json_Exit(true, $data); }
function wp_send_json_error($data = null) { throw new AMC_Json_Exit(false, $data); }

function amc_save($role, array $hidden) {
    $_POST = array('nonce' => 'n', 'role' => $role, 'hidden' => $hidden);
    try {
        Azure_Admin_Menu_Customizer::get_instance()->ajax_save();
    } catch (AMC_Json_Exit $e) {
        return $e;
    }
    return null;
}

WP_Shim::$options = array();
Azure_Admin_Menu_Customizer::$catalog = $catalog;
Azure_Admin_Menu_Customizer::remember_catalog_ids($catalog);
Azure_Admin_Menu_Customizer::$catalog = null;

$res = amc_save('editor', array('edit.php', 'edit.php::post-new.php', 'not-a-menu'));
$t->check($res !== null && $res->success, 'save succeeds without a catalog snapshot in the request');
$t->equals(array('edit.php', 'edit.php::post-new.php'), $res->data['hidden'], 'save echoes the hidden items it kept');
$stored_after = Azure_Admin_Menu_Customizer::stored();
$t->equals(array('edit.php', 'edit.php::post-new.php'), $stored_after['editor']['hidden'] ?? null, 'saved hides are what the editor loads back for the role');
$t->check(!isset($stored_after['azuread']), 'saving one role leaves other roles untouched');

amc_save('azuread', array('azure-plugin::azure-plugin-calendar'));
$stored_after = Azure_Admin_Menu_Customizer::stored();
$t->equals(array('edit.php', 'edit.php::post-new.php'), $stored_after['editor']['hidden'], 'saving a second role keeps the first');
$t->equals(array('azure-plugin::azure-plugin-calendar'), $stored_after['azuread']['hidden'], 'second role is saved under its own slug');

$admin_res = amc_save('administrator', array('edit.php', 'azure-plugin'));
$t->equals(array('edit.php'), $admin_res->data['hidden'], 'save still strips locked items for administrator');

$tab = file_get_contents(dirname(__DIR__) . '/Azure Plugin/admin/system-menu-tab.php');
$t->check(strpos($tab, 'remember_catalog_ids(') !== false, 'Admin Menu tab records the IDs it renders');

$t->equals('access_pta_tools', Azure_Admin_Menu_Customizer::CAP, 'Azure AD users share the PTA Tools capability');

$php = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-admin.php');
$t->check(strpos($php, 'Azure_Admin_Menu_Customizer::CAP') !== false, 'PTA Tools parent menu uses access_pta_tools');
$t->check(strpos($php, "'Dashboard'") !== false, 'Dashboard submenu is registered');

$plugin = file_get_contents(dirname(__DIR__) . '/Azure Plugin/azure-plugin.php');
$t->check(strpos($plugin, "\$caps['manage_options'] = true;") !== false, 'Azure AD User role copies manage_options onto editor caps');
$t->check(preg_match("/'access_pta_tools'\\s*=>\\s*true,\\s*'manage_options'\\s*=>\\s*true,/s", $plugin) === 1, 'Azure AD User fallback caps include manage_options');

$system = file_get_contents(dirname(__DIR__) . '/Azure Plugin/admin/system-page.php');
$t->check(strpos($system, 'tab=menu') !== false, 'System has an Admin Menu tab');

exit($t->finish() === 0 ? 0 : 1);
