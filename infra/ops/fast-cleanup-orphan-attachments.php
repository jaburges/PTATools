<?php
/**
 * Plugin Name: One-off — Fast orphan cleanup (direct SQL)
 * Description: Bulk-deletes orphan attachment posts via direct $wpdb
 *              queries. Bypasses wp_delete_post() which is too slow for
 *              1,400+ deletions in one PHP execution window (hits
 *              max_execution_time). Skips files that exist on disk as a
 *              safety check.
 *
 * Trigger:
 *   ?pta_fast_cleanup=<TOKEN>            dryrun
 *   ?pta_fast_cleanup=<TOKEN>&commit=1   commit
 */
if (!defined('ABSPATH')) return;

add_action('init', function () {
    if (empty($_GET['pta_fast_cleanup'])) return;
    if (!hash_equals('d4d4306c0c8ddc80312c3554aefbedbd', (string) $_GET['pta_fast_cleanup'])) {
        status_header(403);
        header('Content-Type: text/plain');
        echo 'forbidden';
        exit;
    }

    $commit = !empty($_GET['commit']);
    global $wpdb;
    $upload_dir = wp_upload_dir();
    $basedir = $upload_dir['basedir'];

    // Build the orphan ID list — same logic as the slow script, but only
    // collect IDs first, no deletion in the loop.
    $rows = $wpdb->get_results(
        "SELECT p.ID, pm.meta_value AS rel_path
         FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} pm
            ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
         WHERE p.post_type = 'attachment'"
    );
    $orphan_ids = array();
    foreach ($rows as $r) {
        if (!file_exists($basedir . '/' . $r->rel_path)) {
            $orphan_ids[] = (int) $r->ID;
        }
    }

    $out = array(
        'mode'              => $commit ? 'commit' : 'dryrun',
        'inspected'         => count($rows),
        'orphans_detected'  => count($orphan_ids),
        'deleted_posts'     => 0,
        'deleted_postmeta'  => 0,
        'deleted_term_rels' => 0,
        'deleted_orphan_children' => 0,
        'errors'            => array(),
        'self_deleted'      => false,
    );

    if (!$commit || empty($orphan_ids)) {
        nocache_headers();
        header('Content-Type: application/json');
        echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Delete in batches of 500 IDs per query so we don't exceed MySQL
    // max_allowed_packet on the IN(...) list.
    $batch_size = 500;
    $batches = array_chunk($orphan_ids, $batch_size);

    foreach ($batches as $batch) {
        $placeholders = implode(',', array_fill(0, count($batch), '%d'));

        // 1. wp_postmeta (we use this to know rows count by selecting first)
        $count_pm = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders)",
            $batch
        ));
        $ok = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders)",
            $batch
        ));
        if ($ok === false) {
            $out['errors'][] = 'postmeta delete failed: ' . $wpdb->last_error;
        } else {
            $out['deleted_postmeta'] += $count_pm;
        }

        // 2. wp_term_relationships
        $count_tr = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id IN ($placeholders)",
            $batch
        ));
        $ok = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($placeholders)",
            $batch
        ));
        if ($ok === false) {
            $out['errors'][] = 'term_relationships delete failed: ' . $wpdb->last_error;
        } else {
            $out['deleted_term_rels'] += $count_tr;
        }

        // 3. Child posts that point at these as post_parent (rare for
        //    attachments — happens for image-cropping intermediate posts).
        $count_kids = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent IN ($placeholders)",
            $batch
        ));
        $ok = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->posts} WHERE post_parent IN ($placeholders)",
            $batch
        ));
        if ($ok === false) {
            $out['errors'][] = 'orphan-children delete failed: ' . $wpdb->last_error;
        } else {
            $out['deleted_orphan_children'] += $count_kids;
        }

        // 4. The attachment posts themselves
        $ok = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->posts} WHERE ID IN ($placeholders) AND post_type = 'attachment'",
            $batch
        ));
        if ($ok === false) {
            $out['errors'][] = 'posts delete failed: ' . $wpdb->last_error;
        } else {
            $out['deleted_posts'] += $ok;
        }
    }

    // Flush object cache so admin pages reflect new state immediately
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }

    if (class_exists('Azure_Logger')) {
        Azure_Logger::info('[fast-cleanup] ' . wp_json_encode($out));
    }

    // Self-delete on clean success
    if (empty($out['errors']) && $out['deleted_posts'] > 0) {
        @unlink(__FILE__);
        $out['self_deleted'] = !file_exists(__FILE__);
    }

    nocache_headers();
    header('Content-Type: application/json');
    echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
});
