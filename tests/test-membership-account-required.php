<?php
/**
 * Family/Individual membership checkout requires a logged-in account.
 *
 * Run: php tests/test-membership-account-required.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-membership-module.php';

$t = new TestRunner('PTSA membership account required');

$t->equals(true, Azure_Membership_Module::membership_types_require_account(array('family')), 'family requires an account');
$t->equals(true, Azure_Membership_Module::membership_types_require_account(array('individual')), 'individual requires an account');
$t->equals(true, Azure_Membership_Module::membership_types_require_account(array('staff', 'family')), 'family in a mixed cart still requires an account');
$t->equals(false, Azure_Membership_Module::membership_types_require_account(array('staff')), 'staff-only can stay a guest');
$t->equals(false, Azure_Membership_Module::membership_types_require_account(array()), 'a cart with no membership does not require an account');

$t->equals(true, Azure_Membership_Module::product_requires_membership_account(0, 0, 'PTSA Family Membership'), 'family product title requires an account');
$t->equals(true, Azure_Membership_Module::product_requires_membership_account(0, 0, 'PTSA Individual Membership'), 'individual product title requires an account');
$t->equals(false, Azure_Membership_Module::product_requires_membership_account(0, 0, 'PTSA Staff Membership'), 'staff product title does not require an account');
$t->equals(false, Azure_Membership_Module::product_requires_membership_account(0, 0, 'Enrichment Chess'), 'non-membership products do not require an account');

$t->equals(true, Azure_Membership_Module::guest_membership_checkout_allowed(true, array('family')), 'logged-in members can check out');
$t->equals(false, Azure_Membership_Module::guest_membership_checkout_allowed(false, array('family')), 'guests cannot buy family membership');
$t->equals(false, Azure_Membership_Module::guest_membership_checkout_allowed(false, array('individual')), 'guests cannot buy individual membership');
$t->equals(true, Azure_Membership_Module::guest_membership_checkout_allowed(false, array('staff')), 'guests can still buy staff membership');
$t->equals(true, Azure_Membership_Module::guest_membership_checkout_allowed(false, array()), 'guests can check out a cart with no membership');

$t->equals(true, Azure_Membership_Module::guest_may_use_express_pay(true, true, 'cart'), 'logged-in members can use Apple Pay anywhere');
$t->equals(true, Azure_Membership_Module::guest_may_use_express_pay(false, true, 'checkout'), 'guests can use Apple Pay on checkout after the account fields');
$t->equals(false, Azure_Membership_Module::guest_may_use_express_pay(false, true, 'cart'), 'guests cannot Apple Pay a membership from the cart');
$t->equals(false, Azure_Membership_Module::guest_may_use_express_pay(false, true, 'product'), 'guests cannot Apple Pay a membership from the product page');
$t->equals(true, Azure_Membership_Module::guest_may_use_express_pay(false, false, 'cart'), 'non-membership carts keep express pay');
$t->equals(true, Azure_Membership_Module::guest_may_use_express_pay(false, false, 'product'), 'staff guests may see product-page wallets at the membership layer — required fields hide them separately');

$module = new ReflectionClass('Azure_Membership_Module');
$t->equals(true, $module->hasMethod('enable_registration_for_membership_cart'), 'checkout signup is forced on for membership carts');
$t->equals(true, $module->hasMethod('require_registration_for_membership_cart'), 'guest checkout is blocked for membership carts');
$t->equals(true, $module->hasMethod('validate_store_api_membership_account'), 'blocks checkout is validated server-side');

exit($t->finish() === 0 ? 0 : 1);
