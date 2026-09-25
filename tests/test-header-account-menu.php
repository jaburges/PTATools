<?php
/**
 * Header account dropdown includes Signups under Orders.
 *
 * Run: php tests/test-header-account-menu.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return (string) $url;
    }
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-user-management-module.php';

$t = new TestRunner('Header account menu');

$links = Azure_User_Management_Module::header_account_links();
$keys = array_keys($links);
$t->equals('orders', $keys[1], 'Orders is the second header link');
$t->equals('volunteered', $keys[2], 'Signups sits under Orders');
$t->equals('Signups', $links['volunteered'][0], 'the header label is Signups');
$t->equals('https://example.test/my-account/volunteered/', $links['volunteered'][1], 'Signups points at the account page');

$module = new ReflectionClass('Azure_User_Management_Module');
$insert = $module->getMethod('insert_signups_nav_item');
$instance = $module->newInstanceWithoutConstructor();
$args = (object) array('theme_location' => 'pta-account-menu');
$html = '<li class="menu-item"><a href="https://example.test/my-account/orders/">Orders</a></li>'
    . '<li class="menu-item"><a href="https://example.test/my-account/profile/">Family Info</a></li>';
$with = $insert->invoke($instance, $html, $args);
$orders_at = strpos($with, '/my-account/orders/');
$signups_at = strpos($with, '/my-account/volunteered/');
$family_at = strpos($with, '/my-account/profile/');
$t->equals(true, $orders_at !== false && $signups_at !== false && $orders_at < $signups_at && $signups_at < $family_at, 'an assigned menu gains Signups after Orders');

$again = $insert->invoke($instance, $with, $args);
$t->equals(1, substr_count($again, '/my-account/volunteered/'), 'Signups is not added twice');

$other = $insert->invoke($instance, $html, (object) array('theme_location' => 'primary'));
$t->equals($html, $other, 'other menus are left alone');

$t->finish();
