<?php
/**
 * Plugin Name: One-off — backup_jobs table inspector
 * Description: Dumps the backup_jobs table state — schema + row count +
 *              all rows (for diagnosing why recent_backup_jobs is empty).
 */
if (!defined('ABSPATH')) return;

add_action('init', function () {
    if (empty($_GET['pta_bj_inspect'])) return;
    if (!hash_equals('d4d4306c0c8ddc80312c3554aefbedbd', (string) $_GET['pta_bj_inspect'])) {
        status_header(403);
        header('Content-Type: text/plain');
        echo 'forbidden';
        exit;
    }

    global $wpdb;
    $out = array();

    if (!class_exists('Azure_Database')) {
        $out['error'] = 'Azure_Database not loaded';
    } else {
        $table = Azure_Database::get_table_name('backup_jobs');
        $out['table_name_from_helper'] = $table;
        $out['table_exists'] = (bool) $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $table)
        );
        $out['row_count'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        $out['columns'] = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
        $out['all_rows'] = $wpdb->get_results(
            "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT 20"
        );
    }

    nocache_headers();
    header('Content-Type: application/json');
    echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
});
