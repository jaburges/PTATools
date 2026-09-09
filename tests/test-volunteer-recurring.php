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
    'series:cal123:masterABC',
    Azure_Volunteer_Signup::build_series_key('cal123', 'masterABC', 'Grade 1 Math Adventures'),
    'series master id wins over title'
);

$t->equals(
    'title:cal123:grade 1 math adventures',
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

exit($t->finish() === 0 ? 0 : 1);
