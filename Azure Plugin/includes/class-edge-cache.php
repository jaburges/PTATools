<?php
/**
 * Edge cache coordination for Azure Front Door.
 *
 * Front Door serves wilderptsa.net and, once caching is switched on, will hold
 * anonymous HTML at the edge. Three things have to be true for that to be both
 * correct and useful, and all three live here:
 *
 *   1. A response rendered for a signed-in user must never be storable in a
 *      shared cache. Front Door rules can do this, but rules are configuration
 *      that anyone with portal access can change; this header is the copy that
 *      lives in code and goes through review. If the two ever disagree, this
 *      one still keeps personal markup out of the cache.
 *   2. JavaScript on a cached page needs to know whether the visitor is signed
 *      in, so it can replace the guest header with a real one. It cannot read
 *      `wordpress_logged_in_*` because WordPress sets that HttpOnly, hence the
 *      `pta_signed_in` marker cookie written here. The marker carries no
 *      identity — only the fact that a session exists.
 *   3. Edits have to reach visitors promptly. The TTL below bounds staleness,
 *      and a purge on publish removes it almost entirely.
 *
 * @package Azure_Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Edge_Cache {

    /** Readable by JavaScript by design; holds no identity, only presence. */
    const MARKER_COOKIE = 'pta_signed_in';

    /**
     * How long Front Door may serve an anonymous page without revalidating.
     * Sent as `s-maxage`, paired with `max-age=0` so browsers always
     * revalidate and a visitor's own back button never shows stale content.
     * This is the backstop for a purge that failed or was never configured.
     */
    const SHARED_MAX_AGE = 300;

    /**
     * Publishing several posts in a row should not fire several global purges.
     * A short window collapses a burst into one.
     */
    const PURGE_DEBOUNCE_KEY     = 'azure_edge_purge_recent';
    const PURGE_DEBOUNCE_SECONDS = 30;

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Two hooks for cache headers, deliberately. `send_headers` runs before
        // the main query, so conditional tags are not usable there, but it is
        // early and unconditional — the right place for the signed-in
        // guarantee. `template_redirect` runs after the query, which is the
        // earliest point is_cart() and friends can be trusted.
        add_action('send_headers', array($this, 'send_private_headers_when_signed_in'));
        add_action('template_redirect', array($this, 'send_shared_cache_headers'), 99);

        add_action('wp_login', array($this, 'set_marker_cookie'), 10, 0);
        add_action('wp_logout', array($this, 'clear_marker_cookie'), 10, 0);
        add_action('clear_auth_cookie', array($this, 'clear_marker_cookie'));
        add_action('init', array($this, 'backfill_marker_cookie'));

        add_action('transition_post_status', array($this, 'purge_on_post_change'), 10, 3);
        add_action('wp_update_nav_menu', array($this, 'purge_on_menu_change'));
    }

    // ─────────────────────────────────────────────────────────────────
    //  Cache-Control
    // ─────────────────────────────────────────────────────────────────

    /**
     * Mark every signed-in response private and unstorable.
     *
     * Deliberately does not inspect the request beyond the session: any page
     * can pick up an admin bar or a personalised widget, and the cost of being
     * wrong in this direction is one visitor's name served to strangers.
     */
    public function send_private_headers_when_signed_in() {
        if (is_admin() || headers_sent() || !is_user_logged_in()) {
            return;
        }
        header('Cache-Control: private, no-store, max-age=0');
    }

    /**
     * Offer anonymous, side-effect-free page views to the shared cache.
     *
     * Pages WooCommerce manages its own headers for are skipped rather than
     * overridden — WC_Cache_Helper already sends no-cache on cart, checkout
     * and account, and it is the authority on its own pages.
     */
    public function send_shared_cache_headers() {
        if (is_admin() || headers_sent() || is_user_logged_in()) {
            return;
        }

        // Both branches are explicit on purpose. Front Door assigns its own
        // default TTL to a response that arrives with no cache directives at
        // all, so staying silent here would hand the decision to whatever the
        // edge defaults happen to be rather than to this list.
        if (!$this->is_cacheable_anonymous_request()) {
            header('Cache-Control: private, no-store, max-age=0');
            return;
        }

        header('Cache-Control: public, max-age=0, s-maxage=' . (int) self::SHARED_MAX_AGE);
    }

    /**
     * Whether an anonymous response is safe for every other anonymous visitor.
     */
    private function is_cacheable_anonymous_request() {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method !== 'GET') {
            return false;
        }

        // Previews and the customizer render unpublished content, and a 404 or
        // a search result is cheap to regenerate but expensive to get wrong.
        if (is_preview() || is_customize_preview() || is_search() || is_404()) {
            return false;
        }

        // Password-protected content is the sharpest edge here. Once a visitor
        // enters the password the response contains the unlocked content while
        // the visitor is still anonymous, so caching it would hand that content
        // to everyone who asks for the URL.
        if (function_exists('post_password_required') && post_password_required()) {
            return false;
        }

        // A visitor holding cart, session or password state may see it
        // reflected in the page, so nothing they fetch is shareable.
        if ($this->has_visitor_state_cookie()) {
            return false;
        }

        if (function_exists('is_cart') && (is_cart() || is_checkout())) {
            return false;
        }
        if (function_exists('is_account_page') && is_account_page()) {
            return false;
        }

        // Cart mutations arrive as GETs with these parameters.
        foreach (array('add-to-cart', 'wc-ajax', 'removed_item', 'undo_item') as $param) {
            if (isset($_GET[$param]) && $_GET[$param] !== '') {
                return false;
            }
        }

        return true;
    }

    private function has_visitor_state_cookie() {
        if (empty($_COOKIE) || !is_array($_COOKIE)) {
            return false;
        }
        $prefixes = array(
            'woocommerce_items_in_cart',
            'woocommerce_cart_hash',
            'wp_woocommerce_session_',
            // Holder has unlocked a password-protected post.
            'wp-postpass_',
            // Commenter identity is echoed back into the comment form.
            'comment_author_',
        );
        foreach (array_keys($_COOKIE) as $name) {
            foreach ($prefixes as $prefix) {
                if (strpos((string) $name, $prefix) === 0) {
                    return true;
                }
            }
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Signed-in marker cookie
    // ─────────────────────────────────────────────────────────────────

    public function set_marker_cookie() {
        $this->write_marker_cookie('1', time() + (2 * DAY_IN_SECONDS));
    }

    public function clear_marker_cookie() {
        $this->write_marker_cookie('', time() - YEAR_IN_SECONDS);
    }

    /**
     * Give an already-signed-in visitor the marker without making them log in
     * again. Sessions that predate this code would otherwise never hydrate.
     */
    public function backfill_marker_cookie() {
        if (headers_sent() || !is_user_logged_in()) {
            return;
        }
        if (isset($_COOKIE[self::MARKER_COOKIE]) && $_COOKIE[self::MARKER_COOKIE] === '1') {
            return;
        }
        $this->set_marker_cookie();
    }

    private function write_marker_cookie($value, $expires) {
        if (headers_sent()) {
            return;
        }
        // Array signature so SameSite can be set; requires PHP 7.3+, and the
        // container runs 8.3.
        setcookie(self::MARKER_COOKIE, (string) $value, array(
            'expires'  => (int) $expires,
            'path'     => (defined('COOKIEPATH') && COOKIEPATH) ? COOKIEPATH : '/',
            'domain'   => (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ));
        $_COOKIE[self::MARKER_COOKIE] = (string) $value;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Purge on content change
    // ─────────────────────────────────────────────────────────────────

    /**
     * Purge when a public post enters or leaves published state.
     *
     * Draft-to-draft saves are ignored: nothing anonymous can see has changed,
     * and an author saving repeatedly should not purge the whole edge cache.
     */
    public function purge_on_post_change($new_status, $old_status, $post) {
        if (!($post instanceof WP_Post)) {
            return;
        }
        if ($new_status !== 'publish' && $old_status !== 'publish') {
            return;
        }
        if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
            return;
        }

        $type = get_post_type_object($post->post_type);
        if (!$type || empty($type->public)) {
            return;
        }

        // Parents have no editing rights, so nothing they do should be able to
        // trigger a global purge. Unauthenticated transitions (imports, WP-CLI,
        // the cron job) are left alone — there is no user to check.
        if (is_user_logged_in() && !current_user_can('edit_posts')) {
            return;
        }

        $this->request_purge(sprintf('%s #%d %s to %s', $post->post_type, $post->ID, $old_status, $new_status));
    }

    public function purge_on_menu_change($menu_id) {
        if (is_user_logged_in() && !current_user_can('edit_theme_options')) {
            return;
        }
        $this->request_purge('nav menu #' . (int) $menu_id);
    }

    /**
     * Ask Front Door to drop everything, at most once per debounce window.
     *
     * Dispatched non-blocking: a publish should not wait on ARM, and a purge
     * that fails is a bounded staleness problem rather than a lost edit,
     * because SHARED_MAX_AGE still expires the entry.
     */
    private function request_purge($reason) {
        if (!class_exists('Azure_Platform_Sync')
            || !method_exists('Azure_Platform_Sync', 'burst_afd_cache')) {
            return;
        }

        if (get_transient(self::PURGE_DEBOUNCE_KEY)) {
            return;
        }
        set_transient(self::PURGE_DEBOUNCE_KEY, time(), self::PURGE_DEBOUNCE_SECONDS);

        $result = Azure_Platform_Sync::burst_afd_cache(false);

        if (class_exists('Azure_Logger')) {
            if (!empty($result['success'])) {
                Azure_Logger::info('Edge cache: purge dispatched after ' . $reason, 'EdgeCache');
            } else {
                $message = isset($result['message']) ? $result['message'] : 'unknown error';
                Azure_Logger::warning('Edge cache: purge not sent after ' . $reason . ' — ' . $message, 'EdgeCache');
            }
        }
    }
}
