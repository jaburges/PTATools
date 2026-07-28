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

exit($t->finish() > 0 ? 1 : 0);
