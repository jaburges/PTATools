<?php
/**
 * Event store: stay on TEC, migrate (dual-write), or use PTA Tools.
 *
 * Run: php tests/test-event-store.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-event-cpt.php';

$t = new TestRunner('Event store TEC compatibility');

function azure_event_store_reset() {
    WP_Shim::reset();
    Azure_Event_CPT::$tec_active_override = null;
}

azure_event_store_reset();
$t->equals('pta', Azure_Event_CPT::get_owner(), 'unset owner without TEC is pta');
$t->equals('pta', Azure_Event_CPT::get_data_source(), 'unset reader follows pta owner');
$t->equals(array('pta_event'), Azure_Event_CPT::write_post_types(), 'PTA-only writes pta_event');
$t->equals('pta_event_category', Azure_Event_CPT::write_taxonomy(), 'PTA-only taxonomy');
$t->check(Azure_Event_CPT::is_pta_owner_active(), 'PTA-only registers pta_event');
$t->check(Azure_Event_CPT::should_own_event_urls(), 'PTA-only owns /event/');

azure_event_store_reset();
Azure_Event_CPT::$tec_active_override = true;
$t->equals('tec', Azure_Event_CPT::get_owner(), 'unset owner with TEC active stays on TEC');
$t->equals('tribe', Azure_Event_CPT::get_data_source(), 'unset reader follows TEC owner');
$t->equals(array('tribe_events'), Azure_Event_CPT::write_post_types(), 'stay-on-TEC writes tribe_events');
$t->equals('tribe_events_cat', Azure_Event_CPT::write_taxonomy(), 'stay-on-TEC uses TEC taxonomy');
$t->check(!Azure_Event_CPT::is_pta_owner_active(), 'stay-on-TEC does not register pta_event');
$t->check(!Azure_Event_CPT::should_own_event_urls(), 'stay-on-TEC leaves /event/ to TEC');

azure_event_store_reset();
Azure_Event_CPT::$tec_active_override = true;
WP_Shim::$settings['pta_calendar_owner'] = 'both';
WP_Shim::$settings['pta_calendar_data_source'] = 'tribe';
$t->equals(array('pta_event', 'tribe_events'), Azure_Event_CPT::write_post_types(), 'migration dual-writes');
$t->equals('pta_event_category', Azure_Event_CPT::write_taxonomy('pta_event'), 'dual-write PTA taxonomy');
$t->equals('tribe_events_cat', Azure_Event_CPT::write_taxonomy('tribe_events'), 'dual-write TEC taxonomy');
$t->equals('tribe_events', Azure_Event_CPT::query_post_type(), 'migration still reads TEC until cutover');
$t->check(Azure_Event_CPT::is_pta_owner_active(), 'migration registers pta_event');
$t->check(!Azure_Event_CPT::should_own_event_urls(), 'migration reading TEC does not steal /event/');

azure_event_store_reset();
Azure_Event_CPT::$tec_active_override = true;
WP_Shim::$settings['pta_calendar_owner'] = 'both';
WP_Shim::$settings['pta_calendar_data_source'] = 'pta';
$t->check(Azure_Event_CPT::should_own_event_urls(), 'migration can cut the reader over to PTA Tools');
$t->equals('pta_event', Azure_Event_CPT::query_post_type(), 'cutover reader is pta_event');

azure_event_store_reset();
Azure_Event_CPT::$tec_active_override = true;
WP_Shim::$settings['pta_calendar_owner'] = 'pta';
$t->equals(array('pta_event'), Azure_Event_CPT::write_post_types(), 'explicit PTA owner wins even when TEC is installed');
$t->equals('pta', Azure_Event_CPT::get_data_source(), 'explicit PTA owner defaults reader to pta');

azure_event_store_reset();
Azure_Event_CPT::$tec_active_override = false;
WP_Shim::$settings['pta_calendar_owner'] = 'tec';
$t->equals('pta', Azure_Event_CPT::get_owner(), 'saved tec degrades to pta when TEC is gone');
$t->equals(array('pta_event'), Azure_Event_CPT::write_post_types(), 'degraded tec writes pta_event');
$t->check(Azure_Event_CPT::is_pta_owner_active(), 'degraded tec still registers pta_event');

azure_event_store_reset();
$t->equals('pta', Azure_Event_CPT::sanitize_owner('nope'), 'invalid owner without TEC is pta');
Azure_Event_CPT::$tec_active_override = true;
$t->equals('tec', Azure_Event_CPT::sanitize_owner('tec'), 'tec owner kept when TEC is active');
$t->equals('both', Azure_Event_CPT::sanitize_owner('both'), 'both owner kept when TEC is active');
$t->equals('pta', Azure_Event_CPT::sanitize_data_source(''), 'empty reader sanitizes to pta');
$t->equals('tribe', Azure_Event_CPT::sanitize_data_source('tribe'), 'tribe reader kept');

azure_event_store_reset();
exit($t->finish() === 0 ? 0 : 1);
