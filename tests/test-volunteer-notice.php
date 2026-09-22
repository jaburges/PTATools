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
$t->check(strpos($body, 'Event: Congdon - Math Adventures') !== false, 'email names the event');
$t->check(strpos($body, 'Room helper — October 1, 2026, 1:35 PM – 2:10 PM Pacific Time') !== false, 'email includes the activity, date, and time');
$t->check(strpos($body, 'Location: Room 12') !== false, 'email includes the location');
$t->check(strpos($body, 'Access Event Page: https://wilderptsa.net/event/congdon-math-adventures/') !== false, 'email links the event page');
$t->equals('Test Site volunteering reminder', Azure_Volunteer_Signup::reminder_subject(), 'reminder subject uses the site name');
$t->equals(7200, Azure_Volunteer_Signup::reminder_lead_seconds(), 'default reminder lead is two hours');

$start = '2026-10-01 15:35:00';
$start_ts = Azure_Volunteer_Signup::slot_start_timestamp($start);
$t->check($start_ts > 0, 'slot start parses as Pacific');
$t->check(Azure_Volunteer_Signup::reminder_is_due($start, $start_ts - (int) (1.5 * 3600)), 'a shift inside the two-hour lead is due');
$t->check(!Azure_Volunteer_Signup::reminder_is_due($start, $start_ts - (int) (2.5 * 3600)), 'a shift beyond the lead waits for a later sweep');
$t->check(!Azure_Volunteer_Signup::reminder_is_due($start, $start_ts + 600), 'a shift that already started is not reminded');

$page = file_get_contents(dirname(__DIR__) . '/Azure Plugin/admin/volunteer-page.php');
$t->check(strpos($page, 'azure-vs-series-toggle') !== false, 'series rows have an expand control');
$t->check(strpos($page, "aria-expanded=\"false\"") !== false, 'series start collapsed');
$t->check(strpos($page, 'azure-vs-series-child') !== false, 'events in a series are child rows');
$t->check(strpos($page, 'Send email reminder to volunteers') !== false, 'reminder setting is on the volunteer page');
$t->check(strpos($page, 'volunteer_reminder_unit') !== false, 'reminder lead can be hours or days');

$emails = file_get_contents(dirname(__DIR__) . '/Azure Plugin/admin/emails-page.php');
$t->check(strpos($emails, 'email-messages-page.php') !== false, 'volunteer emails are edited on the messages tab');
$catalog = Azure_Email_Messages::catalog();
foreach (array('membership_guest_account', 'parent_welcome', 'parent_activation', 'office365_welcome', 'backup_notification') as $key) {
    $t->check(isset($catalog[$key]), 'messages list includes ' . $key);
}

$cron = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-pta-cron.php');
$t->check(strpos($cron, "ensure_schedule('azure_volunteer_send_reminders', 'hourly')") !== false, 'reminders stay on one hourly sweep');

exit($t->finish() === 0 ? 0 : 1);
