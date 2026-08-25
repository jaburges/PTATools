<?php
/**
 * OneDrive Media Manager - Main Orchestration Class
 * Manages WordPress Media Library integration with OneDrive/SharePoint
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_OneDrive_Media_Manager {
    
    private static $instance = null;
    private $auth;
    private $graph_api;
    private $enabled;

    /**
     * Set while importing from OneDrive so the `add_attachment` backup hook does
     * not immediately queue the imported file for upload back to its source.
     */
    private $suppress_backup_queue = false;
    
    public static function get_instance() {
        if (self::$instance == null) {
            self::$instance = new Azure_OneDrive_Media_Manager();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Check if module is enabled
        $this->enabled = Azure_Settings::is_module_enabled('onedrive_media');
        
        if (!$this->enabled) {
            return;
        }
        
        // Initialize dependencies
        if (class_exists('Azure_OneDrive_Media_Auth')) {
            $this->auth = new Azure_OneDrive_Media_Auth();
        }
        
        if (class_exists('Azure_OneDrive_Media_GraphAPI')) {
            $this->graph_api = new Azure_OneDrive_Media_GraphAPI();
        }
        
        // Hook into WordPress media upload.
        add_filter('wp_handle_upload_prefilter', array($this, 'intercept_upload'), 10, 1);
        // The Graph upload is NOT performed in the upload request. A 4GB ceiling
        // against a remote API cannot be held open inside wp-admin without
        // timing out the editor, and an inline failure had nowhere to be retried.
        // The attachment is queued instead and drained by the backup worker.
        add_action('add_attachment', array($this, 'queue_attachment_backup'), 10, 1);
        add_action('delete_attachment', array($this, 'handle_delete_attachment'), 10, 1);
        
        // Add custom fields to attachment
        add_filter('attachment_fields_to_edit', array($this, 'add_onedrive_fields'), 10, 2);
        // Never override local URLs — files live on disk at /wp-content/uploads/YYYY/MM/.
        // SharePoint/OneDrive links are stored in the DB for sync management only.
        
        // Register AJAX handlers
        add_action('wp_ajax_onedrive_media_sync_from_onedrive', array($this, 'ajax_sync_from_onedrive'));
        add_action('wp_ajax_onedrive_media_browse_folders', array($this, 'ajax_browse_folders'));
        add_action('wp_ajax_onedrive_media_create_folder', array($this, 'ajax_create_folder'));
        add_action('wp_ajax_onedrive_media_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_onedrive_media_list_sharepoint_sites', array($this, 'ajax_list_sharepoint_sites'));
        add_action('wp_ajax_onedrive_media_list_sharepoint_drives', array($this, 'ajax_list_sharepoint_drives'));
        add_action('wp_ajax_onedrive_media_resolve_sharepoint_site', array($this, 'ajax_resolve_sharepoint_site'));
        add_action('wp_ajax_onedrive_media_create_year_folders', array($this, 'ajax_create_year_folders'));
        add_action('wp_ajax_onedrive_media_import_from_onedrive', array($this, 'ajax_import_from_onedrive'));
        add_action('wp_ajax_onedrive_media_repair_diagnose', array($this, 'ajax_repair_diagnose'));
        add_action('wp_ajax_onedrive_media_backup_library', array($this, 'ajax_backup_library'));
        
        // Schedule WordPress Cron for auto-sync. wp_schedule_event() rejects a
        // recurrence with no cron_schedules entry and returns false, which
        // would leave auto-sync permanently off with nothing in the log to say
        // why — so fall back to hourly rather than skip the schedule.
        if (!wp_next_scheduled('onedrive_media_auto_sync')) {
            $frequency = (string) Azure_Settings::get_setting('onedrive_media_sync_frequency', 'hourly');
            if (!array_key_exists($frequency, wp_get_schedules())) {
                Azure_Logger::warning('OneDrive Media: Unknown sync frequency "' . $frequency . '", falling back to hourly');
                $frequency = 'hourly';
            }
            wp_schedule_event(time(), $frequency, 'onedrive_media_auto_sync');
        }
        add_action('onedrive_media_auto_sync', array($this, 'run_auto_sync'));

        // The backup queue drains on its own schedule so a backlog is not held
        // hostage by the auto-sync toggle, which governs the import direction.
        if (!wp_next_scheduled('onedrive_media_backup_queue')) {
            wp_schedule_event(time() + 300, 'hourly', 'onedrive_media_backup_queue');
        }
        add_action('onedrive_media_backup_queue', array($this, 'run_backup_queue'));

        add_action('wp_ajax_onedrive_media_repair_guids', array($this, 'ajax_repair_sharepoint_guids'));
    }
    
    /**
     * Intercept file upload before processing
     */
    public function intercept_upload($file) {
        // The media library is the primary copy, so a file too large for the
        // backup target is still a legitimate WordPress upload. This used to set
        // $file['error'], which rejected the upload outright; now it only warns,
        // and the backup worker records the file as unbackable.
        $max_size = (int) Azure_Settings::get_setting('onedrive_media_max_file_size', 4294967296);

        if ($max_size > 0 && isset($file['size']) && $file['size'] > $max_size) {
            Azure_Logger::warning(sprintf(
                'OneDrive Media: "%s" (%s) exceeds the OneDrive backup limit (%s); accepting the upload but it will not be backed up',
                $file['name'] ?? 'unknown',
                size_format((int) $file['size']),
                size_format($max_size)
            ));
        }

        return $file;
    }
    
    /**
     * AJAX: Repair attachments whose guid/URL is a SharePoint/OneDrive URL.
     */
    public function ajax_repair_sharepoint_guids() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT ID, guid FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND (guid LIKE '%sharepoint.com%' OR guid LIKE '%onedrive.com%' OR guid LIKE '%1drv.ms%')"
        );

        if (empty($rows)) {
            wp_send_json_success(array('fixed' => 0, 'total' => 0, 'message' => 'No attachments with SharePoint/OneDrive URLs found.'));
            return;
        }

        $upload_dir = wp_upload_dir();
        $baseurl = $upload_dir['baseurl'];
        $fixed = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $relative = get_post_meta($row->ID, '_wp_attached_file', true);

            if ($relative && preg_match('|^https?://|', $relative)) {
                $meta = wp_get_attachment_metadata($row->ID);
                if (!empty($meta['file'])) {
                    $relative = $meta['file'];
                } else {
                    $filename = basename(parse_url($relative, PHP_URL_PATH));
                    $relative = $filename ?: '';
                }
                if ($relative && !preg_match('|^https?://|', $relative)) {
                    update_post_meta($row->ID, '_wp_attached_file', $relative);
                }
            }

            if (!$relative || preg_match('|^https?://|', $relative)) {
                $skipped++;
                continue;
            }

            $correct_url = $baseurl . '/' . ltrim($relative, '/');
            if ($correct_url !== $row->guid) {
                $wpdb->update($wpdb->posts, array('guid' => $correct_url), array('ID' => $row->ID));
                $fixed++;
            }
        }

        $total = count($rows);
        if ($fixed > 0) {
            Azure_Logger::info("OneDrive Media: Repaired {$fixed} of {$total} attachment(s) with SharePoint URLs");
        }

        wp_send_json_success(array(
            'fixed'   => $fixed,
            'skipped' => $skipped,
            'total'   => $total,
            'message' => $fixed > 0
                ? "Repaired {$fixed} attachment URL(s)."
                : "All {$total} attachment(s) already have correct URLs.",
        ));
    }

    /**
     * Queue a newly added attachment for backup to OneDrive.
     *
     * Fires on `add_attachment`, which also runs for the sideloads performed by
     * the OneDrive importer. Backing those up would push a file straight back to
     * the folder it was just read from, so imports set $suppress_backup_queue
     * and anything that already carries a mapping row is skipped.
     */
    public function queue_attachment_backup($attachment_id) {
        if ($this->suppress_backup_queue) {
            return;
        }

        if (!$this->is_backup_enabled()) {
            return;
        }

        $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
        if (empty($attached_file) || $this->is_wp_thumbnail(basename($attached_file))) {
            return;
        }

        if ($this->get_mapping_for_attachment($attachment_id)) {
            return;
        }

        $this->enqueue_backup($attachment_id);
    }

    /**
     * Add an attachment to the backup queue, or reset an existing row to pending.
     *
     * Relies on the unique key over (operation, attachment_id) so re-running the
     * library backfill is idempotent rather than additive.
     */
    public function enqueue_backup($attachment_id) {
        global $wpdb;
        $table = Azure_Database::get_table_name('onedrive_sync_queue');
        if (!$table) {
            return false;
        }

        $attachment_id = (int) $attachment_id;
        $local_path = get_attached_file($attachment_id);

        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (operation, attachment_id, local_path, status, retry_count, error_message, next_attempt_at)
             VALUES ('backup', %d, %s, 'pending', 0, NULL, NULL)
             ON DUPLICATE KEY UPDATE
                local_path = VALUES(local_path),
                status = 'pending',
                retry_count = 0,
                error_message = NULL,
                next_attempt_at = NULL",
            $attachment_id,
            (string) $local_path
        ));

        return $result !== false;
    }

    /**
     * Queue every media library item that has no OneDrive backup yet.
     *
     * Driven strictly from the attachment table, so files sitting in uploads that
     * were never registered as attachments are left alone by design.
     *
     * @return array{queued:int,already:int,total:int}
     */
    public function backfill_library_queue() {
        global $wpdb;

        $files_table = Azure_Database::get_table_name('onedrive_files');
        $summary = array('queued' => 0, 'already' => 0, 'total' => 0);

        if (!$files_table) {
            return $summary;
        }

        $attachment_ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' ORDER BY ID ASC"
        );

        $summary['total'] = count($attachment_ids);

        if (empty($attachment_ids)) {
            return $summary;
        }

        $mapped = $wpdb->get_col(
            "SELECT attachment_id FROM {$files_table}
              WHERE attachment_id IS NOT NULL AND sync_status = 'synced'"
        );
        $mapped = array_flip(array_map('intval', $mapped));

        foreach ($attachment_ids as $attachment_id) {
            $attachment_id = (int) $attachment_id;

            if (isset($mapped[$attachment_id])) {
                $summary['already']++;
                continue;
            }

            if ($this->enqueue_backup($attachment_id)) {
                $summary['queued']++;
            }
        }

        Azure_Logger::info(sprintf(
            'OneDrive Media Backup: Backfill queued %d of %d library items (%d already backed up)',
            $summary['queued'],
            $summary['total'],
            $summary['already']
        ));

        return $summary;
    }

    /**
     * AJAX driver for the library backup panel.
     *
     * Split into scan/batch so the browser can walk a backlog without any single
     * request having to outlive the PHP time limit.
     */
    public function ajax_backup_library() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        if (!$this->graph_api) {
            wp_send_json_error('Graph API not initialized');
            return;
        }
        if (!$this->is_backup_enabled()) {
            wp_send_json_error('Sync direction is set to "OneDrive → WordPress only", so backups are disabled. Change it in Sync Settings.');
            return;
        }

        $mode = sanitize_text_field($_POST['mode'] ?? 'scan');

        if ($mode === 'scan') {
            $summary = $this->backfill_library_queue();
            $stats = $this->get_sync_stats();

            wp_send_json_success(array(
                'queued'  => $summary['queued'],
                'already' => $summary['already'],
                'total'   => $summary['total'],
                'pending' => (int) $stats['queued_files'],
                'stats'   => $stats,
            ));
            return;
        }

        if ($mode === 'batch') {
            @set_time_limit(150);
            $result = $this->process_backup_queue(10, 60);
            $result['stats'] = $this->get_sync_stats();

            wp_send_json_success($result);
            return;
        }

        if ($mode === 'retry_failed') {
            global $wpdb;
            $queue = Azure_Database::get_table_name('onedrive_sync_queue');
            $reset = 0;
            if ($queue) {
                $reset = (int) $wpdb->query(
                    "UPDATE {$queue}
                        SET status = 'pending', retry_count = 0, error_message = NULL, next_attempt_at = NULL
                      WHERE operation = 'backup' AND status = 'failed'"
                );
            }
            wp_send_json_success(array('reset' => $reset, 'stats' => $this->get_sync_stats()));
            return;
        }

        wp_send_json_error('Invalid mode');
    }

    /**
     * Scheduled entry point for draining the backup queue.
     */
    public function run_backup_queue() {
        if (!$this->is_backup_enabled()) {
            return;
        }

        $result = $this->process_backup_queue();

        if ($result['attempted'] > 0) {
            Azure_Logger::info(sprintf(
                'OneDrive Media Backup: %d attempted, %d succeeded, %d failed, %d remaining',
                $result['attempted'],
                $result['succeeded'],
                $result['failed'],
                $result['remaining']
            ));
        }
    }

    /**
     * Drain pending backup rows until the batch limit or time budget is spent.
     *
     * WP-Cron is disabled in the container and the external pinger only fires
     * once a day, so a single tick has to move as much as it safely can rather
     * than one fixed-size batch.
     */
    public function process_backup_queue($max_items = null, $time_budget = null) {
        global $wpdb;

        $summary = array('attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'remaining' => 0);

        $table = Azure_Database::get_table_name('onedrive_sync_queue');
        if (!$table || !$this->graph_api) {
            return $summary;
        }

        if ($max_items === null) {
            $max_items = (int) Azure_Settings::get_setting('onedrive_media_backup_batch_size', 25);
        }
        if ($time_budget === null) {
            $time_budget = (int) Azure_Settings::get_setting('onedrive_media_backup_time_budget', 90);
        }

        $max_retries = 4;
        $started = time();

        // Reclaim rows abandoned mid-upload. A worker killed by a PHP timeout
        // leaves its row in 'processing', where nothing would ever pick it up
        // again while it still counted towards the outstanding total.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET status = 'pending'
              WHERE operation = 'backup'
                AND status = 'processing'
                AND updated_at < %s",
            gmdate('Y-m-d H:i:s', time() - 1800)
        ));

        for ($i = 0; $i < $max_items; $i++) {
            if ((time() - $started) >= $time_budget) {
                break;
            }

            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table}
                  WHERE operation = 'backup'
                    AND status = 'pending'
                    AND (next_attempt_at IS NULL OR next_attempt_at <= %s)
                  ORDER BY id ASC
                  LIMIT 1",
                current_time('mysql', true)
            ));

            if (!$row) {
                break;
            }

            // Claim the row before the upload so a concurrent worker (an admin
            // clicking Back Up Now while cron fires) cannot pick up the same file.
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = 'processing', updated_at = %s
                  WHERE id = %d AND status = 'pending'",
                current_time('mysql', true),
                $row->id
            ));

            if (!$claimed) {
                continue;
            }

            $summary['attempted']++;
            $outcome = $this->backup_attachment((int) $row->attachment_id);

            if ($outcome['success']) {
                $wpdb->delete($table, array('id' => $row->id), array('%d'));
                $summary['succeeded']++;
                continue;
            }

            $summary['failed']++;
            $retry_count = (int) $row->retry_count + 1;

            if ($outcome['permanent'] || $retry_count >= $max_retries) {
                $wpdb->update(
                    $table,
                    array('status' => 'failed', 'retry_count' => $retry_count, 'error_message' => $outcome['message']),
                    array('id' => $row->id),
                    array('%s', '%d', '%s'),
                    array('%d')
                );
                Azure_Logger::error('OneDrive Media Backup: Giving up on attachment #' . $row->attachment_id . ' - ' . $outcome['message']);
                continue;
            }

            // Exponential backoff so a transient Graph outage does not burn
            // through the retry budget inside a single cron tick.
            $delay_minutes = pow(2, $retry_count) * 5;
            $wpdb->update(
                $table,
                array(
                    'status' => 'pending',
                    'retry_count' => $retry_count,
                    'error_message' => $outcome['message'],
                    'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + ($delay_minutes * 60)),
                ),
                array('id' => $row->id),
                array('%s', '%d', '%s', '%s'),
                array('%d')
            );
        }

        $summary['remaining'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE operation = 'backup' AND status IN ('pending', 'processing')"
        );

        return $summary;
    }

    /**
     * Upload one attachment's original file to OneDrive and record the mapping.
     *
     * Only the original is backed up; WordPress regenerates the intermediate
     * sizes from it, so shipping them would multiply the transfer for no
     * recovery benefit.
     *
     * @return array{success:bool,permanent:bool,message:string}
     */
    public function backup_attachment($attachment_id) {
        $fail = function ($message, $permanent = false) {
            return array('success' => false, 'permanent' => $permanent, 'message' => $message);
        };

        if (!$this->graph_api) {
            return $fail('Graph API not initialized');
        }

        if (get_post_type($attachment_id) !== 'attachment') {
            return $fail('Attachment no longer exists', true);
        }

        $local_file = get_attached_file($attachment_id);
        if (empty($local_file) || !file_exists($local_file)) {
            return $fail('Local file missing: ' . (string) $local_file, true);
        }

        $file_name = basename($local_file);
        $max_size = (int) Azure_Settings::get_setting('onedrive_media_max_file_size', 4294967296);
        $size = (int) @filesize($local_file);
        if ($max_size > 0 && $size > $max_size) {
            return $fail('File exceeds configured maximum size', true);
        }

        $existing = $this->get_mapping_for_attachment($attachment_id);
        if ($existing && $existing->sync_status === 'synced') {
            return array('success' => true, 'permanent' => false, 'message' => 'Already backed up');
        }

        $remote_path = $this->get_backup_folder_for_attachment($attachment_id);

        $file_data = $this->graph_api->upload_file($local_file, $remote_path, $file_name);
        if (!$file_data) {
            return $fail('Graph upload failed for ' . $file_name);
        }

        $this->store_file_mapping($attachment_id, $file_data, $local_file);
        Azure_Logger::debug('OneDrive Media Backup: Uploaded ' . $file_name . ' to ' . $remote_path);

        return array('success' => true, 'permanent' => false, 'message' => 'Backed up');
    }

    /**
     * Resolve the OneDrive folder for an attachment's backup.
     *
     * Uses the year the file was actually filed under in uploads rather than the
     * current year, so a backfill of older items does not collapse the whole
     * library into the current year's folder.
     */
    private function get_backup_folder_for_attachment($attachment_id) {
        $base_folder = Azure_Settings::get_setting('onedrive_media_base_folder', 'WordPress Media');

        if (!Azure_Settings::get_setting('onedrive_media_use_year_folders', true)) {
            return $base_folder;
        }

        $year = '';
        $attached_file = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
        if (preg_match('#^(\d{4})/#', $attached_file, $m)) {
            $year = $m[1];
        }

        if ($year === '') {
            $uploaded = get_post_field('post_date', $attachment_id);
            $year = $uploaded ? date('Y', strtotime($uploaded)) : date('Y');
        }

        return $base_folder . '/' . $year;
    }

    /**
     * Fetch the mapping row for an attachment, if any.
     */
    private function get_mapping_for_attachment($attachment_id) {
        global $wpdb;
        $table = Azure_Database::get_table_name('onedrive_files');
        if (!$table) {
            return null;
        }

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE attachment_id = %d LIMIT 1",
            (int) $attachment_id
        ));
    }

    /**
     * Earliest year the importer is allowed to pull into the media library.
     *
     * The OneDrive folder holds the previous site's full back catalogue. Pulling
     * that in would fill uploads with media the current site never references, so
     * imports are bounded and backups are driven from the library instead.
     */
    private function get_import_min_year() {
        $configured = (int) Azure_Settings::get_setting('onedrive_media_import_min_year', 2026);

        return $configured > 0 ? $configured : 0;
    }

    /**
     * Whether a folder name is a year folder older than the import cutoff.
     *
     * Only names that are entirely a 4-digit year are treated as year folders, so
     * a real folder such as "2026 Auction Photos" is never silently skipped.
     */
    private function is_year_folder_below_cutoff($folder_name) {
        $min_year = $this->get_import_min_year();
        if ($min_year <= 0) {
            return false;
        }

        if (!preg_match('/^\d{4}$/', trim((string) $folder_name))) {
            return false;
        }

        return (int) $folder_name < $min_year;
    }

    /**
     * Whether backups to OneDrive should run at all.
     */
    private function is_backup_enabled() {
        if (!$this->graph_api) {
            return false;
        }

        $direction = (string) Azure_Settings::get_setting('onedrive_media_sync_direction', 'wp_to_onedrive');

        return in_array($direction, array('wp_to_onedrive', 'two_way'), true);
    }

    /**
     * Handle attachment deletion.
     *
     * The OneDrive copy is deliberately retained: a backup that deletes itself
     * the moment the original is deleted cannot recover from an accidental
     * deletion, which is the main thing it exists to protect against. The
     * mapping is marked orphaned so the copy can still be found, and mirroring
     * remains available for anyone who explicitly opts in.
     */
    public function handle_delete_attachment($attachment_id) {
        global $wpdb;
        $table = Azure_Database::get_table_name('onedrive_files');
        $queue = Azure_Database::get_table_name('onedrive_sync_queue');

        if ($queue) {
            $wpdb->delete($queue, array('operation' => 'backup', 'attachment_id' => (int) $attachment_id), array('%s', '%d'));
        }

        if (!$table) {
            return;
        }

        $file_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE attachment_id = %d",
            $attachment_id
        ));

        if (!$file_row) {
            return;
        }

        $propagate = (bool) Azure_Settings::get_setting('onedrive_media_delete_propagation', false);

        if (!$propagate) {
            $wpdb->update(
                $table,
                array('sync_status' => 'orphaned', 'attachment_id' => null),
                array('id' => $file_row->id),
                array('%s', '%s'),
                array('%d')
            );
            Azure_Logger::info('OneDrive Media: Attachment #' . $attachment_id . ' deleted in WordPress; OneDrive backup retained - ' . $file_row->file_name);
            return;
        }

        if ($this->graph_api && $this->graph_api->delete_file($file_row->onedrive_id)) {
            $wpdb->delete($table, array('id' => $file_row->id), array('%d'));
            Azure_Logger::info('OneDrive Media: File deleted from OneDrive - ' . $file_row->file_name);
        } else {
            Azure_Logger::error('OneDrive Media: Failed to delete file from OneDrive - ' . $file_row->file_name);
        }
    }
    
    /**
     * Store file mapping in database
     */
    private function store_file_mapping($attachment_id, $file_data, $local_path = null) {
        global $wpdb;
        $table = Azure_Database::get_table_name('onedrive_files');
        
        $folder_year = null;
        if (Azure_Settings::get_setting('onedrive_media_use_year_folders', true)) {
            // Extract the year from the file's actual SharePoint path if possible,
            // rather than defaulting to the current year. This ensures files synced
            // from year-based folders (e.g. "WordPress Media/2024/photo.jpg") get
            // the correct year in their mapping.
            $parent_path = $file_data['parent_path'] ?? '';
            if (preg_match('/\/(\d{4})$/', $parent_path, $m)) {
                $folder_year = $m[1];
            } else {
                $folder_year = date('Y');
            }
        }
        
        if (empty($file_data['id'])) {
            Azure_Logger::warning('OneDrive Media: Refusing to store a mapping with no OneDrive file id');
            return false;
        }

        // Column => placeholder, so $data and its format list stay aligned even
        // when an optional column is dropped below.
        $columns = array(
            'attachment_id' => '%d',
            'onedrive_id'   => '%s',
            'onedrive_path' => '%s',
            'file_name'     => '%s',
            'file_size'     => '%d',
            'mime_type'     => '%s',
            'public_url'    => '%s',
            'folder_year'   => '%s',
            'last_modified' => '%s',
            'download_url'  => '%s',
            'sync_status'   => '%s',
        );

        $data = array(
            'attachment_id' => $attachment_id !== null ? (int) $attachment_id : null,
            'onedrive_id'   => $file_data['id'],
            'onedrive_path' => $file_data['parent_path'] ?? '',
            'file_name'     => $file_data['name'] ?? '',
            'file_size'     => (int) ($file_data['size'] ?? 0),
            'mime_type'     => $file_data['mime_type'] ?? '',
            'public_url'    => $file_data['web_url'] ?? '',
            'folder_year'   => $folder_year,
            'last_modified' => $file_data['modified'] ?? '',
            'download_url'  => $file_data['download_url'] ?? '',
            'sync_status'   => 'synced',
        );

        // An empty string is not a valid datetime; leave the column alone rather
        // than letting MySQL coerce it (or reject the row in strict mode).
        if (empty($data['last_modified'])) {
            unset($data['last_modified']);
        }

        // onedrive_id carries a UNIQUE key, so a plain insert for a file that is
        // already mapped fails silently and the row keeps its stale download URL
        // (and stays unlinked from its attachment).
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE onedrive_id = %s",
            $file_data['id']
        ));

        if ($existing_id) {
            // Don't clear an existing link just because this pass has no ID.
            if ($data['attachment_id'] === null) {
                unset($data['attachment_id']);
            }
            $wpdb->update($table, $data, array('id' => $existing_id), array_values(array_intersect_key($columns, $data)), array('%d'));
            return (int) $existing_id;
        }

        $wpdb->insert($table, $data, array_values(array_intersect_key($columns, $data)));

        return $wpdb->insert_id;
    }
    
    /**
     * Add OneDrive fields to attachment edit screen
     */
    public function add_onedrive_fields($fields, $post) {
        global $wpdb;
        $table = Azure_Database::get_table_name('onedrive_files');
        
        $file_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE attachment_id = %d",
            $post->ID
        ));
        
        if ($file_row) {
            $fields['onedrive_status'] = array(
                'label' => 'OneDrive Status',
                'input' => 'html',
                'html' => '<span style="color: green;">✓ Stored in OneDrive</span><br>' .
                         'File ID: ' . esc_html($file_row->onedrive_id) . '<br>' .
                         'Path: ' . esc_html($file_row->onedrive_path) . '<br>' .
                         ($file_row->public_url ? '<a href="' . esc_url($file_row->public_url) . '" target="_blank">View in OneDrive</a>' : '')
            );
        }
        
        return $fields;
    }
    
    /**
     * Get the OneDrive metadata for an attachment (for admin display only).
     */
    public function get_onedrive_info($attachment_id) {
        global $wpdb;
        $table = Azure_Database::get_table_name('onedrive_files');
        return $wpdb->get_row($wpdb->prepare(
            "SELECT onedrive_id, public_url, download_url, thumbnail_url FROM {$table} WHERE attachment_id = %d",
            $attachment_id
        ));
    }
    
    /**
     * Sync files from OneDrive to WordPress (recurses into subfolders)
     */
    public function sync_from_onedrive($folder_path = '') {
        if (!$this->graph_api) {
            return array('success' => false, 'message' => 'Graph API not initialized');
        }

        $base_folder = Azure_Settings::get_setting('onedrive_media_base_folder', 'WordPress Media');
        if (empty($folder_path)) {
            $folder_path = $base_folder;
        }

        $synced_count = 0;
        $error_count  = 0;
        $skipped_count = 0;

        $this->sync_folder_recursive($folder_path, $synced_count, $error_count, $skipped_count);

        Azure_Logger::info("OneDrive Media: Sync completed - {$synced_count} synced, {$skipped_count} already mapped, {$error_count} errors");

        $message = "Synced {$synced_count} files from OneDrive";
        if ($skipped_count > 0) {
            $message .= " ({$skipped_count} already existed)";
        }
        if ($error_count > 0) {
            $message .= " ({$error_count} errors)";
        }

        return array(
            'success' => true,
            'synced'  => $synced_count,
            'skipped' => $skipped_count,
            'errors'  => $error_count,
            'message' => $message,
        );
    }

    /**
     * Recursively sync a folder and its subfolders.
     */
    private function sync_folder_recursive($folder_path, &$synced, &$errors, &$skipped, $depth = 0) {
        if ($depth > 5) {
            return;
        }

        $items = $this->graph_api->list_folder($folder_path);
        if (empty($items)) {
            return;
        }

        global $wpdb;
        $table = Azure_Database::get_table_name('onedrive_files');

        foreach ($items as $item) {
            if ($item['is_folder']) {
                if ($this->is_year_folder_below_cutoff($item['name'])) {
                    Azure_Logger::debug('OneDrive Media: Skipping pre-cutoff year folder ' . $item['name']);
                    continue;
                }
                $subfolder = ltrim($folder_path . '/' . $item['name'], '/');
                $this->sync_folder_recursive($subfolder, $synced, $errors, $skipped, $depth + 1);
                continue;
            }

            // Skip WordPress-generated thumbnails (e.g. image-150x150.png)
            if ($this->is_wp_thumbnail($item['name'])) {
                $skipped++;
                continue;
            }

            // Check if this OneDrive file ID is already mapped
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, attachment_id, download_url FROM {$table} WHERE onedrive_id = %s",
                $item['id']
            ));

            if ($existing) {
                // Refresh the download URL for internal sync tracking
                $new_url = $item['download_url'] ?? '';
                if ($new_url && $new_url !== $existing->download_url) {
                    $wpdb->update($table, array('download_url' => $new_url), array('id' => $existing->id));
                }

                $skipped++;
                continue;
            }

            // Determine the correct relative path based on the OneDrive folder structure
            $subdir = $this->get_relative_upload_subdir($item['parent_path'] ?? '');
            $relative_file = $subdir !== '' ? $subdir . '/' . $item['name'] : $item['name'];

            // Exact path match — attachment already at the correct location
            $exact_match = $wpdb->get_var($wpdb->prepare(
                "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
                 WHERE pm.meta_key = '_wp_attached_file' AND pm.meta_value = %s
                 LIMIT 1",
                $relative_file
            ));

            if ($exact_match) {
                $this->store_file_mapping((int) $exact_match, $item);
                $skipped++;
                continue;
            }

            // Basename match — attachment exists but at the WRONG path (e.g. 2026/03 instead of 2019/02).
            // Move the file to the correct location and update WordPress metadata.
            $wrong_path_match = $wpdb->get_var($wpdb->prepare(
                "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
                 WHERE pm.meta_key = '_wp_attached_file' AND pm.meta_value LIKE %s
                 LIMIT 1",
                '%/' . $wpdb->esc_like($item['name'])
            ));

            if (!$wrong_path_match) {
                $wrong_path_match = $wpdb->get_var($wpdb->prepare(
                    "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
                     WHERE pm.meta_key = '_wp_attached_file' AND pm.meta_value = %s
                     LIMIT 1",
                    $item['name']
                ));
            }

            if ($wrong_path_match && $subdir !== '') {
                $this->relocate_attachment((int) $wrong_path_match, $subdir);
                $this->store_file_mapping((int) $wrong_path_match, $item);
                $skipped++;
                continue;
            } elseif ($wrong_path_match) {
                $this->store_file_mapping((int) $wrong_path_match, $item);
                $skipped++;
                continue;
            }

            $attachment_id = $this->create_attachment_from_onedrive($item);
            if ($attachment_id) {
                $synced++;
            } else {
                $errors++;
            }
        }
    }
    
    /**
     * Move an attachment's files from their current location to the correct YYYY/MM subdir
     * and update all WordPress metadata so URLs resolve properly.
     */
    private function relocate_attachment($attachment_id, $correct_subdir) {
        $upload_dir = wp_upload_dir();
        $basedir = $upload_dir['basedir'];
        $baseurl = $upload_dir['baseurl'];

        $current_relative = get_post_meta($attachment_id, '_wp_attached_file', true);
        if (empty($current_relative)) {
            return;
        }

        $filename = basename($current_relative);
        $correct_relative = $correct_subdir . '/' . $filename;

        // Already in the right place
        if ($current_relative === $correct_relative) {
            return;
        }

        $old_path = $basedir . '/' . $current_relative;
        $new_dir  = $basedir . '/' . $correct_subdir;
        $new_path = $new_dir . '/' . $filename;

        // Create target directory if needed
        if (!is_dir($new_dir)) {
            wp_mkdir_p($new_dir);
        }

        // Move the main file
        if (file_exists($old_path) && !file_exists($new_path)) {
            rename($old_path, $new_path);
        }

        // Move any thumbnails (e.g. image-150x150.jpg)
        $old_dir = dirname($old_path);
        $name_without_ext = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $pattern = $old_dir . '/' . $name_without_ext . '-*.' . $ext;
        foreach (glob($pattern) as $thumb_file) {
            $thumb_name = basename($thumb_file);
            $thumb_dest = $new_dir . '/' . $thumb_name;
            if (!file_exists($thumb_dest)) {
                rename($thumb_file, $thumb_dest);
            }
        }

        // Update _wp_attached_file meta
        update_post_meta($attachment_id, '_wp_attached_file', $correct_relative);

        // Update thumbnail paths in _wp_attachment_metadata
        $meta = wp_get_attachment_metadata($attachment_id);
        if (is_array($meta)) {
            if (!empty($meta['file'])) {
                $meta['file'] = $correct_relative;
            }
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                // Size entries only store the filename, not the subdir — they're
                // resolved relative to the file's directory, so no changes needed.
            }
            wp_update_attachment_metadata($attachment_id, $meta);
        }

        // Update the post guid
        global $wpdb;
        $new_guid = $baseurl . '/' . $correct_relative;
        $wpdb->update($wpdb->posts, array('guid' => $new_guid), array('ID' => $attachment_id));

        Azure_Logger::info("OneDrive Media: Relocated attachment #{$attachment_id} from {$current_relative} to {$correct_relative}");
    }

    /**
     * Extract the relative subpath (e.g. "2019/02") from a Graph API parent_path
     * by stripping everything up to and including the configured base folder name.
     */
    private function get_relative_upload_subdir($parent_path) {
        $base_folder = Azure_Settings::get_setting('onedrive_media_base_folder', 'WordPress Media');

        // parent_path looks like: /drives/{id}/root:/WordPress Media/2019/02
        // Graph percent-encodes it, so "WordPress Media" arrives as
        // "WordPress%20Media" and a raw comparison never matches — which sent
        // every imported file to today's YYYY/MM instead of its real date.
        $parent_path = rawurldecode((string) $parent_path);

        $marker = ':/' . trim($base_folder, '/');
        $pos = strpos($parent_path, $marker);
        if ($pos === false) {
            return '';
        }

        $after_base = substr($parent_path, $pos + strlen($marker));

        // The match must land on a path boundary. Otherwise a base folder of
        // "Media" matches "Media Archive/2019/02" and yields " Archive/2019/02".
        if ($after_base !== '' && $after_base[0] !== '/') {
            return '';
        }

        return trim($after_base, '/');
    }

    /**
     * Create WordPress attachment from OneDrive file.
     * Downloads the file and copies it directly to the correct uploads/YYYY/MM/
     * path derived from the OneDrive folder structure, bypassing media_handle_sideload
     * which always uses today's date.
     */
    private function create_attachment_from_onedrive($file_data) {
        $file_size = isset($file_data['size']) ? (int) $file_data['size'] : 0;
        $max_upload_size = wp_max_upload_size();
        if ($file_size > 0 && $file_size > $max_upload_size) {
            Azure_Logger::warning('OneDrive Media: Skipped "' . $file_data['name'] . '" (' . size_format($file_size) . ') — exceeds max upload size (' . size_format($max_upload_size) . ')');
            return false;
        }

        $temp_file = $this->download_onedrive_item($file_data);

        if (is_wp_error($temp_file)) {
            Azure_Logger::error('OneDrive Media: Failed to download - ' . $file_data['name'] . ': ' . $temp_file->get_error_message());
            return false;
        }

        $filename = sanitize_file_name($file_data['name']);
        $upload_dir = wp_upload_dir();
        $basedir = $upload_dir['basedir'];
        $baseurl = $upload_dir['baseurl'];

        // Determine target subdir from OneDrive folder structure (e.g. "2019/02")
        $subdir = $this->get_relative_upload_subdir($file_data['parent_path'] ?? '');
        if ($subdir === '') {
            // Fallback: use current YYYY/MM only if OneDrive has no folder structure
            $subdir = date('Y') . '/' . date('m');
        }

        $target_dir = $basedir . '/' . $subdir;
        if (!is_dir($target_dir)) {
            wp_mkdir_p($target_dir);
        }

        // Avoid overwriting — add suffix if file already exists
        $target_path = $target_dir . '/' . $filename;
        if (file_exists($target_path)) {
            $name_part = pathinfo($filename, PATHINFO_FILENAME);
            $ext_part  = pathinfo($filename, PATHINFO_EXTENSION);
            $counter   = 1;
            while (file_exists($target_path)) {
                $target_path = $target_dir . '/' . $name_part . '-' . $counter . '.' . $ext_part;
                $counter++;
            }
            $filename = basename($target_path);
        }

        // Copy downloaded file to correct uploads location
        $copied = copy($temp_file, $target_path);
        @unlink($temp_file);

        if (!$copied) {
            Azure_Logger::error('OneDrive Media: Failed to copy file to ' . $target_path);
            return false;
        }

        // Set correct permissions
        $stat = stat(dirname($target_path));
        @chmod($target_path, $stat['mode'] & 0000666);

        $relative_path = $subdir . '/' . $filename;
        $mime_type = $file_data['mime_type'] ?: mime_content_type($target_path);

        // Create the attachment post
        $attachment = array(
            'post_mime_type' => $mime_type,
            'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'guid'           => $baseurl . '/' . $relative_path,
        );

        // This file came from OneDrive, so it is already backed up by definition.
        // Without this guard the `add_attachment` hook would queue it to be
        // uploaded straight back to the folder it was just read from.
        $this->suppress_backup_queue = true;
        $attachment_id = wp_insert_attachment($attachment, $target_path, 0, true);
        $this->suppress_backup_queue = false;

        if (is_wp_error($attachment_id)) {
            Azure_Logger::error('OneDrive Media: Failed to create attachment - ' . $file_data['name'] . ': ' . $attachment_id->get_error_message());
            @unlink($target_path);
            return false;
        }

        // Ensure _wp_attached_file stores the correct relative path
        update_post_meta($attachment_id, '_wp_attached_file', $relative_path);

        // Generate thumbnails and attachment metadata
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attachment_id, $target_path);
        if (!is_wp_error($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        $this->store_file_mapping($attachment_id, $file_data);

        Azure_Logger::debug('OneDrive Media: Imported ' . $file_data['name'] . ' → uploads/' . $relative_path);

        return $attachment_id;
    }
    
    /**
     * Download a OneDrive item, re-minting its URL if the cached one has died.
     *
     * `@microsoft.graph.downloadUrl` is pre-signed and short-lived (about an
     * hour). Batched imports cache the folder listing and work through it 20
     * files per request, so a large folder routinely outlives the URLs it
     * started with — asking Graph for a fresh one turns a hard failure into a
     * retry.
     *
     * @return string|WP_Error Temp file path, or the download error.
     */
    private function download_onedrive_item($item) {
        $url = $item['download_url'] ?? '';

        if ($url !== '') {
            $temp_file = download_url($url);
            if (!is_wp_error($temp_file)) {
                return $temp_file;
            }
            $first_error = $temp_file;
        } else {
            $first_error = new WP_Error('onedrive_no_download_url', 'No download URL for ' . ($item['name'] ?? 'file'));
        }

        if (!$this->graph_api || empty($item['id'])) {
            return $first_error;
        }

        $fresh_url = $this->graph_api->get_download_url($item['id']);
        if (!$fresh_url) {
            return $first_error;
        }

        Azure_Logger::debug('OneDrive Media: Refreshed expired download URL for ' . ($item['name'] ?? $item['id']));
        return download_url($fresh_url);
    }

    /**
     * Run auto-sync (scheduled via WordPress Cron)
     */
    public function run_auto_sync() {
        if (!Azure_Settings::get_setting('onedrive_media_auto_sync', false)) {
            return;
        }

        // The direction setting used to be collected and then ignored: this always
        // ran the import, so a site set to "WordPress → OneDrive" silently pulled
        // files in instead of backing them up.
        $direction = (string) Azure_Settings::get_setting('onedrive_media_sync_direction', 'wp_to_onedrive');

        Azure_Logger::info('OneDrive Media: Starting auto-sync (direction: ' . $direction . ')');

        if (in_array($direction, array('wp_to_onedrive', 'two_way'), true)) {
            $this->run_backup_queue();
        }

        if (in_array($direction, array('onedrive_to_wp', 'two_way'), true)) {
            $this->sync_from_onedrive();
        }
    }
    
    /**
     * AJAX: Scan OneDrive folders — returns the list of top-level folders with file counts
     * so the UI can drive batch imports one folder at a time.
     */
    public function ajax_import_from_onedrive() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        if (!$this->graph_api) {
            wp_send_json_error('Graph API not initialized');
            return;
        }

        $mode = sanitize_text_field($_POST['mode'] ?? 'scan');

        if ($mode === 'scan') {
            $this->import_scan();
        } elseif ($mode === 'batch') {
            $this->import_batch();
        } else {
            wp_send_json_error('Invalid mode');
        }
    }

    /**
     * Step 1: Scan the OneDrive base folder and return a list of top-level
     * subfolders (year folders) with their recursive file counts. Also counts
     * any loose files in the base folder root.
     */
    private function import_scan() {
        $base_folder = Azure_Settings::get_setting('onedrive_media_base_folder', 'WordPress Media');
        $items = $this->graph_api->list_folder($base_folder);

        if (empty($items)) {
            wp_send_json_error('No files or folders found in ' . $base_folder);
            return;
        }

        $batches = array();
        $root_files = 0;

        $excluded_years = array();

        foreach ($items as $item) {
            if ($item['is_folder']) {
                // A new school year means the old site's back catalogue should stay
                // out of the library; importing it would only inflate the uploads
                // volume with media nothing on the site references.
                if ($this->is_year_folder_below_cutoff($item['name'])) {
                    $excluded_years[] = $item['name'];
                    continue;
                }
                $folder_path = $base_folder . '/' . $item['name'];
                $files = array();
                $this->collect_onedrive_files($folder_path, $item['name'], $files);
                $batches[] = array(
                    'folder'     => $item['name'],
                    'file_count' => count($files),
                );
            } else {
                if (!$this->is_wp_thumbnail($item['name'])) {
                    $root_files++;
                }
            }
        }

        // Add root loose files as a batch if any
        if ($root_files > 0) {
            array_unshift($batches, array(
                'folder'     => '__root__',
                'file_count' => $root_files,
            ));
        }

        // $batches already includes the __root__ entry, so seeding the total with
        // $root_files as well counted the loose root files twice and made the
        // import progress bar stall short of 100%.
        $total_files = 0;
        foreach ($batches as $b) {
            $total_files += $b['file_count'];
        }

        Azure_Logger::info('OneDrive Media Import: Scan found ' . count($batches) . ' batches, ' . $total_files . ' total files');

        if (!empty($excluded_years)) {
            sort($excluded_years);
            Azure_Logger::info('OneDrive Media Import: Excluded pre-cutoff year folders - ' . implode(', ', $excluded_years));
        }

        wp_send_json_success(array(
            'batches'         => $batches,
            'total_files'     => $total_files,
            'excluded_years'  => $excluded_years,
            'import_min_year' => $this->get_import_min_year(),
        ));
    }

    private static $IMPORT_CHUNK_SIZE = 20;

    /**
     * Step 2: Import a chunk of files from a single folder.
     * Called repeatedly by the UI with increasing offset until done.
     * File list is cached in a transient after the first call so we
     * only hit the Graph API once per folder.
     */
    private function import_batch() {
        $folder = sanitize_text_field($_POST['folder'] ?? '');
        if ($folder === '') {
            wp_send_json_error('No folder specified');
            return;
        }

        $offset = max(0, intval($_POST['offset'] ?? 0));

        @set_time_limit(120);
        @ini_set('memory_limit', '512M');
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $base_folder = Azure_Settings::get_setting('onedrive_media_base_folder', 'WordPress Media');
        $upload_dir  = wp_upload_dir();
        $basedir     = $upload_dir['basedir'];
        $baseurl     = $upload_dir['baseurl'];

        // Cache file list in a transient so we only scan OneDrive once per folder
        $cache_key = 'od_import_' . md5($folder);
        $files = get_transient($cache_key);

        if ($files === false || $offset === 0) {
            $files = array();
            if ($folder === '__root__') {
                $items = $this->graph_api->list_folder($base_folder);
                foreach (($items ?: array()) as $item) {
                    if (!$item['is_folder']) {
                        $files[] = array('item' => $item, 'subpath' => '');
                    }
                }
            } else {
                $folder_path = $base_folder . '/' . $folder;
                $this->collect_onedrive_files($folder_path, $folder, $files);
            }
            set_transient($cache_key, $files, 1800); // 30 min TTL
            if ($offset === 0) {
                Azure_Logger::info('OneDrive Media Import: Folder "' . $folder . '" — ' . count($files) . ' files found');
            }
        }

        $total   = count($files);
        $chunk   = array_slice($files, $offset, self::$IMPORT_CHUNK_SIZE);
        $imported = 0;
        $skipped  = 0;
        $errors   = 0;

        global $wpdb;
        $onedrive_table = Azure_Database::get_table_name('onedrive_files');

        $max_upload_size = wp_max_upload_size();

        foreach ($chunk as $file_entry) {
            $item    = $file_entry['item'];
            $subpath = $file_entry['subpath'];

            if ($this->is_wp_thumbnail($item['name'])) {
                $skipped++;
                continue;
            }

            $file_size = isset($item['size']) ? (int) $item['size'] : 0;
            if ($file_size > 0 && $file_size > $max_upload_size) {
                Azure_Logger::warning('OneDrive Media Import: Skipped "' . $item['name'] . '" (' . size_format($file_size) . ') — exceeds max upload size (' . size_format($max_upload_size) . ')');
                $skipped++;
                continue;
            }

            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$onedrive_table} WHERE onedrive_id = %s", $item['id']
            ));
            if ($existing) {
                $skipped++;
                continue;
            }

            $filename = sanitize_file_name($item['name']);
            $relative_path = $subpath !== '' ? $subpath . '/' . $filename : $filename;

            $already_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
                 WHERE pm.meta_key = '_wp_attached_file' AND pm.meta_value = %s
                 LIMIT 1",
                $relative_path
            ));
            if ($already_exists) {
                $this->store_file_mapping((int) $already_exists, $item);
                $skipped++;
                continue;
            }

            $temp_file = $this->download_onedrive_item($item);
            if (is_wp_error($temp_file)) {
                Azure_Logger::error('OneDrive Media Import: Download failed — ' . $item['name'] . ': ' . $temp_file->get_error_message());
                $errors++;
                continue;
            }

            $target_dir = $subpath !== '' ? $basedir . '/' . $subpath : $basedir;
            if (!is_dir($target_dir)) {
                wp_mkdir_p($target_dir);
            }

            $target_path = $target_dir . '/' . $filename;
            if (file_exists($target_path)) {
                $name_part = pathinfo($filename, PATHINFO_FILENAME);
                $ext_part  = pathinfo($filename, PATHINFO_EXTENSION);
                $counter   = 1;
                while (file_exists($target_path)) {
                    $target_path = $target_dir . '/' . $name_part . '-' . $counter . '.' . $ext_part;
                    $counter++;
                }
                $filename = basename($target_path);
                $relative_path = $subpath !== '' ? $subpath . '/' . $filename : $filename;
            }

            $copied = copy($temp_file, $target_path);
            @unlink($temp_file);

            if (!$copied) {
                Azure_Logger::error('OneDrive Media Import: Copy failed — ' . $target_path);
                $errors++;
                continue;
            }

            @chmod($target_path, 0644);

            $mime_type = $item['mime_type'] ?: (mime_content_type($target_path) ?: 'application/octet-stream');
            $attachment_id = wp_insert_attachment(array(
                'post_mime_type' => $mime_type,
                'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
                'post_content'   => '',
                'post_status'    => 'inherit',
                'guid'           => $baseurl . '/' . $relative_path,
            ), $target_path, 0, true);

            if (is_wp_error($attachment_id)) {
                Azure_Logger::error('OneDrive Media Import: Attachment failed — ' . $filename . ': ' . $attachment_id->get_error_message());
                @unlink($target_path);
                $errors++;
                continue;
            }

            update_post_meta($attachment_id, '_wp_attached_file', $relative_path);

            $metadata = wp_generate_attachment_metadata($attachment_id, $target_path);
            if (!is_wp_error($metadata) && is_array($metadata)) {
                wp_update_attachment_metadata($attachment_id, $metadata);
            }

            $this->store_file_mapping($attachment_id, $item);
            $imported++;
        }

        $next_offset = $offset + self::$IMPORT_CHUNK_SIZE;
        $has_more = $next_offset < $total;

        if (!$has_more) {
            delete_transient($cache_key);
            Azure_Logger::info('OneDrive Media Import: Folder "' . $folder . '" complete');
        }

        wp_send_json_success(array(
            'folder'      => $folder,
            'imported'    => $imported,
            'skipped'     => $skipped,
            'errors'      => $errors,
            'offset'      => $offset,
            'next_offset' => $next_offset,
            'total'       => $total,
            'has_more'    => $has_more,
        ));
    }

    /**
     * Recursively collect all files from OneDrive with their relative subpath.
     * Builds a flat list: [ ['item' => ..., 'subpath' => '2019/02'], ... ]
     */
    private function collect_onedrive_files($folder_path, $relative_subpath, &$files, $depth = 0) {
        if ($depth > 10) {
            return;
        }

        $items = $this->graph_api->list_folder($folder_path);
        if (empty($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item['is_folder']) {
                $child_folder  = ltrim($folder_path . '/' . $item['name'], '/');
                $child_subpath = $relative_subpath !== '' ? $relative_subpath . '/' . $item['name'] : $item['name'];
                $this->collect_onedrive_files($child_folder, $child_subpath, $files, $depth + 1);
            } else {
                $files[] = array(
                    'item'    => $item,
                    'subpath' => $relative_subpath,
                );
            }
        }
    }

    /**
     * AJAX: Sync from OneDrive
     */
    public function ajax_sync_from_onedrive() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $result = $this->sync_from_onedrive();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * AJAX: Browse OneDrive/SharePoint folders
     */
    public function ajax_browse_folders() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $folder_path = isset($_POST['path']) ? sanitize_text_field($_POST['path']) : '/';
        $storage_type = isset($_POST['storage_type']) ? sanitize_text_field($_POST['storage_type']) : 'onedrive';
        
        if ($this->graph_api) {
            // If SharePoint, use site and drive ID
            if ($storage_type === 'sharepoint') {
                $site_id = isset($_POST['site_id']) ? sanitize_text_field($_POST['site_id']) : '';
                $drive_id = isset($_POST['drive_id']) ? sanitize_text_field($_POST['drive_id']) : '';
                
                if (empty($site_id) || empty($drive_id)) {
                    wp_send_json_error('SharePoint site ID and drive ID required');
                    return;
                }
                
                $items = $this->graph_api->list_drive_folder($drive_id, $folder_path);
            } else {
                // OneDrive
                $items = $this->graph_api->list_folder($folder_path);
            }
            
            // Filter to only return folders
            $folders = array_filter($items, function($item) {
                return $item['is_folder'];
            });
            
            wp_send_json_success(array('folders' => array_values($folders)));
        } else {
            wp_send_json_error('Graph API not initialized');
        }
    }
    
    /**
     * AJAX: Create folder
     */
    public function ajax_create_folder() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $parent_path = isset($_POST['parent_path']) ? sanitize_text_field($_POST['parent_path']) : '';
        $folder_name = isset($_POST['folder_name']) ? sanitize_text_field($_POST['folder_name']) : '';
        
        if (empty($folder_name)) {
            wp_send_json_error('Folder name is required');
            return;
        }
        
        if ($this->graph_api) {
            $result = $this->graph_api->create_folder($parent_path, $folder_name);
            
            if ($result) {
                wp_send_json_success(array('folder' => $result));
            } else {
                wp_send_json_error('Failed to create folder');
            }
        } else {
            wp_send_json_error('Graph API not initialized');
        }
    }
    
    /**
     * AJAX: Test connection
     */
    public function ajax_test_connection() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if ($this->auth) {
            $result = $this->auth->test_connection();
            
            if ($result['success']) {
                wp_send_json_success($result['message']);
            } else {
                wp_send_json_error($result['message']);
            }
        } else {
            wp_send_json_error('Authentication not initialized');
        }
    }
    
    /**
     * AJAX: List SharePoint sites
     */
    public function ajax_list_sharepoint_sites() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        // Check if OneDrive auth is initialized
        if (!$this->auth) {
            wp_send_json_error('OneDrive authentication not initialized. Please check Azure credentials.');
            return;
        }
        
        // Get access token
        $access_token = $this->auth->get_access_token();
        
        if (!$access_token) {
            wp_send_json_error('No access token available. Please authorize OneDrive access first (Step 1).');
            return;
        }
        
        // Make direct Graph API call
        $api_url = 'https://graph.microsoft.com/v1.0/sites?search=*';
        
        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error('Failed to connect to Microsoft Graph API: ' . $response->get_error_message());
            return;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            wp_send_json_error('Graph API error: ' . ($data['error']['message'] ?? 'Unknown error'));
            return;
        }
        
        $sites = $data['value'] ?? array();
        
        if (empty($sites)) {
            wp_send_json_error('No SharePoint sites found. Make sure you have access to SharePoint sites and the required permissions (Sites.Read.All).');
            return;
        }
        
        wp_send_json_success(array('sites' => $sites));
    }
    
    /**
     * AJAX: List SharePoint document libraries (drives)
     */
    public function ajax_list_sharepoint_drives() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $site_id = isset($_POST['site_id']) ? sanitize_text_field($_POST['site_id']) : '';
        
        if (empty($site_id)) {
            wp_send_json_error('Site ID required');
            return;
        }
        
        // Check if OneDrive auth is initialized
        if (!$this->auth) {
            wp_send_json_error('OneDrive authentication not initialized');
            return;
        }
        
        $access_token = $this->auth->get_access_token();
        
        if (!$access_token) {
            wp_send_json_error('No access token available');
            return;
        }
        
        // Make direct Graph API call
        $api_url = "https://graph.microsoft.com/v1.0/sites/{$site_id}/drives";
        
        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error('Failed to connect: ' . $response->get_error_message());
            return;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            wp_send_json_error('Graph API error: ' . ($data['error']['message'] ?? 'Unknown error'));
            return;
        }
        
        $drives = $data['value'] ?? array();
        
        if (empty($drives)) {
            wp_send_json_error('No document libraries found');
            return;
        }
        
        wp_send_json_success(array('drives' => $drives));
    }
    
    /**
     * AJAX: Resolve SharePoint site from URL
     */
    public function ajax_resolve_sharepoint_site() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $site_url = isset($_POST['site_url']) ? esc_url_raw($_POST['site_url']) : '';
        
        if (empty($site_url)) {
            wp_send_json_error('Site URL required');
            return;
        }
        
        if ($this->graph_api) {
            $site = $this->graph_api->get_site_by_url($site_url);
            
            if ($site) {
                wp_send_json_success(array(
                    'site_id' => $site['id'],
                    'site_name' => $site['displayName'] ?? $site['name']
                ));
            } else {
                wp_send_json_error('Failed to resolve SharePoint site');
            }
        } else {
            wp_send_json_error('Graph API not initialized');
        }
    }
    
    /**
     * AJAX: Create year folders
     */
    public function ajax_create_year_folders() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!$this->graph_api) {
            wp_send_json_error('Graph API not initialized');
            return;
        }
        
        $base_folder = Azure_Settings::get_setting('onedrive_media_base_folder', 'WordPress Media');
        $current_year = (int) date('Y');
        $folders_created = array();

        // Determine the earliest year from existing WordPress uploads
        $earliest_year = $current_year;
        $upload_dir = wp_upload_dir();
        $upload_base = $upload_dir['basedir'];
        if (is_dir($upload_base)) {
            foreach (scandir($upload_base) as $entry) {
                if (preg_match('/^(\d{4})$/', $entry, $m) && is_dir($upload_base . '/' . $entry)) {
                    $yr = (int) $m[1];
                    if ($yr >= 2010 && $yr < $earliest_year) {
                        $earliest_year = $yr;
                    }
                }
            }
        }

        // Create individual year folders from earliest through current year
        for ($year = $earliest_year; $year <= $current_year; $year++) {
            $result = $this->graph_api->create_folder($base_folder, (string) $year);
            if ($result) {
                $folders_created[] = (string) $year;
            }
        }
        
        if (!empty($folders_created)) {
            wp_send_json_success(array('message' => 'Created folders: ' . implode(', ', $folders_created)));
        } else {
            wp_send_json_error('No folders were created. They may already exist.');
        }
    }
    
    /**
     * AJAX: Diagnostic check — show what OneDrive sees vs what WP has, without changing anything.
     */
    public function ajax_repair_diagnose() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        @set_time_limit(120);

        $info = array();

        // Storage config
        $info['storage_type'] = Azure_Settings::get_setting('onedrive_media_storage_type', 'onedrive');
        $info['base_folder']  = Azure_Settings::get_setting('onedrive_media_base_folder', 'WordPress Media');
        $info['site_id']      = Azure_Settings::get_setting('onedrive_media_site_id', '') ? 'set' : 'empty';
        $info['drive_id']     = Azure_Settings::get_setting('onedrive_media_drive_id', '') ? 'set' : 'empty';

        if (!$this->graph_api) {
            wp_send_json_error('Graph API not initialized — OneDrive module may need authorization.');
            return;
        }

        // Test: list the base folder
        $base_items = $this->graph_api->list_folder($info['base_folder']);
        $info['base_folder_items'] = count($base_items);
        $info['base_folder_contents'] = array();
        foreach ($base_items as $bi) {
            $info['base_folder_contents'][] = ($bi['is_folder'] ? '[folder] ' : '[file] ') . $bi['name'];
        }

        // Build full recursive index (thumbnails filtered out)
        $full_index = $this->build_onedrive_file_index();
        $info['onedrive_originals'] = count($full_index);
        $info['onedrive_note'] = 'WP thumbnails (-WxH variants) are excluded from index';
        $info['onedrive_sample_files'] = array_slice(array_keys($full_index), 0, 10);

        // WP attachments missing local files
        global $wpdb;
        $upload_dir = wp_upload_dir();
        $basedir = trailingslashit($upload_dir['basedir']);

        $all_attachments = $wpdb->get_results(
            "SELECT p.ID, pm.meta_value AS attached_file
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment'
             AND p.post_mime_type LIKE 'image/%'
             ORDER BY p.ID DESC"
        );

        $info['wp_total_image_attachments'] = count($all_attachments);
        $missing = array();
        foreach ($all_attachments as $att) {
            if (!file_exists($basedir . $att->attached_file)) {
                $missing[] = $att;
            }
        }
        $info['wp_missing_local_files'] = count($missing);

        // Sample WP filenames vs OneDrive index (with fuzzy matching)
        $matched = 0;
        $unmatched_samples = array();
        foreach (array_slice($missing, 0, 20) as $m) {
            $fn = basename($m->attached_file);
            $found = $this->find_in_index($fn, $full_index);
            if ($found) {
                $matched++;
            } else {
                $unmatched_samples[] = $m->attached_file;
            }
        }
        $info['sample_matched'] = $matched . ' of first ' . min(20, count($missing));
        $info['sample_unmatched_paths'] = array_slice($unmatched_samples, 0, 10);
        $info['match_note'] = 'Uses fuzzy matching: strips -scaled, -rotated, -e{timestamp}, -WxH suffixes';

        // Mapping table
        $table = Azure_Database::get_table_name('onedrive_files');
        $info['mapping_table_rows'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        wp_send_json_success($info);
    }

    /**
     * Try to find a file in the OneDrive index by trying multiple WordPress filename variants.
     * WP may add "-scaled", "-e{timestamp}", "-{WxH}" suffixes that OneDrive won't have.
     *
     * @return array|null  The file_data from the index, or null if no match.
     */
    private function find_in_index($filename, $onedrive_index) {
        foreach ($this->wp_filename_variants($filename) as $variant) {
            if (isset($onedrive_index[$variant])) {
                return $onedrive_index[$variant];
            }
        }

        return null;
    }

    /**
     * Every lower-cased name a WordPress copy of an original might have.
     *
     * WP appends suffixes the OneDrive original won't carry: "-scaled" from
     * big-image downsizing, "-e{timestamp}" from an in-editor edit, "-rotated",
     * and "-{W}x{H}" for intermediate sizes. They stack — editing an already
     * downsized image gives "name-e1764958349687-scaled.jpg" — so strip
     * repeatedly instead of testing each suffix once against the full stem.
     *
     * @return array Lower-cased filename candidates, most specific first.
     */
    private function wp_filename_variants($filename) {
        $lower = strtolower($filename);
        $ext   = pathinfo($lower, PATHINFO_EXTENSION);
        $stem  = pathinfo($lower, PATHINFO_FILENAME);

        $suffixes = array(
            '/-scaled$/',
            '/-rotated$/',
            '/-e\d{10,14}$/',
            '/-\d+x\d+$/',
        );

        $variants = array($lower);
        $seen     = array($stem => true);
        $queue    = array($stem);

        while ($queue) {
            $current = array_shift($queue);
            foreach ($suffixes as $pattern) {
                $stripped = preg_replace($pattern, '', $current);
                if ($stripped === $current || $stripped === '' || isset($seen[$stripped])) {
                    continue;
                }
                $seen[$stripped] = true;
                $queue[] = $stripped;
                $variants[] = $ext !== '' ? $stripped . '.' . $ext : $stripped;
            }
        }

        return $variants;
    }

    /**
     * Build a filename → file data index from all OneDrive files (recursive).
     */
    private function build_onedrive_file_index() {
        $base_folder = Azure_Settings::get_setting('onedrive_media_base_folder', 'WordPress Media');
        $index = array();
        $this->index_folder_recursive($base_folder, $index);
        Azure_Logger::info('OneDrive Repair: Indexed ' . count($index) . ' files from OneDrive');
        return $index;
    }

    private function index_folder_recursive($folder_path, &$index, $depth = 0) {
        if ($depth > 5) return;

        $items = $this->graph_api->list_folder($folder_path);
        if (empty($items)) return;

        foreach ($items as $item) {
            if ($item['is_folder']) {
                $subfolder = ltrim($folder_path . '/' . $item['name'], '/');
                $this->index_folder_recursive($subfolder, $index, $depth + 1);
            } else {
                // Skip WP thumbnail variants (e.g. image-300x200.png, image-100x100.png)
                if ($this->is_wp_thumbnail($item['name'])) {
                    continue;
                }
                $key = strtolower($item['name']);
                if (!isset($index[$key])) {
                    $index[$key] = $item;
                }
            }
        }
    }

    /**
     * Check if a filename looks like a WordPress-generated thumbnail.
     * Matches patterns like: name-150x150.png, name-300x200-1.jpg (with OneDrive conflict suffix)
     */
    private function is_wp_thumbnail($filename) {
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        // Matches -WxH or -WxH-N (OneDrive conflict suffix) at the end
        return (bool) preg_match('/-\d{2,4}x\d{2,4}(-\d+)?$/', $stem);
    }

    /**
     * Get sync statistics
     */
    /**
     * Backup coverage for the media library.
     *
     * Counting only the mapping table meant the dashboard could report a healthy
     * "12 synced" while staying silent about the other 83 library items that had
     * never been attempted, so the library total is the denominator here.
     */
    public function get_sync_stats() {
        global $wpdb;
        $table = Azure_Database::get_table_name('onedrive_files');
        $queue = Azure_Database::get_table_name('onedrive_sync_queue');

        $library_total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'"
        );

        $backed_up = 0;
        $orphaned = 0;
        $total_size = 0;

        if ($table) {
            $backed_up = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT attachment_id) FROM {$table}
                  WHERE attachment_id IS NOT NULL AND sync_status = 'synced'"
            );
            $orphaned = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE sync_status = 'orphaned'"
            );
            $total_size = (int) $wpdb->get_var("SELECT SUM(file_size) FROM {$table}");
        }

        $queued = 0;
        $failed = 0;

        if ($queue) {
            $queued = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$queue}
                  WHERE operation = 'backup' AND status IN ('pending', 'processing')"
            );
            $failed = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$queue} WHERE operation = 'backup' AND status = 'failed'"
            );
        }

        $not_queued = max(0, $library_total - $backed_up - $queued - $failed);
        $coverage = $library_total > 0 ? round(($backed_up / $library_total) * 100) : 0;

        return array(
            'library_total'   => $library_total,
            'backed_up_files' => $backed_up,
            'queued_files'    => $queued,
            'failed_files'    => $failed,
            'not_queued'      => $not_queued,
            'orphaned_files'  => $orphaned,
            'coverage_pct'    => $coverage,
            'total_size'      => $total_size,
        );
    }
}