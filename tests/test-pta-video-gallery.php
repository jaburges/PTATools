<?php
/**
 * How-to video cards and the PTSA-team-only wrapper.
 *
 * Run: php tests/test-pta-video-gallery.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_url')) {
    function esc_url($url) {
        return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8');
    }
}

require_once AZURE_PLUGIN_PATH . 'includes/class-pta-video-gallery.php';

$t = new TestRunner('Video gallery and team-only content');
$g = 'Azure_PTA_Video_Gallery';
$team = array('administrator', 'azuread');

// Who counts as the PTSA team
$t->check($g::roles_are_team(array('administrator'), false, $team), 'administrators are team');
$t->check($g::roles_are_team(array('azuread'), false, $team), 'Azure AD users are team');
$t->check($g::roles_are_team(array('customer', 'azuread'), false, $team), 'a second azuread role counts');
$t->check($g::roles_are_team(array('subscriber'), true, $team), 'a custom SSO role carrying azure_ad_user counts');
$t->check(!$g::roles_are_team(array('customer'), false, $team), 'parents (customers) are not team');
$t->check(!$g::roles_are_team(array('subscriber'), false, $team), 'subscribers are not team');
$t->check(!$g::roles_are_team(array(), false, $team), 'no roles is not team');

// Cards
$card = $g::card_html('https://example.org/v.mp4', 'https://example.org/p.jpg', '1. Signing In', 'Why it is worth signing in.');
$t->check(strpos($card, 'class="pta-video-card"') !== false, 'renders a card');
$t->check(strpos($card, 'poster="https://example.org/p.jpg"') !== false, 'the thumbnail is the poster');
$t->check(strpos($card, 'preload="none"') !== false, 'videos do not download until played');
$t->check(strpos($card, 'playsinline') !== false, 'iPhones play in the card instead of jumping to fullscreen');
$t->check(strpos($card, '<source src="https://example.org/v.mp4" type="video/mp4" />') !== false, 'has the mp4 source');
$t->check(strpos($card, '<h3 class="pta-video-card__title">1. Signing In</h3>') !== false, 'shows the title');
$t->check(strpos($card, '<p class="pta-video-card__text">Why it is worth signing in.</p>') !== false, 'shows the description');
$t->equals('', $g::card_html('', 'p.jpg', 'x', 'y'), 'no card without a video');
$escaped = $g::card_html('https://example.org/v.mp4', '', '<b>T</b>', 'a & b');
$t->check(strpos($escaped, '&lt;b&gt;T&lt;/b&gt;') !== false && strpos($escaped, 'a &amp; b') !== false, 'title and description are escaped');
$t->check(strpos($escaped, 'poster=') === false, 'no empty poster attribute');

// wpautop leftovers between nested shortcodes
$auto = "<p>[pta-video src=\"a\"]A[/pta-video]<br />\n[pta-video src=\"b\"]B[/pta-video]</p>";
$t->equals('[pta-video src="a"]A[/pta-video][pta-video src="b"]B[/pta-video]', $g::strip_autop($auto), 'stray <p> and <br> between cards are removed');

// Wiring
$main = file_get_contents(AZURE_PLUGIN_PATH . 'azure-plugin.php');
$t->check(strpos($main, "'class-pta-video-gallery.php'") !== false && strpos($main, 'Azure_PTA_Video_Gallery::get_instance()') !== false, 'the plugin loads the gallery');
$t->check(file_exists(AZURE_PLUGIN_PATH . 'css/pta-video-gallery.css'), 'the stylesheet ships');

exit($t->finish());
