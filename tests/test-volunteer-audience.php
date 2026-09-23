<?php
/**
 * Grade/teacher matching for the Volunteered account page and its calendar feed.
 *
 * Run: php tests/test-volunteer-audience.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-volunteer-signup.php';

$t = new TestRunner('Volunteer audience and calendar');

$teachers = array('Congdon', 'Jensen');
$grades = array('PreK', 'K', '1', '2', '3', '4', '5');

$parsed = Azure_Volunteer_Signup::audience_from_title('Congdon - Math Adventures', $teachers, $grades);
$t->equals('Congdon', $parsed['teacher'], 'teacher comes from the title before the dash');
$t->equals('', $parsed['grade'], 'an activity title does not invent a grade');

$possessive = Azure_Volunteer_Signup::audience_from_title("Mrs. Congdon's - Art Docents", $teachers, $grades);
$t->equals('Congdon', $possessive['teacher'], 'a title matches the teacher list without Mrs or possessive');

$general = Azure_Volunteer_Signup::audience_from_title('Carnival', $teachers, $grades);
$t->equals('', $general['teacher'], 'a title with no teacher stays general');
$t->equals('', $general['grade'], 'a title with no grade stays general');

$grade_only = Azure_Volunteer_Signup::audience_from_title('Grade 2 - Reading helpers', $teachers, $grades);
$t->equals('', $grade_only['teacher'], 'Grade 2 is not stored as a teacher');
$t->equals('2', $grade_only['grade'], 'Grade 2 is stored as the grade');

$range = Azure_Volunteer_Signup::audience_from_title('K-3 Helpers', $teachers, $grades);
$t->equals('', $range['grade'], 'a grade range is not assigned to one grade');

$free = Azure_Volunteer_Signup::audience_from_title('Congdon - Math Adventures', array(), $grades);
$t->equals('Congdon', $free['teacher'], 'with no teacher list the name before the dash is kept');

$t->equals('Math Adventures', Azure_Volunteer_Signup::opportunity_group_label('Congdon - Math Adventures'), 'groups use the activity name');
$t->equals('Carnival', Azure_Volunteer_Signup::opportunity_group_label('Carnival'), 'a general title is its own group');

$congdon = (object) array('teacher' => 'Congdon', 'grade' => '');
$both = (object) array('teacher' => 'Congdon', 'grade' => '2');
$open = (object) array('teacher' => '', 'grade' => '');
$kids = array(
    array('teacher' => 'Congdon', 'grade' => '1'),
    array('teacher' => 'Jensen', 'grade' => '3'),
);
$t->check(Azure_Volunteer_Signup::sheet_matches_children($open, $kids), 'a general sheet is shown to every family');
$t->check(Azure_Volunteer_Signup::sheet_matches_children($congdon, $kids), 'Congdon matches the child in that class');
$t->check(!Azure_Volunteer_Signup::sheet_matches_children($both, $kids), 'teacher and grade must match the same child');
$t->check(Azure_Volunteer_Signup::sheet_matches_children($both, array(array('teacher' => 'Congdon', 'grade' => '2'))), 'a child in Congdon grade 2 matches both');
$t->check(!Azure_Volunteer_Signup::sheet_matches_children((object) array('teacher' => 'Ng', 'grade' => ''), $kids), 'another teacher is hidden');

$year = Azure_Volunteer_Signup::school_year_bounds('2026-09-23 12:00:00');
$t->equals('2026-08-01 00:00:00', $year['from'], 'September is in the school year that started in August');
$t->equals('2027-08-01 00:00:00', $year['until'], 'the school year ends the next August');
$t->equals('2026–2027', $year['label'], 'the school year label spans both years');
$january = Azure_Volunteer_Signup::school_year_bounds('2027-01-15 09:00:00');
$t->equals('2026–2027', $january['label'], 'January stays in the school year that started the previous August');

$menu = Azure_Volunteer_Signup::insert_account_menu_item(array(
    'dashboard' => 'Dashboard',
    'orders' => 'Orders',
    'profile' => 'Family Info',
    'edit-account' => 'Account details',
));
$keys = array_keys($menu);
$t->equals('volunteered', $keys[array_search('profile', $keys, true) + 1], 'Volunteered sits after Family Info');
$t->equals('Volunteered', $menu['volunteered'], 'the menu label is Volunteered');

$sheet = (object) array(
    'title' => 'Fall Carnival',
    'event_date' => '2026-10-17 12:00:00',
    'event_location' => 'Wilder Gym',
);
$soon = (object) array(
    'id' => 9,
    'name' => 'Set up',
    'description' => 'Tables',
    'slot_start' => '2026-10-17 17:00:00',
    'slot_end' => '2026-10-17 18:00:00',
);
$past = (object) array(
    'id' => 4,
    'name' => 'Cleanup',
    'description' => 'Done',
    'slot_start' => '2026-09-01 17:00:00',
    'slot_end' => '2026-09-01 18:00:00',
);
$user = (object) array('ID' => 44);
$ics = Azure_Volunteer_Signup::build_feed_ics(array(
    array('sheet' => $sheet, 'activity' => $soon),
    array('sheet' => $sheet, 'activity' => $past),
), $user, '2026-09-23 12:00:00');
$t->check(strpos($ics, 'METHOD:PUBLISH') !== false, 'the feed is a calendar subscription');
$t->check(strpos($ics, 'UID:pta-volunteer-9-44@') !== false, 'the upcoming shift keeps a stable uid');
$t->check(strpos($ics, 'UID:pta-volunteer-4-44@') === false, 'a finished shift is left out of the feed');
$t->equals(1, substr_count($ics, 'BEGIN:VEVENT'), 'the feed has one event');

exit($t->finish() === 0 ? 0 : 1);
