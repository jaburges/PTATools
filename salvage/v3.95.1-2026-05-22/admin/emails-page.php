<?php
/**
 * Combined Emails Module Page
 * Tabs: Email Logs | Emails
 */
if (!defined('ABSPATH')) {
    exit;
}

$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'logs';
$valid_tabs = array('logs', 'settings');
if (!in_array($active_tab, $valid_tabs)) {
    $active_tab = 'logs';
}

$GLOBALS['azure_tab_mode'] = true;
?>
<div class="wrap">
    <h1><span class="dashicons dashicons-email-alt"></span> <?php _e('Emails', 'azure-plugin'); ?></h1>

    <nav class="azure-tabs-nav">
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-emails&tab=logs')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'logs' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-list-view"></span> Email Logs
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-emails&tab=settings')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'settings' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-email-alt"></span> Emails
        </a>
    </nav>

    <?php
    switch ($active_tab) {
        case 'logs':
            include AZURE_PLUGIN_PATH . 'admin/email-logs-page.php';
            break;
        case 'settings':
            include AZURE_PLUGIN_PATH . 'admin/email-page.php';
            break;
    }
    ?>
</div>
<?php unset($GLOBALS['azure_tab_mode']); ?>
