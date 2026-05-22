<?php
/**
 * Newsletter Settings Tab
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle settings save
if (isset($_POST['save_newsletter_settings']) && wp_verify_nonce($_POST['_wpnonce'], 'newsletter_settings')) {
    $settings_to_save = array(
        'newsletter_sending_service' => sanitize_text_field($_POST['newsletter_sending_service'] ?? 'mailgun'),
        'newsletter_batch_size' => intval($_POST['newsletter_batch_size'] ?? 100),
        'newsletter_rate_limit_per_hour' => intval($_POST['newsletter_rate_limit_per_hour'] ?? 1000),
        'newsletter_default_category' => sanitize_text_field($_POST['newsletter_default_category'] ?? 'newsletter'),
        'newsletter_reply_to' => sanitize_email($_POST['newsletter_reply_to'] ?? ''),
        'newsletter_bounce_enabled' => isset($_POST['newsletter_bounce_enabled']),
        'newsletter_bounce_mailbox' => sanitize_email($_POST['newsletter_bounce_mailbox'] ?? ''),
        
        // Mailgun settings
        'newsletter_mailgun_api_key' => sanitize_text_field($_POST['newsletter_mailgun_api_key'] ?? ''),
        'newsletter_mailgun_webhook_key' => sanitize_text_field($_POST['newsletter_mailgun_webhook_key'] ?? ''),
        'newsletter_mailgun_domain' => sanitize_text_field($_POST['newsletter_mailgun_domain'] ?? ''),
        'newsletter_mailgun_region' => sanitize_text_field($_POST['newsletter_mailgun_region'] ?? 'us'),
        
        // SendGrid settings
        'newsletter_sendgrid_api_key' => sanitize_text_field($_POST['newsletter_sendgrid_api_key'] ?? ''),
        
        // Amazon SES settings
        'newsletter_ses_access_key' => sanitize_text_field($_POST['newsletter_ses_access_key'] ?? ''),
        'newsletter_ses_secret_key' => sanitize_text_field($_POST['newsletter_ses_secret_key'] ?? ''),
        'newsletter_ses_region' => sanitize_text_field($_POST['newsletter_ses_region'] ?? 'us-east-1'),
        
        // Custom SMTP settings
        'newsletter_smtp_host' => sanitize_text_field($_POST['newsletter_smtp_host'] ?? ''),
        'newsletter_smtp_port' => intval($_POST['newsletter_smtp_port'] ?? 587),
        'newsletter_smtp_username' => sanitize_text_field($_POST['newsletter_smtp_username'] ?? ''),
        'newsletter_smtp_password' => sanitize_text_field($_POST['newsletter_smtp_password'] ?? ''),
        'newsletter_smtp_encryption' => sanitize_text_field($_POST['newsletter_smtp_encryption'] ?? 'tls'),
    );
    
    // Save From addresses
    $from_addresses = array();
    if (!empty($_POST['from_email']) && !empty($_POST['from_name'])) {
        $emails = array_map('sanitize_email', (array)$_POST['from_email']);
        $names = array_map('sanitize_text_field', (array)$_POST['from_name']);
        for ($i = 0; $i < count($emails); $i++) {
            if (!empty($emails[$i]) && !empty($names[$i])) {
                $from_addresses[] = array(
                    'email' => $emails[$i],
                    'name' => $names[$i]
                );
            }
        }
    }
    $settings_to_save['newsletter_from_addresses'] = $from_addresses;
    
    Azure_Settings::update_settings($settings_to_save);
    echo '<div class="notice notice-success"><p>' . __('Settings saved successfully.', 'azure-plugin') . '</p></div>';
    
    // Refresh settings
    $settings = Azure_Settings::get_all_settings();
}

$sending_service = $settings['newsletter_sending_service'] ?? 'mailgun';
$from_addresses = $settings['newsletter_from_addresses'] ?? array();
?>

<div class="newsletter-settings">
    <form method="post">
        <?php wp_nonce_field('newsletter_settings'); ?>
        
        <!-- Sending Service Selection -->
        <div class="settings-section">
            <h3><?php _e('Sending Service', 'azure-plugin'); ?></h3>
            <p class="description"><?php _e('Choose how to send your newsletters. We recommend using a dedicated email service for best deliverability.', 'azure-plugin'); ?></p>
            
            <table class="form-table">
                <tr>
                    <th><label for="newsletter_sending_service"><?php _e('Email Service', 'azure-plugin'); ?></label></th>
                    <td>
                        <select name="newsletter_sending_service" id="newsletter_sending_service" class="regular-text">
                            <option value="mailgun" <?php selected($sending_service, 'mailgun'); ?>>Mailgun</option>
                            <option value="sendgrid" <?php selected($sending_service, 'sendgrid'); ?>>SendGrid</option>
                            <option value="ses" <?php selected($sending_service, 'ses'); ?>>Amazon SES</option>
                            <option value="smtp" <?php selected($sending_service, 'smtp'); ?>>Custom SMTP</option>
                            <option value="office365" <?php selected($sending_service, 'office365'); ?>>Office 365 (via Azure)</option>
                        </select>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Mailgun Settings -->
        <div class="service-settings" id="mailgun-settings" style="<?php echo $sending_service !== 'mailgun' ? 'display:none;' : ''; ?>">
            <h4><?php _e('Mailgun Settings', 'azure-plugin'); ?></h4>
            <table class="form-table">
                <tr>
                    <th><label><?php _e('API Key', 'azure-plugin'); ?></label></th>
                    <td>
                        <input type="password" name="newsletter_mailgun_api_key" 
                               value="<?php echo esc_attr($settings['newsletter_mailgun_api_key'] ?? ''); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('Domain', 'azure-plugin'); ?></label></th>
                    <td>
                        <input type="text" name="newsletter_mailgun_domain" 
                               value="<?php echo esc_attr($settings['newsletter_mailgun_domain'] ?? ''); ?>" class="regular-text"
                               placeholder="mail.yourdomain.com">
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('Region', 'azure-plugin'); ?></label></th>
                    <td>
                        <select name="newsletter_mailgun_region">
                            <option value="us" <?php selected($settings['newsletter_mailgun_region'] ?? 'us', 'us'); ?>>US</option>
                            <option value="eu" <?php selected($settings['newsletter_mailgun_region'] ?? 'us', 'eu'); ?>>EU</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('Webhook Signing Key', 'azure-plugin'); ?></label></th>
                    <td>
                        <input type="password" name="newsletter_mailgun_webhook_key" 
                               value="<?php echo esc_attr($settings['newsletter_mailgun_webhook_key'] ?? ''); ?>" class="regular-text">
                        <p class="description"><?php _e('Found in Mailgun Dashboard → Sending → Webhooks → HTTP webhook signing key. Leave blank to use API key.', 'azure-plugin'); ?></p>
                    </td>
                </tr>
            </table>
            <p class="webhook-url">
                <strong><?php _e('Webhook URL (add for all events):', 'azure-plugin'); ?></strong><br>
                <code><?php echo rest_url('azure-plugin/v1/newsletter/webhook/mailgun'); ?></code>
                <br><small class="description"><?php _e('Configure in Mailgun Dashboard → Sending → Webhooks. Add this URL for: accepted, delivered, opened, clicked, complained, temporary_fail, permanent_fail', 'azure-plugin'); ?></small>
            </p>
        </div>
        
        <!-- SendGrid Settings -->
        <div class="service-settings" id="sendgrid-settings" style="<?php echo $sending_service !== 'sendgrid' ? 'display:none;' : ''; ?>">
            <h4><?php _e('SendGrid Settings', 'azure-plugin'); ?></h4>
            <table class="form-table">
                <tr>
                    <th><label><?php _e('API Key', 'azure-plugin'); ?></label></th>
                    <td>
                        <input type="password" name="newsletter_sendgrid_api_key" 
                               value="<?php echo esc_attr($settings['newsletter_sendgrid_api_key'] ?? ''); ?>" class="regular-text">
                    </td>
                </tr>
            </table>
            <p class="webhook-url">
                <strong><?php _e('Webhook URL:', 'azure-plugin'); ?></strong><br>
                <code><?php echo rest_url('azure-plugin/v1/newsletter/webhook/sendgrid'); ?></code>
            </p>
        </div>
        
        <!-- Amazon SES Settings -->
        <div class="service-settings" id="ses-settings" style="<?php echo $sending_service !== 'ses' ? 'display:none;' : ''; ?>">
            <h4><?php _e('Amazon SES Settings', 'azure-plugin'); ?></h4>
            <table class="form-table">
                <tr>
                    <th><label><?php _e('Access Key', 'azure-plugin'); ?></label></th>
                    <td>
                        <input type="text" name="newsletter_ses_access_key" 
                               value="<?php echo esc_attr($settings['newsletter_ses_access_key'] ?? ''); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('Secret Key', 'azure-plugin'); ?></label></th>
                    <td>
                        <input type="password" name="newsletter_ses_secret_key" 
                               value="<?php echo esc_attr($settings['newsletter_ses_secret_key'] ?? ''); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('Region', 'azure-plugin'); ?></label></th>
                    <td>
                        <select name="newsletter_ses_region">
                            <option value="us-east-1" <?php selected($settings['newsletter_ses_region'] ?? '', 'us-east-1'); ?>>US East (N. Virginia)</option>
                            <option value="us-west-2" <?php selected($settings['newsletter_ses_region'] ?? '', 'us-west-2'); ?>>US West (Oregon)</option>
                            <option value="eu-west-1" <?php selected($settings['newsletter_ses_region'] ?? '', 'eu-west-1'); ?>>EU (Ireland)</option>
                        </select>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Custom SMTP Settings -->
        <div class="service-settings" id="smtp-settings" style="<?php echo $sending_service !== 'smtp' ? 'display:none;' : ''; ?>">
            <h4><?php _e('Custom SMTP Settings', 'azure-plugin'); ?></h4>
            <table class="form-table">
                <tr>
                    <th><label><?php _e('SMTP Host', 'azure-plugin'); ?></label></th>
                    <td>
                        <input type="text" name="newsletter_smtp_host" 
                               value="<?php echo esc_attr($settings['newsletter_smtp_host'] ?? ''); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('SMTP Port', 'azure-plugin'); ?></label></th>
                    <td>
                        <input type="number" name="newsletter_smtp_port" 
                               value="<?php echo esc_attr($settings['newsletter_smtp_port'] ?? 587); ?>" class="small-text">
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('Username', 'azure-plugin'); ?></label></th>
                    <td>
                        <input type="text" name="newsletter_smtp_username" 
                               value="<?php echo esc_attr($settings['newsletter_smtp_username'] ?? ''); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('Password', 'azure-plugin'); ?></label></th>
                    <td>
                        <input type="password" name="newsletter_smtp_password" 
                               value="<?php echo esc_attr($settings['newsletter_smtp_password'] ?? ''); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('Encryption', 'azure-plugin'); ?></label></th>
                    <td>
                        <select name="newsletter_smtp_encryption">
                            <option value="tls" <?php selected($settings['newsletter_smtp_encryption'] ?? 'tls', 'tls'); ?>>TLS</option>
                            <option value="ssl" <?php selected($settings['newsletter_smtp_encryption'] ?? 'tls', 'ssl'); ?>>SSL</option>
                            <option value="none" <?php selected($settings['newsletter_smtp_encryption'] ?? 'tls', 'none'); ?>>None</option>
                        </select>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Office 365 Settings -->
        <div class="service-settings" id="office365-settings" style="<?php echo $sending_service !== 'office365' ? 'display:none;' : ''; ?>">
            <h4><?php _e('Office 365 Settings', 'azure-plugin'); ?></h4>
            <p class="description">
                <?php _e('Office 365 sending uses your existing Azure App Registration. Make sure you have the Mail.Send permission configured.', 'azure-plugin'); ?>
            </p>
            <p>
                <a href="<?php echo admin_url('admin.php?page=azure-plugin'); ?>" class="button">
                    <?php _e('Configure Azure Credentials', 'azure-plugin'); ?>
                </a>
            </p>
        </div>
        
        <!-- From Addresses -->
        <div class="settings-section">
            <h3><?php _e('From Addresses', 'azure-plugin'); ?></h3>
            <p class="description"><?php _e('Define the email addresses that can be used as the sender. This prevents typos and ensures consistency.', 'azure-plugin'); ?></p>
            
            <div id="from-addresses-list">
                <?php if (empty($from_addresses)): ?>
                <div class="from-address-row">
                    <input type="text" name="from_name[]" placeholder="<?php _e('From Name', 'azure-plugin'); ?>" class="regular-text">
                    <input type="email" name="from_email[]" placeholder="<?php _e('From Email', 'azure-plugin'); ?>" class="regular-text">
                    <button type="button" class="button remove-from-address" style="display:none;">&times;</button>
                </div>
                <?php else: ?>
                <?php foreach ($from_addresses as $index => $addr): ?>
                <div class="from-address-row">
                    <input type="text" name="from_name[]" value="<?php echo esc_attr($addr['name']); ?>" placeholder="<?php _e('From Name', 'azure-plugin'); ?>" class="regular-text">
                    <input type="email" name="from_email[]" value="<?php echo esc_attr($addr['email']); ?>" placeholder="<?php _e('From Email', 'azure-plugin'); ?>" class="regular-text">
                    <button type="button" class="button remove-from-address">&times;</button>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <button type="button" class="button" id="add-from-address">+ <?php _e('Add Another', 'azure-plugin'); ?></button>
            
            <table class="form-table" style="margin-top: 20px;">
                <tr>
                    <th><label for="newsletter_reply_to"><?php _e('Reply-To Address', 'azure-plugin'); ?></label></th>
                    <td>
                        <input type="email" name="newsletter_reply_to" id="newsletter_reply_to"
                               value="<?php echo esc_attr($settings['newsletter_reply_to'] ?? ''); ?>" 
                               class="regular-text" placeholder="noreply@yourdomain.com">
                        <p class="description"><?php _e('Where replies should go. Leave blank to use the From address.', 'azure-plugin'); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Queue Settings -->
        <div class="settings-section">
            <h3><?php _e('Queue Settings', 'azure-plugin'); ?></h3>
            <table class="form-table">
                <tr>
                    <th><label><?php _e('Batch Size', 'azure-plugin'); ?></label></th>
                    <td>
                        <input type="number" name="newsletter_batch_size" 
                               value="<?php echo esc_attr($settings['newsletter_batch_size'] ?? 100); ?>" class="small-text">
                        <p class="description"><?php _e('Number of emails to send per batch (each minute).', 'azure-plugin'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('Rate Limit (per hour)', 'azure-plugin'); ?></label></th>
                    <td>
                        <input type="number" name="newsletter_rate_limit_per_hour" 
                               value="<?php echo esc_attr($settings['newsletter_rate_limit_per_hour'] ?? 1000); ?>" class="small-text">
                        <p class="description"><?php _e('Maximum emails to send per hour. Check your sending service limits.', 'azure-plugin'); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Bounce Handling -->
        <div class="settings-section">
            <h3><?php _e('Bounce Handling (Office 365 IMAP)', 'azure-plugin'); ?></h3>
            <p class="description"><?php _e('Configure a bounce mailbox to automatically track bounced emails. Uses your Azure App Registration.', 'azure-plugin'); ?></p>
            
            <table class="form-table">
                <tr>
                    <th><label><?php _e('Enable IMAP Bounce Processing', 'azure-plugin'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="newsletter_bounce_enabled" 
                                   <?php checked($settings['newsletter_bounce_enabled'] ?? false); ?>>
                            <?php _e('Enable automatic bounce detection via Office 365', 'azure-plugin'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('Bounce Mailbox', 'azure-plugin'); ?></label></th>
                    <td>
                        <input type="email" name="newsletter_bounce_mailbox" 
                               value="<?php echo esc_attr($settings['newsletter_bounce_mailbox'] ?? ''); ?>" 
                               class="regular-text" placeholder="bounce@yourdomain.com">
                        <p class="description"><?php _e('The email address where bounced emails are received.', 'azure-plugin'); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- WordPress Page Settings -->
        <div class="settings-section">
            <h3><?php _e('Newsletter Archive Pages', 'azure-plugin'); ?></h3>
            <table class="form-table">
                <tr>
                    <th><label><?php _e('Default Category/Tag', 'azure-plugin'); ?></label></th>
                    <td>
                        <input type="text" name="newsletter_default_category" 
                               value="<?php echo esc_attr($settings['newsletter_default_category'] ?? 'newsletter'); ?>" class="regular-text">
                        <p class="description"><?php _e('Category or tag applied to WordPress pages created from newsletters.', 'azure-plugin'); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        
        <p class="submit">
            <button type="submit" name="save_newsletter_settings" class="button button-primary">
                <?php _e('Save Settings', 'azure-plugin'); ?>
            </button>
            <button type="button" class="button" id="test-connection">
                <?php _e('Test Connection', 'azure-plugin'); ?>
            </button>
        </p>
    </form>
    
    <!-- Test Email Section -->
    <div class="settings-section test-email-section">
        <h3><?php _e('Send Test Email', 'azure-plugin'); ?></h3>
        <p class="description"><?php _e('Send a test email to verify your email configuration is working correctly.', 'azure-plugin'); ?></p>
        
        <table class="form-table">
            <tr>
                <th><label for="test_email_address"><?php _e('Recipient Email', 'azure-plugin'); ?></label></th>
                <td>
                    <input type="email" id="test_email_address" class="regular-text" 
                           value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" 
                           placeholder="your@email.com">
                </td>
            </tr>
        </table>
        
        <p>
            <button type="button" class="button button-primary" id="send-test-email">
                <span class="dashicons dashicons-email-alt" style="margin-top: 4px;"></span>
                <?php _e('Send Test Email', 'azure-plugin'); ?>
            </button>
            <span id="test-email-status" style="margin-left: 10px;"></span>
        </p>
    </div>
    
    <!-- Queue Status Quick View -->
    <?php
    // Check table status and queue
    global $wpdb;
    $newsletters_table = $wpdb->prefix . 'azure_newsletters';
    $queue_table = $wpdb->prefix . 'azure_newsletter_queue';
    $tables_exist = $wpdb->get_var("SHOW TABLES LIKE '{$newsletters_table}'") === $newsletters_table;
    
    // Get queue statistics
    $pending_count = 0;
    $failed_count = 0;
    $next_cron = wp_next_scheduled('azure_newsletter_process_queue');
    
    if ($wpdb->get_var("SHOW TABLES LIKE '{$queue_table}'") === $queue_table) {
        $pending_count = $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status = 'pending'") ?: 0;
        $failed_count = $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status = 'failed'") ?: 0;
    }
    ?>
    <div class="settings-section queue-status-section">
        <h3><span class="dashicons dashicons-clock"></span> <?php _e('Queue Status', 'azure-plugin'); ?></h3>
        
        <div class="queue-quick-stats">
            <span class="quick-stat">
                <strong><?php echo number_format($pending_count); ?></strong> <?php _e('pending', 'azure-plugin'); ?>
            </span>
            <span class="quick-stat-sep">•</span>
            <span class="quick-stat <?php echo $failed_count > 0 ? 'has-issues' : ''; ?>">
                <strong><?php echo number_format($failed_count); ?></strong> <?php _e('failed', 'azure-plugin'); ?>
            </span>
            <span class="quick-stat-sep">•</span>
            <span class="quick-stat">
                <?php _e('Next run:', 'azure-plugin'); ?>
                <?php if ($next_cron): ?>
                    <strong><?php echo human_time_diff(time(), $next_cron); ?></strong>
                <?php else: ?>
                    <strong style="color:#d63638;"><?php _e('Not scheduled', 'azure-plugin'); ?></strong>
                <?php endif; ?>
            </span>
        </div>
        
        <p style="margin-top: 15px;">
            <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter&tab=queue'); ?>" class="button">
                <span class="dashicons dashicons-list-view" style="margin-top: 4px;"></span>
                <?php _e('View Full Queue', 'azure-plugin'); ?>
            </a>
            <button type="button" class="button" id="process-queue-now" style="margin-left: 5px;">
                <span class="dashicons dashicons-update" style="margin-top: 4px;"></span>
                <?php _e('Process Now', 'azure-plugin'); ?>
            </button>
            <span id="process-queue-status" style="margin-left: 10px;"></span>
        </p>
    </div>
    
    <!-- Danger Zone -->
    <div class="danger-zone">
        <h3><?php _e('Database Management', 'azure-plugin'); ?></h3>
        
        <div class="danger-zone-content">
            <div class="table-status">
                <?php if ($tables_exist): ?>
                <span class="status-indicator status-ok">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php _e('Newsletter tables are installed', 'azure-plugin'); ?>
                </span>
                <?php else: ?>
                <span class="status-indicator status-error">
                    <span class="dashicons dashicons-warning"></span>
                    <?php _e('Newsletter tables are NOT installed', 'azure-plugin'); ?>
                </span>
                <?php endif; ?>
            </div>
            
            <div class="danger-actions">
                <div class="danger-action">
                    <div class="action-info">
                        <strong><?php _e('Create/Repair Database Tables', 'azure-plugin'); ?></strong>
                        <p><?php _e('Creates all required newsletter database tables. Safe to run multiple times - existing data will not be deleted.', 'azure-plugin'); ?></p>
                    </div>
                    <button type="button" class="button button-danger" id="create-newsletter-tables">
                        <?php _e('Create Tables', 'azure-plugin'); ?>
                    </button>
                </div>
                
                <?php if ($tables_exist): ?>
                <div class="danger-action">
                    <div class="action-info">
                        <strong><?php _e('Reset System Templates', 'azure-plugin'); ?></strong>
                        <p><?php _e('Resets built-in email templates to their default state with updated designs. Custom templates are not affected.', 'azure-plugin'); ?></p>
                    </div>
                    <button type="button" class="button button-danger" id="reset-system-templates">
                        <?php _e('Reset Templates', 'azure-plugin'); ?>
                    </button>
                </div>
                
                <div class="danger-action">
                    <div class="action-info">
                        <strong><?php _e('Reset All Newsletter Data', 'azure-plugin'); ?></strong>
                        <p><?php _e('Deletes ALL newsletter data including campaigns, statistics, lists, and templates. This cannot be undone!', 'azure-plugin'); ?></p>
                    </div>
                    <button type="button" class="button button-danger-critical" id="reset-newsletter-data">
                        <?php _e('Reset All Data', 'azure-plugin'); ?>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Diagnostics -->
    <div class="settings-section diagnostics-section">
        <h3><?php _e('📊 Statistics Diagnostics', 'azure-plugin'); ?></h3>
        
        <?php
        global $wpdb;
        $stats_table = $wpdb->prefix . 'azure_newsletter_stats';
        $queue_table = $wpdb->prefix . 'azure_newsletter_queue';
        
        // Check if stats table exists
        $stats_exists = $wpdb->get_var("SHOW TABLES LIKE '{$stats_table}'") === $stats_table;
        $queue_exists = $wpdb->get_var("SHOW TABLES LIKE '{$queue_table}'") === $queue_table;
        
        // Check for required columns in stats table
        $missing_columns = array();
        $required_columns = array('user_id', 'link_url', 'link_text');
        if ($stats_exists) {
            $existing_columns = $wpdb->get_col("SHOW COLUMNS FROM {$stats_table}");
            foreach ($required_columns as $col) {
                if (!in_array($col, $existing_columns)) {
                    $missing_columns[] = $col;
                }
            }
        }
        
        // Get counts
        $stats_count = $stats_exists ? $wpdb->get_var("SELECT COUNT(*) FROM {$stats_table}") : 0;
        $queue_count = $queue_exists ? $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table}") : 0;
        $queue_sent = $queue_exists ? $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status = 'sent'") : 0;
        
        // Get recent stats
        $recent_stats = $stats_exists ? $wpdb->get_results("SELECT * FROM {$stats_table} ORDER BY id DESC LIMIT 5") : array();
        
        // Mailgun webhook URL
        $webhook_url = rest_url('azure-plugin/v1/newsletter/webhook/mailgun');
        ?>
        
        <table class="widefat" style="max-width: 700px;">
            <tbody>
                <tr>
                    <th style="width: 200px;">Stats Table</th>
                    <td>
                        <?php if ($stats_exists): ?>
                        <span style="color: #00a32a;">✓ Exists</span> (<?php echo number_format($stats_count); ?> records)
                        <?php else: ?>
                        <span style="color: #d63638;">✗ Does not exist</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if (!empty($missing_columns)): ?>
                <tr>
                    <th>Schema Status</th>
                    <td>
                        <span style="color: #996800;">⚠ Missing columns: <?php echo implode(', ', $missing_columns); ?></span>
                        <br><small>Click "Repair Tables" below to fix.</small>
                    </td>
                </tr>
                <?php elseif ($stats_exists): ?>
                <tr>
                    <th>Schema Status</th>
                    <td><span style="color: #00a32a;">✓ All columns present</span></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th>Queue Table</th>
                    <td>
                        <?php if ($queue_exists): ?>
                        <span style="color: #00a32a;">✓ Exists</span> (<?php echo number_format($queue_count); ?> total, <strong><?php echo number_format($queue_sent); ?> sent</strong>)
                        <?php else: ?>
                        <span style="color: #d63638;">✗ Does not exist</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Stats vs Queue</th>
                    <td>
                        <?php 
                        $stats_sent = $stats_exists ? intval($wpdb->get_var("SELECT COUNT(*) FROM {$stats_table} WHERE event_type = 'sent'")) : 0;
                        if ($queue_sent > 0 && $stats_sent == 0): ?>
                        <span style="color: #996800;">⚠ Queue shows <?php echo $queue_sent; ?> sent, but stats table has 0 'sent' records.</span>
                        <br><small>This may be due to missing columns. Click "Repair Tables" then resend or the stats will track going forward.</small>
                        <?php elseif ($queue_sent > 0): ?>
                        <span style="color: #00a32a;">✓ <?php echo $stats_sent; ?> sent events recorded</span>
                        <?php else: ?>
                        <span style="color: #646970;">No emails sent yet</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Mailgun Webhook URL</th>
                    <td>
                        <code style="word-break: break-all; font-size: 11px;"><?php echo esc_html($webhook_url); ?></code>
                        <br><small>Add this URL to Mailgun Dashboard → Sending → Webhooks (for opens/clicks tracking)</small>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <p style="margin-top: 15px;">
            <button type="button" class="button" id="repair-newsletter-tables">
                🔧 <?php _e('Repair Tables', 'azure-plugin'); ?>
            </button>
            <span id="repair-status" style="margin-left: 10px;"></span>
        </p>
        
        <?php if (!empty($recent_stats)): ?>
        <h4 style="margin-top: 20px;">Recent Stats Records</h4>
        <table class="widefat striped" style="max-width: 800px;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Newsletter</th>
                    <th>Email</th>
                    <th>Event</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_stats as $stat): ?>
                <tr>
                    <td><?php echo $stat->id; ?></td>
                    <td>#<?php echo $stat->newsletter_id; ?></td>
                    <td><?php echo esc_html($stat->email); ?></td>
                    <td><span class="status-badge status-<?php echo esc_attr($stat->event_type); ?>"><?php echo esc_html($stat->event_type); ?></span></td>
                    <td><?php echo esc_html($stat->created_at); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php elseif ($stats_exists): ?>
        <p style="margin-top: 15px; color: #646970;"><em>No statistics recorded yet. Send a newsletter to start tracking.</em></p>
        <?php endif; ?>
        
        <div style="margin-top: 20px; padding: 15px; background: #f0f6fc; border-left: 4px solid #2271b1; border-radius: 4px;">
            <strong>📈 How Statistics Work:</strong><br><br>
            • <strong>Sent Count</strong>: Based on the queue table (emails successfully sent to your email provider)<br>
            • <strong>Opens/Clicks</strong>: Tracked via Mailgun webhooks (requires webhook configuration)<br>
            • <strong>Bounces</strong>: Received via Mailgun webhooks when emails fail to deliver<br>
            <br>
            <strong>Note:</strong> The "Sent" column on the Campaigns page uses queue data. Opens/Clicks require Mailgun webhooks to be configured in your Mailgun dashboard pointing to the URL above.
        </div>
    </div>
</div>

<style>
.newsletter-settings .settings-section {
    background: #fff;
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid #ccd0d4;
}
.newsletter-settings .service-settings {
    background: #f8f9fa;
    padding: 15px 20px;
    margin: 15px 0;
    border-left: 4px solid #2271b1;
}
.newsletter-settings .from-address-row {
    display: flex;
    gap: 10px;
    margin-bottom: 10px;
    align-items: center;
}
.newsletter-settings .webhook-url {
    margin-top: 15px;
    padding: 10px;
    background: #fff;
}
.newsletter-settings .webhook-url code {
    display: block;
    margin-top: 5px;
    padding: 8px;
    background: #f0f0f1;
}

/* Queue Status */
.queue-status-section h3 {
    display: flex;
    align-items: center;
    gap: 8px;
}
.queue-status-section h3 .dashicons {
    color: #2271b1;
}
.queue-quick-stats {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    color: #1d2327;
}
.queue-quick-stats .quick-stat-sep {
    color: #c3c4c7;
}
.queue-quick-stats .quick-stat.has-issues strong {
    color: #d63638;
}

/* Danger Zone */
.newsletter-settings .danger-zone {
    background: #fff;
    border: 2px solid #d63638;
    border-radius: 4px;
    margin-top: 40px;
}
.newsletter-settings .danger-zone h3 {
    margin: 0;
    padding: 15px 20px;
    background: linear-gradient(135deg, #d63638 0%, #b32d2e 100%);
    color: #fff;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.newsletter-settings .danger-zone-content {
    padding: 20px;
}
.newsletter-settings .table-status {
    margin-bottom: 20px;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 4px;
}
.newsletter-settings .status-indicator {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 500;
}
.newsletter-settings .status-indicator .dashicons {
    font-size: 20px;
    width: 20px;
    height: 20px;
}
.newsletter-settings .status-ok {
    color: #00a32a;
}
.newsletter-settings .status-error {
    color: #d63638;
}
.newsletter-settings .danger-actions {
    display: flex;
    flex-direction: column;
    gap: 15px;
}
.newsletter-settings .danger-action {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    background: #fef2f1;
    border: 1px solid #facfd2;
    border-radius: 4px;
}
.newsletter-settings .action-info {
    flex: 1;
    padding-right: 20px;
}
.newsletter-settings .action-info strong {
    display: block;
    margin-bottom: 5px;
    color: #1d2327;
}
.newsletter-settings .action-info p {
    margin: 0;
    color: #646970;
    font-size: 13px;
}
.newsletter-settings .button-danger {
    background: #d63638 !important;
    border-color: #d63638 !important;
    color: #fff !important;
    min-width: 140px;
}
.newsletter-settings .button-danger:hover {
    background: #b32d2e !important;
    border-color: #b32d2e !important;
}
.newsletter-settings .button-danger:focus {
    box-shadow: 0 0 0 1px #fff, 0 0 0 3px #d63638 !important;
}
.newsletter-settings .button-danger-critical {
    background: #8a2424 !important;
    border-color: #8a2424 !important;
    color: #fff !important;
    min-width: 140px;
}
.newsletter-settings .button-danger-critical:hover {
    background: #6d1c1c !important;
    border-color: #6d1c1c !important;
}

/* Test Email Section */
.newsletter-settings .test-email-section {
    border-left: 4px solid #00a32a;
}
.newsletter-settings .test-email-section h3 {
    margin-top: 0;
    color: #1d2327;
}
.newsletter-settings #test-email-status {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.newsletter-settings #test-email-status .dashicons {
    font-size: 18px;
    width: 18px;
    height: 18px;
}

/* Spin animation for loading */
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
.newsletter-settings .spin {
    animation: spin 1s linear infinite;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Toggle service settings
    $('#newsletter_sending_service').on('change', function() {
        $('.service-settings').hide();
        $('#' + $(this).val() + '-settings').show();
    });
    
    // Add from address row
    $('#add-from-address').on('click', function() {
        var row = `
            <div class="from-address-row">
                <input type="text" name="from_name[]" placeholder="<?php _e('From Name', 'azure-plugin'); ?>" class="regular-text">
                <input type="email" name="from_email[]" placeholder="<?php _e('From Email', 'azure-plugin'); ?>" class="regular-text">
                <button type="button" class="button remove-from-address">&times;</button>
            </div>
        `;
        $('#from-addresses-list').append(row);
        $('#from-addresses-list .remove-from-address').show();
    });
    
    // Remove from address row
    $(document).on('click', '.remove-from-address', function() {
        $(this).closest('.from-address-row').remove();
        if ($('.from-address-row').length === 1) {
            $('.remove-from-address').hide();
        }
    });
    
    // Test connection
    $('#test-connection').on('click', function() {
        var btn = $(this);
        btn.prop('disabled', true).text('<?php _e('Testing...', 'azure-plugin'); ?>');
        
        // Save form first, then test
        $.post(ajaxurl, {
            action: 'azure_newsletter_test_connection',
            service: $('#newsletter_sending_service').val(),
            nonce: '<?php echo wp_create_nonce('newsletter_test_connection'); ?>'
        }, function(response) {
            btn.prop('disabled', false).text('<?php _e('Test Connection', 'azure-plugin'); ?>');
            if (response.success) {
                alert('<?php _e('Connection successful!', 'azure-plugin'); ?>');
            } else {
                alert('<?php _e('Connection failed:', 'azure-plugin'); ?> ' + response.data);
            }
        });
    });
    
    // Send test email
    $('#send-test-email').on('click', function() {
        var btn = $(this);
        var email = $('#test_email_address').val();
        var statusSpan = $('#test-email-status');
        
        if (!email || !email.includes('@')) {
            alert('<?php _e('Please enter a valid email address.', 'azure-plugin'); ?>');
            return;
        }
        
        btn.prop('disabled', true);
        btn.find('.dashicons').removeClass('dashicons-email-alt').addClass('dashicons-update spin');
        statusSpan.html('<span style="color: #2271b1;"><?php _e('Sending test email...', 'azure-plugin'); ?></span>');
        
        $.post(ajaxurl, {
            action: 'azure_newsletter_send_test_email',
            email: email,
            nonce: '<?php echo wp_create_nonce('newsletter_send_test_email'); ?>'
        }, function(response) {
            btn.prop('disabled', false);
            btn.find('.dashicons').removeClass('dashicons-update spin').addClass('dashicons-email-alt');
            
            if (response.success) {
                statusSpan.html('<span style="color: #00a32a;"><span class="dashicons dashicons-yes"></span> ' + response.data + '</span>');
            } else {
                statusSpan.html('<span style="color: #d63638;"><span class="dashicons dashicons-no"></span> ' + response.data + '</span>');
            }
        }).fail(function() {
            btn.prop('disabled', false);
            btn.find('.dashicons').removeClass('dashicons-update spin').addClass('dashicons-email-alt');
            statusSpan.html('<span style="color: #d63638;"><?php _e('Request failed. Please try again.', 'azure-plugin'); ?></span>');
        });
    });
    
    // Process queue manually
    // Process queue now (quick action)
    $('#process-queue-now').on('click', function() {
        var btn = $(this);
        var statusSpan = $('#process-queue-status');
        
        btn.prop('disabled', true);
        btn.find('.dashicons').addClass('spin');
        statusSpan.html('<span style="color: #2271b1;"><?php _e('Processing...', 'azure-plugin'); ?></span>');
        
        $.post(ajaxurl, {
            action: 'azure_newsletter_process_queue',
            nonce: '<?php echo wp_create_nonce('azure_newsletter_process_queue'); ?>'
        }, function(response) {
            btn.prop('disabled', false);
            btn.find('.dashicons').removeClass('spin');
            
            if (response.success) {
                var data = response.data;
                statusSpan.html('<span style="color: #00a32a;">✓ ' + data.sent + ' sent</span>');
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                statusSpan.html('<span style="color: #d63638;">✗ ' + response.data + '</span>');
            }
        }).fail(function() {
            btn.prop('disabled', false);
            btn.find('.dashicons').removeClass('spin');
            statusSpan.html('<span style="color: #d63638;"><?php _e('Failed', 'azure-plugin'); ?></span>');
        });
    });
    
    // Create newsletter tables
    $('#create-newsletter-tables').on('click', function() {
        var btn = $(this);
        btn.prop('disabled', true).text('<?php _e('Creating...', 'azure-plugin'); ?>');
        
        $.post(ajaxurl, {
            action: 'azure_newsletter_create_tables',
            nonce: '<?php echo wp_create_nonce('azure_newsletter_create_tables'); ?>'
        }, function(response) {
            btn.prop('disabled', false).text('<?php _e('Create Tables', 'azure-plugin'); ?>');
            if (response.success) {
                alert('<?php _e('Database tables created successfully!', 'azure-plugin'); ?>');
                location.reload();
            } else {
                alert('<?php _e('Failed to create tables:', 'azure-plugin'); ?> ' + response.data);
            }
        }).fail(function() {
            btn.prop('disabled', false).text('<?php _e('Create Tables', 'azure-plugin'); ?>');
            alert('<?php _e('Request failed. Please try again.', 'azure-plugin'); ?>');
        });
    });
    
    // Reset system templates
    $('#reset-system-templates').on('click', function() {
        if (!confirm('<?php _e('This will reset all built-in templates to their default state. Custom templates will not be affected. Continue?', 'azure-plugin'); ?>')) {
            return;
        }
        
        var btn = $(this);
        btn.prop('disabled', true).text('<?php _e('Resetting...', 'azure-plugin'); ?>');
        
        $.post(ajaxurl, {
            action: 'azure_newsletter_reset_templates',
            nonce: '<?php echo wp_create_nonce('azure_newsletter_reset_templates'); ?>'
        }, function(response) {
            btn.prop('disabled', false).text('<?php _e('Reset Templates', 'azure-plugin'); ?>');
            if (response.success) {
                alert(response.data.message || '<?php _e('System templates have been reset.', 'azure-plugin'); ?>');
            } else {
                alert('<?php _e('Failed to reset templates:', 'azure-plugin'); ?> ' + response.data);
            }
        }).fail(function() {
            btn.prop('disabled', false).text('<?php _e('Reset Templates', 'azure-plugin'); ?>');
            alert('<?php _e('Request failed. Please try again.', 'azure-plugin'); ?>');
        });
    });
    
    // Reset newsletter data
    $('#reset-newsletter-data').on('click', function() {
        if (!confirm('<?php _e('Are you absolutely sure? This will DELETE ALL newsletter data including campaigns, statistics, lists, and templates. This action cannot be undone!', 'azure-plugin'); ?>')) {
            return;
        }
        
        var confirmText = prompt('<?php _e('Type "RESET" to confirm:', 'azure-plugin'); ?>');
        if (confirmText !== 'RESET') {
            alert('<?php _e('Reset cancelled.', 'azure-plugin'); ?>');
            return;
        }
        
        var btn = $(this);
        btn.prop('disabled', true).text('<?php _e('Resetting...', 'azure-plugin'); ?>');
        
        $.post(ajaxurl, {
            action: 'azure_newsletter_reset_data',
            confirm: confirmText,
            nonce: '<?php echo wp_create_nonce('azure_newsletter_reset_data'); ?>'
        }, function(response) {
            btn.prop('disabled', false).text('<?php _e('Reset All Data', 'azure-plugin'); ?>');
            if (response.success) {
                alert('<?php _e('All newsletter data has been reset.', 'azure-plugin'); ?>');
                location.reload();
            } else {
                alert('<?php _e('Failed to reset data:', 'azure-plugin'); ?> ' + response.data);
            }
        }).fail(function() {
            btn.prop('disabled', false).text('<?php _e('Reset All Data', 'azure-plugin'); ?>');
            alert('<?php _e('Request failed. Please try again.', 'azure-plugin'); ?>');
        });
    });
    
    // Repair Tables button
    $('#repair-newsletter-tables').on('click', function() {
        var btn = $(this);
        var statusSpan = $('#repair-status');
        
        btn.prop('disabled', true);
        statusSpan.html('<span class="spinner is-active" style="float:none;margin:0;vertical-align:middle;"></span> Repairing...');
        
        $.post(ajaxurl, {
            action: 'azure_newsletter_create_tables',
            nonce: '<?php echo wp_create_nonce('azure_newsletter_nonce'); ?>'
        }).done(function(response) {
            btn.prop('disabled', false);
            if (response.success) {
                statusSpan.html('<span style="color:#00a32a;">✓ Tables repaired successfully!</span>');
                setTimeout(function() {
                    location.reload();
                }, 1500);
            } else {
                statusSpan.html('<span style="color:#d63638;">✗ ' + (response.data || 'Failed to repair') + '</span>');
            }
        }).fail(function() {
            btn.prop('disabled', false);
            statusSpan.html('<span style="color:#d63638;">✗ Request failed</span>');
        });
    });
});
</script>


