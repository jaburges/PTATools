<?php
/**
 * [azure_calendar_events include_signups="true"] shows filled/needed on the list.
 *
 * Run: php tests/test-calendar-event-signups.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $cb) { return true; }
}
if (!function_exists('esc_url')) {
    function esc_url($url) { return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('get_permalink')) {
    function get_permalink($id) { return 'https://example.test/event/' . (int) $id; }
}
if (!function_exists('has_post_thumbnail')) {
    function has_post_thumbnail($id) { return false; }
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-volunteer-signup.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-upcoming-module.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-calendar-shortcode.php';

$wpdb = new Fake_WPDB();
$sheets = 'wp_azure_volunteer_sheets';
$activities = 'wp_azure_volunteer_activities';
$signups = 'wp_azure_volunteer_signups';

$wpdb->tables[$sheets] = array(
    array('id' => 1, 'pta_event_id' => 70, 'is_template' => 0, 'status' => 'open'),
    array('id' => 2, 'pta_event_id' => 71, 'is_template' => 0, 'status' => 'open'),
    array('id' => 3, 'pta_event_id' => 72, 'is_template' => 0, 'status' => 'open'),
);
$wpdb->tables[$activities] = array(
    array('id' => 10, 'sheet_id' => 1, 'spots_needed' => 2),
    array('id' => 11, 'sheet_id' => 2, 'spots_needed' => 2),
    array('id' => 12, 'sheet_id' => 3, 'spots_needed' => 2),
);
$wpdb->tables[$signups] = array(
    array('id' => 1, 'activity_id' => 11, 'user_id' => 9),
    array('id' => 2, 'activity_id' => 12, 'user_id' => 9),
    array('id' => 3, 'activity_id' => 12, 'user_id' => 8),
);

$t = new TestRunner('Calendar list volunteer spots');

$sc = new Azure_Calendar_Shortcode();
$render = new ReflectionMethod($sc, 'render_pta_events_list');

function calendar_list_atts($signups) {
    return array(
        'class' => 'azure-events-list',
        'format' => 'list',
        'show_image' => false,
        'include_signups' => $signups,
        'show_join_meeting' => false,
        'show_location' => false,
        'show_description' => false,
        'date_format' => 'M j, Y',
        'time_format' => 'g:i A',
    );
}

function calendar_post($id, $title) {
    return (object) array('ID' => $id, 'post_title' => $title, 'post_content' => '');
}

$on = $render->invoke($sc, array(
    calendar_post(70, 'WatchDOGS'),
    calendar_post(71, 'WatchDOGS partial'),
    calendar_post(72, 'WatchDOGS full'),
    calendar_post(73, 'No sheet'),
), calendar_list_atts(true));

$t->check(strpos($on, 'upcoming-volunteer-spaces is-empty') !== false && strpos($on, '>0/2<') !== false, 'an empty sheet reads 0/2 in red');
$t->check(strpos($on, 'upcoming-volunteer-spaces is-partial') !== false && strpos($on, '>1/2<') !== false, 'a partial sheet reads 1/2 in amber');
$t->check(strpos($on, 'upcoming-volunteer-spaces is-full') !== false && strpos($on, '>2/2<') !== false, 'a full sheet reads 2/2 in green');
$t->check(substr_count($on, 'upcoming-volunteer-spaces') === 3, 'an event without a signup sheet has no count');

$off = $render->invoke($sc, array(calendar_post(70, 'WatchDOGS')), calendar_list_atts(false));
$t->check(strpos($off, 'upcoming-volunteer-spaces') === false, 'the count stays off unless include_signups is true');

$src = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-calendar-shortcode.php');
$month_start = strpos($src, 'function calendar_shortcode');
$month_end = strpos($src, 'function events_list_shortcode');
$month = substr($src, $month_start, $month_end - $month_start);
$t->check(strpos($month, 'include_signups') === false, 'the month calendar does not read include_signups');

$list_start = strpos($src, 'function render_pta_events_list');
$list_end = strpos($src, 'function single_event_shortcode');
$list = substr($src, $list_start, $list_end - $list_start);
$t->check(strpos($list, 'class-upcoming-module.php') !== false, 'the list loads the spot chip without requiring the up-next shortcode');

exit($t->finish() === 0 ? 0 : 1);
