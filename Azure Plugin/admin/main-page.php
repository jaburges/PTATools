<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1>PTA Tools - Main Settings</h1>
    
    <?php
    // Show setup wizard progress banner if not completed
    $wizard_class_exists = class_exists('Azure_Setup_Wizard');
    $wizard_completed = Azure_Settings::get_setting('setup_wizard_completed', false);
    $restore_completed = get_option('azure_restore_completed', false);
    
    if ($wizard_class_exists && !$wizard_completed && !$restore_completed):
        $wizard_progress = Azure_Setup_Wizard::get_wizard_progress();
    ?>
    <div class="setup-progress-banner">
        <h3><?php _e('Complete Your Setup', 'azure-plugin'); ?></h3>
        <div class="progress-bar">
            <div class="progress-fill" style="width: <?php echo esc_attr($wizard_progress['percent']); ?>%"></div>
        </div>
        <p><?php printf(__('Step %d of %d completed', 'azure-plugin'), $wizard_progress['current_step'], $wizard_progress['total_steps']); ?></p>
        <a href="<?php echo admin_url('admin.php?page=azure-plugin-setup'); ?>" class="button button-primary">
            <?php _e('Continue Setup', 'azure-plugin'); ?>
        </a>
    </div>
    <?php endif; ?>
    
    <div class="azure-plugin-dashboard">
        <div class="azure-plugin-modules">
            <h2>Module Status</h2>
            
            <?php
            $calendar_enabled = $settings['enable_calendar'] || ($settings['enable_tec_integration'] ?? false);
            $selling_any = ($settings['enable_auction'] ?? false) || ($settings['enable_classes'] ?? false) || ($settings['enable_product_fields'] ?? false) || ($settings['enable_donations'] ?? false);
            ?>
            <div class="module-cards">
                <div class="module-card <?php echo $settings['enable_sso'] ? 'enabled' : 'disabled'; ?>">
                    <div class="module-header">
                        <h3><span class="dashicons dashicons-admin-users"></span> SSO Authentication</h3>
                        <div class="module-controls">
                            <label class="switch">
                                <input type="checkbox" class="module-toggle" data-module="sso" <?php checked($settings['enable_sso']); ?> />
                                <span class="slider"></span>
                            </label>
                            <a href="<?php echo admin_url('admin.php?page=azure-plugin-sso'); ?>" class="button button-configure">Configure</a>
                        </div>
                    </div>
                    <div class="module-description">
                        <p>Enable Azure AD Single Sign-On for user authentication.</p>
                    </div>
                </div>
                
                <div class="module-card <?php echo $settings['enable_backup'] ? 'enabled' : 'disabled'; ?>">
                    <div class="module-header">
                        <h3><span class="dashicons dashicons-backup"></span> Azure Backup</h3>
                        <div class="module-controls">
                            <label class="switch">
                                <input type="checkbox" class="module-toggle" data-module="backup" <?php checked($settings['enable_backup']); ?> />
                                <span class="slider"></span>
                            </label>
                            <a href="<?php echo admin_url('admin.php?page=azure-plugin-backup'); ?>" class="button button-configure">Configure</a>
                        </div>
                    </div>
                    <div class="module-description">
                        <p>Backup your WordPress site to Azure Blob Storage.</p>
                    </div>
                </div>
                
                <div class="module-card <?php echo $calendar_enabled ? 'enabled' : 'disabled'; ?>">
                    <div class="module-header">
                        <h3><span class="dashicons dashicons-calendar-alt"></span> Calendar</h3>
                        <div class="module-controls">
                            <label class="switch">
                                <input type="checkbox" class="module-toggle" data-module="calendar" <?php checked($settings['enable_calendar']); ?> />
                                <span class="slider"></span>
                            </label>
                            <a href="<?php echo admin_url('admin.php?page=azure-plugin-calendar'); ?>" class="button button-configure">Configure</a>
                        </div>
                    </div>
                    <div class="module-description">
                        <p>Calendar embed, sync with The Events Calendar, upcoming events, and volunteer sign-ups.</p>
                        <div class="sub-modules">
                            <label class="sub-module-item">
                                <label class="switch-mini">
                                    <input type="checkbox" class="module-toggle" data-module="tec_integration" <?php checked($settings['enable_tec_integration'] ?? false); ?> />
                                    <span class="slider"></span>
                                </label>
                                Calendar Sync (TEC)
                            </label>
                            <label class="sub-module-item">
                                <label class="switch-mini">
                                    <input type="checkbox" class="module-toggle" data-module="volunteer" <?php checked($settings['enable_volunteer'] ?? false); ?> />
                                    <span class="slider"></span>
                                </label>
                                Volunteer Sign Up
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="module-card <?php echo $settings['enable_email'] ? 'enabled' : 'disabled'; ?>">
                    <div class="module-header">
                        <h3><span class="dashicons dashicons-email-alt"></span> Emails</h3>
                        <div class="module-controls">
                            <label class="switch">
                                <input type="checkbox" class="module-toggle" data-module="email" <?php checked($settings['enable_email']); ?> />
                                <span class="slider"></span>
                            </label>
                            <a href="<?php echo admin_url('admin.php?page=azure-plugin-emails'); ?>" class="button button-configure">Configure</a>
                        </div>
                    </div>
                    <div class="module-description">
                        <p>Send emails through Microsoft Graph API with logging and tracking.</p>
                    </div>
                </div>
                
                <div class="module-card <?php echo $settings['enable_pta'] ? 'enabled' : 'disabled'; ?>">
                    <div class="module-header">
                        <h3><span class="dashicons dashicons-networking"></span> PTA Roles Manager</h3>
                        <div class="module-controls">
                            <label class="switch">
                                <input type="checkbox" class="module-toggle" data-module="pta" <?php checked($settings['enable_pta']); ?> />
                                <span class="slider"></span>
                            </label>
                            <a href="<?php echo admin_url('admin.php?page=azure-plugin-pta'); ?>" class="button button-configure">Configure</a>
                        </div>
                    </div>
                    <div class="module-description">
                        <p>Manage PTA organizational structure with Azure AD sync.</p>
                    </div>
                </div>
                
                <div class="module-card <?php echo ($settings['enable_onedrive_media'] ?? false) ? 'enabled' : 'disabled'; ?>">
                    <div class="module-header">
                        <h3><span class="dashicons dashicons-cloud-upload"></span> OneDrive Media</h3>
                        <div class="module-controls">
                            <label class="switch">
                                <input type="checkbox" class="module-toggle" data-module="onedrive_media" <?php checked($settings['enable_onedrive_media'] ?? false); ?> />
                                <span class="slider"></span>
                            </label>
                            <a href="<?php echo admin_url('admin.php?page=azure-plugin-onedrive-media'); ?>" class="button button-configure">Configure</a>
                        </div>
                    </div>
                    <div class="module-description">
                        <p>Store WordPress media files in OneDrive/SharePoint with CDN optimization.</p>
                    </div>
                </div>
                
                <div class="module-card <?php echo ($settings['enable_newsletter'] ?? false) ? 'enabled' : 'disabled'; ?>">
                    <div class="module-header">
                        <h3><span class="dashicons dashicons-email-alt2"></span> Newsletter</h3>
                        <div class="module-controls">
                            <label class="switch">
                                <input type="checkbox" class="module-toggle" data-module="newsletter" <?php checked($settings['enable_newsletter'] ?? false); ?> />
                                <span class="slider"></span>
                            </label>
                            <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter'); ?>" class="button button-configure">Configure</a>
                        </div>
                    </div>
                    <div class="module-description">
                        <p>Create and send newsletters with drag-drop editor, tracking, and bounce handling.</p>
                    </div>
                </div>
                
                <div class="module-card <?php echo ($settings['enable_tickets'] ?? false) ? 'enabled' : 'disabled'; ?>">
                    <div class="module-header">
                        <h3><span class="dashicons dashicons-tickets-alt"></span> Event Tickets</h3>
                        <div class="module-controls">
                            <label class="switch">
                                <input type="checkbox" class="module-toggle" data-module="tickets" <?php checked($settings['enable_tickets'] ?? false); ?> />
                                <span class="slider"></span>
                            </label>
                            <a href="<?php echo admin_url('admin.php?page=azure-plugin-tickets'); ?>" class="button button-configure">Configure</a>
                        </div>
                    </div>
                    <div class="module-description">
                        <p>Visual seating designer, QR code tickets, Apple Wallet, and event check-in.</p>
                    </div>
                </div>
                
                <div class="module-card <?php echo $selling_any ? 'enabled' : 'disabled'; ?>">
                    <div class="module-header">
                        <h3><span class="dashicons dashicons-cart"></span> Selling</h3>
                        <div class="module-controls">
                            <a href="<?php echo admin_url('admin.php?page=azure-plugin-selling'); ?>" class="button button-configure">Configure</a>
                        </div>
                    </div>
                    <div class="module-description">
                        <p>Auction, Classes, Product Fields, and Donations.</p>
                        <div class="sub-modules">
                            <label class="sub-module-item">
                                <label class="switch-mini">
                                    <input type="checkbox" class="module-toggle" data-module="auction" <?php checked($settings['enable_auction'] ?? false); ?> />
                                    <span class="slider"></span>
                                </label>
                                Auction
                            </label>
                            <label class="sub-module-item">
                                <label class="switch-mini">
                                    <input type="checkbox" class="module-toggle" data-module="classes" <?php checked($settings['enable_classes'] ?? false); ?> />
                                    <span class="slider"></span>
                                </label>
                                Classes
                            </label>
                            <label class="sub-module-item">
                                <label class="switch-mini">
                                    <input type="checkbox" class="module-toggle" data-module="product_fields" <?php checked($settings['enable_product_fields'] ?? false); ?> />
                                    <span class="slider"></span>
                                </label>
                                Product Fields
                            </label>
                            <label class="sub-module-item">
                                <label class="switch-mini">
                                    <input type="checkbox" class="module-toggle" data-module="donations" <?php checked($settings['enable_donations'] ?? false); ?> />
                                    <span class="slider"></span>
                                </label>
                                Donations
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="azure-plugin-dependencies" style="margin-top: 20px;">
            <h2>Plugin Dependencies</h2>
            <p class="description">These third-party plugins are required by various PTA Tools modules. Install and activate any that are needed for your enabled modules.</p>
            <table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">
                <thead>
                    <tr>
                        <th style="width: 25%;">Plugin</th>
                        <th style="width: 15%;">Status</th>
                        <th>Used By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $dependencies = array(
                        array(
                            'name'    => 'The Events Calendar (TEC)',
                            'check'   => class_exists('Tribe__Events__Main'),
                            'modules' => 'TEC Integration, Calendar Sync, Classes, Event Tickets',
                        ),
                        array(
                            'name'    => 'WooCommerce',
                            'check'   => class_exists('WooCommerce'),
                            'modules' => 'Classes, Auction, Event Tickets, Product Fields',
                        ),
                        array(
                            'name'    => 'Forminator',
                            'check'   => class_exists('Forminator'),
                            'modules' => 'PTA Roles (Signup Forms)',
                        ),
                        array(
                            'name'    => 'Beaver Builder',
                            'check'   => class_exists('FLBuilder'),
                            'modules' => 'PTA Roles (Custom Modules)',
                        ),
                        array(
                            'name'    => 'Event Tickets',
                            'check'   => class_exists('Tribe__Tickets__Main'),
                            'modules' => 'Event Tickets, Check-In',
                        ),
                    );
                    foreach ($dependencies as $dep):
                        $status_class = $dep['check'] ? 'success' : 'error';
                        $status_label = $dep['check'] ? 'Active' : 'Not Installed';
                        $icon = $dep['check'] ? 'yes-alt' : 'warning';
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($dep['name']); ?></strong></td>
                        <td>
                            <span class="dashicons dashicons-<?php echo $icon; ?>" style="color: <?php echo $dep['check'] ? '#46b450' : '#dc3232'; ?>; vertical-align: middle;"></span>
                            <span style="color: <?php echo $dep['check'] ? '#46b450' : '#dc3232'; ?>;"><?php echo $status_label; ?></span>
                        </td>
                        <td><?php echo esc_html($dep['modules']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="azure-plugin-settings">
            <form method="post" action="">
                <?php wp_nonce_field('azure_plugin_settings'); ?>
                
                <!-- Hidden inputs to mirror module toggle states -->
                <input type="hidden" name="enable_sso" id="hidden_enable_sso" value="<?php echo $settings['enable_sso'] ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_backup" id="hidden_enable_backup" value="<?php echo $settings['enable_backup'] ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_calendar" id="hidden_enable_calendar" value="<?php echo $settings['enable_calendar'] ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_email" id="hidden_enable_email" value="<?php echo $settings['enable_email'] ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_pta" id="hidden_enable_pta" value="<?php echo $settings['enable_pta'] ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_tec_integration" id="hidden_enable_tec_integration" value="<?php echo ($settings['enable_tec_integration'] ?? false) ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_onedrive_media" id="hidden_enable_onedrive_media" value="<?php echo ($settings['enable_onedrive_media'] ?? false) ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_classes" id="hidden_enable_classes" value="<?php echo ($settings['enable_classes'] ?? false) ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_newsletter" id="hidden_enable_newsletter" value="<?php echo ($settings['enable_newsletter'] ?? false) ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_tickets" id="hidden_enable_tickets" value="<?php echo ($settings['enable_tickets'] ?? false) ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_auction" id="hidden_enable_auction" value="<?php echo ($settings['enable_auction'] ?? false) ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_product_fields" id="hidden_enable_product_fields" value="<?php echo ($settings['enable_product_fields'] ?? false) ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_volunteer" id="hidden_enable_volunteer" value="<?php echo ($settings['enable_volunteer'] ?? false) ? '1' : '0'; ?>" />
                <input type="hidden" name="enable_donations" id="hidden_enable_donations" value="<?php echo ($settings['enable_donations'] ?? false) ? '1' : '0'; ?>" />
                
                <div class="credentials-section">
                    <h2>Azure Credentials</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Use Common Credentials</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="use_common_credentials" id="use_common_credentials" <?php checked($settings['use_common_credentials'] ?? true); ?> />
                                    Use the same Azure credentials for all enabled modules
                                </label>
                                <p class="description">When enabled, all modules will use the common credentials below. When disabled, each module can have its own credentials.</p>
                            </td>
                        </tr>
                    </table>
                    
                    <div id="common-credentials" <?php echo !($settings['use_common_credentials'] ?? true) ? 'style="display:none;"' : ''; ?>>
                        <h3>Common Credentials</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Client ID</th>
                                <td>
                                    <input type="text" name="common_client_id" id="common_client_id" value="<?php echo esc_attr($settings['common_client_id'] ?? ''); ?>" class="regular-text" />
                                    <p class="description">Your Azure App Registration Client ID</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Client Secret</th>
                                <td>
                                    <input type="password" name="common_client_secret" id="common_client_secret" value="<?php echo esc_attr($settings['common_client_secret'] ?? ''); ?>" class="regular-text" />
                                    <p class="description">Your Azure App Registration Client Secret</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Tenant ID</th>
                                <td>
                                    <input type="text" name="common_tenant_id" id="common_tenant_id" value="<?php echo esc_attr($settings['common_tenant_id'] ?? 'common'); ?>" class="regular-text" />
                                    <p class="description">Your Azure Tenant ID (or 'common' for multi-tenant)</p>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2">
                                    <button type="button" class="button test-credentials" 
                                        data-client-id-field="common_client_id" 
                                        data-client-secret-field="common_client_secret" 
                                        data-tenant-id-field="common_tenant_id">
                                        Test Credentials
                                    </button>
                                    <span class="credentials-status"></span>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <div class="debug-section" style="margin-top: 20px;">
                    <h2>Debug Settings</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="debug_mode">Debug Mode</label>
                            </th>
                            <td>
                                <input type="checkbox" id="debug_mode" name="debug_mode" value="1" 
                                       <?php checked($settings['debug_mode'] ?? false); ?> />
                                <label for="debug_mode">Enable detailed debug logging</label>
                                <p class="description">
                                    ⚠️ <strong>Warning:</strong> Only enable for troubleshooting. Requires WP_DEBUG to be enabled in wp-config.php. 
                                    <br>Impacts performance when enabled. Logs are written to <code>wp-content/plugins/PTA Tools/logs.md</code>
                                </p>
                            </td>
                        </tr>
                        
                        <tr id="debug-modules-row" style="<?php echo ($settings['debug_mode'] ?? false) ? '' : 'display:none;'; ?>">
                            <th scope="row">Debug Modules</th>
                            <td>
                                <?php
                                $debug_modules = $settings['debug_modules'] ?? array();
                                $available_modules = array('Core', 'SSO', 'Calendar', 'TEC', 'Email', 'Backup', 'PTA', 'OneDrive');
                                foreach ($available_modules as $module):
                                ?>
                                <label style="display: inline-block; margin-right: 15px; margin-bottom: 5px;">
                                    <input type="checkbox" name="debug_modules[]" value="<?php echo esc_attr($module); ?>"
                                           <?php checked(in_array($module, $debug_modules)); ?> />
                                    <?php echo esc_html($module); ?>
                                </label>
                                <?php endforeach; ?>
                                <p class="description">
                                    Select specific modules to debug. Leave all unchecked to debug all modules.
                                    <br><strong>Tip:</strong> Enable only the module you're troubleshooting to reduce log noise.
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <p class="submit">
                    <input type="submit" name="azure_plugin_submit" class="button-primary" value="Save Settings" />
                </p>
            </form>
        </div>
        
        <div class="azure-plugin-info">
            <div class="info-box">
                <h3>Quick Setup Guide</h3>
                <ol>
                    <li>Create an Azure App Registration in your Azure portal</li>
                    <li>Add the required API permissions for the modules you want to use</li>
                    <li>Copy the Client ID, Client Secret, and Tenant ID to the credentials section above</li>
                    <li>Enable the modules you want to use</li>
                    <li>Configure each module using the links above</li>
                </ol>
            </div>
            
            <div class="info-box">
                <h3>Required API Permissions</h3>
                <ul>
                    <li><strong>SSO:</strong> User.Read, openid, profile, email</li>
                    <li><strong>Backup:</strong> Files.ReadWrite.All (for backup storage)</li>
                    <li><strong>Calendar:</strong> Calendar.Read, Calendar.ReadWrite</li>
                    <li><strong>Email:</strong> Mail.Send, Mail.ReadWrite</li>
                </ul>
            </div>
            
            <div class="info-box">
                <h3>Support & Documentation</h3>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=azure-plugin-system'); ?>" class="button">View Logs</a>
                    <a href="#" class="button" onclick="location.reload();">Refresh Status</a>
                </p>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Handle common credentials toggle
    $('#use_common_credentials').change(function() {
        if ($(this).is(':checked')) {
            $('#common-credentials').slideDown();
        } else {
            $('#common-credentials').slideUp();
        }
    });
    
    // Handle debug mode toggle
    $('#debug_mode').on('change', function() {
        if ($(this).is(':checked')) {
            $('#debug-modules-row').slideDown('fast');
        } else {
            $('#debug-modules-row').slideUp('fast');
        }
    });
    
    // Sync hidden form inputs when module toggles change. The actual AJAX save
    // is handled centrally by admin.js — this handler ONLY keeps the hidden
    // form inputs in sync so that if the user later clicks "Save Settings"
    // the form POST does not accidentally revert module states. Having a
    // second AJAX request fire here was causing race conditions where a
    // duplicate save would occasionally clobber the first (most visibly on
    // the PTA module toggle).
    $(document).on('change', '.module-toggle', function() {
        var module = $(this).data('module');
        var enabled = $(this).is(':checked');
        if (module) {
            $('#hidden_enable_' + module).val(enabled ? '1' : '0');
        }
    });
    
    // Handle credentials test
    $('.test-credentials').click(function() {
        var button = $(this);
        var status = button.siblings('.credentials-status');
        var clientIdField = $('#' + button.data('client-id-field'));
        var clientSecretField = $('#' + button.data('client-secret-field'));
        var tenantIdField = $('#' + button.data('tenant-id-field'));
        
        button.prop('disabled', true).text('Testing...');
        status.html('<span class="spinner is-active"></span>');
        
        $.ajax({
            url: azure_plugin_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'azure_test_credentials',
                client_id: clientIdField.val(),
                client_secret: clientSecretField.val(),
                tenant_id: tenantIdField.val(),
                nonce: azure_plugin_ajax.nonce
            },
            success: function(response) {
            button.prop('disabled', false).text('Test Credentials');
            
            console.log('Test Credentials Response:', response);
            console.log('Response type:', typeof response);
            console.log('Response keys:', response ? Object.keys(response) : 'null');
            
            // Handle string responses (jQuery might not parse as JSON if there's whitespace)
            if (typeof response === 'string') {
                try {
                    response = JSON.parse(response.trim());
                    console.log('Parsed string response:', response);
                } catch (e) {
                    console.error('Failed to parse response:', e);
                    status.html('<span class="dashicons dashicons-dismiss" style="color: red;"></span> Invalid JSON response from server');
                    setTimeout(function() { status.fadeOut(); }, 5000);
                    return;
                }
            }
            
            // WordPress AJAX format: response.success, response.data
            if (response && typeof response === 'object' && ('success' in response || response.hasOwnProperty('success'))) {
                if (response.success === true || response.success === 'true') {
                    // Success response: response.data contains the validation result
                    var message = (response.data && response.data.message) || 'Credentials are valid';
                    status.html('<span class="dashicons dashicons-yes-alt" style="color: green; font-weight: bold;"></span> <strong style="color: green;">' + message + '</strong>');
                    status.show(); // Keep it visible permanently
                } else {
                    // Error response: response.data is the error message
                    var errorMsg = typeof response.data === 'string' ? response.data : (response.data ? JSON.stringify(response.data) : 'Credentials validation failed');
                    status.html('<span class="dashicons dashicons-dismiss" style="color: red;"></span> <strong style="color: red;">' + errorMsg + '</strong>');
                    // Only fade out errors after 8 seconds
                    setTimeout(function() {
                        status.fadeOut();
                    }, 8000);
                }
            } else {
                console.error('Invalid response structure:', response);
                status.html('<span class="dashicons dashicons-dismiss" style="color: red;"></span> Invalid response from server. Check console for details.');
                setTimeout(function() {
                    status.fadeOut();
                }, 8000);
            }
            },
            error: function(xhr, status, error) {
                button.prop('disabled', false).text('Test Credentials');
                console.error('AJAX Error:', xhr.responseText);
                status.html('<span class="dashicons dashicons-dismiss" style="color: red;"></span> <strong style="color: red;">Network error: ' + (error || 'Unknown error') + '</strong>');
                
                setTimeout(function() {
                    status.fadeOut();
                }, 8000);
            }
        });
    });
});
</script>