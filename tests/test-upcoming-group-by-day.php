<?php
/**
 * [up-next] theme option: events on the same date share one card.
 *
 * Run: php tests/test-upcoming-group-by-day.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null) {
        return esc_html(__($text, $domain));
    }
}
if (!function_exists('esc_url')) {
    function esc_url($url) {
        return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) {
        return esc_url($url);
    }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) {
        return $component === -1 ? parse_url($url) : parse_url($url, $component);
    }
}
if (!function_exists('date_i18n')) {
    function date_i18n($format, $timestamp = false) {
        return date($format, $timestamp ? $timestamp : time());
    }
}
if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback) {
        return true;
    }
}

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once AZURE_PLUGIN_PATH . 'includes/class-event-cpt.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-upcoming-module.php';

$t = new TestRunner('Upcoming group by day');

$module = new Azure_Upcoming_Module();
$render = new ReflectionMethod('Azure_Upcoming_Module', 'render_events_list');

function pta_group_event($title, $start, $all_day = false) {
    return array(
        'id'         => 0,
        'title'      => $title,
        'url'        => 'https://example.test/event/' . strtolower(str_replace(' ', '-', $title)),
        'start_date' => $start,
        'end_date'   => $start,
        'all_day'    => $all_day,
        'online_url' => '',
        'location'   => '',
    );
}

$events = array(
    pta_group_event('Fall Enrichment Session Begins', '2026-09-28 00:00:00', true),
    pta_group_event('Art Docent Training', '2026-09-28 08:45:00'),
    pta_group_event('Art Docent Training', '2026-09-30 08:45:00'),
    pta_group_event('Popcorn Friday', '2026-10-02 00:00:00', true),
);

$options = array(
    'show_time'         => true,
    'link_titles'       => true,
    'show_join_meeting' => false,
    'show_location'     => false,
    'show_image'        => false,
    'date_pill'         => 'left',
    'group_by_day'      => true,
    'empty_message'     => 'none',
);

$grouped = $render->invoke($module, $events, $options);
$t->equals(3, substr_count($grouped, '<li '), 'four events on three dates make three cards');
$t->equals(3, substr_count($grouped, 'upcoming-date-pill"'), 'one date pill per day');
$t->equals(4, substr_count($grouped, 'class="upcoming-body"'), 'every event keeps its own body');
$t->equals(3, substr_count($grouped, 'upcoming-day-events'), 'each day wraps its events');
$t->equals(substr_count($grouped, '<li '), substr_count($grouped, '</li>'), 'every card is closed');
$first_card = substr($grouped, 0, strpos($grouped, '</li>'));
$t->check(strpos($first_card, 'Fall Enrichment Session Begins') !== false && strpos($first_card, '8:45am') !== false, 'both Monday events are in the first card');

$ungrouped_options = $options;
$ungrouped_options['group_by_day'] = false;
$ungrouped = $render->invoke($module, $events, $ungrouped_options);
$t->equals(4, substr_count($ungrouped, '<li '), 'grouping off keeps one card per event');
$t->check(strpos($ungrouped, 'upcoming-day-events') === false, 'grouping off adds no day wrapper');

$no_pill = $options;
$no_pill['date_pill'] = 'none';
$plain = $render->invoke($module, $events, $no_pill);
$t->equals(4, substr_count($plain, '<li '), 'grouping needs the date pill');

$single = $render->invoke($module, array($events[2]), $options);
$t->equals(1, substr_count($single, '<li '), 'a single event still renders one card');
$t->equals(1, substr_count($single, '</li>'), 'a single card is closed');

$placed = pta_group_event('5th Grade - Neibauer', '2026-10-01 12:30:00');
$placed['location'] = 'Art Room';
$compact_options = $options;
$compact_options['compact_cards'] = true;
$compact_options['show_location'] = true;
$compact = $render->invoke($module, array($placed), $compact_options);
$title_at = strpos($compact, '5th Grade - Neibauer');
$meta_at = strpos($compact, 'upcoming-meta-line');
$t->check($title_at !== false && $meta_at !== false && $title_at < $meta_at, 'compact card puts the event title before the time line');
$t->check(strpos($compact, '12:30pm</span><span class="upcoming-meta-sep"> · </span><span class="upcoming-meta-place">Art Room') !== false, 'the line under the title is time · location');
$t->check(strpos($compact, 'upcoming-corner') === false, 'compact card does not put the location in the heading slot');

exit($t->finish());
