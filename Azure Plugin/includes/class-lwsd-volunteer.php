<?php
/**
 * LWSD volunteer roster helpers: name matching, expiry parsing, and status.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Lwsd_Volunteer {

    const META_EXPIRES = 'pta_lwsd_volunteer_expires';
    const META_ACTIVE  = 'pta_lwsd_volunteer_active';

    const OPTION_IMPORTED_AT = 'pta_lwsd_volunteer_imported_at';
    const OPTION_BLOB        = 'pta_lwsd_volunteer_last_blob';
    const OPTION_FILENAME    = 'pta_lwsd_volunteer_last_filename';

    /**
     * Trim, collapse internal whitespace, and lowercase a name fragment.
     */
    public static function normalize_name($name) {
        $name = trim((string) $name);
        $name = preg_replace('/\s+/', ' ', $name);
        return strtolower($name);
    }

    /**
     * Stable lookup key for first + last name matching.
     */
    public static function name_key($first, $last) {
        return self::normalize_name($first) . '|' . self::normalize_name($last);
    }

    /**
     * Parse an expiry value to Y-m-d, or empty string if unusable.
     *
     * @param mixed $value
     */
    public static function parse_expiry($value) {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $formats = array('Y-m-d', 'Y-m-d H:i:s', 'n/j/Y');
        foreach ($formats as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $value);
            if ($dt instanceof DateTimeImmutable) {
                $errors = DateTimeImmutable::getLastErrors();
                if (empty($errors['warning_count']) && empty($errors['error_count'])) {
                    return $dt->format('Y-m-d');
                }
            }
        }

        return '';
    }

    /**
     * True when clearance expires on or after $today.
     */
    public static function is_active($expires_on, $today) {
        $expires_on = (string) $expires_on;
        if ($expires_on === '') {
            return false;
        }
        return $expires_on >= $today;
    }

    /**
     * True when clearance covers the event date (expiry on or after event day).
     */
    public static function is_approved_for_event($expires_on, $event_date) {
        $expires_on = (string) $expires_on;
        $event_date = (string) $event_date;
        if ($expires_on === '' || $event_date === '') {
            return false;
        }
        return $expires_on >= $event_date;
    }

    /**
     * Match roster rows to WordPress users by normalized first|last name key.
     *
     * @param array $roster_rows
     * @param array $users
     * @return array
     */
    public static function match_rows(array $roster_rows, array $users) {
        $by_key = array();
        foreach ($users as $user) {
            $key = self::name_key($user['first_name'] ?? '', $user['last_name'] ?? '');
            if (!isset($by_key[$key])) {
                $by_key[$key] = array();
            }
            $by_key[$key][] = (int) ($user['ID'] ?? 0);
        }

        $out = array();
        foreach ($roster_rows as $row) {
            $matched = $row;
            $key = self::name_key($row['first'] ?? '', $row['last'] ?? '');
            $ids = $by_key[$key] ?? array();

            if (count($ids) === 1) {
                $matched['match_state'] = 'matched';
                $matched['user_id'] = $ids[0];
            } elseif (count($ids) > 1) {
                $matched['match_state'] = 'ambiguous';
                $matched['user_id'] = 0;
            } else {
                $matched['match_state'] = 'unmatched';
                $matched['user_id'] = 0;
            }

            $out[] = $matched;
        }

        return $out;
    }

    /**
     * Today's date in America/Los_Angeles as Y-m-d.
     */
    public static function today_pacific() {
        return (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
    }

    /**
     * Plan user-meta writes for a full roster replace.
     *
     * @return array{set: array<int,array{expires_on:string,active:int}>, clear: int[]}
     */
    public static function plan_meta_writes(array $matched_rows, array $previously_matched_user_ids, $today) {
        $today = (string) $today;
        $set = array();

        foreach ($matched_rows as $row) {
            if (($row['match_state'] ?? '') !== 'matched') {
                continue;
            }

            $user_id = (int) ($row['user_id'] ?? 0);
            if ($user_id <= 0) {
                continue;
            }

            $expires_on = (string) ($row['expires_on'] ?? '');
            $set[$user_id] = array(
                'expires_on' => $expires_on,
                'active'     => self::is_active($expires_on, $today) ? 1 : 0,
            );
        }

        $clear = array();
        foreach ($previously_matched_user_ids as $prev_id) {
            $prev_id = (int) $prev_id;
            if ($prev_id > 0 && !isset($set[$prev_id])) {
                $clear[] = $prev_id;
            }
        }

        return array(
            'set'   => $set,
            'clear' => $clear,
        );
    }

    /**
     * Derive volunteer clearance status from stored expiry meta.
     *
     * @return array{active:bool,approved_for_event:bool,reason:string,expires_on:string}
     */
    public static function status_from_meta($expires_on, $event_date, $today) {
        $expires_on = (string) $expires_on;
        $event_date = (string) $event_date;
        $today = (string) $today;

        if ($expires_on === '') {
            return array(
                'active'             => false,
                'approved_for_event' => false,
                'reason'             => 'not_on_roster',
                'expires_on'         => '',
            );
        }

        $active = self::is_active($expires_on, $today);
        $approved = self::is_approved_for_event($expires_on, $event_date);

        if ($expires_on < $today) {
            return array(
                'active'             => false,
                'approved_for_event' => false,
                'reason'             => 'expired',
                'expires_on'         => $expires_on,
            );
        }

        if ($event_date !== '' && $expires_on < $event_date) {
            return array(
                'active'             => $active,
                'approved_for_event' => false,
                'reason'             => 'expires_before_event',
                'expires_on'         => $expires_on,
            );
        }

        return array(
            'active'             => $active,
            'approved_for_event' => $approved,
            'reason'             => 'ok',
            'expires_on'         => $expires_on,
        );
    }

    /**
     * Dashboard widget counts from matched roster rows.
     *
     * @return array{active:int,expiring:int,expired:int,unmatched:int,ambiguous:int,total:int}
     */
    public static function widget_stats(array $roster_rows, $today, $expiring_days = 14) {
        $stats = array(
            'active'    => 0,
            'expiring'  => 0,
            'expired'   => 0,
            'unmatched' => 0,
            'ambiguous' => 0,
            'total'     => count($roster_rows),
        );

        $expiring_until = (new DateTimeImmutable($today))->modify('+' . (int) $expiring_days . ' days')->format('Y-m-d');

        foreach ($roster_rows as $row) {
            $match_state = $row['match_state'] ?? '';
            $expires_on = (string) ($row['expires_on'] ?? '');

            if ($match_state === 'unmatched') {
                $stats['unmatched']++;
            } elseif ($match_state === 'ambiguous') {
                $stats['ambiguous']++;
            }

            if ($expires_on !== '' && $expires_on < $today) {
                $stats['expired']++;
            }

            if ($match_state === 'matched' && self::is_active($expires_on, $today)) {
                $stats['active']++;
            }

            if ($expires_on !== '' && $expires_on >= $today && $expires_on <= $expiring_until) {
                $stats['expiring']++;
            }
        }

        return $stats;
    }

    /**
     * Matched users with email whose clearance expires within the reminder window.
     */
    public static function expiring_contactable(array $matched_users, $today, $days = 14) {
        $expiring_until = (new DateTimeImmutable($today))->modify('+' . (int) $days . ' days')->format('Y-m-d');
        $out = array();

        foreach ($matched_users as $user) {
            if (($user['match_state'] ?? '') !== 'matched') {
                continue;
            }

            $email = trim((string) ($user['user_email'] ?? ''));
            if ($email === '' || !self::is_usable_email($email)) {
                continue;
            }

            $expires_on = (string) ($user['expires_on'] ?? '');
            if ($expires_on === '' || !self::is_active($expires_on, $today) || $expires_on > $expiring_until) {
                continue;
            }

            $out[] = $user;
        }

        return $out;
    }

    /**
     * @param string $email
     */
    private static function is_usable_email($email) {
        if (function_exists('is_email')) {
            return (bool) is_email($email);
        }
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Hard-coded apply page slug used by apply_url().
     *
     * Kept as a constant so tests can assert it without WordPress.
     */
    const APPLY_PATH = '/become-an-lwsd-approved-volunteer/';

    /**
     * URL of the public "become an approved volunteer" page.
     *
     * @return string
     */
    public static function apply_url() {
        return function_exists('home_url') ? home_url(self::apply_path()) : self::apply_path();
    }

    /**
     * Hard-coded apply page path (no host).
     *
     * @return string
     */
    public static function apply_path() {
        return self::APPLY_PATH;
    }

    /**
     * Modal / email warning copy for a status_from_meta() result.
     *
     * @param array{reason?:string,expires_on?:string,active?:bool,approved_for_event?:bool} $status
     * @return array{title:string,body:string,show_link:bool}
     */
    public static function warning_copy(array $status) {
        $reason = isset($status['reason']) ? (string) $status['reason'] : '';
        if ($reason === 'ok') {
            return array(
                'title'     => '',
                'body'      => '',
                'show_link' => false,
            );
        }

        $expires_on = isset($status['expires_on']) ? (string) $status['expires_on'] : '';

        if ($reason === 'expired') {
            return array(
                'title'     => 'Your LWSD volunteer approval has expired',
                'body'      => 'Your LWSD volunteer approval is no longer current. You can still sign up, but please renew your district approval.',
                'show_link' => true,
            );
        }

        if ($reason === 'expires_before_event') {
            $when = $expires_on !== '' ? $expires_on : 'soon';
            return array(
                'title'     => 'Your LWSD volunteer approval expires before this event',
                'body'      => 'Your LWSD volunteer approval expires on ' . $when . ', which is before this event. You can still sign up.',
                'show_link' => true,
            );
        }

        return array(
            'title'     => 'You are not on the LWSD approved volunteer roster',
            'body'      => 'You are not currently listed as an LWSD-approved volunteer. You can still sign up.',
            'show_link' => true,
        );
    }

    /**
     * Extra confirmation-email text: empty when approved, otherwise warning + apply URL.
     *
     * @param array{reason?:string,expires_on?:string,active?:bool,approved_for_event?:bool} $status
     * @param string $apply_url
     * @return string
     */
    public static function confirmation_footer(array $status, $apply_url) {
        $copy = self::warning_copy($status);
        if (empty($copy['show_link'])) {
            return '';
        }

        $lines = array();
        if ($copy['title'] !== '') {
            $lines[] = $copy['title'];
        }
        if ($copy['body'] !== '') {
            $lines[] = $copy['body'];
        }
        $apply_url = (string) $apply_url;
        if ($apply_url !== '') {
            $lines[] = $apply_url;
        }

        return "\n\n" . implode("\n", $lines);
    }

    /**
     * Staff alert recipients for unapproved volunteer signups.
     *
     * @return string[]
     */
    public static function staff_alert_recipients() {
        return array(
            'BethanyM@wilderptsa.net',
            'webmaster@wilderptsa.net',
        );
    }

    /**
     * Subject line for staff LWSD coverage alerts.
     */
    public static function staff_alert_subject($volunteer_name, $sheet_title) {
        return 'LWSD volunteer coverage — ' . (string) $volunteer_name . ' — ' . (string) $sheet_title;
    }

    /**
     * Plain-text body for staff LWSD coverage alerts.
     *
     * @param array{volunteer_name?:string,volunteer_email?:string,user_id?:int,sheet_title?:string,activities?:string,event_date?:string,expires_on?:string,reason?:string} $ctx
     */
    public static function staff_alert_body(array $ctx) {
        $name = isset($ctx['volunteer_name']) ? (string) $ctx['volunteer_name'] : '';
        $email = isset($ctx['volunteer_email']) ? (string) $ctx['volunteer_email'] : '';
        $user_id = isset($ctx['user_id']) ? (int) $ctx['user_id'] : 0;
        $sheet_title = isset($ctx['sheet_title']) ? (string) $ctx['sheet_title'] : '';
        $activities = isset($ctx['activities']) ? (string) $ctx['activities'] : '';
        $event_date = isset($ctx['event_date']) ? (string) $ctx['event_date'] : '';
        $expires_on = isset($ctx['expires_on']) ? (string) $ctx['expires_on'] : '';
        $reason = isset($ctx['reason']) ? (string) $ctx['reason'] : '';

        $lines = array(
            'An unapproved volunteer signed up for a shift.',
            '',
            'Volunteer: ' . $name . ($email !== '' ? ' (' . $email . ')' : ''),
            'User ID: ' . $user_id,
            'Sheet: ' . $sheet_title,
            'Activities: ' . $activities,
        );

        if ($event_date !== '') {
            $lines[] = 'Event date: ' . $event_date;
        }

        if ($expires_on === '') {
            $lines[] = 'LWSD status: not on the LWSD roster';
        } else {
            $lines[] = 'LWSD status: expiry ' . $expires_on;
        }

        if ($reason !== '') {
            $lines[] = 'Reason: ' . $reason;
        }

        return implode("\n", $lines);
    }

    /**
     * Build the blob name for an archived roster upload.
     *
     * Format: lwsd-volunteer-rosters/{stamp}-{sanitized-basename}.xlsx
     *
     * The basename is forced to .xlsx so a mislabeled upload cannot write a
     * different extension into the private archive container.
     *
     * @param string $original Original client filename (may contain spaces/case).
     * @param string $stamp    Pre-formatted Y-m-d-His stamp.
     * @return string
     */
    public static function blob_name($original, $stamp) {
        $base = basename((string) $original);
        if (function_exists('sanitize_file_name')) {
            $clean = sanitize_file_name($base);
        } else {
            $clean = preg_replace('/[^a-zA-Z0-9._-]+/', '-', strtolower($base));
        }
        $clean = strtolower((string) $clean);
        // Strip any extension(s) and force .xlsx.
        $clean = preg_replace('/\.[^.]+$/', '', $clean);
        if ($clean === '') {
            $clean = 'roster';
        }
        return 'lwsd-volunteer-rosters/' . $stamp . '-' . $clean . '.xlsx';
    }

    // ───────────────────────────────────────────────────────────────────
    // WordPress wiring
    // ───────────────────────────────────────────────────────────────────

    /** Nonce action used by the admin upload widget. */
    const NONCE = 'azure_lwsd_volunteer_admin';

    private static $instance = null;

    /**
     * Register the AJAX + profile hooks. Called from init_volunteer_components.
     */
    public static function init() {
        add_action('wp_ajax_azure_lwsd_volunteer_upload', array(__CLASS__, 'ajax_upload'));
        add_action('show_user_profile', array(__CLASS__, 'render_user_profile_fields'));
        add_action('edit_user_profile', array(__CLASS__, 'render_user_profile_fields'));

        // Email Expiring Volunteers is implemented but not hooked until we can
        // mail only people we have WordPress accounts for.
        /*
        add_action('wp_ajax_azure_lwsd_volunteer_email_expiring', array(__CLASS__, 'ajax_email_expiring'));
        */
    }

    /**
     * Best-effort: archive the uploaded xlsx to the private blob container.
     * Failure (storage not configured or upload throws) is logged but never
     * propagates — the roster import must still succeed.
     *
     * @param string $tmp_path  $_FILES['...']['tmp_name'].
     * @param string $original  Original client filename.
     * @param string $stamp     Y-m-d-His stamp for the blob name.
     * @return string Empty string on failure, the blob name on success.
     */
    public static function archive_upload_to_blob($tmp_path, $original, $stamp) {
        if (!class_exists('Azure_Backup_Storage')) {
            return '';
        }

        $blob_name = self::blob_name($original, $stamp);

        try {
            $storage = new Azure_Backup_Storage();
            $uploaded = $storage->upload_backup($tmp_path, $blob_name);
            if (is_string($uploaded) && $uploaded !== '') {
                return $uploaded;
            }
            return $blob_name;
        } catch (\Throwable $e) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::warning(
                    'LWSD volunteer roster blob archive failed: ' . $e->getMessage(),
                    array('module' => 'Volunteer')
                );
            }
            return '';
        }
    }

    /**
     * Load WordPress users in a form match_rows() can consume. Skips users
     * with no first or last name (they can never be matched by name).
     *
     * @return array<int,array{ID:int,first_name:string,last_name:string,user_email:string}>
     */
    public static function load_wp_users_for_match() {
        if (!function_exists('get_users')) {
            return array();
        }

        $ids = get_users(array('fields' => array('ID')));
        $out = array();
        foreach ($ids as $uid) {
            $id = (int) $uid;
            $first = (string) get_user_meta($id, 'first_name', true);
            $last  = (string) get_user_meta($id, 'last_name', true);
            if ($first === '' || $last === '') {
                continue;
            }
            $out[] = array(
                'ID'         => $id,
                'first_name' => $first,
                'last_name'  => $last,
                'user_email' => (string) get_user_meta($id, 'user_email', true),
            );
        }
        return $out;
    }

    /**
     * Existing roster rows' user_ids that are currently matched. Used as the
     * "previously matched" set so the next import clears users who left.
     *
     * @return int[]
     */
    public static function current_matched_user_ids() {
        global $wpdb;
        $table = Azure_Database::get_table_name('lwsd_volunteer_roster');
        if (!$table) {
            return array();
        }
        $rows = $wpdb->get_results(
            "SELECT user_id FROM {$table} WHERE match_state = 'matched' AND user_id > 0"
        );
        $out = array();
        foreach ($rows as $r) {
            $out[] = (int) $r->user_id;
        }
        return $out;
    }

    /**
     * Full roster replace: truncate, insert, apply the meta plan, and update
     * the imported_at option. Returns counts for the admin widget.
     *
     * @param array  $matched_rows Output of match_rows().
     * @param string $today        Y-m-d (Pacific).
     * @return array{stats:array,unmatched:array,ambiguous:array}
     */
    public static function replace_roster(array $matched_rows, $today) {
        global $wpdb;
        $table = Azure_Database::get_table_name('lwsd_volunteer_roster');
        $now = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');

        $prev = self::current_matched_user_ids();
        $plan = self::plan_meta_writes($matched_rows, $prev, $today);

        if ($table) {
            // Full replace: delete every row, then insert the new set.
            $wpdb->query("DELETE FROM {$table}");

            foreach ($matched_rows as $row) {
                $first = isset($row['first']) ? (string) $row['first'] : '';
                $last  = isset($row['last']) ? (string) $row['last'] : '';
                $expires_on = isset($row['expires_on']) && $row['expires_on'] !== ''
                    ? (string) $row['expires_on']
                    : null;
                $user_id = (int) ($row['user_id'] ?? 0);
                $match_state = (string) ($row['match_state'] ?? 'unmatched');

                $wpdb->insert($table, array(
                    'first_name'  => $first,
                    'last_name'   => $last,
                    'name_key'    => self::name_key($first, $last),
                    'expires_on'  => $expires_on,
                    'user_id'     => $user_id,
                    'match_state' => $match_state,
                    'imported_at' => $now,
                ));
            }
        }

        // Apply user-meta plan: set matched users, clear users who left.
        foreach ($plan['set'] as $user_id => $meta) {
            update_user_meta($user_id, self::META_EXPIRES, $meta['expires_on']);
            update_user_meta($user_id, self::META_ACTIVE, $meta['active']);
        }
        foreach ($plan['clear'] as $user_id) {
            delete_user_meta($user_id, self::META_EXPIRES);
            delete_user_meta($user_id, self::META_ACTIVE);
        }

        update_option(self::OPTION_IMPORTED_AT, $now);

        $stats = self::widget_stats($matched_rows, $today);

        $unmatched = array();
        $ambiguous = array();
        foreach ($matched_rows as $row) {
            $state = $row['match_state'] ?? '';
            $entry = array(
                'first'      => (string) ($row['first'] ?? ''),
                'last'       => (string) ($row['last'] ?? ''),
                'expires_on' => (string) ($row['expires_on'] ?? ''),
            );
            if ($state === 'unmatched') {
                $unmatched[] = $entry;
            } elseif ($state === 'ambiguous') {
                $ambiguous[] = $entry;
            }
        }

        return array(
            'stats'     => $stats,
            'unmatched'  => $unmatched,
            'ambiguous'  => $ambiguous,
        );
    }

    /**
     * AJAX handler: upload a district xlsx, replace the roster, archive to blob.
     */
    public static function ajax_upload() {
        if (!function_exists('check_ajax_referer')) {
            return;
        }
        check_ajax_referer(self::NONCE, 'nonce');
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
            return;
        }

        $file = isset($_FILES['lwsd_xlsx']) ? $_FILES['lwsd_xlsx'] : null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            wp_send_json_error('No file uploaded.');
            return;
        }

        $original = isset($file['name']) ? (string) $file['name'] : '';
        $tmp_path = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            wp_send_json_error('Only .xlsx workbooks are accepted.');
            return;
        }

        try {
            $rows = Azure_Lwsd_Volunteer_Xlsx::parse_file($tmp_path);
        } catch (\Throwable $e) {
            wp_send_json_error($e->getMessage());
            return;
        }

        $today = self::today_pacific();
        $users = self::load_wp_users_for_match();
        $matched = self::match_rows($rows, $users);
        $result = self::replace_roster($matched, $today);

        $stamp = (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d-His');
        $blob = self::archive_upload_to_blob($tmp_path, $original, $stamp);

        update_option(self::OPTION_FILENAME, $original);
        update_option(self::OPTION_BLOB, $blob);

        wp_send_json_success(array(
            'stats'     => $result['stats'],
            'unmatched'  => $result['unmatched'],
            'ambiguous'  => $result['ambiguous'],
            'filename'  => $original,
            'blob'      => $blob,
            'imported_at' => get_option(self::OPTION_IMPORTED_AT, ''),
        ));
    }

    /**
     * Read-only view model for the admin widget.
     *
     * @return array{stats:array,unmatched:array,ambiguous:array,imported_at:string,filename:string,blob:string}
     */
    public static function widget_view_model() {
        global $wpdb;
        $table = Azure_Database::get_table_name('lwsd_volunteer_roster');
        $rows = array();
        if ($table) {
            $results = $wpdb->get_results("SELECT first_name, last_name, expires_on, user_id, match_state FROM {$table}");
            foreach ($results as $r) {
                $rows[] = array(
                    'first'       => (string) $r->first_name,
                    'last'        => (string) $r->last_name,
                    'expires_on'  => (string) ($r->expires_on ?? ''),
                    'user_id'     => (int) $r->user_id,
                    'match_state' => (string) $r->match_state,
                );
            }
        }

        $today = self::today_pacific();
        $stats = self::widget_stats($rows, $today);

        $unmatched = array();
        $ambiguous = array();
        foreach ($rows as $row) {
            $state = $row['match_state'] ?? '';
            $entry = array(
                'first'      => $row['first'],
                'last'       => $row['last'],
                'expires_on' => $row['expires_on'],
            );
            if ($state === 'unmatched') {
                $unmatched[] = $entry;
            } elseif ($state === 'ambiguous') {
                $ambiguous[] = $entry;
            }
        }

        return array(
            'stats'       => $stats,
            'unmatched'    => $unmatched,
            'ambiguous'    => $ambiguous,
            'imported_at'  => (string) get_option(self::OPTION_IMPORTED_AT, ''),
            'filename'     => (string) get_option(self::OPTION_FILENAME, ''),
            'blob'         => (string) get_option(self::OPTION_BLOB, ''),
        );
    }

    /**
     * Read-only LWSD volunteer fields on the WP user profile (admin only).
     */
    public static function render_user_profile_fields($user) {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return;
        }
        $expires = get_user_meta($user->ID, self::META_EXPIRES, true);
        $active = get_user_meta($user->ID, self::META_ACTIVE, true);
        ?>
        <h2><?php _e('LWSD Volunteer Clearance', 'azure-plugin'); ?></h2>
        <table class="form-table">
            <tr>
                <th><label><?php _e('Clearance Expires', 'azure-plugin'); ?></label></th>
                <td><input type="text" value="<?php echo esc_attr((string) $expires); ?>" readonly disabled class="regular-text" />
                    <p class="description"><?php _e('Managed by the LWSD roster import. Read-only.', 'azure-plugin'); ?></p></td>
            </tr>
            <tr>
                <th><label><?php _e('Active', 'azure-plugin'); ?></label></th>
                <td><input type="text" value="<?php echo esc_attr($active ? __('Yes', 'azure-plugin') : __('No', 'azure-plugin')); ?>" readonly disabled class="regular-text" /></td>
            </tr>
        </table>
        <?php
    }

    /**
     * Stub for the Email Expiring Volunteers endpoint. Filled in by Task 7.
     */
    public static function ajax_email_expiring() {
        // Implemented in Task 7.
    }
}
