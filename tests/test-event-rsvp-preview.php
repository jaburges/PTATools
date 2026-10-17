<?php
/**
 * RSVP on unpublished events and for editors: an editor previewing a draft
 * sees the RSVP box (button disabled), members only see it once the event
 * is published, and an editor on an event with RSVP off is told where to
 * turn it on.
 *
 * Run: php tests/test-event-rsvp-preview.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

class Preview_State {
    public static $posts = array();
    /** user id => event ids they can edit */
    public static $editors = array();
}

function _n($single, $plural, $n, $domain = null) { return $n == 1 ? $single : $plural; }
function add_shortcode($tag, $cb) { return true; }
function delete_post_meta($id, $key) { unset(WP_Shim::$post_meta[$id][$key]); return true; }
function get_post($id) { return Preview_State::$posts[(int) $id] ?? null; }
function get_permalink($id) { return 'https://example.test/event/e' . (int) $id . '/'; }
function get_edit_post_link($id, $context = 'display') { return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit'; }
function wp_login_url($redirect = '') { return 'https://example.test/wp-login.php'; }
function user_can($user_id, $cap, $post_id = 0) {
    return $cap === 'edit_post' && in_array((int) $post_id, Preview_State::$editors[(int) $user_id] ?? array(), true);
}

class Preview_WPDB extends Fake_WPDB {
    public function get_results($sql, $output = null) { $this->prepare_args = array(); return array(); }
}
$GLOBALS['wpdb'] = new Preview_WPDB();
WP_Shim::$timezone = 'America/Los_Angeles';

require_once AZURE_PLUGIN_PATH . 'includes/class-event-rsvp.php';

$t = new TestRunner('RSVP preview and editor hints');
$R = 'Azure_Event_Rsvp';

function preview_event($id, $status, $rsvp) {
    Preview_State::$posts[$id] = (object) array('ID' => $id, 'post_type' => 'pta_event', 'post_status' => $status, 'post_title' => 'Coffee with the Principal', 'post_content' => '');
    WP_Shim::$post_meta[$id]['_EventStartDate'] = '2030-11-05 08:30:00';
    WP_Shim::$post_meta[$id]['_EventEndDate'] = '2030-11-05 09:30:00';
    Azure_Event_Rsvp::save_settings($id, Azure_Event_Rsvp::sanitize_settings($rsvp));
}

preview_event(37184, 'draft', array('enabled' => true, 'capacity' => '30', 'max_guests' => '2'));
preview_event(37185, 'publish', array('enabled' => true));
preview_event(37186, 'publish', array());
preview_event(37187, 'draft', array());
$admin = 1;
$member = 7;
Preview_State::$editors[$admin] = array(37184, 37185, 37186, 37187);
$now = '2030-10-01 09:00:00';

// Draft with RSVP on
$html = $R::render_for_event(37184, $admin, $now);
$t->check(strpos($html, 'class="pta-event-rsvp"') !== false, 'an editor previewing a draft sees the RSVP box');
$t->check(strpos($html, 'Preview: members can RSVP once this event is published.') !== false, 'with a note that it opens on publish');
$t->check(strpos($html, 'pta-rsvp-submit" disabled aria-disabled="true"') !== false, 'and the RSVP button, disabled');
$t->check(strpos($html, '0 of 30 spots taken') !== false && strpos($html, '<select name="guests" disabled>') !== false, 'capacity and the guest picker preview too');
$t->equals('', $R::render_for_event(37184, $member, $now), 'members do not see RSVP on a draft');
$t->equals('', $R::render_for_event(37184, 0, $now), 'visitors do not see RSVP on a draft');
$r = $R::create(37184, $admin, 0, $now);
$t->check(!$r['ok'] && $r['code'] === 'not_found', 'nobody can RSVP to a draft, editors included');
WP_Shim::$post_meta[37184]['_pta_rsvp_enabled'] = 'yes';

// Published with RSVP on
$html = $R::render_for_event(37185, $member, $now);
$t->check(strpos($html, 'pta-rsvp-submit">RSVP</button>') !== false && strpos($html, 'disabled') === false, 'members get a working RSVP button once published');
$t->check(strpos($R::render_for_event(37185, 0, $now), 'pta-rsvp-login') !== false, 'visitors are asked to log in');
$t->check(strpos($R::render_for_event(37185, $admin, $now), 'Preview:') === false, 'editors see the normal box on a published event');

// RSVP off
$note = $R::render_for_event(37186, $admin, $now);
$t->check(strpos($note, 'Only editors see this:') !== false && strpos($note, 'tick Enable RSVP') !== false, 'an editor on an event with RSVP off is told how to turn it on');
$t->check(strpos($note, 'href="https://example.test/wp-admin/post.php?post=37186&amp;action=edit"') !== false, 'with a link to the editor');
$t->check(strpos($note, 'pta-rsvp-submit') === false, 'but no RSVP button');
$t->equals('', $R::render_for_event(37186, $member, $now), 'members see nothing when RSVP is off');
$t->equals('', $R::render_for_event(37186, 0, $now), 'visitors see nothing when RSVP is off');
$t->check(strpos($R::render_for_event(37187, $admin, $now), 'Only editors see this:') !== false, 'the hint shows on drafts too');
$t->equals('', $R::render_for_event(99999, $admin, $now), 'no event, nothing');

// Where it lives
$src = file_get_contents(AZURE_PLUGIN_PATH . 'includes/class-event-rsvp.php');
$t->check(strpos($src, "add_action('add_meta_boxes_' . self::POST_TYPE, array(\$this, 'register_metabox'))") !== false && strpos($src, "const POST_TYPE = 'pta_event';") !== false, 'the RSVP box is added to the pta_event editor');
$tpl = file_get_contents(AZURE_PLUGIN_PATH . 'templates/single-pta_event.php');
$t->check(strpos($tpl, 'Azure_Event_Rsvp::render_for_event($post_id)') !== false, 'the event page template renders the RSVP box itself (no shortcode needed)');

exit($t->finish() === 0 ? 0 : 1);
