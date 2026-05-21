<?php
/**
 * Admin Performance
 *
 * Targeted optimizations for the WordPress admin to reduce per-page-load overhead.
 *
 * What this does (admin-only, never affects the front-end):
 *   - Slows the WP Heartbeat to 60s on dashboard/list screens (keeps 15s on post-edit)
 *   - Removes high-cost dashboard widgets that make outbound HTTP calls
 *     (WordPress events/news, primary news feed, BB-Plugin promo, WPMU DEV news,
 *     Site Health, Quick Draft, At a Glance recent comments, etc.)
 *   - Hides the WordPress "welcome" panel
 *   - Removes the admin emoji scripts
 *   - Disables the heavy "browse happy" / "serve happy" admin nags
 *
 * All optimizations can be disabled with the option `azure_admin_performance` = `0`.
 * Default: enabled.
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Admin_Performance {

    private static $instance = null;
    const OPTION_KEY = 'azure_admin_performance';

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Wire on init so we can check is_admin() reliably
        add_action('init', array($this, 'maybe_apply'), 1);
    }

    public static function is_enabled() {
        // Default ON. Users can set option to '0' to disable.
        return get_option(self::OPTION_KEY, '1') !== '0';
    }

    public function maybe_apply() {
        if (!is_admin() || !self::is_enabled()) {
            return;
        }

        // Skip optimizations during AJAX/cron/REST except where they apply
        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }

        $this->tune_heartbeat();
        $this->trim_dashboard_widgets();
        $this->disable_welcome_panel();
        $this->disable_admin_emojis();
        $this->disable_browse_happy();
    }

    /**
     * Slow Heartbeat to 60s on most admin pages.
     * Keep the default 15s on post-edit screens (autosave + locking depend on it).
     */
    private function tune_heartbeat() {
        add_filter('heartbeat_settings', function ($settings) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            $is_edit = $screen && in_array($screen->base, array('post', 'edit', 'edit-tags', 'term'), true);
            // 60s on dashboard/lists, 15s on edit screens
            $settings['interval'] = $is_edit ? 15 : 60;
            return $settings;
        }, 99);
    }

    /**
     * Remove high-cost dashboard widgets that issue outbound HTTP calls
     * or run heavy DB queries on every dashboard load.
     */
    private function trim_dashboard_widgets() {
        add_action('wp_dashboard_setup', function () {
            global $wp_meta_boxes;

            $kill = array(
                'normal' => array(
                    'core' => array(
                        'dashboard_right_now',          // At a glance — runs counts on big tables
                        'dashboard_activity',           // Recent comments/posts — DB heavy
                        'dashboard_incoming_links',
                        'dashboard_plugins',
                    ),
                ),
                'side' => array(
                    'core' => array(
                        'dashboard_quick_press',
                        'dashboard_recent_drafts',
                        'dashboard_primary',            // WordPress events/news (HTTP)
                        'dashboard_secondary',
                    ),
                ),
            );

            foreach ($kill as $context => $priorities) {
                foreach ($priorities as $prio => $ids) {
                    foreach ($ids as $id) {
                        remove_meta_box($id, 'dashboard', $prio);
                    }
                }
            }

            // Third-party widgets that are common offenders
            $third_party_normal = array(
                'wpe_dify_news_feed',           // WP Engine
                'wpseo-dashboard-overview',     // Yoast
                'monsterinsights_reports_widget_network',
                'wpmudev_dashboard_news',       // WPMU DEV
                'wpmudev_dashboard_widget',
                'rg_forms_dashboard',           // Gravity Forms
                'forminator_quick_setup',       // Forminator
                'tribe-dashboard',              // The Events Calendar
                'fl-dashboard-widget',          // Beaver Builder news
                'woocommerce_dashboard_status',
                'koko_analytics_dashboard_widget',
            );
            foreach ($third_party_normal as $id) {
                remove_meta_box($id, 'dashboard', 'normal');
                remove_meta_box($id, 'dashboard', 'side');
            }

            // Welcome panel
            remove_action('welcome_panel', 'wp_welcome_panel');
        }, 99);
    }

    private function disable_welcome_panel() {
        // Welcome panel: hide for current user without DB write per request
        add_action('admin_init', function () {
            if (function_exists('update_user_meta') && get_current_user_id()) {
                $current = (int) get_user_meta(get_current_user_id(), 'show_welcome_panel', true);
                if ($current !== 0) {
                    update_user_meta(get_current_user_id(), 'show_welcome_panel', 0);
                }
            }
        });
    }

    private function disable_admin_emojis() {
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
    }

    private function disable_browse_happy() {
        // These run an HTTP call to api.wordpress.org on every dashboard load
        remove_action('admin_notices', 'maintenance_nag', 10);
        // Browse Happy / Serve Happy run on dashboard, kill the dashboard meta box
        add_action('wp_dashboard_setup', function () {
            remove_meta_box('dashboard_browser_nag', 'dashboard', 'normal');
            remove_meta_box('dashboard_php_nag', 'dashboard', 'normal');
        }, 100);
    }
}
