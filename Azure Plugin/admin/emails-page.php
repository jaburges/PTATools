<?php
/**
 * Combined Emails Module Page
 * Tabs: Email Logs | Sending | Settings
 *
 * v3.123: added the Sending tab (per-service routing table editor)
 * and moved it ahead of Settings since it's the new primary
 * configuration surface. Settings remains for module toggle,
 * credentials, and queue display.
 */
if (!defined('ABSPATH')) {
    exit;
}

$valid_tabs = array('logs', 'sending', 'settings');
$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : '';
if (!in_array($active_tab, $valid_tabs, true)) {
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
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-emails&tab=sending')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'sending' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-randomize"></span> Sending
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-emails&tab=settings')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'settings' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-admin-generic"></span> Settings
        </a>
    </nav>

    <?php
    switch ($active_tab) {
        case 'logs':
            include AZURE_PLUGIN_PATH . 'admin/email-logs-page.php';
            break;
        case 'sending':
            include AZURE_PLUGIN_PATH . 'admin/email-sending-page.php';
            break;
        case 'settings':
            include AZURE_PLUGIN_PATH . 'admin/email-page.php';
            break;
    }
    ?>
</div>
<?php unset($GLOBALS['azure_tab_mode']); ?>
