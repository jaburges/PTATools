<?php
/**
 * Users > AI Connectors: connect an assistant, download the skill file,
 * see and revoke your connections, create personal access tokens.
 *
 * @var string $new_token Shown once, straight after creation.
 */

if (!defined('ABSPATH')) {
    exit;
}

$user = wp_get_current_user();
$mcp_url = Azure_AI_Connectors_Module::mcp_url();
$connections = Azure_AI_Connectors_Tokens::list_connections((int) $user->ID);
$tools = Azure_AI_Connectors_Registry::available(Azure_AI_Connectors_Policy::scopes());
$restricted = Azure_AI_Connectors_Policy::is_entra_restricted($user);
$content_limited = Azure_AI_Connectors_Policy::is_content_limited($user);
$max_days = (int) Azure_AI_Connectors_Settings::value('pat_max_days');
$skill_url = wp_nonce_url(admin_url('admin-post.php?action=pta_ai_skill'), 'pta_ai_skill');
$secure = Azure_AI_Connectors_Module::is_secure_request();
?>
<div class="wrap">
    <h1><?php esc_html_e('AI Connectors', 'azure-plugin'); ?></h1>
    <?php Azure_AI_Connectors_Admin::render_notice(); ?>

    <?php if ($new_token !== '') : ?>
        <div class="notice notice-success">
            <p><strong><?php esc_html_e('Your new token. Copy it now: it will not be shown again.', 'azure-plugin'); ?></strong></p>
            <p><input type="text" class="large-text code" readonly value="<?php echo esc_attr($new_token); ?>" onclick="this.select();" aria-label="<?php esc_attr_e('New personal access token', 'azure-plugin'); ?>"></p>
            <p class="description"><?php esc_html_e('Send it as the header Authorization: Bearer <token>. Treat it like a password.', 'azure-plugin'); ?></p>
        </div>
    <?php endif; ?>

    <?php if (!$secure) : ?>
        <div class="notice notice-warning inline"><p><?php esc_html_e('This page is not on HTTPS, so connections and tokens cannot be created here.', 'azure-plugin'); ?></p></div>
    <?php endif; ?>

    <p><?php esc_html_e('Connect an AI assistant to this site. It acts as you, with exactly your permissions, and everything it does is recorded.', 'azure-plugin'); ?></p>
    <?php if ($restricted) : ?>
        <p class="description"><?php esc_html_e('Your account signs in with Microsoft Entra, so assistants can read and update for you but never delete or use admin tools.', 'azure-plugin'); ?></p>
    <?php endif; ?>
    <?php if ($content_limited) : ?>
        <p class="description"><?php esc_html_e('With your role, assistants can read and update pages and posts but not create or trash them.', 'azure-plugin'); ?></p>
    <?php endif; ?>

    <h2><?php esc_html_e('1. Add the server to your assistant', 'azure-plugin'); ?></h2>
    <p>
        <label for="pta-ai-mcp-url"><strong><?php esc_html_e('MCP server URL', 'azure-plugin'); ?></strong></label><br>
        <input type="text" id="pta-ai-mcp-url" class="large-text code" readonly value="<?php echo esc_attr($mcp_url); ?>" onclick="this.select();">
    </p>
    <table class="widefat striped" style="max-width:960px">
        <tbody>
            <tr><th scope="row"><?php esc_html_e('Claude', 'azure-plugin'); ?></th>
                <td><?php esc_html_e('Settings > Connectors > Add custom connector. Paste the URL, then Connect and sign in.', 'azure-plugin'); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('ChatGPT', 'azure-plugin'); ?></th>
                <td><?php esc_html_e('Settings > Apps & Connectors > Advanced: turn on Developer mode, then Create. Paste the URL and choose OAuth.', 'azure-plugin'); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('Microsoft Copilot', 'azure-plugin'); ?></th>
                <td><?php esc_html_e('Copilot Studio > your agent > Tools > Add a tool > Model Context Protocol. Paste the URL and choose OAuth 2.0 (dynamic discovery).', 'azure-plugin'); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('Gemini', 'azure-plugin'); ?></th>
                <td><?php esc_html_e('Gemini CLI: add the URL as an httpUrl MCP server in settings.json, then run /mcp auth. The skill file has the exact snippet.', 'azure-plugin'); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('Grok Bot, Muse and other MCP clients', 'azure-plugin'); ?></th>
                <td><?php esc_html_e('Add a remote (Streamable HTTP) MCP server with the URL. If it cannot sign in with OAuth, create a personal access token below and send it as the Authorization header.', 'azure-plugin'); ?></td></tr>
        </tbody>
    </table>

    <h2><?php esc_html_e('2. Sign in and approve', 'azure-plugin'); ?></h2>
    <p><?php esc_html_e('Your assistant opens this site\'s sign-in page (Microsoft sign-in works too) and then asks you to approve. You can untick "Allow changes" for a read-only connection.', 'azure-plugin'); ?></p>

    <h2><?php esc_html_e('Skill file', 'azure-plugin'); ?></h2>
    <p><?php esc_html_e('A SKILL.md describing this site\'s server, how to connect each assistant, the rules, and every tool (marked with the ones you can use). Give it to assistants that support skills or project instructions.', 'azure-plugin'); ?></p>
    <p><a class="button" href="<?php echo esc_url($skill_url); ?>"><?php esc_html_e('Download skill file', 'azure-plugin'); ?></a></p>

    <h2><?php esc_html_e('Your connections', 'azure-plugin'); ?></h2>
    <table class="widefat striped" style="max-width:960px">
        <thead><tr>
            <th><?php esc_html_e('Name', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Type', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Access', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Created', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Last used', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Expires', 'azure-plugin'); ?></th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php if (empty($connections)) : ?>
            <tr><td colspan="7"><?php esc_html_e('No assistants are connected.', 'azure-plugin'); ?></td></tr>
        <?php endif; ?>
        <?php foreach ($connections as $row) : ?>
            <tr>
                <td><?php echo esc_html($row->name); ?><?php if ($row->token_hint !== '') : ?> <code><?php echo esc_html($row->token_hint); ?></code><?php endif; ?></td>
                <td><?php echo $row->token_type === Azure_AI_Connectors_Tokens::TYPE_PAT ? esc_html__('Personal token', 'azure-plugin') : esc_html__('Connected app', 'azure-plugin'); ?></td>
                <td><?php echo esc_html(Azure_AI_Connectors_Admin::scope_label($row->scope)); ?></td>
                <td><?php echo Azure_AI_Connectors_Admin::format_time($row->created_at); ?></td>
                <td><?php echo Azure_AI_Connectors_Admin::format_time($row->last_used_at); ?></td>
                <td><?php echo $row->expires_at ? Azure_AI_Connectors_Admin::format_time($row->expires_at) : esc_html__('Never', 'azure-plugin'); ?></td>
                <td><?php echo Azure_AI_Connectors_Admin::revoke_button($row); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2><?php esc_html_e('Create a personal access token', 'azure-plugin'); ?></h2>
    <p><?php esc_html_e('For assistants that cannot sign in with OAuth. The token is shown once and stored only as a hash.', 'azure-plugin'); ?></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="pta_ai_create_pat">
        <?php wp_nonce_field('pta_ai_create_pat'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="pta-ai-token-name"><?php esc_html_e('Name', 'azure-plugin'); ?></label></th>
                <td><input type="text" id="pta-ai-token-name" name="token_name" class="regular-text" maxlength="100" required placeholder="<?php esc_attr_e('e.g. Grok on my laptop', 'azure-plugin'); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="pta-ai-token-expiry"><?php esc_html_e('Expires after', 'azure-plugin'); ?></label></th>
                <td><select id="pta-ai-token-expiry" name="expires_days">
                    <?php foreach (array(7, 30, 90, 365) as $days) : ?>
                        <?php if ($max_days === 0 || $days <= $max_days) : ?>
                            <option value="<?php echo (int) $days; ?>" <?php selected($days, 30); ?>><?php echo esc_html(sprintf(_n('%d day', '%d days', $days, 'azure-plugin'), $days)); ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($max_days === 0) : ?>
                        <option value="0"><?php esc_html_e('Never', 'azure-plugin'); ?></option>
                    <?php endif; ?>
                </select></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Access', 'azure-plugin'); ?></th>
                <td><label><input type="checkbox" name="read_only" value="1"> <?php esc_html_e('Read only', 'azure-plugin'); ?></label></td>
            </tr>
        </table>
        <?php submit_button(__('Create token', 'azure-plugin'), 'secondary', 'submit', true, $secure ? array() : array('disabled' => 'disabled')); ?>
    </form>

    <h2><?php esc_html_e('What assistants can do for you', 'azure-plugin'); ?></h2>
    <?php if (empty($tools)) : ?>
        <p><?php esc_html_e('Your account has no tools available.', 'azure-plugin'); ?></p>
    <?php else : ?>
        <ul class="ul-disc">
            <?php foreach ($tools as $tool) : ?>
                <li><strong><?php echo esc_html($tool['title']); ?></strong>
                    <?php if (Azure_AI_Connectors_Policy::requires_confirm($tool)) : ?>
                        <em><?php esc_html_e('(asks you to confirm first)', 'azure-plugin'); ?></em>
                    <?php endif; ?>
                    &mdash; <?php echo esc_html($tool['description']); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
