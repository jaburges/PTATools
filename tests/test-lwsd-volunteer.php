<?php
require_once __DIR__ . '/wp-shim.php';
if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-lwsd-volunteer.php';

$t = new TestRunner('LWSD volunteer roster logic');

$t->equals('steven', Azure_Lwsd_Volunteer::normalize_name('  STEVEN '), 'case-fold and trim');
$t->equals('marc-antoine', Azure_Lwsd_Volunteer::normalize_name('Marc-Antoine'), 'keep hyphen');
$t->equals('steven|alvarez', Azure_Lwsd_Volunteer::name_key('steven', 'alvarez'), 'key is first|last');
$t->equals('steven|alvarez', Azure_Lwsd_Volunteer::name_key('STEVEN', 'Alvarez'), 'key ignores case');

$t->equals('2026-09-13', Azure_Lwsd_Volunteer::parse_expiry('2026-09-13 00:00:00'), 'datetime string');
$t->equals('2026-09-13', Azure_Lwsd_Volunteer::parse_expiry('9/13/2026'), 'US slash date');
$t->equals('', Azure_Lwsd_Volunteer::parse_expiry(''), 'empty expiry');

$t->check(Azure_Lwsd_Volunteer::is_active('2026-09-07', '2026-09-07'), 'expires today is still active');
$t->check(!Azure_Lwsd_Volunteer::is_active('2026-09-06', '2026-09-07'), 'yesterday is expired');
$t->check(!Azure_Lwsd_Volunteer::is_active('', '2026-09-07'), 'missing expiry is not active');

$t->check(Azure_Lwsd_Volunteer::is_approved_for_event('2026-10-17', '2026-10-17'), 'expiry on event day covers it');
$t->check(!Azure_Lwsd_Volunteer::is_approved_for_event('2026-10-16', '2026-10-17'), 'expiry before event does not');

$roster = array(
    array('first' => 'Lindsay', 'last' => 'Allan', 'expires_on' => '2028-08-21'),
    array('first' => 'Pat', 'last' => 'Smith', 'expires_on' => '2026-09-10'),
    array('first' => 'No', 'last' => 'Account', 'expires_on' => '2027-01-01'),
);
$users = array(
    array('ID' => 11, 'first_name' => 'lindsay', 'last_name' => 'allan', 'user_email' => 'l@example.com'),
    array('ID' => 21, 'first_name' => 'Pat', 'last_name' => 'Smith', 'user_email' => 'p1@example.com'),
    array('ID' => 22, 'first_name' => 'Pat', 'last_name' => 'Smith', 'user_email' => 'p2@example.com'),
);
$matched = Azure_Lwsd_Volunteer::match_rows($roster, $users);
$t->equals('matched', $matched[0]['match_state'], 'unique name matches');
$t->equals(11, $matched[0]['user_id'], 'matched user id');
$t->equals('ambiguous', $matched[1]['match_state'], 'two WP users with same name');
$t->equals(0, $matched[1]['user_id'], 'ambiguous writes no user');
$t->equals('unmatched', $matched[2]['match_state'], 'no WP user');

$plan = Azure_Lwsd_Volunteer::plan_meta_writes($matched, array(11, 99), '2026-09-07');
$t->equals(array(11), array_keys($plan['set']), 'only unique match gets meta');
$t->equals('2028-08-21', $plan['set'][11]['expires_on'], 'expiry copied');
$t->equals(1, $plan['set'][11]['active'], 'Lindsay still active on 2026-09-07 — pass today into plan_meta_writes');
$t->check(in_array(99, $plan['clear'], true), 'user who left the roster is cleared');
$t->check(!in_array(21, $plan['clear'], true) && !isset($plan['set'][21]), 'ambiguous Pat is neither set nor assumed');

$ok = Azure_Lwsd_Volunteer::status_from_meta('2028-08-21', '2026-10-17', '2026-09-07');
$t->equals('ok', $ok['reason'], 'future expiry is ok');
$t->check($ok['active'] && $ok['approved_for_event'], 'ok is active and covers event');

$missing = Azure_Lwsd_Volunteer::status_from_meta('', '2026-10-17', '2026-09-07');
$t->equals('not_on_roster', $missing['reason'], 'empty expiry is not on roster');

$expired = Azure_Lwsd_Volunteer::status_from_meta('2026-09-01', '2026-10-17', '2026-09-07');
$t->equals('expired', $expired['reason'], 'past expiry is expired');

$soon = Azure_Lwsd_Volunteer::status_from_meta('2026-09-20', '2026-10-17', '2026-09-07');
$t->equals('expires_before_event', $soon['reason'], 'covers today but not the event');
$t->check($soon['active'] && !$soon['approved_for_event'], 'active today but not approved for event');

$stats = Azure_Lwsd_Volunteer::widget_stats($matched, '2026-09-07', 14);
$t->equals(1, $stats['active'], 'Lindsay is active');
$t->equals(1, $stats['expiring'], 'Pat expires 10 Sep, within 14 days, even if ambiguous');
$t->equals(1, $stats['unmatched'], 'No Account unmatched');
$t->equals(1, $stats['ambiguous'], 'Pat ambiguous');
$t->equals(3, $stats['total'], 'roster total');

$contactable = Azure_Lwsd_Volunteer::expiring_contactable(
    array(
        array('ID' => 11, 'user_email' => 'l@example.com', 'expires_on' => '2026-09-18', 'match_state' => 'matched'),
        array('ID' => 12, 'user_email' => '', 'expires_on' => '2026-09-18', 'match_state' => 'matched'),
        array('ID' => 13, 'user_email' => 'x@example.com', 'expires_on' => '2027-01-01', 'match_state' => 'matched'),
        array('ID' => 0, 'user_email' => '', 'expires_on' => '2026-09-18', 'match_state' => 'unmatched'),
    ),
    '2026-09-07',
    14
);
$t->equals(1, count($contactable), 'only matched users with email and expiry in window');
$t->equals(11, $contactable[0]['ID'], 'Lindsay-equivalent contact');

// Step 1 helpers: apply_path + blob_name (no WordPress needed).
$t->equals('/become-an-lwsd-approved-volunteer/', Azure_Lwsd_Volunteer::apply_path(), 'apply path');
$t->equals(
    'lwsd-volunteer-rosters/2026-09-07-131500-wilder-approved-for-ptsa-9-2-2026.xlsx',
    Azure_Lwsd_Volunteer::blob_name('Wilder approved for PTSA 9 2 2026.xlsx', '2026-09-07-131500'),
    'blob prefix and sanitized name'
);
$t->equals(
    'lwsd-volunteer-rosters/2026-09-07-131500-district-roster.xlsx',
    Azure_Lwsd_Volunteer::blob_name('District Roster.XLSX', '2026-09-07-131500'),
    'blob forces xlsx extension and lowercases'
);

exit($t->finish());
