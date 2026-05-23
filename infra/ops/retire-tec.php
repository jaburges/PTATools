<?php
/**
 * Plugin Name: One-off — TEC retirement data migration (v3.97)
 *
 * Migrates production from the dual-write TEC + pta_event state to a
 * pta_event-only world. Token-gated, self-deletes after a successful
 * --commit run.
 *
 * Modes (via query string):
 *   ?retire_tec=<TOKEN>                  Dry-run \u2014 reports what would change
 *   ?retire_tec=<TOKEN>&commit=1         Apply changes
 *   ?retire_tec=<TOKEN>&undo=1           Revert post_type renames only
 *                                        (settings + tables are not restored)
 *
 * Steps applied in `commit` mode:
 *   1. Rename post_type:  tribe_events    -> pta_event
 *                         tribe_venue     -> pta_venue
 *                         tribe_organizer -> pta_organizer
 *   2. ALTER TABLE wp_azure_volunteer_sheets CHANGE tec_event_id pta_event_id
 *   3. RENAME TABLE wp_azure_tec_calendar_mappings -> wp_azure_calendar_mappings
 *      and rename columns tec_category_id -> category_id, tec_category_name -> category_name
 *   4. DROP TABLE wp_azure_tec_sync_history, _conflicts, _queue
 *   5. Settings cleanup: delete enable_tec_integration + all tec_* keys
 *      from azure_plugin_settings; set pta_calendar_owner='pta';
 *      remove the now-redundant pta_calendar_data_source key.
 *   6. Unschedule TEC cron events: azure_tec_scheduled_sync and any
 *      azure_tec_mapping_sync_<id> hooks.
 *
 * Idempotent: each step checks state before acting and can be re-run.
 *
 * After commit, this MU-plugin removes itself from /mu-plugins/.
 */

if (!defined('ABSPATH')) return;

add_action('init', function () {
    if (empty($_GET['retire_tec'])) return;
    if (!hash_equals('d4d4306c0c8ddc80312c3554aefbedbd', (string) $_GET['retire_tec'])) {
        status_header(403);
        header('Content-Type: text/plain');
        echo 'forbidden';
        exit;
    }

    $commit = !empty($_GET['commit']);
    $undo   = !empty($_GET['undo']);

    global $wpdb;
    $report = array(
        'mode'    => $commit ? 'commit' : ($undo ? 'undo' : 'dry-run'),
        'time'    => current_time('mysql'),
        'steps'   => array(),
        'errors'  => array(),
    );

    $log = function ($key, $info) use (&$report) {
        $report['steps'][$key] = $info;
    };

    // ── Step 1: post_type renames ────────────────────────────────────
    $rename_map = $undo
        ? array('pta_event' => 'tribe_events', 'pta_venue' => 'tribe_venue', 'pta_organizer' => 'tribe_organizer')
        : array('tribe_events' => 'pta_event', 'tribe_venue' => 'pta_venue', 'tribe_organizer' => 'pta_organizer');

    $rename_report = array();
    foreach ($rename_map as $from => $to) {
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
            $from
        ));
        $rename_report[$from] = array('to' => $to, 'count_before' => $count);
        if (($commit || $undo) && $count > 0) {
            $affected = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_type = %s WHERE post_type = %s",
                $to,
                $from
            ));
            $rename_report[$from]['affected'] = $affected;
        }
    }
    $log('1_post_type_renames', $rename_report);

    if ($undo) {
        // Stop here on undo \u2014 we don't restore settings, tables, or crons.
        nocache_headers();
        header('Content-Type: application/json');
        echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ── Step 2: volunteer_sheets column rename ────────────────────────
    $vs_table = $wpdb->prefix . 'azure_volunteer_sheets';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $vs_table)) === $vs_table) {
        $cols = $wpdb->get_col("SHOW COLUMNS FROM `{$vs_table}`");
        $has_old = in_array('tec_event_id', $cols, true);
        $has_new = in_array('pta_event_id', $cols, true);
        $info = array(
            'has_tec_event_id'  => $has_old,
            'has_pta_event_id'  => $has_new,
        );
        if ($commit && $has_old && !$has_new) {
            $info['result'] = $wpdb->query(
                "ALTER TABLE `{$vs_table}` CHANGE `tec_event_id` `pta_event_id` BIGINT(20) UNSIGNED DEFAULT 0"
            );
        }
        $log('2_volunteer_sheets_column_rename', $info);
    }

    // ── Step 3: rename calendar mappings table + columns ──────────────
    $old_map = $wpdb->prefix . 'azure_tec_calendar_mappings';
    $new_map = $wpdb->prefix . 'azure_calendar_mappings';
    $old_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $old_map)) === $old_map;
    $new_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $new_map)) === $new_map;
    $info = array('old_exists' => $old_exists, 'new_exists' => $new_exists);
    if ($commit) {
        if ($old_exists && !$new_exists) {
            $info['rename_result'] = $wpdb->query("RENAME TABLE `{$old_map}` TO `{$new_map}`");
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $new_map)) === $new_map) {
            $cols = $wpdb->get_col("SHOW COLUMNS FROM `{$new_map}`");
            if (in_array('tec_category_id', $cols, true)) {
                $info['col_category_id'] = $wpdb->query(
                    "ALTER TABLE `{$new_map}` CHANGE `tec_category_id` `category_id` BIGINT(20) UNSIGNED"
                );
            }
            if (in_array('tec_category_name', $cols, true)) {
                $info['col_category_name'] = $wpdb->query(
                    "ALTER TABLE `{$new_map}` CHANGE `tec_category_name` `category_name` VARCHAR(255) NOT NULL"
                );
            }
        }
    }
    $log('3_calendar_mappings_rename', $info);

    // ── Step 4: drop retired TEC sync tables ──────────────────────────
    $drop_tables = array(
        $wpdb->prefix . 'azure_tec_sync_history',
        $wpdb->prefix . 'azure_tec_sync_conflicts',
        $wpdb->prefix . 'azure_tec_sync_queue',
    );
    $drop_report = array();
    foreach ($drop_tables as $t) {
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t;
        $row_count = $exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$t}`") : null;
        $drop_report[$t] = array('exists' => $exists, 'rows' => $row_count);
        if ($commit && $exists) {
            $drop_report[$t]['drop_result'] = $wpdb->query("DROP TABLE `{$t}`");
        }
    }
    $log('4_drop_tec_sync_tables', $drop_report);

    // ── Step 5: settings cleanup ──────────────────────────────────────
    $opts = get_option('azure_plugin_settings', array());
    $tec_keys_to_drop = array(
        'enable_tec_integration',
        'tec_outlook_calendar_id',
        'tec_default_venue',
        'tec_default_organizer',
        'tec_organizer_email',
        'tec_sync_frequency',
        'tec_conflict_resolution',
        'tec_include_event_url',
        'tec_event_footer',
        'tec_default_category',
        'tec_client_id',
        'tec_client_secret',
        'tec_tenant_id',
        'tec_calendar_user_email',
        'tec_calendar_mailbox_email',
        'tec_sync_schedule_enabled',
        'tec_sync_schedule_frequency',
        'tec_sync_lookback_days',
        'tec_sync_lookahead_days',
        'pta_calendar_data_source', // no longer needed once owner=pta
    );
    $settings_info = array(
        'before_keys'    => count($opts),
        'tec_keys_found' => array(),
    );
    foreach ($tec_keys_to_drop as $k) {
        if (array_key_exists($k, $opts)) {
            $settings_info['tec_keys_found'][] = $k;
        }
    }
    $owner_before = $opts['pta_calendar_owner'] ?? '(unset)';
    $settings_info['pta_calendar_owner_before'] = $owner_before;

    if ($commit) {
        $changed = false;
        foreach ($tec_keys_to_drop as $k) {
            if (array_key_exists($k, $opts)) {
                unset($opts[$k]);
                $changed = true;
            }
        }
        if (($opts['pta_calendar_owner'] ?? null) !== 'pta') {
            $opts['pta_calendar_owner'] = 'pta';
            $changed = true;
        }
        if ($changed) {
            update_option('azure_plugin_settings', $opts);
            $settings_info['after_keys'] = count($opts);
            $settings_info['pta_calendar_owner_after'] = $opts['pta_calendar_owner'];
        }
    }
    $log('5_settings_cleanup', $settings_info);

    // ── Step 6: cron cleanup ──────────────────────────────────────────
    $cron_info = array();
    $hooks = array(
        'azure_tec_scheduled_sync',
        'azure_tec_sync_run',
    );
    foreach ($hooks as $h) {
        $next = wp_next_scheduled($h);
        $cron_info[$h] = array('next_scheduled_before' => $next ? date('Y-m-d H:i:s', $next) : null);
        if ($commit) {
            wp_clear_scheduled_hook($h);
            $cron_info[$h]['next_scheduled_after'] = wp_next_scheduled($h) ? 'still scheduled' : null;
        }
    }

    // Sweep per-mapping dynamic hooks like azure_tec_mapping_sync_42.
    $cron = get_option('cron');
    $mapping_hits = 0;
    if (is_array($cron)) {
        foreach ($cron as $ts => $hooks_for_ts) {
            if (!is_array($hooks_for_ts)) continue;
            foreach (array_keys($hooks_for_ts) as $hook) {
                if (strpos($hook, 'azure_tec_mapping_sync_') === 0) {
                    $mapping_hits++;
                    if ($commit) {
                        wp_clear_scheduled_hook($hook);
                    }
                }
            }
        }
    }
    $cron_info['mapping_sync_hooks_found'] = $mapping_hits;
    $log('6_cron_cleanup', $cron_info);

    // ── Final: self-delete on commit ──────────────────────────────────
    if ($commit) {
        $self = __FILE__;
        if (file_exists($self)) {
            @unlink($self);
            $report['self_deleted'] = !file_exists($self);
        }
    }

    nocache_headers();
    header('Content-Type: application/json');
    echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
});
