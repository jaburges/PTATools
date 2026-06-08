<?php
/**
 * Calendar shortcode handler for Azure Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Calendar_Shortcode {
    
    private $graph_api;
    
    public function __construct() {
        // Note: we used to instantiate Azure_Calendar_GraphAPI here, but
        // the resource-efficiency refactor in azure-plugin.php only loads
        // the GraphAPI/Auth/Manager classes on backend (admin/cron) so the
        // class wasn't available in the FRONTEND constructor. Result: the
        // shortcode rendered "Calendar service is not available" on every
        // public page that embedded a calendar.
        //
        // We now lazy-load (and lazily require_once) GraphAPI/Auth on the
        // first shortcode render. Pages that don't use the shortcode pay
        // nothing; pages that do pay one require() and one constructor.
        $this->graph_api = null;

        // Register shortcodes
        add_shortcode('azure_calendar', array($this, 'calendar_shortcode'));
        add_shortcode('azure_calendar_events', array($this, 'events_list_shortcode'));
        add_shortcode('azure_calendar_event', array($this, 'single_event_shortcode'));
        
        // Enqueue scripts and styles for frontend
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
    }

    /**
     * Lazy-load the Graph API helper. Called from each shortcode entry
     * point that actually needs to talk to Microsoft Graph. Returns the
     * shared instance, or null if the dependency files / classes can't
     * be resolved (in which case callers should render a friendly
     * error to the front-end visitor).
     *
     * @return Azure_Calendar_GraphAPI|null
     */
    private function get_graph_api() {
        if ($this->graph_api instanceof Azure_Calendar_GraphAPI) {
            return $this->graph_api;
        }

        // Frontend requests don't pre-load the calendar admin/cron
        // classes — pull them in on demand here.
        if (!class_exists('Azure_Calendar_Auth')
            && defined('AZURE_PLUGIN_PATH')
            && file_exists(AZURE_PLUGIN_PATH . 'includes/class-calendar-auth.php')
        ) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-calendar-auth.php';
        }
        if (!class_exists('Azure_Calendar_GraphAPI')
            && defined('AZURE_PLUGIN_PATH')
            && file_exists(AZURE_PLUGIN_PATH . 'includes/class-calendar-graph-api.php')
        ) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-calendar-graph-api.php';
        }

        if (class_exists('Azure_Calendar_GraphAPI')) {
            $this->graph_api = new Azure_Calendar_GraphAPI();
            return $this->graph_api;
        }
        return null;
    }
    
    /**
     * Main calendar shortcode
     * Usage: [azure_calendar id="calendar_id" view="month" height="600px"]
     * 
     * For shared mailboxes, use:
     * [azure_calendar id="calendar_id" user_email="user@domain.com" mailbox_email="shared@domain.com"]
     * 
     * Legacy support: 'email' parameter is treated as mailbox_email, and user_email is fetched from settings
     */
    public function calendar_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => '',
            'email' => '', // Legacy: treated as mailbox_email for shared mailbox access
            'user_email' => '', // Authenticated user who has token
            'mailbox_email' => '', // Shared mailbox to access (optional)
            'view' => 'month', // month, week, day, list
            'height' => '600px',
            'width' => '100%',
            'theme' => 'default',
            'timezone' => '',
            'max_events' => 100,
            'start_date' => '',
            'end_date' => '',
            'show_toolbar' => true,
            'show_weekends' => true,
            'first_day' => 0, // 0 = Sunday, 1 = Monday
            'time_format' => '24h',
            'slot_min_time' => '08:00:00', // Start time for day/week views (e.g., '08:00:00')
            'slot_max_time' => '18:00:00', // End time for day/week views (e.g., '18:00:00')
            'slot_duration' => '00:30:00', // Time slot duration (e.g., '00:30:00' for 30 min slots)
            'source' => '',                // 'pta' | 'outlook' | '' (auto from flag)
        ), $atts);

        // Determine the effective data source up front so we can skip
        // the Outlook-specific `id` requirement when reading from
        // pta_event (where the calendar mapping is implicit — every
        // published pta_event renders).
        $effective_source = 'tec';
        if (class_exists('Azure_Event_CPT')) {
            $effective_source = Azure_Event_CPT::get_data_source();
        }
        $force_outlook = ($atts['source'] === 'outlook');
        $force_pta     = ($atts['source'] === 'pta');
        $reading_pta   = (($effective_source === 'pta' || $force_pta) && !$force_outlook);

        if (!$reading_pta && empty($atts['id'])) {
            return '<p class="azure-calendar-error">Calendar ID is required.</p>';
        }

        // Generate unique container ID
        $container_id = 'azure-calendar-' . uniqid();

        // Get calendar events.
        //
        // We prefetch a wide window (~3 months back, ~12 months
        // forward) so that when visitors click prev/next inside
        // FullCalendar to navigate to other months they actually
        // see events — the original "today + 30 days" window left
        // every other view empty (including past all-day events).
        $start_date = $atts['start_date'] ?: gmdate('Y-m-d\TH:i:s\Z', strtotime('-3 months'));
        $end_date   = $atts['end_date']   ?: gmdate('Y-m-d\TH:i:s\Z', strtotime('+12 months'));

        // Phase 5 source routing was determined at the top of the
        // method (see $reading_pta). Reading from pta_event here
        // surfaces both Outlook-synced events AND local-only school
        // entries (Spring Break, Memorial Day, etc.) that never made
        // it back to Outlook.
        if ($reading_pta) {
            $events = $this->fetch_pta_events($start_date, $end_date);
            $calendar_events = $this->format_events_for_calendar($events);

            $output  = '<div id="' . esc_attr($container_id) . '" class="azure-calendar-container" style="height: ' . esc_attr($atts['height']) . '; width: ' . esc_attr($atts['width']) . ';"></div>';
            $output .= $this->render_subscribe_bar($container_id);
            $output .= $this->get_calendar_script($container_id, $calendar_events, $atts);
            return $output;
        }

        $graph_api = $this->get_graph_api();
        if (!$graph_api) {
            return '<p class="azure-calendar-error">Calendar service is not available.</p>';
        }
        
        // Handle email parameters for shared mailbox access
        // If 'email' is provided (legacy), treat it as the mailbox_email
        // and get user_email from calendar embed settings
        $mailbox_email = !empty($atts['mailbox_email']) ? sanitize_email($atts['mailbox_email']) : null;
        $user_email = !empty($atts['user_email']) ? sanitize_email($atts['user_email']) : null;
        
        // Legacy support: if 'email' is provided but not 'mailbox_email' or 'user_email'
        if (!empty($atts['email']) && empty($mailbox_email)) {
            $mailbox_email = sanitize_email($atts['email']);
        }
        
        // If we have a mailbox but no user_email, get the authenticated user from settings
        if ($mailbox_email && !$user_email) {
            $settings = Azure_Settings::get_all_settings();
            // Use the calendar-embed user email if set (legacy tec_calendar_user_email
            // is read as fall-back during the TEC retirement and will be removed
            // when the one-shot migration deletes the option).
            $user_email = $settings['calendar_embed_user_email'] ?? $settings['tec_calendar_user_email'] ?? '';
            
            if (empty($user_email)) {
                Azure_Logger::warning("Calendar Shortcode: Mailbox email provided but no authenticated user configured. Please set up Calendar Embed authentication.", 'Calendar');
                return '<p class="azure-calendar-error">Calendar authentication not configured. Please contact the site administrator.</p>';
            }
        }
        
        // If no emails specified at all, try to get both from settings
        if (!$user_email && !$mailbox_email) {
            $settings = Azure_Settings::get_all_settings();
            $user_email = $settings['calendar_embed_user_email'] ?? '';
            $mailbox_email = $settings['calendar_embed_mailbox_email'] ?? '';
            
            // If still no user_email, check if they're using the same email for both
            if (empty($user_email)) {
                Azure_Logger::warning("Calendar Shortcode: No user_email configured. Please set up Calendar Embed authentication.", 'Calendar');
            }
        }
        
        // If no mailbox specified, user_email is both the token holder and calendar owner
        if (!$mailbox_email && $user_email) {
            // User is accessing their own calendar
            $mailbox_email = null;
        }
        
        Azure_Logger::debug("Calendar Shortcode: Fetching events for calendar {$atts['id']}, user_email: " . ($user_email ?: 'default') . ", mailbox_email: " . ($mailbox_email ?: 'none'), 'Calendar');
        
        $events = $graph_api->get_calendar_events(
            $atts['id'],
            $start_date,
            $end_date,
            intval($atts['max_events']),
            false, // force_refresh
            $user_email,
            $mailbox_email
        );
        
        if ($events === false) {
            return '<p class="azure-calendar-error">Failed to load calendar events.</p>';
        }
        
        // Convert events to FullCalendar format
        $calendar_events = $this->format_events_for_calendar($events);
        
        // Build calendar HTML and JavaScript
        $output = '<div id="' . esc_attr($container_id) . '" class="azure-calendar-container" style="height: ' . esc_attr($atts['height']) . '; width: ' . esc_attr($atts['width']) . ';"></div>';
        
        $output .= $this->get_calendar_script($container_id, $calendar_events, $atts);
        
        return $output;
    }
    
    /**
     * Events list shortcode
     * Usage: [azure_calendar_events id="calendar_id" limit="10" format="list"]
     *
     * For shared mailboxes:
     * [azure_calendar_events id="calendar_id" user_email="user@domain.com" mailbox_email="shared@domain.com"]
     *
     * As of v3.91.11, when `pta_calendar_data_source = pta` (the
     * post-cutover state on lwptsa/wilder), this shortcode reads from
     * the local `pta_event` CPT instead of hitting Microsoft Graph live.
     * `id` is then optional — when omitted, all pta_event posts are
     * surfaced. `id` can still be passed to scope to events that synced
     * from a specific Outlook calendar (matched on `_outlook_calendar_id`).
     */
    public function events_list_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => '',
            'email' => '', // Legacy: treated as mailbox_email
            'user_email' => '', // Authenticated user who has token
            'mailbox_email' => '', // Shared mailbox to access
            'limit' => 10,
            'format' => 'list', // list, grid, compact
            'show_dates' => true,
            'show_times' => true,
            'show_location' => true,
            'show_description' => false,
            'show_image' => true,         // Featured image thumb (pta_event only)
            'show_join_meeting' => true,  // "Join meeting" CTA when URL detected
            'date_format' => 'M j, Y',
            'time_format' => 'g:i A',
            'upcoming_only' => true,
            'class' => 'azure-events-list',
            'source' => '', // 'pta' | 'outlook' | '' (auto from flag)
        ), $atts);

        $data_source = class_exists('Azure_Event_CPT')
            ? Azure_Event_CPT::get_data_source()
            : 'tribe';
        $force_outlook = ($atts['source'] === 'outlook');
        $force_pta     = ($atts['source'] === 'pta');

        // Post-cutover read path (no Graph round-trip, no `id` required).
        if (($data_source === 'pta' || $force_pta) && !$force_outlook) {
            $events = $this->fetch_pta_events_for_list($atts);
            if (empty($events)) {
                return '<p class="azure-calendar-no-events">No upcoming events found.</p>';
            }
            return $this->render_pta_events_list($events, $atts);
        }

        if (empty($atts['id'])) {
            return '<p class="azure-calendar-error">Calendar ID is required.</p>';
        }

        $graph_api = $this->get_graph_api();
        if (!$graph_api) {
            return '<p class="azure-calendar-error">Calendar service is not available.</p>';
        }

        // Get events
        $start_date = $atts['upcoming_only'] ? date('Y-m-d\TH:i:s\Z') : date('Y-m-d\TH:i:s\Z', strtotime('-30 days'));
        $end_date = date('Y-m-d\TH:i:s\Z', strtotime('+90 days'));

        // Handle email parameters for shared mailbox access
        $mailbox_email = !empty($atts['mailbox_email']) ? sanitize_email($atts['mailbox_email']) : null;
        $user_email = !empty($atts['user_email']) ? sanitize_email($atts['user_email']) : null;

        // Legacy support: if 'email' is provided but not 'mailbox_email'
        if (!empty($atts['email']) && empty($mailbox_email)) {
            $mailbox_email = sanitize_email($atts['email']);
        }

        // If we have a mailbox but no user_email, get from settings
        if ($mailbox_email && !$user_email) {
            $settings = Azure_Settings::get_all_settings();
            $user_email = $settings['calendar_embed_user_email'] ?? $settings['tec_calendar_user_email'] ?? '';

            if (empty($user_email)) {
                return '<p class="azure-calendar-error">Calendar authentication not configured.</p>';
            }
        }

        $events = $graph_api->get_calendar_events(
            $atts['id'],
            $start_date,
            $end_date,
            intval($atts['limit']) * 2, // Get more to account for filtering
            false, // force_refresh
            $user_email,
            $mailbox_email
        );

        if ($events === false) {
            return '<p class="azure-calendar-error">Failed to load events.</p>';
        }

        // Filter and limit events
        if ($atts['upcoming_only']) {
            $now = time();
            $events = array_filter($events, function($event) use ($now) {
                return strtotime($event['start']) >= $now;
            });
        }

        $events = array_slice($events, 0, intval($atts['limit']));

        if (empty($events)) {
            return '<p class="azure-calendar-no-events">No upcoming events found.</p>';
        }

        return $this->render_events_list($events, $atts);
    }

    /**
     * Fetch upcoming events from pta_event for the events-list shortcode.
     *
     * Honours the same `limit`, `upcoming_only`, and optional `id`
     * (Outlook calendar ID, matched against `_outlook_calendar_id`)
     * attributes the Graph-API code path uses, so swapping data
     * sources via the flag is transparent.
     *
     * @param array $atts Sanitised shortcode attributes.
     * @return WP_Post[] Posts of type pta_event, ordered by start date.
     */
    private function fetch_pta_events_for_list($atts) {
        if (!post_type_exists('pta_event')) {
            return array();
        }

        $window_start = $atts['upcoming_only']
            ? current_time('Y-m-d 00:00:00')
            : date('Y-m-d 00:00:00', strtotime('-30 days', current_time('timestamp')));
        $window_end = date('Y-m-d 23:59:59', strtotime('+90 days', current_time('timestamp')));

        $meta_query = array(
            'relation' => 'AND',
            array(
                'key'     => '_EventStartDate',
                'value'   => $window_start,
                'compare' => '>=',
                'type'    => 'DATETIME',
            ),
            array(
                'key'     => '_EventStartDate',
                'value'   => $window_end,
                'compare' => '<=',
                'type'    => 'DATETIME',
            ),
        );

        // Scope to one Outlook calendar mapping if the caller passed `id`.
        // Otherwise show every pta_event in the window (the common case
        // for a "site-wide upcoming events" widget).
        if (!empty($atts['id'])) {
            $meta_query[] = array(
                'key'     => '_outlook_calendar_id',
                'value'   => $atts['id'],
                'compare' => '=',
            );
        }

        $args = array(
            'post_type'      => 'pta_event',
            'post_status'    => 'publish',
            'posts_per_page' => max(1, (int) $atts['limit']),
            'meta_key'       => '_EventStartDate',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => $meta_query,
            'no_found_rows'  => true,
        );

        $q = new WP_Query($args);
        return is_array($q->posts) ? $q->posts : array();
    }

    /**
     * Render the events-list shortcode output from pta_event posts.
     *
     * Card-style layout: optional featured-image thumb on the left,
     * date stamp, title (linked to single event), date/time row,
     * optional venue line, and a "Join meeting" button when an online
     * meeting URL was detected in the post body/venue.
     *
     * @param WP_Post[] $posts Pre-filtered pta_event posts.
     * @param array     $atts  Sanitised shortcode attributes.
     * @return string HTML.
     */
    private function render_pta_events_list($posts, $atts) {
        $show_image = filter_var($atts['show_image'],        FILTER_VALIDATE_BOOLEAN);
        $show_join  = filter_var($atts['show_join_meeting'], FILTER_VALIDATE_BOOLEAN);
        $show_loc   = filter_var($atts['show_location'],     FILTER_VALIDATE_BOOLEAN);
        $show_desc  = filter_var($atts['show_description'],  FILTER_VALIDATE_BOOLEAN);
        $date_fmt   = (string) $atts['date_format'];
        $time_fmt   = (string) $atts['time_format'];

        $out  = '<div class="' . esc_attr($atts['class']) . ' pta-events-cards format-' . esc_attr($atts['format']) . '">';

        foreach ($posts as $post) {
            $eid     = $post->ID;
            $start   = (string) get_post_meta($eid, '_EventStartDate', true);
            $end     = (string) get_post_meta($eid, '_EventEndDate',   true);
            $all_day = strtolower((string) get_post_meta($eid, '_EventAllDay', true)) === 'yes';
            $sts     = $start ? strtotime($start) : 0;
            $ets     = $end   ? strtotime($end)   : $sts;
            $url     = get_permalink($eid);
            $thumb   = ($show_image && has_post_thumbnail($eid))
                ? get_the_post_thumbnail_url($eid, 'medium')
                : '';
            $join    = $show_join
                ? Azure_Event_CPT::render_join_meeting_button($eid, 'inline')
                : '';
            $venue   = '';
            if ($show_loc) {
                $vid = (int) get_post_meta($eid, '_EventVenueID', true);
                if ($vid > 0) {
                    $vb = Azure_Event_CPT::get_venue_block($vid);
                    if ($vb && !empty($vb['name'])) { $venue = $vb['name']; }
                }
                if ($venue === '') {
                    $venue = (string) get_post_meta($eid, '_EventVenue', true);
                }
            }

            $when = '';
            if ($sts) {
                $when = date_i18n($date_fmt, $sts);
                if (!$all_day && $time_fmt) {
                    $when .= ' @ ' . date_i18n($time_fmt, $sts);
                    if ($ets && $ets !== $sts) {
                        $when .= ' - ' . date_i18n($time_fmt, $ets);
                    }
                } elseif ($all_day) {
                    $when .= ' (all day)';
                }
            }

            $out .= '<div class="pta-events-card' . ($thumb ? ' has-thumb' : '') . ($join ? ' has-join' : '') . '">';
            $out .= '  <a class="pta-events-card-link" href="' . esc_url($url) . '" aria-label="' . esc_attr($post->post_title) . '">';
            if ($thumb) {
                $out .= '    <div class="pta-events-card-thumb" style="background-image:url(\'' . esc_url($thumb) . '\');"></div>';
            }
            $out .= '    <div class="pta-events-card-body">';
            $out .= '      <h4 class="pta-events-card-title">' . esc_html($post->post_title) . '</h4>';
            if ($when) {
                $out .= '      <p class="pta-events-card-when">' . esc_html($when) . '</p>';
            }
            if ($venue) {
                $out .= '      <p class="pta-events-card-venue">' . esc_html($venue) . '</p>';
            }
            if ($show_desc) {
                $excerpt = wp_trim_words(wp_strip_all_tags($post->post_content), 24);
                if ($excerpt !== '') {
                    $out .= '      <p class="pta-events-card-desc">' . esc_html($excerpt) . '</p>';
                }
            }
            $out .= '    </div>';
            $out .= '  </a>';
            if ($join) {
                $out .= '  <div class="pta-events-card-join">' . $join . '</div>';
            }
            $out .= '</div>';
        }

        $out .= '</div>';
        return $out;
    }
    
    /**
     * Single event shortcode
     * Usage: [azure_calendar_event id="calendar_id" event_id="event_id"]
     */
    public function single_event_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => '',
            'event_id' => '',
            'show_attendees' => false,
            'show_description' => true,
            'class' => 'azure-single-event'
        ), $atts);
        
        if (empty($atts['id']) || empty($atts['event_id'])) {
            return '<p class="azure-calendar-error">Calendar ID and Event ID are required.</p>';
        }
        
        // This would require implementing a get_single_event method
        return '<p class="azure-calendar-error">Single event display not yet implemented.</p>';
    }
    
    /**
     * Fetch events from pta_event for embed rendering.
     *
     * Used when the migration flag `pta_calendar_data_source = pta`
     * is active — at that point pta_event posts are the canonical
     * store and they include both Outlook-mirrored and local-only
     * events. This means the embed picks up things like Spring
     * Break, Memorial Day, SBA Testing etc. that were authored
     * directly in the WP admin and never round-tripped to Outlook.
     *
     * Both date inputs are ISO-8601 (used by the Outlook code
     * path); we slice them to YYYY-MM-DD for the meta_query
     * range comparison against `_EventStartDate`.
     *
     * @param string $start_date_iso ISO-8601 (e.g. 2026-02-08T00:00:00Z)
     * @param string $end_date_iso   ISO-8601
     * @return array of events shaped like Azure_Calendar_GraphAPI::process_events()
     */
    private function fetch_pta_events($start_date_iso, $end_date_iso) {
        if (!post_type_exists('pta_event')) {
            return array();
        }

        $start_ymd = substr($start_date_iso, 0, 10) . ' 00:00:00';
        $end_ymd   = substr($end_date_iso, 0, 10)   . ' 23:59:59';

        $query = new WP_Query(array(
            'post_type'              => 'pta_event',
            'post_status'            => array('publish', 'future'),
            'posts_per_page'         => 500,
            'no_found_rows'          => true,
            'update_post_term_cache' => true,
            'update_post_meta_cache' => true,
            'meta_query'             => array(
                array(
                    'key'     => '_EventStartDate',
                    'value'   => array($start_ymd, $end_ymd),
                    'compare' => 'BETWEEN',
                    'type'    => 'DATETIME',
                ),
            ),
            'orderby'  => 'meta_value',
            'meta_key' => '_EventStartDate',
            'order'    => 'ASC',
        ));

        $out = array();
        if (!empty($query->posts)) {
            foreach ($query->posts as $post) {
                $start = (string) get_post_meta($post->ID, '_EventStartDate', true);
                $end   = (string) get_post_meta($post->ID, '_EventEndDate',   true);
                $all   = strtolower((string) get_post_meta($post->ID, '_EventAllDay', true));
                $is_all_day = in_array($all, array('yes', '1', 'true'), true);

                if ($is_all_day) {
                    // pta_event mirrors store all-day boundaries in two
                    // formats:
                    //   - Outlook style:  end = next-midnight  (exclusive)
                    //   - TEC style:      end = 23:59:59       (inclusive)
                    // FullCalendar wants exclusive ends, so when the
                    // stored end has a non-zero time we bump it to
                    // the next day.
                    $start_emit = substr($start, 0, 10);
                    $end_date   = substr($end,   0, 10);
                    $end_time   = substr($end,  11);
                    if ($end === '' || $end_date === '') {
                        $end_emit = $start_emit;
                    } elseif ($end_time !== '00:00:00' && $end_time !== '') {
                        $end_emit = date('Y-m-d', strtotime($end_date . ' +1 day'));
                    } else {
                        $end_emit = $end_date;
                    }
                } else {
                    $start_emit = $this->convert_local_to_iso($start);
                    $end_emit   = $end ? $this->convert_local_to_iso($end) : $start_emit;
                }

                $venue = '';
                $venue_id = (int) get_post_meta($post->ID, '_EventVenueID', true);
                if ($venue_id && get_post($venue_id)) {
                    $venue = get_the_title($venue_id);
                }
                if ($venue === '') {
                    $venue = (string) get_post_meta($post->ID, '_EventVenue', true);
                }

                $cats = wp_get_post_terms($post->ID, 'pta_event_category', array('fields' => 'names'));
                if (is_wp_error($cats)) { $cats = array(); }

                // Online-meeting URL surfaced as extendedProps so the
                // FullCalendar grid view can show a tiny indicator
                // (and so visitors can grab it from the popover/title
                // without a full page navigation).
                $join_url = class_exists('Azure_Event_CPT')
                    ? Azure_Event_CPT::extract_online_meeting_url($post->ID)
                    : '';

                $out[] = array(
                    'id'          => 'pta-' . $post->ID,
                    'title'       => $post->post_title,
                    'start'       => $start_emit,
                    'end'         => $end_emit,
                    'allDay'      => $is_all_day,
                    'location'    => $venue,
                    'description' => '',
                    'categories'  => $cats,
                    'showAs'      => 'busy',
                    'sensitivity' => 'normal',
                    // Custom field used by format_events_for_calendar
                    // to attach a click-through URL.
                    'url'         => get_permalink($post->ID),
                    'joinUrl'     => $join_url,
                );
            }
        }
        return $out;
    }

    /**
     * Render the Subscribe / Download / Copy URL bar that sits
     * directly under a pta_event-driven calendar embed. We only
     * surface this when the embed is reading from pta_event (the
     * Outlook-live source has no public feed URL).
     */
    private function render_subscribe_bar($container_id) {
        if (!class_exists('Azure_Event_CPT')) { return ''; }
        $urls = Azure_Event_CPT::get_feed_urls();
        if (empty($urls)) { return ''; }

        $ics    = esc_url($urls['ics']);
        $webcal = esc_attr($urls['webcal']);
        $copy   = esc_attr($urls['ics']);
        $popid  = esc_attr($container_id . '-subscribe');

        return ''
            . '<div class="pta-cal-subscribe" data-target="' . esc_attr($container_id) . '">'
            . '  <div class="pta-cal-subscribe-inner">'
            . '    <button type="button" class="pta-cal-sub-toggle" aria-expanded="false" aria-controls="' . $popid . '">'
            . '      <span class="pta-cal-sub-icon" aria-hidden="true">+</span>'
            . '      <span>Subscribe to Calendar</span>'
            . '    </button>'
            . '    <div id="' . $popid . '" class="pta-cal-sub-popover" hidden>'
            . '      <p class="pta-cal-sub-lead">Stay in sync — your calendar app will refresh automatically as we add and update events.</p>'
            . '      <div class="pta-cal-sub-actions">'
            . '        <a class="pta-cal-sub-btn primary" href="' . $webcal . '">'
            . '          <span>Subscribe (live)</span>'
            . '          <small>Apple Calendar · Outlook · Google</small>'
            . '        </a>'
            . '        <a class="pta-cal-sub-btn" href="' . $ics . '" download>'
            . '          <span>Download .ics</span>'
            . '          <small>One-time snapshot</small>'
            . '        </a>'
            . '        <button type="button" class="pta-cal-sub-btn copy" data-copy="' . $copy . '">'
            . '          <span>Copy feed URL</span>'
            . '          <small>Paste into any calendar app</small>'
            . '        </button>'
            . '      </div>'
            . '    </div>'
            . '  </div>'
            . '  <script>'
            . '  (function(){'
            . '    var wrap=document.querySelector(".pta-cal-subscribe[data-target=\"' . esc_js($container_id) . '\"]");'
            . '    if(!wrap)return;'
            . '    var btn=wrap.querySelector(".pta-cal-sub-toggle");'
            . '    var pop=wrap.querySelector(".pta-cal-sub-popover");'
            . '    btn.addEventListener("click",function(){'
            . '      var open=!pop.hasAttribute("hidden");'
            . '      if(open){pop.setAttribute("hidden","");btn.setAttribute("aria-expanded","false");}'
            . '      else{pop.removeAttribute("hidden");btn.setAttribute("aria-expanded","true");}'
            . '    });'
            . '    document.addEventListener("click",function(e){'
            . '      if(!wrap.contains(e.target)){pop.setAttribute("hidden","");btn.setAttribute("aria-expanded","false");}'
            . '    });'
            . '    var copyBtn=wrap.querySelector(".pta-cal-sub-btn.copy");'
            . '    if(copyBtn){copyBtn.addEventListener("click",function(){'
            . '      var url=copyBtn.getAttribute("data-copy");'
            . '      if(navigator.clipboard&&navigator.clipboard.writeText){'
            . '        navigator.clipboard.writeText(url).then(function(){copyBtn.classList.add("copied");var s=copyBtn.querySelector("span");var orig=s.textContent;s.textContent="Copied!";setTimeout(function(){s.textContent=orig;copyBtn.classList.remove("copied");},1800);});'
            . '      } else {'
            . '        var ta=document.createElement("textarea");ta.value=url;document.body.appendChild(ta);ta.select();try{document.execCommand("copy");}catch(e){}document.body.removeChild(ta);'
            . '      }'
            . '    });}'
            . '  })();'
            . '  </script>'
            . '</div>';
    }

    /**
     * Convert a stored "YYYY-MM-DD HH:MM:SS" (in WP local time)
     * into an ISO-8601 string with offset that FullCalendar can
     * parse cleanly. Falls back to the raw string on failure.
     */
    private function convert_local_to_iso($local) {
        if ($local === '') { return ''; }
        try {
            $tz = wp_timezone();
            $dt = new DateTime($local, $tz);
            return $dt->format('c');
        } catch (Exception $e) {
            return $local;
        }
    }

    /**
     * Format events for FullCalendar
     *
     * For all-day events, the Graph API returns ISO strings with
     * a timezone offset (e.g. "2026-02-12T00:00:00-08:00"). When
     * FullCalendar parses those with allDay:true the offset can
     * shift the displayed date by a day depending on the visitor's
     * locale. We normalise to date-only ("YYYY-MM-DD"), which is
     * the canonical format for all-day events in FullCalendar v6.
     *
     * Outlook's all-day end date is exclusive (Feb 17 means the
     * event ends after Feb 16) and FullCalendar's end is also
     * exclusive, so passing the date verbatim yields the correct
     * span across day cells.
     */
    private function format_events_for_calendar($events) {
        $calendar_events = array();

        foreach ($events as $event) {
            $is_all_day = !empty($event['allDay']);
            $start      = $event['start'] ?? '';
            $end        = $event['end']   ?? '';

            if ($is_all_day) {
                if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $start, $m)) {
                    $start = $m[1];
                }
                if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $end, $m)) {
                    $end = $m[1];
                }
            }

            $color = $this->get_event_color($event);
            $class_names = $is_all_day ? array('pta-cal-allday') : array('pta-cal-timed');
            if (!empty($event['joinUrl'])) {
                // Picked up by frontend CSS to add a small meeting
                // indicator on the event chip (camera icon via
                // ::before in calendar-frontend.css).
                $class_names[] = 'pta-cal-has-meeting';
            }
            $row = array(
                'id'              => $event['id'],
                'title'           => $event['title'],
                'start'           => $start,
                'end'             => $end,
                'allDay'          => $is_all_day,
                'location'        => $event['location'] ?? '',
                'description'     => $event['description'] ?? '',
                'backgroundColor' => $color,
                'borderColor'     => $color,
                'textColor'       => '#ffffff',
                // Hint to FullCalendar to render this as a
                // rounded pill bar spanning multiple days.
                'classNames'      => $class_names,
                'display'         => $is_all_day ? 'block' : 'auto',
                'extendedProps'   => array(
                    'joinUrl' => $event['joinUrl'] ?? '',
                ),
            );
            if (!empty($event['url'])) {
                $row['url'] = $event['url'];
            }
            $calendar_events[] = $row;
        }

        return $calendar_events;
    }
    
    /**
     * Get event color based on categories or default
     */
    private function get_event_color($event) {
        $default_colors = array(
            '#0073aa', '#46b450', '#dc3232', '#ffb900', '#826eb4',
            '#f56e28', '#00a0d2', '#007cba', '#d54e21', '#78c8db'
        );
        
        if (!empty($event['categories'])) {
            // Use category to determine color
            $category = $event['categories'][0];
            $color_index = crc32($category) % count($default_colors);
            return $default_colors[$color_index];
        }
        
        // Default color
        return $default_colors[0];
    }
    
    /**
     * Generate calendar JavaScript
     */
    private function get_calendar_script($container_id, $events, $atts) {
        $events_json = json_encode($events);
        
        // Determine timezone: shortcode attr > per-calendar setting > plugin default > WordPress default
        $timezone = '';
        if (!empty($atts['timezone'])) {
            $timezone = $atts['timezone'];
        } else {
            $settings = Azure_Settings::get_all_settings();
            // Check for per-calendar timezone setting
            if (!empty($atts['id']) && !empty($settings['calendar_timezone_' . $atts['id']])) {
                $timezone = $settings['calendar_timezone_' . $atts['id']];
            } elseif (!empty($settings['calendar_default_timezone'])) {
                // Fall back to plugin default timezone
                $timezone = $settings['calendar_default_timezone'];
            } else {
                // Fall back to WordPress timezone
                $timezone = wp_timezone_string();
            }
        }
        
        // Map view names to FullCalendar v6 view names
        $view_map = array(
            'month' => 'dayGridMonth',
            'week' => 'timeGridWeek',
            'day' => 'timeGridDay',
            'list' => 'listWeek'
        );
        $initial_view = isset($view_map[$atts['view']]) ? $view_map[$atts['view']] : 'dayGridMonth';
        
        // Time range settings for day/week views
        $slot_min_time = esc_js($atts['slot_min_time']);
        $slot_max_time = esc_js($atts['slot_max_time']);
        $slot_duration = esc_js($atts['slot_duration']);
        
        $script = "
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('{$container_id}');
            
            if (typeof FullCalendar !== 'undefined') {
                var calendar = new FullCalendar.Calendar(calendarEl, {
                    initialView: '{$initial_view}',
                    timeZone: '{$timezone}',
                    events: {$events_json},
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
                    },
                    weekends: " . ($atts['show_weekends'] ? 'true' : 'false') . ",
                    firstDay: {$atts['first_day']},
                    slotMinTime: '{$slot_min_time}',
                    slotMaxTime: '{$slot_max_time}',
                    slotDuration: '{$slot_duration}',
                    scrollTime: '{$slot_min_time}',
                    eventClick: function(info) {
                        // If the event source supplied a URL (pta_event
                        // posts in particular do — single-event template),
                        // navigate to it rather than firing a tiny alert.
                        if (info.event.url) {
                            info.jsEvent.preventDefault();
                            window.location.href = info.event.url;
                            return;
                        }
                        var when;
                        if (info.event.allDay) {
                            when = info.event.start.toLocaleDateString();
                        } else {
                            when = info.event.start.toLocaleString();
                        }
                        alert(info.event.title + '\\n' + when);
                    },
                    eventDidMount: function(info) {
                        // Titles are clamped to one line with an ellipsis in
                        // the month grid (see calendar-frontend.css), so the
                        // full text might be hidden. Expose it as a native
                        // tooltip on hover and to assistive tech. For timed
                        // events, append the start time for quick context.
                        var full = info.event.title || '';
                        if (info.event.start && !info.event.allDay) {
                            try {
                                full += ' \\u2014 ' + info.event.start.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
                            } catch (e) {}
                        }
                        if (full) {
                            info.el.setAttribute('title', full);
                            info.el.setAttribute('aria-label', full);
                        }
                    },
                    height: '{$atts['height']}',
                    themeSystem: 'standard'
                });
                
                calendar.render();
            } else {
                calendarEl.innerHTML = '<p class=\"azure-calendar-error\">FullCalendar library not loaded. Please make sure JavaScript is enabled.</p>';
            }
        });
        </script>";
        
        return $script;
    }
    
    /**
     * Render events list HTML
     */
    private function render_events_list($events, $atts) {
        $output = '<div class="' . esc_attr($atts['class']) . ' format-' . esc_attr($atts['format']) . '">';
        
        foreach ($events as $event) {
            $start_time = strtotime($event['start']);
            $end_time = strtotime($event['end']);
            
            $output .= '<div class="azure-event-item">';
            
            // Event title
            $output .= '<h3 class="event-title">' . esc_html($event['title']) . '</h3>';
            
            // Event date/time
            if ($atts['show_dates'] || $atts['show_times']) {
                $output .= '<div class="event-datetime">';
                
                if ($atts['show_dates']) {
                    $output .= '<span class="event-date">' . date($atts['date_format'], $start_time) . '</span>';
                }
                
                if ($atts['show_times'] && !$event['allDay']) {
                    $output .= ' <span class="event-time">' . date($atts['time_format'], $start_time);
                    if ($start_time !== $end_time) {
                        $output .= ' - ' . date($atts['time_format'], $end_time);
                    }
                    $output .= '</span>';
                }
                
                $output .= '</div>';
            }
            
            // Event location
            if ($atts['show_location'] && !empty($event['location'])) {
                $output .= '<div class="event-location"><strong>Location:</strong> ' . esc_html($event['location']) . '</div>';
            }
            
            // Event description
            if ($atts['show_description'] && !empty($event['description'])) {
                $description = wp_trim_words($event['description'], 30);
                $output .= '<div class="event-description">' . esc_html($description) . '</div>';
            }
            
            $output .= '</div>';
        }
        
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Enqueue frontend assets only when a calendar shortcode is present.
     */
    public function enqueue_frontend_assets() {
        if (is_admin()) {
            return;
        }

        global $post;
        if (!is_a($post, 'WP_Post')) {
            return;
        }

        $shortcodes = array('azure_calendar', 'azure_calendar_events', 'azure_calendar_event');
        $found = false;
        foreach ($shortcodes as $sc) {
            if (has_shortcode($post->post_content, $sc)) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return;
        }

        wp_enqueue_script(
            'fullcalendar',
            'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js',
            array(),
            '6.1.8',
            true
        );
        
        wp_enqueue_style(
            'azure-calendar-frontend',
            AZURE_PLUGIN_URL . 'css/calendar-frontend.css',
            array(),
            AZURE_PLUGIN_VERSION
        );

        // Shared event styles (Join-meeting button + pta_event card
        // grid). Loaded whenever any azure_calendar* shortcode renders
        // so the [azure_calendar_events] cards and Join buttons are
        // styled even outside the single-event/archive context.
        wp_enqueue_style(
            'pta-event-shared',
            AZURE_PLUGIN_URL . 'css/pta-event-shared.css',
            array(),
            AZURE_PLUGIN_VERSION
        );
    }
}
