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
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-event-cpt.php';

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

$t->check(Azure_PTSA_Meetings::is_general_title('PTSA General Meeting/Family Game Night'), 'general: with game night');
$t->check(Azure_PTSA_Meetings::is_general_title('General Membership Meeting'), 'general: membership');
$t->check(!Azure_PTSA_Meetings::is_general_title('PTSA Board Meeting'), 'board is not general');

$t->equals(2025, Azure_PTSA_Meetings::school_year_of('2026-06-11 19:00:00'), 'June belongs to the school year that started the previous fall');
$t->equals(2026, Azure_PTSA_Meetings::school_year_of('2026-07-01 00:00:00'), 'July 1 starts the new school year');
$t->equals('2025–26', Azure_PTSA_Meetings::school_year_label(2025), 'school year label');
$t->equals('1999–00', Azure_PTSA_Meetings::school_year_label(1999), 'label wraps the century');

$meetings = array(
    array('id' => 1, 'title' => 'PTSA General Meeting', 'start' => '2025-09-11 19:00:00'),
    array('id' => 2, 'title' => 'PTSA Board Meeting',   'start' => '2025-10-09 09:45:00'),
    array('id' => 3, 'title' => 'PTSA Board Meeting',   'start' => '2026-02-19 08:45:00'),
    array('id' => 4, 'title' => 'PTSA General Meeting', 'start' => '2026-06-11 19:00:00'),
    array('id' => 5, 'title' => 'PTSA Board Meeting',   'start' => '2026-09-10 08:45:00'),
    array('id' => 6, 'title' => 'PTSA General Meeting', 'start' => '2026-10-08 19:00:00'),
    array('id' => 7, 'title' => 'PTSA Board Meeting',   'start' => '2026-11-12 08:45:00'),
    array('id' => 8, 'title' => 'PTSA Board Meeting',   'start' => '2024-05-02 08:45:00'),
);
$ids = function ($rows) {
    return array_map(function ($r) { return $r['id']; }, $rows);
};

$g = Azure_PTSA_Meetings::group_meetings($meetings, '2026-10-07 20:30:00');
$t->equals(array(6, 7), $ids($g['upcoming']), 'upcoming in date order');
$t->equals(array(5), $ids($g['past']), 'past is this school year only');
$t->equals(4, $g['previous_general']['id'] ?? null, 'June General Meeting is the previous General Meeting');
$t->equals(array('2025–26', '2023–24'), array_keys($g['archive']), 'archive by school year, newest first');
$t->equals(array(3, 2, 1), $ids($g['archive']['2025–26']), 'archive leaves out the previous General Meeting, newest first');

$g = Azure_PTSA_Meetings::group_meetings($meetings, '2026-10-08 21:30:00');
$t->equals(6, $g['previous_general']['id'] ?? null, 'once October has happened it becomes the previous General Meeting');
$t->equals(array(5), $ids($g['past']), 'and is not repeated under past meetings');
$t->equals(array(4, 3, 2, 1), $ids($g['archive']['2025–26']), 'June moves into the archive');

$g = Azure_PTSA_Meetings::group_meetings(array($meetings[4]), '2026-10-07 20:30:00');
$t->equals(null, $g['previous_general'], 'no previous General Meeting when there has not been one');

$t->equals(array(12, 34), Azure_Event_CPT::normalize_attachment_ids(array('12', '34', '0', '12')), 'attachment ids unique and positive');
$t->equals(array(5, 9), Azure_Event_CPT::normalize_attachment_ids('5, 9'), 'attachment ids from csv');
$t->equals(array(), Azure_Event_CPT::normalize_attachment_ids(''), 'empty attachment ids');

$t->equals(array(36630), Azure_Event_CPT::removed_attachment_ids(array(36630), array()), 'removing the only file detaches it');
$t->equals(array(7), Azure_Event_CPT::removed_attachment_ids(array(5, 7, 9), array('9', '5', '11')), 'only the dropped file, not added ones');
$t->equals(array(), Azure_Event_CPT::removed_attachment_ids(array(), array(3)), 'adding a file removes nothing');

exit($t->finish() === 0 ? 0 : 1);
