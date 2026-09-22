<?php
/**
 * [up-next show-location="true"] corner: join link or place name.
 *
 * Run: php tests/test-upcoming-location.php
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

$t = new TestRunner('Upcoming location corner');

update_post_meta(41, '_EventVenue', 'Art Portable');
$t->equals('Art Portable', Azure_Upcoming_Module::location_name(41), 'venue text is the location');

update_post_meta(42, '_EventVenue', 'https://teams.microsoft.com/l/meetup-join/abc');
$t->equals('', Azure_Upcoming_Module::location_name(42), 'a meeting URL is not shown as a place');

$module = new Azure_Upcoming_Module();
$render = new ReflectionMethod('Azure_Upcoming_Module', 'render_events_list');

$base = array(
    'id'         => 0,
    'title'      => 'Oceans',
    'url'        => 'https://example.test/event/oceans',
    'start_date' => '2026-09-23 12:30:00',
    'end_date'   => '2026-09-23 13:30:00',
    'all_day'    => false,
    'online_url' => '',
    'location'   => 'Art Portable',
);
$options = array(
    'show_time'         => true,
    'link_titles'       => true,
    'show_join_meeting' => true,
    'show_location'     => true,
    'show_image'        => false,
    'empty_message'     => 'none',
);

$in_person = $render->invoke($module, array($base), $options);
$t->check(strpos($in_person, 'upcoming-place') !== false, 'in-person event shows the place');
$t->check(strpos($in_person, 'Art Portable') !== false, 'place text is the location');
$t->check(strpos($in_person, 'has-corner') !== false, 'place sits in the corner slot');
$t->check(strpos($in_person, 'pta-join-meeting') === false, 'in-person event does not show a join link');
$t->check(strpos($in_person, 'upcoming-location-badge') === false, 'the corner replaces the in-person badge');

$online = $base;
$online['online_url'] = 'https://teams.microsoft.com/l/meetup-join/abc';
$online['location'] = 'Art Portable';
$online_html = $render->invoke($module, array($online), $options);
$t->check(strpos($online_html, 'pta-join-meeting') !== false, 'online event shows the join link');
$t->check(strpos($online_html, 'upcoming-place') === false, 'online event does not also show the place');
$t->check(strpos($online_html, 'upcoming-corner upcoming-join-meeting') !== false, 'join link is in the top-right corner');

$off = $options;
$off['show_location'] = false;
$plain = $render->invoke($module, array($base), $off);
$t->check(strpos($plain, 'upcoming-place') === false, 'show-location off leaves the place hidden');
$t->check(strpos($plain, 'upcoming-location-badge') !== false, 'the badge stays when the corner is off');

$empty_place = $base;
$empty_place['location'] = '';
$no_place = $render->invoke($module, array($empty_place), $options);
$t->check(strpos($no_place, 'upcoming-place') === false, 'an in-person event with no location has an empty corner');
$t->check(strpos($no_place, 'has-corner') === false, 'no corner class without a place or join link');

$docs = file_get_contents(AZURE_PLUGIN_PATH . 'admin/upcoming-page.php');
$t->check(strpos($docs, 'show-location="true"') !== false, 'Upcoming docs show the attribute');

exit($t->finish());
