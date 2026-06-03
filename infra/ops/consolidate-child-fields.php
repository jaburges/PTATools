<?php
/**
 * Plugin Name: One-off — Consolidate child fields (Phase 2, 2026-06-02)
 *
 * Token-gated MU-plugin. Dry-run + commit modes. Self-deletes after a
 * successful commit run.
 *
 * Modes:
 *   ?consolidate_child_fields=<TOKEN>           Dry-run — counts only
 *   ?consolidate_child_fields=<TOKEN>&commit=1  Apply changes
 *
 * Reads these legacy line-item meta keys (written by the old WAPF
 * plugin and similar before the canonical Product Fields registry
 * existed):
 *
 *   Child name aliases:
 *     Childs Name, Child Name, Child's Name, Child&#039;s Name,
 *     Student Name
 *
 *   Direct grade keys:
 *     Child(s) Grade, Child's Grade, Childs Grade, Grade
 *
 *   Combined "teacher + grade" strings:
 *     Teacher and Grade, Teacher/Grade,
 *     Student's Teacher and Grade, Student&#039;s Teacher and Grade
 *
 *   Direct teacher keys:
 *     Child Teacher, Child's Teacher, Teacher
 *
 * For each line item with any of these, the migration normalizes and
 * writes the canonical machine-stable meta keys ON the order item:
 *   _pta_child_name      string  (the child's name as captured at order time)
 *   _pta_childsgrade     enum    (PreK | K | 1 | 2 | 3 | 4 | 5)
 *   _pta_child_teacher   string  (teacher name, parsed from combo if needed)
 *
 * If the line item also has _azure_pf_child_id (set by Part 1
 * backfill), the migration ALSO writes canonical profile meta:
 *   azure_user_children_meta:
 *     pta_pf_childsgrade   ← only if currently empty
 *     pta_pf_child_teacher ← only if currently empty
 *
 * Non-destructive:
 *   - Original legacy meta_keys/values are NEVER touched.
 *   - Existing canonical _pta_<field_key> values are NEVER overwritten
 *     (re-runs are idempotent).
 *   - Existing pta_pf_<field_key> child meta values are NEVER overwritten
 *     (the parent's profile entries take precedence over historical
 *     order data).
 */

if (!defined('ABSPATH')) return;

add_action('wp_loaded', function () {
    if (empty($_GET['consolidate_child_fields'])) return;
    if (!hash_equals('ccf-3c8a17e9', (string) $_GET['consolidate_child_fields'])) {
        status_header(403);
        header('Content-Type: text/plain');
        echo 'forbidden';
        exit;
    }

    // Force-load classes
    foreach (array('class-database.php', 'class-user-children.php') as $f) {
        $p = AZURE_PLUGIN_PATH . 'includes/' . $f;
        if (file_exists($p)) require_once $p;
    }
    if (!class_exists('Azure_User_Children')) {
        nocache_headers(); header('Content-Type: application/json');
        echo wp_json_encode(array('error' => 'Azure_User_Children not loaded'));
        exit;
    }

    @set_time_limit(0);
    $commit = !empty($_GET['commit']);

    global $wpdb;
    $items = $wpdb->prefix . 'woocommerce_order_items';
    $imeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
    $hpos  = $wpdb->prefix . 'wc_orders';

    // ── Normalizers ──────────────────────────────────────────────────
    $decode = function ($v) {
        return trim(html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    };

    $normalize_grade = function ($v) use ($decode) {
        $v = $decode($v);
        if ($v === '') return null;
        if (preg_match('/pre[\s\-]?k(?:indergarten)?|preschool/i', $v)) return 'PreK';
        if (preg_match('/(?:^|[^a-z])(k|kindergarten|kinder)(?:[^a-z]|$)/i', $v)) return 'K';
        if (preg_match('/[1-5]/', $v, $m)) return $m[0];
        $words = array('first'=>'1','second'=>'2','third'=>'3','fourth'=>'4','fifth'=>'5');
        $lower = strtolower($v);
        foreach ($words as $w => $n) if (strpos($lower, $w) !== false) return $n;
        return null;
    };

    $split_teacher_grade = function ($raw) use ($decode, $normalize_grade) {
        $v = $decode($raw);
        if ($v === '') return array(null, null);
        $grade = $normalize_grade($v);
        $teacher = null;
        if ($grade !== null) {
            $t = $v;
            $t = preg_replace('/pre[\s\-]?k(?:indergarten)?|preschool/i', '', $t);
            $t = preg_replace('/(?:^|[^a-z])(kindergarten|kinder|k)(?=[^a-z]|$)/i', ' ', $t);
            $t = preg_replace('/([1-5])(?:st|nd|rd|th)?(?:\s*grade)?/i', '', $t);
            $t = preg_replace('/(first|second|third|fourth|fifth)(?:\s*grade)?/i', '', $t);
            $t = preg_replace('/\bgrade\b/i', '', $t);
            $t = preg_replace('/\(\s*\)/', '', $t);
            $t = preg_replace('/[\/,;\-\.()]+/', ' ', $t);
            $t = preg_replace('/\s+/', ' ', $t);
            $teacher = trim($t);
            if ($teacher === '') $teacher = null;
        } else {
            $teacher = $v;
        }
        return array($teacher, $grade);
    };

    $name_keys    = array('Childs Name','Child Name',"Child's Name",'Child&#039;s Name','Student Name');
    $grade_keys   = array('Child(s) Grade',"Child's Grade",'Childs Grade','Grade');
    $combo_keys   = array('Teacher and Grade','Teacher/Grade',"Student's Teacher and Grade",'Student&#039;s Teacher and Grade');
    $teacher_keys = array('Child Teacher',"Child's Teacher",'Teacher');

    // ── Gather candidate items ───────────────────────────────────────
    $all_keys = array_merge($name_keys, $grade_keys, $combo_keys, $teacher_keys);
    $ph = implode(',', array_fill(0, count($all_keys), '%s'));
    $candidate_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT oim.order_item_id FROM {$imeta} oim
         INNER JOIN {$items} oi ON oi.order_item_id = oim.order_item_id
         INNER JOIN {$hpos} h   ON h.id = oi.order_id AND h.type = 'shop_order'
         WHERE oim.meta_key IN ({$ph})",
        ...$all_keys
    ));

    $report = array(
        'mode'                 => $commit ? 'commit' : 'dry-run',
        'time'                 => current_time('mysql'),
        'total_candidate_items'=> count($candidate_ids),
        'line_item_writes'     => array(
            'pta_child_name_written'    => 0,
            'pta_child_name_skipped'    => 0,
            'pta_childsgrade_written'   => 0,
            'pta_childsgrade_skipped'   => 0,
            'pta_child_teacher_written' => 0,
            'pta_child_teacher_skipped' => 0,
        ),
        'profile_writes' => array(
            'children_with_pf_child_id'   => 0,
            'profile_grade_written'       => 0,
            'profile_grade_skipped'       => 0,
            'profile_teacher_written'     => 0,
            'profile_teacher_skipped'     => 0,
        ),
        'grade_distribution' => array(),
        'errors' => array(),
    );

    if (empty($candidate_ids)) {
        nocache_headers(); header('Content-Type: application/json');
        echo wp_json_encode($report, JSON_PRETTY_PRINT);
        exit;
    }

    // Batch the rest of the work to keep memory bounded.
    foreach (array_chunk($candidate_ids, 500) as $chunk) {
        $oid_list = implode(',', array_map('intval', $chunk));
        // Load every meta row for this batch in one query
        $rs = $wpdb->get_results(
            "SELECT order_item_id, meta_key, meta_value
             FROM {$imeta}
             WHERE order_item_id IN ({$oid_list})",
            ARRAY_A
        );
        $by_item = array();
        foreach ($rs as $r) {
            $by_item[(int)$r['order_item_id']][$r['meta_key']] = $r['meta_value'];
        }

        foreach ($by_item as $oi_id => $kv) {
            // ── 1. Resolve child name ────────────────────────────────
            $name = '';
            foreach ($name_keys as $k) {
                if (!empty($kv[$k])) {
                    $name = $decode($kv[$k]);
                    if ($name !== '') break;
                }
            }
            $existing_pta_name = $kv['_pta_child_name'] ?? '';
            if ($name !== '') {
                if ($existing_pta_name !== '') {
                    $report['line_item_writes']['pta_child_name_skipped']++;
                } else {
                    $report['line_item_writes']['pta_child_name_written']++;
                    if ($commit) {
                        $ok = wc_add_order_item_meta($oi_id, '_pta_child_name', $name, true);
                        if (!$ok) { $report['errors'][] = array('item'=>$oi_id, 'op'=>'_pta_child_name'); }
                    }
                }
            }

            // ── 2. Resolve grade + teacher (direct + combo) ─────────
            $grade   = null;
            $teacher = null;
            foreach ($grade_keys as $k) {
                if (!empty($kv[$k])) { $grade = $normalize_grade($kv[$k]); if ($grade !== null) break; }
            }
            foreach ($teacher_keys as $k) {
                if (!empty($kv[$k])) { $teacher = $decode($kv[$k]); if ($teacher !== '') break; }
            }
            foreach ($combo_keys as $k) {
                if (empty($kv[$k])) continue;
                list($t, $g) = $split_teacher_grade($kv[$k]);
                if ($grade === null && $g !== null) $grade = $g;
                if (($teacher === null || $teacher === '') && $t !== null) $teacher = $t;
                break;
            }

            // ── 3. Write line item canonical metas ──────────────────
            if ($grade !== null) {
                $existing_g = $kv['_pta_childsgrade'] ?? '';
                if ($existing_g !== '') {
                    $report['line_item_writes']['pta_childsgrade_skipped']++;
                } else {
                    $report['line_item_writes']['pta_childsgrade_written']++;
                    $report['grade_distribution'][$grade] = ($report['grade_distribution'][$grade] ?? 0) + 1;
                    if ($commit) {
                        wc_add_order_item_meta($oi_id, '_pta_childsgrade', $grade, true);
                    }
                }
            }
            if ($teacher !== null && $teacher !== '') {
                $existing_t = $kv['_pta_child_teacher'] ?? '';
                if ($existing_t !== '') {
                    $report['line_item_writes']['pta_child_teacher_skipped']++;
                } else {
                    $report['line_item_writes']['pta_child_teacher_written']++;
                    if ($commit) {
                        wc_add_order_item_meta($oi_id, '_pta_child_teacher', $teacher, true);
                    }
                }
            }

            // ── 4. Write child profile meta if linked ───────────────
            $cid = (int) ($kv['_azure_pf_child_id'] ?? 0);
            if ($cid > 0) {
                $report['profile_writes']['children_with_pf_child_id']++;
                $profile_meta_writes = array();
                // Direct $wpdb lookup is cheaper than the helper which
                // returns ALL keys for the child on every call.
                $meta_table = Azure_Database::get_table_name('user_children_meta');
                $existing_meta = $meta_table
                    ? $wpdb->get_results($wpdb->prepare(
                        "SELECT meta_key, meta_value FROM {$meta_table} WHERE child_id = %d",
                        $cid
                    ), OBJECT_K)
                    : array();
                if ($grade !== null) {
                    $existing_pf_grade = isset($existing_meta['pta_pf_childsgrade']) ? (string) $existing_meta['pta_pf_childsgrade']->meta_value : '';
                    if ($existing_pf_grade !== '') {
                        $report['profile_writes']['profile_grade_skipped']++;
                    } else {
                        $report['profile_writes']['profile_grade_written']++;
                        $profile_meta_writes['pta_pf_childsgrade'] = $grade;
                    }
                }
                if ($teacher !== null && $teacher !== '') {
                    $existing_pf_teacher = isset($existing_meta['pta_pf_child_teacher']) ? (string) $existing_meta['pta_pf_child_teacher']->meta_value : '';
                    if ($existing_pf_teacher !== '') {
                        $report['profile_writes']['profile_teacher_skipped']++;
                    } else {
                        $report['profile_writes']['profile_teacher_written']++;
                        $profile_meta_writes['pta_pf_child_teacher'] = $teacher;
                    }
                }
                if ($commit && !empty($profile_meta_writes)) {
                    Azure_User_Children::update_child_meta($cid, $profile_meta_writes);
                }
            }
        }
    }

    // Self-delete after a successful commit run
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
}, 25);
