<?php
if (!defined('ABSPATH')) {
    exit;
}

// Debug logging for SSO page
function sso_debug_log($message) {
    $log_file = AZURE_PLUGIN_PATH . 'logs.txt';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] SSO DEBUG: {$message}\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

sso_debug_log('SSO page loading started');

// Get SSO statistics if available
sso_debug_log('Starting SSO statistics collection');
$sso_stats = array();
$last_sync_info = array();

if (class_exists('Azure_SSO_Sync')) {
    sso_debug_log('Azure_SSO_Sync class exists, attempting to initialize');
    try {
        $sync = new Azure_SSO_Sync();
        sso_debug_log('Azure_SSO_Sync initialized successfully');
        $sso_stats = $sync->get_sync_stats();
        sso_debug_log('get_sync_stats completed: ' . json_encode($sso_stats));
    } catch (Exception $e) {
        sso_debug_log('ERROR initializing SSO_Sync: ' . $e->getMessage());
        sso_debug_log('ERROR stack trace: ' . $e->getTraceAsString());
        Azure_Logger::error('SSO: Failed to initialize SSO_Sync - ' . $e->getMessage());
        $sso_stats = array('error' => 'Unable to load SSO statistics');
    }
} else {
    sso_debug_log('Azure_SSO_Sync class does not exist');
}
    
    // Get last sync details from activity log
    sso_debug_log('Starting activity log sync query');
    try {
        global $wpdb;
        sso_debug_log('Getting activity_log table name');
        $activity_table = Azure_Database::get_table_name('activity_log');
        sso_debug_log('Activity table name: ' . ($activity_table ?: 'NULL'));
        
        if ($activity_table) {
            sso_debug_log('Executing last sync query');
            $last_sync = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$activity_table} WHERE module = 'sso' AND action = 'users_synced' ORDER BY created_at DESC LIMIT 1"
            ));
            sso_debug_log('Last sync query result: ' . ($last_sync ? 'Found record' : 'No record'));
            
            if ($last_sync) {
                sso_debug_log('Processing last sync details: ' . json_encode($last_sync));
                $sync_data = json_decode($last_sync->details, true);
                sso_debug_log('Decoded sync data: ' . json_encode($sync_data));
                $last_sync_info = array(
                    'date' => $last_sync->created_at,
                    'created' => $sync_data['created'] ?? 0,
                    'updated' => $sync_data['updated'] ?? 0,
                    'errors' => $sync_data['errors'] ?? 0,
                    'total_processed' => ($sync_data['created'] ?? 0) + ($sync_data['updated'] ?? 0) + ($sync_data['errors'] ?? 0)
                );
                sso_debug_log('Processed last_sync_info: ' . json_encode($last_sync_info));
            }
        } else {
            sso_debug_log('Activity table is NULL or empty');
        }
    } catch (Exception $e) {
        sso_debug_log('ERROR in activity log query: ' . $e->getMessage());
        sso_debug_log('ERROR stack trace: ' . $e->getTraceAsString());
        Azure_Logger::error('SSO: Failed to get last sync info - ' . $e->getMessage());
    }

// Get recent SSO activity
sso_debug_log('Starting recent logins query');
$recent_logins = array();
try {
    global $wpdb;
    sso_debug_log('Getting activity_log table name for recent logins');
    $activity_table = Azure_Database::get_table_name('activity_log');
    sso_debug_log('Activity table name for logins: ' . ($activity_table ?: 'NULL'));
    
    if ($activity_table) {
        sso_debug_log('Executing recent logins query');
        $recent_logins = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$activity_table} WHERE module = 'sso' AND action = 'user_login' ORDER BY created_at DESC LIMIT 10"
        ));
        sso_debug_log('Recent logins query result count: ' . count($recent_logins));
        if (!empty($recent_logins)) {
            sso_debug_log('Sample login record: ' . json_encode($recent_logins[0]));
        }
    } else {
        sso_debug_log('Activity table is NULL for recent logins');
    }
} catch (Exception $e) {
    sso_debug_log('ERROR in recent logins query: ' . $e->getMessage());
    sso_debug_log('ERROR stack trace: ' . $e->getTraceAsString());
    Azure_Logger::error('SSO: Failed to get recent logins - ' . $e->getMessage());
}

// Get last detailed sync results for persistent widgets
$last_sync_results = array();
try {
    global $wpdb;
    sso_debug_log('Getting last detailed sync results');
    $activity_table = Azure_Database::get_table_name('activity_log');
    if ($activity_table) {
        $last_detailed_sync = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$activity_table} WHERE module = 'sso' AND action = 'users_synced' ORDER BY created_at DESC LIMIT 1"
        ));
        
        if ($last_detailed_sync && !empty($last_detailed_sync->details)) {
            $sync_details = json_decode($last_detailed_sync->details, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($sync_details)) {
                $last_sync_results = array(
                    'stats' => $sync_details,
                    'date' => $last_detailed_sync->created_at,
                    'status' => $last_detailed_sync->status
                );
                sso_debug_log('Last sync results found: ' . json_encode($last_sync_results));
            }
        }
    }
} catch (Exception $e) {
    sso_debug_log('ERROR getting last sync results: ' . $e->getMessage());
}

sso_debug_log('PHP processing complete, starting HTML render');
sso_debug_log('sso_stats: ' . json_encode($sso_stats));
sso_debug_log('last_sync_info: ' . json_encode($last_sync_info));
sso_debug_log('recent_logins count: ' . count($recent_logins));
sso_debug_log('last_sync_results: ' . json_encode($last_sync_results));
?>

<?php sso_debug_log('Starting HTML output'); ?>
<div class="wrap">
    <h1>PTA Tools - SSO Settings</h1>
    
    <!-- Module Toggle Section -->
    <div class="module-status-section">
        <h2>Module Status</h2>
        <div class="module-toggle-card">
            <div class="module-info">
                <h3><span class="dashicons dashicons-admin-users"></span> SSO Authentication Module</h3>
                <p>Enable Azure AD Single Sign-On for user authentication</p>
            </div>
            <div class="module-control">
                <?php sso_debug_log('Checking if SSO module is enabled'); ?>
                <label class="switch">
                    <input type="checkbox" class="sso-module-toggle" <?php 
                        try {
                            sso_debug_log('Calling Azure_Settings::is_module_enabled for checkbox');
                            checked(Azure_Settings::is_module_enabled('sso')); 
                            sso_debug_log('Azure_Settings::is_module_enabled call successful for checkbox');
                        } catch (Exception $e) {
                            sso_debug_log('ERROR in Azure_Settings::is_module_enabled for checkbox: ' . $e->getMessage());
                        }
                    ?> />
                    <span class="slider"></span>
                </label>
                <span class="toggle-status"><?php 
                    try {
                        sso_debug_log('Calling Azure_Settings::is_module_enabled for status text');
                        echo Azure_Settings::is_module_enabled('sso') ? 'Enabled' : 'Disabled'; 
                        sso_debug_log('Azure_Settings::is_module_enabled call successful for status text');
                    } catch (Exception $e) {
                        sso_debug_log('ERROR in Azure_Settings::is_module_enabled for status text: ' . $e->getMessage());
                        echo 'Unknown';
                    }
                ?></span>
            </div>
        </div>
        <?php if (!Azure_Settings::is_module_enabled('sso')): ?>
        <div class="notice notice-warning inline">
            <p><strong>SSO module is disabled.</strong> Enable it above or in the <a href="<?php echo admin_url('admin.php?page=azure-plugin'); ?>">main settings</a> to use SSO functionality.</p>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="azure-sso-dashboard">
        <!-- SSO Statistics -->
        <div class="sso-stats-section">
            <h2>SSO Statistics</h2>
            
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $last_sync_info['total_processed'] ?? 0; ?></div>
                    <div class="stat-label">Users Synced</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-number"><?php 
                        if (!empty($last_sync_info['date'])) {
                            try {
                                $date = new DateTime($last_sync_info['date']);
                                echo $date->format('M j, Y g:i A');
                            } catch (Exception $e) {
                                echo 'Invalid Date';
                            }
                        } else {
                            echo 'Never';
                        }
                    ?></div>
                    <div class="stat-label">Last Sync</div>
                </div>
                
                <div class="stat-card <?php echo (!empty($last_sync_info) && $last_sync_info['errors'] == 0) ? 'success' : ((!empty($last_sync_info) && $last_sync_info['errors'] > 0) ? 'error' : ''); ?>">
                    <div class="stat-number"><?php 
                        if (!empty($last_sync_info)) {
                            if ($last_sync_info['errors'] == 0) {
                                echo '✓ Successful';
                            } else {
                                echo $last_sync_info['errors'] . ' Errors';
                            }
                        } else {
                            echo 'No Sync Yet';
                        }
                    ?></div>
                    <div class="stat-label">Last Sync Status</div>
                </div>
                
                <div class="stat-card info">
                    <div class="stat-number"><?php echo $sso_stats['total_mappings'] ?? 0; ?></div>
                    <div class="stat-label">Total Azure AD Users</div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="sso-actions-section">
            <h2>Quick Actions</h2>
            
            <div class="action-buttons">
                <button type="button" class="button button-primary sync-users">
                    <span class="dashicons dashicons-update"></span>
                    Sync Users Now
                </button>
                
                <button type="button" class="button test-sso-connection">
                    <span class="dashicons dashicons-admin-users"></span>
                    Test SSO Connection
                </button>
                
                <a href="<?php echo admin_url('users.php'); ?>" class="button">
                    <span class="dashicons dashicons-admin-users"></span>
                    Manage Users
                </a>
            </div>
        </div>
        
        <!-- Last Sync Results -->
        <?php if (!empty($last_sync_results['stats'])): ?>
        <div class="sso-last-sync-section">
            <h2>Last Sync Results</h2>
            <div class="last-sync-info" style="margin-bottom: 15px; font-size: 14px; color: #666;">
                <strong>Last sync:</strong> <?php 
                    try {
                        $date = new DateTime($last_sync_results['date']);
                        echo $date->format('M j, Y g:i A');
                    } catch (Exception $e) {
                        echo esc_html($last_sync_results['date']);
                    }
                ?>
            </div>
            
            <div id="persistent-sync-results" style="background: #fff; border: 1px solid #e5e5e5; border-radius: 4px; padding: 20px;">
                <div class="sync-results-widgets" style="display: flex; gap: 15px; margin-bottom: 15px;">
                    <div class="result-widget successful persistent" style="flex: 1; text-align: center; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;">
                        <div style="font-size: 24px; font-weight: bold; color: #155724;"><?php echo $last_sync_results['stats']['successful'] ?? 0; ?></div>
                        <div style="font-size: 14px; color: #155724;">Successful</div>
                        <div style="font-size: 11px; color: #155724; margin-top: 2px;">
                            Created: <?php echo $last_sync_results['stats']['created'] ?? 0; ?> | 
                            Updated: <?php echo $last_sync_results['stats']['updated'] ?? 0; ?> | 
                            Linked: <?php echo $last_sync_results['stats']['linked'] ?? 0; ?>
                        </div>
                    </div>
                    <div class="result-widget skipped persistent" style="flex: 1; text-align: center; padding: 15px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; cursor: <?php echo ($last_sync_results['stats']['skipped'] ?? 0) > 0 ? 'pointer' : 'default'; ?>;">
                        <div style="font-size: 24px; font-weight: bold; color: #856404;"><?php echo $last_sync_results['stats']['skipped'] ?? 0; ?></div>
                        <div style="font-size: 14px; color: #856404;">Skipped</div>
                        <?php if (($last_sync_results['stats']['skipped'] ?? 0) > 0): ?>
                        <div style="font-size: 11px; color: #856404; margin-top: 2px;">Click to view details</div>
                        <?php endif; ?>
                    </div>
                    <div class="result-widget errors persistent" style="flex: 1; text-align: center; padding: 15px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; cursor: <?php echo ($last_sync_results['stats']['errors'] ?? 0) > 0 ? 'pointer' : 'default'; ?>;">
                        <div style="font-size: 24px; font-weight: bold; color: #721c24;"><?php echo $last_sync_results['stats']['errors'] ?? 0; ?></div>
                        <div style="font-size: 14px; color: #721c24;">Errors</div>
                        <?php if (($last_sync_results['stats']['errors'] ?? 0) > 0): ?>
                        <div style="font-size: 11px; color: #721c24; margin-top: 2px;">Click to view details</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="font-size: 14px; color: #666;">
                    Total processed: <?php echo $last_sync_results['stats']['total'] ?? 0; ?> users
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Settings Form -->
        <div class="sso-settings-section">
            <form method="post" action="">
                <?php wp_nonce_field('azure_plugin_settings'); ?>
                
                <!-- Common Credentials Toggle -->
                <div class="credentials-toggle-section">
                    <h2>Credentials Configuration</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Use Common Credentials</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="use_common_credentials" id="use_common_credentials" <?php checked($settings['use_common_credentials'] ?? true); ?> />
                                    Use shared Azure AD credentials from main settings
                                </label>
                                <p class="description">When enabled, this module will use the common Azure AD credentials configured on the main PTA Tools page. When disabled, you can configure module-specific credentials below.</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Module-Specific Credentials Section -->
                <div class="credentials-section" id="sso-specific-credentials" <?php echo ($settings['use_common_credentials'] ?? true) ? 'style="display: none;"' : ''; ?>>
                    <h2>SSO-Specific Azure AD Credentials</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Client ID</th>
                            <td>
                                <input type="text" name="sso_client_id" value="<?php echo esc_attr($settings['sso_client_id'] ?? ''); ?>" class="regular-text" />
                                <p class="description">Your Azure App Registration Client ID</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Client Secret</th>
                            <td>
                                <input type="password" name="sso_client_secret" value="<?php echo esc_attr($settings['sso_client_secret'] ?? ''); ?>" class="regular-text" />
                                <p class="description">Your Azure App Registration Client Secret</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Tenant ID</th>
                            <td>
                                <input type="text" name="sso_tenant_id" value="<?php echo esc_attr($settings['sso_tenant_id'] ?? 'common'); ?>" class="regular-text" />
                                <p class="description">Your Azure Tenant ID (or 'common' for multi-tenant)</p>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <button type="button" class="button test-credentials" 
                                    data-client-id-field="sso_client_id" 
                                    data-client-secret-field="sso_client_secret" 
                                    data-tenant-id-field="sso_tenant_id">
                                    Test Credentials
                                </button>
                                <span class="credentials-status"></span>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Authentication Settings -->
                <div class="sso-authentication">
                    <h2>Authentication Settings</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Require SSO</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="sso_require_sso" <?php checked($settings['sso_require_sso'] ?? false); ?> />
                                    Force all users to login via Azure AD (disables WordPress login)
                                </label>
                                <p class="description">⚠️ <strong>Warning:</strong> This will prevent access to wp-admin without Azure AD login. Make sure SSO is working first!</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Show on Login Page</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="sso_show_on_login_page" id="sso_show_on_login_page" <?php checked($settings['sso_show_on_login_page'] ?? true); ?> />
                                    Show "Sign in" button on WordPress login page
                                </label>
                                <div id="sso_button_text_wrapper" style="margin-top: 10px; <?php echo ($settings['sso_show_on_login_page'] ?? true) ? '' : 'display: none;'; ?>">
                                    <label for="sso_login_button_text">
                                        <strong>Button Text:</strong><br />
                                        <input type="text" name="sso_login_button_text" id="sso_login_button_text" value="<?php echo esc_attr($settings['sso_login_button_text'] ?? 'Sign in with Microsoft'); ?>" class="regular-text" placeholder="Sign in with Microsoft" />
                                    </label>
                                    <p class="description">Customize the text shown on the login button. The Microsoft icon will still be displayed.</p>
                                    <label for="sso_login_org_heading" style="display:block;margin-top:12px;">
                                        <strong>Login page — organization heading (above SSO button):</strong><br />
                                        <input type="text" name="sso_login_org_heading" id="sso_login_org_heading" value="<?php echo esc_attr($settings['sso_login_org_heading'] ?? ''); ?>" class="regular-text" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>" />
                                    </label>
                                    <p class="description">Shown in the Microsoft sign-in section after the Parents username/password form (e.g. <code>WilderPTSA</code>). Leave blank to use the site title.</p>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Auto Create Users</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="sso_auto_create_users" <?php checked($settings['sso_auto_create_users'] ?? true); ?> />
                                    Automatically create WordPress accounts for new Azure AD users
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Default Role</th>
                            <td>
                                <div style="margin-bottom: 10px;">
                                    <label>
                                        <input type="checkbox" name="sso_use_custom_role" id="sso_use_custom_role" <?php checked($settings['sso_use_custom_role'] ?? false); ?> />
                                        Use custom role for Azure AD users
                                    </label>
                                </div>
                                
                                <!-- Standard WordPress Roles -->
                                <div id="sso_standard_roles" style="<?php echo ($settings['sso_use_custom_role'] ?? false) ? 'display: none;' : ''; ?>">
                                    <select name="sso_default_role">
                                        <?php
                                        $current_role = $settings['sso_default_role'] ?? 'subscriber';
                                        $roles = get_editable_roles();
                                        
                                        // Fallback if get_editable_roles() is empty
                                        if (empty($roles)) {
                                            global $wp_roles;
                                            if (!isset($wp_roles)) {
                                                $wp_roles = new WP_Roles();
                                            }
                                            $roles = $wp_roles->roles;
                                        }
                                        ?>
                                        <?php if (!empty($roles)): ?>
                                            <?php foreach ($roles as $role_key => $role): ?>
                                            <option value="<?php echo esc_attr($role_key); ?>" <?php selected($current_role, $role_key); ?>>
                                                <?php echo esc_html($role['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="subscriber" <?php selected($current_role, 'subscriber'); ?>>Subscriber</option>
                                            <option value="contributor" <?php selected($current_role, 'contributor'); ?>>Contributor</option>
                                            <option value="author" <?php selected($current_role, 'author'); ?>>Author</option>
                                            <option value="editor" <?php selected($current_role, 'editor'); ?>>Editor</option>
                                            <option value="administrator" <?php selected($current_role, 'administrator'); ?>>Administrator</option>
                                        <?php endif; ?>
                                    </select>
                                    <p class="description">Choose from existing WordPress roles</p>
                                </div>
                                
                                <!-- Custom Role Configuration -->
                                <div id="sso_custom_role" style="<?php echo ($settings['sso_use_custom_role'] ?? false) ? '' : 'display: none;'; ?>">
                                    <input type="text" name="sso_custom_role_name" value="<?php echo esc_attr($settings['sso_custom_role_name'] ?? 'AzureAD'); ?>" class="regular-text" placeholder="AzureAD" />
                                    <p class="description">Custom role name for Azure AD users (will be created automatically)</p>
                                    <p class="description"><strong>Benefits:</strong> Easy to identify and manage Azure AD users separately from regular WordPress users</p>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- User Synchronization -->
                <div class="sso-sync">
                    <h2>User Synchronization</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Enable Automatic Sync</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="sso_sync_enabled" <?php checked($settings['sso_sync_enabled'] ?? false); ?> />
                                    Automatically sync users from Azure AD
                                </label>
                                <p class="description">Periodically sync user information from Azure AD to WordPress</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Sync Frequency</th>
                            <td>
                                <select name="sso_sync_frequency">
                                    <?php
                                    $current_frequency = $settings['sso_sync_frequency'] ?? 'daily';
                                    $frequencies = array(
                                        'hourly' => 'Every Hour',
                                        'twicedaily' => 'Twice Daily',
                                        'daily' => 'Daily',
                                        'weekly' => 'Weekly'
                                    );
                                    ?>
                                    <?php foreach ($frequencies as $value => $label): ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($current_frequency, $value); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">How often to sync users from Azure AD</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Preserve Local User Data</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="sso_preserve_local_data" <?php checked($settings['sso_preserve_local_data'] ?? false); ?> />
                                    Don't overwrite local user data with Azure AD information
                                </label>
                                <p class="description">When enabled, existing WordPress user names and profiles won't be modified during sync. Only new users will get Azure AD information.</p>
                            </td>
                        </tr>
                        <?php if (isset($sso_stats['last_sync'])): ?>
                        <tr>
                            <th scope="row">Last Sync</th>
                            <td>
                                <strong><?php echo esc_html($sso_stats['last_sync']); ?></strong>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
                
                <!-- Redirect Settings -->
                <div class="sso-redirects">
                    <h2>Redirect Settings</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Redirect URI</th>
                            <td>
                                <input type="url" name="sso_redirect_uri" value="<?php echo esc_attr($settings['sso_redirect_uri'] ?? home_url('/wp-admin/admin-ajax.php?action=azure_sso_callback')); ?>" class="regular-text" readonly />
                                <p class="description">Use this URL in your Azure App Registration as the Redirect URI</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Post-Login Redirect</th>
                            <td>
                                <input type="url" name="sso_post_login_redirect" value="<?php echo esc_attr($settings['sso_post_login_redirect'] ?? ''); ?>" class="regular-text" placeholder="<?php echo admin_url(); ?>" />
                                <p class="description">Where to redirect users after successful login (leave empty for admin dashboard)</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <p class="submit">
                    <input type="submit" name="azure_plugin_submit" class="button-primary" value="Save SSO Settings" />
                </p>
            </form>
        </div>
        
        <!-- Do Not Sync Exclusion List -->
        <div class="sso-exclusion-section">
            <h2><span class="dashicons dashicons-dismiss"></span> Do Not Sync - Exclusion List</h2>
            <p class="description">Exclude service accounts, shared mailboxes, and other non-user accounts from syncing to WordPress and from SSO login. These accounts will be blocked from authentication.</p>

            <?php
            $exclude_external = Azure_Settings::get_setting('sso_exclude_external_domains', false);
            $org_domain = Azure_Settings::get_setting('org_domain', '');
            ?>
            <table class="form-table" style="margin-bottom: 0;">
                <tr>
                    <th scope="row">Exclude External Domains</th>
                    <td>
                        <label>
                            <input type="checkbox" id="sso_exclude_external_domains" name="sso_exclude_external_domains" value="1"
                                <?php checked($exclude_external); ?> />
                            Do not sync or allow login from accounts outside the organization domain
                        </label>
                        <?php if (!empty($org_domain)): ?>
                        <p class="description">Only accounts with <strong>@<?php echo esc_html($org_domain); ?></strong> email addresses will be synced and allowed to log in. All other domains (e.g., gmail.com, outlook.com, yahoo.com) will be automatically excluded.</p>
                        <?php else: ?>
                        <p class="description" style="color: #dc3232;">
                            <span class="dashicons dashicons-warning" style="font-size: 16px; vertical-align: text-bottom;"></span>
                            Organization domain is not configured. Please set it in <a href="<?php echo admin_url('admin.php?page=azure-plugin'); ?>">PTA Tools &rarr; Main Settings</a> or the Setup Wizard before enabling this option.
                        </p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <div class="exclusion-add-section">
                <h4>Add Account to Exclusion List</h4>
                <div class="exclusion-add-form">
                    <select id="azure-users-dropdown" class="regular-text" style="min-width: 350px;">
                        <option value="">-- Click "Load Azure AD Users" to populate --</option>
                    </select>
                    <button type="button" class="button" id="load-azure-users">
                        <span class="dashicons dashicons-download"></span> Load Azure AD Users
                    </button>
                    <button type="button" class="button button-primary" id="add-to-exclusion" disabled>
                        <span class="dashicons dashicons-plus-alt"></span> Add to Exclusion List
                    </button>
                </div>
                <p class="description" style="margin-top: 10px;">Select a user from the dropdown and click "Add to Exclusion List" to prevent them from syncing or logging in.</p>
            </div>
            
            <div class="exclusion-list-section" style="margin-top: 20px;">
                <h4>Currently Excluded Accounts</h4>
                <?php
                $excluded_users = get_option('azure_sso_excluded_users', array());
                ?>
                <table class="wp-list-table widefat fixed striped" id="exclusion-list-table">
                    <thead>
                        <tr>
                            <th style="width: 30%;">Display Name</th>
                            <th style="width: 35%;">Email / UPN</th>
                            <th style="width: 20%;">Date Added</th>
                            <th style="width: 15%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($excluded_users)): ?>
                        <tr class="no-exclusions">
                            <td colspan="4" style="text-align: center; color: #666;">
                                <em>No accounts are currently excluded. Add service accounts or shared mailboxes above.</em>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($excluded_users as $user_id => $user_data): ?>
                        <tr data-user-id="<?php echo esc_attr($user_id); ?>">
                            <td><?php echo esc_html($user_data['display_name']); ?></td>
                            <td><?php echo esc_html($user_data['email']); ?></td>
                            <td><?php echo esc_html(date('M j, Y g:i A', strtotime($user_data['added_at']))); ?></td>
                            <td>
                                <button type="button" class="button button-small remove-from-exclusion" data-user-id="<?php echo esc_attr($user_id); ?>">
                                    <span class="dashicons dashicons-no-alt"></span> Remove
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <p class="description" style="margin-top: 10px;">
                    <strong>Note:</strong> Excluded accounts cannot log in via SSO and will not be synced to WordPress during user sync operations.
                </p>
            </div>
        </div>
        
        <!-- Recent Login Activity -->
        <div class="sso-activity-section">
            <h2>Recent Login Activity</h2>
            
            <?php if (!empty($recent_logins)): ?>
            <table class="sso-activity-table widefat striped">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Time</th>
                        <th>IP Address</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_logins as $login): ?>
                    <?php 
                    $details = array();
                    if (!empty($login->details)) {
                        $decoded_details = json_decode($login->details, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_details)) {
                            $details = $decoded_details;
                        }
                    }
                    $user_email = isset($details['mail']) ? $details['mail'] : (isset($details['userPrincipalName']) ? $details['userPrincipalName'] : 'Unknown');
                    ?>
                    <tr>
                        <td><?php echo esc_html($user_email); ?></td>
                        <td><?php echo esc_html($login->created_at ?? 'Unknown'); ?></td>
                        <td><?php echo esc_html($login->ip_address ?? 'Unknown'); ?></td>
                        <td>
                            <span class="status-indicator <?php echo ($login->status ?? 'unknown') === 'success' ? 'success' : 'error'; ?>">
                                <?php echo esc_html(ucfirst($login->status ?? 'Unknown')); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p>No recent login activity found.</p>
            <?php endif; ?>
        </div>
        
        <!-- Shortcode Examples -->
        <div class="sso-shortcodes-section">
            <h2>Available Shortcodes</h2>
            
            <div class="shortcode-examples">
                <div class="shortcode-example">
                    <h4>Login Button</h4>
                    <code>[azure_sso_login text="Sign in with My PTA" redirect="/dashboard"]</code>
                    <p>Creates a login button that redirects to the specified page after authentication.</p>
                </div>
                
                <div class="shortcode-example">
                    <h4>Logout Button</h4>
                    <code>[azure_sso_logout text="Sign out" redirect="/"]</code>
                    <p>Creates a logout button that redirects to the specified page.</p>
                </div>
                
                <div class="shortcode-example">
                    <h4>User Information</h4>
                    <code>[azure_user_info field="display_name"]</code>
                    <p>Displays specific user information. Available fields: display_name, email, azure_id, last_login, wp_username</p>
                </div>
                
                <div class="shortcode-example">
                    <h4>Full User Info</h4>
                    <code>[azure_user_info]</code>
                    <p>Displays all available user information in a formatted list.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Handle use common credentials toggle
    $('#use_common_credentials').on('change', function() {
        var isChecked = $(this).is(':checked');
        var specificCredentials = $('#sso-specific-credentials');
        
        if (isChecked) {
            specificCredentials.slideUp('fast');
        } else {
            specificCredentials.slideDown('fast');
        }
    });
    
    // Handle custom role toggle
    $('#sso_use_custom_role').on('change', function() {
        var useCustomRole = $(this).is(':checked');
        var standardRoles = $('#sso_standard_roles');
        var customRole = $('#sso_custom_role');
        
        if (useCustomRole) {
            standardRoles.slideUp('fast');
            customRole.slideDown('fast');
        } else {
            customRole.slideUp('fast');
            standardRoles.slideDown('fast');
        }
    });
    
    // Handle show on login page toggle
    $('#sso_show_on_login_page').on('change', function() {
        var isChecked = $(this).is(':checked');
        var buttonTextWrapper = $('#sso_button_text_wrapper');
        
        if (isChecked) {
            buttonTextWrapper.slideDown('fast');
        } else {
            buttonTextWrapper.slideUp('fast');
        }
    });
    
    // Handle sync users with enhanced progress and results
    $('.sync-users').click(function() {
        if (!confirm('Are you sure you want to sync all users from Azure AD? This may take a while.')) {
            return;
        }
        
        var button = $(this);
        var originalText = button.html();
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Syncing...');
        
        // Hide persistent widgets and any existing temporary results during sync
        $('#persistent-sync-results, #sync-results-container').fadeOut();
        
        // Create enhanced progress UI
        var progressContainer = $(`
            <div id="sync-progress-container" style="margin-top: 15px; display: none;">
                <div style="background: #f9f9f9; border: 1px solid #e5e5e5; border-radius: 4px; padding: 15px;">
                    <h4 style="margin-top: 0;">User Sync Progress</h4>
                    <div id="sync-progress-bar" style="background: #e0e0e0; height: 20px; border-radius: 10px; overflow: hidden; margin-bottom: 10px;">
                        <div id="sync-progress-fill" style="background: #0073aa; height: 100%; width: 0%; transition: width 0.3s ease;"></div>
                    </div>
                    <div id="sync-status" style="font-size: 14px; color: #666;">Starting sync...</div>
                    <div id="sync-percentage" style="font-size: 12px; color: #999; margin-top: 5px;">0%</div>
                </div>
            </div>
        `);
        
        button.closest('.action-buttons').after(progressContainer);
        progressContainer.fadeIn();
        
        // Update progress text
        setTimeout(function() { $('#sync-status').text('Connecting to Azure AD...'); }, 1000);
        setTimeout(function() { $('#sync-status').text('Retrieving user list...'); }, 3000);
        setTimeout(function() { $('#sync-status').text('Processing users...'); }, 5000);
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_sync_users',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            progressContainer.fadeOut();
            
            if (response.success && response.stats) {
                showSyncResults(response.stats, response.details || {});
            } else {
                alert('❌ User sync failed: ' + (response.message || 'Unknown error'));
            }
            
            button.prop('disabled', false).html(originalText);
        }).fail(function(xhr, status, error) {
            progressContainer.fadeOut();
            var errorMsg = 'Network error occurred';
            
            if (xhr.responseText) {
                try {
                    var errorResponse = JSON.parse(xhr.responseText);
                    errorMsg = errorResponse.message || errorResponse.data || xhr.responseText;
                } catch (e) {
                    errorMsg = xhr.responseText;
                }
            }
            
            alert('❌ ' + errorMsg);
            button.prop('disabled', false).html(originalText);
        });
    });
    
    // Show sync results with widgets and modals
    function showSyncResults(stats, details) {
        // Remove any existing results
        $('#sync-results-container').remove();
        
        var resultsHtml = `
            <div id="sync-results-container" style="margin-top: 20px;">
                <div style="background: #fff; border: 1px solid #e5e5e5; border-radius: 4px; padding: 20px;">
                    <h3 style="margin-top: 0; color: #0073aa;">Sync Results</h3>
                    <div class="sync-results-widgets" style="display: flex; gap: 15px; margin-bottom: 15px;">
                        <div class="result-widget successful" data-type="successful" style="flex: 1; text-align: center; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; cursor: pointer;">
                            <div style="font-size: 24px; font-weight: bold; color: #155724;">${stats.successful || 0}</div>
                            <div style="font-size: 14px; color: #155724;">Successful</div>
                        </div>
                        <div class="result-widget skipped" data-type="skipped" style="flex: 1; text-align: center; padding: 15px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; cursor: ${stats.skipped > 0 ? 'pointer' : 'default'};">
                            <div style="font-size: 24px; font-weight: bold; color: #856404;">${stats.skipped || 0}</div>
                            <div style="font-size: 14px; color: #856404;">Skipped</div>
                        </div>
                        <div class="result-widget errors" data-type="errors" style="flex: 1; text-align: center; padding: 15px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; cursor: ${stats.errors > 0 ? 'pointer' : 'default'};">
                            <div style="font-size: 24px; font-weight: bold; color: #721c24;">${stats.errors || 0}</div>
                            <div style="font-size: 14px; color: #721c24;">Errors</div>
                        </div>
                    </div>
                    <div style="font-size: 14px; color: #666;">
                        Total processed: ${stats.total || 0} users
                    </div>
                </div>
            </div>
        `;
        
        $('.action-buttons').after(resultsHtml);
        
        // Add click handlers for widgets
        $('.result-widget.skipped').click(function() {
            if (stats.skipped > 0) {
                showDetailsModal('Skipped Users', details.skipped || []);
            }
        });
        
        $('.result-widget.errors').click(function() {
            if (stats.errors > 0) {
                showDetailsModal('Error Details', details.errors || []);
            }
        });
        
        // Reload page after 5 seconds to show persistent widgets
        setTimeout(function() {
            location.reload();
        }, 5000);
    }
    
    // Show details modal
    function showDetailsModal(title, items) {
        var modalHtml = `
            <div id="sync-details-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 100000; display: flex; align-items: center; justify-content: center;">
                <div style="background: white; padding: 20px; border-radius: 8px; max-width: 80%; max-height: 80%; overflow-y: auto; min-width: 500px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #e5e5e5; padding-bottom: 10px;">
                        <h3 style="margin: 0;">${title}</h3>
                        <button id="close-modal" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #666;">&times;</button>
                    </div>
                    <div style="max-height: 400px; overflow-y: auto;">
        `;
        
        if (items.length === 0) {
            modalHtml += '<p>No items to display.</p>';
        } else {
            items.forEach(function(item) {
                modalHtml += '<div style="padding: 8px 0; border-bottom: 1px solid #f0f0f0;">' + item + '</div>';
            });
        }
        
        modalHtml += `
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(modalHtml);
        
        // Close modal handlers
        $('#close-modal, #sync-details-modal').click(function(e) {
            if (e.target === this) {
                $('#sync-details-modal').remove();
            }
        });
    }
    
    // Test SSO connection
    $('.test-sso-connection').click(function() {
        var button = $(this);
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Testing...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_test_sso_connection',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html('<span class="dashicons dashicons-admin-users"></span> Test SSO Connection');
            
            var data = response.data || {};
            var checks = data.checks || [];
            var message = (typeof data === 'string') ? data : (data.message || 'Unknown result');

            showSSOTestResults(response.success, message, checks);
        }).fail(function() {
            button.prop('disabled', false).html('<span class="dashicons dashicons-admin-users"></span> Test SSO Connection');
            showSSOTestResults(false, 'Network error occurred', []);
        });
    });

    function showSSOTestResults(success, message, checks) {
        $('#sso-test-modal').remove();

        var html = '<div id="sso-test-modal" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:100000;display:flex;align-items:center;justify-content:center;">';
        html += '<div style="background:#fff;border-radius:8px;max-width:520px;width:90%;padding:24px;box-shadow:0 4px 20px rgba(0,0,0,0.3);">';
        html += '<h3 style="margin:0 0 16px;">' + (success ? '✅' : '❌') + ' SSO Connection Test</h3>';

        if (checks.length) {
            html += '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">';
            html += '<thead><tr><th style="text-align:left;padding:6px 8px;border-bottom:2px solid #ddd;">Check</th><th style="text-align:left;padding:6px 8px;border-bottom:2px solid #ddd;">Status</th></tr></thead><tbody>';
            for (var i = 0; i < checks.length; i++) {
                var c = checks[i];
                var icon = c.pass ? '✅' : '❌';
                var rowColor = c.pass ? 'inherit' : '#fef0f0';
                html += '<tr style="background:' + rowColor + ';">';
                html += '<td style="padding:8px;border-bottom:1px solid #eee;"><strong>' + c.name + '</strong></td>';
                html += '<td style="padding:8px;border-bottom:1px solid #eee;">' + icon + '</td>';
                html += '</tr>';
                if (c.detail) {
                    html += '<tr style="background:' + rowColor + ';"><td colspan="2" style="padding:4px 8px 10px;color:#666;font-size:12px;">' + c.detail + '</td></tr>';
                }
            }
            html += '</tbody></table>';
        }

        html += '<p style="margin:0 0 16px;font-weight:600;">' + message + '</p>';
        html += '<button class="button button-primary" onclick="jQuery(\'#sso-test-modal\').remove();">Close</button>';
        html += '</div></div>';

        $('body').append(html);
        $('#sso-test-modal').on('click', function(e) { if (e.target === this) $(this).remove(); });
    }
    
    // Handle module toggle
    $('.sso-module-toggle').change(function() {
        var enabled = $(this).is(':checked');
        var statusText = $('.toggle-status');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_toggle_module',
            module: 'sso',
            enabled: enabled,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                statusText.text(enabled ? 'Enabled' : 'Disabled');
                if (enabled) {
                    $('.notice.notice-warning.inline').fadeOut();
                } else {
                    location.reload(); // Refresh to show warning
                }
            } else {
                // Revert toggle if failed
                $('.sso-module-toggle').prop('checked', !enabled);
                alert('Failed to toggle SSO module: ' + (response.data || 'Unknown error'));
            }
        }).fail(function() {
            // Revert toggle if failed
            $('.sso-module-toggle').prop('checked', !enabled);
            alert('Network error occurred');
        });
    });
    
    // Show warning when enabling require SSO
    $('input[name="sso_require_sso"]').change(function() {
        if ($(this).is(':checked')) {
            if (!confirm('⚠️ WARNING: Enabling "Require SSO" will prevent all users from logging in with WordPress passwords.\n\nMake sure your SSO configuration is working properly first.\n\nDo you want to continue?')) {
                $(this).prop('checked', false);
            }
        }
    });
    
    // Handle persistent widget clicks for last sync results
    <?php if (!empty($last_sync_results['stats']) && !empty($last_sync_results['stats']['detailed_results'])): ?>
    var persistentResults = <?php echo json_encode($last_sync_results['stats']['detailed_results']); ?>;
    
    $('.result-widget.persistent.skipped').click(function() {
        if (persistentResults.skipped && persistentResults.skipped.length > 0) {
            showDetailsModal('Skipped Users - Last Sync', persistentResults.skipped);
        }
    });
    
    $('.result-widget.persistent.errors').click(function() {
        if (persistentResults.errors && persistentResults.errors.length > 0) {
            showDetailsModal('Error Details - Last Sync', persistentResults.errors);
        }
    });
    <?php endif; ?>
    
    // ===== Do Not Sync Exclusion List Handlers =====

    // Save "Exclude External Domains" checkbox via AJAX
    $('#sso_exclude_external_domains').on('change', function() {
        var enabled = $(this).is(':checked');
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_save_exclude_external_domains',
            enabled: enabled,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (!response.success) {
                alert('Failed to save setting');
            }
        });
    });

    // Load Azure AD Users into dropdown
    $('#load-azure-users').click(function() {
        var button = $(this);
        var dropdown = $('#azure-users-dropdown');
        
        button.prop('disabled', true).html('<span class="spinner is-active" style="float: none; margin: 0;"></span> Loading...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_get_all_ad_users',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Load Azure AD Users');
            
            if (response.success && response.data.users) {
                dropdown.empty();
                dropdown.append('<option value="">-- Select an account to exclude --</option>');
                
                // Get currently excluded user IDs
                var excludedIds = [];
                $('#exclusion-list-table tbody tr[data-user-id]').each(function() {
                    excludedIds.push($(this).data('user-id'));
                });
                
                response.data.users.forEach(function(user) {
                    // Skip already excluded users
                    if (excludedIds.indexOf(user.id) === -1) {
                        var optionText = user.displayName + ' (' + (user.mail || user.userPrincipalName) + ')';
                        dropdown.append('<option value="' + user.id + '" data-display-name="' + user.displayName + '" data-email="' + (user.mail || user.userPrincipalName) + '">' + optionText + '</option>');
                    }
                });
                
                $('#add-to-exclusion').prop('disabled', false);
                alert('✅ Loaded ' + response.data.users.length + ' users from Azure AD');
            } else {
                alert('❌ Failed to load users: ' + (response.data || 'Unknown error'));
            }
        }).fail(function() {
            button.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Load Azure AD Users');
            alert('❌ Network error occurred');
        });
    });
    
    // Add user to exclusion list
    $('#add-to-exclusion').click(function() {
        var dropdown = $('#azure-users-dropdown');
        var selectedOption = dropdown.find('option:selected');
        var userId = dropdown.val();
        
        if (!userId) {
            alert('Please select a user from the dropdown');
            return;
        }
        
        var displayName = selectedOption.data('display-name');
        var email = selectedOption.data('email');
        
        if (!confirm('Are you sure you want to exclude "' + displayName + '" (' + email + ') from syncing and SSO login?')) {
            return;
        }
        
        var button = $(this);
        button.prop('disabled', true).html('<span class="spinner is-active" style="float: none; margin: 0;"></span> Adding...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_add_user_to_exclusion',
            user_id: userId,
            display_name: displayName,
            email: email,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span> Add to Exclusion List');
            
            if (response.success) {
                // Remove the "no exclusions" row if present
                $('#exclusion-list-table tbody .no-exclusions').remove();
                
                // Add new row to table
                var newRow = '<tr data-user-id="' + userId + '">' +
                    '<td>' + displayName + '</td>' +
                    '<td>' + email + '</td>' +
                    '<td>' + response.data.added_at + '</td>' +
                    '<td><button type="button" class="button button-small remove-from-exclusion" data-user-id="' + userId + '"><span class="dashicons dashicons-no-alt"></span> Remove</button></td>' +
                    '</tr>';
                $('#exclusion-list-table tbody').append(newRow);
                
                // Remove from dropdown
                selectedOption.remove();
                dropdown.val('');
                
                alert('✅ ' + displayName + ' has been added to the exclusion list');
            } else {
                alert('❌ Failed to add user: ' + (response.data || 'Unknown error'));
            }
        }).fail(function() {
            button.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span> Add to Exclusion List');
            alert('❌ Network error occurred');
        });
    });
    
    // Remove user from exclusion list
    $(document).on('click', '.remove-from-exclusion', function() {
        var button = $(this);
        var userId = button.data('user-id');
        var row = button.closest('tr');
        var displayName = row.find('td:first').text();
        
        if (!confirm('Are you sure you want to remove "' + displayName + '" from the exclusion list? They will be able to sync and log in again.')) {
            return;
        }
        
        button.prop('disabled', true).html('<span class="spinner is-active" style="float: none; margin: 0;"></span>');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_remove_user_from_exclusion',
            user_id: userId,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                row.fadeOut(function() {
                    row.remove();
                    
                    // Show "no exclusions" message if table is empty
                    if ($('#exclusion-list-table tbody tr').length === 0) {
                        $('#exclusion-list-table tbody').append(
                            '<tr class="no-exclusions"><td colspan="4" style="text-align: center; color: #666;">' +
                            '<em>No accounts are currently excluded. Add service accounts or shared mailboxes above.</em>' +
                            '</td></tr>'
                        );
                    }
                });
                
                alert('✅ ' + displayName + ' has been removed from the exclusion list');
            } else {
                button.prop('disabled', false).html('<span class="dashicons dashicons-no-alt"></span> Remove');
                alert('❌ Failed to remove user: ' + (response.data || 'Unknown error'));
            }
        }).fail(function() {
            button.prop('disabled', false).html('<span class="dashicons dashicons-no-alt"></span> Remove');
            alert('❌ Network error occurred');
        });
    });
});
</script>

<style>
.sso-stats-section {
    margin-bottom: 20px;
}

.sso-actions-section {
    margin-bottom: 30px;
}

.sso-authentication,
.sso-sync,
.sso-redirects {
    background: #fff;
    padding: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    margin-bottom: 20px;
}

.sso-activity-table {
    margin-top: 15px;
}

.sso-activity-table th,
.sso-activity-table td {
    padding: 12px;
}

.sso-last-sync-section {
    background: #fff;
    padding: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    margin-bottom: 20px;
}

.result-widget.persistent {
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.result-widget.persistent:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.sso-shortcodes-section {
    background: #fff;
    padding: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    margin-top: 20px;
}

.shortcode-examples {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 15px;
}

.shortcode-example {
    border: 1px solid #ddd;
    padding: 15px;
    border-radius: 4px;
    background: #f9f9f9;
}

.shortcode-example h4 {
    margin-top: 0;
    color: #0073aa;
}

.shortcode-example code {
    display: block;
    background: #fff;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin: 10px 0;
    font-size: 13px;
    word-break: break-all;
}

.shortcode-example p {
    margin-bottom: 0;
    font-size: 13px;
    color: #666;
}

/* Module Status Section */
.module-status-section {
    margin-bottom: 30px;
}

.module-toggle-card {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #fff !important;
    color: #333 !important;
    padding: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    margin-bottom: 15px;
}

.module-info h3 {
    margin: 0 0 8px 0;
    color: #333 !important;
    display: flex;
    align-items: center;
    gap: 8px;
}

.module-info p {
    margin: 0;
    color: #666 !important;
}

.module-control {
    display: flex;
    align-items: center;
    gap: 15px;
}

.toggle-status {
    font-weight: 500;
    color: #333 !important;
}

.notice.inline {
    margin: 15px 0;
}

/* Toggle Switch Styles */
.switch {
    position: relative;
    display: inline-block;
    width: 50px;
    height: 24px;
}

.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    border-radius: 24px;
    transition: .4s;
}

.slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    border-radius: 50%;
    transition: .4s;
}

input:checked + .slider {
    background-color: #0073aa;
}

input:checked + .slider:before {
    transform: translateX(26px);
}

/* Contrast Improvements */
.sso-authentication,
.sso-sync,
.sso-redirects,
.sso-shortcodes-section,
.sso-exclusion-section {
    background: #fff !important;
    color: #333 !important;
}

/* Exclusion List Section */
.sso-exclusion-section {
    background: #fff;
    padding: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    margin-top: 20px;
}

.sso-exclusion-section h2 {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #d63638 !important;
    border-bottom: 1px solid #f0f0f0;
    padding-bottom: 10px;
}

.exclusion-add-form {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.exclusion-add-form select {
    padding: 5px 10px;
}

#exclusion-list-table {
    margin-top: 10px;
}

#exclusion-list-table th {
    background: #f9f9f9;
}

#exclusion-list-table .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
    vertical-align: middle;
}

.remove-from-exclusion {
    color: #d63638 !important;
    border-color: #d63638 !important;
}

.remove-from-exclusion:hover {
    background: #d63638 !important;
    color: #fff !important;
}

.form-table th {
    color: #333 !important;
    background: #f9f9f9 !important;
}

.form-table td {
    color: #333 !important;
}

.form-table input,
.form-table select,
.form-table textarea {
    color: #333 !important;
    background: #fff !important;
    border: 1px solid #ddd;
}

.form-table .description {
    color: #666 !important;
}

/* Section Headers */
.sso-stats-section h2,
.sso-actions-section h2,
.sso-authentication h2,
.sso-sync h2,
.sso-redirects h2,
.sso-shortcodes-section h2,
.credentials-toggle-section h2,
.credentials-section h2 {
    color: #333 !important;
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
}

/* Credentials Toggle Section */
.credentials-toggle-section {
    margin-bottom: 20px;
    padding: 15px;
    background: #f9f9f9;
    border: 1px solid #e5e5e5;
    border-radius: 3px;
}

.credentials-section {
    padding: 15px;
    background: #f0f0f1;
    border: 1px solid #c3c4c7;
    border-radius: 3px;
    margin-bottom: 20px;
}

/* WordPress Dark Theme Overrides */
body.admin-color-midnight .module-toggle-card,
body.admin-color-midnight .sso-authentication,
body.admin-color-midnight .sso-sync,
body.admin-color-midnight .sso-redirects,
body.admin-color-midnight .sso-shortcodes-section {
    background: #fff !important;
    color: #333 !important;
    border-color: #ccd0d4 !important;
}
</style>

<?php sso_debug_log('SSO page rendering completed successfully');