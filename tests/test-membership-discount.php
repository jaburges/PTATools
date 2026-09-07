<?php
/**
 * PTSA Membership discount amount on products and in the cart.
 *
 * Run: php tests/test-membership-discount.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-membership-module.php';

$t = new TestRunner('PTSA membership discount');

$t->equals('', Azure_Membership_Module::sanitize_member_discount_amount(''), 'blank stays blank');
$t->equals('', Azure_Membership_Module::sanitize_member_discount_amount('0'), 'zero is stored as blank');
$t->equals('', Azure_Membership_Module::sanitize_member_discount_amount('-10'), 'negative amounts are rejected');
$t->equals('25', Azure_Membership_Module::sanitize_member_discount_amount('25'), 'whole dollars are kept');
$t->equals('25.5', Azure_Membership_Module::sanitize_member_discount_amount('25.50'), 'decimals are kept');

$t->equals(25.0, Azure_Membership_Module::resolve_member_discount_amount('25', '10'), 'variation amount wins when set');
$t->equals(10.0, Azure_Membership_Module::resolve_member_discount_amount('', '10'), 'parent amount is used when the variation is blank');
$t->equals(0.0, Azure_Membership_Module::resolve_member_discount_amount('', ''), 'no amount means no discount');

$items = array(
    array('discount' => 25, 'qty' => 1, 'line_subtotal' => 80, 'is_membership' => false),
    array('discount' => 10, 'qty' => 2, 'line_subtotal' => 40, 'is_membership' => false),
);
$t->equals(45.0, Azure_Membership_Module::discount_for_cart_items($items, true), 'member gets the sum of per-line discounts');
$t->equals(0.0, Azure_Membership_Module::discount_for_cart_items($items, false), 'logged-out shoppers get no member discount');

$capped = array(
    array('discount' => 25, 'qty' => 2, 'line_subtotal' => 30, 'is_membership' => false),
);
$t->equals(30.0, Azure_Membership_Module::discount_for_cart_items($capped, true), 'discount cannot exceed the line subtotal');

$membership = array(
    array('discount' => 25, 'qty' => 1, 'line_subtotal' => 35, 'is_membership' => true),
    array('discount' => 25, 'qty' => 1, 'line_subtotal' => 80, 'is_membership' => false),
);
$t->equals(25.0, Azure_Membership_Module::discount_for_cart_items($membership, true), 'membership products themselves are not discounted');

$empty = array(
    array('discount' => 0, 'qty' => 1, 'line_subtotal' => 80, 'is_membership' => false),
);
$t->equals(0.0, Azure_Membership_Module::discount_for_cart_items($empty, true), 'products without an amount add nothing');

exit($t->finish() === 0 ? 0 : 1);
