<?php
/**
 * Backup restore - downloads component archives from Azure and restores
 * selected components. Supports both v2 (split) and v1 (single-zip) formats.
 *
 * DB restore uses raw mysqli with statement awareness, auto-reconnect,
 * and max_allowed_packet handling.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Backup_Restore {
    
    private static $restore_in_progress = false;
    private $storage;
    private static $restore_key = 'azure_restore_progress';
    private $pre_restore_credentials = array();
    
    public function __construct() {
        try {
            if (class_exists('Azure_Backup_Storage')) {
                $this->storage = new Azure_Backup_Storage();
            }
            
            add_action('wp_ajax_azure_restore_backup', array($this, 'ajax_restore_backup'));
            add_action('wp_ajax_azure_get_restore_status', array($this, 'ajax_get_restore_status'));
            add_action('wp_ajax_azure_list_remote_backups', array($this, 'ajax_list_remote_backups'));
            add_action('wp_ajax_azure_restore_remote_backup', array($this, 'ajax_restore_remote_backup'));
            add_action('wp_ajax_azure_get_restore_progress', array($this, 'ajax_get_restore_progress'));
            add_action('wp_ajax_azure_get_backup_manifest', array($this, 'ajax_get_backup_manifest'));
        } catch (Exception $e) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::error('Restore: Constructor error - ' . $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------
    // Progress helpers
    // ------------------------------------------------------------------

    private function update_progress($progress, $status, $message) {
        $data = array(
            'progress' => intval($progress),
            'status'   => $status,
            'message'  => $message,
            'updated'  => time(),
        );
        set_transient(self::$restore_key, $data, 600);
    }

    public function ajax_get_restore_progress() {
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        $data = get_transient(self::$restore_key);
        wp_send_json_success($data ?: array('progress' => 0, 'status' => 'idle', 'message' => ''));
    }

    /**
     * Emit an activity log entry to the Restore Wizard's log (if class exists).
     */
    private function wizard_log($message, $type = 'info') {
        if (class_exists('Azure_Restore_Wizard')) {
            Azure_Restore_Wizard::log_activity($message, $type);
        }
    }

    // ------------------------------------------------------------------
    // Manifest discovery
    // ------------------------------------------------------------------

    /**
     * AJAX: Fetch manifest for a backup to show available components.
     */
    public function ajax_get_backup_manifest() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }

        $blob_name = sanitize_text_field($_POST['blob_name'] ?? '');
        if (empty($blob_name)) wp_send_json_error('Missing blob name');

        try {
            // blob_name may be the manifest itself or the backup prefix
            $manifest_blob = $blob_name;
            if (strpos($blob_name, 'manifest.json') === false) {
                $manifest_blob = rtrim($blob_name, '/') . '/manifest.json';
                // Also try: replace filename with manifest
                if (!$this->blob_exists($manifest_blob)) {
                    $dir = dirname($blob_name);
                    $parts = explode('/', $dir);
                    $manifest_blob = $dir . '/manifest.json';
                }
            }

            $tmp = AZURE_PLUGIN_PATH . 'backups/tmp_manifest_' . uniqid() . '.json';
            wp_mkdir_p(dirname($tmp));
            $this->storage->download_backup($manifest_blob, $tmp);

            $manifest = json_decode(file_get_contents($tmp), true);
            @unlink($tmp);

            if (!$manifest) wp_send_json_error('Invalid manifest');
            wp_send_json_success($manifest);
        } catch (Exception $e) {
            // No manifest = v1 backup, return null so UI can fall back
            wp_send_json_success(null);
        }
    }

    private function blob_exists($blob_name) {
        try {
            $tmp = tempnam(sys_get_temp_dir(), 'chk');
            $this->storage->download_backup($blob_name, $tmp);
            $exists = file_exists($tmp) && filesize($tmp) > 0;
            @unlink($tmp);
            return $exists;
        } catch (Exception $e) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Restore from local job
    // ------------------------------------------------------------------

    public function ajax_restore_backup() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_die('Unauthorized');
        }

        @ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $backup_id = intval($_POST['backup_id']);
        $restore_types = isset($_POST['restore_types']) ? array_map('sanitize_text_field', $_POST['restore_types']) : null;

        try {
            $this->restore_backup($backup_id, $restore_types);
            wp_send_json_success('Restore completed');
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function restore_backup($backup_id, $restore_types = null) {
        if (self::$restore_in_progress) throw new Exception('Another restore is in progress.');
        if (!$this->storage) throw new Exception('Azure Storage not configured.');

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        self::$restore_in_progress = true;
        
        global $wpdb;
        $table = Azure_Database::get_table_name('backup_jobs');
        $backup = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND status = 'completed'", $backup_id));

        if (!$backup || empty($backup->azure_blob_name)) {
            self::$restore_in_progress = false;
            throw new Exception('Backup not found or incomplete.');
        }

        try {
            $this->do_restore($backup->azure_blob_name, $restore_types);
            self::$restore_in_progress = false;
        } catch (Exception $e) {
            self::$restore_in_progress = false;
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Restore from remote blob (no local DB entry)
    // ------------------------------------------------------------------

    public function ajax_restore_remote_backup() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_die('Unauthorized');
        }

        @ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $blob_name = sanitize_text_field($_POST['blob_name'] ?? '');
        $restore_types = isset($_POST['restore_types']) ? array_map('sanitize_text_field', $_POST['restore_types']) : null;

        if (empty($blob_name)) { wp_send_json_error('Blob name required'); return; }

        try {
            $this->do_restore($blob_name, $restore_types);
            wp_send_json_success('Restore completed');
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Core restore logic
    // ------------------------------------------------------------------

    /**
     * Main restore dispatcher. Handles both v2 (manifest-based, split) and
     * v1 (single zip) backup formats.
     */
    private function do_restore($blob_name, $restore_types = null) {
        $this->update_progress(5, 'running', 'Starting restore...');
        $types_label = $restore_types ? implode(', ', $restore_types) : 'all';
        Azure_Logger::info("Restore: Starting for: {$blob_name} (types: {$types_label})", 'Backup');

        // Capture current site's Azure Storage credentials before DB restore overwrites them
        if (class_exists('Azure_Settings')) {
            $cred_keys = array(
                'backup_storage_account_name', 'backup_storage_account',
                'backup_storage_account_key', 'backup_storage_key',
                'backup_storage_container_name', 'backup_container_name',
            );
            foreach ($cred_keys as $k) {
                $val = Azure_Settings::get_setting($k, '');
                if (!empty($val)) {
                    $this->pre_restore_credentials[$k] = $val;
                }
            }
        }

        $restore_dir = AZURE_PLUGIN_PATH . 'backups/restore_' . uniqid();
        wp_mkdir_p($restore_dir);

        try {
            $manifest = $this->try_download_manifest($blob_name, $restore_dir);

            if ($manifest) {
                Azure_Logger::info('Restore: Detected v2 (split) backup — manifest found with ' . count($manifest['components']) . ' component(s)', 'Backup');
                $this->restore_v2($manifest, $restore_dir, $restore_types);
            } else {
                Azure_Logger::info('Restore: No manifest found — treating as v1 (legacy) backup', 'Backup');
                $this->restore_v1($blob_name, $restore_dir, $restore_types);
            }

            $this->update_progress(95, 'running', 'Clearing caches...');
            $this->clear_caches();

            $this->remove_directory($restore_dir);
            $this->update_progress(100, 'completed', 'Restore completed successfully!');
            Azure_Logger::info('Restore: Completed successfully', 'Backup');
            
        } catch (Exception $e) {
            $this->update_progress(0, 'failed', 'Restore failed: ' . $e->getMessage());
            Azure_Logger::error('Restore: Failed: ' . $e->getMessage(), 'Backup');
            $this->remove_directory($restore_dir);
            throw $e;
        }
    }
    
    /**
     * Try to download the manifest for a v2 backup.
     */
    private function try_download_manifest($blob_name, $restore_dir) {
        $candidates = array();

        if (strpos($blob_name, 'manifest.json') !== false) {
            $candidates[] = $blob_name;
        } else {
            $prefix = rtrim(dirname($blob_name), '/');
            $candidates[] = $prefix . '/' . basename($prefix) . '-manifest.json';
            $candidates[] = rtrim($blob_name, '/') . '-manifest.json';
        }

        foreach ($candidates as $manifest_blob) {
            try {
                $tmp = $restore_dir . '/manifest.json';
                Azure_Logger::debug("Restore: Trying manifest candidate: {$manifest_blob}", 'Backup');
                $this->storage->download_backup($manifest_blob, $tmp);
                if (file_exists($tmp) && filesize($tmp) > 10) {
                    $manifest = json_decode(file_get_contents($tmp), true);
                    if ($manifest && !empty($manifest['components'])) {
                        $manifest['_manifest_blob'] = $manifest_blob;
                        $manifest['_blob_prefix'] = dirname($manifest_blob);
                        Azure_Logger::info("Restore: Manifest loaded from {$manifest_blob} — backup_id: " . ($manifest['backup_id'] ?? 'unknown'), 'Backup');
                        return $manifest;
                    }
                    Azure_Logger::warning("Restore: Manifest at {$manifest_blob} parsed but has no components", 'Backup');
                }
                @unlink($tmp);
            } catch (Exception $e) {
                Azure_Logger::debug("Restore: Manifest candidate not found: {$manifest_blob}", 'Backup');
            }
        }

        return null;
    }

    /**
     * v2 restore: download and apply individual component archives.
     */
    private static function sort_restore_entities($a, $b) {
        $order = array('database' => 0, 'mu-plugins' => 1, 'plugins' => 2, 'themes' => 3, 'uploads' => 4, 'media' => 4, 'others' => 5, 'content' => 5);
        $a_ord = $order[$a] ?? 5;
        $b_ord = $order[$b] ?? 5;
        return $a_ord - $b_ord;
    }

    private function restore_v2($manifest, $restore_dir, $restore_types) {
        $components = $manifest['components'];

        if ($restore_types) {
            $ui_to_manifest = array('uploads' => 'media', 'others' => 'content');
            $manifest_to_ui = array('media' => 'uploads', 'content' => 'others');
            $wanted = array();
            foreach ($restore_types as $rt) {
                $wanted[] = $rt;
                if (isset($ui_to_manifest[$rt])) $wanted[] = $ui_to_manifest[$rt];
                if (isset($manifest_to_ui[$rt])) $wanted[] = $manifest_to_ui[$rt];
            }
            $components = array_intersect_key($components, array_flip($wanted));
        }

        uksort($components, array(__CLASS__, 'sort_restore_entities'));
        Azure_Logger::info('Restore: Component order: ' . implode(' → ', array_keys($components)), 'Backup');

        $total_blobs = 0;
        foreach ($components as $entity => $info) {
            $count = count($info['files'] ?? array());
            $total_blobs += $count;
            Azure_Logger::info("Restore: Component '{$entity}' has {$count} file(s) in manifest", 'Backup');
        }

        if ($total_blobs === 0) {
            $keys = implode(', ', array_keys($components));
            Azure_Logger::error("Restore: Manifest has components ({$keys}) but none contain any files. The backup may have had archive failures.", 'Backup');
            throw new Exception('This backup contains no restorable files. The backup may have been incomplete — check System Logs for details.');
        }

        Azure_Logger::info("Restore: Processing {$total_blobs} blob(s) across " . count($components) . " component(s)", 'Backup');

        $current_siteurl = get_option('siteurl');
        $current_home = get_option('home');
        $total = count($components);
        $done = 0;
        $restored_blobs = 0;

        foreach ($components as $entity => $info) {
            $pct = 10 + intval(80 * $done / max($total, 1));
            $this->update_progress($pct, 'running', "Restoring {$entity}...");
            $this->wizard_log(ucfirst($entity), 'heading');

            $blobs = $info['files'] ?? array();
            if (empty($blobs)) {
                Azure_Logger::warning("Restore: Skipping '{$entity}' — no files in manifest for this component", 'Backup');
                $this->wizard_log("Skipping {$entity} — no files in backup", 'warning');
                $done++;
                continue;
            }

            foreach ($blobs as $blob) {
                $local = $restore_dir . '/' . basename($blob);
                $this->wizard_log("Downloading " . basename($blob) . "...");
                Azure_Logger::info("Restore: Downloading {$blob}", 'Backup');
                $this->storage->download_backup($blob, $local);

                $size = file_exists($local) ? size_format(filesize($local)) : '0 B';
                $this->wizard_log("Downloaded {$size} — applying...");

                $this->apply_component($entity, $local, $restore_dir, $current_siteurl, $current_home);
                @unlink($local);
                $restored_blobs++;
            }

            Azure_Logger::info("Restore: Component '{$entity}' restored successfully (" . count($blobs) . " file(s))", 'Backup');
            $this->wizard_log(ucfirst($entity) . " restored (" . count($blobs) . " file(s))", 'success');
            $done++;
        }

        Azure_Logger::info("Restore: v2 restore complete — {$restored_blobs} blob(s) restored", 'Backup');
        $this->wizard_log("Restore complete — {$restored_blobs} archive(s) processed", 'success');
    }

    /**
     * v1 restore: single zip containing all components (legacy format).
     */
    private function restore_v1($blob_name, $restore_dir, $restore_types) {
        $this->update_progress(10, 'running', 'Downloading backup archive...');
        $archive = $restore_dir . '/backup.zip';
        $this->storage->download_backup($blob_name, $archive);

        $this->update_progress(30, 'running', 'Extracting archive...');
        $extract_dir = $restore_dir . '/extracted';
        $this->extract_zip($archive, $extract_dir);
        @unlink($archive);

        $types = $this->detect_types($extract_dir);
        if ($restore_types) {
            $types = array_intersect($types, $restore_types);
        }

        usort($types, function ($a, $b) {
            return self::sort_restore_entities($a, $b);
        });

        $current_siteurl = get_option('siteurl');
        $current_home = get_option('home');
        $total = count($types);

        foreach ($types as $i => $type) {
            $pct = 40 + intval(50 * $i / max($total, 1));
            $this->update_progress($pct, 'running', "Restoring {$type}...");
            $this->apply_v1_component($type, $extract_dir, $current_siteurl, $current_home);
        }
    }

    // ------------------------------------------------------------------
    // Component applicators
    // ------------------------------------------------------------------

    private function apply_component($entity, $local_path, $restore_dir, $siteurl, $home) {
        $ext = pathinfo($local_path, PATHINFO_EXTENSION);
        $extract = $restore_dir . '/comp_' . $entity . '_' . uniqid();
        wp_mkdir_p($extract);

        Azure_Logger::info("Restore: Applying component '{$entity}' from " . basename($local_path) . " (size: " . size_format(filesize($local_path)) . ")", 'Backup');

        if ($ext === 'gz' && strpos(basename($local_path), '-db.') !== false) {
            $this->restore_database_gz($local_path, $siteurl, $home);
            return;
        }

        $file_count = $this->extract_zip($local_path, $extract);
        Azure_Logger::info("Restore: Extracted {$file_count} entries from " . basename($local_path), 'Backup');

        if ($file_count === 0) {
            Azure_Logger::warning("Restore: Archive " . basename($local_path) . " was empty — nothing to apply for '{$entity}'", 'Backup');
            $this->remove_directory($extract);
            return;
        }

        $dest = null;
        switch ($entity) {
            case 'database':
                $sql_files = glob($extract . '/*.sql');
                if (!empty($sql_files)) {
                    $this->restore_database_sql($sql_files[0], $siteurl, $home);
                } else {
                    Azure_Logger::warning("Restore: No .sql files found in database archive", 'Backup');
                }
                break;
            case 'mu-plugins':
                $dest = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
                wp_mkdir_p($dest);
                break;
            case 'plugins':
                $dest = WP_PLUGIN_DIR;
                break;
            case 'themes':
                $dest = get_theme_root();
                break;
            case 'uploads':
            case 'media':
                $uploads = wp_upload_dir();
                $dest = $uploads['basedir'];
                break;
            case 'others':
            case 'content':
                $dest = WP_CONTENT_DIR;
                break;
            default:
                Azure_Logger::warning("Restore: Unknown entity type '{$entity}' — skipping", 'Backup');
                break;
        }

        if ($dest) {
            $copied = $this->overlay_directory($extract, $dest);
            Azure_Logger::info("Restore: Overlaid {$copied} file(s) into {$dest} for '{$entity}'", 'Backup');
        }

        $this->remove_directory($extract);
    }

    private function apply_v1_component($type, $extract_dir, $siteurl, $home) {
            switch ($type) {
                case 'database':
                $sql_files = glob($extract_dir . '/database_*.sql');
                if (!empty($sql_files)) {
                    $this->restore_database_sql($sql_files[0], $siteurl, $home);
                }
                    break;
                case 'content':
                $dirs = glob($extract_dir . '/content_*', GLOB_ONLYDIR);
                if (!empty($dirs)) $this->overlay_directory($dirs[0], WP_CONTENT_DIR);
                    break;
                case 'media':
                $dirs = glob($extract_dir . '/media_*', GLOB_ONLYDIR);
                if (!empty($dirs)) {
                    $uploads = wp_upload_dir();
                    $this->overlay_directory($dirs[0], $uploads['basedir']);
                }
                break;
            case 'mu-plugins':
                $dirs = glob($extract_dir . '/mu-plugins_*', GLOB_ONLYDIR);
                if (!empty($dirs)) {
                    $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
                    wp_mkdir_p($mu_dir);
                    $this->overlay_directory($dirs[0], $mu_dir);
                }
                    break;
                case 'plugins':
                $dirs = glob($extract_dir . '/plugins_*', GLOB_ONLYDIR);
                if (!empty($dirs)) $this->overlay_directory($dirs[0], WP_PLUGIN_DIR);
                    break;
                case 'themes':
                $dirs = glob($extract_dir . '/themes_*', GLOB_ONLYDIR);
                if (!empty($dirs)) $this->overlay_directory($dirs[0], get_theme_root());
                    break;
            }
        }
        
    // ------------------------------------------------------------------
    // Database restore (smart, with reconnect and statement awareness)
    // ------------------------------------------------------------------

    /**
     * Restore a gzipped SQL dump (v2 format).
     */
    private function restore_database_gz($gz_path, $current_siteurl, $current_home) {
        $gz = gzopen($gz_path, 'rb');
        if (!$gz) throw new Exception('Cannot open database backup.');

        $this->run_sql_stream($gz, 'gzgets', $current_siteurl, $current_home);
        gzclose($gz);
    }

    /**
     * Restore a plain SQL dump (v1 format).
     */
    private function restore_database_sql($sql_path, $current_siteurl, $current_home) {
        $fh = fopen($sql_path, 'r');
        if (!$fh) throw new Exception('Cannot open database backup file.');

        $this->run_sql_stream($fh, 'fgets', $current_siteurl, $current_home);
        fclose($fh);
    }

    /**
     * Stream SQL statements from a handle and execute via raw mysqli.
     * Features: statement-type awareness, auto-reconnect, INSERT IGNORE fallback.
     */
    private function run_sql_stream($handle, $read_fn, $current_siteurl, $current_home) {
        global $wpdb;
        $mysqli = $wpdb->dbh;
        if (!($mysqli instanceof mysqli)) {
            throw new Exception('Cannot access raw database connection.');
        }

        $buffer = '';
        $executed = 0;
        $errors = 0;
        $last_table = '';
        @set_time_limit(0);

        while (($line = $read_fn($handle)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '#') === 0) {
                continue;
            }

            $buffer .= $line;
            if (substr($trimmed, -1) !== ';') {
                continue;
            }
            
            $query = trim($buffer);
            $buffer = '';
            if (empty($query)) continue;

            // Statement-type awareness
            $upper = strtoupper(substr($query, 0, 20));

            // Track current table for progress
            if (strpos($upper, 'DROP TABLE') === 0 || strpos($upper, 'CREATE TABLE') === 0) {
                if (preg_match('/`([^`]+)`/', $query, $m)) {
                    if ($m[1] !== $last_table) {
                        $last_table = $m[1];
                        $this->update_progress(null, 'running', "Restoring table: {$last_table}");
                        $this->wizard_log("Processing table: {$last_table}");
                    }
                }
            }

            // Execute with auto-reconnect
            $result = $this->execute_sql($mysqli, $query);
            $executed++;
            
            if ($result === false) {
                $err = mysqli_error($mysqli);

                // Auto-reconnect on "server has gone away"
                if (stripos($err, 'server has gone away') !== false || stripos($err, 'Lost connection') !== false) {
                    Azure_Logger::warning("Restore: Lost DB connection, reconnecting...", 'Backup');
                    if (mysqli_ping($mysqli) || $wpdb->db_connect(false)) {
                        $result = $this->execute_sql($mysqli, $query);
                        if ($result !== false) continue;
                    }
                }

                // Try INSERT IGNORE for duplicate key errors
                if (strpos($upper, 'INSERT') === 0 && (stripos($err, 'Duplicate entry') !== false || mysqli_errno($mysqli) === 1062)) {
                    $ignore_query = preg_replace('/^INSERT\s+/i', 'INSERT IGNORE ', $query, 1);
                    $this->execute_sql($mysqli, $ignore_query);
                    continue;
                }

                $errors++;
                if ($errors <= 50) {
                    Azure_Logger::warning("Restore: SQL error #{$executed}: " . substr($err, 0, 200), 'Backup');
                }
            }

            if ($executed % 200 === 0) {
                @set_time_limit(120);
            }
        }

        // Flush remaining buffer
        if (!empty(trim($buffer))) {
            $this->execute_sql($mysqli, trim($buffer));
            $executed++;
        }

        // URL replacement after DB restore
        $this->do_url_replacement($mysqli, $current_siteurl, $current_home);

        // Post-DB-restore fixups: mark wizard complete and preserve storage credentials
        $this->post_db_restore_fixups($mysqli);

        wp_cache_flush();
        Azure_Logger::info("Restore: DB restored - {$executed} queries, {$errors} errors", 'Backup');
    }

    private function execute_sql($mysqli, $query) {
        // Check max_allowed_packet
        $len = strlen($query);
        if ($len > 16 * 1024 * 1024) {
            Azure_Logger::warning("Restore: Skipping oversized query ({$len} bytes)", 'Backup');
            return true;
        }
        return mysqli_query($mysqli, $query);
    }

    /**
     * Replace source site URLs with current site URLs in key tables.
     */
    private function do_url_replacement($mysqli, $current_siteurl, $current_home) {
        global $wpdb;

        $row = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'siteurl' LIMIT 1"));
        $source_url = $row ? $row['option_value'] : '';

        $esc_current = mysqli_real_escape_string($mysqli, $current_siteurl);
        $esc_home = mysqli_real_escape_string($mysqli, $current_home);

        // Restore this site's identity
        mysqli_query($mysqli, "UPDATE {$wpdb->options} SET option_value = '{$esc_current}' WHERE option_name = 'siteurl'");
        mysqli_query($mysqli, "UPDATE {$wpdb->options} SET option_value = '{$esc_home}' WHERE option_name = 'home'");

        if ($source_url && $source_url !== $current_siteurl) {
            Azure_Logger::info("Restore: Replacing URLs: {$source_url} -> {$current_siteurl}", 'Backup');
            $this->wizard_log("Migration detected: {$source_url} → {$current_siteurl}");
            $this->wizard_log("Running search and replace on database...");
            $esc_source = mysqli_real_escape_string($mysqli, $source_url);

            $replacements = array(
                $wpdb->posts    => array('post_content', 'post_excerpt', 'guid'),
                $wpdb->postmeta => array('meta_value'),
                $wpdb->options  => array('option_value'),
            );

            foreach ($replacements as $tbl => $cols) {
                foreach ($cols as $col) {
                    $sql = "UPDATE `{$tbl}` SET `{$col}` = REPLACE(`{$col}`, '{$esc_source}', '{$esc_current}') WHERE `{$col}` LIKE '%{$esc_source}%'";
                    mysqli_query($mysqli, $sql);
                    $affected = mysqli_affected_rows($mysqli);
                    if ($affected > 0) {
                        Azure_Logger::info("Restore: Replaced URLs in {$tbl}.{$col}: {$affected} rows", 'Backup');
                    }
                }
            }
        }
    }

    /**
     * After DB restore: mark setup wizard complete, preserve Azure Storage
     * credentials, and set a flag so the plugin knows a restore occurred.
     */
    private function post_db_restore_fixups($mysqli) {
        global $wpdb;

        // 1. Mark that a restore just completed (survives the DB swap)
        update_option('azure_restore_completed', gmdate('c'), false);

        // 2. Ensure setup wizard is marked completed in the restored settings
        $row = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'azure_plugin_settings' LIMIT 1"));

        if ($row) {
            $settings = maybe_unserialize($row['option_value']);
            if (!is_array($settings)) {
                $settings = json_decode($row['option_value'], true);
            }
            if (is_array($settings)) {
                $settings['setup_wizard_completed'] = true;

                // 3. Re-inject the current site's Azure Storage credentials
                //    so the restored site can still access the same backup storage
                foreach ($this->pre_restore_credentials as $k => $v) {
                    $settings[$k] = $v;
                }

                $encoded = mysqli_real_escape_string($mysqli, maybe_serialize($settings));
                mysqli_query($mysqli,
                    "UPDATE {$wpdb->options} SET option_value = '{$encoded}' WHERE option_name = 'azure_plugin_settings'");

                $restored_keys = implode(', ', array_keys($this->pre_restore_credentials));
                Azure_Logger::info("Restore: Post-DB fixups applied — wizard marked complete, storage credentials preserved ({$restored_keys})", 'Backup');
            }
        }
    }

    // ------------------------------------------------------------------
    // Remote backup listing
    // ------------------------------------------------------------------

    public function ajax_list_remote_backups() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_die('Unauthorized');
        }

        if (!$this->storage) { wp_send_json_error('Storage not configured.'); return; }

        try {
            $blobs = $this->storage->list_backups(200);

            // Group blobs by backup set (detect manifests)
            $backup_sets = $this->group_blobs_into_sets($blobs);

            // Check which are in local DB
            $known = array();
            if (class_exists('Azure_Database')) {
                global $wpdb;
                $table = Azure_Database::get_table_name('backup_jobs');
                if ($table) {
                    $rows = $wpdb->get_col("SELECT azure_blob_name FROM {$table} WHERE azure_blob_name IS NOT NULL AND azure_blob_name != ''");
                    $known = array_flip($rows);
                }
            }

            foreach ($backup_sets as &$set) {
                $set['in_local_db'] = isset($known[$set['name']]);
            }

            wp_send_json_success($backup_sets);
        } catch (Exception $e) {
            wp_send_json_error('Failed: ' . $e->getMessage());
        }
    }

    /**
     * Group raw blobs into logical backup sets.
     * v2 backups have manifests; v1 backups are single .zip files.
     */
    private function group_blobs_into_sets($blobs) {
        $sets = array();
        $manifests = array();
        $standalone = array();

        foreach ($blobs as $blob) {
            if (strpos($blob['name'], '-manifest.json') !== false) {
                $prefix = dirname($blob['name']);
                $manifests[$prefix] = $blob;
            } else {
                $standalone[] = $blob;
            }
        }

        // v2 sets (manifest-based)
        foreach ($manifests as $prefix => $blob) {
            $total_size = 0;
            $file_count = 0;
            foreach ($standalone as $s) {
                if (strpos($s['name'], $prefix . '/') === 0) {
                    $total_size += $s['size'];
                    $file_count++;
                }
            }

            $sets[] = array(
                'name'       => $blob['name'],
                'size'       => $total_size,
                'modified'   => $blob['modified'],
                'type'       => 'v2',
                'components' => $file_count,
                'url'        => '',
            );
        }

        // v1 sets (standalone zips not part of a v2 set)
        $v2_prefixes = array_keys($manifests);
        foreach ($standalone as $blob) {
            $in_v2 = false;
            foreach ($v2_prefixes as $p) {
                if (strpos($blob['name'], $p . '/') === 0) { $in_v2 = true; break; }
            }
            if (!$in_v2 && preg_match('/\.zip$/i', $blob['name'])) {
                $sets[] = array(
                    'name'     => $blob['name'],
                    'size'     => $blob['size'],
                    'modified' => $blob['modified'],
                    'type'     => 'v1',
                    'url'      => '',
                );
            }
        }

        // Sort by date descending
        usort($sets, function ($a, $b) {
            return strtotime($b['modified']) - strtotime($a['modified']);
        });

        return $sets;
    }

    // ------------------------------------------------------------------
    // File operations
    // ------------------------------------------------------------------

    /**
     * @return int Number of entries extracted.
     */
    private function extract_zip($archive, $dir) {
        if (!class_exists('ZipArchive')) throw new Exception('ZipArchive not available.');
        wp_mkdir_p($dir);

        $zip = new ZipArchive();
        $result = $zip->open($archive);
        if ($result !== true) {
            throw new Exception('Cannot open archive ' . basename($archive) . ' (error code: ' . $result . ')');
        }

        $num = $zip->numFiles;
        if ($num === 0) {
            $zip->close();
            return 0;
        }

        $ok = $zip->extractTo($dir);
        $zip->close();

        if (!$ok) {
            throw new Exception('Failed to extract archive ' . basename($archive));
        }

        return $num;
    }

    /**
     * @return int Number of files copied.
     */
    private function overlay_directory($source, $dest) {
        if (!is_dir($source)) {
            Azure_Logger::warning("Restore: overlay source does not exist: {$source}", 'Backup');
            return 0;
        }
        
        wp_mkdir_p($dest);
        
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        $copied = 0;
        $failed = 0;

        foreach ($iter as $item) {
            $rel = str_replace($source, '', $item->getPathname());
            $target = $dest . $rel;
            
            if ($item->isDir()) {
                wp_mkdir_p($target);
            } elseif ($item->isFile()) {
                wp_mkdir_p(dirname($target));
                if (copy($item->getPathname(), $target)) {
                    $copied++;
                } else {
                    $failed++;
                    if ($failed <= 5) {
                        Azure_Logger::warning("Restore: Failed to copy to {$target}", 'Backup');
                    }
                }
            }
        }

        if ($failed > 0) {
            Azure_Logger::warning("Restore: {$failed} file(s) failed to copy to {$dest}", 'Backup');
        }

        return $copied;
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

    private function detect_types($dir) {
        $types = array();
        if (!empty(glob($dir . '/database_*.sql'))) $types[] = 'database';
        if (!empty(glob($dir . '/content_*', GLOB_ONLYDIR))) $types[] = 'content';
        if (!empty(glob($dir . '/media_*', GLOB_ONLYDIR))) $types[] = 'media';
        if (!empty(glob($dir . '/plugins_*', GLOB_ONLYDIR))) $types[] = 'plugins';
        if (!empty(glob($dir . '/themes_*', GLOB_ONLYDIR))) $types[] = 'themes';
        return $types;
    }

    private function clear_caches() {
        wp_cache_flush();
        if (function_exists('opcache_reset')) opcache_reset();
        if (function_exists('wp_cache_clear_cache')) wp_cache_clear_cache();
        if (function_exists('w3tc_flush_all')) w3tc_flush_all();
        if (function_exists('rocket_clean_domain')) rocket_clean_domain();
        Azure_Logger::info('Restore: Caches cleared', 'Backup');
    }

    // ------------------------------------------------------------------
    // Delete backup
    // ------------------------------------------------------------------

    /**
     * Delete a backup job and its Azure blobs (supports both v1 and v2).
     */
    public function delete_backup($backup_id) {
        global $wpdb;
        $table = Azure_Database::get_table_name('backup_jobs');
        if (!$table) {
            return array('success' => false, 'message' => 'Database table not found');
        }

        $backup = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $backup_id));
        if (!$backup) {
            return array('success' => false, 'message' => 'Backup not found');
        }

        $deleted_blobs = 0;

        if ($this->storage && !empty($backup->azure_blob_name)) {
            try {
                // Check if this is a v2 backup (manifest-based)
                $entity_state = json_decode($backup->entity_state ?? '{}', true);

                if (!empty($entity_state)) {
                    // v2: delete all component blobs listed in entity_state
                    foreach ($entity_state as $entity => $state) {
                        foreach ($state['files'] ?? array() as $blob) {
                            try {
                                $this->storage->delete_backup($blob);
                                $deleted_blobs++;
                            } catch (Exception $e) {
                                Azure_Logger::warning("Delete: Failed to delete blob {$blob}: " . $e->getMessage(), 'Backup');
                            }
                        }
                    }
                    // Also delete the manifest blob
                    try {
                        $this->storage->delete_backup($backup->azure_blob_name);
                        $deleted_blobs++;
                    } catch (Exception $e) {
                        // Manifest may not exist
                    }
                } else {
                    // v1: single blob
                    $this->storage->delete_backup($backup->azure_blob_name);
                    $deleted_blobs++;
                }
        } catch (Exception $e) {
                Azure_Logger::warning('Delete: Azure blob deletion error: ' . $e->getMessage(), 'Backup');
            }
        }

        // Delete the DB record
        $wpdb->delete($table, array('id' => $backup_id), array('%d'));

        Azure_Logger::info("Delete: Backup #{$backup_id} removed ({$deleted_blobs} blobs deleted)", 'Backup');

        return array('success' => true, 'message' => "Backup deleted ({$deleted_blobs} blob(s) removed from Azure)");
    }

    // Misc
    public function ajax_get_restore_status() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) wp_die('Unauthorized');
        wp_send_json_success(array('in_progress' => self::$restore_in_progress));
    }

    public static function is_restore_in_progress() {
        return self::$restore_in_progress;
    }
}
