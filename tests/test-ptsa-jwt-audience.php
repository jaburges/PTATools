<?php
/**
 * PTSA REST JWT audience: accept the iOS app id_token, not only website SSO.
 *
 * Run: php tests/test-ptsa-jwt-audience.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-ptsa-jwt.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-ptsa-rest-api.php';

$t = new TestRunner('PTSA JWT audience');

$ios = Azure_PTSA_REST_API::IOS_CLIENT_ID;
$sso = '4b740332-8ed7-4cf1-aecf-f2e3abcd90fe';

$t->check($ios === '62d983db-f1e9-49cf-a833-b332ea3af84e', 'iOS client id matches the board app registration');

$ids = Azure_PTSA_REST_API::collect_client_ids('', '', $sso);
$t->check(in_array($ios, $ids, true), 'empty option still allows the iOS client (prod fallback)');
$t->check(in_array($sso, $ids, true), 'website SSO client remains an allowed audience');
$t->check(count($ids) === 2, 'empty explicit/option does not invent extra ids');

$ids = Azure_PTSA_REST_API::collect_client_ids($sso, '', $sso);
$t->check(in_array($ios, $ids, true), 'PTSA_REST_CLIENT_ID set to SSO still allows iOS');
$t->check(count($ids) === 2, 'duplicate SSO ids are collapsed');

$custom = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
$ids = Azure_PTSA_REST_API::collect_client_ids('', $custom, $sso);
$t->check(in_array($custom, $ids, true), 'ptsa_rest_ios_client_id option is accepted');
$t->check(in_array($ios, $ids, true), 'hardcoded iOS id stays allowed alongside the option');

$t->check(Azure_PTSA_JWT::audience_matches($ios, $ids), 'iOS id_token aud matches the allowed list');
$t->check(Azure_PTSA_JWT::audience_matches($sso, array($sso)), 'SSO-only list still matches an SSO token');
$t->check(!Azure_PTSA_JWT::audience_matches($ios, array($sso)), 'iOS token is rejected when only SSO is allowed (the live 401)');
$t->check(Azure_PTSA_JWT::audience_matches(array('other', $ios), array($ios)), 'aud arrays match if any entry is allowed');
$t->check(!Azure_PTSA_JWT::audience_matches('not-an-app', array($ios, $sso)), 'unknown audience is rejected');
$t->check(!Azure_PTSA_JWT::audience_matches($ios, array()), 'empty allow-list rejects everything');
$t->check(Azure_PTSA_JWT::normalize_client_ids(array('', '  ', $ios, $ios)) === array($ios), 'normalize drops blanks and duplicates');

$jwt = new Azure_PTSA_JWT('a220d676-fd60-4d01-8742-d18944f51a66', $sso);
$t->check($jwt instanceof Azure_PTSA_JWT, 'constructor still accepts a single client id string');

exit($t->finish() === 0 ? 0 : 1);
