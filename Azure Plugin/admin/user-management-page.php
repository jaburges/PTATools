<?php
/**
 * PTA Tools → User Management
 *
 * Top-level tabbed admin page that consolidates:
 *   - Family Hierarchy (Parent 1 ↔ Parent 2 ↔ children visualization)
 *   - Role Editor (existing visual cap matrix + Subscriber→Parent migration)
 *   - Account Menu (header dropdown shortcode + nav location status)
 *
 * Each tab is a small, focused partial under admin/user-management/ so this
 * top-level file stays under the 500-line policy and the partials can be
 * read/edited in isolation.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    return;
}

require_once AZURE_PLUGIN_PATH . 'includes/class-user-management-module.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-parent-role-admin.php';
Azure_User_Management_Module::get_instance();
Azure_Parent_Role_Admin::get_instance();

$active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'family-hierarchy';
$valid_tabs = array('family-hierarchy', 'role-editor', 'account-menu');
if (!in_array($active_tab, $valid_tabs, true)) {
    $active_tab = 'family-hierarchy';
}

$base_url = admin_url('admin.php?page=azure-plugin-user-management');
?>
<div class="wrap">
    <h1><span class="dashicons dashicons-groups"></span> <?php _e('User Management', 'azure-plugin'); ?></h1>

    <nav class="azure-tabs-nav nav-tab-wrapper" style="margin-top:12px;">
        <a href="<?php echo esc_url(add_query_arg('tab', 'family-hierarchy', $base_url)); ?>"
           class="nav-tab <?php echo $active_tab === 'family-hierarchy' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-networking" style="vertical-align:text-bottom;"></span>
            <?php _e('Family Hierarchy', 'azure-plugin'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'role-editor', $base_url)); ?>"
           class="nav-tab <?php echo $active_tab === 'role-editor' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-shield" style="vertical-align:text-bottom;"></span>
            <?php _e('Role Editor', 'azure-plugin'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'account-menu', $base_url)); ?>"
           class="nav-tab <?php echo $active_tab === 'account-menu' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-menu-alt" style="vertical-align:text-bottom;"></span>
            <?php _e('Account Menu', 'azure-plugin'); ?>
        </a>
    </nav>

    <div style="margin-top:18px;">
        <?php
        switch ($active_tab) {
            case 'role-editor':
                include AZURE_PLUGIN_PATH . 'admin/user-management/role-editor-tab.php';
                break;
            case 'account-menu':
                include AZURE_PLUGIN_PATH . 'admin/user-management/account-menu-tab.php';
                break;
            case 'family-hierarchy':
            default:
                include AZURE_PLUGIN_PATH . 'admin/user-management/family-hierarchy-tab.php';
                break;
        }
        ?>
    </div>
</div>
