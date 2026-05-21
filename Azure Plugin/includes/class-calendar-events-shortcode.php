<?php
/**
 * Calendar Events Shortcode for Azure Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Calendar_EventsShortcode {
    
    public function __construct() {
        // NOTE (v3.91.11): `azure_calendar_events` is now handled by the
        // real implementation in Azure_Calendar_Shortcode (which reads
        // pta_event posts and renders the image + Join-meeting cards).
        // We must NOT re-register it here or this stub will overwrite
        // the real callback (last add_shortcode() wins) and visitors
        // get the placeholder "No upcoming events found." message even
        // when the post type has plenty of events.
        //
        // The other two shortcodes below are pure stubs that were
        // never wired up to real data, but leaving them registered
        // doesn't hurt — they return empty results.
        add_shortcode('azure_upcoming_events', array($this, 'render_upcoming_events'));
        add_shortcode('azure_event_details', array($this, 'render_event_details'));

        if (class_exists('Azure_Logger')) {
            Azure_Logger::debug('Calendar Events Shortcode: Initialized (azure_calendar_events handled by Azure_Calendar_Shortcode)');
        }
    }
    
    /**
     * Render calendar events shortcode
     */
    public function render_events_shortcode($atts) {
        $atts = shortcode_atts(array(
            'calendar_id' => '',
            'limit' => 10,
            'view' => 'list',
            'show_date' => 'true',
            'show_time' => 'true',
            'show_location' => 'true',
            'date_format' => 'F j, Y',
            'time_format' => 'g:i A'
        ), $atts, 'azure_calendar_events');
        
        if (class_exists('Azure_Logger')) {
            Azure_Logger::debug('Calendar Events Shortcode: Rendering events for calendar ' . $atts['calendar_id']);
        }
        
        // Get events (placeholder)
        $events = $this->get_calendar_events($atts['calendar_id'], $atts['limit']);
        
        $output = '<div class="azure-calendar-events">';
        
        if (empty($events)) {
            $output .= '<p class="no-events">No upcoming events found.</p>';
        } else {
            foreach ($events as $event) {
                $output .= $this->render_event_item($event, $atts);
            }
        }
        
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Render upcoming events shortcode
     */
    public function render_upcoming_events($atts) {
        $atts = shortcode_atts(array(
            'limit' => 5,
            'days' => 30,
            'show_date' => 'true',
            'show_time' => 'true'
        ), $atts, 'azure_upcoming_events');
        
        if (class_exists('Azure_Logger')) {
            Azure_Logger::debug('Calendar Events Shortcode: Rendering upcoming events');
        }
        
        // Get upcoming events (placeholder)
        $events = $this->get_upcoming_events($atts['days'], $atts['limit']);
        
        $output = '<div class="azure-upcoming-events">';
        $output .= '<h3>Upcoming Events</h3>';
        
        if (empty($events)) {
            $output .= '<p class="no-events">No upcoming events in the next ' . $atts['days'] . ' days.</p>';
        } else {
            $output .= '<ul class="events-list">';
            foreach ($events as $event) {
                $output .= '<li>' . $this->render_event_summary($event, $atts) . '</li>';
            }
            $output .= '</ul>';
        }
        
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Render event details shortcode
     */
    public function render_event_details($atts) {
        $atts = shortcode_atts(array(
            'event_id' => '',
            'show_description' => 'true',
            'show_attendees' => 'false'
        ), $atts, 'azure_event_details');
        
        if (empty($atts['event_id'])) {
            return '<p class="error">Event ID is required.</p>';
        }
        
        if (class_exists('Azure_Logger')) {
            Azure_Logger::debug('Calendar Events Shortcode: Rendering event details for ' . $atts['event_id']);
        }
        
        // Get event details (placeholder)
        $event = $this->get_event_details($atts['event_id']);
        
        if (!$event) {
            return '<p class="error">Event not found.</p>';
        }
        
        $output = '<div class="azure-event-details">';
        $output .= '<h3>' . esc_html($event['title']) . '</h3>';
        
        if ($atts['show_description'] === 'true' && !empty($event['description'])) {
            $output .= '<div class="event-description">' . wp_kses_post($event['description']) . '</div>';
        }
        
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Get calendar events (placeholder)
     */
    private function get_calendar_events($calendar_id, $limit) {
        // Placeholder - would fetch from Microsoft Graph API or local database
        return array();
    }
    
    /**
     * Get upcoming events (placeholder)
     */
    private function get_upcoming_events($days, $limit) {
        // Placeholder - would fetch upcoming events
        return array();
    }
    
    /**
     * Get event details (placeholder)
     */
    private function get_event_details($event_id) {
        // Placeholder - would fetch single event details
        return null;
    }
    
    /**
     * Render individual event item
     */
    private function render_event_item($event, $atts) {
        $output = '<div class="event-item">';
        $output .= '<h4>' . esc_html($event['title'] ?? 'Untitled Event') . '</h4>';
        
        if ($atts['show_date'] === 'true') {
            $output .= '<div class="event-date">Date: ' . date($atts['date_format'], strtotime($event['start_date'] ?? 'now')) . '</div>';
        }
        
        if ($atts['show_time'] === 'true') {
            $output .= '<div class="event-time">Time: ' . date($atts['time_format'], strtotime($event['start_date'] ?? 'now')) . '</div>';
        }
        
        if ($atts['show_location'] === 'true' && !empty($event['location'])) {
            $output .= '<div class="event-location">Location: ' . esc_html($event['location']) . '</div>';
        }
        
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Render event summary for upcoming events
     */
    private function render_event_summary($event, $atts) {
        $summary = '<strong>' . esc_html($event['title'] ?? 'Untitled Event') . '</strong>';
        
        if ($atts['show_date'] === 'true') {
            $summary .= ' - ' . date('M j', strtotime($event['start_date'] ?? 'now'));
        }
        
        if ($atts['show_time'] === 'true') {
            $summary .= ' at ' . date('g:i A', strtotime($event['start_date'] ?? 'now'));
        }
        
        return $summary;
    }
}
