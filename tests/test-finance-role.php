<?php
/**
 * Finance role clones Shop Manager and adds manage_pta_finance.
 *
 * Run: php tests/test-finance-role.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

if (!class_exists('WP_Role')) {
    class WP_Role {
        public $name;
        public $capabilities;

        public function __construct($name, $caps = array()) {
            $this->name = $name;
            $this->capabilities = $caps;
        }

        public function has_cap($cap) {
            return !empty($this->capabilities[$cap]);
        }

        public function add_cap($cap, $grant = true) {
            $this->capabilities[$cap] = (bool) $grant;
        }

        public function remove_cap($cap) {
            unset($this->capabilities[$cap]);
        }
    }
}

$GLOBALS['wp_test_roles'] = array();
$GLOBALS['wp_test_current_caps'] = array();

if (!function_exists('get_role')) {
    function get_role($slug) {
        return isset($GLOBALS['wp_test_roles'][$slug]) ? $GLOBALS['wp_test_roles'][$slug] : null;
    }
}

if (!function_exists('add_role')) {
    function add_role($slug, $display_name, $caps = array()) {
        $GLOBALS['wp_test_roles'][$slug] = new WP_Role($slug, $caps);
        return $GLOBALS['wp_test_roles'][$slug];
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($cap) {
        return !empty($GLOBALS['wp_test_current_caps'][$cap]);
    }
}

if (!function_exists('user_can')) {
    function user_can($user_id, $cap) {
        return current_user_can($cap);
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-finance-role.php';

$t = new TestRunner('Finance role');

$GLOBALS['wp_test_roles'] = array(
    'shop_manager' => new WP_Role('shop_manager', array(
        'read' => true,
        'manage_woocommerce' => true,
        'view_woocommerce_reports' => true,
        'edit_shop_orders' => true,
        'edit_products' => true,
    )),
    'administrator' => new WP_Role('administrator', array(
        'manage_options' => true,
        'manage_woocommerce' => true,
    )),
);

$caps = Azure_Finance_Role::capabilities();
$t->check(!empty($caps['manage_woocommerce']), 'clones manage_woocommerce');
$t->check(!empty($caps['view_woocommerce_reports']), 'clones view_woocommerce_reports');
$t->check(!empty($caps['edit_shop_orders']), 'clones edit_shop_orders');
$t->check(!empty($caps[Azure_Finance_Role::CAP]), 'adds manage_pta_finance');
$t->check(empty($caps['manage_options']), 'never copies manage_options');

Azure_Finance_Role::ensure_role();
$finance = get_role('finance');
$t->check($finance instanceof WP_Role, 'creates finance role');
$t->check($finance && $finance->has_cap('manage_woocommerce'), 'finance has manage_woocommerce');
$t->check($finance && $finance->has_cap(Azure_Finance_Role::CAP), 'finance has manage_pta_finance');
$t->check($finance && !$finance->has_cap('manage_options'), 'finance does not have manage_options');
$t->check(get_role('administrator')->has_cap(Azure_Finance_Role::CAP), 'administrator gains manage_pta_finance for menu visibility');

$finance->add_cap('manage_options');
Azure_Finance_Role::ensure_role();
$t->check(!get_role('finance')->has_cap('manage_options'), 'converge strips manage_options if it appeared');

$GLOBALS['wp_test_current_caps'] = array('manage_woocommerce' => true);
$t->check(!Azure_Finance_Role::user_can(), 'shop_manager-only caps are not finance admin');

$GLOBALS['wp_test_current_caps'] = array(Azure_Finance_Role::CAP => true);
$t->check(Azure_Finance_Role::user_can(), 'manage_pta_finance is finance admin');

$GLOBALS['wp_test_current_caps'] = array('manage_options' => true);
$t->check(Azure_Finance_Role::user_can(), 'manage_options is finance admin');

$GLOBALS['wp_test_roles'] = array();
$fallback = Azure_Finance_Role::capabilities();
$t->check(!empty($fallback['manage_woocommerce']), 'fallback caps when shop_manager is missing');
$t->check(!empty($fallback[Azure_Finance_Role::CAP]), 'fallback still includes manage_pta_finance');

exit($t->finish());
