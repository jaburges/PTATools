<?php
/**
 * Shared feeds: [pta-shared-events] / [pta-shared-stories] and the
 * /pta-tools/v1/feed endpoints' parsing, fallback and rendering helpers.
 *
 * Run: php tests/test-shared-feeds.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}
if (!defined('AZURE_PLUGIN_URL')) {
    define('AZURE_PLUGIN_URL', 'https://example.test/wp-content/plugins/Azure Plugin/');
}

$GLOBALS['pta_test_shortcodes'] = array();
function add_shortcode($tag, $cb) { $GLOBALS['pta_test_shortcodes'][$tag] = $cb; }
function shortcode_atts($pairs, $atts, $shortcode = '') {
    $out = array();
    foreach ($pairs as $k => $v) {
        $out[$k] = array_key_exists($k, (array) $atts) ? $atts[$k] : $v;
    }
    return $out;
}
function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES, 'UTF-8'); }
function current_user_can($cap) { return !empty($GLOBALS['pta_test_editor']); }
function wp_enqueue_style() { $GLOBALS['pta_test_styles'] = true; }

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-shared-feeds.php';

$t = new TestRunner('Shared feeds');
WP_Shim::reset();
WP_Shim::$timezone = 'America/Los_Angeles';
$tz = new DateTimeZone('America/Los_Angeles');

// --- source validation -----------------------------------------------------
$t->equals('https://lwptsa.net', Azure_Shared_Feeds::normalize_source('https://lwptsa.net/'), 'trailing slash dropped');
$t->equals('https://lwptsa.net', Azure_Shared_Feeds::normalize_source('lwptsa.net'), 'bare host gets https');
$t->equals('https://lwptsa.net', Azure_Shared_Feeds::normalize_source('https://LWPTSA.net/events/?x=1'), 'path and query ignored, host lowercased');
$t->equals(null, Azure_Shared_Feeds::normalize_source('http://lwptsa.net'), 'plain http refused');
$t->equals(null, Azure_Shared_Feeds::normalize_source('https://user:pw@lwptsa.net'), 'credentials refused');
$t->equals(null, Azure_Shared_Feeds::normalize_source('https://localhost'), 'host without a domain refused');
$t->equals(null, Azure_Shared_Feeds::normalize_source('https://169.254.169.254'), 'IP address refused');
$t->equals(null, Azure_Shared_Feeds::normalize_source(''), 'empty refused');
$t->equals(null, Azure_Shared_Feeds::normalize_source('javascript:alert(1)'), 'non-http scheme refused');

// --- small helpers ---------------------------------------------------------
$t->equals(array('math-adventures', 'art-calendar'), Azure_Shared_Feeds::csv_slugs('Math Adventures, art-calendar, ,Math Adventures'), 'names and slugs become unique slugs');
$t->equals(10, Azure_Shared_Feeds::clamp_int('', 10, 1, 50), 'blank uses default');
$t->equals(50, Azure_Shared_Feeds::clamp_int('999', 10, 1, 50), 'clamped to max');
$t->equals(1, Azure_Shared_Feeds::clamp_int('-3', 10, 1, 50), 'clamped to min');
$t->equals(null, Azure_Shared_Feeds::parse_ymd('2026-02-30', $tz), 'impossible date rejected');
$t->equals('2026-10-04', Azure_Shared_Feeds::parse_ymd('2026-10-04', $tz)->format('Y-m-d'), 'Y-m-d accepted');
$t->equals('2026-10-07T18:30:00-07:00', Azure_Shared_Feeds::local_to_iso('2026-10-07 18:30:00', $tz), 'local time carries the site offset');
$t->equals('2026-10-07', Azure_Shared_Feeds::local_to_iso('2026-10-07 00:00:00', $tz, true), 'all-day keeps the date only');

// --- ICS parsing -----------------------------------------------------------
$ics = implode("\r\n", array(
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'X-WR-CALNAME:Lake Washington PTSA Council Events',
    'BEGIN:VEVENT',
    'UID:pta-event-1@lwptsa.net',
    'DTSTART:20261008T013000Z',
    'DTEND:20261008T030000Z',
    'SUMMARY:General Meeting\, October',
    'DESCRIPTION:Agenda:\nBudget',
    'LOCATION:LWSD Resource Center',
    'CATEGORIES:LWPTSA,Advocacy',
    'URL:https://lwptsa.net/event/general-meeting/',
    'END:VEVENT',
    'BEGIN:VEVENT',
    'UID:pta-event-2@lwptsa.net',
    'DTSTART;VALUE=DATE:20261012',
    'DTEND;VALUE=DATE:20261014',
    'SUMMARY:Mid-term break',
    'CATEGORIES:District',
    'URL:https://lwptsa.net/event/break/',
    'END:VEVENT',
    'BEGIN:VEVENT',
    'DTSTART;TZID=America/New_York:20261020T120000',
    'SUMMARY:Long title that is folded',
    '  across two lines',
    'CATEGORIES:WSPTA',
    'END:VEVENT',
    'BEGIN:VEVENT',
    'DTSTART:20250101T100000Z',
    'SUMMARY:Last year',
    'END:VEVENT',
    'END:VCALENDAR',
));
$parsed = Azure_Shared_Feeds::parse_ics($ics, $tz);
$t->equals('Lake Washington PTSA Council Events', $parsed['name'], 'calendar name read');
$t->equals(4, count($parsed['events']), 'four events read');
$e0 = $parsed['events'][0];
$t->equals('General Meeting, October', $e0['title'], 'escaped comma unescaped');
$t->equals("Agenda:\nBudget", $e0['excerpt'], 'escaped newline unescaped');
$t->equals('2026-10-07T18:30:00-07:00', $e0['start'], 'UTC start converted to site time');
$t->equals(array('LWPTSA', 'Advocacy'), $e0['categories'], 'categories split');
$t->equals(false, $e0['all_day'], 'timed event is not all-day');
$e1 = $parsed['events'][1];
$t->equals(true, $e1['all_day'], 'VALUE=DATE is all-day');
$t->equals('2026-10-12', $e1['start'], 'all-day start date');
$t->equals('2026-10-13', $e1['end'], 'exclusive all-day DTEND returned inclusive');
$e2 = $parsed['events'][2];
$t->equals('Long title that is folded across two lines', $e2['title'], 'folded lines unfolded');
$t->equals('2026-10-20T09:00:00-07:00', $e2['start'], 'TZID start converted to site time');

$from = new DateTime('2026-10-04', $tz);
$to   = new DateTime('2026-11-30', $tz);
$f = Azure_Shared_Feeds::filter_events($parsed['events'], $from, $to, array(), array(), 10);
$t->equals(3, count($f), 'past event dropped by window');
$f = Azure_Shared_Feeds::filter_events($parsed['events'], $from, $to, array('advocacy'), array(), 10);
$t->equals(array('General Meeting, October'), array_column($f, 'title'), 'category include by slug');
$f = Azure_Shared_Feeds::filter_events($parsed['events'], $from, $to, array(), array('district', 'wspta'), 10);
$t->equals(array('General Meeting, October'), array_column($f, 'title'), 'category exclude');
$f = Azure_Shared_Feeds::filter_events($parsed['events'], $from, $to, array(), array(), 1);
$t->equals(1, count($f), 'limit applied');
$f = Azure_Shared_Feeds::filter_events($parsed['events'], new DateTime('2026-10-13', $tz), $to, array('district'), array(), 10);
$t->equals(array('Mid-term break'), array_column($f, 'title'), 'multi-day event still listed on its last day');

// --- WP REST post mapping --------------------------------------------------
$story = Azure_Shared_Feeds::wp_post_to_story(array(
    'id' => 5,
    'title' => array('rendered' => 'Levy &#8211; vote <b>yes</b>'),
    'link' => 'https://lwptsa.net/levy/',
    'date_gmt' => '2026-09-30T17:00:00',
    'excerpt' => array('rendered' => '<p>Ballots are out.</p>'),
    '_embedded' => array(
        'wp:featuredmedia' => array(array('source_url' => 'https://lwptsa.net/full.jpg', 'media_details' => array('sizes' => array('medium' => array('source_url' => 'https://lwptsa.net/m.jpg'))))),
        'wp:term' => array(array(array('taxonomy' => 'category', 'name' => 'Advocacy')), array(array('taxonomy' => 'post_tag', 'name' => 'levy'))),
    ),
));
$t->equals('Levy – vote yes', $story['title'], 'title entities decoded and tags stripped');
$t->equals('Ballots are out.', $story['excerpt'], 'excerpt reduced to text');
$t->equals('https://lwptsa.net/m.jpg', $story['image'], 'best available image size used');
$t->equals(array('Advocacy'), $story['categories'], 'only categories kept, not tags');
$t->equals('2026-09-30T17:00:00Z', $story['date'], 'GMT date marked as UTC');
$bad = Azure_Shared_Feeds::clean_story(array('title' => 'x', 'url' => 'javascript:alert(1)', 'image' => 'data:image/png;base64,AA'));
$t->equals('', $bad['url'], 'non-http link dropped');
$t->equals('', $bad['image'], 'non-http image dropped');

// --- fetch: native endpoint, fallbacks, stale copy ------------------------
$feeds = Azure_Shared_Feeds::get_instance();
$t->check(isset($GLOBALS['pta_test_shortcodes']['pta-shared-events'], $GLOBALS['pta_test_shortcodes']['pta-shared-stories']), 'both shortcodes registered');

WP_Shim::on_request('/wp-json/pta-tools/v1/feed/events', 200, array(
    'source' => array('name' => 'LWPTSA', 'url' => 'https://lwptsa.net/'),
    'events' => array(array('title' => 'Council <script>', 'url' => 'https://lwptsa.net/e/1/', 'start' => '2026-10-07T18:30:00-07:00', 'end' => '', 'all_day' => false, 'venue' => 'RC', 'excerpt' => '', 'image' => '', 'categories' => array('LWPTSA'))),
));
$r = $feeds->fetch_feed('events', 'https://lwptsa.net', array('category' => '', 'exclude_category' => '', 'calendar' => '', 'days' => 60, 'limit' => 10));
$t->equals('LWPTSA', $r['source']['name'], 'native feed: source name');
$t->equals('Council', $r['items'][0]['title'], 'native feed: tags stripped from remote title');
$calls = count(WP_Shim::$http_calls);
$feeds->fetch_feed('events', 'https://lwptsa.net', array('category' => '', 'exclude_category' => '', 'calendar' => '', 'days' => 60, 'limit' => 10));
$t->equals($calls, count(WP_Shim::$http_calls), 'second fetch served from cache');

WP_Shim::reset();
WP_Shim::$timezone = 'America/Los_Angeles';
WP_Shim::on_request('/feed/events', 404, array('code' => 'rest_no_route'));
$future = (new DateTime('today', $tz))->modify('+3 days')->format('Ymd');
WP_Shim::on_request('pta_ical_feed=1', 200, "BEGIN:VCALENDAR\r\nX-WR-CALNAME:LWPTSA Events\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:$future\r\nSUMMARY:Board retreat\r\nCATEGORIES:LWPTSA\r\nURL:https://lwptsa.net/e/2/\r\nEND:VEVENT\r\nEND:VCALENDAR");
$r = $feeds->fetch_feed('events', 'https://lwptsa.net', array('category' => 'lwptsa', 'exclude_category' => '', 'calendar' => 'cal-1', 'days' => 30, 'limit' => 5));
$t->equals(array('Board retreat'), array_column($r['items'], 'title'), 'older source: events read from .ics feed');
$t->equals('LWPTSA', $r['source']['name'], 'older source: name taken from X-WR-CALNAME');
$t->check(strpos(WP_Shim::$http_calls[1]['url'], 'calendar_id=cal-1') !== false, 'calendar id passed to .ics feed');
$t->check(strpos(WP_Shim::$http_calls[0]['url'], 'category=lwptsa') !== false, 'category passed to native endpoint');

WP_Shim::reset();
WP_Shim::on_request('/feed/stories', 404, array('code' => 'rest_no_route'));
WP_Shim::on_request('/wp/v2/categories', 200, array(array('id' => 12, 'slug' => 'advocacy')));
WP_Shim::on_request('/wp/v2/posts', 200, array(array('id' => 9, 'title' => array('rendered' => 'Story'), 'link' => 'https://lwptsa.net/s/', 'date_gmt' => '2026-10-01T00:00:00', 'excerpt' => array('rendered' => ''))));
WP_Shim::on_request('/wp-json/?_fields=name', 200, array('name' => 'Council &amp; Friends'));
$r = $feeds->fetch_feed('stories', 'https://lwptsa.net', array('category' => 'advocacy', 'exclude_category' => '', 'limit' => 3));
$t->equals(array('Story'), array_column($r['items'], 'title'), 'older source: stories read from /wp/v2/posts');
$posts_call = current(array_filter(WP_Shim::$http_calls, function ($c) { return strpos($c['url'], '/wp/v2/posts') !== false; }));
$t->check(strpos($posts_call['url'], 'categories=12') !== false && strpos($posts_call['url'], 'per_page=3') !== false, 'category slug resolved to id and limit passed');
$t->equals('Council & Friends', $r['source']['name'], 'older source: site name read from the REST index');

WP_Shim::reset();
WP_Shim::on_request('/feed/stories', 404, array());
WP_Shim::on_request('/wp/v2/categories', 200, array());
$r = $feeds->fetch_feed('stories', 'https://lwptsa.net', array('category' => 'nope', 'exclude_category' => '', 'limit' => 3));
$t->equals(array(), $r['items'], 'unknown category on the source shows nothing rather than everything');

WP_Shim::reset();
$q = array('category' => '', 'exclude_category' => '', 'limit' => 3);
WP_Shim::on_request('/feed/stories', 200, array('source' => array('name' => 'LWPTSA'), 'stories' => array(array('title' => 'Kept'))));
$feeds->fetch_feed('stories', 'https://lwptsa.net', $q);
foreach (array_keys(WP_Shim::$transients) as $k) {
    if (strpos($k, 'pta_sf_c_') === 0) {
        delete_transient($k);
    }
}
WP_Shim::$http_responses = array();
$r = $feeds->fetch_feed('stories', 'https://lwptsa.net', $q);
$t->check(!is_wp_error($r) && $r['items'][0]['title'] === 'Kept', 'source down: last good copy shown');

WP_Shim::reset();
$r = $feeds->fetch_feed('stories', 'https://lwptsa.net', $q);
$t->check(is_wp_error($r), 'source down with no copy: error returned');

// --- shortcodes and rendering ---------------------------------------------
WP_Shim::reset();
WP_Shim::$timezone = 'America/Los_Angeles';
$GLOBALS['pta_test_editor'] = false;
$t->equals('', $feeds->render_stories_shortcode(array('source' => 'http://bad')), 'visitors see nothing for a bad source');
$GLOBALS['pta_test_editor'] = true;
$t->check(strpos($feeds->render_stories_shortcode(array('source' => '')), 'needs source=') !== false, 'editors see what is wrong');
$t->check(strpos($feeds->render_events_shortcode(array('source' => 'https://down.example.org')), 'Could not load events') !== false, 'editors see fetch errors');

$html = Azure_Shared_Feeds::render_events(
    array(Azure_Shared_Feeds::clean_event(array('title' => 'A & B', 'url' => 'https://lwptsa.net/e/"x', 'start' => '2026-10-07T18:30:00-07:00', 'venue' => 'Library', 'categories' => array()))),
    array('name' => 'LWPTSA', 'url' => 'https://lwptsa.net/'),
    array('layout' => 'list', 'title' => 'Council events', 'empty' => 'none', 'show_source' => 'true')
);
$t->check(strpos($html, 'A &amp; B') !== false, 'event title escaped');
$t->check(strpos($html, '&quot;x') !== false, 'event link attribute escaped');
$t->check(strpos($html, 'Wed, Oct 7 · 6:30 pm · Library') !== false, 'date, time and venue shown in site time');
$t->check(strpos($html, 'From <a href="https://lwptsa.net/">LWPTSA</a>') !== false, 'source attribution links back');
$t->check(strpos($html, '<h3 class="pta-shared-feed-title">Council events</h3>') !== false, 'title shown');

$html = Azure_Shared_Feeds::render_events(
    array(Azure_Shared_Feeds::clean_event(array('title' => 'Break', 'start' => '2026-10-12', 'end' => '2026-10-13', 'all_day' => true))),
    array('name' => 'LWPTSA', 'url' => 'https://lwptsa.net/'),
    array('layout' => 'list', 'title' => '', 'empty' => 'none', 'show_source' => 'false')
);
$t->check(strpos($html, 'Mon, Oct 12 – Tue, Oct 13') !== false, 'multi-day all-day range shown');
$t->check(strpos($html, 'pta-shared-feed-source') === false, 'show_source="false" hides attribution');

$html = Azure_Shared_Feeds::render_stories(array(), array('name' => 'x', 'url' => 'https://x.org/'), array('layout' => 'cards', 'title' => '', 'empty' => 'Nothing yet', 'show_excerpt' => 'true', 'show_source' => 'true'));
$t->check(strpos($html, 'Nothing yet') !== false, 'empty message shown');

$html = Azure_Shared_Feeds::render_stories(
    array(Azure_Shared_Feeds::clean_story(array('title' => 'Levy', 'url' => 'https://lwptsa.net/levy/', 'date' => '2026-09-30T17:00:00Z', 'excerpt' => str_repeat('word ', 40), 'image' => 'https://lwptsa.net/m.jpg'))),
    array('name' => 'LWPTSA', 'url' => 'https://lwptsa.net/'),
    array('layout' => 'cards', 'title' => '', 'empty' => '', 'show_excerpt' => 'true', 'show_source' => 'true')
);
$t->check(strpos($html, 'src="https://lwptsa.net/m.jpg"') !== false, 'card image shown');
$t->check(strpos($html, 'Sep 30, 2026') !== false, 'story date in site time');
$t->check(substr_count($html, 'word') === 30 && strpos($html, '…') !== false, 'excerpt trimmed to 30 words');

$main = file_get_contents(dirname(__DIR__) . '/Azure Plugin/azure-plugin.php');
$t->check(strpos($main, "includes/class-shared-feeds.php") !== false, 'loaded from the main plugin file');

exit($t->finish() > 0 ? 1 : 0);
