<?php
/**
 * Group signups: one person signs up several people, shown as "Jamie × 4".
 *
 * Run: php tests/test-volunteer-group-signups.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once AZURE_PLUGIN_PATH . 'includes/class-volunteer-signup.php';

$t = new TestRunner('Volunteer group signups');
$vs = 'Azure_Volunteer_Signup';

// Labels
$t->equals('Jamie × 4', $vs::signup_label('Jamie', 4), 'a group shows as Name × N');
$t->equals('Jamie', $vs::signup_label('Jamie', 1), 'one person shows just the name');
$t->equals('Jamie', $vs::signup_label('Jamie', 0), 'a zero size is treated as one person');

// Party size of a stored row
$t->equals(4, $vs::party_size_of((object) array('party_size' => 4)), 'party size is read from the row');
$t->equals(1, $vs::party_size_of((object) array()), 'rows from before the column default to one');
$t->equals(1, $vs::party_size_of((object) array('party_size' => 0)), 'a zero party size counts as one');
$t->equals(1, $vs::party_size_of(null), 'a missing row counts as one');

// Sheet flag
$t->check($vs::sheet_allows_group((object) array('allow_group' => 1)), 'allow_group=1 enables groups');
$t->check(!$vs::sheet_allows_group((object) array('allow_group' => 0)), 'allow_group=0 does not');
$t->check(!$vs::sheet_allows_group((object) array()), 'sheets without the column do not');

// Room: the example from the request, 7 spaces
$t->equals(7, $vs::max_party_size(7, 0, 0), 'an empty 7-space role has room for 7');
$t->equals(3, $vs::max_party_size(7, 4, 0), 'after Jamie × 4, 3 spaces are left for others');
$t->equals(7, $vs::max_party_size(7, 4, 4), 'Jamie can resize his own group up to the full 7');
$t->equals(0, $vs::max_party_size(7, 7, 0), 'a full role has no room');
$t->equals(0, $vs::max_party_size(3, 5, 0), 'an overfilled role never reports negative room');

// Resolving a request
$t->equals(4, $vs::resolve_party_size(4, true, 7), 'Jamie signs up 4 of 7');
$t->equals(0, $vs::resolve_party_size(4, true, 3), 'asking for 4 when 3 are left is refused');
$t->equals(3, $vs::resolve_party_size(3, true, 3), 'asking for exactly what is left works');
$t->equals(1, $vs::resolve_party_size(4, false, 7), 'without group signups the size is always 1');
$t->equals(1, $vs::resolve_party_size(0, true, 7), 'a zero request becomes 1');
$t->equals(0, $vs::resolve_party_size(1, true, 0), 'nothing fits in a full role');
$t->equals(0, $vs::resolve_party_size(1, false, 0), 'a single signup does not fit in a full role either');

// Emails
$acts = $vs::with_party_in_names(array(
    (object) array('id' => 1, 'name' => 'Setup', 'party_size' => 4),
    (object) array('id' => 2, 'name' => 'Cleanup'),
));
$t->equals('Setup (4 people)', $acts[0]->name, 'emails name the group size');
$t->equals('Cleanup', $acts[1]->name, 'single signups keep the plain name');
$original = (object) array('name' => 'Setup', 'party_size' => 2);
$vs::with_party_in_names(array($original));
$t->equals('Setup', $original->name, 'the activity object itself is not changed');

// Wiring
$schema = file_get_contents(AZURE_PLUGIN_PATH . 'includes/class-database.php');
$t->check(strpos($schema, 'allow_group tinyint(1) NOT NULL DEFAULT 0') !== false, 'sheets table has allow_group');
$t->check(strpos($schema, 'party_size smallint(5) UNSIGNED NOT NULL DEFAULT 1') !== false, 'signups table has party_size');

$src = file_get_contents(AZURE_PLUGIN_PATH . 'includes/class-volunteer-signup.php');
$t->check(strpos($src, 'SUM(GREATEST(party_size, 1))') !== false, 'spaces filled sums party sizes');
$t->check(strpos($src, "'allow_group'    => empty(\$_POST['allow_group']) ? 0 : 1") !== false, 'saving a sheet stores the checkbox');
$t->check(strpos($src, "\$_POST['party_sizes']") !== false, 'signup reads the requested party sizes');

$admin = file_get_contents(AZURE_PLUGIN_PATH . 'admin/volunteer-page.php');
$t->check(strpos($admin, 'Enable Group Signups') !== false, 'the sheet editor has the Enable Group Signups checkbox');
$t->check(strpos($admin, "allow_group: \$('#azure-vs-allow-group').is(':checked') ? 1 : 0") !== false, 'the editor posts the checkbox');
$t->check(strpos($admin, "parseInt(s.allow_group, 10) === 1") !== false, 'editing a sheet loads the checkbox');

$account = file_get_contents(AZURE_PLUGIN_PATH . 'templates/my-account-volunteered.php');
$t->check(strpos($account, 'signup_label($my_name, $party)') !== false, 'the signups page shows Name × N');
$t->check(strpos($account, "party_sizes[") !== false, 'account signups send party sizes');

$js = file_get_contents(AZURE_PLUGIN_PATH . 'js/volunteer-frontend.js');
$t->check(strpos($js, "'party_sizes[' + activityId + ']'") !== false, 'sheet signups send the party size');
$t->check(strpos($js, '.azure-vs-update-btn') !== false, 'signed-up groups can change their size');

exit($t->finish());
