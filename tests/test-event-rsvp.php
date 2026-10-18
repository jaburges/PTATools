<?php
/**
 * Event RSVPs: create, cancel, duplicates, capacity, deadline, guests,
 * confirmation/cancellation emails, and the per-person calendar feed
 * shared with volunteer shifts.
 *
 * Run: php tests/test-event-rsvp.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

class Rsvp_State {
    public static $posts = array();
    public static $users = array();
    public static $user_meta = array();
    public static $mail = array();
}

function _n($single, $plural, $n, $domain = null) { return $n == 1 ? $single : $plural; }
function add_shortcode($tag, $cb) { return true; }
function get_post($id) { return Rsvp_State::$posts[(int) $id] ?? null; }
function get_post_type($id) { $p = get_post($id); return $p ? $p->post_type : false; }
function get_permalink($id) { return 'https://example.test/event/' . (int) $id . '/'; }
function delete_post_meta($id, $key) { unset(WP_Shim::$post_meta[$id][$key]); return true; }
function get_userdata($id) { return Rsvp_State::$users[(int) $id] ?? false; }
function get_user_meta($id, $key, $single = false) { return Rsvp_State::$user_meta[$id][$key] ?? ''; }
function update_user_meta($id, $key, $value) { Rsvp_State::$user_meta[$id][$key] = $value; return true; }
function get_users($args) {
    foreach (Rsvp_State::$user_meta as $id => $meta) {
        if (($meta[$args['meta_key']] ?? null) === $args['meta_value']) {
            return array($id);
        }
    }
    return array();
}
function wp_login_url($redirect = '') { return 'https://example.test/wp-login.php?redirect_to=' . rawurlencode($redirect); }
function wp_mail($to, $subject, $body, $headers = array()) {
    Rsvp_State::$mail[] = array('to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => $headers);
    return true;
}

/** Fake_WPDB::get_results ignores WHERE; RSVP lists need it honoured. */
class Rsvp_WPDB extends Fake_WPDB {
    public function get_results($sql, $output = null) {
        $args = $this->prepare_args;
        $this->prepare_args = array();
        $rows = array();
        foreach ($this->tables as $name => $table) {
            if (strpos($sql, $name) === false) {
                continue;
            }
            preg_match_all('/([A-Za-z0-9_.]+)\s*=\s*%[sdf]/', $sql, $m);
            foreach ($table as $row) {
                $ok = true;
                foreach ($m[1] as $i => $qualified) {
                    $col = substr($qualified, (int) strrpos($qualified, '.') + (strpos($qualified, '.') === false ? 0 : 1));
                    if (!array_key_exists($i, $args) || (string) ($row[$col] ?? '') !== (string) $args[$i]) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    $rows[] = (object) $row;
                }
            }
            break;
        }
        return $rows;
    }
}

$GLOBALS['wpdb'] = new Rsvp_WPDB();
WP_Shim::$timezone = 'America/Los_Angeles';

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-event-rsvp.php';

$t = new TestRunner('Event RSVP');
$T = 'wp_azure_event_rsvps';

function add_event($id, $title, $start, $end, $extra = array()) {
    Rsvp_State::$posts[$id] = (object) array(
        'ID'           => $id,
        'post_type'    => 'pta_event',
        'post_status'  => $extra['status'] ?? 'publish',
        'post_title'   => $title,
        'post_content' => $extra['content'] ?? '',
    );
    WP_Shim::$post_meta[$id]['_EventStartDate'] = $start;
    WP_Shim::$post_meta[$id]['_EventEndDate'] = $end;
    WP_Shim::$post_meta[$id]['_EventVenue'] = $extra['venue'] ?? '';
    Azure_Event_Rsvp::save_settings($id, Azure_Event_Rsvp::sanitize_settings($extra['rsvp'] ?? array('enabled' => true)));
}
foreach (array(7 => 'Jamie Parent', 8 => 'Sam Parent', 9 => 'Alex Parent', 10 => 'Robin Parent') as $uid => $name) {
    Rsvp_State::$users[$uid] = (object) array('ID' => $uid, 'display_name' => $name, 'user_email' => 'user' . $uid . '@example.test');
}
function rows_for($table) { return $GLOBALS['wpdb']->tables[$table] ?? array(); }

// ── Settings ───────────────────────────────────────────────────────────
$s = Azure_Event_Rsvp::sanitize_settings(array('enabled' => '1', 'capacity' => '40', 'deadline' => '2030-05-01T17:00', 'max_guests' => '3'));
$t->equals(array('enabled' => 'yes', 'capacity' => 40, 'deadline' => '2030-05-01 17:00:00', 'max_guests' => 3), $s, 'editor input is normalised');
$s = Azure_Event_Rsvp::sanitize_settings(array('capacity' => '', 'deadline' => 'nonsense', 'max_guests' => '99'));
$t->equals(array('enabled' => 'no', 'capacity' => 0, 'deadline' => '', 'max_guests' => Azure_Event_Rsvp::GUEST_LIMIT), $s, 'blank capacity is unlimited, a bad deadline is dropped, guests are capped');
$t->equals(0, Azure_Event_Rsvp::sanitize_settings(array('capacity' => '-5'))['capacity'], 'a negative capacity is unlimited, not negative');
$t->equals(Azure_Event_Rsvp::DEFAULT_MAX_GUESTS, Azure_Event_Rsvp::sanitize_settings(array('max_guests' => ' '))['max_guests'], 'blank guests means the default');
$t->equals(0, Azure_Event_Rsvp::sanitize_settings(array('max_guests' => '0'))['max_guests'], 'guests can be turned off with 0');
Azure_Event_Rsvp::save_settings(105, Azure_Event_Rsvp::sanitize_settings(array('enabled' => true, 'max_guests' => '0')));
$t->check(WP_Shim::$post_meta[105]['_pta_rsvp_max_guests'] === '0' && Azure_Event_Rsvp::event_settings(105)['max_guests'] === 0, 'an explicit 0 is stored and read back as no guests');
Azure_Event_Rsvp::save_settings(105, Azure_Event_Rsvp::sanitize_settings(array('enabled' => true)));
$t->check(!isset(WP_Shim::$post_meta[105]['_pta_rsvp_max_guests']) && Azure_Event_Rsvp::event_settings(105)['max_guests'] === Azure_Event_Rsvp::DEFAULT_MAX_GUESTS, 'the default needs no meta row');
unset(WP_Shim::$post_meta[105]);

add_event(100, 'Fall Carnival', '2030-10-23 18:00:00', '2030-10-23 20:30:00', array(
    'venue'   => 'School gym',
    'content' => '<p>Games, food & <strong>fun</strong>.</p><p>Bring a friend.</p>',
    'rsvp'    => array('enabled' => true, 'capacity' => '5', 'max_guests' => '2', 'deadline' => '2030-10-20T17:00'),
));
$s = Azure_Event_Rsvp::event_settings(100);
$t->check($s['enabled'] && $s['capacity'] === 5 && $s['max_guests'] === 2 && $s['deadline'] === '2030-10-20 17:00:00', 'settings round-trip through post meta');
Azure_Event_Rsvp::save_settings(101, Azure_Event_Rsvp::sanitize_settings(array()));
$t->check(!isset(WP_Shim::$post_meta[101]), 'turning RSVP off leaves no meta rows');

$before_deadline = '2030-10-01 09:00:00';

// ── Create ─────────────────────────────────────────────────────────────
$r = Azure_Event_Rsvp::create(100, 7, 1, $before_deadline);
$t->check($r['ok'] && $r['code'] === 'ok', 'a signed-in person can RSVP', $r['message']);
$rows = rows_for($T);
$t->equals(1, count($rows), 'one RSVP row is stored');
$t->check($rows[0]['event_id'] === 100 && $rows[0]['user_id'] === 7 && $rows[0]['guests'] === 1 && $rows[0]['status'] === 'going', 'the row records event, person, guests and status');
$t->equals('Jamie Parent', $rows[0]['user_name'], 'the name is captured');
$t->equals('user7@example.test', $rows[0]['user_email'], 'the email is captured');
$t->equals('2030-10-01 09:00:00', $rows[0]['created_at'], 'the RSVP time is recorded in site time');

$r = Azure_Event_Rsvp::create(100, 7, 0, $before_deadline);
$t->check(!$r['ok'] && $r['code'] === 'duplicate', 'a second RSVP from the same person is refused');
$t->equals(1, count(rows_for($T)), 'no duplicate row is written');

$t->equals('login', Azure_Event_Rsvp::create(100, 0, 0, $before_deadline)['code'], 'logged-out RSVPs are refused');
$t->equals('too_many_guests', Azure_Event_Rsvp::create(100, 8, 3, $before_deadline)['code'], 'more guests than allowed is refused');
$t->equals('guests_invalid', Azure_Event_Rsvp::create(100, 8, Azure_Event_Rsvp::parse_guests('1.5'), $before_deadline)['code'], 'a non-whole guest count is refused');
$t->equals(-1, Azure_Event_Rsvp::parse_guests('-1'), 'negative guests do not parse');
$t->equals(2, Azure_Event_Rsvp::parse_guests(' 2 '), 'a whole guest count parses');
$t->equals(0, Azure_Event_Rsvp::parse_guests(''), 'blank guests is zero');

add_event(110, 'No RSVP Night', '2030-11-01 18:00:00', '2030-11-01 19:00:00', array('rsvp' => array()));
$t->equals('not_enabled', Azure_Event_Rsvp::create(110, 8, 0, $before_deadline)['code'], 'events without RSVP refuse RSVPs');
add_event(111, 'Draft event', '2030-11-02 18:00:00', '2030-11-02 19:00:00', array('status' => 'draft'));
$t->equals('not_found', Azure_Event_Rsvp::create(111, 8, 0, $before_deadline)['code'], 'unpublished events refuse RSVPs');
$t->equals('not_found', Azure_Event_Rsvp::create(999, 8, 0, $before_deadline)['code'], 'unknown events refuse RSVPs');

// ── Capacity ───────────────────────────────────────────────────────────
$t->equals(2, Azure_Event_Rsvp::seats_taken(100), 'guests count toward seats taken');
$t->equals(3, Azure_Event_Rsvp::spaces_left(100), 'spaces left accounts for guests');
$r = Azure_Event_Rsvp::create(100, 8, 2, $before_deadline);
$t->check($r['ok'], 'an RSVP that exactly fills the event is accepted');
$r = Azure_Event_Rsvp::create(100, 9, 0, $before_deadline);
$t->check(!$r['ok'] && $r['code'] === 'full', 'a full event refuses more RSVPs');
$t->equals('Sorry, this event is full.', $r['message'], 'and says it is full');
Azure_Event_Rsvp::cancel(100, 8, $before_deadline);
$r = Azure_Event_Rsvp::create(100, 9, 2, $before_deadline);
$t->check($r['ok'], 'cancelling frees the space for someone else');
$r = Azure_Event_Rsvp::create(100, 10, 1, $before_deadline);
$t->check(!$r['ok'] && $r['code'] === 'full' && strpos($r['message'], 'Only 0') === false, 'a party bigger than the space left is refused');
add_event(102, 'Two spots', '2030-10-24 18:00:00', '2030-10-24 19:00:00', array('rsvp' => array('enabled' => true, 'capacity' => '2', 'max_guests' => '3')));
Azure_Event_Rsvp::create(102, 7, 0, $before_deadline);
$r = Azure_Event_Rsvp::create(102, 8, 2, $before_deadline);
$t->equals('Only 1 space is left.', $r['message'], 'a party too big for the room says how many spaces are left');
add_event(103, 'Open house', '2030-10-25 18:00:00', '2030-10-25 19:00:00', array('rsvp' => array('enabled' => true)));
$ok = true;
foreach (array(7, 8, 9, 10) as $uid) {
    $ok = Azure_Event_Rsvp::create(103, $uid, 0, $before_deadline)['ok'] && $ok;
}
$t->check($ok && Azure_Event_Rsvp::spaces_left(103) === null, 'no capacity means no limit');

// ── Deadline ───────────────────────────────────────────────────────────
$t->check(!Azure_Event_Rsvp::is_closed(100, '2030-10-20 16:59:00'), 'RSVPs are open a minute before the deadline');
$t->check(Azure_Event_Rsvp::is_closed(100, '2030-10-20 17:00:00'), 'RSVPs close at the deadline');
$r = Azure_Event_Rsvp::create(100, 10, 0, '2030-10-21 08:00:00');
$t->check(!$r['ok'] && $r['code'] === 'closed', 'an RSVP after the deadline is refused');
$t->check(!Azure_Event_Rsvp::is_closed(103, '2030-10-25 17:59:00') && Azure_Event_Rsvp::is_closed(103, '2030-10-25 18:00:00'), 'without a deadline RSVPs close when the event starts');
$t->check(Azure_Event_Rsvp::is_closed(100, new DateTimeImmutable('2030-10-21 00:30:00', new DateTimeZone('UTC'))), 'the deadline is in site time (00:30 UTC is 17:30 the day before in Los Angeles)');
$r = Azure_Event_Rsvp::cancel(100, 9, '2030-10-21 08:00:00');
$t->check($r['ok'], 'people can still cancel after the deadline');
Azure_Event_Rsvp::create(100, 9, 2, $before_deadline);

// ── Cancel ─────────────────────────────────────────────────────────────
Rsvp_State::$mail = array();
$r = Azure_Event_Rsvp::cancel(100, 7, '2030-10-02 10:00:00');
$t->check($r['ok'], 'an RSVP can be cancelled');
$row = Azure_Event_Rsvp::get_rsvp(100, 7);
$t->check($row->status === 'cancelled' && $row->cancelled_at === '2030-10-02 10:00:00', 'the row is marked cancelled with a time');
$t->equals('not_going', Azure_Event_Rsvp::cancel(100, 7)['code'], 'cancelling twice is refused');
$t->equals('not_going', Azure_Event_Rsvp::cancel(100, 10)['code'], 'cancelling without an RSVP is refused');
$t->equals(1, count(Rsvp_State::$mail), 'a cancellation sends one email');
$cancel_mail = Rsvp_State::$mail[0];
$t->equals('user7@example.test', $cancel_mail['to'], 'the cancellation goes to the person');
$t->equals('RSVP cancelled: Fall Carnival', $cancel_mail['subject'], 'the cancellation subject names the event');
$t->check(strpos($cancel_mail['body'], 'Your RSVP has been cancelled') !== false, 'the cancellation body says so');

$count_before = count(rows_for($T));
$r = Azure_Event_Rsvp::create(100, 7, 0, $before_deadline);
$t->check($r['ok'] && Azure_Event_Rsvp::get_rsvp(100, 7)->status === 'going', 'someone who cancelled can RSVP again');
$t->equals($count_before, count(rows_for($T)), 'a repeat RSVP reuses their row');
$t->equals(null, Azure_Event_Rsvp::get_rsvp(100, 7)->cancelled_at, 'the repeat RSVP clears the cancelled time');

// ── Confirmation email ─────────────────────────────────────────────────
$confirm = end(Rsvp_State::$mail);
$token7 = Rsvp_State::$user_meta[7]['pta_volunteer_ics_token'] ?? '';
$t->equals('user7@example.test', $confirm['to'], 'the confirmation goes to the person');
$t->equals("You're going: Fall Carnival", $confirm['subject'], 'the confirmation subject names the event');
$t->check(in_array('Content-Type: text/html; charset=UTF-8', $confirm['headers'], true), 'the confirmation is HTML');
$t->check(strpos($confirm['body'], 'Fall Carnival') !== false, 'the confirmation has the title');
$t->check(strpos($confirm['body'], 'Wednesday, October 23, 2030, 6:00 PM – 8:30 PM PDT') !== false, 'the confirmation has the date and time');
$t->check(strpos($confirm['body'], 'School gym') !== false, 'the confirmation has the location');
$t->check(strpos($confirm['body'], 'Games, food &amp; fun.') !== false && strpos($confirm['body'], 'Bring a friend.') !== false, 'the confirmation has the description as escaped text');
$t->check(strpos($confirm['body'], '<strong>fun</strong>') === false, 'event HTML does not reach the email');
$t->check((bool) preg_match('/^[a-f0-9]{32}$/', $token7), 'the person has a private calendar token');
$t->check(strpos($confirm['body'], 'href="https://example.test/?pta_volunteer_subscribe=' . $token7 . '"') !== false, 'the confirmation links their own https subscribe page');
$t->check(strpos($confirm['body'], 'webcal://') === false, 'no webcal:// href that mail apps strip');
$t->check(strpos($cancel_mail['body'], '?pta_volunteer_subscribe=' . $token7) !== false, 'the cancellation carries the same link');
Rsvp_State::$mail = array();
Azure_Event_Rsvp::create(100, 10, 5, $before_deadline);
$t->equals(0, count(Rsvp_State::$mail), 'a refused RSVP sends no email');

$vars = Azure_Event_Rsvp::email_vars(Rsvp_State::$users[8], 100, (object) array('guests' => 2));
$t->equals('You + 2 guests', $vars['guests'], 'the party size reads naturally');
$t->check(strpos($vars['subscribe_url'], 'pta_volunteer_subscribe=') !== false && strpos($vars['subscribe_url'], $token7) === false, 'each person gets their own link');

// ── Feed ───────────────────────────────────────────────────────────────
// A volunteer shift for person 9, shaped like the joined signups query.
$GLOBALS['wpdb']->tables['wp_azure_volunteer_signups'][] = array(
    'signup_id' => 1, 'activity_id' => 50, 'user_id' => 9, 'party_size' => 1,
    'activity_name' => 'Ticket booth', 'activity_description' => '', 'spots_needed' => 2,
    'slot_start' => '2030-10-23 17:00:00', 'slot_end' => '2030-10-23 18:00:00', 'sheet_id' => 5,
    'title' => 'Fall Carnival', 'event_date' => '2030-10-23 17:00:00', 'event_location' => 'School gym',
    'grade' => '', 'teacher' => '', 'pta_event_id' => 100, 'status' => 'open', 'allow_group' => 0,
);
$ics = Azure_Volunteer_Signup::feed_ics_for_user(9, Rsvp_State::$users[9]);
$t->check(strpos($ics, 'BEGIN:VCALENDAR') === 0 && substr_count($ics, 'BEGIN:VEVENT') === 3, 'the feed holds the shift and both RSVPs', substr_count($ics, 'BEGIN:VEVENT') . ' events');
$t->check(strpos($ics, 'SUMMARY:Fall Carnival: Ticket booth') !== false, 'the volunteer shift is still there');
$t->check(strpos($ics, 'UID:pta-rsvp-100-9@example.test') !== false, 'the RSVP has a stable UID');
$t->check(strpos($ics, "SUMMARY:Fall Carnival\r\n") !== false, 'the RSVP is titled after the event');
$t->check(strpos($ics, 'DTSTART:20301024T010000Z') !== false && strpos($ics, 'DTEND:20301024T033000Z') !== false, 'the RSVP times are converted from site time to UTC');
$t->check(strpos($ics, 'LOCATION:School gym') !== false, 'the RSVP has the location');
$t->check(strpos($ics, 'DESCRIPTION:RSVP: You + 2 guests') !== false, 'the RSVP notes the party size');
$t->check(strpos($ics, 'URL:https://example.test/event/100/') !== false, 'the RSVP links the event page');

$feed7 = Azure_Event_Rsvp::feed_vevents_for_user(7);
$t->equals(3, count($feed7), 'person 7 sees all three events they are going to');
$feed8 = implode("\n", Azure_Event_Rsvp::feed_vevents_for_user(8));
$t->check(strpos($feed8, 'pta-rsvp-100-8@') === false, 'a cancelled RSVP drops out of the feed');
$t->check(strpos($feed8, 'pta-rsvp-102-8@') === false && strpos($feed8, 'pta-rsvp-103-8@') !== false, 'only events they are going to are listed');

add_event(120, 'Last spring picnic', '2020-05-01 12:00:00', '2020-05-01 14:00:00');
$GLOBALS['wpdb']->insert($T, array('event_id' => 120, 'user_id' => 7, 'guests' => 0, 'status' => 'going', 'created_at' => '2020-04-01 00:00:00'));
$t->check(strpos(implode("\n", Azure_Event_Rsvp::feed_vevents_for_user(7)), 'pta-rsvp-120-7@') === false, 'past events are left out');

add_event(130, 'Book fair', '2030-11-10 00:00:00', '2030-11-11 00:00:00', array('rsvp' => array('enabled' => true)));
WP_Shim::$post_meta[130]['_EventAllDay'] = 'yes';
Azure_Event_Rsvp::create(130, 7, 0, $before_deadline);
$allday = implode("\n", Azure_Event_Rsvp::feed_vevents_for_user(7));
$t->check(strpos($allday, 'DTSTART;VALUE=DATE:20301110') !== false && strpos($allday, 'DTEND;VALUE=DATE:20301112') !== false, 'all-day events use whole dates');

Rsvp_State::$posts[103]->post_status = 'trash';
$t->check(strpos(implode("\n", Azure_Event_Rsvp::feed_vevents_for_user(7)), 'pta-rsvp-103-7@') === false, 'a trashed event leaves the feed');
$t->check(Azure_Event_Rsvp::get_rsvp(103, 7) !== null, 'but its RSVPs are kept so Restore brings them back');
Rsvp_State::$posts[103]->post_status = 'publish';
$t->check(strpos(implode("\n", Azure_Event_Rsvp::feed_vevents_for_user(7)), 'pta-rsvp-103-7@') !== false, 'a restored event is back in the feed');

Azure_Event_Rsvp::get_instance()->on_event_deleted(103);
$t->equals(0, count(Azure_Event_Rsvp::rsvps_for_event(103)), 'deleting an event deletes its RSVPs');
$t->check(strpos(implode("\n", Azure_Event_Rsvp::feed_vevents_for_user(7)), 'pta-rsvp-103-') === false, 'and it leaves every feed');
$t->check(count(Azure_Event_Rsvp::rsvps_for_event(100)) > 0, 'other events keep their RSVPs');

$page = Azure_Volunteer_Signup::subscribe_page_html('https://example.test/?pta_volunteer_ics=abc', 'Test Site');
$t->check(strpos($page, 'volunteer shifts and RSVPs') !== false, 'the subscribe page mentions RSVPs');

// ── Event page ─────────────────────────────────────────────────────────
$html = Azure_Event_Rsvp::render_for_event(130, 0, $before_deadline);
$t->check(strpos($html, 'pta-rsvp-login') !== false && strpos($html, 'wp-login.php?redirect_to=' . rawurlencode('https://example.test/event/130/')) !== false, 'logged-out visitors are asked to log in and come back');
$t->check(strpos($html, 'pta-rsvp-submit') === false, 'logged-out visitors get no RSVP button');
$html = Azure_Event_Rsvp::render_for_event(130, 8, $before_deadline);
$t->check(strpos($html, 'pta-rsvp-submit') !== false && strpos($html, 'data-event-id="130"') !== false, 'signed-in people get an RSVP button');
$t->check(strpos($html, '<span class="pta-rsvp-party" data-max="' . Azure_Event_Rsvp::DEFAULT_MAX_GUESTS . '" data-name="Sam Parent">') !== false, 'a blank guest setting offers the default number of guests');
$t->check(strpos($html, '<output class="pta-rsvp-party-label" aria-live="polite">Sam Parent</output><input type="hidden" name="guests" value="0">') !== false, 'the picker starts at just them, labelled with their name');
$t->check(strpos($html, 'data-step="-1" aria-label="One fewer guest" disabled') !== false && strpos($html, 'data-step="1" aria-label="Add a guest">+</button>') !== false, 'at zero guests only + works');
add_event(131, 'Staff lunch', '2030-11-12 12:00:00', '2030-11-12 13:00:00', array('rsvp' => array('enabled' => true, 'max_guests' => '0')));
$html = Azure_Event_Rsvp::render_for_event(131, 8, $before_deadline);
$t->check(strpos($html, 'pta-rsvp-submit') !== false && strpos($html, 'name="guests"') === false, 'no guest picker when the event allows 0 guests');
$t->equals('too_many_guests', Azure_Event_Rsvp::create(131, 8, 1, $before_deadline)['code'], 'and a guest sent anyway is refused');
$html = Azure_Event_Rsvp::render_for_event(130, 7, $before_deadline);
$t->check(strpos($html, 'pta-rsvp-cancel') !== false && strpos($html, 'You&#039;re going: Jamie Parent.') !== false, 'people who RSVP\'d see their RSVP and a Cancel button');
Rsvp_State::$user_meta[7]['first_name'] = 'Jamie';
add_event(133, 'Pancake breakfast', '2030-11-14 08:00:00', '2030-11-14 09:00:00');
Azure_Event_Rsvp::create(133, 7, 1, $before_deadline);
$t->check(strpos(Azure_Event_Rsvp::render_for_event(133, 7, $before_deadline), 'You&#039;re going: Jamie +1.') !== false, 'the RSVP is shown under their first name with its guests: Jamie +1');
unset(Rsvp_State::$user_meta[7]['first_name']);
$t->equals('Alex +3', Azure_Event_Rsvp::party_label('Alex', 3), 'party label adds the guest count');
$t->equals('Alex', Azure_Event_Rsvp::party_label('Alex', 0), 'and is just the name with no guests');
$html = Azure_Event_Rsvp::render_for_event(102, 9, $before_deadline);
$t->check(strpos($html, 'pta-rsvp-submit') !== false && strpos($html, 'name="guests"') === false, 'the guest picker never offers more than the spaces left');
add_event(132, 'Workshop', '2030-11-13 18:00:00', '2030-11-13 19:00:00', array('rsvp' => array('enabled' => true, 'capacity' => '3')));
$t->check(strpos(Azure_Event_Rsvp::render_for_event(132, 8, $before_deadline), 'data-max="2"') !== false, 'with three spaces left the picker stops at +2');
$html = Azure_Event_Rsvp::render_for_event(100, 10, $before_deadline);
$t->check(strpos($html, '4 of 5 spots taken') !== false && strpos($html, 'name="guests"') === false, 'one space left: the count shows and no guests are offered');
Azure_Event_Rsvp::create(100, 10, 0, $before_deadline);
$html = Azure_Event_Rsvp::render_for_event(100, 8, $before_deadline);
$t->check(strpos($html, 'this event is full') !== false && strpos($html, 'pta-rsvp-submit') === false, 'a full event shows no RSVP button');
$html = Azure_Event_Rsvp::render_for_event(100, 8, '2030-10-21 08:00:00');
$t->check(strpos($html, 'closed') !== false && strpos($html, 'pta-rsvp-submit') === false, 'a closed event shows no RSVP button');
$t->equals('', Azure_Event_Rsvp::render_for_event(110, 7), 'events without RSVP show nothing');
Rsvp_State::$posts[130]->post_title = '<script>x</script>';
$t->check(strpos(Azure_Event_Rsvp::compose('rsvp_confirmation', Rsvp_State::$users[7], 130)[1], '<script>') === false, 'the event title is escaped in emails');

// ── Admin CSV ──────────────────────────────────────────────────────────
Rsvp_State::$users[11] = (object) array('ID' => 11, 'display_name' => '=HYPERLINK("x")', 'user_email' => 'user11@example.test');
add_event(140, 'Coffee', '2030-12-01 08:00:00', '2030-12-01 09:00:00', array('rsvp' => array('enabled' => true, 'max_guests' => '1')));
Azure_Event_Rsvp::create(140, 7, 1, $before_deadline);
Azure_Event_Rsvp::create(140, 11, 0, $before_deadline);
$lines = Azure_Event_Rsvp::csv_lines(140);
$t->equals("\"Name\",\"Email\",\"Guests\",\"Party size\",\"RSVP'd at\"\n", $lines[0], 'the CSV has a header row');
$t->equals("\"Jamie Parent\",\"user7@example.test\",\"1\",\"2\",\"2030-10-01 09:00:00\"\n", $lines[1], 'each RSVP is a row with name, email, guests and time');
$t->check(strpos($lines[2], '"\'=HYPERLINK(""x"")"') === 0, 'spreadsheet formulas are neutralised');
$t->equals(3, count($lines), 'only current RSVPs are exported');

// ── Wiring ─────────────────────────────────────────────────────────────
$src = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-event-rsvp.php');
$t->check(strpos($src, "check_ajax_referer(self::NONCE, 'nonce')") !== false, 'RSVP AJAX checks the nonce');
$t->check(strpos($src, "current_user_can('read')") !== false, 'RSVP AJAX checks the capability');
$t->check(strpos($src, "wp_ajax_nopriv_azure_rsvp_create") !== false, 'logged-out AJAX gets a log-in answer');
$t->check(strpos($src, "wp_verify_nonce(sanitize_text_field(wp_unslash(\$_POST['pta_event_rsvp_nonce'])), self::EDITOR_NONCE)") !== false && strpos($src, "current_user_can('edit_post', \$post_id)") !== false, 'the editor box checks its nonce and edit rights');
$t->check(strpos($src, 'current_user_can(self::manage_cap())') !== false && strpos($src, 'check_admin_referer(self::EXPORT_NONCE)') !== false, 'CSV export checks rights and nonce');
$db = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-database.php');
$t->check(strpos($db, 'UNIQUE KEY event_user (event_id, user_id)') !== false, 'the table blocks duplicate RSVPs at the database');
$t->check(strpos($db, 'dbDelta($sql_event_rsvps)') !== false, 'the table is created by the upgrade routine');
$vs = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-volunteer-signup.php');
$t->check(strpos($vs, '$ics = self::feed_ics_for_user($user_id, $user);') !== false, 'the private feed URL serves RSVPs too');
$admin = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-admin.php');
$t->check(preg_match("/'azure-plugin-volunteer',.*?'azure-plugin-rsvp',.*?'azure-plugin-tickets'/s", $admin) === 1, 'RSVP is listed under Scheduling & Events');
$t->check(strpos($admin, "'volunteer', 'rsvp', 'forms'") !== false, 'the RSVP module can be toggled');
$plugin = file_get_contents(dirname(__DIR__) . '/Azure Plugin/azure-plugin.php');
$t->check(strpos($plugin, "if (!empty(\$settings['enable_rsvp']))") !== false, 'the module loads only when enabled');

exit($t->finish() === 0 ? 0 : 1);
