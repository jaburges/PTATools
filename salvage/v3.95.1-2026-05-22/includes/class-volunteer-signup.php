<?php
/**
 * Volunteer Sign Up Module
 *
 * SignUpGenius-style volunteer coordination integrated with TEC events.
 * Admins create sign-up sheets with activities/slots; users claim spots.
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
        $tec_event   = absint($_POST['tec_event_id'] ?? 0);
        $event_date  = sanitize_text_field($_POST['event_date'] ?? '');
        $event_loc   = sanitize_text_field($_POST['event_location'] ?? '');
        $status      = in_array($_POST['status'] ?? '', array('open', 'closed'), true) ? $_POST['status'] : 'open';

        if (empty($title)) {
            wp_send_json_error('Title is required.');
        }

        // Pull TEC event data if linked
        if ($tec_event && class_exists('Tribe__Events__Main')) {
            $tec_post = get_post($tec_event);
            if ($tec_post) {
                if (empty($title)) {
                    $title = $tec_post->post_title;
                }
                $start = get_post_meta($tec_event, '_EventStartDate', true);
                if ($start) {
                    $event_date = $start;
                }
                $venue_id = get_post_meta($tec_event, '_EventVenueID', true);
                if ($venue_id && empty($event_loc)) {
                    $event_loc = get_the_title($venue_id);
                }
            }
        }

        $data = array(
            'title'          => $title,
            'description'    => $description,
            'tec_event_id'   => $tec_event,
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

            if (empty($act_name)) {
                continue;
            }

            if ($act_id && in_array($act_id, $existing_ids)) {
                $wpdb->update($activities_t, array(
                    'name'         => $act_name,
                    'description'  => $act_desc,
                    'spots_needed' => $act_spots,
                    'sort_order'   => $i,
                ), array('id' => $act_id));
                $keep_ids[] = $act_id;
            } else {
                $wpdb->insert($activities_t, array(
                    'sheet_id'     => $sheet_id,
                    'name'         => $act_name,
                    'description'  => $act_desc,
                    'spots_needed' => $act_spots,
                    'sort_order'   => $i,
                ));
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
            );
        }
        wp_send_json_success(array(
            'sheet'      => $sheet,
            'activities' => $acts_out,
        ));
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
        }

        if (empty($added)) {
            wp_send_json_error(array('message' => __('Could not sign up — spots may already be full.', 'azure-plugin')));
        }

        // Send confirmation email
        $sheet_id = $wpdb->get_var($wpdb->prepare(
            "SELECT sheet_id FROM {$activities_t} WHERE id = %d", $activity_ids[0]
        ));
        $this->send_confirmation_email($user_id, $sheet_id, $added);

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

    private function send_confirmation_email($user_id, $sheet_id, $activity_names) {
        $user = get_userdata($user_id);
        $sheet = self::get_sheet($sheet_id);
        if (!$user || !$sheet) {
            return;
        }

        $event_date_str = '';
        if ($sheet->event_date) {
            $event_date_str = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($sheet->event_date));
        }

        $subject = sprintf(__('Volunteer Confirmation — %s', 'azure-plugin'), $sheet->title);
        $message = sprintf(
            __("Hi %s,\n\nThank you for volunteering for %s!\n\nYou signed up for:\n• %s", 'azure-plugin'),
            $user->display_name,
            $sheet->title,
            implode("\n• ", $activity_names)
        );

        if ($event_date_str) {
            $message .= sprintf(__("\n\nDate: %s", 'azure-plugin'), $event_date_str);
        }
        if ($sheet->event_location) {
            $message .= sprintf(__("\nLocation: %s", 'azure-plugin'), $sheet->event_location);
        }

        $message .= __("\n\nThank you for helping out!\n", 'azure-plugin');

        wp_mail($user->user_email, $subject, $message);
    }

    public function send_reminders() {
        global $wpdb;
        $sheets_t   = Azure_Database::get_table_name('volunteer_sheets');
        $acts_t     = Azure_Database::get_table_name('volunteer_activities');
        $signups_t  = Azure_Database::get_table_name('volunteer_signups');
        if (!$sheets_t || !$acts_t || !$signups_t) {
            return;
        }

        $tomorrow_start = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $tomorrow_end   = date('Y-m-d 23:59:59', strtotime('+1 day'));

        $sheets = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$sheets_t} WHERE status = 'open' AND event_date BETWEEN %s AND %s",
            $tomorrow_start,
            $tomorrow_end
        ));

        foreach ($sheets as $sheet) {
            $activities = self::get_activities($sheet->id);
            foreach ($activities as $act) {
                $signups = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$signups_t} WHERE activity_id = %d AND reminder_sent = 0",
                    $act->id
                ));
                foreach ($signups as $signup) {
                    $user = get_userdata($signup->user_id);
                    if (!$user) {
                        continue;
                    }

                    $event_date_str = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($sheet->event_date));

                    $subject = sprintf(__('Reminder: %s is tomorrow!', 'azure-plugin'), $sheet->title);
                    $body = sprintf(
                        __("Hi %s,\n\nJust a reminder — you're volunteering tomorrow for %s.\n\nActivity: %s\nDate: %s", 'azure-plugin'),
                        $user->display_name,
                        $sheet->title,
                        $act->name,
                        $event_date_str
                    );
                    if ($sheet->event_location) {
                        $body .= sprintf(__("\nLocation: %s", 'azure-plugin'), $sheet->event_location);
                    }
                    $body .= __("\n\nThank you for helping out!\n", 'azure-plugin');

                    wp_mail($user->user_email, $subject, $body);

                    $wpdb->update($signups_t, array('reminder_sent' => 1), array('id' => $signup->id));
                }
            }
        }

        if (class_exists('Azure_Logger')) {
            Azure_Logger::debug_module('Volunteer', 'Reminder cron completed. Sheets checked: ' . count($sheets));
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

            <div class="azure-vs-activities">
                <?php foreach ($activities as $act):
                    $filled = self::count_signups($act->id);
                    $total  = (int) $act->spots_needed;
                    $full   = ($filled >= $total);
                    $signed = $user_id ? self::user_signed_up($act->id, $user_id) : false;
                    $signups = self::get_signups_for_activity($act->id);
                ?>
                <div class="azure-vs-activity <?php echo $full ? 'full' : ''; ?> <?php echo $signed ? 'signed-up' : ''; ?>"
                     data-activity-id="<?php echo esc_attr($act->id); ?>">
                    <div class="azure-vs-act-header">
                        <div class="azure-vs-act-info">
                            <strong><?php echo esc_html($act->name); ?></strong>
                            <?php if ($act->description): ?>
                                <span class="azure-vs-act-desc"><?php echo esc_html($act->description); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="azure-vs-act-spots">
                            <span class="azure-vs-spot-count"><?php echo $filled; ?>/<?php echo $total; ?></span>
                            <span class="azure-vs-spot-label"><?php _e('filled', 'azure-plugin'); ?></span>
                        </div>
                    </div>
                    <div class="azure-vs-act-volunteers">
                        <?php foreach ($signups as $s):
                            $vol = get_userdata($s->user_id);
                            $name = $vol ? $vol->display_name : __('Unknown', 'azure-plugin');
                        ?>
                            <span class="azure-vs-volunteer <?php echo ($user_id && $s->user_id == $user_id) ? 'is-me' : ''; ?>">
                                <?php echo esc_html($name); ?>
                                <?php if ($user_id && $s->user_id == $user_id): ?>
                                    <button type="button" class="azure-vs-withdraw" data-activity-id="<?php echo esc_attr($act->id); ?>" title="<?php esc_attr_e('Withdraw', 'azure-plugin'); ?>">&times;</button>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($user_id && !$signed && !$full): ?>
                        <label class="azure-vs-signup-check">
                            <input type="checkbox" name="azure_vs_activity[]" value="<?php echo esc_attr($act->id); ?>" />
                            <?php _e('I want to volunteer', 'azure-plugin'); ?>
                        </label>
                    <?php elseif ($full && !$signed): ?>
                        <span class="azure-vs-full-badge"><?php _e('Full', 'azure-plugin'); ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($user_id): ?>
                <div class="azure-vs-actions">
                    <button type="button" class="button azure-vs-save-btn" data-sheet-id="<?php echo esc_attr($sheet->id); ?>">
                        <?php _e('Save my sign-ups', 'azure-plugin'); ?>
                    </button>
                    <span class="azure-vs-message" style="display:none;"></span>
                </div>
            <?php else: ?>
                <div class="azure-vs-login-prompt">
                    <p><?php printf(
                        __('Please %slog in%s or %screate an account%s to volunteer.', 'azure-plugin'),
                        '<a href="' . esc_url(wp_login_url(get_permalink())) . '">',
                        '</a>',
                        '<a href="' . esc_url(wp_registration_url()) . '">',
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
        if (!$post || !has_shortcode($post->post_content, 'volunteer_signup')) {
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
                'confirm_withdraw' => __('Withdraw from this activity?', 'azure-plugin'),
            ),
        ));
    }

    // ──────────────────────────────────────────────
    // Admin helpers (for TEC event dropdown)
    // ──────────────────────────────────────────────

    public static function get_tec_events_for_dropdown() {
        if (!class_exists('Tribe__Events__Main')) {
            return array();
        }
        $events = get_posts(array(
            'post_type'      => 'tribe_events',
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
        $out = array();
        foreach ($events as $e) {
            $start = get_post_meta($e->ID, '_EventStartDate', true);
            $venue_id = get_post_meta($e->ID, '_EventVenueID', true);
            $location = '';
            if ($venue_id) {
                $location = get_the_title($venue_id);
            }
            $out[] = array(
                'id'       => $e->ID,
                'title'    => $e->post_title,
                'date'     => $start,
                'location' => $location,
            );
        }
        return $out;
    }
}
