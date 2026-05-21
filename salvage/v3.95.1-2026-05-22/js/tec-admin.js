/**
 * TEC Integration Admin JavaScript
 */

jQuery(document).ready(function($) {
    
    // ==========================================
    // TEC Calendar Authentication Handlers
    // ==========================================
    
    // Save TEC calendar email
    $('#save-tec-calendar-email').on('click', function() {
        var userEmail = $('#tec_calendar_user_email').val();
        var mailboxEmail = $('#tec_calendar_mailbox_email').val();
        var button = $(this);
        
        if (!userEmail) {
            alert('Please enter your M365 account email address');
            return;
        }
        
        if (!mailboxEmail) {
            alert('Please enter the shared mailbox email address');
            return;
        }
        
        button.prop('disabled', true).text('Saving...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'azure_save_tec_calendar_email',
                user_email: userEmail,
                mailbox_email: mailboxEmail,
                nonce: azureTecAdmin.nonce
            },
            success: function(response) {
                console.log('TEC Save Response:', response);
                
                button.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Settings');
                
                if (response.success === true) {
                    alert('Settings saved successfully! Please click "Authenticate Calendar" to authorize access.');
                    location.reload();
                } else {
                    var errorMsg = 'Unknown error';
                    if (typeof response.data === 'string') {
                        errorMsg = response.data;
                    } else if (response.data && response.data.message) {
                        errorMsg = response.data.message;
                    } else if (response.message) {
                        errorMsg = response.message;
                    }
                    console.error('Save failed:', errorMsg);
                    alert('Failed to save settings: ' + errorMsg);
                }
            },
            error: function(xhr, status, error) {
                button.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Settings');
                console.error('AJAX Error:', xhr.responseText);
                alert('AJAX request failed: ' + error + '\nCheck browser console for details.');
            }
        });
    });
    
    // TEC Calendar authentication
    $('#tec-calendar-auth, #tec-calendar-reauth').on('click', function() {
        var userEmail = $('#tec_calendar_user_email').val();
        var mailboxEmail = $('#tec_calendar_mailbox_email').val();
        var button = $(this);
        
        if (!userEmail) {
            alert('Please enter and save your M365 account email first');
            return;
        }
        
        if (!mailboxEmail) {
            alert('Please enter and save the shared mailbox email first');
            return;
        }
        
        button.prop('disabled', true).text('Generating authorization URL...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'azure_tec_calendar_authorize',
                user_email: userEmail,
                nonce: azureTecAdmin.nonce
            },
            success: function(response) {
                console.log('TEC Auth Response:', response);
                
                if (response.success && response.data.auth_url) {
                    // Redirect to Microsoft authorization page
                    window.location.href = response.data.auth_url;
                } else {
                    var errorMsg = 'Unknown error';
                    if (typeof response.data === 'string') {
                        errorMsg = response.data;
                    } else if (response.data && response.data.message) {
                        errorMsg = response.data.message;
                    } else if (response.message) {
                        errorMsg = response.message;
                    }
                    console.error('Auth failed:', errorMsg);
                    button.prop('disabled', false).text('Authenticate Calendar');
                    alert('Failed to generate authorization URL: ' + errorMsg);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', xhr.responseText);
                button.prop('disabled', false).text('Authenticate Calendar');
                alert('Failed to generate authorization URL due to a network error. Check console for details.');
            }
        });
    });
    
    // Refresh TEC calendars
    $('#refresh-tec-calendars').on('click', function() {
        location.reload();
    });
    
    // ==========================================
    // Calendar Mapping Handlers
    // ==========================================
    
    // Open calendar mapping modal
    $('#add-calendar-mapping').on('click', function() {
        // Reset form for adding new mapping
        $('#calendar-mapping-form')[0].reset();
        $('#mapping-id').val('');
        $('#new-category-name').val('');
        $('#schedule-frequency-row, #schedule-daterange-row').hide();
        
        // Reset button text
        $('#save-mapping-btn').html('<span class="dashicons dashicons-saved"></span> Save Mapping');
        
        $('#calendar-mapping-modal').fadeIn(200);
        $('body').addClass('modal-open');
        
        // Load available calendars when modal opens
        loadOutlookCalendars();
        
        // Load TEC categories
        loadTecCategories();
    });
    
    // Load Outlook calendars into the dropdown
    function loadOutlookCalendars(callback) {
        var $select = $('#outlook-calendar-select');
        $select.html('<option value="">Loading calendars...</option>').prop('disabled', true);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'azure_get_outlook_calendars_for_tec',
                nonce: azureTecAdmin.nonce
            },
            success: function(response) {
                console.log('Outlook Calendars Response:', response);
                
                if (response.success && response.data) {
                    var calendars = response.data;
                    
                    if (calendars.length === 0) {
                        $select.html('<option value="">No calendars found</option>');
                    } else {
                        $select.html('<option value="">Select a calendar...</option>');
                        calendars.forEach(function(calendar) {
                            $select.append(
                                $('<option></option>')
                                    .val(calendar.id)
                                    .text(calendar.name)
                                    .data('calendar-name', calendar.name)
                            );
                        });
                        $select.prop('disabled', false);
                    }
                    
                    // Call callback if provided
                    if (typeof callback === 'function') {
                        callback();
                    }
                } else {
                    var errorMsg = 'Unknown error';
                    if (typeof response.data === 'string') {
                        errorMsg = response.data;
                    } else if (response.data && response.data.message) {
                        errorMsg = response.data.message;
                    }
                    $select.html('<option value="">Failed to load calendars</option>');
                    console.error('Failed to load calendars:', errorMsg);
                }
            },
            error: function(xhr, status, error) {
                $select.html('<option value="">Error loading calendars</option>');
                console.error('AJAX Error loading calendars:', error);
            }
        });
    }
    
    // Load TEC categories into the dropdown
    function loadTecCategories(callback) {
        var $select = $('#tec-category-select');
        $select.html('<option value="">Loading categories...</option>').prop('disabled', true);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'azure_get_tec_categories',
                nonce: azureTecAdmin.nonce
            },
            success: function(response) {
                console.log('TEC Categories Response:', response);
                
                if (response.success && response.data) {
                    var categories = response.data;
                    
                    $select.html('<option value="">Select existing category...</option>');
                    
                    if (categories.length === 0) {
                        $select.append('<option value="" disabled>No categories found - enter new category name below</option>');
                    } else {
                        categories.forEach(function(category) {
                            $select.append(
                                $('<option></option>')
                                    .val(category.term_id)
                                    .text(category.name)
                                    .data('category-name', category.name)
                            );
                        });
                    }
                    $select.prop('disabled', false);
                    
                    // Call callback if provided
                    if (typeof callback === 'function') {
                        callback();
                    }
                } else {
                    var errorMsg = 'Unknown error';
                    if (typeof response.data === 'string') {
                        errorMsg = response.data;
                    } else if (response.data && response.data.message) {
                        errorMsg = response.data.message;
                    }
                    $select.html('<option value="">Failed to load categories</option>');
                    console.error('Failed to load TEC categories:', errorMsg);
                }
            },
            error: function(xhr, status, error) {
                $select.html('<option value="">Error loading categories</option>');
                console.error('AJAX Error loading TEC categories:', error);
            }
        });
    }
    
    // Close calendar mapping modal
    $('.modal-close, .modal-overlay, #cancel-mapping-btn').on('click', function() {
        $('#calendar-mapping-modal').fadeOut(200);
        $('body').removeClass('modal-open');
        
        // Clear form
        $('#calendar-mapping-form')[0].reset();
        $('#mapping-id').val('');
        $('#new-category-name').val('');
        $('#schedule-frequency-row, #schedule-daterange-row').hide();
        
        // Reset button text
        $('#save-mapping-btn').html('<span class="dashicons dashicons-saved"></span> Save Mapping');
    });
    
    // Toggle schedule fields visibility
    $('#schedule-enabled-checkbox').on('change', function() {
        if ($(this).is(':checked')) {
            $('#schedule-frequency-row, #schedule-daterange-row').show();
        } else {
            $('#schedule-frequency-row, #schedule-daterange-row').hide();
        }
    });
    
    // Refresh Outlook calendars
    $('#refresh-outlook-calendars').on('click', function() {
        var button = $(this);
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Refreshing...');
        
        // Use the same loading function, but with force refresh
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'azure_get_outlook_calendars_for_tec',
                nonce: azureTecAdmin.nonce
            },
            success: function(response) {
                button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Refresh Available Calendars');
                
                if (response.success) {
                    alert('Calendars refreshed successfully!');
                    // Reload the dropdown
                    loadOutlookCalendars();
                } else {
                    var errorMsg = 'Unknown error';
                    if (typeof response.data === 'string') {
                        errorMsg = response.data;
                    } else if (response.data && response.data.message) {
                        errorMsg = response.data.message;
                    }
                    alert('Failed to refresh calendars: ' + errorMsg);
                }
            },
            error: function(xhr, status, error) {
                button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Refresh Available Calendars');
                alert('Failed to refresh calendars: ' + error);
            }
        });
    });
    
    // Save calendar mapping (handle form submit)
    $('#calendar-mapping-form').on('submit', function(e) {
        e.preventDefault();
        
        var mappingId = $('#mapping-id').val() || 0; // Get mapping ID for editing
        var outlookCalendarId = $('#outlook-calendar-select').val();
        var outlookCalendarName = $('#outlook-calendar-select option:selected').text();
        var tecCategoryId = $('#tec-category-select').val();
        var tecCategoryName = $('#tec-category-select option:selected').text();
        var newCategoryName = $('#new-category-name').val().trim();
        var syncEnabled = $('#sync-enabled-checkbox').is(':checked') ? 1 : 0;
        var scheduleEnabled = $('#schedule-enabled-checkbox').is(':checked') ? 1 : 0;
        var scheduleFrequency = $('#schedule-frequency-select').val();
        var scheduleLookbackDays = parseInt($('#schedule-lookback-days').val());
        var scheduleLookaheadDays = parseInt($('#schedule-lookahead-days').val());
        var button = $('#save-mapping-btn');
        
        // Validate Outlook calendar selection
        if (!outlookCalendarId) {
            alert('Please select an Outlook calendar');
            return;
        }
        
        // Validate TEC category: must have EITHER dropdown selection OR new name, not both
        var hasExistingCategory = tecCategoryId && tecCategoryId !== '';
        var hasNewCategory = newCategoryName !== '';
        
        if (!hasExistingCategory && !hasNewCategory) {
            alert('Please select an existing TEC category OR enter a new category name');
            return;
        }
        
        if (hasExistingCategory && hasNewCategory) {
            alert('Please either select an existing category OR enter a new category name, not both');
            return;
        }
        
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Saving...');
        
        // If user entered a new category name, create it first
        if (hasNewCategory) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'azure_create_tec_category',
                    category_name: newCategoryName,
                    nonce: azureTecAdmin.nonce
                },
                success: function(response) {
                    console.log('Create Category Response:', response);
                    
                    if (response.success && response.data) {
                        // Category created (or already existed), now save the mapping
                        tecCategoryId = response.data.term_id;
                        tecCategoryName = response.data.name;
                        saveMapping(mappingId, outlookCalendarId, outlookCalendarName, tecCategoryId, tecCategoryName, syncEnabled, scheduleEnabled, scheduleFrequency, scheduleLookbackDays, scheduleLookaheadDays, button);
                    } else {
                        var errorMsg = 'Unknown error';
                        if (typeof response.data === 'string') {
                            errorMsg = response.data;
                        } else if (response.data && response.data.message) {
                            errorMsg = response.data.message;
                        }
                        alert('Failed to create category: ' + errorMsg);
                        button.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Mapping');
                    }
                },
                error: function(xhr, status, error) {
                    alert('Failed to create category: ' + error);
                    button.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Mapping');
                }
            });
        } else {
            // Use existing category
            saveMapping(mappingId, outlookCalendarId, outlookCalendarName, tecCategoryId, tecCategoryName, syncEnabled, scheduleEnabled, scheduleFrequency, scheduleLookbackDays, scheduleLookaheadDays, button);
        }
    });
    
    // Helper function to save the actual mapping
    function saveMapping(mappingId, outlookCalendarId, outlookCalendarName, tecCategoryId, tecCategoryName, syncEnabled, scheduleEnabled, scheduleFrequency, scheduleLookbackDays, scheduleLookaheadDays, button) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'azure_save_calendar_mapping',
                mapping_id: mappingId,
                outlook_calendar_id: outlookCalendarId,
                outlook_calendar_name: outlookCalendarName,
                tec_category_id: tecCategoryId,
                tec_category_name: tecCategoryName,
                sync_enabled: syncEnabled,
                schedule_enabled: scheduleEnabled,
                schedule_frequency: scheduleFrequency,
                schedule_lookback_days: scheduleLookbackDays,
                schedule_lookahead_days: scheduleLookaheadDays,
                nonce: azureTecAdmin.nonce
            },
            success: function(response) {
                console.log('Save Mapping Response:', response);
                
                if (response.success) {
                    var action = mappingId ? 'updated' : 'saved';
                    alert('Calendar mapping ' + action + ' successfully!');
                    $('#calendar-mapping-modal').fadeOut(200);
                    $('body').removeClass('modal-open');
                    
                    // Clear form
                    $('#calendar-mapping-form')[0].reset();
                    $('#mapping-id').val('');
                    $('#new-category-name').val('');
                    $('#schedule-frequency-row, #schedule-daterange-row').hide();
                    
                    // Reload page to show new/updated mapping
                    location.reload();
                } else {
                    var errorMsg = 'Unknown error';
                    if (typeof response.data === 'string') {
                        errorMsg = response.data;
                    } else if (response.data && response.data.message) {
                        errorMsg = response.data.message;
                    }
                    alert('Failed to save mapping: ' + errorMsg);
                    button.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Mapping');
                }
            },
            error: function(xhr, status, error) {
                alert('Failed to save mapping: ' + error);
                button.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Mapping');
            }
        });
    }
    
    // Delete calendar mapping
    $(document).on('click', '.delete-mapping', function() {
        var mappingId = $(this).data('mapping-id');
        
        if (!confirm('Are you sure you want to delete this calendar mapping?')) {
            return;
        }
        
        var button = $(this);
        button.prop('disabled', true).text('Deleting...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'azure_delete_calendar_mapping',
                mapping_id: mappingId,
                nonce: azureTecAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('Calendar mapping deleted successfully!');
                    location.reload();
                } else {
                    var errorMsg = 'Unknown error';
                    if (typeof response.data === 'string') {
                        errorMsg = response.data;
                    } else if (response.data && response.data.message) {
                        errorMsg = response.data.message;
                    }
                    alert('Failed to delete mapping: ' + errorMsg);
                    button.prop('disabled', false).text('Delete');
                }
            },
            error: function(xhr, status, error) {
                alert('Failed to delete mapping: ' + error);
                button.prop('disabled', false).text('Delete');
            }
        });
    });
    
    // Edit calendar mapping
    $(document).on('click', '.edit-mapping', function() {
        var mappingId = $(this).data('mapping-id');
        
        // Load mapping data via AJAX
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'azure_get_calendar_mapping',
                mapping_id: mappingId,
                nonce: azureTecAdmin.nonce
            },
            success: function(response) {
                console.log('Get Mapping Response:', response);
                
                if (response.success && response.data) {
                    var mapping = response.data;
                    
                    // Populate modal with mapping data
                    $('#mapping-id').val(mapping.id);
                    
                    // Load calendars and categories first
                    loadOutlookCalendars(function() {
                        $('#outlook-calendar-select').val(mapping.outlook_calendar_id);
                    });
                    
                    loadTecCategories(function() {
                        $('#tec-category-select').val(mapping.tec_category_id);
                    });
                    
                    $('#sync-enabled-checkbox').prop('checked', mapping.sync_enabled == 1);
                    $('#schedule-enabled-checkbox').prop('checked', mapping.schedule_enabled == 1);
                    $('#schedule-frequency-select').val(mapping.schedule_frequency || 'hourly');
                    $('#schedule-lookback-days').val(mapping.schedule_lookback_days || 30);
                    $('#schedule-lookahead-days').val(mapping.schedule_lookahead_days || 365);
                    
                    // Show/hide schedule fields based on schedule_enabled
                    if (mapping.schedule_enabled == 1) {
                        $('#schedule-frequency-row, #schedule-daterange-row').show();
                    } else {
                        $('#schedule-frequency-row, #schedule-daterange-row').hide();
                    }
                    
                    // Change button text to indicate editing
                    $('#save-mapping-btn').html('<span class="dashicons dashicons-saved"></span> Update Mapping');
                    
                    // Show modal
                    $('#calendar-mapping-modal').fadeIn(200);
                    $('body').addClass('modal-open');
                } else {
                    var errorMsg = 'Unknown error';
                    if (typeof response.data === 'string') {
                        errorMsg = response.data;
                    } else if (response.data && response.data.message) {
                        errorMsg = response.data.message;
                    }
                    alert('Failed to load mapping: ' + errorMsg);
                }
            },
            error: function(xhr, status, error) {
                alert('Failed to load mapping: ' + error);
            }
        });
    });
    
    // Toggle calendar mapping sync
    $(document).on('change', '.toggle-mapping-sync', function() {
        var mappingId = $(this).data('mapping-id');
        var enabled = $(this).is(':checked');
        var checkbox = $(this);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'azure_tec_toggle_mapping_sync',
                mapping_id: mappingId,
                enabled: enabled ? 1 : 0,
                nonce: azureTecAdmin.nonce
            },
            success: function(response) {
                if (!response.success) {
                    var errorMsg = 'Unknown error';
                    if (typeof response.data === 'string') {
                        errorMsg = response.data;
                    } else if (response.data && response.data.message) {
                        errorMsg = response.data.message;
                    }
                    alert('Failed to toggle sync: ' + errorMsg);
                    checkbox.prop('checked', !enabled); // Revert
                }
            },
            error: function(xhr, status, error) {
                alert('Failed to toggle sync: ' + error);
                checkbox.prop('checked', !enabled); // Revert
            }
        });
    });
    
    // Manual sync for calendar mapping
    $(document).on('click', '.sync-mapping-now', function() {
        var mappingId = $(this).data('mapping-id');
        var button = $(this);
        
        button.prop('disabled', true).text('Syncing...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'azure_tec_sync_calendar_mapping',
                mapping_id: mappingId,
                nonce: azureTecAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('Calendar sync completed successfully!');
                    location.reload();
                } else {
                    var errorMsg = 'Unknown error';
                    if (typeof response.data === 'string') {
                        errorMsg = response.data;
                    } else if (response.data && response.data.message) {
                        errorMsg = response.data.message;
                    }
                    alert('Sync failed: ' + errorMsg);
                    button.prop('disabled', false).text('Sync Now');
                }
            },
            error: function(xhr, status, error) {
                alert('Sync failed: ' + error);
                button.prop('disabled', false).text('Sync Now');
            }
        });
    });
    
    // ==========================================
    // Manual sync function for individual events
    // ==========================================
    window.azureTecManualSync = function(postId) {
        if (!postId) {
            alert('Invalid event ID');
            return;
        }
        
        // Show loading indicator
        var button = $('button[onclick="azureTecManualSync(' + postId + ')"]');
        var originalText = button.text();
        button.text('Syncing...').prop('disabled', true);
        
        $.ajax({
            url: azureTecAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'azure_tec_manual_sync',
                post_id: postId,
                nonce: azureTecAdmin.nonce
            },
            success: function(response) {
                button.text(originalText).prop('disabled', false);
                
                if (response.success) {
                    alert('Manual sync initiated successfully!');
                    
                    // Refresh the sync status
                    azureTecRefreshSyncStatus(postId);
                } else {
                    alert('Manual sync failed: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                button.text(originalText).prop('disabled', false);
                alert('Manual sync failed due to a network error: ' + error);
            }
        });
    };
    
    // Break sync function for individual events
    window.azureTecBreakSync = function(postId) {
        if (!postId) {
            alert('Invalid event ID');
            return;
        }
        
        if (!confirm('Are you sure you want to break the sync relationship for this event? This action cannot be undone.')) {
            return;
        }
        
        // Show loading indicator
        var button = $('button[onclick="azureTecBreakSync(' + postId + ')"]');
        var originalText = button.text();
        button.text('Breaking...').prop('disabled', true);
        
        $.ajax({
            url: azureTecAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'azure_tec_break_sync',
                post_id: postId,
                nonce: azureTecAdmin.nonce
            },
            success: function(response) {
                button.text(originalText).prop('disabled', false);
                
                if (response.success) {
                    alert('Sync relationship broken successfully!');
                    
                    // Refresh the page or update the metabox
                    location.reload();
                } else {
                    alert('Failed to break sync: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                button.text(originalText).prop('disabled', false);
                alert('Failed to break sync due to a network error: ' + error);
            }
        });
    };
    
    // Bulk sync function
    window.azureTecBulkSync = function(actionType) {
        if (!actionType) {
            alert('Invalid action type');
            return;
        }
        
        // Show progress indicator
        $('#azure-tec-sync-progress').show();
        $('.progress-bar-fill').css('width', '10%');
        
        // Disable buttons during sync
        $('.azure-tec-actions .button').prop('disabled', true);
        
        var actionText = actionType === 'sync_to_outlook' ? 'TEC to Outlook' : 'Outlook to TEC';
        
        $.ajax({
            url: azureTecAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'azure_tec_bulk_sync',
                action_type: actionType,
                nonce: azureTecAdmin.nonce
            },
            xhr: function() {
                var xhr = new window.XMLHttpRequest();
                
                // Simulate progress (since we don't have real progress feedback)
                var progress = 10;
                var progressInterval = setInterval(function() {
                    if (progress < 90) {
                        progress += Math.random() * 20;
                        $('.progress-bar-fill').css('width', Math.min(progress, 90) + '%');
                    }
                }, 500);
                
                xhr.addEventListener('load', function() {
                    clearInterval(progressInterval);
                    $('.progress-bar-fill').css('width', '100%');
                });
                
                return xhr;
            },
            success: function(response) {
                $('#azure-tec-sync-progress').hide();
                $('.azure-tec-actions .button').prop('disabled', false);
                
                if (response.success) {
                    alert('Bulk sync (' + actionText + ') completed successfully!');
                    
                    // Refresh statistics
                    azureTecRefreshStats();
                } else {
                    alert('Bulk sync failed: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                $('#azure-tec-sync-progress').hide();
                $('.azure-tec-actions .button').prop('disabled', false);
                alert('Bulk sync failed due to a network error: ' + error);
            }
        });
    };
    
    // Refresh statistics
    window.azureTecRefreshStats = function() {
        // For now, just reload the page
        // In a more advanced implementation, this would use AJAX
        location.reload();
    };
    
    // Refresh sync status for a specific event
    window.azureTecRefreshSyncStatus = function(postId) {
        if (!postId) {
            return;
        }
        
        $.ajax({
            url: azureTecAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'azure_tec_get_sync_status',
                post_id: postId,
                nonce: azureTecAdmin.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    // Update the metabox with new status
                    updateSyncStatusMetabox(postId, response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('Failed to refresh sync status:', error);
            }
        });
    };
    
    // Update sync status metabox
    function updateSyncStatusMetabox(postId, statusData) {
        var metabox = $('#azure-tec-sync-metabox');
        
        if (metabox.length === 0) {
            return;
        }
        
        // Update sync status
        var statusText = '';
        var statusColor = 'gray';
        
        switch (statusData.sync_status) {
            case 'synced':
                statusText = '✓ Synced';
                statusColor = 'green';
                break;
            case 'pending':
                statusText = '⏳ Pending';
                statusColor = 'orange';
                break;
            case 'error':
                statusText = '✗ Error';
                statusColor = 'red';
                break;
            default:
                statusText = 'Not synced';
                statusColor = 'gray';
                break;
        }
        
        metabox.find('p:first').html('<strong>Sync Status:</strong> <span style="color: ' + statusColor + ';">' + statusText + '</span>');
        
        // Update Outlook event ID if present
        if (statusData.outlook_event_id) {
            var outlookIdP = metabox.find('p:contains("Outlook Event ID")');
            if (outlookIdP.length === 0) {
                metabox.find('p:first').after('<p><strong>Outlook Event ID:</strong> ' + statusData.outlook_event_id + '</p>');
            } else {
                outlookIdP.html('<strong>Outlook Event ID:</strong> ' + statusData.outlook_event_id);
            }
        }
        
        // Update last sync time
        if (statusData.last_sync) {
            var lastSyncP = metabox.find('p:contains("Last Sync")');
            var lastSyncFormatted = new Date(statusData.last_sync).toLocaleString();
            
            if (lastSyncP.length === 0) {
                metabox.find('p:last').before('<p><strong>Last Sync:</strong> ' + lastSyncFormatted + '</p>');
            } else {
                lastSyncP.html('<strong>Last Sync:</strong> ' + lastSyncFormatted);
            }
        }
        
        // Update message if present
        if (statusData.sync_message) {
            var messageP = metabox.find('p:contains("Message")');
            
            if (messageP.length === 0) {
                metabox.find('.azure-tec-sync-actions').before('<p><strong>Message:</strong> ' + statusData.sync_message + '</p>');
            } else {
                messageP.html('<strong>Message:</strong> ' + statusData.sync_message);
            }
        }
    }
    
    // Auto-refresh sync status every 30 seconds if on event edit page
    if ($('#azure-tec-sync-metabox').length > 0) {
        var postId = $('#post_ID').val();
        
        if (postId) {
            setInterval(function() {
                azureTecRefreshSyncStatus(postId);
            }, 30000); // 30 seconds
        }
    }
    
    // Handle sync status column clicks in event list
    $(document).on('click', '.column-outlook_sync', function(e) {
        e.preventDefault();
        
        var row = $(this).closest('tr');
        var postId = row.find('.check-column input[type="checkbox"]').val();
        
        if (postId) {
            // Show a popup with sync details or trigger manual sync
            var syncStatus = $(this).find('span').attr('title');
            
            if (confirm('Current status: ' + syncStatus + '\n\nWould you like to trigger a manual sync for this event?')) {
                azureTecManualSync(postId);
            }
        }
    });
    
    // Add bulk actions to TEC events list
    if ($('body.post-type-tribe_events').length > 0) {
        // Add bulk sync options
        var bulkActions = $('#bulk-action-selector-top, #bulk-action-selector-bottom');
        
        bulkActions.append('<option value="azure_tec_sync_to_outlook">Sync to Outlook</option>');
        bulkActions.append('<option value="azure_tec_break_sync">Break Outlook Sync</option>');
        
        // Handle bulk action submission
        $('#doaction, #doaction2').click(function(e) {
            var action = $(this).siblings('select').val();
            
            if (action === 'azure_tec_sync_to_outlook' || action === 'azure_tec_break_sync') {
                e.preventDefault();
                
                var checkedPosts = $('tbody th.check-column input[type="checkbox"]:checked');
                
                if (checkedPosts.length === 0) {
                    alert('Please select at least one event.');
                    return;
                }
                
                var postIds = [];
                checkedPosts.each(function() {
                    postIds.push($(this).val());
                });
                
                var actionText = action === 'azure_tec_sync_to_outlook' ? 'sync to Outlook' : 'break sync relationship';
                
                if (confirm('Are you sure you want to ' + actionText + ' for ' + postIds.length + ' selected event(s)?')) {
                    azureTecBulkAction(action, postIds);
                }
            }
        });
    }
    
    // Bulk action handler
    function azureTecBulkAction(action, postIds) {
        if (!postIds || postIds.length === 0) {
            alert('No events selected');
            return;
        }
        
        // Show progress
        var progressHtml = '<div id="azure-tec-bulk-progress" style="margin: 20px 0;"><p>Processing ' + postIds.length + ' events...</p><div class="progress-bar"><div class="progress-bar-fill"></div></div></div>';
        $('.wrap h1').after(progressHtml);
        
        var completed = 0;
        var errors = 0;
        
        // Process each post ID
        postIds.forEach(function(postId, index) {
            setTimeout(function() {
                var ajaxAction = action === 'azure_tec_sync_to_outlook' ? 'azure_tec_manual_sync' : 'azure_tec_break_sync';
                
                $.ajax({
                    url: azureTecAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: ajaxAction,
                        post_id: postId,
                        nonce: azureTecAdmin.nonce
                    },
                    success: function(response) {
                        completed++;
                        
                        if (!response.success) {
                            errors++;
                        }
                        
                        // Update progress
                        var progress = (completed / postIds.length) * 100;
                        $('.progress-bar-fill').css('width', progress + '%');
                        
                        // Check if all completed
                        if (completed === postIds.length) {
                            $('#azure-tec-bulk-progress').remove();
                            
                            var message = 'Bulk action completed!\n';
                            message += 'Processed: ' + completed + ' events\n';
                            
                            if (errors > 0) {
                                message += 'Errors: ' + errors + ' events';
                            }
                            
                            alert(message);
                            location.reload();
                        }
                    },
                    error: function() {
                        completed++;
                        errors++;
                        
                        // Update progress
                        var progress = (completed / postIds.length) * 100;
                        $('.progress-bar-fill').css('width', progress + '%');
                        
                        // Check if all completed
                        if (completed === postIds.length) {
                            $('#azure-tec-bulk-progress').remove();
                            alert('Bulk action completed with ' + errors + ' errors.');
                            location.reload();
                        }
                    }
                });
            }, index * 500); // Stagger requests to avoid overwhelming the server
        });
    }
    
    // ==========================================
    // NOTE: Global schedule settings have been replaced with per-mapping schedules
    // Schedule settings are now configured when creating/editing calendar mappings
    // ==========================================
    
    // ==========================================
    // Manual Sync Now Handler (Step 2 Calendar Mapping + Step 3 Manual Sync)
    // ==========================================
    function runManualSyncNow(button) {
        if (!confirm('This will sync all enabled calendar mappings now. This may take a few minutes. Continue?')) {
            return;
        }
        var originalHtml = button.html();
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Syncing...');
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'azure_tec_manual_sync',
                nonce: azureTecAdmin.nonce
            },
            success: function(response) {
                button.prop('disabled', false).html(originalHtml);
                if (response.success) {
                    var data = response.data;
                    var message = 'Sync completed successfully!\n\n';
                    message += 'Calendars synced: ' + data.calendars_synced + '\n';
                    message += 'Total events synced: ' + data.total_events_synced + '\n';
                    message += 'Errors: ' + data.total_errors;
                    alert(message);
                    location.reload();
                } else {
                    var errorMsg = typeof response.data === 'string' ? response.data : (response.data && response.data.message) ? response.data.message : 'Unknown error';
                    alert('Sync failed: ' + errorMsg);
                }
            },
            error: function(xhr, status, error) {
                button.prop('disabled', false).html(originalHtml);
                try {
                    var errorResponse = JSON.parse(xhr.responseText);
                    alert('Sync failed: ' + (errorResponse && errorResponse.data ? errorResponse.data : error));
                } catch (e) {
                    alert('Sync failed: ' + error + ' (Status: ' + status + ')');
                }
            }
        });
    }
    $('#tec-manual-sync-btn').on('click', function(e) {
        e.preventDefault();
        runManualSyncNow($(this));
    });
    $('#tec-manual-sync-now-mapping').on('click', function(e) {
        e.preventDefault();
        runManualSyncNow($(this));
    });
    
    // ==========================================
    // Repair Event Metadata Handler
    // ==========================================
    $('#tec-repair-metadata-btn').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        
        if (!confirm('This will add missing timezone and UTC date metadata to all synced events.\n\nThis can fix issues where events don\'t appear when filtering by category.\n\nContinue?')) {
            return;
        }
        
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Repairing...');
        
        console.log('Starting metadata repair...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'azure_tec_repair_event_metadata',
                nonce: azureTecAdmin.nonce
            },
            success: function(response) {
                console.log('Repair Response:', response);
                button.prop('disabled', false).html('<span class="dashicons dashicons-admin-tools"></span> Repair Event Metadata');
                
                if (response.success) {
                    var data = response.data;
                    var message = 'Repair completed!\n\n';
                    message += data.message + '\n';
                    if (data.errors > 0) {
                        message += 'Errors: ' + data.errors;
                    }
                    
                    alert(message);
                } else {
                    var errorMsg = 'Unknown error';
                    if (typeof response.data === 'string') {
                        errorMsg = response.data;
                    } else if (response.data && response.data.message) {
                        errorMsg = response.data.message;
                    }
                    alert('Repair failed: ' + errorMsg);
                }
            },
            error: function(xhr, status, error) {
                console.error('Repair AJAX Error:', xhr, status, error);
                button.prop('disabled', false).html('<span class="dashicons dashicons-admin-tools"></span> Repair Event Metadata');
                alert('Repair failed: ' + error);
            }
        });
    });

    // Load Recent Sync History when section is present
    if ($('#sync-history-list').length) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'azure_get_sync_history',
                nonce: azureTecAdmin.nonce
            },
            success: function(response) {
                var tbody = $('#sync-history-list');
                if (!response.success || !response.data || response.data.length === 0) {
                    tbody.html('<tr><td colspan="5" style="text-align: center; padding: 20px;"><em style="color: #666;">No sync history yet.</em></td></tr>');
                    return;
                }
                var rows = response.data.map(function(row) {
                    var statusClass = row.status === 'failed' ? 'status-failed' : 'status-success';
                    return '<tr><td>' + (row.timestamp || '—') + '</td><td>' + (row.type || '—') + '</td><td>' + (row.calendars || '—') + '</td><td>' + (row.events_count || 0) + '</td><td><span class="status-badge ' + statusClass + '">' + (row.status || '—') + '</span></td></tr>';
                });
                tbody.html(rows.join(''));
            },
            error: function() {
                $('#sync-history-list').html('<tr><td colspan="5" style="text-align: center; padding: 20px;"><em style="color: #999;">Could not load sync history.</em></td></tr>');
            }
        });
    }
});