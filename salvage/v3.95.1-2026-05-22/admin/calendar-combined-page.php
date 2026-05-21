<?php
/**
 * Combined Calendar Module Page
 * Tabs: Calendar Embed | Calendar Sync | Upcoming Events
 */
if (!defined('ABSPATH')) {
    exit;
}

$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'embed';
$valid_tabs = array('embed', 'sync', 'upcoming', 'volunteer');
if (!in_array($active_tab, $valid_tabs)) {
    $active_tab = 'embed';
}

$GLOBALS['azure_tab_mode'] = true;
?>
<div class="wrap">
    <h1><span class="dashicons dashicons-calendar-alt"></span> <?php _e('Calendar', 'azure-plugin'); ?></h1>

    <nav class="azure-tabs-nav">
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-calendar&tab=embed')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'embed' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-calendar-alt"></span> Calendar Embed
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-calendar&tab=sync')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'sync' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-update"></span> Calendar Sync
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-calendar&tab=upcoming')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'upcoming' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-clock"></span> Upcoming Events
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-calendar&tab=volunteer')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'volunteer' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-groups"></span> Volunteer Sign Up
        </a>
    </nav>

    <?php
    switch ($active_tab) {
        case 'embed':
            include AZURE_PLUGIN_PATH . 'admin/calendar-page.php';
            break;
        case 'sync':
            include AZURE_PLUGIN_PATH . 'admin/tec-integration-page.php';
            break;
        case 'upcoming':
            include AZURE_PLUGIN_PATH . 'admin/upcoming-page.php';
            break;
        case 'volunteer':
            include AZURE_PLUGIN_PATH . 'admin/volunteer-page.php';
            break;
    }
    ?>
</div>
<?php unset($GLOBALS['azure_tab_mode']); ?>
