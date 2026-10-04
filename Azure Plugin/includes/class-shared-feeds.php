<?php
/**
 * Shared feeds: lets one PTA Tools site publish its calendar and stories,
 * and another show them.
 *
 * Publishing (always on, read-only, published content only):
 *   GET /wp-json/pta-tools/v1/feed/events   ?category=&exclude_category=&calendar=&from=&days=&limit=
 *   GET /wp-json/pta-tools/v1/feed/stories  ?category=&exclude_category=&limit=
 *
 * Subscribing:
 *   [pta-shared-events  source="https://council.example.org" category="" calendar="" days="60" limit="10" layout="list|cards"]
 *   [pta-shared-stories source="https://council.example.org" category="" limit="6" layout="cards|list"]
 *
 * A source running an older PTA Tools has no /feed routes; events then
 * come from its .ics feed and stories from the core /wp/v2/posts API.
 * Items always link back to the source site rather than being copied.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Shared_Feeds {

    const REST_NAMESPACE = 'pta-tools/v1';
    const CACHE_TTL      = 1800;
    const STALE_TTL      = 604800;
    const PROVIDER_TTL   = 600;
    const HTTP_TIMEOUT   = 8;

    /** @var self|null */
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_shortcode('pta-shared-events', array($this, 'render_events_shortcode'));
        add_shortcode('pta-shared-stories', array($this, 'render_stories_shortcode'));
    }

    // ------------------------------------------------------------------
    // Publishing
    // ------------------------------------------------------------------

    public function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/feed/events', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_events'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, '/feed/stories', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_stories'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * @param WP_REST_Request $request
     */
    public function rest_events($request) {
        if (!class_exists('Azure_Event_CPT')) {
            return new WP_Error('pta_feed_unavailable', 'Events are not enabled on this site.', array('status' => 404));
        }
        $tz    = wp_timezone();
        $from  = self::parse_ymd((string) $request->get_param('from'), $tz) ?: new DateTime('today', $tz);
        $days  = self::clamp_int($request->get_param('days'), 90, 1, 400);
        $limit = self::clamp_int($request->get_param('limit'), 50, 1, 200);
        $include  = self::csv_slugs($request->get_param('category'));
        $exclude  = self::csv_slugs($request->get_param('exclude_category'));
        $calendar = trim((string) $request->get_param('calendar'));

        $key = 'pta_sf_ev_' . md5(wp_json_encode(array($from->format('Y-m-d'), $days, $limit, $include, $exclude, $calendar)));
        $cached = get_transient($key);
        if (is_array($cached)) {
            return rest_ensure_response($cached);
        }

        $to = clone $from;
        $to->modify('+' . $days . ' days');

        $meta_query = array(
            'relation' => 'AND',
            array(
                'relation' => 'OR',
                array(
                    'key'     => '_EventStartDate',
                    'value'   => $from->format('Y-m-d 00:00:00'),
                    'compare' => '>=',
                    'type'    => 'DATETIME',
                ),
                array(
                    'key'     => '_EventEndDate',
                    'value'   => $from->format('Y-m-d 00:00:00'),
                    'compare' => '>=',
                    'type'    => 'DATETIME',
                ),
            ),
            array(
                'key'     => '_EventStartDate',
                'value'   => $to->format('Y-m-d 23:59:59'),
                'compare' => '<=',
                'type'    => 'DATETIME',
            ),
        );
        foreach (Azure_Event_CPT::calendar_scope_clauses($calendar) as $clause) {
            $meta_query[] = $clause;
        }

        $taxonomy  = Azure_Event_CPT::query_taxonomy();
        $tax_query = array();
        if ($include) {
            $tax_query[] = array('taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => $include);
        }
        if ($exclude) {
            $tax_query[] = array('taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => $exclude, 'operator' => 'NOT IN');
        }

        $args = array(
            'post_type'      => Azure_Event_CPT::query_post_type(),
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'no_found_rows'  => true,
            'meta_query'     => $meta_query,
            'meta_key'       => '_EventStartDate',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
        );
        if ($tax_query) {
            $args['tax_query'] = array_merge(array('relation' => 'AND'), $tax_query);
        }

        $events = array();
        foreach ((new WP_Query($args))->posts as $post) {
            $events[] = $this->event_item($post, $taxonomy, $tz);
        }

        $body = array(
            'source'    => self::source_info(),
            'generated' => gmdate('c'),
            'timezone'  => wp_timezone_string(),
            'events'    => $events,
        );
        set_transient($key, $body, self::PROVIDER_TTL);
        return rest_ensure_response($body);
    }

    private function event_item($post, $taxonomy, DateTimeZone $tz) {
        $start  = (string) get_post_meta($post->ID, '_EventStartDate', true);
        $end    = (string) get_post_meta($post->ID, '_EventEndDate', true);
        $allday = in_array(strtolower((string) get_post_meta($post->ID, '_EventAllDay', true)), array('yes', '1', 'true'), true);

        $venue = (string) get_post_meta($post->ID, '_EventVenue', true);
        if ($venue === '') {
            $vid = (int) get_post_meta($post->ID, '_EventVenueID', true);
            if ($vid > 0 && method_exists('Azure_Event_CPT', 'get_venue_block')) {
                $vb = Azure_Event_CPT::get_venue_block($vid);
                if ($vb) {
                    $venue = (string) $vb['name'];
                }
            }
        }

        $cats = wp_get_post_terms($post->ID, $taxonomy, array('fields' => 'names'));

        return array(
            'id'         => (int) $post->ID,
            'title'      => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
            'url'        => get_permalink($post),
            'start'      => self::local_to_iso($start, $tz, $allday),
            'end'        => self::local_to_iso($end, $tz, $allday),
            'all_day'    => $allday,
            'venue'      => $venue,
            'excerpt'    => wp_strip_all_tags(get_the_excerpt($post)),
            'image'      => (string) get_the_post_thumbnail_url($post, 'medium_large'),
            'categories' => is_array($cats) ? array_values($cats) : array(),
        );
    }

    /**
     * @param WP_REST_Request $request
     */
    public function rest_stories($request) {
        $limit   = self::clamp_int($request->get_param('limit'), 10, 1, 50);
        $include = self::csv_slugs($request->get_param('category'));
        $exclude = self::csv_slugs($request->get_param('exclude_category'));

        $key = 'pta_sf_st_' . md5(wp_json_encode(array($limit, $include, $exclude)));
        $cached = get_transient($key);
        if (is_array($cached)) {
            return rest_ensure_response($cached);
        }

        $args = array(
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'posts_per_page'      => $limit,
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
        );
        $tax_query = array();
        if ($include) {
            $tax_query[] = array('taxonomy' => 'category', 'field' => 'slug', 'terms' => $include);
        }
        if ($exclude) {
            $tax_query[] = array('taxonomy' => 'category', 'field' => 'slug', 'terms' => $exclude, 'operator' => 'NOT IN');
        }
        if ($tax_query) {
            $args['tax_query'] = array_merge(array('relation' => 'AND'), $tax_query);
        }

        $stories = array();
        foreach ((new WP_Query($args))->posts as $post) {
            $cats = wp_get_post_terms($post->ID, 'category', array('fields' => 'names'));
            $stories[] = array(
                'id'         => (int) $post->ID,
                'title'      => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
                'url'        => get_permalink($post),
                'date'       => get_post_time('c', false, $post),
                'excerpt'    => wp_strip_all_tags(get_the_excerpt($post)),
                'image'      => (string) get_the_post_thumbnail_url($post, 'medium_large'),
                'categories' => is_array($cats) ? array_values($cats) : array(),
            );
        }

        $body = array(
            'source'    => self::source_info(),
            'generated' => gmdate('c'),
            'stories'   => $stories,
        );
        set_transient($key, $body, self::PROVIDER_TTL);
        return rest_ensure_response($body);
    }

    private static function source_info() {
        return array(
            'name' => html_entity_decode(wp_strip_all_tags(get_bloginfo('name')), ENT_QUOTES, 'UTF-8'),
            'url'  => home_url('/'),
        );
    }

    // ------------------------------------------------------------------
    // Subscribing
    // ------------------------------------------------------------------

    public function render_events_shortcode($atts = array()) {
        $atts = shortcode_atts(array(
            'source'           => '',
            'category'         => '',
            'exclude_category' => '',
            'calendar'         => '',
            'days'             => '60',
            'limit'            => '10',
            'layout'           => 'list',
            'title'            => '',
            'empty'            => 'No upcoming events.',
            'show_source'      => 'true',
        ), is_array($atts) ? $atts : array(), 'pta-shared-events');

        $source = self::normalize_source($atts['source']);
        if ($source === null) {
            return self::editor_notice('[pta-shared-events] needs source="https://…" pointing at another PTA Tools site.');
        }

        $query = array(
            'category'         => implode(',', self::csv_slugs($atts['category'])),
            'exclude_category' => implode(',', self::csv_slugs($atts['exclude_category'])),
            'calendar'         => trim((string) $atts['calendar']),
            'days'             => self::clamp_int($atts['days'], 60, 1, 400),
            'limit'            => self::clamp_int($atts['limit'], 10, 1, 200),
        );
        $feed = $this->fetch_feed('events', $source, $query);
        if (is_wp_error($feed)) {
            return self::editor_notice('Could not load events from ' . $source . ': ' . $feed->get_error_message());
        }

        $this->enqueue_styles();
        return self::render_events($feed['items'], $feed['source'], $atts);
    }

    public function render_stories_shortcode($atts = array()) {
        $atts = shortcode_atts(array(
            'source'           => '',
            'category'         => '',
            'exclude_category' => '',
            'limit'            => '6',
            'layout'           => 'cards',
            'title'            => '',
            'empty'            => 'No stories yet.',
            'show_excerpt'     => 'true',
            'show_source'      => 'true',
        ), is_array($atts) ? $atts : array(), 'pta-shared-stories');

        $source = self::normalize_source($atts['source']);
        if ($source === null) {
            return self::editor_notice('[pta-shared-stories] needs source="https://…" pointing at another PTA Tools site.');
        }

        $query = array(
            'category'         => implode(',', self::csv_slugs($atts['category'])),
            'exclude_category' => implode(',', self::csv_slugs($atts['exclude_category'])),
            'limit'            => self::clamp_int($atts['limit'], 6, 1, 50),
        );
        $feed = $this->fetch_feed('stories', $source, $query);
        if (is_wp_error($feed)) {
            return self::editor_notice('Could not load stories from ' . $source . ': ' . $feed->get_error_message());
        }

        $this->enqueue_styles();
        return self::render_stories($feed['items'], $feed['source'], $atts);
    }

    private function enqueue_styles() {
        if (!function_exists('wp_enqueue_style')) {
            return;
        }
        wp_enqueue_style(
            'pta-shared-feeds',
            AZURE_PLUGIN_URL . 'css/shared-feeds.css',
            array(),
            defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : '1'
        );
    }

    /**
     * Cached fetch with a long-lived last-good copy for when the source is down.
     *
     * @param string $kind   'events' or 'stories'
     * @param string $source normalized https origin
     * @param array  $query
     * @return array{items: array, source: array}|WP_Error
     */
    public function fetch_feed($kind, $source, array $query) {
        $hash  = md5($kind . '|' . $source . '|' . wp_json_encode($query));
        $fresh = get_transient('pta_sf_c_' . $hash);
        if (is_array($fresh)) {
            return $fresh;
        }

        $result = $kind === 'events'
            ? $this->fetch_events_remote($source, $query)
            : $this->fetch_stories_remote($source, $query);

        if (is_wp_error($result)) {
            $stale = get_transient('pta_sf_s_' . $hash);
            if (is_array($stale)) {
                set_transient('pta_sf_c_' . $hash, $stale, 300);
                return $stale;
            }
            return $result;
        }

        set_transient('pta_sf_c_' . $hash, $result, self::CACHE_TTL);
        set_transient('pta_sf_s_' . $hash, $result, self::STALE_TTL);
        return $result;
    }

    private function fetch_events_remote($source, array $query) {
        $url  = $source . '/wp-json/' . self::REST_NAMESPACE . '/feed/events?' . http_build_query(array_filter($query, 'strlen'));
        $resp = self::http_get($url);
        if (!is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 200) {
            $data = json_decode((string) wp_remote_retrieve_body($resp), true);
            if (is_array($data) && isset($data['events']) && is_array($data['events'])) {
                return array(
                    'items'  => array_map(array(__CLASS__, 'clean_event'), $data['events']),
                    'source' => self::clean_source(isset($data['source']) ? $data['source'] : array(), $source),
                );
            }
        }

        $ics_args = array('pta_ical_feed' => '1');
        if ($query['calendar'] !== '') {
            $ics_args['calendar_id'] = $query['calendar'];
        }
        $ics = self::http_get($source . '/?' . http_build_query($ics_args));
        if (is_wp_error($ics)) {
            return $ics;
        }
        $body = (string) wp_remote_retrieve_body($ics);
        if ((int) wp_remote_retrieve_response_code($ics) !== 200 || strpos($body, 'BEGIN:VCALENDAR') === false) {
            return new WP_Error('pta_feed_http', 'no calendar feed found (HTTP ' . (int) wp_remote_retrieve_response_code($ics) . ')');
        }
        $parsed = self::parse_ics($body, wp_timezone());
        $tz     = wp_timezone();
        $from   = new DateTime('today', $tz);
        $to     = clone $from;
        $to->modify('+' . (int) $query['days'] . ' days');
        $items  = self::filter_events(
            $parsed['events'],
            $from,
            $to,
            self::csv_slugs($query['category']),
            self::csv_slugs($query['exclude_category']),
            (int) $query['limit']
        );
        $name = $parsed['name'] !== '' ? preg_replace('/\s+Events$/', '', $parsed['name']) : '';
        return array('items' => $items, 'source' => self::clean_source(array('name' => $name), $source));
    }

    private function fetch_stories_remote($source, array $query) {
        $url  = $source . '/wp-json/' . self::REST_NAMESPACE . '/feed/stories?' . http_build_query(array_filter($query, 'strlen'));
        $resp = self::http_get($url);
        if (!is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 200) {
            $data = json_decode((string) wp_remote_retrieve_body($resp), true);
            if (is_array($data) && isset($data['stories']) && is_array($data['stories'])) {
                return array(
                    'items'  => array_map(array(__CLASS__, 'clean_story'), $data['stories']),
                    'source' => self::clean_source(isset($data['source']) ? $data['source'] : array(), $source),
                );
            }
        }

        $args = array('per_page' => (int) $query['limit'], '_embed' => 'wp:featuredmedia,wp:term');
        foreach (array('category' => 'categories', 'exclude_category' => 'categories_exclude') as $att => $param) {
            $slugs = self::csv_slugs($query[$att]);
            if (!$slugs) {
                continue;
            }
            $ids = $this->remote_category_ids($source, $slugs);
            if (is_wp_error($ids)) {
                return $ids;
            }
            if (!$ids && $att === 'category') {
                return array('items' => array(), 'source' => self::clean_source(array(), $source));
            }
            if ($ids) {
                $args[$param] = implode(',', $ids);
            }
        }

        $resp = self::http_get($source . '/wp-json/wp/v2/posts?' . http_build_query($args));
        if (is_wp_error($resp)) {
            return $resp;
        }
        if ((int) wp_remote_retrieve_response_code($resp) !== 200) {
            return new WP_Error('pta_feed_http', 'HTTP ' . (int) wp_remote_retrieve_response_code($resp));
        }
        $posts = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($posts)) {
            return new WP_Error('pta_feed_json', 'unexpected response');
        }
        return array(
            'items'  => array_map(array(__CLASS__, 'wp_post_to_story'), $posts),
            'source' => self::clean_source(array(), $source),
        );
    }

    private function remote_category_ids($source, array $slugs) {
        $resp = self::http_get($source . '/wp-json/wp/v2/categories?' . http_build_query(array('slug' => implode(',', $slugs), 'per_page' => 100)));
        if (is_wp_error($resp)) {
            return $resp;
        }
        $terms = json_decode((string) wp_remote_retrieve_body($resp), true);
        $ids = array();
        if (is_array($terms)) {
            foreach ($terms as $term) {
                if (isset($term['id'])) {
                    $ids[] = (int) $term['id'];
                }
            }
        }
        return $ids;
    }

    private static function http_get($url) {
        $args = array(
            'timeout'    => self::HTTP_TIMEOUT,
            'headers'    => array('Accept' => 'application/json, text/calendar'),
            'user-agent' => 'PTA Tools shared feeds; ' . home_url('/'),
        );
        // wp_safe_remote_get refuses private and loopback addresses.
        return function_exists('wp_safe_remote_get') ? wp_safe_remote_get($url, $args) : wp_remote_get($url, $args);
    }

    // ------------------------------------------------------------------
    // Pure helpers (unit tested)
    // ------------------------------------------------------------------

    /**
     * https origin (scheme + host [+ port]) or null when unusable.
     */
    public static function normalize_source($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
            $value = 'https://' . $value;
        }
        $parts = parse_url($value);
        if (!$parts || empty($parts['host']) || strtolower((string) $parts['scheme']) !== 'https') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host)) {
            return null;
        }
        return 'https://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    /**
     * Comma list of names or slugs to sanitized slugs.
     *
     * @return string[]
     */
    public static function csv_slugs($value) {
        $out = array();
        foreach (explode(',', (string) $value) as $part) {
            $slug = sanitize_title(trim(html_entity_decode($part, ENT_QUOTES, 'UTF-8')));
            $slug = trim($slug, '-');
            if ($slug !== '' && !in_array($slug, $out, true)) {
                $out[] = $slug;
            }
        }
        return $out;
    }

    public static function clamp_int($value, $default, $min, $max) {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }
        return max($min, min($max, (int) $value));
    }

    /**
     * @return DateTime|null
     */
    public static function parse_ymd($value, DateTimeZone $tz) {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim((string) $value), $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return new DateTime($m[0] . ' 00:00:00', $tz);
    }

    /**
     * 'Y-m-d H:i:s' site-local to ISO 8601. All-day values keep only the date.
     */
    public static function local_to_iso($local, DateTimeZone $tz, $all_day = false) {
        $local = trim((string) $local);
        if ($local === '') {
            return '';
        }
        try {
            $dt = new DateTime($local, $tz);
        } catch (Exception $e) {
            return '';
        }
        return $all_day ? $dt->format('Y-m-d') : $dt->format('c');
    }

    /**
     * Minimal RFC 5545 reader for the feed PTA Tools publishes (and most
     * Outlook / Google exports): unfolds lines, unescapes text, and turns
     * DTSTART/DTEND into site-local ISO strings. All-day DTEND is exclusive
     * in iCalendar and is returned inclusive.
     *
     * @return array{name: string, events: array}
     */
    public static function parse_ics($body, DateTimeZone $tz) {
        $body  = preg_replace("/\r\n|\r/", "\n", (string) $body);
        $body  = preg_replace("/\n[ \t]/", '', $body);
        $name  = '';
        $events = array();
        $cur   = null;

        foreach (explode("\n", $body) as $line) {
            if ($line === '') {
                continue;
            }
            if ($line === 'BEGIN:VEVENT') {
                $cur = array('title' => '', 'url' => '', 'start' => '', 'end' => '', 'all_day' => false, 'venue' => '', 'excerpt' => '', 'image' => '', 'categories' => array());
                continue;
            }
            if ($line === 'END:VEVENT') {
                if ($cur !== null && $cur['start'] !== '') {
                    $events[] = $cur;
                }
                $cur = null;
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $head  = substr($line, 0, $colon);
            $value = substr($line, $colon + 1);
            $parts = explode(';', $head);
            $prop  = strtoupper(array_shift($parts));
            $params = array();
            foreach ($parts as $p) {
                $eq = strpos($p, '=');
                if ($eq !== false) {
                    $params[strtoupper(substr($p, 0, $eq))] = trim(substr($p, $eq + 1), '"');
                }
            }

            if ($cur === null) {
                if ($prop === 'X-WR-CALNAME') {
                    $name = self::ics_unescape($value);
                }
                continue;
            }

            switch ($prop) {
                case 'SUMMARY':
                    $cur['title'] = self::ics_unescape($value);
                    break;
                case 'URL':
                    $cur['url'] = trim($value);
                    break;
                case 'LOCATION':
                    $cur['venue'] = self::ics_unescape($value);
                    break;
                case 'DESCRIPTION':
                    $cur['excerpt'] = self::ics_unescape($value);
                    break;
                case 'CATEGORIES':
                    foreach (preg_split('/(?<!\\\\),/', $value) as $c) {
                        $c = trim(self::ics_unescape($c));
                        if ($c !== '') {
                            $cur['categories'][] = $c;
                        }
                    }
                    break;
                case 'DTSTART':
                case 'DTEND':
                    $date_only = (isset($params['VALUE']) && strtoupper($params['VALUE']) === 'DATE') || preg_match('/^\d{8}$/', trim($value));
                    $iso = self::ics_date(trim($value), isset($params['TZID']) ? $params['TZID'] : '', $tz, $date_only);
                    if ($prop === 'DTSTART') {
                        $cur['start']   = $iso;
                        $cur['all_day'] = (bool) $date_only;
                    } else {
                        if ($date_only && $iso !== '') {
                            $iso = (new DateTime($iso, $tz))->modify('-1 day')->format('Y-m-d');
                        }
                        $cur['end'] = $iso;
                    }
                    break;
            }
        }

        foreach ($events as &$e) {
            if ($e['all_day'] && $e['end'] !== '' && $e['end'] < $e['start']) {
                $e['end'] = $e['start'];
            }
        }
        unset($e);

        return array('name' => $name, 'events' => $events);
    }

    private static function ics_unescape($s) {
        return strtr((string) $s, array('\\n' => "\n", '\\N' => "\n", '\\,' => ',', '\\;' => ';', '\\\\' => '\\'));
    }

    private static function ics_date($value, $tzid, DateTimeZone $tz, $date_only) {
        if ($date_only) {
            if (!preg_match('/^(\d{4})(\d{2})(\d{2})/', $value, $m)) {
                return '';
            }
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)$/', $value, $m)) {
            return '';
        }
        $zone = $tz;
        if ($m[7] === 'Z') {
            $zone = new DateTimeZone('UTC');
        } elseif ($tzid !== '') {
            try {
                $zone = new DateTimeZone($tzid);
            } catch (Exception $e) {
                $zone = $tz;
            }
        }
        $dt = new DateTime("{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}", $zone);
        $dt->setTimezone($tz);
        return $dt->format('c');
    }

    /**
     * Window, category and limit filtering for events read from an .ics feed.
     */
    public static function filter_events(array $events, DateTime $from, DateTime $to, array $include, array $exclude, $limit) {
        $from_s = $from->format('Y-m-d');
        $to_s   = $to->format('Y-m-d');
        $out = array();
        foreach ($events as $e) {
            $start_day = substr($e['start'], 0, 10);
            $end_day   = $e['end'] !== '' ? substr($e['end'], 0, 10) : $start_day;
            if ($end_day < $from_s || $start_day > $to_s) {
                continue;
            }
            $slugs = self::csv_slugs(implode(',', $e['categories']));
            if ($include && !array_intersect($include, $slugs)) {
                continue;
            }
            if ($exclude && array_intersect($exclude, $slugs)) {
                continue;
            }
            $out[] = $e;
        }
        usort($out, function ($a, $b) {
            return strcmp($a['start'], $b['start']);
        });
        return array_slice($out, 0, max(1, (int) $limit));
    }

    /**
     * A core /wp/v2/posts item (with _embed) as a story.
     */
    public static function wp_post_to_story($p) {
        $p = is_array($p) ? $p : array();
        $image = '';
        if (!empty($p['_embedded']['wp:featuredmedia'][0])) {
            $media = $p['_embedded']['wp:featuredmedia'][0];
            foreach (array('medium_large', 'large', 'medium', 'full') as $size) {
                if (!empty($media['media_details']['sizes'][$size]['source_url'])) {
                    $image = $media['media_details']['sizes'][$size]['source_url'];
                    break;
                }
            }
            if ($image === '' && !empty($media['source_url'])) {
                $image = $media['source_url'];
            }
        }
        $cats = array();
        if (!empty($p['_embedded']['wp:term']) && is_array($p['_embedded']['wp:term'])) {
            foreach ($p['_embedded']['wp:term'] as $group) {
                foreach ((array) $group as $term) {
                    if (isset($term['taxonomy'], $term['name']) && $term['taxonomy'] === 'category') {
                        $cats[] = html_entity_decode($term['name'], ENT_QUOTES, 'UTF-8');
                    }
                }
            }
        }
        return self::clean_story(array(
            'id'         => isset($p['id']) ? $p['id'] : 0,
            'title'      => isset($p['title']['rendered']) ? $p['title']['rendered'] : '',
            'url'        => isset($p['link']) ? $p['link'] : '',
            'date'       => isset($p['date_gmt']) ? $p['date_gmt'] . 'Z' : (isset($p['date']) ? $p['date'] : ''),
            'excerpt'    => isset($p['excerpt']['rendered']) ? $p['excerpt']['rendered'] : '',
            'image'      => $image,
            'categories' => $cats,
        ));
    }

    public static function clean_story($s) {
        $s = is_array($s) ? $s : array();
        return array(
            'title'      => self::plain(isset($s['title']) ? $s['title'] : ''),
            'url'        => self::safe_url(isset($s['url']) ? $s['url'] : ''),
            'date'       => (string) (isset($s['date']) ? $s['date'] : ''),
            'excerpt'    => self::plain(isset($s['excerpt']) ? $s['excerpt'] : ''),
            'image'      => self::safe_url(isset($s['image']) ? $s['image'] : ''),
            'categories' => array_values(array_map(array(__CLASS__, 'plain'), (array) (isset($s['categories']) ? $s['categories'] : array()))),
        );
    }

    public static function clean_event($e) {
        $e = is_array($e) ? $e : array();
        return array(
            'title'      => self::plain(isset($e['title']) ? $e['title'] : ''),
            'url'        => self::safe_url(isset($e['url']) ? $e['url'] : ''),
            'start'      => (string) (isset($e['start']) ? $e['start'] : ''),
            'end'        => (string) (isset($e['end']) ? $e['end'] : ''),
            'all_day'    => !empty($e['all_day']),
            'venue'      => self::plain(isset($e['venue']) ? $e['venue'] : ''),
            'excerpt'    => self::plain(isset($e['excerpt']) ? $e['excerpt'] : ''),
            'image'      => self::safe_url(isset($e['image']) ? $e['image'] : ''),
            'categories' => array_values(array_map(array(__CLASS__, 'plain'), (array) (isset($e['categories']) ? $e['categories'] : array()))),
        );
    }

    private static function clean_source($s, $fallback_url) {
        $s = is_array($s) ? $s : array();
        $name = self::plain(isset($s['name']) ? $s['name'] : '');
        $url  = self::safe_url(isset($s['url']) ? $s['url'] : '');
        return array(
            'name' => $name !== '' ? $name : (string) parse_url($fallback_url, PHP_URL_HOST),
            'url'  => $url !== '' ? $url : $fallback_url . '/',
        );
    }

    private static function plain($s) {
        $s = html_entity_decode(strip_tags((string) $s), ENT_QUOTES, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    private static function safe_url($u) {
        $u = trim((string) $u);
        return preg_match('#^https?://#i', $u) ? $u : '';
    }

    private static function editor_notice($message) {
        if (function_exists('current_user_can') && current_user_can('edit_posts')) {
            return '<p class="pta-shared-feed-notice">' . esc_html($message) . '</p>';
        }
        return '';
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    public static function render_events(array $events, array $source, array $atts) {
        $tz     = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $layout = strtolower((string) $atts['layout']) === 'cards' ? 'cards' : 'list';
        $html   = '<div class="pta-shared-feed pta-shared-events layout-' . $layout . '">';
        if ($atts['title'] !== '') {
            $html .= '<h3 class="pta-shared-feed-title">' . esc_html($atts['title']) . '</h3>';
        }

        if (!$events) {
            $html .= '<p class="pta-shared-feed-empty">' . esc_html($atts['empty']) . '</p>';
        } else {
            $html .= '<ul class="pta-shared-feed-list">';
            foreach ($events as $e) {
                try {
                    $start = new DateTime($e['start'], $tz);
                    $start->setTimezone($tz);
                } catch (Exception $ex) {
                    continue;
                }
                $when = $start->format('D, M j');
                if (!$e['all_day']) {
                    $when .= ' · ' . $start->format('g:i a');
                } elseif ($e['end'] !== '' && substr($e['end'], 0, 10) !== $start->format('Y-m-d')) {
                    try {
                        $when .= ' – ' . (new DateTime(substr($e['end'], 0, 10), $tz))->format('D, M j');
                    } catch (Exception $ex) {
                        // keep the start date only
                    }
                }
                $meta = $when . ($e['venue'] !== '' ? ' · ' . $e['venue'] : '');
                $title = $e['url'] !== ''
                    ? '<a class="pta-shared-feed-link" href="' . esc_url($e['url']) . '">' . esc_html($e['title']) . '</a>'
                    : esc_html($e['title']);

                $html .= '<li class="pta-shared-feed-item">';
                if ($layout === 'cards' && $e['image'] !== '') {
                    $html .= '<img class="pta-shared-feed-image" src="' . esc_url($e['image']) . '" alt="" loading="lazy">';
                }
                $html .= '<div class="pta-shared-event-date" aria-hidden="true"><span class="m">' . esc_html($start->format('M')) . '</span><span class="d">' . esc_html($start->format('j')) . '</span></div>';
                $html .= '<div class="pta-shared-feed-body"><div class="pta-shared-feed-item-title">' . $title . '</div>';
                $html .= '<div class="pta-shared-feed-meta">' . esc_html($meta) . '</div></div>';
                $html .= '</li>';
            }
            $html .= '</ul>';
        }

        $html .= self::source_line($source, $atts);
        return $html . '</div>';
    }

    public static function render_stories(array $stories, array $source, array $atts) {
        $tz     = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $layout = strtolower((string) $atts['layout']) === 'list' ? 'list' : 'cards';
        $show_excerpt = self::truthy($atts['show_excerpt']);
        $html   = '<div class="pta-shared-feed pta-shared-stories layout-' . $layout . '">';
        if ($atts['title'] !== '') {
            $html .= '<h3 class="pta-shared-feed-title">' . esc_html($atts['title']) . '</h3>';
        }

        if (!$stories) {
            $html .= '<p class="pta-shared-feed-empty">' . esc_html($atts['empty']) . '</p>';
        } else {
            $html .= '<ul class="pta-shared-feed-list">';
            foreach ($stories as $s) {
                $date = '';
                if ($s['date'] !== '') {
                    try {
                        $d = new DateTime($s['date']);
                        $d->setTimezone($tz);
                        $date = $d->format('M j, Y');
                    } catch (Exception $ex) {
                        $date = '';
                    }
                }
                $title = $s['url'] !== ''
                    ? '<a class="pta-shared-feed-link" href="' . esc_url($s['url']) . '">' . esc_html($s['title']) . '</a>'
                    : esc_html($s['title']);

                $html .= '<li class="pta-shared-feed-item">';
                if ($layout === 'cards' && $s['image'] !== '') {
                    $html .= '<img class="pta-shared-feed-image" src="' . esc_url($s['image']) . '" alt="" loading="lazy">';
                }
                $html .= '<div class="pta-shared-feed-body"><div class="pta-shared-feed-item-title">' . $title . '</div>';
                if ($date !== '') {
                    $html .= '<div class="pta-shared-feed-meta">' . esc_html($date) . '</div>';
                }
                if ($show_excerpt && $layout === 'cards' && $s['excerpt'] !== '') {
                    $html .= '<p class="pta-shared-feed-excerpt">' . esc_html(self::trim_words($s['excerpt'], 30)) . '</p>';
                }
                $html .= '</div></li>';
            }
            $html .= '</ul>';
        }

        $html .= self::source_line($source, $atts);
        return $html . '</div>';
    }

    private static function source_line(array $source, array $atts) {
        if (!self::truthy($atts['show_source']) || empty($source['url'])) {
            return '';
        }
        return '<p class="pta-shared-feed-source">From <a href="' . esc_url($source['url']) . '">' . esc_html($source['name']) . '</a></p>';
    }

    private static function truthy($v) {
        return !in_array(strtolower(trim((string) $v)), array('0', 'false', 'no', 'off', ''), true);
    }

    private static function trim_words($text, $n) {
        $words = preg_split('/\s+/u', trim((string) $text));
        if (count($words) <= $n) {
            return implode(' ', $words);
        }
        return implode(' ', array_slice($words, 0, $n)) . '…';
    }
}
