<?php
if (!defined('ABSPATH')) {
    exit;
}

// Get plugin settings
$settings = Azure_Settings::get_all_settings();

// Check if TEC Integration module is enabled
$tec_module_enabled = Azure_Settings::is_module_enabled('tec_integration');

// Check if TEC plugin is installed
$tec_installed = class_exists('Tribe__Events__Main');

// Check TEC calendar authentication status
$tec_user_email = $settings['tec_calendar_user_email'] ?? '';
$tec_mailbox_email = $settings['tec_calendar_mailbox_email'] ?? '';
$tec_calendar_authenticated = false;
if (!empty($tec_user_email) && class_exists('Azure_Calendar_Auth')) {
    try {
        $auth = new Azure_Calendar_Auth();
        $tec_calendar_authenticated = $auth->has_valid_user_token($tec_user_email);
    } catch (Exception $e) {
        // Silently handle error
        $tec_calendar_authenticated = false;
    }
}

// Get TEC calendar mappings
$calendar_mappings = array();
if (class_exists('Azure_TEC_Calendar_Mapping_Manager')) {
    try {
        $mapping_manager = new Azure_TEC_Calendar_Mapping_Manager();
        $calendar_mappings = $mapping_manager->get_all_mappings();
    } catch (Exception $e) {
        // Silently handle error
        $calendar_mappings = array();
    }
}
?>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
<div class="wrap">
    <h1>PTA Tools - Calendar Sync</h1>
<?php endif; ?>
    
    <!-- Module Toggle Section -->
    <div class="module-status-section">
        <h2>TEC Integration Module Status</h2>
        <div class="module-toggle-card module-card <?php echo $tec_module_enabled ? 'enabled' : 'disabled'; ?>">
            <div class="module-info">
                <h3><span class="dashicons dashicons-calendar"></span> TEC Integration Module</h3>
                <p>Sync Microsoft Outlook calendars with The Events Calendar plugin</p>
            </div>
            <div class="module-control">
                <label class="switch">
                    <input type="checkbox" class="module-toggle" data-module="tec_integration" <?php checked($tec_module_enabled); ?> />
                    <span class="slider"></span>
                </label>
                <span class="toggle-status"><?php echo $tec_module_enabled ? 'Enabled' : 'Disabled'; ?></span>
            </div>
        </div>
        <?php if (!$tec_module_enabled): ?>
        <div class="notice notice-warning inline">
            <p><strong>TEC Integration module is disabled.</strong> Enable it above to use TEC Calendar Sync functionality.</p>
        </div>
        <?php endif; ?>
    </div>
    
    <?php if (!$tec_installed): ?>
    <div class="notice notice-error inline-notice">
        <p><span class="dashicons dashicons-dismiss"></span> <strong>The Events Calendar Plugin Not Found</strong></p>
        <p>The Events Calendar plugin must be installed and activated to use the TEC Calendar Sync feature.</p>
        <p><a href="<?php echo admin_url('plugin-install.php?s=the+events+calendar&tab=search&type=term'); ?>" class="button">Install The Events Calendar</a></p>
    </div>
    <?php elseif ($tec_module_enabled): ?>
    
    <!-- TEC Sync Overview -->
    <div class="tec-sync-overview">
        <div class="info-card">
            <h2><span class="dashicons dashicons-update"></span> TEC Calendar Synchronization</h2>
            <p>Sync events from Outlook shared mailbox calendars to The Events Calendar plugin. Events in Outlook will automatically sync to your WordPress site based on your schedule.</p>
            <ul>
                <li><strong>One-way sync:</strong> Outlook → TEC (Outlook always wins)</li>
                <li><strong>Multi-calendar support:</strong> Select specific calendars from shared mailbox</li>
                <li><strong>Category mapping:</strong> Assign TEC categories to imported events</li>
                <li><strong>Flexible scheduling:</strong> Manual or automatic scheduled sync</li>
            </ul>
        </div>
    </div>
    
    <!-- Step 1: Shared Mailbox Authentication -->
    <div class="tec-auth-section">
        <h2><span class="step-number">1</span> Shared Mailbox Authentication</h2>
        <p class="description">Authenticate with your Microsoft 365 account to access a shared mailbox's calendars.</p>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="tec_calendar_user_email">Your M365 Account</label>
                </th>
                <td>
                    <input type="email" 
                           id="tec_calendar_user_email" 
                           name="tec_calendar_user_email" 
                           value="<?php echo esc_attr($tec_user_email); ?>"
                           placeholder="admin@yourorg.net" 
                           class="regular-text">
                    <p class="description">Your Microsoft 365 email address (the account you'll sign in with)</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="tec_calendar_mailbox_email">Shared Mailbox Email</label>
                </th>
                <td>
                    <input type="email" 
                           id="tec_calendar_mailbox_email" 
                           name="tec_calendar_mailbox_email" 
                           value="<?php echo esc_attr($tec_mailbox_email); ?>"
                           placeholder="calendar@yourorg.net" 
                           class="regular-text">
                    <p class="description">The shared mailbox email you have delegated access to</p>
                    <button type="button" class="button button-primary" id="save-tec-calendar-email">
                        <span class="dashicons dashicons-saved"></span> Save Settings
                    </button>
                </td>
            </tr>
            <tr>
                <th scope="row">Authentication Status</th>
                <td>
                    <div class="auth-status-display">
                        <?php if ($tec_calendar_authenticated): ?>
                            <span class="status-badge status-success">
                                <span class="dashicons dashicons-yes-alt"></span> Authenticated as <?php echo esc_html($tec_user_email); ?>
                            </span>
                            <div class="auth-actions-inline">
                                <button type="button" class="button" id="refresh-tec-calendars">
                                    <span class="dashicons dashicons-update"></span> Refresh Calendars
                                </button>
                                <button type="button" class="button button-secondary" id="tec-calendar-reauth">
                                    Re-authenticate
                                </button>
                            </div>
                        <?php else: ?>
                            <span class="status-badge status-error">
                                <span class="dashicons dashicons-dismiss"></span> Not authenticated
                            </span>
                            <div class="auth-actions-inline">
                                <?php if (!empty($tec_user_email) && !empty($tec_mailbox_email)): ?>
                                <button type="button" class="button button-primary" id="tec-calendar-auth">
                                    <span class="dashicons dashicons-admin-network"></span> Authenticate Calendar
                                </button>
                                <?php else: ?>
                                <p class="description">Please enter and save your M365 account email and the shared mailbox email above, then authenticate.</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        </table>
    </div>
    
    <!-- Step 2: Calendar Mapping -->
    <?php if ($tec_calendar_authenticated): ?>
    <div class="tec-calendar-mapping-section">
        <h2><span class="step-number">2</span> Calendar Mapping</h2>
        <p class="description">Select which Outlook calendars to sync and assign TEC categories for filtering.</p>
        
        <div class="calendar-mappings-container">
            <div class="calendar-mappings-header">
                <button type="button" class="button" id="refresh-outlook-calendars">
                    <span class="dashicons dashicons-update"></span> Refresh Available Calendars
                </button>
                <button type="button" class="button" id="tec-manual-sync-now-mapping">
                    <span class="dashicons dashicons-cloud-upload"></span> Manual Sync Now
                </button>
                <button type="button" class="button button-primary" id="add-calendar-mapping">
                    <span class="dashicons dashicons-plus-alt"></span> Add Calendar Mapping
                </button>
            </div>
            
            <table class="wp-list-table widefat fixed striped calendar-mappings-table">
                <thead>
                    <tr>
                        <th style="width: 60px;">Sync</th>
                        <th>Outlook Calendar</th>
                        <th>TEC Category</th>
                        <th style="width: 200px;">Schedule</th>
                        <th style="width: 150px;">Last Sync</th>
                        <th style="width: 150px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="calendar-mappings-list">
                    <?php if (empty($calendar_mappings)): ?>
                    <tr class="no-mappings">
                        <td colspan="6" style="text-align: center; padding: 40px;">
                            <span class="dashicons dashicons-calendar-alt" style="font-size: 48px; opacity: 0.3;"></span>
                            <p style="margin: 10px 0 0 0; color: #666;">No calendar mappings yet. Click "Add Calendar Mapping" to get started.</p>
                </td>
            </tr>
                    <?php else: ?>
                        <?php foreach ($calendar_mappings as $mapping): ?>
                        <tr data-mapping-id="<?php echo esc_attr($mapping->id); ?>">
                            <td>
                                <label class="switch">
                                    <input type="checkbox" 
                                           class="mapping-sync-toggle" 
                                           data-mapping-id="<?php echo esc_attr($mapping->id); ?>"
                                           <?php checked($mapping->sync_enabled); ?> />
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td>
                                <strong><?php echo esc_html($mapping->outlook_calendar_name); ?></strong>
                                <br><small style="color: #666;"><?php echo esc_html($mapping->outlook_calendar_id); ?></small>
                            </td>
                            <td>
                                <span class="tec-category-badge"><?php echo esc_html($mapping->tec_category_name); ?></span>
                            </td>
                            <td>
                                <?php if ($mapping->schedule_enabled): ?>
                                    <div class="schedule-info">
                                        <span class="dashicons dashicons-clock" style="color: #2271b1;"></span>
                                        <?php
                                        $freq_labels = array(
                                            '15min' => 'Every 15 min',
                                            '30min' => 'Every 30 min',
                                            'hourly' => 'Hourly',
                                            'twicedaily' => 'Twice Daily',
                                            'daily' => 'Daily'
                                        );
                                        $freq = $mapping->schedule_frequency ?? 'hourly';
                                        echo esc_html($freq_labels[$freq] ?? $freq);
                                        ?>
                                        <br>
                                        <small style="color: #666;">
                                            <?php echo esc_html($mapping->schedule_lookback_days ?? 30); ?> days back, 
                                            <?php echo esc_html($mapping->schedule_lookahead_days ?? 365); ?> days ahead
                                        </small>
                                    </div>
                                <?php else: ?>
                                    <em style="color: #999;">Manual only</em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                if ($mapping->last_sync) {
                                    echo esc_html(date('M j, Y g:i A', strtotime($mapping->last_sync)));
                                } else {
                                    echo '<em style="color: #999;">Never</em>';
                                }
                                ?>
                            </td>
                            <td>
                                <button type="button" 
                                        class="button button-small edit-mapping" 
                                        data-mapping-id="<?php echo esc_attr($mapping->id); ?>">
                                    Edit
                                </button>
                                <button type="button" 
                                        class="button button-small button-link-delete delete-mapping" 
                                        data-mapping-id="<?php echo esc_attr($mapping->id); ?>">
                                    Delete
                                </button>
                </td>
            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
        </table>
        </div>
    </div>
    
    <!-- Step 3: Manual Sync -->
    <div class="tec-manual-sync-section">
        <h2><span class="step-number">3</span> Manual Sync</h2>
        <p class="description">Trigger an immediate synchronization of enabled calendars. Automatic sync schedules are configured per calendar mapping in Step 2 above.</p>
        
        <div class="manual-sync-card">
            <div class="manual-sync-info">
                <p><strong>Ready to sync:</strong> <span id="enabled-calendars-count">
                    <?php echo count(array_filter($calendar_mappings, function($m) { return $m->sync_enabled; })); ?>
                </span> calendar(s) enabled</p>
                <p>This will sync all enabled Outlook calendars to The Events Calendar based on each mapping's date range settings.</p>
            </div>
            <div class="manual-sync-actions" style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="button" class="button button-primary button-large" id="tec-manual-sync-btn">
                    <span class="dashicons dashicons-update"></span> Sync Now
                </button>
                <button type="button" class="button button-secondary" id="tec-repair-metadata-btn" title="Fix missing timezone and UTC date metadata for existing synced events">
                    <span class="dashicons dashicons-admin-tools"></span> Repair Event Metadata
                </button>
            </div>
        </div>
        
        <div id="sync-progress" style="display:none;">
            <div class="sync-progress-bar">
                <div class="sync-progress-fill" style="width: 0%;"></div>
            </div>
            <p id="sync-status-message" class="sync-message"></p>
            <div id="sync-details"></div>
        </div>
        </div>
        
    <!-- Sync History -->
    <div class="tec-sync-history-section">
        <h2>Recent Sync History</h2>
        <div id="sync-history-container">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 180px;">Date/Time</th>
                        <th style="width: 100px;">Type</th>
                        <th>Calendar(s)</th>
                        <th style="width: 120px;">Events Synced</th>
                        <th style="width: 100px;">Status</th>
                    </tr>
                </thead>
                <tbody id="sync-history-list">
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 20px;">
                            <em style="color: #666;">Loading sync history...</em>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php endif; // End if TEC calendar authenticated ?>
    
    <?php endif; // End if TEC module enabled ?>
</div><!-- End wrap -->

<!-- Calendar Mapping Modal -->
<div id="calendar-mapping-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="mapping-modal-title">Add Calendar Mapping</h2>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <form id="calendar-mapping-form">
                <input type="hidden" id="mapping-id" name="mapping_id">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="outlook-calendar-select">Outlook Calendar</label>
                        </th>
                        <td>
                            <select id="outlook-calendar-select" name="outlook_calendar_id" class="regular-text" required>
                                <option value="">Loading calendars...</option>
                            </select>
                            <p class="description">Select the Outlook calendar to sync</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="tec-category-select">TEC Category</label>
                        </th>
                        <td>
                            <select id="tec-category-select" name="tec_category_id" class="regular-text">
                                <option value="">Select existing category...</option>
                            </select>
                            <p class="description">Select an existing category from the dropdown above</p>
                            <p class="description" style="margin-top: 10px;"><strong>OR</strong> enter a new category name below:</p>
                            <input type="text" id="new-category-name" placeholder="New category name" class="regular-text" style="margin-top: 5px;">
                            <p class="description" style="margin-top: 5px; color: #666; font-style: italic;">
                                Note: Choose either an existing category OR enter a new name, not both.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Enable Sync</th>
                        <td>
                            <label>
                                <input type="checkbox" id="sync-enabled-checkbox" name="sync_enabled" value="1" checked>
                                Enable synchronization for this calendar
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <h3 style="margin-top: 20px; margin-bottom: 10px;">Automatic Sync Schedule (Optional)</h3>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Enable Schedule</th>
                        <td>
                            <label>
                                <input type="checkbox" id="schedule-enabled-checkbox" name="schedule_enabled" value="1">
                                Enable automatic scheduled sync for this mapping
                            </label>
                            <p class="description">When enabled, this calendar will sync automatically at the specified frequency.</p>
                        </td>
                    </tr>
                    <tr id="schedule-frequency-row" style="display: none;">
                        <th scope="row">Sync Frequency</th>
                        <td>
                            <select name="schedule_frequency" id="schedule-frequency-select" class="regular-text">
                                <option value="15min">Every 15 Minutes</option>
                                <option value="30min">Every 30 Minutes</option>
                                <option value="hourly" selected>Hourly</option>
                                <option value="twicedaily">Twice Daily</option>
                                <option value="daily">Daily</option>
                            </select>
                            <p class="description">How often to automatically sync this calendar.</p>
                        </td>
                    </tr>
                    <tr id="schedule-daterange-row" style="display: none;">
                        <th scope="row">Date Range</th>
                        <td>
                            <label>
                                Sync events from 
                                <input type="number" 
                                       name="schedule_lookback_days" 
                                       id="schedule-lookback-days"
                                       value="30" 
                                       min="0" 
                                       max="365" 
                                       style="width: 80px;"> days ago
                            </label>
                            <br>
                            <label>
                                to 
                                <input type="number" 
                                       name="schedule_lookahead_days" 
                                       id="schedule-lookahead-days"
                                       value="365" 
                                       min="1" 
                                       max="730" 
                                       style="width: 80px;"> days ahead
                            </label>
                            <p class="description">Define the date range for syncing events.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary" id="save-mapping-btn">
                        <span class="dashicons dashicons-saved"></span> Save Mapping
        </button>
                    <button type="button" class="button" id="cancel-mapping-btn">Cancel</button>
                </p>
            </form>
        </div>
    </div>
<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
</div>
<?php endif; ?>

<link rel="stylesheet" href="<?php echo AZURE_PLUGIN_URL . 'css/admin.css'; ?>">

<style>
/* ====================
   TEC SYNC PAGE STYLES
   ==================== */

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

/* Inline Notices */
.inline-notice {
    margin: 20px 0;
    padding: 15px 20px;
}

.inline-notice .dashicons {
    font-size: 20px;
    width: 20px;
    height: 20px;
    vertical-align: middle;
    margin-right: 5px;
}

.inline-notice p:first-child {
    display: flex;
    align-items: center;
    margin: 0 0 10px 0;
}

.inline-notice p:last-child {
    margin: 0;
}

/* TEC Sync Overview */
.tec-sync-overview {
    margin-bottom: 30px;
}

.info-card {
    background: #e7f5fe;
    border-left: 4px solid #0073aa;
    padding: 20px;
    border-radius: 4px;
}

.info-card h2 {
    margin-top: 0;
    color: #0073aa;
    display: flex;
    align-items: center;
    gap: 10px;
}

.info-card ul {
    margin: 15px 0 0 20px;
}

.info-card li {
    margin-bottom: 8px;
}

/* Section Styling */
.tec-auth-section,
.tec-calendar-mapping-section,
.tec-sync-schedule-section,
.tec-manual-sync-section,
.tec-sync-history-section {
    background: #fff;
    padding: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    margin-bottom: 20px;
}

.tec-auth-section h2,
.tec-calendar-mapping-section h2,
.tec-sync-schedule-section h2,
.tec-manual-sync-section h2,
.tec-sync-history-section h2 {
    margin-top: 0;
    color: #333;
    display: flex;
    align-items: center;
    gap: 10px;
}

.step-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    background: #0073aa;
    color: #fff;
    border-radius: 50%;
    font-weight: bold;
    font-size: 16px;
}

/* Status Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 4px;
    font-size: 13px;
    font-weight: 500;
}

.status-badge.status-success {
    background: #d4edda;
    color: #155724;
}

.status-badge.status-error {
    background: #f8d7da;
    color: #721c24;
}

.status-badge .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

.auth-status-display {
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.auth-actions-inline {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

/* Calendar Mappings */
.calendar-mappings-container {
    margin-top: 15px;
}

.calendar-mappings-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding: 15px;
    background: #f9f9f9;
    border-radius: 4px;
}

.calendar-mappings-table {
    margin-top: 15px;
}

.tec-category-badge {
    display: inline-block;
    background: #0073aa;
    color: #fff;
    padding: 4px 10px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 500;
}

/* Manual Sync Card */
.manual-sync-card {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f9f9f9;
    padding: 20px;
    border-radius: 4px;
    margin-top: 15px;
}

.manual-sync-info p {
    margin: 5px 0;
}

#enabled-calendars-count {
    font-weight: bold;
    color: #0073aa;
}

/* Sync Progress */
#sync-progress {
    margin-top: 20px;
}

.sync-progress-bar {
    width: 100%;
    height: 30px;
    background: #f0f0f0;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 10px;
}

.sync-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #0073aa 0%, #00a0d2 100%);
    transition: width 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-weight: bold;
}

.sync-message {
    font-size: 14px;
    font-weight: 500;
}

#sync-details {
    margin-top: 15px;
    padding: 15px;
    background: #f9f9f9;
    border-radius: 4px;
}

.sync-results ul {
    margin: 10px 0 0 20px;
}

.sync-results li {
    margin-bottom: 5px;
}

/* Switch with Label */
.switch-with-label {
    display: flex;
    align-items: center;
    gap: 10px;
}

.switch-with-label input[type="checkbox"] {
    margin: 0;
}

.switch-with-label span {
    font-weight: 500;
}

/* Sync History Table */
.tec-sync-history-section table {
    margin-top: 15px;
}

.status-badge.status-failed,
.status-badge.status-error {
    background: #f8d7da;
    color: #721c24;
}

.status-badge.status-partial {
    background: #fff3cd;
    color: #856404;
}

/* Modal Styles */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background: #fff;
    border-radius: 8px;
    max-width: 600px;
    width: 90%;
    max-height: 80%;
    overflow-y: auto;
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h2 {
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-body {
    padding: 20px;
}

#calendar-mapping-modal .form-table th {
    width: 25%;
}

#new-category-name {
    margin-top: 10px;
}

#create-tec-category-btn {
    margin-top: 5px;
}

/* Responsive Adjustments */
@media (max-width: 782px) {
    .manual-sync-card {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .calendar-mappings-header {
        flex-direction: column;
        gap: 10px;
    }
    
    .auth-status-display {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>
