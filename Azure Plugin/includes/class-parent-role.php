<?php
/**
 * Parent Role
 *
 * Registers the `parent` and `alumni` WordPress roles used by Connected
 * Family + Parent Import. Imported parents are created with `parent` and
 * `_pta_login_disabled = 1` meta so they cannot sign in until the admin
 * runs the welcome-email tool, which clears the flag and emails a temp
 * password.
 *
 * Parent = any active child in PreK–5. Alumni = every graded active
 * child is past 5th. Roles are swapped on child save/delete and on
 * plugin upgrade so newsletter lists and directory permissions follow
 * the same source of truth.
 *
 * Also: an `authenticate` filter that short-circuits sign-in for
 * disabled accounts. Force-password-change on first login is enforced via
 * `template_redirect`.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Parent_Role {

    const ROLE_SLUG          = 'parent';
    const ROLE_ALUMNI        = 'alumni';
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

        add_action('azure_user_children_changed', array(__CLASS__, 'sync_family_for_user'), 10, 1);
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
        if (!get_role(self::ROLE_ALUMNI)) {
            self::register_alumni_role();
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
     * Same capability set as Parent. Alumni keep My Account / shop access
     * but drop Parent-only surfaces that check the `parent` role
     * (directory, Parents newsletter list).
     */
    public static function register_alumni_role() {
        if (!get_role(self::ROLE_SLUG)) {
            self::register_role();
        }
        $parent = get_role(self::ROLE_SLUG);
        $caps = ($parent && is_array($parent->capabilities))
            ? $parent->capabilities
            : array('read' => true);

        $existing = get_role(self::ROLE_ALUMNI);
        if (!$existing) {
            add_role(self::ROLE_ALUMNI, __('Alumni', 'azure-plugin'), $caps);
            return;
        }
        foreach ($caps as $cap => $grant) {
            if (empty($existing->capabilities[$cap])) {
                $existing->add_cap($cap, (bool) $grant);
            }
        }
    }

    /**
     * Swap Parent ↔ Alumni while leaving every other role (customer,
     * administrator, …) untouched. Unclassified grades and users who
     * have neither population role are left as-is.
     *
     * @param string[] $current_roles
     * @param string|null $target self::ROLE_SLUG|self::ROLE_ALUMNI|null
     * @return string[]
     */
    public static function next_population_roles($current_roles, $target) {
        $current_roles = array_values((array) $current_roles);
        if ($target !== self::ROLE_SLUG && $target !== self::ROLE_ALUMNI) {
            return $current_roles;
        }
        $has_population = in_array(self::ROLE_SLUG, $current_roles, true)
            || in_array(self::ROLE_ALUMNI, $current_roles, true);
        if (!$has_population) {
            return $current_roles;
        }
        $kept = array();
        foreach ($current_roles as $role) {
            if ($role !== self::ROLE_SLUG && $role !== self::ROLE_ALUMNI) {
                $kept[] = $role;
            }
        }
        $kept[] = $target;
        return $kept;
    }

    /**
     * Recompute Parent vs Alumni for one user from their family's
     * active children. No-op when grades are unclassified or the
     * account is not already in the parent/alumni population.
     *
     * @param int $user_id
     * @return string|null Classification after the attempt.
     */
    public static function sync_user($user_id) {
        $user_id = (int) $user_id;
        if (!$user_id || !function_exists('get_userdata')) {
            return null;
        }
        $user = get_userdata($user_id);
        if (!$user) {
            return null;
        }
        $target = null;
        if (class_exists('Azure_User_Children')) {
            $target = Azure_User_Children::classify_user_population($user_id);
        }
        $current = array_values((array) $user->roles);
        $next = self::next_population_roles($current, $target);
        $cur_sorted = $current;
        $next_sorted = $next;
        sort($cur_sorted);
        sort($next_sorted);
        if ($cur_sorted === $next_sorted) {
            return $target;
        }
        foreach (array(self::ROLE_SLUG, self::ROLE_ALUMNI) as $role) {
            if (in_array($role, $current, true) && !in_array($role, $next, true)) {
                $user->remove_role($role);
            }
        }
        foreach ($next as $role) {
            if (!in_array($role, $current, true)) {
                $user->add_role($role);
            }
        }
        return $target;
    }

    /**
     * Sync the user and their connected co-parent.
     *
     * @param int $user_id
     */
    public static function sync_family_for_user($user_id) {
        $user_id = (int) $user_id;
        if (!$user_id) {
            return;
        }
        if (class_exists('Azure_User_Children')) {
            Azure_User_Children::clear_children_cache();
        }
        $ids = array($user_id);
        if (class_exists('Azure_User_Children')) {
            $family = Azure_User_Children::get_family_for_user($user_id);
            if ($family) {
                if (!empty($family->primary_user_id)) {
                    $ids[] = (int) $family->primary_user_id;
                }
                if (!empty($family->secondary_user_id)) {
                    $ids[] = (int) $family->secondary_user_id;
                }
            }
        }
        foreach (array_unique(array_filter($ids)) as $id) {
            self::sync_user($id);
        }
    }

    /**
     * Recompute Parent vs Alumni for every user who already has one of
     * those roles. Called from the plugin version-bump path.
     *
     * @return array{scanned:int,parent:int,alumni:int,skipped:int}
     */
    public static function sync_all() {
        $counts = array(
            'scanned' => 0,
            'parent'  => 0,
            'alumni'  => 0,
            'skipped' => 0,
        );
        if (!function_exists('get_users')) {
            return $counts;
        }
        $users = get_users(array(
            'role__in' => array(self::ROLE_SLUG, self::ROLE_ALUMNI),
            'fields'   => 'ID',
            'number'   => -1,
        ));
        foreach ($users as $id) {
            $counts['scanned']++;
            $target = self::sync_user((int) $id);
            if ($target === self::ROLE_SLUG) {
                $counts['parent']++;
            } elseif ($target === self::ROLE_ALUMNI) {
                $counts['alumni']++;
            } else {
                $counts['skipped']++;
            }
        }
        return $counts;
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
