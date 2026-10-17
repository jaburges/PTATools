<?php
/**
 * #29: RSVP next to "Join meeting" on the event list shortcodes.
 *
 * Run: php tests/test-rsvp-summary-action.php
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
function user_trailingslashit($p) { return rtrim($p, '/') . '/'; }
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

$t = new TestRunner('RSVP on event lists');

function add_event($id, $title, $start, $end, $extra = array()) {
    Rsvp_State::$posts[$id] = (object) array(
        'ID' => $id, 'post_type' => 'pta_event', 'post_status' => $extra['status'] ?? 'publish',
        'post_title' => $title, 'post_content' => '',
    );
    WP_Shim::$post_meta[$id]['_EventStartDate'] = $start;
    WP_Shim::$post_meta[$id]['_EventEndDate'] = $end;
    Azure_Event_Rsvp::save_settings($id, Azure_Event_Rsvp::sanitize_settings($extra['rsvp'] ?? array('enabled' => true)));
}
foreach (array(7 => 'Jamie Parent', 8 => 'Sam Parent') as $uid => $name) {
    Rsvp_State::$users[$uid] = (object) array('ID' => $uid, 'display_name' => $name, 'user_email' => 'user' . $uid . '@example.test');
}
$now = '2030-10-01 09:00:00';
add_event(300, 'PTSA General Meeting', '2030-10-23 18:00:00', '2030-10-23 19:30:00');
add_event(301, 'No RSVP meeting', '2030-10-24 18:00:00', '2030-10-24 19:00:00', array('rsvp' => array()));
add_event(302, 'Tiny workshop', '2030-10-25 18:00:00', '2030-10-25 19:00:00', array('rsvp' => array('enabled' => true, 'capacity' => '1')));
add_event(303, 'Past social', '2030-09-01 18:00:00', '2030-09-01 19:00:00');
add_event(304, 'Draft event', '2030-10-26 18:00:00', '2030-10-26 19:00:00', array('status' => 'draft'));

$GLOBALS['wp'] = (object) array('request' => 'calendar');
$_SERVER['QUERY_STRING'] = 'month=10';

// Logged out
$out = Azure_Event_Rsvp::render_summary_action(300, 'inline', 0, $now);
$t->check(strpos($out, 'class="pta-rsvp-inline pta-rsvp-inline--inline" data-event-id="300" data-variant="inline"') !== false, 'the control wraps the event id and variant');
$t->check(strpos($out, '>Log in to RSVP</a>') !== false, 'logged out: Log in to RSVP');
$t->check(strpos($out, 'redirect_to=' . rawurlencode('https://example.test/calendar/?month=10')) !== false, 'and login returns to this page');
$t->check(strpos($out, 'class="pta-join-meeting pta-rsvp-action pta-rsvp-inline-login"') !== false, 'styled like the Join meeting button');
$t->check(strpos(Azure_Event_Rsvp::render_summary_action(300, 'compact', 0, $now), 'pta-join-meeting--compact') !== false, 'the compact variant matches the compact Join button');

// Logged in, not going
$out = Azure_Event_Rsvp::render_summary_action(300, 'inline', 7, $now);
$t->check(strpos($out, '<button type="button" class="pta-join-meeting pta-rsvp-action pta-rsvp-inline-go" aria-label="RSVP to PTSA General Meeting">RSVP</button>') !== false, 'logged in: an RSVP button');

// Going
Azure_Event_Rsvp::create(300, 7, 0, $now);
$out = Azure_Event_Rsvp::render_summary_action(300, 'inline', 7, $now);
$t->check(strpos($out, 'is-going') !== false && strpos($out, 'Going ✓') !== false, 'after RSVPing: Going ✓');
$t->check(strpos($out, 'class="pta-rsvp-inline-cancel">Cancel</button>') !== false, 'with Cancel');
$t->check(strpos($out, 'pta-rsvp-inline-go') === false, 'and no second RSVP button');

// Hidden / closed / full
$t->equals('', Azure_Event_Rsvp::render_summary_action(301, 'inline', 7, $now), 'nothing when RSVP is off');
$t->equals('', Azure_Event_Rsvp::render_summary_action(304, 'inline', 7, $now), 'nothing on drafts');
$t->equals('', Azure_Event_Rsvp::render_summary_action(999, 'inline', 7, $now), 'nothing for unknown events');
$t->check(strpos(Azure_Event_Rsvp::render_summary_action(303, 'inline', 7, $now), '>RSVPs closed</span>') !== false, 'closed after the event starts');
Azure_Event_Rsvp::create(302, 8, 0, $now);
$t->check(strpos(Azure_Event_Rsvp::render_summary_action(302, 'inline', 7, $now), '>Full</span>') !== false, 'Full when capacity is reached');
$t->check(strpos(Azure_Event_Rsvp::render_summary_action(302, 'inline', 8, $now), 'Going ✓') !== false, 'the person holding the last spot still sees Going');
$t->check(strpos(Azure_Event_Rsvp::render_summary_action(302, 'inline', 0, $now), '>Full</span>') !== false, 'logged-out visitors see Full, not a login link');

// Same create/cancel logic as the event page
$t->equals('duplicate', Azure_Event_Rsvp::create(300, 7, 0, $now)['code'], 'a second RSVP from the list is refused like on the event page');
$t->equals('full', Azure_Event_Rsvp::create(302, 7, 0, $now)['code'], 'capacity is enforced');
$t->equals('closed', Azure_Event_Rsvp::create(303, 7, 0, $now)['code'], 'the deadline is enforced');

// Cached list HTML: neutral markers filled per person
$cached = '<li>' . Azure_Event_Rsvp::summary_placeholder(300, 'compact') . '</li><li>' . Azure_Event_Rsvp::summary_placeholder(301, 'compact') . '</li>';
$t->equals('<li><!--pta-rsvp:300:compact--></li><li><!--pta-rsvp:301:compact--></li>', $cached, 'the cached HTML holds no per-person state');
$jamie = Azure_Event_Rsvp::fill_summary_placeholders($cached, 7, $now);
$sam = Azure_Event_Rsvp::fill_summary_placeholders($cached, 8, $now);
$t->check(strpos($jamie, 'Going ✓') !== false, 'Jamie sees Going');
$t->check(strpos($sam, 'pta-rsvp-inline-go') !== false && strpos($sam, 'Going') === false, 'Sam sees RSVP from the same cache');
$t->check(strpos($jamie, '<!--pta-rsvp') === false, 'markers never reach the page');
$t->equals('<li></li>', substr(Azure_Event_Rsvp::fill_summary_placeholders($cached, 7, $now), -9), 'events without RSVP leave the slot empty');
$t->equals('<p>x</p>', Azure_Event_Rsvp::fill_summary_placeholders('<p>x</p>', 7, $now), 'HTML without markers is untouched');

// In a newsletter the slot is a link to the event page
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-shortcodes.php';
$prop = new ReflectionProperty('Azure_Newsletter_Shortcodes', 'expanding');
$prop->setAccessible(true);
$prop->setValue(null, 1);
$mail = Azure_Event_Rsvp::fill_summary_placeholders($cached, 7);
$prop->setValue(null, 0);
$t->check(strpos($mail, 'href="https://example.test/event/300/#rsvp"') !== false, 'emails link to the event page RSVP box');
$t->check(strpos($mail, '<button') === false && strpos($mail, 'pta-rsvp-inline') === false, 'emails get no script button');
$t->check(strpos(Azure_Event_Rsvp::render_for_event(300, 7, $now), 'id="rsvp"') !== false, 'the event page RSVP box is the #rsvp anchor');

// Wiring into the shortcodes
$cal = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-calendar-shortcode.php');
$t->check(strpos($cal, "\$join .= Azure_Event_Rsvp::render_summary_action(\$eid, 'inline');") !== false, '[azure_calendar_events] cards show RSVP beside Join');
$up = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-upcoming-module.php');
$t->check(strpos($up, "Azure_Event_Rsvp::summary_placeholder((int) \$event['id'], 'compact')") !== false, '[up-next] puts a marker in the Join slot');
$t->check(substr_count($up, 'return self::with_rsvp(') === 2, '[up-next] fills markers for fresh and cached output');
$t->check(strpos($up, "'<div class=\"upcoming-join-meeting\">' . \$join_html . \$rsvp_html . '</div>'") !== false, '[up-next] RSVP sits in the Join meeting slot');
$arch = file_get_contents(dirname(__DIR__) . '/Azure Plugin/templates/archive-pta_event.php');
$t->check(strpos($arch, "Azure_Event_Rsvp::render_summary_action(\$eid, 'inline')") !== false, 'the events list page shows RSVP beside Join');
$js = file_get_contents(dirname(__DIR__) . '/Azure Plugin/js/event-rsvp.js');
$t->check(strpos($js, "'.pta-rsvp-inline .pta-rsvp-inline-go'") !== false && strpos($js, "variant: \$wrap.data('variant')") !== false, 'the list control posts the same AJAX actions with its variant');
$rsvp_src = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-event-rsvp.php');
$t->check(strpos($rsvp_src, '$html = self::render_summary_action($event_id, $variant, $user_id, null,') !== false, 'the AJAX reply re-renders the list control');

$_SERVER['QUERY_STRING'] = '';
exit($t->finish() > 0 ? 1 : 0);
