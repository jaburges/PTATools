<?php
/**
 * iOS Home widgets: prefs sanitization, volunteer fill math, membership counts.
 *
 * Run: php tests/test-ptsa-home-widgets.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-ptsa-jwt.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-ptsa-rest-api.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-volunteer-signup.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-membership-module.php';

$t = new TestRunner('PTSA Home widgets');

$clean = Azure_PTSA_REST_API::sanitize_home_widgets(array(
    'order'   => array('memberships', 'bogus', 'volunteers', 'memberships'),
    'hidden'  => array('orders', '???', 'orders'),
    'updated' => 1700000000,
));
$t->equals(array('memberships', 'volunteers'), array_slice($clean['order'], 0, 2), 'saved order is kept first');
$t->check(in_array('orders', $clean['order'], true), 'known widgets missing from order are appended');
$t->equals(count(Azure_PTSA_REST_API::HOME_WIDGET_IDS), count($clean['order']), 'every catalog widget appears once');
$t->equals(array('orders'), $clean['hidden'], 'unknown hidden ids are dropped and duplicates collapse');
$t->equals(1700000000, $clean['updated'], 'client updated timestamp is kept');

$empty = Azure_PTSA_REST_API::sanitize_home_widgets(null);
$t->equals(Azure_PTSA_REST_API::HOME_WIDGET_IDS, $empty['order'], 'empty prefs default to catalog order');
$t->equals(array(), $empty['hidden'], 'empty prefs hide nothing');

$fill = Azure_Volunteer_Signup::activity_fill(4, 1);
$t->equals(4, $fill['spots_needed'], 'activity needed spots');
$t->equals(1, $fill['spots_filled'], 'activity filled spots');
$t->equals(3, $fill['spots_open'], 'activity open spots');

$over = Azure_Volunteer_Signup::activity_fill(2, 5);
$t->equals(0, $over['spots_open'], 'overfilled slots do not go negative');

$totals = Azure_Volunteer_Signup::sheet_fill_totals(array(
    array('spots_needed' => 4, 'spots_filled' => 1),
    array('spots_needed' => 2, 'spots_filled' => 2),
));
$t->equals(6, $totals['spots_needed'], 'sheet needed is the sum');
$t->equals(3, $totals['spots_filled'], 'sheet filled is the sum');
$t->equals(3, $totals['spots_open'], 'sheet open is the remainder');

$summary = Azure_Membership_Module::rest_summary(array(
    11 => array('type' => 'family'),
    12 => array('type' => 'family'),
    13 => array('type' => 'individual'),
    14 => array('type' => 'staff'),
    15 => array('type' => 'comp'),
));
$t->equals(5, $summary['total'], 'membership total counts every paid user');
$t->equals(2, $summary['counts']['family'], 'family count');
$t->equals(1, $summary['counts']['individual'], 'individual count');
$t->equals(1, $summary['counts']['staff'], 'staff count');
$t->equals(1, $summary['counts']['other'], 'unknown types roll into other');
$t->check($summary['year'] !== '', 'school year label is present');

$t->check(in_array('volunteers', Azure_PTSA_REST_API::HOME_WIDGET_IDS, true), 'volunteers widget is in the catalog');
$t->check(in_array('memberships', Azure_PTSA_REST_API::HOME_WIDGET_IDS, true), 'memberships widget is in the catalog');

exit($t->finish() === 0 ? 0 : 1);
