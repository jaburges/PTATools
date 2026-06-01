<?php
/**
 * Calendar Sync Engine
 *
 * Pulls events from Outlook calendars (via Microsoft Graph) and writes
 * them into native `pta_event` posts. Adapted from the v3.97-retired
 * `class-tec-sync-engine.php`, but drops every `tribe_events` write,
 * the Phase-2 dual-write mirror, the bidirectional TEC->Outlook path,
 * and any `Tribe__*` dependency. Outlook is the system of record;
 * `pta_event` is the only WP target.
 *
 * Wired into init_calendar_components() in azure-plugin.php (backend
 * only). Cron handler (`azure_calendar_sync_events`) is registered in
 * the constructor and dispatched by class-pta-cron.php.
 *
 * @package AzurePlugin
 * @since   3.113
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Calendar_Sync_Engine {

    /** @var Azure_Calendar_GraphAPI|null */
    private $graph_api;

    public function __construct() {
        if (class_exists('Azure_Calendar_GraphAPI')) {
            $this->graph_api = new Azure_Calendar_GraphAPI();
        }

        // Cron entry point. The schedule itself is owned by
        // Azure_PTA_Cron::ensure_events_scheduled(); this only binds
        // the handler that fires when the event runs.
        add_action('azure_calendar_sync_events', array($this, 'run_scheduled_sync'));

        // Per-mapping cron hooks (one event per mapping, see
        // Azure_Calendar_Mapping_Manager::schedule_mapping_sync()).
        add_action('azure_calendar_mapping_sync', array($this, 'run_mapping_sync'), 10, 1);
    }

    /**
     * Cron entry point. Syncs every enabled mapping using the global
     * default frequency. Per-mapping schedules call `run_mapping_sync`
     * directly via their own cron hook.
     */
    public function run_scheduled_sync() {
        Azure_Logger::info('Calendar Sync Engine: Scheduled sync starting', 'Calendar');

        $settings      = Azure_Settings::get_all_settings();
        $user_email    = $settings['calendar_embed_user_email'] ?? '';
        $mailbox_email = $settings['calendar_embed_mailbox_email'] ?? '';

        if (empty($user_email) || empty($mailbox_email)) {
            Azure_Logger::warning('Calendar Sync Engine: Scheduled sync skipped, mailbox not configured', 'Calendar');
            return;
        }

        $results = $this->sync_all_enabled_calendars(null, null, $user_email, $mailbox_email);

        if (class_exists('Azure_Database')) {
            Azure_Database::log_activity(
                'calendar',
                'scheduled_sync_completed',
                'sync',
                null,
                array(
                    'calendars'      => $results['total_calendars']      ?? 0,
                    'events_synced'  => $results['total_events_synced']  ?? 0,
                    'events_deleted' => $results['total_events_deleted'] ?? 0,
                    'errors'         => $results['total_errors']         ?? 0,
                ),
                ($results['success'] ?? false) ? 'success' : 'error'
            );
        }
    }

    /**
     * Per-mapping cron entry point. Bound to `azure_calendar_mapping_sync`
     * with the mapping id as a single argument.
     *
     * @param int $mapping_id
     */
    public function run_mapping_sync($mapping_id) {
        $mapping_id = (int) $mapping_id;
        if (!$mapping_id) {
            return;
        }
        if (!class_exists('Azure_Calendar_Mapping_Manager')) {
            return;
        }

        $manager = new Azure_Calendar_Mapping_Manager();
        $mapping = $manager->get_mapping_by_id($mapping_id);
        if (!$mapping) {
            Azure_Logger::warning("Calendar Sync Engine: scheduled mapping {$mapping_id} not found", 'Calendar');
            return;
        }

        $settings      = Azure_Settings::get_all_settings();
        $user_email    = $settings['calendar_embed_user_email'] ?? '';
        $mailbox_email = $settings['calendar_embed_mailbox_email'] ?? '';
        if (empty($user_email) || empty($mailbox_email)) {
            Azure_Logger::warning("Calendar Sync Engine: scheduled mapping {$mapping_id} skipped, mailbox not configured", 'Calendar');
            return;
        }

        $lookback  = (int) ($mapping->schedule_lookback_days ?? 30);
        $lookahead = (int) ($mapping->schedule_lookahead_days ?? 365);
        $start     = gmdate('Y-m-d\TH:i:s\Z', strtotime("-{$lookback} days"));
        $end       = gmdate('Y-m-d\TH:i:s\Z', strtotime("+{$lookahead} days"));

        $result = $this->sync_single_calendar(
            $mapping->outlook_calendar_id,
            $mapping->category_name,
            $start,
            $end,
            $user_email,
            $mailbox_email
        );

        $manager->update_last_sync($mapping->outlook_calendar_id);

        if (class_exists('Azure_Database')) {
            Azure_Database::log_activity(
                'calendar',
                'mapping_scheduled_sync_completed',
                'sync',
                (string) $mapping_id,
                array(
                    'mapping_id'     => $mapping_id,
                    'calendar_name'  => $mapping->outlook_calendar_name,
                    'events_synced'  => $result['events_synced']  ?? 0,
                    'events_deleted' => $result['events_deleted'] ?? 0,
                    'errors'         => $result['errors']         ?? 0,
                ),
                ($result['success'] ?? false) ? 'success' : 'error'
            );
        }
    }

    /**
     * Sync every mapping that has `sync_enabled = 1`. Date range is
     * the global default (30 days back / 365 days ahead) when not
     * supplied. Per-mapping date ranges are only honoured by the
     * per-mapping cron path.
     *
     * @param string|null $start_date    ISO 8601 (Z) start range.
     * @param string|null $end_date      ISO 8601 (Z) end range.
     * @param string|null $user_email    Authenticated M365 user email.
     * @param string|null $mailbox_email Shared mailbox to read from.
     * @return array {
     *     @type bool   $success
     *     @type int    $total_calendars
     *     @type int    $total_events_synced
     *     @type int    $total_errors
     *     @type array  $calendar_results  keyed by outlook_calendar_id
     * }
     */
    public function sync_all_enabled_calendars($start_date = null, $end_date = null, $user_email = null, $mailbox_email = null) {
        if (!class_exists('Azure_Calendar_Mapping_Manager')) {
            Azure_Logger::error('Calendar Sync Engine: Mapping manager not available', 'Calendar');
            return array(
                'success'             => false,
                'total_calendars'     => 0,
                'total_events_synced' => 0,
                'total_errors'        => 1,
                'calendar_results'    => array(),
                'message'             => 'Mapping manager not available',
            );
        }

        $manager  = new Azure_Calendar_Mapping_Manager();
        $mappings = $manager->get_enabled_calendars();

        if (empty($mappings)) {
            Azure_Logger::info('Calendar Sync Engine: No enabled calendars to sync', 'Calendar');
            return array(
                'success'             => true,
                'total_calendars'     => 0,
                'total_events_synced' => 0,
                'total_errors'        => 0,
                'calendar_results'    => array(),
            );
        }

        if (!$start_date) {
            $start_date = gmdate('Y-m-d\TH:i:s\Z', strtotime('-30 days'));
        }
        if (!$end_date) {
            $end_date = gmdate('Y-m-d\TH:i:s\Z', strtotime('+365 days'));
        }

        Azure_Logger::info('Calendar Sync Engine: starting multi-calendar sync for ' . count($mappings) . ' calendars', 'Calendar');

        $overall = array(
            'success'              => true,
            'total_calendars'      => count($mappings),
            'total_events_synced'  => 0,
            'total_events_deleted' => 0,
            'total_errors'         => 0,
            'calendar_results'     => array(),
        );

        foreach ($mappings as $mapping) {
            $result = $this->sync_single_calendar(
                $mapping->outlook_calendar_id,
                $mapping->category_name,
                $start_date,
                $end_date,
                $user_email,
                $mailbox_email
            );

            $overall['calendar_results'][$mapping->outlook_calendar_id] = $result;
            $overall['total_events_synced']  += (int) ($result['events_synced']  ?? 0);
            $overall['total_events_deleted'] += (int) ($result['events_deleted'] ?? 0);
            $overall['total_errors']         += (int) ($result['errors']         ?? 0);

            if (empty($result['success'])) {
                $overall['success'] = false;
            }

            $manager->update_last_sync($mapping->outlook_calendar_id);
        }

        Azure_Logger::info(
            "Calendar Sync Engine: multi-calendar sync complete. Events synced: {$overall['total_events_synced']}, deleted: {$overall['total_events_deleted']}, errors: {$overall['total_errors']}",
            'Calendar'
        );

        return $overall;
    }

    /**
     * Sync a single Outlook calendar into pta_event posts.
     *
     * @param string      $calendar_id        Outlook calendar ID.
     * @param string      $category_name      pta_event_category name to assign.
     * @param string|null $start_date         ISO 8601 (Z) range start.
     * @param string|null $end_date           ISO 8601 (Z) range end.
     * @param string|null $user_email         Authenticated M365 user email.
     * @param string|null $mailbox_email      Shared mailbox to read from.
     * @return array { success, calendar_id, events_synced, events_deleted, errors, error_message? }
     */
    public function sync_single_calendar($calendar_id, $category_name, $start_date = null, $end_date = null, $user_email = null, $mailbox_email = null) {
        if (!$this->graph_api) {
            return array(
                'success'        => false,
                'calendar_id'    => $calendar_id,
                'events_synced'  => 0,
                'events_deleted' => 0,
                'errors'         => 1,
                'error_message'  => 'Graph API client unavailable',
            );
        }

        if (!$start_date) {
            $start_date = gmdate('Y-m-d\TH:i:s\Z', strtotime('-30 days'));
        }
        if (!$end_date) {
            $end_date = gmdate('Y-m-d\TH:i:s\Z', strtotime('+365 days'));
        }

        Azure_Logger::info(
            "Calendar Sync Engine: fetching events for {$calendar_id} ({$start_date} -> {$end_date}) as {$user_email} / mailbox {$mailbox_email}",
            'Calendar'
        );

        try {
            $events = $this->graph_api->get_calendar_events(
                $calendar_id,
                $start_date,
                $end_date,
                500,
                true,
                $user_email,
                $mailbox_email
            );
        } catch (\Throwable $e) {
            Azure_Logger::error('Calendar Sync Engine: Graph fetch threw - ' . $e->getMessage(), 'Calendar');
            return array(
                'success'        => false,
                'calendar_id'    => $calendar_id,
                'events_synced'  => 0,
                'events_deleted' => 0,
                'errors'         => 1,
                'error_message'  => $e->getMessage(),
            );
        }

        // Normalize to array even if Graph returned null / empty so the
        // prune step still runs — if Outlook now has zero events for
        // this calendar in this window, every local pta_event that
        // pointed at it is an orphan and should be trashed.
        if (!is_array($events)) {
            $events = array();
        }

        $synced       = 0;
        $errors       = 0;
        $seen_ids     = array();

        foreach ($events as $event) {
            try {
                $result = $this->upsert_event($event, $calendar_id, $category_name);
                if ($result) {
                    $synced++;
                    if (!empty($event['id'])) {
                        $seen_ids[(string) $event['id']] = true;
                    }
                } else {
                    $errors++;
                }
            } catch (\Throwable $e) {
                $event_id = isset($event['id']) ? $event['id'] : '(no id)';
                Azure_Logger::error("Calendar Sync Engine: exception syncing event {$event_id} - " . $e->getMessage(), 'Calendar');
                $errors++;
            }
        }

        // Prune pta_event posts whose Outlook source has been deleted.
        // Scoped to (a) this calendar and (b) the same date window we
        // just asked Graph about, so absence-from-response is a
        // genuine deletion signal and not a query-window artefact.
        $deleted = $this->prune_deleted_events($calendar_id, $seen_ids, $start_date, $end_date);

        Azure_Logger::info(
            "Calendar Sync Engine: {$calendar_id} done. synced={$synced}, deleted={$deleted}, errors={$errors}",
            'Calendar'
        );

        return array(
            'success'        => true,
            'calendar_id'    => $calendar_id,
            'events_synced'  => $synced,
            'events_deleted' => $deleted,
            'errors'         => $errors,
        );
    }

    /**
     * Trash every pta_event whose `_outlook_calendar_id` matches and
     * whose `_outlook_event_id` is NOT in the seen set, provided its
     * `_EventStartDate` falls within the supplied sync window.
     *
     * Why the window filter: Graph was only asked about events in
     * [$start, $end]. An older or further-future pta_event is missing
     * from the response for a benign reason (we didn't ask), not
     * because it was deleted at source. Restricting the prune to the
     * same window avoids false-positive deletions.
     *
     * Why trash (not force-delete): the prune fires on every sync run
     * and a malformed Graph response (rate-limit, partial result) could
     * temporarily return zero events. Trash is reversible from the WP
     * admin Events list within the standard 30-day retention.
     *
     * Locally-authored events (no `_outlook_event_id` meta or empty
     * string) are never touched.
     *
     * @param string $calendar_id    Outlook calendar id (matches `_outlook_calendar_id`).
     * @param array  $seen_ids       map of outlook_event_id => true that came back from Graph.
     * @param string $start_date_iso ISO 8601 window start (UTC, e.g. `2026-05-01T00:00:00Z`).
     * @param string $end_date_iso   ISO 8601 window end.
     * @return int Count of pta_event posts trashed.
     */
    private function prune_deleted_events($calendar_id, array $seen_ids, $start_date_iso, $end_date_iso) {
        global $wpdb;

        // Convert ISO-Z to WP-local 'Y-m-d H:i:s' to compare against
        // `_EventStartDate` (which is WP-local in TEC's schema, see
        // upsert_event() above).
        $wp_tz       = (string) (get_option('timezone_string') ?: 'UTC');
        $window_start = $this->iso_to_wp_local($start_date_iso, $wp_tz);
        $window_end   = $this->iso_to_wp_local($end_date_iso,   $wp_tz);
        if ($window_start === false || $window_end === false) {
            return 0;
        }

        // Candidate posts: same calendar, start date inside the window,
        // not already trashed. Limit defensively so a pathological
        // result set can't run away.
        $candidates = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID,
                    cal.meta_value  AS outlook_calendar_id,
                    oeid.meta_value AS outlook_event_id,
                    start.meta_value AS start_date
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} cal
                 ON p.ID = cal.post_id AND cal.meta_key = '_outlook_calendar_id'
             INNER JOIN {$wpdb->postmeta} oeid
                 ON p.ID = oeid.post_id AND oeid.meta_key = '_outlook_event_id'
             INNER JOIN {$wpdb->postmeta} start
                 ON p.ID = start.post_id AND start.meta_key = '_EventStartDate'
             WHERE p.post_type   = 'pta_event'
               AND p.post_status IN ('publish','future','draft','private')
               AND cal.meta_value = %s
               AND oeid.meta_value <> ''
               AND start.meta_value >= %s
               AND start.meta_value <= %s
             LIMIT 2000",
            (string) $calendar_id,
            $window_start,
            $window_end
        ));

        if (empty($candidates)) {
            return 0;
        }

        $deleted = 0;
        foreach ($candidates as $row) {
            $outlook_id = (string) $row->outlook_event_id;
            if ($outlook_id === '' || isset($seen_ids[$outlook_id])) {
                // Still present in Outlook — leave alone.
                continue;
            }

            $post_id = (int) $row->ID;
            $result  = wp_trash_post($post_id);
            if ($result) {
                $deleted++;
                Azure_Logger::info(
                    "Calendar Sync Engine: trashed orphan pta_event #{$post_id} (outlook_event_id={$outlook_id})",
                    'Calendar'
                );
            } else {
                Azure_Logger::warning(
                    "Calendar Sync Engine: failed to trash orphan pta_event #{$post_id} (outlook_event_id={$outlook_id})",
                    'Calendar'
                );
            }
        }

        return $deleted;
    }

    /**
     * Convert an ISO 8601 (Z) string used for Graph windows into the
     * WP-local datetime form used by `_EventStartDate`.
     *
     * @return string|false
     */
    private function iso_to_wp_local($iso, $wp_timezone) {
        if (!is_string($iso) || $iso === '') return false;
        try {
            $dt = new DateTime($iso);
            $dt->setTimezone(new DateTimeZone($wp_timezone ?: 'UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Upsert one processed Graph event (from get_calendar_events) into
     * a pta_event post. Outlook is always the source of truth: any
     * existing pta_event found by `_outlook_event_id` is overwritten.
     *
     * @param array  $event         Processed event ({id,title,start,end,allDay,location,description,categories}).
     * @param string $calendar_id   Outlook calendar ID (stored in _outlook_calendar_id).
     * @param string $category_name pta_event_category term to assign.
     * @return int|false post ID on success, false on failure.
     */
    private function upsert_event(array $event, $calendar_id, $category_name) {
        if (empty($event['id'])) {
            return false;
        }

        $outlook_event_id = (string) $event['id'];
        $existing_id      = $this->find_pta_event_by_outlook_id($outlook_event_id);

        // Resolve TZ + dates in WP-local form
        $wp_timezone = (string) (get_option('timezone_string') ?: 'UTC');
        $start_local = $this->to_wp_local_datetime($event['start'] ?? '', $wp_timezone);
        $end_local   = $this->to_wp_local_datetime($event['end'] ?? '',   $wp_timezone);

        if (!$start_local || !$end_local) {
            Azure_Logger::warning("Calendar Sync Engine: bad date for event {$outlook_event_id}, skipping", 'Calendar');
            return false;
        }

        $title       = isset($event['title']) ? (string) $event['title'] : '';
        $description = isset($event['description']) ? (string) $event['description'] : '';
        $all_day     = !empty($event['allDay']);
        $venue       = isset($event['location']) ? (string) $event['location'] : '';

        $post_data = array(
            'post_type'    => 'pta_event',
            'post_status'  => 'publish',
            'post_title'   => $title !== '' ? $title : '(Untitled event)',
            'post_content' => $description,
        );

        if ($existing_id) {
            $post_data['ID'] = $existing_id;
            $post_id = wp_update_post($post_data, true);
        } else {
            $post_id = wp_insert_post($post_data, true);
        }

        if (is_wp_error($post_id) || !$post_id) {
            $msg = is_wp_error($post_id) ? $post_id->get_error_message() : 'unknown error';
            Azure_Logger::error("Calendar Sync Engine: wp_insert/update_post failed for {$outlook_event_id}: {$msg}", 'Calendar');
            return false;
        }

        $post_id = (int) $post_id;

        // Core event meta (matches TEC's stored schema; pta_event reuses these names)
        update_post_meta($post_id, '_EventStartDate', $start_local);
        update_post_meta($post_id, '_EventEndDate',   $end_local);
        update_post_meta($post_id, '_EventStartDateUTC', $this->to_utc_datetime($start_local, $wp_timezone));
        update_post_meta($post_id, '_EventEndDateUTC',   $this->to_utc_datetime($end_local,   $wp_timezone));
        update_post_meta($post_id, '_EventAllDay', $all_day ? 'yes' : 'no');
        update_post_meta($post_id, '_EventTimezone', $wp_timezone);
        update_post_meta($post_id, '_EventTimezoneAbbr', $this->get_timezone_abbr($wp_timezone));

        $duration = strtotime($end_local) - strtotime($start_local);
        if ($duration > 0) {
            update_post_meta($post_id, '_EventDuration', $duration);
        }

        if ($venue !== '') {
            update_post_meta($post_id, '_EventVenue', $venue);
        }

        // Sync bookkeeping
        update_post_meta($post_id, '_outlook_event_id',    $outlook_event_id);
        update_post_meta($post_id, '_outlook_calendar_id', (string) $calendar_id);
        update_post_meta($post_id, '_outlook_last_sync',   current_time('mysql'));
        update_post_meta($post_id, '_outlook_sync_status', 'synced');
        update_post_meta($post_id, '_sync_direction',      'from_outlook');

        // Category: write into pta_event_category (replace, don't append)
        if ($category_name !== '') {
            update_post_meta($post_id, '_pta_event_category_name', $category_name);
            if (taxonomy_exists('pta_event_category')) {
                wp_set_object_terms($post_id, array($category_name), 'pta_event_category', false);
            }
        }

        return $post_id;
    }

    /**
     * Look up an existing pta_event by its stored `_outlook_event_id`.
     *
     * @param string $outlook_event_id
     * @return int|false
     */
    private function find_pta_event_by_outlook_id($outlook_event_id) {
        global $wpdb;

        if ($outlook_event_id === '') {
            return false;
        }

        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE pm.meta_key = '_outlook_event_id'
               AND pm.meta_value = %s
               AND p.post_type = 'pta_event'
             LIMIT 1",
            $outlook_event_id
        ));

        return $post_id ? (int) $post_id : false;
    }

    /**
     * Convert a Graph-processed datetime (ISO 8601 with offset, e.g.
     * 2026-06-01T09:00:00-07:00) to the WP-local 'Y-m-d H:i:s' form
     * that TEC's `_EventStartDate` expects.
     *
     * @param string $iso          Source ISO 8601 string.
     * @param string $wp_timezone  Target IANA timezone (WP setting).
     * @return string|false
     */
    private function to_wp_local_datetime($iso, $wp_timezone) {
        if (!is_string($iso) || $iso === '') {
            return false;
        }
        try {
            $dt = new DateTime($iso);
            $dt->setTimezone(new DateTimeZone($wp_timezone ?: 'UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Convert a WP-local 'Y-m-d H:i:s' string back to UTC for the
     * `_EventStartDateUTC` / `_EventEndDateUTC` meta.
     *
     * @param string $local        WP-local datetime.
     * @param string $wp_timezone  Source IANA timezone.
     * @return string
     */
    private function to_utc_datetime($local, $wp_timezone) {
        try {
            $dt = new DateTime($local, new DateTimeZone($wp_timezone ?: 'UTC'));
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return $local;
        }
    }

    /**
     * Resolve the short tz abbreviation (`PST`, `EDT`, …) for a tz string.
     *
     * @param string $tz_string
     * @return string
     */
    private function get_timezone_abbr($tz_string) {
        try {
            $tz = new DateTimeZone($tz_string ?: 'UTC');
            $dt = new DateTime('now', $tz);
            return $dt->format('T');
        } catch (\Throwable $e) {
            return 'UTC';
        }
    }

    /**
     * Repair missing UTC/duration/timezone meta on existing pta_event
     * posts that came from a pre-v3.97 sync. Ported from the deleted
     * TEC integration ajax.
     *
     * @return array { repaired, errors, message }
     */
    public function repair_event_metadata() {
        global $wpdb;

        $events = $wpdb->get_results(
            "SELECT p.ID,
                    pm_start.meta_value AS start_date,
                    pm_end.meta_value   AS end_date
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_outlook
                 ON p.ID = pm_outlook.post_id AND pm_outlook.meta_key = '_outlook_event_id'
             LEFT JOIN {$wpdb->postmeta} pm_start
                 ON p.ID = pm_start.post_id AND pm_start.meta_key = '_EventStartDate'
             LEFT JOIN {$wpdb->postmeta} pm_end
                 ON p.ID = pm_end.post_id AND pm_end.meta_key = '_EventEndDate'
             WHERE p.post_type = 'pta_event'
               AND p.post_status = 'publish'"
        );

        if (empty($events)) {
            return array('repaired' => 0, 'errors' => 0, 'message' => 'No synced events found to repair.');
        }

        $wp_timezone = (string) (get_option('timezone_string') ?: 'UTC');
        $repaired = 0;
        $errors   = 0;

        foreach ($events as $event) {
            try {
                if (empty($event->start_date) || empty($event->end_date)) {
                    $errors++;
                    continue;
                }

                $event_id = (int) $event->ID;
                update_post_meta($event_id, '_EventStartDateUTC', $this->to_utc_datetime($event->start_date, $wp_timezone));
                update_post_meta($event_id, '_EventEndDateUTC',   $this->to_utc_datetime($event->end_date,   $wp_timezone));
                update_post_meta($event_id, '_EventTimezone',     $wp_timezone);
                update_post_meta($event_id, '_EventTimezoneAbbr', $this->get_timezone_abbr($wp_timezone));

                $duration = strtotime($event->end_date) - strtotime($event->start_date);
                update_post_meta($event_id, '_EventDuration', max(0, $duration));

                $repaired++;
            } catch (\Throwable $e) {
                Azure_Logger::error("Calendar Sync Engine: repair failed for {$event->ID} - " . $e->getMessage(), 'Calendar');
                $errors++;
            }
        }

        return array(
            'repaired' => $repaired,
            'errors'   => $errors,
            'message'  => "Repaired metadata for {$repaired} events.",
        );
    }
}
