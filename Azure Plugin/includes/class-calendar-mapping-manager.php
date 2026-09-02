<?php
/**
 * Calendar Mapping Manager
 *
 * CRUD layer over `wp_azure_calendar_mappings`. Each row pairs an
 * Outlook calendar ID with a `pta_event_category` term so the sync
 * engine knows where to drop incoming events.
 *
 * Adapted from the v3.97-retired `Azure_TEC_Calendar_Mapping_Manager`,
 * but uses the renamed schema (`category_id` / `category_name`) and
 * targets `pta_event_category` instead of `tribe_events_cat`.
 *
 * @package AzurePlugin
 * @since   3.113
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Calendar_Mapping_Manager {

    /** @var string|null Fully-qualified table name. */
    private $table_name;

    public function __construct() {
        if (class_exists('Azure_Database')) {
            $this->table_name = Azure_Database::get_table_name('calendar_mappings');
        } else {
            global $wpdb;
            $this->table_name = $wpdb->prefix . 'azure_calendar_mappings';
        }
    }

    /** @return object[] */
    public function get_all_mappings() {
        global $wpdb;
        if (!$this->table_name) return array();
        return $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY sync_enabled DESC, outlook_calendar_name ASC");
    }

    /**
     * @param string $outlook_calendar_id
     * @return object|null
     */
    public function get_mapping_by_calendar_id($outlook_calendar_id) {
        global $wpdb;
        if (!$this->table_name) return null;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE outlook_calendar_id = %s",
            $outlook_calendar_id
        ));
    }

    /**
     * @param int $mapping_id
     * @return object|null
     */
    public function get_mapping_by_id($mapping_id) {
        global $wpdb;
        if (!$this->table_name) return null;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            (int) $mapping_id
        ));
    }

    /** @return object[] */
    public function get_enabled_calendars() {
        global $wpdb;
        if (!$this->table_name) return array();
        return $wpdb->get_results(
            "SELECT * FROM {$this->table_name} WHERE sync_enabled = 1 ORDER BY outlook_calendar_name ASC"
        );
    }

    /**
     * Create a new mapping row. Returns the new id, or false on failure.
     *
     * @return int|false
     */
    public function create_mapping($outlook_calendar_id, $outlook_calendar_name, $category_id, $category_name, $sync_enabled = 1, $schedule_enabled = 0, $schedule_frequency = 'hourly', $schedule_lookback_days = 30, $schedule_lookahead_days = 365, $mapping_mode = 'single', $category_rules = '') {
        global $wpdb;
        if (!$this->table_name) return false;

        $mode  = self::sanitize_mapping_mode($mapping_mode);
        $rules = self::encode_category_rules($category_rules);

        $data = array(
            'outlook_calendar_id'     => $outlook_calendar_id,
            'outlook_calendar_name'   => $outlook_calendar_name,
            'category_id'             => (int) $category_id,
            'category_name'           => $category_name,
            'mapping_mode'            => $mode,
            'category_rules'          => $rules,
            'sync_enabled'            => (int) $sync_enabled,
            'schedule_enabled'        => (int) $schedule_enabled,
            'schedule_frequency'      => $schedule_frequency,
            'schedule_lookback_days'  => (int) $schedule_lookback_days,
            'schedule_lookahead_days' => (int) $schedule_lookahead_days,
            'created_at'              => current_time('mysql'),
            'updated_at'              => current_time('mysql'),
        );
        $formats = array('%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s');

        $result = $wpdb->insert($this->table_name, $data, $formats);
        if (!$result) {
            Azure_Logger::error('Calendar Mapping Manager: insert failed - ' . $wpdb->last_error, 'Calendar');
            return false;
        }

        $insert_id = (int) $wpdb->insert_id;
        Azure_Logger::info("Calendar Mapping Manager: created mapping {$insert_id} ({$outlook_calendar_name} -> {$category_name})", 'Calendar');

        if ($schedule_enabled) {
            $this->schedule_mapping_sync($insert_id, $schedule_frequency);
        }

        return $insert_id;
    }

    /**
     * Update an existing mapping row.
     *
     * @return bool
     */
    public function update_mapping($mapping_id, $outlook_calendar_id, $outlook_calendar_name, $category_id, $category_name, $sync_enabled = 1, $schedule_enabled = 0, $schedule_frequency = 'hourly', $schedule_lookback_days = 30, $schedule_lookahead_days = 365, $mapping_mode = 'single', $category_rules = '') {
        global $wpdb;
        if (!$this->table_name) return false;

        $old = $this->get_mapping_by_id($mapping_id);
        $mode  = self::sanitize_mapping_mode($mapping_mode);
        $rules = self::encode_category_rules($category_rules);

        $data = array(
            'outlook_calendar_id'     => $outlook_calendar_id,
            'outlook_calendar_name'   => $outlook_calendar_name,
            'category_id'             => (int) $category_id,
            'category_name'           => $category_name,
            'mapping_mode'            => $mode,
            'category_rules'          => $rules,
            'sync_enabled'            => (int) $sync_enabled,
            'schedule_enabled'        => (int) $schedule_enabled,
            'schedule_frequency'      => $schedule_frequency,
            'schedule_lookback_days'  => (int) $schedule_lookback_days,
            'schedule_lookahead_days' => (int) $schedule_lookahead_days,
            'updated_at'              => current_time('mysql'),
        );
        $formats = array('%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%s');

        $result = $wpdb->update($this->table_name, $data, array('id' => (int) $mapping_id), $formats, array('%d'));
        if ($result === false) {
            Azure_Logger::error("Calendar Mapping Manager: update failed for {$mapping_id} - " . $wpdb->last_error, 'Calendar');
            return false;
        }

        Azure_Logger::info("Calendar Mapping Manager: updated mapping {$mapping_id} ({$outlook_calendar_name} -> {$category_name})", 'Calendar');

        // Reconcile per-mapping cron schedule.
        if ($schedule_enabled) {
            $this->schedule_mapping_sync((int) $mapping_id, $schedule_frequency);
        } elseif ($old && !empty($old->schedule_enabled)) {
            $this->unschedule_mapping_sync((int) $mapping_id);
        }

        return true;
    }

    /**
     * Delete a mapping row by id (and clean its cron schedule).
     *
     * @return bool
     */
    public function delete_mapping($mapping_id) {
        global $wpdb;
        if (!$this->table_name) return false;

        $this->unschedule_mapping_sync((int) $mapping_id);

        $result = $wpdb->delete($this->table_name, array('id' => (int) $mapping_id), array('%d'));
        if ($result) {
            Azure_Logger::info("Calendar Mapping Manager: deleted mapping {$mapping_id}", 'Calendar');
            return true;
        }
        Azure_Logger::error("Calendar Mapping Manager: delete failed for {$mapping_id}", 'Calendar');
        return false;
    }

    /**
     * Quick toggle for the sync_enabled column (used by the row switch).
     *
     * @return bool
     */
    public function set_sync_enabled($mapping_id, $enabled) {
        global $wpdb;
        if (!$this->table_name) return false;
        return $wpdb->update(
            $this->table_name,
            array('sync_enabled' => $enabled ? 1 : 0, 'updated_at' => current_time('mysql')),
            array('id' => (int) $mapping_id),
            array('%d', '%s'),
            array('%d')
        ) !== false;
    }

    /**
     * Stamp `last_sync` for a calendar after a successful run.
     *
     * @return bool
     */
    public function update_last_sync($outlook_calendar_id) {
        global $wpdb;
        if (!$this->table_name) return false;
        return $wpdb->update(
            $this->table_name,
            array('last_sync' => current_time('mysql'), 'updated_at' => current_time('mysql')),
            array('outlook_calendar_id' => $outlook_calendar_id),
            array('%s', '%s'),
            array('%s')
        ) !== false;
    }

    /**
     * Idempotently ensure a category term with the given name exists.
     * Uses the Event store taxonomy (pta_event_category or tribe_events_cat).
     *
     * @param string      $category_name
     * @param string|null $taxonomy
     * @return int|false
     */
    public function ensure_category_exists($category_name, $taxonomy = null) {
        $category_name = trim((string) $category_name);
        if ($category_name === '') {
            return false;
        }

        if ($taxonomy === null) {
            $taxonomy = class_exists('Azure_Event_CPT')
                ? Azure_Event_CPT::write_taxonomy()
                : 'pta_event_category';
        }
        if (!taxonomy_exists($taxonomy)) {
            Azure_Logger::error("Calendar Mapping Manager: taxonomy '{$taxonomy}' is not registered", 'Calendar');
            return false;
        }

        $term = term_exists($category_name, $taxonomy);
        if ($term) {
            return is_array($term) ? (int) $term['term_id'] : (int) $term;
        }

        $created = wp_insert_term($category_name, $taxonomy, array(
            'description' => "Events synced from Outlook calendar: {$category_name}",
            'slug'        => sanitize_title($category_name),
        ));

        if (is_wp_error($created)) {
            Azure_Logger::error("Calendar Mapping Manager: failed to create category '{$category_name}': " . $created->get_error_message(), 'Calendar');
            return false;
        }

        return (int) $created['term_id'];
    }

    /**
     * Bulk stats used by the Sync admin page header.
     *
     * @return array { total, enabled, disabled, scheduled }
     */
    public function get_mapping_statistics() {
        global $wpdb;
        if (!$this->table_name) {
            return array('total' => 0, 'enabled' => 0, 'disabled' => 0, 'scheduled' => 0);
        }
        $total     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $enabled   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE sync_enabled = 1");
        $scheduled = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE schedule_enabled = 1");
        return array(
            'total'     => $total,
            'enabled'   => $enabled,
            'disabled'  => $total - $enabled,
            'scheduled' => $scheduled,
        );
    }

    /**
     * Schedule a per-mapping cron event. Frequency labels match the
     * dropdown in the Sync admin page; unknown values fall back to
     * `hourly`.
     */
    private function schedule_mapping_sync($mapping_id, $frequency) {
        $this->unschedule_mapping_sync($mapping_id);

        $cron_schedule = $this->resolve_cron_schedule((string) $frequency);

        $scheduled = wp_schedule_event(time(), $cron_schedule, 'azure_calendar_mapping_sync', array((int) $mapping_id));
        if ($scheduled === false) {
            Azure_Logger::error("Calendar Mapping Manager: failed to schedule mapping {$mapping_id}", 'Calendar');
            return false;
        }

        Azure_Logger::info("Calendar Mapping Manager: scheduled mapping {$mapping_id} on {$cron_schedule}", 'Calendar');
        return true;
    }

    private function unschedule_mapping_sync($mapping_id) {
        $args      = array((int) $mapping_id);
        $hook      = 'azure_calendar_mapping_sync';
        $timestamp = wp_next_scheduled($hook, $args);
        while ($timestamp) {
            wp_unschedule_event($timestamp, $hook, $args);
            $timestamp = wp_next_scheduled($hook, $args);
        }
        return true;
    }

    /**
     * Map UI frequency labels to actual cron schedule slugs registered
     * by Azure_PTA_Cron::register_cron_schedules().
     */
    public static function sanitize_mapping_mode($mode) {
        $mode = is_string($mode) ? strtolower(trim($mode)) : 'single';
        return $mode === 'rules' ? 'rules' : 'single';
    }

    public static function look_in_options() {
        return array(
            'subject'          => __('Subject of calendar event', 'azure-plugin'),
            'body'             => __('Body of calendar event', 'azure-plugin'),
            'subject_or_body'  => __('Subject or body', 'azure-plugin'),
        );
    }

    /**
     * @param mixed $raw JSON string or array
     * @return array<int,array{term:string,look_in:string,category_name:string,category_id:int}>
     */
    public static function sanitize_category_rules($raw) {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : array();
        }
        if (!is_array($raw)) {
            return array();
        }
        $look_ins = array_keys(self::look_in_options());
        $out = array();
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $term = trim((string) ($row['term'] ?? ''));
            $name = trim((string) ($row['category_name'] ?? ''));
            if ($term === '' || $name === '') {
                continue;
            }
            $look_in = strtolower(trim((string) ($row['look_in'] ?? 'subject')));
            if (!in_array($look_in, $look_ins, true)) {
                $look_in = 'subject';
            }
            $out[] = array(
                'term'          => $term,
                'look_in'       => $look_in,
                'category_name' => $name,
                'category_id'   => (int) ($row['category_id'] ?? 0),
            );
        }
        return $out;
    }

    public static function encode_category_rules($raw) {
        $rules = self::sanitize_category_rules($raw);
        return wp_json_encode($rules);
    }

    public static function decode_category_rules($stored) {
        return self::sanitize_category_rules($stored);
    }

    /**
     * Which pta_event_category names an Outlook event should receive.
     *
     * @param array       $event   Processed Graph event (title, description).
     * @param object|null $mapping Mapping row, or null for no categories.
     * @return string[]
     */
    public static function categories_for_event($event, $mapping) {
        if (is_string($mapping) || is_numeric($mapping)) {
            $name = trim((string) $mapping);
            return $name !== '' ? array($name) : array();
        }
        if (!is_object($mapping)) {
            return array();
        }
        $mode = self::sanitize_mapping_mode($mapping->mapping_mode ?? 'single');
        if ($mode !== 'rules') {
            $name = trim((string) ($mapping->category_name ?? ''));
            return $name !== '' ? array($name) : array();
        }
        $matched = array();
        foreach (self::decode_category_rules($mapping->category_rules ?? '') as $rule) {
            if (self::event_matches_rule($event, $rule)) {
                $matched[] = $rule['category_name'];
            }
        }
        $matched = array_values(array_unique(array_filter($matched)));
        if (empty($matched)) {
            $fallback = trim((string) ($mapping->category_name ?? ''));
            if ($fallback !== '') {
                $matched[] = $fallback;
            }
        }
        return $matched;
    }

    /**
     * @param array $event
     * @param array $rule
     */
    public static function event_matches_rule($event, array $rule) {
        $term = trim((string) ($rule['term'] ?? ''));
        if ($term === '') {
            return false;
        }
        $haystack = self::event_haystack($event, $rule['look_in'] ?? 'subject');
        if ($haystack === '') {
            return false;
        }
        if (function_exists('mb_stripos')) {
            return mb_stripos($haystack, $term, 0, 'UTF-8') !== false;
        }
        return stripos($haystack, $term) !== false;
    }

    public static function event_haystack($event, $look_in) {
        $title = is_array($event) ? (string) ($event['title'] ?? $event['subject'] ?? '') : '';
        $body  = is_array($event) ? (string) ($event['description'] ?? $event['body'] ?? '') : '';
        if (function_exists('wp_strip_all_tags')) {
            $title = wp_strip_all_tags($title);
            $body  = wp_strip_all_tags($body);
        }
        $look_in = strtolower((string) $look_in);
        if ($look_in === 'body') {
            return $body;
        }
        if ($look_in === 'subject_or_body') {
            return trim($title . "\n" . $body);
        }
        return $title;
    }

    private function resolve_cron_schedule($frequency) {
        switch ($frequency) {
            case '15min':
                return 'every_15_minutes';
            case '30min':
                return 'every_30_minutes';
            case 'twicedaily':
                return 'twicedaily';
            case 'daily':
                return 'daily';
            case 'hourly':
            default:
                return 'hourly';
        }
    }
}
