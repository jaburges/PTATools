<?php
/**
 * Regression checks for Azure_OneDrive_Media_Auth token handling.
 *
 * The important case is a refresh response that omits `refresh_token`.
 * Microsoft Entra ID is not obliged to return one on every refresh, and
 * because store_tokens() persists via $wpdb->replace() (a DELETE + INSERT,
 * not a merge) writing an empty string destroys offline access: every
 * subsequent refresh fails and media sync silently stops until an admin
 * re-authorizes by hand. That is the failure mode referenced in
 * class-backup.php's "OneDrive auth expired 2026-04-02" comment.
 *
 * Run:  php tests/test-onedrive-media-auth.php
 */

require __DIR__ . '/wp-shim.php';
require __DIR__ . '/../Azure Plugin/includes/class-onedrive-media-auth.php';

$t = new TestRunner('Azure_OneDrive_Media_Auth');

const TOKEN_TABLE = 'wp_azure_onedrive_tokens';
const TOKEN_URL = 'login.microsoftonline.com';

/**
 * Fresh auth handler with one stored token row.
 *
 * @param string $expires_at   MySQL datetime for the stored token's expiry.
 * @param string $refresh      Stored refresh token.
 */
function make_auth($expires_at, $refresh = 'stored-refresh-token') {
    global $wpdb;
    WP_Shim::reset();
    $wpdb = new Fake_WPDB();
    $wpdb->tables[TOKEN_TABLE] = array(
        array(
            'user_email'    => 'admin@example.test',
            'access_token'  => 'stored-access-token',
            'refresh_token' => $refresh,
            'expires_at'    => $expires_at,
            'updated_at'    => gmdate('Y-m-d H:i:s'),
        ),
    );
    return new Azure_OneDrive_Media_Auth();
}

function stored_token_row() {
    global $wpdb;
    $rows = $wpdb->tables[TOKEN_TABLE];
    return $rows ? $rows[0] : null;
}

// ---------------------------------------------------------------------------
// 1. A still-valid token is returned without hitting the network
// ---------------------------------------------------------------------------

$auth = make_auth(gmdate('Y-m-d H:i:s', time() + 3600));
$t->equals('stored-access-token', $auth->get_access_token('admin@example.test'), 'a valid token is returned as-is');
$t->equals(0, count(WP_Shim::$http_calls), 'no token request is made while the token is valid');

// ---------------------------------------------------------------------------
// 2. A token inside the 5-minute expiry buffer is refreshed
// ---------------------------------------------------------------------------

$auth = make_auth(gmdate('Y-m-d H:i:s', time() + 60));
WP_Shim::on_request(TOKEN_URL, 200, array(
    'access_token'  => 'refreshed-access-token',
    'refresh_token' => 'refreshed-refresh-token',
    'expires_in'    => 3600,
));

$t->equals('refreshed-access-token', $auth->get_access_token('admin@example.test'), 'a near-expiry token is refreshed');
$row = stored_token_row();
$t->equals('refreshed-access-token', $row['access_token'], 'the refreshed access token is persisted');
$t->equals('refreshed-refresh-token', $row['refresh_token'], 'a rotated refresh token is persisted');

// ---------------------------------------------------------------------------
// 3. THE BUG: a refresh response with no refresh_token must not blank it
// ---------------------------------------------------------------------------

$auth = make_auth(gmdate('Y-m-d H:i:s', time() - 60), 'long-lived-refresh-token');
WP_Shim::on_request(TOKEN_URL, 200, array(
    'access_token' => 'second-access-token',
    'expires_in'   => 3600,
    // No refresh_token — Entra ID reuses the existing one.
));

$t->equals('second-access-token', $auth->get_access_token('admin@example.test'), 'refresh succeeds when no new refresh token is issued');
$row = stored_token_row();
$t->equals(
    'long-lived-refresh-token',
    $row['refresh_token'],
    'the existing refresh token survives a response that omits one'
);

// And the connection must still be refreshable a second time.
WP_Shim::$http_responses = array();
WP_Shim::on_request(TOKEN_URL, 200, array('access_token' => 'third-access-token', 'expires_in' => 3600));
$wpdb->tables[TOKEN_TABLE][0]['expires_at'] = gmdate('Y-m-d H:i:s', time() - 60);

$t->equals('third-access-token', $auth->get_access_token('admin@example.test'), 'a second consecutive refresh still works');
$sent = end(WP_Shim::$http_calls);
$t->equals('long-lived-refresh-token', $sent['args']['body']['refresh_token'], 'the preserved refresh token is the one sent to Entra ID');

// ---------------------------------------------------------------------------
// 4. Error responses must not overwrite good stored credentials
// ---------------------------------------------------------------------------

$auth = make_auth(gmdate('Y-m-d H:i:s', time() - 60), 'good-refresh-token');
WP_Shim::on_request(TOKEN_URL, 400, array(
    'error' => 'invalid_grant',
    // Note: no error_description, which used to raise an undefined-key warning.
));

$t->equals(false, $auth->get_access_token('admin@example.test'), 'a failed refresh returns false');
$row = stored_token_row();
$t->equals('good-refresh-token', $row['refresh_token'], 'a failed refresh leaves the stored refresh token intact');
$t->equals('stored-access-token', $row['access_token'], 'a failed refresh leaves the stored access token intact');

// A 200 with no access_token at all is also a failure, not a blank write.
$auth = make_auth(gmdate('Y-m-d H:i:s', time() - 60), 'good-refresh-token');
WP_Shim::on_request(TOKEN_URL, 200, array('token_type' => 'Bearer'));
$t->equals(false, $auth->get_access_token('admin@example.test'), 'a malformed refresh response returns false');
$t->equals('stored-access-token', stored_token_row()['access_token'], 'a malformed refresh response does not blank the access token');

// ---------------------------------------------------------------------------
// 5. user_has_token() must agree with get_access_token()
// ---------------------------------------------------------------------------

// get_access_token('default') falls back to "any stored token"; the admin
// screen's authorization badge must use the same rule or it reports
// "not connected" for a perfectly usable connection.
$auth = make_auth(gmdate('Y-m-d H:i:s', time() + 3600));
$t->check($auth->get_access_token('default') !== false, 'get_access_token falls back to any stored token');
$t->check($auth->user_has_token('default'), 'user_has_token uses the same fallback as get_access_token');

// An expired-but-refreshable token still counts as authorized.
$auth = make_auth(gmdate('Y-m-d H:i:s', time() - 60), 'good-refresh-token');
$t->check($auth->user_has_token('admin@example.test'), 'an expired token with a refresh token still counts as authorized');

// No stored token at all is not authorized.
WP_Shim::reset();
$wpdb = new Fake_WPDB();
$wpdb->tables[TOKEN_TABLE] = array();
$auth = new Azure_OneDrive_Media_Auth();
$t->equals(false, $auth->user_has_token('admin@example.test'), 'no stored token is reported as unauthorized');

// ---------------------------------------------------------------------------
// 6. App-only token caching
// ---------------------------------------------------------------------------

WP_Shim::reset();
$wpdb = new Fake_WPDB();
$auth = new Azure_OneDrive_Media_Auth();
WP_Shim::on_request(TOKEN_URL, 200, array('access_token' => 'app-token', 'expires_in' => 3600));

$t->equals('app-token', $auth->get_app_access_token(), 'app-only token is fetched');
$t->equals('app-token', $auth->get_app_access_token(), 'app-only token is served from cache');
$t->equals(1, count(WP_Shim::$http_calls), 'the cached app token avoids a second token request');

// A short-lived token must not be cached with a non-positive TTL, which would
// make get_transient() miss immediately and re-request on every single call.
WP_Shim::reset();
$auth = new Azure_OneDrive_Media_Auth();
WP_Shim::on_request(TOKEN_URL, 200, array('access_token' => 'brief-token', 'expires_in' => 30));
$auth->get_app_access_token();
$ttl = WP_Shim::$transient_ttls['azure_onedrive_app_token'] ?? null;
$t->check($ttl !== null && $ttl > 0, 'app token cache TTL stays positive for short-lived tokens', 'ttl=' . var_export($ttl, true));

exit($t->finish() > 0 ? 1 : 0);
