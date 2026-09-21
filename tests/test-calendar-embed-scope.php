<?php
/**
 * A single-calendar [azure_calendar] embed must query only that Outlook calendar.
 *
 * Run: php tests/test-calendar-embed-scope.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-event-cpt.php';

$t = new TestRunner('Calendar embed scope');

$math_id = 'AQMkAGY0ZjRhNTRhLWNkMjctNDM5Zi05ZTk4LTlmYjgyOWVlADllMWMARgAAA63j-M0FYjJHgwG_LlIwjTQHALd7AdtyVRFKjBYjC4ApVTYAAAIBBgAAALd7AdtyVRFKjBYjC4ApVTYAAAIRDgAAAA==';

$scoped = Azure_Event_CPT::calendar_scope_clauses($math_id);
$t->equals(1, count($scoped), 'a declared calendar id adds one meta clause');
$t->equals('_outlook_calendar_id', $scoped[0]['key'], 'clause matches the Outlook calendar id meta');
$t->equals($math_id, $scoped[0]['value'], 'clause value is the shortcode id');
$t->equals('=', $scoped[0]['compare'], 'clause is an exact match');

$t->equals(array(), Azure_Event_CPT::calendar_scope_clauses(''), 'an embed with no id stays site-wide');
$t->equals(array(), Azure_Event_CPT::calendar_scope_clauses('   '), 'whitespace-only id stays site-wide');

$other = Azure_Event_CPT::calendar_scope_clauses('calendar-at-wilderptsa');
$t->check($other[0]['value'] !== $math_id, 'a different calendar id does not match the math calendar');

$shortcode = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-calendar-shortcode.php');
$t->check(strpos($shortcode, 'fetch_pta_events($start_date, $end_date, $calendar_id)') !== false, 'month embed passes the shortcode calendar id into the PTA query');
$t->check(strpos($shortcode, 'render_subscribe_bar($container_id, $calendar_id)') !== false, 'subscribe bar on a scoped embed uses that calendar');
$t->check(strpos($shortcode, 'Azure_Event_CPT::calendar_scope_clauses($calendar_id)') !== false, 'month query uses the shared calendar scope');

$cpt = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-event-cpt.php');
$t->check(strpos($cpt, "\$args['calendar_id'] = \$calendar_id;") !== false, 'scoped ICS feed URL carries calendar_id');
$t->check(strpos($cpt, 'build_ics_feed($calendar_id)') !== false, 'ICS builder receives the calendar id');

exit($t->finish() === 0 ? 0 : 1);
