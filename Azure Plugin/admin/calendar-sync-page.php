<?php
/**
 * Calendar Sync Admin Page
 *
 * Native Outlook → pta_event sync console. Replaces the v3.97-retired
 * TEC integration page and the v3.112 read-only stats placeholder.
 * Provides: stats header, mapping table (add/edit/delete + per-row
 * sync toggle), Sync Now / Repair buttons, and an activity-log
 * powered Recent Sync History panel.
 *
 * Auth (M365 user email + shared mailbox) lives on the Config page;
 * if the user hasn't signed in yet we show a notice + deep link
 * instead of inlining auth here.
 *
 * @package AzurePlugin
 * @since   3.113
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

$settings              = Azure_Settings::get_all_settings();
$calendar_user_email   = (string) ($settings['calendar_embed_user_email'] ?? '');
$calendar_mailbox      = (string) ($settings['calendar_embed_mailbox_email'] ?? '');
$calendar_authenticated = false;
if (!empty($calendar_user_email) && class_exists('Azure_Calendar_Auth')) {
    try {
        $auth_check = new Azure_Calendar_Auth();
        $calendar_authenticated = (bool) $auth_check->has_valid_user_token($calendar_user_email);
    } catch (\Throwable $e) {
        $calendar_authenticated = false;
    }
}

$mapping_manager  = class_exists('Azure_Calendar_Mapping_Manager') ? new Azure_Calendar_Mapping_Manager() : null;
$calendar_mappings = $mapping_manager ? $mapping_manager->get_all_mappings() : array();

$mapping_stats = $mapping_manager ? $mapping_manager->get_mapping_statistics() : array(
    'total' => 0, 'enabled' => 0, 'disabled' => 0, 'scheduled' => 0,
);

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

$mappings_table = class_exists('Azure_Database')
    ? Azure_Database::get_table_name('calendar_mappings')
    : $wpdb->prefix . 'azure_calendar_mappings';
$last_sync = $mappings_table
    ? (string) $wpdb->get_var("SELECT MAX(last_sync) FROM {$mappings_table}")
    : '';

$config_url = admin_url('admin.php?page=azure-plugin');

$frequency_labels = array(
    '15min'      => __('Every 15 min', 'azure-plugin'),
    '30min'      => __('Every 30 min', 'azure-plugin'),
    'hourly'     => __('Hourly', 'azure-plugin'),
    'twicedaily' => __('Twice daily', 'azure-plugin'),
    'daily'      => __('Daily', 'azure-plugin'),
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
            <?php esc_html_e('Map Outlook calendars to PTA event categories and pull events directly into pta_event posts. Outlook is the source of truth — any change there overwrites the local copy on the next sync.', 'azure-plugin'); ?>
        </p>
    </div>

    <!-- Stats row -->
    <div class="azure-stat-row" style="display:flex; gap:16px; flex-wrap:wrap; margin:16px 0;">
        <div class="azure-stat-box" style="background:#fff;border:1px solid #ccd0d4;padding:16px;min-width:180px;">
            <span class="azure-stat-number" style="display:block;font-size:28px;font-weight:700;"><?php echo (int) $mapping_stats['total']; ?></span>
            <span class="azure-stat-label"><?php esc_html_e('Calendar mappings', 'azure-plugin'); ?></span>
            <span style="display:block;color:#646970;font-size:12px;margin-top:4px;">
                <?php printf(esc_html__('%d enabled, %d scheduled', 'azure-plugin'), (int) $mapping_stats['enabled'], (int) $mapping_stats['scheduled']); ?>
            </span>
        </div>
        <div class="azure-stat-box" style="background:#fff;border:1px solid #ccd0d4;padding:16px;min-width:180px;">
            <span class="azure-stat-number" style="display:block;font-size:28px;font-weight:700;"><?php echo esc_html(number_format($synced_event_count)); ?></span>
            <span class="azure-stat-label"><?php esc_html_e('Synced events', 'azure-plugin'); ?></span>
        </div>
        <div class="azure-stat-box" style="background:#fff;border:1px solid #ccd0d4;padding:16px;min-width:180px;">
            <span class="azure-stat-number" style="display:block;font-size:28px;font-weight:700;"><?php echo esc_html(number_format($upcoming_event_count)); ?></span>
            <span class="azure-stat-label"><?php esc_html_e('Upcoming pta_event posts', 'azure-plugin'); ?></span>
        </div>
        <div class="azure-stat-box" style="background:#fff;border:1px solid #ccd0d4;padding:16px;min-width:220px;">
            <span class="azure-stat-number" style="display:block;font-size:16px;font-weight:700;">
                <?php echo $last_sync ? esc_html($last_sync) : esc_html__('Never', 'azure-plugin'); ?>
            </span>
            <span class="azure-stat-label"><?php esc_html_e('Last sync', 'azure-plugin'); ?></span>
        </div>
    </div>

    <?php if (!$calendar_authenticated): ?>
        <div class="notice notice-warning inline" style="padding:14px 16px;">
            <p>
                <strong><?php esc_html_e('Calendar sign-in required.', 'azure-plugin'); ?></strong><br>
                <?php esc_html_e('Calendar Sync needs an authenticated M365 account and a shared mailbox configured on the Config page.', 'azure-plugin'); ?>
                <a class="button button-primary" style="margin-left:8px;" href="<?php echo esc_url($config_url); ?>">
                    <?php esc_html_e('Open Config', 'azure-plugin'); ?>
                </a>
            </p>
        </div>
    <?php endif; ?>

    <!-- Calendar Mappings -->
    <div class="azure-card">
        <h2 style="display:flex;align-items:center;gap:8px;">
            <span class="dashicons dashicons-admin-links"></span>
            <?php esc_html_e('Calendar Mappings', 'azure-plugin'); ?>
        </h2>
        <p class="description">
            <?php esc_html_e('Each row maps one Outlook calendar to one pta_event_category. Events pulled from that calendar are tagged with the chosen category.', 'azure-plugin'); ?>
        </p>

        <div class="calendar-mappings-actions" style="display:flex; gap:8px; flex-wrap:wrap; margin:12px 0;">
            <button type="button" class="button button-primary" id="add-calendar-mapping" <?php disabled(!$calendar_authenticated); ?>>
                <span class="dashicons dashicons-plus-alt"></span> <?php esc_html_e('Add Mapping', 'azure-plugin'); ?>
            </button>
            <button type="button" class="button" id="calendar-manual-sync-now-mapping" <?php disabled(!$calendar_authenticated || empty($calendar_mappings)); ?>>
                <span class="dashicons dashicons-update"></span> <?php esc_html_e('Sync Now', 'azure-plugin'); ?>
            </button>
            <button type="button" class="button" id="calendar-repair-metadata-btn" title="<?php esc_attr_e('Backfill missing UTC/timezone/duration meta on existing synced events', 'azure-plugin'); ?>">
                <span class="dashicons dashicons-admin-tools"></span> <?php esc_html_e('Repair Event Metadata', 'azure-plugin'); ?>
            </button>
        </div>

        <div id="sync-progress" style="display:none; margin:8px 0 16px;">
            <p id="sync-status-message" style="margin:0; color:#1d2327;"></p>
        </div>

        <?php if (empty($calendar_mappings)): ?>
            <div class="notice notice-info inline" style="margin:0;">
                <p>
                    <?php esc_html_e('No calendar mappings yet. Click Add Mapping to connect an Outlook calendar to a PTA category.', 'azure-plugin'); ?>
                </p>
            </div>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped calendar-mappings-table">
                <thead>
                    <tr>
                        <th style="width:70px;"><?php esc_html_e('Sync', 'azure-plugin'); ?></th>
                        <th><?php esc_html_e('Outlook Calendar', 'azure-plugin'); ?></th>
                        <th><?php esc_html_e('PTA Category', 'azure-plugin'); ?></th>
                        <th style="width:180px;"><?php esc_html_e('Schedule', 'azure-plugin'); ?></th>
                        <th style="width:160px;"><?php esc_html_e('Last Sync', 'azure-plugin'); ?></th>
                        <th style="width:160px;"><?php esc_html_e('Actions', 'azure-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($calendar_mappings as $mapping): ?>
                        <tr data-mapping-id="<?php echo esc_attr($mapping->id); ?>">
                            <td>
                                <label class="switch">
                                    <input type="checkbox"
                                           class="mapping-sync-toggle"
                                           data-mapping-id="<?php echo esc_attr($mapping->id); ?>"
                                           <?php checked(!empty($mapping->sync_enabled)); ?> />
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td>
                                <strong><?php echo esc_html($mapping->outlook_calendar_name); ?></strong>
                                <div style="color:#646970;font-size:12px;">
                                    <code><?php echo esc_html($mapping->outlook_calendar_id); ?></code>
                                </div>
                            </td>
                            <td>
                                <span class="azure-category-badge" style="display:inline-block;background:#2271b1;color:#fff;padding:2px 10px;border-radius:3px;font-size:12px;">
                                    <?php echo esc_html($mapping->category_name); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($mapping->schedule_enabled)): ?>
                                    <span class="dashicons dashicons-clock" style="color:#2271b1;"></span>
                                    <?php $freq = $mapping->schedule_frequency ?: 'hourly'; ?>
                                    <?php echo esc_html($frequency_labels[$freq] ?? $freq); ?>
                                    <br>
                                    <small style="color:#646970;">
                                        <?php
                                        printf(
                                            esc_html__('%1$d days back, %2$d days ahead', 'azure-plugin'),
                                            (int) ($mapping->schedule_lookback_days ?? 30),
                                            (int) ($mapping->schedule_lookahead_days ?? 365)
                                        );
                                        ?>
                                    </small>
                                <?php else: ?>
                                    <em style="color:#999;"><?php esc_html_e('Manual only', 'azure-plugin'); ?></em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($mapping->last_sync): ?>
                                    <?php echo esc_html(date_i18n('M j, Y g:i a', strtotime($mapping->last_sync))); ?>
                                <?php else: ?>
                                    <em style="color:#999;"><?php esc_html_e('Never', 'azure-plugin'); ?></em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button"
                                        class="button button-small edit-mapping"
                                        data-mapping-id="<?php echo esc_attr($mapping->id); ?>">
                                    <?php esc_html_e('Edit', 'azure-plugin'); ?>
                                </button>
                                <button type="button"
                                        class="button button-small button-link-delete delete-mapping"
                                        data-mapping-id="<?php echo esc_attr($mapping->id); ?>">
                                    <?php esc_html_e('Delete', 'azure-plugin'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Sync History -->
    <div class="azure-card">
        <h2 style="display:flex;align-items:center;gap:8px;">
            <span class="dashicons dashicons-backup"></span>
            <?php esc_html_e('Recent Sync History', 'azure-plugin'); ?>
            <button type="button" class="button button-small" id="refresh-sync-history" style="margin-left:auto;">
                <span class="dashicons dashicons-update"></span> <?php esc_html_e('Refresh', 'azure-plugin'); ?>
            </button>
        </h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:180px;"><?php esc_html_e('Date / time', 'azure-plugin'); ?></th>
                    <th style="width:110px;"><?php esc_html_e('Type', 'azure-plugin'); ?></th>
                    <th><?php esc_html_e('Calendar(s)', 'azure-plugin'); ?></th>
                    <th style="width:130px;"><?php esc_html_e('Events synced', 'azure-plugin'); ?></th>
                    <th style="width:110px;"><?php esc_html_e('Status', 'azure-plugin'); ?></th>
                </tr>
            </thead>
            <tbody id="sync-history-list">
                <tr>
                    <td colspan="5" style="text-align:center; padding:20px;">
                        <em style="color:#666;"><?php esc_html_e('Loading sync history...', 'azure-plugin'); ?></em>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

</div>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
</div>
<?php endif; ?>

<!-- Calendar Mapping Modal -->
<div id="calendar-mapping-modal" class="azure-modal" style="display:none; position:fixed; inset:0; z-index:99999; background:rgba(0,0,0,0.45);">
    <div class="modal-overlay" style="position:absolute; inset:0;"></div>
    <div class="modal-content" style="position:relative; max-width:540px; margin:80px auto; background:#fff; border-radius:6px; padding:0; box-shadow:0 10px 40px rgba(0,0,0,0.2);">
        <div class="modal-header" style="display:flex; align-items:center; justify-content:space-between; padding:14px 20px; border-bottom:1px solid #dcdcde;">
            <h2 id="mapping-modal-title" style="margin:0; font-size:16px;"><?php esc_html_e('Add Calendar Mapping', 'azure-plugin'); ?></h2>
            <button type="button" class="modal-close button-link" style="font-size:22px; line-height:1;">&times;</button>
        </div>
        <form id="calendar-mapping-form" style="padding:18px 20px;">
            <input type="hidden" id="mapping-id" name="mapping_id" value="">

            <table class="form-table" style="margin-top:0;">
                <tr>
                    <th scope="row"><label for="outlook-calendar-select"><?php esc_html_e('Outlook Calendar', 'azure-plugin'); ?></label></th>
                    <td>
                        <select id="outlook-calendar-select" name="outlook_calendar_id" class="regular-text" required>
                            <option value=""><?php esc_html_e('Loading calendars...', 'azure-plugin'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="pta-category-select"><?php esc_html_e('PTA Category', 'azure-plugin'); ?></label></th>
                    <td>
                        <select id="pta-category-select" name="category_id" class="regular-text">
                            <option value=""><?php esc_html_e('Loading categories...', 'azure-plugin'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Pick an existing pta_event_category, or type a new one below.', 'azure-plugin'); ?></p>
                        <input type="text" id="new-category-name" name="new_category_name" class="regular-text" style="margin-top:6px;"
                               placeholder="<?php esc_attr_e('Or create a new category…', 'azure-plugin'); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Sync enabled', 'azure-plugin'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="sync-enabled-checkbox" checked>
                            <?php esc_html_e('Pull events from this calendar during Sync Now / scheduled syncs.', 'azure-plugin'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Schedule', 'azure-plugin'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="schedule-enabled-checkbox">
                            <?php esc_html_e('Run a dedicated cron for this mapping on its own frequency.', 'azure-plugin'); ?>
                        </label>
                    </td>
                </tr>
                <tr id="schedule-frequency-row" style="display:none;">
                    <th scope="row"><label for="schedule-frequency-select"><?php esc_html_e('Frequency', 'azure-plugin'); ?></label></th>
                    <td>
                        <select id="schedule-frequency-select">
                            <option value="15min"><?php esc_html_e('Every 15 minutes', 'azure-plugin'); ?></option>
                            <option value="30min"><?php esc_html_e('Every 30 minutes', 'azure-plugin'); ?></option>
                            <option value="hourly" selected><?php esc_html_e('Hourly', 'azure-plugin'); ?></option>
                            <option value="twicedaily"><?php esc_html_e('Twice daily', 'azure-plugin'); ?></option>
                            <option value="daily"><?php esc_html_e('Daily', 'azure-plugin'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr id="schedule-daterange-row" style="display:none;">
                    <th scope="row"><?php esc_html_e('Date window', 'azure-plugin'); ?></th>
                    <td>
                        <label>
                            <?php esc_html_e('Look back', 'azure-plugin'); ?>
                            <input type="number" id="schedule-lookback-days" value="30" min="0" max="3650" style="width:80px;">
                            <?php esc_html_e('days', 'azure-plugin'); ?>
                        </label>
                        &nbsp;&nbsp;
                        <label>
                            <?php esc_html_e('Look ahead', 'azure-plugin'); ?>
                            <input type="number" id="schedule-lookahead-days" value="365" min="0" max="3650" style="width:80px;">
                            <?php esc_html_e('days', 'azure-plugin'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <p style="text-align:right; margin:18px 0 0;">
                <button type="button" class="button" id="cancel-mapping-btn"><?php esc_html_e('Cancel', 'azure-plugin'); ?></button>
                <button type="submit" class="button button-primary" id="save-mapping-btn">
                    <span class="dashicons dashicons-saved"></span> <?php esc_html_e('Save Mapping', 'azure-plugin'); ?>
                </button>
            </p>
        </form>
    </div>
</div>

<style>
.calendar-mappings-table .switch {
    position: relative; display: inline-block; width: 38px; height: 22px;
}
.calendar-mappings-table .switch input { opacity: 0; width: 0; height: 0; }
.calendar-mappings-table .slider {
    position: absolute; cursor: pointer; inset: 0; background-color: #c3c4c7;
    transition: background-color .2s; border-radius: 22px;
}
.calendar-mappings-table .slider:before {
    position: absolute; content: ""; height: 16px; width: 16px; left: 3px; bottom: 3px;
    background-color: #fff; transition: transform .2s; border-radius: 50%;
}
.calendar-mappings-table input:checked + .slider { background-color: #2271b1; }
.calendar-mappings-table input:checked + .slider:before { transform: translateX(16px); }
.azure-status-success { color: #1f6e2a; font-weight: 600; }
.azure-status-failed  { color: #b32d2e; font-weight: 600; }
body.modal-open { overflow: hidden; }
</style>
