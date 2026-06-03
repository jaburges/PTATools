<?php
/**
 * Plugin Name: PTA Tools
 * Plugin URI: https://github.com/jaburges/PTATools
 * Update URI: https://github.com/jaburges/PTATools/
 * Description: Microsoft 365 integration for WordPress — SSO with Entra ID claims mapping, automated backup to Azure Blob Storage, Outlook calendar embedding with shared mailbox support, native PTA event calendar (pta_event CPT), email via Microsoft Graph API, PTA role management with O365 Groups sync, WooCommerce class products with event scheduling, Auction module, Newsletter module, and OneDrive media integration.
 * Version: 3.139
 * Author: Jamie Burgess
 * License: GPL v2 or later
 * Text Domain: azure-plugin
 * Requires at least: 5.0
 * Tested up to: 6.9
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('AZURE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AZURE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('AZURE_PLUGIN_VERSION', '3.139');

/**
 * Defensive permission helper for retrofitted gates.
 */
if (!function_exists('azure_user_can')) {
    function azure_user_can($cap, $user_id = null) {
        if (class_exists('Azure_Capabilities')) {
            return Azure_Capabilities::user_can($cap, $user_id);
        }
        if ($user_id === null) {
            return current_user_can('manage_options');
        }
        return user_can($user_id, 'manage_options');
    }
}

if (!function_exists('azure_auction_admin_can')) {
    function azure_auction_admin_can($user_id = null) {
        if ($user_id === null) {
            return current_user_can('manage_woocommerce') || current_user_can('manage_options');
        }
        return user_can($user_id, 'manage_woocommerce') || user_can($user_id, 'manage_options');
    }
}

/**
 * Find the newest GitHub release that ships pta-tools.zip (never downgrades).
 *
 * @return array{version:string,package:string,url:string}|null
 */
function azure_plugin_best_github_release() {
    $cached = get_transient('azure_plugin_github_best_release');
    if (is_array($cached) && !empty($cached['version']) && !empty($cached['package'])) {
        return $cached;
    }

    $best = null;
    $page = 1;
    while ($page <= 5) {
        $response = wp_remote_get(
            'https://api.github.com/repos/jaburges/PTATools/releases?per_page=100&page=' . $page,
            array(
                'user-agent' => 'PTATools-Updater',
                'timeout'    => 15,
            )
        );
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            break;
        }
        $releases = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($releases) || $releases === array()) {
            break;
        }
        foreach ($releases as $release) {
            if (empty($release['tag_name']) || empty($release['assets']) || !empty($release['draft'])) {
                continue;
            }
            // Beta / RC GitHub pre-releases are for staging validation only.
            if (!empty($release['prerelease'])) {
                continue;
            }
            $package_url = null;
            foreach ($release['assets'] as $asset) {
                if (!empty($asset['name']) && $asset['name'] === 'pta-tools.zip' && !empty($asset['browser_download_url'])) {
                    $package_url = $asset['browser_download_url'];
                    break;
                }
            }
            if (!$package_url) {
                continue;
            }
            $version = ltrim((string) $release['tag_name'], 'v');
            if (preg_match('/-(beta|rc)/i', $version)) {
                continue;
            }
            if ($best === null || version_compare($version, $best['version'], '>')) {
                $best = array(
                    'version' => $version,
                    'package' => $package_url,
                    'url'     => $release['html_url'] ?? 'https://github.com/jaburges/PTATools/releases',
                );
            }
        }
        if (count($releases) < 100) {
            break;
        }
        $page++;
    }

    if ($best !== null) {
        set_transient('azure_plugin_github_best_release', $best, 6 * HOUR_IN_SECONDS);
    }
    return $best;
}

/**
 * Lightweight per-request tracer.
 *
 * Emits a single `X-PTA-Trace` response header with compact JSON:
 *   {ver,ctx,mods,marks,now,files,q,mem}
 *
 * Cost per request: ~1 µs to record marks + ~5 µs to JSON-encode + emit header.
 * Disabled entirely if PTA_TRACE_DISABLED is defined or `header()` already sent.
 *
 * Read with:
 *   Invoke-WebRequest -UseBasicParsing https://example.com/ | % { $_.Headers['X-PTA-Trace'] }
 */
class PTA_Trace {
    private static $start = null;
    private static $marks = array();
    private static $modules = array();

    public static function boot() {
        if (defined('PTA_TRACE_DISABLED') && PTA_TRACE_DISABLED) {
            return;
        }
        self::$start = microtime(true);
        self::mark('plugin_file_loaded');
        add_action('send_headers', array(__CLASS__, 'emit'), 100);
        add_filter('rest_post_dispatch', array(__CLASS__, 'emit_rest'), 100, 1);
    }

    public static function mark($key) {
        if (self::$start === null) {
            return;
        }
        self::$marks[$key] = array(
            round((microtime(true) - self::$start) * 1000, 2),
            count(get_included_files()),
        );
    }

    public static function module($name) {
        if (self::$start === null) {
            return;
        }
        self::$modules[] = $name;
    }

    private static function payload() {
        global $wpdb;
        return array(
            'ver'   => defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : '?',
            'ctx'   => self::ctx_label(),
            'mods'  => self::$modules,
            'marks' => self::$marks,
            'now'   => round((microtime(true) - self::$start) * 1000, 2),
            'files' => count(get_included_files()),
            'q'     => isset($wpdb) && is_object($wpdb) ? (int) $wpdb->num_queries : null,
            'mem'   => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        );
    }

    private static function ctx_label() {
        if (defined('REST_REQUEST') && REST_REQUEST) return 'rest';
        if ((function_exists('wp_doing_cron') && wp_doing_cron()) || (defined('DOING_CRON') && DOING_CRON)) return 'cron';
        if ((function_exists('wp_doing_ajax') && wp_doing_ajax()) || (defined('DOING_AJAX') && DOING_AJAX)) return 'ajax';
        if (function_exists('is_admin') && is_admin()) return 'admin';
        return 'frontend';
    }

    public static function emit() {
        if (self::$start === null || headers_sent()) {
            return;
        }
        $json = wp_json_encode(self::payload());
        if ($json && strlen($json) < 4000) {
            header('X-PTA-Trace: ' . $json);
        }
    }

    public static function emit_rest($response) {
        if (self::$start === null || !is_object($response) || !method_exists($response, 'header')) {
            return $response;
        }
        $json = wp_json_encode(self::payload());
        if ($json && strlen($json) < 4000) {
            $response->header('X-PTA-Trace', $json);
        }
        return $response;
    }
}
PTA_Trace::boot();

/**
 * Lazy-loader for the Diagnostics REST API. Hooked to rest_api_init only
 * (so it only runs on real REST requests, not every pageload).
 */
function pta_load_diagnostics_api() {
    $path = AZURE_PLUGIN_PATH . 'includes/class-diagnostics-api.php';
    if (file_exists($path)) {
        require_once $path;
    }
    if (class_exists('Azure_Diagnostics_API')) {
        // The class constructor registers another rest_api_init hook for
        // register_routes(); since we're already inside rest_api_init, that
        // would miss the current dispatch. Call register_routes() directly.
        $api = Azure_Diagnostics_API::get_instance();
        if (method_exists($api, 'register_routes')) {
            $api->register_routes();
        }
    }
}

/**
 * Lazy-loader for the PTSA mobile REST API (/wp-json/ptsa/v1/*).
 * Same shape as the diagnostics loader — runs only on rest_api_init.
 */
function pta_load_ptsa_rest_api() {
    $jwt_path = AZURE_PLUGIN_PATH . 'includes/class-ptsa-jwt.php';
    $api_path = AZURE_PLUGIN_PATH . 'includes/class-ptsa-rest-api.php';
    if (file_exists($jwt_path)) require_once $jwt_path;
    if (file_exists($api_path)) require_once $api_path;
    if (class_exists('Azure_PTSA_REST_API')) {
        $api = new Azure_PTSA_REST_API();
        if (method_exists($api, 'register_routes')) {
            $api->register_routes();
        }
    }
}

// Auto-update from GitHub Releases (Update URI header must match hostname: github.com).
// Uses the highest semver tag with a pta-tools.zip asset — never /releases/latest
// (which can be an older v3.51) and never offers a downgrade.
add_filter('update_plugins_github.com', function ($update, array $plugin_data, string $plugin_file, $locales) {
    $me = plugin_basename(__FILE__);
    if ($plugin_file !== $me) {
        return $update;
    }

    if (defined('AZURE_PLUGIN_DISABLE_GITHUB_UPDATES') && AZURE_PLUGIN_DISABLE_GITHUB_UPDATES) {
        return false;
    }

    if (!apply_filters('azure_plugin_github_updates_enabled', true)) {
        return false;
    }

    $installed = isset($plugin_data['Version']) ? (string) $plugin_data['Version'] : '0';
    $best      = azure_plugin_best_github_release();
    if ($best === null) {
        return $update;
    }

    $new_version = $best['version'];

    // Never downgrade or reinstall the same build from an older GitHub release.
    if (version_compare($installed, $new_version, '>=')) {
        return false;
    }

    $slug = dirname($me);

    if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        error_log(sprintf(
            'PTA Tools updater: offering update plugin=%s slug=%s from %s to %s',
            $me,
            $slug,
            $installed,
            $new_version
        ));
    }

    return array(
        'id'      => 'https://github.com/jaburges/PTATools/',
        'slug'    => $slug,
        'plugin'  => $me,
        'version' => $new_version,
        'url'     => $best['url'],
        'package' => $best['package'],
        'tested'  => '6.9',
    );
}, 10, 4);

// Main plugin class
class AzurePlugin {

    private static $instance = null;

    /**
     * Per-request context flags, populated once at the top of init().
     * Used to decide which classes need to be instantiated for THIS request.
     *
     * Keys: is_admin, is_ajax, is_cron, is_rest, is_cli, is_frontend
     * @var array<string,bool>
     */
    private $context = array();

    public static function get_instance() {
        if (self::$instance == null) {
            self::$instance = new AzurePlugin();
        }
        return self::$instance;
    }

    private function __construct() {
        try {
            // Register hooks
            add_action('plugins_loaded', array($this, 'load_dependencies'), 5);
            add_action('init', array($this, 'init'), 10);

            // AcyMailing's `acym_load_installed_integrations` action
            // can fire as early as AcyMailing's own plugin bootstrap
            // (well before `init`). Register our loader on
            // `plugins_loaded` priority 1 so it's guaranteed in place
            // by the time AcyMailing scans for add-ons.
            add_action('plugins_loaded', array($this, 'register_acymailing_addon'), 1);

            // PTA Tools Email Router intercepts wp_mail() on
            // `pre_wp_mail` priority 1 so it beats AcyMailing's
            // pre_wp_mail (priority 10) and the ACS App Service
            // email plugin's Closure (also ~10). Load on
            // plugins_loaded priority 1 so the hook is registered
            // before any wp_mail call that could fire on init.
            add_action('plugins_loaded', array($this, 'register_email_router'), 1);

            // [up-next] theme presets (v3.125). Bootstraps storage,
            // generated-CSS enqueue, and AJAX handlers for the
            // Calendar > Upcoming Events admin Themes panel. Loaded
            // on plugins_loaded so the shortcode (registered later
            // on init) finds the class on first render.
            add_action('plugins_loaded', array($this, 'register_upnext_themes'), 2);

            // Activation/deactivation hooks must be registered immediately
            register_activation_hook(__FILE__, array($this, 'activate'));
            register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        } catch (\Throwable $e) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::error('Plugin constructor failed: ' . $e->getMessage(), array(
                    'module' => 'Core',
                    'file' => __FILE__,
                    'line' => __LINE__
                ));
            }
            error_log('Azure Plugin: Constructor error - ' . $e->getMessage());
        }
    }

    /**
     * Detect what kind of request we're handling so module init can skip
     * sub-classes that are irrelevant to this context.
     *
     * Note: `is_admin()` is true for /wp-admin/ and admin-ajax.php; we split
     * those apart so AJAX-only requests don't pull in admin UI classes.
     */
    private function detect_context() {
        $is_admin   = is_admin();
        $is_ajax    = (function_exists('wp_doing_ajax') && wp_doing_ajax()) || (defined('DOING_AJAX') && DOING_AJAX);
        $is_cron    = (function_exists('wp_doing_cron') && wp_doing_cron()) || (defined('DOING_CRON') && DOING_CRON);
        $is_rest    = defined('REST_REQUEST') && REST_REQUEST;
        $is_cli     = defined('WP_CLI') && WP_CLI;
        // "Front-end pageload" = a real visitor request rendering an HTML page.
        $is_frontend = !$is_admin && !$is_ajax && !$is_cron && !$is_rest && !$is_cli;

        return array(
            'is_admin'    => $is_admin,
            'is_ajax'     => $is_ajax,
            'is_cron'     => $is_cron,
            'is_rest'     => $is_rest,
            'is_cli'      => $is_cli,
            'is_frontend' => $is_frontend,
            // Convenience flag: true when the request is anything that needs the admin/ajax/cron/CLI machinery.
            'is_backend'  => $is_admin || $is_ajax || $is_cron || $is_cli,
        );
    }
    
    /**
     * Load only the critical files at plugins_loaded. Module-specific files
     * are loaded lazily inside each init_X_components() method, so disabled
     * modules cost zero filesystem I/O.
     *
     * Critical files are always required: Logger (used by every error path),
     * Database (table accessors), Settings (read by everything), Admin (only
     * required when in admin/ajax, but cheap and shared with AJAX handlers).
     */
    public function load_dependencies() {
        PTA_Trace::mark('load_dependencies_start');
        try {
            $critical_files = array(
                'class-logger.php',
                'class-database.php',
                'class-settings.php',
                'class-admin.php',
                'class-pta-cron.php',
            );

            $critical_ok = true;
            foreach ($critical_files as $file) {
                $file_path = AZURE_PLUGIN_PATH . 'includes/' . $file;

                if (!file_exists($file_path)) {
                    error_log("Azure Plugin: Critical file not found: {$file_path}");
                    $critical_ok = false;
                    continue;
                }

                try {
                    require_once $file_path;
                } catch (\Throwable $e) {
                    error_log("Azure Plugin: Error loading critical file {$file}: " . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
                    $critical_ok = false;
                }
            }

            if (!$critical_ok) {
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-error"><p><strong>PTA Tools:</strong> One or more core files could not be loaded. Check the PHP error log for details.</p></div>';
                });
            }

            // Custom cron intervals must be registered on every request that
            // might call wp_get_schedules() (e.g. when WP-Cron dispatches).
            // The filter callback is cheap (array merge) — registering it
            // unconditionally lets us drop 4 scattered `add_filter('cron_schedules', …)`
            // calls in module classes.
            if (class_exists('Azure_PTA_Cron')) {
                add_filter('cron_schedules', array('Azure_PTA_Cron', 'register_intervals'));
            }

        } catch (\Throwable $e) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::error('Failed to load dependencies: ' . $e->getMessage(), array(
                    'module' => 'Core',
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ));
            }
            error_log('Azure Plugin: load_dependencies failed - ' . $e->getMessage());
        }
        PTA_Trace::mark('load_dependencies_end');
    }

    /**
     * Helper: lazily require_once a list of module files from includes/.
     * Silently skips missing files (some optional files may not ship in older builds).
     */
    private function require_module_files(array $files) {
        foreach ($files as $file) {
            $path = AZURE_PLUGIN_PATH . 'includes/' . $file;
            if (!file_exists($path)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("Azure Plugin: Optional file not found: {$path}");
                }
                continue;
            }
            try {
                require_once $path;
            } catch (\Throwable $e) {
                error_log("Azure Plugin: Error loading {$file}: " . $e->getMessage());
            }
        }
    }
    
    public function init() {
        PTA_Trace::mark('init_start');
        try {
            // Detect what kind of request we're handling once, then pass to each
            // init_X_components() method so they can skip work that's irrelevant
            // to this context.
            $this->context = $this->detect_context();
            $ctx = $this->context;

            // Initialize logger first if not already initialized
            if (class_exists('Azure_Logger') && !Azure_Logger::is_initialized()) {
                Azure_Logger::init();
            }

            // Register scheduled log cleanup hook (scheduling is done during activation)
            add_action('azure_plugin_cleanup_logs', array('Azure_Logger', 'scheduled_cleanup'));

            // Load plugin textdomain
            load_plugin_textdomain('azure-plugin', false, dirname(plugin_basename(__FILE__)) . '/languages');

            // Initialize settings system (registers admin_init hook for register_setting)
            if ($ctx['is_admin'] && class_exists('Azure_Settings')) {
                Azure_Settings::get_instance();
            }

            // Run DB migrations on version change (dbDelta is safe to re-run).
            // Front-end requests don't need this; deferring to admin/cron prevents
            // the version-check option read on every visitor pageload.
            if ($ctx['is_backend']) {
                $stored_version = get_option('azure_plugin_db_version', '0');
                if (version_compare($stored_version, AZURE_PLUGIN_VERSION, '<')) {
                    if (class_exists('Azure_Database')) {
                        Azure_Database::create_tables();
                    }
                    // Defer the rewrite flush to wp_loaded so every module's
                    // `add_rewrite_endpoint` hook on init has fired first.
                    // Calling flush_rewrite_rules() inline (during init priority
                    // 10) captures the ruleset before late-registered endpoints
                    // like /my-account/profile/ are added, which 404s them.
                    add_action('wp_loaded', 'flush_rewrite_rules');

                    // v3.107: clear stale pages containing [up-next] during
                    // deploy/promotion as well as on future pta_event changes.
                    // The event-change hook fixes new syncs going forward, but
                    // without this upgrade-time purge a homepage cached before
                    // deployment can keep showing "No upcoming events."
                    $upcoming_path = AZURE_PLUGIN_PATH . 'includes/class-upcoming-module.php';
                    if (file_exists($upcoming_path)) {
                        require_once $upcoming_path;
                    }
                    if (class_exists('Azure_Upcoming_Module')) {
                        Azure_Upcoming_Module::invalidate_cache();
                    }

                    update_option('azure_plugin_db_version', AZURE_PLUGIN_VERSION);

                    // Auction: schedule per-auction finalize events for any
                    // existing auctions with future end times. Self-gated by
                    // azure_auction_finalize_backfill_done so it runs once.
                    if (class_exists('Azure_Auction_Lifecycle') || file_exists(AZURE_PLUGIN_PATH . 'includes/class-auction-lifecycle.php')) {
                        require_once AZURE_PLUGIN_PATH . 'includes/class-auction-lifecycle.php';
                        if (class_exists('Azure_Auction_Lifecycle')) {
                            Azure_Auction_Lifecycle::backfill_finalize_events();
                        }
                    }

                    // v3.67: register the Parent role once. Idempotent —
                    // skips if the role already exists.
                    if (file_exists(AZURE_PLUGIN_PATH . 'includes/class-parent-role.php')) {
                        require_once AZURE_PLUGIN_PATH . 'includes/class-parent-role.php';
                        if (class_exists('Azure_Parent_Role')) {
                            Azure_Parent_Role::register_role();
                        }
                    }

                    // v3.74: seed/repair the Parents newsletter list (role-
                    // bound to `parent`) and ensure the school_staff role
                    // exists for the admin's manual school-staff imports
                    // (school_staff_domain configured per tenant).
                    // Schedule the activation-token cleanup cron. All
                    // idempotent — safe to re-run on every upgrade.
                    $this->seed_parent_population_lists();
                    $this->ensure_school_staff_role();
                    if (!wp_next_scheduled(Azure_Parent_Activation::CLEANUP_HOOK)) {
                        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', Azure_Parent_Activation::CLEANUP_HOOK);
                    }
                }
            }

            // Parent-role login gate is cheap enough to load on every
            // request — it only attaches an authenticate filter and a
            // template_redirect that no-ops for users without the meta.
            if (file_exists(AZURE_PLUGIN_PATH . 'includes/class-parent-role.php')) {
                require_once AZURE_PLUGIN_PATH . 'includes/class-parent-role.php';
                if (class_exists('Azure_Parent_Role')) {
                    Azure_Parent_Role::get_instance();
                }
            }

            // Parent activation listener: handles ?pta-activate=<uid>:<token>.
            // Bails at the first isset() if the query var is absent, so the
            // cost on every front-end pageload is ~1 µs. Loaded globally so
            // the magic link works from email clients that strip cookies
            // (the listener doesn't depend on auth state).
            if (file_exists(AZURE_PLUGIN_PATH . 'includes/class-parent-activation.php')) {
                require_once AZURE_PLUGIN_PATH . 'includes/class-parent-activation.php';
                if (class_exists('Azure_Parent_Activation')) {
                    Azure_Parent_Activation::get_instance();
                }
            }

            // Newsletter signup shortcode + public REST endpoint. Only
            // registers a shortcode tag (lazy-renders) and one REST route
            // (registered on rest_api_init), so the cost on a pageload
            // that doesn't render the shortcode is ~2 µs. Loaded globally
            // because the homepage and other pages may contain the
            // shortcode and we don't know which pages until render.
            if (file_exists(AZURE_PLUGIN_PATH . 'includes/class-newsletter-signup-shortcode.php')) {
                require_once AZURE_PLUGIN_PATH . 'includes/class-newsletter-signup-shortcode.php';
                if (class_exists('Azure_Newsletter_Signup_Shortcode')) {
                    Azure_Newsletter_Signup_Shortcode::get_instance();
                }
            }

            // Disable-all-comments module. Loaded on every request so it
            // applies to admin + frontend, but the constructor short-circuits
            // immediately if the `enable_no_comments` setting is off, so the
            // per-request cost when disabled is one already-cached option
            // lookup. When enabled it strips comment support from all post
            // types, removes /wp/v2/comments REST routes, and hides the
            // Comments admin UI — replacing the legacy WPCode snippet so
            // WPCode can be uninstalled.
            if (file_exists(AZURE_PLUGIN_PATH . 'includes/class-disable-comments.php')) {
                require_once AZURE_PLUGIN_PATH . 'includes/class-disable-comments.php';
                if (class_exists('Azure_Disable_Comments')) {
                    Azure_Disable_Comments::get_instance();
                }
            }

            // User Management module: registers the [pta_user_dropdown]
            // shortcode + the pta-account-menu theme location on every
            // request so themes can use them. Admin AJAX handlers are
            // self-gated inside the class. The full admin page only loads
            // when the user opens it.
            if (file_exists(AZURE_PLUGIN_PATH . 'includes/class-user-management-module.php')) {
                require_once AZURE_PLUGIN_PATH . 'includes/class-user-management-module.php';
                if (class_exists('Azure_User_Management_Module')) {
                    Azure_User_Management_Module::get_instance();
                }
            }

            // Admin classes — only on admin and admin-ajax requests
            if ($ctx['is_admin']) {
                if (class_exists('Azure_Admin')) {
                    Azure_Admin::get_instance();
                }

                // Admin Performance optimizations (opt-out via option)
                $this->require_module_files(array('class-admin-performance.php'));
                if (class_exists('Azure_Admin_Performance')) {
                    Azure_Admin_Performance::get_instance();
                }
            }

            // Diagnostics REST API — register the rest_api_init hook lazily
            // so the file only loads on actual REST requests.
            //
            // Note: we cannot rely on $ctx['is_rest'] here. WordPress doesn't
            // define REST_REQUEST until `parse_request`, which fires AFTER
            // `init`. So during init() on a REST URL, is_rest is still false
            // and the diagnostics class would never be instantiated.
            if (!has_action('rest_api_init', 'pta_load_diagnostics_api')) {
                add_action('rest_api_init', 'pta_load_diagnostics_api');
            }

            // PTSA mobile REST API (ptsa/v1) — same lazy-loading pattern.
            if (!has_action('rest_api_init', 'pta_load_ptsa_rest_api')) {
                add_action('rest_api_init', 'pta_load_ptsa_rest_api');
            }

            // Frontend-only: add stale-while-revalidate cache headers on
            // WooCommerce My Account pages so the browser can serve a
            // cached copy instantly on back/refresh while revalidating
            // in the background. Origin still serves fresh content;
            // Front Door still won't cache (Cache-Control: private).
            // Effect: rapid back-button nav and bfcache restores skip
            // the 5-7s origin TTFB. Hooks send_headers (after WP has
            // set its own no-cache headers) so we override last.
            if ($ctx['is_frontend']) {
                add_action('send_headers', array($this, 'apply_my_account_swr_headers'), 99);

                $this->require_module_files(array('class-frontend-performance.php'));
                if (class_exists('Azure_Frontend_Performance')) {
                    Azure_Frontend_Performance::get_instance();
                }
            }

            // Get settings once for module gating. Autoloaded so this is a
            // single in-memory read after WP's options cache is warm; using
            // Azure_Settings populates the per-request static cache so every
            // subsequent get_setting()/get_credentials() call is free.
            $settings = class_exists('Azure_Settings')
                ? Azure_Settings::get_all_settings()
                : get_option('azure_plugin_settings', array());

            // ───── Per-module init ────────────────────────────────────────
            // Each init_X_components($ctx) loads only the files and instantiates
            // only the sub-classes that are relevant to the current request.

            if (!empty($settings['enable_sso'])) {
                PTA_Trace::module('sso');
                $this->init_sso_components($ctx);
            }

            if (!empty($settings['enable_backup'])) {
                PTA_Trace::module('backup');
                $this->init_backup_components($ctx);
            }

            if (!empty($settings['enable_calendar'])) {
                PTA_Trace::module('calendar');
                $this->init_calendar_components($ctx);
            }

            // PTA Tools native event CPT (pta_event/pta_venue/pta_organizer).
            // This is the canonical event store \u2014 the legacy TEC integration
            // and its dual-write phase were retired in v3.97. See
            // docs/tec-retirement-audit-2026-05-22.md.
            $this->init_events_components($ctx);

            // Email Logger filters wp_mail; mail can be sent from any context
            // (form submits, password resets, cron jobs), so keep it global.
            $this->init_email_logger($ctx);

            if (!empty($settings['enable_email'])) {
                PTA_Trace::module('email');
                $this->init_email_components($ctx);
            }

            if (!empty($settings['enable_pta'])) {
                PTA_Trace::module('pta');
                $this->init_pta_components($ctx);
            }

            if (!empty($settings['enable_onedrive_media'])) {
                PTA_Trace::module('onedrive');
                $this->init_onedrive_media_components($ctx);
            }

            if (!empty($settings['enable_classes'])) {
                PTA_Trace::module('classes');
                $this->init_classes_components($ctx);
            }

            // Upcoming module exists only to register the [up-next] shortcode.
            // Register the tag with a lazy callback — the heavy class only loads
            // when a page actually contains the shortcode.
            $this->register_upcoming_shortcode_lazy();

            // AcyMailing integration registration is wired on
            // `plugins_loaded` priority 1 (see init_hooks() in this
            // file). It MUST happen before AcyMailing fires the
            // `acym_load_installed_integrations` action, which can
            // happen as early as their plugin bootstrap — too early
            // for our `init`-tied callbacks. Nothing to do here.

            if (!empty($settings['enable_newsletter'])) {
                PTA_Trace::module('newsletter');
                $this->init_newsletter_components($ctx);
            }

            if (!empty($settings['enable_auction'])) {
                PTA_Trace::module('auction');
                $this->init_auction_components($ctx);
            }

            // Orders Reports is always on when WooCommerce is active.
            // Page access is gated by the manage_woocommerce capability
            // on the Selling > Reports tab; no per-module enable toggle
            // for now (can be added to settings later if needed).
            if (class_exists('WooCommerce')) {
                PTA_Trace::module('orders_reports');
                $this->init_orders_reports_components($ctx);
            }

            if (!empty($settings['enable_product_fields'])) {
                PTA_Trace::module('product_fields');
                $this->init_product_fields_components($ctx);
            }

            if (!empty($settings['enable_donations'])) {
                PTA_Trace::module('donations');
                $this->init_donations_components($ctx);
            }

            if (!empty($settings['enable_volunteer'])) {
                PTA_Trace::module('volunteer');
                $this->init_volunteer_components($ctx);
            }

            // Centralized "ensure scheduled" pass for all module cron events.
            // Skipped on frontend pageloads — only the WP-Cron daemon, admin,
            // and AJAX requests need to verify event registrations. Idempotent;
            // wp_next_scheduled is autoloaded + Redis-cached so this is cheap.
            if ($ctx['is_backend'] && class_exists('Azure_PTA_Cron')) {
                Azure_PTA_Cron::ensure_events_scheduled();
            }

            PTA_Trace::mark('init_end');
        } catch (\Throwable $e) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::error('Plugin init failed: ' . $e->getMessage(), array(
                    'module' => 'Core',
                    'file' => __FILE__,
                    'line' => __LINE__
                ));
            }
            error_log('Azure Plugin: init error - ' . $e->getMessage());
        }
    }

    /**
     * Replace WooCommerce's "must-revalidate, no-cache" headers on My
     * Account pages with stale-while-revalidate semantics. Browsers
     * (Chromium-based + Firefox) will then serve a cached copy
     * instantly on back/refresh while silently revalidating in the
     * background — eliminating the 5-7s origin round-trip the user
     * sees on rapid mobile navigation.
     *
     * Stays `private` so Azure Front Door still treats every response
     * as uncacheable at the edge (per-user content).
     *
     * Activated 2026-05-06 in response to mobile TTFB investigation.
     * Cheap: a single is_account_page() call + one header() write per
     * frontend request, only fires inside the WC My Account routes.
     */
    public function apply_my_account_swr_headers() {
        if (headers_sent()) {
            return;
        }
        if (!function_exists('is_account_page') || !is_account_page()) {
            return;
        }
        // GET only — never short-cache POST responses (form submits,
        // password change, address update, etc. must always re-fetch
        // the canonical state).
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method !== 'GET') {
            return;
        }
        // 60s SWR window: instant from bfcache for 60s, browser
        // revalidates in background, so the next nav reflects any
        // changes (new order, profile edit) within ~60s of origin.
        header('Cache-Control: private, max-age=0, stale-while-revalidate=60', true);
        header('X-PTA-Account-Cache: swr-60', true);
    }

    /**
     * SSO module
     *   - Auth, Shortcode, UserAccountShortcode: needed on front-end (login redirect, shortcodes)
     *   - Sync: admin/cron only (manual sync UI + scheduled sync hook)
     */
    private function init_sso_components($ctx) {
        try {
            // Files needed regardless of context
            $front_files = array('class-sso-auth.php', 'class-sso-shortcode.php', 'class-user-account-shortcode.php');
            $this->require_module_files($front_files);

            if (class_exists('Azure_SSO_Auth')) {
                new Azure_SSO_Auth();
            }
            if (class_exists('Azure_SSO_Shortcode')) {
                new Azure_SSO_Shortcode();
            }
            if (class_exists('Azure_User_Account_Shortcode')) {
                new Azure_User_Account_Shortcode();
            }

            // Admin/cron-only: scheduled sync + manual sync UI
            if ($ctx['is_backend']) {
                $this->require_module_files(array('class-sso-sync.php'));
                if (class_exists('Azure_SSO_Sync')) {
                    new Azure_SSO_Sync();
                }
            }
        } catch (\Throwable $e) {
            Azure_Logger::error('SSO init failed: ' . $e->getMessage(), array('module' => 'SSO', 'file' => $e->getFile(), 'line' => $e->getLine()));
            error_log('Azure Plugin: SSO init error - ' . $e->getMessage());
        }
    }

    /**
     * Backup module: 100% admin/cron — no front-end behavior.
     */
    private function init_backup_components($ctx) {
        if (!$ctx['is_backend']) {
            return;
        }
        try {
            $this->require_module_files(array(
                'class-backup-engine.php',
                'class-backup.php',
                'class-backup-restore.php',
                'class-backup-azure-storage.php',
                'class-backup-scheduler.php',
            ));

            if (class_exists('Azure_Backup')) {
                new Azure_Backup();
            }
            if (class_exists('Azure_Backup_Restore')) {
                new Azure_Backup_Restore();
            }
            if (class_exists('Azure_Backup_Scheduler')) {
                new Azure_Backup_Scheduler();
            }
            // Azure_Backup_Storage is created on-demand by other classes.
        } catch (\Throwable $e) {
            Azure_Logger::error('Backup init failed: ' . $e->getMessage(), array('module' => 'Backup', 'file' => $e->getFile(), 'line' => $e->getLine()));
            error_log('Azure Plugin: Backup init error - ' . $e->getMessage());
        }
    }

    /**
     * Calendar module
     *   - Renderer, Shortcode, EventsCPT, EventsShortcode: needed front-end (CPT registration, shortcodes)
     *   - Auth, GraphAPI, Manager, ICalSync: admin/cron only (token refresh, event fetch, sync)
     */
    private function init_calendar_components($ctx) {
        try {
            // Front-end facing
            $this->require_module_files(array(
                'class-calendar-renderer.php',
                'class-calendar-shortcode.php',
                'class-calendar-events-cpt.php',
                'class-calendar-events-shortcode.php',
            ));

            if (class_exists('Azure_Calendar_Shortcode')) {
                new Azure_Calendar_Shortcode();
            }
            if (class_exists('Azure_Calendar_EventsCPT')) {
                new Azure_Calendar_EventsCPT();
            }
            if (class_exists('Azure_Calendar_EventsShortcode')) {
                new Azure_Calendar_EventsShortcode();
            }

            // Admin/cron only: anything that talks to Microsoft Graph or runs scheduled sync
            if ($ctx['is_backend']) {
                $this->require_module_files(array(
                    'class-calendar-auth.php',
                    'class-calendar-graph-api.php',
                    'class-calendar-manager.php',
                    'class-calendar-ical-sync.php',
                    // v3.113: native Outlook -> pta_event sync replacement
                    // for the v3.97-retired class-tec-sync-engine.php.
                    'class-calendar-mapping-manager.php',
                    'class-calendar-sync-engine.php',
                    'class-calendar-sync-ajax.php',
                ));

                if (class_exists('Azure_Calendar_Auth')) {
                    new Azure_Calendar_Auth();
                }
                if (class_exists('Azure_Calendar_GraphAPI')) {
                    new Azure_Calendar_GraphAPI();
                }
                if (class_exists('Azure_Calendar_Manager')) {
                    new Azure_Calendar_Manager();
                }
                if (class_exists('Azure_Calendar_ICalSync')) {
                    new Azure_Calendar_ICalSync();
                }
                // Mapping manager is created on demand by callers; the
                // sync engine MUST be instantiated here so its cron and
                // per-mapping action handlers are registered before
                // WP-Cron fires them.
                if (class_exists('Azure_Calendar_Sync_Engine')) {
                    new Azure_Calendar_Sync_Engine();
                }
                if (class_exists('Azure_Calendar_Sync_Ajax')) {
                    new Azure_Calendar_Sync_Ajax();
                }
            }
        } catch (\Throwable $e) {
            Azure_Logger::error('Calendar init failed: ' . $e->getMessage(), array('module' => 'Calendar', 'file' => $e->getFile(), 'line' => $e->getLine()));
            error_log('Azure Plugin: Calendar init error - ' . $e->getMessage());
        }
    }

    /**
     * PTA Tools native event types (pta_event / pta_venue / pta_organizer).
     *
     * Loaded on every context (admin, frontend, REST, cron) so the post
     * types are available wherever WP queries them. This replaced the
     * legacy TEC integration in v3.97.
     */
    private function init_events_components($ctx) {
        try {
            $this->require_module_files(array('class-event-cpt.php'));

            if (class_exists('Azure_Event_CPT')) {
                Azure_Event_CPT::get_instance();
            }
        } catch (\Throwable $e) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::error(
                    'Event CPT init failed: ' . $e->getMessage(),
                    array('module' => 'Events', 'file' => $e->getFile(), 'line' => $e->getLine())
                );
            }
            error_log('Azure Plugin: Event CPT init error - ' . $e->getMessage());
        }
    }

    /**
     * Email Logger hooks the global wp_mail filter and is needed any time a
     * mail might be sent. Keep loaded on every request that has a meaningful
     * chance of sending mail.
     */
    private function init_email_logger($ctx) {
        // Front-end pageloads almost never send mail (no form submit, no admin action).
        // Skip the logger there to avoid the constructor's hook registration cost.
        if ($ctx['is_frontend']) {
            return;
        }
        $this->require_module_files(array('class-email-logger.php'));
        if (class_exists('Azure_Email_Logger')) {
            Azure_Email_Logger::get_instance();
        }
    }

    /**
     * Email module
     *   - Mailer: filters wp_mail (any context that may send mail)
     *   - Shortcode: front-end ([azure_contact_form] etc.)
     *   - Auth: admin/cron (token management for Graph API)
     */
    private function init_email_components($ctx) {
        try {
            $this->require_module_files(array('class-email-mailer.php', 'class-email-shortcode.php'));

            if (class_exists('Azure_Email_Mailer') && !$ctx['is_frontend']) {
                // Mailer registers AJAX handlers + pre_wp_mail filter; only useful
                // when something can actually send mail this request.
                new Azure_Email_Mailer();
            }
            if (class_exists('Azure_Email_Shortcode')) {
                new Azure_Email_Shortcode();
            }

            if ($ctx['is_backend']) {
                $this->require_module_files(array('class-email-auth.php'));
                if (class_exists('Azure_Email_Auth')) {
                    new Azure_Email_Auth();
                }
            }
        } catch (\Throwable $e) {
            Azure_Logger::error('Email init failed: ' . $e->getMessage(), array('module' => 'Email', 'file' => $e->getFile(), 'line' => $e->getLine()));
            error_log('Azure Plugin: Email init error - ' . $e->getMessage());
        }
    }

    /**
     * PTA module
     *   - Database, Manager, Shortcode, Forminator, BeaverBuilder: front-end + admin
     *   - Sync Engine, Groups Manager: admin/cron only (Graph API user provisioning)
     */
    private function init_pta_components($ctx) {
        try {
            // Always loaded (front-end shortcodes + Forminator/BB integrations + table accessors)
            $this->require_module_files(array(
                'class-pta-database.php',
                'class-pta-manager.php',
                'class-pta-shortcode.php',
                'class-pta-forminator.php',
                'class-pta-beaver-builder.php',
            ));

            if (class_exists('Azure_PTA_Database')) {
                Azure_PTA_Database::init();
            }
            if (class_exists('Azure_PTA_Manager')) {
                Azure_PTA_Manager::get_instance();
            }
            if (class_exists('Azure_PTA_Shortcode')) {
                new Azure_PTA_Shortcode();
            }
            if (class_exists('Azure_PTA_BeaverBuilder')) {
                new Azure_PTA_BeaverBuilder();
            }
            if (class_exists('Azure_PTA_Forminator')) {
                Azure_PTA_Forminator::get_instance();
            }

            // Admin/cron only
            if ($ctx['is_backend']) {
                $this->require_module_files(array('class-pta-sync-engine.php', 'class-pta-groups-manager.php'));
                if (class_exists('Azure_PTA_Sync_Engine')) {
                    new Azure_PTA_Sync_Engine();
                }
                if (class_exists('Azure_PTA_Groups_Manager')) {
                    new Azure_PTA_Groups_Manager();
                }
            }
        } catch (\Throwable $e) {
            Azure_Logger::error('PTA init failed: ' . $e->getMessage(), array('module' => 'PTA', 'file' => $e->getFile(), 'line' => $e->getLine()));
            error_log('Azure Plugin: PTA init error - ' . $e->getMessage());
        }
    }

    /**
     * OneDrive Media: 100% admin (media library replacement, browser, OAuth).
     */
    private function init_onedrive_media_components($ctx) {
        if (!$ctx['is_backend']) {
            return;
        }
        try {
            $this->require_module_files(array(
                'class-onedrive-media-auth.php',
                'class-onedrive-media-graph-api.php',
                'class-onedrive-media-manager.php',
            ));

            if (class_exists('Azure_OneDrive_Media_Auth')) {
                new Azure_OneDrive_Media_Auth();
            }
            if (class_exists('Azure_OneDrive_Media_GraphAPI')) {
                new Azure_OneDrive_Media_GraphAPI();
            }
            if (class_exists('Azure_OneDrive_Media_Manager')) {
                Azure_OneDrive_Media_Manager::get_instance();
            }
        } catch (\Throwable $e) {
            Azure_Logger::error('OneDrive Media init failed: ' . $e->getMessage(), array('module' => 'OneDrive', 'file' => $e->getFile(), 'line' => $e->getLine()));
            error_log('Azure Plugin: OneDrive Media init error - ' . $e->getMessage());
        }
    }
    
    /**
     * Idempotently seed the "Parents" newsletter list (role-bound to the
     * `parent` role). Called from the version-bump block in init() so we
     * only pay the option/insert cost when the plugin version actually
     * changes.
     *
     * Important: also REPAIRS the criteria on an existing Parents list.
     * Earlier seeds used the wrong criteria key (`role` singular instead
     * of `roles` plural array), which made Azure_Newsletter_Lists::get_subscribers()
     * report 0 even when parent-role users existed. This method overwrites
     * criteria on every run so the bug is self-healing.
     *
     * We deliberately do NOT seed PTSA Volunteers (the existing
     * "PTSA Board and Staff" list already covers that population via the
     * `participant` role) or School Staff (the admin handles staff imports
     * separately into the `school_staff` role).
     *
     * Skips silently if the Newsletter module isn't installed yet, so
     * activating the plugin without the Newsletter feature flag doesn't
     * fail.
     */
    private function seed_parent_population_lists() {
        try {
            $lists_path = AZURE_PLUGIN_PATH . 'includes/class-newsletter-lists.php';
            if (!class_exists('Azure_Newsletter_Lists')) {
                if (!file_exists($lists_path)) {
                    return;
                }
                require_once $lists_path;
            }
            $migration_path = AZURE_PLUGIN_PATH . 'includes/class-parent-migration.php';
            if (!class_exists('Azure_Parent_Migration')) {
                if (!file_exists($migration_path)) {
                    return;
                }
                require_once $migration_path;
            }

            $lists = new Azure_Newsletter_Lists();
            $correct_criteria = array('roles' => array('parent'));

            // Find an existing "Parents" list (case-insensitive) so we can
            // REPAIR criteria rather than create a duplicate.
            $existing_id = 0;
            $all = $lists->get_all_lists();
            if (is_array($all)) {
                foreach ($all as $l) {
                    if (isset($l->name) && strtolower(trim($l->name)) === 'parents') {
                        $existing_id = (int) $l->id;
                        break;
                    }
                }
            }

            if ($existing_id > 0) {
                $lists->update_list($existing_id, array(
                    'criteria' => $correct_criteria,
                ));
            } else {
                $lists->create_list(
                    'Parents',
                    'role',
                    'All parent-role users (auto-synced).',
                    $correct_criteria
                );
            }

            if (class_exists('Azure_Logger')) {
                Azure_Logger::info('Parents newsletter list seed/repair complete', array('module' => 'Core'));
            }
        } catch (\Throwable $e) {
            error_log('Azure Plugin: seed_parent_population_lists failed - ' . $e->getMessage());
        }
    }

    /**
     * Idempotently register a `school_staff` WP role for the
     * school-staff population the admin manages by hand (CSV exports
     * from a third-party newsletter tool → manual import). Cloned from
     * Subscriber so it has the standard "logged-in but no admin access"
     * cap set. The matching email domain lives in the
     * `pta_school_staff_domain` option, configured per tenant.
     *
     * Skips silently if the role already exists.
     */
    private function ensure_school_staff_role() {
        if (get_role('school_staff')) {
            return;
        }
        $subscriber = get_role('subscriber');
        $caps = $subscriber ? $subscriber->capabilities : array('read' => true);
        add_role('school_staff', __('School Staff', 'azure-plugin'), $caps);
        if (class_exists('Azure_Logger')) {
            Azure_Logger::info('Created school_staff role', array('module' => 'Core'));
        }
    }

    /**
     * Register Classes taxonomy. Called from init_classes_components() now —
     * the previous behavior of registering even when the module was disabled
     * was unnecessary (it was guarding against orphaned admin URLs only).
     */
    private function register_classes_taxonomy() {
        // Only register if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        // Check if already registered
        if (taxonomy_exists('class_provider')) {
            return;
        }
        
        $labels = array(
            'name'              => _x('Class Providers', 'taxonomy general name', 'azure-plugin'),
            'singular_name'     => _x('Class Provider', 'taxonomy singular name', 'azure-plugin'),
            'search_items'      => __('Search Providers', 'azure-plugin'),
            'all_items'         => __('All Providers', 'azure-plugin'),
            'edit_item'         => __('Edit Provider', 'azure-plugin'),
            'update_item'       => __('Update Provider', 'azure-plugin'),
            'add_new_item'      => __('Add New Provider', 'azure-plugin'),
            'new_item_name'     => __('New Provider Name', 'azure-plugin'),
            'menu_name'         => __('Class Providers', 'azure-plugin'),
        );
        
        $args = array(
            'labels'            => $labels,
            'hierarchical'      => false,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_nav_menus' => false,
            'show_tagcloud'     => false,
            'show_in_rest'      => true,
            'rewrite'           => array('slug' => 'class-provider'),
        );
        
        register_taxonomy('class_provider', array('product'), $args);
    }
    
    /**
     * Classes module: front-end (WC product type, shortcodes, prices) + admin.
     * Loaded as a unit because the sub-classes are interleaved across the request.
     */
    private function init_classes_components($ctx) {
        try {
            $this->require_module_files(array('class-classes-module.php'));
            $this->register_classes_taxonomy();
            if (class_exists('Azure_Classes_Module')) {
                Azure_Classes_Module::get_instance();
            }
        } catch (\Throwable $e) {
            Azure_Logger::error('Classes init failed: ' . $e->getMessage(), array('module' => 'Classes', 'file' => $e->getFile(), 'line' => $e->getLine()));
            error_log('Azure Plugin: Classes init error - ' . $e->getMessage());
        }
    }

    /**
     * Upcoming Events module: lazy-load.
     *
     * Previously this class was instantiated on every request just to register
     * the [up-next] shortcode. We register the shortcode tag with a stub
     * callback that requires the file and instantiates the class on first
     * render — so pages that don't use the shortcode pay nothing.
     */
    private function register_upcoming_shortcode_lazy() {
        $invalidate_up_next = function ($post_id = 0) {
            if ($post_id && function_exists('get_post_type')) {
                if (get_post_type($post_id) !== 'pta_event') {
                    return;
                }
            }
            $path = AZURE_PLUGIN_PATH . 'includes/class-upcoming-module.php';
            if (file_exists($path)) {
                require_once $path;
            }
            if (class_exists('Azure_Upcoming_Module')) {
                Azure_Upcoming_Module::invalidate_cache((int) $post_id);
            }
        };

        // The [up-next] shortcode is cached weekly. Bump the cache version
        // whenever a pta_event is edited so admins see updates without
        // waiting for the weekly TTL to expire.
        add_action('save_post_pta_event', $invalidate_up_next, 10, 1);
        add_action('before_delete_post',  $invalidate_up_next, 10, 1);

        if (shortcode_exists('up-next')) {
            return;
        }
        add_shortcode('up-next', function ($atts = array(), $content = null) {
            $path = AZURE_PLUGIN_PATH . 'includes/class-upcoming-module.php';
            if (file_exists($path)) {
                require_once $path;
            }
            if (class_exists('Azure_Upcoming_Module')) {
                return Azure_Upcoming_Module::get_instance()->render_upcoming_shortcode($atts);
            }
            return '';
        });
    }

    /**
     * Register the AcyMailing PTA Tools dynamic-text add-on so the
     * "PTA Tools" tile appears in AcyMailing's Dynamic text picker
     * with `[up-next]` shortcode presets. The loader hooks
     * `acym_load_installed_integrations` and is inert when AcyMailing
     * isn't installed.
     *
     * Public because it's wired as a `plugins_loaded` callback in
     * __construct() — early enough to beat AcyMailing's own integration
     * scan, which is too early for our `init`-time component bootstrap.
     */
    public function register_acymailing_addon() {
        $path = AZURE_PLUGIN_PATH . 'includes/class-acymailing-addon-loader.php';
        if (!file_exists($path)) {
            return;
        }
        require_once $path;
        if (class_exists('Azure_AcyMailing_Addon_Loader')) {
            Azure_AcyMailing_Addon_Loader::bootstrap();
        }
    }

    /**
     * Load the per-service email routing engine. Registered on
     * plugins_loaded priority 1 (see __construct) so its
     * `pre_wp_mail` priority-1 hook beats every other interceptor.
     * Storage + AJAX handlers + admin UI live elsewhere; this just
     * ensures the class is loaded and bootstrapped.
     */
    public function register_email_router() {
        $path = AZURE_PLUGIN_PATH . 'includes/class-email-router.php';
        if (!file_exists($path)) {
            return;
        }
        require_once $path;
        if (class_exists('Azure_Email_Router')) {
            Azure_Email_Router::bootstrap();
        }
    }

    /**
     * v3.125 — Boot the [up-next] theme presets system.
     *
     * Loads storage + CSS engine + AJAX handlers so the Calendar >
     * Upcoming Events admin tab can edit themes and the shortcode
     * can resolve the `theme=...` attribute at render time. Cheap
     * enough to load on every request: storage is a single option
     * read and CSS is transient-cached.
     */
    public function register_upnext_themes() {
        $path = AZURE_PLUGIN_PATH . 'includes/class-upnext-themes.php';
        if (!file_exists($path)) {
            return;
        }
        require_once $path;
        if (class_exists('Azure_UpNext_Themes')) {
            Azure_UpNext_Themes::bootstrap();
        }
    }

    /**
     * Newsletter module: REST webhooks + cron + admin dashboard.
     * No public-facing shortcodes — front-end pageloads can skip it entirely.
     */
    private function init_newsletter_components($ctx) {
        if (!$ctx['is_backend'] && !$ctx['is_rest']) {
            return;
        }
        try {
            $this->require_module_files(array('class-newsletter-module.php'));
            if ($ctx['is_admin']) {
                $this->require_module_files(array('class-newsletter-templates.php', 'class-newsletter-ajax.php'));
            }
            if (class_exists('Azure_Newsletter_Module')) {
                Azure_Newsletter_Module::get_instance();
            }
        } catch (\Throwable $e) {
            Azure_Logger::error('Newsletter init failed: ' . $e->getMessage(), array('module' => 'Newsletter', 'file' => $e->getFile(), 'line' => $e->getLine()));
            error_log('Azure Plugin: Newsletter init error - ' . $e->getMessage());
        }
    }

    /**
     * Auction module: hooks WooCommerce single-product templates (front-end)
     * plus admin/AJAX. Load whenever the module is enabled.
     */
    private function init_auction_components($ctx) {
        try {
            $this->require_module_files(array('class-auction-module.php'));
            if (class_exists('Azure_Auction_Module')) {
                Azure_Auction_Module::get_instance();
            }
        } catch (\Throwable $e) {
            Azure_Logger::error('Auction init failed: ' . $e->getMessage(), array('module' => 'Auction', 'file' => $e->getFile(), 'line' => $e->getLine()));
            error_log('Azure Plugin: Auction init error - ' . $e->getMessage());
        }
    }

    /**
     * Orders Reports module: WooCommerce order reporting builder + saved
     * reports + CSV export. Surfaced under Selling > Reports.
     *
     * The module's own constructor short-circuits when WooCommerce isn't
     * active, but we still check at the loader level so we don't even
     * require_once the file on non-WC sites (small but free saving).
     */
    private function init_orders_reports_components($ctx) {
        try {
            $this->require_module_files(array('class-orders-reports-module.php'));
            if (class_exists('Azure_Orders_Reports_Module')) {
                Azure_Orders_Reports_Module::get_instance();
            }
        } catch (\Throwable $e) {
            Azure_Logger::error('Orders Reports init failed: ' . $e->getMessage(), array('module' => 'OrdersReports', 'file' => $e->getFile(), 'line' => $e->getLine()));
            error_log('Azure Plugin: Orders Reports init error - ' . $e->getMessage());
        }
    }

    /**
     * Product Fields & User Children: WC filters that affect front-end product
     * display, cart, and the My Account tab. Load on every request.
     */
    private function init_product_fields_components($ctx) {
        try {
            $this->require_module_files(array(
                'class-product-fields-module.php',
                'class-user-children.php',
            ));
            if (class_exists('Azure_Product_Fields_Module')) {
                Azure_Product_Fields_Module::get_instance();
            }
            if (class_exists('Azure_User_Children')) {
                // Register WC My Account endpoints synchronously, before any
                // potential rewrite flush. Calling this from inside the main
                // init() body (rather than via an add_action('init', …) on a
                // priority that may have already passed) guarantees the
                // endpoint is in the live rewrite globals on this request.
                Azure_User_Children::bootstrap_endpoints();
                Azure_User_Children::get_instance();
            }

            // Admin-only consolidation tool. Loads only on admin/admin-ajax
            // so front-end requests pay nothing.
            if (!empty($ctx['is_admin'])) {
                $this->require_module_files(array(
                    'class-parent-role-admin.php',
                    'class-parent-migration.php',
                ));
                if (class_exists('Azure_Parent_Role_Admin')) {
                    Azure_Parent_Role_Admin::get_instance();
                }
                if (class_exists('Azure_Parent_Migration')) {
                    Azure_Parent_Migration::get_instance();
                }
            }
        } catch (\Throwable $e) {
            Azure_Logger::error('Product Fields init failed: ' . $e->getMessage(), array('module' => 'ProductFields', 'file' => $e->getFile(), 'line' => $e->getLine()));
            error_log('Azure Plugin: Product Fields init error - ' . $e->getMessage());
        }
    }

    /**
     * Donations: front-end checkout widget + admin.
     */
    private function init_donations_components($ctx) {
        try {
            $this->require_module_files(array('class-donations-module.php'));
            if (class_exists('Azure_Donations_Module')) {
                Azure_Donations_Module::get_instance();
            }
        } catch (\Throwable $e) {
            Azure_Logger::error('Donations init failed: ' . $e->getMessage(), array('module' => 'Donations', 'file' => $e->getFile(), 'line' => $e->getLine()));
            error_log('Azure Plugin: Donations init error - ' . $e->getMessage());
        }
    }

    /**
     * Volunteer Sign Up: front-end shortcode + admin.
     */
    private function init_volunteer_components($ctx) {
        try {
            $this->require_module_files(array('class-volunteer-signup.php'));
            if (class_exists('Azure_Volunteer_Signup')) {
                Azure_Volunteer_Signup::get_instance();
            }
        } catch (\Throwable $e) {
            Azure_Logger::error('Volunteer init failed: ' . $e->getMessage(), array('module' => 'Volunteer', 'file' => $e->getFile(), 'line' => $e->getLine()));
            error_log('Azure Plugin: Volunteer init error - ' . $e->getMessage());
        }
    }
    
    /**
     * Plugin activation with comprehensive debugging
     */
    public function activate() {
        // Create debug log file immediately
        $log_file = AZURE_PLUGIN_PATH . 'logs.md';
        $timestamp = date('Y-m-d H:i:s');
        $header = file_exists($log_file) ? '' : "# Azure Plugin Activation Debug Logs\n\n";
        
        // Helper function to write debug logs
        $write_log = function($message) use ($log_file, $timestamp) {
            $log_entry = "**{$timestamp}** {$message}  \n";
            file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        };
        
        $write_log($header . "🚀 **[START]** Plugin activation initiated");
        $write_log("📁 **[DEBUG]** Plugin path: " . AZURE_PLUGIN_PATH);
        $write_log("🌐 **[DEBUG]** WordPress version: " . get_bloginfo('version'));
        $write_log("🗄️ **[DEBUG]** Database version: " . $GLOBALS['wpdb']->db_version());
        
        try {
            $write_log("⏳ **[STEP 1]** Starting activation process");
            
            // Check if required directories exist
            $includes_dir = AZURE_PLUGIN_PATH . 'includes/';
            if (!is_dir($includes_dir)) {
                throw new Exception("Includes directory not found: {$includes_dir}");
            }
            $write_log("✅ **[STEP 1]** Includes directory exists");
            
            $write_log("⏳ **[STEP 2]** Loading core classes");
            // Ensure logger is available
            $logger_file = AZURE_PLUGIN_PATH . 'includes/class-logger.php';
            if (!file_exists($logger_file)) {
                throw new Exception("Logger class file not found: {$logger_file}");
            }
            
            if (!class_exists('Azure_Logger')) {
                require_once $logger_file;
                if (!class_exists('Azure_Logger')) {
                    throw new Exception("Failed to load Azure_Logger class after require");
                }
            }
            $write_log("✅ **[STEP 2]** Logger class loaded successfully");
            
            $write_log("⏳ **[STEP 3]** Loading database class");
            // Ensure database class is available
            $db_file = AZURE_PLUGIN_PATH . 'includes/class-database.php';
            if (!file_exists($db_file)) {
                throw new Exception("Database class file not found: {$db_file}");
            }
            
            if (!class_exists('Azure_Database')) {
                require_once $db_file;
                if (!class_exists('Azure_Database')) {
                    throw new Exception("Failed to load Azure_Database class after require");
                }
            }
            $write_log("✅ **[STEP 3]** Database class loaded successfully");
            
            $write_log("⏳ **[STEP 4]** Starting logger-based logging");
            // Log with our logger
            Azure_Logger::info('Azure Plugin activation started');
            $write_log("✅ **[STEP 4]** Logger-based logging working");
            
            $write_log("⏳ **[STEP 5]** Checking database connection");
            global $wpdb;
            $db_test = $wpdb->get_var("SELECT 1");
            if ($db_test !== '1') {
                throw new Exception("Database connection test failed");
            }
            $write_log("✅ **[STEP 5]** Database connection verified");
            
            $write_log("⏳ **[STEP 6]** Creating database tables");
            // Create database tables (including auction_bids and other module tables)
            try {
                Azure_Logger::info('Creating database tables');
                Azure_Database::create_tables();
                Azure_Logger::info('Database tables created successfully');
            } catch (\Throwable $e) {
                $write_log("❌ **[STEP 6 ERROR]** Failed to create main tables: " . $e->getMessage());
                Azure_Logger::error('Database table creation failed: ' . $e->getMessage());
            }

            // Module classes are no longer auto-loaded at plugins_loaded; activation
            // needs to require the files explicitly so create_tables() etc. are callable.
            $activation_files = array(
                'class-pta-database.php',
                'class-newsletter-module.php',
            );
            foreach ($activation_files as $f) {
                $p = AZURE_PLUGIN_PATH . 'includes/' . $f;
                if (file_exists($p)) {
                    require_once $p;
                }
            }

            // Create PTA database tables
            if (class_exists('Azure_PTA_Database')) {
                try {
                    Azure_Logger::info('Creating PTA database tables');
                    Azure_PTA_Database::create_pta_tables();
                    Azure_Logger::info('PTA database tables created successfully');
                    
                    $write_log("⏳ **[STEP 6b]** Seeding PTA data from CSV");
                    Azure_Logger::info('Seeding PTA initial data from CSV');
                    Azure_PTA_Database::seed_initial_data(false);
                    Azure_Logger::info('PTA initial data seeded successfully');
                    $write_log("✅ **[STEP 6b]** PTA data seeded from CSV");
                } catch (\Throwable $e) {
                    $write_log("❌ **[STEP 6 ERROR]** Failed to create/seed PTA tables: " . $e->getMessage());
                    Azure_Logger::error('Failed to create/seed PTA database tables: ' . $e->getMessage());
                }
            }
            
            // Create Newsletter database tables
            if (class_exists('Azure_Newsletter_Module')) {
                try {
                    $write_log("⏳ **[STEP 6c]** Creating Newsletter database tables");
                    Azure_Logger::info('Creating Newsletter database tables');
                    Azure_Newsletter_Module::create_tables();
                    Azure_Logger::info('Newsletter database tables created successfully');
                    $write_log("✅ **[STEP 6c]** Newsletter tables created");
                } catch (\Throwable $e) {
                    $write_log("❌ **[STEP 6c ERROR]** Failed to create Newsletter tables: " . $e->getMessage());
                    Azure_Logger::error('Failed to create Newsletter database tables: ' . $e->getMessage());
                }
            }
            
            $write_log("✅ **[STEP 6]** Database tables created and seeded");
            
            $write_log("⏳ **[STEP 7]** Creating AzureAD role");
            // Create AzureAD WordPress role
            $this->create_azuread_role();
            $write_log("✅ **[STEP 7]** AzureAD role created");
            
            $write_log("⏳ **[STEP 8]** Setting default options");
            // Set default options
            Azure_Logger::info('Setting default options');
            if (!get_option('azure_plugin_settings')) {
                $default_settings = array(
                    // General settings
                    'enable_sso' => false,
                    'enable_backup' => false,
                    'enable_calendar' => false,
                    'enable_email' => false,
                    'enable_pta' => false,
                    
                    // Common credentials
                    'use_common_credentials' => true,
                    'common_client_id' => '',
                    'common_client_secret' => '',
                    'common_tenant_id' => 'common',
                    
                    // SSO specific settings
                    'sso_client_id' => '',
                    'sso_client_secret' => '',
                    'sso_tenant_id' => 'common',
                    'sso_redirect_uri' => home_url('/wp-admin/admin-ajax.php?action=azure_sso_callback'),
                    'sso_require_sso' => false,
                    'sso_auto_create_users' => true,
                    'sso_default_role' => 'subscriber',
                    'sso_show_on_login_page' => true,
                    
                    // Backup specific settings
                    'backup_client_id' => '',
                    'backup_client_secret' => '',
                    'backup_storage_account' => '',
                    'backup_storage_key' => '',
                    'backup_container_name' => 'wordpress-backups',
                    'backup_types' => array('content', 'media', 'plugins', 'themes'),
                    'backup_retention_days' => 30,
                    'backup_max_execution_time' => 300,
                    'backup_schedule_enabled' => false,
                    'backup_schedule_frequency' => 'daily',
                    'backup_schedule_time' => '02:00',
                    'backup_email_notifications' => true,
                    'backup_notification_email' => get_option('admin_email'),
                    
                    // Calendar specific settings
                    'calendar_client_id' => '',
                    'calendar_client_secret' => '',
                    'calendar_tenant_id' => '',
                    'calendar_default_timezone' => 'America/New_York',
                    'calendar_default_view' => 'month',
                    'calendar_default_color_theme' => 'blue',
                    'calendar_cache_duration' => 3600,
                    'calendar_max_events_per_calendar' => 100,
                    
                    // Email specific settings
                    'email_client_id' => '',
                    'email_client_secret' => '',
                    'email_tenant_id' => '',
                    'email_auth_method' => 'graph_api',
                    'email_send_as_alias' => '',
                    'email_override_wp_mail' => false,
                    'email_hve_smtp_server' => 'smtp-hve.office365.com',
                    'email_hve_smtp_port' => 587,
                    'email_hve_username' => '',
                    'email_hve_password' => '',
                    'email_hve_from_email' => '',
                    'email_hve_encryption' => 'tls',
                    'email_hve_override_wp_mail' => false,
                    'email_acs_connection_string' => '',
                    'email_acs_endpoint' => '',
                    'email_acs_access_key' => '',
                    'email_acs_from_email' => '',
                    'email_acs_display_name' => '',
                    'email_acs_override_wp_mail' => false,

                    // PTA event calendar settings (replaces legacy TEC integration in v3.97)
                    'pta_calendar_owner' => 'pta',
                );
                update_option('azure_plugin_settings', $default_settings);
                Azure_Logger::info('Default settings created');
                $write_log("✅ **[STEP 7]** Default settings created");
            } else {
                $write_log("ℹ️ **[STEP 7]** Settings already exist, skipping");
            }
            
            $write_log("⏳ **[STEP 8]** Creating backup directory");
            // Create backup directory
            $backup_dir = AZURE_PLUGIN_PATH . 'backups/';
            if (!is_dir($backup_dir)) {
                if (!wp_mkdir_p($backup_dir)) {
                    throw new Exception("Failed to create backup directory: {$backup_dir}");
                }
                // Add .htaccess for security
                $htaccess_content = "Order deny,allow\nDeny from all\n";
                file_put_contents($backup_dir . '.htaccess', $htaccess_content);
            }
            $write_log("✅ **[STEP 8]** Backup directory ready");
            
            $write_log("⏳ **[STEP 9]** Finalizing activation");
            Azure_Logger::info('Plugin activation completed successfully');
            $write_log("🎉 **[SUCCESS]** Plugin activation completed successfully");
            
        } catch (\Throwable $e) {
            $error_msg = 'Failed during activation: ' . $e->getMessage();
            $write_log("❌ **[ERROR]** {$error_msg}");
            $write_log("📍 **[ERROR FILE]** " . $e->getFile() . " line " . $e->getLine());
            $write_log("📝 **[ERROR TRACE]** " . $e->getTraceAsString());
            
            if (class_exists('Azure_Logger')) {
                Azure_Logger::error($error_msg);
                Azure_Logger::error('Error in file: ' . $e->getFile() . ' line ' . $e->getLine());
                Azure_Logger::error('Stack trace: ' . $e->getTraceAsString());
            } else {
                error_log('Azure Plugin: ' . $error_msg);
            }
            
            wp_die(
                'PTA Tools activation failed: ' . esc_html($e->getMessage()) . '<br><br>Check <code>' . esc_html($log_file) . '</code> for detailed logs.<br><br><a href="' . esc_url(admin_url('plugins.php')) . '">Back to Plugins</a>',
                'Plugin Activation Error',
                array('back_link' => true)
            );
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        if (class_exists('Azure_Logger')) {
            Azure_Logger::info('Azure Plugin deactivated');
        }
        
        // Clear any scheduled events from all modules
        wp_clear_scheduled_hook('azure_backup_scheduled');
        wp_clear_scheduled_hook('azure_backup_cleanup');
        wp_clear_scheduled_hook('azure_calendar_sync_events');
        wp_clear_scheduled_hook('azure_mail_token_refresh');
        wp_clear_scheduled_hook('azure_sso_scheduled_sync');
        wp_clear_scheduled_hook('azure_volunteer_send_reminders');
        wp_clear_scheduled_hook('azure_parent_activation_cleanup');

        // Disable the AcyMailing dynamic-text add-on in AcyMailing's
        // plugin registry so it stops appearing in the Dynamic text
        // picker. Safe no-op when AcyMailing isn't installed.
        $loader = AZURE_PLUGIN_PATH . 'includes/class-acymailing-addon-loader.php';
        if (file_exists($loader)) {
            require_once $loader;
            if (class_exists('Azure_AcyMailing_Addon_Loader')) {
                Azure_AcyMailing_Addon_Loader::on_deactivate();
            }
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Create AzureAD WordPress role for SSO users
     */
    private function create_azuread_role() {
        // Check if role already exists
        if (get_role('azuread')) {
            Azure_Logger::info('AzureAD role already exists');
            return;
        }
        
        // Add AzureAD role with basic capabilities
        add_role('azuread', 'Azure AD User', array(
            'read' => true,
            'edit_posts' => false,
            'delete_posts' => false,
            'publish_posts' => false,
            'upload_files' => false,
            'edit_pages' => false,
            'edit_others_posts' => false,
            'edit_published_posts' => false,
            'delete_others_posts' => false,
            'delete_published_posts' => false,
            'delete_pages' => false,
            'manage_categories' => false,
            'manage_links' => false,
            'moderate_comments' => false,
            'unfiltered_html' => false,
            'edit_others_pages' => false,
            'edit_published_pages' => false,
            'delete_others_pages' => false,
            'delete_published_pages' => false
        ));
        
        Azure_Logger::info('Created AzureAD role for SSO users');
    }
}

// Initialize the plugin
try {
    AzurePlugin::get_instance();
} catch (\Throwable $e) {
    if (class_exists('Azure_Logger')) {
        Azure_Logger::error('Plugin initialization failed: ' . $e->getMessage(), array(
            'module' => 'Core',
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ));
    }
    error_log('Azure Plugin: Fatal initialization error - ' . $e->getMessage());
}