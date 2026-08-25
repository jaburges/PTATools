<?php
/**
 * PTSA meeting title matcher and attachment link parser.
 *
 * Run: php tests/test-ptsa-meetings.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-ptsa-meetings.php';

$t = new TestRunner('PTSA meetings matcher');

$yes = array(
    'PTSA Board Meeting',
    'PTSA General Meeting',
    'PTSA General Meeting/Family Game Night',
    'PTSA Meeting',
    'PTSA Meeting 9/10',
    'General Membership Meeting',
    'General Membership',
);

$no = array(
    'WatchDogs Info Meeting',
    'Theater Parents: Required Meeting',
    'Popsicles with PTSA',
    'After & Before School Enrichment Enrollments OPEN',
    'Staff Meeting',
    'Board Retreat',
    '',
);

foreach ($yes as $title) {
    $t->check(Azure_PTSA_Meetings::is_meeting_title($title), 'match: ' . $title);
}
foreach ($no as $title) {
    $t->check(!Azure_PTSA_Meetings::is_meeting_title($title), 'skip: ' . ($title !== '' ? $title : '(empty)'));
}

$t->equals('ptsa general meeting family game night', Azure_PTSA_Meetings::normalize_title('PTSA General Meeting/Family Game Night'), 'slash becomes space');

$t->check(Azure_PTSA_Meetings::looks_like_file_url('https://example.org/wp-content/uploads/2026/09/agenda.pdf'), 'uploads pdf');
$t->check(Azure_PTSA_Meetings::looks_like_file_url('https://files.example.org/minutes.docx'), 'docx by extension');
$t->check(!Azure_PTSA_Meetings::looks_like_file_url('https://example.org/event/ptsa-board-meeting/'), 'event permalink is not a file');
$t->check(!Azure_PTSA_Meetings::looks_like_file_url('https://teams.microsoft.com/l/meetup-join/x'), 'Teams join is not a file');

$html = '<p>See the <a href="https://example.org/wp-content/uploads/2026/09/September-Agenda.pdf">September agenda</a>.</p>'
    . '<!-- wp:file {"id":99,"href":"https://example.org/wp-content/uploads/2026/09/minutes.pdf","fileName":"Board minutes"} -->'
    . '<a href="https://example.org/wp-content/uploads/2026/09/minutes.pdf">Board minutes</a>'
    . '<!-- /wp:file -->'
    . '<p><a href="https://example.org/event/ptsa-board-meeting/">Event page</a></p>';

$files = Azure_PTSA_Meetings::attachments_from_content($html);
$urls  = array_map(function ($f) { return $f['url']; }, $files);
$titles = array();
foreach ($files as $f) {
    $titles[$f['url']] = $f['title'];
}

$t->check(count($files) === 2, 'two file links, event page skipped', (string) count($files));
$t->check(in_array('https://example.org/wp-content/uploads/2026/09/September-Agenda.pdf', $urls, true), 'agenda url kept');
$t->check(in_array('https://example.org/wp-content/uploads/2026/09/minutes.pdf', $urls, true), 'minutes url kept');
$t->equals('September agenda', $titles['https://example.org/wp-content/uploads/2026/09/September-Agenda.pdf'] ?? '', 'anchor label');
$t->equals('Board minutes', $titles['https://example.org/wp-content/uploads/2026/09/minutes.pdf'] ?? '', 'file block name wins');

$t->equals(array('PTSA Meeting'), Azure_PTSA_Meetings::preserved_category_names(array('PTA Events', 'PTSA Meeting', 'Art')), 'sync keeps only the meeting tag');

exit($t->finish() === 0 ? 0 : 1);
