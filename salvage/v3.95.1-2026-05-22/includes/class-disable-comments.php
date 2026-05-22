<?php
/**
 * Disable All Comments Module
 *
 * When the `enable_no_comments` setting is checked in PTA Tools settings,
 * this module strips comment functionality from the entire site:
 *
 *   - Closes commenting + pingbacks on every existing post/page/CPT and
 *     prevents WP from re-opening it on new content
 *   - Removes the comments REST routes (so spam bots get a 404 instead
 *     of touching the DB)
 *   - Drops the comments + ping RSS feeds and the EditURI/wlwmanifest
 *     header links (smaller HTML payload)
 *   - Removes the Comments admin menu, dashboard widget, toolbar item,
 *     and recent-comments widget
 *   - Redirects /wp-admin/edit-comments.php and /wp-admin/options-discussion.php
 *     to the dashboard
 *
 * Replaces the standalone "Disable All Comments" code snippet that was
 * previously registered through WPCode. Owns the comment-blocking
 * behavior so WPCode can be uninstalled without losing the policy.
 *
 * Loaded unconditionally on frontend + admin requests via the main
 * plugin loader; the module reads the setting once and bails immediately
 * if commenting should remain enabled, so the per-request cost when
 * disabled is one option lookup (already in the per-request cache via
 * Azure_Settings::get_all_settings()).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Disable_Comments {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Read once; everything below is essentially free if the
        // setting is off.
        $enabled = false;
        if (class_exists('Azure_Settings')) {
            $settings = Azure_Settings::get_all_settings();
            $enabled  = !empty($settings['enable_no_comments']);
        }
        if (!$enabled) {
            return;
        }

        $this->register_frontend_hooks();
        if (is_admin()) {
            $this->register_admin_hooks();
        }
        $this->register_rest_and_feed_hooks();
    }

    // ─────────────────────────────────────────────────────────────────
    //  Frontend / content-side
    // ─────────────────────────────────────────────────────────────────

    private function register_frontend_hooks() {
        // 1) Make all post types report "comments not supported" so
        //    themes that check `post_type_supports($pt, 'comments')`
        //    don't render the comment form/list.
        add_action('init', array($this, 'remove_comment_support_from_all_post_types'), 100);

        // 2) Force the database state for any existing post: close
        //    comments + pings on every read. Cheap — runs only on
        //    objects already loaded by WP.
        add_filter('comments_open', '__return_false', 20, 2);
        add_filter('pings_open',    '__return_false', 20, 2);

        // 3) Hide existing comments from any template that reads them.
        add_filter('comments_array', '__return_empty_array', 10, 2);
        add_filter('get_comments_number', '__return_zero', 10, 2);

        // 4) Strip the comments + pingback RSS feeds (cuts ~2-3 lines
        //    from <head> output, shrinks HTML).
        remove_action('wp_head', 'feed_links_extra', 3);
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wlwmanifest_link');

        // 5) Disable the XML-RPC pingback method — common spam vector.
        add_filter('xmlrpc_methods', array($this, 'remove_pingback_xmlrpc'), 10, 1);
        add_filter('wp_headers',      array($this, 'remove_x_pingback_header'), 10, 1);
    }

    public function remove_comment_support_from_all_post_types() {
        $types = get_post_types();
        foreach ($types as $pt) {
            if (post_type_supports($pt, 'comments')) {
                remove_post_type_support($pt, 'comments');
            }
            if (post_type_supports($pt, 'trackbacks')) {
                remove_post_type_support($pt, 'trackbacks');
            }
        }
    }

    public function remove_pingback_xmlrpc($methods) {
        if (is_array($methods)) {
            unset($methods['pingback.ping'], $methods['pingback.extensions.getPingbacks']);
        }
        return $methods;
    }

    public function remove_x_pingback_header($headers) {
        if (is_array($headers) && isset($headers['X-Pingback'])) {
            unset($headers['X-Pingback']);
        }
        return $headers;
    }

    // ─────────────────────────────────────────────────────────────────
    //  REST + feeds
    // ─────────────────────────────────────────────────────────────────

    private function register_rest_and_feed_hooks() {
        // Strip /wp/v2/comments routes from the REST schema so bots
        // get a 404 instead of being able to enumerate comment data.
        add_filter('rest_endpoints', array($this, 'filter_rest_endpoints'), 10, 1);
    }

    public function filter_rest_endpoints($endpoints) {
        if (!is_array($endpoints)) {
            return $endpoints;
        }
        foreach (array_keys($endpoints) as $route) {
            if (strpos($route, '/wp/v2/comments') === 0) {
                unset($endpoints[$route]);
            }
        }
        return $endpoints;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Admin UI
    // ─────────────────────────────────────────────────────────────────

    private function register_admin_hooks() {
        // Remove the Comments item from the main admin menu.
        add_action('admin_menu', array($this, 'remove_admin_menu_item'), 100);

        // Remove the Comments item from the admin toolbar (top bar)
        // for both backend AND frontend (when logged in).
        add_action('admin_bar_menu', array($this, 'remove_admin_bar_item'), 999);
        add_action('wp_before_admin_bar_render', array($this, 'remove_admin_bar_item_legacy'), 100);

        // Hide dashboard widgets that reference comments.
        add_action('wp_dashboard_setup', array($this, 'remove_dashboard_widgets'), 100);

        // Block the comments + discussion-settings admin pages and
        // bounce visitors back to the dashboard. Stops accidental
        // navigation that would let an admin re-enable comments.
        add_action('admin_init', array($this, 'block_comments_admin_pages'), 1);
    }

    public function remove_admin_menu_item() {
        remove_menu_page('edit-comments.php');
        // Also remove the "Discussion" page under Settings.
        remove_submenu_page('options-general.php', 'options-discussion.php');
    }

    public function remove_admin_bar_item($wp_admin_bar) {
        if (is_object($wp_admin_bar) && method_exists($wp_admin_bar, 'remove_node')) {
            $wp_admin_bar->remove_node('comments');
        }
    }

    public function remove_admin_bar_item_legacy() {
        global $wp_admin_bar;
        if (is_object($wp_admin_bar) && method_exists($wp_admin_bar, 'remove_menu')) {
            $wp_admin_bar->remove_menu('comments');
        }
    }

    public function remove_dashboard_widgets() {
        remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
        remove_meta_box('dashboard_comments',         'dashboard', 'normal');
    }

    public function block_comments_admin_pages() {
        global $pagenow;
        if (!isset($pagenow)) {
            return;
        }
        $blocked = array('edit-comments.php', 'options-discussion.php', 'comment.php');
        if (in_array($pagenow, $blocked, true)) {
            wp_safe_redirect(admin_url(), 301);
            exit;
        }
    }
}
