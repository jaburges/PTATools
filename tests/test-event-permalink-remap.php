<?php
/**
 * /event/<slug> must resolve to pta_event after TEC is gone.
 *
 * Run: php tests/test-event-permalink-remap.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

if (!function_exists('post_type_exists')) {
    function post_type_exists($type) {
        return $type === 'pta_event';
    }
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-event-cpt.php';

$t = new TestRunner('Event permalink remap');

$t->check(Azure_Event_CPT::should_own_event_urls(), 'we own /event/ when TEC is not registered');

$single = Azure_Event_CPT::remap_event_query_vars(array('pagename' => 'event/first-day'), '/event/first-day-of-school-half-day-conferences-begin/');
$t->equals('pta_event', $single['post_type'], 'single event path becomes pta_event');
$t->equals('first-day-of-school-half-day-conferences-begin', $single['name'], 'slug is taken from the path');
$t->check(!isset($single['pagename']), 'page path is cleared so it is not a 404 child page');
$t->check(!isset($single['tribe_events']), 'legacy TEC query var is cleared');

$archive = Azure_Event_CPT::remap_event_query_vars(array('pagename' => 'events'), '/events/');
$t->equals('pta_event', $archive['post_type'], 'events archive becomes pta_event');

$other = Azure_Event_CPT::remap_event_query_vars(array('pagename' => 'carnival'), '/carnival/');
$t->equals('carnival', $other['pagename'], 'unrelated pages are left alone');

exit($t->finish() === 0 ? 0 : 1);
