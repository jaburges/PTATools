<?php
/**
 * Calendar Events Custom Post Type for Azure Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Calendar_EventsCPT {
    
    public function __construct() {
        // TEMPORARILY DISABLED - Testing if hooks cause website crash
        // add_action('init', array($this, 'register_post_type'));
        // add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        // add_action('save_post', array($this, 'save_meta_boxes'));
        
        if (class_exists('Azure_Logger')) {
            Azure_Logger::debug('Calendar Events CPT: Initialized (hooks disabled for testing)');
        }
    }
    
    /**
     * Register Calendar Events Custom Post Type
     */
    public function register_post_type() {
        $labels = array(
            'name' => 'Calendar Events',
            'singular_name' => 'Calendar Event',
            'menu_name' => 'Calendar Events',
            'add_new' => 'Add New Event',
            'add_new_item' => 'Add New Calendar Event',
            'edit_item' => 'Edit Calendar Event',
            'new_item' => 'New Calendar Event',
            'view_item' => 'View Calendar Event',
            'search_items' => 'Search Calendar Events',
            'not_found' => 'No calendar events found',
            'not_found_in_trash' => 'No calendar events found in trash'
        );
        
        $args = array(
            'labels' => $labels,
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => 'azure-plugin',
            'query_var' => true,
            'rewrite' => array('slug' => 'calendar-event'),
            'capability_type' => 'post',
            'has_archive' => true,
            'hierarchical' => false,
            'menu_position' => null,
            'supports' => array('title', 'editor', 'excerpt', 'thumbnail'),
            'menu_icon' => 'dashicons-calendar-alt'
        );
        
        register_post_type('azure_calendar_event', $args);
        
        if (class_exists('Azure_Logger')) {
            Azure_Logger::debug('Calendar Events CPT: Post type registered');
        }
    }
    
    /**
     * Add meta boxes for calendar events
     */
    public function add_meta_boxes() {
        add_meta_box(
            'azure_calendar_event_details',
            'Event Details',
            array($this, 'event_details_callback'),
            'azure_calendar_event',
            'normal',
            'high'
        );
    }
    
    /**
     * Meta box callback for event details
     */
    public function event_details_callback($post) {
        wp_nonce_field('azure_calendar_event_nonce', 'azure_calendar_event_nonce_field');
        
        $start_date = get_post_meta($post->ID, '_event_start_date', true);
        $end_date = get_post_meta($post->ID, '_event_end_date', true);
        $location = get_post_meta($post->ID, '_event_location', true);
        
        echo '<table class="form-table">';
        echo '<tr><th><label for="event_start_date">Start Date:</label></th>';
        echo '<td><input type="datetime-local" id="event_start_date" name="event_start_date" value="' . esc_attr($start_date) . '" /></td></tr>';
        echo '<tr><th><label for="event_end_date">End Date:</label></th>';
        echo '<td><input type="datetime-local" id="event_end_date" name="event_end_date" value="' . esc_attr($end_date) . '" /></td></tr>';
        echo '<tr><th><label for="event_location">Location:</label></th>';
        echo '<td><input type="text" id="event_location" name="event_location" value="' . esc_attr($location) . '" class="regular-text" /></td></tr>';
        echo '</table>';
    }
    
    /**
     * Save meta box data
     */
    public function save_meta_boxes($post_id) {
        if (!isset($_POST['azure_calendar_event_nonce_field']) || 
            !wp_verify_nonce($_POST['azure_calendar_event_nonce_field'], 'azure_calendar_event_nonce')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        if (isset($_POST['event_start_date'])) {
            update_post_meta($post_id, '_event_start_date', sanitize_text_field($_POST['event_start_date']));
        }
        
        if (isset($_POST['event_end_date'])) {
            update_post_meta($post_id, '_event_end_date', sanitize_text_field($_POST['event_end_date']));
        }
        
        if (isset($_POST['event_location'])) {
            update_post_meta($post_id, '_event_location', sanitize_text_field($_POST['event_location']));
        }
        
        if (class_exists('Azure_Logger')) {
            Azure_Logger::debug('Calendar Events CPT: Meta data saved for post ' . $post_id);
        }
    }
}
