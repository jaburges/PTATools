<?php
/**
 * Series edit and delete apply to every dated sheet, not only the template.
 *
 * Run: php tests/test-volunteer-series-edit.php
 */

require_once __DIR__ . '/wp-shim.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-volunteer-signup.php';

$wpdb = new Fake_WPDB();
$sheets = 'wp_azure_volunteer_sheets';
$activities = 'wp_azure_volunteer_activities';
$signups = 'wp_azure_volunteer_signups';
$wpdb->next_ids[$activities] = 100;

$wpdb->tables[$sheets] = array(
    array('id' => 10, 'title' => 'WatchDOGS', 'description' => 'Series notes', 'is_template' => 1, 'template_id' => 0, 'pta_event_id' => 0, 'event_date' => '', 'event_location' => 'Lobby', 'grade' => '', 'teacher' => '', 'status' => 'open'),
    array('id' => 11, 'title' => 'Edited date', 'description' => 'Only this day', 'is_template' => 0, 'template_id' => 10, 'pta_event_id' => 35717, 'event_date' => '2026-10-01 14:00:00', 'event_location' => 'Room A', 'grade' => '', 'teacher' => '', 'status' => 'open'),
    array('id' => 12, 'title' => 'Another date', 'description' => '', 'is_template' => 0, 'template_id' => 10, 'pta_event_id' => 35718, 'event_date' => '2026-10-08 14:00:00', 'event_location' => '', 'grade' => '', 'teacher' => '', 'status' => 'open'),
    array('id' => 20, 'title' => 'Math Adventures', 'description' => '', 'is_template' => 0, 'template_id' => 0, 'pta_event_id' => 99, 'event_date' => '2026-11-01 14:00:00', 'event_location' => '', 'grade' => '', 'teacher' => '', 'status' => 'open'),
    array('id' => 30, 'title' => 'Other series', 'description' => '', 'is_template' => 1, 'template_id' => 0, 'pta_event_id' => 0, 'event_date' => '', 'event_location' => '', 'grade' => '', 'teacher' => '', 'status' => 'open'),
    array('id' => 31, 'title' => 'Other date', 'description' => '', 'is_template' => 0, 'template_id' => 30, 'pta_event_id' => 80, 'event_date' => '2026-12-01 14:00:00', 'event_location' => '', 'grade' => '', 'teacher' => '', 'status' => 'open'),
);
$wpdb->tables[$activities] = array(
    array('id' => 1, 'sheet_id' => 10, 'name' => 'Volunteer', 'description' => '', 'spots_needed' => 2, 'slot_start' => '2026-09-01 14:00:00', 'slot_end' => '2026-09-01 15:00:00', 'sort_order' => 0),
    array('id' => 2, 'sheet_id' => 10, 'name' => 'Backup', 'description' => '', 'spots_needed' => 1, 'slot_start' => '', 'slot_end' => '', 'sort_order' => 1),
    array('id' => 3, 'sheet_id' => 11, 'name' => 'Volunteer', 'description' => 'custom', 'spots_needed' => 9, 'slot_start' => '2026-10-01 09:00:00', 'slot_end' => '', 'sort_order' => 0),
    array('id' => 4, 'sheet_id' => 11, 'name' => 'Extra', 'description' => '', 'spots_needed' => 1, 'slot_start' => '', 'slot_end' => '', 'sort_order' => 1),
    array('id' => 5, 'sheet_id' => 12, 'name' => 'Volunteer', 'description' => '', 'spots_needed' => 2, 'slot_start' => '', 'slot_end' => '', 'sort_order' => 0),
    array('id' => 6, 'sheet_id' => 31, 'name' => 'Helper', 'description' => '', 'spots_needed' => 1, 'slot_start' => '', 'slot_end' => '', 'sort_order' => 0),
);
$wpdb->tables[$signups] = array(
    array('id' => 1, 'activity_id' => 3, 'user_id' => 9),
    array('id' => 2, 'activity_id' => 4, 'user_id' => 8),
    array('id' => 3, 'activity_id' => 6, 'user_id' => 7),
);

$t = new TestRunner('Volunteer series edit and delete');

$template = Azure_Volunteer_Signup::get_sheet(10);
$updated = Azure_Volunteer_Signup::overwrite_series_instances($template);
$t->equals(2, $updated, 'both dated sheets are overwritten');

$day = Azure_Volunteer_Signup::get_sheet(11);
$t->equals('WatchDOGS', $day->title, 'a date edited on its own takes the series title');
$t->equals('Series notes', $day->description, 'a date edited on its own takes the series description');
$t->equals('2026-10-01 14:00:00', $day->event_date, 'the calendar date on that event stays');
$t->equals(35717, (int) $day->pta_event_id, 'the linked event stays');

$day_acts = Azure_Volunteer_Signup::activities_for_sheet(11);
$t->equals(array('Volunteer', 'Backup'), array($day_acts[0]->name, $day_acts[1]->name), 'the series roles replace a custom role');
$t->equals(2, (int) $day_acts[0]->spots_needed, 'spots follow the series');
$t->equals(3, (int) $day_acts[0]->id, 'the matching role keeps its row so signups stay');
$t->equals('2026-10-01 14:00:00', $day_acts[0]->slot_start, 'the role time moves onto that event date');

$kept = array();
foreach ($wpdb->tables[$signups] as $signup) {
    $kept[] = (int) $signup['activity_id'];
}
$t->check(in_array(3, $kept, true), 'a signup on the matching role stays');
$t->check(!in_array(4, $kept, true), 'a signup on a removed role is deleted');

$other = Azure_Volunteer_Signup::get_sheet(20);
$t->equals('Math Adventures', $other->title, 'a sheet outside the series is left alone');

$removed = Azure_Volunteer_Signup::delete_series(30);
$t->equals(2, $removed, 'deleting a series removes the template and its dates');
$t->equals(null, Azure_Volunteer_Signup::get_sheet(30), 'the series template is gone');
$t->equals(null, Azure_Volunteer_Signup::get_sheet(31), 'the dated sheet in that series is gone');
$t->equals('WatchDOGS', Azure_Volunteer_Signup::get_sheet(10)->title, 'another series is not deleted');
$signup_ids = array();
foreach ($wpdb->tables[$signups] as $signup) {
    $signup_ids[] = (int) $signup['id'];
}
$t->check(!in_array(3, $signup_ids, true), 'signups on the deleted series are removed');
$t->check(in_array(1, $signup_ids, true), 'signups on a different series stay');

Azure_Volunteer_Signup::delete_one_sheet(12);
$t->equals(null, Azure_Volunteer_Signup::get_sheet(12), 'deleting one date removes that sheet');
$t->equals('WatchDOGS', Azure_Volunteer_Signup::get_sheet(10)->title, 'deleting one date leaves the rest of the series');

exit($t->finish() === 0 ? 0 : 1);
