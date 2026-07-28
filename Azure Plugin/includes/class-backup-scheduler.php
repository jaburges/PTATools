<?php
/**
 * Backup scheduler for Azure Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Backup_Scheduler {
    
    private $settings;
    
    public function __construct() {
        try {
            $this->settings = Azure_Settings::get_all_settings();
            
            // Schedule hooks
            add_action('azure_backup_scheduled', array($this, 'run_scheduled_backup'));
            add_action('azure_backup_cleanup', array($this, 'cleanup_old_backups'));
            
            // Setup schedules on init
            add_action('init', array($this, 'setup_schedules'));
            
            // AJAX actions
            add_action('wp_ajax_azure_schedule_backup', array($this, 'ajax_schedule_backup'));
            add_action('wp_ajax_azure_get_next_backup', array($this, 'ajax_get_next_backup'));
            
        } catch (Exception $e) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::error('Backup Scheduler: Constructor error - ' . $e->getMessage());
            }
            $this->settings = array();
        }
    }
    
    /**
     * Setup backup schedules
     */
    public function setup_schedules() {
        $this->setup_backup_schedule();
        $this->setup_cleanup_schedule();
    }
    
    /**
     * Setup backup schedule
     */
    const CONFIG_OPTION = 'azure_backup_schedule_config';

    private function setup_backup_schedule() {
        $schedule_enabled = Azure_Settings::get_setting('backup_schedule_enabled', false);
        $frequency = Azure_Settings::get_setting('backup_schedule_frequency', 'daily');
        $time = Azure_Settings::get_setting('backup_schedule_time', '02:00');

        // setup_schedules() is wired to `init`, so this runs on every request.
        // Only touch WP-Cron when the configuration actually changed or the
        // event is missing — unconditionally clearing and re-adding used to
        // wipe an event that was seconds away from being due, which could
        // defer a backup indefinitely on a busy site.
        $signature = wp_json_encode(array($schedule_enabled ? 1 : 0, $frequency, $time));
        $stored    = get_option(self::CONFIG_OPTION, null);
        $existing  = wp_next_scheduled('azure_backup_scheduled');

        if ($stored === $signature && ($existing !== false) === (bool) $schedule_enabled) {
            return;
        }

        wp_clear_scheduled_hook('azure_backup_scheduled');
        update_option(self::CONFIG_OPTION, $signature, false);

        if (!$schedule_enabled) {
            Azure_Logger::info('Backup Scheduler: Scheduled backups disabled');
            return;
        }

        $next_run = $this->calculate_next_run_time($frequency, $time);

        if (!$next_run) {
            Azure_Logger::error('Backup Scheduler: Failed to calculate next run time for frequency "' . $frequency . '" at "' . $time . '"');
            return;
        }

        if (!wp_schedule_event($next_run, $frequency, 'azure_backup_scheduled')) {
            Azure_Logger::error('Backup Scheduler: WP-Cron refused recurrence "' . $frequency . '" — no matching cron_schedules entry, scheduled backups will not run');
            return;
        }

        Azure_Logger::info('Backup Scheduler: Next backup scheduled for ' . get_date_from_gmt(gmdate('Y-m-d H:i:s', $next_run)));
    }

    /**
     * Setup cleanup schedule
     */
    private function setup_cleanup_schedule() {
        // Do NOT clear first — this runs on every init, and clearing before
        // the wp_next_scheduled() check meant the weekly cleanup was pushed
        // an hour into the future on every page load and never fired.
        if (!wp_next_scheduled('azure_backup_cleanup')) {
            wp_schedule_event(time() + 3600, 'weekly', 'azure_backup_cleanup');
            Azure_Logger::info('Backup Scheduler: Cleanup schedule created');
        }
    }
    
    /**
     * Calculate the next run time as a UTC timestamp.
     *
     * The configured time is a *site-local* wall-clock time (that's what the
     * admin screen presents), while wp_schedule_event() expects UTC. Doing the
     * arithmetic with DateTimeImmutable in the site timezone keeps the two
     * straight and stays correct across DST transitions — the previous version
     * mixed a site-offset timestamp from current_time('timestamp') with
     * UTC-interpreted date strings, so a "02:00" backup on a Pacific site
     * actually ran at 19:00 the day before.
     *
     * @param string   $frequency hourly|daily|weekly|monthly
     * @param string   $time      HH:MM in site-local time.
     * @param int|null $now_ts    UTC timestamp to treat as "now" (for tests).
     * @return int|false UTC timestamp, or false if the config is invalid.
     */
    private function calculate_next_run_time($frequency, $time, $now_ts = null) {
        $time_parts = explode(':', $time);
        if (count($time_parts) !== 2) {
            return false;
        }

        $hour = intval($time_parts[0]);
        $minute = intval($time_parts[1]);

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return false;
        }

        if (!in_array($frequency, array('hourly', 'daily', 'weekly', 'monthly'), true)) {
            return false;
        }

        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $now = (new DateTimeImmutable('@' . ($now_ts === null ? time() : (int) $now_ts)))->setTimezone($tz);
        $target = $now->setTime($hour, $minute, 0);

        switch ($frequency) {
            case 'hourly':
                // The configured minute of the next upcoming hour.
                $next_run = $now->setTime((int) $now->format('G'), $minute, 0);
                if ($next_run <= $now) {
                    $next_run = $next_run->modify('+1 hour');
                }
                break;

            case 'daily':
                $next_run = $target;
                if ($next_run <= $now) {
                    $next_run = $next_run->modify('+1 day');
                }
                break;

            case 'weekly':
                $next_run = $target;
                if ($next_run <= $now) {
                    $next_run = $next_run->modify('+7 days');
                }
                break;

            case 'monthly':
                $next_run = $target;
                if ($next_run <= $now) {
                    // "+1 month" on the 31st overflows into the month after
                    // next, silently skipping a cycle — clamp to the last day.
                    $first_next = $target->modify('first day of next month');
                    $day = min((int) $target->format('j'), (int) $first_next->format('t'));
                    $next_run = $first_next
                        ->setDate((int) $first_next->format('Y'), (int) $first_next->format('n'), $day)
                        ->setTime($hour, $minute, 0);
                }
                break;
        }

        return $next_run->getTimestamp();
    }
    
    /**
     * Run scheduled backup
     */
    public function run_scheduled_backup() {
        if (!Azure_Settings::get_setting('backup_schedule_enabled', false)) {
            Azure_Logger::info('Backup Scheduler: Scheduled backup skipped - scheduling disabled');
            return;
        }
        
        Azure_Logger::info('Backup Scheduler: Starting scheduled backup');
        
        try {
            if (class_exists('Azure_Backup')) {
                $backup = new Azure_Backup();
                $backup->run_scheduled_backup();
                
                Azure_Logger::info('Backup Scheduler: Scheduled backup initiated successfully');
                $this->send_backup_notification(true, 'Scheduled backup initiated successfully', null);
            } else {
                throw new Exception('Backup class not available');
            }
            
        } catch (Exception $e) {
            Azure_Logger::error('Backup Scheduler: Scheduled backup failed: ' . $e->getMessage());
            Azure_Database::log_activity('backup', 'scheduled_backup_failed', 'backup', null, array('error' => $e->getMessage()));
            
            // Send failure notification
            $this->send_backup_notification(false, $e->getMessage());
        }
        
        // Reschedule next backup
        $this->setup_backup_schedule();
    }
    
    /**
     * Clean up old backups
     */
    public function cleanup_old_backups() {
        $retention_days = Azure_Settings::get_setting('backup_retention_days', 30);
        
        if ($retention_days <= 0) {
            Azure_Logger::info('Backup Scheduler: Cleanup skipped - retention disabled');
            return;
        }
        
        Azure_Logger::info("Backup Scheduler: Starting cleanup of backups older than {$retention_days} days");
        
        try {
            global $wpdb;
            $table = Azure_Database::get_table_name('backup_jobs');
            
            if (!$table) {
                throw new Exception('Database table not found');
            }
            
            // Get old backups
            $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));
            $old_backups = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE created_at < %s AND status = 'completed'",
                $cutoff_date
            ));
            
            $deleted_count = 0;
            $storage = null;
            
            if (class_exists('Azure_Backup_Storage')) {
                $storage = new Azure_Backup_Storage();
            }
            
            foreach ($old_backups as $backup) {
                try {
                    // Delete from Azure Storage if available
                    if ($storage && !empty($backup->azure_blob_name)) {
                        $storage->delete_backup($backup->azure_blob_name);
                    }
                    
                    // Delete from database
                    $wpdb->delete($table, array('id' => $backup->id), array('%d'));
                    
                    $deleted_count++;
                    
                } catch (Exception $e) {
                    Azure_Logger::warning('Backup Scheduler: Failed to delete backup ' . $backup->id . ': ' . $e->getMessage());
                }
            }
            
            Azure_Logger::info("Backup Scheduler: Cleanup completed - deleted {$deleted_count} old backups");
            Azure_Database::log_activity('backup', 'cleanup_completed', 'cleanup', null, array('deleted_count' => $deleted_count));
            
        } catch (Exception $e) {
            Azure_Logger::error('Backup Scheduler: Cleanup failed: ' . $e->getMessage());
            Azure_Database::log_activity('backup', 'cleanup_failed', 'cleanup', null, array('error' => $e->getMessage()));
        }
        
        // Also clean up database records
        Azure_Database::cleanup_old_records($retention_days);
    }
    
    /**
     * Send backup notification
     */
    private function send_backup_notification($success, $message, $backup_id = null) {
        if (!Azure_Settings::get_setting('backup_email_notifications', true)) {
            return;
        }
        
        $notification_email = Azure_Settings::get_setting('backup_notification_email', get_option('admin_email'));
        
        if (empty($notification_email)) {
            return;
        }
        
        $subject = $success ? 'Backup Completed Successfully' : 'Backup Failed';
        $subject .= ' - ' . get_bloginfo('name');
        
        $body = "Backup notification from " . get_bloginfo('name') . "\n\n";
        $body .= "Status: " . ($success ? 'Success' : 'Failed') . "\n";
        $body .= "Message: " . $message . "\n";
        $body .= "Time: " . current_time('mysql') . "\n";
        $body .= "Site URL: " . get_site_url() . "\n";
        
        if ($backup_id) {
            $body .= "Backup ID: " . $backup_id . "\n";
        }
        
        // Add next scheduled backup info
        $next_backup = wp_next_scheduled('azure_backup_scheduled');
        if ($next_backup) {
            $body .= "Next Scheduled Backup: " . date('Y-m-d H:i:s', $next_backup) . "\n";
        }
        
        wp_mail($notification_email, $subject, $body);
    }
    
    /**
     * Get schedule information
     */
    public function get_schedule_info() {
        $next_backup = wp_next_scheduled('azure_backup_scheduled');
        $next_cleanup = wp_next_scheduled('azure_backup_cleanup');
        
        return array(
            'backup_enabled' => Azure_Settings::get_setting('backup_schedule_enabled', false),
            'backup_frequency' => Azure_Settings::get_setting('backup_schedule_frequency', 'daily'),
            'backup_time' => Azure_Settings::get_setting('backup_schedule_time', '02:00'),
            'next_backup' => $next_backup ? date('Y-m-d H:i:s', $next_backup) : null,
            'next_cleanup' => $next_cleanup ? date('Y-m-d H:i:s', $next_cleanup) : null,
            'retention_days' => Azure_Settings::get_setting('backup_retention_days', 30)
        );
    }
    
    /**
     * Manual schedule update
     */
    public function update_schedule() {
        $this->setup_backup_schedule();
        $this->setup_cleanup_schedule();
    }
    
    /**
     * AJAX handler for scheduling backup
     */
    public function ajax_schedule_backup() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_die('Unauthorized access');
        }
        
        try {
            $this->update_schedule();
            
            $schedule_info = $this->get_schedule_info();
            
            wp_send_json_success(array(
                'message' => 'Schedule updated successfully',
                'schedule_info' => $schedule_info
            ));
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * AJAX handler for getting next backup time
     */
    public function ajax_get_next_backup() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_die('Unauthorized access');
        }
        
        $schedule_info = $this->get_schedule_info();
        wp_send_json_success($schedule_info);
    }
    
    /**
     * Get backup statistics
     */
    public function get_backup_stats() {
        global $wpdb;
        $table = Azure_Database::get_table_name('backup_jobs');
        
        if (!$table) {
            return array();
        }
        
        $total_backups = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $completed_backups = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'completed'");
        $failed_backups = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'failed'");
        $total_size = $wpdb->get_var("SELECT SUM(file_size) FROM {$table} WHERE status = 'completed'");
        
        $recent_backups = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE created_at > %s AND status = 'completed'",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));
        
        return array(
            'total_backups' => intval($total_backups),
            'completed_backups' => intval($completed_backups),
            'failed_backups' => intval($failed_backups),
            'recent_backups' => intval($recent_backups),
            'total_size' => intval($total_size),
            'total_size_formatted' => size_format(intval($total_size))
        );
    }
    
    /**
     * Test backup schedule
     */
    public function test_schedule() {
        $frequency = Azure_Settings::get_setting('backup_schedule_frequency', 'daily');
        $time = Azure_Settings::get_setting('backup_schedule_time', '02:00');
        
        $next_run = $this->calculate_next_run_time($frequency, $time);
        
        if ($next_run) {
            return array(
                'success' => true,
                'message' => 'Schedule is valid',
                'next_run' => date('Y-m-d H:i:s', $next_run),
                'frequency' => $frequency,
                'time' => $time
            );
        } else {
            return array(
                'success' => false,
                'message' => 'Invalid schedule configuration',
                'frequency' => $frequency,
                'time' => $time
            );
        }
    }
}
