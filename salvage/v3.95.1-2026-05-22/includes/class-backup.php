<?php
/**
 * Backup orchestrator - manages jobs, scheduling, AJAX, and delegates
 * archive creation to Azure_Backup_Engine.
 *
 * v2: Split archives per component, WP-Cron resumption chain,
 * per-entity state tracking, manifest-based backup sets.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Backup {

    private $settings;

    public function __construct() {
        try {
            $this->settings = Azure_Settings::get_all_settings();

            add_action('azure_backup_resume', array($this, 'backup_resume'), 10, 1);
            // Legacy hook kept for any in-flight jobs
            add_action('azure_backup_process', array($this, 'backup_resume'), 10, 1);

            add_action('wp_ajax_azure_start_backup', array($this, 'ajax_start_backup'));
            add_action('wp_ajax_azure_get_backup_jobs', array($this, 'ajax_get_backup_jobs'));
            add_action('wp_ajax_azure_get_backup_progress', array($this, 'ajax_get_backup_progress'));
            add_action('wp_ajax_azure_cancel_all_backups', array($this, 'ajax_cancel_all_backups'));
            add_action('wp_ajax_azure_run_backup_now', array($this, 'ajax_run_backup_now'));
            add_action('wp_ajax_azure_cleanup_backup_files', array($this, 'ajax_cleanup_backup_files'));
            add_action('wp_ajax_azure_trigger_backup_process', array($this, 'ajax_trigger_backup_process'));
            add_action('wp_ajax_azure_get_backup_components', array($this, 'ajax_get_backup_components'));
            add_action('wp_ajax_azure_download_backup_blob', array($this, 'ajax_download_backup_blob'));

            // Heartbeat API for progress (P3)
            add_filter('heartbeat_received', array($this, 'heartbeat_received'), 10, 2);

            // Ensure entity_state column exists (v2 migration)
            $this->maybe_add_entity_state_column();
        } catch (Exception $e) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::error('Backup: Constructor error - ' . $e->getMessage());
            }
            $this->settings = array();
        }
    }

    private function maybe_add_entity_state_column() {
        global $wpdb;
        $table = $this->get_table();
        if (!$table) return;

        $col = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'entity_state'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN entity_state longtext AFTER backup_types");
        }
    }

    // ------------------------------------------------------------------
    // Job management helpers
    // ------------------------------------------------------------------

    private function get_table() {
        return Azure_Database::get_table_name('backup_jobs');
    }

    public function update_backup_progress($backup_id, $progress, $status = null, $message = null) {
        global $wpdb;
        $table = $this->get_table();
        if (!$table) return;

        $data = array('progress' => $progress, 'updated_at' => gmdate('Y-m-d H:i:s'));
        if ($status) $data['status'] = $status;
        if ($message) $data['message'] = $message;

        $wpdb->update($table, $data, array('backup_id' => $backup_id));
    }

    private function update_job_state($backup_id, $entity_state) {
        global $wpdb;
        $table = $this->get_table();
        if (!$table) return;
        $wpdb->update(
            $table,
            array('entity_state' => json_encode($entity_state), 'updated_at' => gmdate('Y-m-d H:i:s')),
            array('backup_id' => $backup_id)
        );
    }

    private function get_job($backup_id) {
        global $wpdb;
        $table = $this->get_table();
        if (!$table) return null;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE backup_id = %s", $backup_id));
    }

    private function update_status($job_id, $status, $error = null) {
        global $wpdb;
        $table = $this->get_table();
        if (!$table) return;
        $data = array('status' => $status);
        if ($status === 'completed') $data['completed_at'] = gmdate('Y-m-d H:i:s');
        if ($error) $data['error_message'] = $error;
        $wpdb->update($table, $data, array('id' => $job_id));
    }

    private function update_blob_info($job_id, $blob_name, $file_size) {
        global $wpdb;
        $table = $this->get_table();
        if (!$table) return;
        $wpdb->update($table, array('azure_blob_name' => $blob_name, 'file_size' => $file_size), array('id' => $job_id));
    }

    private function create_backup_job($backup_id, $name, $types, $scheduled) {
        global $wpdb;
        $table = $this->get_table();
        if (!$table) {
            $this->ensure_backup_table();
            $table = $this->get_table();
            if (!$table) return false;
        }

        $entity_state = array();
        foreach ($types as $t) {
            $entity_state[$t] = array('status' => 'pending', 'files' => array());
        }

        $result = $wpdb->insert($table, array(
            'backup_id'    => $backup_id,
            'job_name'     => $name,
            'backup_types' => json_encode($types),
            'entity_state' => json_encode($entity_state),
            'status'       => 'pending',
            'progress'     => 0,
            'message'      => 'Backup initialized',
            'started_at'   => gmdate('Y-m-d H:i:s'),
        ));

        return $result !== false ? $wpdb->insert_id : false;
    }

    private function ensure_backup_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'azure_backup_jobs';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            backup_id varchar(255) NOT NULL,
            job_name varchar(255) NOT NULL,
            backup_types longtext,
            entity_state longtext,
            status varchar(50) DEFAULT 'pending',
            progress int(11) DEFAULT 0,
            message longtext,
            file_path varchar(500),
            file_size bigint(20) DEFAULT 0,
            azure_blob_name varchar(500),
            started_at datetime,
            completed_at datetime,
            error_message longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY backup_id (backup_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // ------------------------------------------------------------------
    // Backup resume / cron chain (core loop)
    // ------------------------------------------------------------------

    /**
     * Process one entity of a backup job then schedule the next resumption.
     * This is the heart of the WP-Cron chain approach.
     */
    public function backup_resume($backup_id) {
        @ignore_user_abort(true);
        $max_exec = max(intval(Azure_Settings::get_setting('backup_max_execution_time', 3600)), 300);
        @set_time_limit($max_exec);
        @ini_set('memory_limit', '512M');

        Azure_Logger::info("Backup: Resume called for {$backup_id}", 'Backup');

        $job = $this->get_job($backup_id);
        if (!$job) {
            Azure_Logger::error("Backup: Job not found: {$backup_id}", 'Backup');
            return;
        }

        if (in_array($job->status, array('completed', 'failed', 'cancelled'))) {
            Azure_Logger::info("Backup: Job {$backup_id} already {$job->status}, skipping", 'Backup');
            return;
        }

        // Guard against double-run — allow 15 min for long zip/upload operations
        if ($job->status === 'running') {
            $last = strtotime($job->updated_at ?? $job->started_at ?? 'now');
            if ((time() - $last) < 900) {
                Azure_Logger::info("Backup: Job still active ({$backup_id}), skipping duplicate", 'Backup');
                return;
            }
        }

        $this->update_backup_progress($backup_id, 5, 'running', 'Backup in progress...');

        $entity_state = json_decode($job->entity_state ?? '{}', true);
        if (empty($entity_state)) {
            $types = json_decode($job->backup_types, true) ?: array('database', 'mu-plugins', 'plugins', 'themes', 'content');
            foreach ($types as $t) {
                $entity_state[$t] = array('status' => 'pending', 'files' => array());
            }
        }

        $job_meta = get_transient('azure_backup_meta_' . $backup_id);
        if (!is_array($job_meta)) $job_meta = array();
        $backup_dir = $this->get_backup_directory($backup_id);
        wp_mkdir_p($backup_dir);

        if (!class_exists('Azure_Backup_Engine')) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-backup-engine.php';
        }

        $self = $this;
        $engine = new Azure_Backup_Engine($backup_id, $backup_dir, function ($pct, $status, $msg) use ($self, $backup_id) {
            $self->update_backup_progress($backup_id, $pct ?: 0, $status, $msg);
        });

        $storage = class_exists('Azure_Backup_Storage') ? new Azure_Backup_Storage() : null;
        $total_entities = count($entity_state);
        $done_entities = count(array_filter($entity_state, function ($e) { return $e['status'] === 'completed'; }));

        $empty_entities = array();

        try {
            foreach ($entity_state as $entity => &$state) {
                if ($state['status'] === 'completed') {
                    continue;
                }

                $pct = 5 + intval(85 * $done_entities / max($total_entities, 1));
                $this->update_backup_progress($backup_id, $pct, 'running', "Processing: {$entity}...");
                $state['status'] = 'in_progress';
                $this->update_job_state($backup_id, $entity_state);

                $archive_paths = $this->run_entity_backup($engine, $entity, $job_meta, $backup_dir);

                // Upload each archive to Azure and record blob names + sizes
                $blob_names = array();
                $blob_sizes = array();
                if ($storage && !empty($archive_paths)) {
                    foreach ($archive_paths as $apath) {
                        clearstatcache(true, $apath);
                        if (!file_exists($apath) || filesize($apath) === 0) {
                            Azure_Logger::warning("Backup: Skipping missing or empty archive '" . basename($apath) . "' — the zip may have failed to compress. The backup will continue with remaining components.", 'Backup');
                            @unlink($apath);
                            continue;
                        }
                        $asize = filesize($apath);
                        $blob = $this->upload_component($storage, $apath, $backup_id);
                        if ($blob) {
                            $blob_names[] = $blob;
                            $blob_sizes[] = $asize;
                        }
                        @unlink($apath);
                    }
                }

                if (empty($blob_names)) {
                    $empty_entities[] = $entity;
                }

                $state['status'] = 'completed';
                $state['files'] = $blob_names;
                $state['sizes'] = $blob_sizes;
                $done_entities++;
                $this->update_job_state($backup_id, $entity_state);

                Azure_Logger::info("Backup: Entity '{$entity}' completed - " . count($blob_names) . " blob(s)", 'Backup');
            }
            unset($state);

            // Upload manifest
            $manifest_path = $engine->generate_manifest($entity_state);
            if ($storage && file_exists($manifest_path)) {
                $manifest_blob = $this->upload_component($storage, $manifest_path, $backup_id, 'application/json');
                @unlink($manifest_path);
                $this->update_blob_info($job->id, $manifest_blob, $this->calc_total_size($entity_state));
            }

            // Cleanup temp directory
            $this->remove_directory($backup_dir);

            // Post-backup validation: verify all blobs exist in Azure
            $this->update_backup_progress($backup_id, 95, 'running', 'Validating backup in Azure Storage...');
            $validation = $this->validate_backup($storage, $manifest_blob ?? null, $entity_state);

            $this->update_status($job->id, 'completed');

            $msg = 'Backup completed';
            $warnings = array();
            if (!empty($empty_entities)) {
                $warnings[] = 'no files archived for ' . implode(', ', $empty_entities);
            }
            if (!$validation['passed']) {
                $warnings[] = 'validation issues: ' . $validation['summary'];
            }

            if (empty($warnings)) {
                $msg .= ' and verified successfully!';
            } else {
                $msg .= ' with warnings: ' . implode('; ', $warnings) . '. Check System Logs for details.';
            }

            $entity_state['_validation'] = $validation;
            $this->update_job_state($backup_id, $entity_state);
            $this->update_backup_progress($backup_id, 100, 'completed', $msg);
            delete_transient('azure_backup_meta_' . $backup_id);

            Azure_Logger::info("Backup: Job {$backup_id} COMPLETED — validation: " . ($validation['passed'] ? 'PASSED' : 'ISSUES FOUND'), 'Backup');

            if ($this->settings['backup_email_notifications'] ?? false) {
                $this->send_notification($job->id, true, $msg);
            }
        } catch (\Throwable $e) {
            Azure_Logger::error("Backup: Job {$backup_id} FAILED: " . $e->getMessage(), 'Backup');
            $this->update_status($job->id, 'failed', $e->getMessage());
            $this->update_backup_progress($backup_id, 0, 'failed', 'Backup failed: ' . $e->getMessage());
            $this->remove_directory($backup_dir);
        }
    }

    private function run_entity_backup($engine, $entity, $job_meta, $backup_dir) {
        switch ($entity) {
            case 'database':
                $path = $engine->backup_database();
                return $path ? array($path) : array();

            case 'plugins':
                $selected = $job_meta['selected_plugins'] ?? array();
                return $engine->backup_entity('plugins', WP_PLUGIN_DIR, Azure_Backup_Engine::get_plugin_exclusions(), $selected);

            case 'themes':
                $selected = $job_meta['selected_themes'] ?? array();
                return $engine->backup_entity('themes', get_theme_root(), array(), $selected);

            case 'uploads':
            case 'media':
                $uploads = wp_upload_dir();
                return $engine->backup_entity('uploads', $uploads['basedir']);

            case 'mu-plugins':
                $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
                if (is_dir($mu_dir)) {
                    return $engine->backup_entity('mu-plugins', $mu_dir);
                }
                return array();

            case 'content':
            case 'others':
                return $engine->backup_entity('others', WP_CONTENT_DIR, Azure_Backup_Engine::get_others_exclusions());

            default:
                Azure_Logger::warning("Backup: Unknown entity type: {$entity}", 'Backup');
                return array();
        }
    }

    private function upload_component($storage, $file_path, $backup_id, $content_type = 'application/zip') {
        $date = date('Y/m/d');
        $site_name = sanitize_title(get_bloginfo('name'));
        $filename = basename($file_path);
        $blob_name = "{$site_name}/{$date}/{$backup_id}/{$filename}";

        Azure_Logger::info("Backup: Uploading {$filename} to Azure...", 'Backup');
        $self = $this;
        $cur_id = $backup_id;

        $result = $storage->upload_backup($file_path, $blob_name, function ($pct) use ($self, $cur_id, $filename) {
            $self->update_backup_progress($cur_id, null, 'running', "Uploading {$filename}: {$pct}%");
        });

        return $result ?: $blob_name;
    }

    private function calc_total_size($entity_state) {
        $total = 0;
        foreach ($entity_state as $state) {
            foreach ($state['sizes'] ?? array() as $s) {
                $total += (int) $s;
            }
        }
        return $total;
    }

    // ------------------------------------------------------------------
    // Post-backup validation
    // ------------------------------------------------------------------

    /**
     * Verify the backup in Azure Storage is complete and restorable.
     * Uses HEAD requests (no downloads) for efficiency.
     */
    private function validate_backup($storage, $manifest_blob, $entity_state) {
        $result = array(
            'passed'     => true,
            'manifest'   => false,
            'components' => array(),
            'summary'    => '',
            'timestamp'  => gmdate('c'),
        );

        if (!$storage) {
            $result['passed'] = false;
            $result['summary'] = 'Storage not available';
            Azure_Logger::warning('Backup Validation: Skipped — storage not available', 'Backup');
            return $result;
        }

        $issues = array();

        // 1. Verify manifest blob exists
        if ($manifest_blob) {
            $props = $storage->get_blob_properties($manifest_blob);
            if ($props && $props['size'] > 0) {
                $result['manifest'] = true;
                Azure_Logger::info("Backup Validation: Manifest OK — {$manifest_blob} ({$props['size']} bytes)", 'Backup');
            } else {
                $result['passed'] = false;
                $issues[] = 'manifest missing';
                Azure_Logger::error("Backup Validation: Manifest MISSING — {$manifest_blob}", 'Backup');
            }
        } else {
            $result['passed'] = false;
            $issues[] = 'no manifest uploaded';
            Azure_Logger::error('Backup Validation: No manifest blob recorded', 'Backup');
        }

        // 2. Verify each component blob
        $total_blobs = 0;
        $verified_blobs = 0;

        foreach ($entity_state as $entity => $state) {
            if (strpos($entity, '_') === 0) continue; // skip meta keys like _validation

            $files = $state['files'] ?? array();
            $sizes = $state['sizes'] ?? array();
            $comp = array('entity' => $entity, 'expected' => count($files), 'verified' => 0, 'issues' => array());

            if (empty($files)) {
                $comp['issues'][] = 'no files';
                $issues[] = "{$entity}: no files";
            }

            foreach ($files as $i => $blob) {
                $total_blobs++;
                $expected_size = isset($sizes[$i]) ? (int) $sizes[$i] : 0;
                $props = $storage->get_blob_properties($blob);

                if (!$props) {
                    $comp['issues'][] = basename($blob) . ' not found';
                    $issues[] = "{$entity}: " . basename($blob) . ' missing from Azure';
                    Azure_Logger::error("Backup Validation: MISSING blob — {$blob}", 'Backup');
                    continue;
                }

                if ($expected_size > 0 && abs($props['size'] - $expected_size) > 1024) {
                    $comp['issues'][] = basename($blob) . " size mismatch (expected " . size_format($expected_size) . ", got " . size_format($props['size']) . ")";
                    Azure_Logger::warning("Backup Validation: Size mismatch — {$blob} (expected {$expected_size}, got {$props['size']})", 'Backup');
                }

                $comp['verified']++;
                $verified_blobs++;
            }

            $result['components'][$entity] = $comp;
        }

        if ($total_blobs > 0 && $verified_blobs < $total_blobs) {
            $result['passed'] = false;
            $missing = $total_blobs - $verified_blobs;
            $issues[] = "{$missing} of {$total_blobs} blob(s) could not be verified";
        }

        $result['summary'] = empty($issues) ? 'All components verified' : implode('; ', array_slice($issues, 0, 3));
        $label = $result['passed'] ? 'PASSED' : 'FAILED';
        Azure_Logger::info("Backup Validation: {$label} — {$verified_blobs}/{$total_blobs} blobs verified" . (empty($issues) ? '' : ' — ' . $result['summary']), 'Backup');

        return $result;
    }

    // ------------------------------------------------------------------
    // Scheduling and spawning
    // ------------------------------------------------------------------

    private function schedule_next_resume($backup_id, $delay = 5) {
        wp_schedule_single_event(time() + $delay, 'azure_backup_resume', array($backup_id));
        $this->spawn_cron();
    }

    private function spawn_cron() {
        $url = site_url('wp-cron.php');
        wp_remote_post($url, array(
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => false,
            'body'      => array('doing_wp_cron' => sprintf('%.22F', microtime(true))),
        ));
    }

    private function get_backup_directory($backup_id) {
        return AZURE_PLUGIN_PATH . 'backups/temp_' . $backup_id;
    }

    // ------------------------------------------------------------------
    // AJAX handlers
    // ------------------------------------------------------------------

    public function ajax_start_backup() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }

        try {
            $backup_types = Azure_Settings::get_setting('backup_types', array('database', 'mu-plugins', 'plugins', 'themes', 'content'));
            $backup_name = 'Manual Backup - ' . date('Y-m-d H:i:s');
            $backup_id = 'backup_' . time() . '_' . wp_generate_password(8, false);

            $job_meta = array();
            if (!empty($_POST['selected_plugins']) && is_array($_POST['selected_plugins'])) {
                $job_meta['selected_plugins'] = array_map('sanitize_text_field', $_POST['selected_plugins']);
            }
            if (!empty($_POST['selected_themes']) && is_array($_POST['selected_themes'])) {
                $job_meta['selected_themes'] = array_map('sanitize_text_field', $_POST['selected_themes']);
            }
            if (!empty($job_meta)) {
                set_transient('azure_backup_meta_' . $backup_id, $job_meta, 3600);
            }

            $job_id = $this->create_backup_job($backup_id, $backup_name, $backup_types, false);
            if (!$job_id) {
                throw new Exception('Failed to create backup job record');
            }

            Azure_Logger::info("Backup: Job created: {$backup_id}", 'Backup');

            // Schedule the first resumption
            $this->schedule_next_resume($backup_id, 1);

            wp_send_json_success(array(
                'message'           => 'Backup started',
                'backup_id'         => $backup_id,
                'requires_progress' => true,
            ));
        } catch (\Throwable $e) {
            Azure_Logger::error('Backup: Start failed: ' . $e->getMessage(), 'Backup');
            wp_send_json_error('Backup failed: ' . $e->getMessage());
        }
    }

    public function ajax_get_backup_progress() {
        if (!current_user_can('manage_options') || !isset($_POST['backup_id'])) {
            wp_send_json_error('Unauthorized');
        }

        $backup_id = sanitize_text_field($_POST['backup_id']);
        $job = $this->get_job($backup_id);
        if (!$job) {
            wp_send_json_error('Job not found');
        }

        $data = array(
            'backup_id'   => $job->backup_id,
            'backup_name' => $job->job_name,
            'status'      => $job->status,
            'progress'    => intval($job->progress),
            'message'     => $job->message ?: 'Processing...',
            'created_at'  => $job->created_at,
            'updated_at'  => $job->updated_at ?? $job->created_at,
        );

        // If stuck pending for >15s or stale running for >15min, trigger direct run
        if ($job->status === 'pending') {
            $elapsed = time() - strtotime($job->created_at ?? $job->started_at);
            if ($elapsed > 15) {
                $data['needs_direct_run'] = true;
                $data['message'] = 'Backup is initializing...';
            }
        } elseif ($job->status === 'running') {
            $last = strtotime($job->updated_at ?? $job->created_at);
            if ((time() - $last) > 900) {
                $data['needs_direct_run'] = true;
                $data['message'] = 'Backup process appears stalled, restarting...';
                Azure_Logger::warning("Backup: Job {$backup_id} stale, signaling direct run", 'Backup');
            }
        }

        wp_send_json_success($data);
    }

    public function ajax_run_backup_now() {
        if (!current_user_can('manage_options') || !isset($_POST['backup_id']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }

        $backup_id = sanitize_text_field($_POST['backup_id']);
        $job = $this->get_job($backup_id);
        if (!$job || !in_array($job->status, array('pending', 'running'))) {
            wp_send_json_success('Backup already ' . ($job ? $job->status : 'not found'));
            return;
        }

        $lock = 'azure_backup_direct_' . $backup_id;
        if (get_transient($lock)) {
            wp_send_json_success('Already executing');
            return;
        }
        set_transient($lock, true, 3600);

        @ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        // Close HTTP connection early
        if (function_exists('fastcgi_finish_request')) {
            wp_send_json_success('Backup started');
            fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0) @ob_end_clean();
            header('Content-Type: application/json');
            header('Connection: close');
            $resp = json_encode(array('success' => true, 'data' => 'Backup started'));
            header('Content-Length: ' . strlen($resp));
            echo $resp;
            flush();
            if (session_id()) session_write_close();
        }

        $this->backup_resume($backup_id);
        delete_transient($lock);
    }

    public function ajax_trigger_backup_process() {
        if (!current_user_can('manage_options') || !isset($_POST['backup_id']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }
        $backup_id = sanitize_text_field($_POST['backup_id']);
        $this->schedule_next_resume($backup_id, 1);
        wp_send_json_success('Triggered');
    }

    public function ajax_cancel_all_backups() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $table = $this->get_table();
        if (!$table) wp_send_json_error('Table not found');

        $cancel_data = array('status' => 'cancelled', 'error_message' => 'Cancelled by administrator', 'message' => 'Cancelled', 'progress' => 0);
        $running = $wpdb->update($table, $cancel_data, array('status' => 'running'));
        $pending = $wpdb->update($table, $cancel_data, array('status' => 'pending'));

        $ids = $wpdb->get_col("SELECT backup_id FROM {$table} WHERE status = 'cancelled'");
        foreach ($ids as $id) {
            wp_clear_scheduled_hook('azure_backup_resume', array($id));
            wp_clear_scheduled_hook('azure_backup_process', array($id));
        }

        $this->cleanup_stale_temp_directories(true);

        $total = ($running ?: 0) + ($pending ?: 0);
        Azure_Logger::info("Backup: Cancelled {$total} jobs", 'Backup');
        wp_send_json_success(array('cancelled' => $total, 'message' => "Cancelled {$total} job(s)"));
    }

    public function ajax_cleanup_backup_files() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }

        $dir = AZURE_PLUGIN_PATH . 'backups/';
        $before = $this->count_items($dir);
        $this->cleanup_stale_temp_directories(true);
        $after = $this->count_items($dir);
        $cleaned = $before - $after;

        wp_send_json_success(array(
            'before_count' => $before,
            'after_count'  => $after,
            'cleaned'      => $cleaned,
            'message'      => $cleaned > 0 ? "Cleaned {$cleaned} item(s)" : 'Nothing to clean',
        ));
    }

    public function ajax_get_backup_jobs() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $table = $this->get_table();
        if (!$table) { wp_send_json_error('Table not found'); return; }

        $jobs = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 10");
        ob_start();
        foreach ($jobs as $job) {
            $has_entities = !empty($job->entity_state) && $job->entity_state !== '{}';
            $is_completed = $job->status === 'completed' && !empty($job->azure_blob_name);
            $cls = $job->status === 'completed' ? 'success' : ($job->status === 'failed' ? 'error' : 'warning');

            $entity_data = json_decode($job->entity_state ?? '{}', true);
            $validation = $entity_data['_validation'] ?? null;

            echo '<tr class="backup-parent-row" data-job-id="' . $job->id . '">';
            echo '<td style="text-align:center;">';
            if ($is_completed && $has_entities) {
                echo '<button class="button button-link toggle-backup-details" data-job-id="' . $job->id . '" title="Show files" style="padding:0;min-height:0;font-size:16px;line-height:1;cursor:pointer;">';
                echo '<span class="dashicons dashicons-arrow-right-alt2" style="transition:transform 0.2s;"></span></button>';
            }
            echo '</td>';
            echo '<td>' . esc_html($job->job_name) . '</td>';
            echo '<td><span class="status-indicator ' . $cls . '">' . esc_html(ucfirst($job->status)) . '</span>';
            if ($is_completed && $validation) {
                if ($validation['passed']) {
                    echo ' <span title="Backup verified in Azure Storage" style="color:#46b450;font-size:16px;vertical-align:middle;cursor:help;">&#10003;</span>';
                } else {
                    echo ' <span title="' . esc_attr($validation['summary']) . '" style="color:#dc3232;font-size:14px;vertical-align:middle;cursor:help;">&#9888;</span>';
                }
            }
            echo '</td>';
            echo '<td>' . esc_html($job->created_at) . '</td>';
            echo '<td>' . ($job->file_size ? size_format($job->file_size) : '-') . '</td>';
            echo '<td>';
            if ($is_completed) {
                echo '<button class="button button-small restore-backup" data-backup-id="' . $job->id . '" data-has-entities="' . ($has_entities ? '1' : '0') . '">Restore</button> ';
            }
            if ($job->status === 'failed' && !empty($job->error_message)) {
                echo '<button class="button button-small view-error" data-error="' . esc_attr($job->error_message) . '">View Error</button> ';
            }
            if (in_array($job->status, array('completed', 'failed', 'cancelled'))) {
                echo '<button class="button button-small delete-backup" data-backup-id="' . $job->id . '">Delete</button>';
            }
            echo '</td></tr>';

            echo '<tr class="backup-detail-row" data-job-id="' . $job->id . '" style="display:none;">';
            echo '<td colspan="6" style="padding:0;"><div class="backup-detail-content" style="padding:8px 12px 12px 40px;background:#f9f9f9;">';
            echo '<div class="backup-detail-loading" style="color:#666;"><span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span> Loading...</div>';
            echo '<div class="backup-detail-table" style="display:none;"></div>';
            echo '</div></td></tr>';
        }
        wp_send_json_success(ob_get_clean());
    }

    // ------------------------------------------------------------------
    // Component details & download
    // ------------------------------------------------------------------

    public function ajax_get_backup_components() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'] ?? '', 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }

        $job_id = intval($_POST['backup_id'] ?? 0);
        if (!$job_id) {
            wp_send_json_error('Missing backup ID');
        }

        global $wpdb;
        $table = $this->get_table();
        $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $job_id));
        if (!$job) {
            wp_send_json_error('Job not found');
        }

        $entity_state = json_decode($job->entity_state ?? '{}', true);
        $components = array();

        foreach ($entity_state as $entity => $state) {
            $files = $state['files'] ?? array();
            $sizes = $state['sizes'] ?? array();
            foreach ($files as $i => $blob) {
                $components[] = array(
                    'entity'   => $entity,
                    'blob'     => $blob,
                    'filename' => basename($blob),
                    'size'     => isset($sizes[$i]) ? (int) $sizes[$i] : 0,
                    'size_fmt' => isset($sizes[$i]) ? size_format((int) $sizes[$i]) : '-',
                );
            }
        }

        wp_send_json_success(array(
            'components' => $components,
            'total_size' => $job->file_size ? size_format($job->file_size) : '-',
        ));
    }

    public function ajax_download_backup_blob() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['nonce'] ?? '', 'azure_plugin_nonce')) {
            wp_die('Unauthorized');
        }

        $blob_name = sanitize_text_field($_GET['blob'] ?? '');
        if (empty($blob_name)) {
            wp_die('Missing blob name');
        }

        if (!class_exists('Azure_Backup_Storage')) {
            wp_die('Storage class not available');
        }

        try {
            $storage = new Azure_Backup_Storage();
            $tmp = wp_tempnam(basename($blob_name));
            $storage->download_backup($blob_name, $tmp);

            $filename = basename($blob_name);
            $size = @filesize($tmp);

            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            if ($size) {
                header('Content-Length: ' . $size);
            }
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');

            readfile($tmp);
            @unlink($tmp);
            exit;
        } catch (Exception $e) {
            wp_die('Download failed: ' . esc_html($e->getMessage()));
        }
    }

    // ------------------------------------------------------------------
    // Heartbeat API (P3)
    // ------------------------------------------------------------------

    public function heartbeat_received($response, $data) {
        if (!empty($data['azure_backup_id'])) {
            $job = $this->get_job(sanitize_text_field($data['azure_backup_id']));
            if ($job) {
                $response['azure_backup'] = array(
                    'status'   => $job->status,
                    'progress' => intval($job->progress),
                    'message'  => $job->message,
                );
            }
        }
        return $response;
    }

    // ------------------------------------------------------------------
    // Scheduled backup
    // ------------------------------------------------------------------

    public function run_scheduled_backup() {
        if (!Azure_Settings::get_setting('backup_schedule_enabled', false)) return;

        $types = Azure_Settings::get_setting('backup_types', array('database', 'mu-plugins', 'plugins', 'themes', 'content'));
        $name = 'Scheduled Backup - ' . date('Y-m-d H:i:s');
        $backup_id = 'backup_' . time() . '_' . wp_generate_password(8, false);

        $job_id = $this->create_backup_job($backup_id, $name, $types, true);
        if ($job_id) {
            $this->schedule_next_resume($backup_id, 1);
            Azure_Logger::info("Backup: Scheduled backup created: {$backup_id}", 'Backup');
        }
    }

    // ------------------------------------------------------------------
    // Utilities
    // ------------------------------------------------------------------

    private function cleanup_stale_temp_directories($force = false) {
        $dir = AZURE_PLUGIN_PATH . 'backups/';
        if (!is_dir($dir)) return;

        $items = @scandir($dir);
        if (!$items) return;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.htaccess') continue;
            $path = $dir . $item;

            if (is_dir($path) && strpos($item, 'temp_') === 0) {
                $this->remove_directory($path);
            }
            if (is_file($path) && preg_match('/\.(zip|gz)$/i', $item)) {
                if ($force || (time() - filemtime($path)) > 3600) {
                    @unlink($path);
                }
            }
        }
    }

    private function remove_directory($dir) {
        if (!is_dir($dir)) return;
        $items = @scandir($dir);
        if (!$items) return;
        foreach (array_diff($items, array('.', '..')) as $f) {
            $p = $dir . DIRECTORY_SEPARATOR . $f;
            is_dir($p) ? $this->remove_directory($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function count_items($dir) {
        if (!is_dir($dir)) return 0;
        $items = @scandir($dir);
        return $items ? count(array_diff($items, array('.', '..', '.htaccess'))) : 0;
    }

    private function send_notification($job_id, $success, $message) {
        $email = $this->settings['backup_notification_email'] ?? get_option('admin_email');
        if (empty($email)) return;

        $subject = ($success ? 'Backup Completed' : 'Backup Failed') . ' - ' . get_bloginfo('name');
        $body = "Status: " . ($success ? 'Success' : 'Failed') . "\n"
              . "Message: {$message}\nTime: " . current_time('mysql') . "\nSite: " . get_site_url() . "\n";
        wp_mail($email, $subject, $body);
    }

    private function format_bytes($bytes) {
        $units = array('B', 'KB', 'MB', 'GB');
        for ($i = 0; $bytes > 1024 && $i < 3; $i++) $bytes /= 1024;
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Legacy method for backward compat with other classes
     */
    public function process_background_backup($backup_id) {
        $this->backup_resume($backup_id);
    }
}
