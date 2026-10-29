<?php
/**
 * AI Connectors skill file: a SKILL.md for this site that an assistant can
 * load to learn the MCP URL, how to connect, the rules, and the tools.
 *
 * Generated on demand from the live site, so it always carries this site's
 * name, URL and current tool list. When rendered for a user, each tool says
 * whether that user can use it.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AI_Connectors_Skill {

    const GROUP_LABELS = array(
        'site'     => 'Site',
        'content'  => 'Pages and posts',
        'calendar' => 'Calendar',
        'store'    => 'Store',
        'admin'    => 'Admin',
        'other'    => 'Other',
    );

    const KIND_LABELS = array(
        'read'        => 'read',
        'create'      => 'create',
        'update'      => 'update',
        'destructive' => 'destructive, needs confirm=true',
        'admin'       => 'admin',
    );

    public static function slug() {
        $host = (string) parse_url(home_url('/'), PHP_URL_HOST);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($host)), '-');
        return ($slug !== '' ? $slug : 'site') . '-pta-tools';
    }

    public static function filename() {
        return self::slug() . '-SKILL.md';
    }

    /**
     * @param WP_User|null $user Mark which tools this user can use.
     */
    public static function render($user = null) {
        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $host = (string) parse_url(home_url('/'), PHP_URL_HOST);
        $mcp = Azure_AI_Connectors_Module::mcp_url();
        $page = Azure_AI_Connectors_Module::user_page_url();

        $available = array();
        if ($user && !empty($user->ID)) {
            $available = Azure_AI_Connectors_Registry::available(Azure_AI_Connectors_Policy::scopes());
        }

        $md = array();
        $md[] = '---';
        $md[] = 'name: ' . self::slug();
        $md[] = 'description: Work on ' . self::yaml_safe($site) . ' (' . $host . ') through its PTA Tools MCP server - pages and posts, calendar events, the store (products, orders, coupons, report exports) and site admin - acting as the signed-in user with that user\'s WordPress permissions.';
        $md[] = '---';
        $md[] = '';
        $md[] = '# ' . $site . ' PTA Tools';
        $md[] = '';
        $md[] = 'Use this when the user wants to read or change things on ' . $host . ': pages, posts, calendar events, store products, orders, coupons or order reports.';
        $md[] = '';
        $md[] = '## Server';
        $md[] = '';
        $md[] = '- MCP server URL: `' . $mcp . '`';
        $md[] = '- Transport: Streamable HTTP (JSON-RPC 2.0), MCP protocol ' . implode(', ', Azure_AI_Connectors_MCP::PROTOCOL_VERSIONS) . '.';
        $md[] = '- Sign-in: OAuth 2.1 with PKCE. Clients that support MCP authorization discover everything from the URL; the user signs in to ' . $host . ' (including Microsoft sign-in) and approves the connection.';
        $md[] = '- Fallback: a personal access token from ' . $page . ', sent as `Authorization: Bearer <token>`.';
        $md[] = '';
        $md[] = '## Rules';
        $md[] = '';
        $md[] = '1. Every tool acts as the connected WordPress user and is limited to what that user may do. Call `pta_whoami` first to see the account, its roles and the tools it can use.';
        $md[] = '2. Tools marked destructive need `confirm: true`. Describe exactly what will change, wait for the user to agree in the conversation, then call again with `confirm: true`. Never set it on your own.';
        $md[] = '3. Deleting moves pages and posts to the trash. Nothing is permanently deleted.';
        $md[] = '4. Accounts that sign in with Microsoft Entra can read and update but never delete or use admin tools. Shop managers can read and update pages and posts but not create or trash them; their store tools are unaffected. A read-only connection only has read tools.';
        $md[] = '5. Order and customer data is personal. Only show it to the user who asked, and do not repeat download links anywhere else. Each link works once, within 15 minutes; export again if the user needs another copy.';
        $md[] = '6. Never ask the user for their password. If a call fails with an authorization error, ask them to reconnect.';
        $md[] = '';
        $md[] = '## Connect your assistant';
        $md[] = '';
        $md[] = 'Menu names change between versions; look for "connectors", "custom connector", "MCP server" or "tools".';
        $md[] = '';
        $md[] = '### Claude';
        $md[] = '';
        $md[] = '- Claude (web or desktop): Settings > Connectors > Add custom connector. Paste `' . $mcp . '`, add it, click Connect, sign in to ' . $host . ' and choose Allow.';
        $md[] = '- Claude Code: `claude mcp add --transport http pta-tools ' . $mcp . '`, then run `/mcp` and authenticate.';
        $md[] = '';
        $md[] = '### ChatGPT';
        $md[] = '';
        $md[] = 'Settings > Apps & Connectors (or Connectors) > Advanced: turn on Developer mode. Then Create, give it a name, paste `' . $mcp . '` as the MCP server URL, choose OAuth, and sign in when asked. Availability depends on your ChatGPT plan.';
        $md[] = '';
        $md[] = '### Gemini';
        $md[] = '';
        $md[] = 'Gemini CLI: add to `~/.gemini/settings.json`, then run `/mcp auth pta-tools` to sign in:';
        $md[] = '';
        $md[] = '```json';
        $md[] = '{ "mcpServers": { "pta-tools": { "httpUrl": "' . $mcp . '" } } }';
        $md[] = '```';
        $md[] = '';
        $md[] = 'If your Gemini app has no way to add an MCP server, use Gemini CLI or give Gemini this file for reference.';
        $md[] = '';
        $md[] = '### Microsoft Copilot';
        $md[] = '';
        $md[] = 'Copilot Studio: open your agent > Tools > Add a tool > New tool > Model Context Protocol. Server URL `' . $mcp . '`. Authentication: OAuth 2.0 with dynamic discovery, or API key with a personal access token sent in the `Authorization` header as `Bearer <token>`.';
        $md[] = '';
        $md[] = '### Grok Bot';
        $md[] = '';
        $md[] = 'Add a remote MCP server with URL `' . $mcp . '`. If it cannot run the OAuth sign-in, create a personal access token and send it as the `Authorization: Bearer <token>` header. Through the xAI API, pass the same URL and header as a remote MCP tool.';
        $md[] = '';
        $md[] = '### Muse and other MCP clients';
        $md[] = '';
        $md[] = 'Any client that supports remote (Streamable HTTP) MCP servers: use the URL and let it sign in with OAuth, or send a personal access token:';
        $md[] = '';
        $md[] = '```json';
        $md[] = '{ "mcpServers": { "pta-tools": { "type": "http", "url": "' . $mcp . '", "headers": { "Authorization": "Bearer YOUR_TOKEN" } } } }';
        $md[] = '```';
        $md[] = '';
        $md[] = 'Clients that only speak stdio can bridge with `npx mcp-remote ' . $mcp . ' --header "Authorization: Bearer ${PTA_TOKEN}"`.';
        $md[] = '';
        $md[] = '## Tools';
        $md[] = '';
        if ($user && !empty($user->ID)) {
            $md[] = 'Generated for ' . $user->display_name . '. Tools marked "not available to you" need permissions this account does not have.';
            $md[] = '';
        }

        $by_group = array();
        foreach (Azure_AI_Connectors_Registry::all() as $name => $tool) {
            $by_group[$tool['group']][$name] = $tool;
        }
        foreach (self::GROUP_LABELS as $group => $label) {
            if (empty($by_group[$group])) {
                continue;
            }
            $md[] = '### ' . $label;
            $md[] = '';
            foreach ($by_group[$group] as $name => $tool) {
                $line = '- `' . $name . '` (' . (self::KIND_LABELS[$tool['kind']] ?? $tool['kind']) . '; needs ' . self::permission_text($tool) . ')';
                if ($user && !empty($user->ID) && !isset($available[$name])) {
                    $line .= ' - not available to you';
                }
                $md[] = $line . ': ' . $tool['description'];
            }
            $md[] = '';
        }

        return implode("\n", $md) . "\n";
    }

    public static function permission_text(array $tool) {
        $caps = isset($tool['permission']) ? (array) $tool['permission'] : array('read');
        $text = implode(' or ', $caps);
        $kind = $tool['kind'];
        if (!empty($tool['permission_callback']) || in_array($kind, array(Azure_AI_Connectors_Policy::KIND_DESTRUCTIVE, Azure_AI_Connectors_Policy::KIND_ADMIN), true)) {
            $text .= ', not an Entra account';
        } elseif ($kind === Azure_AI_Connectors_Policy::KIND_CREATE) {
            $text .= ', not an Entra account unless an admin allows it';
        }
        if (($tool['group'] ?? '') === Azure_AI_Connectors_Policy::GROUP_CONTENT
            && !in_array($tool['kind'], Azure_AI_Connectors_Policy::content_limited_kinds(), true)) {
            $text .= ', not a shop manager';
        }
        if (Azure_AI_Connectors_Policy::requires_confirm($tool) && $tool['kind'] !== Azure_AI_Connectors_Policy::KIND_DESTRUCTIVE) {
            $text .= ', confirm=true';
        }
        return $text;
    }

    private static function yaml_safe($text) {
        return str_replace(array("\n", ':', '"'), array(' ', ' -', "'"), (string) $text);
    }
}
