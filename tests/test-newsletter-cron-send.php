<?php
/**
 * Scheduled campaigns are sent by wp-cron, and Send Now on a scheduled
 * campaign replaces its schedule instead of reporting 0 emails.
 *
 * Run: php tests/test-newsletter-cron-send.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

// The plugin builds the module from inside its own init callback.
if (!function_exists('did_action')) {
    function did_action($hook) { return $hook === 'init' ? 1 : 0; }
}
if (!class_exists('Azure_Logger')) {
    class Azure_Logger {
        public static function __callStatic($name, $args) {}
    }
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-module.php';

$t = new TestRunner('Newsletter cron send');

$t->check(!class_exists('Azure_Newsletter_Queue', false), 'queue class is not loaded before the module exists');
Azure_Newsletter_Module::get_instance();
$t->check(
    class_exists('Azure_Newsletter_Queue', false),
    'module created after init has started loads the queue class (wp-cron path)'
);
$t->check(class_exists('Azure_Newsletter_Bounce', false), 'bounce cron handlers have their class too');

$more = function ($result, $elapsed) {
    return Azure_Newsletter_Module::should_send_another_batch($result, $elapsed);
};
$t->check($more(array('sent' => 300, 'total' => 300), 60), 'keeps sending while batches succeed');
$t->check(!$more(array('sent' => 0, 'total' => 0), 10), 'stops when the queue is empty');
$t->check(!$more(array('sent' => 0, 'failed' => 300, 'total' => 300), 10), 'stops on an all-failed batch rather than retrying in a loop');
$t->check(!$more(array('sent' => 0, 'locked' => true), 1), 'stops when another send holds the lock');
$t->check(!$more(array('sent' => 10, 'rate_limited' => true), 1), 'stops when rate limited');
$t->check(!$more(array('sent' => 300), Azure_Newsletter_Module::CRON_SEND_BUDGET), 'stops at the time budget');

$queue_src = file_get_contents(AZURE_PLUGIN_PATH . 'includes/class-newsletter-queue.php');
$t->check(
    strpos($queue_src, 'GET_LOCK') !== false && strpos($queue_src, 'RELEASE_LOCK') !== false,
    'process_batch takes a named lock so cron and manual sends cannot overlap'
);

$ajax_src = file_get_contents(AZURE_PLUGIN_PATH . 'includes/class-newsletter-ajax.php');
$clear = strpos($ajax_src, '$queue->clear_pending($newsletter_id)');
$enqueue = strpos($ajax_src, '$queue->queue_newsletter($newsletter_id');
$t->check($clear !== false && $enqueue !== false && $clear < $enqueue, 'Send Now clears unsent rows before queueing again');

$editor_src = file_get_contents(AZURE_PLUGIN_PATH . 'admin/newsletter-editor.php');
$js_src = file_get_contents(AZURE_PLUGIN_PATH . 'js/newsletter-editor.js');
$t->check(strpos($editor_src, 'id="replace-schedule-modal"') !== false, 'editor renders the replace-schedule modal');
$t->check(strpos($editor_src, 'pendingSchedule') !== false, 'editor passes the pending schedule to the script');
$t->check(strpos($js_src, 'Sending now will replace the schedule.') !== false, 'Send Now on a scheduled campaign asks first');

$job = file_get_contents(dirname(__DIR__) . '/infra/aca-wordpress/job-wpcron.yaml');
$t->check(strpos($job, 'cronExpression: "*/30 * * * *"') !== false, 'wp-cron job runs every 30 minutes');

$t->finish();
