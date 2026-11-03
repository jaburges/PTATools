<?php
/**
 * Printed Calendars: grade parsing, time spans, month rows, request
 * validation, the poster HTML and its QR code.
 *
 * Run: php tests/test-printed-calendar.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
if (!function_exists('absint')) {
    function absint($n) { return abs((int) $n); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($u) { return preg_match('#^https?://#', $u) ? $u : ''; }
}
if (!function_exists('esc_url')) {
    function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_html__')) {
    function esc_html__($t, $d = null) { return esc_html($t); }
}
if (!function_exists('esc_html_e')) {
    function esc_html_e($t, $d = null) { echo esc_html($t); }
}
if (!function_exists('_n')) {
    function _n($one, $many, $n, $d = null) { return $n === 1 ? $one : $many; }
}
if (!function_exists('untrailingslashit')) {
    function untrailingslashit($s) { return rtrim((string) $s, '/\\'); }
}
function wp_attachment_is_image($id) { return in_array((int) $id, array(7, 8), true); }

require_once AZURE_PLUGIN_PATH . 'includes/class-printed-calendar.php';

$t = new TestRunner('Printed calendars');

// ── Grades ──
$cases = array(
    '4th- Harewood'              => array('4th', '#5b5fe0', 'Harewood'),
    '4/5 Smith'                  => array('4/5', '#8e44ad', 'Smith'),
    'Kindergarten Art - Jones'   => array('K', '#e8505b', 'Jones'),
    'K - Lee'                    => array('K', '#e8505b', 'Lee'),
    '1st Grade Art: Patel'       => array('1st', '#f59f00', 'Patel'),
    'Grade 3 Wu'                 => array('3rd', '#1c9bd6', 'Wu'),
    '5th'                        => array('5th', '#d63384', '5th'),
    'Docent training'            => array('', '#1f4fa8', 'Docent training'),
    'Kites in the gym'           => array('', '#1f4fa8', 'Kites in the gym'),
    '40th anniversary'           => array('', '#1f4fa8', '40th anniversary'),
);
foreach ($cases as $title => $want) {
    $t->equals($want, Azure_Printed_Calendar::grade_of($title), 'grade_of: ' . $title);
}

// ── Times ──
$t->equals('10:00 am – 1:00 pm', Azure_Printed_Calendar::time_span('2026-11-02 10:00:00', '2026-11-02 13:00:00'), 'time span across noon');
$t->equals('12:30 – 2:30 pm', Azure_Printed_Calendar::time_span('2026-11-02 12:30:00', '2026-11-02 14:30:00'), 'same half of the day drops the first am/pm');
$t->equals('9:15 am', Azure_Printed_Calendar::time_span('2026-11-02 09:15:00', ''), 'no end time');
$t->equals('9:15 am', Azure_Printed_Calendar::time_span('2026-11-02 09:15:00', '2026-11-02 09:15:00'), 'zero-length event');

// ── Month rows ──
$weekdays = Azure_Printed_Calendar::weeks(2026, 11, 'weekdays');
$t->equals('2026-11-02', $weekdays[0][0], 'Nov 2026 Mon–Fri starts on Monday the 2nd (Sunday 1st is not shown)');
$t->equals(5, count($weekdays), 'Nov 2026 has 5 weekday rows');
$t->equals('2026-12-04', end($weekdays)[4], 'last row runs into December');
$t->check(count(array_filter($weekdays, function ($r) { return count($r) !== 5; })) === 0, 'weekday rows have 5 days');

$full = Azure_Printed_Calendar::weeks(2026, 11, 'week');
$t->equals('2026-11-01', $full[0][0], 'Nov 2026 full week starts Sunday the 1st');
$t->equals(5, count($full), 'Nov 2026 has 5 full-week rows');
$t->equals(7, count($full[0]), 'full-week rows have 7 days');

$aug = Azure_Printed_Calendar::weeks(2026, 8, 'weekdays');
$t->equals('2026-08-03', $aug[0][0], 'Aug 2026 (starts Saturday) begins with the first weekday row');

// ── Multi-day all-day events ──
$by_day = Azure_Printed_Calendar::group_by_day(array(
    array('title' => 'Break', 'start' => '2026-11-25 00:00:00', 'end' => '2026-11-28 00:00:00', 'all_day' => true, 'location' => ''),
    array('title' => '2nd Ng', 'start' => '2026-11-25 13:00:00', 'end' => '2026-11-25 14:00:00', 'all_day' => false, 'location' => ''),
));
$t->equals(array('2026-11-25', '2026-11-26', '2026-11-27'), array_keys($by_day), 'all-day event ending at midnight covers its days, not the next one');
$t->equals('Break', $by_day['2026-11-25'][0]['title'], 'all-day events sort first');

// ── Request validation ──
$today = new DateTimeImmutable('2026-11-02 09:00:00');
$args = Azure_Printed_Calendar::request_args(array(), $today);
$t->equals(array(2026, 11, 'weekdays', 'a3', true, true), array($args['year'], $args['month'], $args['days'], $args['paper'], $args['header'], $args['qr']), 'defaults: this month, Mon–Fri, A3, picture and QR on');
$args = Azure_Printed_Calendar::request_args(array('month' => '2027-02', 'days' => 'week', 'paper' => 'letter', 'header' => '0', 'qr' => '0', 'calendar' => 'AAMk<b>x'), $today);
$t->equals(array(2027, 2, 'week', 'letter', false, false, 'AAMkx'), array($args['year'], $args['month'], $args['days'], $args['paper'], $args['header'], $args['qr'], $args['calendar']), 'explicit options are kept');
$args = Azure_Printed_Calendar::request_args(array('month' => '2027-13', 'days' => 'x', 'paper' => array('a3')), $today);
$t->equals(array(2026, 11, 'weekdays', 'a3'), array($args['year'], $args['month'], $args['days'], $args['paper']), 'bad month, days and paper fall back to defaults');

$clean = Azure_Printed_Calendar::sanitize_settings(array('header_id' => '9', 'kicker' => ' Art <i>Docents</i> ', 'link' => 'javascript:alert(1)', 'extra' => 'x'));
$t->equals(0, $clean['header_id'], 'header must be an image attachment');
$t->equals('Art Docents', $clean['kicker'], 'poster text is sanitised');
$t->equals('', $clean['link'], 'non-http links are dropped');
$t->check(!isset($clean['extra']), 'unknown settings are dropped');

// ── QR ──
$svg = Azure_Printed_Calendar::qr_svg('https://wilderptsa.net/art-calendar/');
$t->check(strpos($svg, '<svg') === 0 && strpos($svg, '<path') !== false, 'QR code renders as SVG');
$t->equals('', Azure_Printed_Calendar::qr_svg(''), 'no link, no QR');

// ── Poster ──
$settings = array_merge(Azure_Printed_Calendar::default_settings(), array(
    'kicker' => 'Wilder Elementary · Art Docents',
    'subtitle' => 'Lessons are in the Art Room unless noted.',
    'usual_location' => 'Art Room',
    'footer' => 'Thank you, Art Docents!',
));
$context = array(
    'title' => 'Art Calendar',
    'logo' => 'https://example.test/pta-icon-512.png',
    'header_url' => 'https://example.test/wp-content/uploads/header.jpg',
    'link' => 'https://wilderptsa.net/art-calendar/',
    'printed' => '2 November 2026',
    'site' => 'Wilder PTSA',
    'autoprint' => false,
);
$events = array(
    array('title' => '4th- Harewood', 'start' => '2026-11-03 10:00:00', 'end' => '2026-11-03 11:00:00', 'all_day' => false, 'location' => 'Art Room'),
    array('title' => 'K - Lee', 'start' => '2026-11-03 13:00:00', 'end' => '2026-11-03 14:00:00', 'all_day' => false, 'location' => 'Library'),
    array('title' => '2nd <script>x</script>', 'start' => '2026-11-10 09:00:00', 'end' => '2026-11-10 10:00:00', 'all_day' => false, 'location' => ''),
    array('title' => 'Weekend workshop', 'start' => '2026-11-07 10:00:00', 'end' => '2026-11-07 12:00:00', 'all_day' => false, 'location' => ''),
);
$base = Azure_Printed_Calendar::request_args(array(), $today);
$html = Azure_Printed_Calendar::poster_html($base, $settings, $context, $events);

$t->check(strpos($html, '@page { size: 420mm 297mm') !== false, 'A3 page size');
$t->check(strpos($html, 'data-events="3"') !== false, 'weekday events counted; the Saturday one is not shown');
$t->check(strpos($html, '1 weekend event is not shown.') !== false, 'hidden weekend events are mentioned');
$t->check(strpos($html, '<span class="chip">4th</span><span class="who">Harewood</span>') !== false, 'grade chip and name');
$t->check(strpos($html, 'Library') !== false && substr_count($html, '<span class="where">') === 1, 'only unusual locations are shown');
$t->check(strpos($html, '<script>x</script>') === false && strpos($html, '&lt;script&gt;x&lt;/script&gt;') !== false, 'titles are escaped');
$t->check(strpos($html, 'Grades') !== false && strpos($html, 'Kindergarten') !== false && strpos($html, '4th grade') !== false, 'legend lists the grades used');
$t->check(strpos($html, '1st grade') === false, 'legend leaves out grades with no lessons');
$t->check(strpos($html, 'wilderptsa.net/art-calendar</b>') !== false, 'QR caption shows the short link');
$t->check(strpos($html, 'class="pic" src="https://example.test/wp-content/uploads/header.jpg"') !== false, 'header picture');
$t->check(strpos($html, 'Wilder Elementary · Art Docents') !== false && strpos($html, 'Thank you, Art Docents!') !== false, 'poster text');
$t->check(strpos($html, 'window.print()') === false, 'no auto-print in the preview');

$letter = Azure_Printed_Calendar::poster_html(array_merge($base, array('paper' => 'letter', 'days' => 'week')), $settings, array_merge($context, array('header_url' => '', 'link' => '', 'autoprint' => true)), $events);
$t->check(strpos($letter, '@page { size: 279.4mm 215.9mm') !== false, 'Letter page size');
$t->check(strpos($letter, 'zoom: 0.6652') !== false, 'Letter zooms the A3 layout down to fit');
$t->check(strpos($letter, 'data-events="4"') !== false && strpos($letter, 'weekend event') === false, 'full week shows the Saturday event');
$t->check(strpos($letter, 'repeat(7,') !== false, 'full week has 7 columns');
$t->check(strpos($letter, 'hero plain') !== false && strpos($letter, 'class="pic"') === false, 'no picture, plain header');
$t->check(strpos($letter, 'class="qr"') === false, 'no link, no QR');
$t->check(strpos($letter, 'window.print()') !== false, 'auto-print when opened to print');

$empty = Azure_Printed_Calendar::poster_html($base, Azure_Printed_Calendar::default_settings(), $context, array());
$t->check(strpos($empty, 'data-events="0"') !== false && strpos($empty, 'class="legend"') === false, 'empty month: no legend');

exit($t->finish() === 0 ? 0 : 1);
