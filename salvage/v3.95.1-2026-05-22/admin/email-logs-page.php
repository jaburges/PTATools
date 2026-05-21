<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
<div class="wrap">
    <h1>PTA Tools - Email Logs</h1>
<?php endif; ?>
    
    <div class="azure-email-logs-dashboard">
        <!-- Email Statistics -->
        <?php if (!empty($email_stats)): ?>
        <div class="email-stats-section">
            <h2>Email Statistics</h2>
            
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-number"><?php echo intval($email_stats['total'] ?? 0); ?></div>
                    <div class="stat-label">Total Emails</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-number"><?php echo intval($email_stats['today'] ?? 0); ?></div>
                    <div class="stat-label">Today's Emails</div>
                </div>
                
                <div class="stat-card <?php echo intval($email_stats['failed_today'] ?? 0) > 0 ? 'error' : 'success'; ?>">
                    <div class="stat-number"><?php echo intval($email_stats['failed_today'] ?? 0); ?></div>
                    <div class="stat-label">Failed Today</div>
                </div>
                
                <div class="stat-card success">
                    <div class="stat-number"><?php echo floatval($email_stats['success_rate_today'] ?? 100); ?>%</div>
                    <div class="stat-label">Success Rate</div>
                </div>
            </div>
            
            <!-- Email Methods Chart -->
            <?php if (!empty($email_stats['methods'])): ?>
            <div class="email-methods-section">
                <h3>Email Methods (Last 7 Days)</h3>
                <div class="methods-chart">
                    <?php foreach ($email_stats['methods'] as $method): ?>
                    <div class="method-item">
                        <span class="method-name"><?php echo esc_html($method->method); ?></span>
                        <span class="method-count"><?php echo intval($method->count); ?></span>
                        <div class="method-bar">
                            <div class="method-fill" style="width: <?php echo min(100, ($method->count / $email_stats['methods'][0]->count) * 100); ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Recent Errors -->
            <?php if (!empty($email_stats['recent_errors'])): ?>
            <div class="recent-errors-section">
                <h3>Recent Email Errors</h3>
                <div class="errors-list">
                    <?php foreach ($email_stats['recent_errors'] as $error): ?>
                    <div class="error-item">
                        <div class="error-header">
                            <span class="error-time"><?php echo date('M j, Y H:i', strtotime($error->timestamp)); ?></span>
                            <span class="error-recipient"><?php echo esc_html($error->to_email); ?></span>
                        </div>
                        <div class="error-subject"><?php echo esc_html($error->subject); ?></div>
                        <div class="error-message"><?php echo esc_html($error->error_message); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Email Logs Controls -->
        <div class="email-logs-controls-section">
            <h2>Email Logs Management</h2>
            
            <div class="logs-controls">
                <div class="filters-row">
                    <input type="text" id="email-search" placeholder="Search emails..." class="regular-text">
                    
                    <select id="status-filter" class="email-filter">
                        <option value="">All Status</option>
                        <option value="sent">Sent</option>
                        <option value="failed">Failed</option>
                    </select>
                    
                    <select id="method-filter" class="email-filter">
                        <option value="">All Methods</option>
                        <option value="Azure Graph API">Azure Graph API</option>
                        <option value="Azure HVE">Azure HVE</option>
                        <option value="Azure ACS">Azure ACS</option>
                        <option value="WP Mail SMTP">WP Mail SMTP</option>
                        <option value="WordPress Default">WordPress Default</option>
                        <option value="PHPMailer">PHPMailer</option>
                    </select>
                    
                    <input type="date" id="date-from" class="email-filter">
                    <input type="date" id="date-to" class="email-filter">
                    
                    <button type="button" class="button search-emails">
                        <span class="dashicons dashicons-search"></span>
                        Search
                    </button>
                    
                    <button type="button" class="button refresh-emails">
                        <span class="dashicons dashicons-update"></span>
                        Refresh
                    </button>
                </div>
                
                <div class="bulk-actions-row">
                    <select id="bulk-action">
                        <option value="">Bulk Actions</option>
                        <option value="delete">Delete Selected</option>
                        <option value="resend">Resend Selected</option>
                    </select>
                    
                    <button type="button" class="button apply-bulk-action">Apply</button>
                    
                    <button type="button" class="button clear-all-emails" style="margin-left: 20px; background-color: #d63638; border-color: #d63638; color: white;">
                        <span class="dashicons dashicons-trash"></span>
                        Clear All Logs
                    </button>
                    
                    <button type="button" class="button export-emails">
                        <span class="dashicons dashicons-download"></span>
                        Export Logs
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Email Logs Table -->
        <div class="email-logs-table-section">
            <h2>Email Logs</h2>
            
            <div class="email-logs-container">
                <div class="logs-loading" style="display: none;">
                    <div class="spinner is-active"></div>
                    <p>Loading email logs...</p>
                </div>
                
                <div class="email-logs-table-wrapper">
                    <table class="wp-list-table widefat fixed striped" id="email-logs-table">
                        <thead>
                            <tr>
                                <td class="manage-column column-cb check-column">
                                    <input type="checkbox" id="select-all-emails">
                                </td>
                                <th class="manage-column column-timestamp">Date/Time</th>
                                <th class="manage-column column-to">To</th>
                                <th class="manage-column column-from">From</th>
                                <th class="manage-column column-subject">Subject</th>
                                <th class="manage-column column-method">Method</th>
                                <th class="manage-column column-status">Status</th>
                                <th class="manage-column column-source">Source</th>
                                <th class="manage-column column-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="email-logs-body">
                            <tr>
                                <td colspan="9" class="no-items">No email logs found. Start using the plugin to see email activity here.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num" id="email-logs-count">0 items</span>
                        <span class="pagination-links" id="email-pagination">
                            <!-- Pagination will be inserted here -->
                        </span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Settings -->
        <div class="email-logging-settings-section">
            <h2>Email Logging Settings</h2>
            
            <form method="post" action="options.php">
                <?php settings_fields('azure_plugin_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Email Logging</th>
                        <td>
                            <label>
                                <input type="checkbox" name="email_logging_enabled" <?php checked(Azure_Settings::get_setting('email_logging_enabled', true)); ?> />
                                Log all emails sent through WordPress
                            </label>
                            <p class="description">When enabled, all emails sent by WordPress (from any plugin or theme) will be logged and displayed here.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Log Retention</th>
                        <td>
                            <select name="email_log_retention_days">
                                <option value="7" <?php selected(Azure_Settings::get_setting('email_log_retention_days', 30), 7); ?>>7 days</option>
                                <option value="30" <?php selected(Azure_Settings::get_setting('email_log_retention_days', 30), 30); ?>>30 days</option>
                                <option value="90" <?php selected(Azure_Settings::get_setting('email_log_retention_days', 30), 90); ?>>90 days</option>
                                <option value="365" <?php selected(Azure_Settings::get_setting('email_log_retention_days', 30), 365); ?>>1 year</option>
                                <option value="0" <?php selected(Azure_Settings::get_setting('email_log_retention_days', 30), 0); ?>>Never delete</option>
                            </select>
                            <p class="description">How long to keep email logs before automatically deleting them.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Log Email Content</th>
                        <td>
                            <label>
                                <input type="checkbox" name="email_log_content" <?php checked(Azure_Settings::get_setting('email_log_content', true)); ?> />
                                Store email message content
                            </label>
                            <p class="description">When disabled, only email headers and metadata will be stored (for privacy).</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Save Email Logging Settings'); ?>
            </form>
        </div>
    </div>
<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
</div>
<?php endif; ?>

<!-- Email Preview Modal -->
<div id="email-preview-modal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h2>Email Preview</h2>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="email-preview-content">
                <!-- Email content will be loaded here -->
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var currentPage = 1;
    var perPage = 50;
    
    // Load email logs on page load
    loadEmailLogs();
    
    // Search emails
    $('.search-emails, .refresh-emails').click(function() {
        currentPage = 1;
        loadEmailLogs();
    });
    
    // Filter changes
    $('.email-filter').change(function() {
        currentPage = 1;
        loadEmailLogs();
    });
    
    // Search on Enter key
    $('#email-search').keypress(function(e) {
        if (e.which == 13) {
            currentPage = 1;
            loadEmailLogs();
        }
    });
    
    // Select all emails
    $('#select-all-emails').change(function() {
        $('.email-checkbox').prop('checked', this.checked);
    });
    
    // Apply bulk actions
    $('.apply-bulk-action').click(function() {
        var action = $('#bulk-action').val();
        var selected = $('.email-checkbox:checked').map(function() {
            return this.value;
        }).get();
        
        if (!action) {
            alert('Please select a bulk action');
            return;
        }
        
        if (selected.length === 0) {
            alert('Please select at least one email');
            return;
        }
        
        if (action === 'delete') {
            if (!confirm('Are you sure you want to delete ' + selected.length + ' email logs?')) {
                return;
            }
            
            bulkDeleteEmails(selected);
        } else if (action === 'resend') {
            if (!confirm('Are you sure you want to resend ' + selected.length + ' emails?')) {
                return;
            }
            
            bulkResendEmails(selected);
        }
    });
    
    // Clear all logs
    $('.clear-all-emails').click(function() {
        if (!confirm('⚠️ Warning: This will delete ALL email logs permanently.\n\nThis action cannot be undone.\n\nAre you sure you want to continue?')) {
            return;
        }
        
        var button = $(this);
        var originalHtml = button.html();
        
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Clearing...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_clear_email_logs',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html(originalHtml);
            
            if (response.success) {
                alert('✅ All email logs cleared successfully!');
                loadEmailLogs();
                // Update statistics
                location.reload();
            } else {
                alert('❌ Failed to clear email logs: ' + response.data);
            }
        }).fail(function() {
            button.prop('disabled', false).html(originalHtml);
            alert('❌ Network error occurred');
        });
    });
    
    // Export logs
    $('.export-emails').click(function() {
        var params = {
            search: $('#email-search').val(),
            status: $('#status-filter').val(),
            method: $('#method-filter').val(),
            date_from: $('#date-from').val(),
            date_to: $('#date-to').val()
        };
        
        var query = $.param(params);
        window.open(azure_plugin_ajax.ajax_url + '?action=azure_export_email_logs&' + query + '&nonce=' + azure_plugin_ajax.nonce);
    });
    
    function loadEmailLogs() {
        $('.logs-loading').show();
        $('#email-logs-table').hide();
        
        // Debug: Check if azure_plugin_ajax is available
        console.log('Email Logs Debug: azure_plugin_ajax object:', typeof azure_plugin_ajax !== 'undefined' ? azure_plugin_ajax : 'undefined');
        
        if (typeof azure_plugin_ajax === 'undefined') {
            $('.logs-loading').hide();
            $('#email-logs-table').show();
            $('.logs-message').html('<div class="error">JavaScript not loaded properly. Please refresh the page.</div>');
            return;
        }
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_get_email_logs',
            page: currentPage,
            per_page: perPage,
            search: $('#email-search').val(),
            status: $('#status-filter').val(),
            method: $('#method-filter').val(),
            date_from: $('#date-from').val(),
            date_to: $('#date-to').val(),
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            $('.logs-loading').hide();
            $('#email-logs-table').show();
            
            // Debug: Log the full response
            console.log('Email Logs AJAX Response:', response);
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
                renderEmailLogs(response.data);
            } else {
                var errorMsg = 'Unknown error';
                if (response && response.data) {
                    errorMsg = response.data;
                } else if (response) {
                    errorMsg = 'Invalid response format';
                } else {
                    errorMsg = 'No response from server';
                }
                alert('❌ Failed to load email logs: ' + errorMsg);
            }
        }).fail(function(jqXHR, textStatus, errorThrown) {
            $('.logs-loading').hide();
            $('#email-logs-table').show();
            console.log('Email Logs AJAX Failed:', {jqXHR: jqXHR, textStatus: textStatus, errorThrown: errorThrown});
            console.log('Response Text:', jqXHR.responseText);
            alert('❌ Network error occurred: ' + textStatus + ' - ' + errorThrown);
        });
    }
    
    function renderEmailLogs(data) {
        var tbody = $('#email-logs-body');
        tbody.empty();
        
        if (data.logs.length === 0) {
            tbody.append('<tr><td colspan="9" class="no-items">No email logs found.</td></tr>');
            $('#email-logs-count').text('0 items');
            $('#email-pagination').empty();
            return;
        }
        
        data.logs.forEach(function(log) {
            var statusClass = log.status === 'failed' ? 'error' : 'success';
            var statusIcon = log.status === 'failed' ? 'warning' : 'yes-alt';
            
            var row = $('<tr>').append(
                $('<th class="check-column">').append(
                    $('<input type="checkbox" class="email-checkbox">').val(log.id)
                ),
                $('<td class="column-timestamp">').text(
                    new Date(log.timestamp).toLocaleDateString() + ' ' + 
                    new Date(log.timestamp).toLocaleTimeString()
                ),
                $('<td class="column-to">').text(log.to_email),
                $('<td class="column-from">').text(log.from_email || 'N/A'),
                $('<td class="column-subject">').append(
                    $('<a href="#" class="view-email">').data('id', log.id).text(log.subject || '(No Subject)')
                ),
                $('<td class="column-method">').text(log.method),
                $('<td class="column-status">').addClass(statusClass).append(
                    $('<span class="dashicons dashicons-' + statusIcon + '">'),
                    ' ' + log.status.charAt(0).toUpperCase() + log.status.slice(1)
                ),
                $('<td class="column-source">').text(log.plugin_source || 'Unknown'),
                $('<td class="column-actions">').append(
                    $('<button class="button button-small view-email">').data('id', log.id).text('View'),
                    ' ',
                    $('<button class="button button-small delete-email">').data('id', log.id).text('Delete'),
                    log.status === 'failed' ? ' ' + $('<button class="button button-small resend-email">').data('id', log.id).text('Resend') : ''
                )
            );
            
            tbody.append(row);
        });
        
        // Update pagination
        $('#email-logs-count').text(data.total + ' items');
        renderPagination(data);
        
        // Bind click events
        $('.view-email').click(function(e) {
            e.preventDefault();
            viewEmail($(this).data('id'));
        });
        
        $('.delete-email').click(function() {
            if (confirm('Are you sure you want to delete this email log?')) {
                deleteEmail($(this).data('id'));
            }
        });
        
        $('.resend-email').click(function() {
            if (confirm('Are you sure you want to resend this email?')) {
                resendEmail($(this).data('id'));
            }
        });
    }
    
    function renderPagination(data) {
        var pagination = $('#email-pagination');
        pagination.empty();
        
        if (data.total_pages <= 1) return;
        
        // Previous page
        if (currentPage > 1) {
            pagination.append(
                $('<a class="prev-page button">').text('‹').click(function() {
                    currentPage--;
                    loadEmailLogs();
                })
            );
        }
        
        // Page numbers
        var startPage = Math.max(1, currentPage - 2);
        var endPage = Math.min(data.total_pages, currentPage + 2);
        
        for (var i = startPage; i <= endPage; i++) {
            if (i === currentPage) {
                pagination.append($('<span class="paging-input">').text(i));
            } else {
                pagination.append(
                    $('<a class="page-numbers">').text(i).click((function(page) {
                        return function() {
                            currentPage = page;
                            loadEmailLogs();
                        };
                    })(i))
                );
            }
        }
        
        // Next page
        if (currentPage < data.total_pages) {
            pagination.append(
                $('<a class="next-page button">').text('›').click(function() {
                    currentPage++;
                    loadEmailLogs();
                })
            );
        }
    }
    
    function viewEmail(id) {
        // Implementation for viewing email details
        alert('Email preview functionality will be implemented');
    }
    
    function deleteEmail(id) {
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_delete_email_log',
            log_id: id,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                loadEmailLogs(); // Reload the list
            } else {
                alert('❌ Failed to delete email: ' + response.data);
            }
        });
    }
    
    function resendEmail(id) {
        alert('Resend functionality will be implemented');
    }
    
    function bulkDeleteEmails(ids) {
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_bulk_delete_email_logs',
            log_ids: ids,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                loadEmailLogs();
                alert('✅ ' + response.data.deleted + ' email logs deleted successfully');
            } else {
                alert('❌ Failed to delete emails: ' + response.data);
            }
        });
    }
    
    function bulkResendEmails(ids) {
        alert('Bulk resend functionality will be implemented');
    }
    
    // Modal functionality
    $('.modal-close').click(function() {
        $(this).closest('.modal').hide();
    });
    
    // Auto-refresh every 30 seconds
    setInterval(loadEmailLogs, 30000);
});
</script>

<style>
.azure-email-logs-dashboard {
    margin-top: 20px;
}

.email-stats-section {
    background: white;
    padding: 20px;
    margin-bottom: 20px;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.stats-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    text-align: center;
    border-left: 4px solid #007cba;
}

.stat-card.success {
    border-left-color: #00a32a;
}

.stat-card.error {
    border-left-color: #d63638;
}

.stat-number {
    font-size: 2.5em;
    font-weight: bold;
    color: #1e1e1e;
    line-height: 1;
}

.stat-label {
    margin-top: 8px;
    color: #666;
    font-size: 0.9em;
}

.email-methods-section {
    margin-bottom: 30px;
}

.methods-chart {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
}

.method-item {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
    padding: 8px;
    background: white;
    border-radius: 4px;
}

.method-name {
    flex: 0 0 200px;
    font-weight: bold;
}

.method-count {
    flex: 0 0 60px;
    text-align: center;
    font-weight: bold;
    color: #007cba;
}

.method-bar {
    flex: 1;
    height: 20px;
    background: #e0e0e0;
    border-radius: 10px;
    overflow: hidden;
    margin-left: 15px;
}

.method-fill {
    height: 100%;
    background: linear-gradient(90deg, #007cba, #005a87);
    border-radius: 10px;
}

.recent-errors-section {
    background: #fff2f2;
    padding: 15px;
    border-radius: 8px;
    border-left: 4px solid #d63638;
}

.errors-list {
    margin-top: 10px;
}

.error-item {
    background: white;
    padding: 12px;
    margin-bottom: 10px;
    border-radius: 4px;
    border-left: 2px solid #d63638;
}

.error-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 5px;
}

.error-time {
    color: #666;
    font-size: 0.9em;
}

.error-recipient {
    font-weight: bold;
    color: #007cba;
}

.error-subject {
    font-weight: bold;
    margin-bottom: 5px;
}

.error-message {
    color: #d63638;
    font-size: 0.9em;
}

.email-logs-controls-section {
    background: white;
    padding: 20px;
    margin-bottom: 20px;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.logs-controls {
    margin-top: 15px;
}

.filters-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 15px;
    flex-wrap: wrap;
}

.bulk-actions-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding-top: 15px;
    border-top: 1px solid #e0e0e0;
}

.email-filter {
    min-width: 120px;
}

.email-logs-table-section {
    background: white;
    padding: 20px;
    margin-bottom: 20px;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.email-logs-container {
    margin-top: 15px;
}

.logs-loading {
    text-align: center;
    padding: 40px;
    color: #666;
}

.logs-loading .spinner {
    margin-bottom: 10px;
}

#email-logs-table {
    margin-top: 0;
}

.column-timestamp {
    width: 140px;
}

.column-to,
.column-from {
    width: 200px;
    word-break: break-all;
}

.column-subject {
    width: 300px;
}

.column-method,
.column-status {
    width: 120px;
}

.column-source {
    width: 150px;
}

.column-actions {
    width: 150px;
}

.column-status.success {
    color: #00a32a;
}

.column-status.error {
    color: #d63638;
}

.view-email {
    color: #007cba;
    text-decoration: none;
}

.view-email:hover {
    text-decoration: underline;
}

.email-logging-settings-section {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.modal {
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background: white;
    border-radius: 8px;
    width: 90%;
    max-width: 600px;
    max-height: 90%;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid #e0e0e0;
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
    color: #666;
}

.modal-close:hover {
    color: #333;
}

.modal-body {
    padding: 20px;
    max-height: 70vh;
    overflow-y: auto;
}

/* Dark theme compatibility */
.wp-admin.admin-color-midnight .modal-content,
.wp-admin.admin-color-midnight .stat-card,
.wp-admin.admin-color-midnight .email-stats-section,
.wp-admin.admin-color-midnight .email-logs-controls-section,
.wp-admin.admin-color-midnight .email-logs-table-section,
.wp-admin.admin-color-midnight .email-logging-settings-section {
    background: #1e1e1e !important;
    color: #f0f0f0 !important;
}

.wp-admin.admin-color-midnight .method-item,
.wp-admin.admin-color-midnight .error-item {
    background: #2a2a2a !important;
    color: #f0f0f0 !important;
}

.wp-admin.admin-color-midnight .modal-header {
    border-bottom-color: #444 !important;
}

.wp-admin.admin-color-midnight .bulk-actions-row {
    border-top-color: #444 !important;
}
</style>

<?php
// Add settings page functionality
function get_log_level_class($line) {
    if (strpos($line, '[ERROR]') !== false) return 'error';
    if (strpos($line, '[WARNING]') !== false) return 'warning';
    if (strpos($line, '[DEBUG]') !== false) return 'debug';
    return 'info';
}
