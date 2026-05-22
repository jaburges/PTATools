<?php
/**
 * Plugin Name: One-off — Audit lost media + full inventory
 * Description: READ-ONLY diagnostic. Two modes:
 *              ?pta_audit_lost_media=<TOKEN>           summary (default)
 *              ?pta_audit_lost_media=<TOKEN>&full=1    full inventory dump
 *                                                      of every orphan
 *
 * Does NOT modify any data. Does NOT self-delete.
 */
if (!defined('ABSPATH')) return;

add_action('init', function () {
    if (empty($_GET['pta_audit_lost_media'])) return;
    if (!hash_equals('d4d4306c0c8ddc80312c3554aefbedbd', (string) $_GET['pta_audit_lost_media'])) {
        status_header(403);
        header('Content-Type: text/plain');
        echo 'forbidden';
        exit;
    }

    global $wpdb;
    $upload_dir = wp_upload_dir();
    $basedir    = $upload_dir['basedir'];   // /home/site/wwwroot/wp-content/uploads
    $baseurl    = $upload_dir['baseurl'];

    $list_mode = !empty($_GET['list']);
    $full_mode = !empty($_GET['full']);

    $out = array(
        'wp_upload_basedir' => $basedir,
        'wp_upload_baseurl' => $baseurl,
        'months_audited'    => array('2026/04', '2026/05'),
        'orphan_summary'    => array(),
        'urls_in_each_month'=> array(),
    );

    // For each month of interest, find attachment posts whose guid contains
    // that month path, and check if the file exists on disk.
    foreach (array('2026/04', '2026/05', '2026/03', '2026/02', '2025/12', '2025/11', '2025/10', '2025/09', '2025/07') as $mon) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, guid, post_title FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND guid LIKE %s
             ORDER BY ID",
            '%' . $wpdb->esc_like('/uploads/' . $mon . '/') . '%'
        ));

        $total = count($rows);
        $missing = 0;
        $sample_missing = array();
        $sample_present = array();

        foreach ($rows as $r) {
            // The _wp_attached_file meta gives the relative path
            $rel = get_post_meta($r->ID, '_wp_attached_file', true);
            $abs = $rel ? ($basedir . '/' . $rel) : null;
            $exists = $abs && file_exists($abs);
            if (!$exists) {
                $missing++;
                if (count($sample_missing) < 8) {
                    $sample_missing[] = array(
                        'id'       => (int) $r->ID,
                        'title'    => $r->post_title,
                        'rel_path' => (string) $rel,
                        'guid'     => $r->guid,
                    );
                }
            } else {
                if (count($sample_present) < 3) {
                    $sample_present[] = array(
                        'id'       => (int) $r->ID,
                        'rel_path' => (string) $rel,
                    );
                }
            }
        }

        $out['orphan_summary'][$mon] = array(
            'attachment_posts_in_db'      => $total,
            'physical_files_missing'      => $missing,
            'physical_files_present'      => $total - $missing,
            'sample_missing'              => $sample_missing,
            'sample_present'              => $sample_present,
        );

        if ($list_mode) {
            $out['urls_in_each_month'][$mon] = array_map(function ($r) use ($wpdb, $basedir) {
                $rel = get_post_meta($r->ID, '_wp_attached_file', true);
                return array(
                    'id'   => (int) $r->ID,
                    'url'  => $r->guid,
                    'rel'  => (string) $rel,
                    'on_disk' => (bool) ($rel && file_exists($basedir . '/' . $rel)),
                );
            }, $rows);
        }
    }

    // Site-wide orphan count: how many attachment posts total are missing
    // their underlying file? (Answers the "blank media files" question.)
    $all_attachments = $wpdb->get_results(
        "SELECT p.ID, pm.meta_value AS rel_path
         FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
         WHERE p.post_type = 'attachment'"
    );
    $sitewide_total = count($all_attachments);
    $sitewide_missing = 0;
    foreach ($all_attachments as $a) {
        $abs = $basedir . '/' . $a->rel_path;
        if (!file_exists($abs)) $sitewide_missing++;
    }
    $out['sitewide'] = array(
        'total_attachment_posts'   => $sitewide_total,
        'orphans_missing_file'     => $sitewide_missing,
        'orphans_pct'              => $sitewide_total > 0 ? round(100.0 * $sitewide_missing / $sitewide_total, 1) : 0,
        'note'                     => 'orphans_missing_file = attachment posts whose physical upload was deleted. They still show in WP Media Library as broken/blank thumbnails.',
    );

    // Full inventory mode — dump every orphan's metadata so the PTA team
    // knows exactly what was lost. JSON array, no pretty-print to keep
    // payload reasonable (could be many MB).
    if ($full_mode) {
        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_date, p.post_mime_type, p.guid,
                    pm.meta_value AS rel_path,
                    pm_alt.meta_value AS alt_text
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
             LEFT JOIN {$wpdb->postmeta} pm_alt
                ON pm_alt.post_id = p.ID AND pm_alt.meta_key = '_wp_attachment_image_alt'
             WHERE p.post_type = 'attachment'
             ORDER BY p.post_date DESC"
        );
        $orphans = array();
        foreach ($rows as $r) {
            $abs = $basedir . '/' . $r->rel_path;
            if (file_exists($abs)) continue;
            // Group by year-month for sortability
            $ym = '';
            if (preg_match('#^(\d{4}/\d{2})/#', $r->rel_path, $m)) {
                $ym = $m[1];
            }
            $orphans[] = array(
                'id'        => (int) $r->ID,
                'title'     => $r->post_title,
                'rel_path'  => $r->rel_path,
                'guid'      => $r->guid,
                'mime'      => $r->post_mime_type,
                'uploaded'  => $r->post_date,
                'year_month'=> $ym,
                'alt_text'  => $r->alt_text ?: '',
            );
        }
        $out['orphans_full'] = $orphans;
    }

    nocache_headers();
    header('Content-Type: application/json');
    echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
});
