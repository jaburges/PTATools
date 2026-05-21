<?php
if (!defined('ABSPATH')) {
    exit;
}

// Get email queue statistics
$email_stats = array();
global $wpdb;
$email_queue_table = Azure_Database::get_table_name('email_queue');

if ($email_queue_table) {
    $email_stats['pending_count'] = $wpdb->get_var("SELECT COUNT(*) FROM {$email_queue_table} WHERE status = 'pending'");
    $email_stats['sent_count'] = $wpdb->get_var("SELECT COUNT(*) FROM {$email_queue_table} WHERE status = 'sent' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAYS)");
    $email_stats['failed_count'] = $wpdb->get_var("SELECT COUNT(*) FROM {$email_queue_table} WHERE status = 'failed'");
    $email_stats['total_count'] = $wpdb->get_var("SELECT COUNT(*) FROM {$email_queue_table}");
}

// Get authorized users for Graph API
$authorized_users = array();
if (class_exists('Azure_Email_Auth')) {
    $auth = new Azure_Email_Auth();
    $authorized_users = $auth->get_authorized_users();
}

// Get recent email queue items
$recent_emails = array();
if ($email_queue_table) {
    $recent_emails = $wpdb->get_results("SELECT * FROM {$email_queue_table} ORDER BY created_at DESC LIMIT 10");
}

// Handle auth success message
$show_auth_success = isset($_GET['auth']) && $_GET['auth'] === 'success';
?>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
<div class="wrap">
    <h1>PTA Tools - Email Settings</h1>
<?php endif; ?>
    
    <!-- Module Toggle Section -->
    <div class="module-status-section">
        <h2>Module Status</h2>
        <div class="module-toggle-card">
            <div class="module-info">
                <h3><span class="dashicons dashicons-email-alt"></span> Email Sender Module</h3>
                <p>Send emails through Microsoft Graph API</p>
            </div>
            <div class="module-control">
                <label class="switch">
                    <input type="checkbox" class="email-module-toggle" <?php checked(Azure_Settings::is_module_enabled('email')); ?> />
                    <span class="slider"></span>
                </label>
                <span class="toggle-status"><?php echo Azure_Settings::is_module_enabled('email') ? 'Enabled' : 'Disabled'; ?></span>
            </div>
        </div>
        <?php if (!Azure_Settings::is_module_enabled('email')): ?>
        <div class="notice notice-warning inline">
            <p><strong>Email module is disabled.</strong> Enable it above or in the <a href="<?php echo admin_url('admin.php?page=azure-plugin'); ?>">main settings</a> to use email functionality.</p>
        </div>
        <?php endif; ?>
    </div>
    
    <?php if ($show_auth_success): ?>
    <div class="notice notice-success is-dismissible">
        <p><strong>Success!</strong> Email authorization completed successfully. You can now send emails via Microsoft Graph API.</p>
    </div>
    <?php endif; ?>
    
    <div class="azure-email-dashboard">
        <!-- Email Statistics -->
        <div class="email-stats-section">
            <h2>Email Statistics</h2>
            
            <div class="stats-cards">
                <div class="stat-card warning">
                    <div class="stat-number"><?php echo intval($email_stats['pending_count'] ?? 0); ?></div>
                    <div class="stat-label">Pending</div>
                </div>
                
                <div class="stat-card success">
                    <div class="stat-number"><?php echo intval($email_stats['sent_count'] ?? 0); ?></div>
                    <div class="stat-label">Sent (7 days)</div>
                </div>
                
                <div class="stat-card error">
                    <div class="stat-number"><?php echo intval($email_stats['failed_count'] ?? 0); ?></div>
                    <div class="stat-label">Failed</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-number"><?php echo intval($email_stats['total_count'] ?? 0); ?></div>
                    <div class="stat-label">Total Emails</div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="email-actions-section">
            <h2>Quick Actions</h2>
            
            <div class="action-buttons">
                <button type="button" class="button button-primary send-test-email-btn">
                    <span class="dashicons dashicons-email-alt"></span>
                    Send Test Email
                </button>
                
                <button type="button" class="button process-email-queue">
                    <span class="dashicons dashicons-update"></span>
                    Process Queue Now
                </button>
                
                <button type="button" class="button clear-email-queue">
                    <span class="dashicons dashicons-trash"></span>
                    Clear Sent/Failed
                </button>
            </div>
            
            <div class="test-email-form" style="display: none;">
                <h3>Send Test Email</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">Test Email Address</th>
                        <td>
                            <input type="email" id="test_email_address" value="<?php echo esc_attr(get_option('admin_email')); ?>" class="regular-text" />
                            <button type="button" class="button send-test-email">Send Test</button>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- Authorization Section (for Graph API) -->
        <?php if (Azure_Settings::get_setting('email_auth_method', 'graph_api') === 'graph_api'): ?>
        <div class="email-auth-section">
            <h2>Graph API Authorization</h2>
            
            <div class="auth-info">
                <?php if (!empty($authorized_users)): ?>
                <p><strong>Authorized Users:</strong></p>
                <div class="authorized-users">
                    <?php foreach ($authorized_users as $user): ?>
                    <div class="authorized-user">
                        <span class="user-email"><?php echo esc_html($user->user_email); ?></span>
                        <span class="expires-at">Expires: <?php echo esc_html($user->expires_at); ?></span>
                        <button type="button" class="button button-small revoke-user-token" data-user-email="<?php echo esc_attr($user->user_email); ?>">Revoke</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p>No users have authorized email access yet.</p>
                <?php endif; ?>
                
                <div class="auth-actions">
                    <input type="email" id="auth_user_email" placeholder="user@domain.com" class="regular-text" />
                    <button type="button" class="button button-primary authorize-email-user">
                        Authorize User for Email Sending
                    </button>
                </div>
                <p class="description">Users must authorize their Microsoft account to send emails on their behalf.</p>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Settings Form -->
        <div class="email-settings-section">
            <form method="post" action="">
                <?php wp_nonce_field('azure_plugin_settings'); ?>
                
                <!-- Authentication Method -->
                <div class="email-auth-method">
                    <h2>Authentication Method</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Email Service</th>
                            <td>
                                <?php $current_method = Azure_Settings::get_setting('email_auth_method', 'graph_api'); ?>
                                <label>
                                    <input type="radio" name="email_auth_method" value="graph_api" <?php checked($current_method, 'graph_api'); ?> />
                                    Microsoft Graph API (Recommended)
                                </label><br>
                                <label>
                                    <input type="radio" name="email_auth_method" value="hve" <?php checked($current_method, 'hve'); ?> />
                                    High Volume Email (HVE) SMTP
                                </label><br>
                                <label>
                                    <input type="radio" name="email_auth_method" value="acs" <?php checked($current_method, 'acs'); ?> />
                                    Azure Communication Services (ACS)
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Credentials Section -->
                <?php if (!($settings['use_common_credentials'] ?? true)): ?>
                <div class="credentials-section graph-api-settings" <?php echo $current_method !== 'graph_api' ? 'style="display:none;"' : ''; ?>>
                    <h2>Graph API Credentials</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Client ID</th>
                            <td>
                                <input type="text" name="email_client_id" value="<?php echo esc_attr($settings['email_client_id'] ?? ''); ?>" class="regular-text" />
                                <p class="description">Your Azure App Registration Client ID</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Client Secret</th>
                            <td>
                                <input type="password" name="email_client_secret" value="<?php echo esc_attr($settings['email_client_secret'] ?? ''); ?>" class="regular-text" />
                                <p class="description">Your Azure App Registration Client Secret</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Tenant ID</th>
                            <td>
                                <input type="text" name="email_tenant_id" value="<?php echo esc_attr($settings['email_tenant_id'] ?? 'common'); ?>" class="regular-text" />
                                <p class="description">Your Azure Tenant ID (or 'common' for multi-tenant)</p>
                            </td>
                        </tr>
                    </table>
                </div>
                <?php endif; ?>
                
                <!-- Graph API Settings -->
                <div class="graph-api-settings" <?php echo $current_method !== 'graph_api' ? 'style="display:none;"' : ''; ?>>
                    <h2>Graph API Settings</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Send As Alias</th>
                            <td>
                                <input type="email" name="email_send_as_alias" value="<?php echo esc_attr($settings['email_send_as_alias'] ?? ''); ?>" class="regular-text" />
                                <p class="description">Optional: Send emails as a specific user or shared mailbox</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Override wp_mail()</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="email_override_wp_mail" <?php checked($settings['email_override_wp_mail'] ?? false); ?> />
                                    Replace WordPress default email function with Graph API
                                </label>
                                <p class="description">All WordPress emails will be sent via Microsoft Graph API</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- HVE Settings -->
                <div class="hve-settings" <?php echo $current_method !== 'hve' ? 'style="display:none;"' : ''; ?>>
                    <h2>High Volume Email Settings</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">SMTP Server</th>
                            <td>
                                <input type="text" name="email_hve_smtp_server" value="<?php echo esc_attr($settings['email_hve_smtp_server'] ?? 'smtp-hve.office365.com'); ?>" class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">SMTP Port</th>
                            <td>
                                <input type="number" name="email_hve_smtp_port" value="<?php echo intval($settings['email_hve_smtp_port'] ?? 587); ?>" class="small-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Username</th>
                            <td>
                                <input type="text" name="email_hve_username" value="<?php echo esc_attr($settings['email_hve_username'] ?? ''); ?>" class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Password</th>
                            <td>
                                <input type="password" name="email_hve_password" value="<?php echo esc_attr($settings['email_hve_password'] ?? ''); ?>" class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">From Email</th>
                            <td>
                                <input type="email" name="email_hve_from_email" value="<?php echo esc_attr($settings['email_hve_from_email'] ?? ''); ?>" class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Encryption</th>
                            <td>
                                <select name="email_hve_encryption">
                                    <option value="tls" <?php selected($settings['email_hve_encryption'] ?? 'tls', 'tls'); ?>>TLS</option>
                                    <option value="ssl" <?php selected($settings['email_hve_encryption'] ?? 'tls', 'ssl'); ?>>SSL</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Override wp_mail()</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="email_hve_override_wp_mail" <?php checked($settings['email_hve_override_wp_mail'] ?? false); ?> />
                                    Replace WordPress default email function with HVE SMTP
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- ACS Settings -->
                <div class="acs-settings" <?php echo $current_method !== 'acs' ? 'style="display:none;"' : ''; ?>>
                    <h2>Azure Communication Services Settings</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Connection String</th>
                            <td>
                                <textarea name="email_acs_connection_string" class="large-text" rows="3"><?php echo esc_textarea($settings['email_acs_connection_string'] ?? ''); ?></textarea>
                                <p class="description">ACS connection string from Azure portal</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Endpoint</th>
                            <td>
                                <input type="url" name="email_acs_endpoint" value="<?php echo esc_attr($settings['email_acs_endpoint'] ?? ''); ?>" class="regular-text" />
                                <p class="description">ACS endpoint URL</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Access Key</th>
                            <td>
                                <input type="password" name="email_acs_access_key" value="<?php echo esc_attr($settings['email_acs_access_key'] ?? ''); ?>" class="regular-text" />
                                <p class="description">ACS access key</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">From Email</th>
                            <td>
                                <input type="email" name="email_acs_from_email" value="<?php echo esc_attr($settings['email_acs_from_email'] ?? ''); ?>" class="regular-text" />
                                <p class="description">Verified sender email address</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Display Name</th>
                            <td>
                                <input type="text" name="email_acs_display_name" value="<?php echo esc_attr($settings['email_acs_display_name'] ?? ''); ?>" class="regular-text" />
                                <p class="description">Display name for sender</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Override wp_mail()</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="email_acs_override_wp_mail" <?php checked($settings['email_acs_override_wp_mail'] ?? false); ?> />
                                    Replace WordPress default email function with ACS
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <p class="submit">
                    <input type="submit" name="azure_plugin_submit" class="button-primary" value="Save Email Settings" />
                </p>
            </form>
        </div>
        
        <!-- Email Queue -->
        <div class="email-queue-section">
            <h2>Email Queue</h2>
            
            <?php if (!empty($recent_emails)): ?>
            <table class="email-queue-table widefat striped">
                <thead>
                    <tr>
                        <th>To</th>
                        <th>Subject</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Attempts</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_emails as $email): ?>
                    <tr>
                        <td><?php echo esc_html(wp_trim_words($email->to_email, 3)); ?></td>
                        <td><?php echo esc_html(wp_trim_words($email->subject, 8)); ?></td>
                        <td>
                            <span class="email-status <?php echo esc_attr($email->status); ?>">
                                <?php echo esc_html(ucfirst($email->status)); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($email->created_at); ?></td>
                        <td><?php echo intval($email->attempts); ?>/<?php echo intval($email->max_attempts); ?></td>
                        <td>
                            <?php if ($email->status === 'failed' && !empty($email->error_message)): ?>
                            <button type="button" class="button button-small view-error" data-error="<?php echo esc_attr($email->error_message); ?>">
                                View Error
                            </button>
                            <?php endif; ?>
                            
                            <?php if ($email->status === 'pending'): ?>
                            <button type="button" class="button button-small retry-email" data-email-id="<?php echo $email->id; ?>">
                                Retry Now
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p>No emails in queue.</p>
            <?php endif; ?>
        </div>
        
        <!-- Shortcode Documentation -->
        <div class="email-shortcodes-section">
            <h2>Email Shortcodes</h2>
            
            <div class="shortcode-documentation">
                <div class="shortcode-example">
                    <h4>Contact Form</h4>
                    <code>[azure_contact_form to="admin@site.com" subject="Contact Form"]</code>
                    
                    <h5>Parameters:</h5>
                    <ul>
                        <li><code>to</code> - Recipient email (default: admin email)</li>
                        <li><code>subject</code> - Email subject</li>
                        <li><code>success_message</code> - Success message text</li>
                        <li><code>show_name</code> - Show name field (true/false)</li>
                        <li><code>show_email</code> - Show email field (true/false)</li>
                        <li><code>show_phone</code> - Show phone field (true/false)</li>
                        <li><code>required_fields</code> - Comma-separated required fields</li>
                    </ul>
                </div>
                
                <div class="shortcode-example">
                    <h4>Email Status (Admin Only)</h4>
                    <code>[azure_email_status]</code>
                    
                    <h5>Parameters:</h5>
                    <ul>
                        <li><code>show_queue_count</code> - Show queue statistics (true/false)</li>
                        <li><code>show_method</code> - Show current method (true/false)</li>
                        <li><code>show_last_sent</code> - Show last sent time (true/false)</li>
                    </ul>
                </div>
                
                <div class="shortcode-example">
                    <h4>Email Queue (Admin Only)</h4>
                    <code>[azure_email_queue limit="10" status="all"]</code>
                    
                    <h5>Parameters:</h5>
                    <ul>
                        <li><code>limit</code> - Number of emails to show</li>
                        <li><code>status</code> - Filter by status (all, pending, sent, failed)</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
</div>
<?php endif; ?>

<script>
jQuery(document).ready(function($) {
    // Handle authentication method change
    $('input[name="email_auth_method"]').change(function() {
        var method = $(this).val();
        
        $('.graph-api-settings, .hve-settings, .acs-settings').hide();
        $('.' + method + '-settings').show();
    });
    
    // Show/hide test email form
    $('.send-test-email-btn').click(function() {
        $('.test-email-form').toggle();
    });
    
    // Send test email
    $('.send-test-email').click(function() {
        var email = $('#test_email_address').val();
        
        if (!email) {
            alert('Please enter a test email address');
            return;
        }
        
        var button = $(this);
        button.prop('disabled', true).text('Sending...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_send_test_email',
            test_email: email,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).text('Send Test');
            
            if (response.success) {
                alert('✅ Test email sent successfully to ' + email);
            } else {
                alert('❌ Failed to send test email: ' + (response.data || 'Unknown error'));
            }
        });
    });
    
    // Process email queue
    $('.process-email-queue').click(function() {
        var button = $(this);
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Processing...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_process_email_queue',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Process Queue Now');
            
            if (response.success) {
                alert('✅ Email queue processed successfully');
                location.reload();
            } else {
                alert('❌ Failed to process queue: ' + (response.data || 'Unknown error'));
            }
        });
    });
    
    // Clear email queue
    $('.clear-email-queue').click(function() {
        if (!confirm('Are you sure you want to clear sent and failed emails from the queue?')) {
            return;
        }
        
        var button = $(this);
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Clearing...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_clear_email_queue',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Clear Sent/Failed');
            
            if (response.success) {
                alert('✅ ' + response.data);
                location.reload();
            } else {
                alert('❌ Failed to clear queue: ' + (response.data || 'Unknown error'));
            }
        });
    });
    
    // Authorize email user
    $('.authorize-email-user').click(function() {
        var email = $('#auth_user_email').val();
        
        if (!email) {
            alert('Please enter an email address');
            return;
        }
        
        var button = $(this);
        button.prop('disabled', true).text('Authorizing...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_email_authorize',
            user_email: email,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success && response.data.auth_url) {
                window.open(response.data.auth_url, 'azure_email_auth', 'width=600,height=700');
                button.prop('disabled', false).text('Authorize User for Email Sending');
            } else {
                alert('❌ Failed to generate authorization URL: ' + (response.data || 'Unknown error'));
                button.prop('disabled', false).text('Authorize User for Email Sending');
            }
        });
    });
    
    // Revoke user token
    $('.revoke-user-token').click(function() {
        var userEmail = $(this).data('user-email');
        var userDiv = $(this).closest('.authorized-user');
        
        if (!confirm('Are you sure you want to revoke access for ' + userEmail + '?')) {
            return;
        }
        
        var button = $(this);
        button.prop('disabled', true).text('Revoking...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_email_revoke',
            user_email: userEmail,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                userDiv.fadeOut(function() {
                    userDiv.remove();
                });
            } else {
                alert('❌ Failed to revoke access: ' + (response.data || 'Unknown error'));
                button.prop('disabled', false).text('Revoke');
            }
        });
    });
    
    // View error details
    $('.view-error').click(function() {
        var error = $(this).data('error');
        alert('Error Details:\n\n' + error);
    });
    
    // Retry email
    $('.retry-email').click(function() {
        var emailId = $(this).data('email-id');
        var button = $(this);
        var row = button.closest('tr');
        
        button.prop('disabled', true).text('Retrying...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_retry_email',
            email_id: emailId,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                row.find('.email-status').removeClass('failed').addClass('pending').text('Pending');
                button.remove();
            } else {
                alert('❌ Failed to retry email: ' + (response.data || 'Unknown error'));
                button.prop('disabled', false).text('Retry Now');
            }
        });
    });
    
    // Handle module toggle
    $('.email-module-toggle').change(function() {
        var enabled = $(this).is(':checked');
        var statusText = $('.toggle-status');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_toggle_module',
            module: 'email',
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
                $('.email-module-toggle').prop('checked', !enabled);
                alert('Failed to toggle Email module: ' + (response.data || 'Unknown error'));
            }
        }).fail(function() {
            // Revert toggle if failed
            $('.email-module-toggle').prop('checked', !enabled);
            alert('Network error occurred');
        });
    });
});
</script>

<style>
.email-stats-section {
    margin-bottom: 20px;
}

.email-actions-section {
    margin-bottom: 30px;
}

.test-email-form {
    background: #f9f9f9;
    padding: 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin-top: 15px;
}

.email-auth-section {
    background: #fff;
    padding: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    margin-bottom: 20px;
}

.authorized-users {
    margin: 15px 0;
}

.authorized-user {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 10px;
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin-bottom: 10px;
}

.authorized-user .user-email {
    font-weight: bold;
    flex: 1;
}

.authorized-user .expires-at {
    font-size: 12px;
    color: #666;
}

.auth-actions {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-top: 15px;
}

.email-auth-method,
.graph-api-settings,
.hve-settings,
.acs-settings {
    background: #fff;
    padding: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    margin-bottom: 20px;
}

.email-queue-table {
    margin-top: 15px;
}

.email-queue-table th,
.email-queue-table td {
    padding: 10px;
}

.email-status {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
}

.email-status.pending {
    background: #fff3cd;
    color: #856404;
}

.email-status.sent {
    background: #d4edda;
    color: #155724;
}

.email-status.failed {
    background: #f8d7da;
    color: #721c24;
}

.email-shortcodes-section {
    background: #fff;
    padding: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    margin-top: 20px;
}

.shortcode-documentation {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
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

.shortcode-example h5 {
    margin: 15px 0 5px 0;
    color: #333;
}

.shortcode-example ul {
    margin: 0;
    padding-left: 20px;
}

.shortcode-example li {
    margin-bottom: 5px;
    font-size: 13px;
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
.email-stats-section,
.email-actions-section,
.email-auth-section,
.email-authentication,
.email-providers,
.email-usage-section,
.email-shortcodes-section {
    background: #fff !important;
    color: #333 !important;
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
.email-stats-section h2,
.email-actions-section h2,
.email-auth-section h2,
.email-authentication h2,
.email-providers h2,
.email-usage-section h2,
.email-shortcodes-section h2 {
    color: #333 !important;
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
}

/* Stat Cards */
.stat-card {
    background: #fff !important;
    color: #333 !important;
}

.stat-number {
    color: #0073aa !important;
}

.stat-label {
    color: #666 !important;
}

/* User Cards */
.user-card {
    background: #fff !important;
    color: #333 !important;
    border: 1px solid #ccd0d4 !important;
}

.user-card h4 {
    color: #0073aa !important;
}

.user-card p {
    color: #666 !important;
}

/* WordPress Dark Theme Overrides */
body.admin-color-midnight .module-toggle-card,
body.admin-color-midnight .email-stats-section,
body.admin-color-midnight .email-actions-section,
body.admin-color-midnight .email-auth-section,
body.admin-color-midnight .email-authentication,
body.admin-color-midnight .email-providers,
body.admin-color-midnight .email-usage-section,
body.admin-color-midnight .email-shortcodes-section,
body.admin-color-midnight .stat-card,
body.admin-color-midnight .user-card {
    background: #fff !important;
    color: #333 !important;
    border-color: #ccd0d4 !important;
}
</style>
