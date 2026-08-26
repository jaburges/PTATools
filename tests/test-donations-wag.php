<?php
/**
 * WAG donation levels: sanitizer, colors, and heading defaults.
 *
 * Run: php tests/test-donations-wag.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags((string) $str));
    }
}

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-donations-module.php';

$t = new TestRunner('Donations WAG');

WP_Shim::reset();

$defaults = Azure_Donations_Module::default_wag_levels();
$t->equals(3, count($defaults), 'three default levels');
$t->equals('Pack Leader', $defaults[0]['name'], 'first default name');
$t->equals(250.0, (float) $defaults[1]['amount'], 'second default amount');

$empty = Azure_Donations_Module::sanitize_wag_levels(null);
$t->equals(3, count($empty), 'null input still yields three rows');
$t->equals('Positive Paw', $empty[2]['name'], 'missing rows fall back to defaults');

$dirty = Azure_Donations_Module::sanitize_wag_levels(array(
    array(
        'amount'       => '500.4',
        'name'         => '<b>Pack Leader</b>',
        'suffix'       => 'per student',
        'product_id'   => '42',
        'variation_id' => '-9',
    ),
    array(
        'amount'     => -10,
        'name'       => '',
        'product_id' => 7,
    ),
));
$t->equals(500.4, $dirty[0]['amount'], 'amount is rounded float');
$t->equals('Pack Leader', $dirty[0]['name'], 'name strips tags');
$t->equals(42, $dirty[0]['product_id'], 'product id is int');
$t->equals(0, $dirty[0]['variation_id'], 'negative variation id clamps to 0');
$t->equals(0, $dirty[1]['amount'], 'negative amount clamps to 0');
$t->equals('Helpful Howler', $dirty[1]['name'], 'empty name uses default for that slot');
$t->equals(7, $dirty[1]['product_id'], 'second row product id kept');
$t->equals('Positive Paw', $dirty[2]['name'], 'third row filled from defaults');

$t->equals('#0B2545', Azure_Donations_Module::sanitize_wag_color('not-a-color', '#0B2545'), 'invalid color uses fallback');
$t->equals('#112233', Azure_Donations_Module::sanitize_wag_color('#112233', '#000000'), 'valid 6-digit hex kept');
$t->equals('#abc', Azure_Donations_Module::sanitize_wag_color('#abc', '#000000'), 'valid 3-digit hex kept');

$t->equals('$500', Azure_Donations_Module::format_wag_amount(500), 'whole dollars have no cents');
$t->equals('$12.50', Azure_Donations_Module::format_wag_amount(12.5), 'fractional amounts keep cents');

WP_Shim::$settings['org_name'] = 'Example PTA';
$t->equals(
    'Fund the Example PTA budget and help us reach our $40,000 goal for our kids.',
    Azure_Donations_Module::default_wag_heading(),
    'heading uses org_name'
);

WP_Shim::reset();
$t->equals(
    'Fund the Test Site budget and help us reach our $40,000 goal for our kids.',
    Azure_Donations_Module::default_wag_heading(),
    'heading falls back to site title'
);

WP_Shim::$settings['donations_enable_wag'] = '1';
$t->check(Azure_Donations_Module::wag_enabled(), 'wag enabled when setting is 1');
WP_Shim::$settings['donations_enable_wag'] = '';
$t->check(!Azure_Donations_Module::wag_enabled(), 'wag disabled when setting is empty');

$t->equals('', Azure_Donations_Module::wag_level_url(array('product_id' => 0, 'variation_id' => 0)), 'unmapped level has no url');

WP_Shim::$settings['donations_wag_levels'] = Azure_Donations_Module::sanitize_wag_levels(array(
    array('amount' => 500, 'name' => 'Pack Leader', 'suffix' => 'per student', 'product_id' => 10, 'variation_id' => 99),
    array('amount' => 250, 'name' => 'Howler', 'suffix' => 'per student', 'product_id' => 10, 'variation_id' => 88),
    array('amount' => 150, 'name' => 'Paw', 'suffix' => 'per student', 'product_id' => 22, 'variation_id' => 0),
));
$t->check(Azure_Donations_Module::is_wag_mapped_item(10, 99), 'mapped variation matches');
$t->check(!Azure_Donations_Module::is_wag_mapped_item(10, 77), 'unmapped variation of the same parent does not match');
$t->check(Azure_Donations_Module::is_wag_mapped_item(22, 0), 'parent-only mapping matches the product');
$t->check(!Azure_Donations_Module::is_wag_mapped_item(33, 0), 'unrelated product does not match');

$t->equals(array('type' => 'wag'), Azure_Donations_Module::normalize_progress_campaign_attr('WAG'), 'WAG alias');
$t->equals(array('type' => 'wag'), Azure_Donations_Module::normalize_progress_campaign_attr('donation-items'), 'donation-items alias');
$t->equals(array('type' => 'id', 'id' => 7), Azure_Donations_Module::normalize_progress_campaign_attr('7'), 'numeric campaign id');
$t->equals(array('type' => 'name', 'name' => 'Spring Drive'), Azure_Donations_Module::normalize_progress_campaign_attr('Spring Drive'), 'campaign name');

$totals = Azure_Donations_Module::format_progress_totals(12500, 40000);
$t->equals(12500.0, $totals['raised'], 'progress raised');
$t->equals(31, $totals['pct'], 'progress percent rounds');
$empty = Azure_Donations_Module::format_progress_totals(0, 0);
$t->equals(0, $empty['pct'], 'no goal and no raised is 0%');

exit($t->finish() === 0 ? 0 : 1);
