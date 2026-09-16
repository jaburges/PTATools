<?php
/**
 * Donation receipt eligibility, sanitizers, and PDF payload.
 *
 * Run: php tests/test-donation-receipts.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-donations-module.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-donation-receipt-pdf.php';

$t = new TestRunner('Donation receipts');

WP_Shim::reset();

$t->check(
    strpos(Azure_Donations_Module::default_receipt_text(), '501(c)(3)') !== false,
    'default receipt text includes 501(c)(3) language'
);
$t->check(
    strpos(Azure_Donations_Module::default_receipt_text(), '91-1461125') !== false,
    'default receipt text includes EIN'
);

$t->equals(
    array(12, 34),
    Azure_Donations_Module::sanitize_receipt_category_ids(array('12', 34, 0, -2, '12')),
    'category ids are unique positive ints'
);

$t->equals(
    'Hello world',
    Azure_Donations_Module::sanitize_receipt_text("<b>Hello world</b>"),
    'receipt text strips tags'
);

WP_Shim::$settings['donations_receipt_text'] = '';
$t->check(
    Azure_Donations_Module::get_receipt_text() === Azure_Donations_Module::default_receipt_text(),
    'empty setting uses default receipt text'
);

WP_Shim::$settings['donations_receipt_text'] = 'Custom footer';
$t->equals('Custom footer', Azure_Donations_Module::get_receipt_text(), 'saved receipt text is used');

WP_Shim::reset();
$t->check(Azure_Donations_Module::receipt_include_fees(), 'fees included by default');
WP_Shim::$settings['donations_receipt_include_fees'] = '0';
$t->check(!Azure_Donations_Module::receipt_include_fees(), 'fees can be turned off');

$t->check(
    Azure_Donations_Module::product_in_receipt_categories(array(5, 9), array(9, 11)),
    'product matches when a selected category is present'
);
$t->check(
    !Azure_Donations_Module::product_in_receipt_categories(array(1), array(9, 11)),
    'product does not match unrelated categories'
);
$t->check(
    !Azure_Donations_Module::product_in_receipt_categories(array(9), array()),
    'no selected categories means no match'
);

$t->check(Azure_Donations_Module::fee_is_donation('Round up Donation'), 'round-up fee is a donation');
$t->check(Azure_Donations_Module::fee_is_donation('Wolf Pack Donation'), 'named amount fee is a donation');
$t->check(!Azure_Donations_Module::fee_is_donation('Shipping'), 'shipping is not a donation fee');

$t->check(
    Azure_Donations_Module::order_has_receipt_content(true, false, true),
    'WAG line item qualifies'
);
$t->check(
    Azure_Donations_Module::order_has_receipt_content(false, true, true),
    'donation fee qualifies when enabled'
);
$t->check(
    !Azure_Donations_Module::order_has_receipt_content(false, true, false),
    'donation fee is skipped when fees are disabled'
);
$t->check(
    !Azure_Donations_Module::order_has_receipt_content(false, false, true),
    'membership-only order does not qualify'
);

$items = array(
    array('name' => 'Individual Membership', 'total' => 15, 'category_ids' => array(3)),
    array('name' => 'WAG Pack Leader', 'total' => 500, 'category_ids' => array(8, 2)),
);
$filtered = Azure_Donations_Module::filter_receipt_line_items($items, array(8));
$t->equals(1, count($filtered), 'only the donation product is kept');
$t->equals('WAG Pack Leader', $filtered[0]['name'], 'kept line is WAG');

$fees = array(
    array('name' => 'Shipping', 'total' => 4.5),
    array('name' => 'Round up Donation', 'total' => 0.42),
);
$t->equals(1, count(Azure_Donations_Module::filter_receipt_fees($fees, true)), 'donation fee kept');
$t->equals(0, count(Azure_Donations_Module::filter_receipt_fees($fees, false)), 'fees omitted when disabled');

$t->equals(
    500.42,
    Azure_Donations_Module::receipt_lines_total(array(
        array('total' => 500),
        array('total' => 0.42),
    )),
    'receipt total sums donation lines'
);
$t->equals('$500.00', Azure_Donations_Module::format_receipt_money(500), 'money format');

$pdf = Azure_Donation_Receipt_Pdf::build(array(
    'org'          => 'Laura Ingalls Wilder PTSA',
    'order_number' => '34823',
    'date'         => 'September 14, 2026',
    'donor'        => 'Jamie Burgess',
    'email'        => 'jamie@example.com',
    'lines'        => array(array('name' => 'Wilder About Giving - Pack Leader', 'total' => 500)),
    'total'        => 500,
    'footer'       => Azure_Donations_Module::default_receipt_text(),
));
$t->check(strpos($pdf, '%PDF-1.4') === 0, 'PDF starts with header');
$t->check(substr($pdf, -5) === '%%EOF', 'PDF ends with EOF');
$t->check(strpos($pdf, 'Pack Leader') !== false, 'PDF includes product name');
$t->check(strpos($pdf, '501\\(c\\)\\(3\\)') !== false, 'PDF includes escaped 501(c)(3) language');
$t->check(strpos($pdf, '91-1461125') !== false, 'PDF includes EIN');
$t->check(strpos($pdf, 'Jamie Burgess') !== false, 'PDF includes donor name');
$t->check(strpos($pdf, '34823') !== false, 'PDF includes order number');

exit($t->finish() === 0 ? 0 : 1);
