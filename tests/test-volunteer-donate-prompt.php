<?php
/**
 * Donate after sign-up (#18): a sheet's donation link and amounts, shown
 * straight after someone signs up for an activity that asks for one, plus
 * the per-sheet CSV listing every option each person picked.
 *
 * Run: php tests/test-volunteer-donate-prompt.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

if (!function_exists('get_userdata')) {
    function get_userdata($id) {
        $users = array(
            7 => (object) array('ID' => 7, 'display_name' => 'Jamie Burgess', 'user_email' => 'jamie@example.test'),
            8 => (object) array('ID' => 8, 'display_name' => '=HYPERLINK("x")', 'user_email' => 'sam@example.test'),
        );
        return $users[(int) $id] ?? false;
    }
}

require_once AZURE_PLUGIN_PATH . 'includes/class-volunteer-signup.php';

$t = new TestRunner('Volunteer donate prompt');
$vs = 'Azure_Volunteer_Signup';

// Amount presets
$t->equals(array(5, 10, 25, 50, 100), $vs::parse_donate_amounts(''), 'no amounts falls back to $5, $10, $25, $50, $100');
$t->equals(array(5, 20, 100), $vs::parse_donate_amounts('$20, 5,100'), 'amounts are sorted and accept a $ sign');
$t->equals(array(10, 50), $vs::parse_donate_amounts('2, 10, 50, 150, 7.5, abc'), 'amounts below $5, above $100 or not whole dollars are dropped');
$t->equals(array(5, 10, 25, 50, 100), $vs::parse_donate_amounts('1, 500'), 'a list with nothing usable falls back to the defaults');
$t->equals(array(5), $vs::parse_donate_amounts('5,5,5'), 'duplicates collapse');

// Donation link
$t->equals('https://example.test/donate/', $vs::sanitize_donate_url(' https://example.test/donate/ '), 'an https link is kept');
$t->equals('', $vs::sanitize_donate_url('javascript:alert(1)'), 'a javascript: link is dropped');
$t->equals('', $vs::sanitize_donate_url('/donate/'), 'a relative link is dropped');
$t->equals('https://example.test/donate/?donate_amount=25', $vs::donate_amount_url('https://example.test/donate/', 25), 'amount buttons add ?donate_amount=');
$t->equals('https://example.test/d?c=1&donate_amount=5', $vs::donate_amount_url('https://example.test/d?c=1', 5), 'an existing query string is kept');

// Offer: the Bulb Planting Party example
$sheet = (object) array(
    'id' => 3,
    'title' => 'Bulb Planting Party',
    'donate_url' => 'https://example.test/donate/',
    'donate_label' => 'Donate to bulbs',
    'donate_amounts' => '',
);
$attend = (object) array('id' => 11, 'name' => 'Attending the party', 'donate_prompt' => 0);
$attend_donate = (object) array('id' => 12, 'name' => 'Attending + donating $5+', 'donate_prompt' => 1);
$donate_only = (object) array('id' => 14, 'name' => 'Donating $5+ or dropping off bulbs', 'donate_prompt' => 1);

$t->equals(null, $vs::donate_offer($sheet, array($attend)), 'no donate prompt when the chosen option does not ask for one');
$offer = $vs::donate_offer($sheet, array($attend, $attend_donate));
$t->check(is_array($offer), 'picking a flagged option returns a donate prompt');
$t->equals('Donate to bulbs', $offer['label'] ?? '', 'the button uses the sheet button text');
$t->equals(array('Attending + donating $5+'), $offer['activities'] ?? array(), 'the prompt names only the flagged options');
$t->equals(5, count($offer['amounts'] ?? array()), 'five amount buttons, $5 to $100');
$t->equals('$100', $offer['amounts'][4]['label'] ?? '', 'the largest button is $100');
$t->equals('https://example.test/donate/?donate_amount=5', $offer['amounts'][0]['url'] ?? '', 'the $5 button links to the donation page with the amount');

$no_link = clone $sheet;
$no_link->donate_url = '';
$t->equals(null, $vs::donate_offer($no_link, array($donate_only)), 'no prompt when the sheet has no donation link');
$t->equals(null, $vs::donate_offer(null, array($donate_only)), 'no prompt without a sheet');
$plain = clone $sheet;
$plain->donate_label = '';
$t->equals('Donate', $vs::donate_offer($plain, array($donate_only))['label'], 'the button says Donate when no text is set');
$t->equals(null, $vs::donate_offer($sheet, array((object) array('name' => 'Old row'))), 'activities from before the column never prompt');

// CSV export: every option each person picked
$acts = array($attend, $attend_donate, $donate_only);
$signups = array(
    (object) array('activity_id' => 12, 'user_id' => 7, 'party_size' => 3, 'signed_up_at' => '2026-10-20 18:00:00'),
    (object) array('activity_id' => 14, 'user_id' => 7, 'party_size' => 1, 'signed_up_at' => '2026-10-20 18:01:00'),
    (object) array('activity_id' => 11, 'user_id' => 8, 'party_size' => 1, 'signed_up_at' => '2026-10-21 09:00:00'),
);
$rows = $vs::export_rows($sheet, $acts, $signups);
$t->equals(array('Activity', 'Time', 'Name', 'Email', 'People', 'Signed up'), $rows[0], 'CSV header');
$t->equals(4, count($rows), 'one row per person per option');
$jamie = array_values(array_filter($rows, function ($r) { return $r[3] === 'jamie@example.test'; }));
$t->equals(array('Attending + donating $5+', 'Donating $5+ or dropping off bulbs'), array_column($jamie, 0), 'a person who picked two options appears under both');
$t->equals('3', $jamie[0][4], 'the group size is in the People column');
$t->equals("'=HYPERLINK(\"x\")", $vs::csv_cell('=HYPERLINK("x")'), 'a formula-looking cell is defused');
$t->equals('Jamie', $vs::csv_cell('Jamie'), 'plain cells are untouched');

// Wiring
$src = file_get_contents(AZURE_PLUGIN_PATH . 'includes/class-volunteer-signup.php');
$t->check(strpos($src, "'donate'     => self::donate_offer(self::get_sheet(\$sheet_id), \$added_acts)") !== false, 'the signup response carries the donate prompt');
$t->check(strpos($src, "'donate_prompt'=> empty(\$act['donate_prompt']) ? 0 : 1") !== false, 'saving a sheet stores each activity flag');
$t->check(substr_count($src, "'donate_url'") >= 3, 'the donation link is saved and copied onto series dates');
$js = file_get_contents(AZURE_PLUGIN_PATH . 'js/volunteer-frontend.js');
$t->check(strpos($js, 'showDonate($sheet, res.data.donate)') !== false, 'the page shows the donate buttons after signup');
$t->check(strpos($js, 'azure-vs-donate-done') !== false, 'the page waits for Done before reloading');
$admin = file_get_contents(AZURE_PLUGIN_PATH . 'admin/volunteer-page.php');
$t->check(strpos($admin, 'act-donate') !== false && strpos($admin, 'azure-vs-donate-url') !== false, 'the sheet editor has the link, amounts and per-activity checkbox');
$t->check(strpos($admin, 'Azure_Volunteer_Signup::export_url') !== false, 'each sheet has a CSV download');
$db = file_get_contents(AZURE_PLUGIN_PATH . 'includes/class-database.php');
$t->check(strpos($db, 'donate_prompt tinyint(1)') !== false && strpos($db, 'donate_url varchar(500)') !== false, 'fresh installs get the columns');

// [pta-donate] preselects the amount from the link
$don = file_get_contents(AZURE_PLUGIN_PATH . 'includes/class-donations-module.php');
$t->check(strpos($don, 'self::requested_donate_amount()') !== false, '[pta-donate] reads ?donate_amount=');
require_once AZURE_PLUGIN_PATH . 'includes/class-donations-module.php';
$_GET['donate_amount'] = '25';
$t->equals(25, Azure_Donations_Module::requested_donate_amount(), '?donate_amount=25 preselects $25');
$_GET['donate_amount'] = '25<script>';
$t->equals(0, Azure_Donations_Module::requested_donate_amount(), 'anything but digits is ignored');
$_GET['donate_amount'] = '0';
$t->equals(0, Azure_Donations_Module::requested_donate_amount(), 'zero is ignored');
unset($_GET['donate_amount']);
$t->equals(0, Azure_Donations_Module::requested_donate_amount(), 'no parameter, no preset');

$t->finish();
