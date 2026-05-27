<?php
/**
 * Upcoming Events Module
 * 
 * Provides shortcodes for displaying upcoming pta_event posts in a clean, customizable format.
 * 
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Upcoming_Module {
    
    private static $instance = null;
    private const CACHE_VERSION_OPTION = 'azure_up_next_cache_version';
    /** Bump when query/render logic changes so stale transients are ignored. */
    private const CACHE_SCHEMA = '6';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Register shortcode
        add_shortcode('up-next', array($this, 'render_upcoming_shortcode'));
        
        // Enqueue frontend styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_styles'));
    }
    
    /**
     * Enqueue frontend styles only when [up-next] shortcode is present.
     */
    public function enqueue_frontend_styles() {
        global $post;
        if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'up-next')) {
            return;
        }
        wp_enqueue_style(
            'azure-upcoming-frontend',
            AZURE_PLUGIN_URL . 'css/upcoming-frontend.css',
            array(),
            AZURE_PLUGIN_VERSION
        );
        if (class_exists('Azure_Event_CPT') && file_exists(AZURE_PLUGIN_PATH . 'css/pta-event-shared.css')) {
            wp_enqueue_style(
                'pta-event-shared',
                AZURE_PLUGIN_URL . 'css/pta-event-shared.css',
                array(),
                AZURE_PLUGIN_VERSION
            );
        }
    }
    
    /**
     * Render the [up-next] shortcode
     * 
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function render_upcoming_shortcode($atts) {
        // Reads from the pta_event CPT. Bails out only if the CPT registrar
        // is missing (which would mean the plugin is partly loaded).
        if (!class_exists('Azure_Event_CPT')) {
            return '<p class="upcoming-error">' . __('Event CPT is not available.', 'azure-plugin') . '</p>';
        }

        // Parse attributes with defaults
        $atts = shortcode_atts(array(
            'current-week'        => 'true',
            'next-week'           => 'true',
            'columns'             => '1',
            'exclude-categories'  => '',
            'week-start'          => 'monday',
            'show-time'           => 'true',
            'link-titles'         => 'true',
            'show-join-meeting'   => 'true',
            'show-empty'          => 'true',
            'show-coming-up'      => 'true',
            'coming-up-days'      => '30',
            'coming-up-title'     => __('Coming up', 'azure-plugin'),
            'cache'               => 'true',
            'empty-message'       => __('No upcoming events.', 'azure-plugin'),
            'this-week-title'     => __('This Week', 'azure-plugin'),
            'next-week-title'     => __('Next Week', 'azure-plugin'),
        ), $atts, 'up-next');
        
        // Normalize boolean attributes
        $show_current_week = filter_var($atts['current-week'], FILTER_VALIDATE_BOOLEAN);
        $show_next_week = filter_var($atts['next-week'], FILTER_VALIDATE_BOOLEAN);
        $show_time = filter_var($atts['show-time'], FILTER_VALIDATE_BOOLEAN);
        $link_titles = filter_var($atts['link-titles'], FILTER_VALIDATE_BOOLEAN);
        $show_join_meeting = filter_var($atts['show-join-meeting'], FILTER_VALIDATE_BOOLEAN);
        $show_empty = filter_var($atts['show-empty'], FILTER_VALIDATE_BOOLEAN);
        $show_coming_up = filter_var($atts['show-coming-up'], FILTER_VALIDATE_BOOLEAN);
        $use_cache = filter_var($atts['cache'], FILTER_VALIDATE_BOOLEAN);
        $coming_up_days = max(7, min(90, (int) $atts['coming-up-days']));
        $columns = intval($atts['columns']);
        if ($columns < 1) $columns = 1;
        if ($columns > 3) $columns = 3;
        
        // Parse excluded categories
        $exclude_categories = array_filter(array_map('trim', explode(',', $atts['exclude-categories'])));
        
        // Get week boundaries
        $week_start_day = strtolower($atts['week-start']) === 'sunday' ? 0 : 1; // 0 = Sunday, 1 = Monday
        $today = new DateTime('today', wp_timezone());
        
        // Calculate start of current week
        $current_day_of_week = (int) $today->format('w'); // 0 = Sunday
        if ($week_start_day === 1) { // Monday start
            $days_since_start = $current_day_of_week === 0 ? 6 : $current_day_of_week - 1;
        } else { // Sunday start
            $days_since_start = $current_day_of_week;
        }
        
        $current_week_start = clone $today;
        $current_week_start->modify("-{$days_since_start} days");
        $current_week_start->setTime(0, 0, 0);

        // Inclusive end-of-day on the 7th day of each week so evening events
        // on the last day (e.g. Sunday 6pm) are not clipped by a midnight
        // exclusive upper bound.
        $current_week_end = clone $current_week_start;
        $current_week_end->modify('+6 days');
        $current_week_end->setTime(23, 59, 59);

        $next_week_start = clone $current_week_start;
        $next_week_start->modify('+7 days');
        $next_week_start->setTime(0, 0, 0);

        $next_week_end = clone $next_week_start;
        $next_week_end->modify('+6 days');
        $next_week_end->setTime(23, 59, 59);

        $coming_up_start = clone $today;
        $coming_up_start->setTime(0, 0, 0);
        $coming_up_end = clone $today;
        $coming_up_end->modify('+' . $coming_up_days . ' days');
        $coming_up_end->setTime(23, 59, 59);

        $cache_key = $this->get_cache_key($atts, $current_week_start, $coming_up_end);
        if ($use_cache) {
            $cached = get_transient($cache_key);
            if (is_string($cached)) {
                return $cached;
            }
        }
        
        $list_options = array(
            'show_time'         => $show_time,
            'link_titles'       => $link_titles,
            'show_join_meeting' => $show_join_meeting,
            'empty_message'     => $atts['empty-message'],
        );

        // Build output
        $output = '<div class="upcoming-events upcoming-columns-' . esc_attr($columns) . '">';
        
        $has_events = false;
        
        // Current week events
        if ($show_current_week) {
            $current_week_events = $this->get_events_in_range($current_week_start, $current_week_end, $exclude_categories);
            if (!empty($current_week_events) || $show_empty) {
                $output .= '<div class="upcoming-week upcoming-current-week">';
                $output .= '<h3>' . esc_html($atts['this-week-title']) . '</h3>';
                $output .= $this->render_events_list($current_week_events, $list_options);
                $output .= '</div>';
                if (!empty($current_week_events)) {
                    $has_events = true;
                }
            }
        }
        
        // Next week events
        if ($show_next_week) {
            $next_week_events = $this->get_events_in_range($next_week_start, $next_week_end, $exclude_categories);
            if (!empty($next_week_events) || $show_empty) {
                $output .= '<div class="upcoming-week upcoming-next-week">';
                $output .= '<h3>' . esc_html($atts['next-week-title']) . '</h3>';
                $output .= $this->render_events_list($next_week_events, $list_options);
                $output .= '</div>';
                if (!empty($next_week_events)) {
                    $has_events = true;
                }
            }
        }

        // When this/next week are empty, show the next N days (e.g. June events while still in May).
        if (!$has_events && $show_coming_up) {
            $coming_events = $this->get_events_in_range($coming_up_start, $coming_up_end, $exclude_categories);
            if (!empty($coming_events)) {
                $output .= '<div class="upcoming-week upcoming-coming-up">';
                $output .= '<h3>' . esc_html($atts['coming-up-title']) . '</h3>';
                $output .= $this->render_events_list($coming_events, $list_options);
                $output .= '</div>';
                $has_events = true;
            }
        }
        
        // If no events at all and not showing empty message
        if (!$has_events && !$show_empty) {
            if ($use_cache) {
                set_transient($cache_key, '', $this->get_cache_ttl($current_week_end));
            }
            return '';
        }
        
        $output .= '</div>';

        if ($use_cache) {
            set_transient($cache_key, $output, $this->get_cache_ttl($current_week_end));
        }
        
        return $output;
    }

    /**
     * Build a stable transient key for this shortcode output.
     *
     * The key includes shortcode attributes, current/next week range, plugin
     * version, and a lightweight cache-version option that gets bumped when
     * TEC events are edited. That gives us weekly caching without needing a
     * custom database table or duplicate event data.
     *
     * @param array $atts Normalized shortcode attributes.
     * @param DateTime $current_week_start Current week start.
     * @param DateTime $next_week_end End of next week.
     * @return string
     */
    private function get_cache_key($atts, $current_week_start, $next_week_end) {
        ksort($atts);
        $version = get_option(self::CACHE_VERSION_OPTION, '1');
        // Including the data-source flag in the cache key means a
        // flip from `tribe` to `pta` (or back) automatically falls
        // through to a fresh query without needing an explicit
        // invalidate_cache() bump on every flag change.
        $data_source = class_exists('Azure_Event_CPT')
            ? Azure_Event_CPT::get_data_source()
            : 'tribe';
        $parts = array(
            'atts' => $atts,
            'range' => array(
                $current_week_start->format('Y-m-d'),
                $next_week_end->format('Y-m-d'),
            ),
            'version'     => $version,
            'plugin'      => defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : 'unknown',
            'data_source' => $data_source,
            'schema'      => self::CACHE_SCHEMA,
        );

        return 'azure_up_next_' . md5(wp_json_encode($parts));
    }

    /**
     * Cache until the next week boundary, with a one-hour safety floor.
     *
     * @param DateTime $current_week_end Boundary where the shortcode result changes.
     * @return int TTL in seconds.
     */
    private function get_cache_ttl($current_week_end) {
        $seconds = $current_week_end->getTimestamp() - current_time('timestamp') + (10 * MINUTE_IN_SECONDS);
        return max(HOUR_IN_SECONDS, (int) $seconds);
    }

    /**
     * Invalidate cached [up-next] output and any page cache that may contain it.
     *
     * Bumping the shortcode cache version is necessary but not sufficient on
     * production: the homepage/page cache can still serve stale rendered HTML
     * that already contains the old [up-next] output. Whenever a pta_event is
     * created, synced, edited, or deleted, purge the page-cache layer too so
     * newly synced events appear immediately.
     *
     * @param int $post_id Optional pta_event post ID being changed.
     */
    public static function invalidate_cache($post_id = 0) {
        update_option(self::CACHE_VERSION_OPTION, (string) time(), false);

        if ($post_id) {
            clean_post_cache((int) $post_id);
        }

        // W3 Total Cache.
        if (function_exists('w3tc_flush_all')) {
            @w3tc_flush_all();
            return;
        }
        if (function_exists('w3tc_pgcache_flush')) {
            @w3tc_pgcache_flush();
        }
        if (function_exists('w3tc_flush_url')) {
            @w3tc_flush_url(home_url('/'));
        }

        // WP Rocket / LiteSpeed if those plugins are ever used on another host.
        if (function_exists('rocket_clean_domain')) {
            @rocket_clean_domain();
        }
        if (has_action('litespeed_purge_all')) {
            do_action('litespeed_purge_all');
        }

        // Last resort: clear object cache so stale transient/object-cache reads
        // don't outlive the page-cache purge.
        if (function_exists('wp_cache_flush')) {
            @wp_cache_flush();
        }
    }
    
    /**
     * Get pta_event posts within a date range.
     *
     * @param DateTime $start Start date
     * @param DateTime $end End date
     * @param array $exclude_categories Categories to exclude
     * @return array Array of event post objects
     */
    private function get_events_in_range($start, $end, $exclude_categories = array(), $future_only = false) {
        $post_type = 'pta_event';
        $taxonomy = class_exists('Azure_Event_CPT')
            ? Azure_Event_CPT::query_taxonomy()
            : 'pta_event_category';

        $args = array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_key'       => '_EventStartDate',
            'meta_type'      => 'DATETIME',
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'     => '_EventStartDate',
                    'value'   => $start->format('Y-m-d H:i:s'),
                    'compare' => '>=',
                    'type'    => 'DATETIME',
                ),
                array(
                    'key'     => '_EventStartDate',
                    'value'   => $end->format('Y-m-d H:i:s'),
                    'compare' => '<=',
                    'type'    => 'DATETIME',
                ),
            ),
        );

        // Exclude categories if specified
        if (!empty($exclude_categories) && taxonomy_exists($taxonomy)) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => $taxonomy,
                    'field'    => 'name',
                    'terms'    => $exclude_categories,
                    'operator' => 'NOT IN',
                ),
            );
        }

        $query = new WP_Query($args);
        $events = array();
        
        if ($query->have_posts()) {
            $seen_event_ids = array();
            while ($query->have_posts()) {
                $query->the_post();
                $event_id = get_the_ID();

                if (isset($seen_event_ids[$event_id])) {
                    continue;
                }
                $seen_event_ids[$event_id] = true;

                if (get_post_meta($event_id, '_EventHideFromUpcoming', true) === 'yes') {
                    continue;
                }
                
                $events[] = array(
                    'id'         => $event_id,
                    'title'      => get_the_title(),
                    'url'        => get_permalink(),
                    'start_date' => get_post_meta($event_id, '_EventStartDate', true),
                    'end_date'   => get_post_meta($event_id, '_EventEndDate', true),
                    'all_day'    => get_post_meta($event_id, '_EventAllDay', true) === 'yes',
                    'online_url' => $this->get_online_meeting_url($event_id),
                );
            }
            wp_reset_postdata();
        }

        if ($future_only && !empty($events)) {
            $cutoff = (new DateTime('now', wp_timezone()))->getTimestamp();
            $events = array_values(array_filter($events, function ($event) use ($cutoff) {
                return !empty($event['start_date']) && strtotime($event['start_date']) >= $cutoff;
            }));
        }
        
        // Sort by start date
        usort($events, function($a, $b) {
            return strtotime($a['start_date']) - strtotime($b['start_date']);
        });
        
        return $events;
    }

    /**
     * Find the first online meeting URL in TEC location fields or event body.
     *
     * Outlook/Graph sync can store the meeting URL in either the location
     * display name or the event body. TEC venues may also hold the link in the
     * venue title/body/meta when an online meeting was represented as a venue.
     *
     * @param int $event_id TEC event post ID.
     * @return string URL or empty string.
     */
    private function get_online_meeting_url($event_id) {
        if (class_exists('Azure_Event_CPT')) {
            return Azure_Event_CPT::extract_online_meeting_url($event_id);
        }
        return '';
    }
    
    /**
     * Render a list of events
     *
     * @param array $events  Array of event data.
     * @param array $options show_time, link_titles, show_join_meeting, empty_message.
     * @return string HTML output
     */
    private function render_events_list($events, $options) {
        $show_time         = !empty($options['show_time']);
        $link_titles       = !empty($options['link_titles']);
        $show_join_meeting = !empty($options['show_join_meeting']);
        $empty_message     = isset($options['empty_message']) ? $options['empty_message'] : __('No upcoming events.', 'azure-plugin');

        if (empty($events)) {
            return '<p class="upcoming-empty">' . esc_html($empty_message) . '</p>';
        }
        
        $output = '<ul class="upcoming-list">';
        
        foreach ($events as $event) {
            $start = strtotime($event['start_date']);
            
            // Format date: M/D (e.g., 12/4)
            $date_str = date_i18n('n/j', $start);
            
            // Format time if needed
            $time_str = '';
            if ($show_time && !$event['all_day']) {
                $time_str = ' ' . date_i18n('g:ia', $start);
            }
            
            $output .= '<li class="upcoming-event">';
            $output .= '<span class="upcoming-date">' . esc_html($date_str . $time_str) . '</span>';
            $output .= '<span class="upcoming-separator"> – </span>';
            
            if ($link_titles && !empty($event['url'])) {
                $output .= '<a href="' . esc_url($event['url']) . '" class="upcoming-title">' . esc_html($event['title']) . '</a>';
            } else {
                $output .= '<span class="upcoming-title">' . esc_html($event['title']) . '</span>';
            }

            if ($show_join_meeting && class_exists('Azure_Event_CPT')) {
                $join_btn = Azure_Event_CPT::render_join_meeting_button((int) $event['id'], 'inline');
                if ($join_btn !== '') {
                    $output .= '<div class="upcoming-join-meeting">' . $join_btn . '</div>';
                }
            } elseif (!empty($event['online_url'])) {
                $output .= '<div class="upcoming-online-meeting">';
                $output .= '<a href="' . esc_url($event['online_url']) . '" target="_blank" rel="noopener noreferrer">';
                $output .= esc_html__('Join online meeting', 'azure-plugin');
                $output .= '</a>';
                $output .= '</div>';
            }
            
            $output .= '</li>';
        }
        
        $output .= '</ul>';
        
        return $output;
    }
    
    /**
     * Get available event categories for admin reference.
     *
     * @return array Array of category names
     */
    public static function get_event_categories() {
        $categories = get_terms(array(
            'taxonomy'   => 'tribe_events_cat',
            'hide_empty' => false,
        ));
        
        if (is_wp_error($categories)) {
            return array();
        }
        
        return wp_list_pluck($categories, 'name');
    }
}

