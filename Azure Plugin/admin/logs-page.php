<?php
if (!defined('ABSPATH')) {
    exit;
}

// Check if user is an administrator - this page is admin-only
if (!current_user_can('administrator')) {
    wp_die(__('You do not have sufficient permissions to access this page.', 'azure-plugin'));
}

// Get recent logs using the new formatted logger
$level_filter = $_GET['level'] ?? '';
$module_filter = $_GET['module'] ?? '';
$log_lines = Azure_Logger::get_formatted_logs(500, $level_filter, $module_filter);

// Get activity log statistics
$activity_stats = array();
global $wpdb;
$activity_table = Azure_Database::get_table_name('activity_log');

if ($activity_table) {
    $activity_stats['total_activities'] = $wpdb->get_var("SELECT COUNT(*) FROM {$activity_table}");
    $activity_stats['today_activities'] = $wpdb->get_var("SELECT COUNT(*) FROM {$activity_table} WHERE DATE(created_at) = CURDATE()");
    $activity_stats['errors_today'] = $wpdb->get_var("SELECT COUNT(*) FROM {$activity_table} WHERE DATE(created_at) = CURDATE() AND status = 'error'");
    
    // Get recent activity
    $recent_activity = $wpdb->get_results("SELECT * FROM {$activity_table} ORDER BY created_at DESC LIMIT 20");
} else {
    $recent_activity = array();
}
?>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
<div class="wrap">
    <h1>PTA Tools - Logs & Activity</h1>
<?php endif; ?>
    
    <div class="azure-logs-dashboard">

    <?php if (empty($GLOBALS['azure_system_tab']) || $GLOBALS['azure_system_tab'] === 'logs'): ?>
        <!-- Activity Statistics -->
        <?php if (!empty($activity_stats)): ?>
        <div class="activity-stats-section">
            <h2>Activity Overview</h2>
            
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-number"><?php echo intval($activity_stats['total_activities'] ?? 0); ?></div>
                    <div class="stat-label">Total Activities</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-number"><?php echo intval($activity_stats['today_activities'] ?? 0); ?></div>
                    <div class="stat-label">Today's Activities</div>
                </div>
                
                <div class="stat-card <?php echo intval($activity_stats['errors_today'] ?? 0) > 0 ? 'error' : 'success'; ?>">
                    <div class="stat-number"><?php echo intval($activity_stats['errors_today'] ?? 0); ?></div>
                    <div class="stat-label">Errors Today</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($log_lines); ?></div>
                    <div class="stat-label">Log Entries</div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- PTA Roles Admin Functions -->
        <div class="pta-admin-functions-section" style="margin-bottom: 30px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h2><span class="dashicons dashicons-groups" style="margin-right: 8px;"></span>PTA Roles Admin Functions</h2>
            <p class="description">Administrative functions for PTA Roles management. Use with caution.</p>
            
            <div class="pta-admin-buttons" style="margin-top: 15px; display: flex; gap: 15px; flex-wrap: wrap;">
                <button type="button" class="button button-primary import-roles-from-azure">
                    <span class="dashicons dashicons-download"></span>
                    One-Time Pull from Azure AD
                </button>
                
                <button type="button" class="button reimport-default-tables" style="background-color: #d63638; border-color: #d63638; color: white;">
                    <span class="dashicons dashicons-database-import"></span>
                    Reimport Default Tables
                </button>
            </div>
            
            <p class="description" style="margin-top: 15px; color: #666;">
                <strong>One-Time Pull from Azure AD:</strong> Imports job titles from Azure AD and maps them to PTA roles for all existing users.<br>
                <strong>Reimport Default Tables:</strong> Recreates PTA database tables and imports default roles/departments. <span style="color: #d63638;">Warning: This will delete existing data!</span>
            </p>
        </div>
        
        <!-- Diagnostics API Key -->
        <?php if (class_exists('Azure_Diagnostics_API')): ?>
        <div class="diagnostics-api-section" style="margin-bottom: 30px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h2><span class="dashicons dashicons-rest-api" style="margin-right: 8px;"></span>Diagnostics API</h2>
            <p class="description">Remote read-only API for monitoring this site. Endpoints are at <code>/wp-json/pta-tools/v1/diagnostics/...</code></p>
            <div style="margin-top: 12px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <label style="font-weight: 600;">API Key:</label>
                <input type="text" id="diag-api-key" value="<?php echo esc_attr(Azure_Diagnostics_API::get_api_key()); ?>" readonly
                       style="width: 420px; font-family: monospace; font-size: 13px; background: #f6f7f7;" />
                <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('diag-api-key').value); this.textContent='Copied!'; setTimeout(()=>this.textContent='Copy', 1500);">Copy</button>
                <button type="button" class="button" id="regen-diag-key">Regenerate</button>
            </div>
            <p class="description" style="margin-top: 10px;">
                Pass this key as the <code>X-Diag-Key</code> header or <code>?key=</code> query param. Available endpoints:
                <code>/health</code>, <code>/logs</code>, <code>/php-errors</code>, <code>/cron</code>, <code>/options</code>, <code>/modules</code>, <code>/tables</code>
            </p>
        </div>
        <?php endif; ?>

        <!-- Log Controls -->
        <div class="log-controls-section">
            <h2>Log Management</h2>
            
            <div class="log-controls">
                <button type="button" class="button refresh-logs">
                    <span class="dashicons dashicons-update"></span>
                    Refresh Logs
                </button>
                
                <button type="button" class="button clear-logs">
                    <span class="dashicons dashicons-trash"></span>
                    Clear Logs
                </button>
                
                <button type="button" class="button download-logs">
                    <span class="dashicons dashicons-download"></span>
                    Download Logs
                </button>
                
                <select id="log-level-filter" class="log-level-filter">
                    <option value="">All Levels</option>
                    <option value="ERROR">Errors Only</option>
                    <option value="WARNING">Warnings Only</option>
                    <option value="INFO">Info Only</option>
                    <option value="DEBUG">Debug Only</option>
                </select>
                
                <select id="module-filter" class="module-filter">
                    <option value="">All Modules</option>
                    <option value="sso">SSO</option>
                    <option value="backup">Backup</option>
                    <option value="calendar">Calendar</option>
                    <option value="email">Email</option>
                    <option value="pta">PTA Roles</option>
                    <option value="admin">Admin</option>
                    <option value="system">System</option>
                </select>
            </div>
        </div>
        
        <!-- Debug Logs Viewer -->
        <div class="debug-logs-section">
            <h2>Debug Logs</h2>
            
            <?php if (!empty($log_lines)): ?>
            <div class="log-viewer" id="log-content">
                <?php foreach ($log_lines as $line): ?>
                    <?php if (trim($line)): ?>
                        <?php
                        // Parse log line: "MM-DD-YYYY HH:MM:SS [Module] - LEVEL - message"
                        $level_class = 'info';
                        if (strpos($line, '- ERROR -') !== false) $level_class = 'error';
                        elseif (strpos($line, '- WARNING -') !== false) $level_class = 'warning';
                        elseif (strpos($line, '- DEBUG -') !== false) $level_class = 'debug';
                        
                        // Extract parts for better formatting
                        if (preg_match('/^(\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2}) (\[.*?\]) - (\w+) - (.*)$/', $line, $matches)) {
                            $timestamp = $matches[1];
                            $module = $matches[2];
                            $level = $matches[3];
                            $message = $matches[4];
                        } else {
                            $timestamp = '';
                            $module = '';
                            $level = '';
                            $message = $line;
                        }
                        ?>
                    <div class="log-line <?php echo $level_class; ?>" data-level="<?php echo strtolower($level); ?>" data-module="<?php echo strtolower(trim($module, '[]')); ?>">
                        <?php if ($timestamp): ?>
                            <span class="log-timestamp"><?php echo esc_html($timestamp); ?></span> <span class="log-module module-badge module-<?php echo strtolower(trim($module, '[]')); ?>"><?php echo esc_html($module); ?></span> <span class="log-level level-<?php echo strtolower($level); ?>"><?php echo esc_html($level); ?></span> <span class="log-message"><?php echo esc_html($message); ?></span>
                        <?php else: ?>
                            <?php echo esc_html($line); ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="log-viewer" id="log-content">
                <div class="log-line info">No logs available yet. Activity will appear here as you use the plugin modules.</div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Recent Activity -->
        <?php if (!empty($recent_activity)): ?>
        <div class="recent-activity-section">
            <h2>Recent Activity</h2>
            
            <table class="activity-table widefat striped">
                <thead>
                    <tr>
                        <th>Module</th>
                        <th>Action</th>
                        <th>Object</th>
                        <th>User</th>
                        <th>Status</th>
                        <th>Time</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_activity as $activity): ?>
                    <?php 
                    $user_name = 'System';
                    if ($activity->user_id) {
                        $user = get_user_by('id', $activity->user_id);
                        $user_name = $user ? $user->display_name : 'User #' . $activity->user_id;
                    }
                    ?>
                    <tr>
                        <td>
                            <span class="module-badge module-<?php echo esc_attr($activity->module); ?>">
                                <?php echo esc_html(strtoupper($activity->module)); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($activity->action); ?></td>
                        <td>
                            <?php if ($activity->object_type): ?>
                                <?php echo esc_html($activity->object_type); ?>
                                <?php if ($activity->object_id): ?>
                                    <code>#<?php echo esc_html($activity->object_id); ?></code>
                                <?php endif; ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($user_name); ?></td>
                        <td>
                            <span class="status-indicator <?php echo esc_attr($activity->status); ?>">
                                <?php echo esc_html(ucfirst($activity->status)); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($activity->created_at); ?></td>
                        <td>
                            <?php if ($activity->details): ?>
                            <button type="button" class="button button-small view-details" data-details="<?php echo esc_attr($activity->details); ?>">
                                View
                            </button>
                            <?php else: ?>
                            -
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- System Information -->
        <div class="system-info-section">
            <h2>System Information</h2>
            
            <div class="system-info-grid">
                <div class="info-card">
                    <h4>WordPress</h4>
                    <ul>
                        <li><strong>Version:</strong> <?php echo get_bloginfo('version'); ?></li>
                        <li><strong>Multisite:</strong> <?php echo is_multisite() ? 'Yes' : 'No'; ?></li>
                        <li><strong>Debug:</strong> <?php echo defined('WP_DEBUG') && WP_DEBUG ? 'Enabled' : 'Disabled'; ?></li>
                        <li><strong>Memory Limit:</strong> <?php echo ini_get('memory_limit'); ?></li>
                    </ul>
                </div>
                
                <div class="info-card">
                    <h4>Server</h4>
                    <ul>
                        <li><strong>PHP Version:</strong> <?php echo PHP_VERSION; ?></li>
                        <li><strong>Web Server:</strong> <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?></li>
                        <li><strong>Max Execution Time:</strong> <?php echo ini_get('max_execution_time'); ?>s</li>
                        <li><strong>Upload Max Size:</strong> <?php echo ini_get('upload_max_filesize'); ?></li>
                    </ul>
                </div>
                
                <div class="info-card">
                    <h4>PTA Tools</h4>
                    <ul>
                        <li><strong>Version:</strong> <?php echo AZURE_PLUGIN_VERSION; ?></li>
                        <li><strong>Path:</strong> <code><?php echo AZURE_PLUGIN_PATH; ?></code></li>
                        <li><strong>URL:</strong> <code><?php echo AZURE_PLUGIN_URL; ?></code></li>
                        <li><strong>Log File:</strong> <?php echo file_exists(AZURE_PLUGIN_PATH . 'logs.md') ? 'Exists' : 'Not Found'; ?></li>
                    </ul>
                </div>
                
                <div class="info-card">
                    <h4>Modules Status</h4>
                    <ul>
                        <li><strong>SSO:</strong> <?php echo Azure_Settings::is_module_enabled('sso') ? '✅ Enabled' : '❌ Disabled'; ?></li>
                        <li><strong>Backup:</strong> <?php echo Azure_Settings::is_module_enabled('backup') ? '✅ Enabled' : '❌ Disabled'; ?></li>
                        <li><strong>Calendar:</strong> <?php echo Azure_Settings::is_module_enabled('calendar') ? '✅ Enabled' : '❌ Disabled'; ?></li>
                        <li><strong>Email:</strong> <?php echo Azure_Settings::is_module_enabled('email') ? '✅ Enabled' : '❌ Disabled'; ?></li>
                        <li><strong>PTA Roles:</strong> <?php echo Azure_Settings::is_module_enabled('pta') ? '✅ Enabled' : '❌ Disabled'; ?></li>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- Export/Import Settings -->
        <div class="settings-export-section">
            <h2>Settings Management</h2>
            
            <div class="export-import-controls">
                <div class="export-section">
                    <h4>Export Settings</h4>
                    <p>Export your Azure plugin settings for backup or migration.</p>
                    <button type="button" class="button export-settings">
                        <span class="dashicons dashicons-download"></span>
                        Export Settings
                    </button>
                </div>
                
                <div class="import-section">
                    <h4>Import Settings</h4>
                    <p>Import settings from a previously exported file.</p>
                    <input type="file" id="import-settings-file" accept=".json" style="display: none;">
                    <button type="button" class="button import-settings">
                        <span class="dashicons dashicons-upload"></span>
                        Import Settings
                    </button>
                </div>
            </div>
        </div>

    <?php endif; // end logs tab ?>

    <?php if (empty($GLOBALS['azure_system_tab']) || $GLOBALS['azure_system_tab'] === 'critical'): ?>
        <!-- Organization Settings -->
        <?php
        $org_domain = Azure_Settings::get_setting('org_domain', '');
        $org_name = Azure_Settings::get_setting('org_name', '');
        $org_team_name = Azure_Settings::get_setting('org_team_name', '');
        $org_admin_email = Azure_Settings::get_setting('org_admin_email', '');
        $raw_org_domain = Azure_Settings::get_all_settings()['org_domain'] ?? '';
        ?>
        <div style="margin-top: 30px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h2 style="margin: 0 0 15px; display: flex; align-items: center; gap: 8px;">
                <span class="dashicons dashicons-admin-site-alt3"></span> Organization Settings
            </h2>
            <p class="description">These are typically set during the Setup Wizard. You can update them here if needed (e.g. after a site restore).</p>
            <table class="form-table" style="margin-top: 10px;">
                <tr>
                    <th scope="row"><label for="org_domain">Organization Domain</label></th>
                    <td>
                        <input type="text" id="org_domain" class="regular-text org-setting-field" value="<?php echo esc_attr($org_domain); ?>" placeholder="e.g. yourptsa.net" />
                        <?php if (empty($raw_org_domain) && !empty($org_domain)): ?>
                            <p class="description"><em>Auto-derived from site URL. Save to make it explicit.</em></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="org_name">Organization Name</label></th>
                    <td><input type="text" id="org_name" class="regular-text org-setting-field" value="<?php echo esc_attr($org_name); ?>" placeholder="e.g. Maple Elementary PTSA" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="org_team_name">Team Name</label></th>
                    <td><input type="text" id="org_team_name" class="regular-text org-setting-field" value="<?php echo esc_attr($org_team_name); ?>" placeholder="e.g. Maple PTSA Team" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="org_admin_email">Admin Email (FROM address)</label></th>
                    <td><input type="email" id="org_admin_email" class="regular-text org-setting-field" value="<?php echo esc_attr($org_admin_email); ?>" placeholder="e.g. admin@yourptsa.net" /></td>
                </tr>
            </table>
            <button type="button" class="button button-primary save-org-settings" style="margin-top: 10px;">
                <span class="dashicons dashicons-saved" style="vertical-align: middle; line-height: 1; margin-right: 4px;"></span>
                Save Organization Settings
            </button>
            <span id="org-settings-result" style="display: none; margin-left: 10px;"></span>
        </div>

        <!-- Danger Zone -->
        <?php
        if (!class_exists('Azure_Platform_Sync')) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-platform-sync.php';
        }
        $platform_sync_status = Azure_Platform_Sync::get_status();
        ?>
        <div style="margin-top: 30px; padding: 20px; background: #fff; border: 2px solid #d63638; border-radius: 4px;">
            <h2 style="color: #d63638; margin: 0 0 15px; display: flex; align-items: center; gap: 8px;">
                <span class="dashicons dashicons-warning"></span> Danger Zone
            </h2>
            <div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-start;">
                <div style="flex: 1; min-width: 250px;">
                    <h4 style="margin: 0 0 5px;">Clear Media Library</h4>
                    <p class="description" style="margin: 0 0 10px;">Deletes ALL WordPress media attachments, their local files, and OneDrive mappings. Use before restoring a backup to start from a clean state.</p>
                    <button type="button" class="button clear-media-library-btn" style="background-color: #d63638; border-color: #d63638; color: white;">
                        <span class="dashicons dashicons-trash" style="vertical-align: middle; line-height: 1; margin-right: 4px;"></span>
                        Clear Entire Media Library
                    </button>
                    <span id="clear-media-result" style="display: none; margin-left: 10px;"></span>
                </div>

                <div style="flex: 1; min-width: 280px;">
                    <h4 style="margin: 0 0 5px;">Sync Production DB → Staging DB</h4>
                    <p class="description" style="margin: 0 0 10px;">
                        Overwrites the staging database with a fresh copy of production (pages, posts, settings) and rewrites URLs. Use before testing plugin or theme changes on staging so it reflects live content.
                    </p>
                    <?php if (!empty($platform_sync_status['available'])) : ?>
                        <p class="description" style="margin: 0 0 10px;">
                            <strong>Staging DB:</strong> <code><?php echo esc_html($platform_sync_status['staging_database']); ?></code>
                            <?php if (!empty($platform_sync_status['staging_site_url'])) : ?>
                                &middot; <strong>Staging URL:</strong> <?php echo esc_html($platform_sync_status['staging_site_url']); ?>
                            <?php endif; ?>
                        </p>
                        <button type="button" class="button" id="azure-sync-prod-to-staging-db" style="background-color: #d63638; border-color: #d63638; color: white;">
                            <span class="dashicons dashicons-database-import" style="vertical-align: middle; line-height: 1; margin-right: 4px;"></span>
                            Sync Prod DB to Staging DB
                        </button>
                        <span id="azure-sync-prod-to-staging-status" style="margin-left: 10px;"></span>
                    <?php else : ?>
                        <p class="description" style="color: #50575e;">
                            <?php echo esc_html($platform_sync_status['reason'] ?? __('Sync is not available on this site.', 'azure-plugin')); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; // end critical tab ?>

    </div>
<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
</div>
<?php endif; ?>

<script>
jQuery(document).ready(function($) {
    // Refresh logs
    $('.refresh-logs').click(function() {
        var button = $(this);
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Refreshing...');
        
        // Debug: Check if azure_plugin_ajax is available
        console.log('System Logs Debug: azure_plugin_ajax object:', typeof azure_plugin_ajax !== 'undefined' ? azure_plugin_ajax : 'undefined');
        
        if (typeof azure_plugin_ajax === 'undefined') {
            button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Refresh Logs');
            alert('JavaScript not loaded properly. Please refresh the page.');
            return;
        }
        
        var level_filter = $('#log-level-filter').val() || '';
        var module_filter = $('#module-filter').val() || '';
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_refresh_logs',
            level: level_filter,
            module: module_filter,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Refresh Logs');
            
            // Debug: Log the full response
            console.log('System Logs AJAX Response:', response);
            console.log('Response type:', typeof response);
            
            // Parse JSON if response is a string
            if (typeof response === 'string') {
                try {
                    response = JSON.parse(response);
                    console.log('JSON parsed successfully:', response);
                } catch (e) {
                    console.log('JSON parse failed:', e);
                    alert('❌ Invalid JSON response from server');
                    return;
                }
            }
            
            console.log('Response success:', response ? response.success : 'response is null/undefined');
            console.log('Response data:', response ? response.data : 'response is null/undefined');
            
            if (response && response.success) {
                $('#log-content').html(response.data.html);
                $('.stat-card:last-child .stat-number').text(response.data.count);
                
                // Scroll to bottom to show newest logs
                var logViewer = $('#log-content')[0];
                logViewer.scrollTop = logViewer.scrollHeight;
            } else {
                var errorMsg = 'Unknown error';
                if (response && response.data) {
                    errorMsg = response.data;
                } else if (response) {
                    errorMsg = 'Invalid response format';
                } else {
                    errorMsg = 'No response from server';
                }
                alert('❌ Failed to refresh logs: ' + errorMsg);
            }
        }).fail(function(jqXHR, textStatus, errorThrown) {
            button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Refresh Logs');
            console.log('System Logs AJAX Failed:', {jqXHR: jqXHR, textStatus: textStatus, errorThrown: errorThrown});
            console.log('Response Text:', jqXHR.responseText);
            alert('❌ Network error while refreshing logs: ' + textStatus + ' - ' + errorThrown);
        });
    });
    
    // Clear logs
    $('.clear-logs').click(function() {
        if (!confirm('Are you sure you want to clear all logs? This action cannot be undone.')) {
            return;
        }
        
        var button = $(this);
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Clearing...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_clear_logs',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Clear Logs');
            
            if (response.success) {
                $('#log-content').html('<div class="log-line info">Logs cleared successfully.</div>');
                $('.stat-card:last-child .stat-number').text('0');
            } else {
                alert('❌ Failed to clear logs: ' + (response.data || 'Unknown error'));
            }
        });
    });
    
    // Download logs
    $('.download-logs').click(function() {
        var button = $(this);
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Preparing...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_download_logs',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Download Logs');
            
            if (response.success) {
                var blob = new Blob([response.data.content], { type: 'text/plain' });
                var url = window.URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.style.display = 'none';
                a.href = url;
                a.download = response.data.filename;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
            } else {
                alert('❌ Failed to prepare logs for download: ' + (response.data || 'Unknown error'));
            }
        }).fail(function() {
            button.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Download Logs');
            alert('❌ Network error while downloading logs');
        });
    });
    
    // Filter logs
    $('#log-level-filter, #module-filter').change(function() {
        $('.refresh-logs').click(); // Trigger refresh with new filters
    });
    
    // Auto-refresh logs every 10 seconds
    setInterval(function() {
        if ($('#log-content').length && !$('.refresh-logs').prop('disabled')) {
            $('.refresh-logs').click();
        }
    }, 10000);
    
    // Initialize: Load logs on page load
    $(document).ready(function() {
        $('.refresh-logs').click();
    });
    
    // View activity details
    $('.view-details').click(function() {
        var details = $(this).data('details');
        try {
            var parsed = JSON.parse(details);
            details = JSON.stringify(parsed, null, 2);
        } catch (e) {
            // Use raw details if not JSON
        }
        
        alert('Activity Details:\n\n' + details);
    });
    
    // Export settings
    $('.export-settings').click(function() {
        var button = $(this);
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Exporting...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_export_settings',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Export Settings');
            
            if (response.success) {
                var blob = new Blob([response.data], { type: 'application/json' });
                var url = window.URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.style.display = 'none';
                a.href = url;
                a.download = 'azure-plugin-settings-' + new Date().toISOString().slice(0, 10) + '.json';
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
            } else {
                alert('❌ Failed to export settings: ' + (response.data || 'Unknown error'));
            }
        });
    });
    
    // Import settings
    $('.import-settings').click(function() {
        $('#import-settings-file').click();
    });
    
    $('#import-settings-file').change(function() {
        var file = this.files[0];
        if (!file) return;
        
        var reader = new FileReader();
        reader.onload = function(e) {
            if (!confirm('Are you sure you want to import these settings? This will overwrite your current configuration.')) {
                return;
            }
            
            $.post(azure_plugin_ajax.ajax_url, {
                action: 'azure_import_settings',
                settings_data: e.target.result,
                nonce: azure_plugin_ajax.nonce
            }, function(response) {
                if (response.success) {
                    alert('✅ Settings imported successfully! The page will reload.');
                    location.reload();
                } else {
                    alert('❌ Failed to import settings: ' + (response.data || 'Unknown error'));
                }
            });
        };
        reader.readAsText(file);
    });
    
    // Auto-refresh activity
    setInterval(function() {
        if ($('.recent-activity-section').length) {
            // Silently refresh activity table
            $.post(azure_plugin_ajax.ajax_url, {
                action: 'azure_get_recent_activity',
                nonce: azure_plugin_ajax.nonce
            }, function(response) {
                if (response.success && response.data) {
                    $('.activity-table tbody').html(response.data);
                }
            });
        }
    }, 30000); // Refresh every 30 seconds
});
</script>

<style>
.activity-stats-section {
    margin-bottom: 30px;
}

.log-controls-section {
    margin-bottom: 20px;
}

.log-controls {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.log-level-filter,
.module-filter {
    min-width: 120px;
}

.debug-logs-section {
    margin-bottom: 30px;
}

.log-viewer {
    background: #1e1e1e;
    color: #f0f0f0;
    font-family: 'Courier New', monospace;
    font-size: 12px;
    line-height: 1.4;
    padding: 20px;
    border-radius: 4px;
    min-height: 800px;
    max-height: 1200px;
    overflow-y: auto;
    white-space: pre-wrap;
    word-wrap: break-word;
    border: 2px solid #333;
}

.log-line {
    margin-bottom: 1px;
    padding: 1px 0;
    display: block;
    white-space: nowrap;
}

.log-line.error {
    color: #ff6b6b;
}

.log-line.warning {
    color: #ffd93d;
}

.log-line.info {
    color: #74c0fc;
}

.log-line.debug {
    color: #95f985;
}

.log-timestamp {
    color: #00a0d2;
    font-weight: bold;
    margin-right: 8px;
}

.log-level {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 10px;
    font-weight: bold;
    margin-right: 8px;
    min-width: 50px;
    text-align: center;
}

.log-level.level-error {
    background-color: #dc3545;
    color: white;
}

.log-level.level-warning {
    background-color: #ffc107;
    color: #212529;
}

.log-level.level-info {
    background-color: #007cba;
    color: white;
}

.log-level.level-debug {
    background-color: #6c757d;
    color: white;
}

.log-message {
    margin-left: 4px;
}

.activity-table {
    margin-top: 15px;
}

.activity-table th,
.activity-table td {
    padding: 10px;
}

.module-badge {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: bold;
    color: white;
}

.module-badge.module-sso {
    background: #0073aa;
}

.module-badge.module-backup {
    background: #46b450;
}

.module-badge.module-calendar {
    background: #dc3232;
}

.module-badge.module-email {
    background: #ffb900;
}

.module-badge.module-admin {
    background: #826eb4;
}

.module-badge.module-pta {
    background: #d63638;
}

.module-badge.module-system {
    background: #555;
}

.system-info-section {
    margin: 30px 0;
}

.system-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-top: 15px;
}

.info-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    padding: 20px;
}

.info-card h4 {
    margin-top: 0;
    color: #0073aa;
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
}

.info-card ul {
    margin: 0;
    padding: 0;
    list-style: none;
}

.info-card li {
    padding: 8px 0;
    border-bottom: 1px solid #f0f0f0;
    font-size: 13px;
}

.info-card li:last-child {
    border-bottom: none;
}

.info-card code {
    font-size: 11px;
    background: #f9f9f9;
    padding: 2px 4px;
    border-radius: 3px;
}

.settings-export-section {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    padding: 20px;
    margin-top: 30px;
}

.export-import-controls {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 30px;
    margin-top: 15px;
}

.export-section,
.import-section {
    padding: 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: #f9f9f9;
}

.export-section h4,
.import-section h4 {
    margin-top: 0;
    color: #333;
}

.export-section p,
.import-section p {
    color: #666;
    font-size: 13px;
}

<?php
// Helper method for log level classes (this would be in the admin class)
function get_log_level_class($line) {
    if (strpos($line, '[ERROR]') !== false) return 'error';
    if (strpos($line, '[WARNING]') !== false) return 'warning';
    if (strpos($line, '[DEBUG]') !== false) return 'debug';
    return 'info';
}
?>

/* PTA Admin Functions Section */
.pta-admin-functions-section .button .dashicons {
    vertical-align: middle;
    line-height: 1;
    margin-right: 5px;
}
</style>

<script>
jQuery(document).ready(function($) {
    // One-Time Pull from Azure AD
    $('.import-roles-from-azure').click(function() {
        var button = $(this);
        var originalHtml = button.html();

        if (!confirm('⚠️ Warning: This will attempt to import roles from Azure AD job titles for all existing WordPress users linked to Azure AD.\n\nThis is a ONE-TIME import and will not overwrite existing assignments unless a role is explicitly removed and then re-imported.\n\nAre you sure you want to continue?')) {
            return;
        }

        button.prop('disabled', true).html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span> Importing...');

        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_import_roles_from_azure',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html(originalHtml);

            if (response.success) {
                var message = '✅ ' + response.data.message;
                if (response.data.errors && response.data.errors.length > 0) {
                    message += '\n\nWarnings/Errors:\n' + response.data.errors.slice(0, 10).join('\n');
                    if (response.data.errors.length > 10) {
                        message += '\n... and ' + (response.data.errors.length - 10) + ' more';
                    }
                }
                alert(message);
            } else {
                alert('❌ One-time import failed:\n\n' + (response.data || 'Unknown error'));
            }
        }).fail(function(xhr) {
            button.prop('disabled', false).html(originalHtml);
            alert('❌ One-time import failed due to network error:\n\n' + (xhr.responseJSON ? xhr.responseJSON.data : 'Unknown error'));
            console.error('One-time import AJAX error:', xhr);
        });
    });

    // Reimport Default Tables
    $('.reimport-default-tables').click(function() {
        var button = $(this);
        var originalHtml = button.html();

        if (!confirm('⚠️ DANGER: This will DELETE all existing PTA roles, departments, and assignments, then reimport the default data.\n\nThis action CANNOT be undone!\n\nAre you absolutely sure you want to continue?')) {
            return;
        }

        // Double confirmation for destructive action
        if (!confirm('🚨 FINAL WARNING: All PTA data will be permanently deleted!\n\nType "DELETE" mentally and click OK to proceed.')) {
            return;
        }

        button.prop('disabled', true).html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span> Reimporting...');

        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_reimport_default_tables',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html(originalHtml);

            if (response.success) {
                alert('✅ Default tables reimported successfully!\n\n' + (response.data.message || ''));
            } else {
                alert('❌ Reimport failed:\n\n' + (response.data || 'Unknown error'));
            }
        }).fail(function(xhr) {
            button.prop('disabled', false).html(originalHtml);
            alert('❌ Reimport failed due to network error:\n\n' + (xhr.responseJSON ? xhr.responseJSON.data : 'Unknown error'));
            console.error('Reimport AJAX error:', xhr);
        });
    });

    // Clear Media Library
    $('.clear-media-library-btn').click(function() {
        if (!confirm('WARNING: This will permanently delete ALL media attachments, their local files, and OneDrive mappings.\n\nThis CANNOT be undone!\n\nAre you sure?')) {
            return;
        }
        if (!confirm('FINAL CONFIRMATION: ALL media will be deleted. Click OK to proceed.')) {
            return;
        }

        var $btn = $(this);
        var originalHtml = $btn.html();
        var $result = $('#clear-media-result');
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear; vertical-align: middle; line-height: 1; margin-right: 4px;"></span> Clearing...');
        $result.hide();

        function clearBatch() {
            $.ajax({
                url: azure_plugin_ajax.ajax_url,
                type: 'POST',
                timeout: 120000,
                data: { action: 'azure_clear_media_library', nonce: azure_plugin_ajax.nonce },
                success: function(response) {
                    if (!response.success) {
                        $btn.prop('disabled', false).html(originalHtml);
                        $result.css('color', '#d63638').text('Failed: ' + (response.data || 'Unknown error')).show();
                        return;
                    }
                    var d = response.data;
                    $result.css('color', '#2271b1').text(d.message).show();
                    if (!d.done) {
                        clearBatch();
                    } else {
                        $btn.prop('disabled', false).html(originalHtml);
                        $result.css('color', '#00a32a');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).html(originalHtml);
                    $result.css('color', '#d63638').text('Request failed or timed out.').show();
                }
            });
        }

        clearBatch();
    });

    // Save Organization Settings
    $('.save-org-settings').click(function() {
        var $btn = $(this);
        var $result = $('#org-settings-result');
        var originalHtml = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span> Saving...');
        $result.hide();

        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_save_org_settings',
            nonce: azure_plugin_ajax.nonce,
            org_domain: $('#org_domain').val(),
            org_name: $('#org_name').val(),
            org_team_name: $('#org_team_name').val(),
            org_admin_email: $('#org_admin_email').val()
        }, function(response) {
            $btn.prop('disabled', false).html(originalHtml);
            if (response.success) {
                $result.css('color', '#00a32a').text('Settings saved.').show();
                setTimeout(function() { $result.fadeOut(); }, 3000);
            } else {
                $result.css('color', '#d63638').text('Failed: ' + (response.data || 'Unknown error')).show();
            }
        }).fail(function() {
            $btn.prop('disabled', false).html(originalHtml);
            $result.css('color', '#d63638').text('Network error.').show();
        });
    });

    // Regenerate Diagnostics API key
    $('#regen-diag-key').on('click', function() {
        if (!confirm('Regenerate the Diagnostics API key? Any existing integrations using the old key will stop working.')) return;
        var $btn = $(this);
        $btn.prop('disabled', true).text('Regenerating...');
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_regen_diag_key',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            $btn.prop('disabled', false).text('Regenerate');
            if (response.success) {
                $('#diag-api-key').val(response.data.key);
            } else {
                alert('Failed: ' + (response.data || 'Unknown error'));
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Regenerate');
        });
    });
});
</script>
