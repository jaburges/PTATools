<?php
/**
 * Icon-only join-meeting control for Upcoming cards.
 *
 * Run: php tests/test-join-meeting-icon.php
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
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) {
        return $component === -1 ? parse_url($url) : parse_url($url, $component);
    }
}

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-event-cpt.php';

$t = new TestRunner('Join meeting icon');

$t->equals('teams', Azure_Event_CPT::online_meeting_provider_slug('https://teams.microsoft.com/l/meetup-join/19%3a'), 'Teams host');
$t->equals('zoom', Azure_Event_CPT::online_meeting_provider_slug('https://us02web.zoom.us/j/123'), 'Zoom host');
$t->equals('meet', Azure_Event_CPT::online_meeting_provider_slug('https://meet.google.com/abc-defg'), 'Meet host');
$t->equals('generic', Azure_Event_CPT::online_meeting_provider_slug('https://example.com/room'), 'unknown host is generic');

$teams_svg = Azure_Event_CPT::join_meeting_icon_svg('teams');
$zoom_svg  = Azure_Event_CPT::join_meeting_icon_svg('zoom');
$t->check(strpos($teams_svg, '<svg') !== false, 'Teams icon is SVG');
$t->check(strpos($zoom_svg, '<svg') !== false, 'Zoom icon is SVG');
$t->check($teams_svg !== $zoom_svg, 'Teams and Zoom icons differ');

$html = Azure_Event_CPT::render_join_meeting_markup('https://teams.microsoft.com/l/meetup-join/x', 'icon');
$t->check(strpos($html, 'pta-join-meeting--icon') !== false, 'icon variant class');
$t->check(strpos($html, 'data-provider="teams"') !== false, 'icon data-provider is slug');
$t->check(strpos($html, 'Join Microsoft Teams meeting') !== false, 'accessible label names Teams');
$t->check(strpos($html, 'pta-join-meeting-label') === false, 'icon variant has no visible Join meeting label');
$t->check(strpos($html, '<svg') !== false, 'icon variant embeds SVG');

$inline = Azure_Event_CPT::render_join_meeting_markup('https://teams.microsoft.com/l/meetup-join/x', 'inline');
$t->check(strpos($inline, 'pta-join-meeting-label') !== false, 'inline variant still has the text button');
$t->check(strpos($inline, 'pta-join-meeting--icon') === false, 'inline variant is not icon-only');

$t->equals('', Azure_Event_CPT::render_join_meeting_markup('', 'icon'), 'empty URL yields no markup');

exit($t->finish() === 0 ? 0 : 1);
