<?php
/**
 * Tickets Module - Settings Tab
 */

if (!defined('ABSPATH')) {
    exit;
}

$settings = Azure_Settings::get_all_settings();
?>

<div class="tickets-settings-wrap">
    <h2><?php _e('Tickets Settings', 'azure-plugin'); ?></h2>
    
    <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field('tickets_settings', 'tickets_settings_nonce'); ?>
        
        <!-- General Settings -->
        <div class="settings-section">
            <h3><?php _e('General Settings', 'azure-plugin'); ?></h3>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="tickets_require_names"><?php _e('Require Attendee Names', 'azure-plugin'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="tickets_require_names" id="tickets_require_names" value="1" 
                                   <?php checked($settings['tickets_require_names'] ?? true); ?>>
                            <?php _e('Require a name for each ticket at checkout', 'azure-plugin'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="tickets_reservation_timeout"><?php _e('Seat Reservation Timeout', 'azure-plugin'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="tickets_reservation_timeout" id="tickets_reservation_timeout" 
                               value="<?php echo esc_attr($settings['tickets_reservation_timeout'] ?? 15); ?>" 
                               min="5" max="60" class="small-text"> <?php _e('minutes', 'azure-plugin'); ?>
                        <p class="description"><?php _e('How long seats are held during checkout before being released.', 'azure-plugin'); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Apple Wallet Settings -->
        <div class="settings-section">
            <h3>
                <span class="dashicons dashicons-smartphone"></span>
                <?php _e('Apple Wallet Integration', 'azure-plugin'); ?>
            </h3>
            <p class="description">
                <?php _e('Configure Apple Wallet pass generation. Requires an Apple Developer account.', 'azure-plugin'); ?>
                <a href="https://developer.apple.com/documentation/walletpasses" target="_blank"><?php _e('Learn more', 'azure-plugin'); ?></a>
            </p>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="apple_wallet_pass_type_id"><?php _e('Pass Type ID', 'azure-plugin'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="apple_wallet_pass_type_id" id="apple_wallet_pass_type_id" 
                               value="<?php echo esc_attr($settings['tickets_apple_pass_type_id'] ?? ''); ?>" 
                               class="regular-text" placeholder="pass.com.yourorg.tickets">
                        <p class="description"><?php _e('Your Pass Type ID from Apple Developer portal.', 'azure-plugin'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="apple_wallet_team_id"><?php _e('Team ID', 'azure-plugin'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="apple_wallet_team_id" id="apple_wallet_team_id" 
                               value="<?php echo esc_attr($settings['tickets_apple_team_id'] ?? ''); ?>" 
                               class="regular-text" placeholder="ABCD1234EF">
                        <p class="description"><?php _e('Your Apple Developer Team ID.', 'azure-plugin'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="apple_wallet_certificate"><?php _e('Signing Certificate', 'azure-plugin'); ?></label>
                    </th>
                    <td>
                        <?php if (!empty($settings['tickets_apple_cert_path']) && file_exists($settings['tickets_apple_cert_path'])): ?>
                        <p class="certificate-status">
                            <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                            <?php _e('Certificate uploaded', 'azure-plugin'); ?>
                        </p>
                        <?php endif; ?>
                        <input type="file" name="apple_wallet_certificate" id="apple_wallet_certificate" accept=".p12,.pfx">
                        <p class="description"><?php _e('Upload your .p12 certificate file from Apple Developer portal.', 'azure-plugin'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="apple_wallet_cert_password"><?php _e('Certificate Password', 'azure-plugin'); ?></label>
                    </th>
                    <td>
                        <input type="password" name="apple_wallet_cert_password" id="apple_wallet_cert_password" 
                               value="<?php echo esc_attr($settings['tickets_apple_cert_password'] ?? ''); ?>" 
                               class="regular-text">
                        <p class="description"><?php _e('Password for the .p12 certificate.', 'azure-plugin'); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Email Settings -->
        <div class="settings-section">
            <h3>
                <span class="dashicons dashicons-email"></span>
                <?php _e('Email Settings', 'azure-plugin'); ?>
            </h3>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="tickets_email_subject"><?php _e('Ticket Email Subject', 'azure-plugin'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="tickets_email_subject" id="tickets_email_subject" 
                               value="<?php echo esc_attr($settings['tickets_email_subject'] ?? 'Your tickets for {{event_name}}'); ?>" 
                               class="large-text">
                        <p class="description"><?php _e('Available tags: {{event_name}}, {{order_number}}', 'azure-plugin'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label><?php _e('Include PDF Attachment', 'azure-plugin'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="tickets_include_pdf" value="1" 
                                   <?php checked($settings['tickets_include_pdf'] ?? true); ?>>
                            <?php _e('Attach a PDF version of tickets to the confirmation email', 'azure-plugin'); ?>
                        </label>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Check-in Settings -->
        <div class="settings-section">
            <h3>
                <span class="dashicons dashicons-yes"></span>
                <?php _e('Check-in Settings', 'azure-plugin'); ?>
            </h3>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label><?php _e('Sound Effects', 'azure-plugin'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="tickets_checkin_sounds" value="1" 
                                   <?php checked($settings['tickets_checkin_sounds'] ?? true); ?>>
                            <?php _e('Play sound on successful/failed scan', 'azure-plugin'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label><?php _e('Auto-continue Scanning', 'azure-plugin'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="tickets_auto_continue" value="1" 
                                   <?php checked($settings['tickets_auto_continue'] ?? true); ?>>
                            <?php _e('Automatically continue scanning after each ticket', 'azure-plugin'); ?>
                        </label>
                        <p class="description"><?php _e('When enabled, the scanner resumes immediately after checking in a ticket.', 'azure-plugin'); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Offline Mode (Future Feature) -->
        <div class="settings-section future-feature">
            <h3>
                <span class="dashicons dashicons-cloud-saved"></span>
                <?php _e('Offline Check-in', 'azure-plugin'); ?>
                <span class="coming-soon-badge"><?php _e('Coming Soon', 'azure-plugin'); ?></span>
            </h3>
            <p class="description">
                <?php _e('Download ticket data before an event to enable check-in without internet connectivity.', 'azure-plugin'); ?>
            </p>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Status', 'azure-plugin'); ?></th>
                    <td>
                        <p style="color: #666;">
                            <span class="dashicons dashicons-info"></span>
                            <?php _e('This feature is planned for a future update.', 'azure-plugin'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        
        <p class="submit">
            <button type="submit" class="button button-primary"><?php _e('Save Settings', 'azure-plugin'); ?></button>
        </p>
    </form>
    
    <!-- Danger Zone -->
    <div class="danger-zone-section">
        <h3>
            <span class="dashicons dashicons-warning" style="color: #d63638;"></span>
            <?php _e('Danger Zone', 'azure-plugin'); ?>
        </h3>
        
        <div class="danger-zone-controls">
            <div class="control-group">
                <h4><?php _e('Database Tables', 'azure-plugin'); ?></h4>
                <p class="description"><?php _e('Create or reset the tickets database tables.', 'azure-plugin'); ?></p>
                <form method="post">
                    <?php wp_nonce_field('tickets_danger_zone', 'tickets_danger_nonce'); ?>
                    <button type="submit" name="create_tickets_tables" class="button">
                        <?php _e('Create/Repair Tables', 'azure-plugin'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.tickets-settings-wrap {
    max-width: 800px;
}

.settings-section {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
}

.settings-section h3 {
    margin: 0 0 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
    display: flex;
    align-items: center;
    gap: 8px;
}

.settings-section.future-feature {
    opacity: 0.7;
}

.coming-soon-badge {
    background: #dba617;
    color: #fff;
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 3px;
    font-weight: normal;
    margin-left: auto;
}

.certificate-status {
    display: flex;
    align-items: center;
    gap: 5px;
    color: #00a32a;
    margin-bottom: 10px;
}

.danger-zone-section {
    background: #fff;
    border: 1px solid #d63638;
    border-radius: 4px;
    padding: 20px;
    margin-top: 30px;
}

.danger-zone-section h3 {
    margin: 0 0 15px;
    color: #d63638;
}

.danger-zone-controls {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}

.control-group {
    flex: 1;
    min-width: 200px;
}

.control-group h4 {
    margin: 0 0 5px;
}
</style>

