<?php
/**
 * TEC Calendar Mapping Manager
 * Handles CRUD operations for Outlook calendar to TEC category mappings
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_TEC_Calendar_Mapping_Manager {
    
    private $table_name;
    
    public function __construct() {
        $this->table_name = Azure_Database::get_table_name('tec_calendar_mappings');
        
        if (!$this->table_name) {
            Azure_Logger::error('TEC Calendar Mapping Manager: Unable to get table name', 'TEC');
        }
    }
    
    /**
     * Get all calendar mappings
     */
    public function get_all_mappings() {
        global $wpdb;
        
        if (!$this->table_name) {
            return array();
        }
        
        $mappings = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY outlook_calendar_name ASC");
        
        Azure_Logger::debug('TEC Calendar Mapping Manager: Retrieved ' . count($mappings) . ' mappings', 'TEC');
        
        return $mappings;
    }
    
    /**
     * Get mapping by Outlook calendar ID
     */
    public function get_mapping_by_calendar_id($outlook_calendar_id) {
        global $wpdb;
        
        if (!$this->table_name) {
            return null;
        }
        
        $mapping = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE outlook_calendar_id = %s",
            $outlook_calendar_id
        ));
        
        return $mapping;
    }
    
    /**
     * Get mapping by ID
     */
    public function get_mapping_by_id($mapping_id) {
        global $wpdb;
        
        if (!$this->table_name) {
            return null;
        }
        
        $mapping = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $mapping_id
        ));
        
        return $mapping;
    }
    
    /**
     * Create new calendar mapping
     */
    public function create_mapping($outlook_calendar_id, $outlook_calendar_name, $tec_category_id, $tec_category_name, $sync_enabled = 1, $schedule_enabled = 0, $schedule_frequency = 'hourly', $schedule_lookback_days = 30, $schedule_lookahead_days = 365) {
        global $wpdb;
        
        if (!$this->table_name) {
            Azure_Logger::error('TEC Calendar Mapping Manager: Table name not available', 'TEC');
            return false;
        }
        
        $data = array(
            'outlook_calendar_id' => $outlook_calendar_id,
            'outlook_calendar_name' => $outlook_calendar_name,
            'tec_category_id' => $tec_category_id,
            'tec_category_name' => $tec_category_name,
            'sync_enabled' => $sync_enabled,
            'schedule_enabled' => $schedule_enabled,
            'schedule_frequency' => $schedule_frequency,
            'schedule_lookback_days' => $schedule_lookback_days,
            'schedule_lookahead_days' => $schedule_lookahead_days,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );
        
        $formats = array('%s', '%s', '%d', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s');
        
        $result = $wpdb->insert($this->table_name, $data, $formats);
        
        if ($result) {
            $insert_id = $wpdb->insert_id;
            Azure_Logger::info("TEC Calendar Mapping Manager: Created mapping ID {$insert_id} for calendar: {$outlook_calendar_name} -> {$tec_category_name}", 'TEC');
            
            // Schedule if enabled
            if ($schedule_enabled) {
                $this->schedule_mapping_sync($insert_id, $schedule_frequency);
            }
            
            return $insert_id;
        } else {
            Azure_Logger::error("TEC Calendar Mapping Manager: Failed to create mapping for calendar: {$outlook_calendar_name}. Error: " . $wpdb->last_error, 'TEC');
            return false;
        }
    }
    
    /**
     * Update existing calendar mapping
     */
    public function update_mapping($mapping_id, $outlook_calendar_id, $outlook_calendar_name, $tec_category_id, $tec_category_name, $sync_enabled = 1, $schedule_enabled = 0, $schedule_frequency = 'hourly', $schedule_lookback_days = 30, $schedule_lookahead_days = 365) {
        global $wpdb;
        
        if (!$this->table_name) {
            Azure_Logger::error('TEC Calendar Mapping Manager: Table name not available', 'TEC');
            return false;
        }
        
        // Get old mapping to check if schedule changed
        $old_mapping = $this->get_mapping_by_id($mapping_id);
        
        $data = array(
            'outlook_calendar_id' => $outlook_calendar_id,
            'outlook_calendar_name' => $outlook_calendar_name,
            'tec_category_id' => $tec_category_id,
            'tec_category_name' => $tec_category_name,
            'sync_enabled' => $sync_enabled,
            'schedule_enabled' => $schedule_enabled,
            'schedule_frequency' => $schedule_frequency,
            'schedule_lookback_days' => $schedule_lookback_days,
            'schedule_lookahead_days' => $schedule_lookahead_days,
            'updated_at' => current_time('mysql')
        );
        
        $formats = array('%s', '%s', '%d', '%s', '%d', '%d', '%s', '%d', '%d', '%s');
        
        $result = $wpdb->update(
            $this->table_name,
            $data,
            array('id' => $mapping_id),
            $formats,
            array('%d')
        );
        
        if ($result !== false) {
            Azure_Logger::info("TEC Calendar Mapping Manager: Updated mapping ID {$mapping_id} for calendar: {$outlook_calendar_name} -> {$tec_category_name}", 'TEC');
            
            // Handle schedule changes
            if ($schedule_enabled) {
                // Schedule or reschedule
                $this->schedule_mapping_sync($mapping_id, $schedule_frequency);
            } else if ($old_mapping && $old_mapping->schedule_enabled) {
                // Was enabled, now disabled - unschedule
                $this->unschedule_mapping_sync($mapping_id);
            }
            
            return true;
        } else {
            Azure_Logger::error("TEC Calendar Mapping Manager: Failed to update mapping ID {$mapping_id}. Error: " . $wpdb->last_error, 'TEC');
            return false;
        }
    }
    
    /**
     * Delete calendar mapping by ID
     */
    public function delete_mapping($mapping_id) {
        global $wpdb;
        
        if (!$this->table_name) {
            return false;
        }
        
        // Unschedule before deleting
        $this->unschedule_mapping_sync($mapping_id);
        
        $result = $wpdb->delete(
            $this->table_name,
            array('id' => $mapping_id),
            array('%d')
        );
        
        if ($result) {
            Azure_Logger::info("TEC Calendar Mapping Manager: Deleted mapping ID: {$mapping_id}", 'TEC');
            return true;
        } else {
            Azure_Logger::error("TEC Calendar Mapping Manager: Failed to delete mapping ID: {$mapping_id}", 'TEC');
            return false;
        }
    }
    
    /**
     * Get only enabled calendars for syncing
     */
    public function get_enabled_calendars() {
        global $wpdb;
        
        if (!$this->table_name) {
            return array();
        }
        
        $mappings = $wpdb->get_results("SELECT * FROM {$this->table_name} WHERE sync_enabled = 1 ORDER BY outlook_calendar_name ASC");
        
        Azure_Logger::debug('TEC Calendar Mapping Manager: Retrieved ' . count($mappings) . ' enabled calendars', 'TEC');
        
        return $mappings;
    }
    
    /**
     * Create or update calendar mapping
     */
    public function create_or_update_mapping($outlook_calendar_id, $calendar_name, $tec_category_name, $sync_enabled = true) {
        global $wpdb;
        
        if (!$this->table_name) {
            return false;
        }
        
        // Ensure TEC category exists
        $tec_category_id = $this->ensure_tec_category_exists($tec_category_name);
        
        if (!$tec_category_id) {
            Azure_Logger::error("TEC Calendar Mapping Manager: Failed to create/find category: {$tec_category_name}", 'TEC');
            return false;
        }
        
        // Check if mapping already exists
        $existing_mapping = $this->get_mapping_by_calendar_id($outlook_calendar_id);
        
        $data = array(
            'outlook_calendar_id' => $outlook_calendar_id,
            'outlook_calendar_name' => $calendar_name,
            'tec_category_id' => $tec_category_id,
            'tec_category_name' => $tec_category_name,
            'sync_enabled' => $sync_enabled ? 1 : 0,
            'updated_at' => current_time('mysql')
        );
        
        $formats = array('%s', '%s', '%d', '%s', '%d', '%s');
        
        if ($existing_mapping) {
            // Update existing mapping
            $result = $wpdb->update(
                $this->table_name,
                $data,
                array('outlook_calendar_id' => $outlook_calendar_id),
                $formats,
                array('%s')
            );
            
            if ($result !== false) {
                Azure_Logger::info("TEC Calendar Mapping Manager: Updated mapping for calendar: {$calendar_name}", 'TEC');
                return true;
            } else {
                Azure_Logger::error("TEC Calendar Mapping Manager: Failed to update mapping for calendar: {$calendar_name}", 'TEC');
                return false;
            }
        } else {
            // Create new mapping
            $data['created_at'] = current_time('mysql');
            array_push($formats, '%s');
            
            $result = $wpdb->insert(
                $this->table_name,
                $data,
                $formats
            );
            
            if ($result) {
                Azure_Logger::info("TEC Calendar Mapping Manager: Created mapping for calendar: {$calendar_name} -> {$tec_category_name}", 'TEC');
                return $wpdb->insert_id;
            } else {
                Azure_Logger::error("TEC Calendar Mapping Manager: Failed to create mapping for calendar: {$calendar_name}", 'TEC');
                return false;
            }
        }
    }
    
    /**
     * Delete calendar mapping by Outlook calendar ID
     */
    public function delete_mapping_by_calendar_id($outlook_calendar_id) {
        global $wpdb;
        
        if (!$this->table_name) {
            return false;
        }
        
        $result = $wpdb->delete(
            $this->table_name,
            array('outlook_calendar_id' => $outlook_calendar_id),
            array('%s')
        );
        
        if ($result) {
            Azure_Logger::info("TEC Calendar Mapping Manager: Deleted mapping for calendar ID: {$outlook_calendar_id}", 'TEC');
            return true;
        } else {
            Azure_Logger::error("TEC Calendar Mapping Manager: Failed to delete mapping for calendar ID: {$outlook_calendar_id}", 'TEC');
            return false;
        }
    }
    
    /**
     * Update sync enabled status for a calendar
     */
    public function update_sync_status($outlook_calendar_id, $enabled) {
        global $wpdb;
        
        if (!$this->table_name) {
            return false;
        }
        
        $result = $wpdb->update(
            $this->table_name,
            array('sync_enabled' => $enabled ? 1 : 0, 'updated_at' => current_time('mysql')),
            array('outlook_calendar_id' => $outlook_calendar_id),
            array('%d', '%s'),
            array('%s')
        );
        
        if ($result !== false) {
            $status = $enabled ? 'enabled' : 'disabled';
            Azure_Logger::info("TEC Calendar Mapping Manager: {$status} sync for calendar ID: {$outlook_calendar_id}", 'TEC');
            return true;
        } else {
            Azure_Logger::error("TEC Calendar Mapping Manager: Failed to update sync status for calendar ID: {$outlook_calendar_id}", 'TEC');
            return false;
        }
    }
    
    /**
     * Update last sync timestamp for a calendar
     */
    public function update_last_sync($outlook_calendar_id) {
        global $wpdb;
        
        if (!$this->table_name) {
            return false;
        }
        
        $result = $wpdb->update(
            $this->table_name,
            array('last_sync' => current_time('mysql'), 'updated_at' => current_time('mysql')),
            array('outlook_calendar_id' => $outlook_calendar_id),
            array('%s', '%s'),
            array('%s')
        );
        
        return $result !== false;
    }
    
    /**
     * Ensure the event category exists, create if it doesn't.
     *
     * v3.91.12+: writes to whichever taxonomy is active for the current
     * `pta_calendar_data_source` flag. Post-migration that's
     * `pta_event_category`; pre-migration it's `tribe_events_cat`.
     * Hard-coding `tribe_events_cat` (TEC-owned taxonomy) silently
     * failed once TEC was deactivated, dropping every imported event's
     * category on the floor.
     */
    public function ensure_tec_category_exists($category_name) {
        $taxonomy = class_exists('Azure_Event_CPT')
            ? Azure_Event_CPT::query_taxonomy()
            : 'tribe_events_cat';

        // Defensive: if the chosen taxonomy isn't registered (e.g. TEC
        // is gone AND Azure_Event_CPT hasn't bootstrapped), fall back
        // to pta_event_category if THAT is registered. Don't try to
        // write to a non-existent taxonomy — wp_insert_term will just
        // return an "invalid_taxonomy" WP_Error.
        if (!taxonomy_exists($taxonomy) && taxonomy_exists('pta_event_category')) {
            $taxonomy = 'pta_event_category';
        }
        if (!taxonomy_exists($taxonomy)) {
            Azure_Logger::error(
                "TEC Calendar Mapping Manager: Neither '{$taxonomy}' nor 'pta_event_category' is registered — cannot create category '{$category_name}'",
                'TEC'
            );
            return false;
        }

        // Check if term exists in the active taxonomy
        $term = term_exists($category_name, $taxonomy);

        if ($term) {
            if (is_array($term)) {
                return $term['term_id'];
            }
            return $term;
        }

        // Term doesn't exist, create it
        $term = wp_insert_term($category_name, $taxonomy, array(
            'description' => "Events synced from Outlook calendar: {$category_name}",
            'slug'        => sanitize_title($category_name),
        ));

        if (is_wp_error($term)) {
            Azure_Logger::error(
                "TEC Calendar Mapping Manager: Failed to create category '{$category_name}' in taxonomy '{$taxonomy}': " . $term->get_error_message(),
                'TEC'
            );
            return false;
        }

        Azure_Logger::info("TEC Calendar Mapping Manager: Created category '{$category_name}' in taxonomy '{$taxonomy}'", 'TEC');

        return $term['term_id'];
    }
    
    /**
     * Get statistics for calendar mappings
     */
    public function get_mapping_statistics() {
        global $wpdb;
        
        if (!$this->table_name) {
            return array(
                'total' => 0,
                'enabled' => 0,
                'disabled' => 0
            );
        }
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $enabled = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE sync_enabled = 1");
        
        return array(
            'total' => (int) $total,
            'enabled' => (int) $enabled,
            'disabled' => (int) ($total - $enabled)
        );
    }
    
    /**
     * Sync mappings with available Outlook calendars
     * Creates mappings for new calendars, marks missing calendars as disabled
     */
    public function sync_with_outlook_calendars($outlook_calendars) {
        if (empty($outlook_calendars)) {
            return array('created' => 0, 'updated' => 0, 'disabled' => 0);
        }
        
        $stats = array('created' => 0, 'updated' => 0, 'disabled' => 0);
        
        // Get existing mappings
        $existing_mappings = $this->get_all_mappings();
        $existing_ids = array();
        foreach ($existing_mappings as $mapping) {
            $existing_ids[] = $mapping->outlook_calendar_id;
        }
        
        // Process Outlook calendars
        foreach ($outlook_calendars as $calendar) {
            $calendar_id = $calendar['id'];
            $calendar_name = $calendar['name'];
            
            $existing_mapping = $this->get_mapping_by_calendar_id($calendar_id);
            
            if (!$existing_mapping) {
                // Create new mapping with default category name = calendar name
                $result = $this->create_or_update_mapping($calendar_id, $calendar_name, $calendar_name, false);
                if ($result) {
                    $stats['created']++;
                }
            } else {
                // Update calendar name if changed
                if ($existing_mapping->outlook_calendar_name !== $calendar_name) {
                    $this->create_or_update_mapping(
                        $calendar_id,
                        $calendar_name,
                        $existing_mapping->tec_category_name,
                        $existing_mapping->sync_enabled
                    );
                    $stats['updated']++;
                }
            }
        }
        
        Azure_Logger::info("TEC Calendar Mapping Manager: Synced with Outlook calendars - Created: {$stats['created']}, Updated: {$stats['updated']}", 'TEC');
        
        return $stats;
    }
    
    /**
     * Schedule sync for a specific mapping
     */
    private function schedule_mapping_sync($mapping_id, $frequency) {
        $hook_name = 'azure_tec_mapping_sync_' . $mapping_id;
        
        // Clear any existing schedule for this mapping
        $this->unschedule_mapping_sync($mapping_id);
        
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
        $result = wp_schedule_event(time(), $cron_schedule, $hook_name, array($mapping_id));
        
        if ($result) {
            Azure_Logger::info("TEC Calendar Mapping Manager: Scheduled mapping ID {$mapping_id} with frequency '{$frequency}' ({$cron_schedule})", 'TEC');
            return true;
        } else {
            Azure_Logger::error("TEC Calendar Mapping Manager: Failed to schedule mapping ID {$mapping_id}", 'TEC');
            return false;
        }
    }
    
    /**
     * Unschedule sync for a specific mapping
     */
    private function unschedule_mapping_sync($mapping_id) {
        $hook_name = 'azure_tec_mapping_sync_' . $mapping_id;
        $timestamp = wp_next_scheduled($hook_name, array($mapping_id));
        
        if ($timestamp) {
            wp_unschedule_event($timestamp, $hook_name, array($mapping_id));
            Azure_Logger::info("TEC Calendar Mapping Manager: Unscheduled mapping ID {$mapping_id}", 'TEC');
            return true;
        }
        
        return false;
    }
}