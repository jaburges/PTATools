<?php
/**
 * TEC Sync Scheduler
 * Handles scheduled automatic synchronization of Outlook calendars to TEC
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_TEC_Sync_Scheduler {
    
    private static $instance = null;
    private $hook_name = 'azure_tec_scheduled_sync';
    
    public static function get_instance() {
        if (self::$instance == null) {
            self::$instance = new Azure_TEC_Sync_Scheduler();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Custom cron intervals (every_15_minutes, every_30_minutes) are now
        // owned by Azure_PTA_Cron::register_intervals(). This class no longer
        // needs its own cron_schedules filter.

        // Register the global cron hook (legacy support)
        add_action($this->hook_name, array($this, 'execute_scheduled_sync'));
        
        // Register per-mapping cron hooks dynamically
        $this->register_per_mapping_hooks();
        
        // Self-healing: verify cron is scheduled when settings say it should be
        add_action('admin_init', array($this, 'ensure_cron_scheduled'));
        
        Azure_Logger::debug('TEC Sync Scheduler: Initialized', 'TEC');
    }
    
    /**
     * Self-healing cron check - re-schedules cron if it's missing but should be active
     */
    public function ensure_cron_scheduled() {
        $settings = Azure_Settings::get_all_settings();
        $sync_enabled = ($settings['tec_sync_schedule_enabled'] ?? false) && ($settings['enable_tec_integration'] ?? false);
        
        if ($sync_enabled && !wp_next_scheduled($this->hook_name)) {
            $frequency = $settings['tec_sync_schedule_frequency'] ?? 'hourly';
            $this->schedule_sync($frequency);
            Azure_Logger::info("TEC Sync Scheduler: Re-scheduled missing cron with frequency '{$frequency}'", 'TEC');
        }
    }
    
    /**
     * Register cron hooks for all mappings with schedules enabled
     */
    private function register_per_mapping_hooks() {
        if (!class_exists('Azure_TEC_Calendar_Mapping_Manager')) {
            return;
        }
        
        $manager = new Azure_TEC_Calendar_Mapping_Manager();
        $mappings = $manager->get_all_mappings();
        
        foreach ($mappings as $mapping) {
            if ($mapping->schedule_enabled) {
                $hook_name = 'azure_tec_mapping_sync_' . $mapping->id;
                // Register hook handler for this mapping
                add_action($hook_name, array($this, 'execute_mapping_sync'));
            }
        }
    }
    
    /**
     * Schedule the sync cron job
     */
    public function schedule_sync($frequency = 'hourly') {
        // Clear any existing schedule
        $this->unschedule_sync();
        
        // Map frequency names to cron schedule names
        $frequency_map = array(
            '15min' => 'every_15_minutes',
            '30min' => 'every_30_minutes',
            'hourly' => 'hourly',
            'twicedaily' => 'twicedaily',
            'daily' => 'daily'
        );
        
        $cron_schedule = isset($frequency_map[$frequency]) ? $frequency_map[$frequency] : 'hourly';
        
        // Schedule new event
        $result = wp_schedule_event(time(), $cron_schedule, $this->hook_name);
        
        if ($result) {
            Azure_Logger::info("TEC Sync Scheduler: Scheduled sync with frequency '{$frequency}' ({$cron_schedule})", 'TEC');
            return true;
        } else {
            Azure_Logger::error("TEC Sync Scheduler: Failed to schedule sync", 'TEC');
            return false;
        }
    }
    
    /**
     * Unschedule the sync cron job
     */
    public function unschedule_sync() {
        $timestamp = wp_next_scheduled($this->hook_name);
        if ($timestamp) {
            wp_unschedule_event($timestamp, $this->hook_name);
            Azure_Logger::info('TEC Sync Scheduler: Unscheduled sync', 'TEC');
        }
    }
    
    /**
     * Execute the scheduled sync
     */
    public function execute_scheduled_sync() {
        Azure_Logger::info('TEC Sync Scheduler: Starting scheduled sync', 'TEC');
        
        try {
            // Check if TEC integration is enabled
            $settings = Azure_Settings::get_all_settings();
            if (!($settings['enable_tec_integration'] ?? false)) {
                Azure_Logger::info('TEC Sync Scheduler: TEC integration not enabled, skipping', 'TEC');
                return;
            }
            
            // Check if scheduled sync is enabled
            if (!($settings['tec_sync_schedule_enabled'] ?? false)) {
                Azure_Logger::info('TEC Sync Scheduler: Scheduled sync not enabled, skipping', 'TEC');
                return;
            }
            
            // Get the calendar user email and mailbox email
            $user_email = $settings['tec_calendar_user_email'] ?? '';
            $mailbox_email = $settings['tec_calendar_mailbox_email'] ?? '';
            
            if (empty($user_email)) {
                Azure_Logger::warning('TEC Sync Scheduler: No calendar user email configured', 'TEC');
                return;
            }
            
            if (empty($mailbox_email)) {
                Azure_Logger::warning('TEC Sync Scheduler: No shared mailbox email configured', 'TEC');
                return;
            }
            
            // Check if user is authenticated
            if (class_exists('Azure_Calendar_Auth')) {
                $auth = new Azure_Calendar_Auth();
                if (!$auth->is_user_authenticated($user_email)) {
                    Azure_Logger::warning("TEC Sync Scheduler: User {$user_email} not authenticated", 'TEC');
                    return;
                }
            }
            
            Azure_Logger::info("TEC Sync Scheduler: Syncing from mailbox '{$mailbox_email}' using token from '{$user_email}'", 'TEC');
            
            // Get date range from settings
            $lookback_days = $settings['tec_sync_lookback_days'] ?? 30;
            $lookahead_days = $settings['tec_sync_lookahead_days'] ?? 365;
            
            $start_date = date('Y-m-d\TH:i:s\Z', strtotime("-{$lookback_days} days"));
            $end_date = date('Y-m-d\TH:i:s\Z', strtotime("+{$lookahead_days} days"));
            
            // Execute sync
            if (class_exists('Azure_TEC_Sync_Engine')) {
                $sync_engine = new Azure_TEC_Sync_Engine();
                $results = $sync_engine->sync_multiple_calendars_to_tec(
                    null,  // null = all enabled calendars
                    $start_date,
                    $end_date,
                    $user_email,
                    $mailbox_email
                );
                
                if ($results && $results['success']) {
                    Azure_Logger::info("TEC Sync Scheduler: Scheduled sync completed successfully. Events synced: {$results['total_events_synced']}, Errors: {$results['total_errors']}", 'TEC');
                    
                    // Log to activity
                    Azure_Database::log_activity(
                        'tec',
                        'scheduled_sync_completed',
                        'sync',
                        null,
                        array(
                            'calendars' => $results['total_calendars'],
                            'events_synced' => $results['total_events_synced'],
                            'errors' => $results['total_errors']
                        ),
                        'success'
                    );
                } else {
                    $error_message = isset($results['error_message']) ? $results['error_message'] : 'Unknown error';
                    Azure_Logger::error("TEC Sync Scheduler: Scheduled sync failed: {$error_message}", 'TEC');
                    
                    // Log error to activity
                    Azure_Database::log_activity(
                        'tec',
                        'scheduled_sync_failed',
                        'sync',
                        null,
                        array('error' => $error_message),
                        'error'
                    );
                }
            } else {
                Azure_Logger::error('TEC Sync Scheduler: TEC Sync Engine not available', 'TEC');
            }
            
        } catch (Exception $e) {
            Azure_Logger::error('TEC Sync Scheduler: Exception during scheduled sync: ' . $e->getMessage(), 'TEC');
            
            // Log exception to activity
            Azure_Database::log_activity(
                'tec',
                'scheduled_sync_exception',
                'sync',
                null,
                array('exception' => $e->getMessage()),
                'error'
            );
        }
    }
    
    /**
     * Execute sync for a specific mapping (called by WP cron)
     */
    public function execute_mapping_sync($mapping_id) {
        Azure_Logger::info("TEC Sync Scheduler: Starting scheduled sync for mapping ID {$mapping_id}", 'TEC');
        
        try {
            // Check if TEC integration is enabled
            $settings = Azure_Settings::get_all_settings();
            if (!($settings['enable_tec_integration'] ?? false)) {
                Azure_Logger::info("TEC Sync Scheduler: TEC integration not enabled, skipping mapping {$mapping_id}", 'TEC');
                return;
            }
            
            // Get the calendar user email and mailbox email
            $user_email = $settings['tec_calendar_user_email'] ?? '';
            $mailbox_email = $settings['tec_calendar_mailbox_email'] ?? '';
            
            if (empty($user_email)) {
                Azure_Logger::warning("TEC Sync Scheduler: No calendar user email configured, skipping mapping {$mapping_id}", 'TEC');
                return;
            }
            
            if (empty($mailbox_email)) {
                Azure_Logger::warning("TEC Sync Scheduler: No shared mailbox email configured, skipping mapping {$mapping_id}", 'TEC');
                return;
            }
            
            // Check if user is authenticated
            if (class_exists('Azure_Calendar_Auth')) {
                $auth = new Azure_Calendar_Auth();
                if (!$auth->is_user_authenticated($user_email)) {
                    Azure_Logger::warning("TEC Sync Scheduler: User {$user_email} not authenticated, skipping mapping {$mapping_id}", 'TEC');
                    return;
                }
            }
            
            // Get the mapping
            if (!class_exists('Azure_TEC_Calendar_Mapping_Manager')) {
                Azure_Logger::error("TEC Sync Scheduler: Mapping manager not available", 'TEC');
                return;
            }
            
            $manager = new Azure_TEC_Calendar_Mapping_Manager();
            $mapping = $manager->get_mapping_by_id($mapping_id);
            
            if (!$mapping) {
                Azure_Logger::warning("TEC Sync Scheduler: Mapping ID {$mapping_id} not found", 'TEC');
                return;
            }
            
            // Check if mapping is enabled
            if (!$mapping->sync_enabled) {
                Azure_Logger::info("TEC Sync Scheduler: Mapping ID {$mapping_id} is disabled, skipping", 'TEC');
                return;
            }
            
            // Check if schedule is enabled for this mapping
            if (!$mapping->schedule_enabled) {
                Azure_Logger::info("TEC Sync Scheduler: Schedule not enabled for mapping ID {$mapping_id}, skipping", 'TEC');
                return;
            }
            
            Azure_Logger::info("TEC Sync Scheduler: Syncing mapping {$mapping_id} from mailbox '{$mailbox_email}' using token from '{$user_email}'", 'TEC');
            
            // Get date range from mapping settings
            $lookback_days = $mapping->schedule_lookback_days ?? 30;
            $lookahead_days = $mapping->schedule_lookahead_days ?? 365;
            
            $start_date = date('Y-m-d\TH:i:s\Z', strtotime("-{$lookback_days} days"));
            $end_date = date('Y-m-d\TH:i:s\Z', strtotime("+{$lookahead_days} days"));
            
            // Execute sync for this specific calendar
            if (class_exists('Azure_TEC_Sync_Engine')) {
                $sync_engine = new Azure_TEC_Sync_Engine();
                
                // Sync this specific calendar by passing its ID
                $results = $sync_engine->sync_multiple_calendars_to_tec(
                    array($mapping->outlook_calendar_id),  // Sync only this calendar
                    $start_date,
                    $end_date,
                    $user_email,
                    $mailbox_email
                );
                
                if ($results && $results['success']) {
                    Azure_Logger::info("TEC Sync Scheduler: Scheduled sync for mapping ID {$mapping_id} completed successfully. Events synced: {$results['total_events_synced']}, Errors: {$results['total_errors']}", 'TEC');
                    
                    // Log to activity
                    Azure_Database::log_activity(
                        'tec',
                        'mapping_scheduled_sync_completed',
                        'sync',
                        null,
                        array(
                            'mapping_id' => $mapping_id,
                            'calendar_name' => $mapping->outlook_calendar_name,
                            'events_synced' => $results['total_events_synced'],
                            'errors' => $results['total_errors']
                        ),
                        'success'
                    );
                } else {
                    $error_message = isset($results['error_message']) ? $results['error_message'] : 'Unknown error';
                    Azure_Logger::error("TEC Sync Scheduler: Scheduled sync for mapping ID {$mapping_id} failed: {$error_message}", 'TEC');
                    
                    // Log error to activity
                    Azure_Database::log_activity(
                        'tec',
                        'mapping_scheduled_sync_failed',
                        'sync',
                        null,
                        array(
                            'mapping_id' => $mapping_id,
                            'calendar_name' => $mapping->outlook_calendar_name,
                            'error' => $error_message
                        ),
                        'error'
                    );
                }
            } else {
                Azure_Logger::error('TEC Sync Scheduler: TEC Sync Engine not available', 'TEC');
            }
            
        } catch (Exception $e) {
            Azure_Logger::error("TEC Sync Scheduler: Exception during scheduled sync for mapping ID {$mapping_id}: " . $e->getMessage(), 'TEC');
            
            // Log exception to activity
            Azure_Database::log_activity(
                'tec',
                'mapping_scheduled_sync_exception',
                'sync',
                null,
                array(
                    'mapping_id' => $mapping_id,
                    'exception' => $e->getMessage()
                ),
                'error'
            );
        }
    }
    
    /**
     * Get next scheduled sync time
     */
    public function get_next_sync_time() {
        $timestamp = wp_next_scheduled($this->hook_name);
        if ($timestamp) {
            return $timestamp;
        }
        return false;
    }
    
    /**
     * Check if sync is currently scheduled
     */
    public function is_sync_scheduled() {
        return wp_next_scheduled($this->hook_name) !== false;
    }
    
    /**
     * Get sync schedule frequency
     */
    public function get_sync_frequency() {
        $timestamp = wp_next_scheduled($this->hook_name);
        if (!$timestamp) {
            return false;
        }
        
        $crons = _get_cron_array();
        foreach ($crons as $time => $cron) {
            if ($time == $timestamp && isset($cron[$this->hook_name])) {
                foreach ($cron[$this->hook_name] as $event) {
                    return $event['schedule'] ?? 'unknown';
                }
            }
        }
        
        return 'unknown';
    }
    
    /**
     * Activate scheduler - set up schedules
     */
    public function activate() {
        $settings = Azure_Settings::get_all_settings();
        
        if (($settings['tec_sync_schedule_enabled'] ?? false) && ($settings['enable_tec_integration'] ?? false)) {
            $frequency = $settings['tec_sync_schedule_frequency'] ?? 'hourly';
            $this->schedule_sync($frequency);
            Azure_Logger::info('TEC Sync Scheduler: Activated with frequency ' . $frequency, 'TEC');
        }
    }
    
    /**
     * Deactivate scheduler - clear all schedules
     */
    public function deactivate() {
        $this->unschedule_sync();
        Azure_Logger::info('TEC Sync Scheduler: Deactivated', 'TEC');
    }
}

