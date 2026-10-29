<?php
/**
 * Shared setup for the AI Connectors suites: WordPress users, roles and
 * capabilities, plus an in-memory SQLite copy of the module's tables.
 */

require_once __DIR__ . '/wp-shim.php';
require_once __DIR__ . '/sqlite-wpdb.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}
if (!defined('AZURE_PLUGIN_VERSION')) {
    define('AZURE_PLUGIN_VERSION', 'test');
}

class WP_User {
    public $ID = 0;
    public $roles = array();
    public $allcaps = array();
    public $user_login = '';
    public $display_name = '';
    public $user_email = '';

    public function __construct($id = 0, array $roles = array(), $login = '') {
        $this->ID = (int) $id;
        $this->roles = $roles;
        $this->user_login = $login !== '' ? $login : 'user' . $id;
        $this->display_name = ucfirst($this->user_login);
        $this->user_email = $this->user_login . '@example.test';
        $this->allcaps = AI_Test::caps_for_roles($roles);
    }
}

class AI_Test {
    /** @var array<int,WP_User> */
    public static $users = array();
    public static $current = 0;
    /** @var array<string,int> hook => times fired */
    public static $actions = array();

    public static function roles() {
        $editor = array(
            'read' => true, 'edit_posts' => true, 'edit_others_posts' => true, 'publish_posts' => true,
            'delete_posts' => true, 'delete_others_posts' => true, 'delete_published_posts' => true,
            'edit_pages' => true, 'edit_others_pages' => true, 'publish_pages' => true,
            'delete_pages' => true, 'delete_others_pages' => true, 'read_private_posts' => true,
            'upload_files' => true, 'unfiltered_html' => true,
        );
        $shop = array(
            'read' => true, 'manage_woocommerce' => true, 'view_woocommerce_reports' => true,
            'edit_products' => true, 'publish_products' => true, 'delete_products' => true,
            'edit_shop_orders' => true, 'edit_shop_coupons' => true, 'publish_shop_coupons' => true,
        );
        return array(
            'administrator' => array_merge($editor, $shop, array('manage_options' => true, 'list_users' => true, 'access_pta_tools' => true)),
            'shop_manager'  => $shop,
            // Mirrors AzurePlugin::get_azuread_capabilities(): editor + PTA access + manage_options.
            'azuread'       => array_merge($editor, array('access_pta_tools' => true, 'manage_options' => true)),
            'newsletter_editor' => array('read' => true, 'edit_posts' => true),
            'subscriber'    => array('read' => true),
        );
    }

    public static function caps_for_roles(array $roles) {
        $all = self::roles();
        $caps = array();
        foreach ($roles as $role) {
            $caps = array_merge($caps, $all[$role] ?? array());
        }
        return $caps;
    }

    public static function add_user($id, array $roles, $login = '') {
        self::$users[$id] = new WP_User($id, $roles, $login);
        return self::$users[$id];
    }

    public static function login($id) {
        self::$current = (int) $id;
    }

    /** In-memory copy of the three AI Connectors tables plus sso_users. */
    public static function fresh_db() {
        $db = new Sqlite_WPDB();
        $db->exec("CREATE TABLE wp_azure_ai_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT NOT NULL UNIQUE, token_type TEXT NOT NULL,
            token_hint TEXT NOT NULL DEFAULT '', user_id INTEGER NOT NULL DEFAULT 0, client_id TEXT NOT NULL DEFAULT '',
            grant_id TEXT NOT NULL DEFAULT '', name TEXT NOT NULL DEFAULT '', scope TEXT NOT NULL DEFAULT '',
            resource TEXT NOT NULL DEFAULT '', redirect_uri TEXT, code_challenge TEXT NOT NULL DEFAULT '',
            created_at TEXT, expires_at TEXT, last_used_at TEXT, revoked_at TEXT)");
        $db->exec("CREATE TABLE wp_azure_ai_clients (
            id INTEGER PRIMARY KEY AUTOINCREMENT, client_id TEXT NOT NULL UNIQUE, client_secret_hash TEXT NOT NULL DEFAULT '',
            client_name TEXT NOT NULL DEFAULT '', redirect_uris TEXT, grant_types TEXT NOT NULL DEFAULT '',
            token_endpoint_auth_method TEXT NOT NULL DEFAULT 'none', client_uri TEXT NOT NULL DEFAULT '',
            software_id TEXT NOT NULL DEFAULT '', created_at TEXT, last_used_at TEXT)");
        $db->exec("CREATE TABLE wp_azure_ai_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT, user_id INTEGER NOT NULL DEFAULT 0,
            token_id INTEGER NOT NULL DEFAULT 0, client_label TEXT NOT NULL DEFAULT '', tool TEXT NOT NULL DEFAULT '',
            args_summary TEXT, status TEXT NOT NULL DEFAULT '', message TEXT NOT NULL DEFAULT '',
            duration_ms INTEGER NOT NULL DEFAULT 0, ip_hash TEXT NOT NULL DEFAULT '')");
        $db->exec("CREATE TABLE wp_azure_sso_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT, wordpress_user_id INTEGER NOT NULL, azure_user_id TEXT NOT NULL,
            azure_email TEXT NOT NULL DEFAULT '', azure_display_name TEXT, last_login TEXT, created_at TEXT, updated_at TEXT)");
        $GLOBALS['wpdb'] = $db;
        return $db;
    }

    public static function reset() {
        WP_Shim::reset();
        self::$users = array();
        self::$current = 0;
        self::$actions = array();
        Azure_AI_Connectors_Settings::reset_cache();
        Azure_AI_Connectors_Policy::reset_cache();
        Azure_AI_Connectors_Registry::reset();
        self::fresh_db();
    }
}

// ─── WordPress shims used by the module ──────────────────────────────

function wp_get_current_user() {
    return AI_Test::$users[AI_Test::$current] ?? new WP_User(0);
}
function get_current_user_id() {
    return AI_Test::$current;
}
function wp_set_current_user($id) {
    AI_Test::$current = (int) $id;
    return wp_get_current_user();
}
function get_userdata($id) {
    return AI_Test::$users[(int) $id] ?? false;
}
function user_can($user, $cap) {
    $user = is_object($user) ? $user : get_userdata($user);
    return $user && !empty($user->allcaps[$cap]);
}
function current_user_can($cap, ...$args) {
    return user_can(wp_get_current_user(), $cap);
}
function do_action($hook, ...$args) {
    AI_Test::$actions[$hook] = (AI_Test::$actions[$hook] ?? 0) + 1;
}
function apply_filters($hook, $value, ...$args) {
    return $value;
}
function sanitize_text_field($s) {
    return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $s)));
}
function sanitize_key($s) {
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s));
}
function wp_strip_all_tags($s) {
    return trim(strip_tags((string) $s));
}
function esc_url_raw($url, $protocols = null) {
    return (string) $url;
}
function wp_specialchars_decode($s, $q = 0) {
    return htmlspecialchars_decode((string) $s, ENT_QUOTES);
}
function trailingslashit($s) {
    return rtrim((string) $s, '/\\') . '/';
}
function rest_url($path = '') {
    return 'https://example.test/wp-json/' . ltrim((string) $path, '/');
}
function is_ssl() {
    return true;
}
function wp_salt($scheme = 'auth') {
    return 'test-salt';
}
