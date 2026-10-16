<?php
/**
 * RSVP — a Scheduling & Events sub-module.
 *
 * Admins turn RSVP on per pta_event (optional capacity, deadline and
 * plus-guests). Signed-in people RSVP or cancel from the event page, get a
 * confirmation or cancellation email, and the event shows up in the same
 * private calendar feed as their volunteer shifts
 * (?pta_volunteer_ics=<token>, offered by ?pta_volunteer_subscribe=<token>).
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Azure_Email_Messages')) {
    require_once __DIR__ . '/class-email-messages.php';
}
if (!class_exists('Azure_Volunteer_Signup')) {
    require_once __DIR__ . '/class-volunteer-signup.php';
}

class Azure_Event_Rsvp {

    const POST_TYPE = 'pta_event';

    const META_ENABLED    = '_pta_rsvp_enabled';
    const META_CAPACITY   = '_pta_rsvp_capacity';
    const META_DEADLINE   = '_pta_rsvp_deadline';
    const META_MAX_GUESTS = '_pta_rsvp_max_guests';

    const STATUS_GOING     = 'going';
    const STATUS_CANCELLED = 'cancelled';

    const NONCE        = 'azure_event_rsvp';
    const EDITOR_NONCE = 'pta_event_rsvp_save';
    const EXPORT_NONCE = 'azure_rsvp_export';
    const TABLE_OPTION = 'azure_event_rsvps_table';

    /** Upper bound for the per-event plus-guests setting. */
    const GUEST_LIMIT = 20;

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        self::ensure_table();

        add_action('add_meta_boxes_' . self::POST_TYPE, array($this, 'register_metabox'));
        add_action('save_post_' . self::POST_TYPE, array($this, 'save_metabox'), 20, 2);

        add_action('wp_ajax_azure_rsvp_create', array($this, 'ajax_create'));
        add_action('wp_ajax_azure_rsvp_cancel', array($this, 'ajax_cancel'));
        add_action('wp_ajax_nopriv_azure_rsvp_create', array($this, 'ajax_login_required'));
        add_action('wp_ajax_nopriv_azure_rsvp_cancel', array($this, 'ajax_login_required'));

        add_action('admin_post_azure_rsvp_export', array($this, 'export_csv'));
        add_action('before_delete_post', array($this, 'on_event_deleted'));
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_frontend'));

        add_shortcode('event_rsvp', array($this, 'shortcode_render'));

        // The private feed and its subscribe page belong to the Volunteer
        // module. Serve them here too so RSVPs reach calendars without it.
        if (!class_exists('Azure_Settings') || !Azure_Settings::is_module_enabled('volunteer')) {
            add_action('template_redirect', array('Azure_Volunteer_Signup', 'maybe_serve_calendar'), 0);
            add_action('template_redirect', array('Azure_Volunteer_Signup', 'maybe_serve_subscribe_page'), 0);
        }
    }

    /**
     * create_tables() adds the table on the first admin, AJAX or cron
     * request after an upgrade. A visitor can open an event page before
     * that, so create it here once if needed.
     */
    public static function ensure_table() {
        global $wpdb;
        if (function_exists('get_option') && get_option(self::TABLE_OPTION) === '1') {
            return;
        }
        if (!isset($wpdb) || !method_exists($wpdb, 'query') || !method_exists('Azure_Database', 'event_rsvps_table_sql')) {
            return;
        }
        $sql = preg_replace('/^\s*CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', Azure_Database::event_rsvps_table_sql());
        if ($wpdb->query($sql) !== false && function_exists('update_option')) {
            update_option(self::TABLE_OPTION, '1', true);
        }
    }

    public static function table() {
        return Azure_Database::get_table_name('event_rsvps');
    }

    public static function manage_cap() {
        return class_exists('Azure_Admin_Menu_Customizer') ? Azure_Admin_Menu_Customizer::CAP : 'manage_options';
    }

    // ──────────────────────────────────────────────
    // Per-event settings
    // ──────────────────────────────────────────────

    /**
     * @param int $event_id
     * @return array{enabled:bool,capacity:int,deadline:string,max_guests:int}
     */
    public static function event_settings($event_id) {
        $event_id = (int) $event_id;
        return array(
            'enabled'    => get_post_meta($event_id, self::META_ENABLED, true) === 'yes',
            'capacity'   => max(0, (int) get_post_meta($event_id, self::META_CAPACITY, true)),
            'deadline'   => (string) get_post_meta($event_id, self::META_DEADLINE, true),
            'max_guests' => min(self::GUEST_LIMIT, max(0, (int) get_post_meta($event_id, self::META_MAX_GUESTS, true))),
        );
    }

    /**
     * Editor input → stored meta values. Blank capacity means unlimited,
     * a blank deadline means RSVPs close when the event starts.
     *
     * @param array $input enabled, capacity, deadline (datetime-local), max_guests
     * @return array{enabled:string,capacity:int,deadline:string,max_guests:int}
     */
    public static function sanitize_settings(array $input) {
        $deadline = '';
        $raw = trim((string) ($input['deadline'] ?? ''));
        if ($raw !== '') {
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw, self::timezone())
                ?: DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $raw, self::timezone());
            if ($dt) {
                $deadline = $dt->format('Y-m-d H:i:s');
            }
        }
        $capacity = self::whole_number($input['capacity'] ?? '');
        $guests = self::whole_number($input['max_guests'] ?? '');
        return array(
            'enabled'    => !empty($input['enabled']) ? 'yes' : 'no',
            'capacity'   => max(0, $capacity),
            'deadline'   => $deadline,
            'max_guests' => min(self::GUEST_LIMIT, max(0, $guests)),
        );
    }

    /**
     * @return int The value, 0 for blank, or -1 when it is not a whole number.
     */
    private static function whole_number($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return 0;
        }
        return preg_match('/^\d{1,6}$/', $raw) ? (int) $raw : -1;
    }

    /**
     * Guests requested on the event page. -1 when not a whole number.
     */
    public static function parse_guests($raw) {
        if (is_int($raw)) {
            return $raw >= 0 ? $raw : -1;
        }
        $raw = trim((string) $raw);
        if ($raw === '') {
            return 0;
        }
        return preg_match('/^\d{1,3}$/', $raw) ? (int) $raw : -1;
    }

    // ──────────────────────────────────────────────
    // Time
    // ──────────────────────────────────────────────

    public static function timezone() {
        $name = '';
        if (function_exists('azure_wp_timezone_string')) {
            $name = (string) azure_wp_timezone_string();
        } elseif (function_exists('wp_timezone')) {
            $name = (string) wp_timezone()->getName();
        }
        try {
            return new DateTimeZone($name !== '' ? $name : 'UTC');
        } catch (Exception $e) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * @param DateTimeInterface|string|null $now
     */
    private static function now($now = null) {
        $tz = self::timezone();
        if ($now instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromFormat('U', (string) $now->getTimestamp())->setTimezone($tz);
        }
        try {
            return new DateTimeImmutable(is_string($now) && $now !== '' ? $now : 'now', $tz);
        } catch (Exception $e) {
            return new DateTimeImmutable('now', $tz);
        }
    }

    private static function local($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value, self::timezone());
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * The moment RSVPs stop: the deadline, or the event start without one.
     *
     * @return string Site-time datetime, or '' when the event has no date.
     */
    public static function closes_at($event_id) {
        $settings = self::event_settings($event_id);
        if ($settings['deadline'] !== '') {
            return $settings['deadline'];
        }
        return (string) get_post_meta((int) $event_id, '_EventStartDate', true);
    }

    public static function is_closed($event_id, $now = null) {
        $closes = self::local(self::closes_at($event_id));
        return $closes !== null && self::now($now) >= $closes;
    }

    // ──────────────────────────────────────────────
    // Data
    // ──────────────────────────────────────────────

    public static function get_rsvp($event_id, $user_id) {
        global $wpdb;
        $t = self::table();
        if (!$t || !isset($wpdb)) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t} WHERE event_id = %d AND user_id = %d",
            (int) $event_id,
            (int) $user_id
        ));
    }

    /**
     * @return object[] Oldest first.
     */
    public static function rsvps_for_event($event_id, $status = self::STATUS_GOING) {
        global $wpdb;
        $t = self::table();
        if (!$t || !isset($wpdb)) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE event_id = %d AND status = %s ORDER BY created_at ASC, id ASC",
            (int) $event_id,
            (string) $status
        ));
        return is_array($rows) ? $rows : array();
    }

    /**
     * @return object[]
     */
    public static function rsvps_for_user($user_id) {
        global $wpdb;
        $t = self::table();
        if (!$t || !isset($wpdb) || (int) $user_id <= 0) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE user_id = %d AND status = %s ORDER BY created_at ASC, id ASC",
            (int) $user_id,
            self::STATUS_GOING
        ));
        return is_array($rows) ? $rows : array();
    }

    public static function party_size($rsvp) {
        return 1 + max(0, (int) ($rsvp->guests ?? 0));
    }

    /**
     * People coming, guests included.
     */
    public static function seats_taken($event_id) {
        $total = 0;
        foreach (self::rsvps_for_event($event_id) as $row) {
            $total += self::party_size($row);
        }
        return $total;
    }

    /**
     * @return int|null Spaces left, or null when there is no capacity.
     */
    public static function spaces_left($event_id) {
        $capacity = self::event_settings($event_id)['capacity'];
        if ($capacity <= 0) {
            return null;
        }
        return max(0, $capacity - self::seats_taken($event_id));
    }

    private static function event_post($event_id) {
        $post = function_exists('get_post') ? get_post((int) $event_id) : null;
        if (!$post || ($post->post_type ?? '') !== self::POST_TYPE) {
            return null;
        }
        return $post;
    }

    private static function lock($event_id) {
        global $wpdb;
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', 'azure_rsvp_' . (int) $event_id, 5));
        // NULL means the server could not take a lock at all; carry on and
        // let the unique key catch duplicates.
        return $got === null || (string) $got === '1';
    }

    private static function unlock($event_id) {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'azure_rsvp_' . (int) $event_id));
    }

    private static function result($ok, $code, $message, $rsvp = null) {
        return array('ok' => (bool) $ok, 'code' => (string) $code, 'message' => (string) $message, 'rsvp' => $rsvp);
    }

    /**
     * RSVP one person (plus guests) to an event and send the confirmation.
     *
     * @param int $event_id
     * @param int $user_id
     * @param int $guests Already parsed; -1 for an invalid entry.
     * @param DateTimeInterface|string|null $now
     * @return array{ok:bool,code:string,message:string,rsvp:object|null}
     */
    public static function create($event_id, $user_id, $guests = 0, $now = null) {
        global $wpdb;
        $event_id = (int) $event_id;
        $user_id = (int) $user_id;
        $guests = (int) $guests;

        if ($user_id <= 0) {
            return self::result(false, 'login', __('Please log in to RSVP.', 'azure-plugin'));
        }
        $post = self::event_post($event_id);
        if (!$post || $post->post_status !== 'publish') {
            return self::result(false, 'not_found', __('This event is not available.', 'azure-plugin'));
        }
        $settings = self::event_settings($event_id);
        if (!$settings['enabled']) {
            return self::result(false, 'not_enabled', __('This event does not take RSVPs.', 'azure-plugin'));
        }
        if (self::is_closed($event_id, $now)) {
            return self::result(false, 'closed', __('RSVPs for this event are closed.', 'azure-plugin'));
        }
        if ($guests < 0) {
            return self::result(false, 'guests_invalid', __('Enter a whole number of guests, 0 or more.', 'azure-plugin'));
        }
        if ($guests > $settings['max_guests']) {
            $message = $settings['max_guests'] === 0
                ? __('This event does not allow guests.', 'azure-plugin')
                : sprintf(
                    /* translators: %d: guests allowed */
                    _n('You can bring up to %d guest.', 'You can bring up to %d guests.', $settings['max_guests'], 'azure-plugin'),
                    $settings['max_guests']
                );
            return self::result(false, 'too_many_guests', $message);
        }
        $t = self::table();
        if (!$t || !isset($wpdb)) {
            return self::result(false, 'error', __('Could not save your RSVP. Please try again.', 'azure-plugin'));
        }
        if (!self::lock($event_id)) {
            return self::result(false, 'busy', __('Lots of people are RSVPing right now. Please try again.', 'azure-plugin'));
        }
        try {
            $existing = self::get_rsvp($event_id, $user_id);
            if ($existing && $existing->status === self::STATUS_GOING) {
                return self::result(false, 'duplicate', __('You have already RSVP\'d to this event.', 'azure-plugin'), $existing);
            }
            if ($settings['capacity'] > 0) {
                $left = $settings['capacity'] - self::seats_taken($event_id);
                if ($left < 1 + $guests) {
                    $message = $left <= 0
                        ? __('Sorry, this event is full.', 'azure-plugin')
                        : sprintf(
                            /* translators: %d: spaces left */
                            _n('Only %d space is left.', 'Only %d spaces are left.', $left, 'azure-plugin'),
                            $left
                        );
                    return self::result(false, 'full', $message);
                }
            }

            $user = function_exists('get_userdata') ? get_userdata($user_id) : null;
            $stamp = self::now($now)->format('Y-m-d H:i:s');
            $data = array(
                'guests'       => $guests,
                'status'       => self::STATUS_GOING,
                'user_name'    => $user ? (string) $user->display_name : '',
                'user_email'   => $user ? (string) $user->user_email : '',
                'created_at'   => $stamp,
                'updated_at'   => $stamp,
                'cancelled_at' => null,
            );
            if ($existing) {
                $saved = $wpdb->update($t, $data, array('id' => (int) $existing->id));
            } else {
                $saved = $wpdb->insert($t, array('event_id' => $event_id, 'user_id' => $user_id) + $data);
            }
            if ($saved === false) {
                // The unique key refused a second row from a double click.
                return self::result(false, 'duplicate', __('You have already RSVP\'d to this event.', 'azure-plugin'));
            }
            $rsvp = self::get_rsvp($event_id, $user_id);
        } finally {
            self::unlock($event_id);
        }

        self::send_email('rsvp_confirmation', $user_id, $event_id, $rsvp);
        return self::result(true, 'ok', __('You\'re going! A confirmation email is on its way.', 'azure-plugin'), $rsvp);
    }

    /**
     * @return array{ok:bool,code:string,message:string,rsvp:object|null}
     */
    public static function cancel($event_id, $user_id, $now = null) {
        global $wpdb;
        $event_id = (int) $event_id;
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return self::result(false, 'login', __('Please log in.', 'azure-plugin'));
        }
        $existing = self::get_rsvp($event_id, $user_id);
        if (!$existing || $existing->status !== self::STATUS_GOING) {
            return self::result(false, 'not_going', __('You have not RSVP\'d to this event.', 'azure-plugin'));
        }
        $stamp = self::now($now)->format('Y-m-d H:i:s');
        $saved = $wpdb->update(self::table(), array(
            'status'       => self::STATUS_CANCELLED,
            'updated_at'   => $stamp,
            'cancelled_at' => $stamp,
        ), array('id' => (int) $existing->id));
        if ($saved === false) {
            return self::result(false, 'error', __('Could not cancel your RSVP. Please try again.', 'azure-plugin'));
        }
        $rsvp = self::get_rsvp($event_id, $user_id);
        self::send_email('rsvp_cancellation', $user_id, $event_id, $rsvp);
        return self::result(true, 'ok', __('Your RSVP is cancelled.', 'azure-plugin'), $rsvp);
    }

    /**
     * Permanently deleted events take their RSVPs with them. A trashed
     * event keeps them (Restore brings them back) but leaves every feed.
     */
    public function on_event_deleted($post_id) {
        $post_id = (int) $post_id;
        if ($post_id <= 0 || !function_exists('get_post_type') || get_post_type($post_id) !== self::POST_TYPE) {
            return;
        }
        self::delete_for_event($post_id);
    }

    public static function delete_for_event($event_id) {
        global $wpdb;
        $t = self::table();
        if (!$t || !isset($wpdb) || (int) $event_id <= 0) {
            return 0;
        }
        return (int) $wpdb->delete($t, array('event_id' => (int) $event_id));
    }

    // ──────────────────────────────────────────────
    // Event details (email + feed)
    // ──────────────────────────────────────────────

    /**
     * @return array{title:string,start:string,end:string,all_day:bool,when:string,location:string,description:string,url:string}
     */
    public static function event_details($event_id) {
        $event_id = (int) $event_id;
        $post = self::event_post($event_id);
        $start = (string) get_post_meta($event_id, '_EventStartDate', true);
        $end = (string) get_post_meta($event_id, '_EventEndDate', true);
        $all_day = get_post_meta($event_id, '_EventAllDay', true) === 'yes';

        $location = '';
        if (class_exists('Azure_Event_CPT') && method_exists('Azure_Event_CPT', 'get_in_person_location')) {
            $location = (string) Azure_Event_CPT::get_in_person_location($event_id);
        }
        if ($location === '') {
            $location = trim((string) get_post_meta($event_id, '_EventVenue', true));
        }

        $url = '';
        if ($post && function_exists('get_permalink')) {
            $link = get_permalink($event_id);
            $url = is_string($link) ? $link : '';
        }

        return array(
            'title'       => $post ? (string) $post->post_title : '',
            'start'       => $start,
            'end'         => $end,
            'all_day'     => $all_day,
            'when'        => self::format_when($start, $end, $all_day),
            'location'    => $location,
            'description' => $post ? self::plain_description((string) $post->post_content) : '',
            'url'         => $url,
        );
    }

    /**
     * "Friday, October 23, 2026, 6:00 PM – 7:30 PM PDT"
     */
    public static function format_when($start, $end, $all_day) {
        $s = self::local($start);
        if (!$s) {
            return '';
        }
        $e = self::local($end);
        $date_fmt = 'l, F j, Y';
        if ($all_day) {
            if ($e && $e->format('Ymd') !== $s->format('Ymd')) {
                return $s->format($date_fmt) . ' – ' . $e->format($date_fmt);
            }
            return $s->format($date_fmt) . ' (all day)';
        }
        $out = $s->format($date_fmt) . ', ' . $s->format('g:i A');
        if ($e && $e > $s) {
            $out .= ' – ' . ($e->format('Ymd') === $s->format('Ymd') ? $e->format('g:i A') : $e->format($date_fmt) . ', ' . $e->format('g:i A'));
        }
        return $out . ' ' . $s->format('T');
    }

    private static function plain_description($content) {
        if (function_exists('strip_shortcodes')) {
            $content = strip_shortcodes($content);
        }
        $content = preg_replace('#<(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', "\n", $content);
        $content = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($content) : strip_tags($content);
        $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
        $content = preg_replace("/[ \t]+/", ' ', $content);
        $content = trim(preg_replace("/\n\s*\n+/", "\n\n", $content));
        if (function_exists('mb_strlen') && mb_strlen($content) > 1500) {
            $content = rtrim(mb_substr($content, 0, 1500)) . '…';
        }
        return $content;
    }

    // ──────────────────────────────────────────────
    // Email
    // ──────────────────────────────────────────────

    private static function h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function guests_label($guests) {
        $guests = (int) $guests;
        if ($guests <= 0) {
            return __('Just you', 'azure-plugin');
        }
        /* translators: %d: number of guests */
        return sprintf(_n('You + %d guest', 'You + %d guests', $guests, 'azure-plugin'), $guests);
    }

    /**
     * @return array<string,string>
     */
    public static function email_vars($user, $event_id, $rsvp = null) {
        $d = self::event_details($event_id);
        $user_id = $user ? (int) $user->ID : 0;
        $guests = $rsvp ? (int) ($rsvp->guests ?? 0) : 0;
        $p = '<p style="margin:0 0 8px 0;font-size:15px;line-height:1.5;color:#3c434a;">';

        $details = '';
        if ($d['when'] !== '') {
            $details .= $p . '<strong>When:</strong> ' . self::h($d['when']) . '</p>';
        }
        if ($d['location'] !== '') {
            $details .= $p . '<strong>Where:</strong> ' . self::h($d['location']) . '</p>';
        }
        $details .= $p . '<strong>Party:</strong> ' . self::h(self::guests_label($guests)) . '</p>';
        $details = '<div style="margin:0 0 16px 0;padding:12px 16px;background:#f6f7f7;border-radius:6px;">' . $details . '</div>';

        $description = '';
        if ($d['description'] !== '') {
            $description = '<div style="margin:0 0 16px 0;font-size:15px;line-height:1.5;color:#3c434a;">' . nl2br(self::h($d['description'])) . '</div>';
        }

        $event_link = '';
        if ($d['url'] !== '') {
            $event_link = '<p style="margin:0 0 16px 0;font-size:15px;line-height:1.5;color:#3c434a;"><a href="' . self::h($d['url']) . '" style="color:#0073aa;">View the event</a></p>';
        }

        $subscribe = Azure_Volunteer_Signup::calendar_subscribe_url($user_id);
        $subscribe_button = '';
        if ($subscribe !== '') {
            $subscribe_button = '<p style="text-align:center;margin:24px 0;">'
                . '<a href="' . self::h($subscribe) . '" style="display:inline-block;padding:14px 28px;background:#0078d4;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;font-size:15px;">Subscribe</a>'
                . '</p>'
                . '<p style="margin:0 0 16px 0;font-size:15px;line-height:1.5;color:#3c434a;">Subscribe once. Your calendar checks this private link and adds or drops events when you RSVP, sign up or cancel.</p>';
        }

        return array(
            'name'             => $user ? (string) $user->display_name : '',
            'event'            => $d['title'],
            'when'             => $d['when'],
            'location'         => $d['location'],
            'guests'           => self::guests_label($guests),
            'details'          => $details,
            'description'      => $description,
            'event_link'       => $event_link,
            'event_url'        => $d['url'],
            'subscribe_url'    => $subscribe,
            'subscribe_button' => $subscribe_button,
            'site_name'        => Azure_Email_Messages::site_name(),
        );
    }

    /**
     * @return array{0:string,1:string} subject, body
     */
    public static function compose($key, $user, $event_id, $rsvp = null) {
        return Azure_Email_Messages::render($key, self::email_vars($user, $event_id, $rsvp));
    }

    private static function send_email($key, $user_id, $event_id, $rsvp) {
        $user = function_exists('get_userdata') ? get_userdata((int) $user_id) : null;
        if (!$user || empty($user->user_email)) {
            return false;
        }
        list($subject, $body) = self::compose($key, $user, $event_id, $rsvp);
        if ($subject === '' || $body === '') {
            return false;
        }
        return wp_mail($user->user_email, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
    }

    // ──────────────────────────────────────────────
    // Calendar feed
    // ──────────────────────────────────────────────

    private static function ics_escape($s) {
        return preg_replace(
            array('/\\\\/', '/,/', '/;/', "/\r\n|\r|\n/"),
            array('\\\\', '\\,', '\\;', '\\n'),
            (string) $s
        );
    }

    /**
     * One VEVENT per upcoming, published event this person is going to.
     * Cancelled RSVPs, trashed or deleted events and past events drop out.
     *
     * @param int $user_id
     * @param DateTimeInterface|string|null $now
     * @return string[]
     */
    public static function feed_vevents_for_user($user_id, $now = null) {
        $out = array();
        $current = self::now($now);
        foreach (self::rsvps_for_user($user_id) as $rsvp) {
            $post = self::event_post((int) $rsvp->event_id);
            if (!$post || $post->post_status !== 'publish') {
                continue;
            }
            $d = self::event_details((int) $rsvp->event_id);
            $start = self::local($d['start']);
            if (!$start) {
                continue;
            }
            $end = self::local($d['end']);
            if (!$end || $end < $start) {
                $end = $d['all_day'] ? $start : $start->modify('+1 hour');
            }
            $last = $d['all_day'] ? $end->setTime(23, 59, 59) : $end;
            if ($last < $current) {
                continue;
            }
            $vevent = self::event_vevent($rsvp, $d, $start, $end);
            if ($vevent !== '') {
                $out[] = $vevent;
            }
        }
        return $out;
    }

    private static function event_vevent($rsvp, array $d, DateTimeImmutable $start, DateTimeImmutable $end) {
        $host = 'localhost';
        if (function_exists('home_url')) {
            $parsed = parse_url(home_url(), PHP_URL_HOST);
            if (is_string($parsed) && $parsed !== '') {
                $host = $parsed;
            }
        }
        $utc = new DateTimeZone('UTC');
        $lines = array(
            'BEGIN:VEVENT',
            'UID:pta-rsvp-' . (int) $rsvp->event_id . '-' . (int) $rsvp->user_id . '@' . $host,
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        );
        if ($d['all_day']) {
            $lines[] = 'DTSTART;VALUE=DATE:' . $start->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:' . $end->modify('+1 day')->format('Ymd');
        } else {
            $lines[] = 'DTSTART:' . $start->setTimezone($utc)->format('Ymd\THis\Z');
            $lines[] = 'DTEND:' . $end->setTimezone($utc)->format('Ymd\THis\Z');
        }
        $desc = 'RSVP: ' . self::guests_label((int) $rsvp->guests);
        if ($d['description'] !== '') {
            $desc .= "\n\n" . $d['description'];
        }
        $lines[] = 'SUMMARY:' . self::ics_escape($d['title'] !== '' ? $d['title'] : 'Event');
        $lines[] = 'DESCRIPTION:' . self::ics_escape($desc);
        $lines[] = 'LOCATION:' . self::ics_escape($d['location']);
        if ($d['url'] !== '') {
            $lines[] = 'URL:' . $d['url'];
        }
        $lines[] = 'END:VEVENT';
        return implode("\r\n", $lines);
    }

    // ──────────────────────────────────────────────
    // Front end
    // ──────────────────────────────────────────────

    public function shortcode_render($atts) {
        $atts = shortcode_atts(array('id' => 0), $atts, 'event_rsvp');
        $event_id = (int) $atts['id'];
        if ($event_id <= 0 && function_exists('get_the_ID')) {
            $event_id = (int) get_the_ID();
        }
        return self::render_for_event($event_id);
    }

    /**
     * The RSVP box on an event page. Empty when RSVP is off for the event.
     *
     * @param int      $event_id
     * @param int|null $user_id Defaults to the signed-in user.
     * @param DateTimeInterface|string|null $now
     * @return string
     */
    public static function render_for_event($event_id, $user_id = null, $now = null) {
        $event_id = (int) $event_id;
        $post = self::event_post($event_id);
        $settings = self::event_settings($event_id);
        if (!$post || $post->post_status !== 'publish' || !$settings['enabled']) {
            return '';
        }
        if ($user_id === null) {
            $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        }
        $user_id = (int) $user_id;
        $rsvp = $user_id > 0 ? self::get_rsvp($event_id, $user_id) : null;
        $going = $rsvp && $rsvp->status === self::STATUS_GOING;
        $closed = self::is_closed($event_id, $now);
        $taken = self::seats_taken($event_id);
        $capacity = $settings['capacity'];
        $left = $capacity > 0 ? max(0, $capacity - $taken) : null;

        $summary = array();
        if ($capacity > 0) {
            /* translators: 1: people coming, 2: capacity */
            $summary[] = sprintf(__('%1$d of %2$d spots taken', 'azure-plugin'), $taken, $capacity);
        } elseif ($taken > 0) {
            /* translators: %d: people coming */
            $summary[] = sprintf(_n('%d person going', '%d people going', $taken, 'azure-plugin'), $taken);
        }
        $closes = self::local(self::closes_at($event_id));
        if ($closes && !$closed && $settings['deadline'] !== '') {
            /* translators: %s: deadline */
            $summary[] = sprintf(__('RSVP by %s', 'azure-plugin'), $closes->format('l, F j, g:i A'));
        }

        $h = function ($v) {
            return self::h($v);
        };
        $out = '<section class="pta-event-rsvp" data-event-id="' . $event_id . '">';
        $out .= '<h2 class="pta-event-section">' . $h(__('RSVP', 'azure-plugin')) . '</h2>';
        if ($summary) {
            $out .= '<p class="pta-rsvp-summary">' . $h(implode(' · ', $summary)) . '</p>';
        }

        if ($going) {
            $out .= '<p class="pta-rsvp-status">' . $h(sprintf(
                /* translators: %s: "Just you" or "You + 2 guests" */
                __('You\'re going. %s.', 'azure-plugin'),
                self::guests_label((int) $rsvp->guests)
            )) . '</p>';
            $out .= '<button type="button" class="button pta-rsvp-cancel">' . $h(__('Cancel RSVP', 'azure-plugin')) . '</button>';
        } elseif ($closed) {
            $out .= '<p class="pta-rsvp-status">' . $h(__('RSVPs for this event are closed.', 'azure-plugin')) . '</p>';
        } elseif ($left === 0) {
            $out .= '<p class="pta-rsvp-status">' . $h(__('Sorry, this event is full.', 'azure-plugin')) . '</p>';
        } elseif ($user_id <= 0) {
            $here = function_exists('get_permalink') ? (string) get_permalink($event_id) : '';
            $login = function_exists('wp_login_url') ? wp_login_url($here) : '';
            $out .= '<p class="pta-rsvp-status">' . $h(__('Log in to RSVP to this event.', 'azure-plugin')) . '</p>';
            $out .= '<a class="button pta-rsvp-login" href="' . $h($login) . '">' . $h(__('Log in to RSVP', 'azure-plugin')) . '</a>';
        } else {
            $max = $settings['max_guests'];
            if ($left !== null) {
                $max = min($max, max(0, $left - 1));
            }
            $out .= '<div class="pta-rsvp-form">';
            if ($max > 0) {
                $out .= '<label class="pta-rsvp-guests">' . $h(__('Guests', 'azure-plugin')) . ' <select name="guests">';
                for ($i = 0; $i <= $max; $i++) {
                    $out .= '<option value="' . $i . '">' . $i . '</option>';
                }
                $out .= '</select></label> ';
            }
            $out .= '<button type="button" class="button button-primary pta-rsvp-submit">' . $h(__('RSVP', 'azure-plugin')) . '</button>';
            $out .= '</div>';
        }
        $out .= '<p class="pta-rsvp-message" role="status" aria-live="polite"></p>';
        $out .= '</section>';
        return $out;
    }

    public function maybe_enqueue_frontend() {
        global $post;
        $event_id = 0;
        if (function_exists('is_singular') && is_singular(self::POST_TYPE) && $post) {
            $event_id = (int) $post->ID;
        }
        $shortcode = $post && function_exists('has_shortcode') && has_shortcode((string) $post->post_content, 'event_rsvp');
        if (!$shortcode && ($event_id <= 0 || !self::event_settings($event_id)['enabled'])) {
            return;
        }
        $ver = defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : '1.0';
        wp_enqueue_style('azure-event-rsvp', AZURE_PLUGIN_URL . 'css/event-rsvp.css', array(), $ver);
        wp_enqueue_script('azure-event-rsvp', AZURE_PLUGIN_URL . 'js/event-rsvp.js', array('jquery'), $ver, true);
        wp_localize_script('azure-event-rsvp', 'azureRsvp', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE),
            'i18n'    => array(
                'saving'         => __('Saving...', 'azure-plugin'),
                'error'          => __('Something went wrong. Please try again.', 'azure-plugin'),
                'confirm_cancel' => __('Cancel your RSVP?', 'azure-plugin'),
            ),
        ));
    }

    // ──────────────────────────────────────────────
    // AJAX
    // ──────────────────────────────────────────────

    public function ajax_login_required() {
        wp_send_json_error(array('message' => __('Please log in to RSVP.', 'azure-plugin'), 'code' => 'login'));
    }

    private function ajax_guard() {
        check_ajax_referer(self::NONCE, 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id || !current_user_can('read')) {
            $this->ajax_login_required();
        }
        return (int) $user_id;
    }

    private function ajax_reply(array $result, $event_id, $user_id) {
        $payload = array(
            'message' => $result['message'],
            'code'    => $result['code'],
            'html'    => self::render_for_event($event_id, $user_id),
        );
        if ($result['ok']) {
            wp_send_json_success($payload);
        }
        wp_send_json_error($payload);
    }

    public function ajax_create() {
        $user_id = $this->ajax_guard();
        $event_id = absint($_POST['event_id'] ?? 0);
        $guests = self::parse_guests(isset($_POST['guests']) ? wp_unslash($_POST['guests']) : '0');
        $this->ajax_reply(self::create($event_id, $user_id, $guests), $event_id, $user_id);
    }

    public function ajax_cancel() {
        $user_id = $this->ajax_guard();
        $event_id = absint($_POST['event_id'] ?? 0);
        $this->ajax_reply(self::cancel($event_id, $user_id), $event_id, $user_id);
    }

    // ──────────────────────────────────────────────
    // Event editor
    // ──────────────────────────────────────────────

    public function register_metabox() {
        add_meta_box(
            'pta_event_rsvp',
            __('RSVP', 'azure-plugin'),
            array($this, 'render_metabox'),
            self::POST_TYPE,
            'side',
            'default'
        );
    }

    public function render_metabox($post) {
        wp_nonce_field(self::EDITOR_NONCE, 'pta_event_rsvp_nonce');
        $s = self::event_settings((int) $post->ID);
        $deadline = '';
        if ($s['deadline'] !== '' && ($dt = self::local($s['deadline']))) {
            $deadline = $dt->format('Y-m-d\TH:i');
        }
        $going = $s['enabled'] ? self::seats_taken((int) $post->ID) : 0;
        ?>
        <p>
            <label><input type="checkbox" name="pta_rsvp_enabled" value="yes" <?php checked($s['enabled']); ?>> <?php esc_html_e('Enable RSVP', 'azure-plugin'); ?></label>
        </p>
        <p>
            <label for="pta_rsvp_capacity"><?php esc_html_e('Capacity (people, guests included)', 'azure-plugin'); ?></label><br>
            <input type="number" min="0" step="1" id="pta_rsvp_capacity" name="pta_rsvp_capacity" value="<?php echo $s['capacity'] > 0 ? esc_attr((string) $s['capacity']) : ''; ?>" placeholder="<?php esc_attr_e('No limit', 'azure-plugin'); ?>" style="width:100%;">
        </p>
        <p>
            <label for="pta_rsvp_deadline"><?php esc_html_e('RSVP deadline', 'azure-plugin'); ?></label><br>
            <input type="datetime-local" id="pta_rsvp_deadline" name="pta_rsvp_deadline" value="<?php echo esc_attr($deadline); ?>" style="width:100%;">
            <span class="description"><?php esc_html_e('Blank closes RSVPs when the event starts.', 'azure-plugin'); ?></span>
        </p>
        <p>
            <label for="pta_rsvp_max_guests"><?php esc_html_e('Plus-guests allowed per RSVP', 'azure-plugin'); ?></label><br>
            <input type="number" min="0" max="<?php echo (int) self::GUEST_LIMIT; ?>" step="1" id="pta_rsvp_max_guests" name="pta_rsvp_max_guests" value="<?php echo $s['max_guests'] > 0 ? esc_attr((string) $s['max_guests']) : ''; ?>" placeholder="0" style="width:100%;">
        </p>
        <?php if ($s['enabled']) : ?>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-rsvp&event_id=' . (int) $post->ID)); ?>">
                <?php
                /* translators: %d: people coming */
                echo esc_html(sprintf(_n('%d person going — view RSVPs', '%d people going — view RSVPs', $going, 'azure-plugin'), $going));
                ?>
            </a></p>
        <?php endif;
    }

    public function save_metabox($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id)) {
            return;
        }
        if (!isset($_POST['pta_event_rsvp_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pta_event_rsvp_nonce'])), self::EDITOR_NONCE)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        $clean = self::sanitize_settings(array(
            'enabled'    => !empty($_POST['pta_rsvp_enabled']),
            'capacity'   => isset($_POST['pta_rsvp_capacity']) ? wp_unslash($_POST['pta_rsvp_capacity']) : '',
            'deadline'   => isset($_POST['pta_rsvp_deadline']) ? wp_unslash($_POST['pta_rsvp_deadline']) : '',
            'max_guests' => isset($_POST['pta_rsvp_max_guests']) ? wp_unslash($_POST['pta_rsvp_max_guests']) : '',
        ));
        self::save_settings((int) $post_id, $clean);
    }

    public static function save_settings($event_id, array $clean) {
        $map = array(
            self::META_ENABLED    => $clean['enabled'] === 'yes' ? 'yes' : '',
            self::META_CAPACITY   => $clean['capacity'] > 0 ? (int) $clean['capacity'] : '',
            self::META_DEADLINE   => (string) $clean['deadline'],
            self::META_MAX_GUESTS => $clean['max_guests'] > 0 ? (int) $clean['max_guests'] : '',
        );
        foreach ($map as $key => $value) {
            if ($value === '') {
                delete_post_meta($event_id, $key);
            } else {
                update_post_meta($event_id, $key, $value);
            }
        }
    }

    // ──────────────────────────────────────────────
    // Admin list + CSV
    // ──────────────────────────────────────────────

    /**
     * Events with RSVP turned on, soonest first then past ones.
     *
     * @return WP_Post[]
     */
    public static function rsvp_events() {
        if (!function_exists('get_posts')) {
            return array();
        }
        return get_posts(array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => array('publish', 'future', 'draft', 'private'),
            'posts_per_page' => 200,
            'meta_key'       => '_EventStartDate',
            'orderby'        => 'meta_value',
            'order'          => 'DESC',
            'meta_query'     => array(array('key' => self::META_ENABLED, 'value' => 'yes')),
        ));
    }

    public static function display_name($rsvp) {
        $name = trim((string) ($rsvp->user_name ?? ''));
        if ($name === '' && function_exists('get_userdata') && ($u = get_userdata((int) $rsvp->user_id))) {
            $name = (string) $u->display_name;
        }
        return $name;
    }

    public static function display_email($rsvp) {
        $email = trim((string) ($rsvp->user_email ?? ''));
        if ($email === '' && function_exists('get_userdata') && ($u = get_userdata((int) $rsvp->user_id))) {
            $email = (string) $u->user_email;
        }
        return $email;
    }

    public static function export_url($event_id) {
        $url = admin_url('admin-post.php?action=azure_rsvp_export&event_id=' . (int) $event_id);
        return function_exists('wp_nonce_url') ? wp_nonce_url($url, self::EXPORT_NONCE) : $url;
    }

    /**
     * Neutralise spreadsheet formulas. Phone-like values are left alone.
     */
    public static function csv_cell($value) {
        $value = (string) $value;
        if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false && !preg_match('/^[+-]?[\d\s().-]+$/', $value)) {
            $value = "'" . $value;
        }
        return '"' . str_replace('"', '""', $value) . '"';
    }

    /**
     * @return string[] CSV lines, header first.
     */
    public static function csv_lines($event_id) {
        $lines = array(implode(',', array_map(array(__CLASS__, 'csv_cell'), array('Name', 'Email', 'Guests', 'Party size', 'RSVP\'d at'))) . "\n");
        foreach (self::rsvps_for_event($event_id) as $row) {
            $lines[] = implode(',', array_map(array(__CLASS__, 'csv_cell'), array(
                self::display_name($row),
                self::display_email($row),
                (int) $row->guests,
                self::party_size($row),
                (string) $row->created_at,
            ))) . "\n";
        }
        return $lines;
    }

    public function export_csv() {
        if (!current_user_can(self::manage_cap())) {
            wp_die(esc_html__('You do not have permission to export RSVPs.', 'azure-plugin'), 403);
        }
        check_admin_referer(self::EXPORT_NONCE);
        $event_id = absint($_GET['event_id'] ?? 0);
        $post = self::event_post($event_id);
        if (!$post) {
            wp_die(esc_html__('Event not found.', 'azure-plugin'), 404);
        }
        $filename = sanitize_file_name(sanitize_title($post->post_title) . '-rsvps-' . wp_date('Y-m-d') . '.csv');
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";
        foreach (self::csv_lines($event_id) as $line) {
            echo $line;
        }
        exit;
    }
}
