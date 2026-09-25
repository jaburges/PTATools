<?php
/**
 * Volunteer list grouping, signup email, and the two-hour reminder window.
 *
 * Run: php tests/test-volunteer-notice.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-volunteer-signup.php';

$t = new TestRunner('Volunteer notices and series list');

$single = (object) array('id' => 1, 'title' => 'Book Fair', 'is_template' => 0, 'template_id' => 0, 'event_date' => '2026-10-03 09:00:00');
$template = (object) array('id' => 10, 'title' => 'Math Adventures', 'is_template' => 1, 'template_id' => 0, 'event_date' => '');
$later = (object) array('id' => 12, 'title' => 'Math Adventures', 'is_template' => 0, 'template_id' => 10, 'event_date' => '2026-10-08 13:35:00');
$sooner = (object) array('id' => 11, 'title' => 'Math Adventures', 'is_template' => 0, 'template_id' => 10, 'event_date' => '2026-10-01 13:35:00');

$groups = Azure_Volunteer_Signup::group_sheets_for_list(array($later, $single, $template, $sooner));
$t->equals(2, count($groups), 'a series and a single event are two rows');
$t->equals('series', $groups[0]['kind'], 'the earlier series sorts first');
$t->equals(10, (int) $groups[0]['template']->id, 'series keeps its template');
$t->equals(array(11, 12), array((int) $groups[0]['sheets'][0]->id, (int) $groups[0]['sheets'][1]->id), 'series events are ordered by date');
$t->equals('single', $groups[1]['kind'], 'a one-off sheet stays on its own');
$t->equals(1, (int) $groups[1]['sheets'][0]->id, 'the single sheet is Book Fair');

$orphan = (object) array('id' => 20, 'title' => 'Art', 'is_template' => 0, 'template_id' => 99, 'event_date' => '2026-11-01 10:00:00');
$orphans = Azure_Volunteer_Signup::group_sheets_for_list(array($orphan));
$t->equals('series', $orphans[0]['kind'], 'an instance whose template is missing is still a series');
$t->equals(null, $orphans[0]['template'], 'missing template is not invented');

$sheet = (object) array(
    'title' => 'Math Adventures sheet',
    'event_location' => 'Room 12',
    'event_date' => '2026-10-01 13:35:00',
);
$act = (object) array(
    'name' => 'Room helper',
    'slot_start' => '2026-10-01 13:35:00',
    'slot_end' => '2026-10-01 14:10:00',
);
$body = Azure_Volunteer_Signup::volunteer_notice(
    'Jamie',
    'Thank you for volunteering!',
    $sheet,
    array($act),
    'Congdon - Math Adventures',
    'https://wilderptsa.net/event/congdon-math-adventures/'
);
$t->check(strpos($body, 'Congdon - Math Adventures') !== false, 'email names the event');
$t->check(strpos($body, 'Room helper — October 1, 2026, 1:35 PM – 2:10 PM Pacific Time') !== false, 'email includes the activity, date, and time');
$t->check(strpos($body, 'Location: Room 12') !== false, 'email includes the location');
$t->check(strpos($body, 'https://wilderptsa.net/event/congdon-math-adventures/') !== false, 'email links the event page');
$t->check(strpos($body, 'background:#f6f6f6') !== false, 'volunteer email uses the account email card');
$t->check(strpos($body, 'calendar invite') === false, 'the email no longer mentions an attached invite');
$t->equals('Test Site volunteering reminder', Azure_Volunteer_Signup::reminder_subject(), 'reminder subject uses the site name');
$t->equals(7200, Azure_Volunteer_Signup::reminder_lead_seconds(), 'default reminder lead is two hours');
$t->equals(1, count(Azure_Volunteer_Signup::reminder_schedule()), 'the default schedule is a single reminder');

$start = '2026-10-01 15:35:00';
$start_ts = Azure_Volunteer_Signup::slot_start_timestamp($start);
$t->check($start_ts > 0, 'slot start parses as Pacific');
$t->check(Azure_Volunteer_Signup::reminder_is_due($start, $start_ts - (int) (1.5 * 3600)), 'a shift inside the two-hour lead is due');
$t->check(!Azure_Volunteer_Signup::reminder_is_due($start, $start_ts - (int) (2.5 * 3600)), 'a shift beyond the lead waits for a later sweep');
$t->check(!Azure_Volunteer_Signup::reminder_is_due($start, $start_ts + 600), 'a shift that already started is not reminded');

$two = Azure_Volunteer_Signup::normalize_reminder_schedule(array(
    array('amount' => 2, 'unit' => 'days'),
    array('amount' => 2, 'unit' => 'hours'),
    array('amount' => 2, 'unit' => 'hours'),
));
$t->equals(2, count($two), 'a repeated lead is stored once');
$t->equals(2 * 86400, Azure_Volunteer_Signup::reminder_due_seconds($start, $start_ts - (36 * 3600), $two), 'a day and a half out sends the 2-day reminder');
$t->equals(2 * 3600, Azure_Volunteer_Signup::reminder_due_seconds($start, $start_ts - (int) (1.5 * 3600), $two), 'inside two hours sends the 2-hour reminder');
$t->equals(0, Azure_Volunteer_Signup::reminder_due_seconds($start, $start_ts - (3 * 86400), $two), 'before the farthest reminder nothing is due');
$t->equals(0, Azure_Volunteer_Signup::reminder_due_seconds($start, $start_ts + 600, $two), 'a started shift sends neither reminder');

$already = (object) array('reminder_sent' => 1, 'reminders_sent' => '');
$sent = Azure_Volunteer_Signup::reminder_sent_keys($already);
$t->check(isset($sent[7200]), 'the old sent flag counts as the original two-hour reminder');
$t->check(!isset($sent[2 * 86400]), 'the old sent flag does not block a newly added reminder');
$later_sent = Azure_Volunteer_Signup::reminder_sent_keys((object) array('reminder_sent' => 1, 'reminders_sent' => '172800,7200'));
$t->check(isset($later_sent[172800]) && isset($later_sent[7200]), 'each mailed lead is recorded on the signup');

$page = file_get_contents(dirname(__DIR__) . '/Azure Plugin/admin/volunteer-page.php');
$t->check(strpos($page, 'azure-vs-series-toggle') !== false, 'series rows have an expand control');
$t->check(strpos($page, "aria-expanded=\"false\"") !== false, 'series start collapsed');
$t->check(strpos($page, 'azure-vs-series-child') !== false, 'events in a series are child rows');
$t->check(strpos($page, 'Send email reminder to volunteers') !== false, 'reminder setting is on the volunteer page');
$t->check(strpos($page, 'volunteer_reminder_unit') !== false, 'reminder lead can be hours or days');
$t->check(strpos($page, 'Add reminder') !== false, 'more than one reminder can be added');
$t->check(strpos($page, 'volunteer_reminder_amount[]') !== false, 'reminder leads post as a list');

$signup = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-volunteer-signup.php');
$t->check(strpos($signup, 'write_ics_attachments') === false, 'confirmation mail does not attach an ics file');
$t->check(strpos($signup, 'Content-Type: text/html; charset=UTF-8') !== false, 'volunteer mail is sent as HTML');
$t->equals('html', Azure_Email_Messages::catalog()['volunteer_confirmation']['format'], 'the volunteer message is an HTML email');

$emails = file_get_contents(dirname(__DIR__) . '/Azure Plugin/admin/emails-page.php');
$t->check(strpos($emails, 'email-messages-page.php') !== false, 'volunteer emails are edited on the messages tab');
$catalog = Azure_Email_Messages::catalog();
foreach (array('membership_guest_account', 'parent_welcome', 'parent_activation', 'office365_welcome', 'backup_notification') as $key) {
    $t->check(isset($catalog[$key]), 'messages list includes ' . $key);
}

$cron = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-pta-cron.php');
$t->check(strpos($cron, "ensure_schedule('azure_volunteer_send_reminders', 'hourly')") !== false, 'reminders stay on one hourly sweep');

exit($t->finish() === 0 ? 0 : 1);
