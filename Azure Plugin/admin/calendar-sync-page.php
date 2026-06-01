<?php
/**
 * Calendar Sync Admin Page
 *
 * Native pta_event calendar sync overview. Replaces the retired TEC
 * integration page.
 */
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

$mappings_table = class_exists('Azure_Database')
    ? Azure_Database::get_table_name('calendar_mappings')
    : $wpdb->prefix . 'azure_calendar_mappings';

$table_exists = $mappings_table && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $mappings_table)) === $mappings_table;
$mappings = array();
$synced_event_count = 0;
$upcoming_event_count = 0;
$last_sync = '';

if ($table_exists) {
    $mappings = $wpdb->get_results("SELECT * FROM {$mappings_table} ORDER BY sync_enabled DESC, outlook_calendar_name ASC");
    $last_sync = (string) $wpdb->get_var("SELECT MAX(last_sync) FROM {$mappings_table}");
}

$synced_event_count = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts} p
     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_outlook_event_id'
     WHERE p.post_type = 'pta_event'
       AND p.post_status = 'publish'
       AND pm.meta_value <> ''"
);

$upcoming_event_count = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->posts} p
     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_EventStartDate'
     WHERE p.post_type = 'pta_event'
       AND p.post_status = 'publish'
       AND pm.meta_value >= %s",
    current_time('mysql')
));

$recent_events = $wpdb->get_results(
    "SELECT p.ID, p.post_title, start_meta.meta_value AS start_date, cal_meta.meta_value AS calendar_id
     FROM {$wpdb->posts} p
     INNER JOIN {$wpdb->postmeta} start_meta ON p.ID = start_meta.post_id AND start_meta.meta_key = '_EventStartDate'
     LEFT JOIN {$wpdb->postmeta} cal_meta ON p.ID = cal_meta.post_id AND cal_meta.meta_key = '_outlook_calendar_id'
     WHERE p.post_type = 'pta_event'
       AND p.post_status = 'publish'
     ORDER BY start_meta.meta_value DESC
     LIMIT 10"
);
?>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
<div class="wrap azure-admin-wrap">
    <h1><span class="dashicons dashicons-update"></span> <?php esc_html_e('Calendar Sync', 'azure-plugin'); ?></h1>
<?php endif; ?>

<div class="azure-admin-content">
    <div class="azure-card">
        <h2><?php esc_html_e('Native Calendar Sync', 'azure-plugin'); ?></h2>
        <p>
            <?php esc_html_e('Outlook calendars sync directly into PTA Tools native pta_event posts. The Events Calendar plugin is no longer required.', 'azure-plugin'); ?>
        </p>
    </div>

    <div class="azure-stat-row" style="display:flex; gap:16px; flex-wrap:wrap; margin:16px 0;">
        <div class="azure-stat-box" style="background:#fff;border:1px solid #ccd0d4;padding:16px;min-width:180px;">
            <span class="azure-stat-number" style="display:block;font-size:28px;font-weight:700;"><?php echo number_format((int) count($mappings)); ?></span>
            <span class="azure-stat-label"><?php esc_html_e('Calendar mappings', 'azure-plugin'); ?></span>
        </div>
        <div class="azure-stat-box" style="background:#fff;border:1px solid #ccd0d4;padding:16px;min-width:180px;">
            <span class="azure-stat-number" style="display:block;font-size:28px;font-weight:700;"><?php echo number_format($synced_event_count); ?></span>
            <span class="azure-stat-label"><?php esc_html_e('Synced events', 'azure-plugin'); ?></span>
        </div>
        <div class="azure-stat-box" style="background:#fff;border:1px solid #ccd0d4;padding:16px;min-width:180px;">
            <span class="azure-stat-number" style="display:block;font-size:28px;font-weight:700;"><?php echo number_format($upcoming_event_count); ?></span>
            <span class="azure-stat-label"><?php esc_html_e('Upcoming pta_event posts', 'azure-plugin'); ?></span>
        </div>
        <div class="azure-stat-box" style="background:#fff;border:1px solid #ccd0d4;padding:16px;min-width:220px;">
            <span class="azure-stat-number" style="display:block;font-size:16px;font-weight:700;"><?php echo $last_sync ? esc_html($last_sync) : esc_html__('Never', 'azure-plugin'); ?></span>
            <span class="azure-stat-label"><?php esc_html_e('Last sync', 'azure-plugin'); ?></span>
        </div>
    </div>

    <div class="azure-card">
        <h2><?php esc_html_e('Calendar Mappings', 'azure-plugin'); ?></h2>
        <?php if (!$table_exists): ?>
            <div class="notice notice-warning inline">
                <p><?php esc_html_e('Calendar mappings table is missing. Re-run plugin activation or database migration.', 'azure-plugin'); ?></p>
            </div>
        <?php elseif (empty($mappings)): ?>
            <p><em><?php esc_html_e('No calendar mappings found yet.', 'azure-plugin'); ?></em></p>
        <?php else: ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Outlook Calendar', 'azure-plugin'); ?></th>
                        <th><?php esc_html_e('Category', 'azure-plugin'); ?></th>
                        <th><?php esc_html_e('Sync', 'azure-plugin'); ?></th>
                        <th><?php esc_html_e('Schedule', 'azure-plugin'); ?></th>
                        <th><?php esc_html_e('Last Sync', 'azure-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mappings as $m): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($m->outlook_calendar_name); ?></strong>
                            <div style="color:#646970;font-size:12px;"><code><?php echo esc_html($m->outlook_calendar_id); ?></code></div>
                        </td>
                        <td><?php echo esc_html($m->category_name); ?></td>
                        <td><?php echo !empty($m->sync_enabled) ? esc_html__('Enabled', 'azure-plugin') : esc_html__('Disabled', 'azure-plugin'); ?></td>
                        <td>
                            <?php echo !empty($m->schedule_enabled) ? esc_html($m->schedule_frequency) : esc_html__('Manual', 'azure-plugin'); ?>
                        </td>
                        <td><?php echo $m->last_sync ? esc_html($m->last_sync) : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="azure-card">
        <h2><?php esc_html_e('Recent Synced Events', 'azure-plugin'); ?></h2>
        <?php if (empty($recent_events)): ?>
            <p><em><?php esc_html_e('No pta_event posts found.', 'azure-plugin'); ?></em></p>
        <?php else: ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Event', 'azure-plugin'); ?></th>
                        <th><?php esc_html_e('Starts', 'azure-plugin'); ?></th>
                        <th><?php esc_html_e('Calendar ID', 'azure-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_events as $event): ?>
                    <tr>
                        <td><a href="<?php echo esc_url(get_edit_post_link((int) $event->ID)); ?>"><?php echo esc_html($event->post_title); ?></a></td>
                        <td><?php echo esc_html($event->start_date); ?></td>
                        <td><code><?php echo esc_html($event->calendar_id ?: ''); ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
</div>
<?php endif; ?>
