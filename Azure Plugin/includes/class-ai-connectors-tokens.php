<?php
/**
 * AI Connectors token and client storage.
 *
 * One table holds every credential, distinguished by token_type:
 *   pat      personal access token a user created on the AI Connectors page
 *   grant    one row per OAuth connection (what the user sees and revokes);
 *            never accepted as a bearer token
 *   code     OAuth authorization code, single use, 5 minutes
 *   access   OAuth access token
 *   refresh  OAuth refresh token, rotated on every use
 *   download single-use report download link, 15 minutes; never a bearer token
 *
 * Only a SHA-256 of each secret is stored. Secrets are 256 bits of
 * randomness behind a readable prefix, so a plain hash is enough and keeps
 * them valid if the site's salts change. OAuth rows that belong together
 * share a grant_id, and revoking the grant revokes all of them.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AI_Connectors_Tokens {

    const TYPE_PAT     = 'pat';
    const TYPE_GRANT   = 'grant';
    const TYPE_CODE    = 'code';
    const TYPE_ACCESS  = 'access';
    const TYPE_REFRESH = 'refresh';
    const TYPE_DOWNLOAD = 'download';

    const CODE_TTL = 300;

    /** Last-used timestamps are written at most this often per token. */
    const TOUCH_INTERVAL = 60;

    private static $prefixes = array(
        self::TYPE_PAT     => 'ptat_',
        self::TYPE_GRANT   => 'ptog_',
        self::TYPE_CODE    => 'ptoc_',
        self::TYPE_ACCESS  => 'ptoa_',
        self::TYPE_REFRESH => 'ptor_',
    );

    public static function table() {
        return Azure_Database::get_table_name('ai_tokens');
    }

    public static function clients_table() {
        return Azure_Database::get_table_name('ai_clients');
    }

    public static function hash($secret) {
        return hash('sha256', (string) $secret);
    }

    public static function random_string($bytes = 32) {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function generate($type) {
        return self::$prefixes[$type] . self::random_string(32);
    }

    public static function now() {
        return gmdate('Y-m-d H:i:s');
    }

    private static function at($seconds_from_now) {
        return gmdate('Y-m-d H:i:s', time() + (int) $seconds_from_now);
    }

    private static function hint($secret) {
        return substr((string) $secret, 0, 9) . '…';
    }

    private static function insert(array $row) {
        global $wpdb;
        $defaults = array(
            'token_hash'     => '',
            'token_type'     => '',
            'token_hint'     => '',
            'user_id'        => 0,
            'client_id'      => '',
            'grant_id'       => '',
            'name'           => '',
            'scope'          => '',
            'resource'       => '',
            'redirect_uri'   => '',
            'code_challenge' => '',
            'created_at'     => self::now(),
            'expires_at'     => null,
            'last_used_at'   => null,
            'revoked_at'     => null,
        );
        $row = array_merge($defaults, $row);
        $ok = $wpdb->insert(self::table(), $row);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    private static function find_by_secret($secret, array $types) {
        global $wpdb;
        $secret = (string) $secret;
        if ($secret === '' || strlen($secret) > 200) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE token_hash = %s LIMIT 1',
            self::hash($secret)
        ));
        if (!$row || !in_array($row->token_type, $types, true)) {
            return null;
        }
        return $row;
    }

    private static function is_expired($row) {
        return !empty($row->expires_at) && strcmp((string) $row->expires_at, self::now()) <= 0;
    }

    // ─── Personal access tokens ───────────────────────────────────────

    /**
     * @param int      $expires_days 0 = no expiry (if the site allows it).
     * @param string[] $scopes
     * @return array{token:string,id:int}|WP_Error
     */
    public static function create_pat($user_id, $name, $expires_days, array $scopes) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return new WP_Error('pta_ai_no_user', 'No user.');
        }
        $name = trim(sanitize_text_field((string) $name));
        if ($name === '') {
            return new WP_Error('pta_ai_pat_name', 'Give the token a name so you can recognise it later.');
        }
        $max = (int) Azure_AI_Connectors_Settings::value('pat_max_days');
        $expires_days = max(0, (int) $expires_days);
        if ($max > 0 && ($expires_days === 0 || $expires_days > $max)) {
            return new WP_Error('pta_ai_pat_expiry', sprintf('Tokens on this site must expire within %d days.', $max));
        }
        $secret = self::generate(self::TYPE_PAT);
        $id = self::insert(array(
            'token_hash' => self::hash($secret),
            'token_type' => self::TYPE_PAT,
            'token_hint' => self::hint($secret),
            'user_id'    => $user_id,
            'name'       => mb_substr($name, 0, 190),
            'scope'      => implode(' ', Azure_AI_Connectors_Policy::normalize_scopes($scopes)),
            'expires_at' => $expires_days > 0 ? self::at($expires_days * DAY_IN_SECONDS) : null,
        ));
        if (!$id) {
            return new WP_Error('pta_ai_db', 'Could not save the token.');
        }
        return array('token' => $secret, 'id' => $id);
    }

    // ─── Bearer authentication ───────────────────────────────────────

    /**
     * Resolve a bearer token to its row. Only personal access tokens and
     * OAuth access tokens are accepted.
     *
     * @return object|WP_Error Row with ->user_id, ->scope, ->client_id, ->grant_id.
     */
    public static function authenticate($bearer) {
        $row = self::find_by_secret($bearer, array(self::TYPE_PAT, self::TYPE_ACCESS));
        if (!$row) {
            return new WP_Error('invalid_token', 'The access token is not recognised.');
        }
        if (!empty($row->revoked_at)) {
            return new WP_Error('invalid_token', 'The access token has been revoked.');
        }
        if (self::is_expired($row)) {
            return new WP_Error('invalid_token', 'The access token has expired.');
        }
        if ($row->token_type === self::TYPE_ACCESS && $row->grant_id !== '') {
            $grant = self::get_grant($row->grant_id);
            if (!$grant || !empty($grant->revoked_at)) {
                return new WP_Error('invalid_token', 'The connection has been revoked.');
            }
        }
        if (!get_userdata((int) $row->user_id)) {
            return new WP_Error('invalid_token', 'The account behind this token no longer exists.');
        }
        self::touch($row);
        return $row;
    }

    private static function touch($row) {
        if (!empty($row->last_used_at) && strtotime($row->last_used_at . ' UTC') > time() - self::TOUCH_INTERVAL) {
            return;
        }
        global $wpdb;
        $now = self::now();
        $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . ' SET last_used_at = %s WHERE id = %d', $now, (int) $row->id));
        if ($row->grant_id !== '') {
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . self::table() . ' SET last_used_at = %s WHERE grant_id = %s AND token_type = %s',
                $now,
                $row->grant_id,
                self::TYPE_GRANT
            ));
        }
    }

    // ─── OAuth grants, codes and token pairs ─────────────────────────

    public static function get_grant($grant_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE grant_id = %s AND token_type = %s LIMIT 1',
            (string) $grant_id,
            self::TYPE_GRANT
        ));
    }

    /**
     * Record the user's consent and return an authorization code.
     *
     * @return string|WP_Error The code.
     */
    public static function create_authorization($user_id, $client, array $scopes, $redirect_uri, $code_challenge, $resource) {
        $grant_id = self::random_string(24);
        $scope = implode(' ', Azure_AI_Connectors_Policy::normalize_scopes($scopes));
        $grant_row = self::insert(array(
            'token_hash' => self::hash(self::generate(self::TYPE_GRANT)),
            'token_type' => self::TYPE_GRANT,
            'user_id'    => (int) $user_id,
            'client_id'  => (string) $client->client_id,
            'grant_id'   => $grant_id,
            'name'       => mb_substr((string) $client->client_name, 0, 190),
            'scope'      => $scope,
            'resource'   => (string) $resource,
            'expires_at' => self::at(self::CODE_TTL),
        ));
        if (!$grant_row) {
            return new WP_Error('server_error', 'Could not save the connection.');
        }
        $code = self::generate(self::TYPE_CODE);
        $ok = self::insert(array(
            'token_hash'     => self::hash($code),
            'token_type'     => self::TYPE_CODE,
            'user_id'        => (int) $user_id,
            'client_id'      => (string) $client->client_id,
            'grant_id'       => $grant_id,
            'scope'          => $scope,
            'resource'       => (string) $resource,
            'redirect_uri'   => (string) $redirect_uri,
            'code_challenge' => (string) $code_challenge,
            'expires_at'     => self::at(self::CODE_TTL),
        ));
        return $ok ? $code : new WP_Error('server_error', 'Could not save the authorization code.');
    }

    /**
     * Exchange an authorization code. Single use: a second attempt revokes
     * everything issued from the first, as OAuth 2.1 requires.
     *
     * @return array|WP_Error Token response fields.
     */
    public static function exchange_code($code, $client_id, $redirect_uri, $code_verifier, $resource = '') {
        global $wpdb;
        $row = self::find_by_secret($code, array(self::TYPE_CODE));
        if (!$row || !hash_equals((string) $row->client_id, (string) $client_id)) {
            return new WP_Error('invalid_grant', 'The authorization code is not valid.');
        }
        if (!empty($row->revoked_at)) {
            self::revoke_grant($row->grant_id);
            return new WP_Error('invalid_grant', 'The authorization code has already been used.');
        }
        $claimed = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET revoked_at = %s WHERE id = %d AND revoked_at IS NULL',
            self::now(),
            (int) $row->id
        ));
        if ((int) $claimed !== 1) {
            self::revoke_grant($row->grant_id);
            return new WP_Error('invalid_grant', 'The authorization code has already been used.');
        }
        $failure = null;
        if (self::is_expired($row)) {
            $failure = new WP_Error('invalid_grant', 'The authorization code has expired.');
        } elseif ((string) $row->redirect_uri !== (string) $redirect_uri) {
            $failure = new WP_Error('invalid_grant', 'redirect_uri does not match the authorization request.');
        } elseif (!self::pkce_verify((string) $code_verifier, (string) $row->code_challenge)) {
            $failure = new WP_Error('invalid_grant', 'PKCE verification failed.');
        } elseif ($resource !== '' && (string) $row->resource !== '' && !self::same_resource($resource, $row->resource)) {
            $failure = new WP_Error('invalid_target', 'The resource does not match the authorization request.');
        }
        if ($failure) {
            self::revoke_grant($row->grant_id);
            return $failure;
        }
        $grant = self::get_grant($row->grant_id);
        if (!$grant || !empty($grant->revoked_at) || !get_userdata((int) $row->user_id)) {
            return new WP_Error('invalid_grant', 'The connection is no longer valid.');
        }
        return self::issue_pair($row->user_id, $row->client_id, $row->grant_id, $row->scope, $row->resource);
    }

    /**
     * Rotate a refresh token. Presenting a token that was already rotated
     * means it leaked or was replayed, so the whole connection is revoked.
     *
     * @return array|WP_Error
     */
    public static function refresh($refresh_token, $client_id, $requested_scope = '') {
        global $wpdb;
        $row = self::find_by_secret($refresh_token, array(self::TYPE_REFRESH));
        if (!$row || !hash_equals((string) $row->client_id, (string) $client_id)) {
            return new WP_Error('invalid_grant', 'The refresh token is not valid.');
        }
        if (!empty($row->revoked_at)) {
            self::revoke_grant($row->grant_id);
            return new WP_Error('invalid_grant', 'The refresh token was already used. The connection has been revoked; reconnect to continue.');
        }
        if (self::is_expired($row)) {
            return new WP_Error('invalid_grant', 'The refresh token has expired. Reconnect to continue.');
        }
        $grant = self::get_grant($row->grant_id);
        if (!$grant || !empty($grant->revoked_at) || !get_userdata((int) $row->user_id)) {
            return new WP_Error('invalid_grant', 'The connection has been revoked.');
        }
        $claimed = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET revoked_at = %s WHERE id = %d AND revoked_at IS NULL',
            self::now(),
            (int) $row->id
        ));
        if ((int) $claimed !== 1) {
            self::revoke_grant($row->grant_id);
            return new WP_Error('invalid_grant', 'The refresh token was already used.');
        }

        $granted = explode(' ', (string) $row->scope);
        $scope = $granted;
        if (trim((string) $requested_scope) !== '') {
            $asked = preg_split('/\s+/', trim((string) $requested_scope));
            if (array_diff($asked, $granted)) {
                return new WP_Error('invalid_scope', 'A refresh cannot add scopes the user did not grant.');
            }
            $scope = Azure_AI_Connectors_Policy::normalize_scopes($asked);
        }
        return self::issue_pair($row->user_id, $row->client_id, $row->grant_id, implode(' ', $scope), $row->resource);
    }

    private static function issue_pair($user_id, $client_id, $grant_id, $scope, $resource) {
        $access_ttl = (int) Azure_AI_Connectors_Settings::value('access_token_ttl');
        $refresh_ttl = (int) Azure_AI_Connectors_Settings::value('refresh_token_ttl');
        $access = self::generate(self::TYPE_ACCESS);
        $refresh = self::generate(self::TYPE_REFRESH);
        $common = array(
            'user_id'   => (int) $user_id,
            'client_id' => (string) $client_id,
            'grant_id'  => (string) $grant_id,
            'scope'     => (string) $scope,
            'resource'  => (string) $resource,
        );
        $a = self::insert($common + array(
            'token_hash' => self::hash($access),
            'token_type' => self::TYPE_ACCESS,
            'token_hint' => self::hint($access),
            'expires_at' => self::at($access_ttl),
        ));
        $r = self::insert($common + array(
            'token_hash' => self::hash($refresh),
            'token_type' => self::TYPE_REFRESH,
            'token_hint' => self::hint($refresh),
            'expires_at' => self::at($refresh_ttl),
        ));
        if (!$a || !$r) {
            return new WP_Error('server_error', 'Could not save the tokens.');
        }
        // The connection lives as long as its newest refresh token.
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET expires_at = %s WHERE grant_id = %s AND token_type = %s',
            self::at($refresh_ttl),
            (string) $grant_id,
            self::TYPE_GRANT
        ));
        return array(
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'expires_in'    => $access_ttl,
            'refresh_token' => $refresh,
            'scope'         => (string) $scope,
        );
    }

    // ─── Single-use download links ───────────────────────────────────

    /**
     * @return string The secret for the download URL.
     */
    public static function create_download($path, $filename, $user_id, $ttl) {
        $secret = self::random_string(32);
        $id = self::insert(array(
            'token_hash'   => self::hash($secret),
            'token_type'   => self::TYPE_DOWNLOAD,
            'user_id'      => (int) $user_id,
            'name'         => mb_substr((string) $filename, 0, 190),
            'redirect_uri' => (string) $path,
            'expires_at'   => self::at($ttl),
        ));
        return $id ? $secret : '';
    }

    /**
     * Claim a download link. Only the first claim succeeds, even when two
     * requests race, because the claim is a conditional UPDATE.
     *
     * @return object|WP_Error Row with ->redirect_uri (file path) and ->name (filename).
     */
    public static function claim_download($secret) {
        global $wpdb;
        $row = self::find_by_secret($secret, array(self::TYPE_DOWNLOAD));
        if (!$row || !empty($row->revoked_at) || self::is_expired($row)) {
            return new WP_Error('pta_ai_download_gone', 'This download link has expired or was already used.');
        }
        $claimed = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET revoked_at = %s, last_used_at = %s WHERE id = %d AND revoked_at IS NULL',
            self::now(),
            self::now(),
            (int) $row->id
        ));
        if ((int) $claimed !== 1) {
            return new WP_Error('pta_ai_download_gone', 'This download link has expired or was already used.');
        }
        return $row;
    }

    public static function pkce_verify($verifier, $challenge) {
        if ($challenge === '' || !preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier)) {
            return false;
        }
        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return hash_equals($challenge, $computed);
    }

    public static function same_resource($a, $b) {
        return rtrim(strtolower((string) $a), '/') === rtrim(strtolower((string) $b), '/');
    }

    // ─── Revocation and listing ──────────────────────────────────────

    public static function revoke_grant($grant_id) {
        global $wpdb;
        if ((string) $grant_id === '') {
            return 0;
        }
        return (int) $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET revoked_at = %s WHERE grant_id = %s AND revoked_at IS NULL',
            self::now(),
            (string) $grant_id
        ));
    }

    /**
     * Revoke a personal access token or OAuth connection by row id.
     * $actor_id must own it unless $as_admin.
     */
    public static function revoke_by_id($id, $actor_id, $as_admin = false) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1', (int) $id));
        if (!$row || !in_array($row->token_type, array(self::TYPE_PAT, self::TYPE_GRANT), true)) {
            return new WP_Error('pta_ai_not_found', 'That connection was not found.');
        }
        if (!$as_admin && (int) $row->user_id !== (int) $actor_id) {
            return new WP_Error('pta_ai_forbidden', 'You can only revoke your own connections.');
        }
        if ($row->token_type === self::TYPE_GRANT) {
            self::revoke_grant($row->grant_id);
        } else {
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . self::table() . ' SET revoked_at = %s WHERE id = %d AND revoked_at IS NULL',
                self::now(),
                (int) $row->id
            ));
        }
        return true;
    }

    /** RFC 7009: revoke an access or refresh token presented by its client. */
    public static function revoke_by_secret($secret, $client_id) {
        $row = self::find_by_secret($secret, array(self::TYPE_ACCESS, self::TYPE_REFRESH));
        if ($row && hash_equals((string) $row->client_id, (string) $client_id)) {
            self::revoke_grant($row->grant_id);
        }
    }

    public static function revoke_all_for_user($user_id) {
        global $wpdb;
        return (int) $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET revoked_at = %s WHERE user_id = %d AND revoked_at IS NULL',
            self::now(),
            (int) $user_id
        ));
    }

    /**
     * Active personal access tokens and OAuth connections.
     *
     * @param int $user_id 0 for everyone (admin view).
     * @return object[]
     */
    public static function list_connections($user_id = 0) {
        global $wpdb;
        $sql = 'SELECT id, token_type, token_hint, user_id, client_id, grant_id, name, scope, created_at, expires_at, last_used_at FROM '
            . self::table() . ' WHERE token_type IN (%s, %s) AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > %s)';
        $args = array(self::TYPE_PAT, self::TYPE_GRANT, self::now());
        if ((int) $user_id > 0) {
            $sql .= ' AND user_id = %d';
            $args[] = (int) $user_id;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 500';
        return (array) $wpdb->get_results($wpdb->prepare($sql, $args));
    }

    public static function purge_expired() {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::table() . ' WHERE (expires_at IS NOT NULL AND expires_at < %s) OR (revoked_at IS NOT NULL AND revoked_at < %s)',
            $cutoff,
            $cutoff
        ));
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::clients_table() . ' WHERE last_used_at IS NULL AND created_at < %s',
            $cutoff
        ));
    }

    // ─── OAuth clients (dynamic client registration) ─────────────────

    /**
     * RFC 7591 registration.
     *
     * @return array|WP_Error Registration response.
     */
    public static function register_client(array $meta) {
        global $wpdb;
        $uris = isset($meta['redirect_uris']) ? $meta['redirect_uris'] : null;
        if (!is_array($uris) || empty($uris) || count($uris) > 10) {
            return new WP_Error('invalid_redirect_uri', 'Provide between one and ten redirect_uris.');
        }
        $clean_uris = array();
        foreach ($uris as $uri) {
            if (!is_string($uri) || !self::valid_redirect_uri($uri)) {
                return new WP_Error('invalid_redirect_uri', 'Redirect URIs must be https, a loopback http address, or an app-specific scheme.');
            }
            $clean_uris[] = $uri;
        }

        $method = isset($meta['token_endpoint_auth_method']) ? (string) $meta['token_endpoint_auth_method'] : 'none';
        if (!in_array($method, array('none', 'client_secret_post', 'client_secret_basic'), true)) {
            return new WP_Error('invalid_client_metadata', 'token_endpoint_auth_method must be none, client_secret_post or client_secret_basic.');
        }
        $grant_types = isset($meta['grant_types']) && is_array($meta['grant_types']) ? array_map('strval', $meta['grant_types']) : array('authorization_code', 'refresh_token');
        if (array_diff($grant_types, array('authorization_code', 'refresh_token'))) {
            return new WP_Error('invalid_client_metadata', 'Only the authorization_code and refresh_token grants are supported.');
        }
        if (isset($meta['response_types']) && (!is_array($meta['response_types']) || array_diff($meta['response_types'], array('code')))) {
            return new WP_Error('invalid_client_metadata', 'Only the code response type is supported.');
        }

        $name = isset($meta['client_name']) && is_string($meta['client_name']) ? trim(wp_strip_all_tags($meta['client_name'])) : '';
        $name = $name !== '' ? mb_substr($name, 0, 120) : 'MCP client';
        $client_uri = isset($meta['client_uri']) && is_string($meta['client_uri']) ? esc_url_raw($meta['client_uri'], array('https')) : '';
        $software_id = isset($meta['software_id']) && is_string($meta['software_id']) ? sanitize_text_field(mb_substr($meta['software_id'], 0, 190)) : '';

        $client_id = 'ptc_' . self::random_string(18);
        $secret = '';
        if ($method !== 'none') {
            $secret = 'ptcs_' . self::random_string(32);
        }
        $now = self::now();
        $ok = $wpdb->insert(self::clients_table(), array(
            'client_id'                  => $client_id,
            'client_secret_hash'         => $secret !== '' ? self::hash($secret) : '',
            'client_name'                => $name,
            'redirect_uris'              => wp_json_encode($clean_uris),
            'grant_types'                => implode(' ', $grant_types),
            'token_endpoint_auth_method' => $method,
            'client_uri'                 => (string) $client_uri,
            'software_id'                => $software_id,
            'created_at'                 => $now,
            'last_used_at'               => null,
        ));
        if (!$ok) {
            return new WP_Error('server_error', 'Could not register the client.');
        }

        $response = array(
            'client_id'                  => $client_id,
            'client_id_issued_at'        => time(),
            'client_name'                => $name,
            'redirect_uris'              => $clean_uris,
            'grant_types'                => $grant_types,
            'response_types'             => array('code'),
            'token_endpoint_auth_method' => $method,
            'scope'                      => implode(' ', Azure_AI_Connectors_Policy::scopes()),
        );
        if ($client_uri !== '') {
            $response['client_uri'] = $client_uri;
        }
        if ($secret !== '') {
            $response['client_secret'] = $secret;
            $response['client_secret_expires_at'] = 0;
        }
        return $response;
    }

    public static function get_client($client_id) {
        global $wpdb;
        $client_id = (string) $client_id;
        if ($client_id === '' || strlen($client_id) > 100) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::clients_table() . ' WHERE client_id = %s LIMIT 1',
            $client_id
        ));
        if (!$row) {
            return null;
        }
        $row->redirect_uris_list = json_decode((string) $row->redirect_uris, true) ?: array();
        return $row;
    }

    public static function list_clients() {
        global $wpdb;
        return (array) $wpdb->get_results('SELECT id, client_id, client_name, redirect_uris, token_endpoint_auth_method, created_at, last_used_at FROM '
            . self::clients_table() . ' ORDER BY created_at DESC LIMIT 500');
    }

    public static function mark_client_used($client_id) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::clients_table() . ' SET last_used_at = %s WHERE client_id = %s',
            self::now(),
            (string) $client_id
        ));
    }

    public static function delete_client($client_id) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET revoked_at = %s WHERE client_id = %s AND revoked_at IS NULL',
            self::now(),
            (string) $client_id
        ));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::clients_table() . ' WHERE client_id = %s', (string) $client_id));
    }

    /**
     * Confidential clients must present their secret; public clients
     * (token_endpoint_auth_method none) rely on PKCE.
     */
    public static function client_authenticates($client, $secret) {
        if (!$client) {
            return false;
        }
        if ((string) $client->token_endpoint_auth_method === 'none' || (string) $client->client_secret_hash === '') {
            return true;
        }
        return is_string($secret) && $secret !== '' && hash_equals((string) $client->client_secret_hash, self::hash($secret));
    }

    /**
     * https anywhere; http only to a loopback address (RFC 8252 native apps);
     * private-use schemes such as cursor:// or vscode://.
     */
    public static function valid_redirect_uri($uri) {
        if (strlen($uri) > 2000 || preg_match('/[\s<>"]/', $uri)) {
            return false;
        }
        $parts = parse_url($uri);
        if (!is_array($parts) || empty($parts['scheme']) || isset($parts['fragment'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if (in_array($scheme, array('javascript', 'data', 'file', 'vbscript', 'about', 'blob', 'ftp', 'ws', 'wss'), true)) {
            return false;
        }
        if ($scheme === 'https') {
            return !empty($parts['host']);
        }
        if ($scheme === 'http') {
            return !empty($parts['host']) && self::is_loopback_host($parts['host']);
        }
        if (in_array($scheme, array('cursor', 'vscode', 'vscode-insiders', 'claude', 'windsurf', 'zed'), true)) {
            return true;
        }
        return preg_match('/^[a-z][a-z0-9+\-]*(\.[a-z0-9+\-]+)+$/', $scheme) === 1;
    }

    public static function is_loopback_host($host) {
        $host = strtolower(trim((string) $host, '[]'));
        return in_array($host, array('localhost', '127.0.0.1', '::1'), true);
    }

    /**
     * Exact match, except a loopback redirect may use any port (RFC 8252 7.3).
     */
    public static function redirect_uri_allowed($client, $uri) {
        if (!$client || !is_string($uri) || $uri === '') {
            return false;
        }
        foreach ((array) $client->redirect_uris_list as $registered) {
            if ($registered === $uri) {
                return true;
            }
            $a = parse_url($registered);
            $b = parse_url($uri);
            if (!is_array($a) || !is_array($b)) {
                continue;
            }
            if (($a['scheme'] ?? '') === 'http' && ($b['scheme'] ?? '') === 'http'
                && self::is_loopback_host($a['host'] ?? '') && strtolower($a['host'] ?? '') === strtolower($b['host'] ?? '')
                && ($a['path'] ?? '') === ($b['path'] ?? '') && ($a['query'] ?? '') === ($b['query'] ?? '')) {
                return true;
            }
        }
        return false;
    }
}
