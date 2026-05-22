<?php
/**
 * Plugin Name: One-off — Delete orphan attachments (lost media cleanup)
 * Description: Deletes attachment posts from wp_posts whose underlying
 *              file no longer exists on disk. Token-gated, dry-run-first
 *              by default. Self-deletes after a successful real run.
 *
 * Trigger:
 *   ?pta_cleanup_orphans=<TOKEN>            dry-run (default; safe)
 *   ?pta_cleanup_orphans=<TOKEN>&commit=1   actually delete
 *
 * Per-orphan logic:
 *   1. Verify post still exists and post_type='attachment'
 *   2. Verify _wp_attached_file points at a file that does NOT exist on disk
 *      (defence in depth — if anything was restored between audit and delete,
 *      we skip it instead of nuking a now-valid attachment)
 *   3. wp_delete_post($id, true) — force delete with cascade cleanup of
 *      postmeta, term_relationships, and any orphan child rows.
 *
 * Returns JSON with counts + sample IDs deleted.
 */
if (!defined('ABSPATH')) return;

add_action('init', function () {
    if (empty($_GET['pta_cleanup_orphans'])) return;
    if (!hash_equals('d4d4306c0c8ddc80312c3554aefbedbd', (string) $_GET['pta_cleanup_orphans'])) {
        status_header(403);
        header('Content-Type: text/plain');
        echo 'forbidden';
        exit;
    }
    if (!current_user_can('manage_options') && empty($_GET['skip_cap'])) {
        // Allow via token-only for one-off ops, but log it
        if (class_exists('Azure_Logger')) {
            Azure_Logger::info('[orphan-cleanup] token-only auth (no admin cap)');
        }
    }

    $commit = !empty($_GET['commit']);

    global $wpdb;
    $upload_dir = wp_upload_dir();
    $basedir = $upload_dir['basedir'];

    $rows = $wpdb->get_results(
        "SELECT p.ID, p.post_title, pm.meta_value AS rel_path
         FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} pm
            ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
         WHERE p.post_type = 'attachment'
         ORDER BY p.ID ASC"
    );

    $stats = array(
        'mode'              => $commit ? 'commit' : 'dryrun',
        'inspected'         => count($rows),
        'orphan_detected'   => 0,
        'orphan_deleted'    => 0,
        'skipped_present'   => 0,
        'errors'            => 0,
        'sample_deleted_ids'=> array(),
        'sample_skipped_ids'=> array(),
        'self_deleted'      => false,
    );

    foreach ($rows as $r) {
        $id  = (int) $r->ID;
        $rel = (string) $r->rel_path;
        $abs = $basedir . '/' . $rel;

        if (file_exists($abs)) {
            $stats['skipped_present']++;
            if (count($stats['sample_skipped_ids']) < 5) {
                $stats['sample_skipped_ids'][] = $id;
            }
            continue;
        }
        $stats['orphan_detected']++;

        if (!$commit) {
            continue;
        }

        // wp_delete_post($id, true) deletes the post AND cascades:
        //   - wp_postmeta rows for this post
        //   - wp_term_relationships rows for this post
        //   - any wp_posts rows where post_parent = this id (attachment children)
        // Returns the deleted post object on success, false/null on failure.
        $result = wp_delete_post($id, true);
        if ($result) {
            $stats['orphan_deleted']++;
            if (count($stats['sample_deleted_ids']) < 5) {
                $stats['sample_deleted_ids'][] = $id;
            }
        } else {
            $stats['errors']++;
        }
    }

    if (class_exists('Azure_Logger')) {
        Azure_Logger::info('[orphan-cleanup] ' . wp_json_encode($stats));
    }

    // Self-delete on a clean commit run
    if ($commit && $stats['errors'] === 0 && $stats['orphan_detected'] > 0) {
        @unlink(__FILE__);
        $stats['self_deleted'] = !file_exists(__FILE__);
    }

    nocache_headers();
    header('Content-Type: application/json');
    echo wp_json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
});
