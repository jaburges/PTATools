<?php
/**
 * Calendar Sync AJAX Controller
 *
 * Handles all wp_ajax_* endpoints used by the Calendar Sync admin tab:
 *
 *   azure_get_outlook_calendars_for_sync   list calendars in mailbox
 *   azure_get_pta_event_categories         list pta_event_category terms
 *   azure_create_pta_event_category        create new category term
 *
 *   azure_get_calendar_mapping             load mapping row by id
 *   azure_save_calendar_mapping            create or update mapping row
 *   azure_delete_calendar_mapping          remove mapping row
 *   azure_toggle_calendar_sync             flip sync_enabled column
 *
 *   azure_save_calendar_sync_schedule      legacy global-schedule save
 *   azure_calendar_manual_sync             trigger immediate sync of all enabled
 *   azure_get_calendar_sync_history        recent activity log rows
 *   azure_calendar_repair_event_metadata   repair UTC/timezone meta
 *
 * Adapted from the v3.97-retired `Azure_TEC_Integration_Ajax` but
 * targets `pta_event_category` and the rebuilt sync engine/mapping
 * manager.
 *
 * @package AzurePlugin
 * @since   3.113
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Calendar_Sync_Ajax {

    public function __construct() {
        // Discovery
        add_action('wp_ajax_azure_get_outlook_calendars_for_sync', array($this, 'ajax_get_outlook_calendars'));
        add_action('wp_ajax_azure_get_pta_event_categories',       array($this, 'ajax_get_categories'));
        add_action('wp_ajax_azure_create_pta_event_category',      array($this, 'ajax_create_category'));

        // Mapping CRUD
        add_action('wp_ajax_azure_get_calendar_mapping',    array($this, 'ajax_get_calendar_mapping'));
        add_action('wp_ajax_azure_save_calendar_mapping',   array($this, 'ajax_save_calendar_mapping'));
        add_action('wp_ajax_azure_delete_calendar_mapping', array($this, 'ajax_delete_calendar_mapping'));
        add_action('wp_ajax_azure_toggle_calendar_sync',    array($this, 'ajax_toggle_calendar_sync'));

        // Sync run + history
        add_action('wp_ajax_azure_save_calendar_sync_schedule',    array($this, 'ajax_save_sync_schedule'));
        add_action('wp_ajax_azure_calendar_manual_sync',           array($this, 'ajax_manual_sync'));
        add_action('wp_ajax_azure_get_calendar_sync_history',      array($this, 'ajax_get_sync_history'));
        add_action('wp_ajax_azure_calendar_repair_event_metadata', array($this, 'ajax_repair_event_metadata'));
    }

    // ---------------------------------------------------------------
    // Guard helper
    // ---------------------------------------------------------------

    /**
     * Standard nonce + capability check. Returns true if request can
     * proceed, false (and sends the JSON error itself) otherwise.
     */
    private function guard() {
        if (!check_ajax_referer('azure_plugin_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return false;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return false;
        }
        return true;
    }

    // ---------------------------------------------------------------
    // Discovery
    // ---------------------------------------------------------------

    public function ajax_get_outlook_calendars() {
        if (!$this->guard()) return;

        $settings      = Azure_Settings::get_all_settings();
        $user_email    = $settings['calendar_embed_user_email'] ?? '';
        $mailbox_email = $settings['calendar_embed_mailbox_email'] ?? '';

        if (empty($user_email)) {
            wp_send_json_error('M365 user email not configured on the Config page.');
            return;
        }
        if (empty($mailbox_email)) {
            wp_send_json_error('Shared mailbox email not configured on the Config page.');
            return;
        }
        if (!class_exists('Azure_Calendar_GraphAPI')) {
            wp_send_json_error('Calendar Graph API class not available');
            return;
        }

        try {
            $graph_api = new Azure_Calendar_GraphAPI();
            $calendars = $graph_api->get_mailbox_calendars($user_email, $mailbox_email, true);
            if (!is_array($calendars)) {
                $calendars = array();
            }
            wp_send_json_success($calendars);
        } catch (\Throwable $e) {
            Azure_Logger::error('Calendar Sync AJAX: get_mailbox_calendars failed - ' . $e->getMessage(), 'Calendar');
            wp_send_json_error('Failed to fetch calendars: ' . $e->getMessage());
        }
    }

    public function ajax_get_categories() {
        if (!$this->guard()) return;

        $taxonomy = 'pta_event_category';
        if (!taxonomy_exists($taxonomy)) {
            wp_send_json_error('The pta_event_category taxonomy is not registered. Enable the Calendar module first.');
            return;
        }

        $terms = get_terms(array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ));

        if (is_wp_error($terms)) {
            wp_send_json_error('Failed to retrieve categories: ' . $terms->get_error_message());
            return;
        }

        $payload = array();
        foreach ($terms as $term) {
            $payload[] = array(
                'term_id' => (int) $term->term_id,
                'name'    => $term->name,
                'slug'    => $term->slug,
                'count'   => (int) $term->count,
            );
        }
        wp_send_json_success($payload);
    }

    public function ajax_create_category() {
        if (!$this->guard()) return;

        $taxonomy      = 'pta_event_category';
        $category_name = sanitize_text_field($_POST['category_name'] ?? '');

        if ($category_name === '') {
            wp_send_json_error('Category name is required');
            return;
        }
        if (!taxonomy_exists($taxonomy)) {
            wp_send_json_error('The pta_event_category taxonomy is not registered.');
            return;
        }

        $existing = term_exists($category_name, $taxonomy);
        if ($existing) {
            wp_send_json_success(array(
                'term_id' => is_array($existing) ? (int) $existing['term_id'] : (int) $existing,
                'name'    => $category_name,
                'existed' => true,
            ));
            return;
        }

        $result = wp_insert_term($category_name, $taxonomy);
        if (is_wp_error($result)) {
            wp_send_json_error('Failed to create category: ' . $result->get_error_message());
            return;
        }

        wp_send_json_success(array(
            'term_id' => (int) $result['term_id'],
            'name'    => $category_name,
            'existed' => false,
        ));
    }

    // ---------------------------------------------------------------
    // Mapping CRUD
    // ---------------------------------------------------------------

    public function ajax_get_calendar_mapping() {
        if (!$this->guard()) return;

        $mapping_id = (int) ($_POST['mapping_id'] ?? 0);
        if (!$mapping_id) {
            wp_send_json_error('Invalid mapping ID');
            return;
        }
        if (!class_exists('Azure_Calendar_Mapping_Manager')) {
            wp_send_json_error('Mapping manager not available');
            return;
        }

        $manager = new Azure_Calendar_Mapping_Manager();
        $mapping = $manager->get_mapping_by_id($mapping_id);
        if (!$mapping) {
            wp_send_json_error('Mapping not found');
            return;
        }

        wp_send_json_success((array) $mapping);
    }

    public function ajax_save_calendar_mapping() {
        if (!$this->guard()) return;
        if (!class_exists('Azure_Calendar_Mapping_Manager')) {
            wp_send_json_error('Mapping manager not available');
            return;
        }

        $mapping_id              = (int) ($_POST['mapping_id'] ?? 0);
        $outlook_calendar_id     = sanitize_text_field($_POST['outlook_calendar_id'] ?? '');
        $outlook_calendar_name   = sanitize_text_field($_POST['outlook_calendar_name'] ?? '');
        $category_id             = (int) ($_POST['category_id'] ?? 0);
        $category_name           = sanitize_text_field($_POST['category_name'] ?? '');
        $sync_enabled            = (int) ($_POST['sync_enabled'] ?? 1);
        $schedule_enabled        = (int) ($_POST['schedule_enabled'] ?? 0);
        $schedule_frequency      = sanitize_text_field($_POST['schedule_frequency'] ?? 'hourly');
        $schedule_lookback_days  = (int) ($_POST['schedule_lookback_days'] ?? 30);
        $schedule_lookahead_days = (int) ($_POST['schedule_lookahead_days'] ?? 365);

        if ($outlook_calendar_id === '' || $outlook_calendar_name === '' || $category_name === '') {
            wp_send_json_error('Missing required fields (calendar + category required).');
            return;
        }

        $manager = new Azure_Calendar_Mapping_Manager();

        // If no category_id, auto-create the term so the user can type
        // a brand new category right in the modal without having to
        // create it separately first.
        if (!$category_id) {
            $category_id = (int) $manager->ensure_category_exists($category_name);
            if (!$category_id) {
                wp_send_json_error('Could not create or resolve category.');
                return;
            }
        }

        if ($mapping_id) {
            $ok = $manager->update_mapping(
                $mapping_id,
                $outlook_calendar_id,
                $outlook_calendar_name,
                $category_id,
                $category_name,
                $sync_enabled,
                $schedule_enabled,
                $schedule_frequency,
                $schedule_lookback_days,
                $schedule_lookahead_days
            );
            if ($ok) {
                wp_send_json_success(array('mapping_id' => $mapping_id, 'action' => 'updated'));
            } else {
                global $wpdb;
                wp_send_json_error($wpdb->last_error ?: 'Failed to update mapping');
            }
            return;
        }

        $new_id = $manager->create_mapping(
            $outlook_calendar_id,
            $outlook_calendar_name,
            $category_id,
            $category_name,
            $sync_enabled,
            $schedule_enabled,
            $schedule_frequency,
            $schedule_lookback_days,
            $schedule_lookahead_days
        );
        if ($new_id) {
            wp_send_json_success(array('mapping_id' => $new_id, 'action' => 'created'));
        } else {
            global $wpdb;
            wp_send_json_error($wpdb->last_error ?: 'Failed to create mapping');
        }
    }

    public function ajax_delete_calendar_mapping() {
        if (!$this->guard()) return;

        $mapping_id = (int) ($_POST['mapping_id'] ?? 0);
        if (!$mapping_id) {
            wp_send_json_error('Invalid mapping ID');
            return;
        }
        if (!class_exists('Azure_Calendar_Mapping_Manager')) {
            wp_send_json_error('Mapping manager not available');
            return;
        }

        $manager = new Azure_Calendar_Mapping_Manager();
        if ($manager->delete_mapping($mapping_id)) {
            wp_send_json_success(array('mapping_id' => $mapping_id));
        } else {
            wp_send_json_error('Failed to delete mapping');
        }
    }

    public function ajax_toggle_calendar_sync() {
        if (!$this->guard()) return;

        $mapping_id = (int) ($_POST['mapping_id'] ?? 0);
        $enabled    = isset($_POST['enabled']) && $_POST['enabled'] === 'true' ? 1 : 0;

        if (!$mapping_id) {
            wp_send_json_error('Invalid mapping ID');
            return;
        }
        if (!class_exists('Azure_Calendar_Mapping_Manager')) {
            wp_send_json_error('Mapping manager not available');
            return;
        }

        $manager = new Azure_Calendar_Mapping_Manager();
        if ($manager->set_sync_enabled($mapping_id, (bool) $enabled)) {
            wp_send_json_success(array('mapping_id' => $mapping_id, 'enabled' => $enabled));
        } else {
            wp_send_json_error('Failed to update sync status');
        }
    }

    // ---------------------------------------------------------------
    // Sync + history
    // ---------------------------------------------------------------

    public function ajax_save_sync_schedule() {
        if (!$this->guard()) return;

        $sync_enabled   = isset($_POST['sync_enabled']) && $_POST['sync_enabled'] === 'true';
        $frequency      = sanitize_text_field($_POST['frequency'] ?? 'hourly');
        $lookback_days  = (int) ($_POST['lookback_days'] ?? 30);
        $lookahead_days = (int) ($_POST['lookahead_days'] ?? 365);

        Azure_Settings::update_settings(array(
            'calendar_sync_schedule_enabled' => $sync_enabled,
            'calendar_sync_default_frequency' => $frequency,
            'calendar_sync_lookback_days'    => $lookback_days,
            'calendar_sync_lookahead_days'   => $lookahead_days,
        ));

        // Reconcile the cron entry immediately so the user doesn't
        // wait for the next ensure_events_scheduled pass.
        if (class_exists('Azure_PTA_Cron')) {
            Azure_PTA_Cron::ensure_events_scheduled();
        }

        wp_send_json_success(array(
            'sync_enabled'   => $sync_enabled,
            'frequency'      => $frequency,
            'lookback_days'  => $lookback_days,
            'lookahead_days' => $lookahead_days,
        ));
    }

    public function ajax_manual_sync() {
        if (!$this->guard()) return;

        if (!class_exists('Azure_Calendar_Sync_Engine')) {
            wp_send_json_error('Sync engine not available');
            return;
        }

        $settings      = Azure_Settings::get_all_settings();
        $user_email    = $settings['calendar_embed_user_email'] ?? '';
        $mailbox_email = $settings['calendar_embed_mailbox_email'] ?? '';

        if (empty($user_email) || empty($mailbox_email)) {
            wp_send_json_error('Calendar mailbox is not configured on the Config page.');
            return;
        }

        $lookback_days  = (int) ($settings['calendar_sync_lookback_days']  ?? 30);
        $lookahead_days = (int) ($settings['calendar_sync_lookahead_days'] ?? 365);
        $start          = gmdate('Y-m-d\TH:i:s\Z', strtotime("-{$lookback_days} days"));
        $end            = gmdate('Y-m-d\TH:i:s\Z', strtotime("+{$lookahead_days} days"));

        try {
            $engine  = new Azure_Calendar_Sync_Engine();
            $results = $engine->sync_all_enabled_calendars($start, $end, $user_email, $mailbox_email);
        } catch (\Throwable $e) {
            Azure_Logger::error('Calendar Sync AJAX: manual sync threw - ' . $e->getMessage(), 'Calendar');
            wp_send_json_error('Sync error: ' . $e->getMessage());
            return;
        }

        if (class_exists('Azure_Database')) {
            Azure_Database::log_activity(
                'calendar',
                'manual_sync_completed',
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

        if (!empty($results['success'])) {
            wp_send_json_success(array(
                'calendars_synced'     => $results['total_calendars']      ?? 0,
                'total_events_synced'  => $results['total_events_synced']  ?? 0,
                'total_events_deleted' => $results['total_events_deleted'] ?? 0,
                'total_errors'         => $results['total_errors']         ?? 0,
                'calendar_results'     => $results['calendar_results']     ?? array(),
            ));
        } else {
            wp_send_json_error($results['message'] ?? 'Sync failed');
        }
    }

    public function ajax_get_sync_history() {
        if (!$this->guard()) return;

        global $wpdb;
        $table = class_exists('Azure_Database') ? Azure_Database::get_table_name('activity_log') : '';
        if (!$table) {
            wp_send_json_success(array());
            return;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, module, action, details, status, created_at
                   FROM {$table}
                  WHERE LOWER(module) = %s
                    AND (action LIKE %s OR action LIKE %s)
                  ORDER BY created_at DESC
                  LIMIT 50",
                'calendar',
                '%sync%',
                '%scheduled%'
            ),
            ARRAY_A
        );

        $formatted = array();
        foreach ((array) $rows as $row) {
            $details      = !empty($row['details']) ? json_decode($row['details'], true) : null;
            $type         = (strpos($row['action'], 'scheduled') !== false) ? 'Scheduled' : 'Manual';
            $calendars    = '—';
            $events_count = 0;
            $message      = '';
            $status       = $row['status'] === 'success' ? 'success' : 'failed';

            if (is_array($details)) {
                if (isset($details['events_synced'])) {
                    $events_count = (int) $details['events_synced'];
                }
                if (isset($details['calendars'])) {
                    $calendars = is_numeric($details['calendars'])
                        ? ((int) $details['calendars']) . ' calendar(s)'
                        : (string) $details['calendars'];
                }
                if (isset($details['calendar_name'])) {
                    $calendars = (string) $details['calendar_name'];
                }
                if (isset($details['message'])) {
                    $message = (string) $details['message'];
                }
            }

            $formatted[] = array(
                'timestamp'    => date('M j, Y g:i A', strtotime($row['created_at'])),
                'type'         => $type,
                'calendars'    => $calendars,
                'events_count' => $events_count,
                'status'       => $status,
                'message'      => $message,
            );
        }

        wp_send_json_success($formatted);
    }

    public function ajax_repair_event_metadata() {
        if (!$this->guard()) return;

        if (!class_exists('Azure_Calendar_Sync_Engine')) {
            wp_send_json_error('Sync engine not available');
            return;
        }

        try {
            $engine = new Azure_Calendar_Sync_Engine();
            $result = $engine->repair_event_metadata();
        } catch (\Throwable $e) {
            Azure_Logger::error('Calendar Sync AJAX: repair threw - ' . $e->getMessage(), 'Calendar');
            wp_send_json_error('Repair failed: ' . $e->getMessage());
            return;
        }

        wp_send_json_success($result);
    }
}
