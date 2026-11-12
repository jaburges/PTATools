<?php
/**
 * AI Connectors tools for saved order reports (Selling > Reports): list,
 * read and export them, and create or edit them. Reports are saved through
 * Azure_Orders_Reports_Storage, so the result is the same as saving from
 * the report builder.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AI_Connectors_Report_Tools {

    const DATE_PRESETS = array('last_7_days', 'last_30_days', 'previous_month', 'previous_quarter', 'this_school_year');

    public static function register() {
        $r = 'Azure_AI_Connectors_Registry';
        $P = 'Azure_AI_Connectors_Policy';
        $self = __CLASS__;
        $wc = array('Azure_AI_Connectors_Tools', 'woocommerce_active');
        $read_caps = array('edit_posts', 'manage_woocommerce');

        $r::register('pta_list_reports', array(
            'group'        => 'reports',
            'title'        => 'List saved order reports',
            'description'  => 'Saved reports from Selling > Reports, with when each was last exported.',
            'kind'         => $P::KIND_READ,
            'permission'   => $read_caps,
            'access'       => 'reports',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'properties' => array()),
            'callback'     => array($self, 'list_reports'),
        ));
        $r::register('pta_get_report', array(
            'group'        => 'reports',
            'title'        => 'Get a saved order report',
            'description'  => 'The settings of one saved report: date range, order statuses, product, category and tag filters, one row per order or per line item, and its columns.',
            'kind'         => $P::KIND_READ,
            'permission'   => $read_caps,
            'access'       => 'reports',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'required' => array('report_id'), 'properties' => array(
                'report_id' => array('type' => 'integer', 'minimum' => 1),
            )),
            'callback'     => array($self, 'get_report'),
        ));
        $r::register('pta_export_report', array(
            'group'        => 'reports',
            'title'        => 'Export an order report as CSV',
            'description'  => 'Run a saved Selling > Reports report and return a single-use download link that expires after 15 minutes. Set include_csv to also get the CSV text (small reports only).',
            'kind'         => $P::KIND_READ,
            'permission'   => $read_caps,
            'access'       => 'reports',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'required' => array('report_id'), 'properties' => array(
                'report_id'   => array('type' => 'integer', 'minimum' => 1),
                'as_of_today' => array('type' => 'boolean', 'description' => 'End the date range now, like the dashboard Export button. Default true.'),
                'include_csv' => array('type' => 'boolean'),
            )),
            'callback'     => array($self, 'export_report'),
        ));

        $config_props = array(
            'name'         => array('type' => 'string', 'maxLength' => 200),
            'granularity'  => array('type' => 'string', 'enum' => array('line_item', 'order'), 'description' => 'line_item: one row per item bought (default). order: one row per order.'),
            'columns'      => array('type' => 'array', 'items' => array('type' => 'string'), 'maxItems' => 80, 'description' => 'Column keys in order, from pta_list_report_columns.'),
            'date_preset'  => array('type' => 'string', 'enum' => array_merge(array(''), self::DATE_PRESETS), 'description' => 'A rolling range. Send "" to use date_from and date_to instead.'),
            'date_from'    => array('type' => 'string', 'maxLength' => 20, 'description' => 'Start date, e.g. 2026-08-01.'),
            'date_to'      => array('type' => 'string', 'maxLength' => 20, 'description' => 'End date; leave out with to_today.'),
            'to_today'     => array('type' => 'boolean', 'description' => 'Always end the range at the moment of export.'),
            'statuses'     => array('type' => 'array', 'items' => array('type' => 'string'), 'maxItems' => 20, 'description' => 'Order statuses, e.g. processing, completed. Default: processing, on-hold, completed, pending.'),
            'product_ids'  => array('type' => 'array', 'items' => array('type' => 'integer'), 'maxItems' => 500),
            'category_ids' => array('type' => 'array', 'items' => array('type' => 'integer'), 'maxItems' => 200),
            'tag_ids'      => array('type' => 'array', 'items' => array('type' => 'integer'), 'maxItems' => 200),
        );

        $r::register('pta_list_report_columns', array(
            'group'        => 'reports',
            'title'        => 'List report columns',
            'description'  => 'Columns a report can include, with their keys, for one row per line item or one row per order. Product field columns are included.',
            'kind'         => $P::KIND_READ,
            'permission'   => array('manage_woocommerce'),
            'access'       => 'reports_edit',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'properties' => array(
                'granularity' => $config_props['granularity'],
            )),
            'callback'     => array($self, 'list_columns'),
        ));
        $r::register('pta_create_report', array(
            'group'        => 'reports',
            'title'        => 'Create a saved order report',
            'description'  => 'Save a new report in Selling > Reports. Call pta_list_report_columns first for column keys. Leave out columns for the default set.',
            'kind'         => $P::KIND_CREATE,
            'permission'   => array('manage_woocommerce'),
            'access'       => 'reports_edit',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'required' => array('name'), 'properties' => $config_props),
            'callback'     => array($self, 'create_report'),
        ));
        $r::register('pta_update_report', array(
            'group'        => 'reports',
            'title'        => 'Edit a saved order report',
            'description'  => 'Change a saved report. Only the settings you send change; columns, statuses and the id lists are replaced as a whole when sent.',
            'kind'         => $P::KIND_UPDATE,
            'permission'   => array('manage_woocommerce'),
            'access'       => 'reports_edit',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'required' => array('report_id'), 'properties' => array_merge(
                array('report_id' => array('type' => 'integer', 'minimum' => 1)),
                $config_props
            )),
            'callback'     => array($self, 'update_report'),
        ));
    }

    private static function load() {
        foreach (array(
            'class-orders-reports-cpt.php',
            'class-orders-reports-columns.php',
            'class-orders-reports-query.php',
            'class-orders-reports-export.php',
            'class-orders-reports-storage.php',
        ) as $file) {
            $path = AZURE_PLUGIN_PATH . 'includes/' . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
        return class_exists('Azure_Orders_Reports_Storage') && class_exists('Azure_Orders_Reports_Export') && class_exists('Azure_Orders_Reports_Query');
    }

    private static function unavailable() {
        return new WP_Error('pta_ai_unavailable', 'Order reports are not available.');
    }

    private static function summary(array $loaded) {
        return array(
            'id'                 => (int) $loaded['id'],
            'name'               => (string) $loaded['name'],
            'modified'           => (string) $loaded['modified'],
            'last_exported_at'   => $loaded['last_exported_at'] !== '' ? (string) $loaded['last_exported_at'] : null,
            'last_exported_rows' => (int) $loaded['last_exported_rows'],
        );
    }

    private static function describe(array $loaded) {
        $config = $loaded['config'];
        $labels = class_exists('Azure_Orders_Reports_Columns') ? Azure_Orders_Reports_Columns::all() : array();
        $columns = array();
        foreach ((array) $config['columns'] as $col) {
            $key = (string) $col['key'];
            $columns[] = array('key' => $key, 'label' => isset($labels[$key]) ? (string) $labels[$key]['label'] : $key);
        }
        $dr = $config['date_range'];
        return self::summary($loaded) + array(
            'granularity'  => $config['granularity'],
            'date_preset'  => (string) ($dr['preset'] ?? ''),
            'date_from'    => $dr['from'],
            'date_to'      => $dr['to'],
            'to_today'     => !empty($dr['to_today']),
            'statuses'     => $config['filters']['statuses'],
            'product_ids'  => $config['filters']['product_ids'],
            'category_ids' => $config['filters']['category_ids'],
            'tag_ids'      => $config['filters']['tag_ids'],
            'columns'      => $columns,
        );
    }

    public static function list_reports(array $args) {
        if (!self::load()) {
            return self::unavailable();
        }
        $out = array();
        foreach (Azure_Orders_Reports_Storage::list_all() as $r) {
            $out[] = self::summary($r);
        }
        return array('reports' => $out);
    }

    public static function get_report(array $args) {
        if (!self::load()) {
            return self::unavailable();
        }
        $loaded = Azure_Orders_Reports_Storage::load((int) $args['report_id']);
        if (!$loaded) {
            return new WP_Error('pta_ai_not_found', 'No saved report with that id.');
        }
        return self::describe($loaded);
    }

    public static function list_columns(array $args) {
        if (!self::load()) {
            return self::unavailable();
        }
        $granularity = ($args['granularity'] ?? 'line_item') === 'order' ? 'order' : 'line_item';
        $categories = Azure_Orders_Reports_Columns::categories();
        $out = array();
        foreach (Azure_Orders_Reports_Columns::for_granularity($granularity) as $key => $col) {
            $out[] = array(
                'key'      => (string) $key,
                'label'    => (string) $col['label'],
                'category' => (string) ($categories[$col['category']] ?? $col['category']),
            );
        }
        return array(
            'granularity' => $granularity,
            'default'     => Azure_Orders_Reports_Columns::default_columns_for_granularity($granularity),
            'columns'     => $out,
        );
    }

    /**
     * Apply tool arguments to a stored config. Unknown columns are refused
     * rather than dropped, so a typo does not silently change the report.
     *
     * @return array|WP_Error
     */
    public static function apply_args(array $config, array $args) {
        if (isset($args['granularity'])) {
            $config['granularity'] = $args['granularity'] === 'order' ? 'order' : 'line_item';
        }
        $granularity = isset($config['granularity']) && $config['granularity'] === 'order' ? 'order' : 'line_item';
        if (isset($args['columns'])) {
            $valid = Azure_Orders_Reports_Columns::for_granularity($granularity);
            $unknown = array_values(array_diff($args['columns'], array_keys($valid)));
            if ($unknown) {
                return new WP_Error('pta_ai_bad_args', 'Unknown columns for ' . $granularity . ' reports: ' . implode(', ', $unknown) . '. Call pta_list_report_columns for valid keys.');
            }
            $config['columns'] = array_map(function ($key) {
                return array('key' => $key);
            }, array_values(array_unique($args['columns'])));
        } elseif (isset($args['granularity']) && !empty($config['columns'])) {
            $valid = Azure_Orders_Reports_Columns::for_granularity($granularity);
            $config['columns'] = array_values(array_filter((array) $config['columns'], function ($col) use ($valid) {
                return isset($valid[$col['key'] ?? '']);
            }));
        }

        $dr = isset($config['date_range']) && is_array($config['date_range']) ? $config['date_range'] : array();
        if (array_key_exists('date_preset', $args)) {
            $dr['preset'] = $args['date_preset'] !== '' ? $args['date_preset'] : null;
        }
        foreach (array('date_from' => 'from', 'date_to' => 'to') as $in => $key) {
            if (!array_key_exists($in, $args)) {
                continue;
            }
            $value = trim((string) $args[$in]);
            if ($value !== '' && strtotime($value) === false) {
                return new WP_Error('pta_ai_bad_args', $in . ' must be a date like 2026-08-01.');
            }
            $dr[$key] = $value !== '' ? $value : null;
            if ($value !== '') {
                $dr['preset'] = null;
            }
        }
        if (isset($args['to_today'])) {
            $dr['to_today'] = (bool) $args['to_today'];
        }
        $config['date_range'] = $dr;

        $filters = isset($config['filters']) && is_array($config['filters']) ? $config['filters'] : array();
        foreach (array('statuses', 'product_ids', 'category_ids', 'tag_ids') as $key) {
            if (isset($args[$key])) {
                $filters[$key] = $args[$key];
            }
        }
        $config['filters'] = $filters;
        return $config;
    }

    public static function create_report(array $args) {
        if (!self::load()) {
            return self::unavailable();
        }
        $config = self::apply_args(array(), $args);
        if (is_wp_error($config)) {
            return $config;
        }
        $id = Azure_Orders_Reports_Storage::save((string) $args['name'], $config);
        if (is_wp_error($id)) {
            return $id;
        }
        return self::describe(Azure_Orders_Reports_Storage::load($id));
    }

    public static function update_report(array $args) {
        if (!self::load()) {
            return self::unavailable();
        }
        $loaded = Azure_Orders_Reports_Storage::load((int) $args['report_id']);
        if (!$loaded) {
            return new WP_Error('pta_ai_not_found', 'No saved report with that id.');
        }
        $patch = $args;
        unset($patch['report_id']);
        if (empty($patch)) {
            return new WP_Error('pta_ai_nothing', 'Send at least one setting to change.');
        }
        $config = self::apply_args($loaded['config'], $patch);
        if (is_wp_error($config)) {
            return $config;
        }
        $name = isset($args['name']) ? (string) $args['name'] : (string) $loaded['name'];
        $id = Azure_Orders_Reports_Storage::save($name, $config, (int) $loaded['id']);
        if (is_wp_error($id)) {
            return $id;
        }
        return self::describe(Azure_Orders_Reports_Storage::load($id));
    }

    public static function export_report(array $args) {
        if (!self::load()) {
            return self::unavailable();
        }
        $loaded = Azure_Orders_Reports_Storage::load((int) $args['report_id']);
        if (!$loaded) {
            return new WP_Error('pta_ai_not_found', 'No saved report with that id.');
        }
        $config = $loaded['config'];
        if (!array_key_exists('as_of_today', $args) || $args['as_of_today']) {
            if (!isset($config['date_range']) || !is_array($config['date_range'])) {
                $config['date_range'] = array('from' => null, 'to' => null, 'preset' => null);
            }
            $config['date_range']['to_today'] = true;
        }

        $path = Azure_AI_Connectors_Module::export_path();
        if (!$path) {
            return new WP_Error('pta_ai_export_failed', 'Could not create the export file.');
        }
        $handle = fopen($path, 'w');
        if ($handle === false) {
            return new WP_Error('pta_ai_export_failed', 'Could not create the export file.');
        }
        $rows = (new Azure_Orders_Reports_Export())->write_to_handle($config, $handle);
        fclose($handle);
        Azure_Orders_Reports_Storage::mark_exported((int) $loaded['id'], $rows);

        $filename = sanitize_file_name($loaded['name'] . '-' . gmdate('Ymd-His') . '.csv');
        $link = Azure_AI_Connectors_Module::create_download($path, $filename, get_current_user_id());
        $out = array(
            'report'       => $loaded['name'],
            'rows'         => (int) $rows,
            'filename'     => $filename,
            'download_url' => $link['url'],
            'expires_at'   => gmdate('c', $link['expires']),
            'note'         => 'The link works once, within 15 minutes, for whoever opens it first. Share it only with the user.',
        );
        if (!empty($args['include_csv'])) {
            $size = (int) filesize($path);
            if ($size <= 256 * 1024) {
                $csv = (string) file_get_contents($path);
                $out['csv'] = strpos($csv, "\xEF\xBB\xBF") === 0 ? substr($csv, 3) : $csv;
            } else {
                $out['csv_omitted'] = 'The CSV is larger than 256 KB; use the download link.';
            }
        }
        return $out;
    }
}
