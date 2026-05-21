<?php
/**
 * Newsletter Module Main Page
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get settings for the module
$settings = Azure_Settings::get_all_settings();

// Determine current tab
$current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'campaigns';
$valid_tabs = array('campaigns', 'templates', 'lists', 'queue', 'statistics', 'settings');
if (!in_array($current_tab, $valid_tabs)) {
    $current_tab = 'campaigns';
}
?>

<div class="wrap azure-newsletter-wrap">
    <h1>
        <span class="dashicons dashicons-email-alt" style="font-size: 30px; width: 30px; height: 30px; margin-right: 10px;"></span>
        <?php _e('Newsletter', 'azure-plugin'); ?>
    </h1>
    
    <?php if (!($settings['enable_newsletter'] ?? false)): ?>
    <div class="notice notice-warning">
        <p>
            <?php _e('The Newsletter module is currently disabled.', 'azure-plugin'); ?>
            <a href="<?php echo admin_url('admin.php?page=azure-plugin'); ?>"><?php _e('Enable it on the main settings page.', 'azure-plugin'); ?></a>
        </p>
    </div>
    <?php endif; ?>
    
    <nav class="nav-tab-wrapper wp-clearfix">
        <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter&tab=campaigns'); ?>" 
           class="nav-tab <?php echo $current_tab === 'campaigns' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-email"></span> <?php _e('Campaigns', 'azure-plugin'); ?>
        </a>
        <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter&action=new'); ?>" 
           class="nav-tab nav-tab-create">
            <span class="dashicons dashicons-plus-alt"></span> <?php _e('Create New', 'azure-plugin'); ?>
        </a>
        <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter&tab=templates'); ?>" 
           class="nav-tab <?php echo $current_tab === 'templates' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-layout"></span> <?php _e('Templates', 'azure-plugin'); ?>
        </a>
        <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter&tab=lists'); ?>" 
           class="nav-tab <?php echo $current_tab === 'lists' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-groups"></span> <?php _e('Lists', 'azure-plugin'); ?>
        </a>
        <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter&tab=queue'); ?>" 
           class="nav-tab <?php echo $current_tab === 'queue' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-list-view"></span> <?php _e('Queue', 'azure-plugin'); ?>
        </a>
        <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter&tab=statistics'); ?>" 
           class="nav-tab <?php echo $current_tab === 'statistics' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-chart-bar"></span> <?php _e('Statistics', 'azure-plugin'); ?>
        </a>
        <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter&tab=settings'); ?>" 
           class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-admin-generic"></span> <?php _e('Settings', 'azure-plugin'); ?>
        </a>
    </nav>
    
    <div class="tab-content">
        <?php
        // Check for editor action first
        if (isset($_GET['action']) && $_GET['action'] === 'new') {
            include AZURE_PLUGIN_PATH . 'admin/newsletter-editor.php';
        } else {
            // Include the appropriate tab content
            switch ($current_tab) {
                case 'campaigns':
                    include AZURE_PLUGIN_PATH . 'admin/newsletter-campaigns.php';
                    break;
                case 'templates':
                    include AZURE_PLUGIN_PATH . 'admin/newsletter-templates.php';
                    break;
                case 'lists':
                    include AZURE_PLUGIN_PATH . 'admin/newsletter-lists.php';
                    break;
                case 'queue':
                    include AZURE_PLUGIN_PATH . 'admin/newsletter-queue.php';
                    break;
                case 'statistics':
                    include AZURE_PLUGIN_PATH . 'admin/newsletter-statistics.php';
                    break;
                case 'settings':
                    include AZURE_PLUGIN_PATH . 'admin/newsletter-settings.php';
                    break;
            }
        }
        ?>
    </div>
</div>

<style>
.azure-newsletter-wrap .nav-tab-wrapper {
    margin-bottom: 0;
    border-bottom: 1px solid #c3c4c7;
    padding-left: 0;
}
.azure-newsletter-wrap .nav-tab {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 10px 15px;
}
.azure-newsletter-wrap .nav-tab .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}
.azure-newsletter-wrap .nav-tab-create {
    background: #2271b1;
    color: #fff;
    border-color: #2271b1;
}
.azure-newsletter-wrap .nav-tab-create:hover {
    background: #135e96;
}
.azure-newsletter-wrap .tab-content {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-top: none;
    padding: 20px;
}
</style>
