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
     * Status of Redis cache isolation between this slot and any sibling
     * (staging) slot. We can't query the other slot from here, but we
     * can detect the local config that determines whether keys would
     * collide. Returns one of three states:
     *
     *   - "isolated"   — slot has both/either a non-default WP_REDIS_DATABASE
     *                   or a slot-specific WP_CACHE_KEY_SALT. Safe.
     *   - "shared"    — slot uses the default Redis DB 0 AND a generic salt.
     *                   Cross-contamination is possible.
     *   - "no_cache"  — Redis Object Cache drop-in isn't active. Nothing to
     *                   isolate; defaults to safe.
     *
     * The check is intentionally local-only: each slot evaluates its own
     * config. Both slots running this check should show "isolated" when
     * the runbook in docs/runbooks/redis-isolation.md has been applied.
     *
     * @return array{
     *   state: string,
     *   summary: string,
     *   redis_database: int|null,
     *   key_salt: string,
     *   key_salt_is_slot_specific: bool,
     *   afd_domain: string
     * }
     */
    public static function get_redis_isolation_status() {
        // Object caching (Redis) is independent of the WP_CACHE constant —
        // WP_CACHE only controls page caching via advanced-cache.php. The
        // Redis Object Cache plugin loads its drop-in via wp-settings.php
        // and sets wp_using_ext_object_cache() to true regardless of
        // WP_CACHE. So this check only asks "is an external object cache
        // active?" which is precisely what we need to know for isolation.
        $cache_active = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
        $redis_db   = defined('WP_REDIS_DATABASE') ? (int) WP_REDIS_DATABASE : null;
        $salt       = defined('WP_CACHE_KEY_SALT') ? (string) WP_CACHE_KEY_SALT : '';
        $afd_domain = (string) (getenv('AFD_DOMAIN') ?: '');

        // Slot-specific salt = the salt contains the slot's AFD_DOMAIN.
        // Generic salts (e.g. "wilderptsa.net:" used on both slots) would
        // fail this test on the staging slot but pass on prod, which is
        // exactly what we want — the warning surfaces on the slot that
        // would silently overwrite the other slot's keys.
        $salt_slot_specific = ($afd_domain !== '' && stripos($salt, $afd_domain) !== false);

        if (!$cache_active) {
            return array(
                'state'                      => 'no_cache',
                'summary'                    => __('Redis Object Cache is not active. Nothing to isolate.', 'azure-plugin'),
                'redis_database'             => $redis_db,
                'key_salt'                   => $salt,
                'key_salt_is_slot_specific'  => $salt_slot_specific,
                'afd_domain'                 => $afd_domain,
            );
        }

        // Isolated when EITHER mechanism is in place:
        //  - non-default logical DB (1+), OR
        //  - slot-specific key salt.
        $isolated = ($redis_db !== null && $redis_db > 0) || $salt_slot_specific;

        // Production slot legitimately uses DB 0. So for prod, salt must
        // be slot-specific (or there's nothing distinguishing prod from
        // staging which is also on DB 0 default).
        if (self::is_production_slot() && ($redis_db === null || $redis_db === 0) && !$salt_slot_specific) {
            $isolated = false;
        }

        if ($isolated) {
            return array(
                'state'                     => 'isolated',
                'summary'                   => sprintf(
                    /* translators: 1: Redis DB number, 2: key salt prefix */
                    __('Redis isolated. DB #%1$d, salt "%2$s".', 'azure-plugin'),
                    (int) ($redis_db ?? 0),
                    $salt
                ),
                'redis_database'            => $redis_db,
                'key_salt'                  => $salt,
                'key_salt_is_slot_specific' => $salt_slot_specific,
                'afd_domain'                => $afd_domain,
            );
        }

        return array(
            'state'                     => 'shared',
            'summary'                   => __('Redis is shared with the sibling slot. Cache writes can leak across slots. See docs/runbooks/redis-isolation.md to fix.', 'azure-plugin'),
            'redis_database'            => $redis_db,
            'key_salt'                  => $salt,
            'key_salt_is_slot_specific' => $salt_slot_specific,
            'afd_domain'                => $afd_domain,
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

    // ------------------------------------------------------------------
    // Cache burst (System → Critical)
    // ------------------------------------------------------------------

    /**
     * Status payload for the Cache Burst widget.
     *
     * @return array{
     *   redis_active: bool,
     *   w3tc_active: bool,
     *   afd_enabled: bool,
     *   afd_configured: bool,
     *   afd_endpoint_name: string,
     *   afd_profile_name: string,
     *   slot_label: string
     * }
     */
    public static function get_cache_burst_status() {
        $redis_active = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
        $w3tc_active  = defined('WP_CACHE') && WP_CACHE;
        $afd_enabled  = strtolower((string) (getenv('AFD_ENABLED') ?: '')) === 'true';
        $afd_cfg      = self::get_afd_purge_config();

        return array(
            'redis_active'      => $redis_active,
            'w3tc_active'       => $w3tc_active,
            'afd_enabled'       => $afd_enabled,
            'afd_configured'    => $afd_cfg['configured'],
            'afd_endpoint_name' => $afd_cfg['endpoint_name'],
            'afd_profile_name'  => $afd_cfg['profile_name'],
            'slot_label'        => self::is_production_slot() ? __('production', 'azure-plugin') : __('staging', 'azure-plugin'),
        );
    }

    /**
     * Flush WordPress object cache (Redis drop-in).
     *
     * @return array{success:bool,message:string}
     */
    public static function burst_redis_cache() {
        $had_cache = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();

        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        if (class_exists('\\Rhubarb\\RedisCache\\Plugin')) {
            try {
                $plugin = \Rhubarb\RedisCache\Plugin::instance();
                if ($plugin && method_exists($plugin, 'flush_cache')) {
                    $plugin->flush_cache();
                }
            } catch (\Throwable $e) {
                Azure_Logger::warning('Cache burst: Redis plugin flush failed — ' . $e->getMessage(), 'Platform');
            }
        }

        Azure_Logger::info('Cache burst: Redis/object cache flushed on ' . self::get_cache_burst_status()['slot_label'] . ' slot', 'Platform');

        return array(
            'success' => true,
            'message' => $had_cache
                ? __('Redis object cache flushed for this slot.', 'azure-plugin')
                : __('Object cache flush ran (no external object cache detected).', 'azure-plugin'),
        );
    }

    /**
     * Flush W3 Total Cache (page, DB, object, minify).
     *
     * @return array{success:bool,message:string,details?:array}
     */
    public static function burst_w3tc_cache() {
        $details = array(
            'flush_all'   => false,
            'pgcache'     => false,
            'dbcache'     => false,
            'objectcache' => false,
            'minify'      => false,
        );

        if (function_exists('w3tc_flush_all')) {
            @w3tc_flush_all();
            $details['flush_all'] = true;
        } else {
            if (function_exists('w3tc_pgcache_flush')) {
                @w3tc_pgcache_flush();
                $details['pgcache'] = true;
            }
            if (function_exists('w3tc_dbcache_flush')) {
                @w3tc_dbcache_flush();
                $details['dbcache'] = true;
            }
            if (function_exists('w3tc_objectcache_flush')) {
                @w3tc_objectcache_flush();
                $details['objectcache'] = true;
            }
            if (function_exists('w3tc_minify_flush')) {
                @w3tc_minify_flush();
                $details['minify'] = true;
            }
        }

        if (function_exists('wp_cache_flush')) {
            @wp_cache_flush();
        }

        $any = $details['flush_all'] || in_array(true, $details, true);
        if (!$any && !(defined('WP_CACHE') && WP_CACHE)) {
            return array(
                'success' => false,
                'message' => __('W3 Total Cache is not active (WP_CACHE is off).', 'azure-plugin'),
            );
        }

        Azure_Logger::info('Cache burst: W3TC caches flushed — ' . wp_json_encode($details), 'Platform');

        return array(
            'success' => true,
            'message' => $details['flush_all']
                ? __('W3 Total Cache: all caches emptied.', 'azure-plugin')
                : __('W3 Total Cache: available cache layers flushed.', 'azure-plugin'),
            'details' => $details,
        );
    }

    /**
     * Purge Azure Front Door edge cache for this slot via ARM API +
     * the App Service managed identity.
     *
     * @return array{success:bool,message:string,details?:array}
     */
    public static function burst_afd_cache() {
        if (strtolower((string) (getenv('AFD_ENABLED') ?: '')) !== 'true') {
            return array(
                'success' => false,
                'message' => __('Front Door is not enabled on this slot (AFD_ENABLED ≠ true).', 'azure-plugin'),
            );
        }

        $cfg = self::get_afd_purge_config();
        if (!$cfg['configured']) {
            return array(
                'success' => false,
                'message' => __(
                    'Front Door purge is not configured. Set slot-sticky app settings AFD_PROFILE_NAME and AFD_ENDPOINT_NAME (Azure resource name, not the public hostname).',
                    'azure-plugin'
                ),
            );
        }

        $token = self::fetch_arm_access_token();
        if (!$token) {
            return array(
                'success' => false,
                'message' => __(
                    'Could not obtain an Azure management token from the App Service managed identity. Grant this site\'s identity CDN purge rights on the Front Door profile.',
                    'azure-plugin'
                ),
            );
        }

        $api_version = '2023-05-01';
        $url = sprintf(
            'https://management.azure.com/subscriptions/%s/resourceGroups/%s/providers/Microsoft.Cdn/profiles/%s/afdEndpoints/%s/purge?api-version=%s',
            rawurlencode($cfg['subscription_id']),
            rawurlencode($cfg['resource_group']),
            rawurlencode($cfg['profile_name']),
            rawurlencode($cfg['endpoint_name']),
            rawurlencode($api_version)
        );

        $body = array(
            'contentPaths' => array('/*'),
            'domains'      => array_filter(array($cfg['afd_domain'])),
        );

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode($body),
            'timeout' => 45,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => sprintf(__('Front Door purge request failed: %s', 'azure-plugin'), $response->get_error_message()),
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $resp_body = wp_remote_retrieve_body($response);

        if ($code >= 200 && $code < 300) {
            Azure_Logger::info(
                'Cache burst: AFD purge accepted for endpoint ' . $cfg['endpoint_name'] . ' on ' . $cfg['slot_label'] . ' slot',
                'Platform'
            );
            return array(
                'success' => true,
                'message' => sprintf(
                    /* translators: 1: AFD endpoint resource name, 2: slot label */
                    __('Front Door purge queued for endpoint %1$s (%2$s slot). Edge cache may take 1–3 minutes to clear globally.', 'azure-plugin'),
                    $cfg['endpoint_name'],
                    $cfg['slot_label']
                ),
                'details' => array(
                    'endpoint' => $cfg['endpoint_name'],
                    'profile'  => $cfg['profile_name'],
                    'domain'   => $cfg['afd_domain'],
                ),
            );
        }

        Azure_Logger::error('Cache burst: AFD purge HTTP ' . $code . ' — ' . $resp_body, 'Platform');

        return array(
            'success' => false,
            'message' => sprintf(
                __('Front Door purge failed (HTTP %1$d). Response: %2$s', 'azure-plugin'),
                (int) $code,
                substr($resp_body, 0, 300)
            ),
        );
    }

    /**
     * Resolve Front Door ARM identifiers for purge on the current slot.
     *
     * @return array{
     *   configured: bool,
     *   subscription_id: string,
     *   resource_group: string,
     *   profile_name: string,
     *   endpoint_name: string,
     *   afd_domain: string,
     *   slot_label: string
     * }
     */
    private static function get_afd_purge_config() {
        $subscription_id = trim((string) (getenv('WEBSITE_OWNER_NAME') ?: ''));
        if ($subscription_id === '') {
            $subscription_id = trim((string) Azure_Settings::get_setting('platform_azure_subscription_id', ''));
        }

        $resource_group = trim((string) (getenv('WEBSITE_RESOURCE_GROUP') ?: ''));
        if ($resource_group === '') {
            $resource_group = trim((string) Azure_Settings::get_setting('platform_afd_resource_group', 'PTSAWebsite'));
        }

        $profile_name = trim((string) (getenv('AFD_PROFILE_NAME') ?: ''));
        if ($profile_name === '') {
            $profile_name = trim((string) Azure_Settings::get_setting('platform_afd_profile_name', 'WilderPTSAAFD'));
        }

        $endpoint_name = trim((string) (getenv('AFD_ENDPOINT_NAME') ?: ''));
        if ($endpoint_name === '') {
            $endpoint_name = trim((string) Azure_Settings::get_setting('platform_afd_endpoint_name', ''));
        }

        $afd_domain = trim((string) (getenv('AFD_DOMAIN') ?: ''));
        $slot_label = self::is_production_slot() ? 'production' : 'staging';

        $configured = ($subscription_id !== '' && $resource_group !== '' && $profile_name !== '' && $endpoint_name !== '');

        return array(
            'configured'      => $configured,
            'subscription_id' => $subscription_id,
            'resource_group'  => $resource_group,
            'profile_name'    => $profile_name,
            'endpoint_name'   => $endpoint_name,
            'afd_domain'      => $afd_domain,
            'slot_label'      => $slot_label,
        );
    }

    /**
     * Fetch an Azure Resource Manager token via the App Service IMDS endpoint.
     *
     * @return string|false
     */
    private static function fetch_arm_access_token() {
        $client_id = trim((string) (getenv('ENTRA_CLIENT_ID') ?: getenv('AZURE_CLIENT_ID') ?: ''));
        $url       = 'http://169.254.169.254/metadata/identity/oauth2/token?api-version=2019-08-01&resource='
            . rawurlencode('https://management.azure.com/');
        if ($client_id !== '') {
            $url .= '&client_id=' . rawurlencode($client_id);
        }

        $response = wp_remote_get($url, array(
            'headers' => array('Metadata' => 'true'),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            Azure_Logger::warning('Cache burst: IMDS token request failed — ' . $response->get_error_message(), 'Platform');
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['access_token'])) {
            Azure_Logger::warning('Cache burst: IMDS returned no access_token', 'Platform');
            return false;
        }

        return (string) $data['access_token'];
    }
}
