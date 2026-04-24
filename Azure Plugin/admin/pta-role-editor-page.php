<?php
/**
 * PTA Role Editor - Visual editor for WordPress role capabilities.
 *
 * Lets admins pick any WP role (including custom roles synced from Azure AD)
 * and toggle its capabilities grouped by functional area.
 */
if (!defined('ABSPATH')) {
    exit;
}

$wp_roles = wp_roles();
$all_roles = $wp_roles->roles;

// Default to "azuread" if present (the custom role SSO creates), otherwise first non-admin role.
$default_role = '';
if (isset($all_roles['azuread'])) {
    $default_role = 'azuread';
} else {
    foreach (array_keys($all_roles) as $slug) {
        if ($slug !== 'administrator') {
            $default_role = $slug;
            break;
        }
    }
    if (empty($default_role)) {
        $default_role = 'administrator';
    }
}

// Determine currently selected role (?role= in query).
$selected_role = isset($_GET['role']) ? sanitize_key($_GET['role']) : $default_role;
if (!isset($all_roles[$selected_role])) {
    $selected_role = $default_role;
}

$selected_role_obj = get_role($selected_role);
$selected_caps = $selected_role_obj ? $selected_role_obj->capabilities : array();

// Build the canonical list of caps: union of all caps defined on any role.
// Administrator typically has everything so this catches WP core + active plugin caps.
$all_known_caps = array();
foreach ($all_roles as $role_data) {
    foreach (array_keys($role_data['capabilities']) as $cap) {
        $all_known_caps[$cap] = true;
    }
}
$all_known_caps = array_keys($all_known_caps);
sort($all_known_caps);

// Group caps by functional area. Anything that doesn't match a known group
// falls into "Other / Custom".
$cap_groups = array(
    'core'       => array('label' => 'Core & Administration', 'icon' => 'admin-settings', 'caps' => array()),
    'users'      => array('label' => 'Users & Profiles',      'icon' => 'admin-users',    'caps' => array()),
    'posts'      => array('label' => 'Posts',                 'icon' => 'admin-post',     'caps' => array()),
    'pages'      => array('label' => 'Pages',                 'icon' => 'admin-page',     'caps' => array()),
    'media'      => array('label' => 'Media & Files',         'icon' => 'admin-media',    'caps' => array()),
    'comments'   => array('label' => 'Comments',              'icon' => 'admin-comments', 'caps' => array()),
    'taxonomy'   => array('label' => 'Taxonomies & Links',    'icon' => 'category',       'caps' => array()),
    'themes'     => array('label' => 'Themes & Appearance',   'icon' => 'admin-appearance', 'caps' => array()),
    'plugins'    => array('label' => 'Plugins',               'icon' => 'admin-plugins',  'caps' => array()),
    'tools'      => array('label' => 'Tools & Import/Export', 'icon' => 'admin-tools',    'caps' => array()),
    'woocommerce'=> array('label' => 'WooCommerce',           'icon' => 'cart',           'caps' => array()),
    'tec'        => array('label' => 'The Events Calendar',   'icon' => 'calendar-alt',   'caps' => array()),
    'azure'      => array('label' => 'PTA Tools / Azure',     'icon' => 'cloud',          'caps' => array()),
    'other'      => array('label' => 'Other / Custom',        'icon' => 'admin-generic',  'caps' => array()),
);

// Friendly labels for common WP core caps. Caps not in this list are shown verbatim.
$cap_labels = array(
    // Core
    'manage_options'          => 'Manage all site settings',
    'manage_network'          => 'Manage multisite network',
    'manage_sites'            => 'Manage network sites',
    'manage_network_options'  => 'Manage network options',
    'manage_network_plugins'  => 'Manage network plugins',
    'manage_network_themes'   => 'Manage network themes',
    'manage_network_users'    => 'Manage network users',
    'create_sites'            => 'Create network sites',
    'delete_sites'            => 'Delete network sites',
    'upgrade_network'         => 'Upgrade network',
    'setup_network'           => 'Setup network',
    'update_core'             => 'Update WordPress core',
    'edit_dashboard'          => 'Edit dashboard',
    'read'                    => 'Read site content',
    'level_0'                 => 'User level 0 (legacy)',
    'level_1'                 => 'User level 1 (legacy)',
    'level_2'                 => 'User level 2 (legacy)',
    'level_3'                 => 'User level 3 (legacy)',
    'level_4'                 => 'User level 4 (legacy)',
    'level_5'                 => 'User level 5 (legacy)',
    'level_6'                 => 'User level 6 (legacy)',
    'level_7'                 => 'User level 7 (legacy)',
    'level_8'                 => 'User level 8 (legacy)',
    'level_9'                 => 'User level 9 (legacy)',
    'level_10'                => 'User level 10 (legacy)',

    // Users
    'create_users'            => 'Create new users',
    'delete_users'            => 'Delete users',
    'edit_users'              => 'Edit any user',
    'list_users'              => 'List users',
    'promote_users'           => 'Promote users (change roles)',
    'remove_users'            => 'Remove users from site',
    'add_users'               => 'Add existing users (multisite)',

    // Posts
    'edit_posts'              => 'Edit own posts',
    'edit_others_posts'       => 'Edit others\' posts',
    'edit_published_posts'    => 'Edit published posts',
    'edit_private_posts'      => 'Edit private posts',
    'delete_posts'            => 'Delete own posts',
    'delete_others_posts'     => 'Delete others\' posts',
    'delete_published_posts'  => 'Delete published posts',
    'delete_private_posts'    => 'Delete private posts',
    'publish_posts'           => 'Publish posts',
    'read_private_posts'      => 'Read private posts',

    // Pages
    'edit_pages'              => 'Edit own pages',
    'edit_others_pages'       => 'Edit others\' pages',
    'edit_published_pages'    => 'Edit published pages',
    'edit_private_pages'      => 'Edit private pages',
    'delete_pages'            => 'Delete own pages',
    'delete_others_pages'     => 'Delete others\' pages',
    'delete_published_pages'  => 'Delete published pages',
    'delete_private_pages'    => 'Delete private pages',
    'publish_pages'           => 'Publish pages',
    'read_private_pages'      => 'Read private pages',

    // Media
    'upload_files'            => 'Upload files',
    'unfiltered_upload'       => 'Upload unfiltered files (dangerous)',
    'unfiltered_html'         => 'Post unfiltered HTML (dangerous)',

    // Comments
    'moderate_comments'       => 'Moderate comments',
    'edit_comment'            => 'Edit comments',

    // Taxonomy
    'manage_categories'       => 'Manage categories',
    'manage_links'            => 'Manage links',

    // Themes
    'switch_themes'           => 'Switch themes',
    'edit_theme_options'      => 'Edit theme options (Customizer, menus, widgets)',
    'edit_themes'             => 'Edit theme files',
    'install_themes'          => 'Install themes',
    'delete_themes'           => 'Delete themes',
    'update_themes'           => 'Update themes',
    'customize'               => 'Access Customizer',

    // Plugins
    'activate_plugins'        => 'Activate/deactivate plugins',
    'edit_plugins'            => 'Edit plugin files',
    'install_plugins'         => 'Install plugins',
    'delete_plugins'          => 'Delete plugins',
    'update_plugins'          => 'Update plugins',

    // Tools
    'import'                  => 'Import content',
    'export'                  => 'Export content',
    'edit_files'              => 'Edit files in the admin',
);

/**
 * Decide which group a capability belongs to.
 */
$classify_cap = function($cap) {
    static $core = array(
        'manage_options', 'manage_network', 'manage_sites', 'manage_network_options',
        'manage_network_plugins', 'manage_network_themes', 'manage_network_users',
        'create_sites', 'delete_sites', 'upgrade_network', 'setup_network',
        'update_core', 'edit_dashboard', 'read',
        'level_0','level_1','level_2','level_3','level_4','level_5',
        'level_6','level_7','level_8','level_9','level_10',
    );
    static $users = array(
        'create_users','delete_users','edit_users','list_users','promote_users','remove_users','add_users',
    );
    static $posts = array(
        'edit_posts','edit_others_posts','edit_published_posts','edit_private_posts',
        'delete_posts','delete_others_posts','delete_published_posts','delete_private_posts',
        'publish_posts','read_private_posts',
    );
    static $pages = array(
        'edit_pages','edit_others_pages','edit_published_pages','edit_private_pages',
        'delete_pages','delete_others_pages','delete_published_pages','delete_private_pages',
        'publish_pages','read_private_pages',
    );
    static $media = array('upload_files','unfiltered_upload','unfiltered_html');
    static $comments = array('moderate_comments','edit_comment');
    static $taxonomy = array('manage_categories','manage_links');
    static $themes = array('switch_themes','edit_theme_options','edit_themes','install_themes','delete_themes','update_themes','customize');
    static $plugins = array('activate_plugins','edit_plugins','install_plugins','delete_plugins','update_plugins');
    static $tools = array('import','export','edit_files');

    if (in_array($cap, $core, true))     return 'core';
    if (in_array($cap, $users, true))    return 'users';
    if (in_array($cap, $posts, true))    return 'posts';
    if (in_array($cap, $pages, true))    return 'pages';
    if (in_array($cap, $media, true))    return 'media';
    if (in_array($cap, $comments, true)) return 'comments';
    if (in_array($cap, $taxonomy, true)) return 'taxonomy';
    if (in_array($cap, $themes, true))   return 'themes';
    if (in_array($cap, $plugins, true))  return 'plugins';
    if (in_array($cap, $tools, true))    return 'tools';

    // Heuristics for plugin caps.
    if (preg_match('/(woocommerce|shop_order|product|shop_coupon|shop_webhook)/i', $cap)) return 'woocommerce';
    if (preg_match('/(tribe|tec|event-ticket|events_)/i', $cap))                          return 'tec';
    if (preg_match('/(azure|pta|forminator|beaver|fl_builder|fluentcrm|mailpoet)/i', $cap)) return 'azure';

    return 'other';
};

foreach ($all_known_caps as $cap) {
    $group_key = $classify_cap($cap);
    $cap_groups[$group_key]['caps'][] = $cap;
}

// Remove empty groups so the UI only shows what's relevant.
$cap_groups = array_filter($cap_groups, function($g) { return !empty($g['caps']); });

// Stats.
$enabled_count = 0;
foreach ($selected_caps as $enabled) {
    if (!empty($enabled)) $enabled_count++;
}
$total_caps = count($all_known_caps);

// Protected roles (cannot be edited via this UI).
$protected_roles = array('administrator');

// Azure-AD-synced roles have azure_ad_user cap — surface that visually.
$is_azure_role = !empty($selected_caps['azure_ad_user']);
?>

<div class="wrap azure-role-editor">
    <h1><span class="dashicons dashicons-admin-users"></span> PTA Tools - Role Editor</h1>
    <p class="description">Select any WordPress role (including roles synced from Azure AD) and visually edit its capabilities.</p>

    <?php if (!Azure_Settings::is_module_enabled('pta')): ?>
    <div class="notice notice-warning inline">
        <p><strong>PTA module is disabled.</strong> Enable it from the <a href="<?php echo admin_url('admin.php?page=azure-plugin'); ?>">main settings</a> to use this feature.</p>
    </div>
    <?php endif; ?>

    <!-- Role selector -->
    <div class="role-selector-card">
        <form method="get" action="">
            <input type="hidden" name="page" value="azure-plugin-pta-role-editor">
            <label for="azure-role-select"><strong>Select role to edit:</strong></label>
            <select id="azure-role-select" name="role" onchange="this.form.submit()">
                <?php foreach ($all_roles as $slug => $data): ?>
                    <?php
                    $is_azure = !empty($data['capabilities']['azure_ad_user']);
                    $label = translate_user_role($data['name']);
                    if ($is_azure) { $label .= ' — Azure AD'; }
                    ?>
                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($selected_role, $slug); ?>>
                        <?php echo esc_html($label); ?> (<?php echo esc_html($slug); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="button">Load</button></noscript>
        </form>

        <div class="role-stats">
            <span class="stat-chip"><strong><?php echo (int) $enabled_count; ?></strong> enabled</span>
            <span class="stat-chip stat-chip-muted"><strong><?php echo (int) $total_caps; ?></strong> total capabilities</span>
            <?php if ($is_azure_role): ?>
                <span class="stat-chip stat-chip-info"><span class="dashicons dashicons-cloud"></span> Azure AD role</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (in_array($selected_role, $protected_roles, true)): ?>
    <div class="notice notice-error inline">
        <p>
            <strong>The <?php echo esc_html($selected_role_obj->name); ?> role is protected.</strong>
            Editing administrator capabilities can lock you out of the site. To change admin capabilities,
            do it programmatically or select a different role here.
        </p>
    </div>
    <?php endif; ?>

    <!-- Editor -->
    <form id="azure-role-editor-form" onsubmit="return false;">
        <input type="hidden" id="azure-role-editor-role" value="<?php echo esc_attr($selected_role); ?>">

        <!-- Toolbar -->
        <div class="role-editor-toolbar">
            <div class="toolbar-left">
                <button type="button" class="button" id="role-editor-select-all">Select all visible</button>
                <button type="button" class="button" id="role-editor-clear">Clear all</button>
                <button type="button" class="button" id="role-editor-copy-from">Copy from...</button>
                <select id="role-editor-copy-source" style="display:none;">
                    <?php foreach ($all_roles as $slug => $data): ?>
                        <option value="<?php echo esc_attr($slug); ?>" <?php disabled($slug, $selected_role); ?>>
                            <?php echo esc_html(translate_user_role($data['name'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="button" id="role-editor-reset" title="Discard unsaved changes and reload from the database">Reset</button>
            </div>
            <div class="toolbar-right">
                <input type="search" id="role-editor-filter" placeholder="Filter capabilities..." class="regular-text">
                <button type="button" class="button button-primary" id="role-editor-save"
                    <?php disabled(in_array($selected_role, $protected_roles, true)); ?>>
                    <span class="dashicons dashicons-saved"></span> Save Changes
                </button>
            </div>
        </div>

        <div id="role-editor-notice" class="role-editor-notice" style="display:none;"></div>

        <!-- Capability groups -->
        <div class="role-editor-groups">
        <?php foreach ($cap_groups as $group_key => $group): ?>
            <?php
            $group_enabled = 0;
            foreach ($group['caps'] as $c) {
                if (!empty($selected_caps[$c])) $group_enabled++;
            }
            $group_total = count($group['caps']);
            $is_dangerous = in_array($group_key, array('plugins','themes','tools'), true) || $group_key === 'core';
            ?>
            <details class="cap-group" data-group="<?php echo esc_attr($group_key); ?>" open>
                <summary class="cap-group-header">
                    <span class="cap-group-title">
                        <span class="dashicons dashicons-<?php echo esc_attr($group['icon']); ?>"></span>
                        <?php echo esc_html($group['label']); ?>
                        <?php if ($is_dangerous): ?>
                            <span class="cap-group-warn" title="These capabilities have site-wide impact">!</span>
                        <?php endif; ?>
                    </span>
                    <span class="cap-group-meta">
                        <span class="cap-group-count"><span class="group-enabled"><?php echo $group_enabled; ?></span>/<?php echo $group_total; ?></span>
                        <label class="cap-group-toggle-all" onclick="event.stopPropagation();">
                            <input type="checkbox" class="cap-group-toggle-input" data-group="<?php echo esc_attr($group_key); ?>"
                                <?php checked($group_enabled === $group_total && $group_total > 0); ?>>
                            <span>All</span>
                        </label>
                    </span>
                </summary>

                <div class="cap-group-body">
                    <?php foreach ($group['caps'] as $cap): ?>
                        <?php
                        $friendly = isset($cap_labels[$cap]) ? $cap_labels[$cap] : '';
                        $checked = !empty($selected_caps[$cap]);
                        $dangerous_cap = in_array($cap, array(
                            'activate_plugins','edit_plugins','install_plugins','delete_plugins','update_plugins',
                            'edit_themes','install_themes','delete_themes','update_themes',
                            'edit_files','unfiltered_html','unfiltered_upload','update_core',
                            'delete_users','create_users','promote_users',
                        ), true);
                        ?>
                        <label class="cap-row <?php echo $dangerous_cap ? 'cap-dangerous' : ''; ?>" data-cap="<?php echo esc_attr($cap); ?>">
                            <input type="checkbox" class="cap-checkbox" data-cap="<?php echo esc_attr($cap); ?>" <?php checked($checked); ?>>
                            <span class="cap-text">
                                <code class="cap-slug"><?php echo esc_html($cap); ?></code>
                                <?php if ($friendly): ?>
                                    <span class="cap-label"><?php echo esc_html($friendly); ?></span>
                                <?php endif; ?>
                                <?php if ($dangerous_cap): ?>
                                    <span class="cap-danger-badge" title="This capability can affect site security or stability">Sensitive</span>
                                <?php endif; ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
        </div>
    </form>
</div>

<style>
.azure-role-editor .role-selector-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    padding: 16px 20px;
    margin: 16px 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}
.azure-role-editor .role-selector-card label { font-size: 14px; }
.azure-role-editor .role-selector-card select { min-width: 260px; margin-left: 8px; }
.azure-role-editor .role-stats { display: flex; gap: 8px; flex-wrap: wrap; }
.azure-role-editor .stat-chip {
    background: #f0f6fc; border: 1px solid #c3d4e5; color: #0a4b78;
    padding: 4px 10px; border-radius: 999px; font-size: 12px;
    display: inline-flex; align-items: center; gap: 6px;
}
.azure-role-editor .stat-chip-muted { background: #f6f7f7; border-color: #dcdcde; color: #50575e; }
.azure-role-editor .stat-chip-info  { background: #e7f5fe; border-color: #8ec5ec; color: #0073aa; }
.azure-role-editor .stat-chip .dashicons { font-size: 14px; width: 14px; height: 14px; }

.azure-role-editor .role-editor-toolbar {
    display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px;
    background: #fff; border: 1px solid #ccd0d4; border-radius: 8px;
    padding: 12px 16px; position: sticky; top: 32px; z-index: 20;
}
.azure-role-editor .toolbar-left, .azure-role-editor .toolbar-right {
    display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
}

.azure-role-editor .role-editor-notice {
    margin: 10px 0; padding: 10px 14px; border-radius: 4px; border-left: 4px solid #2271b1;
    background: #f0f6fc; color: #1d2327;
}
.azure-role-editor .role-editor-notice.is-success { background: #edfaef; border-left-color: #00a32a; }
.azure-role-editor .role-editor-notice.is-error   { background: #fcf0f1; border-left-color: #d63638; }

.azure-role-editor .role-editor-groups { margin-top: 16px; display: grid; grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); gap: 14px; }
.azure-role-editor .cap-group { background: #fff; border: 1px solid #ccd0d4; border-radius: 8px; overflow: hidden; }
.azure-role-editor .cap-group[open] { box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
.azure-role-editor .cap-group-header {
    cursor: pointer; list-style: none; padding: 12px 16px;
    display: flex; justify-content: space-between; align-items: center;
    background: #f6f7f7; border-bottom: 1px solid #e5e5e5;
}
.azure-role-editor .cap-group-header::-webkit-details-marker { display: none; }
.azure-role-editor .cap-group-title {
    display: inline-flex; align-items: center; gap: 8px; font-weight: 600; font-size: 14px;
}
.azure-role-editor .cap-group-title .dashicons { color: #2271b1; }
.azure-role-editor .cap-group-warn {
    display: inline-flex; width: 18px; height: 18px; border-radius: 50%;
    background: #f0b849; color: #1d2327; justify-content: center; align-items: center;
    font-size: 12px; font-weight: bold;
}
.azure-role-editor .cap-group-meta { display: inline-flex; align-items: center; gap: 10px; font-size: 12px; color: #50575e; }
.azure-role-editor .cap-group-count { background: #fff; border: 1px solid #dcdcde; border-radius: 12px; padding: 2px 8px; }
.azure-role-editor .cap-group-toggle-all { display: inline-flex; align-items: center; gap: 4px; cursor: pointer; }

.azure-role-editor .cap-group-body { padding: 8px 4px 10px; max-height: 440px; overflow-y: auto; }
.azure-role-editor .cap-row {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 6px 14px; cursor: pointer; border-radius: 4px;
}
.azure-role-editor .cap-row:hover { background: #f0f6fc; }
.azure-role-editor .cap-row input[type="checkbox"] { margin-top: 2px; }
.azure-role-editor .cap-row.cap-dangerous .cap-slug { color: #b32d2e; }
.azure-role-editor .cap-row.is-filtered-out { display: none; }
.azure-role-editor .cap-text { display: flex; flex-direction: column; gap: 2px; line-height: 1.4; }
.azure-role-editor .cap-slug { font-size: 12px; background: #f6f7f7; padding: 1px 6px; border-radius: 3px; color: #1d2327; }
.azure-role-editor .cap-label { font-size: 13px; color: #3c434a; }
.azure-role-editor .cap-danger-badge {
    display: inline-block; background: #fcf0f1; color: #b32d2e;
    border: 1px solid #f1aeb5; border-radius: 3px; padding: 0 6px;
    font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;
    margin-top: 2px; width: fit-content;
}

@media (max-width: 960px) {
    .azure-role-editor .role-editor-toolbar { position: static; }
    .azure-role-editor .role-editor-groups { grid-template-columns: 1fr; }
}
</style>

<script>
(function(){
    if (typeof jQuery === 'undefined') { return; }
    jQuery(function($){
        var $form = $('#azure-role-editor-form');
        var $role = $('#azure-role-editor-role');
        var $notice = $('#role-editor-notice');
        var $filter = $('#role-editor-filter');

        function setNotice(msg, type) {
            $notice.removeClass('is-success is-error');
            if (type === 'success') $notice.addClass('is-success');
            else if (type === 'error') $notice.addClass('is-error');
            $notice.html(msg).show();
        }

        function clearNotice() { $notice.hide().empty(); }

        function updateGroupCounts() {
            $('.cap-group').each(function(){
                var $g = $(this);
                var total = $g.find('.cap-checkbox').length;
                var enabled = $g.find('.cap-checkbox:checked').length;
                $g.find('.group-enabled').text(enabled);
                $g.find('.cap-group-toggle-input').prop('checked', total > 0 && enabled === total);
            });
        }

        // Group "All" toggle
        $(document).on('change', '.cap-group-toggle-input', function(){
            var checked = $(this).is(':checked');
            var group = $(this).data('group');
            $('.cap-group[data-group="' + group + '"]').find('.cap-checkbox:visible').prop('checked', checked);
            updateGroupCounts();
        });

        // Individual cap change
        $(document).on('change', '.cap-checkbox', function(){
            updateGroupCounts();
        });

        // Select all visible / Clear all
        $('#role-editor-select-all').on('click', function(){
            $('.cap-row:not(.is-filtered-out) .cap-checkbox').prop('checked', true);
            updateGroupCounts();
        });
        $('#role-editor-clear').on('click', function(){
            $('.cap-checkbox').prop('checked', false);
            updateGroupCounts();
        });

        // Reset = reload current page
        $('#role-editor-reset').on('click', function(){
            if (confirm('Discard unsaved changes?')) {
                window.location.reload();
            }
        });

        // Copy from another role
        $('#role-editor-copy-from').on('click', function(){
            var $select = $('#role-editor-copy-source');
            if ($select.is(':visible')) { $select.hide(); return; }
            $select.show().focus();
        });

        $('#role-editor-copy-source').on('change', function(){
            var source = $(this).val();
            if (!source) return;
            if (!confirm('Copy all capabilities from "' + source + '" to this role? (Save Changes to apply.)')) {
                return;
            }
            clearNotice();
            $.post(azure_plugin_ajax.ajax_url, {
                action: 'azure_get_role_caps',
                role: source,
                nonce: azure_plugin_ajax.nonce
            }, function(resp){
                if (resp && resp.success) {
                    var caps = resp.data.capabilities || {};
                    $('.cap-checkbox').each(function(){
                        var cap = $(this).data('cap');
                        $(this).prop('checked', !!caps[cap]);
                    });
                    updateGroupCounts();
                    setNotice('Copied capabilities from <strong>' + source + '</strong>. Click <em>Save Changes</em> to apply.', 'success');
                } else {
                    setNotice('Failed to load source role: ' + (resp && resp.data ? resp.data : 'unknown error'), 'error');
                }
            }).fail(function(){
                setNotice('Network error loading source role.', 'error');
            });
            $(this).hide();
        });

        // Filter
        $filter.on('input', function(){
            var q = $(this).val().toLowerCase().trim();
            $('.cap-row').each(function(){
                if (!q) { $(this).removeClass('is-filtered-out'); return; }
                var cap = ($(this).data('cap') || '').toLowerCase();
                var label = $(this).find('.cap-label').text().toLowerCase();
                $(this).toggleClass('is-filtered-out', cap.indexOf(q) === -1 && label.indexOf(q) === -1);
            });
        });

        // Save
        $('#role-editor-save').on('click', function(){
            var $btn = $(this);
            if ($btn.prop('disabled')) return;
            var caps = {};
            $('.cap-checkbox').each(function(){
                caps[$(this).data('cap')] = $(this).is(':checked') ? 1 : 0;
            });

            if (!confirm('Save capability changes to role "' + $role.val() + '"?\n\nThis will take effect immediately for all users with that role.')) {
                return;
            }

            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update"></span> Saving...');
            clearNotice();

            $.post(azure_plugin_ajax.ajax_url, {
                action: 'azure_save_role_caps',
                role: $role.val(),
                capabilities: caps,
                nonce: azure_plugin_ajax.nonce
            }, function(resp){
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Changes');
                if (resp && resp.success) {
                    setNotice('Role capabilities saved. <strong>' + (resp.data.enabled || 0) + '</strong> enabled, <strong>' + (resp.data.disabled || 0) + '</strong> disabled.', 'success');
                } else {
                    setNotice('Save failed: ' + (resp && resp.data ? resp.data : 'unknown error'), 'error');
                }
            }).fail(function(xhr){
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Changes');
                setNotice('Network error saving role. ' + (xhr && xhr.status ? 'HTTP ' + xhr.status : ''), 'error');
            });
        });

        updateGroupCounts();
    });
})();
</script>
