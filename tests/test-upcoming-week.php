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

function pta_as_of($send_date, $today_ymd, $tz) {
    $today = new DateTime($today_ymd . ' 15:30:00', $tz);
    return Azure_Upcoming_Module::now_next_reference_date($send_date, $today)->format('Y-m-d');
}

$t->equals('2026-10-04', pta_as_of('10/4/26', '2026-10-03', $tz), 'Saturday test with send-date 10/4/26 renders as Sunday 10/4');
$t->equals('2026-10-04', pta_as_of('10/4/2026', '2026-10-03', $tz), 'four-digit year works');
$t->equals('2026-10-04', pta_as_of('2026-10-04', '2026-10-03', $tz), 'ISO date works');
$t->equals('2026-10-04', pta_as_of(' 10/04/26 ', '2026-10-03', $tz), 'padded and zero-filled dates work');
$t->equals('2026-10-04', pta_as_of('10/4/26', '2026-10-04', $tz), 'on the send day itself today is used');
$t->equals('2026-10-05', pta_as_of('10/4/26', '2026-10-05', $tz), 'a late send uses the real date');
$t->equals('2026-10-11', pta_as_of('10/4/26', '2026-10-11', $tz), 'a copy reused next week ignores the stale send-date');
$t->equals('2026-10-03', pta_as_of('', '2026-10-03', $tz), 'no send-date means today');
$t->equals('2026-10-03', pta_as_of('next sunday', '2026-10-03', $tz), 'unreadable send-date falls back to today');
$t->equals('2026-10-03', pta_as_of('2/30/26', '2026-10-03', $tz), 'impossible date falls back to today');

$as_of = Azure_Upcoming_Module::now_next_reference_date('10/4/26', new DateTime('2026-10-03 15:30:00', $tz));
list($s0, $e0) = Azure_Upcoming_Module::compute_week_boundaries('sunday', 0, $as_of);
list($s1, $e1) = Azure_Upcoming_Module::compute_week_boundaries('sunday', 1, $as_of);
$t->equals(array('2026-10-04', '2026-10-10'), array($s0->format('Y-m-d'), $e0->format('Y-m-d')), 'Saturday test: This Week is the Sunday send week 10/4–10/10');
$t->equals(array('2026-10-11', '2026-10-17'), array($s1->format('Y-m-d'), $e1->format('Y-m-d')), 'Saturday test: Next Week is 10/11–10/17');

$t->check(strpos($src, "'send-date'          => ''") !== false, '[nl-now-next] accepts send-date');
$t->check(strpos($src, "isset(\$atts['send_date'])") !== false, '[nl-now-next] accepts send_date as an alias');
$t->check(strpos($src, "now_next_reference_date(\$atts['send-date']") !== false, '[nl-now-next] uses send-date for its weeks');

$editor_js = file_get_contents(dirname(__DIR__) . '/Azure Plugin/js/newsletter-editor.js');
$t->check(strpos($editor_js, "name: 'send_date'") !== false, 'Now and Next block has a Send date setting');
$t->check(strpos($editor_js, "sc += ' send-date=\"' + sendDate + '\"'") !== false, 'changing a setting keeps send-date in the shortcode');
$t->check(strpos($editor_js, "attr('send-date') || attr('send_date')") !== false, 'a typed send-date is read back into the setting');

exit($t->finish());
