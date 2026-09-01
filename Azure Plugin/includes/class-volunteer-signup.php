<?php
/**
 * Volunteer Sign Up Module
 *
 * SignUpGenius-style volunteer coordination linked to PTA events.
 * Admins create sign-up sheets with activities/slots; users claim spots.
 *
 * Linked event posts are the plugin's own `pta_event` CPT (see
 * class-event-cpt.php). Meta keys (_EventStartDate, _EventVenueID) are
 * intentionally shared with the legacy TEC schema for backward-compat;
 * see docs/tec-retirement-audit-2026-05-22.md for the migration history.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Azure_Volunteer_Signup {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        self::ensure_slot_columns();

        // Admin AJAX
        add_action('wp_ajax_azure_volunteer_save_sheet', array($this, 'ajax_save_sheet'));
        add_action('wp_ajax_azure_volunteer_delete_sheet', array($this, 'ajax_delete_sheet'));
        add_action('wp_ajax_azure_volunteer_get_sheet', array($this, 'ajax_get_sheet'));

        // Frontend AJAX (logged-in users)
        add_action('wp_ajax_azure_volunteer_signup', array($this, 'ajax_signup'));
        add_action('wp_ajax_azure_volunteer_withdraw', array($this, 'ajax_withdraw'));

        // Guests get a "must login" response
        add_action('wp_ajax_nopriv_azure_volunteer_signup', array($this, 'ajax_login_required'));
        add_action('wp_ajax_nopriv_azure_volunteer_withdraw', array($this, 'ajax_login_required'));

        // Shortcode
        add_shortcode('volunteer_signup', array($this, 'shortcode_render'));

        // Frontend assets
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_frontend'));

        // Reminder cron
        add_action('azure_volunteer_send_reminders', array($this, 'send_reminders'));
        if (!wp_next_scheduled('azure_volunteer_send_reminders')) {
            wp_schedule_event(time(), 'daily', 'azure_volunteer_send_reminders');
        }
    }

    // ──────────────────────────────────────────────
    // Data helpers
    // ──────────────────────────────────────────────

    public static function get_sheets($status = 'all') {
        global $wpdb;
        $t = Azure_Database::get_table_name('volunteer_sheets');
        if (!$t) {
            return array();
        }
        $sql = "SELECT * FROM {$t}";
        if ($status !== 'all') {
            $sql .= $wpdb->prepare(" WHERE status = %s", $status);
        }
        $sql .= " ORDER BY event_date ASC, created_at DESC";
        return $wpdb->get_results($sql);
    }

    public static function get_sheet($id) {
        global $wpdb;
        $t = Azure_Database::get_table_name('volunteer_sheets');
        return $t ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $id)) : null;
    }

    public static function get_activities($sheet_id) {
        global $wpdb;
        $t = Azure_Database::get_table_name('volunteer_activities');
        return $t ? $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE sheet_id = %d ORDER BY sort_order ASC, id ASC",
            $sheet_id
        )) : array();
    }

    public static function get_signups_for_activity($activity_id) {
        global $wpdb;
        $t = Azure_Database::get_table_name('volunteer_signups');
        return $t ? $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE activity_id = %d ORDER BY signed_up_at ASC",
            $activity_id
        )) : array();
    }

    public static function count_signups($activity_id) {
        global $wpdb;
        $t = Azure_Database::get_table_name('volunteer_signups');
        return $t ? (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE activity_id = %d",
            $activity_id
        )) : 0;
    }

    public static function ensure_slot_columns() {
        global $wpdb;
        $t = Azure_Database::get_table_name('volunteer_activities');
        if (!$t || !isset($wpdb)) {
            return;
        }
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$t}", 0);
        if (!is_array($cols)) {
            return;
        }
        if (!in_array('slot_start', $cols, true)) {
            $wpdb->query("ALTER TABLE {$t} ADD COLUMN slot_start datetime DEFAULT NULL");
        }
        if (!in_array('slot_end', $cols, true)) {
            $wpdb->query("ALTER TABLE {$t} ADD COLUMN slot_end datetime DEFAULT NULL");
        }
    }

    public static function pacific_timezone() {
        return 'America/Los_Angeles';
    }

    /**
     * Accept a full datetime or a time-of-day and pin it to the sheet date.
     */
    public static function normalize_slot_datetime($value, $sheet_date = '') {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $value)) {
            return str_replace('T', ' ', substr($value, 0, 16)) . ':00';
        }
        $date_part = '';
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) $sheet_date, $m)) {
            $date_part = $m[1];
        }
        if ($date_part === '') {
            return '';
        }
        if (preg_match('/^(\d{1,2}:\d{2})/', $value, $tm)) {
            $time = $tm[1];
            if (strlen($time) === 4) {
                $time = '0' . $time;
            }
            return $date_part . ' ' . $time . ':00';
        }
        return '';
    }

    /**
     * @return array{start:?string,end:?string}
     */
    public static function slot_bounds($sheet, $activity) {
        $start = '';
        $end = '';
        if (is_object($activity)) {
            $start = isset($activity->slot_start) ? trim((string) $activity->slot_start) : '';
            $end = isset($activity->slot_end) ? trim((string) $activity->slot_end) : '';
        }
        if ($start === '' && is_object($sheet) && !empty($sheet->event_date)) {
            $start = (string) $sheet->event_date;
        }
        if ($start !== '' && $end === '') {
            $ts = strtotime($start);
            $end = $ts ? date('Y-m-d H:i:s', $ts + HOUR_IN_SECONDS) : '';
        }
        return array(
            'start' => $start !== '' ? $start : null,
            'end'   => $end !== '' ? $end : null,
        );
    }

    public static function slot_time_label($sheet, $activity) {
        $bounds = self::slot_bounds($sheet, $activity);
        if (empty($bounds['start'])) {
            return '';
        }
        try {
            $tz = new DateTimeZone(self::pacific_timezone());
            $start = new DateTime($bounds['start'], $tz);
            $label = $start->format('g:i A');
            if (!empty($bounds['end'])) {
                $end = new DateTime($bounds['end'], $tz);
                $label .= ' – ' . $end->format('g:i A');
            }
            return $label;
        } catch (Exception $e) {
            return '';
        }
    }

    public static function build_slot_ics($sheet, $activity, $user = null) {
        $bounds = self::slot_bounds($sheet, $activity);
        if (empty($bounds['start'])) {
            return '';
        }
        $tz_name = self::pacific_timezone();
        try {
            $tz = new DateTimeZone($tz_name);
            $start = new DateTime($bounds['start'], $tz);
            $end = !empty($bounds['end'])
                ? new DateTime($bounds['end'], $tz)
                : (clone $start)->modify('+1 hour');
            $utc = new DateTimeZone('UTC');
            $start->setTimezone($utc);
            $end->setTimezone($utc);
        } catch (Exception $e) {
            return '';
        }
        $host = function_exists('home_url') ? (string) parse_url(home_url(), PHP_URL_HOST) : 'wilderptsa.net';
        $uid = 'pta-volunteer-' . (int) ($activity->id ?? 0) . '-' . (int) ($user ? $user->ID : 0) . '@' . $host;
        $summary = trim(($sheet && $sheet->title ? $sheet->title . ': ' : '') . ($activity->name ?? 'Volunteer'));
        $location = ($sheet && !empty($sheet->event_location)) ? (string) $sheet->event_location : '';
        $desc = trim((string) ($activity->description ?? ''));
        $esc = function ($s) {
            return preg_replace(
                array('/\\\\/', '/,/', '/;/', "/\r\n|\r|\n/"),
                array('\\\\', '\\,', '\\;', '\\n'),
                (string) $s
            );
        };
        $lines = array(
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//PTA Tools//Volunteer Slot//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . $start->format('Ymd\THis\Z'),
            'DTEND:' . $end->format('Ymd\THis\Z'),
            'SUMMARY:' . $esc($summary),
            'DESCRIPTION:' . $esc($desc !== '' ? $desc : $summary),
            'LOCATION:' . $esc($location),
            'END:VEVENT',
            'END:VCALENDAR',
        );
        return implode("\r\n", $lines) . "\r\n";
    }

    public static function user_signed_up($activity_id, $user_id) {
        global $wpdb;
        $t = Azure_Database::get_table_name('volunteer_signups');
        return $t ? (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE activity_id = %d AND user_id = %d",
            $activity_id,
            $user_id
        )) : false;
    }

    // ──────────────────────────────────────────────
    // Admin AJAX — save sheet + activities
    // ──────────────────────────────────────────────

    public function ajax_save_sheet() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }

        global $wpdb;
        $sheets_t = Azure_Database::get_table_name('volunteer_sheets');
        $activities_t = Azure_Database::get_table_name('volunteer_activities');

        $sheet_id    = absint($_POST['sheet_id'] ?? 0);
        $title       = sanitize_text_field($_POST['title'] ?? '');
        $description = sanitize_textarea_field($_POST['description'] ?? '');
        // `pta_event_id` is the new field; accept the legacy `tec_event_id` POST
        // key too so any cached admin JS keeps working until the next refresh.
        $assign      = !empty($_POST['assign_to_event']);
        $event_choice = isset($_POST['pta_event_id']) ? wp_unslash($_POST['pta_event_id']) : ($_POST['tec_event_id'] ?? '0');
        $event_date  = sanitize_text_field($_POST['event_date'] ?? '');
        $event_loc   = sanitize_text_field($_POST['event_location'] ?? '');
        $status      = in_array($_POST['status'] ?? '', array('open', 'closed'), true) ? $_POST['status'] : 'open';

        if (empty($title)) {
            wp_send_json_error('Title is required.');
        }

        $new_event_title = sanitize_text_field($_POST['new_event_title'] ?? '');
        $event_id = self::resolve_assigned_event_id(
            $assign,
            $event_choice,
            $new_event_title !== '' ? $new_event_title : $title,
            $event_date,
            $event_loc
        );
        if ($assign && $event_id <= 0) {
            wp_send_json_error('Select an event or create a new one.');
        }

        // Auto-populate from the linked pta_event if the admin picked one.
        // Meta keys (_EventStartDate, _EventVenueID) are inherited from TEC's
        // schema so this is a straight read regardless of legacy origin.
        if ($event_id) {
            $event_post = get_post($event_id);
            if ($event_post && in_array($event_post->post_type, array('pta_event', 'tribe_events'), true)) {
                if (empty($title)) {
                    $title = $event_post->post_title;
                }
                $start = get_post_meta($event_id, '_EventStartDate', true);
                if ($start) {
                    $event_date = $start;
                }
                $venue_id = get_post_meta($event_id, '_EventVenueID', true);
                if ($venue_id && empty($event_loc)) {
                    $event_loc = get_the_title($venue_id);
                }
            }
        }

        $data = array(
            'title'          => $title,
            'description'    => $description,
            'pta_event_id'   => $event_id,
            'event_date'     => $event_date ?: null,
            'event_location' => $event_loc,
            'status'         => $status,
        );

        if ($sheet_id) {
            $wpdb->update($sheets_t, $data, array('id' => $sheet_id));
        } else {
            $data['created_by'] = get_current_user_id();
            $wpdb->insert($sheets_t, $data);
            $sheet_id = $wpdb->insert_id;
        }

        // Sync activities (sent as JSON array)
        $activities_json = $_POST['activities'] ?? '[]';
        $activities = json_decode(stripslashes($activities_json), true);
        if (!is_array($activities)) {
            $activities = array();
        }

        // Get existing activity IDs
        $existing_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$activities_t} WHERE sheet_id = %d",
            $sheet_id
        ));
        $keep_ids = array();

        foreach ($activities as $i => $act) {
            $act_name  = sanitize_text_field($act['name'] ?? '');
            $act_desc  = sanitize_textarea_field($act['description'] ?? '');
            $act_spots = max(1, absint($act['spots_needed'] ?? 1));
            $act_id    = absint($act['id'] ?? 0);
            $slot_start = self::normalize_slot_datetime($act['slot_start'] ?? '', $event_date);
            $slot_end   = self::normalize_slot_datetime($act['slot_end'] ?? '', $event_date);

            if (empty($act_name)) {
                continue;
            }

            $act_data = array(
                'name'         => $act_name,
                'description'  => $act_desc,
                'spots_needed' => $act_spots,
                'slot_start'   => $slot_start !== '' ? $slot_start : null,
                'slot_end'     => $slot_end !== '' ? $slot_end : null,
                'sort_order'   => $i,
            );

            if ($act_id && in_array($act_id, $existing_ids)) {
                $wpdb->update($activities_t, $act_data, array('id' => $act_id));
                $keep_ids[] = $act_id;
            } else {
                $act_data['sheet_id'] = $sheet_id;
                $wpdb->insert($activities_t, $act_data);
                $keep_ids[] = $wpdb->insert_id;
            }
        }

        // Delete removed activities (and their signups)
        $signups_t = Azure_Database::get_table_name('volunteer_signups');
        $remove_ids = array_diff($existing_ids, $keep_ids);
        foreach ($remove_ids as $rid) {
            $wpdb->delete($signups_t, array('activity_id' => $rid));
            $wpdb->delete($activities_t, array('id' => $rid));
        }

        wp_send_json_success(array('sheet_id' => $sheet_id));
    }

    public function ajax_delete_sheet() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }

        global $wpdb;
        $sheet_id = absint($_POST['sheet_id'] ?? 0);
        if (!$sheet_id) {
            wp_send_json_error('Invalid sheet.');
        }

        $activities_t = Azure_Database::get_table_name('volunteer_activities');
        $signups_t    = Azure_Database::get_table_name('volunteer_signups');
        $sheets_t     = Azure_Database::get_table_name('volunteer_sheets');

        $act_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$activities_t} WHERE sheet_id = %d", $sheet_id));
        foreach ($act_ids as $aid) {
            $wpdb->delete($signups_t, array('activity_id' => $aid));
        }
        $wpdb->delete($activities_t, array('sheet_id' => $sheet_id));
        $wpdb->delete($sheets_t, array('id' => $sheet_id));

        wp_send_json_success();
    }

    public function ajax_get_sheet() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');

        // Admin sheet editor only. Without this any logged-in user could walk
        // sheet_id and read every sheet's activities and spot counts.
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }

        $id = absint($_GET['sheet_id'] ?? $_POST['sheet_id'] ?? 0);
        $sheet = self::get_sheet($id);
        if (!$sheet) {
            wp_send_json_error('Sheet not found.');
        }
        $activities = self::get_activities($id);
        $acts_out = array();
        foreach ($activities as $a) {
            $acts_out[] = array(
                'id'           => (int) $a->id,
                'name'         => $a->name,
                'description'  => $a->description ?? '',
                'spots_needed' => (int) $a->spots_needed,
                'slot_start'   => isset($a->slot_start) ? (string) $a->slot_start : '',
                'slot_end'     => isset($a->slot_end) ? (string) $a->slot_end : '',
            );
        }
        $event_id = (int) ($sheet->pta_event_id ?? 0);
        $event_title = '';
        if ($event_id && function_exists('get_the_title')) {
            $event_title = (string) get_the_title($event_id);
        }
        wp_send_json_success(array(
            'sheet'       => $sheet,
            'activities'  => $acts_out,
            'event_title' => $event_title,
        ));
    }

    /**
     * @param bool   $assign
     * @param mixed  $choice  Event ID, or "__new__" to create one.
     * @return int Event post ID, or 0 when unassigned / create failed.
     */
    public static function resolve_assigned_event_id($assign, $choice, $title = '', $event_date = '', $location = '') {
        if (!$assign) {
            return 0;
        }
        $choice = is_string($choice) ? trim($choice) : (string) $choice;
        if ($choice === '__new__' || $choice === 'new') {
            return self::create_event_from_sheet_fields($title, $event_date, $location);
        }
        $id = absint($choice);
        if (!$id) {
            return 0;
        }
        if (function_exists('get_post_type') && get_post_type($id) && get_post_type($id) !== 'pta_event') {
            $legacy = get_post_type($id);
            if ($legacy !== 'tribe_events') {
                return 0;
            }
        }
        return $id;
    }

    /**
     * Publish a pta_event from sheet fields so the signup can show on it.
     *
     * @return int New post ID, or 0 on failure.
     */
    public static function create_event_from_sheet_fields($title, $event_date = '', $location = '') {
        $title = trim((string) $title);
        if ($title === '' || !function_exists('wp_insert_post')) {
            return 0;
        }
        $post_id = wp_insert_post(array(
            'post_type'    => 'pta_event',
            'post_status'  => 'publish',
            'post_title'   => $title,
            'post_content' => '',
        ), true);
        if (is_wp_error($post_id) || !$post_id) {
            return 0;
        }
        $start = trim((string) $event_date);
        if ($start === '') {
            $start = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
        }
        $start_ts = strtotime($start);
        $end = $start_ts ? date('Y-m-d H:i:s', $start_ts + HOUR_IN_SECONDS) : $start;
        $tz = function_exists('azure_wp_timezone_string') ? azure_wp_timezone_string() : 'America/Los_Angeles';
        update_post_meta($post_id, '_EventStartDate', $start);
        update_post_meta($post_id, '_EventEndDate', $end);
        update_post_meta($post_id, '_EventAllDay', 'no');
        update_post_meta($post_id, '_EventTimezone', $tz);
        $location = trim((string) $location);
        if ($location !== '') {
            update_post_meta($post_id, '_EventVenue', $location);
            update_post_meta($post_id, '_pta_event_venue_source', 'manual');
        }
        return (int) $post_id;
    }

    /**
     * @param int $event_id
     * @return object[]
     */
    public static function get_sheets_for_event($event_id) {
        global $wpdb;
        $event_id = (int) $event_id;
        $t = Azure_Database::get_table_name('volunteer_sheets');
        if (!$t || !$event_id || !isset($wpdb)) {
            return array();
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE pta_event_id = %d ORDER BY event_date ASC, id ASC",
            $event_id
        ));
    }

    /**
     * HTML for every open/closed sheet assigned to a pta_event.
     */
    public static function render_for_event($event_id) {
        $sheets = self::get_sheets_for_event($event_id);
        if (empty($sheets)) {
            return '';
        }
        $user_id = function_exists('get_current_user_id') ? get_current_user_id() : 0;
        $self = self::get_instance();
        ob_start();
        echo '<section class="pta-event-volunteer-signups">';
        echo '<h2 class="pta-event-section">' . esc_html__('Volunteer Sign Up', 'azure-plugin') . '</h2>';
        foreach ($sheets as $sheet) {
            $self->render_frontend($sheet, self::get_activities($sheet->id), $user_id);
        }
        echo '</section>';
        return ob_get_clean();
    }

    // ──────────────────────────────────────────────
    // Frontend AJAX — signup / withdraw
    // ──────────────────────────────────────────────

    public function ajax_login_required() {
        wp_send_json_error(array('message' => __('Please log in to volunteer.', 'azure-plugin')));
    }

    public function ajax_signup() {
        check_ajax_referer('azure_volunteer_front', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(array('message' => __('Please log in to volunteer.', 'azure-plugin')));
        }

        global $wpdb;
        $activity_ids = isset($_POST['activity_ids']) ? array_map('absint', (array) $_POST['activity_ids']) : array();
        if (empty($activity_ids)) {
            wp_send_json_error(array('message' => __('No activities selected.', 'azure-plugin')));
        }

        $signups_t    = Azure_Database::get_table_name('volunteer_signups');
        $activities_t = Azure_Database::get_table_name('volunteer_activities');
        $added = array();
        $added_acts = array();

        foreach ($activity_ids as $aid) {
            if (self::user_signed_up($aid, $user_id)) {
                continue;
            }
            $act = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$activities_t} WHERE id = %d", $aid));
            if (!$act) {
                continue;
            }
            $filled = self::count_signups($aid);
            if ($filled >= (int) $act->spots_needed) {
                continue;
            }
            $wpdb->insert($signups_t, array(
                'activity_id' => $aid,
                'user_id'     => $user_id,
            ));
            $added[] = $act->name;
            $added_acts[] = $act;
        }

        if (empty($added)) {
            wp_send_json_error(array('message' => __('Could not sign up — spots may already be full.', 'azure-plugin')));
        }

        $sheet_id = (int) $added_acts[0]->sheet_id;
        $this->send_confirmation_email($user_id, $sheet_id, $added_acts);

        wp_send_json_success(array(
            'message'    => sprintf(__('You signed up for: %s', 'azure-plugin'), implode(', ', $added)),
            'activities' => $added,
        ));
    }

    public function ajax_withdraw() {
        check_ajax_referer('azure_volunteer_front', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(array('message' => __('Please log in.', 'azure-plugin')));
        }

        global $wpdb;
        $activity_id = absint($_POST['activity_id'] ?? 0);
        if (!$activity_id) {
            wp_send_json_error(array('message' => __('Invalid activity.', 'azure-plugin')));
        }

        $signups_t = Azure_Database::get_table_name('volunteer_signups');
        $wpdb->delete($signups_t, array('activity_id' => $activity_id, 'user_id' => $user_id));

        wp_send_json_success(array('message' => __('You have withdrawn from this activity.', 'azure-plugin')));
    }

    // ──────────────────────────────────────────────
    // Emails
    // ──────────────────────────────────────────────

    private function send_confirmation_email($user_id, $sheet_id, $activities) {
        $user = get_userdata($user_id);
        $sheet = self::get_sheet($sheet_id);
        if (!$user || !$sheet) {
            return;
        }

        $lines = array();
        foreach ((array) $activities as $act) {
            if (is_string($act)) {
                $lines[] = $act;
                continue;
            }
            $time = self::slot_time_label($sheet, $act);
            $lines[] = $time !== '' ? $act->name . ' (' . $time . ')' : $act->name;
        }

        $event_date_str = '';
        if ($sheet->event_date) {
            $event_date_str = date_i18n(get_option('date_format'), strtotime($sheet->event_date));
        }

        $subject = sprintf(__('Volunteer Confirmation — %s', 'azure-plugin'), $sheet->title);
        $message = sprintf(
            __("Hi %s,\n\nThank you for volunteering for %s!\n\nYou signed up for:\n• %s", 'azure-plugin'),
            $user->display_name,
            $sheet->title,
            implode("\n• ", $lines)
        );

        if ($event_date_str) {
            $message .= sprintf(__("\n\nDate: %s (Pacific Time)", 'azure-plugin'), $event_date_str);
        }
        if ($sheet->event_location) {
            $message .= sprintf(__("\nLocation: %s", 'azure-plugin'), $sheet->event_location);
        }

        $message .= __("\n\nA calendar invite is attached — add it to keep this shift on your calendar.\n\nThank you for helping out!\n", 'azure-plugin');

        $attachments = $this->write_ics_attachments($sheet, $activities, $user);
        wp_mail($user->user_email, $subject, $message, array(), $attachments);
        $this->cleanup_ics_attachments($attachments);
    }

    /**
     * @param object   $sheet
     * @param object[] $activities
     * @param object   $user
     * @return string[] temp file paths
     */
    private function write_ics_attachments($sheet, $activities, $user) {
        $files = array();
        foreach ((array) $activities as $act) {
            if (!is_object($act)) {
                continue;
            }
            $ics = self::build_slot_ics($sheet, $act, $user);
            if ($ics === '') {
                continue;
            }
            $tmp = function_exists('wp_tempnam') ? wp_tempnam('volunteer.ics') : tempnam(sys_get_temp_dir(), 'vsics');
            if (!$tmp) {
                continue;
            }
            $named = $tmp . '.ics';
            if (@rename($tmp, $named)) {
                $tmp = $named;
            }
            file_put_contents($tmp, $ics);
            $files[] = $tmp;
        }
        return $files;
    }

    private function cleanup_ics_attachments($files) {
        foreach ((array) $files as $file) {
            if (is_string($file) && $file !== '' && file_exists($file)) {
                @unlink($file);
            }
        }
    }

    public function send_reminders() {
        global $wpdb;
        $sheets_t   = Azure_Database::get_table_name('volunteer_sheets');
        $signups_t  = Azure_Database::get_table_name('volunteer_signups');
        if (!$sheets_t || !$signups_t) {
            return;
        }

        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $sheets = $wpdb->get_results("SELECT * FROM {$sheets_t} WHERE status = 'open'");
        $checked = 0;

        foreach ((array) $sheets as $sheet) {
            $activities = self::get_activities($sheet->id);
            foreach ($activities as $act) {
                $bounds = self::slot_bounds($sheet, $act);
                if (empty($bounds['start']) || substr($bounds['start'], 0, 10) !== $tomorrow) {
                    continue;
                }
                $checked++;
                $signups = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$signups_t} WHERE activity_id = %d AND reminder_sent = 0",
                    $act->id
                ));
                foreach ($signups as $signup) {
                    $user = get_userdata($signup->user_id);
                    if (!$user) {
                        continue;
                    }

                    $time_label = self::slot_time_label($sheet, $act);
                    $date_str = date_i18n(get_option('date_format'), strtotime($bounds['start']));
                    $when = $time_label !== '' ? $date_str . ' ' . $time_label . ' (Pacific Time)' : $date_str;

                    $subject = sprintf(__('Reminder: %s is tomorrow!', 'azure-plugin'), $sheet->title);
                    $body = sprintf(
                        __("Hi %s,\n\nJust a reminder — you're volunteering tomorrow for %s.\n\nActivity: %s\nWhen: %s", 'azure-plugin'),
                        $user->display_name,
                        $sheet->title,
                        $act->name,
                        $when
                    );
                    if ($sheet->event_location) {
                        $body .= sprintf(__("\nLocation: %s", 'azure-plugin'), $sheet->event_location);
                    }
                    $body .= __("\n\nA calendar invite is attached.\n\nThank you for helping out!\n", 'azure-plugin');

                    $attachments = $this->write_ics_attachments($sheet, array($act), $user);
                    wp_mail($user->user_email, $subject, $body, array(), $attachments);
                    $this->cleanup_ics_attachments($attachments);

                    $wpdb->update($signups_t, array('reminder_sent' => 1), array('id' => $signup->id));
                }
            }
        }

        if (class_exists('Azure_Logger')) {
            Azure_Logger::debug_module('Volunteer', 'Reminder cron completed. Slots due tomorrow: ' . $checked);
        }
    }

    // ──────────────────────────────────────────────
    // Shortcode [volunteer_signup id="123"]
    // ──────────────────────────────────────────────

    public function shortcode_render($atts) {
        $atts = shortcode_atts(array('id' => 0), $atts, 'volunteer_signup');
        $sheet_id = absint($atts['id']);
        if (!$sheet_id) {
            return '<p>' . __('Please specify a sign-up sheet ID.', 'azure-plugin') . '</p>';
        }

        $sheet = self::get_sheet($sheet_id);
        if (!$sheet) {
            return '<p>' . __('Sign-up sheet not found.', 'azure-plugin') . '</p>';
        }

        $activities = self::get_activities($sheet_id);
        $user_id = get_current_user_id();

        ob_start();
        $this->render_frontend($sheet, $activities, $user_id);
        return ob_get_clean();
    }

    private function render_frontend($sheet, $activities, $user_id) {
        $event_date_str = '';
        if ($sheet->event_date) {
            $event_date_str = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($sheet->event_date));
        }
        $is_closed = ($sheet->status === 'closed');
        ?>
        <div class="azure-volunteer-sheet" data-sheet-id="<?php echo esc_attr($sheet->id); ?>">
            <div class="azure-vs-header">
                <h3><?php echo esc_html($sheet->title); ?></h3>
                <?php if ($sheet->description): ?>
                    <p class="azure-vs-desc"><?php echo esc_html($sheet->description); ?></p>
                <?php endif; ?>
                <div class="azure-vs-meta">
                    <?php if ($event_date_str): ?>
                        <span class="azure-vs-date"><span class="dashicons dashicons-calendar-alt"></span> <?php echo esc_html($event_date_str); ?></span>
                    <?php endif; ?>
                    <?php if ($sheet->event_location): ?>
                        <span class="azure-vs-location"><span class="dashicons dashicons-location"></span> <?php echo esc_html($sheet->event_location); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($is_closed): ?>
                <p class="azure-vs-closed"><?php _e('Sign-ups are closed for this event.', 'azure-plugin'); ?></p>
            <?php else: ?>

            <?php
            $login_url = function_exists('wp_login_url')
                ? wp_login_url(function_exists('get_permalink') ? get_permalink() : '')
                : '';
            ?>
            <div class="azure-vs-table-wrap">
                <table class="azure-vs-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Activity', 'azure-plugin'); ?></th>
                            <th><?php esc_html_e('Time', 'azure-plugin'); ?></th>
                            <th><?php esc_html_e('Spaces', 'azure-plugin'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($activities as $act):
                        $filled = self::count_signups($act->id);
                        $total  = (int) $act->spots_needed;
                        $full   = ($filled >= $total);
                        $signed = $user_id ? self::user_signed_up($act->id, $user_id) : false;
                        $signups = self::get_signups_for_activity($act->id);
                        $time_label = self::slot_time_label($sheet, $act);
                    ?>
                        <tr class="azure-vs-activity <?php echo $full ? 'full' : ''; ?> <?php echo $signed ? 'signed-up' : ''; ?>"
                            data-activity-id="<?php echo esc_attr($act->id); ?>"
                            data-activity-name="<?php echo esc_attr($act->name); ?>"
                            data-activity-time="<?php echo esc_attr($time_label); ?>"
                            data-sheet-title="<?php echo esc_attr($sheet->title); ?>">
                            <td>
                                <strong><?php echo esc_html($act->name); ?></strong>
                                <?php if ($act->description): ?>
                                    <div class="azure-vs-act-desc"><?php echo esc_html($act->description); ?></div>
                                <?php endif; ?>
                                <?php if ($signups): ?>
                                    <div class="azure-vs-act-volunteers">
                                        <?php foreach ($signups as $s):
                                            $vol = get_userdata($s->user_id);
                                            $name = $vol ? $vol->display_name : __('Unknown', 'azure-plugin');
                                        ?>
                                            <span class="azure-vs-volunteer <?php echo ($user_id && (int) $s->user_id === (int) $user_id) ? 'is-me' : ''; ?>">
                                                <?php echo esc_html($name); ?>
                                                <?php if ($user_id && (int) $s->user_id === (int) $user_id): ?>
                                                    <button type="button" class="azure-vs-withdraw" data-activity-id="<?php echo esc_attr($act->id); ?>" title="<?php esc_attr_e('Withdraw', 'azure-plugin'); ?>">&times;</button>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $time_label !== '' ? esc_html($time_label) : '—'; ?></td>
                            <td><?php echo esc_html(sprintf(__('%1$d of %2$d filled', 'azure-plugin'), $filled, $total)); ?></td>
                            <td class="azure-vs-act-action">
                                <?php if ($signed): ?>
                                    <span class="azure-vs-signed-badge"><?php esc_html_e('Signed up', 'azure-plugin'); ?></span>
                                <?php elseif ($full): ?>
                                    <span class="azure-vs-full-badge"><?php esc_html_e('Full', 'azure-plugin'); ?></span>
                                <?php elseif ($user_id): ?>
                                    <button type="button" class="button azure-vs-signup-btn">
                                        <?php esc_html_e('Sign Up', 'azure-plugin'); ?>
                                    </button>
                                <?php else: ?>
                                    <a class="button azure-vs-signup-btn" href="<?php echo esc_url($login_url); ?>">
                                        <?php esc_html_e('Sign Up', 'azure-plugin'); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="azure-vs-message" style="display:none;"></p>
            <?php if (!$user_id): ?>
                <div class="azure-vs-login-prompt">
                    <p><?php printf(
                        __('Please %ssign in%s with your usual WordPress account to volunteer.', 'azure-plugin'),
                        '<a href="' . esc_url($login_url) . '">',
                        '</a>'
                    ); ?></p>
                </div>
            <?php endif; ?>

            <?php endif; // closed check ?>
        </div>
        <?php
    }

    // ──────────────────────────────────────────────
    // Frontend assets
    // ──────────────────────────────────────────────

    public function maybe_enqueue_frontend() {
        global $post;
        $need = false;
        if (function_exists('is_singular') && is_singular('pta_event') && $post) {
            $need = !empty(self::get_sheets_for_event((int) $post->ID));
        }
        if (!$need && $post && function_exists('has_shortcode') && has_shortcode($post->post_content, 'volunteer_signup')) {
            $need = true;
        }
        if (!$need) {
            return;
        }

        wp_enqueue_style(
            'azure-volunteer-frontend',
            AZURE_PLUGIN_URL . 'css/volunteer-frontend.css',
            array(),
            defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : '1.0'
        );

        wp_enqueue_script(
            'azure-volunteer-frontend',
            AZURE_PLUGIN_URL . 'js/volunteer-frontend.js',
            array('jquery'),
            defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : '1.0',
            true
        );

        wp_localize_script('azure-volunteer-frontend', 'azureVolunteer', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('azure_volunteer_front'),
            'i18n'    => array(
                'saving'   => __('Saving...', 'azure-plugin'),
                'saved'    => __('Saved!', 'azure-plugin'),
                'error'    => __('Something went wrong.', 'azure-plugin'),
                'confirm_title' => __('Confirm sign-up', 'azure-plugin'),
                'confirm_btn' => __('Confirm sign-up', 'azure-plugin'),
                'cancel' => __('Cancel', 'azure-plugin'),
                'confirm_withdraw' => __('Withdraw from this activity?', 'azure-plugin'),
            ),
        ));
    }

    // ──────────────────────────────────────────────
    // Admin helpers (PTA event picker)
    // ──────────────────────────────────────────────

    /**
     * List upcoming pta_event posts for the admin "link to event" picker.
     * Returns an array of {id, title, date, location} sorted by start date.
     */
    public static function get_pta_events_for_dropdown($include_id = 0) {
        $events = get_posts(array(
            'post_type'      => 'pta_event',
            'posts_per_page' => 100,
            'post_status'    => 'publish',
            'orderby'        => 'meta_value',
            'meta_key'       => '_EventStartDate',
            'order'          => 'ASC',
            'meta_query'     => array(array(
                'key'     => '_EventStartDate',
                'value'   => date('Y-m-d'),
                'compare' => '>=',
                'type'    => 'DATE',
            )),
        ));
        $seen = array();
        $out = array();
        foreach ($events as $e) {
            $out[] = self::event_dropdown_row($e);
            $seen[(int) $e->ID] = true;
        }
        $include_id = (int) $include_id;
        if ($include_id && empty($seen[$include_id])) {
            $extra = get_post($include_id);
            if ($extra && $extra->post_type === 'pta_event') {
                array_unshift($out, self::event_dropdown_row($extra));
            }
        }
        return $out;
    }

    private static function event_dropdown_row($e) {
        $start = get_post_meta($e->ID, '_EventStartDate', true);
        $venue_id = get_post_meta($e->ID, '_EventVenueID', true);
        $location = '';
        if ($venue_id) {
            $location = get_the_title($venue_id);
        }
        if ($location === '') {
            $location = (string) get_post_meta($e->ID, '_EventVenue', true);
        }
        return array(
            'id'       => $e->ID,
            'title'    => $e->post_title,
            'date'     => $start,
            'location' => $location,
        );
    }

    /**
     * Back-compat shim: any external code still calling the legacy
     * `get_tec_events_for_dropdown()` continues to work and now reads
     * pta_event under the hood. Safe to remove in a future major.
     */
    public static function get_tec_events_for_dropdown() {
        return self::get_pta_events_for_dropdown();
    }
}
