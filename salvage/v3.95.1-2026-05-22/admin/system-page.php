<?php
/**
 * System Module Page (formerly System Logs)
 * Tabs: Logs | Schedules | Critical
 */
if (!defined('ABSPATH')) {
    exit;
}

$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'logs';
$valid_tabs = array('logs', 'schedules', 'critical');
if (!in_array($active_tab, $valid_tabs)) {
    $active_tab = 'logs';
}

$GLOBALS['azure_tab_mode'] = true;
$GLOBALS['azure_system_tab'] = $active_tab;
?>
<div class="wrap">
    <h1><span class="dashicons dashicons-admin-tools"></span> <?php _e('System', 'azure-plugin'); ?></h1>

    <nav class="azure-tabs-nav">
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-system&tab=logs')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'logs' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-list-view"></span> Logs
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-system&tab=schedules')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'schedules' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-clock"></span> Schedules
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-system&tab=critical')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'critical' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-warning"></span> Critical
        </a>
    </nav>

    <?php if ($active_tab === 'schedules'): ?>
        <?php include AZURE_PLUGIN_PATH . 'admin/system-schedules-tab.php'; ?>
    <?php else: ?>
        <?php include AZURE_PLUGIN_PATH . 'admin/logs-page.php'; ?>
    <?php endif; ?>
</div>
<?php
unset($GLOBALS['azure_tab_mode']);
unset($GLOBALS['azure_system_tab']);
?>
