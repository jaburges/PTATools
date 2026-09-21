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

exit($t->finish());
