<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * TEC Calendar Sync AJAX Handlers
 * 
 * Handles all AJAX requests for TEC-Outlook calendar synchronization
 */
class Azure_TEC_Integration_Ajax {
    
    public function __construct() {
        // Authentication handlers
        add_action('wp_ajax_azure_save_tec_calendar_email', array($this, 'ajax_save_tec_calendar_email'));
        add_action('wp_ajax_azure_tec_calendar_authorize', array($this, 'ajax_tec_calendar_authorize'));
        add_action('wp_ajax_azure_tec_calendar_check_auth', array($this, 'ajax_tec_calendar_check_auth'));
        
        // Calendar discovery handlers
        add_action('wp_ajax_azure_get_outlook_calendars_for_tec', array($this, 'ajax_get_outlook_calendars'));
        add_action('wp_ajax_azure_get_tec_categories', array($this, 'ajax_get_tec_categories'));
        add_action('wp_ajax_azure_create_tec_category', array($this, 'ajax_create_tec_category'));
        
        // Mapping handlers
        add_action('wp_ajax_azure_get_calendar_mapping', array($this, 'ajax_get_calendar_mapping'));
        add_action('wp_ajax_azure_save_calendar_mapping', array($this, 'ajax_save_calendar_mapping'));
        add_action('wp_ajax_azure_delete_calendar_mapping', array($this, 'ajax_delete_calendar_mapping'));
        add_action('wp_ajax_azure_toggle_calendar_sync', array($this, 'ajax_toggle_calendar_sync'));
        
        // Sync handlers
        add_action('wp_ajax_azure_save_tec_sync_schedule', array($this, 'ajax_save_tec_sync_schedule'));
        add_action('wp_ajax_azure_tec_manual_sync', array($this, 'ajax_tec_manual_sync'));
        add_action('wp_ajax_azure_get_sync_history', array($this, 'ajax_get_sync_history'));
        
        // Maintenance handlers
        add_action('wp_ajax_azure_tec_repair_event_metadata', array($this, 'ajax_repair_event_metadata'));
        
        Azure_Logger::debug('TEC Integration AJAX: Initialized', 'TEC');
    }
    
    /**
     * Save TEC calendar email settings (user email and mailbox email)
     */
    public function ajax_save_tec_calendar_email() {
        if (!check_ajax_referer('azure_plugin_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            Azure_Logger::warning('TEC Integration AJAX: Unauthorized attempt to save calendar email', 'TEC');
            wp_send_json_error('Unauthorized');
            return;
        }
        
        // Get both user email and mailbox email
        $user_email = sanitize_email($_POST['user_email'] ?? $_POST['email'] ?? '');
        $mailbox_email = sanitize_email($_POST['mailbox_email'] ?? '');
        
        if (empty($user_email) || !is_email($user_email)) {
            Azure_Logger::warning('TEC Integration AJAX: Invalid user email provided', 'TEC');
            wp_send_json_error('Invalid user email address');
            return;
        }
        
        if (empty($mailbox_email) || !is_email($mailbox_email)) {
            Azure_Logger::warning('TEC Integration AJAX: Invalid mailbox email provided', 'TEC');
            wp_send_json_error('Invalid mailbox email address');
            return;
        }
        
        Azure_Settings::update_setting('tec_calendar_user_email', $user_email);
        Azure_Settings::update_setting('tec_calendar_mailbox_email', $mailbox_email);
        Azure_Logger::info("TEC Integration AJAX: Saved calendar settings - User: {$user_email}, Mailbox: {$mailbox_email}", 'TEC');
        
        wp_send_json_success(array(
            'user_email' => $user_email,
            'mailbox_email' => $mailbox_email
        ));
    }
    
    /**
     * Generate OAuth authorization URL for TEC calendar
     */
    public function ajax_tec_calendar_authorize() {
        if (!check_ajax_referer('azure_plugin_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            Azure_Logger::warning('TEC Integration AJAX: Unauthorized calendar auth attempt', 'TEC');
            wp_send_json_error('Unauthorized');
            return;
        }
        
        $email = sanitize_email($_POST['user_email'] ?? $_POST['email'] ?? '');
        
        if (empty($email)) {
            wp_send_json_error('Email is required');
            return;
        }
        
        if (!class_exists('Azure_Calendar_Auth')) {
            Azure_Logger::error('TEC Integration AJAX: Calendar auth class not found', 'TEC');
            wp_send_json_error('Calendar authentication not available');
            return;
        }
        
        try {
            $auth = new Azure_Calendar_Auth();
            
            // Check if credentials are configured
            $settings = Azure_Settings::get_all_settings();
            $credentials = Azure_Settings::get_credentials('calendar');
            
            if (empty($credentials['client_id']) || empty($credentials['tenant_id'])) {
                Azure_Logger::error("TEC Integration AJAX: Calendar credentials not configured - Client ID or Tenant ID missing", 'TEC');
                wp_send_json_error('Calendar credentials not configured. Please set up Azure credentials in the main settings.');
                return;
            }
            
            $state = wp_generate_password(32, false);
            $auth_url = $auth->get_user_authorization_url($email, $state);
            
            if ($auth_url) {
                Azure_Logger::info("TEC Integration AJAX: Generated auth URL for {$email}", 'TEC');
                wp_send_json_success(array('auth_url' => $auth_url));
            } else {
                Azure_Logger::error("TEC Integration AJAX: Failed to generate auth URL for {$email} - get_user_authorization_url returned false", 'TEC');
                wp_send_json_error('Failed to generate authorization URL. Check Azure credentials configuration.');
            }
        } catch (Exception $e) {
            Azure_Logger::error("TEC Integration AJAX: Exception generating auth URL: " . $e->getMessage(), 'TEC');
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Check TEC calendar authentication status
     */
    public function ajax_tec_calendar_check_auth() {
        if (!check_ajax_referer('azure_plugin_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        $email = sanitize_email($_POST['user_email'] ?? $_POST['email'] ?? '');
        
        if (empty($email)) {
            wp_send_json_error('Email is required');
            return;
        }
        
        if (!class_exists('Azure_Calendar_Auth')) {
            wp_send_json_error('Calendar authentication not available');
            return;
        }
        
        $auth = new Azure_Calendar_Auth();
        $authenticated = $auth->has_valid_user_token($email);
        
        if ($authenticated) {
            Azure_Logger::debug("TEC Integration AJAX: Calendar {$email} is authenticated", 'TEC');
            wp_send_json_success(array('authenticated' => true));
        } else {
            wp_send_json_error('Not authenticated');
        }
    }
    
    /**
     * Get Outlook calendars for TEC mapping
     */
    public function ajax_get_outlook_calendars() {
        if (!check_ajax_referer('azure_plugin_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            Azure_Logger::warning('TEC Integration AJAX: Unauthorized calendar fetch attempt', 'TEC');
            wp_send_json_error('Unauthorized');
            return;
        }
        
        $settings = Azure_Settings::get_all_settings();
        $user_email = $settings['tec_calendar_user_email'] ?? '';
        $mailbox_email = $settings['tec_calendar_mailbox_email'] ?? '';
        
        if (empty($user_email)) {
            wp_send_json_error('User email not configured');
            return;
        }
        
        if (empty($mailbox_email)) {
            wp_send_json_error('Shared mailbox email not configured');
            return;
        }
        
        if (!class_exists('Azure_Calendar_GraphAPI')) {
            Azure_Logger::error('TEC Integration AJAX: Graph API class not found', 'TEC');
            wp_send_json_error('Calendar API not available');
            return;
        }
        
        try {
            $graph_api = new Azure_Calendar_GraphAPI();
            // Use get_mailbox_calendars to access shared mailbox calendars with user's token
            $calendars = $graph_api->get_mailbox_calendars($user_email, $mailbox_email, true);
            
            if (is_array($calendars) && !empty($calendars)) {
                Azure_Logger::info("TEC Integration AJAX: Retrieved " . count($calendars) . " calendars from mailbox {$mailbox_email} using {$user_email}", 'TEC');
                wp_send_json_success($calendars);
            } else {
                Azure_Logger::warning("TEC Integration AJAX: No calendars found for mailbox {$mailbox_email}", 'TEC');
                wp_send_json_success(array());
            }
        } catch (Exception $e) {
            Azure_Logger::error("TEC Integration AJAX: Failed to fetch calendars: " . $e->getMessage(), 'TEC');
            wp_send_json_error('Failed to fetch calendars: ' . $e->getMessage());
        }
    }
    
    /**
     * Get TEC event categories
     */
    public function ajax_get_tec_categories() {
        if (!check_ajax_referer('azure_plugin_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        if (!class_exists('Tribe__Events__Main')) {
            wp_send_json_error('The Events Calendar plugin not active');
            return;
        }
        
        $categories = get_terms(array(
            'taxonomy' => 'tribe_events_cat',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ));
        
        if (is_wp_error($categories)) {
            Azure_Logger::error('TEC Integration AJAX: Failed to get categories: ' . $categories->get_error_message(), 'TEC');
            wp_send_json_error('Failed to retrieve categories');
            return;
        }
        
        $formatted_categories = array();
        foreach ($categories as $category) {
            $formatted_categories[] = array(
                'term_id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'count' => $category->count
            );
        }
        
        Azure_Logger::debug('TEC Integration AJAX: Retrieved ' . count($formatted_categories) . ' TEC categories', 'TEC');
        wp_send_json_success($formatted_categories);
    }
    
    /**
     * Create new TEC event category
     */
    public function ajax_create_tec_category() {
        if (!check_ajax_referer('azure_plugin_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        if (!class_exists('Tribe__Events__Main')) {
            wp_send_json_error('The Events Calendar plugin not active');
            return;
        }
        
        $category_name = sanitize_text_field($_POST['category_name'] ?? '');
        
        if (empty($category_name)) {
            wp_send_json_error('Category name is required');
            return;
        }
        
        // Check if category already exists
        $existing = term_exists($category_name, 'tribe_events_cat');
        if ($existing) {
            Azure_Logger::info("TEC Integration AJAX: Category '{$category_name}' already exists", 'TEC');
            wp_send_json_success(array(
                'term_id' => $existing['term_id'],
                'name' => $category_name,
                'existed' => true
            ));
            return;
        }
        
        // Create new category
        $result = wp_insert_term($category_name, 'tribe_events_cat');
        
        if (is_wp_error($result)) {
            Azure_Logger::error("TEC Integration AJAX: Failed to create category '{$category_name}': " . $result->get_error_message(), 'TEC');
            wp_send_json_error('Failed to create category: ' . $result->get_error_message());
            return;
        }
        
        Azure_Logger::info("TEC Integration AJAX: Created category '{$category_name}' with ID {$result['term_id']}", 'TEC');
        wp_send_json_success(array(
            'term_id' => $result['term_id'],
            'name' => $category_name,
            'existed' => false
        ));
    }
    
    /**
     * Get calendar mapping by ID
     */
    public function ajax_get_calendar_mapping() {
        if (!check_ajax_referer('azure_plugin_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        $mapping_id = intval($_POST['mapping_id'] ?? 0);
        
        if (!$mapping_id) {
            wp_send_json_error('Invalid mapping ID');
            return;
        }
        
        if (!class_exists('Azure_TEC_Calendar_Mapping_Manager')) {
            wp_send_json_error('Mapping manager not available');
            return;
        }
        
        $manager = new Azure_TEC_Calendar_Mapping_Manager();
        $mapping = $manager->get_mapping_by_id($mapping_id);
        
        if ($mapping) {
            wp_send_json_success((array)$mapping);
        } else {
            wp_send_json_error('Mapping not found');
        }
    }
    
    /**
     * Save calendar mapping (create or update)
     */
    public function ajax_save_calendar_mapping() {
        if (!check_ajax_referer('azure_plugin_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        if (!class_exists('Azure_TEC_Calendar_Mapping_Manager')) {
            wp_send_json_error('Mapping manager not available');
            return;
        }
        
        $mapping_id = intval($_POST['mapping_id'] ?? 0);
        $outlook_calendar_id = sanitize_text_field($_POST['outlook_calendar_id'] ?? '');
        $outlook_calendar_name = sanitize_text_field($_POST['outlook_calendar_name'] ?? '');
        $tec_category_id = intval($_POST['tec_category_id'] ?? 0);
        $tec_category_name = sanitize_text_field($_POST['tec_category_name'] ?? '');
        $sync_enabled = intval($_POST['sync_enabled'] ?? 1);
        
        // Get schedule parameters
        $schedule_enabled = intval($_POST['schedule_enabled'] ?? 0);
        $schedule_frequency = sanitize_text_field($_POST['schedule_frequency'] ?? 'hourly');
        $schedule_lookback_days = intval($_POST['schedule_lookback_days'] ?? 30);
        $schedule_lookahead_days = intval($_POST['schedule_lookahead_days'] ?? 365);
        
        if (empty($outlook_calendar_id) || empty($outlook_calendar_name) || !$tec_category_id || empty($tec_category_name)) {
            wp_send_json_error('Missing required fields');
            return;
        }
        
        $manager = new Azure_TEC_Calendar_Mapping_Manager();
        
        if ($mapping_id) {
            // Update existing mapping
            $result = $manager->update_mapping(
                $mapping_id,
                $outlook_calendar_id,
                $outlook_calendar_name,
                $tec_category_id,
                $tec_category_name,
                $sync_enabled,
                $schedule_enabled,
                $schedule_frequency,
                $schedule_lookback_days,
                $schedule_lookahead_days
            );
            
            if ($result) {
                Azure_Logger::info("TEC Integration AJAX: Updated mapping ID {$mapping_id}", 'TEC');
                wp_send_json_success(array('mapping_id' => $mapping_id, 'action' => 'updated'));
            } else {
                global $wpdb;
                $error_msg = $wpdb->last_error ? $wpdb->last_error : 'Failed to update mapping';
                Azure_Logger::error("TEC Integration AJAX: Failed to update mapping ID {$mapping_id}. Error: {$error_msg}", 'TEC');
                wp_send_json_error($error_msg);
            }
        } else {
            // Create new mapping
            $new_id = $manager->create_mapping(
                $outlook_calendar_id,
                $outlook_calendar_name,
                $tec_category_id,
                $tec_category_name,
                $sync_enabled,
                $schedule_enabled,
                $schedule_frequency,
                $schedule_lookback_days,
                $schedule_lookahead_days
            );
            
            if ($new_id) {
                Azure_Logger::info("TEC Integration AJAX: Created mapping ID {$new_id}", 'TEC');
                wp_send_json_success(array('mapping_id' => $new_id, 'action' => 'created'));
            } else {
                global $wpdb;
                $error_msg = $wpdb->last_error ? $wpdb->last_error : 'Failed to create mapping';
                Azure_Logger::error("TEC Integration AJAX: Failed to create mapping. Error: {$error_msg}", 'TEC');
                wp_send_json_error($error_msg);
            }
        }
    }
    
    /**
     * Delete calendar mapping
     */
    public function ajax_delete_calendar_mapping() {
        if (!check_ajax_referer('azure_plugin_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        $mapping_id = intval($_POST['mapping_id'] ?? 0);
        
        if (!$mapping_id) {
            wp_send_json_error('Invalid mapping ID');
            return;
        }
        
        if (!class_exists('Azure_TEC_Calendar_Mapping_Manager')) {
            wp_send_json_error('Mapping manager not available');
            return;
        }
        
        $manager = new Azure_TEC_Calendar_Mapping_Manager();
        $result = $manager->delete_mapping($mapping_id);
        
        if ($result) {
            Azure_Logger::info("TEC Integration AJAX: Deleted mapping ID {$mapping_id}", 'TEC');
            wp_send_json_success(array('mapping_id' => $mapping_id));
        } else {
            wp_send_json_error('Failed to delete mapping');
        }
    }
    
    /**
     * Toggle sync enabled status for mapping
     */
    public function ajax_toggle_calendar_sync() {
        if (!check_ajax_referer('azure_plugin_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        $mapping_id = intval($_POST['mapping_id'] ?? 0);
        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'true' ? 1 : 0;
        
        if (!$mapping_id) {
            wp_send_json_error('Invalid mapping ID');
            return;
        }
        
        if (!class_exists('Azure_TEC_Calendar_Mapping_Manager')) {
            wp_send_json_error('Mapping manager not available');
            return;
        }
        
        global $wpdb;
        $table = Azure_Database::get_table_name('tec_calendar_mappings');
        
        $result = $wpdb->update(
            $table,
            array('sync_enabled' => $enabled),
            array('id' => $mapping_id),
            array('%d'),
            array('%d')
        );
        
        if ($result !== false) {
            $status = $enabled ? 'enabled' : 'disabled';
            Azure_Logger::info("TEC Integration AJAX: {$status} sync for mapping ID {$mapping_id}", 'TEC');
            wp_send_json_success(array('mapping_id' => $mapping_id, 'enabled' => $enabled));
        } else {
            wp_send_json_error('Failed to update sync status');
        }
    }
    
    /**
     * Save TEC sync schedule settings
     */
    public function ajax_save_tec_sync_schedule() {
        if (!check_ajax_referer('azure_plugin_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        $sync_enabled = isset($_POST['sync_enabled']) && $_POST['sync_enabled'] === 'true' ? true : false;
        $frequency = sanitize_text_field($_POST['frequency'] ?? 'hourly');
        $lookback_days = intval($_POST['lookback_days'] ?? 30);
        $lookahead_days = intval($_POST['lookahead_days'] ?? 365);
        
        // Update settings
        Azure_Settings::update_setting('tec_sync_schedule_enabled', $sync_enabled);
        Azure_Settings::update_setting('tec_sync_schedule_frequency', $frequency);
        Azure_Settings::update_setting('tec_sync_lookback_days', $lookback_days);
        Azure_Settings::update_setting('tec_sync_lookahead_days', $lookahead_days);
        
        Azure_Logger::info("TEC Integration AJAX: Updated sync schedule - Enabled: {$sync_enabled}, Frequency: {$frequency}", 'TEC');
        
        // Trigger scheduler to update cron jobs
        if (class_exists('Azure_TEC_Sync_Scheduler')) {
            $scheduler = Azure_TEC_Sync_Scheduler::get_instance();
            
            if ($sync_enabled) {
                // Schedule with the selected frequency
                $scheduler->schedule_sync($frequency);
                Azure_Logger::info("TEC Integration AJAX: Scheduled sync with frequency: {$frequency}", 'TEC');
            } else {
                // Disable scheduling
                $scheduler->unschedule_sync();
                Azure_Logger::info("TEC Integration AJAX: Unscheduled sync", 'TEC');
            }
        }
        
        wp_send_json_success(array(
            'sync_enabled' => $sync_enabled,
            'frequency' => $frequency,
            'lookback_days' => $lookback_days,
            'lookahead_days' => $lookahead_days
        ));
    }
    
    /**
     * Execute manual TEC sync
     */
    public function ajax_tec_manual_sync() {
        // Verify nonce - return false instead of dying
        if (!check_ajax_referer('azure_plugin_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        Azure_Logger::info('TEC Integration AJAX: Manual sync initiated', 'TEC');
        
        // Check if TEC is installed
        if (!class_exists('Tribe__Events__Main')) {
            wp_send_json_error('The Events Calendar plugin not active');
            return;
        }
        
        // Check if sync engine is available
        if (!class_exists('Azure_TEC_Sync_Engine')) {
            Azure_Logger::error('TEC Integration AJAX: Sync engine not available', 'TEC');
            wp_send_json_error('Sync engine not available');
            return;
        }
        
        // Get settings for date range
        $settings = Azure_Settings::get_all_settings();
        $user_email = $settings['tec_calendar_user_email'] ?? '';
        $mailbox_email = $settings['tec_calendar_mailbox_email'] ?? '';
        $lookback_days = intval($settings['tec_sync_lookback_days'] ?? 30);
        $lookahead_days = intval($settings['tec_sync_lookahead_days'] ?? 365);
        
        if (empty($user_email)) {
            wp_send_json_error('Calendar user email not configured');
            return;
        }
        
        if (empty($mailbox_email)) {
            wp_send_json_error('Shared mailbox email not configured');
            return;
        }
        
        Azure_Logger::info("TEC Integration AJAX: Syncing from mailbox '{$mailbox_email}' using token from '{$user_email}'", 'TEC');
        
        // Calculate date range - use ISO 8601 format for Graph API
        $start_date = date('Y-m-d\TH:i:s\Z', strtotime("-{$lookback_days} days"));
        $end_date = date('Y-m-d\TH:i:s\Z', strtotime("+{$lookahead_days} days"));
        
        Azure_Logger::info("TEC Integration AJAX: Starting sync - User: {$user_email}, Mailbox: {$mailbox_email}, Start: {$start_date}, End: {$end_date}", 'TEC');
        
        try {
            $sync_engine = new Azure_TEC_Sync_Engine();
            $results = $sync_engine->sync_multiple_calendars_to_tec(null, $start_date, $end_date, $user_email, $mailbox_email);
            
            if ($results && $results['success']) {
                Azure_Logger::info("TEC Integration AJAX: Manual sync completed - Calendars: {$results['total_calendars']}, Events: {$results['total_events_synced']}, Errors: {$results['total_errors']}", 'TEC');
                if (class_exists('Azure_Database')) {
                    Azure_Database::log_activity(
                        'tec',
                        'manual_sync_completed',
                        'sync',
                        null,
                        array(
                            'calendars' => $results['total_calendars'],
                            'events_synced' => $results['total_events_synced'],
                            'errors' => $results['total_errors']
                        ),
                        'success'
                    );
                }
                wp_send_json_success(array(
                    'calendars_synced' => $results['total_calendars'],
                    'total_events_synced' => $results['total_events_synced'],
                    'total_errors' => $results['total_errors'],
                    'calendar_results' => $results['calendar_results'] ?? array()
                ));
            } else {
                Azure_Logger::error('TEC Integration AJAX: Manual sync failed: ' . ($results['message'] ?? 'Unknown error'), 'TEC');
                wp_send_json_error($results['message'] ?? 'Sync failed');
            }
        } catch (Exception $e) {
            Azure_Logger::error('TEC Integration AJAX: Manual sync exception: ' . $e->getMessage(), 'TEC');
            wp_send_json_error('Sync error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get sync history from activity_log (module=tec, action/details/status schema)
     */
    public function ajax_get_sync_history() {
        if (!check_ajax_referer('azure_plugin_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        global $wpdb;
        $activity_table = Azure_Database::get_table_name('activity_log');
        if (!$activity_table) {
            wp_send_json_success(array());
            return;
        }
        
        // activity_log has: module, action, details (JSON), status, created_at (no category/level/message)
        $history = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, module, action, details, status, created_at FROM {$activity_table}
                 WHERE LOWER(module) = %s
                 AND (action LIKE %s OR action = %s OR action LIKE %s)
                 ORDER BY created_at DESC LIMIT 50",
                'tec',
                '%sync%',
                'tec_log',
                '%scheduled%'
            ),
            ARRAY_A
        );
        
        $formatted_history = array();
        foreach ($history ?: array() as $record) {
            $details = !empty($record['details']) ? json_decode($record['details'], true) : null;
            $type = 'Manual';
            $calendars = '—';
            $events_count = 0;
            $status = $record['status'] === 'error' ? 'failed' : (strpos($record['status'], 'fail') !== false ? 'failed' : 'success');
            $message = '';
            
            // From Azure_Database::log_activity (scheduler): action = scheduled_sync_completed, mapping_scheduled_sync_completed, etc.; details = array(calendars, events_synced, errors, ...)
            if (is_array($details)) {
                if (isset($details['events_synced'])) {
                    $events_count = (int) $details['events_synced'];
                }
                if (isset($details['calendars'])) {
                    $calendars = is_numeric($details['calendars']) ? (int) $details['calendars'] . ' calendar(s)' : $details['calendars'];
                }
                if (isset($details['calendar_name'])) {
                    $calendars = $details['calendar_name'];
                }
                if (isset($details['message'])) {
                    $message = $details['message'];
                }
            }
            
            if (strpos($record['action'], 'scheduled') !== false || strpos($record['action'], 'mapping_scheduled') !== false) {
                $type = 'Scheduled';
            } elseif ($record['action'] === 'manual_sync_completed' || $record['action'] === 'tec_log') {
                $type = 'Manual';
                if ($record['action'] === 'tec_log' && is_array($details) && isset($details['message'])) {
                    $message = $details['message'];
                    if (preg_match('/(\d+)\s+events?/i', $message, $m)) {
                        $events_count = (int) $m[1];
                    }
                    if (strpos($message, 'failed') !== false || strpos($message, 'error') !== false) {
                        $status = 'failed';
                    }
                }
            }
            
            $formatted_history[] = array(
                'timestamp' => date('M j, Y g:i A', strtotime($record['created_at'])),
                'type' => $type,
                'calendars' => $calendars,
                'events_count' => $events_count,
                'status' => $status,
                'message' => $message
            );
        }
        
        wp_send_json_success($formatted_history);
    }
    
    /**
     * Repair event metadata for existing synced events
     * This adds missing UTC dates, timezone, and duration fields required by TEC
     */
    public function ajax_repair_event_metadata() {
        if (!check_ajax_referer('azure_plugin_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        Azure_Logger::info('TEC Integration AJAX: Starting event metadata repair', 'TEC');
        
        try {
            global $wpdb;
            
            // Get all events that were synced from Outlook
            $events = $wpdb->get_results(
                "SELECT p.ID, pm_start.meta_value as start_date, pm_end.meta_value as end_date
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm_outlook ON p.ID = pm_outlook.post_id AND pm_outlook.meta_key = '_outlook_event_id'
                 LEFT JOIN {$wpdb->postmeta} pm_start ON p.ID = pm_start.post_id AND pm_start.meta_key = '_EventStartDate'
                 LEFT JOIN {$wpdb->postmeta} pm_end ON p.ID = pm_end.post_id AND pm_end.meta_key = '_EventEndDate'
                 WHERE p.post_type = 'tribe_events' AND p.post_status = 'publish'"
            );
            
            if (empty($events)) {
                wp_send_json_success(array(
                    'message' => 'No synced events found to repair.',
                    'repaired' => 0,
                    'errors' => 0
                ));
                return;
            }
            
            $timezone = get_option('timezone_string', 'UTC');
            if (empty($timezone)) {
                $timezone = 'UTC';
            }
            
            $repaired = 0;
            $errors = 0;
            
            foreach ($events as $event) {
                try {
                    $event_id = $event->ID;
                    $start_date = $event->start_date;
                    $end_date = $event->end_date;
                    
                    if (empty($start_date) || empty($end_date)) {
                        $errors++;
                        continue;
                    }
                    
                    // Set UTC versions
                    try {
                        $start_dt = new DateTime($start_date, new DateTimeZone($timezone));
                        $start_dt->setTimezone(new DateTimeZone('UTC'));
                        update_post_meta($event_id, '_EventStartDateUTC', $start_dt->format('Y-m-d H:i:s'));
                    } catch (Exception $e) {
                        update_post_meta($event_id, '_EventStartDateUTC', $start_date);
                    }
                    
                    try {
                        $end_dt = new DateTime($end_date, new DateTimeZone($timezone));
                        $end_dt->setTimezone(new DateTimeZone('UTC'));
                        update_post_meta($event_id, '_EventEndDateUTC', $end_dt->format('Y-m-d H:i:s'));
                    } catch (Exception $e) {
                        update_post_meta($event_id, '_EventEndDateUTC', $end_date);
                    }
                    
                    // Set timezone meta
                    update_post_meta($event_id, '_EventTimezone', $timezone);
                    
                    // Get timezone abbreviation
                    try {
                        $tz = new DateTimeZone($timezone);
                        $dt = new DateTime('now', $tz);
                        $abbr = $dt->format('T');
                    } catch (Exception $e) {
                        $abbr = 'UTC';
                    }
                    update_post_meta($event_id, '_EventTimezoneAbbr', $abbr);
                    
                    // Set duration
                    $duration = strtotime($end_date) - strtotime($start_date);
                    update_post_meta($event_id, '_EventDuration', max(0, $duration));
                    
                    $repaired++;
                    
                } catch (Exception $e) {
                    Azure_Logger::error("TEC Integration AJAX: Error repairing event {$event->ID}: " . $e->getMessage(), 'TEC');
                    $errors++;
                }
            }
            
            Azure_Logger::info("TEC Integration AJAX: Metadata repair complete. Repaired: {$repaired}, Errors: {$errors}", 'TEC');
            
            wp_send_json_success(array(
                'message' => "Repaired metadata for {$repaired} events.",
                'repaired' => $repaired,
                'errors' => $errors
            ));
            
        } catch (Exception $e) {
            Azure_Logger::error('TEC Integration AJAX: Metadata repair failed - ' . $e->getMessage(), 'TEC');
            wp_send_json_error('Repair failed: ' . $e->getMessage());
        }
    }
}