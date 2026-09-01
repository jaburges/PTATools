<?php
/**
 * Volunteer sheets can be assigned to a pta_event or create one.
 *
 * Run: php tests/test-volunteer-event-assign.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

if (!function_exists('absint')) {
    function absint($maybe) {
        return abs((int) $maybe);
    }
}

if (!function_exists('wp_insert_post')) {
    function wp_insert_post($data, $wp_error = false) {
        $id = isset($GLOBALS['wp_test_next_post_id']) ? (int) $GLOBALS['wp_test_next_post_id'] : 501;
        $GLOBALS['wp_test_next_post_id'] = $id + 1;
        $GLOBALS['wp_test_inserted_posts'][] = $data;
        return $id;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('current_time')) {
    function current_time($type) {
        return '2026-09-15 17:00:00';
    }
}

if (!function_exists('azure_wp_timezone_string')) {
    function azure_wp_timezone_string() {
        return 'America/Los_Angeles';
    }
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-volunteer-signup.php';

$t = new TestRunner('Volunteer event assignment');

WP_Shim::reset();
$GLOBALS['wp_test_inserted_posts'] = array();
$GLOBALS['wp_test_next_post_id'] = 501;

$t->equals(0, Azure_Volunteer_Signup::resolve_assigned_event_id(false, 88, 'Carnival'), 'unchecked assign stores no event');
$t->equals(42, Azure_Volunteer_Signup::resolve_assigned_event_id(true, '42', 'Carnival'), 'existing event id is kept');
$t->equals(0, Azure_Volunteer_Signup::resolve_assigned_event_id(true, '0', 'Carnival'), 'assign with no selection is empty');

$created = Azure_Volunteer_Signup::resolve_assigned_event_id(
    true,
    '__new__',
    'Fall Carnival',
    '2026-10-17 17:00:00',
    'Wilder Gym'
);
$t->equals(501, $created, 'create new event returns the new post id');
$t->check(!empty($GLOBALS['wp_test_inserted_posts'][0]), 'wp_insert_post was called');
$first = $GLOBALS['wp_test_inserted_posts'][0];
$t->equals('pta_event', $first['post_type'], 'new post is a pta_event');
$t->equals('publish', $first['post_status'], 'new event is published');
$t->equals('Fall Carnival', $first['post_title'], 'new event uses the signup title');
$t->equals('2026-10-17 17:00:00', WP_Shim::$post_meta[501]['_EventStartDate'], 'event start comes from the sheet date');
$t->equals('Wilder Gym', WP_Shim::$post_meta[501]['_EventVenue'], 'event venue comes from the sheet location');
$t->equals('America/Los_Angeles', WP_Shim::$post_meta[501]['_EventTimezone'], 'new event is Pacific time');

$t->equals(0, Azure_Volunteer_Signup::create_event_from_sheet_fields(''), 'blank title does not create an event');

exit($t->finish() === 0 ? 0 : 1);
