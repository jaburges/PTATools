<?php
/**
 * Compact Now and Next newsletter table.
 *
 * Run: php tests/test-newsletter-now-next.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-now-next.php';

$t = new TestRunner('Newsletter Now and Next');

$t->equals(5, Azure_Newsletter_Now_Next::LIMIT, 'caps each column at 5 events');
$t->equals('nl-now-next', Azure_Newsletter_Now_Next::SHORTCODE, 'shortcode tag is nl-now-next');

$events = array(
    array(
        'title'      => 'Theater parent session',
        'url'        => 'https://wilderptsa.net/theater/',
        'start_date' => '2026-09-08 08:00:00',
        'all_day'    => false,
    ),
    array(
        'title'      => 'Fall Carnival',
        'url'        => 'https://wilderptsa.net/carnival/',
        'start_date' => '2026-09-11 00:00:00',
        'all_day'    => true,
    ),
);

$line = Azure_Newsletter_Now_Next::format_line($events[0]);
$t->check(strpos($line, 'Theater parent session') !== false, 'line includes the title');
$t->check(strpos($line, 'color:#2271b1') !== false, 'title stays the newsletter blue');
$t->check(strpos($line, '<a ') === false, 'titles are not links by default');
$t->check(strpos($line, 'https://wilderptsa.net/theater/') === false, 'event URLs are omitted by default');
$t->check(strpos($line, '8:00am') !== false || strpos($line, '8:00AM') !== false, 'timed event includes the time');
$t->check(strpos($line, 'Tue') !== false || strpos($line, '9/8') !== false, 'line includes a compact date');

$linked = Azure_Newsletter_Now_Next::format_line($events[0], true);
$t->check(strpos($linked, 'https://wilderptsa.net/theater/') !== false, 'enable_links true wraps the title');
$t->check(strpos($linked, 'color:#2271b1') !== false, 'linked title stays blue');

$t->check(Azure_Newsletter_Now_Next::parse_enable_links('false') === false, 'enable_links=false is off');
$t->check(Azure_Newsletter_Now_Next::parse_enable_links('true') === true, 'enable_links=true is on');

$all_day = Azure_Newsletter_Now_Next::format_line($events[1]);
$t->check(strpos($all_day, 'Fall Carnival') !== false, 'all-day line includes the title');
$t->check(!preg_match('/\d{1,2}:\d{2}\s*[ap]m/i', $all_day), 'all-day line omits a time');

$t->equals('', Azure_Newsletter_Now_Next::format_line(array()), 'empty event is skipped');
$t->equals('', Azure_Newsletter_Now_Next::format_line(array('title' => '')), 'blank title is skipped');

$html = Azure_Newsletter_Now_Next::render($events, array(), array(
    'this_week_title' => 'This Week',
    'next_week_title' => 'Next Week',
    'empty_message'   => 'No events',
    'limit'           => 5,
));

$t->check(strpos($html, 'class="nl-now-next nl-stack-cols"') !== false, 'uses the email 2-column stack table');
$t->check(strpos($html, 'This Week') !== false, 'left column heading is This Week');
$t->check(strpos($html, 'Next Week') !== false, 'right column heading is Next Week');
$t->check(strpos($html, 'Theater parent session') !== false, 'this-week events render');
$t->check(strpos($html, 'No events') !== false, 'empty week shows a short No events line');
$t->check(strpos($html, 'upcoming-thumb') === false, 'does not emit website card thumbnails');
$t->check(strpos($html, 'up-next-theme') === false, 'does not emit website up-next theme chrome');
$t->check(strpos($html, 'width="50%"') !== false, 'columns are 50/50');
$t->check(strpos($html, '<a ') === false, 'rendered table has no event links by default');

$with_links = Azure_Newsletter_Now_Next::render($events, array(), array('enable_links' => true));
$t->check(strpos($with_links, 'https://wilderptsa.net/theater/') !== false, 'enable_links option restores title links');

$many = array();
for ($i = 1; $i <= 8; $i++) {
    $many[] = array(
        'title'      => 'Event ' . $i,
        'url'        => 'https://example.com/' . $i,
        'start_date' => '2026-09-0' . min($i, 7) . ' 09:00:00',
        'all_day'    => true,
    );
}
$capped = Azure_Newsletter_Now_Next::render($many, $many);
$t->check(strpos($capped, 'Event 5') !== false, 'fifth event is kept');
$t->check(strpos($capped, 'Event 6') === false, 'sixth event is dropped so the block stays short');

$empty = Azure_Newsletter_Now_Next::render(array(), array());
$t->check(substr_count($empty, 'No events') === 2, 'both empty columns say No events');

exit($t->finish() === 0 ? 0 : 1);
