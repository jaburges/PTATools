<?php
/**
 * Recurring volunteer sheet series keys and slot re-dating.
 *
 * Run: php tests/test-volunteer-recurring.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-volunteer-signup.php';

$t = new TestRunner('Volunteer recurring series');

$t->equals(
    Azure_Volunteer_Signup::build_series_key('cal123', 'masterABC', 'ignored'),
    Azure_Volunteer_Signup::build_series_key('cal123', 'masterABC', 'Grade 1 Math Adventures'),
    'series master id wins over title'
);
$long_cal = str_repeat('A', 400);
$long_master = str_repeat('B', 400);
$long_key = Azure_Volunteer_Signup::build_series_key($long_cal, $long_master, 'Math Adventures');
$t->check(strlen($long_key) < 80, 'a long Outlook calendar id still produces a series key that fits the column');
$t->check(
    Azure_Volunteer_Signup::event_matches_series_key($long_key, $long_cal, $long_master, 'Math Adventures'),
    'every date in that series matches the key'
);
$t->check(
    !Azure_Volunteer_Signup::event_matches_series_key($long_key, $long_cal, str_repeat('C', 400), 'Math Adventures'),
    'a different series does not match'
);

$t->equals(
    Azure_Volunteer_Signup::build_series_key('cal123', '', 'Grade 1 Math Adventures'),
    Azure_Volunteer_Signup::build_series_key('cal123', '', 'Grade 1  Math Adventures'),
    'title fallback normalizes whitespace and case'
);

$t->equals(
    '',
    Azure_Volunteer_Signup::build_series_key('', '', ''),
    'empty inputs produce no key'
);

$redated = Azure_Volunteer_Signup::redate_slot_to_event('2026-04-01 09:30:00', '2026-09-15 17:00:00');
$t->equals('2026-09-15 09:30:00', $redated, 'slot time is pinned to the occurrence date');

$from_time = Azure_Volunteer_Signup::normalize_slot_datetime('09:30', '2026-10-02 08:00:00');
$t->equals('2026-10-02 09:30:00', $from_time, 'time-only slots attach to the new event date');

if (!function_exists('current_user_can')) {
    function current_user_can($cap) {
        $caps = isset($GLOBALS['pta_test_caps']) ? $GLOBALS['pta_test_caps'] : array();
        return !empty($caps[$cap]);
    }
}
$GLOBALS['pta_test_caps'] = array('access_pta_tools' => true);
$t->check(Azure_Volunteer_Signup::user_can_manage_sheets(), 'an Azure AD user with PTA Tools access can save a sign-up sheet');
$GLOBALS['pta_test_caps'] = array('manage_options' => true);
$t->check(Azure_Volunteer_Signup::user_can_manage_sheets(), 'an administrator can save a sign-up sheet');
$GLOBALS['pta_test_caps'] = array('read' => true);
$t->check(!Azure_Volunteer_Signup::user_can_manage_sheets(), 'a subscriber cannot manage sign-up sheets');

$page = file_get_contents(dirname(__DIR__) . '/Azure Plugin/admin/volunteer-page.php');
$t->check(strpos($page, 'id="azure-vs-series-events"') !== false, 'recurring sign-up picker lists Outlook series');
$t->check(strpos($page, 'data-recurring="1"') !== false, 'series options are marked recurring');
$t->check(strpos($page, "$('#azure-vs-single-events').toggle(!on)") !== false, 'recurring mode hides one-off events');

exit($t->finish() === 0 ? 0 : 1);
