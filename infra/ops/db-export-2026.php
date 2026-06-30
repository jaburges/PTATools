<?php
/**
 * Plugin Name: One-off — DB-only logical export for storage downsize (2026)
 * Description: Token-gated. Triggers a DATABASE-ONLY backup (mysqldump-first,
 *              PHP $wpdb chunked fallback) via the existing PTA Tools backup
 *              engine, which gzip-compresses the dump and uploads it to the
 *              configured Azure Blob backup storage. Read-only against the
 *              live DB (no writes/schema changes/DROP on the source).
 *
 * Modes (GET, all require ?pta_db_export=<TOKEN>):
 *   (default)            Read-only probe: logical DB size, table count,
 *                        backup storage config (masked), recent backup jobs.
 *   &trigger=db          Create a DATABASE-only backup job and schedule it
 *                        on the WP-Cron background chain. Returns backup_id.
 *   &status=<backup_id>  Return the backup_jobs row for that id (progress,
 *                        status, blob name, file size, validation).
 *
 * Deploy to wp-content/mu-plugins/db-export-2026.php via Kudu VFS.
 * Delete after use. Does NOT self-delete.
 */
if (!defined('ABSPATH')) {
    return;
}

add_action('init', function () {
    if (empty($_GET['pta_db_export'])) {
        return;
    }
    $expected = '297050ead174e489b61e46b4a4fe1cd5';
    if (!hash_equals($expected, (string) $_GET['pta_db_export'])) {
        status_header(403);
        nocache_headers();
        header('Content-Type: text/plain');
        echo 'forbidden';
        exit;
    }

    $azure_plugin_path = defined('AZURE_PLUGIN_PATH')
        ? AZURE_PLUGIN_PATH
        : ABSPATH . 'wp-content/plugins/Azure Plugin/';
    foreach (array(
        'includes/class-database.php',
        'includes/class-backup-engine.php',
        'includes/class-backup-azure-storage.php',
        'includes/class-backup.php',
        'includes/class-backup-scheduler.php',
    ) as $f) {
        $p = $azure_plugin_path . $f;
        if (file_exists($p)) {
            require_once $p;
        }
    }

    global $wpdb;
    $out = array('time' => current_time('mysql'));

    // ── Logical DB size (read-only) ─────────────────────────────────
    $db_name = defined('DB_NAME') ? DB_NAME : '';
    $out['database'] = array('name_masked' => _mask_tail($db_name));
    if ($db_name) {
        $sizes = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS table_count,
                    SUM(data_length) AS data_bytes,
                    SUM(index_length) AS index_bytes,
                    SUM(data_length + index_length) AS total_bytes,
                    SUM(data_free) AS free_bytes
             FROM information_schema.tables
             WHERE table_schema = %s",
            $db_name
        ), ARRAY_A);
        if ($sizes) {
            $out['database']['table_count']      = (int) $sizes['table_count'];
            $out['database']['data_bytes']       = (int) $sizes['data_bytes'];
            $out['database']['index_bytes']      = (int) $sizes['index_bytes'];
            $out['database']['total_bytes']      = (int) $sizes['total_bytes'];
            $out['database']['free_bytes']       = (int) $sizes['free_bytes'];
            $out['database']['total_human']      = size_format((int) $sizes['total_bytes'], 2);
        }
        $out['database']['mysql_version'] = $wpdb->get_var('SELECT VERSION()');
        $out['database']['charset']       = defined('DB_CHARSET') ? DB_CHARSET : '';
        $out['database']['table_prefix']  = $wpdb->prefix;
    }

    // ── Backup storage config (masked) ──────────────────────────────
    $sa  = Azure_Settings::get_setting('backup_storage_account_name', '')
         ?: Azure_Settings::get_setting('backup_storage_account', '');
    $cn  = Azure_Settings::get_setting('backup_storage_container_name', '')
         ?: Azure_Settings::get_setting('backup_container_name', '');
    $key = Azure_Settings::get_setting('backup_storage_account_key', '')
         ?: Azure_Settings::get_setting('backup_storage_key', '');
    $out['backup_storage'] = array(
        'account'       => $sa,
        'container'     => $cn ?: 'wordpress-backups',
        'account_key_set' => !empty($key),
        'mysqldump_on_path' => _mysqldump_present(),
    );

    // ── Recent backup jobs ──────────────────────────────────────────
    if (class_exists('Azure_Database')) {
        $table = Azure_Database::get_table_name('backup_jobs');
        if ($table) {
            $out['recent_jobs'] = $wpdb->get_results(
                "SELECT id, backup_id, status, progress, message, file_size,
                        azure_blob_name, started_at, completed_at, error_message
                 FROM {$table} ORDER BY id DESC LIMIT 6"
            ) ?: array();
        }
    }

    // ── TRIGGER: DATABASE-only backup job ───────────────────────────
    if (!empty($_GET['trigger']) && $_GET['trigger'] === 'db') {
        if (!class_exists('Azure_Backup')) {
            $out['trigger'] = array('error' => 'Azure_Backup class not loaded');
        } else {
            try {
                $backup    = new Azure_Backup();
                $types     = array('database');
                $name      = 'DB-only export for storage downsize (' . current_time('mysql') . ')';
                $backup_id = 'backup_' . time() . '_' . wp_generate_password(8, false);

                $ref = new ReflectionClass($backup);
                $m   = $ref->getMethod('create_backup_job');
                $m->setAccessible(true);
                $job_id = $m->invokeArgs($backup, array($backup_id, $name, $types, false));

                if ($job_id) {
                    $r = $ref->getMethod('schedule_next_resume');
                    $r->setAccessible(true);
                    $r->invokeArgs($backup, array($backup_id, 1));
                    $out['trigger'] = array(
                        'ok'        => true,
                        'backup_id' => $backup_id,
                        'job_id'    => (int) $job_id,
                        'types'     => $types,
                        'note'      => 'DB-only job queued on WP-Cron chain. Poll with &status=' . $backup_id,
                    );
                    if (class_exists('Azure_Logger')) {
                        Azure_Logger::info('[db-export-2026] triggered ' . $backup_id, 'Backup');
                    }
                } else {
                    $out['trigger'] = array('error' => 'create_backup_job returned falsy');
                }
            } catch (\Throwable $e) {
                $out['trigger'] = array('error' => $e->getMessage(), 'line' => $e->getLine());
            }
        }
    }

    // ── STATUS: poll a specific backup_id ───────────────────────────
    if (!empty($_GET['status']) && class_exists('Azure_Database')) {
        $sid   = sanitize_text_field((string) $_GET['status']);
        $table = Azure_Database::get_table_name('backup_jobs');
        if ($table) {
            $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE backup_id = %s", $sid));
            if ($job) {
                $entity_state = json_decode($job->entity_state ?? '{}', true);
                $out['status'] = array(
                    'backup_id'       => $job->backup_id,
                    'status'          => $job->status,
                    'progress'        => (int) $job->progress,
                    'message'         => $job->message,
                    'file_size'       => (int) $job->file_size,
                    'file_size_human' => $job->file_size ? size_format((int) $job->file_size, 2) : null,
                    'azure_blob_name' => $job->azure_blob_name,
                    'db_files'        => $entity_state['database']['files'] ?? array(),
                    'db_sizes'        => $entity_state['database']['sizes'] ?? array(),
                    'validation'      => $entity_state['_validation'] ?? null,
                    'started_at'      => $job->started_at,
                    'completed_at'    => $job->completed_at,
                    'error_message'   => $job->error_message,
                );
            } else {
                $out['status'] = array('error' => 'no job with that backup_id');
            }
        }
    }

    nocache_headers();
    header('Content-Type: application/json');
    echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
});

function _mask_tail($s) {
    $s = (string) $s;
    if (strlen($s) <= 6) return str_repeat('*', strlen($s));
    return substr($s, 0, 4) . str_repeat('*', max(0, strlen($s) - 8)) . substr($s, -4);
}

function _mysqldump_present() {
    foreach (array('mysqldump', '/usr/bin/mysqldump', '/usr/local/bin/mysqldump') as $c) {
        $t = @shell_exec(escapeshellarg($c) . ' --version 2>&1');
        if ($t && stripos($t, 'mysqldump') !== false) return $c;
    }
    return false;
}
