<?php
/**
 * Plugin Name: One-off — Orders Reports save/update round-trip probe
 *
 * Validates that `Save report` and `Update saved report` actually
 * persist the user's choices end-to-end:
 *
 *   1. CREATE a brand-new "[VALIDATION]" report via Storage::save()
 *   2. RE-LOAD it and diff every saved field against what we wrote
 *   3. UPDATE the same report with a different config (different
 *      columns, statuses, granularity, date range, to_today flag)
 *   4. RE-LOAD again and diff against the second config
 *   5. DELETE the probe report so nothing remains
 *
 * The probe never touches any user-saved report. The token gate
 * ensures it can only run from a URL the operator constructs.
 *
 * URL:  /?pta_or_save_probe=8e6f1c0a7d2b4e9c1a3f5b8d2e4a7c9b1d3f5a8c2e6b9d1f4a7c0b3e6d9f2a5c
 *
 * Output: pretty JSON with per-step PASS/FAIL and a final summary.
 */
if (!defined('ABSPATH')) return;

add_action('init', function () {
    if (empty($_GET['pta_or_save_probe'])) return;
    if (!hash_equals(
        '8e6f1c0a7d2b4e9c1a3f5b8d2e4a7c9b1d3f5a8c2e6b9d1f4a7c0b3e6d9f2a5c',
        (string) $_GET['pta_or_save_probe']
    )) {
        status_header(403); echo 'forbidden'; exit;
    }

    // The Orders Reports module only loads its dependency files when its
    // module class is constructed. On a public-front-end request the
    // module may not have been wired up yet, so manually require the
    // storage + CPT files. AZURE_PLUGIN_PATH is defined by the main
    // plugin bootstrap on every request.
    if (!class_exists('Azure_Orders_Reports_Storage')) {
        if (defined('AZURE_PLUGIN_PATH')) {
            foreach (array(
                'includes/class-orders-reports-cpt.php',
                'includes/class-orders-reports-columns.php',
                'includes/class-orders-reports-storage.php',
            ) as $rel) {
                $path = AZURE_PLUGIN_PATH . $rel;
                if (file_exists($path)) require_once $path;
            }
            // Storage::sanitize_config calls Columns::default_columns_for_granularity,
            // which is safe to call now that we've required the file.
            // The CPT post type must be registered too for save() to work;
            // hit its registrar manually if it hasn't fired yet.
            if (class_exists('Azure_Orders_Reports_CPT') && !post_type_exists(Azure_Orders_Reports_CPT::POST_TYPE_REPORT)) {
                Azure_Orders_Reports_CPT::register();
            }
        }
    }

    if (!class_exists('Azure_Orders_Reports_Storage')) {
        status_header(500);
        header('Content-Type: application/json');
        echo wp_json_encode(array(
            'error' => 'Azure_Orders_Reports_Storage not loaded',
            'azure_plugin_path' => defined('AZURE_PLUGIN_PATH') ? AZURE_PLUGIN_PATH : '(undefined)',
        ));
        exit;
    }

    $results = array(
        'time'    => current_time('mysql'),
        'plugin'  => defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : '?',
        'steps'   => array(),
        'summary' => array('pass' => 0, 'fail' => 0),
    );

    // ── Step 1: CREATE ─────────────────────────────────────────────
    $cfg_v1 = array(
        'date_range' => array(
            'from'     => '2026-08-01 00:00:00',
            'to'       => '2026-08-31 23:59:59',
            'preset'   => '',
            'to_today' => false,
        ),
        'filters' => array(
            'statuses'     => array('processing', 'completed'),
            'product_ids'  => array(),
            'category_ids' => array(),
            'tag_ids'      => array(),
        ),
        'granularity' => 'line_item',
        'columns'     => array(
            array('key' => 'order_id'),
            array('key' => 'order_date'),
            array('key' => 'product_name'),
        ),
    );
    $created = Azure_Orders_Reports_Storage::save('[VALIDATION] probe ' . wp_generate_password(6, false), $cfg_v1, 0);
    $report_id = is_wp_error($created) ? 0 : (int) $created;

    if (!$report_id) {
        $results['steps'][] = array(
            'step'   => 'create',
            'status' => 'FAIL',
            'error'  => is_wp_error($created) ? $created->get_error_message() : 'no id returned',
        );
        $results['summary']['fail']++;
        wp_send_json($results);
    }
    $results['steps'][] = array(
        'step'      => 'create',
        'status'    => 'PASS',
        'report_id' => $report_id,
    );
    $results['summary']['pass']++;

    // ── Step 2: RE-LOAD and diff v1 ───────────────────────────────
    $loaded_v1 = Azure_Orders_Reports_Storage::load($report_id);
    $diff_v1   = pta_or_probe_diff_config($cfg_v1, $loaded_v1 ? $loaded_v1['config'] : array());
    if (empty($diff_v1)) {
        $results['steps'][] = array(
            'step'   => 'reload_v1',
            'status' => 'PASS',
            'note'   => 'all v1 fields persisted exactly',
        );
        $results['summary']['pass']++;
    } else {
        $results['steps'][] = array(
            'step'   => 'reload_v1',
            'status' => 'FAIL',
            'diff'   => $diff_v1,
            'loaded' => $loaded_v1 ? $loaded_v1['config'] : null,
        );
        $results['summary']['fail']++;
    }

    // ── Step 3: UPDATE with completely different config ───────────
    $cfg_v2 = array(
        'date_range' => array(
            'from'     => '',
            'to'       => '',
            'preset'   => 'this_school_year',
            'to_today' => true, // also exercise the new flag
        ),
        'filters' => array(
            'statuses'     => array('completed', 'pending'),
            'product_ids'  => array(),
            'category_ids' => array(),
            'tag_ids'      => array(),
        ),
        'granularity' => 'order',
        'columns'     => array(
            array('key' => 'order_id'),
            array('key' => 'billing_first_name'),
            array('key' => 'billing_last_name'),
            array('key' => 'order_total'),
        ),
    );
    $updated = Azure_Orders_Reports_Storage::save(
        '[VALIDATION] probe updated',
        $cfg_v2,
        $report_id
    );
    if (is_wp_error($updated) || (int) $updated !== $report_id) {
        $results['steps'][] = array(
            'step'   => 'update',
            'status' => 'FAIL',
            'error'  => is_wp_error($updated) ? $updated->get_error_message() : ('returned id=' . (int) $updated . ' instead of ' . $report_id),
        );
        $results['summary']['fail']++;
    } else {
        $results['steps'][] = array(
            'step'      => 'update',
            'status'    => 'PASS',
            'report_id' => (int) $updated,
        );
        $results['summary']['pass']++;
    }

    // ── Step 4: RE-LOAD and diff v2 ───────────────────────────────
    $loaded_v2 = Azure_Orders_Reports_Storage::load($report_id);
    $diff_v2   = pta_or_probe_diff_config($cfg_v2, $loaded_v2 ? $loaded_v2['config'] : array());
    if (empty($diff_v2)) {
        $results['steps'][] = array(
            'step'   => 'reload_v2',
            'status' => 'PASS',
            'note'   => 'every v2 field overwrote v1 cleanly, including columns / granularity / preset / to_today',
        );
        $results['summary']['pass']++;
    } else {
        $results['steps'][] = array(
            'step'   => 'reload_v2',
            'status' => 'FAIL',
            'diff'   => $diff_v2,
            'loaded' => $loaded_v2 ? $loaded_v2['config'] : null,
        );
        $results['summary']['fail']++;
    }

    // Also confirm the post_title got updated by the rename.
    $renamed_title = $loaded_v2 ? (string) $loaded_v2['name'] : '';
    if ($renamed_title === '[VALIDATION] probe updated') {
        $results['steps'][] = array('step' => 'rename_title', 'status' => 'PASS');
        $results['summary']['pass']++;
    } else {
        $results['steps'][] = array(
            'step'   => 'rename_title',
            'status' => 'FAIL',
            'got'    => $renamed_title,
            'want'   => '[VALIDATION] probe updated',
        );
        $results['summary']['fail']++;
    }

    // ── Step 5: DELETE the probe report ───────────────────────────
    $deleted = Azure_Orders_Reports_Storage::delete($report_id);
    if ($deleted) {
        $results['steps'][] = array('step' => 'delete', 'status' => 'PASS');
        $results['summary']['pass']++;
    } else {
        $results['steps'][] = array('step' => 'delete', 'status' => 'FAIL');
        $results['summary']['fail']++;
    }

    $results['summary']['result'] = $results['summary']['fail'] === 0 ? 'ALL PASS' : 'FAIL';

    wp_send_json($results);
});

/**
 * Diff two sanitised config arrays. Returns an array of field paths
 * that differ; empty array == identical. Both sides are passed through
 * sanitize_config first so we compare apples-to-apples (the storage
 * layer always sanitises on the way in AND on the way out).
 */
function pta_or_probe_diff_config(array $expected, array $actual) {
    $exp = Azure_Orders_Reports_Storage::sanitize_config($expected);
    $act = $actual; // already sanitised by Storage::load()
    $diffs = array();

    foreach (array('from', 'to', 'preset', 'to_today') as $k) {
        if (($exp['date_range'][$k] ?? null) !== ($act['date_range'][$k] ?? null)) {
            $diffs[] = array(
                'path'     => 'date_range.' . $k,
                'expected' => $exp['date_range'][$k] ?? null,
                'actual'   => $act['date_range'][$k] ?? null,
            );
        }
    }

    foreach (array('statuses', 'product_ids', 'category_ids', 'tag_ids') as $k) {
        $e = (array) ($exp['filters'][$k] ?? array());
        $a = (array) ($act['filters'][$k] ?? array());
        sort($e); sort($a);
        if ($e !== $a) {
            $diffs[] = array('path' => 'filters.' . $k, 'expected' => $e, 'actual' => $a);
        }
    }

    if (($exp['granularity'] ?? null) !== ($act['granularity'] ?? null)) {
        $diffs[] = array(
            'path'     => 'granularity',
            'expected' => $exp['granularity'] ?? null,
            'actual'   => $act['granularity'] ?? null,
        );
    }

    $e_cols = array_map(function ($c) { return (string) ($c['key'] ?? ''); }, (array) ($exp['columns'] ?? array()));
    $a_cols = array_map(function ($c) { return (string) ($c['key'] ?? ''); }, (array) ($act['columns'] ?? array()));
    if ($e_cols !== $a_cols) {
        $diffs[] = array('path' => 'columns', 'expected' => $e_cols, 'actual' => $a_cols);
    }

    return $diffs;
}
