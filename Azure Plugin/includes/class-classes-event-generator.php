<?php
/**
 * Classes Module - Event Generator
 *
 * Generates and manages pta_event posts for class products.
 * Creates hierarchical categories (Enrichment > Class Name - Year - Season)
 * for Outlook calendar sync.
 *
 * The `tribe_events_cat` taxonomy slug is intentionally retained because
 * it is the SHARED public slug used by both `pta_event` and the legacy
 * `tribe_events` post type (see class-event-cpt.php). Term IDs and URLs
 * are preserved across the TEC retirement.
 *
 * Event meta keys (_EventStartDate, _EventEndDate, _EventVenueID, etc.)
 * are also retained — pta_event inherits TEC's meta schema so the
 * migration is a straight post_type rewrite with no postmeta rewrite.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Classes_Event_Generator {
    
    const PARENT_CATEGORY_NAME = 'Enrichment';
    const PARENT_CATEGORY_SLUG = 'enrichment';
    
    public function __construct() {
        // Hook into product save
        add_action('azure_classes_product_saved', array($this, 'generate_events'), 10, 1);
        
        // Hook into product status changes
        add_action('transition_post_status', array($this, 'sync_event_status'), 10, 3);
        
        // Hook into product deletion
        add_action('before_delete_post', array($this, 'delete_linked_events'));
    }
    
    /**
     * Generate pta_event posts for a class product (one per scheduled session).
     */
    public function generate_events($product_id) {
        $product = wc_get_product($product_id);
        
        if (!$product || $product->get_type() !== 'class') {
            return;
        }
        
        // Get schedule info
        $start_date = get_post_meta($product_id, '_class_start_date', true);
        $recurrence = get_post_meta($product_id, '_class_recurrence', true);
        $occurrences = intval(get_post_meta($product_id, '_class_occurrences', true));
        $start_time = get_post_meta($product_id, '_class_start_time', true);
        $duration = intval(get_post_meta($product_id, '_class_duration', true));
        $duration_unit = get_post_meta($product_id, '_class_duration_unit', true) ?: 'minutes';
        $venue_id = get_post_meta($product_id, '_class_venue', true);
        $season = get_post_meta($product_id, '_class_season', true);
        
        // Validate required fields
        if (empty($start_date) || empty($recurrence) || $occurrences < 1) {
            Azure_Logger::warning('Classes: Cannot generate events - missing required schedule fields', array(
                'product_id' => $product_id
            ));
            return;
        }
        
        // Calculate duration in minutes
        $duration_minutes = $duration_unit === 'hours' ? $duration * 60 : $duration;
        
        // Get or create parent "Enrichment" category
        $parent_category_id = $this->get_or_create_parent_category();
        
        // Get or create child category for this class
        $class_name = $product->get_name();
        $year = Azure_Classes_Module::get_year_from_date($start_date);
        if (empty($season)) {
            $season = Azure_Classes_Module::get_season_from_date($start_date);
        }
        
        $child_category_id = $this->get_or_create_child_category($class_name, $year, $season, $parent_category_id);
        
        // Calculate all event dates
        $event_dates = $this->calculate_event_dates($start_date, $recurrence, $occurrences);
        
        // Get existing event IDs to update or delete
        $existing_event_ids = get_post_meta($product_id, '_class_event_ids', true);
        if (!is_array($existing_event_ids)) {
            $existing_event_ids = array();
        }
        
        // Delete excess events if occurrences reduced
        if (count($existing_event_ids) > count($event_dates)) {
            $events_to_delete = array_slice($existing_event_ids, count($event_dates));
            foreach ($events_to_delete as $event_id) {
                wp_delete_post($event_id, true);
            }
            $existing_event_ids = array_slice($existing_event_ids, 0, count($event_dates));
        }
        
        // Create or update events
        $new_event_ids = array();
        $product_status = get_post_status($product_id);
        
        foreach ($event_dates as $index => $date) {
            // Check if this event was manually modified
            $event_id = isset($existing_event_ids[$index]) ? $existing_event_ids[$index] : 0;
            $manually_modified = get_post_meta($event_id, '_class_manually_modified', true) === 'yes';
            
            if ($event_id && $manually_modified) {
                // Keep existing event, don't overwrite
                $new_event_ids[] = $event_id;
                
                // But still update categories
                wp_set_object_terms($event_id, array($parent_category_id, $child_category_id), 'tribe_events_cat');
                continue;
            }
            
            // Build event data
            $event_start = $date . ' ' . $start_time;
            $event_end = date('Y-m-d H:i:s', strtotime($event_start) + ($duration_minutes * 60));
            
            $event_title = sprintf('%s - Session %d', $class_name, $index + 1);
            
            $event_data = array(
                'post_title'   => $event_title,
                'post_content' => $this->build_event_description($product),
                'post_status'  => $product_status,
                'post_type'    => 'pta_event'
            );

            if ($event_id) {
                $event_data['ID'] = $event_id;
                wp_update_post($event_data);
            } else {
                $event_id = wp_insert_post($event_data);
            }

            if ($event_id && !is_wp_error($event_id)) {
                // Event meta (shared with the legacy TEC schema for back-compat)
                update_post_meta($event_id, '_EventStartDate', $event_start);
                update_post_meta($event_id, '_EventEndDate', $event_end);
                update_post_meta($event_id, '_EventStartDateUTC', get_gmt_from_date($event_start));
                update_post_meta($event_id, '_EventEndDateUTC', get_gmt_from_date($event_end));
                update_post_meta($event_id, '_EventDuration', $duration_minutes * 60);
                update_post_meta($event_id, '_EventTimezone', wp_timezone_string());
                update_post_meta($event_id, '_EventTimezoneAbbr', '');
                
                // Set venue
                if ($venue_id) {
                    update_post_meta($event_id, '_EventVenueID', $venue_id);
                }
                
                // Link back to product
                update_post_meta($event_id, '_class_product_id', $product_id);
                update_post_meta($event_id, '_class_session_number', $index + 1);
                
                // Set categories (both parent and child)
                wp_set_object_terms($event_id, array($parent_category_id, $child_category_id), 'tribe_events_cat');
                
                $new_event_ids[] = $event_id;
            }
        }
        
        // Save event IDs to product
        update_post_meta($product_id, '_class_event_ids', $new_event_ids);
        update_post_meta($product_id, '_class_category_id', $child_category_id);
        
        Azure_Logger::info('Classes: Events generated', array(
            'product_id' => $product_id,
            'event_count' => count($new_event_ids),
            'category_id' => $child_category_id
        ));
    }
    
    /**
     * Get or create the parent "Enrichment" category
     */
    private function get_or_create_parent_category() {
        $term = get_term_by('slug', self::PARENT_CATEGORY_SLUG, 'tribe_events_cat');
        
        if ($term) {
            return $term->term_id;
        }
        
        // Create the parent category
        $result = wp_insert_term(
            self::PARENT_CATEGORY_NAME,
            'tribe_events_cat',
            array('slug' => self::PARENT_CATEGORY_SLUG)
        );
        
        if (is_wp_error($result)) {
            Azure_Logger::error('Classes: Failed to create Enrichment category', array(
                'error' => $result->get_error_message()
            ));
            return 0;
        }
        
        Azure_Logger::info('Classes: Created Enrichment parent category', array(
            'term_id' => $result['term_id']
        ));
        
        return $result['term_id'];
    }
    
    /**
     * Get or create a child category for a specific class
     */
    private function get_or_create_child_category($class_name, $year, $season, $parent_id) {
        // Build category name: "Chess - 2025 - Fall"
        $category_name = sprintf('%s - %s - %s', $class_name, $year, $season);
        $category_slug = sanitize_title($category_name);
        
        $term = get_term_by('slug', $category_slug, 'tribe_events_cat');
        
        if ($term) {
            // Update parent if needed
            if ($term->parent != $parent_id) {
                wp_update_term($term->term_id, 'tribe_events_cat', array('parent' => $parent_id));
            }
            return $term->term_id;
        }
        
        // Create the child category
        $result = wp_insert_term(
            $category_name,
            'tribe_events_cat',
            array(
                'slug'   => $category_slug,
                'parent' => $parent_id
            )
        );
        
        if (is_wp_error($result)) {
            Azure_Logger::error('Classes: Failed to create class category', array(
                'category_name' => $category_name,
                'error' => $result->get_error_message()
            ));
            return 0;
        }
        
        Azure_Logger::info('Classes: Created class category', array(
            'category_name' => $category_name,
            'term_id' => $result['term_id']
        ));
        
        return $result['term_id'];
    }
    
    /**
     * Calculate all event dates based on recurrence
     */
    private function calculate_event_dates($start_date, $recurrence, $occurrences) {
        $dates = array();
        $current_date = strtotime($start_date);
        
        for ($i = 0; $i < $occurrences; $i++) {
            $dates[] = date('Y-m-d', $current_date);
            
            switch ($recurrence) {
                case 'daily':
                    $current_date = strtotime('+1 day', $current_date);
                    break;
                case 'weekly':
                    $current_date = strtotime('+1 week', $current_date);
                    break;
                case 'biweekly':
                    $current_date = strtotime('+2 weeks', $current_date);
                    break;
                case 'monthly':
                    $current_date = strtotime('+1 month', $current_date);
                    break;
                default:
                    $current_date = strtotime('+1 week', $current_date);
            }
        }
        
        return $dates;
    }
    
    /**
     * Build event description from product
     */
    private function build_event_description($product) {
        $product_id = $product->get_id();
        $description = $product->get_description();
        
        // Add provider info
        $provider_id = get_post_meta($product_id, '_class_provider', true);
        if ($provider_id) {
            $provider = get_term($provider_id, 'class_provider');
            if ($provider && !is_wp_error($provider)) {
                $company_name = get_term_meta($provider_id, 'provider_company_name', true);
                $contact_person = get_term_meta($provider_id, 'provider_contact_person', true);
                
                $description .= "\n\n<strong>Provider:</strong> " . ($company_name ?: $provider->name);
                if ($contact_person) {
                    $description .= "\n<strong>Contact:</strong> " . $contact_person;
                }
            }
        }
        
        // Add chaperone info
        $chaperone_id = get_post_meta($product_id, '_class_chaperone', true);
        if ($chaperone_id) {
            $chaperone = get_user_by('id', $chaperone_id);
            if ($chaperone) {
                $description .= "\n\n<strong>Chaperone:</strong> " . $chaperone->display_name;
            }
        }
        
        // Add link to product
        $description .= "\n\n<a href=\"" . get_permalink($product_id) . "\">View Class Details</a>";
        
        return $description;
    }
    
    /**
     * Sync event status when product status changes
     */
    public function sync_event_status($new_status, $old_status, $post) {
        if ($post->post_type !== 'product') {
            return;
        }
        
        $product = wc_get_product($post->ID);
        if (!$product || $product->get_type() !== 'class') {
            return;
        }
        
        $event_ids = get_post_meta($post->ID, '_class_event_ids', true);
        if (!is_array($event_ids) || empty($event_ids)) {
            return;
        }
        
        foreach ($event_ids as $event_id) {
            // Check if manually modified
            if (get_post_meta($event_id, '_class_manually_modified', true) === 'yes') {
                continue;
            }
            
            wp_update_post(array(
                'ID'          => $event_id,
                'post_status' => $new_status
            ));
        }
        
        Azure_Logger::info('Classes: Event status synced', array(
            'product_id' => $post->ID,
            'new_status' => $new_status,
            'event_count' => count($event_ids)
        ));
    }
    
    /**
     * Delete linked events when product is deleted
     */
    public function delete_linked_events($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'product') {
            return;
        }
        
        $product = wc_get_product($post_id);
        if (!$product || $product->get_type() !== 'class') {
            return;
        }
        
        $event_ids = get_post_meta($post_id, '_class_event_ids', true);
        if (!is_array($event_ids)) {
            return;
        }
        
        foreach ($event_ids as $event_id) {
            wp_delete_post($event_id, true);
        }
        
        Azure_Logger::info('Classes: Linked events deleted', array(
            'product_id' => $post_id,
            'event_count' => count($event_ids)
        ));
    }
    
    /**
     * Mark an event as manually modified (to prevent overwrite)
     */
    public static function mark_event_modified($event_id) {
        update_post_meta($event_id, '_class_manually_modified', 'yes');
        update_post_meta($event_id, '_class_modified_date', current_time('mysql'));
    }
    
    /**
     * Get all events for a class product
     */
    public static function get_class_events($product_id) {
        $event_ids = get_post_meta($product_id, '_class_event_ids', true);
        if (!is_array($event_ids) || empty($event_ids)) {
            return array();
        }
        
        $events = array();
        foreach ($event_ids as $event_id) {
            $event = get_post($event_id);
            // Accept both post types during the back-compat window: prod still
            // has ~129 historical `tribe_events` rows that the one-shot
            // migration will rename to `pta_event`.
            if ($event && in_array($event->post_type, array('pta_event', 'tribe_events'), true)) {
                $events[] = array(
                    'id'             => $event_id,
                    'title'          => $event->post_title,
                    'start_date'     => get_post_meta($event_id, '_EventStartDate', true),
                    'end_date'       => get_post_meta($event_id, '_EventEndDate', true),
                    'venue_id'       => get_post_meta($event_id, '_EventVenueID', true),
                    'session_number' => get_post_meta($event_id, '_class_session_number', true),
                    'modified'       => get_post_meta($event_id, '_class_manually_modified', true) === 'yes',
                    'status'         => $event->post_status,
                    'url'            => get_permalink($event_id)
                );
            }
        }
        
        return $events;
    }
}

