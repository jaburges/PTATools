<?php
/**
 * Sunday–Saturday week boundaries for [up-next] and [nl-now-next].
 *
 * Run: php tests/test-upcoming-week.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-upcoming-module.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-calendar-mapping-manager.php';

$t = new TestRunner('Upcoming week boundaries');

$src = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-upcoming-module.php');
$t->check(preg_match("/'week-start'\\s+=>\\s+'sunday'/", $src) === 1, '[up-next] defaults to a Sunday week');
$t->check(substr_count($src, "'week-start'         => 'sunday'") === 1, '[nl-now-next] defaults to a Sunday week');
$t->check(strpos($src, "'week-start'          => 'monday'") === false, '[up-next] no longer defaults to Monday');
$t->check(strpos($src, "'week-start'         => 'monday'") === false, '[nl-now-next] no longer defaults to Monday');

$tz = new DateTimeZone('America/Los_Angeles');

function pta_week_range($week_start, $offset, $ymd, $tz) {
    $today = new DateTime($ymd . ' 12:00:00', $tz);
    list($start, $end) = Azure_Upcoming_Module::compute_week_boundaries($week_start, $offset, $today);
    return array($start->format('Y-m-d'), $end->format('Y-m-d'));
}

// Sunday 20 Sep 2026 — the reported case: this week must start today, not last Monday.
$t->equals(array('2026-09-20', '2026-09-26'), pta_week_range('sunday', 0, '2026-09-20', $tz), 'Sunday: this week is 9/20–9/26');
$t->equals(array('2026-09-27', '2026-10-03'), pta_week_range('sunday', 1, '2026-09-20', $tz), 'Sunday: next week is 9/27–10/3');
$t->equals(array('2026-09-14', '2026-09-20'), pta_week_range('monday', 0, '2026-09-20', $tz), 'week-start=monday still yields Mon–Sun when asked');

$t->equals(array('2026-09-20', '2026-09-26'), pta_week_range('sunday', 0, '2026-09-23', $tz), 'Wednesday still sits in the Sunday-started week');
$t->equals(array('2026-09-20', '2026-09-26'), pta_week_range('sunday', 0, '2026-09-26', $tz), 'Saturday is the last day of the Sunday-started week');
$t->equals(array('2026-09-27', '2026-10-03'), pta_week_range('sunday', 0, '2026-09-27', $tz), 'the following Sunday opens next week');

$t->equals(array('2026-09-20', '2026-09-26'), pta_week_range('', 0, '2026-09-20', $tz), 'blank week-start falls back to Sunday');

$cal = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-calendar-shortcode.php');
$t->check(strpos($cal, "'first_day' => 0") !== false, 'embedded calendar week still starts Sunday');

$archive = file_get_contents(dirname(__DIR__) . '/Azure Plugin/templates/archive-pta_event.php');
$t->check(strpos($archive, "foreach (array('Sun','Mon','Tue','Wed','Thu','Fri','Sat')") !== false, 'events archive grid is Sunday-first');

$docs = file_get_contents(dirname(__DIR__) . '/Azure Plugin/admin/upcoming-page.php');
$t->check(strpos($docs, 'week-start="sunday"') !== false, 'Upcoming docs example uses sunday');
$t->check(strpos($docs, '<code>"sunday"</code>') !== false, 'Upcoming docs default is sunday');
$t->check(strpos($docs, 'exclude-calendars') !== false, 'Upcoming docs mention exclude-calendars');

$mappings = array(
    (object) array(
        'outlook_calendar_name' => 'Staff',
        'outlook_calendar_id'   => 'cal-staff',
        'category_name'         => 'Staff Events',
    ),
    (object) array(
        'outlook_calendar_name' => 'Wilder PTSA',
        'outlook_calendar_id'   => 'cal-ptsa',
        'category_name'         => 'PTSA',
    ),
);
$resolved = Azure_Upcoming_Module::resolve_excludes('Staff', '', $mappings);
$t->equals(array('cal-staff'), $resolved['calendar_ids'], 'exclude-calendars Staff maps to the Outlook calendar id');
$t->check(in_array('Staff Events', $resolved['categories'], true), 'exclude-calendars Staff also hides the mapped category');
$t->check(in_array('Staff', $resolved['categories'], true), 'the typed name is kept as a category match');

$by_cat = Azure_Upcoming_Module::resolve_excludes('', 'Private', $mappings);
$t->equals(array(), $by_cat['calendar_ids'], 'an unmatched category does not invent a calendar id');
$t->equals(array('Private'), $by_cat['categories'], 'exclude-categories still works as a name list');

$merged = Azure_Upcoming_Module::resolve_excludes('Wilder PTSA', 'Private', $mappings);
$t->check(in_array('cal-ptsa', $merged['calendar_ids'], true), 'calendar and category excludes merge');
$t->check(in_array('Private', $merged['categories'], true), 'merged excludes keep the extra category');

$t->equals(array('Staff', 'Private'), Azure_Upcoming_Module::parse_csv_names(' Staff, Private '), 'csv names trim empties');

$shared = array(
    (object) array(
        'outlook_calendar_name' => 'Calendar',
        'outlook_calendar_id'   => 'cal-ptsa',
        'mailbox_email'         => 'calendar@wilderptsa.net',
        'category_name'         => 'PTA Events',
        'display_name'          => '',
    ),
    (object) array(
        'outlook_calendar_name' => 'Calendar',
        'outlook_calendar_id'   => 'cal-math',
        'mailbox_email'         => 'mathadventures@wilderptsa.net',
        'category_name'         => 'Math Adventures',
        'display_name'          => '',
    ),
    (object) array(
        'outlook_calendar_name' => 'Calendar',
        'outlook_calendar_id'   => 'cal-art',
        'mailbox_email'         => 'artcalendar@wilderptsa.net',
        'category_name'         => 'Art Calendar',
        'display_name'          => 'Art Calendar',
    ),
);
$named = Azure_Upcoming_Module::resolve_excludes('Math Adventures', '', $shared);
$t->equals(array('cal-math'), $named['calendar_ids'], 'exclude-calendars Math Adventures hits the math mailbox, not all Calendars');
$generic = Azure_Upcoming_Module::resolve_excludes('Calendar', '', $shared);
$t->equals(array(), $generic['calendar_ids'], 'shared Outlook name Calendar does not exclude every mapping');
$display = Azure_Upcoming_Module::resolve_excludes('Art Calendar', '', $shared);
$t->equals(array('cal-art'), $display['calendar_ids'], 'exclude-calendars uses the mapping display name');
$renamed = $shared;
$renamed[1]->display_name = 'Math Calendar';
$by_display = Azure_Upcoming_Module::resolve_excludes('Math Calendar', '', $renamed);
$t->equals(array('cal-math'), $by_display['calendar_ids'], 'a custom mapping name is usable in exclude-calendars');

exit($t->finish());
