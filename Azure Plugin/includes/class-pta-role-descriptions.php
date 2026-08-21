<?php
/**
 * Extended PTA role descriptions: time commitment, point of contact,
 * pro tip, and a list of key responsibilities.
 *
 * Description / primary goal stays on pta_roles.description. The extra
 * fields live in pta_role_details (1:1) and pta_role_responsibilities (1:N).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_PTA_Role_Descriptions {

    const SEED_OPTION = 'azure_role_descriptions_seed_version';
    const SEED_VERSION = '2026-27.1';

    /**
     * Normalized text a listing filter can match against.
     */
    public static function search_haystack($role) {
        $parts = array(
            isset($role->name) ? $role->name : '',
            isset($role->slug) ? $role->slug : '',
            isset($role->department_name) ? $role->department_name : '',
            isset($role->description) ? $role->description : '',
            isset($role->time_commitment) ? $role->time_commitment : '',
            isset($role->point_of_contact) ? $role->point_of_contact : '',
            isset($role->pro_tip) ? $role->pro_tip : '',
        );
        if (!empty($role->responsibilities) && is_array($role->responsibilities)) {
            foreach ($role->responsibilities as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $parts[] = $item['heading'] ?? '';
                $parts[] = $item['body'] ?? '';
            }
        }
        return self::normalize_key(implode(' ', $parts));
    }

    /**
     * Collapse a role title or alias to a comparable key.
     */
    public static function normalize_key($value) {
        $value = strtolower(trim((string) $value));
        $value = str_replace(array('&', '–', '—', '/', ','), array('and', ' ', ' ', ' ', ' '), $value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        $value = trim(preg_replace('/\s+/', ' ', $value));
        return $value;
    }

    /**
     * Keys a live role can match: slug, name, and normalized forms.
     *
     * @param object $role Row with name and slug.
     * @return string[]
     */
    public static function role_match_keys($role) {
        $keys = array();
        $name = isset($role->name) ? (string) $role->name : '';
        $slug = isset($role->slug) ? (string) $role->slug : '';
        foreach (array($name, $slug) as $raw) {
            if ($raw === '') {
                continue;
            }
            $keys[] = strtolower(trim($raw));
            $keys[] = self::normalize_key($raw);
            $keys[] = str_replace('-', ' ', strtolower(trim($raw)));
        }
        return array_values(array_unique(array_filter($keys)));
    }

    /**
     * Keys a seed record can match: title plus aliases.
     *
     * @param array $seed
     * @return string[]
     */
    public static function seed_match_keys(array $seed) {
        $candidates = array();
        if (!empty($seed['title'])) {
            $candidates[] = $seed['title'];
        }
        if (!empty($seed['aliases']) && is_array($seed['aliases'])) {
            foreach ($seed['aliases'] as $alias) {
                $candidates[] = $alias;
            }
        }
        $keys = array();
        foreach ($candidates as $raw) {
            $raw = (string) $raw;
            if ($raw === '') {
                continue;
            }
            $keys[] = strtolower(trim($raw));
            $keys[] = self::normalize_key($raw);
            $keys[] = str_replace('-', ' ', strtolower(trim($raw)));
        }
        return array_values(array_unique(array_filter($keys)));
    }

    /**
     * Find the first live role whose keys intersect the seed keys.
     *
     * @param array    $seed
     * @param object[] $roles
     * @return object|null
     */
    public static function match_seed_to_role(array $seed, array $roles) {
        $seed_keys = array_fill_keys(self::seed_match_keys($seed), true);
        foreach ($roles as $role) {
            foreach (self::role_match_keys($role) as $key) {
                if (isset($seed_keys[$key])) {
                    return $role;
                }
            }
        }
        return null;
    }

    public static function details_table() {
        return Azure_PTA_Database::get_table_name('role_details');
    }

    public static function responsibilities_table() {
        return Azure_PTA_Database::get_table_name('role_responsibilities');
    }

    /**
     * Avoid SELECT errors on the first frontend request after a deploy,
     * before the backend version-bump has created the new tables.
     */
    private static $tables_ready = null;

    public static function reset_table_cache() {
        self::$tables_ready = null;
    }

    public static function tables_ready() {
        if (self::$tables_ready !== null) {
            return self::$tables_ready;
        }
        global $wpdb;
        $details = self::details_table();
        $resp = self::responsibilities_table();
        if (!$details || !$resp) {
            self::$tables_ready = false;
            return false;
        }
        $have_details = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $details));
        $have_resp = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $resp));
        self::$tables_ready = (bool) $have_details && (bool) $have_resp;
        return self::$tables_ready;
    }

    /**
     * Load details + responsibilities for one role.
     *
     * @return array{time_commitment:string,point_of_contact:string,pro_tip:string,responsibilities:array}
     */
    public static function get_for_role($role_id) {
        $empty = array(
            'time_commitment'  => '',
            'point_of_contact' => '',
            'pro_tip'          => '',
            'responsibilities' => array(),
        );
        $role_id = intval($role_id);
        if ($role_id <= 0 || !self::tables_ready()) {
            return $empty;
        }

        global $wpdb;
        $details_table = self::details_table();
        $resp_table = self::responsibilities_table();
        if (!$details_table || !$resp_table) {
            return $empty;
        }

        $details = $wpdb->get_row($wpdb->prepare(
            "SELECT time_commitment, point_of_contact, pro_tip FROM $details_table WHERE role_id = %d",
            $role_id
        ), ARRAY_A);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, sort_order, heading, body FROM $resp_table WHERE role_id = %d ORDER BY sort_order ASC, id ASC",
            $role_id
        ), ARRAY_A);

        $responsibilities = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $responsibilities[] = array(
                    'id'         => intval($row['id']),
                    'sort_order' => intval($row['sort_order']),
                    'heading'    => (string) ($row['heading'] ?? ''),
                    'body'       => (string) ($row['body'] ?? ''),
                );
            }
        }

        return array(
            'time_commitment'  => $details['time_commitment'] ?? '',
            'point_of_contact' => $details['point_of_contact'] ?? '',
            'pro_tip'          => $details['pro_tip'] ?? '',
            'responsibilities' => $responsibilities,
        );
    }

    /**
     * Attach description extras onto role objects (mutates in place).
     *
     * @param object[] $roles
     * @return object[]
     */
    public static function attach_to_roles($roles) {
        if (empty($roles) || !is_array($roles) || !self::tables_ready()) {
            return $roles;
        }

        $ids = array();
        foreach ($roles as $role) {
            if (!empty($role->id)) {
                $ids[] = intval($role->id);
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) {
            return $roles;
        }

        global $wpdb;
        $details_table = self::details_table();
        $resp_table = self::responsibilities_table();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $details_by_id = array();
        if ($details_table) {
            $details_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT role_id, time_commitment, point_of_contact, pro_tip FROM $details_table WHERE role_id IN ($placeholders)",
                $ids
            ));
            if (is_array($details_rows)) {
                foreach ($details_rows as $row) {
                    $details_by_id[intval($row->role_id)] = $row;
                }
            }
        }

        $resp_by_id = array();
        if ($resp_table) {
            $resp_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, role_id, sort_order, heading, body FROM $resp_table WHERE role_id IN ($placeholders) ORDER BY sort_order ASC, id ASC",
                $ids
            ));
            if (is_array($resp_rows)) {
                foreach ($resp_rows as $row) {
                    $rid = intval($row->role_id);
                    if (!isset($resp_by_id[$rid])) {
                        $resp_by_id[$rid] = array();
                    }
                    $resp_by_id[$rid][] = array(
                        'id'         => intval($row->id),
                        'sort_order' => intval($row->sort_order),
                        'heading'    => (string) $row->heading,
                        'body'       => (string) $row->body,
                    );
                }
            }
        }

        foreach ($roles as $role) {
            $rid = intval($role->id);
            $details = $details_by_id[$rid] ?? null;
            $role->time_commitment = $details ? (string) $details->time_commitment : '';
            $role->point_of_contact = $details ? (string) $details->point_of_contact : '';
            $role->pro_tip = $details ? (string) $details->pro_tip : '';
            $role->responsibilities = $resp_by_id[$rid] ?? array();
        }

        return $roles;
    }

    /**
     * Replace extras for a role. Empty responsibilities wipes the list.
     *
     * @param int   $role_id
     * @param array $data
     * @return bool
     */
    public static function save_for_role($role_id, array $data) {
        global $wpdb;
        $role_id = intval($role_id);
        if ($role_id <= 0 || !self::tables_ready()) {
            return false;
        }

        $details_table = self::details_table();
        $resp_table = self::responsibilities_table();
        if (!$details_table || !$resp_table) {
            return false;
        }

        $time_commitment = isset($data['time_commitment']) ? sanitize_textarea_field($data['time_commitment']) : '';
        $point_of_contact = isset($data['point_of_contact']) ? sanitize_text_field($data['point_of_contact']) : '';
        $pro_tip = isset($data['pro_tip']) ? sanitize_textarea_field($data['pro_tip']) : '';

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT role_id FROM $details_table WHERE role_id = %d",
            $role_id
        ));

        $fields = array(
            'time_commitment'  => $time_commitment,
            'point_of_contact' => $point_of_contact,
            'pro_tip'          => $pro_tip,
        );

        if ($existing) {
            $wpdb->update($details_table, $fields, array('role_id' => $role_id), array('%s', '%s', '%s'), array('%d'));
        } else {
            $fields['role_id'] = $role_id;
            $wpdb->insert($details_table, $fields, array('%s', '%s', '%s', '%d'));
        }

        $wpdb->delete($resp_table, array('role_id' => $role_id), array('%d'));

        $items = isset($data['responsibilities']) && is_array($data['responsibilities'])
            ? $data['responsibilities']
            : array();

        $order = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $heading = sanitize_text_field($item['heading'] ?? '');
            $body = sanitize_textarea_field($item['body'] ?? '');
            if ($heading === '' && $body === '') {
                continue;
            }
            $wpdb->insert(
                $resp_table,
                array(
                    'role_id'    => $role_id,
                    'sort_order' => $order,
                    'heading'    => $heading,
                    'body'       => $body,
                ),
                array('%d', '%d', '%s', '%s')
            );
            $order++;
        }

        return true;
    }

    public static function delete_for_role($role_id) {
        global $wpdb;
        $role_id = intval($role_id);
        if ($role_id <= 0) {
            return;
        }
        $details_table = self::details_table();
        $resp_table = self::responsibilities_table();
        if ($details_table) {
            $wpdb->delete($details_table, array('role_id' => $role_id), array('%d'));
        }
        if ($resp_table) {
            $wpdb->delete($resp_table, array('role_id' => $role_id), array('%d'));
        }
    }

    /**
     * Parse responsibilities from an AJAX payload (JSON string or arrays).
     *
     * @return array<int,array{heading:string,body:string}>
     */
    public static function parse_responsibilities_from_request($post) {
        if (isset($post['responsibilities']) && is_string($post['responsibilities'])) {
            $decoded = json_decode(wp_unslash($post['responsibilities']), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if (isset($post['responsibilities']) && is_array($post['responsibilities'])) {
            return $post['responsibilities'];
        }

        $headings = isset($post['resp_heading']) && is_array($post['resp_heading']) ? $post['resp_heading'] : array();
        $bodies = isset($post['resp_body']) && is_array($post['resp_body']) ? $post['resp_body'] : array();
        $count = max(count($headings), count($bodies));
        $items = array();
        for ($i = 0; $i < $count; $i++) {
            $items[] = array(
                'heading' => $headings[$i] ?? '',
                'body'    => $bodies[$i] ?? '',
            );
        }
        return $items;
    }

    /**
     * Whether a role has anything the public shortcode can show.
     */
    public static function role_has_public_copy($role) {
        if (!empty($role->description)) {
            return true;
        }
        if (!empty($role->time_commitment) || !empty($role->point_of_contact) || !empty($role->pro_tip)) {
            return true;
        }
        return !empty($role->responsibilities);
    }

    public static function seed_file_path() {
        return AZURE_PLUGIN_PATH . 'data/role-descriptions.json';
    }

    public static function load_seed_file() {
        $path = self::seed_file_path();
        if (!file_exists($path)) {
            return array();
        }
        $raw = file_get_contents($path);
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['roles']) || !is_array($data['roles'])) {
            return array();
        }
        return $data;
    }

    /**
     * One-shot: map seed records onto existing roles and write extras.
     * Does not create roles. Re-runs when SEED_VERSION changes.
     *
     * @return array{updated:int,skipped:string[],missing:string[]}
     */
    public static function maybe_seed_from_file($force = false) {
        $stored = get_option(self::SEED_OPTION, '');
        if (!$force && $stored === self::SEED_VERSION) {
            return array('updated' => 0, 'skipped' => array(), 'missing' => array());
        }

        self::reset_table_cache();
        if (!self::tables_ready()) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::warning('PTA: Role description tables are not ready; seed deferred');
            }
            return array('updated' => 0, 'skipped' => array(), 'missing' => array());
        }

        $seed = self::load_seed_file();
        if (empty($seed['roles'])) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::warning('PTA: Role description seed file missing or empty');
            }
            return array('updated' => 0, 'skipped' => array(), 'missing' => array());
        }

        $result = self::apply_seed($seed['roles']);
        update_option(self::SEED_OPTION, self::SEED_VERSION);

        if (class_exists('Azure_Logger')) {
            Azure_Logger::info(sprintf(
                'PTA: Role descriptions seeded %d roles; unmatched: %s',
                $result['updated'],
                empty($result['missing']) ? 'none' : implode(', ', $result['missing'])
            ));
        }

        return $result;
    }

    /**
     * @param array $seed_roles
     * @return array{updated:int,skipped:string[],missing:string[]}
     */
    public static function apply_seed(array $seed_roles) {
        global $wpdb;
        $roles_table = Azure_PTA_Database::get_table_name('roles');
        $roles = $wpdb->get_results("SELECT id, name, slug FROM $roles_table");
        if (!is_array($roles)) {
            $roles = array();
        }

        $updated = 0;
        $skipped = array();
        $missing = array();
        $used_ids = array();

        foreach ($seed_roles as $seed) {
            if (!is_array($seed) || empty($seed['title'])) {
                continue;
            }
            $match = self::match_seed_to_role($seed, $roles);
            if (!$match) {
                $missing[] = $seed['title'];
                continue;
            }
            if (isset($used_ids[intval($match->id)])) {
                $skipped[] = $seed['title'] . ' (already mapped)';
                continue;
            }

            $description = isset($seed['description']) ? sanitize_textarea_field($seed['description']) : '';
            if ($description !== '') {
                $wpdb->update(
                    $roles_table,
                    array('description' => $description),
                    array('id' => intval($match->id)),
                    array('%s'),
                    array('%d')
                );
            }

            self::save_for_role(intval($match->id), array(
                'time_commitment'  => $seed['time_commitment'] ?? '',
                'point_of_contact' => $seed['point_of_contact'] ?? '',
                'pro_tip'          => $seed['pro_tip'] ?? '',
                'responsibilities' => is_array($seed['responsibilities'] ?? null) ? $seed['responsibilities'] : array(),
            ));

            $used_ids[intval($match->id)] = $seed['title'];
            $updated++;
        }

        return array(
            'updated' => $updated,
            'skipped' => $skipped,
            'missing' => $missing,
        );
    }
}
