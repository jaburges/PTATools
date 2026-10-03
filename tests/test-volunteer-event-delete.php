<?php
/**
 * A deleted event takes its sign-up sheets with it; a changed event keeps them.
 *
 * Run: php tests/test-volunteer-event-delete.php
 */

require_once __DIR__ . '/wp-shim.php';

$GLOBALS['pta_test_post_status'] = array(
    500 => 'trash',
    501 => 'publish',
    502 => 'publish',
    // 503 is missing: permanently deleted.
);
if (!function_exists('get_post_status')) {
    function get_post_status($id) {
        $map = $GLOBALS['pta_test_post_status'];
        return isset($map[(int) $id]) ? $map[(int) $id] : false;
    }
}
if (!function_exists('get_post_type')) {
    function get_post_type($id) {
        return in_array((int) $id, array(500, 501, 502, 503), true) ? 'pta_event' : 'post';
    }
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-volunteer-signup.php';

$wpdb = new Fake_WPDB();
$sheets = 'wp_azure_volunteer_sheets';
$activities = 'wp_azure_volunteer_activities';
$signups = 'wp_azure_volunteer_signups';

$wpdb->tables[$sheets] = array(
    array('id' => 1, 'title' => 'Art series', 'is_template' => 1, 'template_id' => 0, 'pta_event_id' => 0, 'status' => 'open', 'description' => '', 'event_location' => '', 'grade' => '', 'teacher' => ''),
    array('id' => 2, 'title' => 'Art Oct 1', 'is_template' => 0, 'template_id' => 1, 'pta_event_id' => 500, 'status' => 'open', 'description' => '', 'event_location' => '', 'grade' => '', 'teacher' => ''),
    array('id' => 3, 'title' => 'Art Oct 8', 'is_template' => 0, 'template_id' => 1, 'pta_event_id' => 501, 'status' => 'open', 'description' => '', 'event_location' => '', 'grade' => '', 'teacher' => ''),
    array('id' => 4, 'title' => 'Art Oct 15', 'is_template' => 0, 'template_id' => 1, 'pta_event_id' => 503, 'status' => 'trashed', 'description' => '', 'event_location' => '', 'grade' => '', 'teacher' => ''),
    array('id' => 5, 'title' => 'Carnival', 'is_template' => 0, 'template_id' => 0, 'pta_event_id' => 502, 'status' => 'open', 'description' => '', 'event_location' => '', 'grade' => '', 'teacher' => ''),
    array('id' => 6, 'title' => 'Unlinked', 'is_template' => 0, 'template_id' => 0, 'pta_event_id' => 0, 'status' => 'open', 'description' => '', 'event_location' => '', 'grade' => '', 'teacher' => ''),
);
$wpdb->next_ids[$activities] = 100;
$wpdb->tables[$activities] = array(
    array('id' => 10, 'sheet_id' => 1, 'name' => 'Volunteer', 'description' => '', 'spots_needed' => 1, 'slot_start' => '', 'slot_end' => '', 'sort_order' => 0),
    array('id' => 20, 'sheet_id' => 2, 'name' => 'Volunteer'),
    array('id' => 30, 'sheet_id' => 3, 'name' => 'Volunteer'),
    array('id' => 50, 'sheet_id' => 5, 'name' => 'Booth'),
);
$wpdb->tables[$signups] = array(
    array('id' => 1, 'activity_id' => 20, 'user_id' => 9),
    array('id' => 2, 'activity_id' => 30, 'user_id' => 8),
    array('id' => 3, 'activity_id' => 50, 'user_id' => 7),
);

$t = new TestRunner('Volunteer sheets follow deleted events');

$template = Azure_Volunteer_Signup::get_sheet(1);
Azure_Volunteer_Signup::overwrite_series_instances($template);
$t->equals('trashed', Azure_Volunteer_Signup::get_sheet(4)->status, 'saving a series does not reopen a trashed date');

$removed = Azure_Volunteer_Signup::delete_sheets_for_event(500);
$t->equals(1, $removed, 'trashing an event deletes its one dated sheet');
$t->equals(null, Azure_Volunteer_Signup::get_sheet(2), 'that sheet is gone');
$t->check(Azure_Volunteer_Signup::get_sheet(1) !== null, 'the series template stays');
$t->check(Azure_Volunteer_Signup::get_sheet(3) !== null, 'another date in the series stays');
$signup_ids = array_map(function ($r) { return (int) $r['id']; }, $wpdb->tables[$signups]);
$t->check(!in_array(1, $signup_ids, true), 'signups on the deleted sheet are removed');
$t->check(in_array(2, $signup_ids, true), 'signups on other dates stay');

$wpdb->tables[$sheets][] = array('id' => 7, 'title' => 'Art Oct 1 again', 'is_template' => 0, 'template_id' => 1, 'pta_event_id' => 500, 'status' => 'open', 'description' => '', 'event_location' => '', 'grade' => '', 'teacher' => '');
$purged = Azure_Volunteer_Signup::purge_orphan_sheets();
$t->equals(2, $purged, 'cleanup removes sheets for a trashed event and a deleted event');
$t->equals(null, Azure_Volunteer_Signup::get_sheet(7), 'sheet on a trashed event is removed');
$t->equals(null, Azure_Volunteer_Signup::get_sheet(4), 'sheet on a permanently deleted event is removed');
$t->check(Azure_Volunteer_Signup::get_sheet(3) !== null, 'sheet on a published event stays');
$t->check(Azure_Volunteer_Signup::get_sheet(5) !== null, 'a one-off sheet on a published event stays');
$t->check(Azure_Volunteer_Signup::get_sheet(6) !== null, 'a sheet with no event is left alone');
$t->check(Azure_Volunteer_Signup::get_sheet(1) !== null, 'the template is never purged');

$src = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-volunteer-signup.php');
$t->check(strpos($src, "add_action('before_delete_post', array(\$this, 'on_event_trashed'))") !== false, 'permanent deletes are handled too');
$sync = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-calendar-sync-engine.php');
$t->check(strpos($sync, 'Azure_Volunteer_Signup::purge_orphan_sheets()') !== false, 'each calendar sync cleans up orphaned sheets');

exit($t->finish() === 0 ? 0 : 1);
