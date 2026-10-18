<?php
/**
 * Regression checks for Azure_Backup_Scheduler.
 *
 * Covers the two ways scheduled backups have silently stopped running:
 *   1. Next-run times computed against UTC while the admin picked a
 *      site-local time, so a "02:00" backup fired at 19:00 the day before.
 *   2. Recurrences offered in the UI (monthly) that WP-Cron refuses because
 *      no cron_schedules entry registers them.
 *   3. setup_schedules() running on every init and re-arming the cleanup
 *      event, pushing it an hour into the future on every page load.
 *
 * Run:  php tests/test-backup-scheduler.php
 */

require __DIR__ . '/wp-shim.php';

define('AZURE_PLUGIN_PATH', __DIR__ . '/../Azure Plugin/');
// Loaded so wp_get_schedules() in the shim reflects the real production
// cron_schedules filter — that's what makes the "monthly" check meaningful.
require __DIR__ . '/../Azure Plugin/includes/class-pta-cron.php';
require __DIR__ . '/../Azure Plugin/includes/class-backup-scheduler.php';

$t = new TestRunner('Azure_Backup_Scheduler');

/** Build a scheduler with the given settings. */
function make_scheduler(array $settings) {
    WP_Shim::reset();
    WP_Shim::$settings = $settings;
    return new Azure_Backup_Scheduler();
}

function utc($str) {
    return (new DateTimeImmutable($str, new DateTimeZone('UTC')))->getTimestamp();
}

// ---------------------------------------------------------------------------
// 1. Time validation
// ---------------------------------------------------------------------------

$s = make_scheduler(array());

$t->equals(false, call_private($s, 'calculate_next_run_time', array('daily', 'notatime')), 'malformed time string is rejected');
$t->equals(false, call_private($s, 'calculate_next_run_time', array('daily', '99:99')), 'out-of-range time is rejected');
$t->equals(false, call_private($s, 'calculate_next_run_time', array('daily', '02:70')), 'out-of-range minute is rejected');
$t->equals(false, call_private($s, 'calculate_next_run_time', array('fortnightly', '02:00')), 'unknown frequency is rejected');

// ---------------------------------------------------------------------------
// 2. Daily schedules in UTC
// ---------------------------------------------------------------------------

WP_Shim::$timezone = 'UTC';
$now = utc('2026-07-28 03:00:00');

$t->equals(
    utc('2026-07-29 02:00:00'),
    call_private($s, 'calculate_next_run_time', array('daily', '02:00', $now)),
    'daily 02:00 UTC after the window has passed rolls to tomorrow'
);

$now = utc('2026-07-28 01:00:00');
$t->equals(
    utc('2026-07-28 02:00:00'),
    call_private($s, 'calculate_next_run_time', array('daily', '02:00', $now)),
    'daily 02:00 UTC before the window stays today'
);

// ---------------------------------------------------------------------------
// 3. Daily schedules with a site timezone set (the common production case)
// ---------------------------------------------------------------------------

// Pacific daylight time is UTC-7, so 02:00 local == 09:00 UTC.
WP_Shim::$timezone = 'America/Los_Angeles';

$now = utc('2026-07-28 10:00:00'); // 03:00 local, window already passed
$t->equals(
    utc('2026-07-29 09:00:00'),
    call_private($s, 'calculate_next_run_time', array('daily', '02:00', $now)),
    'daily 02:00 is interpreted in the site timezone, not UTC'
);

$now = utc('2026-07-28 07:00:00'); // 00:00 local, window still ahead
$t->equals(
    utc('2026-07-28 09:00:00'),
    call_private($s, 'calculate_next_run_time', array('daily', '02:00', $now)),
    'daily 02:00 local before the window stays on the same local day'
);

// Standard time is UTC-8, so the same 02:00 local is 10:00 UTC in January.
$now = utc('2026-01-15 18:00:00'); // 10:00 local on Jan 15
$t->equals(
    utc('2026-01-16 10:00:00'),
    call_private($s, 'calculate_next_run_time', array('daily', '02:00', $now)),
    'daily schedule follows DST offset changes'
);

// ---------------------------------------------------------------------------
// 4. Hourly / weekly / monthly
// ---------------------------------------------------------------------------

WP_Shim::$timezone = 'UTC';

// For an hourly schedule only the minute matters. The next :30 after 03:10 is
// 03:30 in the same hour; after 03:40 it rolls into the following hour.
$t->equals(
    utc('2026-07-28 03:30:00'),
    call_private($s, 'calculate_next_run_time', array('hourly', '02:30', utc('2026-07-28 03:10:00'))),
    'hourly fires at the next occurrence of the configured minute'
);
$t->equals(
    utc('2026-07-28 04:30:00'),
    call_private($s, 'calculate_next_run_time', array('hourly', '02:30', utc('2026-07-28 03:40:00'))),
    'hourly rolls into the next hour once the minute has passed'
);

$now = utc('2026-07-28 03:00:00');
$t->equals(
    utc('2026-08-04 02:00:00'),
    call_private($s, 'calculate_next_run_time', array('weekly', '02:00', $now)),
    'weekly rolls a full week forward, keeping the weekday'
);

$now = utc('2026-07-28 03:00:00');
$t->equals(
    utc('2026-08-28 02:00:00'),
    call_private($s, 'calculate_next_run_time', array('monthly', '02:00', $now)),
    'monthly keeps the day of month'
);

// Jan 31 + 1 month must not skip to March.
$now = utc('2026-01-31 03:00:00');
$t->equals(
    utc('2026-02-28 02:00:00'),
    call_private($s, 'calculate_next_run_time', array('monthly', '02:00', $now)),
    'monthly clamps to the last day of a short month instead of skipping it'
);

// ---------------------------------------------------------------------------
// 5. The recurrences offered in the admin UI must be registered with WP-Cron
// ---------------------------------------------------------------------------

// These are the four options in admin/backup-page.php.
foreach (array('hourly', 'daily', 'weekly', 'monthly') as $freq) {
    $s = make_scheduler(array(
        'backup_schedule_enabled'   => true,
        'backup_schedule_frequency' => $freq,
        'backup_schedule_time'      => '02:00',
    ));
    $s->setup_schedules();
    $t->check(
        wp_next_scheduled('azure_backup_scheduled') !== false,
        "frequency '{$freq}' offered in the UI actually schedules an event"
    );
}

// ---------------------------------------------------------------------------
// 6. setup_schedules() must be idempotent (it runs on every init)
// ---------------------------------------------------------------------------

$s = make_scheduler(array(
    'backup_schedule_enabled'   => true,
    'backup_schedule_frequency' => 'daily',
    'backup_schedule_time'      => '02:00',
));

$s->setup_schedules();
$first_backup  = wp_next_scheduled('azure_backup_scheduled');
$first_cleanup = wp_next_scheduled('azure_backup_cleanup');

$t->check($first_cleanup !== false, 'cleanup event is armed on first init');

// Simulate two more page loads a few seconds later.
sleep(1);
$s->setup_schedules();
sleep(1);
$s->setup_schedules();

$t->equals($first_backup, wp_next_scheduled('azure_backup_scheduled'), 'repeat init does not move the backup event');
$t->equals($first_cleanup, wp_next_scheduled('azure_backup_cleanup'), 'repeat init does not push the cleanup event further out');

// ---------------------------------------------------------------------------
// 7. Disabling the schedule clears the event; re-enabling re-arms it
// ---------------------------------------------------------------------------

WP_Shim::$settings['backup_schedule_enabled'] = false;
$s->setup_schedules();
$t->equals(false, wp_next_scheduled('azure_backup_scheduled'), 'disabling scheduling clears the backup event');

WP_Shim::$settings['backup_schedule_enabled'] = true;
$s->setup_schedules();
$t->check(wp_next_scheduled('azure_backup_scheduled') !== false, 're-enabling scheduling re-arms the backup event');

// Changing the configured time must move the event.
$before = wp_next_scheduled('azure_backup_scheduled');
WP_Shim::$settings['backup_schedule_time'] = '23:45';
$s->setup_schedules();
$t->check(wp_next_scheduled('azure_backup_scheduled') !== $before, 'changing the backup time reschedules the event');

// A manually cleared event is restored on the next init.
wp_clear_scheduled_hook('azure_backup_scheduled');
$s->setup_schedules();
$t->check(wp_next_scheduled('azure_backup_scheduled') !== false, 'an externally cleared event is re-armed');

// ---------------------------------------------------------------------------
// 8. Built from inside init (how the plugin loads it) it schedules at once
// ---------------------------------------------------------------------------

WP_Shim::reset();
WP_Shim::$did_actions['init'] = 1;
WP_Shim::$settings = array(
    'backup_schedule_enabled'   => true,
    'backup_schedule_frequency' => 'daily',
    'backup_schedule_time'      => '02:00',
);
new Azure_Backup_Scheduler();
$t->check(wp_next_scheduled('azure_backup_scheduled') !== false, 'constructed during init, the daily backup is scheduled without another init');
$t->check(wp_next_scheduled('azure_backup_cleanup') !== false, 'constructed during init, the cleanup is scheduled too');

WP_Shim::reset();
WP_Shim::$settings = array('backup_schedule_enabled' => true, 'backup_schedule_time' => '02:00');
new Azure_Backup_Scheduler();
$t->equals(false, wp_next_scheduled('azure_backup_scheduled'), 'constructed before init, scheduling waits for the init hook');

// ---------------------------------------------------------------------------
// 9. Cleanup removes every blob of an expired backup
// ---------------------------------------------------------------------------

function job($id, $status, $created_at, $files = array(), $manifest = null) {
    $state = array();
    foreach ($files as $entity => $blobs) {
        $state[$entity] = array('status' => 'completed', 'files' => $blobs);
    }
    $state['_validation'] = array('passed' => true);
    return (object) array(
        'id' => $id,
        'status' => $status,
        'created_at' => $created_at,
        'azure_blob_name' => $manifest,
        'entity_state' => json_encode($state),
    );
}

$j = job(7, 'completed', '2026-09-09 21:14:56', array(
    'database' => array('site/2026/09/09/b7/b7-db.sql.gz'),
    'uploads'  => array('site/2026/09/09/b7/b7-uploads.zip', 'site/2026/09/09/b7/b7-uploads2.zip'),
    'content'  => array(),
), 'site/2026/09/09/b7/b7-manifest.json');
$t->equals(array(
    'site/2026/09/09/b7/b7-db.sql.gz',
    'site/2026/09/09/b7/b7-uploads.zip',
    'site/2026/09/09/b7/b7-uploads2.zip',
    'site/2026/09/09/b7/b7-manifest.json',
), Azure_Backup_Scheduler::job_blob_names($j), 'a backup\'s blobs are every component archive plus the manifest');
$t->equals(array(), Azure_Backup_Scheduler::job_blob_names(job(8, 'failed', '2026-09-01 00:00:00')), 'a job that uploaded nothing has no blobs');

// ---------------------------------------------------------------------------
// 10. Retention never removes the newest completed backups
// ---------------------------------------------------------------------------

$jobs = array(
    job(1, 'completed', '2026-08-19 19:59:58'),
    job(2, 'completed', '2026-09-09 21:14:56'),
    job(3, 'failed',    '2026-09-10 02:00:00'),
    job(4, 'completed', '2026-10-01 02:00:00'),
    job(5, 'completed', '2026-10-17 02:00:00'),
    job(6, 'completed', '2026-10-18 02:00:00'),
);
$ids = function ($list) { return array_map(function ($j) { return $j->id; }, $list); };

$t->equals(array(1, 2, 3), $ids(Azure_Backup_Scheduler::jobs_to_expire($jobs, '2026-10-11 00:00:00')), 'past-retention jobs expire oldest first, the newest three completed are kept');
$t->equals(array(3), $ids(Azure_Backup_Scheduler::jobs_to_expire(array_slice($jobs, 1, 3), '2026-10-11 00:00:00')), 'with only two completed backups, both are kept however old they are');
$t->equals(array(), $ids(Azure_Backup_Scheduler::jobs_to_expire($jobs, '2026-08-01 00:00:00')), 'nothing inside the retention window expires');
$running = job(9, 'running', '2026-01-01 00:00:00');
$t->equals(array(), $ids(Azure_Backup_Scheduler::jobs_to_expire(array($running), '2026-10-11 00:00:00')), 'an in-flight job is never expired');

// ---------------------------------------------------------------------------
// 11. cleanup_old_backups() end to end
// ---------------------------------------------------------------------------

class Fake_Cleanup_Wpdb {
    public $prefix = 'wp_';
    public $rows = array();
    public $deleted = array();
    public function get_results($sql) { return $this->rows; }
    public function delete($table, $where, $format = null) { $this->deleted[] = $where['id']; return 1; }
}

class Azure_Backup_Storage {
    public static $deleted = array();
    public static $fail = array();
    public function delete_backup($blob) {
        if (in_array($blob, self::$fail, true)) {
            throw new Exception('Delete failed with status 500');
        }
        if (strpos($blob, 'gone') !== false) {
            throw new Exception('Delete failed with status 404');
        }
        self::$deleted[] = $blob;
        return true;
    }
    public function get_blob_properties($blob) {
        return in_array($blob, self::$fail, true) ? array('size' => 1) : false;
    }
}

$database_calls = array();
WP_Shim::reset();
WP_Shim::$settings = array('backup_retention_days' => 7);
$GLOBALS['wpdb'] = new Fake_Cleanup_Wpdb();
$old = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
$new = gmdate('Y-m-d H:i:s', time() - 86400);
$GLOBALS['wpdb']->rows = array(
    job(1, 'completed', $old, array('database' => array('s/b1/db.gz'), 'uploads' => array('s/b1/up.zip')), 's/b1/manifest.json'),
    job(2, 'completed', $old, array('database' => array('s/b2/db.gz'), 'uploads' => array('s/b2/gone-up.zip')), 's/b2/manifest.json'),
    job(3, 'completed', $old, array('database' => array('s/b3/db.gz')), 's/b3/manifest.json'),
    job(4, 'completed', $new, array('database' => array('s/b4/db.gz')), 's/b4/manifest.json'),
    job(5, 'completed', $new, array(), 's/b5/manifest.json'),
    job(6, 'completed', $new, array(), 's/b6/manifest.json'),
);
Azure_Backup_Storage::$fail = array('s/b3/db.gz');

$s = new Azure_Backup_Scheduler();
$s->cleanup_old_backups();

$t->check(in_array('s/b1/up.zip', Azure_Backup_Storage::$deleted, true), 'cleanup deletes component archives, not just the manifest');
$t->check(in_array('s/b1/manifest.json', Azure_Backup_Storage::$deleted, true), 'cleanup deletes the manifest');
$t->check(in_array(1, $GLOBALS['wpdb']->deleted, true), 'a fully deleted backup loses its row');
$t->check(in_array(2, $GLOBALS['wpdb']->deleted, true), 'a blob that was already gone does not block removing the row');
$t->check(!in_array(3, $GLOBALS['wpdb']->deleted, true), 'a backup whose blob could not be deleted keeps its row for the next run');
$t->check(!array_intersect(array(4, 5, 6), $GLOBALS['wpdb']->deleted), 'recent backups are untouched');

exit($t->finish() > 0 ? 1 : 0);
