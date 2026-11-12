<?php
/**
 * AI Connectors tool access by role.
 *
 * Every tool belongs to an access group (pages, reports, products, Stripe,
 * ...). A site administrator decides which roles get which groups on the
 * Settings tab; someone with several roles gets every group any of them
 * has. Administrators always get every group. WordPress capabilities are
 * still checked on top, so the grid can only narrow what a role can do,
 * except where a tool is deliberately opened to staff (reading saved
 * reports needs only edit_posts).
 *
 * Tools registered without an access group are open to anyone who can
 * connect, unless they are destructive or admin tools, which fall into
 * the admin group.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AI_Connectors_Access {

    const ADMIN_ROLE = 'administrator';

    /** Group used for unlabelled destructive and admin tools. */
    const ADMIN_GROUP = 'admin';

    /**
     * Groups a role gets until an administrator saves the grid. Roles not
     * listed get nothing.
     */
    const DEFAULTS = array(
        'azuread'      => array('content', 'content_create', 'reports'),
        'shop_manager' => array('reports', 'reports_edit', 'products', 'catalog', 'orders', 'coupons'),
        'finance'      => array('reports', 'reports_edit', 'products', 'catalog', 'orders', 'coupons', 'stripe'),
        'editor'       => array('content', 'content_create', 'content_trash'),
        'author'       => array('content', 'content_create'),
        'contributor'  => array('content', 'content_create'),
    );

    /** @return array<string,string> group => label, in display order */
    public static function groups() {
        return array(
            'content'        => __('Pages and posts: read, edit, publish and unpublish', 'azure-plugin'),
            'content_create' => __('Pages and posts: create drafts', 'azure-plugin'),
            'content_trash'  => __('Pages and posts: move to the trash', 'azure-plugin'),
            'reports'        => __('Order reports: read and export', 'azure-plugin'),
            'reports_edit'   => __('Order reports: create and edit', 'azure-plugin'),
            'products'       => __('Products: create drafts, edit, publish, variations', 'azure-plugin'),
            'catalog'        => __('Store setup: categories, attributes, product fields', 'azure-plugin'),
            'orders'         => __('Orders: read', 'azure-plugin'),
            'coupons'        => __('Coupons: read and create', 'azure-plugin'),
            'stripe'         => __('Stripe: balance, payouts, fees, disputes (read only)', 'azure-plugin'),
            'calendar_sync'  => __('Calendar: sync from Outlook now', 'azure-plugin'),
            self::ADMIN_GROUP => __('Site admin', 'azure-plugin'),
        );
    }

    /** Groups the grid offers; the admin group stays with administrators. */
    public static function grantable_groups() {
        $groups = self::groups();
        unset($groups[self::ADMIN_GROUP]);
        return $groups;
    }

    public static function group_label($group) {
        $groups = self::groups();
        return $groups[$group] ?? (string) $group;
    }

    /**
     * Saved choices over the defaults: role => groups.
     *
     * @return array<string,string[]>
     */
    public static function grid() {
        $grid = self::DEFAULTS;
        foreach ((array) Azure_AI_Connectors_Settings::value('role_access') as $role => $groups) {
            $grid[(string) $role] = (array) $groups;
        }
        unset($grid[self::ADMIN_ROLE]);
        return $grid;
    }

    /**
     * Roles shown in the grid: every role that can edit posts or run the
     * store, plus any role with defaults, but not administrator.
     *
     * @return array<string,string> slug => display name
     */
    public static function grid_roles() {
        $out = array();
        $names = function_exists('wp_roles') ? wp_roles()->get_names() : array();
        foreach ($names as $slug => $name) {
            $role = function_exists('get_role') ? get_role($slug) : null;
            $staff = $role && ($role->has_cap('edit_posts') || $role->has_cap('manage_woocommerce'));
            if ($slug !== self::ADMIN_ROLE && ($staff || isset(self::DEFAULTS[$slug]))) {
                $out[$slug] = $name;
            }
        }
        return $out;
    }

    /**
     * @param mixed $input role => groups
     * @return array<string,string[]>
     */
    public static function sanitize_grid($input) {
        $known = array_keys(self::grantable_groups());
        $out = array();
        foreach ((array) $input as $role => $groups) {
            $role = sanitize_key((string) $role);
            if ($role === '' || $role === self::ADMIN_ROLE) {
                continue;
            }
            $out[$role] = array_values(array_intersect($known, array_map('strval', (array) $groups)));
        }
        return $out;
    }

    /**
     * @param WP_User|int|null $user
     */
    public static function is_admin_user($user = null) {
        $user = self::resolve_user($user);
        if (!$user || empty($user->ID)) {
            return false;
        }
        if (in_array(self::ADMIN_ROLE, (array) $user->roles, true)) {
            return true;
        }
        return function_exists('is_multisite') && is_multisite() && function_exists('is_super_admin') && is_super_admin($user->ID);
    }

    /**
     * Groups this account gets: the union over its roles.
     *
     * @param WP_User|int|null $user
     * @return string[]
     */
    public static function groups_for($user = null) {
        $user = self::resolve_user($user);
        if (!$user || empty($user->ID)) {
            return array();
        }
        $all = array_keys(self::groups());
        if (self::is_admin_user($user)) {
            return $all;
        }
        $grid = self::grid();
        $have = array();
        foreach ((array) $user->roles as $role) {
            $have = array_merge($have, $grid[(string) $role] ?? array());
        }
        $groups = array_values(array_intersect($all, $have));
        return (array) apply_filters('pta_ai_connectors_access_groups', $groups, $user);
    }

    /** Access group of a registered tool; '' means open to anyone who can connect. */
    public static function tool_group(array $tool) {
        return (string) ($tool['access'] ?? '');
    }

    /**
     * @param WP_User|int|null $user
     */
    public static function allows($user, array $tool) {
        $group = self::tool_group($tool);
        return $group === '' || in_array($group, self::groups_for($user), true);
    }

    private static function resolve_user($user) {
        if ($user === null) {
            return wp_get_current_user();
        }
        if (is_object($user)) {
            return $user;
        }
        return get_userdata((int) $user);
    }
}
