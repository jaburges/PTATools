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

if (!class_exists('Azure_Email_Messages')) {
    require_once __DIR__ . '/class-email-messages.php';
}

class Azure_Volunteer_Signup {

    private static $instance = null;

    const MESSAGES_OPTION = 'azure_email_messages';

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
        self::ensure_recurring_columns();

        // Admin AJAX
        add_action('wp_ajax_azure_volunteer_save_sheet', array($this, 'ajax_save_sheet'));
        add_action('wp_ajax_azure_volunteer_delete_sheet', array($this, 'ajax_delete_sheet'));
        add_action('wp_ajax_azure_volunteer_get_sheet', array($this, 'ajax_get_sheet'));

        add_action('wp_trash_post', array($this, 'on_event_trashed'));
        add_action('untrashed_post', array($this, 'on_event_untrashed'));

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

        // One recurring sweep. Azure_PTA_Cron owns the schedule so a
        // signup never creates its own cron event.
        add_action('azure_volunteer_send_reminders', array($this, 'send_reminders'));

        add_action('admin_init', array($this, 'save_reminder_settings'));
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
        } else {
            $sql .= " WHERE status <> 'trashed'";
        }
        $sql .= " ORDER BY is_template DESC, event_date ASC, created_at DESC";
        return $wpdb->get_results($sql);
    }

    /**
     * Admin list: one-off sheets stay as rows. A recurring template and
     * every sheet copied from it become one collapsed series.
     *
     * @param object[] $sheets
     * @return array<int, array{kind:string,template:?object,sheets:object[]}>
     */
    public static function group_sheets_for_list($sheets) {
        $templates = array();
        $children = array();
        $singles = array();

        foreach ((array) $sheets as $sheet) {
            if (!is_object($sheet)) {
                continue;
            }
            if (!empty($sheet->is_template)) {
                $templates[(int) $sheet->id] = $sheet;
                continue;
            }
            $template_id = (int) ($sheet->template_id ?? 0);
            if ($template_id > 0) {
                if (!isset($children[$template_id])) {
                    $children[$template_id] = array();
                }
                $children[$template_id][] = $sheet;
                continue;
            }
            $singles[] = $sheet;
        }

        $by_date = function ($a, $b) {
            return strcmp((string) ($a->event_date ?? ''), (string) ($b->event_date ?? ''));
        };

        $groups = array();
        foreach ($templates as $id => $template) {
            $kids = isset($children[$id]) ? $children[$id] : array();
            usort($kids, $by_date);
            unset($children[$id]);
            $groups[] = array(
                'kind'     => 'series',
                'template' => $template,
                'sheets'   => $kids,
            );
        }
        foreach ($children as $kids) {
            usort($kids, $by_date);
            $groups[] = array(
                'kind'     => 'series',
                'template' => null,
                'sheets'   => $kids,
            );
        }
        foreach ($singles as $sheet) {
            $groups[] = array(
                'kind'     => 'single',
                'template' => null,
                'sheets'   => array($sheet),
            );
        }

        usort($groups, function ($a, $b) {
            $key = function ($group) {
                $dates = array();
                foreach ($group['sheets'] as $sheet) {
                    if (!empty($sheet->event_date)) {
                        $dates[] = (string) $sheet->event_date;
                    }
                }
                sort($dates);
                $title = '';
                if ($group['template'] && isset($group['template']->title)) {
                    $title = (string) $group['template']->title;
                } elseif (!empty($group['sheets'][0]->title)) {
                    $title = (string) $group['sheets'][0]->title;
                }
                return ($dates ? $dates[0] : '9999-99-99') . ' ' . $title;
            };
            return strcmp($key($a), $key($b));
        });

        return $groups;
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

    /**
     * @return array{spots_needed:int,spots_filled:int,spots_open:int}
     */
    public static function activity_fill($spots_needed, $signed_up) {
        $need = max(0, (int) $spots_needed);
        $have = max(0, (int) $signed_up);
        return array(
            'spots_needed' => $need,
            'spots_filled' => $have,
            'spots_open'   => max(0, $need - $have),
        );
    }

    /**
     * @param array<int, array{spots_needed:int,spots_filled:int}> $activities
     * @return array{spots_needed:int,spots_filled:int,spots_open:int}
     */
    public static function sheet_fill_totals(array $activities) {
        $need = 0;
        $have = 0;
        foreach ($activities as $row) {
            $need += max(0, (int) ($row['spots_needed'] ?? 0));
            $have += max(0, (int) ($row['spots_filled'] ?? 0));
        }
        return array(
            'spots_needed' => $need,
            'spots_filled' => $have,
            'spots_open'   => max(0, $need - $have),
        );
    }

    /**
     * Combined fill for every live signup sheet attached to an event.
     * Templates and trashed sheets are skipped. Null when the event
     * has no spots to fill.
     *
     * @param int $event_id
     * @return array{spots_needed:int,spots_filled:int,spots_open:int}|null
     */
    public static function fill_for_event($event_id) {
        global $wpdb;
        $event_id = (int) $event_id;
        if ($event_id <= 0 || !isset($wpdb)) {
            return null;
        }
        $sheets_t = Azure_Database::get_table_name('volunteer_sheets');
        if (!$sheets_t) {
            return null;
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, pta_event_id, is_template, status FROM {$sheets_t} WHERE pta_event_id = %d AND is_template = 0 AND status <> %s",
            $event_id,
            'trashed'
        ));
        $fills = array();
        foreach ((array) $rows as $sheet) {
            if (!is_object($sheet)) {
                continue;
            }
            if ((int) ($sheet->pta_event_id ?? 0) !== $event_id) {
                continue;
            }
            if (!empty($sheet->is_template)) {
                continue;
            }
            if ((string) ($sheet->status ?? '') === 'trashed') {
                continue;
            }
            $sheet_id = (int) $sheet->id;
            foreach (self::get_activities($sheet_id) as $activity) {
                if (!is_object($activity) || (int) ($activity->sheet_id ?? 0) !== $sheet_id) {
                    continue;
                }
                $activity_id = (int) $activity->id;
                $signed = 0;
                foreach (self::get_signups_for_activity($activity_id) as $signup) {
                    if (is_object($signup) && (int) ($signup->activity_id ?? 0) === $activity_id) {
                        $signed++;
                    }
                }
                $fills[] = self::activity_fill((int) ($activity->spots_needed ?? 1), $signed);
            }
        }
        if (!$fills) {
            return null;
        }
        $totals = self::sheet_fill_totals($fills);
        if ($totals['spots_needed'] <= 0) {
            return null;
        }
        return $totals;
    }

    /**
     * Compact sheet rows for the iOS home widget / list.
     *
     * @param object[]|null $sheets
     * @return array<int, array>
     */
    public static function rest_sheet_summaries($sheets = null) {
        $sheets = is_array($sheets) ? $sheets : self::get_sheets('all');
        $out = array();
        foreach ($sheets as $sheet) {
            if (!is_object($sheet)) {
                continue;
            }
            if (!empty($sheet->is_template) || ($sheet->status ?? '') === 'trashed') {
                continue;
            }
            $activities = self::get_activities((int) $sheet->id);
            $fills = array();
            foreach ($activities as $activity) {
                $fills[] = self::activity_fill(
                    (int) ($activity->spots_needed ?? 1),
                    self::count_signups((int) $activity->id)
                );
            }
            $totals = self::sheet_fill_totals($fills);
            $out[] = array(
                'id'            => (int) $sheet->id,
                'title'         => (string) ($sheet->title ?? ''),
                'description'   => (string) ($sheet->description ?? ''),
                'event_date'    => (string) ($sheet->event_date ?? ''),
                'event_location'=> (string) ($sheet->event_location ?? ''),
                'status'        => (string) ($sheet->status ?? 'open'),
                'activities'    => count($activities),
                'spots_needed'  => $totals['spots_needed'],
                'spots_filled'  => $totals['spots_filled'],
                'spots_open'    => $totals['spots_open'],
            );
        }
        return $out;
    }

    /**
     * @param int $id
     * @return array|null
     */
    public static function rest_sheet_detail($id) {
        $sheet = self::get_sheet((int) $id);
        if (!$sheet) {
            return null;
        }
        $activities_out = array();
        $fills = array();
        foreach (self::get_activities((int) $sheet->id) as $activity) {
            $signups = array();
            foreach (self::get_signups_for_activity((int) $activity->id) as $signup) {
                $user = function_exists('get_userdata') ? get_userdata((int) $signup->user_id) : null;
                $signups[] = array(
                    'id'           => (int) $signup->id,
                    'user_id'      => (int) $signup->user_id,
                    'display_name' => $user ? (string) $user->display_name : ('User #' . (int) $signup->user_id),
                    'email'        => $user ? (string) $user->user_email : '',
                    'signed_up_at' => (string) ($signup->signed_up_at ?? ''),
                );
            }
            $fill = self::activity_fill((int) ($activity->spots_needed ?? 1), count($signups));
            $fills[] = $fill;
            $activities_out[] = array(
                'id'            => (int) $activity->id,
                'name'          => (string) ($activity->name ?? ''),
                'description'   => (string) ($activity->description ?? ''),
                'slot_start'    => (string) ($activity->slot_start ?? ''),
                'slot_end'      => (string) ($activity->slot_end ?? ''),
                'time_label'    => self::slot_time_label($sheet, $activity),
                'spots_needed'  => $fill['spots_needed'],
                'spots_filled'  => $fill['spots_filled'],
                'spots_open'    => $fill['spots_open'],
                'signups'       => $signups,
            );
        }
        $totals = self::sheet_fill_totals($fills);
        return array(
            'id'             => (int) $sheet->id,
            'title'          => (string) ($sheet->title ?? ''),
            'description'    => (string) ($sheet->description ?? ''),
            'event_date'     => (string) ($sheet->event_date ?? ''),
            'event_location' => (string) ($sheet->event_location ?? ''),
            'status'         => (string) ($sheet->status ?? 'open'),
            'spots_needed'   => $totals['spots_needed'],
            'spots_filled'   => $totals['spots_filled'],
            'spots_open'     => $totals['spots_open'],
            'activities'     => $activities_out,
        );
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

    public static function ensure_recurring_columns() {
        global $wpdb;
        $t = Azure_Database::get_table_name('volunteer_sheets');
        if (!$t || !isset($wpdb)) {
            return;
        }
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$t}", 0);
        if (!is_array($cols)) {
            return;
        }
        $adds = array(
            'is_template'         => 'tinyint(1) NOT NULL DEFAULT 0',
            'template_id'         => 'bigint(20) UNSIGNED DEFAULT 0',
            'series_key'          => 'varchar(255) DEFAULT \'\'',
            'outlook_calendar_id' => 'varchar(255) DEFAULT \'\'',
        );
        foreach ($adds as $col => $def) {
            if (!in_array($col, $cols, true)) {
                $wpdb->query("ALTER TABLE {$t} ADD COLUMN {$col} {$def}");
            }
        }
        // Outlook calendar ids are longer than varchar(255). A strict insert
        // then fails and the recurring sheet never appears in the list.
        $cal_col = $wpdb->get_row("SHOW COLUMNS FROM {$t} LIKE 'outlook_calendar_id'");
        if ($cal_col && isset($cal_col->Type) && stripos((string) $cal_col->Type, 'varchar') !== false) {
            $wpdb->query("ALTER TABLE {$t} MODIFY outlook_calendar_id text");
        }
    }

    /**
     * Prefer Outlook seriesMasterId; fall back to calendar + title.
     *
     * @param string $calendar_id
     * @param string $series_master_id
     * @param string $title
     * @return string
     */
    public static function build_series_key($calendar_id, $series_master_id, $title) {
        $cal    = trim((string) $calendar_id);
        $master = trim((string) $series_master_id);
        if ($master !== '') {
            return 'series:' . hash('sha256', $cal . "\n" . $master);
        }
        $norm = strtolower(trim(preg_replace('/\s+/', ' ', (string) $title)));
        if ($norm === '') {
            return '';
        }
        return 'title:' . hash('sha256', $cal . "\n" . $norm);
    }

    /**
     * True when an event belongs to the series stored on a template.
     * Hashed keys fit the column; unhashed keys from older saves still match.
     */
    public static function event_matches_series_key($series_key, $calendar_id, $series_master_id, $title) {
        $series_key = (string) $series_key;
        if ($series_key === '') {
            return false;
        }
        $primary = self::build_series_key($calendar_id, $series_master_id, $title);
        $by_title = self::build_series_key($calendar_id, '', $title);
        if ($series_key === $primary || ($by_title !== '' && $series_key === $by_title)) {
            return true;
        }
        $cal = trim((string) $calendar_id);
        $master = trim((string) $series_master_id);
        $norm = strtolower(trim(preg_replace('/\s+/', ' ', (string) $title)));
        if (strpos($series_key, 'series:') === 0 && !preg_match('/^series:[a-f0-9]{64}$/', $series_key)) {
            $parts = explode(':', $series_key, 3);
            $want_cal = $parts[1] ?? '';
            $want_master = $parts[2] ?? '';
            return $want_master !== '' && $want_master === $master && ($want_cal === '' || $want_cal === $cal);
        }
        if (strpos($series_key, 'title:') === 0 && !preg_match('/^title:[a-f0-9]{64}$/', $series_key)) {
            $parts = explode(':', $series_key, 3);
            $want_cal = $parts[1] ?? '';
            $want_title = $parts[2] ?? '';
            return $want_title !== '' && $want_title === $norm && ($want_cal === '' || $want_cal === $cal);
        }
        return false;
    }

    public static function series_key_for_event($event_id) {
        $event_id = (int) $event_id;
        if (!$event_id) {
            return '';
        }
        $master = (string) get_post_meta($event_id, '_outlook_series_master_id', true);
        $cal    = (string) get_post_meta($event_id, '_outlook_calendar_id', true);
        $title  = function_exists('get_the_title') ? (string) get_the_title($event_id) : '';
        return self::build_series_key($cal, $master, $title);
    }

    public static function title_series_key_for_event($event_id) {
        $event_id = (int) $event_id;
        if (!$event_id) {
            return '';
        }
        $cal   = (string) get_post_meta($event_id, '_outlook_calendar_id', true);
        $title = function_exists('get_the_title') ? (string) get_the_title($event_id) : '';
        return self::build_series_key($cal, '', $title);
    }

    /**
     * After Outlook sync updates a pta_event: keep instance dates in sync
     * and create a sheet from any matching recurring template.
     */
    public static function on_event_synced($event_id) {
        $event_id = (int) $event_id;
        if (!$event_id) {
            return;
        }
        self::ensure_recurring_columns();
        self::refresh_instance_from_event($event_id);
        $keys = array_values(array_unique(array_filter(array(
            self::series_key_for_event($event_id),
            self::title_series_key_for_event($event_id),
        ))));
        if (empty($keys)) {
            return;
        }
        foreach (self::get_templates_for_series_keys($keys) as $template) {
            self::ensure_instance_for_event($template, $event_id);
        }
    }

    public function on_event_trashed($post_id) {
        $post_id = (int) $post_id;
        if (!$post_id || !function_exists('get_post_type') || get_post_type($post_id) !== 'pta_event') {
            return;
        }
        self::set_instance_status_for_event($post_id, 'trashed');
    }

    public function on_event_untrashed($post_id) {
        $post_id = (int) $post_id;
        if (!$post_id || !function_exists('get_post_type') || get_post_type($post_id) !== 'pta_event') {
            return;
        }
        self::set_instance_status_for_event($post_id, 'open');
        self::on_event_synced($post_id);
    }

    /**
     * @param string[] $keys
     * @return object[]
     */
    public static function get_templates_for_series_keys(array $keys) {
        global $wpdb;
        $t = Azure_Database::get_table_name('volunteer_sheets');
        $keys = array_values(array_filter($keys));
        if (!$t || empty($keys)) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $sql = "SELECT * FROM {$t} WHERE is_template = 1 AND status <> 'trashed' AND series_key IN ({$placeholders})";
        return $wpdb->get_results($wpdb->prepare($sql, $keys)) ?: array();
    }

    public static function refresh_instance_from_event($event_id) {
        global $wpdb;
        $t = Azure_Database::get_table_name('volunteer_sheets');
        if (!$t) {
            return;
        }
        $meta = self::event_sheet_fields($event_id);
        if ($meta === null) {
            return;
        }
        $wpdb->update(
            $t,
            array(
                'event_date'     => $meta['event_date'],
                'event_location' => $meta['event_location'],
            ),
            array('pta_event_id' => (int) $event_id, 'is_template' => 0)
        );
    }

    public static function set_instance_status_for_event($event_id, $status) {
        global $wpdb;
        $t = Azure_Database::get_table_name('volunteer_sheets');
        if (!$t) {
            return;
        }
        $status = in_array($status, array('open', 'closed', 'trashed'), true) ? $status : 'trashed';
        $wpdb->update(
            $t,
            array('status' => $status),
            array('pta_event_id' => (int) $event_id, 'is_template' => 0)
        );
    }

    /**
     * @return array{title:string,event_date:string,event_location:string}|null
     */
    public static function event_sheet_fields($event_id) {
        $event_id = (int) $event_id;
        $post = function_exists('get_post') ? get_post($event_id) : null;
        if (!$post) {
            return null;
        }
        $location = (string) get_post_meta($event_id, '_EventVenue', true);
        $venue_id = (int) get_post_meta($event_id, '_EventVenueID', true);
        if ($location === '' && $venue_id) {
            $location = (string) get_the_title($venue_id);
        }
        return array(
            'title'          => (string) $post->post_title,
            'event_date'     => (string) get_post_meta($event_id, '_EventStartDate', true),
            'event_location' => $location,
        );
    }

    /**
     * Clone template activities onto an event if no instance exists yet.
     *
     * @param object $template
     * @param int    $event_id
     * @return int Instance sheet id, or 0.
     */
    public static function ensure_instance_for_event($template, $event_id) {
        global $wpdb;
        $t = Azure_Database::get_table_name('volunteer_sheets');
        $event_id = (int) $event_id;
        $template_id = (int) ($template->id ?? 0);
        if (!$t || !$event_id || !$template_id) {
            return 0;
        }
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$t} WHERE template_id = %d AND pta_event_id = %d AND status <> 'trashed' LIMIT 1",
            $template_id,
            $event_id
        ));
        if ($existing) {
            return (int) $existing;
        }
        $meta = self::event_sheet_fields($event_id);
        if ($meta === null) {
            return 0;
        }
        $sheet_title = trim((string) ($template->title ?? ''));
        $wpdb->insert($t, array(
            'title'               => $sheet_title !== '' ? $sheet_title : $meta['title'],
            'description'         => (string) ($template->description ?? ''),
            'pta_event_id'        => $event_id,
            'event_date'          => $meta['event_date'] ?: null,
            'event_location'      => $meta['event_location'],
            'status'              => ($template->status ?? 'open') === 'closed' ? 'closed' : 'open',
            'is_template'         => 0,
            'template_id'         => $template_id,
            'series_key'          => (string) ($template->series_key ?? ''),
            'outlook_calendar_id' => (string) ($template->outlook_calendar_id ?? ''),
            'created_by'          => (int) ($template->created_by ?? 0),
        ));
        $instance_id = (int) $wpdb->insert_id;
        if (!$instance_id) {
            return 0;
        }
        self::copy_activities((int) $template->id, $instance_id, $meta['event_date']);
        return $instance_id;
    }

    public static function copy_activities($from_sheet_id, $to_sheet_id, $event_date = '') {
        global $wpdb;
        $activities_t = Azure_Database::get_table_name('volunteer_activities');
        if (!$activities_t) {
            return;
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$activities_t} WHERE sheet_id = %d ORDER BY sort_order ASC, id ASC",
            (int) $from_sheet_id
        ));
        foreach ((array) $rows as $row) {
            $slot_start = (string) ($row->slot_start ?? '');
            $slot_end   = (string) ($row->slot_end ?? '');
            if ($event_date !== '') {
                $slot_start = self::redate_slot_to_event($slot_start, $event_date);
                $slot_end   = self::redate_slot_to_event($slot_end, $event_date);
            }
            $wpdb->insert($activities_t, array(
                'sheet_id'     => (int) $to_sheet_id,
                'name'         => (string) $row->name,
                'description'  => (string) ($row->description ?? ''),
                'spots_needed' => (int) $row->spots_needed,
                'slot_start'   => $slot_start !== '' ? $slot_start : null,
                'slot_end'     => $slot_end !== '' ? $slot_end : null,
                'sort_order'   => (int) ($row->sort_order ?? 0),
            ));
        }
    }

    public static function apply_template_to_matching_events($template) {
        $created = 0;
        $event_ids = self::find_event_ids_for_series_key((string) ($template->series_key ?? ''));
        foreach ($event_ids as $event_id) {
            if (self::ensure_instance_for_event($template, $event_id)) {
                $created++;
            }
        }
        return $created;
    }

    /**
     * @param string $series_key
     * @return int[]
     */
    public static function find_event_ids_for_series_key($series_key) {
        $series_key = (string) $series_key;
        if ($series_key === '' || !function_exists('get_posts')) {
            return array();
        }
        $ids = get_posts(array(
            'post_type'      => 'pta_event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ));
        $matched = array();
        foreach ($ids as $id) {
            $id = (int) $id;
            $cal = function_exists('get_post_meta') ? (string) get_post_meta($id, '_outlook_calendar_id', true) : '';
            $master = function_exists('get_post_meta') ? (string) get_post_meta($id, '_outlook_series_master_id', true) : '';
            $title = function_exists('get_the_title') ? (string) get_the_title($id) : '';
            if (self::event_matches_series_key($series_key, $cal, $master, $title)) {
                $matched[] = $id;
            }
        }
        return $matched;
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
     * Keep the clock time, move it onto another occurrence date.
     */
    public static function redate_slot_to_event($slot, $event_date) {
        $slot = trim((string) $slot);
        if ($slot === '') {
            return '';
        }
        if (preg_match('/(\d{2}:\d{2})/', $slot, $m)) {
            return self::normalize_slot_datetime($m[1], $event_date);
        }
        return self::normalize_slot_datetime($slot, $event_date);
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

    /**
     * Calendar date of the shift, in Pacific Time.
     */
    public static function slot_date_label($sheet, $activity) {
        $bounds = self::slot_bounds($sheet, $activity);
        if (empty($bounds['start'])) {
            return '';
        }
        try {
            $start = new DateTime($bounds['start'], new DateTimeZone(self::pacific_timezone()));
            return $start->format('F j, Y');
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Unix time for a Pacific wall-clock slot. Stored values have no
     * timezone, and WordPress runs PHP in UTC, so strtotime() would
     * shift the reminder by seven or eight hours.
     *
     * @param string $start
     * @return int
     */
    public static function slot_start_timestamp($start) {
        $start = trim((string) $start);
        if ($start === '') {
            return 0;
        }
        try {
            $dt = new DateTime($start, new DateTimeZone(self::pacific_timezone()));
            return $dt->getTimestamp();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * True when a shift should get its reminder on this sweep.
     * The hourly cron sends once the shift is inside the configured
     * lead (default two hours). A missed hour still catches it on the
     * next run, until the shift starts.
     *
     * @param string   $start
     * @param int      $now_ts
     * @param int|null $window_seconds Null uses the saved lead.
     */
    public static function reminder_is_due($start, $now_ts, $window_seconds = null) {
        if ($window_seconds === null) {
            $window_seconds = self::reminder_lead_seconds();
        }
        $start_ts = self::slot_start_timestamp($start);
        if ($start_ts <= 0 || $now_ts <= 0 || (int) $window_seconds <= 0) {
            return false;
        }
        $delta = $start_ts - (int) $now_ts;
        return $delta > 0 && $delta <= (int) $window_seconds;
    }

    public static function reminders_enabled() {
        if (!class_exists('Azure_Settings')) {
            return true;
        }
        $value = Azure_Settings::get_setting('volunteer_reminder_enabled', '1');
        return (string) $value !== '0';
    }

    /**
     * How far ahead the hourly sweep should mail a reminder.
     *
     * @return int seconds, or 0 when reminders are off
     */
    public static function reminder_lead_seconds() {
        if (!self::reminders_enabled()) {
            return 0;
        }
        $amount = 2;
        $unit = 'hours';
        if (class_exists('Azure_Settings')) {
            $amount = (int) Azure_Settings::get_setting('volunteer_reminder_amount', 2);
            $unit = (string) Azure_Settings::get_setting('volunteer_reminder_unit', 'hours');
        }
        if ($amount < 1) {
            $amount = 1;
        }
        if ($amount > 30) {
            $amount = 30;
        }
        return $amount * ($unit === 'days' ? DAY_IN_SECONDS : HOUR_IN_SECONDS);
    }

    public static function reminder_settings() {
        $amount = 2;
        $unit = 'hours';
        $enabled = true;
        if (class_exists('Azure_Settings')) {
            $enabled = self::reminders_enabled();
            $amount = (int) Azure_Settings::get_setting('volunteer_reminder_amount', 2);
            $unit = (string) Azure_Settings::get_setting('volunteer_reminder_unit', 'hours');
        }
        if ($amount < 1) {
            $amount = 2;
        }
        if ($amount > 30) {
            $amount = 30;
        }
        if ($unit !== 'days') {
            $unit = 'hours';
        }
        return array(
            'enabled' => $enabled,
            'amount'  => $amount,
            'unit'    => $unit,
        );
    }

    public static function site_name() {
        $name = function_exists('get_bloginfo') ? trim((string) get_bloginfo('name')) : '';
        return $name !== '' ? $name : __('PTA', 'azure-plugin');
    }

    /**
     * @return array<string, array>
     */
    public static function default_messages() {
        return Azure_Email_Messages::catalog();
    }

    public static function message_overrides() {
        return Azure_Email_Messages::overrides();
    }

    public static function message_for($key) {
        return Azure_Email_Messages::message_for($key);
    }

    public static function apply_message_tokens($text, array $vars) {
        return Azure_Email_Messages::apply($text, $vars);
    }

    public static function reminder_subject() {
        $msg = self::message_for('volunteer_reminder');
        $vars = self::notice_vars('', '', null, array(), '', '');
        return self::apply_message_tokens($msg['subject'], $vars);
    }

    /**
     * Plain-text signup or reminder body.
     *
     * @param string   $user_name
     * @param string   $intro
     * @param object   $sheet
     * @param object[] $activities
     * @param string   $event_title Linked event title when it differs from the sheet.
     * @param string   $event_url
     */
    /**
     * @param object|null $sheet
     * @param object[]    $activities
     * @return array<string, string>
     */
    public static function notice_vars($user_name, $intro, $sheet, $activities, $event_title = '', $event_url = '') {
        $event_name = trim((string) $event_title);
        if ($event_name === '' && is_object($sheet)) {
            $event_name = trim((string) ($sheet->title ?? ''));
        }

        $shift_lines = array();
        foreach ((array) $activities as $act) {
            if (is_string($act)) {
                $shift_lines[] = '• ' . $act;
                continue;
            }
            if (!is_object($act)) {
                continue;
            }
            $date = self::slot_date_label($sheet, $act);
            $time = self::slot_time_label($sheet, $act);
            $when = $date;
            if ($time !== '') {
                $when = $when !== '' ? $when . ', ' . $time . ' Pacific Time' : $time . ' Pacific Time';
            }
            $shift_lines[] = '• ' . $act->name . ($when !== '' ? ' — ' . $when : '');
        }

        $location = '';
        if (is_object($sheet) && !empty($sheet->event_location)) {
            $location = 'Location: ' . $sheet->event_location . "\n";
        }
        $event_url = trim((string) $event_url);
        $event_link = $event_url !== '' ? "\nAccess Event Page: " . $event_url . "\n" : '';

        return array(
            'name'       => (string) $user_name,
            'intro'      => (string) $intro,
            'event'      => $event_name,
            'shifts'     => $shift_lines ? implode("\n", $shift_lines) . "\n" : '',
            'location'   => $location,
            'event_link' => $event_link,
            'event_url'  => $event_url,
            'site_name'  => self::site_name(),
        );
    }

    public static function volunteer_notice($user_name, $intro, $sheet, $activities, $event_title = '', $event_url = '') {
        $vars = self::notice_vars($user_name, $intro, $sheet, $activities, $event_title, $event_url);
        $body = self::default_messages()['volunteer_confirmation']['body'];
        return self::apply_message_tokens($body, $vars);
    }

    /**
     * @return array{0:string,1:string} subject, body
     */
    public static function compose_volunteer_message($key, $user_name, $sheet, $activities, $event_title = '', $event_url = '') {
        $msg = self::message_for($key);
        if (!$msg) {
            $msg = self::default_messages()['volunteer_confirmation'];
        }
        $vars = self::notice_vars($user_name, $msg['intro'], $sheet, $activities, $event_title, $event_url);
        return array(
            self::apply_message_tokens($msg['subject'], $vars),
            self::apply_message_tokens($msg['body'], $vars),
        );
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
        $host = 'localhost';
        if (function_exists('home_url')) {
            $parsed = parse_url(home_url(), PHP_URL_HOST);
            if (is_string($parsed) && $parsed !== '') {
                $host = $parsed;
            }
        }
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

    /**
     * Azure AD users reach Calendar → Volunteer through access_pta_tools.
     * They do not have manage_options, so sheet CRUD cannot require that.
     */
    public static function user_can_manage_sheets() {
        if (!function_exists('current_user_can')) {
            return false;
        }
        if (current_user_can('manage_options')) {
            return true;
        }
        $cap = class_exists('Azure_Admin_Menu_Customizer')
            ? Azure_Admin_Menu_Customizer::CAP
            : 'access_pta_tools';
        return current_user_can($cap);
    }

    public function ajax_save_sheet() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        if (!self::user_can_manage_sheets()) {
            wp_send_json_error('Permission denied.');
        }

        global $wpdb;
        $sheets_t = Azure_Database::get_table_name('volunteer_sheets');
        $activities_t = Azure_Database::get_table_name('volunteer_activities');
        self::ensure_recurring_columns();

        $sheet_id    = absint($_POST['sheet_id'] ?? 0);
        $title       = sanitize_text_field($_POST['title'] ?? '');
        $description = sanitize_textarea_field($_POST['description'] ?? '');
        // `pta_event_id` is the new field; accept the legacy `tec_event_id` POST
        // key too so any cached admin JS keeps working until the next refresh.
        $is_recurring = !empty($_POST['is_recurring']);
        $assign      = $is_recurring ? true : !empty($_POST['assign_to_event']);
        $event_choice = isset($_POST['pta_event_id']) ? wp_unslash($_POST['pta_event_id']) : ($_POST['tec_event_id'] ?? '0');
        $event_date  = sanitize_text_field($_POST['event_date'] ?? '');
        $event_loc   = sanitize_text_field($_POST['event_location'] ?? '');
        $status      = in_array($_POST['status'] ?? '', array('open', 'closed'), true) ? $_POST['status'] : 'open';

        if (empty($title)) {
            wp_send_json_error('Title is required.');
        }

        $new_event_title = sanitize_text_field($_POST['new_event_title'] ?? '');
        $event_id = 0;
        if ($is_recurring) {
            $event_id = absint($event_choice);
            if (!$event_id || (function_exists('get_post_type') && get_post_type($event_id) !== 'pta_event')) {
                wp_send_json_error('Pick an existing event in the series. Recurring sheets cannot create a new event.');
            }
        } else {
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

        $existing_sheet = $sheet_id ? self::get_sheet($sheet_id) : null;
        $editing_instance = $existing_sheet && empty($existing_sheet->is_template) && !empty($existing_sheet->template_id);
        $make_template = $is_recurring && !$editing_instance;

        $data = array(
            'title'          => $title,
            'description'    => $description,
            'pta_event_id'   => $make_template ? 0 : $event_id,
            'event_date'     => $make_template ? null : ($event_date ?: null),
            'event_location' => $event_loc,
            'status'         => $status,
        );
        if ($make_template) {
            $series_key = self::series_key_for_event($event_id);
            if ($series_key === '') {
                wp_send_json_error('That event has no series key yet. Sync the Outlook calendar, or pick an event whose title matches the rest of the series.');
            }
            $data['is_template'] = 1;
            $data['template_id'] = 0;
            $data['series_key'] = $series_key;
            $data['outlook_calendar_id'] = (string) get_post_meta($event_id, '_outlook_calendar_id', true);
        }

        if ($make_template) {
            unset($data['event_date']);
        } elseif ($data['event_date'] === null) {
            unset($data['event_date']);
        }

        if ($sheet_id) {
            $updated = $wpdb->update($sheets_t, $data, array('id' => $sheet_id));
            if ($updated === false) {
                wp_send_json_error($wpdb->last_error ?: 'Could not update the sign-up sheet.');
            }
        } else {
            $data['created_by'] = get_current_user_id();
            $inserted = $wpdb->insert($sheets_t, $data);
            if ($inserted === false) {
                wp_send_json_error($wpdb->last_error ?: 'Could not save the sign-up sheet.');
            }
            $sheet_id = (int) $wpdb->insert_id;
        }
        if (!$sheet_id) {
            wp_send_json_error('Could not save the sign-up sheet.');
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

        $instances = 0;
        if ($make_template) {
            $template = self::get_sheet($sheet_id);
            if ($template) {
                $instances = (int) self::apply_template_to_matching_events($template);
                if (!$instances) {
                    $instances = self::ensure_instance_for_event($template, $event_id) ? 1 : 0;
                }
            }
        }

        $payload = array(
            'sheet_id'  => $sheet_id,
            'instances' => $instances,
        );
        if ($make_template && $instances === 0) {
            $payload['warning'] = 'The template was saved, but it could not be copied onto the events in that series.';
        }
        $this->touch_upcoming_cache();
        wp_send_json_success($payload);
    }

    public function ajax_delete_sheet() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        if (!self::user_can_manage_sheets()) {
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
        $this->touch_upcoming_cache();

        wp_send_json_success();
    }

    public function ajax_get_sheet() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');

        // Admin sheet editor only. Without this any logged-in user could walk
        // sheet_id and read every sheet's activities and spot counts.
        if (!self::user_can_manage_sheets()) {
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
            'is_template' => !empty($sheet->is_template),
            'is_instance' => empty($sheet->is_template) && !empty($sheet->template_id),
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
            "SELECT * FROM {$t} WHERE pta_event_id = %d AND status <> 'trashed' AND (is_template = 0 OR is_template IS NULL) ORDER BY event_date ASC, id ASC",
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
            if (function_exists('current_user_can') && self::user_can_manage_sheets()) {
                $edit = admin_url('admin.php?page=azure-plugin-calendar&tab=volunteer&edit_sheet=' . (int) $sheet->id);
                echo '<p class="azure-vs-admin-edit"><a href="' . esc_url($edit) . '">' . esc_html__('Edit this event’s sign-up sheet', 'azure-plugin') . '</a></p>';
            }
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
        $this->touch_upcoming_cache();

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
        $this->touch_upcoming_cache();

        wp_send_json_success(array('message' => __('You have withdrawn from this activity.', 'azure-plugin')));
    }

    /**
     * Signup counts are baked into cached [up-next] HTML.
     */
    private function touch_upcoming_cache() {
        if (class_exists('Azure_Upcoming_Module')) {
            Azure_Upcoming_Module::invalidate_cache();
        }
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

        list($event_title, $event_url) = $this->event_notice_fields($sheet);
        if ($event_title === '') {
            $event_title = (string) $sheet->title;
        }
        list($subject, $message) = self::compose_volunteer_message(
            'volunteer_confirmation',
            $user->display_name,
            $sheet,
            $activities,
            $event_title,
            $event_url
        );

        $attachments = $this->write_ics_attachments($sheet, $activities, $user);
        wp_mail($user->user_email, $subject, $message, array(), $attachments);
        $this->cleanup_ics_attachments($attachments);
    }

    /**
     * @param object $sheet
     * @return array{0:string,1:string} event title, permalink
     */
    private function event_notice_fields($sheet) {
        $title = '';
        $url = '';
        $event_id = (int) ($sheet->pta_event_id ?? 0);
        if ($event_id <= 0 || !function_exists('get_post')) {
            return array($title, $url);
        }
        $post = get_post($event_id);
        if (!$post) {
            return array($title, $url);
        }
        $title = (string) $post->post_title;
        if ($post->post_status !== 'trash' && function_exists('get_permalink')) {
            $link = get_permalink($event_id);
            if (is_string($link) && $link !== '') {
                $url = $link;
            }
        }
        return array($title, $url);
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
        $window = self::reminder_lead_seconds();
        if ($window <= 0) {
            return;
        }
        $sheets_t   = Azure_Database::get_table_name('volunteer_sheets');
        $signups_t  = Azure_Database::get_table_name('volunteer_signups');
        if (!$sheets_t || !$signups_t) {
            return;
        }

        $now = time();
        $sheets = $wpdb->get_results("SELECT * FROM {$sheets_t} WHERE status = 'open'");
        $checked = 0;

        foreach ((array) $sheets as $sheet) {
            if (!empty($sheet->is_template)) {
                continue;
            }
            $activities = self::get_activities($sheet->id);
            list($event_title, $event_url) = $this->event_notice_fields($sheet);
            foreach ($activities as $act) {
                $bounds = self::slot_bounds($sheet, $act);
                if (empty($bounds['start']) || !self::reminder_is_due($bounds['start'], $now, $window)) {
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

                    list($subject, $body) = self::compose_volunteer_message(
                        'volunteer_reminder',
                        $user->display_name,
                        $sheet,
                        array($act),
                        $event_title,
                        $event_url
                    );

                    $attachments = $this->write_ics_attachments($sheet, array($act), $user);
                    wp_mail($user->user_email, $subject, $body, array(), $attachments);
                    $this->cleanup_ics_attachments($attachments);

                    $wpdb->update($signups_t, array('reminder_sent' => 1), array('id' => $signup->id));
                }
            }
        }

        if (class_exists('Azure_Logger')) {
            Azure_Logger::debug_module('Volunteer', 'Reminder sweep completed. Slots inside the reminder window: ' . $checked);
        }
    }

    public function save_reminder_settings() {
        if (empty($_POST['azure_volunteer_reminder_settings'])) {
            return;
        }
        if (!self::user_can_manage_sheets()) {
            return;
        }
        check_admin_referer('azure_volunteer_reminder_settings');

        $enabled = !empty($_POST['volunteer_reminder_enabled']) ? '1' : '0';
        $amount = isset($_POST['volunteer_reminder_amount']) ? (int) $_POST['volunteer_reminder_amount'] : 2;
        if ($amount < 1) {
            $amount = 1;
        }
        if ($amount > 30) {
            $amount = 30;
        }
        $unit = (isset($_POST['volunteer_reminder_unit']) && $_POST['volunteer_reminder_unit'] === 'days') ? 'days' : 'hours';
        if (class_exists('Azure_Settings')) {
            Azure_Settings::update_setting('volunteer_reminder_enabled', $enabled);
            Azure_Settings::update_setting('volunteer_reminder_amount', (string) $amount);
            Azure_Settings::update_setting('volunteer_reminder_unit', $unit);
        }

        $redirect = wp_get_referer();
        if (!$redirect) {
            $redirect = admin_url('admin.php?page=azure-plugin-calendar&tab=volunteer');
        }
        wp_safe_redirect(add_query_arg('volunteer_reminder', 'saved', $redirect));
        exit;
    }

    public static function save_message($key, $subject, $body) {
        Azure_Email_Messages::save_message($key, $subject, $body);
    }

    public static function reset_message($key) {
        Azure_Email_Messages::reset_message($key);
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
     * One row per Outlook series. The option value is the next upcoming
     * occurrence so saving can copy the sheet onto every date in the series.
     *
     * @return array<int,array{id:int,title:string,date:string,location:string,count:int,series_key:string}>
     */
    public static function get_recurring_series_for_dropdown() {
        if (!function_exists('get_posts')) {
            return array();
        }
        $events = get_posts(array(
            'post_type'      => 'pta_event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => array(array(
                'key'     => '_outlook_series_master_id',
                'value'   => '',
                'compare' => '!=',
            )),
        ));
        $groups = array();
        $today = function_exists('current_time') ? current_time('Y-m-d') : date('Y-m-d');
        foreach ($events as $e) {
            $cal = (string) get_post_meta($e->ID, '_outlook_calendar_id', true);
            $master = (string) get_post_meta($e->ID, '_outlook_series_master_id', true);
            $key = self::build_series_key($cal, $master, $e->post_title);
            if ($key === '') {
                continue;
            }
            $start = (string) get_post_meta($e->ID, '_EventStartDate', true);
            if (!isset($groups[$key])) {
                $row = self::event_dropdown_row($e);
                $row['series_key'] = $key;
                $row['count'] = 0;
                $row['next_date'] = '';
                $groups[$key] = $row;
            }
            $groups[$key]['count']++;
            $day = substr($start, 0, 10);
            $is_upcoming = $day !== '' && $day >= $today;
            $picked = (string) $groups[$key]['next_date'];
            $picked_upcoming = $picked !== '' && substr($picked, 0, 10) >= $today;
            $replace = $picked === ''
                || ($is_upcoming && !$picked_upcoming)
                || ($is_upcoming && $picked_upcoming && $start < $picked)
                || (!$picked_upcoming && !$is_upcoming && $start > $picked);
            if ($replace) {
                $row = self::event_dropdown_row($e);
                $groups[$key]['id'] = $row['id'];
                $groups[$key]['title'] = $row['title'];
                $groups[$key]['date'] = $row['date'];
                $groups[$key]['location'] = $row['location'];
                $groups[$key]['next_date'] = $start;
            }
        }
        $out = array_values($groups);
        usort($out, function ($a, $b) {
            return strcasecmp((string) $a['title'], (string) $b['title']);
        });
        return $out;
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
