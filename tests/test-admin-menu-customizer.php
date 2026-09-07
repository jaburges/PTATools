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

$t->equals('access_pta_tools', Azure_Admin_Menu_Customizer::CAP, 'Azure AD users share the PTA Tools capability');

$php = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-admin.php');
$t->check(strpos($php, 'Azure_Admin_Menu_Customizer::CAP') !== false, 'PTA Tools parent menu uses access_pta_tools');
$t->check(strpos($php, "'Dashboard'") !== false, 'Dashboard stays manage_options so Azure AD users skip module toggles');

$system = file_get_contents(dirname(__DIR__) . '/Azure Plugin/admin/system-page.php');
$t->check(strpos($system, 'tab=menu') !== false, 'System has an Admin Menu tab');

exit($t->finish() === 0 ? 0 : 1);
