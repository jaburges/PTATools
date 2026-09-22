<?php
/**
 * Compact Join control for Upcoming cards.
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

$t = new TestRunner('Join meeting control');

$t->equals('teams', Azure_Event_CPT::online_meeting_provider_slug('https://teams.microsoft.com/l/meetup-join/19%3a'), 'Teams host');
$t->equals('zoom', Azure_Event_CPT::online_meeting_provider_slug('https://us02web.zoom.us/j/123'), 'Zoom host');
$t->equals('meet', Azure_Event_CPT::online_meeting_provider_slug('https://meet.google.com/abc-defg'), 'Meet host');
$t->equals('generic', Azure_Event_CPT::online_meeting_provider_slug('https://example.com/room'), 'unknown host is generic');

$html = Azure_Event_CPT::render_join_meeting_markup('https://teams.microsoft.com/l/meetup-join/x', 'compact');
$t->check(strpos($html, 'pta-join-meeting--compact') !== false, 'compact variant class');
$t->check(strpos($html, 'data-provider="teams"') !== false, 'compact data-provider is slug');
$t->check(strpos($html, 'Join Microsoft Teams meeting') !== false, 'accessible label names Teams');
$t->check(preg_match('/>Join<\/a>/', $html) === 1, 'visible label is Join');
$t->check(strpos($html, '<svg') === false, 'compact variant has no SVG');
$t->check(strpos($html, 'pta-join-meeting-provider') === false, 'compact variant has no provider chip');

$icon = Azure_Event_CPT::render_join_meeting_markup('https://teams.microsoft.com/l/meetup-join/x', 'icon');
$t->check(strpos($icon, 'width="16"') !== false, 'icon SVG has an explicit width');
$t->check(strpos($icon, 'height="16"') !== false, 'icon SVG has an explicit height');

$inline = Azure_Event_CPT::render_join_meeting_markup('https://teams.microsoft.com/l/meetup-join/x', 'inline');
$t->check(strpos($inline, 'pta-join-meeting-label') !== false, 'inline variant still has the text button');
$t->check(strpos($inline, 'pta-join-meeting--compact') === false, 'inline variant is not compact');

$t->equals('', Azure_Event_CPT::render_join_meeting_markup('', 'compact'), 'empty URL yields no markup');

$src = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-upcoming-module.php');
$t->check(strpos($src, "'compact'") !== false, 'Upcoming cards use the compact Join control');
$t->check(strpos($src, "CACHE_SCHEMA = '10'") !== false, 'Upcoming cache schema bumped for join markup');

exit($t->finish() === 0 ? 0 : 1);
