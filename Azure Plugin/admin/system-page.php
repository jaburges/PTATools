<?php
/**
 * System Module Page (formerly System Logs)
 * Tabs: Critical | Logs | Schedules | Class Count | Admin Menu | Config | Rules
 */
if (!defined('ABSPATH')) {
    exit;
}

$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'logs';
$valid_tabs = array('critical', 'logs', 'schedules', 'classes', 'menu', 'config', 'rules');
if (!in_array($active_tab, $valid_tabs, true)) {
    $active_tab = 'logs';
}

$GLOBALS['azure_tab_mode'] = true;
$GLOBALS['azure_system_tab'] = $active_tab;
?>
<div class="wrap">
    <h1><span class="dashicons dashicons-admin-tools"></span> <?php _e('System', 'azure-plugin'); ?></h1>

    <nav class="azure-tabs-nav">
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-system&tab=critical')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'critical' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-warning"></span> <?php esc_html_e('Critical', 'azure-plugin'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-system&tab=logs')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'logs' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-list-view"></span> <?php esc_html_e('Logs', 'azure-plugin'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-system&tab=schedules')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'schedules' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-clock"></span> <?php esc_html_e('Schedules', 'azure-plugin'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-system&tab=classes')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'classes' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-groups"></span> <?php esc_html_e('Class Count', 'azure-plugin'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-system&tab=menu')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'menu' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-menu-alt"></span> <?php esc_html_e('Admin Menu', 'azure-plugin'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-system&tab=config')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'config' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e('Config', 'azure-plugin'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-system&tab=rules')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'rules' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-randomize"></span> <?php esc_html_e('Rules', 'azure-plugin'); ?>
        </a>
    </nav>

    <?php if ($active_tab === 'schedules'): ?>
        <?php include AZURE_PLUGIN_PATH . 'admin/system-schedules-tab.php'; ?>
    <?php elseif ($active_tab === 'classes'): ?>
        <?php include AZURE_PLUGIN_PATH . 'admin/system-classes-tab.php'; ?>
    <?php elseif ($active_tab === 'menu'): ?>
        <?php include AZURE_PLUGIN_PATH . 'admin/system-menu-tab.php'; ?>
    <?php elseif ($active_tab === 'config'): ?>
        <?php include AZURE_PLUGIN_PATH . 'admin/calendar-config-page.php'; ?>
    <?php elseif ($active_tab === 'rules'): ?>
        <?php include AZURE_PLUGIN_PATH . 'admin/order-rules-page.php'; ?>
    <?php else: ?>
        <?php include AZURE_PLUGIN_PATH . 'admin/logs-page.php'; ?>
    <?php endif; ?>
</div>
<?php
unset($GLOBALS['azure_tab_mode']);
unset($GLOBALS['azure_system_tab']);
?>
