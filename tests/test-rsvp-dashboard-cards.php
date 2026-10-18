<?php
/**
 * #26: RSVP dashboard widget — one card per RSVP event, with its RSVPs.
 *
 * Run: php tests/test-rsvp-dashboard-cards.php
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
    public static $can = true;
    public static $widgets = array();
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
function get_edit_post_link($id, $context = 'display') { return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit'; }
function wp_nonce_url($url, $action = -1) { return $url . '&_wpnonce=abc'; }
function current_user_can($cap) { return Rsvp_State::$can; }
function wp_add_dashboard_widget($id, $title, $cb) { Rsvp_State::$widgets[$id] = $title; }
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

$t = new TestRunner('RSVP dashboard cards');

function add_event($id, $title, $start, $end, $extra = array()) {
    Rsvp_State::$posts[$id] = (object) array(
        'ID'           => $id,
        'post_type'    => 'pta_event',
        'post_status'  => $extra['status'] ?? 'publish',
        'post_title'   => $title,
        'post_content' => '',
    );
    WP_Shim::$post_meta[$id]['_EventStartDate'] = $start;
    WP_Shim::$post_meta[$id]['_EventEndDate'] = $end;
    WP_Shim::$post_meta[$id]['_EventAllDay'] = $extra['all_day'] ?? 'no';
    Azure_Event_Rsvp::save_settings($id, Azure_Event_Rsvp::sanitize_settings($extra['rsvp'] ?? array('enabled' => true)));
    return Rsvp_State::$posts[$id];
}
foreach (array(7 => 'Jamie Parent', 8 => 'Sam <b>Parent</b>', 9 => 'Alex Parent') as $uid => $name) {
    Rsvp_State::$users[$uid] = (object) array('ID' => $uid, 'display_name' => $name, 'user_email' => 'user' . $uid . '@example.test');
}

$now = '2030-10-10 12:00:00';
$carnival = add_event(200, 'Fall Carnival', '2030-10-23 18:00:00', '2030-10-23 20:30:00', array(
    'rsvp' => array('enabled' => true, 'capacity' => '40', 'max_guests' => '3', 'deadline' => '2030-10-20T17:00'),
));
$bulbs = add_event(201, 'Bulb Planting Party', '2030-10-12 10:00:00', '2030-10-12 12:00:00', array('status' => 'draft'));
$picnic = add_event(202, 'Back to School Picnic', '2030-09-05 17:00:00', '2030-09-05 19:00:00');
$fair = add_event(203, 'Book Fair', '2030-10-10 08:00:00', '2030-10-10 15:00:00');
$old = add_event(204, 'Spring Fling', '2030-04-01 00:00:00', '2030-04-01 23:59:59', array('all_day' => 'yes'));

Azure_Event_Rsvp::create(200, 7, 2, '2030-10-01 09:00:00');
Azure_Event_Rsvp::create(200, 8, 0, '2030-10-02 19:30:00');
Azure_Event_Rsvp::create(202, 9, 1, '2030-09-01 08:00:00');
Azure_Event_Rsvp::create(200, 9, 0, '2030-10-03 08:00:00');
Azure_Event_Rsvp::cancel(200, 9, '2030-10-04 08:00:00');

$cards = Azure_Event_Rsvp::dashboard_cards(array($carnival, $bulbs, $picnic, $fair, $old), $now);
$t->equals(array(203, 201, 200), array_column($cards['upcoming'], 'id'), 'upcoming events, soonest first (an event still running today counts)');
$t->equals(array(202, 204), array_column($cards['past'], 'id'), 'past events, most recent first');

$c = $cards['upcoming'][2];
$t->equals('Fall Carnival', $c['title'], 'card title');
$t->check(strpos($c['when'], 'October 23, 2030, 6:00 PM') !== false, 'card date and time');
$t->equals('publish', $c['status'], 'card status');
$t->equals(2, $c['rsvp_count'], 'cancelled RSVPs are not counted');
$t->equals(4, $c['people'], 'people include guests');
$t->equals(40, $c['capacity'], 'capacity');
$t->equals('Oct 20, 5:00 PM', $c['deadline'], 'deadline');
$t->check(!$c['closed'], 'still open');
$t->equals(array('Jamie Parent', 'Sam <b>Parent</b>'), array_column($c['rsvps'], 'name'), 'RSVPs listed oldest first');
$t->equals(array(2, 0), array_column($c['rsvps'], 'guests'), 'with guests');
$t->equals('Oct 2, 7:30 PM', $c['rsvps'][1]['at'], 'and RSVP time in site time');
$t->equals('https://example.test/event/200/', $c['view_url'], 'links to the event');
$t->check(strpos($c['edit_url'], 'post=200&action=edit') !== false, 'and its editor');
$t->check(strpos($c['admin_url'], 'page=azure-plugin-rsvp&event_id=200') !== false, 'and the RSVP admin page');
$t->check(strpos($c['export_url'], 'action=azure_rsvp_export&event_id=200') !== false, 'and the CSV export');
$t->equals('draft', $cards['upcoming'][1]['status'], 'draft events are flagged');

$html = Azure_Event_Rsvp::render_cards($cards);
$t->equals(5, substr_count($html, 'class="pta-rsvp-card"'), 'one card per event');
$t->check(strpos($html, 'Sam &lt;b&gt;Parent&lt;/b&gt;') !== false && strpos($html, 'Sam <b>') === false, 'names are escaped');
$t->check(strpos($html, '4 of 40 spots · 2 RSVPs') !== false, 'count vs capacity on the card');
$t->check(strpos($html, 'RSVP by Oct 20, 5:00 PM') !== false, 'deadline on the card');
$t->check(strpos($html, 'mailto:user7@example.test') !== false, 'emails on the card');
$t->check(strpos($html, '<span class="pta-rsvp-card-guests">+2</span>') !== false, 'guests on the card');
$t->check(strpos($html, '<span class="pta-rsvp-card-status">Draft</span>') !== false, 'draft badge');
$t->check(strpos($html, 'Export CSV') !== false && strpos($html, 'All RSVPs') !== false && strpos($html, 'Edit event') !== false, 'quick links');
$t->check(preg_match('/<details class="pta-rsvp-cards-past"><summary>2 past events<\/summary>/', $html) === 1, 'past events are collapsed');
$t->check(strpos($html, 'No RSVPs yet.') !== false, 'an event without RSVPs says so');
$t->check(strpos($html, 'RSVPs closed') !== false, 'a closed event says so');

$t->check(strpos(Azure_Event_Rsvp::render_cards(array('upcoming' => array(), 'past' => array())), 'No events take RSVPs yet') !== false, 'empty state');

// More RSVPs than fit on a card
add_event(205, 'Big Assembly', '2030-11-01 09:00:00', '2030-11-01 10:00:00');
for ($u = 1000; $u < 1030; $u++) {
    Rsvp_State::$users[$u] = (object) array('ID' => $u, 'display_name' => 'Parent ' . $u, 'user_email' => 'p' . $u . '@example.test');
    Azure_Event_Rsvp::create(205, $u, 0, $now);
}
$big = Azure_Event_Rsvp::render_cards(Azure_Event_Rsvp::dashboard_cards(array(Rsvp_State::$posts[205]), $now));
$t->equals(Azure_Event_Rsvp::CARD_LIST_LIMIT, substr_count($big, '<li>'), 'a card lists up to the limit');
$t->check(strpos($big, '+ 5 more on the RSVP page') !== false, 'and points to the rest');

// Capability
$rsvp = Azure_Event_Rsvp::get_instance();
Rsvp_State::$can = false;
$rsvp->register_dashboard_widget();
$t->check(!isset(Rsvp_State::$widgets['azure_event_rsvps']), 'no widget without the PTA Tools capability');
Rsvp_State::$can = true;
$rsvp->register_dashboard_widget();
$t->equals('Event RSVPs', Rsvp_State::$widgets['azure_event_rsvps'] ?? null, 'the widget is registered for admins');

$src = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-event-rsvp.php');
$t->check(strpos($src, "add_action('wp_dashboard_setup', array(\$this, 'register_dashboard_widget'))") !== false, 'the widget hooks the WP dashboard');
$page = file_get_contents(dirname(__DIR__) . '/Azure Plugin/admin/rsvp-page.php');
$t->check(strpos($page, 'Azure_Event_Rsvp::render_cards(Azure_Event_Rsvp::dashboard_cards($events))') !== false, 'the PTA Tools RSVP page shows the cards');

exit($t->finish() > 0 ? 1 : 0);
