<?php
/**
 * Combined Calendar Module Page
 * Tabs: Config | Calendar Embed | Calendar Sync | Upcoming Events | Volunteer Sign Up
 *
 * Config tab landed here in v3.115. It's the new home for M365 sign-in,
 * Azure App credentials (override or read-only inheritance), and the
 * global sync schedule defaults. The Embed and Sync tabs assume the
 * connection is already in place.
 */
if (!defined('ABSPATH')) {
    exit;
}

$requested = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : '';
if ($requested === 'volunteer') {
    $url = admin_url('admin.php?page=azure-plugin-volunteer');
    if (isset($_GET['edit_sheet'])) {
        $url = add_query_arg('edit_sheet', absint($_GET['edit_sheet']), $url);
    }
    wp_safe_redirect($url);
    exit;
}
if ($requested === 'config' || (isset($_GET['auth']) && $_GET['auth'] === 'success' && $requested === '')) {
    $url = admin_url('admin.php?page=azure-plugin-system&tab=config');
    if (isset($_GET['auth'])) {
        $url = add_query_arg('auth', sanitize_key(wp_unslash($_GET['auth'])), $url);
    }
    wp_safe_redirect($url);
    exit;
}

$valid_tabs = array('embed', 'sync', 'upcoming');
$active_tab = in_array($requested, $valid_tabs, true) ? $requested : 'embed';

$GLOBALS['azure_tab_mode'] = true;
?>
<div class="wrap">
    <h1><span class="dashicons dashicons-calendar-alt"></span> <?php _e('Calendar', 'azure-plugin'); ?></h1>

    <nav class="azure-tabs-nav">
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-calendar&tab=embed')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'embed' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e('Embed', 'azure-plugin'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-calendar&tab=sync')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'sync' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-update"></span> <?php esc_html_e('Sync', 'azure-plugin'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-calendar&tab=upcoming')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'upcoming' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-clock"></span> <?php esc_html_e('Upcoming Events', 'azure-plugin'); ?>
        </a>
    </nav>

    <?php
    switch ($active_tab) {
        case 'sync':
            include AZURE_PLUGIN_PATH . 'admin/calendar-sync-page.php';
            break;
        case 'upcoming':
            include AZURE_PLUGIN_PATH . 'admin/upcoming-page.php';
            break;
        default:
            include AZURE_PLUGIN_PATH . 'admin/calendar-page.php';
            break;
    }
    ?>
</div>
<?php unset($GLOBALS['azure_tab_mode']); ?>
