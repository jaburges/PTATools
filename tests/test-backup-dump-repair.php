<?php
/**
 * Restoring PHP-exported database dumps, and the Backup Status widget figures.
 *
 * PHP dumps used to be escaped with esc_sql(), which replaced every % with a
 * random 64-hex placeholder. Restore has to turn those back into %, or URLs,
 * CSS and serialized options come back corrupted.
 *
 * Run:  php tests/test-backup-dump-repair.php
 */

require __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', __DIR__ . '/../Azure Plugin/');
}
require __DIR__ . '/../Azure Plugin/includes/class-backup-restore.php';
require __DIR__ . '/../Azure Plugin/includes/class-backup.php';

$t = new TestRunner('Backup dump repair and dashboard stats');

// ---------------------------------------------------------------------------
// 1. Placeholder repair
// ---------------------------------------------------------------------------

$ph = '{02045d523a42e6f03dbaf04ce25d1c61a63e0b002b36bcfca34feaabf213eaaf}';
$ph2 = '{9b1f0e1c2d3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9c}';
$line = "INSERT INTO `wp_options` VALUES ('1','w','a:1:{s:5:\\\"width\\\";s:3:\\\"50{$ph}\\\";}'),('2','u','a{$ph2}20b');\n";

$t->equals(
    "INSERT INTO `wp_options` VALUES ('1','w','a:1:{s:5:\\\"width\\\";s:3:\\\"50%\\\";}'),('2','u','a%20b');\n",
    Azure_Backup_Restore::repair_placeholder_escapes($line),
    'every placeholder, from any request, becomes % again'
);
$t->equals('{abc} and {ABCDEF0123456789}', Azure_Backup_Restore::repair_placeholder_escapes('{abc} and {ABCDEF0123456789}'), 'other braced text is left alone');
$t->equals("plain ' % line", Azure_Backup_Restore::repair_placeholder_escapes("plain ' % line"), 'a line with real % characters is unchanged');

$engine_src = file_get_contents(__DIR__ . '/../Azure Plugin/includes/class-backup-engine.php');
$t->check(strpos($engine_src, '"' . Azure_Backup_Restore::PHP_DUMP_HEADER . '\n"') !== false, 'restore looks for the exact header the PHP exporter writes');

$restore_src = file_get_contents(__DIR__ . '/../Azure Plugin/includes/class-backup-restore.php');
$t->check(
    (bool) preg_match('/if \(\$php_dump\) \{\s*\$line = self::repair_placeholder_escapes\(\$line\);/', $restore_src),
    'the SQL stream repairs lines once it has seen the PHP dump header'
);

// ---------------------------------------------------------------------------
// 2. Dashboard widget figures come from the jobs table
// ---------------------------------------------------------------------------

class Fake_Stats_Wpdb {
    public $prefix = 'wp_';
    public $jobs = array();
    public $month_start = null;
    public function prepare($sql, ...$args) {
        $this->month_start = $args[0];
        return str_replace('%s', "'" . $args[0] . "'", $sql);
    }
    public function get_var($sql) {
        if (strpos($sql, 'MAX(completed_at)') !== false) {
            $done = array_filter($this->jobs, function ($j) { return $j['status'] === 'completed'; });
            return $done ? max(array_column($done, 'completed_at')) : null;
        }
        if (strpos($sql, 'ORDER BY id DESC') !== false) {
            $finished = array_values(array_filter($this->jobs, function ($j) { return in_array($j['status'], array('completed', 'failed'), true); }));
            return $finished ? end($finished)['status'] : null;
        }
        if (strpos($sql, 'COUNT(*)') !== false) {
            $start = $this->month_start;
            return count(array_filter($this->jobs, function ($j) use ($start) {
                return $j['status'] === 'completed' && $j['completed_at'] >= $start;
            }));
        }
        return null;
    }
}

WP_Shim::reset();
WP_Shim::$timezone = 'America/Los_Angeles';
WP_Shim::$settings = array('backup_schedule_enabled' => true);
$GLOBALS['wpdb'] = new Fake_Stats_Wpdb();
$now = (new DateTimeImmutable('2026-10-18 15:00:00', new DateTimeZone('UTC')))->getTimestamp();

$stats = Azure_Backup::dashboard_stats($now);
$t->equals(null, $stats['last_backup'], 'no jobs: no last backup');
$t->equals(0, $stats['backups_this_month'], 'no jobs: zero this month');
$t->equals(true, $stats['schedule_enabled'], 'the widget knows automatic backups are switched on');
$t->equals(null, $stats['next_backup'], 'and that nothing is scheduled yet');

$GLOBALS['wpdb']->jobs = array(
    array('id' => 1, 'status' => 'completed', 'completed_at' => '2026-08-19 20:01:14'),
    array('id' => 2, 'status' => 'completed', 'completed_at' => '2026-09-09 21:19:45'),
    array('id' => 3, 'status' => 'completed', 'completed_at' => '2026-10-01 06:30:00'),
    array('id' => 4, 'status' => 'completed', 'completed_at' => '2026-10-01 07:30:00'),
);
WP_Shim::$cron['azure_backup_scheduled'] = array('timestamp' => $now + 3600, 'recurrence' => 'daily', 'args' => array());

$stats = Azure_Backup::dashboard_stats($now);
$t->equals('2026-10-01 07:30:00', $stats['last_backup'], 'manual backups in the jobs table count as the last backup');
$t->equals('success', $stats['last_backup_status'], 'a completed latest job shows as success');
$t->equals('2026-10-01 07:00:00', $GLOBALS['wpdb']->month_start, 'the month starts at local midnight on the 1st, in GMT');
$t->equals(1, $stats['backups_this_month'], 'a backup finishing just before local midnight on the 1st counts for the previous month');
$t->equals($now + 3600, $stats['next_backup'], 'the next automatic backup comes from WP-Cron');

$GLOBALS['wpdb']->jobs[] = array('id' => 5, 'status' => 'failed', 'completed_at' => null);
$stats = Azure_Backup::dashboard_stats($now);
$t->equals('error', $stats['last_backup_status'], 'a failed latest job shows as an error');
$t->equals('2026-10-01 07:30:00', $stats['last_backup'], 'and the last good backup is still reported');

$admin_src = file_get_contents(__DIR__ . '/../Azure Plugin/includes/class-admin.php');
$t->check(strpos($admin_src, 'Azure_Backup::dashboard_stats()') !== false, 'the Backup Status widget reads Azure_Backup::dashboard_stats()');
$t->check(strpos($admin_src, "action = 'backup_complete'") === false, 'the widget no longer counts activity-log rows nothing writes');

exit($t->finish() > 0 ? 1 : 0);
