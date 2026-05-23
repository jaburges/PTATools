<?php
/**
 * Plugin Name: One-off — pta_calendar_owner / TEC state probe
 */
if (!defined('ABSPATH')) return;

add_action('init', function () {
    if (empty($_GET['pta_tec_state'])) return;
    if (!hash_equals('d4d4306c0c8ddc80312c3554aefbedbd', (string) $_GET['pta_tec_state'])) {
        status_header(403); echo 'forbidden'; exit;
    }

    global $wpdb;
    $out = array('time' => current_time('mysql'));

    // Owner / data-source flags
    $out['flags'] = array(
        'pta_calendar_owner'       => Azure_Settings::get_setting('pta_calendar_owner', '(default: tec)'),
        'pta_calendar_data_source' => Azure_Settings::get_setting('pta_calendar_data_source', '(default: tribe)'),
        'enable_tec_integration'   => Azure_Settings::get_setting('enable_tec_integration', false),
    );

    // Is the TEC plugin even active?
    $out['tec_plugin_active'] = class_exists('Tribe__Events__Main');

    // Counts of each post type
    $counts = array();
    foreach (array('tribe_events', 'tribe_venue', 'tribe_organizer', 'pta_event', 'pta_venue', 'pta_organizer') as $pt) {
        $counts[$pt] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft')",
            $pt
        ));
    }
    $out['post_type_counts'] = $counts;

    // Volunteer signup sheets with tec_event_id set
    $vs_table = $wpdb->prefix . 'azure_volunteer_sheets';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $vs_table)) === $vs_table) {
        $cols = $wpdb->get_col("SHOW COLUMNS FROM `{$vs_table}`");
        $out['volunteer_sheets'] = array(
            'columns'                 => $cols,
            'total'                   => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$vs_table}`"),
            'with_tec_event'          => in_array('tec_event_id', $cols, true)
                ? (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$vs_table}` WHERE tec_event_id IS NOT NULL AND tec_event_id > 0")
                : 'column does not exist',
        );
    }

    // Class products with _class_event_ids (events generated against tribe_events)
    $class_products_with_events = (int) $wpdb->get_var("
        SELECT COUNT(DISTINCT pm.post_id)
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key = '_class_event_ids'
        AND p.post_type = 'product'
        AND p.post_status NOT IN ('trash','auto-draft')
    ");
    $out['class_products_with_generated_events'] = $class_products_with_events;

    // Look at calendar-related tables
    $tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}azure_%'");
    $out['azure_tables'] = $tables;

    // Calendar sync mappings table (if exists)
    foreach (array('tec_mappings', 'tec_calendar_mappings', 'calendar_mappings', 'calendar_sync_mappings', 'pta_calendar_mappings') as $maybe) {
        $t = $wpdb->prefix . 'azure_' . $maybe;
        $t2 = $wpdb->prefix . $maybe;
        foreach (array($t, $t2) as $candidate) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $candidate)) === $candidate) {
                $out['calendar_mapping_tables'][] = array(
                    'name'  => $candidate,
                    'rows'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$candidate}`"),
                );
            }
        }
    }

    nocache_headers();
    header('Content-Type: application/json');
    echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
});
