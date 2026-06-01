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

$valid_tabs = array('config', 'embed', 'sync', 'upcoming', 'volunteer');
$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : '';

// Default landing rules:
// - Post-OAuth callback (`?auth=success`) → Config, so the user sees
//   the new "Authenticated as …" badge in context.
// - No token yet → Config, where the sign-in form lives.
// - Otherwise → Embed (the most common day-to-day landing).
if (!in_array($active_tab, $valid_tabs, true)) {
    if (isset($_GET['auth']) && $_GET['auth'] === 'success') {
        $active_tab = 'config';
    } else {
        $needs_setup = true;
        if (class_exists('Azure_Calendar_Auth') && class_exists('Azure_Settings')) {
            try {
                $cal_user_email = (string) Azure_Settings::get_setting('calendar_embed_user_email', '');
                if ($cal_user_email !== '') {
                    $auth        = new Azure_Calendar_Auth();
                    $needs_setup = !$auth->has_valid_user_token($cal_user_email);
                }
            } catch (\Throwable $e) {
                $needs_setup = true;
            }
        }
        $active_tab = $needs_setup ? 'config' : 'embed';
    }
}

$GLOBALS['azure_tab_mode'] = true;
?>
<div class="wrap">
    <h1><span class="dashicons dashicons-calendar-alt"></span> <?php _e('Calendar', 'azure-plugin'); ?></h1>

    <nav class="azure-tabs-nav">
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-calendar&tab=config')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'config' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-admin-generic"></span> Config
        </a>
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
        case 'config':
            include AZURE_PLUGIN_PATH . 'admin/calendar-config-page.php';
            break;
        case 'embed':
            include AZURE_PLUGIN_PATH . 'admin/calendar-page.php';
            break;
        case 'sync':
            include AZURE_PLUGIN_PATH . 'admin/calendar-sync-page.php';
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
