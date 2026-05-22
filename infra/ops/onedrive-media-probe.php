<?php
/**
 * Plugin Name: One-off — OneDrive Media probe
 * Description: Check if OneDrive Media integration is configured + sync state.
 */
if (!defined('ABSPATH')) return;

add_action('init', function () {
    if (empty($_GET['pta_odm_probe'])) return;
    if (!hash_equals('d4d4306c0c8ddc80312c3554aefbedbd', (string) $_GET['pta_odm_probe'])) {
        status_header(403);
        header('Content-Type: text/plain');
        echo 'forbidden';
        exit;
    }

    $azure_plugin_path = defined('AZURE_PLUGIN_PATH')
        ? AZURE_PLUGIN_PATH
        : ABSPATH . 'wp-content/plugins/Azure Plugin/';
    foreach (array(
        'includes/class-onedrive-media-auth.php',
        'includes/class-onedrive-media-graph-api.php',
        'includes/class-onedrive-media-manager.php',
    ) as $f) {
        $p = $azure_plugin_path . $f;
        if (file_exists($p)) require_once $p;
    }

    $out = array('time' => current_time('mysql'));

    // Settings
    $setting_keys = array(
        'enable_onedrive_media',
        'onedrive_media_tenant_id',
        'onedrive_media_client_id',
        'onedrive_media_client_secret',
        'onedrive_media_drive_id',
        'onedrive_media_folder_path',
        'onedrive_media_auto_sync',
        'onedrive_media_sync_on_upload',
        'onedrive_media_last_sync',
    );
    $out['settings'] = array();
    foreach ($setting_keys as $k) {
        $v = Azure_Settings::get_setting($k, null);
        if (in_array($k, array('onedrive_media_client_secret'), true) && !empty($v)) {
            $v = '(set, length=' . strlen((string) $v) . ')';
        }
        $out['settings'][$k] = $v;
    }

    // Classes loaded?
    $out['classes'] = array(
        'Azure_OneDrive_Media_Auth'       => class_exists('Azure_OneDrive_Media_Auth'),
        'Azure_OneDrive_Media_Graph_API'  => class_exists('Azure_OneDrive_Media_Graph_API'),
        'Azure_OneDrive_Media_Manager'    => class_exists('Azure_OneDrive_Media_Manager'),
    );

    // Sync state from DB tables
    if (class_exists('Azure_Database')) {
        global $wpdb;
        foreach (array('onedrive_files', 'onedrive_sync_queue', 'onedrive_tokens') as $tname) {
            $table = Azure_Database::get_table_name($tname);
            $exists = $table && $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
            $out['db_tables'][$tname] = array(
                'name'   => $table,
                'exists' => (bool) $exists,
                'rows'   => $exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`") : null,
            );
            if ($exists && $tname === 'onedrive_files') {
                $out['db_tables'][$tname]['sample'] = $wpdb->get_results(
                    "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT 5",
                    ARRAY_A
                );
            }
        }
    }

    // Try to query the actual OneDrive (if auth + class loaded)
    if (class_exists('Azure_OneDrive_Media_Manager')) {
        try {
            $manager = Azure_OneDrive_Media_Manager::get_instance();
            if (method_exists($manager, 'get_sync_stats')) {
                $out['sync_stats'] = $manager->get_sync_stats();
            }
        } catch (\Throwable $e) {
            $out['sync_stats_error'] = $e->getMessage();
        }
    }

    nocache_headers();
    header('Content-Type: application/json');
    echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
});
