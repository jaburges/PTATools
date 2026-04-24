/**
 * Azure Plugin Admin JavaScript
 */

// Wait for WordPress and jQuery to be fully loaded
jQuery(document).ready(function($) {
    'use strict';
    
    console.log('Azure Plugin Admin: jQuery loaded, initializing...');
    
    // Double-check jQuery is available
    if (typeof $ === 'undefined' || typeof $.ajax === 'undefined') {
        console.error('Azure Plugin Admin: jQuery not available, retrying...');
        setTimeout(function() {
            if (typeof jQuery !== 'undefined') {
                jQuery(document).ready(function($) {
                    initAzurePluginAdmin($);
                });
            }
        }, 500);
        return;
    }
    
    // Initialize immediately
    initAzurePluginAdmin($);
});

// Main initialization function
function initAzurePluginAdmin($) {

    var AzurePluginAdmin = {
        initialized: false,
        
        init: function() {
            if (this.initialized) {
                console.log('Azure Plugin Admin: Already initialized, skipping');
                return;
            }
            
            console.log('Azure Plugin Admin: Initializing...');
            this.bindEvents();
            this.initTooltips();
            this.checkModuleStatus();
            this.initialized = true;
            console.log('Azure Plugin Admin: Initialization complete');
        },
        
        bindEvents: function() {
            // Ensure jQuery is available
            var $ = window.jQuery || window.$;
            if (!$ || typeof $.fn === 'undefined') {
                console.error('Azure Plugin: jQuery not available in bindEvents, retrying...');
                var self = this;
                setTimeout(function() {
                    self.bindEvents();
                }, 500);
                return;
            }
            
            var self = this;
            
            console.log('Azure Plugin Admin: Binding events...');
            
            // Handle common credentials toggle
            $(document).on('change', '#use_common_credentials', function() {
                console.log('Common credentials toggle changed');
                self.toggleCommonCredentials($(this).is(':checked'));
            });
            
            // Handle module toggles
            $(document).on('change', '.module-toggle', function() {
                var $toggle = $(this);
                var module = $toggle.data('module');
                var enabled = $toggle.is(':checked');
                var card = $toggle.closest('.module-card');
                
                console.log('Module toggle changed:', module, enabled);
                self.toggleModule(module, enabled, card, $toggle);
            });
        
        // Handle credentials test
        $(document).on('click', '.test-credentials', function() {
            var button = $(this);
            var clientIdField = $('#' + button.data('client-id-field'));
            var clientSecretField = $('#' + button.data('client-secret-field'));
            var tenantIdField = $('#' + button.data('tenant-id-field'));
            
            self.testCredentials(button, clientIdField, clientSecretField, tenantIdField);
        });
        
        // Handle backup actions
        $(document).on('click', '.start-backup', function() {
            self.startBackup($(this));
        });
        
        $(document).on('click', '.restore-backup', function() {
            self.restoreBackup($(this));
        });
        
        // Handle calendar actions
        $(document).on('click', '.sync-calendar', function() {
            self.syncCalendar($(this));
        });
        
        $(document).on('click', '.test-calendar-connection', function() {
            self.testCalendarConnection($(this));
        });
        
        // Handle email actions
        $(document).on('click', '.send-test-email', function() {
            self.sendTestEmail($(this));
        });
        
        $(document).on('click', '.clear-email-queue', function() {
            self.clearEmailQueue($(this));
        });
        
        // Handle log actions
        $(document).on('click', '.clear-logs', function() {
            self.clearLogs($(this));
        });
        
        $(document).on('click', '.refresh-logs', function() {
            self.refreshLogs();
        });
        
        // Auto-refresh certain sections
        if ($('.backup-jobs-table').length) {
            setInterval(function() {
                self.refreshBackupJobs();
            }, 10000); // Refresh every 10 seconds
        }
        
        if ($('.email-queue-table').length) {
            setInterval(function() {
                self.refreshEmailQueue();
            }, 15000); // Refresh every 15 seconds
        }
    },
    
    initTooltips: function() {
        // Add tooltips to help icons
        $(document).on('mouseenter', '[data-tooltip]', function() {
            var tooltip = $('<div class="azure-tooltip">' + $(this).data('tooltip') + '</div>');
            $('body').append(tooltip);
            
            var offset = $(this).offset();
            tooltip.css({
                top: offset.top - tooltip.outerHeight() - 10,
                left: offset.left + ($(this).outerWidth() / 2) - (tooltip.outerWidth() / 2)
            });
        });
        
        $(document).on('mouseleave', '[data-tooltip]', function() {
            $('.azure-tooltip').remove();
        });
    },
    
    checkModuleStatus: function() {
        // Check if modules are properly configured
        var modules = ['sso', 'backup', 'calendar', 'email'];
        
        modules.forEach(function(module) {
            var card = $('.module-card').has('[data-module="' + module + '"]');
            if (card.length) {
                // Add configuration status indicator
                // This would be extended to actually check configuration
            }
        });
    },
    
    toggleCommonCredentials: function(useCommon) {
        if (useCommon) {
            $('#common-credentials').slideDown();
            $('.module-specific-credentials').slideUp();
        } else {
            $('#common-credentials').slideUp();
            $('.module-specific-credentials').slideDown();
        }
    },
    
    toggleModule: function(module, enabled, card, toggleEl) {
        var self = this;
        var isSubToggle = toggleEl && toggleEl.closest('.sub-module-item').length > 0;
        
        // Debug logging
        console.log('Toggle module:', module, 'enabled:', enabled, 'sub:', isSubToggle);
        
        // Check if ajax object exists
        if (typeof azure_plugin_ajax === 'undefined') {
            console.error('azure_plugin_ajax is not defined');
            self.showNotification('error', 'Ajax configuration error');
            if (toggleEl) { toggleEl.prop('checked', !enabled); }
            return;
        }
        
        $.ajax({
            url: azure_plugin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'azure_toggle_module',
                module: module,
                enabled: enabled,
                nonce: azure_plugin_ajax.nonce
            },
            beforeSend: function() {
                console.log('Sending AJAX request for module toggle:', module);
            },
            success: function(response) {
                console.log('AJAX response:', response);
                if (response && response.success) {
                    if (!isSubToggle && card && card.length) {
                        if (enabled) {
                            card.removeClass('disabled').addClass('enabled');
                        } else {
                            card.removeClass('enabled').addClass('disabled');
                        }
                    }
                    // Update the toggle-status label if present (used on module pages)
                    if (toggleEl) {
                        var statusLabel = toggleEl.closest('.module-control').find('.toggle-status, .module-status');
                        if (statusLabel.length) {
                            statusLabel.text(enabled ? 'Enabled' : 'Disabled');
                        }
                    }
                    self.showNotification(enabled ? 'success' : 'info',
                        module.charAt(0).toUpperCase() + module.slice(1).replace(/_/g, ' ') +
                        (enabled ? ' enabled' : ' disabled'));
                } else {
                    console.error('Toggle failed:', response);
                    self.showNotification('error', 'Failed to toggle module: ' + (response.data || 'Unknown error'));
                    if (toggleEl) { toggleEl.prop('checked', !enabled); }
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', status, error, xhr);
                self.showNotification('error', 'Network error occurred: ' + error);
                if (toggleEl) { toggleEl.prop('checked', !enabled); }
            }
        });
    },
    
    testCredentials: function(button, clientIdField, clientSecretField, tenantIdField) {
        var self = this;
        var status = button.siblings('.credentials-status');
        
        var clientId = clientIdField.val();
        var clientSecret = clientSecretField.val();
        var tenantId = tenantIdField.val();
        
        if (!clientId || !clientSecret) {
            self.showNotification('warning', 'Please enter Client ID and Client Secret');
            return;
        }
        
        button.prop('disabled', true).text('Testing...');
        status.html('<span class="spinner is-active"></span> Testing credentials...');
        
        $.ajax({
            url: azure_plugin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'azure_test_credentials',
                client_id: clientId,
                client_secret: clientSecret,
                tenant_id: tenantId,
                nonce: azure_plugin_ajax.nonce
            },
            success: function(response) {
                button.prop('disabled', false).text('Test Credentials');
                
                if (response.valid) {
                    status.html('<span class="dashicons dashicons-yes-alt" style="color: green;"></span> ' + response.message);
                    self.showNotification('success', 'Credentials are valid!');
                } else {
                    status.html('<span class="dashicons dashicons-dismiss" style="color: red;"></span> ' + response.message);
                    self.showNotification('error', 'Credentials validation failed: ' + response.message);
                }
                
                setTimeout(function() {
                    status.fadeOut();
                }, 8000);
            },
            error: function() {
                button.prop('disabled', false).text('Test Credentials');
                status.html('<span class="dashicons dashicons-dismiss" style="color: red;"></span> Network error');
                self.showNotification('error', 'Network error occurred while testing credentials');
            }
        });
    },
    
    startBackup: function(button) {
        var self = this;
        
        if (!confirm('Are you sure you want to start a backup? This may take several minutes.')) {
            return;
        }
        
        button.prop('disabled', true).text('Starting...');
        
        $.ajax({
            url: azure_plugin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'azure_start_backup',
                nonce: azure_plugin_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    self.showNotification('success', 'Backup started successfully');
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    button.prop('disabled', false).text('Start Backup');
                    self.showNotification('error', response.data || 'Failed to start backup');
                }
            },
            error: function() {
                button.prop('disabled', false).text('Start Backup');
                self.showNotification('error', 'Network error occurred');
            }
        });
    },
    
    restoreBackup: function(button) {
        var backupId = button.data('backup-id');
        var self = this;
        
        if (!confirm('Are you sure you want to restore this backup? This will overwrite your current content.')) {
            return;
        }
        
        button.prop('disabled', true).text('Restoring...');
        
        $.ajax({
            url: azure_plugin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'azure_restore_backup',
                backup_id: backupId,
                nonce: azure_plugin_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    self.showNotification('success', 'Restore started successfully');
                } else {
                    button.prop('disabled', false).text('Restore');
                    self.showNotification('error', response.data || 'Failed to start restore');
                }
            },
            error: function() {
                button.prop('disabled', false).text('Restore');
                self.showNotification('error', 'Network error occurred');
            }
        });
    },
    
    syncCalendar: function(button) {
        var calendarId = button.data('calendar-id');
        var self = this;
        
        button.prop('disabled', true).text('Syncing...');
        
        $.ajax({
            url: azure_plugin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'azure_sync_calendar',
                calendar_id: calendarId,
                nonce: azure_plugin_ajax.nonce
            },
            success: function(response) {
                button.prop('disabled', false).text('Sync Calendar');
                
                if (response.success) {
                    self.showNotification('success', 'Calendar synced successfully');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    self.showNotification('error', response.data || 'Failed to sync calendar');
                }
            },
            error: function() {
                button.prop('disabled', false).text('Sync Calendar');
                self.showNotification('error', 'Network error occurred');
            }
        });
    },
    
    testCalendarConnection: function(button) {
        var self = this;
        
        button.prop('disabled', true).text('Testing...');
        
        $.ajax({
            url: azure_plugin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'azure_test_calendar_connection',
                nonce: azure_plugin_ajax.nonce
            },
            success: function(response) {
                button.prop('disabled', false).text('Test Connection');
                
                if (response.success) {
                    self.showNotification('success', 'Calendar connection successful');
                } else {
                    self.showNotification('error', response.data || 'Calendar connection failed');
                }
            },
            error: function() {
                button.prop('disabled', false).text('Test Connection');
                self.showNotification('error', 'Network error occurred');
            }
        });
    },
    
    sendTestEmail: function(button) {
        var testEmail = $('#test_email_address').val();
        var self = this;
        
        if (!testEmail) {
            self.showNotification('warning', 'Please enter a test email address');
            return;
        }
        
        button.prop('disabled', true).text('Sending...');
        
        $.ajax({
            url: azure_plugin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'azure_send_test_email',
                test_email: testEmail,
                nonce: azure_plugin_ajax.nonce
            },
            success: function(response) {
                button.prop('disabled', false).text('Send Test Email');
                
                if (response.success) {
                    self.showNotification('success', 'Test email sent successfully');
                } else {
                    self.showNotification('error', response.data || 'Failed to send test email');
                }
            },
            error: function() {
                button.prop('disabled', false).text('Send Test Email');
                self.showNotification('error', 'Network error occurred');
            }
        });
    },
    
    clearEmailQueue: function(button) {
        var self = this;
        
        if (!confirm('Are you sure you want to clear the email queue? This cannot be undone.')) {
            return;
        }
        
        button.prop('disabled', true).text('Clearing...');
        
        $.ajax({
            url: azure_plugin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'azure_clear_email_queue',
                nonce: azure_plugin_ajax.nonce
            },
            success: function(response) {
                button.prop('disabled', false).text('Clear Queue');
                
                if (response.success) {
                    self.showNotification('success', 'Email queue cleared');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    self.showNotification('error', 'Failed to clear email queue');
                }
            },
            error: function() {
                button.prop('disabled', false).text('Clear Queue');
                self.showNotification('error', 'Network error occurred');
            }
        });
    },
    
    clearLogs: function(button) {
        var self = this;
        
        if (!confirm('Are you sure you want to clear all logs? This cannot be undone.')) {
            return;
        }
        
        button.prop('disabled', true).text('Clearing...');
        
        $.ajax({
            url: azure_plugin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'azure_clear_logs',
                nonce: azure_plugin_ajax.nonce
            },
            success: function(response) {
                button.prop('disabled', false).text('Clear Logs');
                
                if (response.success) {
                    $('.log-viewer').empty();
                    self.showNotification('success', 'Logs cleared successfully');
                } else {
                    self.showNotification('error', 'Failed to clear logs');
                }
            },
            error: function() {
                button.prop('disabled', false).text('Clear Logs');
                self.showNotification('error', 'Network error occurred');
            }
        });
    },
    
    refreshLogs: function() {
        location.reload();
    },
    
    refreshBackupJobs: function() {
        var table = $('.backup-jobs-table tbody');
        if (!table.length) return;
        
        $.ajax({
            url: azure_plugin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'azure_get_backup_jobs',
                nonce: azure_plugin_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    table.html(response.data);
                }
            }
        });
    },
    
    refreshEmailQueue: function() {
        // Ensure jQuery is available
        var $ = window.jQuery || window.$;
        if (!$ || typeof $.ajax === 'undefined') {
            console.error('Azure Plugin: jQuery not available in refreshEmailQueue');
            return;
        }
        
        var table = $('.email-queue-table tbody');
        if (!table.length) return;
        
        $.ajax({
            url: azure_plugin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'azure_get_email_queue',
                nonce: azure_plugin_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    table.html(response.data);
                }
            }
        });
    },
    
    showNotification: function(type, message) {
        var notification = $('<div class="azure-notification ' + type + '">' + message + '</div>');
        $('body').append(notification);
        
        setTimeout(function() {
            notification.fadeOut(function() {
                notification.remove();
            });
        }, 5000);
    },
    
    // Utility functions
    formatFileSize: function(bytes) {
        if (bytes === 0) return '0 Bytes';
        
        var k = 1024;
        var sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    },
    
    formatDateTime: function(dateString) {
        var date = new Date(dateString);
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
    }
    };

    // CSS for tooltips
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            .azure-tooltip {
                position: absolute;
                background: #333;
                color: white;
                padding: 8px 12px;
                border-radius: 4px;
                font-size: 12px;
                z-index: 9999;
                max-width: 250px;
                word-wrap: break-word;
            }
            
            .azure-tooltip:after {
                content: '';
                position: absolute;
                top: 100%;
                left: 50%;
                margin-left: -5px;
                border: 5px solid transparent;
                border-top-color: #333;
            }
        `)
        .appendTo('head');

    // Initialize the admin functionality
    AzurePluginAdmin.init();
}
