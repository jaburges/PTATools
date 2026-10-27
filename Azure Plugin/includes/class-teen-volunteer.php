<?php
/**
 * Teen Volunteer accounts.
 *
 * Teens sign up for volunteer shifts with just an email address, on sheets
 * that allow it. Each teen gets a WordPress user with the Teen Volunteer role
 * so their sign-ups count and their emails go out like anyone else's, but the
 * account holds nothing except the email: no name, no children, no profile
 * fields, no sign-in, no newsletters. Accounts with no shift in the current
 * school year are deleted once June 30 has passed.
 *
 * @package Azure_Plugin
 */
if (!defined('ABSPATH')) {
    exit;
}

class Azure_Teen_Volunteer {

    const ROLE         = 'teen_volunteer';
    const PURGE_HOOK   = 'azure_teen_volunteer_purge';
    const CANCEL_PARAM = 'pta_teen_cancel';

    /** The school year starts on this month/day; accounts are kept for one school year. */
    const SCHOOL_YEAR_START = '07-01';

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        self::ensure_role();
        add_filter('authenticate', array(__CLASS__, 'block_login'), 40, 1);
        add_action(self::PURGE_HOOK, array(__CLASS__, 'purge'));
        add_action('template_redirect', array(__CLASS__, 'maybe_handle_cancel'), 0);
        if (function_exists('wp_next_scheduled') && !wp_next_scheduled(self::PURGE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK);
        }
    }

    public static function ensure_role() {
        if (!get_role(self::ROLE)) {
            add_role(self::ROLE, __('Teen Volunteer', 'azure-plugin'), array());
        }
    }

    public static function is_teen($user) {
        return is_object($user) && in_array(self::ROLE, (array) ($user->roles ?? array()), true);
    }

    public static function is_teen_id($user_id) {
        $user_id = (int) $user_id;
        return $user_id > 0 && function_exists('get_userdata') && self::is_teen(get_userdata($user_id));
    }

    /**
     * Teen accounts are a mailing address for shift emails, not a login. A
     * password reset would otherwise let one be signed into.
     *
     * @param WP_User|WP_Error|null $user
     */
    public static function block_login($user) {
        if (self::is_teen($user)) {
            return new WP_Error('pta_teen_no_login', __('Teen volunteer sign-ups don\'t have a login. Use the link in your confirmation email to cancel a sign-up.', 'azure-plugin'));
        }
        return $user;
    }

    /** Users a newsletter's "all users" audience reaches: everyone but teens. */
    public static function newsletter_user_count() {
        $counts = count_users();
        return (int) $counts['total_users'] - (int) ($counts['avail_roles'][self::ROLE] ?? 0);
    }

    /**
     * The Teen Volunteer account for an email, created if needed.
     *
     * @return int|WP_Error User ID, or `account_exists` when the email
     *                      belongs to any other kind of account.
     */
    public static function find_or_create($email) {
        $email = sanitize_email((string) $email);
        if ($email === '' || !is_email($email)) {
            return new WP_Error('invalid_email', __('Please enter a valid email address.', 'azure-plugin'));
        }
        $existing = get_user_by('email', $email);
        if ($existing) {
            return self::is_teen($existing)
                ? (int) $existing->ID
                : new WP_Error('account_exists', __('That email already has an account on this site. Please sign in to sign up.', 'azure-plugin'));
        }
        self::ensure_role();
        $user_id = wp_insert_user(array(
            'user_login'           => 'teen-' . strtolower(wp_generate_password(12, false, false)),
            'user_email'           => $email,
            'user_pass'            => wp_generate_password(32, true, true),
            'display_name'         => __('Teen volunteer', 'azure-plugin'),
            'nickname'             => __('Teen volunteer', 'azure-plugin'),
            'role'                 => self::ROLE,
            'show_admin_bar_front' => 'false',
        ));
        return is_wp_error($user_id) ? $user_id : (int) $user_id;
    }

    // ──────────────────────────────────────────────
    // Cancel link
    // ──────────────────────────────────────────────

    public static function cancel_token($user_id, $activity_id) {
        return substr(hash_hmac('sha256', 'teen-cancel|' . (int) $user_id . '|' . (int) $activity_id, wp_salt('auth')), 0, 32);
    }

    public static function cancel_url($user_id, $activity_id) {
        $value = (int) $user_id . '.' . (int) $activity_id . '.' . self::cancel_token($user_id, $activity_id);
        return add_query_arg(self::CANCEL_PARAM, $value, home_url('/'));
    }

    /**
     * @return array{0:int,1:int}|null User and activity IDs for a valid link.
     */
    public static function parse_cancel_value($value) {
        if (!preg_match('/^(\d+)\.(\d+)\.([a-f0-9]{32})$/', (string) $value, $m)) {
            return null;
        }
        if (!hash_equals(self::cancel_token((int) $m[1], (int) $m[2]), $m[3])) {
            return null;
        }
        return array((int) $m[1], (int) $m[2]);
    }

    /**
     * The link in a teen's emails. GET shows the shift and a button; only
     * the POST cancels, so mail scanners that open links don't free spots.
     */
    public static function maybe_handle_cancel() {
        if (empty($_GET[self::CANCEL_PARAM])) {
            return;
        }
        $value = sanitize_text_field(wp_unslash($_GET[self::CANCEL_PARAM]));
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
        $ids = self::parse_cancel_value($value);
        if (!$ids || !self::is_teen_id($ids[0])) {
            status_header(404);
            echo self::page_html(__('This link isn\'t valid', 'azure-plugin'), '<p>' . esc_html__('Please use the link in your most recent confirmation email.', 'azure-plugin') . '</p>');
            exit;
        }
        list($user_id, $activity_id) = $ids;
        if (!class_exists('Azure_Volunteer_Signup')) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-volunteer-signup.php';
        }
        $shift = Azure_Volunteer_Signup::shift_summary($activity_id);
        $signed_up = Azure_Volunteer_Signup::user_signed_up($activity_id, $user_id);

        if (!$signed_up) {
            echo self::page_html(__('Already cancelled', 'azure-plugin'), '<p>' . esc_html__('This sign-up has already been cancelled. Thanks for letting us know.', 'azure-plugin') . '</p>');
            exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($_POST['confirm_cancel'])) {
            Azure_Volunteer_Signup::get_instance()->withdraw($user_id, $activity_id);
            echo self::page_html(__('Your sign-up is cancelled', 'azure-plugin'), '<p>' . esc_html(sprintf(__('You are no longer signed up for %s. Thanks for letting us know.', 'azure-plugin'), $shift)) . '</p>');
            exit;
        }
        $form = '<p>' . esc_html__('You are signed up for:', 'azure-plugin') . '</p>'
            . '<p><strong>' . esc_html($shift) . '</strong></p>'
            . '<form method="post" action="' . esc_url(self::cancel_url($user_id, $activity_id)) . '">'
            . '<input type="hidden" name="confirm_cancel" value="1">'
            . '<button type="submit">' . esc_html__('Cancel my sign-up', 'azure-plugin') . '</button>'
            . '</form>';
        echo self::page_html(__('Cancel your volunteer sign-up?', 'azure-plugin'), $form);
        exit;
    }

    private static function page_html($heading, $body) {
        $site = get_bloginfo('name');
        return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>' . esc_html($heading . ' — ' . $site) . '</title>'
            . '<style>body{margin:0;background:#f6f6f6;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;color:#1d2327}'
            . '.card{max-width:520px;margin:32px auto;background:#fff;border-radius:8px;padding:28px;box-shadow:0 1px 3px rgba(0,0,0,.05)}'
            . 'h1{font-size:22px;margin:0 0 12px}p{font-size:15px;line-height:1.5;color:#3c434a;margin:0 0 16px}'
            . 'button{font:inherit;font-weight:600;padding:12px 24px;border:0;border-radius:6px;background:#b32d2e;color:#fff;cursor:pointer}'
            . 'a{color:#0073aa}</style></head><body>'
            . '<div class="card"><p style="font-size:13px;color:#646970;margin:0 0 8px">' . esc_html($site) . '</p>'
            . '<h1>' . esc_html($heading) . '</h1>'
            . $body
            . '<p style="margin-top:20px"><a href="' . esc_url(home_url('/')) . '">' . esc_html($site) . '</a></p>'
            . '</div></body></html>';
    }

    // ──────────────────────────────────────────────
    // End-of-year deletion
    // ──────────────────────────────────────────────

    /**
     * First day of the school year containing $now (Y-m-d, site timezone).
     */
    public static function school_year_start($now = null) {
        $now = $now === null ? current_time('Y-m-d') : (string) $now;
        $year = (int) substr($now, 0, 4);
        if (substr($now, 5) < self::SCHOOL_YEAR_START) {
            $year--;
        }
        return $year . '-' . self::SCHOOL_YEAR_START;
    }

    /**
     * Deletes teen accounts with no shift on or after the start of the
     * current school year, along with their past sign-ups.
     *
     * @return int Accounts deleted.
     */
    public static function purge($now = null) {
        global $wpdb;
        $ids = get_users(array('role' => self::ROLE, 'fields' => 'ID', 'number' => -1));
        if (!$ids) {
            return 0;
        }
        $signups_t = Azure_Database::get_table_name('volunteer_signups');
        $activities_t = Azure_Database::get_table_name('volunteer_activities');
        $sheets_t = Azure_Database::get_table_name('volunteer_sheets');
        $since = self::school_year_start($now) . ' 00:00:00';
        $id_list = implode(',', array_map('intval', $ids));
        $keep = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT s.user_id FROM {$signups_t} s
             JOIN {$activities_t} a ON a.id = s.activity_id
             JOIN {$sheets_t} sh ON sh.id = a.sheet_id
             WHERE s.user_id IN ({$id_list}) AND (sh.event_date IS NULL OR sh.event_date >= %s)",
            $since
        )));
        $gone = array_diff(array_map('intval', $ids), $keep);
        if (!$gone) {
            return 0;
        }
        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        foreach ($gone as $user_id) {
            $wpdb->delete($signups_t, array('user_id' => $user_id));
            wp_delete_user($user_id);
        }
        if (class_exists('Azure_Logger')) {
            Azure_Logger::info(sprintf('Teen volunteers: deleted %d account(s) with no shift since %s', count($gone), $since), array('module' => 'Volunteer'));
        }
        return count($gone);
    }
}
