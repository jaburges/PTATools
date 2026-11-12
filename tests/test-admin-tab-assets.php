<?php
/**
 * Selling and System tabs opened from their own menu entries
 * (?page=azure-plugin-selling-reports) load the same assets as the tab links
 * (?page=azure-plugin-selling&tab=reports).
 *
 * Run: php tests/test-admin-tab-assets.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}
if (!defined('AZURE_PLUGIN_URL')) {
    define('AZURE_PLUGIN_URL', 'https://example.test/wp-content/plugins/Azure%20Plugin/');
}
if (!defined('AZURE_PLUGIN_VERSION')) {
    define('AZURE_PLUGIN_VERSION', '0.0.0');
}

$GLOBALS['tab_assets'] = array();
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
function wp_enqueue_style($handle) { $GLOBALS['tab_assets'][] = $handle; }
function wp_enqueue_script($handle) { $GLOBALS['tab_assets'][] = $handle; }
function wp_localize_script($handle, $name, $data) { return true; }
function wp_create_nonce($action) { return 'nonce'; }
function WC() {
    return new class {
        public function plugin_url() { return 'https://example.test/wp-content/plugins/woocommerce'; }
    };
}

require_once AZURE_PLUGIN_PATH . 'includes/class-admin.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-orders-reports-module.php';

$admin = (new ReflectionClass('Azure_Admin'))->newInstanceWithoutConstructor();
$reports = (new ReflectionClass('Azure_Orders_Reports_Module'))->newInstanceWithoutConstructor();

function assets_for(array $query) {
    global $admin, $reports;
    $_GET = $query;
    $GLOBALS['tab_assets'] = array();
    $admin->enqueue_admin_scripts('pta-tools_page_' . ($query['page'] ?? ''));
    $reports->enqueue_assets('pta-tools_page_' . ($query['page'] ?? ''));
    return $GLOBALS['tab_assets'];
}

$t = new TestRunner('Admin tab assets');

$_GET = array('page' => 'azure-plugin-selling-reports');
$t->equals(array('azure-plugin-selling', 'reports'), Azure_Admin::current_tabbed_page(), 'Reports menu entry resolves to the Selling page, reports tab');
$_GET = array('page' => 'azure-plugin-selling', 'tab' => 'reports');
$t->equals(array('azure-plugin-selling', 'reports'), Azure_Admin::current_tabbed_page(), 'Reports tab link resolves the same way');
$_GET = array('page' => 'azure-plugin-system-rules');
$t->equals(array('azure-plugin-system', 'rules'), Azure_Admin::current_tabbed_page(), 'Rules menu entry resolves to the System page, rules tab');
$_GET = array('page' => 'azure-plugin-system-ai-connectors', 'section' => 'settings');
$t->equals(array('azure-plugin-system', 'ai-connectors'), Azure_Admin::current_tabbed_page(), 'AI Connectors menu entry resolves to the System page, AI Connectors tab');
$_GET = array('page' => 'azure-plugin-selling-rule-email');
$t->equals(array('azure-plugin-selling-rule-email', ''), Azure_Admin::current_tabbed_page(), 'Rule email editor is its own page, not a Selling tab');
$_GET = array('page' => 'azure-plugin-system-logs');
$t->equals(array('azure-plugin-system-logs', ''), Azure_Admin::current_tabbed_page(), 'Logs has no menu entry of its own');
$_GET = array('page' => 'azure-plugin-backup');
$t->equals(array('azure-plugin-backup', ''), Azure_Admin::current_tabbed_page(), 'Other pages pass through');

foreach (array(
    'menu entry' => array('page' => 'azure-plugin-selling-reports'),
    'tab link' => array('page' => 'azure-plugin-selling', 'tab' => 'reports'),
) as $how => $query) {
    $assets = assets_for($query);
    $t->check(in_array('azure-admin-tabs', $assets, true), "Reports via $how: tab bar styles load");
    $t->check(in_array('azure-orders-reports', $assets, true), "Reports via $how: report builder styles load");
    $t->check(in_array('azure-orders-reports-builder', $assets, true), "Reports via $how: report builder script loads");
    $t->check(in_array('wc-enhanced-select', $assets, true), "Reports via $how: product search loads");
    $t->check(in_array('azure-orders-reports-select2', $assets, true), "Reports via $how: product search styles load");
}

foreach (array('classes', 'product-fields', 'donations') as $tab) {
    $assets = assets_for(array('page' => 'azure-plugin-selling-' . $tab));
    $t->check(in_array('azure-admin-tabs', $assets, true), "Selling $tab menu entry: tab bar styles load");
    $t->check(!in_array('azure-orders-reports-builder', $assets, true), "Selling $tab menu entry: no report builder");
}

$assets = assets_for(array('page' => 'azure-plugin-system-rules'));
$t->check(in_array('azure-order-rules-admin', $assets, true), 'System Rules menu entry: rules editor loads');
$assets = assets_for(array('page' => 'azure-plugin-system-menu'));
$t->check(in_array('azure-admin-menu-editor', $assets, true), 'System Admin Menu entry: menu editor loads');
$assets = assets_for(array('page' => 'azure-plugin-system-config'));
$t->check(in_array('azure-calendar-frontend', $assets, true), 'System Config entry: its styles load');

exit($t->finish() === 0 ? 0 : 1);
