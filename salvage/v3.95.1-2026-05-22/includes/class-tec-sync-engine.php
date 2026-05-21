<?php
/**
 * The Events Calendar Sync Engine
 * Handles bidirectional synchronization between TEC and Outlook Calendar
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_TEC_Sync_Engine {
    
    private $graph_api;
    private $data_mapper;
    private $calendar_id;
    
    public function __construct() {
        // Initialize Graph API client
        if (class_exists('Azure_Calendar_GraphAPI')) {
            $this->graph_api = new Azure_Calendar_GraphAPI();
        }
        
        // Initialize data mapper
        if (class_exists('Azure_TEC_Data_Mapper')) {
            $this->data_mapper = new Azure_TEC_Data_Mapper();
        }
        
        // Get default calendar ID from settings
        $settings = Azure_Settings::get_all_settings();
        $this->calendar_id = $settings['tec_outlook_calendar_id'] ?? 'primary';
        
        Azure_Logger::debug('TEC Sync Engine: Initialized with calendar ID: ' . $this->calendar_id, 'TEC');
    }
    
    /**
     * Sync a TEC event to Outlook
     */
    public function sync_tec_to_outlook($tec_event_id) {
        if (!$this->graph_api || !$this->data_mapper) {
            Azure_Logger::error('TEC Sync Engine: Required components not available', 'TEC');
            return false;
        }
        
        Azure_Logger::info("TEC Sync Engine: Starting sync to Outlook for TEC event {$tec_event_id}", 'TEC');
        
        try {
            // Get TEC event data
            $tec_event = get_post($tec_event_id);
            
            if (!$tec_event || $tec_event->post_type !== 'tribe_events') {
                Azure_Logger::error("TEC Sync Engine: Invalid TEC event ID: {$tec_event_id}", 'TEC');
                return false;
            }
            
            // Check if event already exists in Outlook
            $outlook_event_id = get_post_meta($tec_event_id, '_outlook_event_id', true);
            
            // Map TEC event to Outlook format
            $outlook_event_data = $this->data_mapper->map_tec_to_outlook($tec_event_id);
            
            if (!$outlook_event_data) {
                Azure_Logger::error("TEC Sync Engine: Failed to map TEC event {$tec_event_id} to Outlook format", 'TEC');
                return false;
            }
            
            if ($outlook_event_id) {
                // Update existing Outlook event
                Azure_Logger::debug("TEC Sync Engine: Updating existing Outlook event {$outlook_event_id}", 'TEC');
                $result = $this->graph_api->update_event($this->calendar_id, $outlook_event_id, $outlook_event_data);
                
                if ($result) {
                    Azure_Logger::info("TEC Sync Engine: Successfully updated Outlook event {$outlook_event_id}", 'TEC');
                    $this->update_sync_timestamp($tec_event_id);
                    return true;
                } else {
                    // Event might have been deleted in Outlook, try creating new one
                    Azure_Logger::warning("TEC Sync Engine: Failed to update Outlook event, trying to create new one", 'TEC');
                    delete_post_meta($tec_event_id, '_outlook_event_id');
                    $outlook_event_id = null;
                }
            }
            
            if (!$outlook_event_id) {
                // Create new Outlook event
                Azure_Logger::debug("TEC Sync Engine: Creating new Outlook event", 'TEC');
                $result = $this->graph_api->create_event($this->calendar_id, $outlook_event_data);
                
                if ($result && isset($result['id'])) {
                    $new_outlook_event_id = $result['id'];
                    Azure_Logger::info("TEC Sync Engine: Successfully created Outlook event {$new_outlook_event_id}", 'TEC');
                    
                    // Store Outlook event ID in TEC event metadata
                    update_post_meta($tec_event_id, '_outlook_event_id', $new_outlook_event_id);
                    $this->update_sync_timestamp($tec_event_id);
                    
                    return true;
                } else {
                    Azure_Logger::error("TEC Sync Engine: Failed to create Outlook event", 'TEC');
                    return false;
                }
            }
            
        } catch (Exception $e) {
            Azure_Logger::error("TEC Sync Engine: Exception syncing TEC event {$tec_event_id}: " . $e->getMessage(), 'TEC');
            return false;
        }
        
        return false;
    }
    
    /**
     * Sync multiple calendars to TEC (NEW - for multi-calendar support)
     * @param array $calendar_ids Optional array of calendar IDs to sync (null = all enabled)
     * @param string $start_date Start date for sync range
     * @param string $end_date End date for sync range
     * @param string $user_email The authenticated user's email (to get token)
     * @param string $mailbox_email Optional shared mailbox email (if different from user)
     */
    public function sync_multiple_calendars_to_tec($calendar_ids = null, $start_date = null, $end_date = null, $user_email = null, $mailbox_email = null) {
        if (!class_exists('Azure_TEC_Calendar_Mapping_Manager')) {
            Azure_Logger::error('TEC Sync Engine: Calendar Mapping Manager not available', 'TEC');
            return false;
        }
        
        $mapping_manager = new Azure_TEC_Calendar_Mapping_Manager();
        
        // Get enabled calendars if none specified
        if (!$calendar_ids) {
            $mappings = $mapping_manager->get_enabled_calendars();
            if (empty($mappings)) {
                Azure_Logger::info('TEC Sync Engine: No enabled calendars to sync', 'TEC');
                return array(
                    'success' => true,
                    'total_calendars' => 0,
                    'total_events_synced' => 0,
                    'total_errors' => 0,
                    'calendar_results' => array()
                );
            }
            $calendar_ids = array_column($mappings, 'outlook_calendar_id');
        }
        
        Azure_Logger::info('TEC Sync Engine: Starting multi-calendar sync for ' . count($calendar_ids) . ' calendars', 'TEC');
        
        $overall_results = array(
            'success' => true,
            'total_calendars' => count($calendar_ids),
            'total_events_synced' => 0,
            'total_errors' => 0,
            'calendar_results' => array()
        );
        
        foreach ($calendar_ids as $calendar_id) {
            $mapping = $mapping_manager->get_mapping_by_calendar_id($calendar_id);
            
            if (!$mapping) {
                Azure_Logger::warning("TEC Sync Engine: No mapping found for calendar {$calendar_id}, skipping", 'TEC');
                continue;
            }
            
            Azure_Logger::info("TEC Sync Engine: Syncing calendar '{$mapping->outlook_calendar_name}' to category '{$mapping->tec_category_name}'", 'TEC');
            
            $result = $this->sync_single_calendar_to_tec(
                $calendar_id,
                $mapping->tec_category_name,
                $start_date,
                $end_date,
                $user_email,
                $mailbox_email
            );
            
            $overall_results['calendar_results'][$calendar_id] = $result;
            $overall_results['total_events_synced'] += $result['events_synced'];
            $overall_results['total_errors'] += $result['errors'];
            
            if (!$result['success']) {
                $overall_results['success'] = false;
            }
            
            // Update last sync timestamp for this calendar
            $mapping_manager->update_last_sync($calendar_id);
        }
        
        Azure_Logger::info("TEC Sync Engine: Multi-calendar sync completed. Total events: {$overall_results['total_events_synced']}, Errors: {$overall_results['total_errors']}", 'TEC');
        
        return $overall_results;
    }
    
    /**
     * Sync single calendar to TEC with category assignment
     * @param string $calendar_id The Outlook calendar ID
     * @param string $tec_category_name The TEC category to assign events to
     * @param string $start_date Start date for sync range
     * @param string $end_date End date for sync range
     * @param string $user_email The authenticated user's email (to get token)
     * @param string $mailbox_email Optional shared mailbox email (if different from user)
     */
    public function sync_single_calendar_to_tec($calendar_id, $tec_category_name, $start_date = null, $end_date = null, $user_email = null, $mailbox_email = null) {
        if (!$this->graph_api || !$this->data_mapper) {
            return array(
                'success' => false,
                'calendar_id' => $calendar_id,
                'events_synced' => 0,
                'errors' => 0,
                'error_message' => 'Required components not available'
            );
        }
        
        Azure_Logger::info("TEC Sync Engine: Starting sync for calendar {$calendar_id} to category '{$tec_category_name}'", 'TEC');
        
        try {
            // Default date ranges from settings
            if (!$start_date) {
                $lookback_days = Azure_Settings::get_setting('tec_sync_lookback_days', 30);
                $start_date = date('Y-m-d\TH:i:s\Z', strtotime("-{$lookback_days} days"));
            }
            if (!$end_date) {
                $lookahead_days = Azure_Settings::get_setting('tec_sync_lookahead_days', 365);
                $end_date = date('Y-m-d\TH:i:s\Z', strtotime("+{$lookahead_days} days"));
            }
            
            // Get events from Outlook for this specific calendar
            // If mailbox_email is provided, use it to access the shared mailbox's calendars
            Azure_Logger::info("TEC Sync Engine: Fetching events - Calendar: {$calendar_id}, User: {$user_email}, Mailbox: {$mailbox_email}, Start: {$start_date}, End: {$end_date}", 'TEC');
            
            $outlook_events = $this->graph_api->get_calendar_events(
                $calendar_id,
                $start_date,
                $end_date,
                500,   // max events - increase from default
                true,  // force refresh
                $user_email,  // use specific user's token
                $mailbox_email  // access this mailbox's calendars
            );
            
            $event_count = is_array($outlook_events) ? count($outlook_events) : 'null/false';
            Azure_Logger::info("TEC Sync Engine: Received {$event_count} events from Graph API for calendar {$calendar_id}", 'TEC');
            
            // Log first event for debugging if we have any
            if (is_array($outlook_events) && !empty($outlook_events)) {
                Azure_Logger::debug("TEC Sync Engine: First event sample: " . json_encode(array_slice($outlook_events[0], 0, 5)), 'TEC');
            }
            
            if (!$outlook_events || empty($outlook_events)) {
                Azure_Logger::info("TEC Sync Engine: No events found for calendar {$calendar_id}", 'TEC');
                return array(
                    'success' => true,
                    'calendar_id' => $calendar_id,
                    'events_synced' => 0,
                    'errors' => 0
                );
            }
            
            $synced_count = 0;
            $error_count = 0;
            
            foreach ($outlook_events as $outlook_event) {
                try {
                    $result = $this->sync_single_outlook_event_to_tec_with_category(
                        $outlook_event,
                        $calendar_id,
                        $tec_category_name
                    );
                    
                    if ($result) {
                        $synced_count++;
                    } else {
                        $error_count++;
                    }
                } catch (Exception $e) {
                    Azure_Logger::error("TEC Sync Engine: Exception syncing event {$outlook_event['id']}: " . $e->getMessage(), 'TEC');
                    $error_count++;
                }
            }
            
            Azure_Logger::info("TEC Sync Engine: Calendar {$calendar_id} sync completed. Synced: {$synced_count}, Errors: {$error_count}", 'TEC');
            
            return array(
                'success' => true,
                'calendar_id' => $calendar_id,
                'events_synced' => $synced_count,
                'errors' => $error_count
            );
            
        } catch (Exception $e) {
            Azure_Logger::error("TEC Sync Engine: Exception during calendar {$calendar_id} sync: " . $e->getMessage(), 'TEC');
            return array(
                'success' => false,
                'calendar_id' => $calendar_id,
                'events_synced' => 0,
                'errors' => 1,
                'error_message' => $e->getMessage()
            );
        }
    }
    
    /**
     * Sync Outlook events to TEC (Legacy method - kept for backward compatibility)
     */
    public function sync_outlook_to_tec($start_date = null, $end_date = null) {
        if (!$this->graph_api || !$this->data_mapper) {
            Azure_Logger::error('TEC Sync Engine: Required components not available', 'TEC');
            return false;
        }
        
        Azure_Logger::info('TEC Sync Engine: Starting sync from Outlook to TEC', 'TEC');
        
        try {
            // Default to next 30 days if no date range specified
            if (!$start_date) {
                $start_date = date('Y-m-d\TH:i:s\Z');
            }
            if (!$end_date) {
                $end_date = date('Y-m-d\TH:i:s\Z', strtotime('+30 days'));
            }
            
            // Get events from Outlook
            $outlook_events = $this->graph_api->get_calendar_events($this->calendar_id, $start_date, $end_date, null, true);
            
            if (!$outlook_events) {
                Azure_Logger::info('TEC Sync Engine: No Outlook events found to sync', 'TEC');
                return true;
            }
            
            $synced_count = 0;
            $error_count = 0;
            
            foreach ($outlook_events as $outlook_event) {
                try {
                    $result = $this->sync_single_outlook_event_to_tec($outlook_event);
                    
                    if ($result) {
                        $synced_count++;
                    } else {
                        $error_count++;
                    }
                } catch (Exception $e) {
                    Azure_Logger::error("TEC Sync Engine: Exception syncing Outlook event {$outlook_event['id']}: " . $e->getMessage(), 'TEC');
                    $error_count++;
                }
            }
            
            Azure_Logger::info("TEC Sync Engine: Outlook to TEC sync completed. Synced: {$synced_count}, Errors: {$error_count}", 'TEC');
            
            return true;
            
        } catch (Exception $e) {
            Azure_Logger::error('TEC Sync Engine: Exception during Outlook to TEC sync: ' . $e->getMessage(), 'TEC');
            return false;
        }
    }
    
    /**
     * Sync single Outlook event to TEC with category assignment
     */
    private function sync_single_outlook_event_to_tec_with_category($outlook_event, $calendar_id, $tec_category_name) {
        if (!isset($outlook_event['id'])) {
            return false;
        }
        
        $outlook_event_id = $outlook_event['id'];
        
        // Check if TEC event already exists for this Outlook event
        $existing_tec_event_id = $this->find_tec_event_by_outlook_id($outlook_event_id);
        
        if ($existing_tec_event_id) {
            // Update existing TEC event (Outlook always wins)
            return $this->update_tec_event_from_outlook_with_category(
                $existing_tec_event_id,
                $outlook_event,
                $calendar_id,
                $tec_category_name
            );
        } else {
            // Create new TEC event
            return $this->create_tec_event_from_outlook_with_category(
                $outlook_event,
                $calendar_id,
                $tec_category_name
            );
        }
    }
    
    /**
     * Sync a single Outlook event to TEC
     */
    private function sync_single_outlook_event_to_tec($outlook_event) {
        if (!isset($outlook_event['id'])) {
            return false;
        }
        
        $outlook_event_id = $outlook_event['id'];
        
        // Check if TEC event already exists for this Outlook event
        $existing_tec_event_id = $this->find_tec_event_by_outlook_id($outlook_event_id);
        
        if ($existing_tec_event_id) {
            // Update existing TEC event
            return $this->update_tec_event_from_outlook($existing_tec_event_id, $outlook_event);
        } else {
            // Create new TEC event
            return $this->create_tec_event_from_outlook($outlook_event);
        }
    }
    
    /**
     * Find TEC event by Outlook event ID
     */
    private function find_tec_event_by_outlook_id($outlook_event_id) {
        global $wpdb;
        
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_outlook_event_id' 
             AND meta_value = %s
             LIMIT 1",
            $outlook_event_id
        ));
        
        return $post_id ? intval($post_id) : false;
    }
    
    /**
     * Create new TEC event from Outlook event
     */
    private function create_tec_event_from_outlook($outlook_event) {
        if (!$this->data_mapper) {
            return false;
        }
        
        Azure_Logger::debug("TEC Sync Engine: Creating TEC event from Outlook event {$outlook_event['id']}", 'TEC');
        
        try {
            // Map Outlook event to TEC format
            $tec_event_data = $this->data_mapper->map_outlook_to_tec($outlook_event);
            
            if (!$tec_event_data) {
                Azure_Logger::error("TEC Sync Engine: Failed to map Outlook event {$outlook_event['id']} to TEC format", 'TEC');
                return false;
            }
            
            // Create TEC event post
            $post_data = array(
                'post_title' => $tec_event_data['title'],
                'post_content' => $tec_event_data['description'],
                'post_status' => 'publish',
                'post_type' => 'tribe_events'
            );
            
            // Temporarily remove our sync hook to prevent infinite loop
            remove_action('save_post_tribe_events', array(Azure_TEC_Integration::get_instance(), 'sync_tec_event_to_outlook'), 20);
            
            $tec_event_id = wp_insert_post($post_data);
            
            // Re-add our sync hook
            add_action('save_post_tribe_events', array(Azure_TEC_Integration::get_instance(), 'sync_tec_event_to_outlook'), 20, 2);
            
            if (is_wp_error($tec_event_id)) {
                Azure_Logger::error("TEC Sync Engine: Failed to create TEC event: " . $tec_event_id->get_error_message(), 'TEC');
                return false;
            }
            
            // Add TEC event metadata - all required fields for proper TEC functionality
            $timezone = get_option('timezone_string', 'UTC');
            if (empty($timezone)) {
                $timezone = 'UTC';
            }
            
            if (isset($tec_event_data['start_date'])) {
                update_post_meta($tec_event_id, '_EventStartDate', $tec_event_data['start_date']);
                
                // Also set UTC version for TEC queries
                try {
                    $start_dt = new DateTime($tec_event_data['start_date'], new DateTimeZone($timezone));
                    $start_dt->setTimezone(new DateTimeZone('UTC'));
                    update_post_meta($tec_event_id, '_EventStartDateUTC', $start_dt->format('Y-m-d H:i:s'));
                } catch (Exception $e) {
                    update_post_meta($tec_event_id, '_EventStartDateUTC', $tec_event_data['start_date']);
                }
            }
            if (isset($tec_event_data['end_date'])) {
                update_post_meta($tec_event_id, '_EventEndDate', $tec_event_data['end_date']);
                
                // Also set UTC version for TEC queries
                try {
                    $end_dt = new DateTime($tec_event_data['end_date'], new DateTimeZone($timezone));
                    $end_dt->setTimezone(new DateTimeZone('UTC'));
                    update_post_meta($tec_event_id, '_EventEndDateUTC', $end_dt->format('Y-m-d H:i:s'));
                } catch (Exception $e) {
                    update_post_meta($tec_event_id, '_EventEndDateUTC', $tec_event_data['end_date']);
                }
            }
            if (isset($tec_event_data['all_day'])) {
                update_post_meta($tec_event_id, '_EventAllDay', $tec_event_data['all_day'] ? 'yes' : 'no');
            }
            
            // Set timezone meta (required for TEC)
            update_post_meta($tec_event_id, '_EventTimezone', $timezone);
            update_post_meta($tec_event_id, '_EventTimezoneAbbr', $this->get_timezone_abbr($timezone));
            
            // Set duration in seconds (required for some TEC views)
            if (isset($tec_event_data['start_date']) && isset($tec_event_data['end_date'])) {
                $duration = strtotime($tec_event_data['end_date']) - strtotime($tec_event_data['start_date']);
                update_post_meta($tec_event_id, '_EventDuration', max(0, $duration));
            }
            
            if (isset($tec_event_data['venue'])) {
                // Set venue (simplified - in production you might want to create/find venue posts)
                update_post_meta($tec_event_id, '_EventVenueID', 0);
                update_post_meta($tec_event_id, '_EventVenue', $tec_event_data['venue']);
            }
            if (isset($tec_event_data['organizer'])) {
                // Set organizer (simplified - in production you might want to create/find organizer posts)
                update_post_meta($tec_event_id, '_EventOrganizerID', 0);
                update_post_meta($tec_event_id, '_EventOrganizer', $tec_event_data['organizer']);
            }
            
            // Store sync metadata
            update_post_meta($tec_event_id, '_outlook_event_id', $outlook_event['id']);
            update_post_meta($tec_event_id, '_outlook_sync_status', 'synced');
            update_post_meta($tec_event_id, '_outlook_last_sync', current_time('mysql'));
            update_post_meta($tec_event_id, '_sync_direction', 'from_outlook');
            
            Azure_Logger::info("TEC Sync Engine: Successfully created TEC event {$tec_event_id} from Outlook event {$outlook_event['id']}", 'TEC');
            
            // Phase 2 dual-write: mirror this event into pta_event when the
            // pta_calendar_owner flag is 'both' or 'pta'. Skips silently
            // when flag = 'tec'. See docs/internal/TECmigration.md.
            $this->mirror_to_pta_event($tec_event_id);

            return $tec_event_id;
            
        } catch (Exception $e) {
            Azure_Logger::error("TEC Sync Engine: Exception creating TEC event from Outlook: " . $e->getMessage(), 'TEC');
            return false;
        }
    }
    
    /**
     * Create new TEC event from Outlook event with category assignment
     */
    private function create_tec_event_from_outlook_with_category($outlook_event, $calendar_id, $tec_category_name) {
        if (!$this->data_mapper) {
            return false;
        }
        
        Azure_Logger::debug("TEC Sync Engine: Creating TEC event from Outlook event {$outlook_event['id']} with category '{$tec_category_name}'", 'TEC');
        
        try {
            // Use existing create method
            $tec_event_id = $this->create_tec_event_from_outlook($outlook_event);
            
            if (!$tec_event_id) {
                return false;
            }
            
            // Store calendar ID
            update_post_meta($tec_event_id, '_outlook_calendar_id', $calendar_id);
            update_post_meta($tec_event_id, '_outlook_last_modified', $outlook_event['lastModifiedDateTime'] ?? current_time('mysql'));

            // Assign category in every active taxonomy + store the name
            // in postmeta as the canonical source for mirror lookups.
            $this->assign_event_category($tec_event_id, $tec_category_name);

            // Phase 2 dual-write: re-mirror so the new category lands on
            // pta_event too. The category was assigned to tribe_events
            // AFTER the inner create_tec_event_from_outlook() already
            // triggered an initial mirror, so we need a second pass.
            $this->mirror_to_pta_event($tec_event_id);

            return $tec_event_id;

        } catch (Exception $e) {
            Azure_Logger::error("TEC Sync Engine: Exception creating TEC event with category: " . $e->getMessage(), 'TEC');
            return false;
        }
    }
    
    /**
     * Update existing TEC event from Outlook event with category (Outlook always wins)
     */
    private function update_tec_event_from_outlook_with_category($tec_event_id, $outlook_event, $calendar_id, $tec_category_name) {
        if (!$this->data_mapper) {
            return false;
        }
        
        Azure_Logger::debug("TEC Sync Engine: Updating TEC event {$tec_event_id} from Outlook (Outlook wins)", 'TEC');
        
        try {
            // Map Outlook event to TEC format
            $tec_event_data = $this->data_mapper->map_outlook_to_tec($outlook_event);
            
            if (!$tec_event_data) {
                Azure_Logger::error("TEC Sync Engine: Failed to map Outlook event {$outlook_event['id']} to TEC format", 'TEC');
                return false;
            }
            
            // Update TEC event post (Outlook always wins - no conflict check)
            $post_data = array(
                'ID' => $tec_event_id,
                'post_title' => $tec_event_data['title'],
                'post_content' => $tec_event_data['description']
            );
            
            // Temporarily remove our sync hook to prevent infinite loop
            remove_action('save_post_tribe_events', array(Azure_TEC_Integration::get_instance(), 'sync_tec_event_to_outlook'), 20);
            
            $result = wp_update_post($post_data);
            
            // Re-add our sync hook
            add_action('save_post_tribe_events', array(Azure_TEC_Integration::get_instance(), 'sync_tec_event_to_outlook'), 20, 2);
            
            if (is_wp_error($result)) {
                Azure_Logger::error("TEC Sync Engine: Failed to update TEC event: " . $result->get_error_message(), 'TEC');
                return false;
            }
            
            // Update TEC event metadata - all required fields for proper TEC functionality
            $timezone = get_option('timezone_string', 'UTC');
            if (empty($timezone)) {
                $timezone = 'UTC';
            }
            
            if (isset($tec_event_data['start_date'])) {
                update_post_meta($tec_event_id, '_EventStartDate', $tec_event_data['start_date']);
                
                // Also set UTC version for TEC queries
                try {
                    $start_dt = new DateTime($tec_event_data['start_date'], new DateTimeZone($timezone));
                    $start_dt->setTimezone(new DateTimeZone('UTC'));
                    update_post_meta($tec_event_id, '_EventStartDateUTC', $start_dt->format('Y-m-d H:i:s'));
                } catch (Exception $e) {
                    update_post_meta($tec_event_id, '_EventStartDateUTC', $tec_event_data['start_date']);
                }
            }
            if (isset($tec_event_data['end_date'])) {
                update_post_meta($tec_event_id, '_EventEndDate', $tec_event_data['end_date']);
                
                // Also set UTC version for TEC queries
                try {
                    $end_dt = new DateTime($tec_event_data['end_date'], new DateTimeZone($timezone));
                    $end_dt->setTimezone(new DateTimeZone('UTC'));
                    update_post_meta($tec_event_id, '_EventEndDateUTC', $end_dt->format('Y-m-d H:i:s'));
                } catch (Exception $e) {
                    update_post_meta($tec_event_id, '_EventEndDateUTC', $tec_event_data['end_date']);
                }
            }
            if (isset($tec_event_data['all_day'])) {
                update_post_meta($tec_event_id, '_EventAllDay', $tec_event_data['all_day'] ? 'yes' : 'no');
            }
            
            // Set timezone meta (required for TEC)
            update_post_meta($tec_event_id, '_EventTimezone', $timezone);
            update_post_meta($tec_event_id, '_EventTimezoneAbbr', $this->get_timezone_abbr($timezone));
            
            // Set duration in seconds (required for some TEC views)
            if (isset($tec_event_data['start_date']) && isset($tec_event_data['end_date'])) {
                $duration = strtotime($tec_event_data['end_date']) - strtotime($tec_event_data['start_date']);
                update_post_meta($tec_event_id, '_EventDuration', max(0, $duration));
            }
            
            if (isset($tec_event_data['venue'])) {
                update_post_meta($tec_event_id, '_EventVenue', $tec_event_data['venue']);
            }
            if (isset($tec_event_data['organizer'])) {
                update_post_meta($tec_event_id, '_EventOrganizer', $tec_event_data['organizer']);
            }
            
            // Update sync metadata
            update_post_meta($tec_event_id, '_outlook_calendar_id', $calendar_id);
            update_post_meta($tec_event_id, '_outlook_last_modified', $outlook_event['lastModifiedDateTime'] ?? current_time('mysql'));
            update_post_meta($tec_event_id, '_outlook_last_sync', current_time('mysql'));
            update_post_meta($tec_event_id, '_sync_direction', 'from_outlook');
            
            // Assign category in every active taxonomy + store the name
            // in postmeta as the canonical source for mirror lookups.
            $this->assign_event_category($tec_event_id, $tec_category_name);

            Azure_Logger::info("TEC Sync Engine: Successfully updated TEC event {$tec_event_id} from Outlook (Outlook wins)", 'TEC');

            // Phase 2 dual-write: mirror updated state into pta_event.
            $this->mirror_to_pta_event($tec_event_id);

            return $tec_event_id;
            
        } catch (Exception $e) {
            Azure_Logger::error("TEC Sync Engine: Exception updating TEC event: " . $e->getMessage(), 'TEC');
            return false;
        }
    }
    
    /**
     * Update existing TEC event from Outlook event
     */
    private function update_tec_event_from_outlook($tec_event_id, $outlook_event) {
        if (!$this->data_mapper) {
            return false;
        }
        
        Azure_Logger::debug("TEC Sync Engine: Updating TEC event {$tec_event_id} from Outlook event {$outlook_event['id']}", 'TEC');
        
        try {
            // Check for conflicts
            if ($this->has_sync_conflict($tec_event_id, $outlook_event)) {
                return $this->resolve_sync_conflict($tec_event_id, $outlook_event);
            }
            
            // Map Outlook event to TEC format
            $tec_event_data = $this->data_mapper->map_outlook_to_tec($outlook_event);
            
            if (!$tec_event_data) {
                Azure_Logger::error("TEC Sync Engine: Failed to map Outlook event {$outlook_event['id']} to TEC format", 'TEC');
                return false;
            }
            
            // Update TEC event post
            $post_data = array(
                'ID' => $tec_event_id,
                'post_title' => $tec_event_data['title'],
                'post_content' => $tec_event_data['description']
            );
            
            // Temporarily remove our sync hook to prevent infinite loop
            remove_action('save_post_tribe_events', array(Azure_TEC_Integration::get_instance(), 'sync_tec_event_to_outlook'), 20);
            
            $result = wp_update_post($post_data);
            
            // Re-add our sync hook
            add_action('save_post_tribe_events', array(Azure_TEC_Integration::get_instance(), 'sync_tec_event_to_outlook'), 20, 2);
            
            if (is_wp_error($result)) {
                Azure_Logger::error("TEC Sync Engine: Failed to update TEC event: " . $result->get_error_message(), 'TEC');
                return false;
            }
            
            // Update TEC event metadata
            if (isset($tec_event_data['start_date'])) {
                update_post_meta($tec_event_id, '_EventStartDate', $tec_event_data['start_date']);
            }
            if (isset($tec_event_data['end_date'])) {
                update_post_meta($tec_event_id, '_EventEndDate', $tec_event_data['end_date']);
            }
            if (isset($tec_event_data['all_day'])) {
                update_post_meta($tec_event_id, '_EventAllDay', $tec_event_data['all_day'] ? 'yes' : 'no');
            }
            
            // Update sync metadata
            update_post_meta($tec_event_id, '_outlook_sync_status', 'synced');
            update_post_meta($tec_event_id, '_outlook_last_sync', current_time('mysql'));
            
            Azure_Logger::info("TEC Sync Engine: Successfully updated TEC event {$tec_event_id} from Outlook", 'TEC');
            
            // Phase 2 dual-write: mirror updated state into pta_event.
            $this->mirror_to_pta_event($tec_event_id);

            return true;
            
        } catch (Exception $e) {
            Azure_Logger::error("TEC Sync Engine: Exception updating TEC event from Outlook: " . $e->getMessage(), 'TEC');
            return false;
        }
    }
    
    /**
     * Check for sync conflicts
     */
    private function has_sync_conflict($tec_event_id, $outlook_event) {
        // Get last sync timestamp
        $last_sync = get_post_meta($tec_event_id, '_outlook_last_sync', true);
        
        if (!$last_sync) {
            return false; // No previous sync, no conflict
        }
        
        // Get TEC event last modified time
        $tec_event = get_post($tec_event_id);
        $tec_modified = strtotime($tec_event->post_modified);
        $last_sync_time = strtotime($last_sync);
        
        // If TEC event was modified after last sync, there might be a conflict
        if ($tec_modified > $last_sync_time) {
            Azure_Logger::warning("TEC Sync Engine: Potential sync conflict detected for event {$tec_event_id}", 'TEC');
            return true;
        }
        
        return false;
    }
    
    /**
     * Resolve sync conflict
     */
    private function resolve_sync_conflict($tec_event_id, $outlook_event) {
        $settings = Azure_Settings::get_all_settings();
        $resolution_strategy = $settings['tec_conflict_resolution'] ?? 'outlook_wins';
        
        Azure_Logger::info("TEC Sync Engine: Resolving conflict for event {$tec_event_id} using strategy: {$resolution_strategy}", 'TEC');
        
        switch ($resolution_strategy) {
            case 'outlook_wins':
                // Outlook wins - update TEC event
                return $this->update_tec_event_from_outlook($tec_event_id, $outlook_event);
                
            case 'tec_wins':
                // TEC wins - sync TEC event to Outlook
                return $this->sync_tec_to_outlook($tec_event_id);
                
            case 'manual':
                // Manual resolution - log conflict and skip
                update_post_meta($tec_event_id, '_sync_conflict_resolution', 'manual_required');
                update_post_meta($tec_event_id, '_outlook_sync_status', 'conflict');
                Azure_Logger::warning("TEC Sync Engine: Manual conflict resolution required for event {$tec_event_id}", 'TEC');
                return false;
                
            default:
                // Default to Outlook wins
                return $this->update_tec_event_from_outlook($tec_event_id, $outlook_event);
        }
    }
    
    /**
     * Delete Outlook event
     */
    public function delete_outlook_event($outlook_event_id) {
        if (!$this->graph_api) {
            Azure_Logger::error('TEC Sync Engine: Graph API not available for deletion', 'TEC');
            return false;
        }
        
        try {
            $result = $this->graph_api->delete_event($this->calendar_id, $outlook_event_id);
            
            if ($result) {
                Azure_Logger::info("TEC Sync Engine: Successfully deleted Outlook event {$outlook_event_id}", 'TEC');
                return true;
            } else {
                Azure_Logger::warning("TEC Sync Engine: Failed to delete Outlook event {$outlook_event_id}", 'TEC');
                return false;
            }
            
        } catch (Exception $e) {
            Azure_Logger::error("TEC Sync Engine: Exception deleting Outlook event {$outlook_event_id}: " . $e->getMessage(), 'TEC');
            return false;
        }
    }
    
    /**
     * Bulk sync all TEC events to Outlook
     */
    public function bulk_sync_tec_to_outlook($limit = 50) {
        Azure_Logger::info("TEC Sync Engine: Starting bulk sync of TEC events to Outlook (limit: {$limit})", 'TEC');
        
        // Get published TEC events
        $args = array(
            'post_type' => 'tribe_events',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_outlook_sync_status',
                    'compare' => 'NOT EXISTS'
                ),
                array(
                    'key' => '_outlook_sync_status',
                    'value' => array('pending', 'error'),
                    'compare' => 'IN'
                )
            )
        );
        
        $events = get_posts($args);
        
        $synced_count = 0;
        $error_count = 0;
        
        foreach ($events as $event) {
            try {
                $result = $this->sync_tec_to_outlook($event->ID);
                
                if ($result) {
                    $synced_count++;
                } else {
                    $error_count++;
                }
                
                // Small delay to avoid rate limiting
                usleep(100000); // 0.1 second
                
            } catch (Exception $e) {
                Azure_Logger::error("TEC Sync Engine: Exception in bulk sync for event {$event->ID}: " . $e->getMessage(), 'TEC');
                $error_count++;
            }
        }
        
        Azure_Logger::info("TEC Sync Engine: Bulk sync completed. Synced: {$synced_count}, Errors: {$error_count}", 'TEC');
        
        return $synced_count > 0;
    }
    
    /**
     * Update sync timestamp
     */
    private function update_sync_timestamp($tec_event_id) {
        update_post_meta($tec_event_id, '_outlook_last_sync', current_time('mysql'));
    }
    
    /**
     * Get sync statistics
     */
    public function get_sync_statistics() {
        global $wpdb;
        
        $stats = array();
        
        // Total TEC events
        $stats['total_tec_events'] = wp_count_posts('tribe_events')->publish;
        
        // Synced events
        $stats['synced_events'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_outlook_sync_status' 
             AND pm.meta_value = 'synced'
             AND p.post_type = 'tribe_events'
             AND p.post_status = 'publish'"
        );
        
        // Pending events
        $stats['pending_events'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_outlook_sync_status' 
             AND pm.meta_value = 'pending'
             AND p.post_type = 'tribe_events'
             AND p.post_status = 'publish'"
        );
        
        // Error events
        $stats['error_events'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_outlook_sync_status' 
             AND pm.meta_value = 'error'
             AND p.post_type = 'tribe_events'
             AND p.post_status = 'publish'"
        );
        
        // Unsynced events
        $stats['unsynced_events'] = $stats['total_tec_events'] - $stats['synced_events'] - $stats['pending_events'] - $stats['error_events'];
        
        // Last sync time
        $stats['last_sync'] = $wpdb->get_var(
            "SELECT MAX(meta_value) FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_outlook_last_sync'
             AND p.post_type = 'tribe_events'"
        );
        
        return $stats;
    }
    
    /**
     * Retry failed sync attempts (Task 2.8)
     * Implement exponential backoff for failed syncs
     */
    public function retry_failed_syncs($max_retries = 3) {
        Azure_Logger::info('TEC Sync Engine: Starting retry of failed sync attempts', 'TEC');
        
        // Get events with error status or excessive pending time
        $failed_events = get_posts(array(
            'post_type' => 'tribe_events',
            'posts_per_page' => 50, // Limit to prevent overwhelming
            'post_status' => array('publish', 'private'),
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_outlook_sync_status',
                    'value' => 'error'
                ),
                array(
                    'key' => '_outlook_sync_status',
                    'value' => 'pending',
                    'meta_query' => array(
                        array(
                            'key' => '_outlook_last_sync_attempt',
                            'value' => date('Y-m-d H:i:s', strtotime('-1 hour')),
                            'compare' => '<'
                        )
                    )
                )
            )
        ));
        
        $retry_count = 0;
        $success_count = 0;
        $skip_count = 0;
        
        foreach ($failed_events as $event) {
            $event_id = $event->ID;
            
            // Check retry count
            $current_retries = get_post_meta($event_id, '_outlook_sync_retries', true) ?: 0;
            
            if ($current_retries >= $max_retries) {
                // Mark as permanently failed
                update_post_meta($event_id, '_outlook_sync_status', 'failed_permanent');
                update_post_meta($event_id, '_outlook_sync_message', 'Max retries exceeded');
                $skip_count++;
                Azure_Logger::warning("TEC Sync Engine: Event {$event_id} exceeded max retries ({$max_retries})", 'TEC');
                continue;
            }
            
            // Implement exponential backoff
            $backoff_minutes = pow(2, $current_retries) * 5; // 5, 10, 20 minutes
            $last_attempt = get_post_meta($event_id, '_outlook_last_sync_attempt', true);
            
            if ($last_attempt && strtotime($last_attempt) > strtotime("-{$backoff_minutes} minutes")) {
                // Still in backoff period
                $skip_count++;
                continue;
            }
            
            // Record retry attempt
            update_post_meta($event_id, '_outlook_sync_retries', $current_retries + 1);
            update_post_meta($event_id, '_outlook_last_sync_attempt', current_time('mysql'));
            
            // Attempt sync
            try {
                $result = $this->sync_tec_to_outlook($event_id);
                
                if ($result) {
                    // Success - reset retry count
                    delete_post_meta($event_id, '_outlook_sync_retries');
                    delete_post_meta($event_id, '_outlook_last_sync_attempt');
                    $success_count++;
                    Azure_Logger::info("TEC Sync Engine: Successfully retried sync for event {$event_id}", 'TEC');
                } else {
                    // Failed again
                    update_post_meta($event_id, '_outlook_sync_status', 'error');
                    update_post_meta($event_id, '_outlook_sync_message', 'Retry failed');
                    $retry_count++;
                }
                
            } catch (Exception $e) {
                // Exception occurred
                update_post_meta($event_id, '_outlook_sync_status', 'error');
                update_post_meta($event_id, '_outlook_sync_message', 'Retry exception: ' . $e->getMessage());
                $retry_count++;
                Azure_Logger::error("TEC Sync Engine: Retry failed for event {$event_id}: " . $e->getMessage(), 'TEC');
            }
        }
        
        Azure_Logger::info("TEC Sync Engine: Retry complete - {$success_count} succeeded, {$retry_count} failed, {$skip_count} skipped", 'TEC');
        
        return array(
            'processed' => count($failed_events),
            'success' => $success_count,
            'failed' => $retry_count,
            'skipped' => $skip_count
        );
    }
    
    /**
     * Handle API rate limiting (Task 3.13)
     * Implement rate limiting and throttling for Graph API calls
     */
    public function handle_rate_limiting($response_headers = array()) {
        // Check for rate limit headers from Microsoft Graph API
        if (isset($response_headers['Retry-After'])) {
            $retry_after = intval($response_headers['Retry-After']);
            Azure_Logger::warning("TEC Sync Engine: Rate limited, waiting {$retry_after} seconds", 'TEC');
            
            // Store rate limit info for future requests
            update_option('azure_tec_rate_limit_until', time() + $retry_after);
            
            return $retry_after;
        }
        
        // Check for throttling headers
        if (isset($response_headers['X-RateLimit-Remaining'])) {
            $remaining = intval($response_headers['X-RateLimit-Remaining']);
            
            if ($remaining < 10) {
                // Very low on requests, implement delay
                $delay = min(60, (10 - $remaining) * 2); // Up to 60 seconds
                Azure_Logger::info("TEC Sync Engine: Low rate limit remaining ({$remaining}), adding {$delay}s delay", 'TEC');
                
                update_option('azure_tec_throttle_delay', $delay);
                return $delay;
            }
        }
        
        return 0; // No delay needed
    }
    
    /**
     * Check if we should delay requests due to rate limiting
     */
    public function should_delay_request() {
        // Check if we're currently rate limited
        $rate_limit_until = get_option('azure_tec_rate_limit_until', 0);
        if ($rate_limit_until && time() < $rate_limit_until) {
            return $rate_limit_until - time();
        }
        
        // Check if we should throttle
        $throttle_delay = get_option('azure_tec_throttle_delay', 0);
        if ($throttle_delay) {
            // Gradually reduce throttle delay
            $new_delay = max(0, $throttle_delay - 5);
            update_option('azure_tec_throttle_delay', $new_delay);
            
            return $throttle_delay;
        }
        
        return 0;
    }
    
    /**
     * Get timezone abbreviation from timezone string
     */
    private function get_timezone_abbr($timezone_string) {
        try {
            $tz = new DateTimeZone($timezone_string);
            $dt = new DateTime('now', $tz);
            return $dt->format('T');
        } catch (Exception $e) {
            return 'UTC';
        }
    }

    // =====================================================================
    // PHASE 2: TEC -> pta_event dual-write mirror
    //
    // Every successful Outlook -> tribe_events sync is mirrored into a
    // pta_event post so the new event domain stays in sync without any
    // change in user-facing behaviour. See docs/internal/TECmigration.md.
    // =====================================================================

    /**
     * Public entry point for the mirror.
     *
     * Used by Phase 3 backfill (and the manual test endpoint) to mirror
     * a single tribe_events post without going through reflection. The
     * underlying logic is identical to the private method called from
     * the sync engine; this wrapper just exposes it and threads the
     * `$allow_no_outlook` flag for backfill mode.
     *
     * @param int  $tec_event_id     The tribe_events post ID.
     * @param bool $allow_no_outlook True to mirror locally-authored events.
     * @return int|false The pta_event post ID, or false on skip/failure.
     */
    public function mirror_one($tec_event_id, $allow_no_outlook = false) {
        return $this->mirror_to_pta_event($tec_event_id, $allow_no_outlook);
    }

    /**
     * List of postmeta keys we mirror from tribe_events into pta_event.
     *
     * We deliberately keep TEC's _Event* meta names verbatim on pta_event
     * so the Phase 3 backfill is a straight copy and rollback is non-
     * destructive. The list also includes our own sync bookkeeping keys
     * and the featured image pointer.
     *
     * @return array
     */
    private function pta_event_mirrored_meta_keys() {
        return array(
            // TEC's authoritative event fields.
            '_EventStartDate',
            '_EventEndDate',
            '_EventStartDateUTC',
            '_EventEndDateUTC',
            '_EventAllDay',
            '_EventTimezone',
            '_EventTimezoneAbbr',
            '_EventDuration',
            '_EventVenue',
            '_EventVenueID',
            '_EventOrganizer',
            '_EventOrganizerID',
            '_EventURL',
            '_EventCost',
            '_EventCurrencySymbol',
            '_EventCurrencyPosition',
            '_EventShowMap',
            '_EventShowMapLink',
            '_EventHideFromUpcoming',
            // Our sync bookkeeping (also useful on pta_event for parity checks).
            '_outlook_event_id',
            '_outlook_sync_status',
            '_outlook_last_sync',
            '_outlook_last_modified',
            '_outlook_calendar_id',
            '_sync_direction',
            // Featured image pointer (so the same image surfaces on pta_event).
            '_thumbnail_id',
            // Canonical category name written by assign_event_category().
            // Mirrored so consumers reading pta_event directly (e.g. a
            // future sync that doesn't go through tribe_events at all)
            // can recover the category without DB joins.
            '_pta_event_category_name',
        );
    }

    /**
     * Assign an event category to a tribe_events post in every active
     * event-category taxonomy AND cache the category name in a
     * post-type-agnostic postmeta key.
     *
     * Why both: pre-migration, the canonical taxonomy is `tribe_events_cat`
     * (owned by The Events Calendar plugin). Post-migration, TEC is gone
     * and `tribe_events_cat` isn't registered any more — wp_set_object_terms
     * to it silently returns a WP_Error and the category is dropped.
     * Post-migration, `pta_event_category` (registered by Azure_Event_CPT
     * and attached to tribe_events when in 'both' owner mode) is the
     * working taxonomy. Writing to whichever ones are registered keeps
     * the engine working in all three flag states (tec/both/pta).
     *
     * Why the postmeta key: it's the authoritative recovery source for
     * mirror_to_pta_event(). Both taxonomies can become unregistered
     * mid-deploy (e.g. plugin order issues, opcache lag) but the meta
     * value is stable and always readable.
     *
     * @param int    $tec_event_id  The tribe_events post ID.
     * @param string $category_name Display name of the category.
     */
    private function assign_event_category($tec_event_id, $category_name) {
        $category_name = (string) $category_name;
        if ($category_name === '') {
            return;
        }

        // Authoritative cache — read by mirror_to_pta_event() and the
        // backfill endpoint.
        update_post_meta($tec_event_id, '_pta_event_category_name', $category_name);

        $taxonomies = array();
        if (taxonomy_exists('tribe_events_cat')
            && in_array('tribe_events', (array) get_taxonomy('tribe_events_cat')->object_type, true)
        ) {
            $taxonomies[] = 'tribe_events_cat';
        }
        if (taxonomy_exists('pta_event_category')) {
            $tax_obj = get_taxonomy('pta_event_category');
            if ($tax_obj && in_array('tribe_events', (array) $tax_obj->object_type, true)) {
                $taxonomies[] = 'pta_event_category';
            }
        }

        foreach ($taxonomies as $tax) {
            // Pass NAMES; wp_set_object_terms() will create the term in
            // the taxonomy on first use. Don't append — replace, so a
            // category change in Outlook drops the previous one.
            $result = wp_set_object_terms($tec_event_id, array($category_name), $tax, false);
            if (is_wp_error($result)) {
                Azure_Logger::warning(
                    "TEC Sync Engine: Failed to assign category '{$category_name}' to event {$tec_event_id} in taxonomy '{$tax}': " . $result->get_error_message(),
                    'TEC'
                );
            } else {
                Azure_Logger::debug(
                    "TEC Sync Engine: Assigned category '{$category_name}' to event {$tec_event_id} in taxonomy '{$tax}'",
                    'TEC'
                );
            }
        }

        if (empty($taxonomies)) {
            Azure_Logger::warning(
                "TEC Sync Engine: No active event-category taxonomy attached to 'tribe_events' — category '{$category_name}' cached as postmeta only on event {$tec_event_id}",
                'TEC'
            );
        }
    }

    /**
     * Locate an existing pta_event by its _outlook_event_id postmeta.
     *
     * Used by the mirror to find a previously created mirror post when
     * the source tribe_events post hasn't yet had its
     * `_pta_event_mirror_id` pointer written (e.g. mid-Phase-3 backfill).
     *
     * @param string $outlook_event_id Outlook calendar item ID.
     * @return int Found pta_event post ID, or 0 if none.
     */
    private function find_pta_event_by_outlook_id($outlook_event_id) {
        if (empty($outlook_event_id) || !post_type_exists('pta_event')) {
            return 0;
        }
        $q = new WP_Query(array(
            'post_type'      => 'pta_event',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(array(
                'key'     => '_outlook_event_id',
                'value'   => $outlook_event_id,
                'compare' => '=',
            )),
        ));
        if (empty($q->posts)) {
            return 0;
        }
        return (int) $q->posts[0];
    }

    /**
     * Mirror a tribe_events post into a matching pta_event post.
     *
     * Idempotent: looks up an existing mirror by stored pointer first,
     * then by `_outlook_event_id` postmeta, then creates a new pta_event
     * if neither found.
     *
     * Skips silently when:
     *   - pta_calendar_owner flag is 'tec' (Phase 0/1 default)
     *   - pta_event post type isn't registered (e.g. Azure_Event_CPT
     *     hasn't loaded yet, or the flag check is false)
     *   - the source post isn't actually `tribe_events`
     *   - the source has no `_outlook_event_id` (we don't backfill
     *     locally-authored TEC events here — that's Phase 3's job)
     *
     * Always writes:
     *   - post fields: title, content, excerpt, status, slug, author, date
     *   - the meta keys returned by pta_event_mirrored_meta_keys()
     *   - tribe_events_cat term assignments (mirrored to pta_event_category,
     *     which is the same taxonomy registered in Phase 0)
     *   - bidirectional cross-pointers `_pta_event_mirror_id` and
     *     `_tec_event_mirror_id` for fast lookup on subsequent syncs
     *
     * @param int  $tec_event_id     The tribe_events post ID just written.
     * @param bool $allow_no_outlook If true, locally-authored TEC events
     *                               without `_outlook_event_id` are also
     *                               mirrored (Phase 3 backfill mode).
     *                               Default false (Phase 2 sync mode).
     * @return int|false The pta_event post ID, or false on skip/failure.
     */
    private function mirror_to_pta_event($tec_event_id, $allow_no_outlook = false) {
        // Phase 2 dual-write gate.
        if (!class_exists('Azure_Event_CPT')) {
            return false;
        }
        if (!Azure_Event_CPT::is_pta_owner_active()) {
            return false;
        }
        if (!post_type_exists('pta_event')) {
            return false;
        }

        $tec_post = get_post($tec_event_id);
        if (!$tec_post || $tec_post->post_type !== 'tribe_events') {
            return false;
        }

        $outlook_event_id = (string) get_post_meta($tec_event_id, '_outlook_event_id', true);
        if ($outlook_event_id === '' && !$allow_no_outlook) {
            // Locally-authored TEC event with no Outlook source. The
            // live sync path (Phase 2) skips these because they only
            // appear during Phase 3 backfill. The Phase 3 backfill
            // endpoint sets $allow_no_outlook=true to mirror them.
            return false;
        }

        // Find an existing mirror — prefer the stored cross-pointer
        // (cheapest lookup, no meta_query), fall back to outlook ID
        // search when present. For locally-authored events the cross-
        // pointer is the only join key; nothing else to fall back to.
        $pta_event_id = (int) get_post_meta($tec_event_id, '_pta_event_mirror_id', true);
        if (!$pta_event_id && $outlook_event_id !== '') {
            $pta_event_id = $this->find_pta_event_by_outlook_id($outlook_event_id);
        }

        // Build the post fields to write. We preserve slug, author, and
        // both post_date forms so the pta_event reads identically to
        // its tribe_events twin.
        $post_data = array(
            'post_title'        => $tec_post->post_title,
            'post_content'      => $tec_post->post_content,
            'post_excerpt'      => $tec_post->post_excerpt,
            'post_status'       => $tec_post->post_status,
            'post_name'         => $tec_post->post_name,
            'post_author'       => $tec_post->post_author,
            'post_date'         => $tec_post->post_date,
            'post_date_gmt'     => $tec_post->post_date_gmt,
            'post_type'         => 'pta_event',
            'comment_status'    => 'closed',
            'ping_status'       => 'closed',
        );

        if ($pta_event_id) {
            $post_data['ID'] = $pta_event_id;
            $result = wp_update_post($post_data, true);
        } else {
            $result = wp_insert_post($post_data, true);
        }

        if (is_wp_error($result)) {
            Azure_Logger::error(
                "TEC Sync Engine: Mirror to pta_event failed for tec={$tec_event_id}: " . $result->get_error_message(),
                'TEC'
            );
            return false;
        }
        $pta_event_id = (int) $result;

        // Mirror the meta keys we care about. We do this with explicit
        // single-key reads rather than blanket get_post_meta() so we
        // don't accidentally copy plugin-internal keys (Yoast, ACF, BB).
        $keys = $this->pta_event_mirrored_meta_keys();
        foreach ($keys as $k) {
            $v = get_post_meta($tec_event_id, $k, true);
            if ($v === '' || $v === null) {
                // Don't pollute pta_event with empties — and clear any
                // stale value that may exist on the mirror.
                delete_post_meta($pta_event_id, $k);
                continue;
            }
            update_post_meta($pta_event_id, $k, $v);
        }

        // Mirror category terms onto pta_event_category.
        //
        // Resolution order for the category NAME (v3.91.12+):
        //   1. `_pta_event_category_name` postmeta — the canonical
        //      source written by assign_event_category() during sync.
        //      Survives TEC plugin removal.
        //   2. Terms on the tribe_events post in `tribe_events_cat` —
        //      only resolves while TEC is active (it's TEC's taxonomy).
        //      Kept as a fallback for events created pre-3.91.12.
        //   3. Terms on the tribe_events post in `pta_event_category` —
        //      handles the case where Azure_Event_CPT attached the
        //      shared taxonomy to tribe_events during the migration
        //      window.
        //   4. Calendar mapping lookup — find the calendar this event
        //      came from via `_outlook_calendar_id` and use its
        //      `tec_category_name`. Last-resort recovery so backfilled
        //      events from the legacy era still get categorised.
        //
        // wp_set_object_terms() with names will create the term in
        // pta_event_category on first use, no migration needed.
        $category_names = array();

        $stored_name = (string) get_post_meta($tec_event_id, '_pta_event_category_name', true);
        if ($stored_name !== '') {
            $category_names = array($stored_name);
        }

        if (empty($category_names) && taxonomy_exists('tribe_events_cat')) {
            $names = wp_get_object_terms($tec_event_id, 'tribe_events_cat', array('fields' => 'names'));
            if (!is_wp_error($names) && !empty($names)) {
                $category_names = $names;
            }
        }

        if (empty($category_names) && taxonomy_exists('pta_event_category')) {
            $names = wp_get_object_terms($tec_event_id, 'pta_event_category', array('fields' => 'names'));
            if (!is_wp_error($names) && !empty($names)) {
                $category_names = $names;
            }
        }

        if (empty($category_names)) {
            $cal_id = (string) get_post_meta($tec_event_id, '_outlook_calendar_id', true);
            if ($cal_id !== '' && class_exists('Azure_TEC_Calendar_Mapping_Manager')) {
                try {
                    $mm      = new Azure_TEC_Calendar_Mapping_Manager();
                    $mapping = $mm->get_mapping_by_calendar_id($cal_id);
                    if ($mapping && !empty($mapping->tec_category_name)) {
                        $category_names = array($mapping->tec_category_name);
                        // Cache it so future mirrors skip the lookup.
                        update_post_meta($tec_event_id, '_pta_event_category_name', $mapping->tec_category_name);
                    }
                } catch (\Throwable $e) {
                    // Non-fatal; just won't categorise this one event.
                }
            }
        }

        if (!empty($category_names)) {
            wp_set_object_terms($pta_event_id, $category_names, 'pta_event_category', false);
        } else {
            // No category from any source — clear stale terms on the mirror.
            wp_set_object_terms($pta_event_id, array(), 'pta_event_category', false);
        }

        // Bidirectional cross-pointers. These let the next sync skip the
        // meta_query lookup (cheaper) and let the parity diagnostic
        // identify orphan mirrors quickly.
        update_post_meta($tec_event_id, '_pta_event_mirror_id', $pta_event_id);
        update_post_meta($pta_event_id, '_tec_event_mirror_id', $tec_event_id);

        Azure_Logger::debug(
            "TEC Sync Engine: Mirrored tribe_events#{$tec_event_id} -> pta_event#{$pta_event_id} (outlook={$outlook_event_id})",
            'TEC'
        );

        return $pta_event_id;
    }
}