<?php
/**
 * Parent + Children CSV Importer (v3.67)
 *
 * Reads a CSV in the canonical "Kids and parents export" shape and creates
 * Parent users, connected_family rows, and child profiles. Designed to be
 * run once after v3.67 ships, with a dry-run preview before committing.
 *
 * Pipeline:
 *   1. Header validation — every required column must be present.
 *   2. Per-row classification (no writes) → preview JSON for the admin UI.
 *   3. Apply phase, structured as a single non-destructive pass:
 *        a. Resolve / create Parent 1 user (idempotent by email).
 *        b. Resolve / create Parent 2 user, if email present.
 *        c. Resolve / create connected_family rooted at Parent 1.
 *        d. Resolve / create child row by (family_id, child_name).
 *        e. Fill child + family meta — blanks only, never overwrite.
 *
 * Imported users are created with role=parent and `_pta_login_disabled = 1`
 * so they cannot sign in. The welcome-email tool is what flips that flag
 * and emails a temp password.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Parent_Children_Importer {

    const NONCE_ACTION = 'azure_pci_nonce';

    /** Canonical column → internal key map. Keys are matched case-insensitively. */
    private static $column_map = array(
        'parent 1 email'                                   => 'parent_1_email',
        'parent 1 cell number'                             => 'parent_1_cell',
        'parent 1 full name'                               => 'parent_1_name',
        'parent 2 email'                                   => 'parent_2_email',
        'parent 2 cell number'                             => 'parent_2_cell',
        'parent 2 name'                                    => 'parent_2_name',
        'child / student name'                             => 'child_name',
        'grade'                                            => 'child_grade',
        'teacher'                                          => 'child_teacher',
        'allergies'                                        => 'allergies',
        'do you allow photos to be taken of your child'    => 'photos_ok',
        'other notes for class instructor'                 => 'other_notes_instructor',
        'self carry epi pen'                               => 'epi_pen',
        'emergency contact name'                           => 'emergency_contact_name',
        'emergency contact email'                          => 'emergency_contact_email',
        'emergency contact number'                         => 'emergency_contact_cell',
    );

    private static $required_columns = array(
        'parent_1_email', 'parent_1_name', 'child_name',
    );

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!is_admin()) {
            return;
        }
        add_action('wp_ajax_azure_pci_scan',  array($this, 'ajax_scan'));
        add_action('wp_ajax_azure_pci_apply', array($this, 'ajax_apply'));
    }

    // ─── AJAX entry points ─────────────────────────────────────────────

    public function ajax_scan() {
        $this->require_admin();
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $csv = $this->read_csv_payload();
        if (is_wp_error($csv)) {
            wp_send_json_error($csv->get_error_message());
        }

        $rows = $this->parse_csv($csv);
        if (is_wp_error($rows)) {
            wp_send_json_error($rows->get_error_message());
        }

        $report = $this->classify($rows);
        wp_send_json_success($report);
    }

    public function ajax_apply() {
        $this->require_admin();
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $csv = $this->read_csv_payload();
        if (is_wp_error($csv)) {
            wp_send_json_error($csv->get_error_message());
        }

        $rows = $this->parse_csv($csv);
        if (is_wp_error($rows)) {
            wp_send_json_error($rows->get_error_message());
        }

        $summary = $this->apply($rows);
        wp_send_json_success($summary);
    }

    private function require_admin() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
    }

    // ─── CSV intake ────────────────────────────────────────────────────

    /**
     * Read the CSV payload from either an uploaded file (`$_FILES['csv']`)
     * or pasted text (`$_POST['csv_text']`). Returns the raw content or a
     * WP_Error on failure. Caps file size at 5 MB.
     */
    private function read_csv_payload() {
        if (!empty($_FILES['csv']['tmp_name'])) {
            if ((int) $_FILES['csv']['size'] > 5 * 1024 * 1024) {
                return new WP_Error('too_large', 'CSV exceeds the 5 MB limit.');
            }
            $contents = file_get_contents($_FILES['csv']['tmp_name']);
            if ($contents === false) {
                return new WP_Error('read_failed', 'Could not read uploaded CSV.');
            }
            return $contents;
        }
        if (!empty($_POST['csv_text'])) {
            $text = wp_unslash($_POST['csv_text']);
            if (strlen($text) > 5 * 1024 * 1024) {
                return new WP_Error('too_large', 'CSV text exceeds the 5 MB limit.');
            }
            return $text;
        }
        return new WP_Error('no_csv', 'No CSV provided. Upload a file or paste rows.');
    }

    /**
     * Parse a CSV string into an associative array of rows keyed by the
     * canonical internal field names. Skips empty trailing rows.
     *
     * @param string $csv
     * @return array|WP_Error
     */
    private function parse_csv($csv) {
        $csv = preg_replace("/^\xEF\xBB\xBF/", '', $csv);
        $csv = str_replace(array("\r\n", "\r"), "\n", $csv);

        $fh = fopen('php://memory', 'r+');
        if (!$fh) {
            return new WP_Error('fopen_failed', 'Could not open CSV stream.');
        }
        fwrite($fh, $csv);
        rewind($fh);

        $header = fgetcsv($fh);
        if (!$header) {
            fclose($fh);
            return new WP_Error('empty_csv', 'CSV is empty.');
        }

        $col_to_key = array();
        foreach ($header as $i => $col) {
            $norm = strtolower(trim((string) $col));
            if (isset(self::$column_map[$norm])) {
                $col_to_key[$i] = self::$column_map[$norm];
            }
        }

        foreach (self::$required_columns as $required) {
            if (!in_array($required, $col_to_key, true)) {
                fclose($fh);
                return new WP_Error('missing_column', sprintf(
                    'Required column missing: %s. Header must match the canonical export.',
                    $required
                ));
            }
        }

        $out = array();
        $line_no = 1;
        while (($cells = fgetcsv($fh)) !== false) {
            $line_no++;
            // Normalize: allow shorter rows than header.
            $row = array();
            foreach ($col_to_key as $i => $key) {
                $row[$key] = isset($cells[$i]) ? trim((string) $cells[$i]) : '';
            }
            $row['__line'] = $line_no;
            $out[] = $row;
        }
        fclose($fh);

        return $out;
    }

    // ─── Classification (dry-run preview) ──────────────────────────────

    /**
     * Walk the parsed rows and emit a structured preview describing what
     * Apply would do. No DB writes.
     *
     * Memoized per-row to mirror the apply phase: parent users / families
     * resolved on row N are reused for row N+1 just like at write-time, so
     * the preview counts match the actual creation totals.
     */
    public function classify(array $rows) {
        $report = array(
            'totals' => array(
                'rows'             => count($rows),
                'rows_skipped'     => 0,
                'parent_create'    => 0,
                'parent_merge'     => 0,
                'parent_invalid'   => 0,
                'family_create'    => 0,
                'family_merge'     => 0,
                'child_create'     => 0,
                'child_merge'      => 0,
            ),
            'rows' => array(),
            'errors' => array(),
        );

        $parent_seen = array(); // email lower => 'create'|'merge'
        $family_seen = array(); // parent1 email lower => 'create'|'merge'
        $child_seen  = array(); // family_key|child_name lower => 'create'|'merge'

        foreach ($rows as $row) {
            $entry = array(
                'line'        => $row['__line'],
                'child_name'  => $row['child_name'],
                'parent_1'    => array('email' => $row['parent_1_email'], 'decision' => null),
                'parent_2'    => array('email' => $row['parent_2_email'] ?? '', 'decision' => 'none'),
                'family'      => null,
                'child'       => null,
            );

            if ($row['child_name'] === '') {
                $entry['error'] = 'blank_child_name';
                $report['totals']['rows_skipped']++;
                $report['rows'][] = $entry;
                continue;
            }

            // Parent 1 (required + must be a valid email).
            $p1_email = strtolower($row['parent_1_email']);
            if (!is_email($p1_email)) {
                $entry['error'] = 'invalid_parent_1_email';
                $report['totals']['parent_invalid']++;
                $report['rows'][] = $entry;
                continue;
            }
            $entry['parent_1']['decision'] = $this->preview_user_decision($p1_email, $parent_seen, $report);

            // Parent 2 (optional).
            $p2_email_raw = isset($row['parent_2_email']) ? trim($row['parent_2_email']) : '';
            $p2_email = $p2_email_raw !== '' ? strtolower($p2_email_raw) : '';
            if ($p2_email !== '') {
                if (!is_email($p2_email)) {
                    $entry['parent_2']['decision'] = 'invalid';
                    $report['totals']['parent_invalid']++;
                } else {
                    $entry['parent_2']['decision'] = $this->preview_user_decision($p2_email, $parent_seen, $report);
                }
            }

            // Family — keyed by Parent 1 email so multiple children of the same
            // family roll up correctly.
            if (!isset($family_seen[$p1_email])) {
                $existing_user = get_user_by('email', $p1_email);
                $existing_family = null;
                if ($existing_user && class_exists('Azure_User_Children')) {
                    $existing_family = Azure_User_Children::get_family_for_user($existing_user->ID);
                }
                $family_seen[$p1_email] = $existing_family ? 'merge' : 'create';
                if ($existing_family) {
                    $report['totals']['family_merge']++;
                } else {
                    $report['totals']['family_create']++;
                }
            }
            $entry['family'] = array('decision' => $family_seen[$p1_email]);

            // Child — key by family + name.
            $child_key = $p1_email . '|' . strtolower($row['child_name']);
            if (!isset($child_seen[$child_key])) {
                $child_seen[$child_key] = $this->preview_child_decision($p1_email, $row['child_name']);
                if ($child_seen[$child_key] === 'merge') {
                    $report['totals']['child_merge']++;
                } else {
                    $report['totals']['child_create']++;
                }
            }
            $entry['child'] = array('decision' => $child_seen[$child_key]);

            $report['rows'][] = $entry;
        }

        return $report;
    }

    private function preview_user_decision($email_lower, array &$parent_seen, array &$report) {
        if (isset($parent_seen[$email_lower])) {
            return $parent_seen[$email_lower];
        }
        $existing = get_user_by('email', $email_lower);
        if ($existing) {
            $parent_seen[$email_lower] = 'merge';
            $report['totals']['parent_merge']++;
        } else {
            $parent_seen[$email_lower] = 'create';
            $report['totals']['parent_create']++;
        }
        return $parent_seen[$email_lower];
    }

    private function preview_child_decision($p1_email_lower, $child_name) {
        $existing_user = get_user_by('email', $p1_email_lower);
        if (!$existing_user) {
            return 'create';
        }
        if (!class_exists('Azure_User_Children')) {
            return 'create';
        }
        $match = Azure_User_Children::find_child_by_name($existing_user->ID, $child_name);
        return $match ? 'merge' : 'create';
    }

    // ─── Apply (commit) ────────────────────────────────────────────────

    public function apply(array $rows) {
        $summary = array(
            'parents_created'  => 0,
            'parents_merged'   => 0,
            'parents_invalid'  => 0,
            'families_created' => 0,
            'families_merged'  => 0,
            'children_created' => 0,
            'children_merged'  => 0,
            'rows_skipped'     => 0,
            'errors'           => array(),
        );

        // Per-run caches to avoid redundant lookups.
        $user_by_email   = array();
        $family_by_user  = array();

        foreach ($rows as $row) {
            $line = $row['__line'];
            if ($row['child_name'] === '') {
                $summary['rows_skipped']++;
                continue;
            }

            $p1_email = strtolower(trim($row['parent_1_email']));
            if (!is_email($p1_email)) {
                $summary['parents_invalid']++;
                $summary['errors'][] = "Line {$line}: invalid Parent 1 email";
                continue;
            }

            $p1_user_id = $this->resolve_or_create_parent(
                $p1_email,
                $row['parent_1_name'],
                array(
                    'parent_1_name'  => $row['parent_1_name'],
                    'parent_1_email' => $row['parent_1_email'],
                    'parent_1_cell'  => $row['parent_1_cell'],
                ),
                $user_by_email,
                $summary
            );
            if (!$p1_user_id) {
                continue; // hard error; already logged
            }

            $p2_user_id = 0;
            $p2_email_raw = isset($row['parent_2_email']) ? trim($row['parent_2_email']) : '';
            if ($p2_email_raw !== '') {
                $p2_email = strtolower($p2_email_raw);
                if (!is_email($p2_email)) {
                    $summary['parents_invalid']++;
                    $summary['errors'][] = "Line {$line}: invalid Parent 2 email";
                } else {
                    $p2_user_id = $this->resolve_or_create_parent(
                        $p2_email,
                        $row['parent_2_name'] ?: $p2_email_raw,
                        array(
                            'parent_1_name'  => $row['parent_2_name'],
                            'parent_1_email' => $row['parent_2_email'],
                            'parent_1_cell'  => $row['parent_2_cell'],
                            'parent_2_name'  => $row['parent_1_name'],
                            'parent_2_email' => $row['parent_1_email'],
                            'parent_2_cell'  => $row['parent_1_cell'],
                        ),
                        $user_by_email,
                        $summary
                    );
                }
            }

            // Mirror Parent 2 fields onto Parent 1's user record so each user
            // sees their co-parent in the "Parent 2" slot of their profile.
            if ($p2_user_id) {
                $this->fill_blank_user_meta($p1_user_id, array(
                    'pta_pf_parent_2_name'  => $row['parent_2_name'],
                    'pta_pf_parent_2_email' => $row['parent_2_email'],
                    'pta_pf_parent_2_cell'  => $row['parent_2_cell'],
                ));
            }

            // Family resolution.
            $family_id = $this->resolve_or_create_family(
                $p1_user_id,
                $p2_user_id,
                $row['parent_1_name'],
                $family_by_user,
                $summary
            );
            if (!$family_id) {
                $summary['errors'][] = "Line {$line}: could not create connected_family";
                continue;
            }

            // Child + child meta.
            $child_meta = $this->build_child_meta_array($row);
            $child = Azure_User_Children::find_child_by_name($p1_user_id, $row['child_name']);
            if ($child) {
                $this->fill_blank_child_meta((int) $child->id, $child_meta);
                // Ensure the child is linked to the resolved family even if it
                // was previously a single-parent backfilled row.
                $this->ensure_child_family_link((int) $child->id, $family_id);
                $summary['children_merged']++;
            } else {
                $new_child_id = Azure_User_Children::save_child($p1_user_id, array(
                    'child_name' => $row['child_name'],
                    'family_id'  => $family_id,
                    'meta'       => $child_meta,
                ));
                if ($new_child_id) {
                    $summary['children_created']++;
                } else {
                    $summary['errors'][] = "Line {$line}: child save failed";
                }
            }

            // Family meta (emergency contact). Fill blanks only.
            $family_meta = array(
                'pta_pf_emergency_contact_name'  => $row['emergency_contact_name'],
                'pta_pf_emergency_contact_email' => $row['emergency_contact_email'],
                'pta_pf_emergency_contact_cell'  => $row['emergency_contact_cell'],
            );
            $this->fill_blank_family_meta($family_id, $family_meta);
        }

        return $summary;
    }

    // ─── Apply helpers ─────────────────────────────────────────────────

    /**
     * Find or create a Parent user. Existing accounts get the parent role
     * added (if missing) but their login state is left untouched. Newly
     * created accounts are login-disabled until the welcome-email tool is
     * run.
     *
     * Profile usermeta is filled blanks-only — never overwrites a value
     * the user has already entered.
     */
    private function resolve_or_create_parent($email_lower, $display_name, array $usermeta_seed, array &$user_cache, array &$summary) {
        if (isset($user_cache[$email_lower])) {
            return $user_cache[$email_lower];
        }

        $existing = get_user_by('email', $email_lower);
        if ($existing) {
            if (!in_array(Azure_Parent_Role::ROLE_SLUG, (array) $existing->roles, true)) {
                $existing->add_role(Azure_Parent_Role::ROLE_SLUG);
            }
            $this->fill_blank_user_meta($existing->ID, $this->prefix_usermeta_keys($usermeta_seed));
            $user_cache[$email_lower] = $existing->ID;
            $summary['parents_merged']++;
            return $existing->ID;
        }

        // Create with a strong placeholder password the importer never reveals.
        // The welcome-email tool replaces it with a fresh temp password before
        // emailing the user, so this value never reaches anyone.
        $placeholder = wp_generate_password(48, true, true);
        $first_name = $this->derive_first_name($display_name);
        $last_name  = $this->derive_last_name($display_name);

        $user_id = wp_insert_user(array(
            'user_login'   => $email_lower,
            'user_email'   => $email_lower,
            'user_pass'    => $placeholder,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => $display_name ?: $email_lower,
            'role'         => Azure_Parent_Role::ROLE_SLUG,
        ));

        if (is_wp_error($user_id)) {
            $summary['parents_invalid']++;
            $summary['errors'][] = 'wp_insert_user: ' . $user_id->get_error_message();
            $user_cache[$email_lower] = 0;
            return 0;
        }

        update_user_meta($user_id, Azure_Parent_Role::META_LOGIN_DISABLED, 1);
        update_user_meta($user_id, '_pta_welcome_email_sent', 0);
        update_user_meta($user_id, '_pta_imported_at', current_time('mysql'));
        $this->fill_blank_user_meta($user_id, $this->prefix_usermeta_keys($usermeta_seed));

        $user_cache[$email_lower] = $user_id;
        $summary['parents_created']++;
        return $user_id;
    }

    private function prefix_usermeta_keys(array $seed) {
        $out = array();
        foreach ($seed as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $out['pta_pf_' . $k] = $v;
        }
        return $out;
    }

    /**
     * Resolve / create the connected_family rooted at $p1_user_id. If a
     * family already exists, attach Parent 2 only when it has no secondary
     * yet — never overwrites an existing co-parent link.
     */
    private function resolve_or_create_family($p1_user_id, $p2_user_id, $p1_display_name, array &$family_cache, array &$summary) {
        if (isset($family_cache[$p1_user_id])) {
            // Existing family already cached for this run; attach P2 once if
            // we now have one and didn't earlier.
            $family_id = $family_cache[$p1_user_id];
            if ($p2_user_id && $family_id) {
                $this->maybe_attach_secondary($family_id, $p2_user_id);
            }
            return $family_id;
        }

        global $wpdb;
        $family_table = Azure_Database::get_table_name('connected_family');
        if (!$family_table) {
            return 0;
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$family_table} WHERE primary_user_id = %d ORDER BY id ASC LIMIT 1",
            $p1_user_id
        ));
        if ($existing) {
            if ($p2_user_id) {
                $this->maybe_attach_secondary((int) $existing->id, $p2_user_id);
            }
            $family_cache[$p1_user_id] = (int) $existing->id;
            $summary['families_merged']++;
            return (int) $existing->id;
        }

        $display = '';
        $last = $this->derive_last_name($p1_display_name);
        if ($last !== '') {
            $display = $last . ' family';
        } else {
            $display = $p1_display_name ?: '';
        }

        $wpdb->insert($family_table, array(
            'display_name'      => $display,
            'primary_user_id'   => (int) $p1_user_id,
            'secondary_user_id' => (int) $p2_user_id,
        ), array('%s', '%d', '%d'));
        $family_id = (int) $wpdb->insert_id;
        $family_cache[$p1_user_id] = $family_id;
        $summary['families_created']++;
        return $family_id;
    }

    private function maybe_attach_secondary($family_id, $p2_user_id) {
        global $wpdb;
        $family_table = Azure_Database::get_table_name('connected_family');
        if (!$family_table) {
            return;
        }
        $current = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT secondary_user_id FROM {$family_table} WHERE id = %d",
            (int) $family_id
        ));
        if ($current === 0 && (int) $p2_user_id > 0) {
            $wpdb->update(
                $family_table,
                array('secondary_user_id' => (int) $p2_user_id),
                array('id' => (int) $family_id),
                array('%d'),
                array('%d')
            );
        }
    }

    private function ensure_child_family_link($child_id, $family_id) {
        global $wpdb;
        $children_table = Azure_Database::get_table_name('user_children');
        if (!$children_table) {
            return;
        }
        $current = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT family_id FROM {$children_table} WHERE id = %d",
            (int) $child_id
        ));
        if ($current !== (int) $family_id) {
            $wpdb->update(
                $children_table,
                array('family_id' => (int) $family_id),
                array('id' => (int) $child_id),
                array('%d'),
                array('%d')
            );
        }
    }

    /**
     * Build the child meta map for a row, normalizing yes/no to "Yes"/"No"
     * for the boolean-style fields so they match the canonical product
     * field options.
     */
    private function build_child_meta_array(array $row) {
        $meta = array();
        $assign = function ($key, $value) use (&$meta) {
            $value = trim((string) $value);
            if ($value === '') {
                return;
            }
            $meta['pta_pf_' . $key] = $value;
        };

        $assign('child_grade', $row['child_grade'] ?? '');
        $assign('child_teacher', $row['child_teacher'] ?? '');
        $assign('allergies', $row['allergies'] ?? '');
        $assign('photos_ok', $this->normalize_yes_no($row['photos_ok'] ?? ''));
        $assign('epi_pen', $this->normalize_yes_no($row['epi_pen'] ?? ''));
        $assign('other_notes_instructor', $row['other_notes_instructor'] ?? '');

        return $meta;
    }

    private function normalize_yes_no($value) {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return '';
        }
        if (in_array($value, array('yes', 'y', 'true', '1'), true)) {
            return 'Yes';
        }
        if (in_array($value, array('no', 'n', 'false', '0'), true)) {
            return 'No';
        }
        return $value;
    }

    private function fill_blank_user_meta($user_id, array $meta) {
        foreach ($meta as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $existing = get_user_meta($user_id, $key, true);
            if ($existing === '' || $existing === null) {
                update_user_meta($user_id, $key, $value);
            }
        }
    }

    private function fill_blank_child_meta($child_id, array $meta) {
        if (empty($meta)) {
            return;
        }
        $existing = Azure_User_Children::get_child_meta((int) $child_id);
        $to_write = array();
        foreach ($meta as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            if (!isset($existing[$k]) || $existing[$k] === '') {
                $to_write[$k] = $v;
            }
        }
        if (!empty($to_write)) {
            Azure_User_Children::update_child_meta((int) $child_id, $to_write);
        }
    }

    private function fill_blank_family_meta($family_id, array $meta) {
        if (empty($meta)) {
            return;
        }
        $existing = Azure_User_Children::get_family_meta((int) $family_id);
        $to_write = array();
        foreach ($meta as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            if (!isset($existing[$k]) || $existing[$k] === '') {
                $to_write[$k] = $v;
            }
        }
        if (!empty($to_write)) {
            Azure_User_Children::update_family_meta((int) $family_id, $to_write);
        }
    }

    // ─── Name parsing ──────────────────────────────────────────────────

    private function derive_first_name($full_name) {
        $full_name = trim((string) $full_name);
        if ($full_name === '') {
            return '';
        }
        $parts = preg_split('/\s+/', $full_name);
        return $parts[0];
    }

    private function derive_last_name($full_name) {
        $full_name = trim((string) $full_name);
        if ($full_name === '') {
            return '';
        }
        $parts = preg_split('/\s+/', $full_name);
        if (count($parts) <= 1) {
            return '';
        }
        return end($parts);
    }
}
