<?php
/**
 * AI Connectors: settings and the permission policy every tool call goes
 * through.
 *
 * A tool is usable when all of these hold for the signed-in user:
 *   - the user has the tool's WordPress capability (current_user_can), so
 *     custom roles get exactly what their capabilities allow;
 *   - the connection's scope allows the tool's kind (read-only connections
 *     only see read tools);
 *   - the user is not an Entra-restricted account, or the tool's kind is one
 *     Entra accounts may use (read and update; create only if an admin
 *     allows it). Destructive and admin tools are never available to them,
 *     whatever their role grants;
 *   - for page and post tools (group "content"), the user is not on a
 *     content-limited role (default: shop_manager), or the tool's kind is
 *     read or update. WooCommerce gives shop managers editor capabilities,
 *     so without this rule they could create and trash pages.
 *
 * Administrators are exempt from both role rules while entra_exempt_admins
 * is on (the default).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AI_Connectors_Settings {

    const OPTION = 'azure_ai_connectors_settings';

    /** @var array|null */
    private static $cache = null;

    public static function defaults() {
        return array(
            'access_token_ttl'          => HOUR_IN_SECONDS,
            'refresh_token_ttl'         => 30 * DAY_IN_SECONDS,
            'rate_limit_per_minute'     => 60,
            'audit_retention_days'      => 90,
            'allow_dynamic_registration' => true,
            'allowed_origins'           => array('https://claude.ai', 'https://chatgpt.com', 'https://chat.openai.com'),
            'pat_max_days'              => 365,
            'entra_restricted_roles'    => array('azuread'),
            'entra_restrict_sso_linked' => false,
            'entra_exempt_admins'       => true,
            'entra_allow_create'        => false,
            'content_limited_roles'     => array('shop_manager'),
        );
    }

    public static function get() {
        if (self::$cache === null) {
            $saved = get_option(self::OPTION, array());
            self::$cache = array_merge(self::defaults(), is_array($saved) ? $saved : array());
        }
        return self::$cache;
    }

    public static function value($key) {
        $all = self::get();
        return array_key_exists($key, $all) ? $all[$key] : null;
    }

    public static function save(array $input) {
        $clean = self::sanitize($input);
        update_option(self::OPTION, $clean, false);
        self::$cache = null;
        return $clean;
    }

    public static function reset_cache() {
        self::$cache = null;
    }

    public static function sanitize(array $input) {
        $d = self::defaults();
        $int = function ($key, $min, $max) use ($input, $d) {
            $v = isset($input[$key]) ? (int) $input[$key] : (int) $d[$key];
            return max($min, min($max, $v));
        };

        $roles = isset($input['entra_restricted_roles']) ? (array) $input['entra_restricted_roles'] : $d['entra_restricted_roles'];
        $roles = array_values(array_unique(array_filter(array_map('sanitize_key', $roles))));
        $content_roles = isset($input['content_limited_roles']) ? (array) $input['content_limited_roles'] : $d['content_limited_roles'];
        $content_roles = array_values(array_unique(array_filter(array_map('sanitize_key', $content_roles))));

        $origins = isset($input['allowed_origins']) ? $input['allowed_origins'] : array();
        if (is_string($origins)) {
            $origins = preg_split('/[\s,]+/', $origins);
        }
        $clean_origins = array();
        foreach ((array) $origins as $origin) {
            $origin = self::normalize_origin((string) $origin);
            if ($origin !== '') {
                $clean_origins[] = $origin;
            }
        }

        return array(
            'access_token_ttl'           => $int('access_token_ttl', 300, DAY_IN_SECONDS),
            'refresh_token_ttl'          => $int('refresh_token_ttl', HOUR_IN_SECONDS, 365 * DAY_IN_SECONDS),
            'rate_limit_per_minute'      => $int('rate_limit_per_minute', 1, 1000),
            'audit_retention_days'       => $int('audit_retention_days', 1, 3650),
            'allow_dynamic_registration' => !empty($input['allow_dynamic_registration']),
            'allowed_origins'            => array_values(array_unique($clean_origins)),
            'pat_max_days'               => $int('pat_max_days', 0, 3650),
            'entra_restricted_roles'     => $roles,
            'entra_restrict_sso_linked'  => !empty($input['entra_restrict_sso_linked']),
            'entra_exempt_admins'        => !empty($input['entra_exempt_admins']),
            'entra_allow_create'         => !empty($input['entra_allow_create']),
            'content_limited_roles'      => $content_roles,
        );
    }

    /**
     * scheme://host[:port], lowercased, or '' when the value is not an
     * http(s) origin.
     */
    public static function normalize_origin($origin) {
        $origin = trim($origin);
        if ($origin === '') {
            return '';
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return '';
        }
        $out = $scheme . '://' . strtolower($parts['host']);
        if (!empty($parts['port'])) {
            $out .= ':' . (int) $parts['port'];
        }
        return $out;
    }
}

class Azure_AI_Connectors_Policy {

    const KIND_READ        = 'read';
    const KIND_CREATE      = 'create';
    const KIND_UPDATE      = 'update';
    const KIND_DESTRUCTIVE = 'destructive';
    const KIND_ADMIN       = 'admin';

    /** Registry group of the page and post tools the content rule applies to. */
    const GROUP_CONTENT = 'content';

    const SCOPE_READ  = 'pta:read';
    const SCOPE_WRITE = 'pta:write';

    /** @var array<int,bool> user id => linked to an Entra sign-in */
    private static $sso_linked = array();

    public static function kinds() {
        return array(self::KIND_READ, self::KIND_CREATE, self::KIND_UPDATE, self::KIND_DESTRUCTIVE, self::KIND_ADMIN);
    }

    public static function scopes() {
        return array(self::SCOPE_READ, self::SCOPE_WRITE);
    }

    /**
     * Keep only scopes this server knows; an empty or unknown request gets
     * both, so the consent screen decides.
     *
     * @param string|array $requested
     * @return string[]
     */
    public static function normalize_scopes($requested) {
        $list = is_array($requested) ? $requested : preg_split('/\s+/', trim((string) $requested));
        $out = array_values(array_intersect(self::scopes(), array_map('strval', (array) $list)));
        if (empty($out)) {
            return self::scopes();
        }
        if (in_array(self::SCOPE_WRITE, $out, true) && !in_array(self::SCOPE_READ, $out, true)) {
            array_unshift($out, self::SCOPE_READ);
        }
        return $out;
    }

    public static function scope_allows($kind, array $scopes) {
        if ($kind === self::KIND_READ) {
            return in_array(self::SCOPE_READ, $scopes, true) || in_array(self::SCOPE_WRITE, $scopes, true);
        }
        return in_array(self::SCOPE_WRITE, $scopes, true);
    }

    /**
     * Whether the account is treated as an Entra (Azure AD) account.
     *
     * Entra sign-in provisions accounts on the Azure AD User role (`azuread`),
     * which carries editor capabilities plus manage_options. A capability check
     * alone would therefore hand those accounts delete and admin tools, so this
     * rule sits on top of capabilities.
     *
     * @param WP_User|int|null $user
     */
    public static function is_entra_restricted($user = null) {
        $user = self::resolve_user($user);
        if (!$user || empty($user->ID)) {
            return false;
        }
        $settings = Azure_AI_Connectors_Settings::get();
        $roles = array_map('strval', (array) $user->roles);

        $restricted = false;
        if (!empty($settings['entra_exempt_admins']) && in_array('administrator', $roles, true)) {
            $restricted = false;
        } elseif (array_intersect($roles, (array) $settings['entra_restricted_roles'])) {
            $restricted = true;
        } elseif (!empty($settings['entra_restrict_sso_linked']) && self::is_sso_linked((int) $user->ID)) {
            $restricted = true;
        }

        return (bool) apply_filters('pta_ai_connectors_is_entra_restricted', $restricted, $user);
    }

    /**
     * Kinds an Entra-restricted account may use. Destructive and admin are
     * never in this list.
     */
    public static function entra_allowed_kinds() {
        $kinds = array(self::KIND_READ, self::KIND_UPDATE);
        if (Azure_AI_Connectors_Settings::value('entra_allow_create')) {
            $kinds[] = self::KIND_CREATE;
        }
        return $kinds;
    }

    /**
     * Whether page and post tools are limited to read and update for this
     * account. WooCommerce's shop_manager role carries editor capabilities
     * (edit_posts, delete_posts, edit_pages, ...), but shop managers should
     * only read and update pages and posts through AI connections.
     *
     * @param WP_User|int|null $user
     */
    public static function is_content_limited($user = null) {
        $user = self::resolve_user($user);
        if (!$user || empty($user->ID)) {
            return false;
        }
        $settings = Azure_AI_Connectors_Settings::get();
        $roles = array_map('strval', (array) $user->roles);

        $limited = false;
        if (!empty($settings['entra_exempt_admins']) && in_array('administrator', $roles, true)) {
            $limited = false;
        } elseif (array_intersect($roles, (array) $settings['content_limited_roles'])) {
            $limited = true;
        }

        return (bool) apply_filters('pta_ai_connectors_is_content_limited', $limited, $user);
    }

    /** Kinds a content-limited account may use on page and post tools. */
    public static function content_limited_kinds() {
        return array(self::KIND_READ, self::KIND_UPDATE);
    }

    /**
     * Who may change AI Connectors settings and see or revoke everyone's
     * connections. manage_options alone is not enough: Entra accounts hold it.
     *
     * @param WP_User|int|null $user
     */
    public static function can_administer($user = null) {
        $user = self::resolve_user($user);
        if (!$user || empty($user->ID)) {
            return false;
        }
        $is_current = (int) $user->ID === (int) get_current_user_id();
        $has_cap = $is_current ? current_user_can('manage_options') : user_can($user, 'manage_options');
        return $has_cap && !self::is_entra_restricted($user);
    }

    /**
     * Can the current user use this tool over a connection with $scopes?
     * Capabilities are checked with current_user_can, so call this after
     * wp_set_current_user().
     *
     * @return true|WP_Error
     */
    public static function check(array $tool, array $scopes) {
        $user = wp_get_current_user();
        if (!$user || empty($user->ID)) {
            return new WP_Error('pta_ai_not_signed_in', 'No signed-in user.');
        }
        if (isset($tool['available']) && is_callable($tool['available']) && !call_user_func($tool['available'])) {
            return new WP_Error('pta_ai_unavailable', 'This tool is not available on this site right now.');
        }
        $kind = (string) ($tool['kind'] ?? self::KIND_READ);
        if (self::is_entra_restricted($user) && !in_array($kind, self::entra_allowed_kinds(), true)) {
            return new WP_Error(
                'pta_ai_entra_restricted',
                'Accounts that sign in with Microsoft Entra can only read and update through AI Connectors. This tool is not available to your account.'
            );
        }
        if (($tool['group'] ?? '') === self::GROUP_CONTENT && self::is_content_limited($user) && !in_array($kind, self::content_limited_kinds(), true)) {
            return new WP_Error(
                'pta_ai_content_limited',
                'Your role can read and update pages and posts through AI Connectors, but not create or trash them.'
            );
        }
        if (!self::has_permission($tool)) {
            return new WP_Error('pta_ai_forbidden', 'Your WordPress account does not have permission to use this tool.');
        }
        if (!self::scope_allows($kind, $scopes)) {
            return new WP_Error('pta_ai_insufficient_scope', 'This connection is read-only. Reconnect and allow changes to use this tool.');
        }
        return true;
    }

    public static function has_permission(array $tool) {
        $caps = isset($tool['permission']) ? (array) $tool['permission'] : array('read');
        $ok = false;
        foreach ($caps as $cap) {
            if (current_user_can((string) $cap)) {
                $ok = true;
                break;
            }
        }
        if ($ok && isset($tool['permission_callback']) && is_callable($tool['permission_callback'])) {
            $ok = (bool) call_user_func($tool['permission_callback']);
        }
        return $ok;
    }

    public static function requires_confirm(array $tool) {
        if (!empty($tool['requires_confirm'])) {
            return true;
        }
        return ($tool['kind'] ?? '') === self::KIND_DESTRUCTIVE;
    }

    public static function confirmed(array $args) {
        return isset($args['confirm']) && $args['confirm'] === true;
    }

    private static function is_sso_linked($user_id) {
        if (isset(self::$sso_linked[$user_id])) {
            return self::$sso_linked[$user_id];
        }
        global $wpdb;
        $linked = false;
        $table = class_exists('Azure_Database') ? Azure_Database::get_table_name('sso_users') : '';
        if ($table && isset($wpdb)) {
            $linked = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE wordpress_user_id = %d LIMIT 1",
                $user_id
            ));
        }
        self::$sso_linked[$user_id] = $linked;
        return $linked;
    }

    public static function reset_cache() {
        self::$sso_linked = array();
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
