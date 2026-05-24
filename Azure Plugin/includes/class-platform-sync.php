<?php
/**
 * Production ↔ staging platform operations (App Service deployment slots).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Platform_Sync {

    /**
     * Status payload for the Danger Zone UI.
     *
     * @return array{
     *   available: bool,
     *   reason: string,
     *   is_production_slot: bool,
     *   staging_database: string,
     *   staging_site_url: string,
     *   production_site_url: string
     * }
     */
    public static function get_status() {
        $is_prod_slot = self::is_production_slot();
        $staging_db   = self::get_staging_database_name();
        $staging_url  = self::get_staging_site_url();
        $prod_url     = untrailingslashit(home_url());

        if (!$is_prod_slot) {
            return array(
                'available'          => false,
                'reason'             => __('You are viewing this on the staging slot. Open the same page on the production slot to sync prod → staging.', 'azure-plugin'),
                'is_production_slot' => false,
                'staging_database'   => $staging_db,
                'staging_site_url'   => $staging_url,
                'production_site_url'=> $prod_url,
            );
        }

        if ($staging_db === '') {
            return array(
                'available'          => false,
                'reason'             => __('No staging database is configured. Sync requires an App Service deployment slot with a separate staging database. Set "Staging database name" in Platform settings below, or use the naming convention prod_db + "_staging".', 'azure-plugin'),
                'is_production_slot' => true,
                'staging_database'   => '',
                'staging_site_url'   => $staging_url,
                'production_site_url'=> $prod_url,
            );
        }

        if ($staging_db === DB_NAME) {
            return array(
                'available'          => false,
                'reason'             => __('Staging database name matches the production database. Configure a separate staging database before syncing.', 'azure-plugin'),
                'is_production_slot' => true,
                'staging_database'   => $staging_db,
                'staging_site_url'   => $staging_url,
                'production_site_url'=> $prod_url,
            );
        }

        if (!self::database_exists($staging_db)) {
            return array(
                'available'          => false,
                'reason'             => sprintf(
                    /* translators: %s: database name */
                    __('Staging database "%s" was not found on this database server. Provision a staging slot and database first.', 'azure-plugin'),
                    $staging_db
                ),
                'is_production_slot' => true,
                'staging_database'   => $staging_db,
                'staging_site_url'   => $staging_url,
                'production_site_url'=> $prod_url,
            );
        }

        return array(
            'available'          => true,
            'reason'             => '',
            'is_production_slot' => true,
            'staging_database'   => $staging_db,
            'staging_site_url'   => $staging_url,
            'production_site_url'=> $prod_url,
        );
    }

    /**
     * Copy production database contents into the staging database.
     *
     * @return array{success:bool,message:string,details?:array}
     */
    public static function sync_prod_db_to_staging() {
        $status = self::get_status();
        if (!$status['available']) {
            return array(
                'success' => false,
                'message' => $status['reason'],
            );
        }

        if (!class_exists('Azure_Backup_Engine')) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-backup-engine.php';
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $staging_db  = $status['staging_database'];
        $staging_url = $status['staging_site_url'];
        $prod_url    = $status['production_site_url'];
        $tmpdir      = trailingslashit(get_temp_dir()) . 'pta-platform-sync-' . wp_generate_password(8, false);
        wp_mkdir_p($tmpdir);

        $backup_id = 'platform-sync-' . gmdate('Ymd-His');
        $engine    = new Azure_Backup_Engine($backup_id, $tmpdir, null);

        Azure_Logger::info('Platform sync: dumping production database', 'Platform');

        try {
            $dump_path = $engine->backup_database();
        } catch (Exception $e) {
            self::rrmdir($tmpdir);
            return array(
                'success' => false,
                'message' => sprintf(__('Database dump failed: %s', 'azure-plugin'), $e->getMessage()),
            );
        }

        if (!is_readable($dump_path)) {
            self::rrmdir($tmpdir);
            return array(
                'success' => false,
                'message' => __('Database dump file was not created.', 'azure-plugin'),
            );
        }

        $sql_path = $dump_path;
        if (substr($dump_path, -3) === '.gz') {
            $sql_path = $tmpdir . '/' . $backup_id . '.sql';
            $gz       = gzopen($dump_path, 'rb');
            $out      = fopen($sql_path, 'wb');
            if (!$gz || !$out) {
                self::rrmdir($tmpdir);
                return array(
                    'success' => false,
                    'message' => __('Could not decompress database dump.', 'azure-plugin'),
                );
            }
            while (!gzeof($gz)) {
                fwrite($out, gzread($gz, 65536));
            }
            gzclose($gz);
            fclose($out);
        }

        $mysqli = self::connect_database($staging_db);
        if (!$mysqli) {
            self::rrmdir($tmpdir);
            return array(
                'success' => false,
                'message' => __('Could not connect to the staging database. Check that the MySQL user can access both databases.', 'azure-plugin'),
            );
        }

        Azure_Logger::info('Platform sync: restoring dump into staging database ' . $staging_db, 'Platform');

        $import = self::import_sql_file($mysqli, $sql_path);
        if (!$import['success']) {
            mysqli_close($mysqli);
            self::rrmdir($tmpdir);
            return $import;
        }

        if ($staging_url && $prod_url && $staging_url !== $prod_url) {
            self::replace_urls_in_database($mysqli, $prod_url, $staging_url);
            Azure_Logger::info("Platform sync: replaced URLs {$prod_url} -> {$staging_url}", 'Platform');
        }

        mysqli_close($mysqli);
        self::rrmdir($tmpdir);

        $message = sprintf(
            __('Production database copied to staging (%1$s). %2$d SQL statements executed.', 'azure-plugin'),
            $staging_db,
            $import['statements']
        );

        Azure_Logger::info('Platform sync: complete — ' . $message, 'Platform');

        return array(
            'success' => true,
            'message' => $message,
            'details' => array(
                'staging_database' => $staging_db,
                'statements'       => $import['statements'],
                'errors'           => $import['errors'],
                'staging_site_url' => $staging_url,
            ),
        );
    }

    /**
     * Heuristic: are we running on the production slot (not staging)?
     *
     * Checks, in order:
     *   1. WEBSITE_SLOT_NAME env var (Azure App Service slot identifier).
     *      Returns "Production" on prod, the slot name on others.
     *   2. Same env var read via $_SERVER (some Azure WP images expose it
     *      there but not via getenv()).
     *   3. DB_NAME ending in `_staging` — strong signal we're on staging.
     *   4. HTTP host containing `-staging` (Azure default + AFD endpoints).
     */
    public static function is_production_slot() {
        $slot = getenv('WEBSITE_SLOT_NAME');
        if (is_string($slot) && $slot !== '') {
            return strtolower($slot) === 'production';
        }
        if (!empty($_SERVER['WEBSITE_SLOT_NAME'])) {
            return strtolower((string) $_SERVER['WEBSITE_SLOT_NAME']) === 'production';
        }
        if (defined('DB_NAME') && preg_match('/_staging$/i', (string) DB_NAME)) {
            return false;
        }
        $host = '';
        if (!empty($_SERVER['HTTP_HOST'])) {
            $host = (string) $_SERVER['HTTP_HOST'];
        } elseif (function_exists('home_url')) {
            $parsed = parse_url(home_url(), PHP_URL_HOST);
            if (is_string($parsed)) {
                $host = $parsed;
            }
        }
        if ($host !== '' && stripos($host, '-staging') !== false) {
            return false;
        }
        return true;
    }

    /**
     * Resolve the staging database name. Used both for the sync target
     * and for display in the Danger Zone status panel.
     *
     * - Explicit user setting wins.
     * - Then STAGING_DATABASE_NAME env var.
     * - Otherwise derive from DB_NAME by appending `_staging` — but never
     *   double-suffix when called from the staging slot itself.
     */
    public static function get_staging_database_name() {
        $configured = trim((string) Azure_Settings::get_setting('platform_staging_database_name', ''));
        if ($configured !== '') {
            return $configured;
        }

        $env = getenv('STAGING_DATABASE_NAME');
        if (is_string($env) && $env !== '') {
            return $env;
        }

        $current = (string) DB_NAME;
        if (preg_match('/_staging$/i', $current)) {
            // We ARE the staging DB — return ourselves so callers don't
            // accidentally suggest `_staging_staging`.
            return $current;
        }
        return $current . '_staging';
    }

    public static function get_staging_site_url() {
        $configured = trim((string) Azure_Settings::get_setting('platform_staging_site_url', ''));
        if ($configured !== '') {
            return untrailingslashit($configured);
        }

        $afd = getenv('STAGING_AFD_DOMAIN');
        if (is_string($afd) && $afd !== '') {
            return 'https://' . ltrim($afd, 'https://');
        }

        $site = getenv('WEBSITE_SITE_NAME');
        if (is_string($site) && $site !== '') {
            return 'https://' . $site . '-staging.azurewebsites.net';
        }

        return '';
    }

    private static function database_exists($name) {
        global $wpdb;
        $mysqli = $wpdb->dbh;
        if (!($mysqli instanceof mysqli)) {
            return false;
        }
        $esc = mysqli_real_escape_string($mysqli, $name);
        $res = mysqli_query($mysqli, "SHOW DATABASES LIKE '{$esc}'");
        if (!$res) {
            return false;
        }
        $exists = mysqli_num_rows($res) > 0;
        mysqli_free_result($res);
        return $exists;
    }

    private static function connect_database($database_name) {
        $host = DB_HOST;
        $port = null;
        if (strpos($host, ':') !== false) {
            list($host, $port) = explode(':', $host, 2);
        }

        $mysqli = mysqli_init();
        if (!$mysqli) {
            return null;
        }

        $connected = @mysqli_real_connect($mysqli, $host, DB_USER, DB_PASSWORD, $database_name, $port ? (int) $port : null);
        if (!$connected) {
            return null;
        }

        mysqli_set_charset($mysqli, DB_CHARSET);
        return $mysqli;
    }

    /**
     * @return array{success:bool,message?:string,statements?:int,errors?:int}
     */
    private static function import_sql_file($mysqli, $sql_path) {
        $fh = fopen($sql_path, 'rb');
        if (!$fh) {
            return array(
                'success' => false,
                'message' => __('Could not read database dump file.', 'azure-plugin'),
            );
        }

        $buffer    = '';
        $executed  = 0;
        $errors    = 0;

        while (($line = fgets($fh)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '#') === 0) {
                continue;
            }

            $buffer .= $line;
            if (substr($trimmed, -1) !== ';') {
                continue;
            }

            $query = trim($buffer);
            $buffer = '';
            if ($query === '') {
                continue;
            }

            if (@mysqli_query($mysqli, $query) === false) {
                $errors++;
                if ($errors <= 20) {
                    Azure_Logger::warning('Platform sync SQL error: ' . mysqli_error($mysqli), 'Platform');
                }
            }
            $executed++;
            if ($executed % 200 === 0) {
                @set_time_limit(120);
            }
        }

        if (trim($buffer) !== '') {
            if (@mysqli_query($mysqli, trim($buffer)) === false) {
                $errors++;
            }
            $executed++;
        }

        fclose($fh);

        return array(
            'success'    => true,
            'statements' => $executed,
            'errors'     => $errors,
        );
    }

    private static function replace_urls_in_database($mysqli, $from_url, $to_url) {
        global $wpdb;

        $prefix   = $wpdb->prefix;
        $esc_from = mysqli_real_escape_string($mysqli, $from_url);
        $esc_to   = mysqli_real_escape_string($mysqli, $to_url);
        $esc_home = $esc_to;

        mysqli_query($mysqli, "UPDATE `{$prefix}options` SET option_value = '{$esc_to}' WHERE option_name = 'siteurl'");
        mysqli_query($mysqli, "UPDATE `{$prefix}options` SET option_value = '{$esc_home}' WHERE option_name = 'home'");

        $tables = array(
            $prefix . 'posts'    => array('post_content', 'post_excerpt', 'guid'),
            $prefix . 'postmeta' => array('meta_value'),
            $prefix . 'options'  => array('option_value'),
        );

        foreach ($tables as $table => $columns) {
            foreach ($columns as $column) {
                $sql = "UPDATE `{$table}` SET `{$column}` = REPLACE(`{$column}`, '{$esc_from}', '{$esc_to}') WHERE `{$column}` LIKE '%{$esc_from}%'";
                mysqli_query($mysqli, $sql);
            }
        }
    }

    private static function rrmdir($dir) {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
