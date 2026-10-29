<?php
/**
 * AI Connectors: let a signed-in user connect an AI assistant (Claude,
 * ChatGPT, Gemini, Copilot, Grok or any MCP client) to this site.
 *
 *   /wp-json/pta-tools/v1/mcp          MCP server (Streamable HTTP, JSON-RPC)
 *   /wp-json/pta-tools/v1/oauth/*      OAuth 2.1 authorization server
 *   /.well-known/oauth-*               discovery documents at the site root
 *   Users > AI Connectors              personal setup page, tokens, skill file
 *   PTA Tools > AI Connectors          admin: settings, all connections, audit
 *
 * Every request authenticates with a bearer token (OAuth access token or
 * personal access token), runs as that token's WordPress user, and every
 * tool checks that user's capabilities when it is called.
 *
 * The constructor only adds hooks; the classes it needs are loaded on the
 * requests that use them.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A REST response body that is written as-is instead of JSON-encoded:
 * the empty 202 for MCP notifications, and file downloads.
 */
class Azure_AI_Connectors_Raw_Body {
    public $body = '';
    public $content_type = '';
    public $file = '';
    public $filename = '';
    /** Delete $file once it has been sent. */
    public $delete_after = false;

    public function __construct($body = '', $content_type = '') {
        $this->body = (string) $body;
        $this->content_type = (string) $content_type;
    }
}

class Azure_AI_Connectors_Module {

    const REST_NS      = 'pta-tools/v1';
    const PURGE_HOOK   = 'azure_ai_connectors_purge';
    const USER_PAGE    = 'pta-ai-connectors';
    const ADMIN_PAGE   = 'azure-plugin-ai-connectors';
    const DOWNLOAD_TTL = 900;

    private static $instance = null;

    /** @var array<string,string> client_id => display name, per request */
    private static $client_names = array();

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('rest_pre_serve_request', array($this, 'serve'), 20, 4);
        add_action('parse_request', array($this, 'maybe_serve_well_known'), 0);
        add_action(self::PURGE_HOOK, array(__CLASS__, 'purge'));

        add_action('admin_post_pta_ai_authorize', array($this, 'handle_authorize'));
        add_action('admin_post_nopriv_pta_ai_authorize', array($this, 'handle_authorize'));
        add_action('admin_post_pta_ai_authorize_decision', array($this, 'handle_decision'));
        add_action('admin_post_nopriv_pta_ai_authorize_decision', array($this, 'handle_decision'));

        add_action('deleted_user', array(__CLASS__, 'revoke_user'));
        add_action('after_password_reset', array(__CLASS__, 'revoke_user'));

        if (is_admin()) {
            self::load();
            require_once __DIR__ . '/class-ai-connectors-admin.php';
            Azure_AI_Connectors_Admin::get_instance();
        }
    }

    public static function load() {
        foreach (array('policy', 'registry', 'tokens', 'audit', 'mcp', 'oauth', 'tools', 'skill') as $part) {
            require_once __DIR__ . '/class-ai-connectors-' . $part . '.php';
        }
    }

    // ─── URLs ────────────────────────────────────────────────────────

    /** REST base with a trailing slash, e.g. https://example.org/wp-json/pta-tools/v1/ */
    public static function rest_base() {
        return trailingslashit(rest_url(self::REST_NS));
    }

    public static function mcp_url() {
        return self::rest_base() . 'mcp';
    }

    public static function issuer() {
        return self::rest_base() . 'oauth';
    }

    public static function resource_metadata_url() {
        return self::rest_base() . 'oauth/protected-resource';
    }

    /** Users > AI Connectors for the current user (it sits under Profile for users who cannot list users). */
    public static function user_page_url() {
        $parent = current_user_can('list_users') ? 'users.php' : 'profile.php';
        return admin_url($parent . '?page=' . self::USER_PAGE);
    }

    public static function admin_page_url($tab = '') {
        return admin_url('admin.php?page=' . self::ADMIN_PAGE . ($tab !== '' ? '&tab=' . rawurlencode($tab) : ''));
    }

    // ─── Request helpers ─────────────────────────────────────────────

    /**
     * Tokens are only accepted or issued over HTTPS. Local development can
     * opt out with define('PTA_AI_CONNECTORS_ALLOW_HTTP', true).
     */
    public static function is_secure_request() {
        if (function_exists('is_ssl') && is_ssl()) {
            return true;
        }
        if (defined('PTA_AI_CONNECTORS_ALLOW_HTTP') && PTA_AI_CONNECTORS_ALLOW_HTTP) {
            return true;
        }
        return function_exists('wp_get_environment_type') && wp_get_environment_type() === 'local';
    }

    public static function authorization_header(WP_REST_Request $request) {
        $header = (string) $request->get_header('authorization');
        if ($header === '' && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if ($header === '' && function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $k => $v) {
                if (strcasecmp((string) $k, 'authorization') === 0) {
                    $header = (string) $v;
                    break;
                }
            }
        }
        return trim($header);
    }

    public static function bearer_token(WP_REST_Request $request) {
        $header = self::authorization_header($request);
        if (stripos($header, 'Bearer ') !== 0) {
            return '';
        }
        return trim(substr($header, 7));
    }

    public static function client_ip_hash() {
        $ip = '';
        foreach (array('HTTP_X_AZURE_CLIENTIP', 'REMOTE_ADDR') as $key) {
            if (!empty($_SERVER[$key]) && filter_var($_SERVER[$key], FILTER_VALIDATE_IP)) {
                $ip = (string) $_SERVER[$key];
                break;
            }
        }
        return substr(hash('sha256', $ip), 0, 24);
    }

    /**
     * Fixed-window counter. Returns false once $limit is exceeded.
     */
    public static function rate_limit($bucket, $limit, $window) {
        $window = max(1, (int) $window);
        $key = 'pta_ai_rl_' . md5($bucket . '|' . (int) floor(time() / $window));
        $count = (int) get_transient($key);
        if ($count >= (int) $limit) {
            return false;
        }
        set_transient($key, $count + 1, $window);
        return true;
    }

    public static function site_origin() {
        return Azure_AI_Connectors_Settings::normalize_origin(home_url('/'));
    }

    public static function origin_allowed($origin) {
        $origin = Azure_AI_Connectors_Settings::normalize_origin((string) $origin);
        if ($origin === '') {
            return false;
        }
        if ($origin === self::site_origin()) {
            return true;
        }
        return in_array($origin, (array) Azure_AI_Connectors_Settings::value('allowed_origins'), true);
    }

    // ─── REST routes ─────────────────────────────────────────────────

    public function register_routes() {
        self::load();
        $ns = self::REST_NS;
        $open = '__return_true';

        register_rest_route($ns, '/mcp', array(
            'methods'             => array('POST', 'GET', 'DELETE'),
            'callback'            => array($this, 'rest_mcp'),
            'permission_callback' => $open,
        ));
        register_rest_route($ns, '/oauth/protected-resource', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_protected_resource'),
            'permission_callback' => $open,
        ));
        foreach (array('/oauth/metadata', '/oauth/\.well-known/oauth-authorization-server', '/oauth/\.well-known/openid-configuration') as $route) {
            register_rest_route($ns, $route, array(
                'methods'             => 'GET',
                'callback'            => array($this, 'rest_authorization_server'),
                'permission_callback' => $open,
            ));
        }
        register_rest_route($ns, '/oauth/register', array(
            'methods'             => 'POST',
            'callback'            => array('Azure_AI_Connectors_OAuth', 'rest_register'),
            'permission_callback' => $open,
        ));
        register_rest_route($ns, '/oauth/authorize', array(
            'methods'             => 'GET',
            'callback'            => array('Azure_AI_Connectors_OAuth', 'rest_authorize'),
            'permission_callback' => $open,
        ));
        register_rest_route($ns, '/oauth/token', array(
            'methods'             => 'POST',
            'callback'            => array('Azure_AI_Connectors_OAuth', 'rest_token'),
            'permission_callback' => $open,
        ));
        register_rest_route($ns, '/oauth/revoke', array(
            'methods'             => 'POST',
            'callback'            => array('Azure_AI_Connectors_OAuth', 'rest_revoke'),
            'permission_callback' => $open,
        ));
        register_rest_route($ns, '/download/(?P<token>[A-Za-z0-9_\-]{32,64})', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_download'),
            'permission_callback' => $open,
        ));
    }

    public function rest_protected_resource() {
        return $this->public_json(Azure_AI_Connectors_OAuth::protected_resource_metadata());
    }

    public function rest_authorization_server() {
        return $this->public_json(Azure_AI_Connectors_OAuth::authorization_server_metadata());
    }

    private function public_json(array $doc) {
        $response = new WP_REST_Response($doc, 200);
        $response->header('Cache-Control', 'public, max-age=3600');
        return $response;
    }

    private function unauthorized($error, $description) {
        $challenge = 'Bearer resource_metadata="' . self::resource_metadata_url() . '", scope="' . implode(' ', Azure_AI_Connectors_Policy::scopes()) . '"';
        if ($error !== '') {
            $challenge .= ', error="' . $error . '", error_description="' . str_replace('"', "'", $description) . '"';
        }
        $response = new WP_REST_Response(array('error' => $error !== '' ? $error : 'unauthorized', 'error_description' => $description), 401);
        $response->header('WWW-Authenticate', $challenge);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    private function http_error($status, $error, $description) {
        $response = new WP_REST_Response(array('error' => $error, 'error_description' => $description), $status);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    public function rest_mcp(WP_REST_Request $request) {
        if ($request->get_method() !== 'POST') {
            $response = new WP_REST_Response(new Azure_AI_Connectors_Raw_Body(), 405);
            $response->header('Allow', 'POST');
            return $response;
        }
        if (!self::is_secure_request()) {
            return $this->http_error(403, 'https_required', 'Connect over HTTPS.');
        }
        $origin = (string) $request->get_header('origin');
        if ($origin !== '' && !self::origin_allowed($origin)) {
            return $this->http_error(403, 'origin_not_allowed', 'This browser origin is not allowed. An admin can add it under PTA Tools > AI Connectors > Settings.');
        }

        $bearer = self::bearer_token($request);
        if ($bearer === '') {
            return $this->unauthorized('', 'Sign in to connect.');
        }
        $token = Azure_AI_Connectors_Tokens::authenticate($bearer);
        if (is_wp_error($token)) {
            return $this->unauthorized('invalid_token', $token->get_error_message());
        }
        if ((string) $token->resource !== '' && !Azure_AI_Connectors_Tokens::same_resource($token->resource, self::mcp_url())) {
            return $this->unauthorized('invalid_token', 'This token was issued for a different server.');
        }

        $limit = (int) Azure_AI_Connectors_Settings::value('rate_limit_per_minute');
        if (!self::rate_limit('mcp_' . $token->token_hash, $limit, MINUTE_IN_SECONDS)) {
            $response = $this->http_error(429, 'rate_limited', sprintf('More than %d requests a minute on this connection. Wait a minute and try again.', $limit));
            $response->header('Retry-After', '60');
            return $response;
        }

        $version = (string) $request->get_header('mcp_protocol_version');
        if ($version !== '' && !in_array($version, Azure_AI_Connectors_MCP::PROTOCOL_VERSIONS, true)) {
            return $this->http_error(400, 'unsupported_protocol_version', 'Supported MCP versions: ' . implode(', ', Azure_AI_Connectors_MCP::PROTOCOL_VERSIONS));
        }

        wp_set_current_user((int) $token->user_id);
        $user = wp_get_current_user();

        $payload = json_decode((string) $request->get_body(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_REST_Response(Azure_AI_Connectors_MCP::error(null, Azure_AI_Connectors_MCP::PARSE_ERROR, 'Parse error.'), 400);
        }

        $context = array(
            'user'         => $user,
            'token'        => $token,
            'scopes'       => array_values(array_filter(explode(' ', (string) $token->scope))),
            'client_label' => self::client_label($token),
        );
        $result = Azure_AI_Connectors_MCP::handle_payload($payload, $context);
        if ($result === null) {
            return new WP_REST_Response(new Azure_AI_Connectors_Raw_Body(), 202);
        }
        $response = new WP_REST_Response($result, 200);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    public static function client_label($token) {
        if ($token->token_type === Azure_AI_Connectors_Tokens::TYPE_PAT) {
            return 'Personal token: ' . $token->name;
        }
        $id = (string) $token->client_id;
        if (!isset(self::$client_names[$id])) {
            $client = Azure_AI_Connectors_Tokens::get_client($id);
            self::$client_names[$id] = $client ? (string) $client->client_name : $id;
        }
        return self::$client_names[$id];
    }

    /**
     * Replace core's permissive REST CORS headers on this namespace, and
     * write raw bodies.
     */
    public function serve($served, $result, $request, $server) {
        if (!($request instanceof WP_REST_Request) || strpos($request->get_route(), '/' . self::REST_NS . '/') !== 0) {
            return $served;
        }
        $this->send_cors($request->get_route(), (string) $request->get_header('origin'));

        $data = $result instanceof WP_REST_Response ? $result->get_data() : null;
        if (!($data instanceof Azure_AI_Connectors_Raw_Body)) {
            return $served;
        }
        if ($data->file !== '') {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: ' . ($data->content_type !== '' ? $data->content_type : 'application/octet-stream'));
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $data->filename) . '"');
            header('Content-Length: ' . (int) filesize($data->file));
            header('X-Content-Type-Options: nosniff');
            readfile($data->file);
            if ($data->delete_after) {
                @unlink($data->file);
            }
            return true;
        }
        if ($data->body === '') {
            header_remove('Content-Type');
            return true;
        }
        header('Content-Type: ' . ($data->content_type !== '' ? $data->content_type : 'text/plain; charset=utf-8'));
        echo $data->body;
        return true;
    }

    private function send_cors($route, $origin) {
        if (headers_sent()) {
            return;
        }
        foreach (array('Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials', 'Access-Control-Allow-Methods', 'Access-Control-Allow-Headers', 'Access-Control-Expose-Headers') as $h) {
            header_remove($h);
        }
        $public = (bool) preg_match('#^/' . preg_quote(self::REST_NS, '#') . '/oauth/(metadata|protected-resource|\.well-known/)#', $route);
        if ($public) {
            header('Access-Control-Allow-Origin: *');
        } elseif ($origin !== '' && self::origin_allowed($origin)) {
            header('Access-Control-Allow-Origin: ' . Azure_AI_Connectors_Settings::normalize_origin($origin));
            header('Vary: Origin', false);
        } else {
            return;
        }
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Mcp-Session-Id, MCP-Protocol-Version, Last-Event-ID');
        header('Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id, MCP-Protocol-Version');
        header('Access-Control-Max-Age: 600');
    }

    /**
     * Root-level discovery documents. The MCP spec has clients look for
     * these on the site origin, outside /wp-json/.
     */
    public function maybe_serve_well_known($wp = null) {
        $path = isset($_SERVER['REQUEST_URI']) ? (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
        if ($path === '' || strpos($path, '/.well-known/') === false) {
            return;
        }
        self::load();
        $doc = self::well_known_document($path);
        if ($doc === null) {
            return;
        }
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        header('Access-Control-Allow-Origin: *');
        status_header(200);
        echo wp_json_encode($doc, JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * @return array|null The document for a /.well-known/ request path.
     */
    public static function well_known_document($path) {
        $home_path = rtrim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($home_path !== '' && strpos($path, $home_path . '/.well-known/') === 0) {
            $path = substr($path, strlen($home_path));
        }
        $path = rtrim($path, '/');
        $mcp_path = rtrim((string) parse_url(self::mcp_url(), PHP_URL_PATH), '/');
        $issuer_path = rtrim((string) parse_url(self::issuer(), PHP_URL_PATH), '/');

        if (in_array($path, array('/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource' . $mcp_path), true)) {
            return Azure_AI_Connectors_OAuth::protected_resource_metadata();
        }
        if (in_array($path, array(
            '/.well-known/oauth-authorization-server',
            '/.well-known/oauth-authorization-server' . $issuer_path,
            '/.well-known/openid-configuration' . $issuer_path,
        ), true)) {
            return Azure_AI_Connectors_OAuth::authorization_server_metadata();
        }
        return null;
    }

    public function handle_authorize() {
        self::load();
        Azure_AI_Connectors_OAuth::handle_authorize();
    }

    public function handle_decision() {
        self::load();
        Azure_AI_Connectors_OAuth::handle_decision();
    }

    // ─── Report downloads ────────────────────────────────────────────

    public static function export_dir() {
        return class_exists('Azure_Private_Storage') ? Azure_Private_Storage::dir('ai-exports') : '';
    }

    /** A fresh, unguessable file path for an export. */
    public static function export_path() {
        $dir = self::export_dir();
        if ($dir === '' || !is_dir($dir)) {
            return '';
        }
        self::purge_exports(HOUR_IN_SECONDS);
        return $dir . '/export-' . Azure_AI_Connectors_Tokens::random_string(16) . '.csv';
    }

    /**
     * A single-use link to an export file, valid for DOWNLOAD_TTL seconds.
     *
     * @return array{url:string,expires:int}
     */
    public static function create_download($path, $filename, $user_id) {
        $token = Azure_AI_Connectors_Tokens::create_download($path, $filename, $user_id, self::DOWNLOAD_TTL);
        return array('url' => self::rest_base() . 'download/' . $token, 'expires' => time() + self::DOWNLOAD_TTL);
    }

    /**
     * The first GET claims the link and the file is deleted after it is sent,
     * so a second request gets 404. HEAD requests (link previews) do not
     * claim it.
     */
    public function rest_download(WP_REST_Request $request) {
        $gone = $this->http_error(404, 'not_found', 'This download link has expired or was already used. Ask the assistant to export the report again.');
        $gone->header('Cache-Control', 'private, no-store, max-age=0');
        if ($request->get_method() === 'HEAD') {
            return new WP_REST_Response(new Azure_AI_Connectors_Raw_Body(), 200);
        }
        $row = Azure_AI_Connectors_Tokens::claim_download((string) $request['token']);
        if (is_wp_error($row)) {
            return $gone;
        }
        $dir = realpath(self::export_dir());
        $file = realpath((string) $row->redirect_uri);
        if (!$dir || !$file || strpos($file, $dir . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
            return $gone;
        }
        $body = new Azure_AI_Connectors_Raw_Body('', 'text/csv; charset=utf-8');
        $body->file = $file;
        $body->filename = (string) $row->name;
        $body->delete_after = true;
        $response = new WP_REST_Response($body, 200);
        $response->header('Cache-Control', 'private, no-store, max-age=0');
        return $response;
    }

    private static function purge_exports($older_than) {
        $dir = self::export_dir();
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        foreach ((array) glob($dir . '/export-*.csv') as $file) {
            if (is_file($file) && filemtime($file) < time() - $older_than) {
                @unlink($file);
            }
        }
    }

    // ─── Housekeeping ────────────────────────────────────────────────

    public static function purge() {
        self::load();
        Azure_AI_Connectors_Tokens::purge_expired();
        Azure_AI_Connectors_Audit::purge((int) Azure_AI_Connectors_Settings::value('audit_retention_days'));
        self::purge_exports(HOUR_IN_SECONDS);
    }

    public static function revoke_user($user) {
        self::load();
        $id = is_object($user) ? (int) $user->ID : (int) $user;
        if ($id > 0) {
            Azure_AI_Connectors_Tokens::revoke_all_for_user($id);
        }
    }
}
