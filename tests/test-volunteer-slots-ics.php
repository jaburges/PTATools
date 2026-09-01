<?php
/**
 * Volunteer timed slots and Pacific ICS invites.
 *
 * Run: php tests/test-volunteer-slots-ics.php
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

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-volunteer-signup.php';

$t = new TestRunner('Volunteer slots and ICS');

$t->equals(
    '2026-10-17 17:00:00',
    Azure_Volunteer_Signup::normalize_slot_datetime('17:00', '2026-10-17 09:00:00'),
    'time-of-day pins to the event date'
);
$t->equals(
    '2026-10-17 18:30:00',
    Azure_Volunteer_Signup::normalize_slot_datetime('2026-10-17T18:30', 'ignored'),
    'full datetime is kept'
);
$t->equals('', Azure_Volunteer_Signup::normalize_slot_datetime('17:00', ''), 'time without a date is empty');

$sheet = (object) array(
    'id' => 1,
    'title' => 'Fall Carnival',
    'event_date' => '2026-10-17 12:00:00',
    'event_location' => 'Wilder Gym',
);
$act = (object) array(
    'id' => 9,
    'name' => 'Set up',
    'description' => 'Tables and signs',
    'slot_start' => '2026-10-17 17:00:00',
    'slot_end' => '2026-10-17 18:00:00',
);

$bounds = Azure_Volunteer_Signup::slot_bounds($sheet, $act);
$t->equals('2026-10-17 17:00:00', $bounds['start'], 'slot start from the activity');
$t->equals('2026-10-17 18:00:00', $bounds['end'], 'slot end from the activity');
$t->equals('5:00 PM – 6:00 PM', Azure_Volunteer_Signup::slot_time_label($sheet, $act), 'Pacific wall-clock label');

$no_slot = (object) array('id' => 2, 'name' => 'Help', 'slot_start' => '', 'slot_end' => '');
$fallback = Azure_Volunteer_Signup::slot_bounds($sheet, $no_slot);
$t->equals('2026-10-17 12:00:00', $fallback['start'], 'missing slot falls back to the sheet date');

$user = (object) array('ID' => 44);
$ics = Azure_Volunteer_Signup::build_slot_ics($sheet, $act, $user);
$t->check(strpos($ics, 'BEGIN:VCALENDAR') !== false, 'ICS is a calendar');
$t->check(strpos($ics, 'DTSTART:20261018T000000Z') !== false, '5pm Pacific is 00:00 UTC the next day');
$t->check(strpos($ics, 'DTEND:20261018T010000Z') !== false, '6pm Pacific is 01:00 UTC the next day');
$t->check(strpos($ics, 'SUMMARY:Fall Carnival: Set up') !== false, 'ICS summary includes event and activity');
$t->check(strpos($ics, 'LOCATION:Wilder Gym') !== false, 'ICS includes the location');
$t->check(strpos($ics, 'UID:pta-volunteer-9-44@') !== false, 'ICS uid is per slot and user');

exit($t->finish() === 0 ? 0 : 1);
