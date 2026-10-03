<?php
/**
 * Reconnecting a series template to a different Outlook series adds sheets
 * for its events and deletes sheets for events outside it.
 *
 * Run: php tests/test-volunteer-series-reconnect.php
 */

require_once __DIR__ . '/wp-shim.php';

$GLOBALS['pta_test_events'] = array(
    // Old weekly series (master OLD): one past date, one upcoming.
    701 => array('title' => '5th Grade - Neibauer', 'master' => 'OLD', 'start' => '2026-09-24 12:30:00'),
    702 => array('title' => '5th Grade - Neibauer', 'master' => 'OLD', 'start' => '2026-10-01 12:30:00'),
    // New monthly series (master NEW).
    801 => array('title' => '5th Grade - Neibauer', 'master' => 'NEW', 'start' => '2026-10-07 12:30:00'),
    802 => array('title' => '5th Grade - Neibauer', 'master' => 'NEW', 'start' => '2026-11-04 12:30:00'),
    803 => array('title' => '5th Grade - Neibauer', 'master' => 'NEW', 'start' => '2026-12-02 12:30:00'),
);
foreach ($GLOBALS['pta_test_events'] as $id => $e) {
    update_post_meta($id, '_outlook_calendar_id', 'CAL');
    update_post_meta($id, '_outlook_series_master_id', $e['master']);
    update_post_meta($id, '_EventStartDate', $e['start']);
}
if (!function_exists('get_posts')) {
    function get_posts($args = array()) {
        return array_keys($GLOBALS['pta_test_events']);
    }
}
if (!function_exists('get_the_title')) {
    function get_the_title($id) {
        return $GLOBALS['pta_test_events'][(int) $id]['title'] ?? '';
    }
}
if (!function_exists('get_post')) {
    function get_post($id) {
        $e = $GLOBALS['pta_test_events'][(int) $id] ?? null;
        return $e ? (object) array('ID' => (int) $id, 'post_title' => $e['title'], 'post_type' => 'pta_event') : null;
    }
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-volunteer-signup.php';

$wpdb = new Fake_WPDB();
$sheets = 'wp_azure_volunteer_sheets';
$activities = 'wp_azure_volunteer_activities';
$signups = 'wp_azure_volunteer_signups';
$wpdb->next_ids[$sheets] = 500;
$wpdb->next_ids[$activities] = 900;

$old_key = Azure_Volunteer_Signup::build_series_key('CAL', 'OLD', '');
$new_key = Azure_Volunteer_Signup::build_series_key('CAL', 'NEW', '');

$wpdb->tables[$sheets] = array(
    array('id' => 10, 'title' => '5th Grade Art', 'description' => '', 'is_template' => 1, 'template_id' => 0, 'pta_event_id' => 0, 'series_key' => $old_key, 'status' => 'open', 'event_location' => 'Art Room', 'grade' => '5', 'teacher' => 'Neibauer', 'outlook_calendar_id' => 'CAL', 'created_by' => 1),
    array('id' => 11, 'title' => '5th Grade Art', 'is_template' => 0, 'template_id' => 10, 'pta_event_id' => 701, 'status' => 'open'),
    array('id' => 12, 'title' => '5th Grade Art', 'is_template' => 0, 'template_id' => 10, 'pta_event_id' => 702, 'status' => 'open'),
    array('id' => 20, 'title' => 'Other one-off', 'is_template' => 0, 'template_id' => 0, 'pta_event_id' => 801, 'status' => 'open'),
);
$wpdb->tables[$activities] = array(
    array('id' => 1, 'sheet_id' => 10, 'name' => 'Volunteer', 'description' => '', 'spots_needed' => 7, 'slot_start' => '', 'slot_end' => '', 'sort_order' => 0),
    array('id' => 2, 'sheet_id' => 11, 'name' => 'Volunteer'),
    array('id' => 3, 'sheet_id' => 12, 'name' => 'Volunteer'),
);
$wpdb->tables[$signups] = array(
    array('id' => 1, 'activity_id' => 3, 'user_id' => 658),
);

$t = new TestRunner('Volunteer series reconnect');

$template = Azure_Volunteer_Signup::get_sheet(10);

$health = Azure_Volunteer_Signup::series_health($template, '2026-09-28');
$t->equals(1, $health['in_series'], 'the old series has one upcoming date');
$t->equals(1, $health['with_sheet'], 'that date has a sheet');

$template->series_key = $new_key;
$broken = Azure_Volunteer_Signup::series_health($template, '2026-09-28');
$t->equals(3, $broken['missing'], 'pointed at the new series, three upcoming dates have no sheet');

$plan = Azure_Volunteer_Signup::plan_series_reconnect($template, $new_key);
$t->equals(3, count($plan['add']), 'preview adds a sheet for each date in the new series');
$t->equals(2, count($plan['remove']), 'preview deletes both sheets from the old series');
$t->equals(0, count($plan['keep']), 'nothing is kept');
$t->equals(1, $plan['signups'], 'preview counts the signup that will be lost');
$t->check(!in_array(20, $plan['remove'], true), 'a one-off sheet on a series event is never touched');

$wpdb->update($sheets, array('series_key' => $new_key), array('id' => 10));
$result = Azure_Volunteer_Signup::reconnect_series(Azure_Volunteer_Signup::get_sheet(10));
$t->equals(3, $result['added'], 'reconnect adds three sheets');
$t->equals(2, $result['removed'], 'reconnect deletes two sheets');
$t->equals(null, Azure_Volunteer_Signup::get_sheet(11), 'the past old-series sheet is gone');
$t->equals(null, Azure_Volunteer_Signup::get_sheet(12), 'the upcoming old-series sheet is gone');
$t->equals(0, count($wpdb->tables[$signups]), 'its signup is gone');

$linked = array();
foreach (Azure_Volunteer_Signup::instances_for_template(10) as $row) {
    $linked[] = (int) $row->pta_event_id;
}
sort($linked);
$t->equals(array(801, 802, 803), $linked, 'the series now has one sheet per new date');
$t->check(Azure_Volunteer_Signup::get_sheet(20) !== null, 'the one-off sheet stays');
$t->equals(0, Azure_Volunteer_Signup::series_health(Azure_Volunteer_Signup::get_sheet(10), '2026-09-28')['missing'], 'no dates are missing afterwards');

$again = Azure_Volunteer_Signup::reconnect_series(Azure_Volunteer_Signup::get_sheet(10));
$t->equals(0, $again['added'] + $again['removed'], 'running it again changes nothing');

$src = file_get_contents(dirname(__DIR__) . '/Azure Plugin/admin/volunteer-page.php');
$t->check(strpos($src, 'data-reconnect="1"') !== false, 'a broken series row offers Reconnect');
$t->check(strpos($src, "action: 'azure_volunteer_series_preview'") !== false, 'picking another series previews the change before saving');

exit($t->finish() === 0 ? 0 : 1);
