<?php
/**
 * PTA Tools > AI Connectors (site administrators): every connection,
 * audit log, registered apps, settings. Access is checked by
 * Azure_AI_Connectors_Admin::render_admin_page().
 */

if (!defined('ABSPATH')) {
    exit;
}

$settings_all = class_exists('Azure_Settings') ? Azure_Settings::get_all_settings() : array();
$enabled = !empty($settings_all['enable_ai_connectors']);
$tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'connections';
$tabs = array(
    'connections' => __('Connections', 'azure-plugin'),
    'audit'       => __('Audit log', 'azure-plugin'),
    'apps'        => __('Apps', 'azure-plugin'),
    'tools'       => __('Tools', 'azure-plugin'),
    'settings'    => __('Settings', 'azure-plugin'),
);
if (!isset($tabs[$tab])) {
    $tab = 'connections';
}
$s = Azure_AI_Connectors_Settings::get();
?>
<div class="wrap">
    <h1><?php esc_html_e('AI Connectors', 'azure-plugin'); ?></h1>
    <?php Azure_AI_Connectors_Admin::render_notice(); ?>

    <?php if (!$enabled) : ?>
        <div class="notice notice-info inline"><p><?php esc_html_e('The AI Connectors module is off. Turn it on from the PTA Tools dashboard.', 'azure-plugin'); ?>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin')); ?>"><?php esc_html_e('Open dashboard', 'azure-plugin'); ?></a></p></div>
    <?php endif; ?>

    <p>
        <?php esc_html_e('MCP server URL:', 'azure-plugin'); ?>
        <code><?php echo esc_html(Azure_AI_Connectors_Module::mcp_url()); ?></code>
        &middot; <a href="<?php echo esc_url(Azure_AI_Connectors_Module::user_page_url()); ?>"><?php esc_html_e('Set up your own connection', 'azure-plugin'); ?></a>
    </p>

    <nav class="nav-tab-wrapper">
        <?php foreach ($tabs as $key => $label) : ?>
            <a class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(Azure_AI_Connectors_Module::admin_page_url($key)); ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </nav>

<?php if ($tab === 'connections') :
    $rows = Azure_AI_Connectors_Tokens::list_connections(0);
    ?>
    <p class="description"><?php esc_html_e('Every active connected app and personal access token on the site.', 'azure-plugin'); ?></p>
    <table class="widefat striped">
        <thead><tr>
            <th><?php esc_html_e('User', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Name', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Type', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Access', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Created', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Last used', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Expires', 'azure-plugin'); ?></th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php if (empty($rows)) : ?>
            <tr><td colspan="8"><?php esc_html_e('No active connections.', 'azure-plugin'); ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row) :
            $owner = get_userdata((int) $row->user_id);
            ?>
            <tr>
                <td><?php echo $owner ? '<a href="' . esc_url(get_edit_user_link($owner->ID)) . '">' . esc_html($owner->display_name) . '</a><br><small>' . esc_html(implode(', ', (array) $owner->roles)) . '</small>' : esc_html('#' . (int) $row->user_id); ?></td>
                <td><?php echo esc_html($row->name); ?></td>
                <td><?php echo $row->token_type === Azure_AI_Connectors_Tokens::TYPE_PAT ? esc_html__('Personal token', 'azure-plugin') : esc_html__('Connected app', 'azure-plugin'); ?></td>
                <td><?php echo esc_html(Azure_AI_Connectors_Admin::scope_label($row->scope)); ?></td>
                <td><?php echo Azure_AI_Connectors_Admin::format_time($row->created_at); ?></td>
                <td><?php echo Azure_AI_Connectors_Admin::format_time($row->last_used_at); ?></td>
                <td><?php echo $row->expires_at ? Azure_AI_Connectors_Admin::format_time($row->expires_at) : esc_html__('Never', 'azure-plugin'); ?></td>
                <td><?php echo Azure_AI_Connectors_Admin::revoke_button($row, true); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

<?php elseif ($tab === 'audit') :
    $filters = array(
        'tool'    => isset($_GET['tool']) ? sanitize_key(wp_unslash($_GET['tool'])) : '',
        'status'  => isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '',
        'user_id' => isset($_GET['user_id']) ? absint($_GET['user_id']) : 0,
    );
    $paged = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
    $result = Azure_AI_Connectors_Audit::query($filters, $paged, 50);
    $pages = max(1, (int) ceil($result['total'] / 50));
    ?>
    <form method="get" style="margin:12px 0">
        <input type="hidden" name="page" value="<?php echo esc_attr(Azure_AI_Connectors_Module::ADMIN_PAGE); ?>">
        <input type="hidden" name="tab" value="audit">
        <select name="tool">
            <option value=""><?php esc_html_e('All tools', 'azure-plugin'); ?></option>
            <?php foreach (array_keys(Azure_AI_Connectors_Registry::all()) as $name) : ?>
                <option value="<?php echo esc_attr($name); ?>" <?php selected($filters['tool'], $name); ?>><?php echo esc_html($name); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value=""><?php esc_html_e('Any result', 'azure-plugin'); ?></option>
            <?php foreach (array('ok', 'error', 'denied', 'confirm_required', 'invalid') as $st) : ?>
                <option value="<?php echo esc_attr($st); ?>" <?php selected($filters['status'], $st); ?>><?php echo esc_html($st); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="number" min="0" name="user_id" class="small-text" placeholder="<?php esc_attr_e('User ID', 'azure-plugin'); ?>" value="<?php echo $filters['user_id'] ? (int) $filters['user_id'] : ''; ?>">
        <?php submit_button(__('Filter', 'azure-plugin'), 'secondary', '', false); ?>
    </form>
    <p class="description"><?php echo esc_html(sprintf(__('Entries are kept for %d days. Arguments are summarised; secrets and long text are never stored.', 'azure-plugin'), (int) $s['audit_retention_days'])); ?></p>
    <table class="widefat striped">
        <thead><tr>
            <th><?php esc_html_e('Time', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('User', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('App', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Tool', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Arguments', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Result', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('ms', 'azure-plugin'); ?></th>
        </tr></thead>
        <tbody>
        <?php if (empty($result['rows'])) : ?>
            <tr><td colspan="7"><?php esc_html_e('No tool calls recorded.', 'azure-plugin'); ?></td></tr>
        <?php endif; ?>
        <?php foreach ($result['rows'] as $row) :
            $who = get_userdata((int) $row->user_id);
            ?>
            <tr>
                <td><?php echo Azure_AI_Connectors_Admin::format_time($row->created_at); ?></td>
                <td><?php echo esc_html($who ? $who->display_name : '#' . (int) $row->user_id); ?></td>
                <td><?php echo esc_html($row->client_label); ?></td>
                <td><code><?php echo esc_html($row->tool); ?></code></td>
                <td><code style="white-space:pre-wrap;word-break:break-word"><?php echo esc_html($row->args_summary); ?></code></td>
                <td><?php echo esc_html($row->status); ?><?php if ($row->message !== '') : ?><br><small><?php echo esc_html($row->message); ?></small><?php endif; ?></td>
                <td><?php echo (int) $row->duration_ms; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($pages > 1) : ?>
        <div class="tablenav bottom"><div class="tablenav-pages"><?php
            echo paginate_links(array(
                'base'    => add_query_arg('paged', '%#%'),
                'format'  => '',
                'current' => $paged,
                'total'   => $pages,
            ));
        ?></div></div>
    <?php endif; ?>

<?php elseif ($tab === 'apps') :
    $clients = Azure_AI_Connectors_Tokens::list_clients();
    ?>
    <p class="description"><?php esc_html_e('Apps that registered themselves to start an OAuth sign-in. Registering grants nothing; each user still has to approve. Apps that never complete a sign-in are removed after 30 days.', 'azure-plugin'); ?></p>
    <table class="widefat striped">
        <thead><tr>
            <th><?php esc_html_e('Name (as the app reports it)', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Client ID', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Redirects to', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Registered', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Last used', 'azure-plugin'); ?></th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php if (empty($clients)) : ?>
            <tr><td colspan="6"><?php esc_html_e('No apps registered yet.', 'azure-plugin'); ?></td></tr>
        <?php endif; ?>
        <?php foreach ($clients as $client) :
            $hosts = array();
            foreach ((array) json_decode((string) $client->redirect_uris, true) as $uri) {
                $h = parse_url((string) $uri, PHP_URL_HOST);
                $hosts[] = $h ? $h : (string) parse_url((string) $uri, PHP_URL_SCHEME) . '://';
            }
            ?>
            <tr>
                <td><?php echo esc_html($client->client_name); ?></td>
                <td><code><?php echo esc_html($client->client_id); ?></code></td>
                <td><?php echo esc_html(implode(', ', array_unique($hosts))); ?></td>
                <td><?php echo Azure_AI_Connectors_Admin::format_time($client->created_at); ?></td>
                <td><?php echo Azure_AI_Connectors_Admin::format_time($client->last_used_at); ?></td>
                <td>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Remove this app and revoke every connection made through it?', 'azure-plugin')); ?>');">
                        <input type="hidden" name="action" value="pta_ai_delete_client">
                        <input type="hidden" name="client_id" value="<?php echo esc_attr($client->client_id); ?>">
                        <?php wp_nonce_field('pta_ai_delete_client_' . $client->client_id); ?>
                        <button type="submit" class="button button-link-delete"><?php esc_html_e('Remove', 'azure-plugin'); ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

<?php elseif ($tab === 'tools') : ?>
    <p class="description"><?php esc_html_e('Every tool on this site and what it needs. A user sees a tool only when their WordPress capabilities allow it; Entra accounts never get destructive or admin tools, and shop managers only read and update pages and posts.', 'azure-plugin'); ?></p>
    <table class="widefat striped">
        <thead><tr>
            <th><?php esc_html_e('Tool', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Kind', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Needs', 'azure-plugin'); ?></th>
            <th><?php esc_html_e('Description', 'azure-plugin'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach (Azure_AI_Connectors_Registry::all() as $name => $tool) : ?>
            <tr>
                <td><code><?php echo esc_html($name); ?></code></td>
                <td><?php echo esc_html($tool['kind']); ?></td>
                <td><?php echo esc_html(Azure_AI_Connectors_Skill::permission_text($tool)); ?></td>
                <td><?php echo esc_html($tool['description']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

<?php else :
    $roles = function_exists('wp_roles') ? wp_roles()->get_names() : array();
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="pta_ai_save_settings">
        <?php wp_nonce_field('pta_ai_settings'); ?>

        <h2><?php esc_html_e('Microsoft Entra accounts', 'azure-plugin'); ?></h2>
        <p class="description"><?php esc_html_e('Accounts created by Entra sign-in get the Azure AD User role, which carries editor capabilities plus manage_options. Through AI connections, these accounts can read and update only: delete, trash and admin tools are never offered, whatever their role allows.', 'azure-plugin'); ?></p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Treat these roles as Entra accounts', 'azure-plugin'); ?></th>
                <td>
                    <?php foreach ($roles as $slug => $label) : ?>
                        <label style="display:inline-block;min-width:200px"><input type="checkbox" name="entra_restricted_roles[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, (array) $s['entra_restricted_roles'], true)); ?>> <?php echo esc_html(translate_user_role($label)); ?></label>
                    <?php endforeach; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Also by sign-in', 'azure-plugin'); ?></th>
                <td><label><input type="checkbox" name="entra_restrict_sso_linked" value="1" <?php checked(!empty($s['entra_restrict_sso_linked'])); ?>> <?php esc_html_e('Anyone who has ever signed in with Entra SSO, whatever their role', 'azure-plugin'); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Administrators', 'azure-plugin'); ?></th>
                <td><label><input type="checkbox" name="entra_exempt_admins" value="1" <?php checked(!empty($s['entra_exempt_admins'])); ?>> <?php esc_html_e('Accounts with the Administrator role keep full tools, whatever other roles they have (applies to both rules on this page)', 'azure-plugin'); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Creating', 'azure-plugin'); ?></th>
                <td><label><input type="checkbox" name="entra_allow_create" value="1" <?php checked(!empty($s['entra_allow_create'])); ?>> <?php esc_html_e('Let Entra accounts create pages, posts and coupons (still never delete)', 'azure-plugin'); ?></label></td>
            </tr>
        </table>

        <h2><?php esc_html_e('Pages and posts', 'azure-plugin'); ?></h2>
        <p class="description"><?php esc_html_e('WooCommerce gives shop managers editor capabilities. Through AI connections, these roles can read and update pages and posts but not create or trash them. Their store tools are not affected.', 'azure-plugin'); ?></p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Read and update only', 'azure-plugin'); ?></th>
                <td>
                    <?php foreach ($roles as $slug => $label) : ?>
                        <label style="display:inline-block;min-width:200px"><input type="checkbox" name="content_limited_roles[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, (array) $s['content_limited_roles'], true)); ?>> <?php echo esc_html(translate_user_role($label)); ?></label>
                    <?php endforeach; ?>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Sign-in and tokens', 'azure-plugin'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('App registration', 'azure-plugin'); ?></th>
                <td><label><input type="checkbox" name="allow_dynamic_registration" value="1" <?php checked(!empty($s['allow_dynamic_registration'])); ?>> <?php esc_html_e('Let assistants register themselves (needed for Claude, ChatGPT and Copilot to connect with OAuth)', 'azure-plugin'); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><label for="pta-ai-access-ttl"><?php esc_html_e('Access tokens last', 'azure-plugin'); ?></label></th>
                <td><input type="number" id="pta-ai-access-ttl" name="access_token_minutes" min="5" max="1440" class="small-text" value="<?php echo (int) round($s['access_token_ttl'] / MINUTE_IN_SECONDS); ?>"> <?php esc_html_e('minutes', 'azure-plugin'); ?></td>
            </tr>
            <tr>
                <th scope="row"><label for="pta-ai-refresh-ttl"><?php esc_html_e('Stay connected for', 'azure-plugin'); ?></label></th>
                <td><input type="number" id="pta-ai-refresh-ttl" name="refresh_token_days" min="1" max="365" class="small-text" value="<?php echo (int) round($s['refresh_token_ttl'] / DAY_IN_SECONDS); ?>"> <?php esc_html_e('days without use before the user must sign in again', 'azure-plugin'); ?></td>
            </tr>
            <tr>
                <th scope="row"><label for="pta-ai-pat-max"><?php esc_html_e('Personal tokens expire within', 'azure-plugin'); ?></label></th>
                <td><input type="number" id="pta-ai-pat-max" name="pat_max_days" min="0" max="3650" class="small-text" value="<?php echo (int) $s['pat_max_days']; ?>"> <?php esc_html_e('days (0 allows tokens that never expire)', 'azure-plugin'); ?></td>
            </tr>
            <tr>
                <th scope="row"><label for="pta-ai-rate"><?php esc_html_e('Rate limit', 'azure-plugin'); ?></label></th>
                <td><input type="number" id="pta-ai-rate" name="rate_limit_per_minute" min="1" max="1000" class="small-text" value="<?php echo (int) $s['rate_limit_per_minute']; ?>"> <?php esc_html_e('requests a minute per connection', 'azure-plugin'); ?></td>
            </tr>
            <tr>
                <th scope="row"><label for="pta-ai-origins"><?php esc_html_e('Browser origins', 'azure-plugin'); ?></label></th>
                <td><textarea id="pta-ai-origins" name="allowed_origins" rows="3" class="large-text code" placeholder="https://example.com"><?php echo esc_textarea(implode("\n", (array) $s['allowed_origins'])); ?></textarea>
                    <p class="description"><?php esc_html_e('Only needed for MCP clients that run inside a web page. One origin per line. Assistants that connect from their own servers do not need an entry.', 'azure-plugin'); ?></p></td>
            </tr>
        </table>

        <h2><?php esc_html_e('Audit log', 'azure-plugin'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="pta-ai-retention"><?php esc_html_e('Keep entries for', 'azure-plugin'); ?></label></th>
                <td><input type="number" id="pta-ai-retention" name="audit_retention_days" min="1" max="3650" class="small-text" value="<?php echo (int) $s['audit_retention_days']; ?>"> <?php esc_html_e('days', 'azure-plugin'); ?></td>
            </tr>
        </table>
        <?php submit_button(__('Save settings', 'azure-plugin')); ?>
    </form>
<?php endif; ?>
</div>
