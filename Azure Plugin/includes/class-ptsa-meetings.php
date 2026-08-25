<?php
/**
 * PTSA meeting events: category tag, shortcode, and attachment links.
 *
 * Outlook sync maps every calendar to one category (usually "PTA Events").
 * Board and General meetings share that calendar with everything else, so
 * the shortcode discovers them by a dedicated `PTSA Meeting` category
 * rather than matching titles at render time.
 *
 * A one-shot seeder (and the sync engine) still use title heuristics to
 * apply that category. After that, editors can add or remove the tag.
 *
 * @package AzurePlugin
 * @since   3.147.14
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_PTSA_Meetings {

    const CATEGORY_NAME = 'PTSA Meeting';
    const CATEGORY_SLUG = 'ptsa-meeting';
    const TAG_OPTION    = 'azure_ptsa_meeting_tag_version';
    const TAG_VERSION   = '1';

    /** @var self|null */
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_shortcode('ptsa-meetings', array($this, 'render_shortcode'));
        add_shortcode('PTSA-meetings', array($this, 'render_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        // Event CPT registers the taxonomy at init/20. Tag after that.
        add_action('init', array(__CLASS__, 'maybe_tag_existing_meetings'), 21);
        if (did_action('init') && taxonomy_exists(self::taxonomy())) {
            self::maybe_tag_existing_meetings();
        }
    }

    /**
     * Collapse an event title so Board / General naming variants match.
     */
    public static function normalize_title($title) {
        $value = strtolower(html_entity_decode(strip_tags((string) $title), ENT_QUOTES, 'UTF-8'));
        $value = str_replace(array('&', '–', '—', '/', '\\'), array('and', ' ', ' ', ' ', ' '), $value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    /**
     * True when this title is a regular Board or General PTSA meeting.
     *
     * WatchDOGS / theater parent / info sessions are excluded even if
     * they contain the word "meeting".
     */
    public static function is_meeting_title($title) {
        $normalized = self::normalize_title($title);
        if ($normalized === '') {
            return false;
        }

        foreach (array('watchdogs', 'theater parents', 'info meeting') as $exclude) {
            if (strpos($normalized, $exclude) !== false) {
                return false;
            }
        }

        if (preg_match('/^ptsa board meeting\b/', $normalized)) {
            return true;
        }
        if (preg_match('/^ptsa general meeting\b/', $normalized)) {
            return true;
        }
        if (preg_match('/^ptsa meeting\b/', $normalized)) {
            return true;
        }
        if (preg_match('/^general membership( meeting)?\b/', $normalized)) {
            return true;
        }

        return false;
    }

    /**
     * True when a URL looks like a downloadable file, not a page or join link.
     */
    public static function looks_like_file_url($url) {
        $url = html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8');
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return false;
        }
        if (preg_match('#/wp-content/uploads/#i', $path)) {
            return true;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $ok  = array(
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'zip', 'txt', 'rtf', 'csv', 'odt', 'ods', 'odp',
        );
        return in_array($ext, $ok, true);
    }

    /**
     * File links embedded in event HTML (Outlook body, editor, file blocks).
     *
     * @return array<int, array{url:string,title:string}>
     */
    public static function attachments_from_content($html) {
        $html  = (string) $html;
        $files = array();

        if (preg_match_all('/<!--\s*wp:file\s+(\{.*?\})\s+-->/s', $html, $blocks)) {
            foreach ($blocks[1] as $json) {
                $data = json_decode($json, true);
                if (!is_array($data) || empty($data['href'])) {
                    continue;
                }
                $url = (string) $data['href'];
                if (!self::looks_like_file_url($url)) {
                    continue;
                }
                $title = '';
                if (!empty($data['fileName'])) {
                    $title = (string) $data['fileName'];
                }
                $files[self::attachment_key($url)] = array(
                    'url'   => $url,
                    'title' => $title !== '' ? $title : self::filename_from_url($url),
                );
            }
        }

        if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
                if (!self::looks_like_file_url($url)) {
                    continue;
                }
                $label = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES, 'UTF-8'));
                $key   = self::attachment_key($url);
                if (isset($files[$key]) && $files[$key]['title'] !== '') {
                    continue;
                }
                $files[$key] = array(
                    'url'   => $url,
                    'title' => $label !== '' ? $label : self::filename_from_url($url),
                );
            }
        }

        return array_values($files);
    }

    /**
     * Media library children plus file links in the event body.
     * Featured images are skipped so a hero photo is not listed as a download.
     *
     * @return array<int, array{url:string,title:string,id?:int}>
     */
    public static function get_event_attachments($post_id) {
        $post_id = (int) $post_id;
        $files   = array();
        $featured = function_exists('get_post_thumbnail_id')
            ? (int) get_post_thumbnail_id($post_id)
            : 0;

        $curated = function_exists('get_post_meta')
            ? get_post_meta($post_id, '_pta_event_attachment_ids', true)
            : array();
        if (class_exists('Azure_Event_CPT')) {
            $curated = Azure_Event_CPT::normalize_attachment_ids($curated);
        } else {
            $curated = is_array($curated) ? array_values(array_filter(array_map('intval', $curated))) : array();
        }
        foreach ($curated as $att_id) {
            if ($featured > 0 && $att_id === $featured) {
                continue;
            }
            $url = function_exists('wp_get_attachment_url')
                ? wp_get_attachment_url($att_id)
                : '';
            if (!$url) {
                continue;
            }
            $title = function_exists('get_the_title') ? trim((string) get_the_title($att_id)) : '';
            $files[self::attachment_key($url)] = array(
                'id'    => $att_id,
                'url'   => $url,
                'title' => $title !== '' ? $title : self::filename_from_url($url),
            );
        }

        if (function_exists('get_children')) {
            $children = get_children(array(
                'post_parent' => $post_id,
                'post_type'   => 'attachment',
                'post_status' => 'inherit',
                'numberposts' => 50,
                'orderby'     => 'menu_order title',
                'order'       => 'ASC',
            ));
            if (is_array($children)) {
                foreach ($children as $att) {
                    $att_id = (int) $att->ID;
                    if ($featured > 0 && $att_id === $featured) {
                        continue;
                    }
                    $url = function_exists('wp_get_attachment_url')
                        ? wp_get_attachment_url($att_id)
                        : '';
                    if (!$url) {
                        continue;
                    }
                    $title = trim((string) $att->post_title);
                    $files[self::attachment_key($url)] = array(
                        'id'    => $att_id,
                        'url'   => $url,
                        'title' => $title !== '' ? $title : self::filename_from_url($url),
                    );
                }
            }
        }

        $content = function_exists('get_post_field')
            ? (string) get_post_field('post_content', $post_id)
            : '';
        foreach (self::attachments_from_content($content) as $file) {
            $key = self::attachment_key($file['url']);
            if (!isset($files[$key])) {
                $files[$key] = $file;
            }
        }

        return array_values($files);
    }

    /**
     * Create the category if needed. Returns the term ID, or 0 on failure.
     */
    public static function ensure_category() {
        $taxonomy = self::taxonomy();
        if (!function_exists('taxonomy_exists') || !taxonomy_exists($taxonomy)) {
            return 0;
        }

        $existing = get_term_by('slug', self::CATEGORY_SLUG, $taxonomy);
        if ($existing && !is_wp_error($existing)) {
            return (int) $existing->term_id;
        }

        $by_name = get_term_by('name', self::CATEGORY_NAME, $taxonomy);
        if ($by_name && !is_wp_error($by_name)) {
            return (int) $by_name->term_id;
        }

        $created = wp_insert_term(self::CATEGORY_NAME, $taxonomy, array(
            'slug' => self::CATEGORY_SLUG,
        ));
        if (is_wp_error($created)) {
            return 0;
        }

        return (int) $created['term_id'];
    }

    /**
     * Append the PTSA Meeting category without replacing existing terms.
     */
    public static function tag_event($post_id) {
        $post_id = (int) $post_id;
        $term_id = self::ensure_category();
        if ($post_id < 1 || $term_id < 1) {
            return false;
        }
        $result = wp_set_object_terms($post_id, array($term_id), self::taxonomy(), true);
        return !is_wp_error($result);
    }

    /**
     * One-shot: create the category and tag existing Board / General meetings.
     */
    public static function maybe_tag_existing_meetings($force = false) {
        if (!$force && (string) get_option(self::TAG_OPTION, '') === self::TAG_VERSION) {
            return;
        }

        $term_id = self::ensure_category();
        if ($term_id < 1) {
            return;
        }

        $ids = get_posts(array(
            'post_type'      => self::post_type(),
            'post_status'    => array('publish', 'future', 'private', 'draft'),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ));

        $tagged = 0;
        foreach ($ids as $id) {
            if (!self::is_meeting_title(get_the_title($id))) {
                continue;
            }
            if (self::tag_event((int) $id)) {
                $tagged++;
            }
        }

        update_option(self::TAG_OPTION, self::TAG_VERSION, false);

        if (class_exists('Azure_Logger')) {
            Azure_Logger::info('PTSA Meetings: tagged ' . $tagged . ' events as ' . self::CATEGORY_NAME);
        }
    }

    /**
     * Categories the Outlook sync must keep when it rewrites terms.
     *
     * @param string[] $existing_names
     * @return string[]
     */
    public static function preserved_category_names($existing_names) {
        $keep = array();
        foreach ((array) $existing_names as $name) {
            $name = trim((string) $name);
            if ($name === self::CATEGORY_NAME) {
                $keep[] = $name;
            }
        }
        return $keep;
    }

    public function enqueue_assets() {
        global $post;
        if (!is_a($post, 'WP_Post')) {
            return;
        }
        if (
            !has_shortcode($post->post_content, 'ptsa-meetings')
            && !has_shortcode($post->post_content, 'PTSA-meetings')
        ) {
            return;
        }

        wp_enqueue_style(
            'azure-ptsa-meetings',
            AZURE_PLUGIN_URL . 'css/pta-meetings.css',
            array(),
            AZURE_PLUGIN_VERSION
        );
    }

    /**
     * [ptsa-meetings] — list every event tagged PTSA Meeting.
     *
     * Attributes:
     *   upcoming          true = hide past meetings
     *   limit             max events (-1 = all)
     *   order             ASC | DESC by start date (used when upcoming=true)
     *   show_attachments  true/false
     *   show_location     true/false
     *   show_join         true/false
     *   show_time         true/false
     *   empty             custom empty-state text
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'upcoming'         => 'false',
            'limit'            => '-1',
            'order'            => 'ASC',
            'show_attachments' => 'true',
            'show_location'    => 'true',
            'show_join'        => 'true',
            'show_time'        => 'true',
            'empty'            => '',
        ), $atts, 'ptsa-meetings');

        if (!taxonomy_exists(self::taxonomy())) {
            return '';
        }

        $upcoming_only    = filter_var($atts['upcoming'], FILTER_VALIDATE_BOOLEAN);
        $show_attachments = filter_var($atts['show_attachments'], FILTER_VALIDATE_BOOLEAN);
        $show_location    = filter_var($atts['show_location'], FILTER_VALIDATE_BOOLEAN);
        $show_join        = filter_var($atts['show_join'], FILTER_VALIDATE_BOOLEAN);
        $show_time        = filter_var($atts['show_time'], FILTER_VALIDATE_BOOLEAN);
        $limit            = (int) $atts['limit'];
        $order            = strtoupper((string) $atts['order']) === 'DESC' ? 'DESC' : 'ASC';

        $query_args = array(
            'post_type'      => self::post_type(),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'meta_value',
            'meta_key'       => '_EventStartDate',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'tax_query'      => array(
                array(
                    'taxonomy' => self::taxonomy(),
                    'field'    => 'slug',
                    'terms'    => self::CATEGORY_SLUG,
                ),
            ),
        );

        $query  = new WP_Query($query_args);
        $now    = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
        $upcoming = array();
        $past     = array();

        foreach ($query->posts as $event) {
            $start = (string) get_post_meta($event->ID, '_EventStartDate', true);
            if ($start !== '' && $start < $now) {
                $past[] = $event;
            } else {
                $upcoming[] = $event;
            }
        }

        if ($upcoming_only) {
            $past = array();
            if ($order === 'DESC') {
                $upcoming = array_reverse($upcoming);
            }
        } else {
            $past = array_reverse($past);
        }

        if ($limit > 0) {
            $upcoming = array_slice($upcoming, 0, $limit);
            $remain   = $limit - count($upcoming);
            $past     = $remain > 0 ? array_slice($past, 0, $remain) : array();
        }

        if (empty($upcoming) && empty($past)) {
            $empty = trim((string) $atts['empty']);
            if ($empty === '') {
                $empty = __('No PTSA meetings found.', 'azure-plugin');
            }
            return '<div class="ptsa-meetings ptsa-meetings-empty"><p>' . esc_html($empty) . '</p></div>';
        }

        $html  = '<div class="ptsa-meetings">';
        if (!empty($upcoming)) {
            $html .= '<section class="ptsa-meetings-section ptsa-meetings-upcoming">';
            $html .= '<h3 class="ptsa-meetings-heading">' . esc_html__('Upcoming meetings', 'azure-plugin') . '</h3>';
            $html .= $this->render_list($upcoming, $show_attachments, $show_time, $show_location, $show_join);
            $html .= '</section>';
        }
        if (!empty($past)) {
            $html .= '<section class="ptsa-meetings-section ptsa-meetings-past">';
            $html .= '<h3 class="ptsa-meetings-heading">' . esc_html__('Past meetings', 'azure-plugin') . '</h3>';
            $html .= $this->render_list($past, $show_attachments, $show_time, $show_location, $show_join);
            $html .= '</section>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * @param WP_Post[] $events
     */
    private function render_list($events, $show_attachments, $show_time, $show_location = true, $show_join = true) {
        $html = '<ul class="ptsa-meetings-list">';
        foreach ($events as $event) {
            $html .= $this->render_item($event, $show_attachments, $show_time, $show_location, $show_join);
        }
        $html .= '</ul>';
        return $html;
    }

    private function render_item($event, $show_attachments, $show_time, $show_location = true, $show_join = true) {
        $post_id = (int) $event->ID;
        $title   = get_the_title($post_id);
        $url     = get_permalink($post_id);
        $when    = $this->format_when($post_id, $show_time);

        $html  = '<li class="ptsa-meetings-item">';
        $html .= '<div class="ptsa-meetings-main">';
        if ($when !== '') {
            $html .= '<time class="ptsa-meetings-date" datetime="'
                . esc_attr((string) get_post_meta($post_id, '_EventStartDate', true)) . '">'
                . esc_html($when) . '</time>';
        }
        $html .= '<a class="ptsa-meetings-title" href="' . esc_url($url) . '">'
            . esc_html($title) . '</a>';
        $html .= '</div>';

        if ($show_location && class_exists('Azure_Event_CPT')) {
            $location = Azure_Event_CPT::get_in_person_location($post_id);
            if ($location !== '') {
                $html .= '<p class="ptsa-meetings-location"><span class="ptsa-meetings-label">'
                    . esc_html__('In person', 'azure-plugin') . '</span> '
                    . esc_html($location) . '</p>';
            }
        }

        if ($show_join && class_exists('Azure_Event_CPT')) {
            $join = Azure_Event_CPT::extract_online_meeting_url($post_id);
            if ($join !== '') {
                $html .= '<p class="ptsa-meetings-join"><span class="ptsa-meetings-label">'
                    . esc_html__('Teams meeting', 'azure-plugin') . '</span> '
                    . '<a href="' . esc_url($join) . '" target="_blank" rel="noopener">'
                    . esc_html($join) . '</a></p>';
            }
        }

        if ($show_attachments) {
            $files = self::get_event_attachments($post_id);
            if (!empty($files)) {
                $html .= '<p class="ptsa-meetings-label">' . esc_html__('Attachments', 'azure-plugin') . '</p>';
                $html .= '<ul class="ptsa-meetings-files">';
                foreach ($files as $file) {
                    $html .= '<li><a class="ptsa-meetings-file" href="'
                        . esc_url($file['url']) . '" target="_blank" rel="noopener">'
                        . esc_html($file['title']) . '</a></li>';
                }
                $html .= '</ul>';
            }
        }

        $html .= '</li>';
        return $html;
    }

    private function format_when($post_id, $show_time) {
        $start = (string) get_post_meta($post_id, '_EventStartDate', true);
        if ($start === '') {
            return '';
        }
        $ts = strtotime($start);
        if (!$ts) {
            return $start;
        }

        $date_fmt = get_option('date_format', 'F j, Y');
        $line     = function_exists('date_i18n') ? date_i18n($date_fmt, $ts) : date($date_fmt, $ts);

        $all_day = get_post_meta($post_id, '_EventAllDay', true) === 'yes';
        if ($show_time && !$all_day) {
            $time_fmt = get_option('time_format', 'g:i A');
            $line    .= ' · ' . (function_exists('date_i18n') ? date_i18n($time_fmt, $ts) : date($time_fmt, $ts));
        }

        return $line;
    }

    private static function taxonomy() {
        return class_exists('Azure_Event_CPT')
            ? Azure_Event_CPT::TAXONOMY_CATEGORY
            : 'pta_event_category';
    }

    private static function post_type() {
        return class_exists('Azure_Event_CPT')
            ? Azure_Event_CPT::POST_TYPE_EVENT
            : 'pta_event';
    }

    private static function attachment_key($url) {
        return strtolower(preg_replace('/[?#].*$/', '', (string) $url));
    }

    private static function filename_from_url($url) {
        $path = parse_url((string) $url, PHP_URL_PATH);
        $base = is_string($path) ? rawurldecode(basename($path)) : '';
        return $base !== '' ? $base : (string) $url;
    }
}
