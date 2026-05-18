<?php
/**
 * Diagnostics REST API
 *
 * Exposes read-only diagnostic endpoints secured by a per-site API key.
 * Endpoints live under /wp-json/pta-tools/v1/diagnostics/...
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Diagnostics_API {

    private static $instance = null;
    const OPTION_KEY = 'azure_diagnostics_api_key';

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Get or auto-generate the API key (stored as a WP option).
     */
    public static function get_api_key() {
        $key = get_option(self::OPTION_KEY);
        if (!$key) {
            $key = wp_generate_password(48, false);
            update_option(self::OPTION_KEY, $key, false);
        }
        return $key;
    }

    /**
     * Regenerate the API key.
     */
    public static function regenerate_api_key() {
        $key = wp_generate_password(48, false);
        update_option(self::OPTION_KEY, $key, false);
        return $key;
    }

    /**
     * Permission callback — validates the X-Diag-Key header OR the
     * caller's logged-in user has the `run_pta_diagnostics` capability
     * (or `manage_options` as a fallback during Phase 1).
     *
     * The diagnostic key path lets ops tools / monitors hit these
     * endpoints from outside WP without a session. The cap path lets
     * trusted admin users hit them straight from the browser.
     */
    public function check_api_key($request) {
        // Path 1: shared diagnostic key in header or ?key= query param.
        $provided = $request->get_header('X-Diag-Key');
        if (!$provided) {
            $provided = $request->get_param('key');
        }
        if ($provided && hash_equals(self::get_api_key(), $provided)) {
            return true;
        }

        // Path 2: logged-in user with the diagnostics capability.
        if (class_exists('Azure_Capabilities')) {
            return Azure_Capabilities::user_can('run_pta_diagnostics');
        }
        return current_user_can('manage_options');
    }

    public function register_routes() {
        $ns = 'pta-tools/v1';
        $auth = array($this, 'check_api_key');

        register_rest_route($ns, '/diagnostics/health', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_health'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/logs', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_logs'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/php-errors', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_php_errors'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/cron', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_cron'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/options', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_options'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/modules', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_modules'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/tables', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_tables'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/subscribers', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_subscribers'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/drop-tables', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_drop_tables'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/media-audit', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_media_audit'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/product-image-audit', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_product_image_audit'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/fix-media-dates', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_fix_media_dates'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/content-url-scan', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_content_url_scan'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/featured-image-audit', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_featured_image_audit'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/product-image-repair', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_product_image_repair'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/wc-order-ids', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'route_wc_order_ids'),
            'permission_callback' => $auth,
        ));

        register_rest_route($ns, '/diagnostics/insert-order', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'route_insert_order'),
            'permission_callback' => $auth,
        ));
    }

    // --- Route handlers ---

    public function route_health($request) {
        global $wpdb;

        $db_ok = (bool) $wpdb->get_var('SELECT 1');
        $upload_dir = wp_upload_dir();

        return rest_ensure_response(array(
            'status'      => 'ok',
            'wp_version'  => get_bloginfo('version'),
            'php_version' => phpversion(),
            'plugin_version' => defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : 'unknown',
            'memory_limit'     => ini_get('memory_limit'),
            'max_upload_size'  => size_format(wp_max_upload_size()),
            'max_execution_time' => ini_get('max_execution_time'),
            'database'    => $db_ok ? 'connected' : 'error',
            'uploads_writable' => wp_is_writable($upload_dir['basedir']),
            'server_time' => current_time('mysql'),
            'utc_time'    => gmdate('Y-m-d H:i:s'),
            'active_plugins' => array_values(get_option('active_plugins', array())),
        ));
    }

    public function route_logs($request) {
        $lines  = (int) $request->get_param('lines') ?: 100;
        $level  = sanitize_text_field($request->get_param('level') ?: '');
        $module = sanitize_text_field($request->get_param('module') ?: '');

        $lines = min(max($lines, 10), 500);

        if (!class_exists('Azure_Logger') || !Azure_Logger::is_initialized()) {
            return rest_ensure_response(array('logs' => array(), 'error' => 'Logger not initialized'));
        }

        $logs = Azure_Logger::get_formatted_logs($lines, $level, $module);

        return rest_ensure_response(array(
            'count' => count($logs),
            'logs'  => $logs,
        ));
    }

    public function route_php_errors($request) {
        $lines = (int) $request->get_param('lines') ?: 50;
        $lines = min(max($lines, 10), 200);

        $log_path = ini_get('error_log');
        if (!$log_path || !file_exists($log_path) || !is_readable($log_path)) {
            $debug_log = WP_CONTENT_DIR . '/debug.log';
            if (file_exists($debug_log) && is_readable($debug_log)) {
                $log_path = $debug_log;
            } else {
                return rest_ensure_response(array('lines' => array(), 'path' => $log_path ?: 'not set'));
            }
        }

        $all = file($log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $tail = array_slice($all, -$lines);

        return rest_ensure_response(array(
            'path'  => $log_path,
            'count' => count($tail),
            'lines' => $tail,
        ));
    }

    public function route_cron($request) {
        $crons = _get_cron_array();
        $plugin_hooks = array();

        $plugin_prefixes = array('azure_', 'onedrive_media_', 'pta_', 'backup_');

        foreach ($crons as $timestamp => $hooks) {
            foreach ($hooks as $hook => $events) {
                $is_plugin = false;
                foreach ($plugin_prefixes as $prefix) {
                    if (strpos($hook, $prefix) === 0) {
                        $is_plugin = true;
                        break;
                    }
                }
                if (!$is_plugin) {
                    continue;
                }
                foreach ($events as $key => $event) {
                    $plugin_hooks[] = array(
                        'hook'      => $hook,
                        'next_run'  => gmdate('Y-m-d H:i:s', $timestamp),
                        'schedule'  => $event['schedule'] ?: 'single',
                        'interval'  => isset($event['interval']) ? $event['interval'] : null,
                        'args'      => $event['args'],
                    );
                }
            }
        }

        usort($plugin_hooks, function ($a, $b) {
            return strcmp($a['next_run'], $b['next_run']);
        });

        return rest_ensure_response(array(
            'count' => count($plugin_hooks),
            'jobs'  => $plugin_hooks,
        ));
    }

    public function route_options($request) {
        $settings = Azure_Settings::get_all_settings();

        $safe = array();
        $sensitive = array('client_secret', 'password', 'secret', 'key', 'token');
        foreach ($settings as $k => $v) {
            $is_secret = false;
            foreach ($sensitive as $s) {
                if (stripos($k, $s) !== false) {
                    $is_secret = true;
                    break;
                }
            }
            $safe[$k] = $is_secret ? '***' : $v;
        }

        return rest_ensure_response($safe);
    }

    public function route_modules($request) {
        $modules = array(
            'sso'            => 'sso',
            'backup'         => 'backup',
            'onedrive_media' => 'onedrive_media',
            'calendar'       => 'calendar',
            'newsletter'     => 'newsletter',
            'pta_roles'      => 'pta_roles',
            'classes'        => 'classes',
            'tickets'        => 'tickets',
            'auction'        => 'auction',
        );

        $status = array();
        foreach ($modules as $label => $key) {
            $status[$label] = Azure_Settings::is_module_enabled($key);
        }

        return rest_ensure_response($status);
    }

    public function route_tables($request) {
        global $wpdb;

        $all_tables = $wpdb->get_col('SHOW TABLES');
        $prefix = $wpdb->prefix;

        $wp_core = array(
            'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts',
            'term_relationships', 'term_taxonomy', 'termmeta', 'terms', 'usermeta', 'users',
        );
        $core_set = array_flip(array_map(function ($t) use ($prefix) {
            return $prefix . $t;
        }, $wp_core));

        $core = array();
        $plugin = array();
        foreach ($all_tables as $table) {
            if (isset($core_set[$table])) {
                $core[] = $table;
            } else {
                $plugin[] = $table;
            }
        }

        return rest_ensure_response(array(
            'prefix'       => $prefix,
            'total'        => count($all_tables),
            'core_count'   => count($core),
            'plugin_count' => count($plugin),
            'core'         => $core,
            'plugin'       => $plugin,
        ));
    }

    public function route_subscribers($request) {
        global $wpdb;

        $result = array();

        $has_mailpoet = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}mailpoet_subscribers'");
        if ($has_mailpoet) {
            $rows = $wpdb->get_results(
                "SELECT email, first_name, last_name, status, created_at FROM {$wpdb->prefix}mailpoet_subscribers ORDER BY email",
                ARRAY_A
            );
            $result['mailpoet'] = array('count' => count($rows), 'subscribers' => $rows);
        } else {
            $result['mailpoet'] = array('count' => 0, 'subscribers' => array(), 'note' => 'Table not found');
        }

        $has_fluentcrm = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}fc_subscribers'");
        if ($has_fluentcrm) {
            $rows = $wpdb->get_results(
                "SELECT email, first_name, last_name, status, created_at FROM {$wpdb->prefix}fc_subscribers ORDER BY email",
                ARRAY_A
            );
            $result['fluentcrm'] = array('count' => count($rows), 'subscribers' => $rows);
        } else {
            $result['fluentcrm'] = array('count' => 0, 'subscribers' => array(), 'note' => 'Table not found');
        }

        $has_acymailing = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}acym_user'");
        if ($has_acymailing) {
            $rows = $wpdb->get_results(
                "SELECT email, name, active, confirmed, creation_date FROM {$wpdb->prefix}acym_user ORDER BY email",
                ARRAY_A
            );
            $result['acymailing'] = array('count' => count($rows), 'subscribers' => $rows);
        } else {
            $result['acymailing'] = array('count' => 0, 'subscribers' => array(), 'note' => 'Table not found');
        }

        $emails = array();
        foreach (array('mailpoet', 'fluentcrm', 'acymailing') as $src) {
            foreach ($result[$src]['subscribers'] as $sub) {
                $e = strtolower(trim($sub['email']));
                if (!isset($emails[$e])) {
                    $emails[$e] = array();
                }
                $emails[$e][] = $src;
            }
        }

        $only_mailpoet = array();
        $only_fluentcrm = array();
        $in_all = array();
        foreach ($emails as $email => $sources) {
            $has_acym = in_array('acymailing', $sources);
            if (!$has_acym && in_array('mailpoet', $sources) && !in_array('fluentcrm', $sources)) {
                $only_mailpoet[] = $email;
            } elseif (!$has_acym && in_array('fluentcrm', $sources) && !in_array('mailpoet', $sources)) {
                $only_fluentcrm[] = $email;
            }
            if (count($sources) === 3) {
                $in_all[] = $email;
            }
        }

        $result['comparison'] = array(
            'total_unique_emails' => count($emails),
            'in_all_three'        => count($in_all),
            'only_in_mailpoet'    => $only_mailpoet,
            'only_in_fluentcrm'   => $only_fluentcrm,
            'missing_from_acymailing' => array_values(array_diff(
                array_keys($emails),
                array_map('strtolower', array_column($result['acymailing']['subscribers'], 'email'))
            )),
        );

        return rest_ensure_response($result);
    }

    public function route_drop_tables($request) {
        global $wpdb;

        $body = json_decode($request->get_body(), true);
        $tables = isset($body['tables']) ? $body['tables'] : array();

        if (empty($tables) || !is_array($tables)) {
            return new \WP_Error('invalid_request', 'Provide a "tables" array in the JSON body.', array('status' => 400));
        }

        $prefix = $wpdb->prefix;
        $core_tables = array(
            $prefix . 'commentmeta', $prefix . 'comments', $prefix . 'links', $prefix . 'options',
            $prefix . 'postmeta', $prefix . 'posts', $prefix . 'term_relationships',
            $prefix . 'term_taxonomy', $prefix . 'termmeta', $prefix . 'terms',
            $prefix . 'usermeta', $prefix . 'users',
        );

        $dropped = array();
        $skipped = array();
        $errors  = array();

        foreach ($tables as $table) {
            $table = sanitize_key($table);

            if (in_array($table, $core_tables)) {
                $skipped[] = $table . ' (core table — protected)';
                continue;
            }

            if (strpos($table, $prefix) !== 0) {
                $skipped[] = $table . ' (wrong prefix)';
                continue;
            }

            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if (!$exists) {
                $skipped[] = $table . ' (not found)';
                continue;
            }

            $result = $wpdb->query("DROP TABLE `{$table}`");
            if ($result !== false) {
                $dropped[] = $table;
            } else {
                $errors[] = $table . ': ' . $wpdb->last_error;
            }
        }

        return rest_ensure_response(array(
            'dropped' => count($dropped),
            'skipped' => count($skipped),
            'errors'  => count($errors),
            'details' => array(
                'dropped' => $dropped,
                'skipped' => $skipped,
                'errors'  => $errors,
            ),
        ));
    }

    public function route_media_audit($request) {
        global $wpdb;

        $year = $request->get_param('year') ?: '2026';
        $upload_dir = wp_upload_dir();
        $basedir = $upload_dir['basedir'];
        $baseurl = $upload_dir['baseurl'];

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.guid, pm.meta_value AS attached_file
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment'
               AND pm.meta_value LIKE %s
             ORDER BY pm.meta_value",
            $year . '/%'
        ), ARRAY_A);

        $missing_file = array();
        $wrong_guid = array();
        $sharepoint_urls = array();
        $no_month = array();
        $ok = 0;

        foreach ($rows as $row) {
            $rel = $row['attached_file'];
            $full_path = $basedir . '/' . $rel;
            $expected_url = $baseurl . '/' . $rel;

            if (preg_match('|^https?://|', $rel)) {
                $sharepoint_urls[] = array(
                    'id' => $row['ID'],
                    'title' => $row['post_title'],
                    'attached_file' => $rel,
                );
                continue;
            }

            if (!preg_match('|^\d{4}/\d{2}/|', $rel)) {
                $no_month[] = array(
                    'id' => $row['ID'],
                    'title' => $row['post_title'],
                    'attached_file' => $rel,
                    'file_exists' => file_exists($full_path),
                );
                continue;
            }

            $issues = array();
            if (!file_exists($full_path)) {
                $issues[] = 'file_missing';
            }
            if ($row['guid'] !== $expected_url) {
                $issues[] = 'guid_mismatch';
            }

            if (!empty($issues)) {
                $entry = array(
                    'id' => $row['ID'],
                    'title' => $row['post_title'],
                    'attached_file' => $rel,
                    'guid' => $row['guid'],
                    'expected_url' => $expected_url,
                    'issues' => $issues,
                );
                if (in_array('file_missing', $issues)) {
                    $missing_file[] = $entry;
                } else {
                    $wrong_guid[] = $entry;
                }
            } else {
                $ok++;
            }
        }

        $product_images = $wpdb->get_results(
            "SELECT p.ID, p.post_title,
                    tm.meta_value AS thumbnail_id,
                    pm2.meta_value AS thumbnail_file
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} tm ON p.ID = tm.post_id AND tm.meta_key = '_thumbnail_id'
             LEFT JOIN {$wpdb->postmeta} pm2 ON tm.meta_value = pm2.post_id AND pm2.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'product'
               AND p.post_status = 'publish'
               AND (tm.meta_value IS NULL OR tm.meta_value = '' OR pm2.meta_value IS NULL)",
            ARRAY_A
        );

        $products_no_image = array();
        foreach ($product_images as $pi) {
            $products_no_image[] = array(
                'product_id' => $pi['ID'],
                'title' => $pi['post_title'],
                'thumbnail_id' => $pi['thumbnail_id'],
            );
        }

        return rest_ensure_response(array(
            'year' => $year,
            'total_attachments' => count($rows),
            'ok' => $ok,
            'missing_file' => array('count' => count($missing_file), 'items' => array_slice($missing_file, 0, 50)),
            'wrong_guid' => array('count' => count($wrong_guid), 'items' => array_slice($wrong_guid, 0, 50)),
            'sharepoint_urls' => array('count' => count($sharepoint_urls), 'items' => array_slice($sharepoint_urls, 0, 20)),
            'no_month_subfolder' => array('count' => count($no_month), 'items' => array_slice($no_month, 0, 20)),
            'products_missing_image' => array('count' => count($products_no_image), 'items' => array_slice($products_no_image, 0, 20)),
        ));
    }

    public function route_fix_media_dates($request) {
        global $wpdb;
        @set_time_limit(300);

        $dry_run = $request->get_param('dry_run') !== 'false';

        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_date, pm.meta_value AS attached_file
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment'
             ORDER BY p.ID"
        );

        $updated = 0;
        $skipped = 0;
        $already_correct = 0;
        $no_date_path = 0;
        $samples = array();

        foreach ($rows as $row) {
            if (!preg_match('#^(\d{4})/(\d{2})/#', $row->attached_file, $m)) {
                $no_date_path++;
                continue;
            }

            $year  = (int) $m[1];
            $month = (int) $m[2];

            if ($year < 2000 || $year > 2099 || $month < 1 || $month > 12) {
                $skipped++;
                continue;
            }

            $existing_year  = (int) date('Y', strtotime($row->post_date));
            $existing_month = (int) date('m', strtotime($row->post_date));

            if ($existing_year === $year && $existing_month === $month) {
                $already_correct++;
                continue;
            }

            $new_date = sprintf('%04d-%02d-01 00:00:00', $year, $month);

            if (!$dry_run) {
                $wpdb->update(
                    $wpdb->posts,
                    array(
                        'post_date'     => $new_date,
                        'post_date_gmt' => $new_date,
                    ),
                    array('ID' => $row->ID),
                    array('%s', '%s'),
                    array('%d')
                );
            }

            $updated++;
            if (count($samples) < 10) {
                $samples[] = array(
                    'id'        => (int) $row->ID,
                    'file'      => $row->attached_file,
                    'old_date'  => $row->post_date,
                    'new_date'  => $new_date,
                );
            }
        }

        return rest_ensure_response(array(
            'dry_run'         => $dry_run,
            'total_checked'   => count($rows),
            'already_correct' => $already_correct,
            'updated'         => $updated,
            'skipped'         => $skipped,
            'no_date_in_path' => $no_date_path,
            'samples'         => $samples,
        ));
    }

    public function route_content_url_scan($request) {
        global $wpdb;
        @set_time_limit(300);

        $all_like = array(
            'sharepoint'  => '%sharepoint.com%',
            'attachments' => '%attachments.office.net%',
            '1drv'        => '%1drv.ms%',
        );
        $all_php = array(
            'sharepoint'  => '#https?://[a-zA-Z0-9\-]+\.sharepoint\.com[^\"\'\s<>]+#i',
            'attachments' => '#https?://attachments\.office\.net[^\"\'\s<>]+#i',
            '1drv'        => '#https?://1drv\.ms[^\"\'\s<>]+#i',
        );

        $filter = $request->get_param('pattern');
        if ($filter && isset($all_like[$filter])) {
            $like_patterns = array($filter => $all_like[$filter]);
            $php_patterns  = array($filter => $all_php[$filter]);
        } else {
            $like_patterns = $all_like;
            $php_patterns  = $all_php;
        }

        $results = array();
        $summary = array();

        foreach ($like_patterns as $label => $like) {
            $rows = $wpdb->get_results(
                "SELECT ID, post_title, post_type, post_status FROM {$wpdb->posts} WHERE post_content LIKE '{$like}' AND post_status IN ('publish','draft','private','pending') ORDER BY post_type, ID LIMIT 200"
            );

            $items = array();
            foreach ($rows as $row) {
                $content = get_post_field('post_content', $row->ID);
                preg_match_all($php_patterns[$label], $content, $matches);
                $unique_urls = array_values(array_unique($matches[0]));
                $items[] = array(
                    'post_id'    => (int) $row->ID,
                    'title'      => $row->post_title,
                    'post_type'  => $row->post_type,
                    'status'     => $row->post_status,
                    'url_count'  => count($unique_urls),
                    'sample_urls' => array_slice($unique_urls, 0, 3),
                );
            }

            $summary[$label] = count($items);
            if (!empty($items)) {
                $results[$label] = $items;
            }
        }

        if (!$filter || $filter === 'attachments_meta') {
            $guid_rows = $wpdb->get_results(
                "SELECT ID, post_title, guid FROM {$wpdb->posts} WHERE post_type = 'attachment' AND (guid LIKE '%sharepoint.com%' OR guid LIKE '%attachments.office.net%' OR guid LIKE '%1drv.ms%') LIMIT 50"
            );
            $bad_guids = array();
            foreach ($guid_rows as $gr) {
                $bad_guids[] = array(
                    'attachment_id' => (int) $gr->ID,
                    'title'         => $gr->post_title,
                    'guid'          => $gr->guid,
                );
            }

            $meta_rows = $wpdb->get_results(
                "SELECT p.ID, p.post_title, pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_wp_attached_file' AND (pm.meta_value LIKE '%sharepoint.com%' OR pm.meta_value LIKE '%attachments.office.net%' OR pm.meta_value LIKE '%1drv.ms%') LIMIT 50"
            );
            $bad_meta = array();
            foreach ($meta_rows as $mr) {
                $bad_meta[] = array(
                    'attachment_id' => (int) $mr->ID,
                    'title'         => $mr->post_title,
                    'attached_file' => $mr->meta_value,
                );
            }
        }

        $response = array('summary' => $summary, 'content_matches' => $results);
        if (isset($bad_guids)) {
            $response['attachment_bad_guids'] = array('count' => count($bad_guids), 'items' => $bad_guids);
        }
        if (isset($bad_meta)) {
            $response['attachment_bad_meta'] = array('count' => count($bad_meta), 'items' => $bad_meta);
        }

        return rest_ensure_response($response);
    }

    public function route_featured_image_audit($request) {
        global $wpdb;

        $upload_dir = wp_upload_dir();
        $basedir = $upload_dir['basedir'];

        $post_type = $request->get_param('post_type') ?: 'post';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_status, p.post_type,
                    thumb.meta_value AS thumbnail_id
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} thumb ON p.ID = thumb.post_id AND thumb.meta_key = '_thumbnail_id'
             WHERE p.post_type = %s
               AND p.post_status IN ('publish', 'draft', 'private')
             ORDER BY p.ID",
            $post_type
        ), ARRAY_A);

        $ok = 0;
        $no_thumbnail = array();
        $broken = array();
        $never_had = 0;

        foreach ($rows as $row) {
            $tid = intval($row['thumbnail_id']);

            if (!$tid) {
                $no_thumbnail[] = array(
                    'id' => $row['ID'],
                    'title' => $row['post_title'],
                    'status' => $row['post_status'],
                );
                continue;
            }

            $att = get_post($tid);
            $file = get_post_meta($tid, '_wp_attached_file', true);

            if (!$att) {
                $broken[] = array(
                    'id' => $row['ID'],
                    'title' => $row['post_title'],
                    'status' => $row['post_status'],
                    'thumbnail_id' => $tid,
                    'issue' => 'attachment_deleted',
                );
            } elseif (!$file || !file_exists($basedir . '/' . $file)) {
                $broken[] = array(
                    'id' => $row['ID'],
                    'title' => $row['post_title'],
                    'status' => $row['post_status'],
                    'thumbnail_id' => $tid,
                    'attached_file' => $file,
                    'issue' => $file ? 'file_missing' : 'no_meta',
                );
            } else {
                $ok++;
            }
        }

        $published_no_thumb = array_filter($no_thumbnail, function ($i) { return $i['status'] === 'publish'; });
        $published_broken = array_filter($broken, function ($i) { return $i['status'] === 'publish'; });

        return rest_ensure_response(array(
            'post_type' => $post_type,
            'total' => count($rows),
            'ok' => $ok,
            'no_thumbnail_set' => array(
                'total' => count($no_thumbnail),
                'published' => count($published_no_thumb),
                'items' => array_values(array_slice($no_thumbnail, 0, 50)),
            ),
            'broken_thumbnail' => array(
                'total' => count($broken),
                'published' => count($published_broken),
                'items' => array_values(array_slice($broken, 0, 50)),
            ),
        ));
    }

    public function route_product_image_repair($request) {
        global $wpdb;

        $dry_run = $request->get_param('dry_run') !== 'false';
        $post_type = $request->get_param('post_type') ?: 'all';
        $upload_dir = wp_upload_dir();
        $basedir = $upload_dir['basedir'];

        $where_type = $post_type === 'all'
            ? "p.post_type IN ('post', 'page', 'product', 'tribe_events')"
            : $wpdb->prepare("p.post_type = %s", $post_type);

        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_content, p.post_status, p.post_type,
                    thumb.meta_value AS thumbnail_id
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} thumb ON p.ID = thumb.post_id AND thumb.meta_key = '_thumbnail_id'
             WHERE {$where_type}
               AND p.post_status IN ('publish', 'draft', 'private')
               AND (thumb.meta_value IS NULL OR thumb.meta_value = '' OR thumb.meta_value = '0')
             ORDER BY p.ID",
            ARRAY_A
        );

        $repaired = array();
        $unresolved = array();

        foreach ($rows as $row) {
            $found_id = $this->find_image_in_content($row['post_content'], $basedir, $wpdb);

            if ($found_id) {
                if (!$dry_run) {
                    update_post_meta($row['ID'], '_thumbnail_id', $found_id['id']);
                }
                $repaired[] = array(
                    'post_id' => $row['ID'],
                    'title' => $row['post_title'],
                    'type' => $row['post_type'],
                    'status' => $row['post_status'],
                    'attachment_id' => $found_id['id'],
                    'file' => $found_id['file'],
                    'method' => $found_id['method'],
                );
            } else {
                $unresolved[] = array(
                    'post_id' => $row['ID'],
                    'title' => $row['post_title'],
                    'type' => $row['post_type'],
                    'status' => $row['post_status'],
                    'has_images' => (bool) preg_match('/<img/i', $row['post_content']),
                );
            }
        }

        return rest_ensure_response(array(
            'dry_run' => $dry_run,
            'post_type' => $post_type,
            'total_missing' => count($rows),
            'repaired' => array('count' => count($repaired), 'items' => array_slice($repaired, 0, 100)),
            'unresolved' => array('count' => count($unresolved), 'items' => array_slice($unresolved, 0, 100)),
        ));
    }

    private function find_image_in_content($content, $basedir, $wpdb) {
        if (preg_match_all('/wp-content\/uploads\/(\d{4}\/\d{2}\/[^\s"\'<>)]+\.(jpe?g|png|gif|webp))/i', $content, $matches)) {
            foreach ($matches[1] as $rel_path) {
                $rel_clean = preg_replace('/-\d+x\d+(\.\w+)$/', '$1', $rel_path);
                foreach (array($rel_path, $rel_clean) as $try_path) {
                    $att = $wpdb->get_var($wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta}
                         WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
                        $try_path
                    ));
                    if ($att && file_exists($basedir . '/' . $try_path)) {
                        return array('id' => intval($att), 'file' => $try_path, 'method' => 'content_url');
                    }
                }
            }
        }

        if (preg_match_all('/wp-image-(\d+)/i', $content, $matches)) {
            foreach ($matches[1] as $att_id) {
                $att_id = intval($att_id);
                $file = get_post_meta($att_id, '_wp_attached_file', true);
                if ($file && file_exists($basedir . '/' . $file)) {
                    return array('id' => $att_id, 'file' => $file, 'method' => 'wp_image_class');
                }
            }
        }

        if (preg_match_all('/src=["\']([^"\']*\/wp-content\/uploads\/[^"\']+\.(jpe?g|png|gif|webp))["\']/', $content, $matches)) {
            foreach ($matches[1] as $url) {
                if (preg_match('/wp-content\/uploads\/(\d{4}\/\d{2}\/[^"\'?#]+)/i', $url, $m)) {
                    $rel = $m[1];
                    $rel_clean = preg_replace('/-\d+x\d+(\.\w+)$/', '$1', $rel);
                    foreach (array($rel, $rel_clean) as $try_path) {
                        $att = $wpdb->get_var($wpdb->prepare(
                            "SELECT post_id FROM {$wpdb->postmeta}
                             WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
                            $try_path
                        ));
                        if ($att) {
                            return array('id' => intval($att), 'file' => $try_path, 'method' => 'img_src');
                        }
                    }
                }
            }
        }

        return null;
    }

    public function route_product_image_audit($request) {
        global $wpdb;

        $upload_dir = wp_upload_dir();
        $basedir = $upload_dir['basedir'];
        $baseurl = $upload_dir['baseurl'];

        $products = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_status,
                    thumb.meta_value AS thumbnail_id,
                    gallery.meta_value AS gallery_ids
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} thumb ON p.ID = thumb.post_id AND thumb.meta_key = '_thumbnail_id'
             LEFT JOIN {$wpdb->postmeta} gallery ON p.ID = gallery.post_id AND gallery.meta_key = '_product_image_gallery'
             WHERE p.post_type = 'product'
               AND p.post_status IN ('publish', 'draft', 'private')
             ORDER BY p.ID",
            ARRAY_A
        );

        $broken_thumb = array();
        $broken_gallery = array();
        $no_thumb = array();
        $ok = 0;

        foreach ($products as $prod) {
            $tid = intval($prod['thumbnail_id']);
            if (!$tid) {
                $no_thumb[] = array(
                    'product_id' => $prod['ID'],
                    'title' => $prod['post_title'],
                    'status' => $prod['post_status'],
                );
                continue;
            }

            $att_file = get_post_meta($tid, '_wp_attached_file', true);
            $att_exists = get_post($tid);

            if (!$att_exists) {
                $broken_thumb[] = array(
                    'product_id' => $prod['ID'],
                    'title' => $prod['post_title'],
                    'thumbnail_id' => $tid,
                    'issue' => 'attachment_post_missing',
                );
            } elseif (!$att_file || !file_exists($basedir . '/' . $att_file)) {
                $broken_thumb[] = array(
                    'product_id' => $prod['ID'],
                    'title' => $prod['post_title'],
                    'thumbnail_id' => $tid,
                    'attached_file' => $att_file,
                    'issue' => $att_file ? 'file_missing_on_disk' : 'no_attached_file_meta',
                );
            } else {
                $ok++;
            }

            $gal = trim($prod['gallery_ids']);
            if ($gal) {
                $gal_ids = array_filter(array_map('intval', explode(',', $gal)));
                foreach ($gal_ids as $gid) {
                    $gatt = get_post($gid);
                    $gfile = get_post_meta($gid, '_wp_attached_file', true);
                    if (!$gatt || !$gfile || !file_exists($basedir . '/' . $gfile)) {
                        $broken_gallery[] = array(
                            'product_id' => $prod['ID'],
                            'title' => $prod['post_title'],
                            'gallery_attachment_id' => $gid,
                            'attached_file' => $gfile ?: null,
                            'issue' => !$gatt ? 'attachment_post_missing' : (!$gfile ? 'no_attached_file_meta' : 'file_missing_on_disk'),
                        );
                    }
                }
            }
        }

        return rest_ensure_response(array(
            'total_products' => count($products),
            'ok' => $ok,
            'no_thumbnail_set' => array('count' => count($no_thumb), 'items' => $no_thumb),
            'broken_thumbnail' => array('count' => count($broken_thumb), 'items' => array_slice($broken_thumb, 0, 30)),
            'broken_gallery' => array('count' => count($broken_gallery), 'items' => array_slice($broken_gallery, 0, 30)),
        ));
    }

    public function route_wc_order_ids($request) {
        global $wpdb;

        $max_post_id = $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}");
        $max_wc_order_id = $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}wc_orders");
        $max_addr_id = $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}wc_order_addresses");
        $max_op_id = $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}wc_order_operational_data");
        $max_item_id = $wpdb->get_var("SELECT MAX(order_item_id) FROM {$wpdb->prefix}woocommerce_order_items");
        $max_itemmeta_id = $wpdb->get_var("SELECT MAX(meta_id) FROM {$wpdb->prefix}woocommerce_order_itemmeta");
        $max_ordermeta_id = $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}wc_orders_meta");
        $max_postmeta_id = $wpdb->get_var("SELECT MAX(meta_id) FROM {$wpdb->postmeta}");
        $max_stats_id = $wpdb->get_var("SELECT MAX(order_id) FROM {$wpdb->prefix}wc_order_stats");
        $max_product_lookup_id = $wpdb->get_var("SELECT MAX(order_item_id) FROM {$wpdb->prefix}wc_order_product_lookup");

        $products = array();
        $product_names = array('Wilder Yearbook', 'Mariners Game', 'Celebration Book', 'Chess');
        foreach ($product_names as $name) {
            $found = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'product' AND post_title LIKE %s AND post_status = 'publish' LIMIT 3",
                '%' . $wpdb->esc_like($name) . '%'
            ), ARRAY_A);
            $products[$name] = $found;
        }

        $customers = array();
        $emails = array('Reneehelland@hotmail.com', 'nicolecmiles@gmail.com', 'glocklme@comcast.net', 'christine.d.hang@gmail.com', 'z.weins@comcast.net');
        foreach ($emails as $email) {
            $cust = $wpdb->get_row($wpdb->prepare(
                "SELECT customer_id FROM {$wpdb->prefix}wc_customer_lookup WHERE email = %s LIMIT 1",
                $email
            ), ARRAY_A);
            $customers[$email] = $cust ? intval($cust['customer_id']) : 0;
        }

        return rest_ensure_response(array(
            'max_post_id' => intval($max_post_id),
            'max_wc_order_id' => intval($max_wc_order_id),
            'max_addr_id' => intval($max_addr_id),
            'max_op_id' => intval($max_op_id),
            'max_item_id' => intval($max_item_id),
            'max_itemmeta_id' => intval($max_itemmeta_id),
            'max_ordermeta_id' => intval($max_ordermeta_id),
            'max_postmeta_id' => intval($max_postmeta_id),
            'max_stats_id' => intval($max_stats_id),
            'max_product_lookup_id' => intval($max_product_lookup_id),
            'products' => $products,
            'customers' => $customers,
        ));
    }

    public function route_insert_order($request) {
        global $wpdb;

        $body = $request->get_json_params();
        if (empty($body['sql_statements']) || !is_array($body['sql_statements'])) {
            return new WP_Error('missing_sql', 'Provide sql_statements array', array('status' => 400));
        }

        if (!empty($body['dry_run'])) {
            return rest_ensure_response(array(
                'dry_run' => true,
                'statement_count' => count($body['sql_statements']),
                'statements' => $body['sql_statements'],
            ));
        }

        $results = array();
        $wpdb->query('START TRANSACTION');

        foreach ($body['sql_statements'] as $i => $sql) {
            $ok = $wpdb->query($sql);
            if ($ok === false) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('sql_error', "Statement $i failed: " . $wpdb->last_error, array('status' => 500));
            }
            $results[] = array('index' => $i, 'affected' => $ok);
        }

        $wpdb->query('COMMIT');

        return rest_ensure_response(array(
            'success' => true,
            'results' => $results,
        ));
    }
}
