<?php
/**
 * The Subscribe button in volunteer emails is an https link unique to the
 * recipient, and that link offers the private feed to each calendar app.
 *
 * Run: php tests/test-volunteer-subscribe-link.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

class Subscribe_State {
    public static $meta = array();
}

function get_user_meta($id, $key, $single = false) { return Subscribe_State::$meta[$id][$key] ?? ''; }
function update_user_meta($id, $key, $value) { Subscribe_State::$meta[$id][$key] = $value; return true; }
function get_users($args) {
    foreach (Subscribe_State::$meta as $id => $meta) {
        if (($meta[$args['meta_key']] ?? null) === $args['meta_value']) {
            return array($id);
        }
    }
    return array();
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-volunteer-signup.php';

$t = new TestRunner('Volunteer email Subscribe link');

$sheet = (object) array('title' => 'WatchDOGS', 'event_location' => 'Wilder', 'event_date' => '2026-10-23 11:30:00');
$act = (object) array('name' => 'Volunteer', 'slot_start' => '2026-10-23 11:30:00', 'slot_end' => '2026-10-23 13:30:00');

list($subject, $body) = Azure_Volunteer_Signup::compose_volunteer_message('volunteer_reminder', 'Jamie', $sheet, array($act), 'WatchDOGS', '', 44);
$token44 = Subscribe_State::$meta[44]['pta_volunteer_ics_token'] ?? '';
$t->check((bool) preg_match('/^[a-f0-9]{32}$/', $token44), 'the recipient gets a private calendar token');

$t->check(preg_match('#<a href="([^"]+)"[^>]*>Subscribe</a>#', $body, $m) === 1, 'the reminder Subscribe button is an anchor with an href');
$href = $m[1] ?? '';
$t->equals('https://example.test/?pta_volunteer_subscribe=' . $token44, $href, 'the button links to this recipient\'s https subscribe page');
$t->check(strpos($body, 'webcal://') === false, 'no webcal:// href that mail apps strip');

list(, $other) = Azure_Volunteer_Signup::compose_volunteer_message('volunteer_reminder', 'Sam', $sheet, array($act), 'WatchDOGS', '', 45);
$token45 = Subscribe_State::$meta[45]['pta_volunteer_ics_token'] ?? '';
$t->check($token45 !== '' && $token45 !== $token44, 'each recipient has their own token');
$t->check(strpos($other, $token45) !== false && strpos($other, $token44) === false, 'a recipient only sees their own link');

list(, $again) = Azure_Volunteer_Signup::compose_volunteer_message('volunteer_reminder', 'Jamie', $sheet, array($act), 'WatchDOGS', '', 44);
$t->check(strpos($again, $token44) !== false, 'the link is stable across emails');

list(, $confirm) = Azure_Volunteer_Signup::compose_volunteer_message('volunteer_confirmation', 'Jamie', $sheet, array($act), 'WatchDOGS', '', 44);
$t->check(strpos($confirm, 'href="' . $href . '"') !== false, 'the confirmation email uses the same link');

list(, $anon) = Azure_Volunteer_Signup::compose_volunteer_message('volunteer_reminder', 'Guest', $sheet, array($act), 'WatchDOGS', '', 0);
$t->check(strpos($anon, '>Subscribe</a>') === false, 'no button without a recipient');

$feed = Azure_Volunteer_Signup::calendar_feed_url(44);
$t->equals('https://example.test/?pta_volunteer_ics=' . $token44, $feed, 'the feed URL is https');
$t->equals(44, Azure_Volunteer_Signup::user_id_for_calendar_token($token44), 'the subscribe token resolves to its owner');
$t->equals(0, Azure_Volunteer_Signup::user_id_for_calendar_token('not-a-token'), 'a malformed token resolves to nobody');

$opts = Azure_Volunteer_Signup::subscribe_options($feed, 'Test Site volunteering');
$urls = array_column($opts, 'url');
$t->equals('webcal://example.test/?pta_volunteer_ics=' . $token44, $urls[0], 'Apple and Outlook apps get the webcal feed');
$t->equals('https://calendar.google.com/calendar/render?cid=' . rawurlencode('webcal://example.test/?pta_volunteer_ics=' . $token44), $urls[1], 'Google adds the feed by URL');
$t->check(strpos($urls[2], 'https://outlook.live.com/calendar/0/addfromweb?url=' . rawurlencode($feed)) === 0, 'Outlook.com adds the feed by URL');
$t->check(strpos($urls[3], 'https://outlook.office.com/calendar/0/addfromweb?url=' . rawurlencode($feed)) === 0, 'Microsoft 365 adds the feed by URL');
$t->equals(array(), Azure_Volunteer_Signup::subscribe_options('', 'x'), 'no options without a feed');

$page = Azure_Volunteer_Signup::subscribe_page_html($feed, 'Test Site');
$t->check(strpos($page, 'href="webcal://example.test/?pta_volunteer_ics=' . $token44 . '"') !== false, 'the page links the webcal feed');
$t->check(strpos($page, 'href="https://calendar.google.com/calendar/render?cid=') !== false, 'the page links Google Calendar');
$t->check(strpos($page, 'value="' . $feed . '"') !== false, 'the page shows the feed to copy');
$t->check(strpos($page, 'noindex') !== false, 'the private page is not indexed');
$t->check(strpos(Azure_Volunteer_Signup::subscribe_page_html($feed, '<b>x</b>'), '<b>x</b>') === false, 'the site name is escaped');

$src = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-volunteer-signup.php');
$t->check(strpos($src, "add_action('template_redirect', array(\$this, 'maybe_serve_subscribe_page')") !== false, 'the subscribe page is served on template_redirect');

exit($t->finish() === 0 ? 0 : 1);
