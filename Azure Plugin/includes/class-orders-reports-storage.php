<?php
/**
 * Orders Reports — Saved-report storage (CPT CRUD).
 *
 * Thin facade over WP's post functions so callers don't have to know
 * the postmeta key names or JSON encoding details. All saved reports
 * are shared across users with `manage_woocommerce`.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Orders_Reports_Storage {

    const META_CONFIG               = '_azure_or_config';
    const META_LAST_EXPORTED_AT     = '_azure_or_last_exported_at';
    const META_LAST_EXPORTED_BY     = '_azure_or_last_exported_by';
    const META_LAST_EXPORTED_ROWS   = '_azure_or_last_exported_rows';

    /**
     * Create or update a saved report.
     *
     * @return int|WP_Error  the report post ID, or WP_Error on failure
     */
    public static function save($name, array $config, $report_id = 0) {
        $name = sanitize_text_field((string) $name);
        if ($name === '') {
            return new WP_Error('azure_or_invalid_name', __('Report name is required.', 'azure-plugin'));
        }
        $clean = self::sanitize_config($config);

        $payload = array(
            'post_type'   => Azure_Orders_Reports_CPT::POST_TYPE_REPORT,
            'post_status' => 'publish',
            'post_title'  => $name,
            'post_author' => get_current_user_id(),
        );

        if ($report_id > 0) {
            $existing = get_post($report_id);
            if (!$existing || $existing->post_type !== Azure_Orders_Reports_CPT::POST_TYPE_REPORT) {
                return new WP_Error('azure_or_not_found', __('Report not found.', 'azure-plugin'));
            }
            $payload['ID'] = $report_id;
            $result = wp_update_post($payload, true);
        } else {
            $result = wp_insert_post($payload, true);
        }
        if (is_wp_error($result)) return $result;
        $id = (int) $result;

        update_post_meta($id, self::META_CONFIG, wp_json_encode($clean));
        return $id;
    }

    /**
     * Load a saved report into a [name, config] structure.
     *
     * @return array{id:int,name:string,config:array,author:int,modified:string,last_exported_at:string,last_exported_rows:int}|null
     */
    public static function load($report_id) {
        $report_id = (int) $report_id;
        if (!$report_id) return null;
        $p = get_post($report_id);
        if (!$p || $p->post_type !== Azure_Orders_Reports_CPT::POST_TYPE_REPORT) {
            return null;
        }
        $raw = get_post_meta($report_id, self::META_CONFIG, true);
        $config = is_string($raw) && $raw !== '' ? json_decode($raw, true) : array();
        if (!is_array($config)) $config = array();
        return array(
            'id'                  => $report_id,
            'name'                => $p->post_title,
            'config'              => self::sanitize_config($config),
            'author'              => (int) $p->post_author,
            'modified'            => $p->post_modified,
            'last_exported_at'    => (string) get_post_meta($report_id, self::META_LAST_EXPORTED_AT, true),
            'last_exported_by'    => (int) get_post_meta($report_id, self::META_LAST_EXPORTED_BY, true),
            'last_exported_rows'  => (int) get_post_meta($report_id, self::META_LAST_EXPORTED_ROWS, true),
        );
    }

    /**
     * List all saved reports, most recently modified first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function list_all() {
        $posts = get_posts(array(
            'post_type'      => Azure_Orders_Reports_CPT::POST_TYPE_REPORT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ));
        $out = array();
        foreach ($posts as $p) {
            $out[] = array(
                'id'                 => (int) $p->ID,
                'name'               => $p->post_title,
                'author'             => (int) $p->post_author,
                'modified'           => $p->post_modified,
                'last_exported_at'   => (string) get_post_meta($p->ID, self::META_LAST_EXPORTED_AT, true),
                'last_exported_by'   => (int) get_post_meta($p->ID, self::META_LAST_EXPORTED_BY, true),
                'last_exported_rows' => (int) get_post_meta($p->ID, self::META_LAST_EXPORTED_ROWS, true),
            );
        }
        return $out;
    }

    public static function delete($report_id) {
        $report_id = (int) $report_id;
        $p = get_post($report_id);
        if (!$p || $p->post_type !== Azure_Orders_Reports_CPT::POST_TYPE_REPORT) {
            return false;
        }
        return (bool) wp_trash_post($report_id);
    }

    public static function duplicate($report_id) {
        $loaded = self::load($report_id);
        if (!$loaded) return new WP_Error('azure_or_not_found', __('Report not found.', 'azure-plugin'));
        return self::save($loaded['name'] . ' (copy)', $loaded['config']);
    }

    public static function mark_exported($report_id, $rows) {
        $report_id = (int) $report_id;
        if (!$report_id) return;
        update_post_meta($report_id, self::META_LAST_EXPORTED_AT, current_time('mysql'));
        update_post_meta($report_id, self::META_LAST_EXPORTED_BY, get_current_user_id());
        update_post_meta($report_id, self::META_LAST_EXPORTED_ROWS, (int) $rows);
    }

    /**
     * Clean and validate a posted/loaded config to the canonical schema.
     * Drops unknown keys; coerces types; never trusts caller input.
     */
    public static function sanitize_config($config) {
        if (!is_array($config)) $config = array();
        $dr = isset($config['date_range']) && is_array($config['date_range']) ? $config['date_range'] : array();
        // 'previous_year' kept for backwards compatibility with saved
        // reports written before the "This school year" replacement.
        $valid_presets = array('', 'last_7_days', 'last_30_days', 'previous_month', 'previous_quarter', 'previous_year', 'this_school_year');
        $preset = isset($dr['preset']) ? (string) $dr['preset'] : '';
        if (!in_array($preset, $valid_presets, true)) $preset = '';

        $filters = isset($config['filters']) && is_array($config['filters']) ? $config['filters'] : array();
        $statuses = isset($filters['statuses']) && is_array($filters['statuses']) ? $filters['statuses'] : array();
        $statuses = array_values(array_filter(array_map('sanitize_key', $statuses)));
        if (empty($statuses)) {
            $statuses = array('processing', 'on-hold', 'completed', 'pending');
        }

        $granularity = isset($config['granularity']) ? (string) $config['granularity'] : 'line_item';
        if (!in_array($granularity, array('line_item', 'order'), true)) {
            $granularity = 'line_item';
        }

        $cols = isset($config['columns']) && is_array($config['columns']) ? $config['columns'] : array();
        $clean_cols = array();
        foreach ($cols as $c) {
            $key = is_array($c) ? (isset($c['key']) ? (string) $c['key'] : '') : (string) $c;
            $key = trim($key);
            if ($key === '') continue;
            // Allow ASCII printable + colons + spaces (product_field:Child's name).
            if (!preg_match('/^[\x20-\x7E]+$/', $key)) continue;
            $clean_cols[] = array('key' => $key);
        }
        if (empty($clean_cols)) {
            foreach (Azure_Orders_Reports_Columns::default_columns_for_granularity($granularity) as $k) {
                $clean_cols[] = array('key' => $k);
            }
        }

        return array(
            'version' => 1,
            'date_range' => array(
                'from'     => isset($dr['from']) ? sanitize_text_field((string) $dr['from']) : null,
                'to'       => isset($dr['to'])   ? sanitize_text_field((string) $dr['to'])   : null,
                'preset'   => $preset !== '' ? $preset : null,
                // When true, the resolver replaces `to` with right-now
                // at run-time. Lets a saved report with explicit dates
                // (e.g. "from 2026-08-01 to ___") stay accurate every
                // time it's exported without re-editing.
                'to_today' => !empty($dr['to_today']),
            ),
            'filters' => array(
                'statuses'     => $statuses,
                'product_ids'  => isset($filters['product_ids'])  ? array_map('intval', (array) $filters['product_ids'])  : array(),
                'category_ids' => isset($filters['category_ids']) ? array_map('intval', (array) $filters['category_ids']) : array(),
                'tag_ids'      => isset($filters['tag_ids'])      ? array_map('intval', (array) $filters['tag_ids'])      : array(),
            ),
            'granularity' => $granularity,
            'columns'     => $clean_cols,
        );
    }
}
