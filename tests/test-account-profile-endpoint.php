<?php
/**
 * Family Info (/my-account/profile/) must survive a stale rewrite flush flag.
 *
 * Run: php tests/test-account-profile-endpoint.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

if (!function_exists('add_rewrite_endpoint')) {
    function add_rewrite_endpoint($name, $places) {
        unset($name, $places);
    }
}
if (!defined('EP_ROOT')) {
    define('EP_ROOT', 1);
}
if (!defined('EP_PAGES')) {
    define('EP_PAGES', 2);
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-user-children.php';

$t = new TestRunner('Account profile endpoint');

WP_Shim::reset();

$t->check(
    !Azure_User_Children::rewrite_rules_have_profile(array('(.?.+?)/?$' => 'index.php?pagename=$matches[1]')),
    'generic page rules do not count as the profile endpoint'
);
$t->check(
    Azure_User_Children::rewrite_rules_have_profile(array('(.?.+?)/profile(/(.*))?/?$' => 'index.php?pagename=$matches[1]&profile=$matches[3]')),
    'WC profile endpoint pattern is detected'
);

$mapped = Azure_User_Children::map_stale_account_profile_request(array(
    'pagename' => 'my-account/profile',
    'name'     => 'profile',
    'error'    => '404',
));
$t->equals('my-account', $mapped['pagename'], 'stale profile path remaps to My Account');
$t->check(isset($mapped['profile']) && $mapped['profile'] === '', 'profile query var is set');
$t->check(!isset($mapped['error']), '404 error is cleared');

$kids = Azure_User_Children::map_stale_account_profile_request(array(
    'pagename' => 'my-account/my-children',
));
$t->equals('my-account', $kids['pagename'], 'my-children remaps to My Account');
$t->check(isset($kids['my-children']), 'my-children query var is set');

$shop = Azure_User_Children::map_stale_account_profile_request(array('pagename' => 'shop'));
$t->equals('shop', $shop['pagename'], 'unrelated pages are left alone');

$vars = Azure_User_Children::register_account_query_vars(array());
$t->equals('profile', $vars['profile'], 'WC query var for profile is registered');
$t->equals('my-children', $vars['my-children'], 'WC query var for my-children is registered');

exit($t->finish() === 0 ? 0 : 1);
