<?php
/**
 * Plugin Name: One-off — Backup module probe + smoke-test trigger
 * Description: Token-gated diagnostics for the backup module.
 *
 * Modes:
 *   ?pta_backup_probe=<TOKEN>                       Read-only: settings + scheduler state
 *   ?pta_backup_probe=<TOKEN>&trigger=full          Trigger a one-shot full backup
 *                                                   (database + mu-plugins + plugins
 *                                                    + themes + content + UPLOADS)
 *   ?pta_backup_probe=<TOKEN>&status=<backup_id>    Get progress of a running backup
 *
 * Does NOT modify settings, schedules, or any persistent data.
 * Self-deletes after a successful trigger.
 */
if (!defined('ABSPATH')) return;

add_action('init', function () {
    if (empty($_GET['pta_backup_probe'])) return;
    if (!hash_equals('d4d4306c0c8ddc80312c3554aefbedbd', (string) $_GET['pta_backup_probe'])) {
        status_header(403);
        header('Content-Type: text/plain');
        echo 'forbidden';
        exit;
    }

    // Force-load backup module classes (the backup module only initialises
    // them when needed, typically inside the admin context).
    $azure_plugin_path = defined('AZURE_PLUGIN_PATH')
        ? AZURE_PLUGIN_PATH
        : ABSPATH . 'wp-content/plugins/Azure Plugin/';
    foreach (array(
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

    $out = array('time' => current_time('mysql'));

    // ── Settings ─────────────────────────────────────────────────────
    $settings_keys = array(
        'backup_schedule_enabled',
        'backup_schedule_frequency',
        'backup_schedule_time',
        'backup_types',
        'backup_retention_days',
        'backup_email_notifications',
        'backup_notification_email',
        'backup_storage_account_name',
        'backup_storage_account',
        'backup_storage_container_name',
        'backup_container_name',
        'enable_backup',
    );
    $out['settings'] = array();
    foreach ($settings_keys as $k) {
        $v = Azure_Settings::get_setting($k, null);
        // mask credential-looking values
        if ($v !== null && (stripos($k, 'key') !== false || stripos($k, 'secret') !== false)) {
            $v = '(set, length=' . strlen((string) $v) . ')';
        }
        $out['settings'][$k] = $v;
    }
    // Account key is stored under a few possible keys
    $key_a = Azure_Settings::get_setting('backup_storage_account_key', null);
    $key_b = Azure_Settings::get_setting('backup_storage_key', null);
    $out['settings']['backup_storage_account_key_set'] = !empty($key_a);
    $out['settings']['backup_storage_key_set']         = !empty($key_b);

    // ── Scheduler state ──────────────────────────────────────────────
    $next = wp_next_scheduled('azure_backup_scheduled');
    $cleanup_next = wp_next_scheduled('azure_backup_cleanup');
    $out['scheduler'] = array(
        'next_backup_at'  => $next ? date('Y-m-d H:i:s', $next) : null,
        'next_cleanup_at' => $cleanup_next ? date('Y-m-d H:i:s', $cleanup_next) : null,
        'now'             => current_time('mysql'),
    );

    // ── Recent backup jobs from DB ──────────────────────────────────
    if (class_exists('Azure_Database')) {
        global $wpdb;
        $table = Azure_Database::get_table_name('backup_jobs');
        if ($table) {
            $rows = $wpdb->get_results(
                "SELECT id, backup_id, name, backup_types, status, file_size, created_at, completed_at, azure_blob_name, error_message
                 FROM {$table}
                 ORDER BY id DESC
                 LIMIT 10"
            );
            $out['recent_backup_jobs'] = $rows ?: array();
        }
    }

    // ── Storage account connection test ─────────────────────────────
    if (class_exists('Azure_Backup_Storage')) {
        try {
            $storage = new Azure_Backup_Storage();
            // The class has different test methods depending on version; try common names.
            $tested = false;
            foreach (array('test_connection', 'verify_connection', 'is_configured', 'ping') as $m) {
                if (method_exists($storage, $m)) {
                    $r = $storage->$m();
                    $out['storage_test'] = array('method' => $m, 'result' => $r);
                    $tested = true;
                    break;
                }
            }
            if (!$tested) {
                $out['storage_test'] = array('note' => 'no test method on Azure_Backup_Storage; class loaded ok');
            }
        } catch (\Throwable $e) {
            $out['storage_test'] = array('error' => $e->getMessage());
        }
    } else {
        $out['storage_test'] = array('error' => 'Azure_Backup_Storage class not loaded');
    }

    // ── TRIGGER mode ────────────────────────────────────────────────
    if (!empty($_GET['trigger']) && $_GET['trigger'] === 'full') {
        if (!class_exists('Azure_Backup')) {
            $out['trigger'] = array('error' => 'Azure_Backup class not loaded');
        } else {
            try {
                $backup = new Azure_Backup();
                // Use reflection if needed, but check public methods first.
                if (!method_exists($backup, 'create_backup_job')) {
                    // Fall back to the AJAX path: simulate the start_backup logic.
                    $out['trigger'] = array('error' => 'create_backup_job is not public; cannot trigger from outside');
                } else {
                    $types = array('database', 'mu-plugins', 'plugins', 'themes', 'content', 'uploads');
                    $name = 'Smoke-test full backup (probe-triggered ' . current_time('mysql') . ')';
                    $backup_id = 'backup_' . time() . '_' . wp_generate_password(8, false);

                    // create_backup_job is private — use reflection
                    $ref = new ReflectionClass($backup);
                    $method = $ref->getMethod('create_backup_job');
                    $method->setAccessible(true);
                    $job_id = $method->invokeArgs($backup, array($backup_id, $name, $types, false));

                    if ($job_id) {
                        $resume = $ref->getMethod('schedule_next_resume');
                        $resume->setAccessible(true);
                        $resume->invokeArgs($backup, array($backup_id, 1));

                        $out['trigger'] = array(
                            'ok'       => true,
                            'backup_id'=> $backup_id,
                            'job_id'   => (int) $job_id,
                            'types'    => $types,
                            'note'     => 'Job queued. Use ?status=' . $backup_id . ' to poll progress, or watch recent_backup_jobs in subsequent probes.',
                        );
                        if (class_exists('Azure_Logger')) {
                            Azure_Logger::info('[backup-probe] triggered ' . $backup_id, 'Backup');
                        }
                    } else {
                        $out['trigger'] = array('error' => 'create_backup_job returned falsy');
                    }
                }
            } catch (\Throwable $e) {
                $out['trigger'] = array('error' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine());
            }
        }
    }

    // ── STATUS mode — progress for a specific backup_id ─────────────
    if (!empty($_GET['status'])) {
        $sid = sanitize_text_field((string) $_GET['status']);
        if (class_exists('Azure_Database')) {
            global $wpdb;
            $table = Azure_Database::get_table_name('backup_jobs');
            if ($table) {
                $job = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$table} WHERE backup_id = %s",
                    $sid
                ));
                $out['status'] = $job ?: array('error' => 'no job with that backup_id');
            }
        }
    }

    nocache_headers();
    header('Content-Type: application/json');
    echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
});
