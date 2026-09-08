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

$t->check(!Azure_Lwsd_Volunteer::live_active('2026-09-06', '2026-09-07'), 'expired-yesterday is not live even if import stamped active=1');
$t->check(Azure_Lwsd_Volunteer::live_active('2026-09-07', '2026-09-07'), 'expires today is still live');
$t->check(!Azure_Lwsd_Volunteer::live_active('', '2026-09-07'), 'empty expiry is not live');

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
$t->equals(0, $stats['expiring_matched'], 'Pat is ambiguous so expiring_matched is 0');
$t->equals(1, $stats['unmatched'], 'No Account unmatched');
$t->equals(1, $stats['ambiguous'], 'Pat ambiguous');
$t->equals(3, $stats['total'], 'roster total');

$dual = array(
    array('first' => 'Lindsay', 'last' => 'Allan', 'expires_on' => '2028-08-21', 'match_state' => 'matched', 'user_id' => 11),
    array('first' => 'Soon', 'last' => 'Match', 'expires_on' => '2026-09-12', 'match_state' => 'matched', 'user_id' => 12),
    array('first' => 'Old', 'last' => 'Match', 'expires_on' => '2026-09-01', 'match_state' => 'matched', 'user_id' => 13),
    array('first' => 'Old', 'last' => 'Ghost', 'expires_on' => '2026-08-01', 'match_state' => 'unmatched', 'user_id' => 0),
);
$dual_stats = Azure_Lwsd_Volunteer::widget_stats($dual, '2026-09-07', 14);
$t->equals(1, $dual_stats['expiring_matched'], 'Soon Match is matched and in the 14-day window');
$t->equals(1, $dual_stats['expired_matched'], 'Old Match is matched and already expired');
$t->equals(2, $dual_stats['expired'], 'expired counts all roster rows before today');
$t->equals('1 matched (1 on roster)', Azure_Lwsd_Volunteer::dual_count_label(1, 1), 'widget dual-population copy');

$t->equals('', Azure_Lwsd_Volunteer::archive_note('', ''), 'no import means no archive note');
$t->equals('', Azure_Lwsd_Volunteer::archive_note('2026-09-07 13:15:00', 'lwsd-volunteer-rosters/file.xlsx'), 'archived blob has no warning');
$t->equals(' — not archived', Azure_Lwsd_Volunteer::archive_note('2026-09-07 13:15:00', ''), 'import without blob shows not archived');

$chunk_rows = array(
    array('first' => 'A', 'last' => 'One', 'expires_on' => '2027-01-01', 'user_id' => 1, 'match_state' => 'matched'),
    array('first' => 'B', 'last' => 'Two', 'expires_on' => '', 'user_id' => 0, 'match_state' => 'unmatched'),
    array('first' => 'C', 'last' => 'Three', 'expires_on' => '2026-12-01', 'user_id' => 3, 'match_state' => 'matched'),
);
$chunks = Azure_Lwsd_Volunteer::roster_insert_chunks($chunk_rows, '2026-09-07 13:15:00', 2);
$t->equals(2, count($chunks), 'chunk size 2 splits 3 rows into 2 batches');
$t->equals(2, count($chunks[0]), 'first chunk is full');
$t->equals(1, count($chunks[1]), 'second chunk has the remainder');
$t->equals('a|one', $chunks[0][0]['name_key'], 'chunk rows include name_key');
$t->equals('2026-09-07 13:15:00', $chunks[0][0]['imported_at'], 'chunk rows stamp imported_at');
$t->equals(null, $chunks[0][1]['expires_on'], 'empty expiry becomes null for insert');

$built = Azure_Lwsd_Volunteer::roster_insert_sql('wp_lwsd_volunteer_roster', $chunks[0]);
$t->check(strpos($built['sql'], 'INSERT INTO wp_lwsd_volunteer_roster') === 0, 'insert SQL targets the roster table');
$t->check(strpos($built['sql'], '(%s, %s, %s, NULL, %d, %s, %s)') !== false, 'null expiry emits literal NULL in SQL');
$t->check(substr_count($built['sql'], '(%s, %s, %s, %s, %d, %s, %s)') === 1, 'dated row still binds expiry with %s');
$t->equals(13, count($built['values']), 'null expiry omits bind value so 7 plus 6 binds');
$t->equals('A', $built['values'][0], 'first bind value is first_name');
$t->equals('2027-01-01', $built['values'][3], 'dated row binds expires_on');
$t->equals(1, $built['values'][4], 'user_id binds as integer position');

$many = array();
for ($i = 0; $i < 201; $i++) {
    $many[] = array('first' => 'F' . $i, 'last' => 'L' . $i, 'expires_on' => '2027-01-01', 'user_id' => $i + 1, 'match_state' => 'matched');
}
$default_chunks = Azure_Lwsd_Volunteer::roster_insert_chunks($many, '2026-09-07 13:15:00');
$t->equals(2, count($default_chunks), 'default chunk size is 200');
$t->equals(200, count($default_chunks[0]), 'first default chunk holds 200 rows');
$t->equals(1, count($default_chunks[1]), '201st row is in the next chunk');

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

$warn = Azure_Lwsd_Volunteer::warning_copy(array(
    'reason' => 'not_on_roster',
    'expires_on' => '',
    'active' => false,
    'approved_for_event' => false,
));
$t->check($warn['show_link'] === true, 'unapproved shows link');
$t->check(strpos($warn['body'], 'LWSD') !== false, 'body mentions LWSD');

$okc = Azure_Lwsd_Volunteer::warning_copy(array(
    'reason' => 'ok',
    'expires_on' => '2028-08-21',
    'active' => true,
    'approved_for_event' => true,
));
$t->check($okc['show_link'] === false, 'approved has no nag');

$foot = Azure_Lwsd_Volunteer::confirmation_footer(
    array('reason' => 'expires_before_event', 'expires_on' => '2026-09-20', 'active' => true, 'approved_for_event' => false),
    'https://wilderptsa.net/become-an-lwsd-approved-volunteer/'
);
$t->check(strpos($foot, 'https://wilderptsa.net/become-an-lwsd-approved-volunteer/') !== false, 'footer has apply URL');
$t->equals('', Azure_Lwsd_Volunteer::confirmation_footer(array('reason' => 'ok', 'expires_on' => '2028-01-01', 'active' => true, 'approved_for_event' => true), 'https://x'), 'ok footer empty');

$t->equals(
    array('BethanyM@wilderptsa.net', 'webmaster@wilderptsa.net'),
    Azure_Lwsd_Volunteer::staff_alert_recipients(),
    'staff recipients'
);
$body = Azure_Lwsd_Volunteer::staff_alert_body(array(
    'volunteer_name' => 'Pat Smith',
    'volunteer_email' => 'pat@example.com',
    'user_id' => 21,
    'sheet_title' => 'Fall Carnival',
    'activities' => 'Set up (5:00 PM – 6:00 PM)',
    'event_date' => '2026-10-17',
    'expires_on' => '2026-09-20',
    'reason' => 'expires_before_event',
));
$t->check(strpos($body, 'Pat Smith') !== false, 'name in staff body');
$t->check(strpos($body, '2026-09-20') !== false, 'expiry in staff body');
$t->check(strpos($body, 'Fall Carnival') !== false, 'sheet in staff body');

$t->equals('Your LWSD volunteer approval expires soon', Azure_Lwsd_Volunteer::expiring_email_subject(), 'subject');
$body = Azure_Lwsd_Volunteer::expiring_email_body(array(
    'first_name' => 'Lindsay',
    'expires_on' => '2026-09-18',
    'apply_url' => 'https://wilderptsa.net/become-an-lwsd-approved-volunteer/',
));
$t->check(strpos($body, 'Lindsay') !== false, 'expiring email names the volunteer');
$t->check(strpos($body, '2026-09-18') !== false, 'expiring email has the date');
$t->check(strpos($body, 'become-an-lwsd-approved-volunteer') !== false, 'expiring email has apply link');
$t->check(strpos($body, 'renew before volunteering at school') !== false, 'expiring email asks them to renew');

if (!function_exists('get_users')) {
    function get_users($args = array()) {
        return isset($GLOBALS['lwsd_test_user_ids']) ? $GLOBALS['lwsd_test_user_ids'] : array();
    }
}
if (!function_exists('get_user_meta')) {
    function get_user_meta($user_id, $key, $single = true) {
        $store = isset($GLOBALS['lwsd_test_user_meta']) ? $GLOBALS['lwsd_test_user_meta'] : array();
        if (!isset($store[(int) $user_id][$key])) {
            return $single ? '' : array();
        }
        return $store[(int) $user_id][$key];
    }
}
if (!function_exists('get_userdata')) {
    function get_userdata($user_id) {
        $store = isset($GLOBALS['lwsd_test_wp_users']) ? $GLOBALS['lwsd_test_wp_users'] : array();
        return isset($store[(int) $user_id]) ? $store[(int) $user_id] : false;
    }
}

$GLOBALS['lwsd_test_user_ids'] = array(11);
$GLOBALS['lwsd_test_user_meta'] = array(
    11 => array(
        'first_name' => 'Lindsay',
        'last_name'  => 'Allan',
        'user_email' => 'meta-must-not-be-used@example.com',
    ),
);
$user = new stdClass();
$user->ID = 11;
$user->user_email = 'l@example.com';
$GLOBALS['lwsd_test_wp_users'] = array(11 => $user);

$loaded = Azure_Lwsd_Volunteer::load_wp_users_for_match();
$t->equals(1, count($loaded), 'named WP user is loaded for matching');
$t->equals('l@example.com', $loaded[0]['user_email'], 'email comes from WP_User, not user meta');

exit($t->finish());
