<?php
/**
 * [up-next include-volunteer-spaces="true"] shows filled/needed.
 *
 * Run: php tests/test-upcoming-volunteer-spaces.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!function_exists('date_i18n')) {
    function date_i18n($format, $timestamp = false) {
        return date($format, $timestamp ? $timestamp : time());
    }
}
if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback) {
        return true;
    }
}
if (!function_exists('esc_url')) {
    function esc_url($url) {
        return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8');
    }
}

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once AZURE_PLUGIN_PATH . 'includes/class-volunteer-signup.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-upcoming-module.php';

$wpdb = new Fake_WPDB();
$sheets = 'wp_azure_volunteer_sheets';
$activities = 'wp_azure_volunteer_activities';
$signups = 'wp_azure_volunteer_signups';

$wpdb->tables[$sheets] = array(
    array('id' => 1, 'pta_event_id' => 50, 'is_template' => 0, 'status' => 'open'),
    array('id' => 6, 'pta_event_id' => 50, 'is_template' => 0, 'status' => 'open'),
    array('id' => 2, 'pta_event_id' => 51, 'is_template' => 0, 'status' => 'open'),
    array('id' => 3, 'pta_event_id' => 52, 'is_template' => 0, 'status' => 'open'),
    array('id' => 4, 'pta_event_id' => 53, 'is_template' => 1, 'status' => 'open'),
    array('id' => 5, 'pta_event_id' => 54, 'is_template' => 0, 'status' => 'trashed'),
    array('id' => 7, 'pta_event_id' => 55, 'is_template' => 0, 'status' => 'closed'),
);
$wpdb->tables[$activities] = array(
    array('id' => 10, 'sheet_id' => 1, 'spots_needed' => 2),
    array('id' => 13, 'sheet_id' => 6, 'spots_needed' => 1),
    array('id' => 11, 'sheet_id' => 2, 'spots_needed' => 2),
    array('id' => 12, 'sheet_id' => 3, 'spots_needed' => 2),
    array('id' => 14, 'sheet_id' => 4, 'spots_needed' => 4),
    array('id' => 15, 'sheet_id' => 5, 'spots_needed' => 3),
    array('id' => 16, 'sheet_id' => 7, 'spots_needed' => 1),
);
$wpdb->tables[$signups] = array(
    array('id' => 1, 'activity_id' => 10, 'user_id' => 9),
    array('id' => 2, 'activity_id' => 13, 'user_id' => 9),
    array('id' => 3, 'activity_id' => 12, 'user_id' => 9),
    array('id' => 4, 'activity_id' => 12, 'user_id' => 8),
    array('id' => 5, 'activity_id' => 16, 'user_id' => 9),
    array('id' => 6, 'activity_id' => 14, 'user_id' => 9),
);

$t = new TestRunner('Upcoming volunteer spaces');

$partial = Azure_Volunteer_Signup::fill_for_event(50);
$t->equals(3, $partial['spots_needed'], 'sheets on one event add their spots');
$t->equals(2, $partial['spots_filled'], 'sheets on one event add their signups');

$empty = Azure_Volunteer_Signup::fill_for_event(51);
$t->equals(0, $empty['spots_filled'], 'an open sheet with no signups is empty');
$t->equals(2, $empty['spots_needed'], 'empty sheet still has spots needed');

$full = Azure_Volunteer_Signup::fill_for_event(52);
$t->equals(2, $full['spots_filled'], 'a full sheet counts every signup');

$t->equals(null, Azure_Volunteer_Signup::fill_for_event(53), 'a template is not an attached signup');
$t->equals(null, Azure_Volunteer_Signup::fill_for_event(54), 'a trashed sheet is ignored');
$t->equals(null, Azure_Volunteer_Signup::fill_for_event(99), 'an event with no sheet has no count');

$closed = Azure_Volunteer_Signup::fill_for_event(55);
$t->equals(1, $closed['spots_filled'], 'a closed sheet still reports its fill');

$t->check(strpos(Azure_Upcoming_Module::volunteer_spaces_html(0, 2), 'is-empty') !== false, 'zero signups is red');
$t->check(strpos(Azure_Upcoming_Module::volunteer_spaces_html(0, 2), '>0/2<') !== false, 'zero signups reads 0/2');
$t->check(strpos(Azure_Upcoming_Module::volunteer_spaces_html(1, 2), 'is-partial') !== false, 'a partial signup is yellow');
$t->check(strpos(Azure_Upcoming_Module::volunteer_spaces_html(1, 2), '>1/2<') !== false, 'a partial signup reads 1/2');
$t->check(strpos(Azure_Upcoming_Module::volunteer_spaces_html(2, 2), 'is-full') !== false, 'a filled signup is green');
$t->check(strpos(Azure_Upcoming_Module::volunteer_spaces_html(2, 2), '>2/2<') !== false, 'a filled signup reads 2/2');
$t->check(strpos(Azure_Upcoming_Module::volunteer_spaces_html(5, 2), '>2/2<') !== false, 'extra signups do not pass the total');
$t->equals('', Azure_Upcoming_Module::volunteer_spaces_html(0, 0), 'no spots needed renders nothing');

$module = new Azure_Upcoming_Module();
$render = new ReflectionMethod('Azure_Upcoming_Module', 'render_events_list');
$event = array(
    'id'         => 50,
    'title'      => 'Oceans',
    'url'        => 'https://example.test/event/oceans',
    'start_date' => '2026-09-23 12:30:00',
    'end_date'   => '2026-09-23 13:30:00',
    'all_day'    => false,
    'online_url' => '',
    'location'   => '',
);
$on = $render->invoke($module, array($event), array(
    'show_time' => true,
    'link_titles' => true,
    'show_join_meeting' => false,
    'show_image' => false,
    'include_volunteer_spaces' => true,
    'empty_message' => 'none',
));
$t->check(strpos($on, 'upcoming-volunteer-spaces is-partial') !== false, 'the event list shows the combined count');
$t->check(strpos($on, '>2/3<') !== false, 'the event list shows filled out of needed');
$t->check(strpos($on, 'upcoming-title-row') !== false, 'the count sits with the title');

$off = $render->invoke($module, array($event), array(
    'show_time' => true,
    'link_titles' => true,
    'show_join_meeting' => false,
    'show_image' => false,
    'include_volunteer_spaces' => false,
    'empty_message' => 'none',
));
$t->check(strpos($off, 'upcoming-volunteer-spaces') === false, 'the count stays hidden unless the attribute is on');

$none = $event;
$none['id'] = 99;
$bare = $render->invoke($module, array($none), array(
    'show_time' => true,
    'link_titles' => true,
    'show_join_meeting' => false,
    'show_image' => false,
    'include_volunteer_spaces' => true,
    'empty_message' => 'none',
));
$t->check(strpos($bare, 'upcoming-volunteer-spaces') === false, 'an event without a signup shows no count');

$docs = file_get_contents(AZURE_PLUGIN_PATH . 'admin/upcoming-page.php');
$t->check(strpos($docs, 'include-volunteer-spaces="true"') !== false, 'Upcoming docs show the attribute');

exit($t->finish());
