<?php
/**
 * Parent Role
 *
 * Registers the `parent` WordPress role used by the Connected Family +
 * Parent Import (v3.67). Imported parents are created with this role and
 * `_pta_login_disabled = 1` meta so they cannot sign in until the admin
 * runs the welcome-email tool, which clears the flag and emails a temp
 * password.
 *
 * The class is intentionally tiny: a one-shot role registration on plugin
 * upgrade plus an `authenticate` filter that short-circuits sign-in for
 * disabled accounts. Force-password-change on first login is enforced via
 * `template_redirect`.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Parent_Role {

    const ROLE_SLUG          = 'parent';
    const META_LOGIN_DISABLED = '_pta_login_disabled';
    const META_FORCE_PW_RESET = '_pta_force_password_change';
    const META_LAST_LOGIN     = '_pta_last_login';

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Enforced on every login attempt.
        add_filter('authenticate', array($this, 'block_disabled_logins'), 30, 3);

        // First-login redirect to force password change. Cheap front-end
        // hook — only runs for logged-in users carrying the meta flag.
        add_action('template_redirect', array($this, 'maybe_force_password_change'));

        // Allow the user to clear the flag from My Account → Account details.
        add_action('woocommerce_save_account_details', array($this, 'clear_force_pw_on_password_change'), 10, 1);

        // Stamp last-login meta on every successful sign-in (SSO + native).
        // One user-meta write per actual login event — negligible cost,
        // powers the Parents dashboard widget without any custom table.
        add_action('wp_login', array(__CLASS__, 'record_last_login'), 10, 2);

        // Self-heal: if the role isn't in wp_user_roles yet (e.g. the
        // upgrade path was short-circuited, or another plugin removed it),
        // register it on the next admin request. get_role() is an in-memory
        // lookup against the already-loaded $wp_roles global so this costs
        // nothing on the front-end or for logged-out visitors.
        add_action('admin_init', array(__CLASS__, 'maybe_self_heal_role'));
    }

    /**
     * Stamp the user's last-login timestamp in MySQL DATETIME format
     * (UTC, matches `current_time('mysql', true)`). Stored as user_meta
     * so the Parents widget can do a single indexed query without an
     * extra custom table.
     */
    public static function record_last_login($user_login, $user) {
        if (!($user instanceof WP_User)) {
            return;
        }
        update_user_meta($user->ID, self::META_LAST_LOGIN, current_time('mysql', true));
    }

    /**
     * Idempotent role registration on admin_init. Only writes to the
     * wp_user_roles option if the Parent role is genuinely missing.
     */
    public static function maybe_self_heal_role() {
        if (!get_role(self::ROLE_SLUG)) {
            self::register_role();
        }
    }

    /**
     * Register / refresh the Parent role with the union of Subscriber +
     * WooCommerce Customer capability sets. Called from the plugin upgrade
     * path so we only pay the option-write cost when the version actually
     * changes (and from admin_init self-heal if the role goes missing).
     *
     * Why merge both Subscriber AND Customer:
     *   - Subscriber is WordPress' canonical "logged-in, no admin access"
     *     baseline. Cloning each upgrade keeps Parent in sync with whatever
     *     caps Subscriber picks up from other plugins.
     *   - WooCommerce assigns the `customer` role to anyone who places an
     *     order. Without the customer caps, a Parent could place an order
     *     and end up with TWO roles (parent + customer), which clutters
     *     the user list and confuses role-based reports.
     *   - By merging customer caps into Parent up front, we can safely
     *     strip the auto-added `customer` role on first purchase (or just
     *     leave both — neither hurts) and Parent has every cap WC checks
     *     for at checkout / My Account / order management.
     *   - Role editor plugins generally only list roles with mapped caps;
     *     copying the full cap set makes Parent visible/editable everywhere.
     */
    public static function register_role() {
        $subscriber = get_role('subscriber');
        $customer   = get_role('customer'); // WooCommerce role; may be null if WC not active

        $base_caps = array('read' => true);
        if ($subscriber && is_array($subscriber->capabilities)) {
            $base_caps = array_merge($base_caps, $subscriber->capabilities);
        }
        if ($customer && is_array($customer->capabilities)) {
            $base_caps = array_merge($base_caps, $customer->capabilities);
        }

        $existing = get_role(self::ROLE_SLUG);
        if (!$existing) {
            add_role(self::ROLE_SLUG, __('Parent', 'azure-plugin'), $base_caps);
            return;
        }

        // Add any caps the source roles have that Parent is missing. We
        // don't strip caps an admin may have manually granted to Parent —
        // only additive sync.
        foreach ($base_caps as $cap => $grant) {
            if (empty($existing->capabilities[$cap])) {
                $existing->add_cap($cap, (bool) $grant);
            }
        }
    }

    /**
     * Block sign-in for users whose `_pta_login_disabled` meta is truthy.
     * Returns a WP_Error so all auth handlers (cookie, app password,
     * SSO via authenticate filter) reject identically.
     */
    public function block_disabled_logins($user, $username, $password) {
        if (!is_a($user, 'WP_User')) {
            return $user;
        }
        $disabled = get_user_meta($user->ID, self::META_LOGIN_DISABLED, true);
        if (!empty($disabled) && $disabled !== '0') {
            return new WP_Error(
                'pta_login_disabled',
                __('This account is not yet active. Please contact the PTA for an invitation.', 'azure-plugin')
            );
        }
        return $user;
    }

    /**
     * On first login (after the welcome-email tool grants access), redirect
     * the user to My Account → Account details until they change their
     * password. Skips admin and AJAX/REST requests.
     */
    public function maybe_force_password_change() {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }
        if (!is_user_logged_in()) {
            return;
        }
        $user_id = get_current_user_id();
        $force = get_user_meta($user_id, self::META_FORCE_PW_RESET, true);
        if (!$force || $force === '0') {
            return;
        }

        if (!function_exists('wc_get_account_endpoint_url')) {
            return;
        }
        $target = wc_get_account_endpoint_url('edit-account');
        $current = home_url(add_query_arg(array(), $_SERVER['REQUEST_URI']));
        if (strpos($current, $target) === 0) {
            // Already on the change-password page; show a notice. We
            // intentionally point at the temp password we mailed because
            // WC requires "Current password" to set a new one — typing
            // the temp from the email satisfies that without us having
            // to monkey-patch WC's validator.
            $msg = __('Please set a new password to finish activating your account. Use the temporary password from your welcome email as the "Current password".', 'azure-plugin');
            if (function_exists('wc_add_notice') && !wc_has_notice($msg)) {
                wc_add_notice($msg, 'notice');
            }
            return;
        }
        wp_safe_redirect($target);
        exit;
    }

    /**
     * When a user changes their password from Account details, clear the
     * force-change flag so subsequent visits behave normally.
     */
    public function clear_force_pw_on_password_change($user_id) {
        if (!$user_id) {
            return;
        }
        if (!empty($_POST['password_1'])) {
            delete_user_meta($user_id, self::META_FORCE_PW_RESET);
        }
    }

}
