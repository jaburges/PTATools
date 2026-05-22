<?php
if (!defined('ABSPATH')) {
    exit;
}

// Get backup statistics
$backup_stats = array();
$schedule_info = array();

if (class_exists('Azure_Backup_Scheduler')) {
    try {
        $scheduler = new Azure_Backup_Scheduler();
        $backup_stats = $scheduler->get_backup_stats();
        $schedule_info = $scheduler->get_schedule_info();
    } catch (Exception $e) {
        // Set fallback values on error
        $backup_stats = array(
            'total_backups' => 0,
            'completed_backups' => 0,
            'failed_backups' => 0,
            'total_size_formatted' => '0 B'
        );
    }
}

// Get recent backup jobs
global $wpdb;
$backup_jobs_table = Azure_Database::get_table_name('backup_jobs');
$recent_jobs = array();

if ($backup_jobs_table) {
    $recent_jobs = $wpdb->get_results("SELECT * FROM {$backup_jobs_table} ORDER BY created_at DESC LIMIT 10");
}

// Get settings for form
$settings = Azure_Settings::get_all_settings();
?>

<div class="wrap">
    <h1>PTA Tools - Backup Settings</h1>
    
    <!-- Module Status Toggle -->
    <div class="module-status-section">
        <h2>Module Status</h2>
        <div class="module-toggle-card">
            <div class="module-info">
                <h3><span class="dashicons dashicons-backup"></span> Azure Backup Module</h3>
                <p>Backup your WordPress site to Azure Blob Storage</p>
            </div>
            <div class="module-control">
                <label class="switch">
                    <input type="checkbox" class="backup-module-toggle" <?php checked(Azure_Settings::is_module_enabled('backup')); ?> />
                    <span class="slider"></span>
                </label>
                <span class="toggle-status"><?php echo Azure_Settings::is_module_enabled('backup') ? 'Enabled' : 'Disabled'; ?></span>
            </div>
        </div>
        <?php if (!Azure_Settings::is_module_enabled('backup')): ?>
        <div class="notice notice-warning inline">
            <p><strong>Backup module is disabled.</strong> Enable it above or in the <a href="<?php echo admin_url('admin.php?page=azure-plugin'); ?>">main settings</a> to use backup functionality.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Backup Statistics - 4 Widgets -->
    <!-- FORCE REFRESH: <?php echo time(); ?> -->
    <div class="backup-stats-section" data-timestamp="<?php echo time(); ?>">
        <h2>Backup Statistics</h2>
        
        <div class="stats-cards" data-cards-count="4">
            <!-- Card 1: Total -->
            <div class="stat-card total-card">
                <div class="stat-number"><?php echo intval($backup_stats['total_backups'] ?? 0); ?></div>
                <div class="stat-label">Total</div>
            </div>
            
            <!-- Card 2: Completed -->
            <div class="stat-card success completed-card">
                <div class="stat-number"><?php echo intval($backup_stats['completed_backups'] ?? 0); ?></div>
                <div class="stat-label">Completed</div>
            </div>
            
            <!-- Card 3: Failed -->
            <div class="stat-card error failed-card">
                <div class="stat-number"><?php echo intval($backup_stats['failed_backups'] ?? 0); ?></div>
                <div class="stat-label">Failed</div>
            </div>
            
            <!-- Card 4: Size -->
            <div class="stat-card size-card">
                <div class="stat-number"><?php echo esc_html($backup_stats['total_size_formatted'] ?? '0 B'); ?></div>
                <div class="stat-label">Size</div>
            </div>
        </div>
        
        <!-- Debug info (remove in production) -->
        <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
        <p style="font-size: 11px; color: #666; margin-top: 10px;">
            Debug: Cards rendered at <?php echo date('H:i:s'); ?> | 
            Total: <?php echo $backup_stats['total_backups'] ?? 'null'; ?> | 
            Completed: <?php echo $backup_stats['completed_backups'] ?? 'null'; ?> | 
            Failed: <?php echo $backup_stats['failed_backups'] ?? 'null'; ?> | 
            Size: <?php echo $backup_stats['total_size_formatted'] ?? 'null'; ?>
        </p>
        <?php endif; ?>
    </div>

    <!-- Backup Progress Section (Hidden by default) -->
    <div id="backup-progress-section" style="display: none; margin-top: 20px; padding: 20px; background: #f9f9f9; border-radius: 8px; border-left: 4px solid #0073aa;">
        <h3 style="margin: 0 0 15px 0; color: #0073aa;">🔄 Backup in Progress</h3>
        <div id="backup-progress-details" style="margin-bottom: 15px;">
            <p id="backup-progress-name" style="margin: 0; font-weight: bold; color: #333;"></p>
            <p id="backup-progress-status" style="margin: 5px 0; color: #666; font-size: 14px;"></p>
        </div>
        <div style="background: #e9ecef; border-radius: 10px; height: 28px; margin: 15px 0; overflow: hidden; box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);">
            <div id="backup-progress-bar" style="
                background: linear-gradient(90deg, #0073aa 0%, #005a87 100%); 
                height: 100%; 
                width: 0%; 
                transition: width 0.5s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-weight: bold;
                text-shadow: 0 1px 2px rgba(0,0,0,0.3);
                font-size: 14px;
            ">
                <span id="backup-progress-percent">0%</span>
            </div>
        </div>
        <div id="backup-progress-message" style="margin: 15px 0 10px 0; padding: 12px; background: #fff; border-radius: 4px; border: 1px solid #ddd; font-size: 14px; color: #555; font-family: monospace;"></div>
        <div id="backup-progress-actions" style="text-align: right; margin-top: 15px; display: none;">
            <button type="button" class="button" onclick="hideBackupProgress()">Hide Progress</button>
            <button type="button" class="button button-primary" onclick="refreshPage()">Refresh Page</button>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions-section">
        <h2>Quick Actions</h2>
        
        <div class="action-buttons">
            <button type="button" class="button button-primary start-backup">
                <span class="dashicons dashicons-backup"></span>
                Start Manual Backup
            </button>
            
            <button type="button" class="button test-azure-connection" data-type="sso">
                <span class="dashicons dashicons-cloud"></span>
                Test Azure Connection
            </button>
            
            <button type="button" class="button test-storage-connection">
                <span class="dashicons dashicons-cloud"></span>
                Test Storage Connection
            </button>
            
            <?php
            // Check if there are any running or pending (stuck) backups
            $stalled_backups_count = 0;
            if ($backup_jobs_table) {
                $stalled_backups_count = $wpdb->get_var("SELECT COUNT(*) FROM {$backup_jobs_table} WHERE status IN ('running', 'pending')");
            }
            ?>
            <button type="button" class="button button-secondary cancel-all-backups" <?php echo $stalled_backups_count == 0 ? 'disabled' : ''; ?>>
                <span class="dashicons dashicons-dismiss"></span>
                Cancel Stalled Backups
                <?php if ($stalled_backups_count > 0): ?>
                    <span class="running-count">(<?php echo $stalled_backups_count; ?>)</span>
                <?php endif; ?>
            </button>
            
            <button type="button" class="button button-secondary cleanup-backup-files">
                <span class="dashicons dashicons-trash"></span>
                Clean Up Local Files
            </button>
        </div>
    </div>

    <!-- Settings Form -->
    <div class="backup-settings-section">
        <form method="post" action="">
            <?php wp_nonce_field('azure_plugin_settings'); ?>
            
            <!-- Azure Storage Configuration -->
            <div class="credentials-section">
                <h2>Azure Storage Configuration</h2>
                <p class="description">Azure Storage Account is required for backup functionality.</p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Storage Account Name</th>
                        <td>
                            <input type="text" name="azure_plugin_settings[backup_storage_account_name]" value="<?php echo esc_attr($settings['backup_storage_account_name'] ?? ''); ?>" class="regular-text" />
                            <p class="description">Your Azure Storage Account name (without .blob.core.windows.net)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Storage Access Key</th>
                        <td>
                            <input type="password" name="azure_plugin_settings[backup_storage_account_key]" value="<?php echo esc_attr($settings['backup_storage_account_key'] ?? ''); ?>" class="regular-text" />
                            <p class="description">Primary or secondary access key for your storage account</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Container Name</th>
                        <td>
                            <input type="text" name="azure_plugin_settings[backup_storage_container_name]" value="<?php echo esc_attr($settings['backup_storage_container_name'] ?? 'wordpress-backups'); ?>" class="regular-text" />
                            <p class="description">Azure Blob Storage container name for backups</p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- Backup Configuration -->
            <div class="backup-configuration">
                <h2>Backup Configuration</h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Backup Types</th>
                        <td>
                            <?php
                            $backup_types = $settings['backup_types'] ?? array('database', 'mu-plugins', 'plugins', 'themes', 'content');
                            $selected_plugins = $settings['backup_selected_plugins'] ?? array();
                            $selected_themes  = $settings['backup_selected_themes']  ?? array();

                            $simple_types = array(
                                'database'   => 'Database',
                                'mu-plugins' => 'Must-Use Plugins',
                                'content'    => 'Content Files',
                            );

                            $all_plugins = get_plugins();
                            $all_themes  = wp_get_themes();
                            ?>
                            <?php foreach ($simple_types as $type => $label): ?>
                            <label>
                                <input type="checkbox" name="azure_plugin_settings[backup_types][]" value="<?php echo $type; ?>" <?php checked(in_array($type, $backup_types)); ?> />
                                <?php echo $label; ?>
                            </label><br>
                            <?php endforeach; ?>

                            <!-- Plugins expandable -->
                            <div style="margin: 4px 0;">
                                <label>
                                    <input type="checkbox" name="azure_plugin_settings[backup_types][]" value="plugins" id="backup-type-plugins" <?php checked(in_array('plugins', $backup_types)); ?> />
                                    Plugins
                                </label>
                                <a href="#" class="backup-expand-toggle" data-target="backup-plugins-list" style="margin-left: 6px; font-size: 12px; text-decoration: none;">[select individually ▾]</a>
                                <div id="backup-plugins-list" style="display: none; margin: 6px 0 6px 24px; padding: 8px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; max-height: 200px; overflow-y: auto;">
                                    <label style="display: block; margin-bottom: 4px; font-weight: bold;">
                                        <input type="checkbox" class="backup-select-all" data-group="backup-plugin-item" checked /> Select All
                                    </label>
                                    <hr style="margin: 4px 0;">
                                    <?php foreach ($all_plugins as $plugin_file => $plugin_data):
                                        $slug = dirname($plugin_file);
                                        if ($slug === '.') $slug = basename($plugin_file, '.php');
                                        $is_checked = empty($selected_plugins) || in_array($slug, $selected_plugins);
                                    ?>
                                    <label style="display: block; margin: 2px 0;">
                                        <input type="checkbox" class="backup-plugin-item" name="azure_plugin_settings[backup_selected_plugins][]" value="<?php echo esc_attr($slug); ?>" <?php checked($is_checked); ?> />
                                        <?php echo esc_html($plugin_data['Name']); ?>
                                        <span style="color: #888; font-size: 11px;">(<?php echo esc_html($slug); ?>)</span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Themes expandable -->
                            <div style="margin: 4px 0;">
                                <label>
                                    <input type="checkbox" name="azure_plugin_settings[backup_types][]" value="themes" id="backup-type-themes" <?php checked(in_array('themes', $backup_types)); ?> />
                                    Themes
                                </label>
                                <a href="#" class="backup-expand-toggle" data-target="backup-themes-list" style="margin-left: 6px; font-size: 12px; text-decoration: none;">[select individually ▾]</a>
                                <div id="backup-themes-list" style="display: none; margin: 6px 0 6px 24px; padding: 8px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; max-height: 200px; overflow-y: auto;">
                                    <label style="display: block; margin-bottom: 4px; font-weight: bold;">
                                        <input type="checkbox" class="backup-select-all" data-group="backup-theme-item" checked /> Select All
                                    </label>
                                    <hr style="margin: 4px 0;">
                                    <?php foreach ($all_themes as $theme_slug => $theme_obj): ?>
                                    <label style="display: block; margin: 2px 0;">
                                        <input type="checkbox" class="backup-theme-item" name="azure_plugin_settings[backup_selected_themes][]" value="<?php echo esc_attr($theme_slug); ?>" <?php checked(empty($selected_themes) || in_array($theme_slug, $selected_themes)); ?> />
                                        <?php echo esc_html($theme_obj->get('Name')); ?>
                                        <span style="color: #888; font-size: 11px;">(<?php echo esc_html($theme_slug); ?>)</span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <p class="description">Select which components to include in backups. Use "select individually" to choose specific plugins or themes.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Enable Scheduled Backups</th>
                        <td>
                            <label>
                                <input type="checkbox" name="azure_plugin_settings[backup_schedule_enabled]" <?php checked($settings['backup_schedule_enabled'] ?? false); ?> />
                                Run backups automatically
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Backup Frequency</th>
                        <td>
                            <select name="azure_plugin_settings[backup_schedule_frequency]">
                                <?php
                                $current_frequency = $settings['backup_schedule_frequency'] ?? 'daily';
                                $frequencies = array(
                                    'hourly' => 'Hourly',
                                    'daily' => 'Daily',
                                    'weekly' => 'Weekly',
                                    'monthly' => 'Monthly'
                                );
                                ?>
                                <?php foreach ($frequencies as $value => $label): ?>
                                <option value="<?php echo $value; ?>" <?php selected($current_frequency, $value); ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Retention Days</th>
                        <td>
                            <input type="number" name="azure_plugin_settings[backup_retention_days]" value="<?php echo esc_attr($settings['backup_retention_days'] ?? 30); ?>" min="1" max="365" class="small-text" />
                            <p class="description">Number of days to keep backups (0 = forever)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Archive Split Size (MB)</th>
                        <td>
                            <input type="number" name="azure_plugin_settings[backup_split_size]" value="<?php echo esc_attr($settings['backup_split_size'] ?? 400); ?>" min="25" max="2000" class="small-text" />
                            <p class="description">Maximum size per archive file in MB. Large components (media) will be split into multiple files. Default: 400 MB.</p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <p class="submit">
                <input type="submit" name="azure_plugin_submit" class="button-primary" value="Save Backup Settings" />
            </p>
        </form>
    </div>
    
    <!-- Recent Backup Jobs -->
    <div class="backup-jobs-section">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
            <h2 style="margin: 0;">Recent Backup Jobs</h2>
            <button class="button button-secondary sync-remote-backups">
                <span class="dashicons dashicons-cloud" style="vertical-align: middle; margin-right: 4px;"></span>
                Sync from Azure
            </button>
        </div>

        <?php if (!empty($recent_jobs)): ?>
        <table class="wp-list-table widefat fixed striped" id="backup-jobs-table">
            <thead>
                <tr>
                    <th style="width:30px;"></th>
                    <th>Job Name</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Size</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_jobs as $job):
                    $has_entities = !empty($job->entity_state) && $job->entity_state !== '{}';
                    $is_completed = $job->status === 'completed' && !empty($job->azure_blob_name);
                    $status_class = $job->status === 'completed' ? 'success' : ($job->status === 'failed' ? 'error' : 'warning');
                ?>
                <tr class="backup-parent-row" data-job-id="<?php echo $job->id; ?>">
                    <td style="text-align:center;">
                        <?php if ($is_completed && $has_entities): ?>
                        <button class="button button-link toggle-backup-details" data-job-id="<?php echo $job->id; ?>" title="Show files" style="padding:0; min-height:0; font-size:16px; line-height:1; cursor:pointer;">
                            <span class="dashicons dashicons-arrow-right-alt2" style="transition:transform 0.2s;"></span>
                        </button>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($job->job_name); ?></td>
                    <td>
                        <span class="status-indicator <?php echo $status_class; ?>">
                            <?php echo esc_html(ucfirst($job->status)); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($job->created_at); ?></td>
                    <td><?php echo $job->file_size ? size_format($job->file_size) : '-'; ?></td>
                    <td>
                        <?php if ($is_completed): ?>
                        <button class="button button-small restore-backup" data-backup-id="<?php echo $job->id; ?>" data-has-entities="<?php echo $has_entities ? '1' : '0'; ?>">
                            Restore
                        </button>
                        <?php endif; ?>
                        <?php if ($job->status === 'failed' && !empty($job->error_message)): ?>
                        <button class="button button-small view-error" data-error="<?php echo esc_attr($job->error_message); ?>">
                            View Error
                        </button>
                        <?php endif; ?>
                        <?php if (in_array($job->status, array('completed', 'failed', 'cancelled'))): ?>
                        <button class="button button-small delete-backup" data-backup-id="<?php echo $job->id; ?>">
                            Delete
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr class="backup-detail-row" data-job-id="<?php echo $job->id; ?>" style="display:none;">
                    <td colspan="6" style="padding:0;">
                        <div class="backup-detail-content" style="padding:8px 12px 12px 40px; background:#f9f9f9;">
                            <div class="backup-detail-loading" style="color:#666;"><span class="spinner is-active" style="float:none; margin:0 6px 0 0;"></span> Loading component files...</div>
                            <div class="backup-detail-table" style="display:none;"></div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p>No backup jobs found. <a href="#" class="start-backup">Create your first backup</a>.</p>
        <?php endif; ?>
    </div>

    <!-- Restore Progress Section (Hidden by default) -->
    <div id="restore-progress-section" style="display: none; margin-top: 20px; padding: 20px; background: #f9f9f9; border-radius: 8px; border-left: 4px solid #2196f3;">
        <h3 style="margin: 0 0 15px 0; color: #2196f3;">⏳ Restore in Progress</h3>
        <div id="restore-progress-details" style="margin-bottom: 15px;">
            <p id="restore-progress-status" style="margin: 5px 0; color: #666; font-size: 14px;">Status: Initializing...</p>
        </div>
        <div style="background: #e9ecef; border-radius: 10px; height: 28px; margin: 15px 0; overflow: hidden; box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);">
            <div id="restore-progress-bar" style="
                background: linear-gradient(90deg, #2196f3 0%, #1769aa 100%);
                height: 100%;
                width: 0%;
                transition: width 0.5s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-weight: bold;
                text-shadow: 0 1px 2px rgba(0,0,0,0.3);
                font-size: 14px;
            ">
                <span id="restore-progress-percent">0%</span>
            </div>
        </div>
        <div id="restore-progress-message" style="margin: 15px 0 10px 0; padding: 12px; background: #fff; border-radius: 4px; border: 1px solid #ddd; font-size: 14px; color: #555; font-family: monospace;"></div>
        <div id="restore-progress-actions" style="text-align: right; margin-top: 15px; display: none;">
            <button type="button" class="button" onclick="jQuery('#restore-progress-section').slideUp();">Hide</button>
            <button type="button" class="button button-primary" onclick="location.reload();">Refresh Page</button>
        </div>
    </div>

    <!-- Remote Azure Backups -->
    <div id="remote-backups-section" class="backup-jobs-section" style="display: none; margin-top: 20px;">
        <h2>Azure Storage Backups</h2>
        <div id="remote-backups-loading" style="display: none;">
            <span class="spinner is-active" style="float: none;"></span> Loading backups from Azure Storage...
        </div>
        <div id="remote-backups-content"></div>
    </div>

    <!-- Restore Component Selection Dialog -->
    <div id="restore-component-dialog" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:100000;">
        <div style="background:#fff; max-width:480px; margin:10% auto; padding:24px; border-radius:8px; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
            <h3 style="margin:0 0 16px;">Select Components to Restore</h3>
            <p style="color:#666; margin-bottom:12px;">Choose which parts of the backup to restore:</p>
            <label style="display:block; margin:0 0 10px; padding-bottom:8px; border-bottom:1px solid #ddd; font-weight:600;">
                <input type="checkbox" id="restore-select-all" checked /> Select All
            </label>
            <div id="restore-component-list" style="margin-bottom:16px;">
                <label style="display:block; margin:6px 0;"><input type="checkbox" class="restore-comp" value="database" checked /> Database</label>
                <label style="display:block; margin:6px 0;"><input type="checkbox" class="restore-comp" value="mu-plugins" checked /> Must-Use Plugins</label>
                <label style="display:block; margin:6px 0;"><input type="checkbox" class="restore-comp" value="plugins" checked /> Plugins</label>
                <label style="display:block; margin:6px 0;"><input type="checkbox" class="restore-comp" value="themes" checked /> Themes</label>
                <label style="display:block; margin:6px 0;"><input type="checkbox" class="restore-comp" value="others" checked /> Other Content</label>
                <p style="color:#666; font-size:12px; margin:8px 0 0;">Media is synced from SharePoint/OneDrive after restore.</p>
            </div>
            <div style="text-align:right;">
                <button type="button" class="button" id="restore-dialog-cancel">Cancel</button>
                <button type="button" class="button button-primary" id="restore-dialog-confirm" style="margin-left:8px;">Restore Selected</button>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Expand/collapse toggle for plugin/theme lists
    $('.backup-expand-toggle').click(function(e) {
        e.preventDefault();
        var target = $('#' + $(this).data('target'));
        target.slideToggle(200);
        var lbl = $(this).text().indexOf('▾') !== -1 ? '[select individually ▴]' : '[select individually ▾]';
        $(this).text(lbl);
    });

    // "Select All" toggles
    $('.backup-select-all').change(function() {
        var group = $(this).data('group');
        $('.' + group).prop('checked', $(this).is(':checked'));
    });
    // Keep "Select All" in sync when individual items change
    $(document).on('change', '.backup-plugin-item, .backup-theme-item', function() {
        var cls = $(this).hasClass('backup-plugin-item') ? 'backup-plugin-item' : 'backup-theme-item';
        var all = $('.' + cls).length;
        var checked = $('.' + cls + ':checked').length;
        $(this).closest('div').find('.backup-select-all').prop('checked', all === checked);
    });

    // Expand/collapse backup detail rows
    var loadedComponents = {};
    $(document).on('click', '.toggle-backup-details', function(e) {
        e.preventDefault();
        var jobId = $(this).data('job-id');
        var $detail = $('.backup-detail-row[data-job-id="' + jobId + '"]');
        var $icon = $(this).find('.dashicons');
        var visible = $detail.is(':visible');

        if (visible) {
            $detail.hide();
            $icon.css('transform', 'rotate(0deg)');
        } else {
            $detail.show();
            $icon.css('transform', 'rotate(90deg)');

            if (!loadedComponents[jobId]) {
                loadBackupComponents(jobId);
            }
        }
    });

    function loadBackupComponents(jobId) {
        var $detail = $('.backup-detail-row[data-job-id="' + jobId + '"]');
        var $loading = $detail.find('.backup-detail-loading');
        var $table = $detail.find('.backup-detail-table');

        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_get_backup_components',
            backup_id: jobId,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            $loading.hide();
            if (!response.success || !response.data.components.length) {
                $table.html('<p style="color:#999; margin:4px 0;">No component details available.</p>').show();
                return;
            }

            var entityLabels = {database:'Database', plugins:'Plugins', themes:'Themes', uploads:'Media / Uploads', media:'Media / Uploads', content:'Other Content', others:'Other Content'};
            var html = '<table class="widefat" style="margin:0; border:none; box-shadow:none;">';
            html += '<thead><tr><th>Component</th><th>File</th><th>Size</th><th></th></tr></thead><tbody>';

            var downloadBase = azure_plugin_ajax.ajax_url + '?action=azure_download_backup_blob&nonce=' + encodeURIComponent(azure_plugin_ajax.nonce) + '&blob=';

            response.data.components.forEach(function(c) {
                var label = entityLabels[c.entity] || c.entity;
                html += '<tr>';
                html += '<td>' + label + '</td>';
                html += '<td style="font-family:monospace; font-size:12px; word-break:break-all;">' + c.filename + '</td>';
                html += '<td>' + c.size_fmt + '</td>';
                html += '<td><a href="' + downloadBase + encodeURIComponent(c.blob) + '" class="button button-small" title="Download this file"><span class="dashicons dashicons-download" style="vertical-align:middle;"></span></a></td>';
                html += '</tr>';
            });
            html += '</tbody></table>';

            $table.html(html).show();
            loadedComponents[jobId] = true;
        }).fail(function() {
            $loading.hide();
            $table.html('<p style="color:#dc3232;">Failed to load component details.</p>').show();
        });
    }

    // Restore dialog: Select All toggle
    $('#restore-select-all').change(function() {
        $('.restore-comp').prop('checked', $(this).is(':checked'));
    });
    $(document).on('change', '.restore-comp', function() {
        var all = $('.restore-comp').length;
        var checked = $('.restore-comp:checked').length;
        $('#restore-select-all').prop('checked', all === checked);
    });

    // Handle backup actions
    $('.start-backup').click(function() {
        if (!confirm('Are you sure you want to start a manual backup? This may take several minutes.')) {
            return;
        }
        
        var button = $(this);
        startBackupWithProgress(button);
    });
    
    function startBackupWithProgress(button) {
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Starting...');

        var postData = {
            action: 'azure_start_backup',
            nonce: azure_plugin_ajax.nonce
        };

        // Collect selected plugins if the plugins type is checked
        if ($('#backup-type-plugins').is(':checked')) {
            var selPlugins = [];
            $('.backup-plugin-item:checked').each(function() { selPlugins.push($(this).val()); });
            if (selPlugins.length && selPlugins.length < $('.backup-plugin-item').length) {
                postData.selected_plugins = selPlugins;
            }
        }

        // Collect selected themes if the themes type is checked
        if ($('#backup-type-themes').is(':checked')) {
            var selThemes = [];
            $('.backup-theme-item:checked').each(function() { selThemes.push($(this).val()); });
            if (selThemes.length && selThemes.length < $('.backup-theme-item').length) {
                postData.selected_themes = selThemes;
            }
        }

        $.post(azure_plugin_ajax.ajax_url, postData, function(response) {
            // Parse JSON if response is a string
            if (typeof response === 'string') {
                try {
                    response = JSON.parse(response);
                } catch (e) {
                    alert('❌ Invalid response from server');
                    button.prop('disabled', false).html('<span class="dashicons dashicons-backup"></span> Start Manual Backup');
                    return;
                }
            }
            
            if (response && response.success && response.data.requires_progress) {
                showBackupProgress(response.data.backup_id, response.data.message);
                trackBackupProgress(response.data.backup_id);
            } else if (response && response.success) {
                alert('Backup started successfully!');
                setTimeout(function() { location.reload(); }, 2000);
            } else {
                var errorMsg = response && response.data ? response.data : 'Unknown error';
                alert('Failed to start backup: ' + errorMsg);
                button.prop('disabled', false).html('<span class="dashicons dashicons-backup"></span> Start Manual Backup');
            }
        }).fail(function() {
            alert('❌ Network error occurred');
            button.prop('disabled', false).html('<span class="dashicons dashicons-backup"></span> Start Manual Backup');
        });
    }
    
    function showBackupProgress(backupId, message) {
        $('#backup-progress-section').show();
        $('#backup-progress-name').text('Backup ID: ' + backupId);
        $('#backup-progress-status').text('Status: Initializing...');
        $('#backup-progress-message').text(message || 'Backup started successfully');
        $('#backup-progress-percent').text('0%');
        $('#backup-progress-bar').css('width', '0%');
        $('#backup-progress-actions').hide();
        
        $('html, body').animate({
            scrollTop: $('#backup-progress-section').offset().top - 20
        }, 500);
    }
    
    function trackBackupProgress(backupId) {
        var polling = false;
        var stopped = false;
        var directRunFired = false;

        function fireDirectRun() {
            if (directRunFired) return;
            directRunFired = true;
            updateProgressDisplay(3, 'running', 'Starting backup directly...');

            $.ajax({
                url: azure_plugin_ajax.ajax_url,
                type: 'POST',
                timeout: 1800000,
                data: {
                    action: 'azure_run_backup_now',
                    backup_id: backupId,
                    nonce: azure_plugin_ajax.nonce
                },
                success: function() {},
                error: function() {}
            });
        }

        function poll() {
            if (polling || stopped) return;
            polling = true;

            $.ajax({
                url: azure_plugin_ajax.ajax_url,
                type: 'POST',
                timeout: 30000,
                data: { action: 'azure_get_backup_progress', backup_id: backupId },
                success: function(response) {
                    polling = false;
                    if (typeof response === 'string') {
                        try { response = JSON.parse(response); } catch (e) { return; }
                    }
                    if (response && response.success) {
                        var data = response.data;
                        updateProgressDisplay(data.progress, data.status, data.message);

                        if (data.needs_direct_run) {
                            fireDirectRun();
                        }

                        if (data.status === 'completed' || data.status === 'failed' || data.status === 'error' || data.status === 'cancelled') {
                            stopped = true;
                            showBackupComplete(data);
                        }
                    }
                },
                error: function() {
                    polling = false;
                }
            });
        }

        var progressInterval = setInterval(poll, 3000);
        poll();

        // Safety timeout - stop checking after 30 minutes
        setTimeout(function() {
            stopped = true;
            clearInterval(progressInterval);
            $('#backup-progress-message').text('Backup is taking longer than expected. Please check the logs for status.');
            $('#backup-progress-actions').show();
        }, 30 * 60 * 1000);
    }
    
    function updateProgressDisplay(progress, status, message) {
        $('#backup-progress-bar').css('width', progress + '%');
        $('#backup-progress-percent').text(progress + '%');
        $('#backup-progress-status').text('Status: ' + (status || 'running'));
        $('#backup-progress-message').text(message || 'Processing...');
        
        if (status === 'running') {
            $('#backup-progress-bar').css('background', 'linear-gradient(90deg, #0073aa 0%, #005a87 100%)');
        } else if (status === 'failed' || status === 'error') {
            $('#backup-progress-bar').css('background', 'linear-gradient(90deg, #dc3232 0%, #b32d2e 100%)');
        }
    }
    
    function showBackupComplete(data) {
        if (data.status === 'completed') {
            $('#backup-progress-bar').css('background', 'linear-gradient(90deg, #46b450 0%, #399245 100%)');
            $('#backup-progress-percent').text('100%');
            $('#backup-progress-status').text('Status: Completed Successfully');
            $('#backup-progress-message').text('Backup completed successfully!');
        } else if (data.status === 'cancelled') {
            $('#backup-progress-bar').css('background', 'linear-gradient(90deg, #f0ad4e 0%, #d9963a 100%)');
            $('#backup-progress-percent').text('Cancelled');
            $('#backup-progress-status').text('Status: Cancelled');
            $('#backup-progress-message').text('Backup was cancelled.');
        } else {
            $('#backup-progress-bar').css('background', 'linear-gradient(90deg, #dc3232 0%, #b32d2e 100%)');
            $('#backup-progress-percent').text('Error');
            $('#backup-progress-status').text('Status: Failed');
            $('#backup-progress-message').text('Backup failed: ' + (data.message || 'Unknown error'));
        }
        
        $('#backup-progress-actions').show();
        $('.start-backup').prop('disabled', false).html('<span class="dashicons dashicons-backup"></span> Start Manual Backup');
        
        if (data.status === 'completed') {
            setTimeout(function() { location.reload(); }, 5000);
        }
    }
    
    window.hideBackupProgress = function() {
        $('#backup-progress-section').hide();
        $('.start-backup').prop('disabled', false).html('<span class="dashicons dashicons-backup"></span> Start Manual Backup');
    };
    
    window.refreshPage = function() {
        location.reload();
    };
    
    // Test Azure connection
    $('.test-azure-connection').click(function() {
        var button = $(this);
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Testing...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_test_sso_connection',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html('<span class="dashicons dashicons-cloud"></span> Test Azure Connection');
            
            if (typeof response === 'string') {
                try {
                    response = JSON.parse(response);
                } catch (e) {
                    alert('❌ Invalid response from server');
                    return;
                }
            }
            
            if (response && response.success) {
                alert('✅ Azure connection successful!');
            } else {
                alert('❌ Connection failed: ' + (response && response.data ? response.data : 'Unknown error'));
            }
        }).fail(function() {
            button.prop('disabled', false).html('<span class="dashicons dashicons-cloud"></span> Test Azure Connection');
            alert('❌ Network error occurred');
        });
    });
    
    // Test Storage connection
    $('.test-storage-connection').click(function() {
        var button = $(this);
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Testing...');
        
        var storageAccount = $('input[name="azure_plugin_settings[backup_storage_account_name]"]').val();
        var storageKey = $('input[name="azure_plugin_settings[backup_storage_account_key]"]').val();
        var containerName = $('input[name="azure_plugin_settings[backup_storage_container_name]"]').val();
        
        if (!storageAccount || !storageKey) {
            button.prop('disabled', false).html('<span class="dashicons dashicons-cloud"></span> Test Storage Connection');
            alert('❌ Please fill in Storage Account Name and Access Key');
            return;
        }
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_test_storage_connection',
            storage_account: storageAccount,
            storage_key: storageKey,
            container_name: containerName,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html('<span class="dashicons dashicons-cloud"></span> Test Storage Connection');
            
            if (typeof response === 'string') {
                try {
                    response = JSON.parse(response);
                } catch (e) {
                    alert('❌ Invalid response from server');
                    return;
                }
            }
            
            if (response && response.success) {
                alert('✅ Storage connection successful!');
            } else {
                alert('❌ Storage connection failed: ' + (response && response.data ? response.data : 'Unknown error'));
            }
        }).fail(function() {
            button.prop('disabled', false).html('<span class="dashicons dashicons-cloud"></span> Test Storage Connection');
            alert('❌ Network error occurred');
        });
    });
    
    // Module toggle
    $('.backup-module-toggle').change(function() {
        var enabled = $(this).is(':checked');
        var statusText = $('.toggle-status');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_toggle_module',
            module: 'backup',
            enabled: enabled,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                statusText.text(enabled ? 'Enabled' : 'Disabled');
                if (enabled) {
                    $('.notice.notice-warning.inline').fadeOut();
                } else {
                    location.reload();
                }
            } else {
                $('.backup-module-toggle').prop('checked', !enabled);
                alert('Failed to toggle backup module: ' + (response.data || 'Unknown error'));
            }
        }).fail(function() {
            $('.backup-module-toggle').prop('checked', !enabled);
            alert('Network error occurred');
        });
    });
    
    // Handle restore (local backup) with component selection
    var pendingLocalRestoreId = null;

    var entityLabelsRestore = {
        database: 'Database', plugins: 'Plugins', themes: 'Themes',
        uploads: 'Media / Uploads', media: 'Media / Uploads',
        content: 'Other Content', others: 'Other Content'
    };

    $(document).on('click', '.restore-backup', function() {
        var backupId = $(this).data('backup-id');
        var hasEntities = $(this).data('has-entities') === 1 || $(this).data('has-entities') === '1';

        if (!confirm('Are you sure you want to restore this backup? This will overwrite selected content and cannot be undone.')) {
            return;
        }

        pendingLocalRestoreId = backupId;
        pendingRestoreBlob = null;

        if (hasEntities) {
            populateRestoreDialog(backupId, function() {
                $('#restore-component-dialog').show();
            });
        } else {
            resetRestoreDialog();
            $('#restore-component-dialog').show();
        }
    });

    function populateRestoreDialog(backupId, callback) {
        var $list = $('#restore-component-list');
        $list.html('<p style="color:#666;"><span class="spinner is-active" style="float:none; margin:0 6px 0 0;"></span> Loading components...</p>');
        $('#restore-component-dialog').show();

        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_get_backup_components',
            backup_id: backupId,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (!response.success) {
                resetRestoreDialog();
                if (callback) callback();
                return;
            }
            var entities = {};
            var skipEntities = ['uploads', 'media'];
            response.data.components.forEach(function(c) {
                if (skipEntities.indexOf(c.entity) === -1 && !entities[c.entity]) entities[c.entity] = true;
            });

            var html = '';
            Object.keys(entities).forEach(function(key) {
                var label = entityLabelsRestore[key] || key;
                html += '<label style="display:block; margin:6px 0;"><input type="checkbox" class="restore-comp" value="' + key + '" checked /> ' + label + '</label>';
            });
            html += '<p style="color:#666; font-size:12px; margin:8px 0 0;">Media is synced from SharePoint/OneDrive after restore.</p>';

            if (!html) {
                resetRestoreDialog();
            } else {
                $list.html(html);
                $('#restore-select-all').prop('checked', true);
            }
            if (callback) callback();
        }).fail(function() {
            resetRestoreDialog();
            if (callback) callback();
        });
    }

    function resetRestoreDialog() {
        var html = '';
        html += '<label style="display:block; margin:6px 0;"><input type="checkbox" class="restore-comp" value="database" checked /> Database</label>';
        html += '<label style="display:block; margin:6px 0;"><input type="checkbox" class="restore-comp" value="mu-plugins" checked /> Must-Use Plugins</label>';
        html += '<label style="display:block; margin:6px 0;"><input type="checkbox" class="restore-comp" value="plugins" checked /> Plugins</label>';
        html += '<label style="display:block; margin:6px 0;"><input type="checkbox" class="restore-comp" value="themes" checked /> Themes</label>';
        html += '<label style="display:block; margin:6px 0;"><input type="checkbox" class="restore-comp" value="others" checked /> Other Content</label>';
        html += '<p style="color:#666; font-size:12px; margin:8px 0 0;">Media is synced from SharePoint/OneDrive after restore.</p>';
        $('#restore-component-list').html(html);
        $('#restore-select-all').prop('checked', true);
    }

    $('#restore-dialog-confirm').off('click').on('click', function() {
        var selected = [];
        $('.restore-comp:checked').each(function() { selected.push($(this).val()); });
        if (selected.length === 0) {
            alert('Please select at least one component.');
            return;
        }
        $('#restore-component-dialog').hide();

        if (pendingRestoreBlob) {
            executeRemoteRestore(pendingRestoreBlob, selected);
            pendingRestoreBlob = null;
        } else if (pendingLocalRestoreId) {
            executeLocalRestore(pendingLocalRestoreId, selected);
            pendingLocalRestoreId = null;
        }
    });

    function executeLocalRestore(backupId, restoreTypes) {
        showRestoreProgress();

        var postData = {
            action: 'azure_restore_backup',
            backup_id: backupId,
            nonce: azure_plugin_ajax.nonce
        };
        if (restoreTypes) {
            postData.restore_types = restoreTypes;
        }

        $.ajax({
            url: azure_plugin_ajax.ajax_url,
            type: 'POST',
            timeout: 1800000,
            data: postData,
            success: function(response) {
                stopRestorePolling();
                if (response.success) {
                    setRestoreComplete('completed', 'Restore completed successfully!');
                } else {
                    setRestoreComplete('failed', 'Restore failed: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status) {
                stopRestorePolling();
                var msg = status === 'timeout'
                    ? 'The restore is taking longer than expected. It may still be running on the server.'
                    : 'Network error occurred. The operation may still be running on the server.';
                setRestoreComplete('failed', msg);
            }
        });
    }
    
    // Handle delete backup
    $(document).on('click', '.delete-backup', function() {
        var backupId = $(this).data('backup-id');
        
        if (!confirm('Are you sure you want to delete this backup? This action cannot be undone.')) {
            return;
        }
        
        var button = $(this);
        var parentRow = button.closest('tr');
        var detailRow = $('.backup-detail-row[data-job-id="' + backupId + '"]');
        
        button.prop('disabled', true).text('Deleting...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_delete_backup',
            backup_id: backupId,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                parentRow.fadeOut(function() { parentRow.remove(); });
                detailRow.fadeOut(function() { detailRow.remove(); });
            } else {
                alert('Delete failed: ' + (response.data || 'Unknown error'));
                button.prop('disabled', false).text('Delete');
            }
        }).fail(function() {
            alert('Network error occurred');
            button.prop('disabled', false).text('Delete');
        });
    });
    
    // Handle view error
    $(document).on('click', '.view-error', function() {
        var error = $(this).data('error');
        alert('Error Details:\n\n' + error);
    });
    
    // Clean up orphaned backup files
    $('.cleanup-backup-files').click(function() {
        if (!confirm('This will remove any orphaned backup files from the local server (temp directories and zip files not uploaded to Azure). Continue?')) {
            return;
        }
        
        var button = $(this);
        button.prop('disabled', true).html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span> Cleaning...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_cleanup_backup_files',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Clean Up Local Files');
            
            if (typeof response === 'string') {
                try {
                    response = JSON.parse(response);
                } catch (e) {
                    alert('❌ Invalid response from server');
                    return;
                }
            }
            
            if (response && response.success) {
                var msg = response.data.message;
                if (response.data.files_found && response.data.files_found.length > 0) {
                    msg += '\n\nFiles found before cleanup:';
                    response.data.files_found.forEach(function(f) {
                        var size = f.size ? ' (' + formatBytes(f.size) + ')' : '';
                        msg += '\n• ' + f.name + size;
                    });
                }
                alert('✅ ' + msg);
            } else {
                alert('❌ Cleanup failed: ' + (response && response.data ? response.data : 'Unknown error'));
            }
        }).fail(function() {
            button.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Clean Up Local Files');
            alert('❌ Network error occurred');
        });
    });
    
    function formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        var k = 1024;
        var sizes = ['B', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    // Sync remote backups from Azure
    $('.sync-remote-backups').click(function() {
        var button = $(this);
        var section = $('#remote-backups-section');
        var loading = $('#remote-backups-loading');
        var content = $('#remote-backups-content');

        button.prop('disabled', true);
        section.show();
        loading.show();
        content.html('');

        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_list_remote_backups',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            loading.hide();
            button.prop('disabled', false);

            if (!response.success) {
                content.html('<div class="notice notice-error inline"><p>' + (response.data || 'Failed to list remote backups.') + '</p></div>');
                return;
            }

            var blobs = response.data;
            if (!blobs || blobs.length === 0) {
                content.html('<p>No backups found in Azure Storage.</p>');
                return;
            }

            var html = '<table class="wp-list-table widefat fixed striped">';
            html += '<thead><tr><th>Backup</th><th>Format</th><th>Size</th><th>Last Modified</th><th>Local</th><th>Actions</th></tr></thead><tbody>';

            blobs.forEach(function(blob) {
                var localBadge = blob.in_local_db
                    ? '<span class="status-indicator success" style="font-size:12px;">Yes</span>'
                    : '<span class="status-indicator warning" style="font-size:12px;">No</span>';

                var typeBadge = (blob.type === 'v2')
                    ? '<span style="background:#0073aa;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">Split</span>'
                    : '<span style="background:#888;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">Legacy</span>';

                var components = blob.components ? ' (' + blob.components + ' files)' : '';

                html += '<tr>';
                html += '<td title="' + escHtml(blob.name) + '">' + escHtml(shortenBlobName(blob.name)) + components + '</td>';
                html += '<td>' + typeBadge + '</td>';
                html += '<td>' + formatBytes(blob.size) + '</td>';
                html += '<td>' + escHtml(blob.modified) + '</td>';
                html += '<td>' + localBadge + '</td>';
                html += '<td><button class="button button-small restore-remote-backup" data-blob="' + escHtml(blob.name) + '" data-type="' + (blob.type || 'v1') + '">Restore</button></td>';
                html += '</tr>';
            });

            html += '</tbody></table>';
            content.html(html);
        }).fail(function() {
            loading.hide();
            button.prop('disabled', false);
            content.html('<div class="notice notice-error inline"><p>Network error while connecting to Azure Storage.</p></div>');
        });
    });

    // Restore from a remote blob - show component selection for v2 backups
    var pendingRestoreBlob = null;

    $(document).on('click', '.restore-remote-backup', function() {
        var blobName = $(this).data('blob');
        var blobType = $(this).data('type') || 'v1';

        if (!confirm('Are you sure you want to restore from this Azure backup?\n\n' + shortenBlobName(blobName) + '\n\nThis will overwrite selected content and cannot be undone.')) {
            return;
        }

        if (blobType === 'v2') {
            pendingRestoreBlob = blobName;
            $('#restore-component-dialog').show();
            return;
        }

        executeRemoteRestore(blobName, null);
    });

    $('#restore-dialog-cancel').click(function() {
        $('#restore-component-dialog').hide();
        pendingRestoreBlob = null;
        pendingLocalRestoreId = null;
    });

    function executeRemoteRestore(blobName, restoreTypes) {
        showRestoreProgress();

        var postData = {
            action: 'azure_restore_remote_backup',
            blob_name: blobName,
            nonce: azure_plugin_ajax.nonce
        };
        if (restoreTypes) {
            postData.restore_types = restoreTypes;
        }

        $.ajax({
            url: azure_plugin_ajax.ajax_url,
            type: 'POST',
            timeout: 1800000,
            data: postData,
            success: function(response) {
                stopRestorePolling();
                if (response.success) {
                    setRestoreComplete('completed', 'Restore completed successfully!');
                } else {
                    setRestoreComplete('failed', 'Restore failed: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status) {
                stopRestorePolling();
                var msg = status === 'timeout'
                    ? 'The restore is taking longer than expected. It may still be running on the server.'
                    : 'Network error occurred. The operation may still be running on the server.';
                setRestoreComplete('failed', msg);
            }
        });
    }

    function shortenBlobName(name) {
        var parts = name.split('/');
        if (parts.length > 2) {
            return parts.slice(-2).join('/');
        }
        return name;
    }

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // --- Restore progress helpers ---
    var restoreInterval = null;

    function showRestoreProgress() {
        var $section = $('#restore-progress-section');
        $section.show();
        $('#restore-progress-bar').css({width: '0%', background: 'linear-gradient(90deg, #2196f3 0%, #1769aa 100%)'});
        $('#restore-progress-percent').text('0%');
        $('#restore-progress-status').text('Status: Initializing...');
        $('#restore-progress-message').text('Starting restore operation...');
        $('#restore-progress-actions').hide();

        $('html, body').animate({scrollTop: $section.offset().top - 20}, 500);

        restoreInterval = setInterval(pollRestoreProgress, 3000);
    }

    function pollRestoreProgress() {
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_get_restore_progress'
        }, function(response) {
            if (response && response.success && response.data) {
                var d = response.data;
                if (d.status === 'idle') return;

                $('#restore-progress-bar').css('width', d.progress + '%');
                $('#restore-progress-percent').text(d.progress + '%');
                $('#restore-progress-status').text('Status: ' + d.status);
                $('#restore-progress-message').text(d.message || '');

                if (d.status === 'completed' || d.status === 'failed') {
                    stopRestorePolling();
                    setRestoreComplete(d.status, d.message);
                }
            }
        });
    }

    function stopRestorePolling() {
        if (restoreInterval) {
            clearInterval(restoreInterval);
            restoreInterval = null;
        }
    }

    function setRestoreComplete(status, message) {
        stopRestorePolling();

        if (status === 'completed') {
            $('#restore-progress-bar').css({width: '100%', background: 'linear-gradient(90deg, #46b450 0%, #399245 100%)'});
            $('#restore-progress-percent').text('100%');
            $('#restore-progress-status').text('Status: Completed');
            $('#restore-progress-message').text(message);
        } else {
            $('#restore-progress-bar').css('background', 'linear-gradient(90deg, #dc3232 0%, #b32d2e 100%)');
            $('#restore-progress-percent').text('Error');
            $('#restore-progress-status').text('Status: Failed');
            $('#restore-progress-message').text(message);
        }

        $('#restore-progress-actions').show();
    }

    // Cancel all running backups
    $('.cancel-all-backups').click(function() {
        if (!confirm('Are you sure you want to cancel ALL running backup jobs? This will mark them as failed and stop any progress tracking.')) {
            return;
        }
        
        var button = $(this);
        button.prop('disabled', true).html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span> Cancelling...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_cancel_all_backups',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (typeof response === 'string') {
                try {
                    response = JSON.parse(response);
                } catch (e) {
                    alert('❌ Invalid response from server');
                    button.prop('disabled', false).html('<span class="dashicons dashicons-dismiss"></span> Cancel All Running Backups');
                    return;
                }
            }
            
            if (response && response.success) {
                alert('✅ ' + response.data.message);
                // Hide progress section if visible
                $('#backup-progress-section').hide();
                // Reload to show updated status
                location.reload();
            } else {
                alert('❌ Failed to cancel backups: ' + (response && response.data ? response.data : 'Unknown error'));
                button.prop('disabled', false).html('<span class="dashicons dashicons-dismiss"></span> Cancel All Running Backups');
            }
        }).fail(function() {
            alert('❌ Network error occurred');
            button.prop('disabled', false).html('<span class="dashicons dashicons-dismiss"></span> Cancel All Running Backups');
        });
    });
});
</script>