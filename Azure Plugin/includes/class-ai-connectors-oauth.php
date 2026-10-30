<?php
/**
 * AI Connectors OAuth 2.1 authorization server.
 *
 *   Discovery    RFC 8414 authorization server metadata, RFC 9728 protected
 *                resource metadata, plus the OpenID-style path the MCP spec
 *                asks clients to try.
 *   Registration RFC 7591 dynamic client registration.
 *   Authorize    Authorization code with PKCE (S256 only). The user signs in
 *                with the normal WordPress login (including Entra SSO) and
 *                approves on a consent screen served from admin-post.php,
 *                which the edge cache never stores.
 *   Token        authorization_code and refresh_token grants. Refresh
 *                tokens rotate.
 *   Revocation   RFC 7009.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AI_Connectors_OAuth {

    const AUTHORIZE_ACTION = 'pta_ai_authorize';
    const DECISION_ACTION  = 'pta_ai_authorize_decision';

    // ─── Discovery ───────────────────────────────────────────────────

    public static function authorization_server_metadata() {
        $base = Azure_AI_Connectors_Module::rest_base();
        $methods = array('none', 'client_secret_post', 'client_secret_basic');
        $meta = array(
            'issuer'                                         => Azure_AI_Connectors_Module::issuer(),
            'authorization_endpoint'                         => $base . 'oauth/authorize',
            'token_endpoint'                                 => $base . 'oauth/token',
            'revocation_endpoint'                            => $base . 'oauth/revoke',
            'response_types_supported'                       => array('code'),
            'response_modes_supported'                       => array('query'),
            'grant_types_supported'                          => array('authorization_code', 'refresh_token'),
            'code_challenge_methods_supported'               => array('S256'),
            'token_endpoint_auth_methods_supported'          => $methods,
            'revocation_endpoint_auth_methods_supported'     => $methods,
            'scopes_supported'                               => Azure_AI_Connectors_Policy::scopes(),
            'authorization_response_iss_parameter_supported' => true,
            'service_documentation'                          => Azure_AI_Connectors_Module::user_page_url(),
        );
        if (Azure_AI_Connectors_Settings::value('allow_dynamic_registration')) {
            $meta['registration_endpoint'] = $base . 'oauth/register';
        }
        return $meta;
    }

    public static function protected_resource_metadata() {
        return array(
            'resource'                 => Azure_AI_Connectors_Module::mcp_url(),
            'authorization_servers'    => array(Azure_AI_Connectors_Module::issuer()),
            'scopes_supported'         => Azure_AI_Connectors_Policy::scopes(),
            'bearer_methods_supported' => array('header'),
            'resource_name'            => trim(wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES) . ' PTA Tools'),
        );
    }

    // ─── Registration ────────────────────────────────────────────────

    public static function rest_register(WP_REST_Request $request) {
        if (!Azure_AI_Connectors_Module::is_secure_request()) {
            return self::oauth_error('invalid_request', 'HTTPS is required.', 400);
        }
        if (!Azure_AI_Connectors_Settings::value('allow_dynamic_registration')) {
            return self::oauth_error('access_denied', 'Dynamic client registration is turned off on this site. Use a personal access token instead.', 403);
        }
        if (!Azure_AI_Connectors_Module::rate_limit('reg_' . Azure_AI_Connectors_Module::client_ip_hash(), 20, HOUR_IN_SECONDS)) {
            return self::oauth_error('slow_down', 'Too many registrations from this address. Try again later.', 429);
        }
        $meta = $request->get_json_params();
        if (!is_array($meta)) {
            $meta = $request->get_body_params();
        }
        $result = Azure_AI_Connectors_Tokens::register_client(is_array($meta) ? $meta : array());
        if (is_wp_error($result)) {
            return self::oauth_error($result->get_error_code(), $result->get_error_message(), 400);
        }
        return self::no_store(new WP_REST_Response($result, 201));
    }

    // ─── Authorize ───────────────────────────────────────────────────

    /**
     * The advertised authorization endpoint. Hands the request to
     * admin-post.php, where the WordPress login cookie is available.
     */
    public static function rest_authorize(WP_REST_Request $request) {
        $params = array();
        foreach ($request->get_query_params() as $k => $v) {
            if (is_string($v)) {
                $params[$k] = $v;
            }
        }
        $params['action'] = self::AUTHORIZE_ACTION;
        $url = admin_url('admin-post.php') . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $response = new WP_REST_Response(null, 302);
        $response->header('Location', $url);
        return self::no_store($response);
    }

    /**
     * Validate an authorization request.
     *
     * @return array|WP_Error On error, data['redirect'] says whether the error
     *                        may be sent back to the client's redirect_uri.
     */
    public static function validate_authorize_request(array $q) {
        $client = Azure_AI_Connectors_Tokens::get_client((string) ($q['client_id'] ?? ''));
        if (!$client) {
            return new WP_Error('invalid_client', 'This app is not registered with the site. Remove the connector and add it again.', array('redirect' => false));
        }
        $redirect_uri = (string) ($q['redirect_uri'] ?? '');
        if ($redirect_uri === '' && count($client->redirect_uris_list) === 1) {
            $redirect_uri = (string) $client->redirect_uris_list[0];
        }
        if (!Azure_AI_Connectors_Tokens::redirect_uri_allowed($client, $redirect_uri)) {
            return new WP_Error('invalid_request', 'The redirect address does not match the app\'s registration.', array('redirect' => false));
        }
        // Anyone can register an app under any name, so codes only go to
        // assistant domains an admin has approved. Never redirect to an
        // unapproved one, not even with an error.
        if (!Azure_AI_Connectors_Settings::redirect_host_approved($redirect_uri)) {
            $host = strtolower((string) parse_url($redirect_uri, PHP_URL_HOST));
            return new WP_Error('unapproved_client', sprintf('%s is not an approved assistant on this site yet.', $host), array(
                'redirect'    => false,
                'host'        => $host,
                'client_name' => (string) $client->client_name,
            ));
        }
        $base = array('redirect' => true, 'redirect_uri' => $redirect_uri, 'state' => (string) ($q['state'] ?? ''));

        if (($q['response_type'] ?? '') !== 'code') {
            return new WP_Error('unsupported_response_type', 'Only response_type=code is supported.', $base);
        }
        $challenge = (string) ($q['code_challenge'] ?? '');
        if (!preg_match('/^[A-Za-z0-9\-_]{43,128}$/', $challenge) || ($q['code_challenge_method'] ?? '') !== 'S256') {
            return new WP_Error('invalid_request', 'PKCE with code_challenge_method=S256 is required.', $base);
        }
        $resource = (string) ($q['resource'] ?? '');
        if ($resource !== '' && !Azure_AI_Connectors_Tokens::same_resource($resource, Azure_AI_Connectors_Module::mcp_url())) {
            return new WP_Error('invalid_target', 'This server can only issue tokens for ' . Azure_AI_Connectors_Module::mcp_url(), $base);
        }
        return array(
            'client'         => $client,
            'redirect_uri'   => $redirect_uri,
            'state'          => (string) ($q['state'] ?? ''),
            'scopes'         => Azure_AI_Connectors_Policy::normalize_scopes((string) ($q['scope'] ?? '')),
            'code_challenge' => $challenge,
            'resource'       => $resource !== '' ? $resource : Azure_AI_Connectors_Module::mcp_url(),
        );
    }

    /** admin-post.php?action=pta_ai_authorize (GET) */
    public static function handle_authorize() {
        nocache_headers();
        $q = self::request_params($_GET);
        $valid = self::validate_authorize_request($q);
        $unapproved = is_wp_error($valid) && $valid->get_error_code() === 'unapproved_client';
        if (is_wp_error($valid) && !$unapproved) {
            self::fail($valid);
        }
        if (!Azure_AI_Connectors_Module::is_secure_request()) {
            self::render_message('HTTPS required', 'Connections can only be approved over HTTPS.');
        }
        if (!is_user_logged_in()) {
            $q['action'] = self::AUTHORIZE_ACTION;
            $back = admin_url('admin-post.php') . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
            wp_safe_redirect(wp_login_url($back));
            exit;
        }
        self::require_staff();
        if ($unapproved) {
            self::render_unapproved($valid);
        }
        self::render_consent($valid, $q);
    }

    private static function require_staff() {
        if (!Azure_AI_Connectors_Policy::can_connect()) {
            self::render_message('PTA staff accounts only', 'AI assistants can only be connected to PTA staff accounts. If you need access, ask a site administrator.', 403);
        }
    }

    /**
     * A staff member tried an assistant whose redirect domain is not
     * approved. Record it for the admins and explain what happens next.
     */
    private static function render_unapproved(WP_Error $error) {
        $data = (array) $error->get_error_data();
        $host = (string) ($data['host'] ?? '');
        $name = (string) ($data['client_name'] ?? '');
        Azure_AI_Connectors_Settings::record_pending_host($host, $name, get_current_user_id());
        status_header(403);
        self::page_open('Waiting for approval');
        echo '<h1>' . esc_html('This assistant is not approved yet') . '</h1>';
        echo '<p>' . esc_html(sprintf('"%s" wants to connect and send you back to %s, which is not on this site\'s list of approved assistants.', $name, $host)) . '</p>';
        if (Azure_AI_Connectors_Policy::can_administer()) {
            echo '<p>' . esc_html('You are a site administrator. If you recognise this domain as the assistant you are connecting, approve it, then start connecting again from the assistant.') . '</p>';
            echo '<p><a href="' . esc_url(Azure_AI_Connectors_Module::admin_page_url('apps')) . '">' . esc_html('Review it under PTA Tools > AI Connectors > Apps') . '</a></p>';
        } else {
            echo '<p>' . esc_html('A site administrator has been asked to review it. Once it is approved, start connecting again from the assistant.') . '</p>';
        }
        self::page_close();
        exit;
    }

    /** admin-post.php?action=pta_ai_authorize_decision (POST) */
    public static function handle_decision() {
        nocache_headers();
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url());
            exit;
        }
        self::require_staff();
        $q = self::request_params($_POST);
        $valid = self::validate_authorize_request($q);
        if (is_wp_error($valid)) {
            self::fail($valid);
        }
        if (!Azure_AI_Connectors_Module::is_secure_request()) {
            self::render_message('HTTPS required', 'Connections can only be approved over HTTPS.');
        }
        $nonce = isset($_POST['_pta_ai_nonce']) ? sanitize_text_field(wp_unslash($_POST['_pta_ai_nonce'])) : '';
        if (!wp_verify_nonce($nonce, self::nonce_action($valid))) {
            self::render_message('Request expired', 'This approval page expired. Go back to your assistant and start connecting again.');
        }
        $redirect = $valid['redirect_uri'];
        if (($_POST['decision'] ?? '') !== 'allow') {
            self::redirect_to_client($redirect, array('error' => 'access_denied', 'error_description' => 'The user declined.', 'state' => $valid['state']));
        }

        $scopes = array(Azure_AI_Connectors_Policy::SCOPE_READ);
        if (in_array(Azure_AI_Connectors_Policy::SCOPE_WRITE, $valid['scopes'], true) && !empty($_POST['allow_write'])) {
            $scopes[] = Azure_AI_Connectors_Policy::SCOPE_WRITE;
        }
        $code = Azure_AI_Connectors_Tokens::create_authorization(
            get_current_user_id(),
            $valid['client'],
            $scopes,
            $redirect,
            $valid['code_challenge'],
            $valid['resource']
        );
        if (is_wp_error($code)) {
            self::redirect_to_client($redirect, array('error' => 'server_error', 'error_description' => $code->get_error_message(), 'state' => $valid['state']));
        }
        Azure_AI_Connectors_Tokens::mark_client_used($valid['client']->client_id);
        self::redirect_to_client($redirect, array('code' => $code, 'state' => $valid['state']));
    }

    private static function request_params(array $source) {
        $keys = array('client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'scope', 'state', 'resource');
        $out = array();
        foreach ($keys as $k) {
            if (isset($source[$k]) && is_string($source[$k])) {
                $out[$k] = wp_unslash($source[$k]);
            }
        }
        return $out;
    }

    private static function nonce_action(array $valid) {
        return 'pta_ai_consent|' . $valid['client']->client_id . '|' . md5($valid['redirect_uri'] . '|' . $valid['code_challenge']);
    }

    private static function fail(WP_Error $error) {
        $data = (array) $error->get_error_data();
        if (!empty($data['redirect']) && !empty($data['redirect_uri'])) {
            self::redirect_to_client($data['redirect_uri'], array(
                'error'             => $error->get_error_code(),
                'error_description' => $error->get_error_message(),
                'state'             => (string) ($data['state'] ?? ''),
            ));
        }
        self::render_message('Cannot connect', $error->get_error_message());
    }

    private static function redirect_to_client($redirect_uri, array $params) {
        $params['iss'] = Azure_AI_Connectors_Module::issuer();
        $params = array_filter($params, function ($v) {
            return $v !== '' && $v !== null;
        });
        $sep = strpos($redirect_uri, '?') === false ? '?' : '&';
        $location = $redirect_uri . $sep . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        header('Location: ' . str_replace(array("\r", "\n"), '', $location), true, 302);
        exit;
    }

    private static function page_headers() {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('X-Frame-Options: DENY');
            header("Content-Security-Policy: frame-ancestors 'none'");
            header('Referrer-Policy: no-referrer');
            header('X-Content-Type-Options: nosniff');
        }
    }

    private static function page_open($title) {
        self::page_headers();
        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $icon = function_exists('get_site_icon_url') ? get_site_icon_url(64) : '';
        echo '<!doctype html><html lang="' . esc_attr(get_bloginfo('language')) . '"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">';
        echo '<title>' . esc_html($title . ' – ' . $site) . '</title>';
        echo '<style>body{font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f0f0f1;color:#1d2327;margin:0;padding:24px}'
            . '.card{max-width:560px;margin:24px auto;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:28px}'
            . 'h1{font-size:22px;margin:0 0 12px}h2{font-size:15px;margin:20px 0 6px}ul{margin:4px 0 0;padding-left:20px}li{margin:2px 0}'
            . '.muted{color:#646970;font-size:13px}.warn{background:#fcf9e8;border-left:4px solid #dba617;padding:8px 12px;margin:14px 0}'
            . '.site{display:flex;align-items:center;gap:10px;margin-bottom:16px}.site img{width:32px;height:32px;border-radius:6px}'
            . '.actions{display:flex;gap:10px;margin-top:22px}button{font:inherit;padding:9px 18px;border-radius:4px;border:1px solid #2271b1;cursor:pointer}'
            . '.allow{background:#2271b1;color:#fff}.deny{background:#fff;color:#2271b1}label{display:block;margin-top:14px}code{background:#f6f7f7;padding:1px 4px}</style>';
        echo '</head><body><main class="card"><div class="site">';
        if ($icon) {
            echo '<img src="' . esc_url($icon) . '" alt="">';
        }
        echo '<strong>' . esc_html($site) . '</strong></div>';
    }

    private static function page_close() {
        echo '</main></body></html>';
    }

    private static function render_message($title, $message, $status = 400) {
        status_header((int) $status);
        self::page_open($title);
        echo '<h1>' . esc_html($title) . '</h1><p>' . esc_html($message) . '</p>';
        self::page_close();
        exit;
    }

    private static function render_consent(array $valid, array $q) {
        $user = wp_get_current_user();
        $client = $valid['client'];
        $wants_write = in_array(Azure_AI_Connectors_Policy::SCOPE_WRITE, $valid['scopes'], true);
        $read_tools = Azure_AI_Connectors_Registry::available(array(Azure_AI_Connectors_Policy::SCOPE_READ));
        $all_tools = $wants_write ? Azure_AI_Connectors_Registry::available(Azure_AI_Connectors_Policy::scopes()) : $read_tools;
        $write_tools = array_diff_key($all_tools, $read_tools);
        $redirect_host = (string) parse_url($valid['redirect_uri'], PHP_URL_HOST);
        if ($redirect_host === '') {
            $redirect_host = (string) parse_url($valid['redirect_uri'], PHP_URL_SCHEME) . '://';
        }

        self::page_open('Connect an assistant');
        echo '<h1>' . esc_html(sprintf('Allow %s to use your account?', $client->client_name)) . '</h1>';
        echo '<p>' . esc_html(sprintf('Signed in as %s (%s).', $user->display_name, $user->user_email)) . ' ';
        echo '<a href="' . esc_url(wp_logout_url(admin_url('admin-post.php') . '?' . http_build_query($q + array('action' => self::AUTHORIZE_ACTION), '', '&', PHP_QUERY_RFC3986))) . '">Not you?</a></p>';
        echo '<p class="muted">' . esc_html(sprintf('The app calls itself "%s"; this name is not verified. After you approve, you will be sent back to %s.', $client->client_name, $redirect_host)) . '</p>';
        echo '<p>It will act as you, with exactly your permissions on this site. Everything it does is recorded in the audit log.</p>';

        echo '<h2>It will be able to</h2><ul>';
        foreach ($read_tools as $tool) {
            echo '<li>' . esc_html($tool['title']) . '</li>';
        }
        echo '</ul>';
        if ($write_tools) {
            echo '<h2>If you allow changes, also</h2><ul>';
            foreach ($write_tools as $tool) {
                $mark = Azure_AI_Connectors_Policy::requires_confirm($tool) ? ' (asks you to confirm first)' : '';
                echo '<li>' . esc_html($tool['title'] . $mark) . '</li>';
            }
            echo '</ul>';
        }
        if (Azure_AI_Connectors_Policy::is_entra_restricted($user)) {
            echo '<p class="warn">Your account signs in with Microsoft Entra, so delete and admin tools are never available through AI connections.</p>';
        }
        if (Azure_AI_Connectors_Policy::is_content_limited($user)) {
            echo '<p class="warn">Your role can read and update pages and posts through AI connections, but not create or trash them.</p>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::DECISION_ACTION) . '">';
        wp_nonce_field(self::nonce_action($valid), '_pta_ai_nonce', false);
        foreach ($q as $k => $v) {
            echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        }
        if ($write_tools) {
            echo '<label><input type="checkbox" name="allow_write" value="1" checked> Allow changes (create and update, and deletes you confirm)</label>';
        }
        echo '<div class="actions"><button class="allow" type="submit" name="decision" value="allow">Allow</button>';
        echo '<button class="deny" type="submit" name="decision" value="deny">Cancel</button></div>';
        echo '</form>';
        echo '<p class="muted">You can disconnect at any time from Users &gt; AI Connectors.</p>';
        self::page_close();
        exit;
    }

    // ─── Token and revocation ────────────────────────────────────────

    /**
     * @return array{0:object|null,1:string|null} Client row and the secret it presented.
     */
    private static function client_from_request(WP_REST_Request $request) {
        $client_id = '';
        $secret = null;
        $auth = (string) Azure_AI_Connectors_Module::authorization_header($request);
        if (stripos($auth, 'Basic ') === 0) {
            $decoded = base64_decode(substr($auth, 6), true);
            if (is_string($decoded) && strpos($decoded, ':') !== false) {
                list($client_id, $secret) = explode(':', $decoded, 2);
                $client_id = rawurldecode($client_id);
                $secret = rawurldecode($secret);
            }
        }
        if ($client_id === '') {
            $client_id = (string) $request->get_param('client_id');
            $posted = $request->get_param('client_secret');
            $secret = is_string($posted) ? $posted : null;
        }
        return array(Azure_AI_Connectors_Tokens::get_client($client_id), $secret);
    }

    public static function rest_token(WP_REST_Request $request) {
        if (!Azure_AI_Connectors_Module::is_secure_request()) {
            return self::oauth_error('invalid_request', 'HTTPS is required.', 400);
        }
        if (!Azure_AI_Connectors_Module::rate_limit('tok_ip_' . Azure_AI_Connectors_Module::client_ip_hash(), 60, MINUTE_IN_SECONDS)) {
            return self::oauth_error('slow_down', 'Too many token requests. Try again in a minute.', 429);
        }
        list($client, $secret) = self::client_from_request($request);
        if (!$client || !Azure_AI_Connectors_Tokens::client_authenticates($client, $secret)) {
            return self::oauth_error('invalid_client', 'Client authentication failed.', 401);
        }

        $grant_type = (string) $request->get_param('grant_type');
        if ($grant_type === 'authorization_code') {
            $result = Azure_AI_Connectors_Tokens::exchange_code(
                (string) $request->get_param('code'),
                $client->client_id,
                (string) $request->get_param('redirect_uri'),
                (string) $request->get_param('code_verifier'),
                (string) $request->get_param('resource')
            );
        } elseif ($grant_type === 'refresh_token') {
            $result = Azure_AI_Connectors_Tokens::refresh(
                (string) $request->get_param('refresh_token'),
                $client->client_id,
                (string) $request->get_param('scope')
            );
        } else {
            return self::oauth_error('unsupported_grant_type', 'Use authorization_code or refresh_token.', 400);
        }
        if (is_wp_error($result)) {
            return self::oauth_error($result->get_error_code(), $result->get_error_message(), 400);
        }
        Azure_AI_Connectors_Tokens::mark_client_used($client->client_id);
        return self::no_store(new WP_REST_Response($result, 200));
    }

    public static function rest_revoke(WP_REST_Request $request) {
        list($client, $secret) = self::client_from_request($request);
        if (!$client || !Azure_AI_Connectors_Tokens::client_authenticates($client, $secret)) {
            return self::oauth_error('invalid_client', 'Client authentication failed.', 401);
        }
        Azure_AI_Connectors_Tokens::revoke_by_secret((string) $request->get_param('token'), $client->client_id);
        return self::no_store(new WP_REST_Response(new stdClass(), 200));
    }

    public static function oauth_error($code, $description, $status) {
        $response = new WP_REST_Response(array('error' => (string) $code, 'error_description' => (string) $description), (int) $status);
        if ((int) $status === 401) {
            $response->header('WWW-Authenticate', 'Basic realm="pta-tools"');
        }
        if ((int) $status === 429) {
            $response->header('Retry-After', '60');
        }
        return self::no_store($response);
    }

    private static function no_store(WP_REST_Response $response) {
        $response->header('Cache-Control', 'no-store');
        $response->header('Pragma', 'no-cache');
        return $response;
    }
}
