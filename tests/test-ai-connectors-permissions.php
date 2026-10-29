<?php
/**
 * AI Connectors permissions: which tools each kind of account gets, the
 * Entra no-destructive rule, confirm=true, read-only connections, and the
 * MCP JSON-RPC layer that enforces them.
 *
 * Run: php tests/test-ai-connectors-permissions.php
 */

require_once __DIR__ . '/ai-connectors-shim.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-private-storage.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-ai-connectors-module.php';
Azure_AI_Connectors_Module::load();

// WooCommerce present, so store tools are offered.
function wc_get_product($id = 0) { return false; }
function wc_get_orders($args = array()) { return array(); }

$t = new TestRunner('AI Connectors permissions');

$ALL = Azure_AI_Connectors_Policy::scopes();
$READ = array(Azure_AI_Connectors_Policy::SCOPE_READ);

function setup_site() {
    AI_Test::reset();
    WP_Shim::$settings = array('enable_calendar' => true, 'enable_ai_connectors' => true);
    AI_Test::add_user(1, array('administrator'), 'admin');
    AI_Test::add_user(2, array('shop_manager'), 'shop');
    AI_Test::add_user(3, array('azuread'), 'entra');
    AI_Test::add_user(4, array('newsletter_editor'), 'writer');
    AI_Test::add_user(5, array('subscriber'), 'parent');
    AI_Test::add_user(6, array('administrator', 'azuread'), 'boardadmin');
    AI_Test::add_user(7, array('administrator', 'shop_manager'), 'shopadmin');
}

function tools_for($user_id, array $scopes) {
    AI_Test::login($user_id);
    $names = array_keys(Azure_AI_Connectors_Registry::available($scopes));
    sort($names);
    return $names;
}

function sorted(array $names) {
    sort($names);
    return $names;
}

$store_read = array('pta_get_order', 'pta_get_product', 'pta_list_coupons', 'pta_list_orders', 'pta_list_products', 'pta_list_reports', 'pta_export_report');
$store_all = array_merge($store_read, array('pta_update_product', 'pta_create_coupon'));
$content_all = array('pta_list_content', 'pta_get_content', 'pta_create_content', 'pta_update_content', 'pta_trash_content');

// ─── Capability filtering by role ────────────────────────────────────

setup_site();
$every = sorted(array_keys(Azure_AI_Connectors_Registry::all()));
$t->equals(18, count($every), 'the first tool set has 18 tools');
$t->check(!in_array('pta_set_module', $every, true), 'there is no tool for switching modules');

$t->equals($every, tools_for(1, $ALL), 'an administrator gets every tool');

$t->equals(
    sorted(array_merge(array('pta_whoami', 'pta_list_events', 'pta_list_content', 'pta_get_content', 'pta_update_content'), $store_all)),
    tools_for(2, $ALL),
    'a shop manager gets every store tool, read and update for pages and posts, and nothing admin'
);

$t->equals(
    sorted(array('pta_whoami', 'pta_list_events', 'pta_list_content', 'pta_get_content', 'pta_create_content', 'pta_update_content')),
    tools_for(4, $ALL),
    'a custom role with only edit_posts gets the content tools its capabilities allow, without trash'
);

$t->equals(sorted(array('pta_whoami', 'pta_list_events')), tools_for(5, $ALL), 'a subscriber or parent can only see who they are and the calendar');

// ─── Entra (Azure AD) accounts ───────────────────────────────────────

AI_Test::login(3);
$t->check(current_user_can('delete_posts') && current_user_can('manage_options'), 'the azuread role really holds delete_posts and manage_options');
$t->check(Azure_AI_Connectors_Policy::is_entra_restricted(3), 'an azuread account is treated as an Entra account');
$entra_tools = tools_for(3, $ALL);
$t->equals(
    sorted(array('pta_whoami', 'pta_list_events', 'pta_list_content', 'pta_get_content', 'pta_update_content')),
    $entra_tools,
    'an Entra account gets read and update tools only'
);
foreach ($entra_tools as $name) {
    $kind = Azure_AI_Connectors_Registry::get($name)['kind'];
    $t->check(in_array($kind, array('read', 'update'), true), "Entra tool {$name} is read or update ({$kind})");
}
$t->check(!in_array('pta_trash_content', $entra_tools, true), 'no trash tool for Entra, even though the role can delete posts');
$t->check(!in_array('pta_calendar_sync', $entra_tools, true), 'no calendar sync for Entra, even though the role has manage_options');
$t->check(!in_array('pta_list_modules', $entra_tools, true), 'no admin tools for Entra');
$t->check(!Azure_AI_Connectors_Policy::can_administer(3), 'an Entra account cannot administer AI Connectors despite manage_options');
$t->check(Azure_AI_Connectors_Policy::can_administer(1), 'an administrator can');
$t->check(!Azure_AI_Connectors_Policy::can_administer(2), 'a shop manager cannot');

Azure_AI_Connectors_Settings::save(array_merge(Azure_AI_Connectors_Settings::defaults(), array('entra_allow_create' => true)));
$with_create = tools_for(3, $ALL);
$t->check(in_array('pta_create_content', $with_create, true), 'the admin setting can let Entra accounts create');
$t->check(!in_array('pta_trash_content', $with_create, true), 'but never delete');
$t->check(!in_array('pta_calendar_sync', $with_create, true), 'or run admin tools');

setup_site();
$t->equals($every, tools_for(6, $ALL), 'an administrator who also has the azuread role is exempt by default');
Azure_AI_Connectors_Settings::save(array_merge(Azure_AI_Connectors_Settings::defaults(), array('entra_exempt_admins' => false)));
$boardadmin = tools_for(6, $ALL);
$t->check(!in_array('pta_trash_content', $boardadmin, true) && !in_array('pta_calendar_sync', $boardadmin, true), 'with the exemption off, that administrator loses delete and admin tools');
$t->check(in_array('pta_export_report', $boardadmin, true) && in_array('pta_update_product', $boardadmin, true), 'but keeps read and update tools their caps allow');
$t->check(!in_array('pta_create_coupon', $boardadmin, true), 'and loses create tools');

setup_site();
$GLOBALS['wpdb']->insert('wp_azure_sso_users', array('wordpress_user_id' => 4, 'azure_user_id' => 'aad-4', 'azure_email' => 'writer@example.test'));
$t->check(!Azure_AI_Connectors_Policy::is_entra_restricted(4), 'signing in with Entra once does not restrict a custom role by default');
Azure_AI_Connectors_Settings::save(array_merge(Azure_AI_Connectors_Settings::defaults(), array('entra_restrict_sso_linked' => true)));
Azure_AI_Connectors_Policy::reset_cache();
$t->check(Azure_AI_Connectors_Policy::is_entra_restricted(4), 'with "also by sign-in" on, an SSO-linked account is restricted');
$t->check(!in_array('pta_create_content', tools_for(4, $ALL), true), 'and loses create');
$t->check(!Azure_AI_Connectors_Policy::is_entra_restricted(5), 'accounts never linked to Entra are unaffected');

setup_site();
Azure_AI_Connectors_Settings::save(array_merge(Azure_AI_Connectors_Settings::defaults(), array('entra_restricted_roles' => array('azuread', 'shop_manager'))));
$t->check(Azure_AI_Connectors_Policy::is_entra_restricted(2), 'admins can add other roles to the Entra rule');
$t->check(!in_array('pta_create_coupon', tools_for(2, $ALL), true), 'which then lose create tools');

// ─── Shop managers and pages/posts ───────────────────────────────────

setup_site();
AI_Test::login(2);
$t->check(current_user_can('delete_posts') && current_user_can('edit_pages'), 'the shop_manager role really holds editor capabilities');
$t->check(Azure_AI_Connectors_Policy::is_content_limited(2), 'a shop manager is limited to read and update on pages and posts');
$shop_tools = tools_for(2, $ALL);
$t->check(!in_array('pta_create_content', $shop_tools, true) && !in_array('pta_trash_content', $shop_tools, true), 'no create or trash for pages and posts');
$t->check(in_array('pta_create_coupon', $shop_tools, true) && in_array('pta_update_product', $shop_tools, true), 'store create and update tools are not affected');
$t->check(!Azure_AI_Connectors_Policy::is_entra_restricted(2), 'the shop manager rule is separate from the Entra rule');
$t->check(!Azure_AI_Connectors_Policy::is_content_limited(5) && !Azure_AI_Connectors_Policy::is_content_limited(4), 'other roles are not limited by it');

$t->equals($every, tools_for(7, $ALL), 'an administrator who is also a shop manager keeps full tools');

Azure_AI_Connectors_Settings::save(array_merge(Azure_AI_Connectors_Settings::defaults(), array('content_limited_roles' => array())));
$lifted = tools_for(2, $ALL);
$t->check(in_array('pta_create_content', $lifted, true) && in_array('pta_trash_content', $lifted, true), 'clearing the setting gives shop managers what their capabilities allow');

Azure_AI_Connectors_Settings::save(array_merge(Azure_AI_Connectors_Settings::defaults(), array('content_limited_roles' => array('shop_manager', 'newsletter_editor'))));
$t->check(!in_array('pta_create_content', tools_for(4, $ALL), true) && in_array('pta_update_content', tools_for(4, $ALL), true), 'admins can apply the rule to other roles');

Azure_AI_Connectors_Settings::save(array_merge(Azure_AI_Connectors_Settings::defaults(), array('entra_exempt_admins' => false)));
$shopadmin = tools_for(7, $ALL);
$t->check(!in_array('pta_create_content', $shopadmin, true) && in_array('pta_create_coupon', $shopadmin, true), 'with the administrator exemption off, the rule applies to an administrator who is also a shop manager');

// ─── Read-only connections ───────────────────────────────────────────

setup_site();
$read_only = tools_for(1, $READ);
$t->equals(
    sorted(array_merge(array('pta_whoami', 'pta_list_content', 'pta_get_content', 'pta_list_events', 'pta_list_modules'), $store_read)),
    $read_only,
    'a read-only connection only offers read tools, even to an administrator'
);

// ─── Registry rules for destructive tools ────────────────────────────

$trash = Azure_AI_Connectors_Registry::get('pta_trash_content');
$t->check(in_array('confirm', $trash['input_schema']['required'], true), 'destructive tools require a confirm argument');
$t->equals('boolean', $trash['input_schema']['properties']['confirm']['type'], 'confirm is a boolean');
$t->check(strpos($trash['description'], 'confirm=true') !== false, 'the description says confirm=true is needed');
$mcp_trash = Azure_AI_Connectors_Registry::to_mcp($trash);
$t->check($mcp_trash['annotations']['destructiveHint'] === true && $mcp_trash['annotations']['readOnlyHint'] === false, 'the trash tool is annotated destructive');
$confirm_admin = Azure_AI_Connectors_Registry::register('test_reset_thing', array(
    'kind'             => Azure_AI_Connectors_Policy::KIND_ADMIN,
    'requires_confirm' => true,
    'permission'       => array('manage_options'),
    'callback'         => function () { return array(); },
));
$t->check($confirm_admin === true && in_array('confirm', Azure_AI_Connectors_Registry::get('test_reset_thing')['input_schema']['required'], true), 'a non-destructive tool can opt in to confirm=true');
Azure_AI_Connectors_Registry::unregister('test_reset_thing');
$t->check(Azure_AI_Connectors_Registry::to_mcp(Azure_AI_Connectors_Registry::get('pta_list_events'))['annotations']['readOnlyHint'] === true, 'read tools are annotated read-only');

$removed = 0;
$r = Azure_AI_Connectors_Registry::register('test_remove_widget', array(
    'title'        => 'Remove a widget',
    'description'  => 'Removes a widget.',
    'kind'         => Azure_AI_Connectors_Policy::KIND_DESTRUCTIVE,
    'permission'   => array('read'),
    'input_schema' => array('type' => 'object', 'required' => array('id'), 'properties' => array('id' => array('type' => 'integer'))),
    'callback'     => function (array $args) use (&$removed) {
        $removed++;
        return array('removed' => $args['id']);
    },
));
$t->check($r === true, 'other modules can register tools');
$t->check(is_wp_error(Azure_AI_Connectors_Registry::register('Bad Name', array('callback' => 'strlen'))), 'tool names are validated');
$t->check(is_wp_error(Azure_AI_Connectors_Registry::register('test_bad_kind', array('callback' => 'strlen', 'kind' => 'nuke'))), 'unknown kinds are refused');

// ─── MCP layer ───────────────────────────────────────────────────────

function mcp($user_id, $method, array $params = array(), array $scopes = null, $id = 1) {
    $scopes = $scopes === null ? Azure_AI_Connectors_Policy::scopes() : $scopes;
    AI_Test::login($user_id);
    $token = (object) array('id' => 90 + $user_id, 'token_type' => 'pat', 'name' => 'test', 'scope' => implode(' ', $scopes));
    $msg = array('jsonrpc' => '2.0', 'method' => $method, 'params' => $params);
    if ($id !== null) {
        $msg['id'] = $id;
    }
    return Azure_AI_Connectors_MCP::handle_payload($msg, array(
        'user'         => wp_get_current_user(),
        'token'        => $token,
        'scopes'       => $scopes,
        'client_label' => 'Test client',
    ));
}

function call_tool($user_id, $name, array $args, array $scopes = null) {
    return mcp($user_id, 'tools/call', array('name' => $name, 'arguments' => $args), $scopes);
}

function audit_rows() {
    return $GLOBALS['wpdb']->get_results('SELECT * FROM wp_azure_ai_audit ORDER BY id');
}

$init = mcp(1, 'initialize', array('protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => array('name' => 't', 'version' => '1')));
$t->equals('2025-06-18', $init['result']['protocolVersion'], 'initialize keeps a supported protocol version');
$t->check(isset($init['result']['capabilities']['tools']), 'initialize advertises tools');
$t->equals('pta-tools', $init['result']['serverInfo']['name'], 'initialize names the server');
$t->check(strpos($init['result']['instructions'], 'confirm=true') !== false, 'server instructions explain confirm=true');
$init = mcp(1, 'initialize', array('protocolVersion' => '1999-01-01'));
$t->equals(Azure_AI_Connectors_MCP::PROTOCOL_VERSIONS[0], $init['result']['protocolVersion'], 'an unknown version gets the newest supported one');
$t->equals('2025-11-25', Azure_AI_Connectors_MCP::PROTOCOL_VERSIONS[0], 'the newest supported version is 2025-11-25');

$t->check(mcp(1, 'notifications/initialized', array(), null, null) === null, 'notifications get no response (HTTP 202)');
$t->check(mcp(1, 'ping')['result'] instanceof stdClass, 'ping answers with an empty result');
$t->equals(-32601, mcp(1, 'resources/read')['error']['code'], 'unknown methods are method-not-found');
$bad = Azure_AI_Connectors_MCP::handle_payload(array('method' => 'ping', 'id' => 1), array('scopes' => array()));
$t->equals(-32600, $bad['error']['code'], 'a message without jsonrpc 2.0 is an invalid request');
$batch = Azure_AI_Connectors_MCP::handle_payload(array(
    array('jsonrpc' => '2.0', 'id' => 'a', 'method' => 'ping'),
    array('jsonrpc' => '2.0', 'method' => 'notifications/initialized'),
), array('scopes' => array()));
$t->check(is_array($batch) && count($batch) === 1 && $batch[0]['id'] === 'a', 'a batch answers only its requests');

$listed = array_map(function ($tool) { return $tool['name']; }, mcp(3, 'tools/list')['result']['tools']);
sort($listed);
$t->equals(
    sorted(array('pta_whoami', 'pta_list_events', 'pta_list_content', 'pta_get_content', 'pta_update_content')),
    $listed,
    'tools/list for an Entra account lists only its read and update tools'
);
$listed_sub = array_map(function ($tool) { return $tool['name']; }, mcp(5, 'tools/list')['result']['tools']);
$t->check(!in_array('pta_trash_content', $listed_sub, true) && !in_array('pta_list_orders', $listed_sub, true), 'tools/list for a parent hides tools they cannot use');
$t->check(in_array('test_remove_widget', array_map(function ($tool) { return $tool['name']; }, mcp(1, 'tools/list')['result']['tools']), true), 'a registered tool is listed for users who may use it');

// confirm=true behaviour
$res = call_tool(1, 'test_remove_widget', array('id' => 7));
$t->check($res['result']['isError'] === true && strpos($res['result']['content'][0]['text'], 'confirm=true') !== false, 'a destructive call without confirm is refused and says to ask the user');
$t->equals(0, $removed, 'the destructive tool did not run without confirm');
$res = call_tool(1, 'test_remove_widget', array('id' => 7, 'confirm' => 'true'));
$t->check($res['result']['isError'] === true && $removed === 0, 'confirm must be the boolean true, not the string "true"');
$res = call_tool(1, 'test_remove_widget', array('id' => 7, 'confirm' => false));
$t->check($res['result']['isError'] === true && $removed === 0, 'confirm=false is refused');
$res = call_tool(1, 'test_remove_widget', array('id' => 7, 'confirm' => true));
$t->check($res['result']['isError'] === false && $removed === 1, 'with confirm=true the destructive tool runs');
$t->equals(array('removed' => 7), $res['result']['structuredContent'], 'the result is returned as structured content');
$t->check(strpos($res['result']['content'][0]['text'], '"removed": 7') !== false, 'and as JSON text');

$res = call_tool(3, 'test_remove_widget', array('id' => 8, 'confirm' => true));
$t->check($res['result']['isError'] === true && $removed === 1, 'an Entra account cannot run a destructive tool even with confirm=true and the capability');
$t->check(strpos($res['result']['content'][0]['text'], 'Microsoft Entra') !== false, 'and is told why');
$res = call_tool(3, 'pta_trash_content', array('id' => 12, 'confirm' => true));
$t->check($res['result']['isError'] === true && strpos($res['result']['content'][0]['text'], 'Microsoft Entra') !== false, 'an Entra account cannot trash content even with confirm=true');
$res = call_tool(1, 'test_remove_widget', array('id' => 9, 'confirm' => true), $READ);
$t->check($res['result']['isError'] === true && $removed === 1, 'a read-only connection cannot run a destructive tool');
$res = call_tool(5, 'pta_calendar_sync', array());
$t->check($res['result']['isError'] === true && strpos($res['result']['content'][0]['text'], 'permission') !== false, 'a parent calling an admin tool directly is refused');
$res = call_tool(2, 'pta_trash_content', array('id' => 12, 'confirm' => true));
$t->check($res['result']['isError'] === true && strpos($res['result']['content'][0]['text'], 'not create or trash') !== false, 'a shop manager cannot trash content even with confirm=true, and is told why');
$res = call_tool(2, 'pta_create_content', array('title' => 'x'));
$t->check($res['result']['isError'] === true && strpos($res['result']['content'][0]['text'], 'not create or trash') !== false, 'a shop manager cannot create content');
$shop_who = call_tool(2, 'pta_whoami', array())['result']['structuredContent'];
$t->check($shop_who['restrictions']['content_limited'] === true && $shop_who['restrictions']['entra_account'] === false, 'whoami reports the shop manager rule');
$t->equals(array('read', 'update'), $shop_who['restrictions']['content_allowed_kinds'], 'whoami lists what a shop manager may do with pages and posts');
$res = call_tool(1, 'pta_nope', array());
$t->equals(-32602, $res['error']['code'], 'an unknown tool is an invalid-params error');
$res = call_tool(1, 'test_remove_widget', array('id' => 'seven', 'confirm' => true));
$t->check($res['result']['isError'] === true && strpos($res['result']['content'][0]['text'], 'id must be integer') !== false && $removed === 1, 'arguments are validated against the schema');

$who = call_tool(3, 'pta_whoami', array());
$data = $who['result']['structuredContent'];
$t->equals(3, $data['user']['id'], 'whoami reports the connected account');
$t->check($data['restrictions']['entra_account'] === true, 'whoami says the account is an Entra account');
$t->equals(array('read', 'update'), $data['restrictions']['allowed_kinds'], 'whoami lists what an Entra account may do');
$t->equals('https://example.test/wp-json/pta-tools/v1/mcp', $data['site']['mcp_url'], 'whoami gives the MCP URL');

// ─── Audit log ───────────────────────────────────────────────────────

$rows = audit_rows();
$statuses = array_map(function ($r) { return $r->status; }, $rows);
$t->check(in_array('confirm_required', $statuses, true), 'calls missing confirm are audited');
$t->check(in_array('denied', $statuses, true), 'denied calls are audited');
$t->check(in_array('ok', $statuses, true), 'successful calls are audited');
$t->check(in_array('invalid', $statuses, true), 'invalid calls are audited');
$last_ok = null;
foreach ($rows as $row) {
    if ($row->status === 'ok' && $row->tool === 'test_remove_widget') {
        $last_ok = $row;
    }
}
$t->check($last_ok && (int) $last_ok->user_id === 1 && $last_ok->client_label === 'Test client' && (int) $last_ok->token_id === 91, 'an audit row records user, client and token');
$t->check($last_ok && strpos($last_ok->args_summary, '"id":7') !== false, 'an audit row records an argument summary');

$summary = Azure_AI_Connectors_Audit::summarize_args(array(
    'password'      => 'hunter2',
    'access_token'  => 'ptat_secret',
    'nested'        => array('client_secret' => 'xyz', 'ok' => 1),
    'content'       => str_repeat('<p>long text</p>', 50),
    'email'         => 'jane.doe@example.com',
    'title'         => str_repeat('a', 300),
));
$t->check(strpos($summary, 'hunter2') === false && strpos($summary, 'ptat_secret') === false && strpos($summary, 'xyz') === false, 'secrets never reach the audit log');
$t->check(strpos($summary, '[redacted]') !== false, 'secret keys are marked redacted');
$t->check(strpos($summary, 'long text') === false && strpos($summary, '[800 chars]') !== false, 'page content is reduced to its length');
$t->check(strpos($summary, 'jane.doe@') === false && strpos($summary, 'j***@example.com') !== false, 'email addresses are masked');
$t->check(strpos($summary, '[300 chars]') !== false, 'long strings are truncated');

$GLOBALS['wpdb']->query("UPDATE wp_azure_ai_audit SET created_at = '2001-01-01 00:00:00' WHERE id = 1");
$before = count(audit_rows());
Azure_AI_Connectors_Audit::purge(90);
$t->equals($before - 1, count(audit_rows()), 'retention purges entries older than the setting');
$page = Azure_AI_Connectors_Audit::query(array('status' => 'denied'), 1, 50);
$t->check($page['total'] > 0 && $page['rows'][0]->status === 'denied', 'the admin view can filter by result');

// ─── Argument validation ─────────────────────────────────────────────

$schema = array('type' => 'object', 'required' => array('n'), 'properties' => array(
    'n'    => array('type' => 'integer', 'minimum' => 1, 'maximum' => 10),
    'mode' => array('type' => 'string', 'enum' => array('a', 'b')),
    'ids'  => array('type' => 'array', 'items' => array('type' => 'integer'), 'maxItems' => 2),
));
$t->equals(array('n' => 5), Azure_AI_Connectors_Registry::validate_args($schema, array('n' => '5', 'extra' => 'dropped')), 'integers sent as strings are accepted and unknown arguments dropped');
$t->check(is_wp_error(Azure_AI_Connectors_Registry::validate_args($schema, array())), 'missing required arguments are refused');
$t->check(is_wp_error(Azure_AI_Connectors_Registry::validate_args($schema, array('n' => 11))), 'maximum is enforced');
$t->check(is_wp_error(Azure_AI_Connectors_Registry::validate_args($schema, array('n' => 1, 'mode' => 'c'))), 'enum is enforced');
$t->check(is_wp_error(Azure_AI_Connectors_Registry::validate_args($schema, array('n' => 1, 'ids' => array(1, 2, 3)))), 'maxItems is enforced');
$t->check(is_wp_error(Azure_AI_Connectors_Registry::validate_args($schema, array(1, 2))), 'a JSON array is not accepted as arguments');

// ─── Skill file ──────────────────────────────────────────────────────

setup_site();
AI_Test::login(5);
$skill = Azure_AI_Connectors_Skill::render(wp_get_current_user());
$t->check(strpos($skill, "---\nname: example-test-pta-tools\n") === 0, 'the skill file starts with front matter named after the site');
$t->check(strpos($skill, 'https://example.test/wp-json/pta-tools/v1/mcp') !== false, 'the skill file gives the MCP URL');
foreach (array('### Claude', '### ChatGPT', '### Gemini', '### Microsoft Copilot', '### Grok Bot', '### Muse and other MCP clients') as $heading) {
    $t->check(strpos($skill, $heading) !== false, 'the skill file explains ' . trim($heading, '# '));
}
foreach (array_keys(Azure_AI_Connectors_Registry::all()) as $name) {
    $t->check(strpos($skill, '`' . $name . '`') !== false, 'the skill file lists ' . $name);
}
$t->check(strpos($skill, '`pta_trash_content` (destructive, needs confirm=true; needs delete_posts or delete_pages, not an Entra account, not a shop manager) - not available to you') !== false, 'tools the user cannot use are marked for them, with the role rules');
preg_match('/^- `pta_update_content`[^\n]*$/m', $skill, $update_line);
$t->check(!empty($update_line) && strpos($update_line[0], 'shop manager') === false, 'update tools carry no shop manager note');
$t->check(strpos($skill, 'Shop managers can read and update pages and posts but not create or trash them') !== false, 'the skill rules explain the shop manager rule');
$t->check(strpos($skill, 'Each link works once, within 15 minutes') !== false, 'the skill rules say download links are single-use');
$t->check(strpos($skill, 'pta_set_module') === false, 'the skill file no longer mentions pta_set_module');
$t->check(strpos($skill, '`pta_whoami` (read; needs read):') !== false, 'tools the user can use are not marked');

exit($t->finish() > 0 ? 1 : 0);
