<?php
/**
 * Plugin Name: One-off — Child Name Backfill (2026-06-02, v2)
 *
 * Token-gated MU-plugin. Self-deletes after a successful commit run.
 *
 * Modes (via query string):
 *   ?backfill_child_names=<TOKEN>             Dry-run — counts + samples
 *   ?backfill_child_names=<TOKEN>&commit=1    Apply changes
 *
 * Scans woocommerce_order_itemmeta for these 5 legacy "child name" keys:
 *   Childs Name           (largest source — Yearbook)
 *   Child Name
 *   Child's Name          (canonical form, rare)
 *   Child&#039;s Name     (HTML-entity-encoded apostrophe form)
 *   Student Name
 *
 * For each order item with one of those keys:
 *   1. HTML-entity-decode the value, trim it.
 *   2. Resolve the order's parent user id:
 *      - First: HPOS wp_wc_orders.customer_id (set when parent was logged in)
 *      - Fallback: lookup wp_users by billing_email (guest checkout but
 *        parent has an account elsewhere — accounts for ~96% of "guest"
 *        orders on wilderptsa.net)
 *      - True guest (no matching user) → skip + log.
 *   3. If the line item already has _azure_pf_child_id, skip — already linked.
 *   4. Look up the parent's existing children via Azure_User_Children
 *      (family-aware: includes children attached to either co-parent).
 *   5. Three branches:
 *      - Case A: parent has 0 children → CREATE a new child row with that
 *                name (via Azure_User_Children::save_child, which also
 *                creates the connected_family row if missing), then link
 *                the order item via _azure_pf_child_id. In DRY-RUN we
 *                also track "would-create" children in a per-request
 *                cache so subsequent line items for the same parent+name
 *                count as Case B (cascade simulation).
 *      - Case B: parent has children AND one matches the order's name
 *                exactly (case-insensitive, trimmed) → LINK only. No new
 *                child row.
 *      - Case C: parent has children but NONE match → REPORT for review.
 *                No DB writes. The user reviews each and decides whether
 *                to add a sibling, fix a typo, or ignore.
 *
 * Non-destructive: the original Childs Name / Child Name / Student Name /
 * etc. meta_keys+values on every order item stay exactly as they are.
 *
 * Idempotent: the existing _azure_pf_child_id check at step 3 means
 * re-running the script never double-links.
 */

if (!defined('ABSPATH')) return;

add_action('init', function () {
    if (empty($_GET['backfill_child_names'])) return;
    if (!hash_equals('bcn-7e9a3f4d12b8', (string) $_GET['backfill_child_names'])) {
        status_header(403);
        header('Content-Type: text/plain');
        echo 'forbidden';
        exit;
    }

    // Force-load classes we rely on. Azure_User_Children is normally only
    // loaded inside the User Management / Product Fields paths, which
    // aren't triggered on a plain front-end request like this probe.
    if (!class_exists('Azure_Database')) {
        $p = AZURE_PLUGIN_PATH . 'includes/class-database.php';
        if (file_exists($p)) require_once $p;
    }
    if (!class_exists('Azure_User_Children')) {
        $p = AZURE_PLUGIN_PATH . 'includes/class-user-children.php';
        if (file_exists($p)) require_once $p;
    }
    if (!class_exists('Azure_User_Children')) {
        nocache_headers();
        header('Content-Type: application/json');
        echo wp_json_encode(array(
            'error' => 'Azure_User_Children class could not be loaded',
            'azure_plugin_path' => defined('AZURE_PLUGIN_PATH') ? AZURE_PLUGIN_PATH : '(undef)',
        ), JSON_PRETTY_PRINT);
        exit;
    }

    @set_time_limit(0);

    $commit = !empty($_GET['commit']);

    // Bracket the whole run in a try so we can return the actual error
    // instead of WP's generic "critical error" page.
    try {

    global $wpdb;
    $items_table    = $wpdb->prefix . 'woocommerce_order_items';
    $itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

    $legacy_keys = array(
        'Childs Name',
        'Child Name',
        "Child's Name",
        'Child&#039;s Name',
        'Student Name',
    );

    $placeholders = implode(',', array_fill(0, count($legacy_keys), '%s'));

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT oim.order_item_id, oim.meta_key, oim.meta_value, oi.order_id, oi.order_item_name
         FROM {$itemmeta_table} oim
         INNER JOIN {$items_table} oi ON oi.order_item_id = oim.order_item_id
         WHERE oim.meta_key IN ({$placeholders})
         ORDER BY oi.order_id DESC",
        ...$legacy_keys
    ));

    $report = array(
        'mode'                   => $commit ? 'commit' : 'dry-run',
        'time'                   => current_time('mysql'),
        'total_legacy_meta_rows' => count($rows),
        'by_meta_key'            => array_fill_keys($legacy_keys, 0),
        'parent_resolution'      => array(
            'hpos_customer_id'  => 0,
            'matched_by_email'  => 0,
            'truly_guest_skip'  => 0,
        ),
        'cases'                  => array(
            'A_created_new_child'  => 0,
            'B_linked_existing'    => 0,
            'C_mismatch_reported'  => 0,
            'already_linked_skip'  => 0,
            'empty_value_skip'     => 0,
            'order_not_found_skip' => 0,
        ),
        'samples' => array(
            'created_children'    => array(),
            'linked_existing'     => array(),
            'mismatches'          => array(),
            'truly_guest_orders'  => array(),
            'matched_by_email'    => array(),
        ),
        'errors' => array(),
    );

    // Pre-fetch HPOS rows in one query for all distinct order ids in $rows.
    $hpos_table = $wpdb->prefix . 'wc_orders';
    $hpos_rows  = array();
    $hpos_exists = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_table));
    if ($hpos_exists && !empty($rows)) {
        $order_ids = array_unique(array_map(function ($r) { return (int) $r->order_id; }, $rows));
        foreach (array_chunk($order_ids, 500) as $chunk) {
            $oid_list = implode(',', array_map('intval', $chunk));
            $rs = $wpdb->get_results("SELECT id, customer_id, billing_email FROM {$hpos_table} WHERE id IN ({$oid_list})", ARRAY_A);
            foreach ($rs as $r) $hpos_rows[(int)$r['id']] = $r;
        }
    }

    // In dry-run we track would-be-created children per parent so the
    // cascade is simulated. Key = "{parent_user_id}|{name_lowercase}".
    $simulated_children = array();
    // Cache parent → children objects so we don't re-query for parents
    // hit by many line items (Yearbook bulk orders).
    $children_cache = array();

    foreach ($rows as $r) {
        $key = (string) $r->meta_key;
        if (isset($report['by_meta_key'][$key])) {
            $report['by_meta_key'][$key]++;
        }

        $raw     = (string) $r->meta_value;
        $decoded = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($decoded === '') {
            $report['cases']['empty_value_skip']++;
            continue;
        }

        // Resolve parent user id: HPOS first, email fallback second.
        $oid      = (int) $r->order_id;
        $hpos_row = $hpos_rows[$oid] ?? null;
        if (!$hpos_row) {
            $report['cases']['order_not_found_skip']++;
            continue;
        }
        $customer_id  = (int) $hpos_row['customer_id'];
        $billing_email = (string) $hpos_row['billing_email'];
        $resolved_via = '';

        if ($customer_id > 0) {
            $resolved_via = 'hpos_customer_id';
            $report['parent_resolution']['hpos_customer_id']++;
        } elseif ($billing_email !== '') {
            $u = get_user_by('email', $billing_email);
            if ($u) {
                $customer_id = (int) $u->ID;
                $resolved_via = 'matched_by_email';
                $report['parent_resolution']['matched_by_email']++;
                if (count($report['samples']['matched_by_email']) < 15) {
                    $report['samples']['matched_by_email'][] = array(
                        'order_id'      => $oid,
                        'order_item_id' => (int) $r->order_item_id,
                        'billing_email' => $billing_email,
                        'matched_user_id' => $customer_id,
                        'child_name'    => $decoded,
                    );
                }
            }
        }

        if ($customer_id <= 0) {
            $report['parent_resolution']['truly_guest_skip']++;
            if (count($report['samples']['truly_guest_orders']) < 30) {
                $report['samples']['truly_guest_orders'][] = array(
                    'order_id'      => $oid,
                    'order_item_id' => (int) $r->order_item_id,
                    'meta_key'      => $key,
                    'value'         => $decoded,
                    'product_name'  => (string) $r->order_item_name,
                    'billing_email' => $billing_email,
                );
            }
            continue;
        }

        $existing_link = (int) wc_get_order_item_meta((int) $r->order_item_id, '_azure_pf_child_id', true);
        if ($existing_link > 0) {
            $report['cases']['already_linked_skip']++;
            continue;
        }

        // Look up parent's children — cache per parent.
        if (!isset($children_cache[$customer_id])) {
            $children_cache[$customer_id] = Azure_User_Children::get_children_for_user($customer_id);
        }
        $children = $children_cache[$customer_id];

        $decoded_norm = strtolower($decoded);

        // Cascade simulation in dry-run mode: append any would-be-created
        // children from earlier rows in this run to the children list, so
        // subsequent rows for the same parent+name count as Case B.
        if (!$commit && !empty($simulated_children[$customer_id])) {
            foreach ($simulated_children[$customer_id] as $sim_name) {
                $children[] = (object) array('id' => 0, 'child_name' => $sim_name);
            }
        }

        $match = null;
        foreach ($children as $child) {
            if (strtolower(trim((string) $child->child_name)) === $decoded_norm) {
                $match = $child;
                break;
            }
        }

        if ($match) {
            $report['cases']['B_linked_existing']++;
            if (count($report['samples']['linked_existing']) < 30) {
                $report['samples']['linked_existing'][] = array(
                    'order_item_id' => (int) $r->order_item_id,
                    'child_id'      => (int) $match->id,
                    'name'          => $decoded,
                    'meta_key'      => $key,
                );
            }
            if ($commit) {
                wc_add_order_item_meta((int) $r->order_item_id, '_azure_pf_child_id', (int) $match->id, true);
            }
            continue;
        }

        if (empty($children)) {
            $report['cases']['A_created_new_child']++;
            if (count($report['samples']['created_children']) < 30) {
                $report['samples']['created_children'][] = array(
                    'order_item_id'  => (int) $r->order_item_id,
                    'parent_user_id' => $customer_id,
                    'name'           => $decoded,
                    'meta_key'       => $key,
                    'resolved_via'   => $resolved_via,
                );
            }
            if ($commit) {
                $new_id = Azure_User_Children::save_child($customer_id, array(
                    'child_name' => $decoded,
                ));
                if ($new_id > 0) {
                    wc_add_order_item_meta((int) $r->order_item_id, '_azure_pf_child_id', (int) $new_id, true);
                    // Update cache so subsequent items see the new child.
                    $children_cache[$customer_id][] = (object) array(
                        'id' => (int) $new_id,
                        'child_name' => $decoded,
                    );
                } else {
                    $report['errors'][] = array(
                        'order_item_id'  => (int) $r->order_item_id,
                        'parent_user_id' => $customer_id,
                        'name'           => $decoded,
                        'reason'         => 'save_child returned 0',
                    );
                }
            } else {
                // Dry-run: remember we'd have created this child so cascade
                // simulation works for later rows.
                if (!isset($simulated_children[$customer_id])) {
                    $simulated_children[$customer_id] = array();
                }
                $simulated_children[$customer_id][] = $decoded;
            }
            continue;
        }

        $report['cases']['C_mismatch_reported']++;
        if (count($report['samples']['mismatches']) < 200) {
            $report['samples']['mismatches'][] = array(
                'order_id'          => (int) $r->order_id,
                'order_item_id'     => (int) $r->order_item_id,
                'parent_user_id'    => $customer_id,
                'billing_email'     => $billing_email,
                'meta_key'          => $key,
                'meta_value'        => $decoded,
                'product_name'      => (string) $r->order_item_name,
                'existing_children' => array_map(function ($c) {
                    return array('id' => (int) $c->id, 'name' => (string) $c->child_name);
                }, $children),
            );
        }
    }

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

    } catch (\Throwable $e) {
        if (!headers_sent()) {
            status_header(500);
            header('Content-Type: application/json');
        }
        echo wp_json_encode(array(
            'error'   => 'Throwable: ' . $e->getMessage(),
            'class'   => get_class($e),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => array_slice($e->getTrace(), 0, 6),
            'partial' => $report ?? null,
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
});
