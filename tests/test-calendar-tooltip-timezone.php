<?php
/**
 * #20: calendar hover text and grid times follow the site timezone.
 *
 * FullCalendar runs without a timezone plugin, so for a named zone it shows
 * the wall-clock digits of each event string and ignores the offset. A
 * Graph event that arrived as 17:00+00:00 (Graph answers in UTC when the
 * site uses UTC or a raw offset like UTC-7) showed "5:00pm" for a 10 AM
 * Pacific meeting. Events are now sent as site wall clock with a
 * server-formatted hover label.
 *
 * Run: php tests/test-calendar-tooltip-timezone.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $cb) { return true; }
}
if (!function_exists('wp_date')) {
    function wp_date($format, $ts = null, $tz = null) {
        $d = new DateTime('@' . (int) ($ts ?? time()));
        $d->setTimezone($tz instanceof DateTimeZone ? $tz : wp_timezone());
        return $d->format($format);
    }
}
if (!function_exists('esc_js')) {
    function esc_js($s) { return addslashes((string) $s); }
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-calendar-shortcode.php';

$t = new TestRunner('Calendar tooltip timezone');
$cs = 'Azure_Calendar_Shortcode';
$la = new DateTimeZone('America/Los_Angeles');

// Wall clock conversion
$t->equals('2026-10-12T10:00:00', $cs::to_wall_clock('2026-10-12T17:00:00+00:00', $la), 'a UTC Graph time becomes 10:00 Pacific');
$t->equals('2026-10-12T10:00:00', $cs::to_wall_clock('2026-10-12T17:00:00Z', $la), 'a Z time becomes 10:00 Pacific');
$t->equals('2026-10-12T10:00:00', $cs::to_wall_clock('2026-10-12T17:00:00.0000000Z', $la), 'Graph fractional seconds are accepted');
$t->equals('2026-10-12T10:00:00', $cs::to_wall_clock('2026-10-12T10:00:00-07:00', $la), 'a Pacific time is unchanged');
$t->equals('2026-10-12T10:00:00', $cs::to_wall_clock('2026-10-12T13:00:00-04:00', $la), 'an Eastern time becomes Pacific');
$t->equals('2026-10-12T10:00:00', $cs::to_wall_clock('2026-10-12 10:00:00', $la), 'offset-free pta_event times are already site-local');
$t->equals('', $cs::to_wall_clock('', $la), 'empty stays empty');

// DST: US clocks fall back on Sunday Nov 1, 2026
$t->equals('2026-10-31T10:00:00', $cs::to_wall_clock('2026-10-31T17:00:00Z', $la), '17:00Z the day before is 10 AM PDT');
$t->equals('2026-11-02T09:00:00', $cs::to_wall_clock('2026-11-02T17:00:00Z', $la), '17:00Z after the change is 9 AM PST');
$t->equals('2026-03-09T10:00:00', $cs::to_wall_clock('2026-03-09T17:00:00Z', $la), 'spring forward (Mar 8) is handled too');

// Hover label
WP_Shim::$options['time_format'] = 'g:i a';
$t->equals('10:00 am – 11:30 am', $cs::time_label('2026-10-12T17:00:00+00:00', '2026-10-12T18:30:00+00:00', $la), 'the hover label is Pacific, not UTC');
$t->equals('10:00 am', $cs::time_label('2026-10-12T17:00:00Z', '2026-10-12T17:00:00Z', $la), 'a zero-length event shows one time');
$t->equals('Oct 12, 8:00 pm – Oct 13, 2:00 am', $cs::time_label('2026-10-13T03:00:00Z', '2026-10-13T09:00:00Z', $la), 'an overnight event names both days');
$t->equals('9:00 am – 10:00 am', $cs::time_label('2026-11-02T17:00:00Z', '2026-11-02T18:00:00Z', $la), 'after the DST change the label shifts with the clock');
WP_Shim::$options['time_format'] = 'H:i';
$t->equals('10:00 – 11:00', $cs::time_label('2026-10-12T17:00:00Z', '2026-10-12T18:00:00Z', $la), 'the site time format is used');
unset(WP_Shim::$options['time_format']);

// FullCalendar zone name
$t->equals('America/Los_Angeles', $cs::fullcalendar_timezone_name($la), 'a named site zone is passed through');
$t->equals('UTC', $cs::fullcalendar_timezone_name(new DateTimeZone('-07:00')), 'a raw offset becomes UTC (strings are wall clock anyway)');
$t->equals('UTC', $cs::fullcalendar_timezone_name(new DateTimeZone('UTC')), 'UTC stays UTC');

// Display zone
WP_Shim::$timezone = 'America/Los_Angeles';
$t->equals('America/Los_Angeles', $cs::display_timezone()->getName(), 'the site timezone is the default');
$t->equals('America/New_York', $cs::display_timezone('America/New_York')->getName(), 'an explicit timezone="" attribute wins');
$t->equals('America/Los_Angeles', $cs::display_timezone('Not/AZone')->getName(), 'a bad attribute falls back to the site timezone');

$off = $cs::now_offset_seconds($la);
$t->check($off === -7 * 3600 || $off === -8 * 3600, 'now offset is PDT or PST');

// Grid rows, for each way a site can be set up
$sc = (new ReflectionClass($cs))->newInstanceWithoutConstructor();
$format = new ReflectionMethod($cs, 'format_events_for_calendar');
$format->setAccessible(true);
$script = new ReflectionMethod($cs, 'get_calendar_script');
$script->setAccessible(true);

$graph_utc = array(
    'id' => 'g1', 'title' => 'Board meeting', 'allDay' => false,
    'start' => '2026-10-12T17:00:00+00:00', 'end' => '2026-10-12T18:00:00+00:00',
);
$pta_local = array(
    'id' => 'p1', 'title' => 'Book fair', 'allDay' => false,
    'start' => '2026-10-12T10:00:00-07:00', 'end' => '2026-10-12T11:00:00-07:00',
);
$all_day = array(
    'id' => 'a1', 'title' => 'No school', 'allDay' => true,
    'start' => '2026-11-11T00:00:00-08:00', 'end' => '2026-11-12T00:00:00-08:00',
);

foreach (array('America/Los_Angeles', 'UTC', '-07:00') as $site_tz) {
    WP_Shim::$timezone = $site_tz;
    $site = new DateTimeZone($site_tz);
    $rows = $format->invoke($sc, array($graph_utc, $pta_local, $all_day), $cs::display_timezone());
    $expect = $cs::to_wall_clock('2026-10-12T17:00:00Z', $site);
    $t->equals($expect, $rows[0]['start'], "site tz {$site_tz}: Graph event start is site wall clock with no offset");
    $t->check(!preg_match('/(Z|[+-]\d{2}:\d{2})$/', $rows[0]['start'] . $rows[1]['start']), "site tz {$site_tz}: no offsets reach FullCalendar");
    $t->equals($cs::time_label('2026-10-12T17:00:00Z', '2026-10-12T18:00:00Z', $site), $rows[0]['extendedProps']['timeLabel'], "site tz {$site_tz}: hover label matches the site clock");
    $t->equals('2026-11-11', $rows[2]['start'], "site tz {$site_tz}: all-day events stay date-only");
    $t->equals('', $rows[2]['extendedProps']['timeLabel'], "site tz {$site_tz}: all-day events have no time in the hover");

    $js = $script->invoke($sc, 'cal1', $rows, array(
        'timezone' => '', 'view' => 'month', 'slot_min_time' => '08:00:00', 'slot_max_time' => '18:00:00',
        'slot_duration' => '00:30:00', 'show_weekends' => true, 'first_day' => 0, 'height' => '600px',
    ));
    $t->check(strpos($js, "timeZone: '" . $cs::fullcalendar_timezone_name($site) . "'") !== false, "site tz {$site_tz}: FullCalendar gets a zone it accepts");
    $t->check(strpos($js, 'now: function() { return new Date(Date.now() + (' . ($site->getOffset(new DateTime('now', new DateTimeZone('UTC'))) * 1000) . '))') !== false, "site tz {$site_tz}: today follows the site clock");
    if ($site_tz === 'America/Los_Angeles' && ($out = getenv('PTA_CAL_SCRIPT_OUT'))) {
        file_put_contents($out, $js);
    }
}
WP_Shim::$timezone = 'America/Los_Angeles';
$t->equals('2026-10-12T10:00:00', $format->invoke($sc, array($graph_utc), $la)[0]['start'], 'Pacific site: the 10 AM meeting is 10:00');
$t->equals('10:00 am – 11:00 am', $format->invoke($sc, array($graph_utc), $la)[0]['extendedProps']['timeLabel'], 'Pacific site: the hover says 10:00 am – 11:00 am');

$src = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-calendar-shortcode.php');
$t->check(strpos($src, "if (label && !withDate) {") !== false, 'the hover uses the server label');
$t->check(strpos($src, "date(\$atts['time_format'], \$start_time)") === false, 'the events list no longer formats times in PHP default (UTC)');

exit($t->finish() > 0 ? 1 : 0);
