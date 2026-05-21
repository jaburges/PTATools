<?php
/**
 * Microsoft Graph API handler for Calendar functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Calendar_GraphAPI {
    
    private $auth;
    private $cache_duration;
    
    public function __construct() {
        if (class_exists('Azure_Calendar_Auth')) {
            $this->auth = new Azure_Calendar_Auth();
        }
        
        $this->cache_duration = Azure_Settings::get_setting('calendar_cache_duration', 3600);
        
        // AJAX handlers
        add_action('wp_ajax_azure_calendar_get_calendars', array($this, 'ajax_get_calendars'));
        add_action('wp_ajax_azure_calendar_get_events', array($this, 'ajax_get_events'));
        add_action('wp_ajax_azure_calendar_create_event', array($this, 'ajax_create_event'));
        add_action('wp_ajax_azure_calendar_update_event', array($this, 'ajax_update_event'));
        add_action('wp_ajax_azure_calendar_delete_event', array($this, 'ajax_delete_event'));
    }
    
    /**
     * Get user calendars from Microsoft Graph
     * If $user_email is provided, gets calendars for that specific user (shared mailbox)
     */
    public function get_calendars($user_email = null, $force_refresh = false) {
        if (!$this->auth) {
            return array();
        }
        
        // Use different cache key for different users
        $cache_key = $user_email ? 'azure_calendar_calendars_' . md5($user_email) : 'azure_calendar_calendars';
        
        if (!$force_refresh) {
            $cached_data = $this->get_cached_data($cache_key);
            if ($cached_data !== false) {
                return $cached_data;
            }
        }
        
        // Get access token for specific user or default
        if ($user_email) {
            $access_token = $this->auth->get_user_access_token($user_email);
        } else {
            $access_token = $this->auth->get_access_token();
        }
        
        if (!$access_token) {
            $user_context = $user_email ? " for user {$user_email}" : '';
            Azure_Logger::error("Calendar API: No access token available{$user_context}");
            return array();
        }
        
        try {
            // Build API URL - use specific user endpoint if user_email provided, otherwise use /me
            if ($user_email) {
                $api_url = "https://graph.microsoft.com/v1.0/users/{$user_email}/calendars";
                Azure_Logger::debug("Calendar API: Fetching calendars for user: {$user_email}");
            } else {
                $api_url = 'https://graph.microsoft.com/v1.0/me/calendars';
                Azure_Logger::debug("Calendar API: Fetching calendars for authenticated user");
            }
            
            $response = wp_remote_get($api_url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json'
                ),
                'timeout' => 30
            ));
            
            if (is_wp_error($response)) {
                Azure_Logger::error('Calendar API: Failed to get calendars - ' . $response->get_error_message());
                return array();
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            
            if ($response_code !== 200) {
                Azure_Logger::error('Calendar API: Calendar request failed with status ' . $response_code . ': ' . $response_body);
                return array();
            }
            
            $data = json_decode($response_body, true);
            $calendars = $data['value'] ?? array();
            
            // Cache the results
            $this->cache_data($cache_key, $calendars, $this->cache_duration);
            
            Azure_Logger::info('Calendar API: Retrieved ' . count($calendars) . ' calendars');
            
            return $calendars;
            
        } catch (Exception $e) {
            Azure_Logger::error('Calendar API: Exception getting calendars - ' . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Alias for get_calendars with user_email
     * Makes it easier to read: get_user_calendars($email) vs get_calendars($email)
     */
    public function get_user_calendars($user_email, $force_refresh = false) {
        return $this->get_calendars($user_email, $force_refresh);
    }
    
    /**
     * Get calendars from a mailbox using delegated access
     * 
     * @param string $authenticated_user_email The user who authenticated (has the token)
     * @param string $mailbox_email The mailbox to access (shared mailbox)
     * @param bool $force_refresh Force refresh cache
     * @return array Array of calendars
     */
    public function get_mailbox_calendars($authenticated_user_email, $mailbox_email, $force_refresh = false) {
        if (!$this->auth) {
            return array();
        }
        
        // Use different cache key for different mailboxes
        $cache_key = 'azure_calendar_mailbox_' . md5($authenticated_user_email . '_' . $mailbox_email);
        
        if (!$force_refresh) {
            $cached_data = $this->get_cached_data($cache_key);
            if ($cached_data !== false) {
                return $cached_data;
            }
        }
        
        // Get access token for the AUTHENTICATED USER (who has delegated access)
        $access_token = $this->auth->get_user_access_token($authenticated_user_email);
        
        if (!$access_token) {
            Azure_Logger::error("Calendar API: No access token available for user {$authenticated_user_email}");
            return array();
        }
        
        try {
            // Use the MAILBOX's endpoint with the USER's token
            $api_url = "https://graph.microsoft.com/v1.0/users/{$mailbox_email}/calendars";
            Azure_Logger::debug("Calendar API: Fetching calendars for mailbox '{$mailbox_email}' using token from '{$authenticated_user_email}'");
            
            $response = wp_remote_get($api_url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json'
                ),
                'timeout' => 30
            ));
            
            if (is_wp_error($response)) {
                Azure_Logger::error('Calendar API: Failed to get mailbox calendars - ' . $response->get_error_message());
                return array();
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            
            if ($response_code !== 200) {
                Azure_Logger::error('Calendar API: Mailbox calendar request failed with status ' . $response_code . ': ' . $response_body);
                return array();
            }
            
            $data = json_decode($response_body, true);
            $calendars = $data['value'] ?? array();
            
            // Cache the results
            $this->cache_data($cache_key, $calendars, $this->cache_duration);
            
            Azure_Logger::info("Calendar API: Retrieved " . count($calendars) . " calendars from mailbox '{$mailbox_email}'");
            
            return $calendars;
            
        } catch (Exception $e) {
            Azure_Logger::error('Calendar API: Exception getting mailbox calendars - ' . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Get calendar events
     * @param string $calendar_id The calendar ID to fetch events from
     * @param string $start_date Start date for events (ISO format)
     * @param string $end_date End date for events (ISO format)
     * @param int $max_events Maximum number of events to fetch
     * @param bool $force_refresh Force refresh from API (skip cache)
     * @param string $user_email The authenticated user's email (to get token)
     * @param string $mailbox_email Optional shared mailbox email (if different from user)
     */
    public function get_calendar_events($calendar_id, $start_date = null, $end_date = null, $max_events = null, $force_refresh = false, $user_email = null, $mailbox_email = null) {
        if (!$this->auth) {
            return array();
        }
        
        // Default date range: next 30 days
        if (!$start_date) {
            $start_date = date('Y-m-d\TH:i:s\Z');
        }
        if (!$end_date) {
            $end_date = date('Y-m-d\TH:i:s\Z', strtotime('+30 days'));
        }
        
        $max_events = $max_events ?: Azure_Settings::get_setting('calendar_max_events_per_calendar', 100);
        
        // Include mailbox in cache key if provided
        $cache_key = 'azure_calendar_events_' . md5($calendar_id . $start_date . $end_date . $max_events . ($mailbox_email ?? ''));
        
        if (!$force_refresh) {
            $cached_data = $this->get_cached_data($cache_key);
            if ($cached_data !== false) {
                return $cached_data;
            }
        }
        
        // Get access token for specific user or default
        if ($user_email) {
            $access_token = $this->auth->get_user_access_token($user_email);
        } else {
            $access_token = $this->auth->get_access_token();
        }
        
        if (!$access_token) {
            $user_context = $user_email ? " for user {$user_email}" : '';
            Azure_Logger::error("Calendar API: No access token available for events{$user_context}");
            return array();
        }
        
        try {
            // Build the API URL with query parameters
            $query_params = array(
                'startDateTime' => $start_date,
                'endDateTime' => $end_date,
                '$top' => $max_events,
                '$orderby' => 'start/dateTime',
                '$select' => 'id,subject,start,end,location,attendees,body,isAllDay,showAs,sensitivity,categories'
            );
            
            // Use /users/{mailbox}/calendars/ for shared mailbox, otherwise /me/calendars/
            if ($mailbox_email) {
                $api_url = "https://graph.microsoft.com/v1.0/users/{$mailbox_email}/calendars/{$calendar_id}/calendarView?" . http_build_query($query_params);
                Azure_Logger::info("Calendar API: Fetching events from mailbox '{$mailbox_email}' calendar '{$calendar_id}' using token from '{$user_email}'", 'Calendar');
            } else {
                $api_url = "https://graph.microsoft.com/v1.0/me/calendars/{$calendar_id}/calendarView?" . http_build_query($query_params);
                Azure_Logger::info("Calendar API: Fetching events from /me/calendars/{$calendar_id} (no mailbox specified)", 'Calendar');
            }
            
            Azure_Logger::debug("Calendar API: Full URL: " . substr($api_url, 0, 200) . "...", 'Calendar');
            
            // Get the preferred timezone for this calendar from settings
            $settings = Azure_Settings::get_all_settings();
            $preferred_timezone = '';
            
            // Check for per-calendar timezone setting first
            if (!empty($settings['calendar_timezone_' . $calendar_id])) {
                $preferred_timezone = $settings['calendar_timezone_' . $calendar_id];
            } elseif (!empty($settings['calendar_default_timezone'])) {
                $preferred_timezone = $settings['calendar_default_timezone'];
            } else {
                // Fall back to WordPress timezone
                $preferred_timezone = wp_timezone_string();
            }
            
            // Convert IANA timezone to Windows format for Graph API if needed
            $outlook_timezone = $this->convert_iana_to_windows_timezone($preferred_timezone);
            
            Azure_Logger::debug("Calendar API: Using timezone preference: {$outlook_timezone} (from: {$preferred_timezone})", 'Calendar');
            
            $response = wp_remote_get($api_url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json',
                    'Prefer' => 'outlook.timezone="' . $outlook_timezone . '"'
                ),
                'timeout' => 30
            ));
            
            if (is_wp_error($response)) {
                Azure_Logger::error('Calendar API: Failed to get events - ' . $response->get_error_message());
                return array();
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            
            Azure_Logger::debug("Calendar API: Events response code: {$response_code}", 'Calendar');
            
            if ($response_code !== 200) {
                $error_context = $mailbox_email ? "mailbox '{$mailbox_email}'" : "user's own calendars";
                Azure_Logger::error("Calendar API: Events request failed for {$error_context}, calendar ID '{$calendar_id}' - Status {$response_code}: {$response_body}");
                
                // Provide helpful hint for 404 errors
                if ($response_code === 404) {
                    Azure_Logger::error("Calendar API: 404 error typically means the calendar ID doesn't exist in the target mailbox. You may need to delete and re-create calendar mappings after changing mailbox settings.");
                }
                return array();
            }
            
            $data = json_decode($response_body, true);
            $events = $data['value'] ?? array();
            
            Azure_Logger::info("Calendar API: Raw events count from Graph API: " . count($events), 'Calendar');
            
            // Process events for easier frontend consumption
            $processed_events = $this->process_events($events);
            
            Azure_Logger::info("Calendar API: Processed events count: " . count($processed_events), 'Calendar');
            
            // Cache the results
            $this->cache_data($cache_key, $processed_events, $this->cache_duration);
            
            $mailbox_context = $mailbox_email ? " (mailbox: {$mailbox_email})" : '';
            Azure_Logger::info('Calendar API: Retrieved ' . count($processed_events) . ' events for calendar ' . $calendar_id . $mailbox_context);
            
            return $processed_events;
            
        } catch (Exception $e) {
            Azure_Logger::error('Calendar API: Exception getting events - ' . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Create a new event
     */
    public function create_event($calendar_id, $event_data) {
        if (!$this->auth) {
            return false;
        }
        
        $access_token = $this->auth->get_access_token();
        
        if (!$access_token) {
            Azure_Logger::error('Calendar API: No access token available for creating event');
            return false;
        }
        
        try {
            $api_url = "https://graph.microsoft.com/v1.0/me/calendars/{$calendar_id}/events";
            
            $response = wp_remote_post($api_url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($event_data),
                'timeout' => 30
            ));
            
            if (is_wp_error($response)) {
                Azure_Logger::error('Calendar API: Failed to create event - ' . $response->get_error_message());
                return false;
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            
            if ($response_code === 201) {
                $created_event = json_decode($response_body, true);
                Azure_Logger::info('Calendar API: Event created successfully with ID: ' . $created_event['id']);
                
                // Clear calendar events cache
                $this->clear_events_cache($calendar_id);
                
                return $created_event;
            } else {
                Azure_Logger::error('Calendar API: Event creation failed with status ' . $response_code . ': ' . $response_body);
                return false;
            }
            
        } catch (Exception $e) {
            Azure_Logger::error('Calendar API: Exception creating event - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update an existing event
     */
    public function update_event($calendar_id, $event_id, $event_data) {
        if (!$this->auth) {
            return false;
        }
        
        $access_token = $this->auth->get_access_token();
        
        if (!$access_token) {
            Azure_Logger::error('Calendar API: No access token available for updating event');
            return false;
        }
        
        try {
            $api_url = "https://graph.microsoft.com/v1.0/me/calendars/{$calendar_id}/events/{$event_id}";
            
            $response = wp_remote_request($api_url, array(
                'method' => 'PATCH',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($event_data),
                'timeout' => 30
            ));
            
            if (is_wp_error($response)) {
                Azure_Logger::error('Calendar API: Failed to update event - ' . $response->get_error_message());
                return false;
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            
            if ($response_code === 200) {
                $updated_event = json_decode($response_body, true);
                Azure_Logger::info('Calendar API: Event updated successfully: ' . $event_id);
                
                // Clear calendar events cache
                $this->clear_events_cache($calendar_id);
                
                return $updated_event;
            } else {
                Azure_Logger::error('Calendar API: Event update failed with status ' . $response_code . ': ' . $response_body);
                return false;
            }
            
        } catch (Exception $e) {
            Azure_Logger::error('Calendar API: Exception updating event - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete an event
     */
    public function delete_event($calendar_id, $event_id) {
        if (!$this->auth) {
            return false;
        }
        
        $access_token = $this->auth->get_access_token();
        
        if (!$access_token) {
            Azure_Logger::error('Calendar API: No access token available for deleting event');
            return false;
        }
        
        try {
            $api_url = "https://graph.microsoft.com/v1.0/me/calendars/{$calendar_id}/events/{$event_id}";
            
            $response = wp_remote_request($api_url, array(
                'method' => 'DELETE',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token
                ),
                'timeout' => 30
            ));
            
            if (is_wp_error($response)) {
                Azure_Logger::error('Calendar API: Failed to delete event - ' . $response->get_error_message());
                return false;
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            
            if ($response_code === 204) {
                Azure_Logger::info('Calendar API: Event deleted successfully: ' . $event_id);
                
                // Clear calendar events cache
                $this->clear_events_cache($calendar_id);
                
                return true;
            } else {
                $response_body = wp_remote_retrieve_body($response);
                Azure_Logger::error('Calendar API: Event deletion failed with status ' . $response_code . ': ' . $response_body);
                return false;
            }
            
        } catch (Exception $e) {
            Azure_Logger::error('Calendar API: Exception deleting event - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Process events for frontend consumption
     */
    private function process_events($events) {
        $processed = array();
        
        foreach ($events as $event) {
            $processed[] = array(
                'id' => $event['id'] ?? '',
                'title' => $event['subject'] ?? '',
                'start' => $this->format_datetime($event['start'] ?? array()),
                'end' => $this->format_datetime($event['end'] ?? array()),
                'allDay' => $event['isAllDay'] ?? false,
                'location' => $this->format_location($event['location'] ?? array()),
                'description' => $this->format_body($event['body'] ?? array()),
                'attendees' => $this->format_attendees($event['attendees'] ?? array()),
                'categories' => $event['categories'] ?? array(),
                'showAs' => $event['showAs'] ?? 'busy',
                'sensitivity' => $event['sensitivity'] ?? 'normal'
            );
        }
        
        return $processed;
    }
    
    /**
     * Format datetime from Graph API response
     * Returns ISO 8601 format with timezone offset for proper FullCalendar handling
     */
    private function format_datetime($datetime_obj) {
        if (empty($datetime_obj['dateTime'])) {
            return '';
        }
        
        $timezone = $datetime_obj['timeZone'] ?? 'UTC';
        
        // Convert Windows timezone names to IANA format that PHP understands
        $timezone = $this->convert_windows_timezone($timezone);
        
        try {
            $dt = new DateTime($datetime_obj['dateTime'], new DateTimeZone($timezone));
            // Return ISO 8601 format WITH timezone offset so FullCalendar knows the correct time
            // Example: 2025-12-04T09:00:00-08:00 (Pacific Time)
            return $dt->format('c'); // 'c' = ISO 8601 with timezone offset
        } catch (Exception $e) {
            Azure_Logger::warning("Calendar: Failed to parse datetime with timezone '{$timezone}': " . $e->getMessage(), 'Calendar');
            // Fall back to treating the datetime as UTC
            try {
                $dt = new DateTime($datetime_obj['dateTime'], new DateTimeZone('UTC'));
                return $dt->format('c');
            } catch (Exception $e2) {
                return $datetime_obj['dateTime'];
            }
        }
    }
    
    /**
     * Convert Windows timezone names to IANA timezone identifiers
     * Microsoft Graph API returns Windows timezone names, but PHP requires IANA names
     */
    private function convert_windows_timezone($windows_tz) {
        // Common Windows to IANA timezone mappings
        $timezone_map = array(
            // US Timezones
            'Pacific Standard Time' => 'America/Los_Angeles',
            'Pacific Daylight Time' => 'America/Los_Angeles',
            'Mountain Standard Time' => 'America/Denver',
            'Mountain Daylight Time' => 'America/Denver',
            'Central Standard Time' => 'America/Chicago',
            'Central Daylight Time' => 'America/Chicago',
            'Eastern Standard Time' => 'America/New_York',
            'Eastern Daylight Time' => 'America/New_York',
            'Alaska Standard Time' => 'America/Anchorage',
            'Alaska Daylight Time' => 'America/Anchorage',
            'Hawaii-Aleutian Standard Time' => 'Pacific/Honolulu',
            'Hawaiian Standard Time' => 'Pacific/Honolulu',
            
            // Europe
            'GMT Standard Time' => 'Europe/London',
            'British Summer Time' => 'Europe/London',
            'W. Europe Standard Time' => 'Europe/Berlin',
            'Central European Standard Time' => 'Europe/Budapest',
            'Romance Standard Time' => 'Europe/Paris',
            'Central Europe Standard Time' => 'Europe/Prague',
            'E. Europe Standard Time' => 'Europe/Bucharest',
            'FLE Standard Time' => 'Europe/Helsinki',
            'GTB Standard Time' => 'Europe/Athens',
            'Russian Standard Time' => 'Europe/Moscow',
            
            // Asia Pacific
            'China Standard Time' => 'Asia/Shanghai',
            'Tokyo Standard Time' => 'Asia/Tokyo',
            'Korea Standard Time' => 'Asia/Seoul',
            'Singapore Standard Time' => 'Asia/Singapore',
            'India Standard Time' => 'Asia/Kolkata',
            'AUS Eastern Standard Time' => 'Australia/Sydney',
            'AUS Central Standard Time' => 'Australia/Darwin',
            'E. Australia Standard Time' => 'Australia/Brisbane',
            'Cen. Australia Standard Time' => 'Australia/Adelaide',
            'W. Australia Standard Time' => 'Australia/Perth',
            'New Zealand Standard Time' => 'Pacific/Auckland',
            
            // Others
            'UTC' => 'UTC',
            'Coordinated Universal Time' => 'UTC',
            'tzone://Microsoft/Utc' => 'UTC',
        );
        
        // Check if it's already a valid IANA timezone
        try {
            new DateTimeZone($windows_tz);
            return $windows_tz; // It's already valid
        } catch (Exception $e) {
            // Not a valid IANA timezone, try to map it
        }
        
        // Look up in our mapping
        if (isset($timezone_map[$windows_tz])) {
            return $timezone_map[$windows_tz];
        }
        
        // Try case-insensitive lookup
        foreach ($timezone_map as $win_tz => $iana_tz) {
            if (strcasecmp($win_tz, $windows_tz) === 0) {
                return $iana_tz;
            }
        }
        
        // Log unknown timezone and fall back to UTC
        Azure_Logger::warning("Calendar: Unknown timezone '{$windows_tz}', falling back to UTC", 'Calendar');
        return 'UTC';
    }
    
    /**
     * Convert IANA timezone identifiers to Windows timezone names
     * Used when making requests to Microsoft Graph API with Prefer header
     */
    private function convert_iana_to_windows_timezone($iana_tz) {
        // IANA to Windows timezone mappings (reverse of the above)
        $timezone_map = array(
            // US Timezones
            'America/Los_Angeles' => 'Pacific Standard Time',
            'America/Denver' => 'Mountain Standard Time',
            'America/Phoenix' => 'US Mountain Standard Time',
            'America/Chicago' => 'Central Standard Time',
            'America/New_York' => 'Eastern Standard Time',
            'America/Anchorage' => 'Alaskan Standard Time',
            'Pacific/Honolulu' => 'Hawaiian Standard Time',
            
            // Europe
            'Europe/London' => 'GMT Standard Time',
            'Europe/Berlin' => 'W. Europe Standard Time',
            'Europe/Paris' => 'Romance Standard Time',
            'Europe/Budapest' => 'Central European Standard Time',
            'Europe/Prague' => 'Central Europe Standard Time',
            'Europe/Bucharest' => 'E. Europe Standard Time',
            'Europe/Helsinki' => 'FLE Standard Time',
            'Europe/Athens' => 'GTB Standard Time',
            'Europe/Moscow' => 'Russian Standard Time',
            
            // Asia Pacific
            'Asia/Shanghai' => 'China Standard Time',
            'Asia/Hong_Kong' => 'China Standard Time',
            'Asia/Tokyo' => 'Tokyo Standard Time',
            'Asia/Seoul' => 'Korea Standard Time',
            'Asia/Singapore' => 'Singapore Standard Time',
            'Asia/Kolkata' => 'India Standard Time',
            'Australia/Sydney' => 'AUS Eastern Standard Time',
            'Australia/Melbourne' => 'AUS Eastern Standard Time',
            'Australia/Darwin' => 'AUS Central Standard Time',
            'Australia/Brisbane' => 'E. Australia Standard Time',
            'Australia/Adelaide' => 'Cen. Australia Standard Time',
            'Australia/Perth' => 'W. Australia Standard Time',
            'Pacific/Auckland' => 'New Zealand Standard Time',
            
            // Others
            'UTC' => 'UTC',
        );
        
        // Direct lookup
        if (isset($timezone_map[$iana_tz])) {
            return $timezone_map[$iana_tz];
        }
        
        // Try case-insensitive lookup
        foreach ($timezone_map as $iana => $windows) {
            if (strcasecmp($iana, $iana_tz) === 0) {
                return $windows;
            }
        }
        
        // If the timezone is already a Windows name (like from WordPress settings), return it
        // Check if it looks like a Windows timezone name (contains "Standard Time" or "Daylight Time")
        if (strpos($iana_tz, 'Standard Time') !== false || strpos($iana_tz, 'Daylight Time') !== false) {
            return $iana_tz;
        }
        
        // Log and fall back to UTC
        Azure_Logger::debug("Calendar: No Windows timezone mapping for '{$iana_tz}', using UTC", 'Calendar');
        return 'UTC';
    }
    
    /**
     * Format location from Graph API response
     */
    private function format_location($location_obj) {
        if (empty($location_obj)) {
            return '';
        }
        
        return $location_obj['displayName'] ?? '';
    }
    
    /**
     * Format body from Graph API response
     */
    private function format_body($body_obj) {
        if (empty($body_obj['content'])) {
            return '';
        }
        
        $content = $body_obj['content'];
        
        // Strip HTML if content type is HTML
        if (($body_obj['contentType'] ?? '') === 'html') {
            $content = wp_strip_all_tags($content);
        }
        
        return $content;
    }
    
    /**
     * Format attendees from Graph API response
     */
    private function format_attendees($attendees) {
        $formatted = array();
        
        foreach ($attendees as $attendee) {
            $formatted[] = array(
                'name' => $attendee['emailAddress']['name'] ?? '',
                'email' => $attendee['emailAddress']['address'] ?? '',
                'response' => $attendee['status']['response'] ?? 'none',
                'type' => $attendee['type'] ?? 'required'
            );
        }
        
        return $formatted;
    }
    
    /**
     * Cache data
     */
    private function cache_data($key, $data, $expiration = 3600) {
        global $wpdb;
        $table = Azure_Database::get_table_name('calendar_cache');
        
        if (!$table) {
            return false;
        }
        
        $expires_at = date('Y-m-d H:i:s', time() + $expiration);
        
        // Delete existing cache entry
        $wpdb->delete($table, array('cache_key' => $key), array('%s'));
        
        // Insert new cache entry
        return $wpdb->insert(
            $table,
            array(
                'cache_key' => $key,
                'calendar_id' => '',
                'event_data' => json_encode($data),
                'expires_at' => $expires_at
            ),
            array('%s', '%s', '%s', '%s')
        );
    }
    
    /**
     * Get cached data
     */
    private function get_cached_data($key) {
        global $wpdb;
        $table = Azure_Database::get_table_name('calendar_cache');
        
        if (!$table) {
            return false;
        }
        
        $cached = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE cache_key = %s AND expires_at > NOW()",
            $key
        ));
        
        if ($cached) {
            return json_decode($cached->event_data, true);
        }
        
        return false;
    }
    
    /**
     * Clear events cache for a calendar
     */
    private function clear_events_cache($calendar_id) {
        global $wpdb;
        $table = Azure_Database::get_table_name('calendar_cache');
        
        if (!$table) {
            return;
        }
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE cache_key LIKE %s",
            'azure_calendar_events_' . $calendar_id . '%'
        ));
    }
    
    /**
     * Clear all cached data
     */
    public function clear_all_cache() {
        global $wpdb;
        $table = Azure_Database::get_table_name('calendar_cache');
        
        if (!$table) {
            return false;
        }
        
        return $wpdb->query("DELETE FROM {$table}");
    }
    
    /**
     * AJAX handlers
     */
    public function ajax_get_calendars() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_die('Unauthorized access');
        }
        
        $force_refresh = isset($_POST['force_refresh']) && $_POST['force_refresh'];
        $calendars = $this->get_calendars($force_refresh);
        
        wp_send_json_success($calendars);
    }
    
    public function ajax_get_events() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_die('Unauthorized access');
        }
        
        $calendar_id = sanitize_text_field($_POST['calendar_id'] ?? '');
        $start_date = sanitize_text_field($_POST['start_date'] ?? '');
        $end_date = sanitize_text_field($_POST['end_date'] ?? '');
        $max_events = intval($_POST['max_events'] ?? 0);
        $force_refresh = isset($_POST['force_refresh']) && $_POST['force_refresh'];
        
        if (empty($calendar_id)) {
            wp_send_json_error('Calendar ID is required');
        }
        
        $events = $this->get_calendar_events($calendar_id, $start_date, $end_date, $max_events, $force_refresh);
        
        wp_send_json_success($events);
    }
    
    public function ajax_create_event() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_die('Unauthorized access');
        }
        
        $calendar_id = sanitize_text_field($_POST['calendar_id'] ?? '');
        $event_data = $_POST['event_data'] ?? array();
        
        if (empty($calendar_id) || empty($event_data)) {
            wp_send_json_error('Calendar ID and event data are required');
        }
        
        $result = $this->create_event($calendar_id, $event_data);
        
        if ($result) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error('Failed to create event');
        }
    }
    
    public function ajax_update_event() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_die('Unauthorized access');
        }
        
        $calendar_id = sanitize_text_field($_POST['calendar_id'] ?? '');
        $event_id = sanitize_text_field($_POST['event_id'] ?? '');
        $event_data = $_POST['event_data'] ?? array();
        
        if (empty($calendar_id) || empty($event_id) || empty($event_data)) {
            wp_send_json_error('Calendar ID, event ID and event data are required');
        }
        
        $result = $this->update_event($calendar_id, $event_id, $event_data);
        
        if ($result) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error('Failed to update event');
        }
    }
    
    public function ajax_delete_event() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_die('Unauthorized access');
        }
        
        $calendar_id = sanitize_text_field($_POST['calendar_id'] ?? '');
        $event_id = sanitize_text_field($_POST['event_id'] ?? '');
        
        if (empty($calendar_id) || empty($event_id)) {
            wp_send_json_error('Calendar ID and event ID are required');
        }
        
        $result = $this->delete_event($calendar_id, $event_id);
        
        if ($result) {
            wp_send_json_success('Event deleted successfully');
        } else {
            wp_send_json_error('Failed to delete event');
        }
    }
}
