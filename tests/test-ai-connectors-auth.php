<?php
/**
 * AI Connectors auth: personal access tokens, dynamic client registration,
 * authorization code + PKCE, refresh rotation, revocation and discovery.
 *
 * Run: php tests/test-ai-connectors-auth.php
 */

require_once __DIR__ . '/ai-connectors-shim.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-private-storage.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-ai-connectors-module.php';
Azure_AI_Connectors_Module::load();

$t = new TestRunner('AI Connectors auth');

function pkce_pair() {
    $verifier = Azure_AI_Connectors_Tokens::random_string(48);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    return array($verifier, $challenge);
}

function token_rows() {
    return $GLOBALS['wpdb']->get_results('SELECT * FROM wp_azure_ai_tokens');
}

// ─── Personal access tokens ──────────────────────────────────────────

AI_Test::reset();
AI_Test::add_user(1, array('administrator'), 'admin');

$pat = Azure_AI_Connectors_Tokens::create_pat(1, 'Laptop', 30, Azure_AI_Connectors_Policy::scopes());
$t->check(is_array($pat) && strpos($pat['token'], 'ptat_') === 0, 'a personal access token is created with its prefix');

$stored = token_rows();
$t->equals(1, count($stored), 'one row is stored for the token');
$t->check($stored[0]->token_hash === hash('sha256', $pat['token']), 'only the SHA-256 of the token is stored');
$t->check(strpos(json_encode($stored), $pat['token']) === false, 'the plaintext token appears nowhere in the table');
$t->check($stored[0]->expires_at !== null, 'a 30-day token has an expiry');

$auth = Azure_AI_Connectors_Tokens::authenticate($pat['token']);
$t->check(!is_wp_error($auth) && (int) $auth->user_id === 1, 'the token authenticates as its owner');
$t->check(is_wp_error(Azure_AI_Connectors_Tokens::authenticate($pat['token'] . 'x')), 'a tampered token is rejected');
$t->check(is_wp_error(Azure_AI_Connectors_Tokens::authenticate('')), 'an empty token is rejected');

$t->check(is_wp_error(Azure_AI_Connectors_Tokens::create_pat(1, '   ', 30, array())), 'a token needs a name');
$t->check(is_wp_error(Azure_AI_Connectors_Tokens::create_pat(1, 'Forever', 0, array())), 'with a 365-day cap, a never-expiring token is refused');
$t->check(is_wp_error(Azure_AI_Connectors_Tokens::create_pat(1, 'Too long', 400, array())), 'a token past the cap is refused');
update_option(Azure_AI_Connectors_Settings::OPTION, array('pat_max_days' => 0));
Azure_AI_Connectors_Settings::reset_cache();
$forever = Azure_AI_Connectors_Tokens::create_pat(1, 'Forever', 0, array());
$t->check(is_array($forever), 'with no cap, a never-expiring token is allowed');

AI_Test::add_user(2, array('subscriber'), 'parent');
$t->check(is_wp_error(Azure_AI_Connectors_Tokens::revoke_by_id($pat['id'], 2, false)), 'another user cannot revoke your token');
$t->check(Azure_AI_Connectors_Tokens::revoke_by_id($pat['id'], 1, false) === true, 'the owner can revoke it');
$t->check(is_wp_error(Azure_AI_Connectors_Tokens::authenticate($pat['token'])), 'a revoked token stops working');

$GLOBALS['wpdb']->query("UPDATE wp_azure_ai_tokens SET expires_at = '2000-01-01 00:00:00' WHERE id = " . (int) $forever['id']);
$t->check(is_wp_error(Azure_AI_Connectors_Tokens::authenticate($forever['token'])), 'an expired token stops working');

$ro = Azure_AI_Connectors_Tokens::create_pat(1, 'Read only', 7, array(Azure_AI_Connectors_Policy::SCOPE_READ));
$t->equals('pta:read', Azure_AI_Connectors_Tokens::authenticate($ro['token'])->scope, 'a read-only token carries only pta:read');

$other = Azure_AI_Connectors_Tokens::create_pat(2, 'Phone', 7, array());
$t->check(Azure_AI_Connectors_Tokens::revoke_by_id($other['id'], 1, true) === true, 'an admin can revoke anyone\'s token');
$listed = Azure_AI_Connectors_Tokens::list_connections(1);
$t->check(count($listed) === 1 && $listed[0]->name === 'Read only', 'a user\'s list shows only their active tokens');

unset(AI_Test::$users[2]);
$gone = Azure_AI_Connectors_Tokens::create_pat(1, 'x', 7, array());
$GLOBALS['wpdb']->query('UPDATE wp_azure_ai_tokens SET user_id = 2 WHERE id = ' . (int) $gone['id']);
$t->check(is_wp_error(Azure_AI_Connectors_Tokens::authenticate($gone['token'])), 'a token whose user was deleted is rejected');

// ─── Client registration and redirect rules ──────────────────────────

AI_Test::reset();
AI_Test::add_user(1, array('administrator'), 'admin');

$reg = Azure_AI_Connectors_Tokens::register_client(array(
    'client_name'                => 'Claude',
    'redirect_uris'              => array('https://claude.ai/api/mcp/auth_callback'),
    'token_endpoint_auth_method' => 'none',
));
$t->check(is_array($reg) && strpos($reg['client_id'], 'ptc_') === 0, 'a public client registers');
$t->check(!isset($reg['client_secret']), 'a public client gets no secret');

foreach (array(
    'http://evil.example/cb'     => 'plain http to a remote host',
    'javascript:alert(1)'        => 'a javascript: URI',
    'https://ok.example/cb#frag' => 'a URI with a fragment',
    'data:text/html,hi'          => 'a data: URI',
) as $uri => $why) {
    $bad = Azure_AI_Connectors_Tokens::register_client(array('redirect_uris' => array($uri)));
    $t->check(is_wp_error($bad), 'registration rejects ' . $why);
}
foreach (array('http://127.0.0.1:33418/callback', 'http://localhost/cb', 'cursor://anysphere.cursor-retrieval/oauth/callback', 'com.example.app:/oauth') as $uri) {
    $t->check(Azure_AI_Connectors_Tokens::valid_redirect_uri($uri), 'registration accepts ' . $uri);
}
$t->check(is_wp_error(Azure_AI_Connectors_Tokens::register_client(array('redirect_uris' => array('https://a.example/cb'), 'grant_types' => array('client_credentials')))), 'unsupported grant types are refused');

$client = Azure_AI_Connectors_Tokens::get_client($reg['client_id']);
$t->check(Azure_AI_Connectors_Tokens::redirect_uri_allowed($client, 'https://claude.ai/api/mcp/auth_callback'), 'the registered redirect is allowed');
$t->check(!Azure_AI_Connectors_Tokens::redirect_uri_allowed($client, 'https://claude.ai/api/mcp/auth_callback/x'), 'a different path is not allowed');

$loop = Azure_AI_Connectors_Tokens::register_client(array('redirect_uris' => array('http://127.0.0.1:5000/cb')));
$loop_client = Azure_AI_Connectors_Tokens::get_client($loop['client_id']);
$t->check(Azure_AI_Connectors_Tokens::redirect_uri_allowed($loop_client, 'http://127.0.0.1:61234/cb'), 'a loopback redirect may use another port (RFC 8252)');
$t->check(!Azure_AI_Connectors_Tokens::redirect_uri_allowed($loop_client, 'http://localhost:61234/cb'), 'but not another host');

$conf = Azure_AI_Connectors_Tokens::register_client(array('redirect_uris' => array('https://chatgpt.example/cb'), 'token_endpoint_auth_method' => 'client_secret_post'));
$conf_client = Azure_AI_Connectors_Tokens::get_client($conf['client_id']);
$t->check(!empty($conf['client_secret']), 'a confidential client gets a secret, once');
$t->check($conf_client->client_secret_hash === hash('sha256', $conf['client_secret']), 'the client secret is stored hashed');
$t->check(Azure_AI_Connectors_Tokens::client_authenticates($conf_client, $conf['client_secret']), 'the right secret authenticates the client');
$t->check(!Azure_AI_Connectors_Tokens::client_authenticates($conf_client, 'wrong'), 'a wrong secret does not');
$t->check(!Azure_AI_Connectors_Tokens::client_authenticates($conf_client, null), 'no secret does not');

// ─── Authorization request validation ────────────────────────────────

list($verifier, $challenge) = pkce_pair();
$base = array(
    'client_id'             => $reg['client_id'],
    'redirect_uri'          => 'https://claude.ai/api/mcp/auth_callback',
    'response_type'         => 'code',
    'code_challenge'        => $challenge,
    'code_challenge_method' => 'S256',
    'state'                 => 'xyz',
    'resource'              => 'https://example.test/wp-json/pta-tools/v1/mcp',
);
$ok = Azure_AI_Connectors_OAuth::validate_authorize_request($base);
$t->check(is_array($ok) && $ok['scopes'] === array('pta:read', 'pta:write'), 'a valid request with no scope offers read and write');

$no_pkce = $base;
unset($no_pkce['code_challenge']);
$e = Azure_AI_Connectors_OAuth::validate_authorize_request($no_pkce);
$t->check(is_wp_error($e) && $e->get_error_code() === 'invalid_request', 'PKCE is required');
$plain = array_merge($base, array('code_challenge_method' => 'plain'));
$t->check(is_wp_error(Azure_AI_Connectors_OAuth::validate_authorize_request($plain)), 'the plain PKCE method is refused');
$other_res = array_merge($base, array('resource' => 'https://other.example/mcp'));
$e = Azure_AI_Connectors_OAuth::validate_authorize_request($other_res);
$t->check(is_wp_error($e) && $e->get_error_code() === 'invalid_target', 'a token for another resource is refused');
$bad_redirect = array_merge($base, array('redirect_uri' => 'https://attacker.example/cb'));
$e = Azure_AI_Connectors_OAuth::validate_authorize_request($bad_redirect);
$t->check(is_wp_error($e) && empty($e->get_error_data()['redirect']), 'an unregistered redirect is shown as an error, never redirected to');
$e = Azure_AI_Connectors_OAuth::validate_authorize_request(array_merge($base, array('client_id' => 'ptc_nope')));
$t->check(is_wp_error($e) && $e->get_error_code() === 'invalid_client', 'an unknown client is refused');

// ─── Code exchange ───────────────────────────────────────────────────

$code = Azure_AI_Connectors_Tokens::create_authorization(1, $client, array('pta:read', 'pta:write'), $base['redirect_uri'], $challenge, $base['resource']);
$t->check(is_string($code) && strpos($code, 'ptoc_') === 0, 'consent produces an authorization code');

$wrong_verifier = Azure_AI_Connectors_Tokens::exchange_code($code, $reg['client_id'], $base['redirect_uri'], str_repeat('a', 43));
$t->check(is_wp_error($wrong_verifier) && $wrong_verifier->get_error_code() === 'invalid_grant', 'a wrong PKCE verifier is refused');

$code2 = Azure_AI_Connectors_Tokens::create_authorization(1, $client, array('pta:read', 'pta:write'), $base['redirect_uri'], $challenge, $base['resource']);
$other_client = Azure_AI_Connectors_Tokens::exchange_code($code2, $loop['client_id'], $base['redirect_uri'], $verifier);
$t->check(is_wp_error($other_client), 'another client cannot redeem the code');
$pair = Azure_AI_Connectors_Tokens::exchange_code($code2, $reg['client_id'], $base['redirect_uri'], $verifier, $base['resource']);
$t->check(is_array($pair) && strpos($pair['access_token'], 'ptoa_') === 0 && strpos($pair['refresh_token'], 'ptor_') === 0, 'the right verifier gets an access and refresh token');
$t->equals(3600, $pair['expires_in'], 'access tokens last an hour by default');
$t->equals('Bearer', $pair['token_type'], 'token_type is Bearer');

$access = Azure_AI_Connectors_Tokens::authenticate($pair['access_token']);
$t->check(!is_wp_error($access) && (int) $access->user_id === 1, 'the access token authenticates as the consenting user');
$t->check(is_wp_error(Azure_AI_Connectors_Tokens::authenticate($pair['refresh_token'])), 'a refresh token is not accepted as a bearer token');
$t->check(is_wp_error(Azure_AI_Connectors_Tokens::authenticate($code2)), 'an authorization code is not accepted as a bearer token');

$replay = Azure_AI_Connectors_Tokens::exchange_code($code2, $reg['client_id'], $base['redirect_uri'], $verifier);
$t->check(is_wp_error($replay), 'a code can only be used once');
$t->check(is_wp_error(Azure_AI_Connectors_Tokens::authenticate($pair['access_token'])), 'replaying a code revokes the tokens it issued');

// ─── Refresh rotation ────────────────────────────────────────────────

list($verifier, $challenge) = pkce_pair();
$code3 = Azure_AI_Connectors_Tokens::create_authorization(1, $client, array('pta:read'), $base['redirect_uri'], $challenge, $base['resource']);
$pair = Azure_AI_Connectors_Tokens::exchange_code($code3, $reg['client_id'], $base['redirect_uri'], $verifier);
$t->equals('pta:read', $pair['scope'], 'a read-only consent issues a read-only token');

$escalate = Azure_AI_Connectors_Tokens::refresh($pair['refresh_token'], $reg['client_id'], 'pta:read pta:write');
$t->check(is_wp_error($escalate) && $escalate->get_error_code() === 'invalid_scope', 'a refresh cannot add scopes');

$code4 = Azure_AI_Connectors_Tokens::create_authorization(1, $client, array('pta:read', 'pta:write'), $base['redirect_uri'], $challenge, $base['resource']);
$pair = Azure_AI_Connectors_Tokens::exchange_code($code4, $reg['client_id'], $base['redirect_uri'], $verifier);
$next = Azure_AI_Connectors_Tokens::refresh($pair['refresh_token'], $reg['client_id']);
$t->check(is_array($next) && $next['refresh_token'] !== $pair['refresh_token'], 'refreshing rotates the refresh token');
$t->check(!is_wp_error(Azure_AI_Connectors_Tokens::authenticate($next['access_token'])), 'the new access token works');
$t->check(is_wp_error(Azure_AI_Connectors_Tokens::refresh($next['refresh_token'], $loop['client_id'])), 'another client cannot use the refresh token');

$reuse = Azure_AI_Connectors_Tokens::refresh($pair['refresh_token'], $reg['client_id']);
$t->check(is_wp_error($reuse), 'an already-rotated refresh token is refused');
$t->check(is_wp_error(Azure_AI_Connectors_Tokens::authenticate($next['access_token'])), 'reusing a rotated refresh token revokes the whole connection');
$t->check(is_wp_error(Azure_AI_Connectors_Tokens::refresh($next['refresh_token'], $reg['client_id'])), 'including the newest refresh token');

// ─── Revoking a connection ───────────────────────────────────────────

$code5 = Azure_AI_Connectors_Tokens::create_authorization(1, $client, array('pta:read', 'pta:write'), $base['redirect_uri'], $challenge, $base['resource']);
$pair = Azure_AI_Connectors_Tokens::exchange_code($code5, $reg['client_id'], $base['redirect_uri'], $verifier);
$connections = Azure_AI_Connectors_Tokens::list_connections(1);
$this_grant = Azure_AI_Connectors_Tokens::authenticate($pair['access_token'])->grant_id;
$grant_rows = array_values(array_filter($connections, function ($r) use ($this_grant) {
    return $r->token_type === 'grant' && $r->grant_id === $this_grant;
}));
$t->check(!empty($grant_rows) && $grant_rows[0]->name === 'Claude', 'the connection is listed under the app\'s name');
$t->check(is_wp_error(Azure_AI_Connectors_Tokens::authenticate(token_rows()[0]->token_hash)), 'a grant row is never usable as a token');
Azure_AI_Connectors_Tokens::revoke_by_id($grant_rows[0]->id, 1, false);
$t->check(is_wp_error(Azure_AI_Connectors_Tokens::authenticate($pair['access_token'])), 'revoking the connection stops its access token');
$t->check(is_wp_error(Azure_AI_Connectors_Tokens::refresh($pair['refresh_token'], $reg['client_id'])), 'and its refresh token');

$code6 = Azure_AI_Connectors_Tokens::create_authorization(1, $client, array('pta:read'), $base['redirect_uri'], $challenge, $base['resource']);
$pair = Azure_AI_Connectors_Tokens::exchange_code($code6, $reg['client_id'], $base['redirect_uri'], $verifier);
Azure_AI_Connectors_Tokens::revoke_by_secret($pair['refresh_token'], $loop['client_id']);
$t->check(!is_wp_error(Azure_AI_Connectors_Tokens::authenticate($pair['access_token'])), 'RFC 7009 revocation by another client does nothing');
Azure_AI_Connectors_Tokens::revoke_by_secret($pair['refresh_token'], $reg['client_id']);
$t->check(is_wp_error(Azure_AI_Connectors_Tokens::authenticate($pair['access_token'])), 'RFC 7009 revocation by the owning client revokes the connection');

Azure_AI_Connectors_Tokens::revoke_all_for_user(1);
$t->equals(0, count(Azure_AI_Connectors_Tokens::list_connections(1)), 'revoking everything for a user empties their list');

// ─── Connection lifecycle ────────────────────────────────────────────

$grants = function () {
    return array_values(array_filter(Azure_AI_Connectors_Tokens::list_connections(1), function ($r) { return $r->token_type === 'grant'; }));
};
$abandoned = Azure_AI_Connectors_Tokens::create_authorization(1, $client, array('pta:read'), $base['redirect_uri'], $challenge, $base['resource']);
$t->equals(1, count($grants()), 'an approved connection is listed while its code is fresh');
$GLOBALS['wpdb']->query("UPDATE wp_azure_ai_tokens SET expires_at = '2000-01-01 00:00:00' WHERE token_type IN ('grant', 'code')");
$t->equals(0, count($grants()), 'a connection whose code was never exchanged drops off the list');

$failed = Azure_AI_Connectors_Tokens::create_authorization(1, $client, array('pta:read'), $base['redirect_uri'], $challenge, $base['resource']);
Azure_AI_Connectors_Tokens::exchange_code($failed, $reg['client_id'], $base['redirect_uri'], str_repeat('b', 43));
$t->equals(0, count($grants()), 'a failed code exchange does not leave a connection behind');

$good = Azure_AI_Connectors_Tokens::create_authorization(1, $client, array('pta:read'), $base['redirect_uri'], $challenge, $base['resource']);
Azure_AI_Connectors_Tokens::exchange_code($good, $reg['client_id'], $base['redirect_uri'], $verifier);
$listed_grant = $grants();
$t->check(count($listed_grant) === 1 && strtotime($listed_grant[0]->expires_at . ' UTC') > time() + 29 * DAY_IN_SECONDS, 'a completed connection lasts as long as its refresh token');

$before = count(token_rows());
Azure_AI_Connectors_Tokens::purge_expired();
$t->check(count(token_rows()) < $before, 'the daily purge removes long-expired rows');
$t->equals(1, count($grants()), 'and keeps live connections');

// ─── PKCE and discovery ──────────────────────────────────────────────

// RFC 7636 appendix B test vector.
$t->check(Azure_AI_Connectors_Tokens::pkce_verify('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'), 'PKCE S256 matches the RFC 7636 test vector');
$t->check(!Azure_AI_Connectors_Tokens::pkce_verify('short', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'), 'a verifier under 43 characters is refused');

$as = Azure_AI_Connectors_OAuth::authorization_server_metadata();
$t->equals('https://example.test/wp-json/pta-tools/v1/oauth', $as['issuer'], 'the issuer is the oauth REST base');
$t->equals(array('S256'), $as['code_challenge_methods_supported'], 'metadata advertises S256 only');
$t->check(isset($as['registration_endpoint']), 'metadata advertises dynamic client registration');
$pr = Azure_AI_Connectors_OAuth::protected_resource_metadata();
$t->equals('https://example.test/wp-json/pta-tools/v1/mcp', $pr['resource'], 'the protected resource is the MCP URL');
$t->equals(array($as['issuer']), $pr['authorization_servers'], 'it points at this authorization server');

foreach (array(
    '/.well-known/oauth-protected-resource'                                  => 'resource',
    '/.well-known/oauth-protected-resource/wp-json/pta-tools/v1/mcp'         => 'resource',
    '/.well-known/oauth-authorization-server'                                => 'issuer',
    '/.well-known/oauth-authorization-server/wp-json/pta-tools/v1/oauth'     => 'issuer',
    '/.well-known/openid-configuration/wp-json/pta-tools/v1/oauth'           => 'issuer',
) as $path => $key) {
    $doc = Azure_AI_Connectors_Module::well_known_document($path);
    $t->check(is_array($doc) && isset($doc[$key]), 'serves ' . $path);
}
$t->check(Azure_AI_Connectors_Module::well_known_document('/.well-known/openid-configuration') === null, 'leaves the root openid-configuration to other plugins');
$t->check(Azure_AI_Connectors_Module::well_known_document('/.well-known/acme-challenge/abc') === null, 'ignores unrelated .well-known paths');

update_option(Azure_AI_Connectors_Settings::OPTION, array('allow_dynamic_registration' => false));
Azure_AI_Connectors_Settings::reset_cache();
$t->check(!isset(Azure_AI_Connectors_OAuth::authorization_server_metadata()['registration_endpoint']), 'turning registration off removes it from the metadata');

// ─── Settings and origins ────────────────────────────────────────────

$clean = Azure_AI_Connectors_Settings::sanitize(array(
    'allowed_origins'       => "https://Inspector.Example:6274/path\njavascript:alert(1)\nftp://x.example",
    'rate_limit_per_minute' => 100000,
    'access_token_ttl'      => 1,
));
$t->equals(array('https://inspector.example:6274'), $clean['allowed_origins'], 'origins are normalised and non-http values dropped');
$t->equals(1000, $clean['rate_limit_per_minute'], 'the rate limit is capped');
$t->equals(300, $clean['access_token_ttl'], 'access tokens last at least five minutes');

Azure_AI_Connectors_Settings::save(array('allowed_origins' => 'https://inspector.example:6274'));
$t->check(Azure_AI_Connectors_Module::origin_allowed('https://example.test'), 'the site\'s own origin is allowed');
$t->check(Azure_AI_Connectors_Module::origin_allowed('https://inspector.example:6274'), 'a configured origin is allowed');
$t->check(!Azure_AI_Connectors_Module::origin_allowed('https://evil.example'), 'other origins are not');

WP_Shim::$transients = array();
$hits = 0;
for ($i = 0; $i < 5; $i++) {
    if (Azure_AI_Connectors_Module::rate_limit('mcp_42', 3, 60)) {
        $hits++;
    }
}
$t->equals(3, $hits, 'the per-connection rate limit stops requests past the limit');
$t->check(Azure_AI_Connectors_Module::rate_limit('mcp_43', 3, 60), 'other connections have their own budget');

exit($t->finish() > 0 ? 1 : 0);
