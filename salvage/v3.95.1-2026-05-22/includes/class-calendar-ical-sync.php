<?php
/**
 * Calendar iCal Sync for Azure Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Calendar_ICalSync {
    
    private $settings;
    
    public function __construct() {
        try {
            $this->settings = class_exists('Azure_Settings') ? Azure_Settings::get_all_settings() : array();
        } catch (Exception $e) {
            $this->settings = array();
            error_log('Calendar iCal Sync: Settings error - ' . $e->getMessage());
        }
        
        // Hook for scheduled sync
        add_action('azure_calendar_ical_sync', array($this, 'run_sync'));
        
        // AJAX actions
        add_action('wp_ajax_azure_calendar_import_ical', array($this, 'ajax_import_ical'));
        add_action('wp_ajax_azure_calendar_export_ical', array($this, 'ajax_export_ical'));
        
        if (class_exists('Azure_Logger')) {
            Azure_Logger::debug('Calendar iCal Sync: Initialized');
        }
    }
    
    /**
     * Import iCal feed
     */
    public function import_ical($feed_url, $calendar_id = null) {
        // Placeholder for iCal import functionality
        if (class_exists('Azure_Logger')) {
            Azure_Logger::debug('Calendar iCal Sync: Importing from ' . $feed_url);
        }
        
        if (!filter_var($feed_url, FILTER_VALIDATE_URL)) {
            throw new Exception('Invalid iCal feed URL provided');
        }
        
        // Basic implementation would fetch and parse iCal data
        return array('success' => true, 'events_imported' => 0, 'message' => 'iCal import feature coming soon');
    }
    
    /**
     * Export calendar to iCal format
     */
    public function export_ical($calendar_id = null) {
        // Placeholder for iCal export functionality
        if (class_exists('Azure_Logger')) {
            Azure_Logger::debug('Calendar iCal Sync: Exporting calendar ' . $calendar_id);
        }
        
        $ical_content = "BEGIN:VCALENDAR\r\n";
        $ical_content .= "VERSION:2.0\r\n";
        $ical_content .= "PRODID:-//Azure Plugin//Calendar Export//EN\r\n";
        $ical_content .= "METHOD:PUBLISH\r\n";
        $ical_content .= "END:VCALENDAR\r\n";
        
        return $ical_content;
    }
    
    /**
     * Run scheduled sync
     */
    public function run_sync() {
        if (class_exists('Azure_Logger')) {
            Azure_Logger::debug('Calendar iCal Sync: Running scheduled sync');
        }
        // Placeholder for scheduled sync functionality
    }
    
    /**
     * AJAX handler for iCal import
     */
    public function ajax_import_ical() {
        check_ajax_referer('azure_calendar_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $feed_url = sanitize_url($_POST['feed_url'] ?? '');
        $calendar_id = sanitize_text_field($_POST['calendar_id'] ?? '');
        
        try {
            $result = $this->import_ical($feed_url, $calendar_id);
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * AJAX handler for iCal export
     */
    public function ajax_export_ical() {
        check_ajax_referer('azure_calendar_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $calendar_id = sanitize_text_field($_POST['calendar_id'] ?? '');
        
        try {
            $ical_content = $this->export_ical($calendar_id);
            
            header('Content-Type: text/calendar');
            header('Content-Disposition: attachment; filename="calendar.ics"');
            echo $ical_content;
            exit;
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}
