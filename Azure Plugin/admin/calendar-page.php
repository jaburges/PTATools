<?php
if (!defined('ABSPATH')) {
    exit;
}

// Get plugin settings
$settings = Azure_Settings::get_all_settings();

// Get calendar user email (who authenticates) and mailbox email (shared mailbox to access)
$calendar_user_email = $settings['calendar_embed_user_email'] ?? '';
$calendar_mailbox_email = $settings['calendar_embed_mailbox_email'] ?? '';
$calendar_authenticated = false;

// Check authentication status for the user
if (!empty($calendar_user_email) && class_exists('Azure_Calendar_Auth')) {
    try {
    $auth = new Azure_Calendar_Auth();
        $calendar_authenticated = $auth->has_valid_user_token($calendar_user_email);
    } catch (Exception $e) {
        // Silently handle error
        $calendar_authenticated = false;
    }
}

// Get calendars from the shared mailbox if authenticated
$mailbox_calendars = array();
if ($calendar_authenticated && !empty($calendar_user_email) && !empty($calendar_mailbox_email) && class_exists('Azure_Calendar_GraphAPI')) {
    try {
        $graph_api = new Azure_Calendar_GraphAPI();
        // Use authenticated user's token to access mailbox's calendars
        $mailbox_calendars = $graph_api->get_mailbox_calendars($calendar_user_email, $calendar_mailbox_email);
    } catch (Exception $e) {
        // Silently handle error
        Azure_Logger::error('Calendar Page: Failed to get mailbox calendars - ' . $e->getMessage());
        $mailbox_calendars = array();
    }
}

// Handle auth success message
$show_auth_success = isset($_GET['auth']) && $_GET['auth'] === 'success';
?>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
<div class="wrap">
    <h1>PTA Tools - Calendar Embed</h1>
<?php endif; ?>

    <?php if ($show_auth_success): ?>
    <div class="notice notice-success is-dismissible">
        <p><strong>Success!</strong> Calendar authorization completed successfully. You can now manage calendars below.</p>
    </div>
    <?php endif; ?>

    <div class="azure-calendar-dashboard">
        <?php if (!Azure_Settings::is_module_enabled('calendar')): ?>
        <div class="notice notice-warning inline" style="margin-bottom:16px;">
            <p>
                <strong>Calendar module is disabled.</strong>
                Turn it on from the
                <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin')); ?>">PTA Tools Config screen</a>
                to use calendar embedding.
            </p>
        </div>
        <?php endif; ?>

        <?php if (!$calendar_authenticated): ?>
        <div class="notice notice-warning inline" style="margin-bottom:16px;">
            <p>
                <strong>Calendar sign-in required.</strong>
                Connect your M365 account and shared mailbox on the
                <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin')); ?>">PTA Tools Config screen</a>
                before managing calendar embeds here.
            </p>
        </div>
        <?php endif; ?>

        <!-- Available Calendars from Mailbox -->
        <?php if ($calendar_authenticated): ?>
        <div class="calendar-list-section">
            <h2><span class="dashicons dashicons-calendar-alt"></span> Available Calendars from Mailbox</h2>
            <p class="description">Select which calendars from <?php echo esc_html($calendar_mailbox_email); ?> you want to enable for embedding.</p>
            
            <?php if (!empty($mailbox_calendars)): ?>
            <div class="calendars-grid">
                <?php foreach ($mailbox_calendars as $calendar): ?>
                <div class="calendar-item" data-calendar-id="<?php echo esc_attr($calendar['id']); ?>">
                    <div class="calendar-header">
                        <div class="calendar-title-section">
                        <h3><?php echo esc_html($calendar['name']); ?></h3>
                            <label class="calendar-enable-toggle">
                                <input type="checkbox" 
                                       class="calendar-embed-toggle" 
                                       data-calendar-id="<?php echo esc_attr($calendar['id']); ?>"
                                       data-calendar-name="<?php echo esc_attr($calendar['name']); ?>"
                                       <?php checked(in_array($calendar['id'], $settings['calendar_embed_enabled_calendars'] ?? [])); ?> />
                                <span>Enable for embedding</span>
                            </label>
                        </div>
                        <div class="calendar-actions">
                            <button type="button" class="button button-small preview-calendar" 
                                    data-calendar-id="<?php echo esc_attr($calendar['id']); ?>"
                                    data-user-email="<?php echo esc_attr($calendar_user_email); ?>">
                                Preview
                            </button>
                        </div>
                    </div>
                    
                    <div class="calendar-info">
                        <p><strong>Calendar ID:</strong> <code class="selectable-text"><?php echo esc_html($calendar['id']); ?></code></p>
                        <?php if (isset($calendar['description']) && !empty($calendar['description'])): ?>
                        <p><strong>Description:</strong> <?php echo esc_html($calendar['description']); ?></p>
                        <?php endif; ?>
                        
                        <div class="calendar-settings-form">
                            <div class="calendar-timezone-setting">
                                <label>
                                    <strong>Default Timezone:</strong>
                                    <select class="calendar-timezone-select" data-calendar-id="<?php echo esc_attr($calendar['id']); ?>">
                                        <?php
                                        $current_tz = $settings['calendar_timezone_' . $calendar['id']] ?? 'America/New_York';
                                        $timezones = array(
                                            'America/New_York' => 'Eastern Time',
                                            'America/Chicago' => 'Central Time',
                                            'America/Denver' => 'Mountain Time',
                                            'America/Los_Angeles' => 'Pacific Time',
                                            'America/Phoenix' => 'Arizona',
                                            'America/Anchorage' => 'Alaska',
                                            'Pacific/Honolulu' => 'Hawaii',
                                            'UTC' => 'UTC',
                                        );
                                        foreach ($timezones as $tz_value => $tz_label):
                                        ?>
                                            <option value="<?php echo esc_attr($tz_value); ?>" <?php selected($current_tz, $tz_value); ?>>
                                                <?php echo esc_html($tz_label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>
                            
                            <button type="button" class="button button-primary save-calendar-settings" 
                                    data-calendar-id="<?php echo esc_attr($calendar['id']); ?>"
                                    data-calendar-name="<?php echo esc_attr($calendar['name']); ?>">
                                <span class="dashicons dashicons-saved"></span> Save Calendar Settings
                            </button>
                        </div>
                        
                        <div class="calendar-shortcodes">
                            <h4>Shortcodes for embedding:</h4>
                            <div class="shortcode-examples">
                                <div class="shortcode">
                                    <label>Calendar View:</label>
                                    <input type="text" readonly 
                                           value='[azure_calendar email="<?php echo esc_attr($calendar_mailbox_email); ?>" id="<?php echo esc_attr($calendar['id']); ?>" view="month"]' 
                                           onclick="this.select();" class="shortcode-input">
                                </div>
                                <div class="shortcode">
                                    <label>Events List:</label>
                                    <input type="text" readonly 
                                           value='[azure_calendar_events email="<?php echo esc_attr($calendar_mailbox_email); ?>" id="<?php echo esc_attr($calendar['id']); ?>" limit="10"]' 
                                           onclick="this.select();" class="shortcode-input">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="notice notice-info inline">
                <p>No calendars found in this mailbox. Make sure you have delegated access to the shared mailbox.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Settings Form -->
        <div class="calendar-settings-section">
            <form method="post" action="">
                <?php wp_nonce_field('azure_plugin_settings'); ?>
                
                <!-- Credentials Section -->
                <?php if (!($settings['use_common_credentials'] ?? true)): ?>
                <div class="credentials-section">
                    <h2>Azure Calendar Credentials</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Client ID</th>
                            <td>
                                <input type="text" name="calendar_client_id" value="<?php echo esc_attr($settings['calendar_client_id'] ?? ''); ?>" class="regular-text" />
                                <p class="description">Your Azure App Registration Client ID</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Client Secret</th>
                            <td>
                                <input type="password" name="calendar_client_secret" value="<?php echo esc_attr($settings['calendar_client_secret'] ?? ''); ?>" class="regular-text" />
                                <p class="description">Your Azure App Registration Client Secret</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Tenant ID</th>
                            <td>
                                <input type="text" name="calendar_tenant_id" value="<?php echo esc_attr($settings['calendar_tenant_id'] ?? 'common'); ?>" class="regular-text" />
                                <p class="description">Your Azure Tenant ID (or 'common' for multi-tenant)</p>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <button type="button" class="button test-credentials" 
                                    data-client-id-field="calendar_client_id" 
                                    data-client-secret-field="calendar_client_secret" 
                                    data-tenant-id-field="calendar_tenant_id">
                                    Test Credentials
                                </button>
                                <span class="credentials-status"></span>
                            </td>
                        </tr>
                    </table>
                </div>
                <?php endif; ?>
                
                <!-- Display Settings -->
                <div class="calendar-display">
                    <h2>Display Settings</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Default Timezone</th>
                            <td>
                                <select name="calendar_default_timezone">
                                    <?php
                                    $current_timezone = $settings['calendar_default_timezone'] ?? 'America/New_York';
                                    $timezones = array(
                                        'America/New_York' => 'Eastern Time (US & Canada)',
                                        'America/Chicago' => 'Central Time (US & Canada)',
                                        'America/Denver' => 'Mountain Time (US & Canada)',
                                        'America/Los_Angeles' => 'Pacific Time (US & Canada)',
                                        'America/Phoenix' => 'Arizona',
                                        'America/Anchorage' => 'Alaska',
                                        'Pacific/Honolulu' => 'Hawaii',
                                        'UTC' => 'UTC',
                                        'Europe/London' => 'London',
                                        'Europe/Paris' => 'Paris',
                                        'Europe/Berlin' => 'Berlin',
                                        'Asia/Tokyo' => 'Tokyo',
                                        'Asia/Shanghai' => 'Shanghai',
                                        'Australia/Sydney' => 'Sydney'
                                    );
                                    ?>
                                    <?php foreach ($timezones as $value => $label): ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($current_timezone, $value); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Default timezone for calendar displays</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Default View</th>
                            <td>
                                <select name="calendar_default_view">
                                    <?php
                                    $current_view = $settings['calendar_default_view'] ?? 'month';
                                    $views = array(
                                        'month' => 'Month View',
                                        'week' => 'Week View',
                                        'day' => 'Day View',
                                        'list' => 'List View'
                                    );
                                    ?>
                                    <?php foreach ($views as $value => $label): ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($current_view, $value); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Default view for calendar displays</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Color Theme</th>
                            <td>
                                <select name="calendar_default_color_theme">
                                    <?php
                                    $current_theme = $settings['calendar_default_color_theme'] ?? 'blue';
                                    $themes = array(
                                        'blue' => 'Blue',
                                        'green' => 'Green',
                                        'red' => 'Red',
                                        'purple' => 'Purple',
                                        'orange' => 'Orange',
                                        'gray' => 'Gray'
                                    );
                                    ?>
                                    <?php foreach ($themes as $value => $label): ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($current_theme, $value); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Default color theme for calendar events</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Performance Settings -->
                <div class="calendar-performance">
                    <h2>Performance Settings</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Cache Duration</th>
                            <td>
                                <input type="number" name="calendar_cache_duration" value="<?php echo intval($settings['calendar_cache_duration'] ?? 3600); ?>" min="300" max="86400" class="small-text" />
                                <span>seconds</span>
                                <p class="description">How long to cache calendar data (300-86400 seconds)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Max Events Per Calendar</th>
                            <td>
                                <input type="number" name="calendar_max_events_per_calendar" value="<?php echo intval($settings['calendar_max_events_per_calendar'] ?? 100); ?>" min="10" max="1000" class="small-text" />
                                <span>events</span>
                                <p class="description">Maximum number of events to fetch per calendar (10-1000)</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <p class="submit">
                    <input type="submit" name="azure_plugin_submit" class="button-primary" value="Save Calendar Settings" />
                </p>
            </form>
        </div>
        
        <!-- Shortcode Documentation -->
        <div class="calendar-shortcodes-section">
            <h2>Calendar Shortcodes</h2>
            
            <div class="shortcode-documentation">
                <div class="shortcode-example">
                    <h4>Full Calendar Display</h4>
                    <code>[azure_calendar id="calendar_id" view="month" height="600px"]</code>
                    
                    <h5>Basic Parameters:</h5>
                    <ul>
                        <li><code>id</code> - Required. The calendar ID from above</li>
                        <li><code>email</code> - Shared mailbox email (e.g., calendar@domain.com)</li>
                        <li><code>view</code> - month, week, day, list (default: month)</li>
                        <li><code>height</code> - CSS height value (default: 600px)</li>
                        <li><code>width</code> - CSS width value (default: 100%)</li>
                        <li><code>timezone</code> - Override default timezone</li>
                        <li><code>max_events</code> - Maximum events to show (default: 100)</li>
                        <li><code>show_weekends</code> - true/false (default: true)</li>
                        <li><code>first_day</code> - 0 = Sunday, 1 = Monday (default: 0)</li>
                        <li><code>time_format</code> - 12h or 24h (default: 24h)</li>
                    </ul>
                    
                    <h5>Time Range Parameters (for week/day views):</h5>
                    <ul>
                        <li><code>slot_min_time</code> - Start time for day/week views (default: 08:00:00)</li>
                        <li><code>slot_max_time</code> - End time for day/week views (default: 18:00:00)</li>
                        <li><code>slot_duration</code> - Duration of each time slot (default: 00:30:00)</li>
                    </ul>
                    
                    <h5>Example with Time Range:</h5>
                    <code>[azure_calendar email="calendar@domain.com" id="calendar_id" view="week" slot_min_time="08:00:00" slot_max_time="18:00:00"]</code>
                    <p class="description">This limits the week view to show only 8am - 6pm, hiding empty early morning and late evening hours.</p>
                </div>
                
                <div class="shortcode-example">
                    <h4>Events List</h4>
                    <code>[azure_calendar_events id="calendar_id" limit="10" format="list"]</code>
                    
                    <h5>Parameters:</h5>
                    <ul>
                        <li><code>id</code> - Required. The calendar ID</li>
                        <li><code>email</code> - Shared mailbox email (e.g., calendar@domain.com)</li>
                        <li><code>limit</code> - Number of events to show (default: 10)</li>
                        <li><code>format</code> - list, grid, compact (default: list)</li>
                        <li><code>upcoming_only</code> - true/false (default: true)</li>
                        <li><code>show_dates</code> - true/false (default: true)</li>
                        <li><code>show_times</code> - true/false (default: true)</li>
                        <li><code>show_location</code> - true/false (default: true)</li>
                        <li><code>show_description</code> - true/false (default: false)</li>
                    </ul>
                </div>
            </div>
        </div>
    </div><!-- End Calendar Dashboard -->
    
</div><!-- End wrap -->

<!-- Calendar Preview Modal -->
<div id="calendar-preview-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Calendar Preview</h2>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="calendar-preview-container">
                Loading...
            </div>
        </div>
    </div>
<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
</div>
<?php endif; ?>

<script>
jQuery(document).ready(function($) {
    // Auth, mailbox-email save, re-auth, revoke, and refresh handlers
    // were moved to the Config page (admin.php?page=azure-plugin) as
    // part of the v3.113 UI consolidation. The Calendar Embed tab is
    // now embed-only; per-calendar toggles + timezones live below.

    // Toggle calendar enable/disable for embedding
    $('.calendar-embed-toggle').change(function() {
        var calendarId = $(this).data('calendar-id');
        var calendarName = $(this).data('calendar-name');
        var enabled = $(this).is(':checked');
        
        $.post(ajaxurl, {
            action: 'azure_toggle_calendar_embed',
            calendar_id: calendarId,
            calendar_name: calendarName,
            enabled: enabled,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (!response.success) {
                alert('❌ Failed to toggle calendar: ' + (response.data || 'Unknown error'));
                // Revert toggle
                $('.calendar-embed-toggle[data-calendar-id="' + calendarId + '"]').prop('checked', !enabled);
            }
        });
    });
    
    // Save calendar timezone
    $('.save-calendar-timezone').click(function() {
        var calendarId = $(this).data('calendar-id');
        var timezone = $('.calendar-timezone-select[data-calendar-id="' + calendarId + '"]').val();
        var button = $(this);
        
        button.prop('disabled', true).text('Saving...');
        
        $.post(ajaxurl, {
            action: 'azure_save_calendar_timezone',
            calendar_id: calendarId,
            timezone: timezone,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).text('Save');
            
            if (response.success) {
                alert('✅ Timezone saved successfully!');
            } else {
                alert('❌ Failed to save timezone: ' + (response.data || 'Unknown error'));
            }
        });
    });
    
    // Save calendar settings (for the calendar card "Save Calendar Settings" button)
    $('.save-calendar-settings').click(function() {
        var calendarId = $(this).data('calendar-id');
        var timezone = $('.calendar-timezone-select[data-calendar-id="' + calendarId + '"]').val();
        var button = $(this);
        
        button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Saving...');
        
        $.post(ajaxurl, {
            action: 'azure_save_calendar_timezone',
            calendar_id: calendarId,
            timezone: timezone,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Calendar Settings');
            
            if (response.success) {
                alert('✅ Calendar settings saved successfully!');
            } else {
                alert('❌ Failed to save settings: ' + (response.data || 'Unknown error'));
            }
        });
    });
    
    // Handle calendar preview
    $('.preview-calendar').click(function() {
        var calendarId = $(this).data('calendar-id');
        var userEmail = $(this).data('user-email');
        var modal = $('#calendar-preview-modal');
        var container = $('#calendar-preview-container');
        
        modal.show();
        container.html('Loading calendar preview...');
        
        $.post(ajaxurl, {
            action: 'azure_calendar_get_events',
            calendar_id: calendarId,
            user_email: userEmail,
            max_events: 10,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                var events = response.data;
                var html = '<h3>Upcoming Events</h3>';
                
                if (events.length === 0) {
                    html += '<p>No upcoming events found.</p>';
                } else {
                    html += '<ul>';
                    events.forEach(function(event) {
                        var startDate = new Date(event.start).toLocaleString();
                        html += '<li><strong>' + event.title + '</strong><br>';
                        html += '<small>' + startDate + '</small>';
                        if (event.location) {
                            html += '<br><em>Location: ' + event.location + '</em>';
                        }
                        html += '</li>';
                    });
                    html += '</ul>';
                }
                
                container.html(html);
            } else {
                container.html('<p class="error">Failed to load calendar events: ' + (response.data || 'Unknown error') + '</p>');
            }
        });
    });
    
    // Handle modal close
    $('.modal-close, .modal').click(function(e) {
        if (e.target === this) {
            $('.modal').hide();
        }
    });
    
    // Handle module toggle
    $('.calendar-module-toggle').change(function() {
        var enabled = $(this).is(':checked');
        var statusText = $(this).closest('.module-control').find('.toggle-status');
        
        $.post(ajaxurl, {
            action: 'azure_toggle_module',
            module: 'calendar',
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
                $('.calendar-module-toggle').prop('checked', !enabled);
                alert('Failed to toggle Calendar module: ' + (response.data || 'Unknown error'));
            }
        }).fail(function() {
            // Revert toggle if failed
            $('.calendar-module-toggle').prop('checked', !enabled);
            alert('Network error occurred');
        });
    });
});
</script>

<style>
/* Step Numbers */
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
    margin-right: 5px;
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

.calendar-auth-section {
    margin-bottom: 30px;
    background: #fff;
    padding: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
}

.calendar-auth-section h2 {
    margin-top: 0;
    color: #333;
    display: flex;
    align-items: center;
}

.auth-status-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    padding: 20px;
}

.auth-status {
    display: flex;
    align-items: center;
    gap: 15px;
}

.auth-status .dashicons {
    font-size: 24px;
}

.auth-status.success .dashicons {
    color: #46b450;
}

.auth-status.warning .dashicons {
    color: #ffb900;
}

.auth-status.error .dashicons {
    color: #dc3232;
}

.auth-info {
    flex: 1;
}

.auth-info h3 {
    margin: 0 0 5px 0;
}

.auth-info p {
    margin: 0;
    color: #666;
}

.auth-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.calendar-list-section {
    background: #fff;
    padding: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    margin-bottom: 20px;
}

.calendar-list-section h2 {
    margin-top: 0;
    color: #333;
    display: flex;
    align-items: center;
}

.calendars-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 15px;
}

.calendar-item {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    padding: 20px;
}

.calendar-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.calendar-title-section {
    flex: 1;
}

.calendar-title-section h3 {
    margin: 0 0 8px 0;
    color: #0073aa;
}

.calendar-enable-toggle {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 13px;
    color: #666;
    cursor: pointer;
}

.calendar-enable-toggle input[type="checkbox"] {
    margin: 0;
}

.calendar-actions {
    display: flex;
    gap: 5px;
}

.calendar-info p {
    margin: 10px 0;
    font-size: 13px;
}

.calendar-info .selectable-text {
    font-size: 11px;
    cursor: text;
    user-select: all;
    display: inline-block;
    max-width: 100%;
    word-break: break-all;
    overflow-wrap: anywhere;
    background: #f5f5f5;
    border: 1px solid #e5e5e5;
    border-radius: 3px;
    padding: 2px 6px;
    line-height: 1.5;
    vertical-align: middle;
}

.calendar-info p strong {
    display: inline-block;
    min-width: 90px;
    vertical-align: top;
}

.calendar-timezone-setting {
    margin: 15px 0;
    padding: 10px;
    background: #f9f9f9;
    border-radius: 4px;
}

.calendar-timezone-setting label {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.calendar-timezone-select {
    flex: 1;
    min-width: 150px;
}

.calendar-shortcodes {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #eee;
}

.calendar-shortcodes h4 {
    margin: 0 0 10px 0;
    font-size: 13px;
    color: #666;
}

.shortcode-examples .shortcode {
    margin: 8px 0;
}

.shortcode-examples label {
    display: inline-block;
    width: 100px;
    font-size: 12px;
    color: #666;
    vertical-align: top;
    padding-top: 6px;
}

.shortcode-examples .shortcode-input {
    width: calc(100% - 110px);
    font-size: 11px;
    font-family: monospace;
    background: #f9f9f9;
    border: 1px solid #ddd;
    padding: 4px 6px;
    cursor: pointer;
}

.calendar-display,
.calendar-performance {
    background: #fff;
    padding: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    margin-bottom: 20px;
}

.calendar-shortcodes-section {
    background: #fff;
    padding: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    margin-top: 20px;
}

.shortcode-documentation {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 20px;
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
    max-width: 800px;
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
.calendar-auth-section,
.calendar-display,
.calendar-authentication {
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
.calendar-auth-section h2,
.calendar-display h2,
.calendar-authentication h2 {
    color: #333 !important;
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
}

/* Calendar List Items */
.calendar-item {
    background: #fff !important;
    color: #333 !important;
    border: 1px solid #ccd0d4 !important;
}

.calendar-item h4 {
    color: #0073aa !important;
}

.calendar-item p {
    color: #666 !important;
}

/* WordPress Dark Theme Overrides */
body.admin-color-midnight .module-toggle-card,
body.admin-color-midnight .calendar-auth-section,
body.admin-color-midnight .calendar-display,
body.admin-color-midnight .calendar-authentication,
body.admin-color-midnight .calendar-item {
    background: #fff !important;
    color: #333 !important;
    border-color: #ccd0d4 !important;
}

/* Spinner animation for dashicons */
.dashicons.spin {
    animation: dashicons-spin 1s linear infinite;
}

@keyframes dashicons-spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>
