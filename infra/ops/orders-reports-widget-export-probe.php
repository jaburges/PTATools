<?php
/**
 * Plugin Name: One-off — Orders Reports widget Export diagnostic
 *
 * Lists every saved Orders Report and, for each one, computes three
 * different row counts so we can see exactly what the dashboard
 * widget's "Export" button would have returned vs what the same
 * report would return when run normally:
 *
 *   - count_saved      = the report exactly as stored
 *   - count_run_as_today (v3.139 fix path) = inject `to_today=true`
 *                        and re-resolve the date range
 *   - count_legacy_widget (v3.137/v3.138 buggy path) = null out the
 *                        preset and stuff today into `to`; the
 *                        path that produced the empty XLS
 *
 * If `count_legacy_widget` is 0 while `count_saved` and
 * `count_run_as_today` are positive, that confirms the bug and the
 * v3.139 fix.
 *
 * URL: /?pta_or_widget_probe=c1d4e9a6b3f0a7d2e5b8c1f4a9d6e3b0c7f4a1d8e5b2c9f6a3d0e7b4c1f8a5d2
 */
if (!defined('ABSPATH')) return;

add_action('init', function () {
    if (empty($_GET['pta_or_widget_probe'])) return;
    if (!hash_equals(
        'c1d4e9a6b3f0a7d2e5b8c1f4a9d6e3b0c7f4a1d8e5b2c9f6a3d0e7b4c1f8a5d2',
        (string) $_GET['pta_or_widget_probe']
    )) {
        status_header(403); echo 'forbidden'; exit;
    }

    if (!class_exists('Azure_Orders_Reports_Storage')) {
        if (defined('AZURE_PLUGIN_PATH')) {
            foreach (array(
                'includes/class-orders-reports-cpt.php',
                'includes/class-orders-reports-columns.php',
                'includes/class-orders-reports-query.php',
                'includes/class-orders-reports-storage.php',
            ) as $rel) {
                $path = AZURE_PLUGIN_PATH . $rel;
                if (file_exists($path)) require_once $path;
            }
            if (class_exists('Azure_Orders_Reports_CPT') && !post_type_exists(Azure_Orders_Reports_CPT::POST_TYPE_REPORT)) {
                Azure_Orders_Reports_CPT::register();
            }
        }
    }

    if (!class_exists('Azure_Orders_Reports_Storage') || !function_exists('wc_get_orders')) {
        status_header(500);
        header('Content-Type: application/json');
        echo wp_json_encode(array('error' => 'storage or WC not loaded'));
        exit;
    }

    $now      = current_time('mysql');
    $reports  = Azure_Orders_Reports_Storage::list_all();
    $query    = new Azure_Orders_Reports_Query();
    $rows_out = array();

    foreach ($reports as $r) {
        $loaded = Azure_Orders_Reports_Storage::load((int) $r['id']);
        if (!$loaded) continue;
        $saved = $loaded['config'];

        // (a) Count exactly as stored.
        try { $c_saved = $query->count($saved); }
        catch (\Throwable $e) { $c_saved = 'ERR: ' . $e->getMessage(); }

        // (b) v3.139 widget path: just set to_today=true and re-resolve.
        $cfg_new = $saved;
        $cfg_new['date_range']['to_today'] = true;
        try { $c_new = $query->count($cfg_new); }
        catch (\Throwable $e) { $c_new = 'ERR: ' . $e->getMessage(); }

        // (c) v3.137/v3.138 buggy widget path: null preset, stuff today into to.
        $cfg_bug = $saved;
        $cfg_bug['date_range']['to']     = $now;
        $cfg_bug['date_range']['preset'] = null;
        try { $c_bug = $query->count($cfg_bug); }
        catch (\Throwable $e) { $c_bug = 'ERR: ' . $e->getMessage(); }

        // Resolved ranges for visibility.
        $rng_saved = Azure_Orders_Reports_Query::resolve_date_range($saved);
        $rng_new   = Azure_Orders_Reports_Query::resolve_date_range($cfg_new);
        $rng_bug   = Azure_Orders_Reports_Query::resolve_date_range($cfg_bug);

        // What does wc_get_orders return with NO date range and NO
        // product/category/tag filters, but the SAVED statuses? If
        // this is also 0, the statuses filter is the problem. If it
        // is large, then the product/category/tag filter (or the
        // date) is what zeroes the saved report out.
        $statuses_only = array(
            'limit'  => 5,
            'status' => !empty($saved['filters']['statuses']) ? $saved['filters']['statuses'] : array('processing','on-hold','completed','pending'),
            'type'   => 'shop_order',
            'return' => 'ids',
        );
        $statuses_only_sample = wc_get_orders($statuses_only);

        // Same again but ALSO with the saved date range.
        $with_date = $statuses_only;
        $rng       = Azure_Orders_Reports_Query::resolve_date_range($saved);
        if (!empty($rng['from']) && !empty($rng['to'])) {
            $with_date['date_created'] = $rng['from'] . '...' . $rng['to'];
        }
        $with_date_sample = wc_get_orders($with_date);

        // Probe the tax → product-ids resolution to confirm the
        // intersection-vs-union hypothesis.
        $resolved_pids = array();
        $pid_diag      = array();
        if (!empty($saved['filters']['product_ids']) || !empty($saved['filters']['category_ids']) || !empty($saved['filters']['tag_ids'])) {
            $reflection = new ReflectionMethod('Azure_Orders_Reports_Query', 'resolve_product_ids_from_taxonomies');
            $reflection->setAccessible(true);
            try {
                $resolved_pids = $reflection->invoke(null,
                    (array) ($saved['filters']['product_ids']  ?? array()),
                    (array) ($saved['filters']['category_ids'] ?? array()),
                    (array) ($saved['filters']['tag_ids']      ?? array())
                );
            } catch (\Throwable $e) {
                $resolved_pids = 'ERR: ' . $e->getMessage();
            }
            // Are the listed product_ids actually tagged with the listed tags?
            foreach ((array) ($saved['filters']['product_ids'] ?? array()) as $pid) {
                $terms = wp_get_object_terms($pid, 'product_tag', array('fields' => 'ids'));
                $pid_diag[(int) $pid] = array(
                    'tags'   => is_wp_error($terms) ? 'ERR' : array_map('intval', $terms),
                    'status' => get_post_status($pid),
                    'title'  => get_the_title($pid),
                );
            }
            // How many products are in each tag?
            foreach ((array) ($saved['filters']['tag_ids'] ?? array()) as $tid) {
                $q = get_posts(array(
                    'post_type'      => 'product',
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                    'tax_query'      => array(array('taxonomy' => 'product_tag', 'field' => 'term_id', 'terms' => array((int) $tid))),
                ));
                $pid_diag['tag_' . $tid] = array(
                    'product_count' => count($q),
                    'first_5'       => array_slice(array_map('intval', $q), 0, 5),
                );
            }
        }

        $rows_out[] = array(
            'id'                  => (int) $r['id'],
            'name'                => $r['name'],
            'granularity'         => $saved['granularity'] ?? null,
            'stored_date_range'   => $saved['date_range'],
            'stored_filters'      => $saved['filters'],
            'count_saved'         => $c_saved,
            'count_run_as_today'  => $c_new,
            'count_legacy_widget' => $c_bug,
            'resolved_saved'      => $rng_saved,
            'resolved_new'        => $rng_new,
            'resolved_legacy'     => $rng_bug,
            'columns'             => array_map(function ($c) { return $c['key'] ?? ''; }, (array) ($saved['columns'] ?? array())),
            'diag' => array(
                'orders_with_saved_statuses_no_date_no_pid' => array(
                    'count_sample' => count($statuses_only_sample),
                    'sample_ids'   => array_map('intval', (array) $statuses_only_sample),
                ),
                'orders_with_saved_statuses_and_date' => array(
                    'count_sample' => count($with_date_sample),
                    'sample_ids'   => array_map('intval', (array) $with_date_sample),
                ),
                'tax_resolution' => array(
                    'resolved_product_ids' => $resolved_pids,
                    'pid_tag_diag'         => $pid_diag,
                ),
            ),
        );
    }

    wp_send_json(array(
        'time'    => $now,
        'plugin'  => defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : '?',
        'reports' => $rows_out,
    ));
});
