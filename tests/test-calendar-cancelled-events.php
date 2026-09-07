<?php
/**
 * Graph calendarView cancelled occurrences must not be imported.
 *
 * Run: php tests/test-calendar-cancelled-events.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-calendar-graph-api.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-calendar-sync-engine.php';

$t = new TestRunner('Calendar cancelled Graph events');

$t->check(
    !Azure_Calendar_GraphAPI::is_cancelled_graph_event(array('id' => 'a')),
    'missing isCancelled is treated as live'
);
$t->check(
    !Azure_Calendar_GraphAPI::is_cancelled_graph_event(array('id' => 'a', 'isCancelled' => false)),
    'boolean false is live'
);
$t->check(
    !Azure_Calendar_GraphAPI::is_cancelled_graph_event(array('id' => 'a', 'isCancelled' => 'false')),
    'string false is live (Graph sometimes stringifies)'
);
$t->check(
    Azure_Calendar_GraphAPI::is_cancelled_graph_event(array('id' => 'a', 'isCancelled' => true)),
    'boolean true is cancelled'
);
$t->check(
    Azure_Calendar_GraphAPI::is_cancelled_graph_event(array('id' => 'a', 'isCancelled' => 'true')),
    'string true is cancelled'
);
$t->check(
    Azure_Calendar_GraphAPI::is_cancelled_graph_event(array('id' => 'a', 'isCancelled' => 1)),
    'integer 1 is cancelled'
);
$t->check(
    !Azure_Calendar_GraphAPI::is_cancelled_graph_event('not-an-array'),
    'non-array is not cancelled'
);

$api = new Azure_Calendar_GraphAPI();
$raw = array(
    array(
        'id'      => 'live-1',
        'subject' => 'Math Adventures Info Session',
        'start'   => array('dateTime' => '2026-09-18T12:00:00', 'timeZone' => 'Pacific Standard Time'),
        'end'     => array('dateTime' => '2026-09-18T13:00:00', 'timeZone' => 'Pacific Standard Time'),
        'isCancelled' => false,
    ),
    array(
        'id'      => 'dead-1',
        'subject' => '1st Grade Math Adventures',
        'start'   => array('dateTime' => '2026-09-09T14:00:00', 'timeZone' => 'Pacific Standard Time'),
        'end'     => array('dateTime' => '2026-09-09T15:00:00', 'timeZone' => 'Pacific Standard Time'),
        'isCancelled' => true,
    ),
    array(
        'id'      => 'dead-2',
        'subject' => '1st Grade Math Adventures',
        'start'   => array('dateTime' => '2026-09-11T14:00:00', 'timeZone' => 'Pacific Standard Time'),
        'end'     => array('dateTime' => '2026-09-11T15:00:00', 'timeZone' => 'Pacific Standard Time'),
        'isCancelled' => 'true',
    ),
);

$processed = call_private($api, 'process_events', array($raw));
$ids = array();
foreach ($processed as $row) {
    $ids[] = $row['id'];
}

$t->equals(array('live-1'), $ids, 'cancelled occurrences are dropped; the info session stays');
$t->equals(1, count($processed), 'only one importable event remains');

$src = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-calendar-graph-api.php');
$t->check(
    strpos($src, 'isCancelled') !== false && strpos($src, '$select') !== false,
    'Graph $select asks for isCancelled'
);

$t->check(
    !Azure_Calendar_Sync_Engine::may_update_existing_outlook_event('trash'),
    'trashed Outlook events are not republished'
);
$t->check(
    Azure_Calendar_Sync_Engine::may_update_existing_outlook_event('publish'),
    'published Outlook events can still be updated'
);
$t->check(
    Azure_Calendar_Sync_Engine::may_update_existing_outlook_event('draft'),
    'draft Outlook events can still be updated'
);

exit($t->finish());
