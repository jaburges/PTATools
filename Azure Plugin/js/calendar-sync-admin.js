/**
 * Calendar Sync admin tab interactions.
 *
 * Wires the Calendar Sync admin page (admin/calendar-sync-page.php) to
 * the AJAX endpoints in class-calendar-sync-ajax.php: mapping CRUD,
 * Sync Now, repair metadata, sync history refresh.
 *
 * Adapted from the v3.97-retired js/tec-admin.js, but uses the new
 * `azure_*_calendar_*` action names, drops the TEC OAuth flow (now
 * lives on the Config screen), and references `pta_event_category`
 * instead of `tribe_events_cat`.
 *
 * Requires window.azureCalendarSync = { nonce, ajaxUrl } to be
 * localized by class-admin.php before the script runs.
 *
 * @since 3.113
 */
(function ($) {
    'use strict';

    var ctx = window.azureCalendarSync || {};
    var ajaxUrl = ctx.ajaxUrl || (window.ajaxurl || '/wp-admin/admin-ajax.php');
    var nonce = ctx.nonce || '';

    function post(action, payload) {
        return $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: $.extend({ action: action, nonce: nonce }, payload || {})
        });
    }

    function errorText(response, fallback) {
        if (!response) return fallback || 'Unknown error';
        if (typeof response.data === 'string') return response.data;
        if (response.data && response.data.message) return response.data.message;
        return fallback || 'Unknown error';
    }

    // -----------------------------------------------------------------
    // Modal open / reset
    // -----------------------------------------------------------------

    function resetMappingModal() {
        var form = $('#calendar-mapping-form');
        if (form.length) {
            form[0].reset();
        }
        $('#mapping-id').val('');
        $('#new-category-name').val('');
        $('#schedule-frequency-row, #schedule-daterange-row').hide();
        $('#mapping-modal-title').text('Add Calendar Mapping');
        $('#save-mapping-btn').prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Mapping');
    }

    function openMappingModal() {
        $('#calendar-mapping-modal').fadeIn(150);
        $('body').addClass('modal-open');
    }

    function closeMappingModal() {
        $('#calendar-mapping-modal').fadeOut(150);
        $('body').removeClass('modal-open');
        resetMappingModal();
    }

    // -----------------------------------------------------------------
    // Dropdown population
    // -----------------------------------------------------------------

    function loadOutlookCalendars() {
        var select = $('#outlook-calendar-select');
        select.html('<option value="">Loading calendars...</option>');
        return post('azure_get_outlook_calendars_for_sync').done(function (response) {
            if (!response.success) {
                select.html('<option value="">' + errorText(response, 'Failed to load calendars') + '</option>');
                return;
            }
            select.html('<option value="">— Select Outlook calendar —</option>');
            (response.data || []).forEach(function (cal) {
                $('<option/>').val(cal.id).text(cal.name).appendTo(select);
            });
        }).fail(function () {
            select.html('<option value="">Failed to load calendars</option>');
        });
    }

    function loadPtaCategories() {
        var select = $('#pta-category-select');
        select.html('<option value="">Loading categories...</option>');
        return post('azure_get_pta_event_categories').done(function (response) {
            if (!response.success) {
                select.html('<option value="">' + errorText(response, 'Failed to load categories') + '</option>');
                return;
            }
            select.html('<option value="">— Select existing category —</option>');
            (response.data || []).forEach(function (term) {
                $('<option/>').val(term.term_id).text(term.name).appendTo(select);
            });
        }).fail(function () {
            select.html('<option value="">Failed to load categories</option>');
        });
    }

    // -----------------------------------------------------------------
    // Mapping save (create or update)
    // -----------------------------------------------------------------

    function saveMapping(payload, $button) {
        post('azure_save_calendar_mapping', payload).done(function (response) {
            if (response && response.success) {
                closeMappingModal();
                window.location.reload();
                return;
            }
            alert('Failed to save mapping: ' + errorText(response));
            $button.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Mapping');
        }).fail(function (xhr, status, error) {
            alert('Failed to save mapping: ' + (error || 'network error'));
            $button.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Mapping');
        });
    }

    // -----------------------------------------------------------------
    // Sync run
    // -----------------------------------------------------------------

    function runManualSync($button) {
        var originalLabel = $button.html();
        $button.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span> Syncing...');
        $('#sync-progress').show();
        $('#sync-status-message').text('Syncing enabled calendars from Outlook...');

        post('azure_calendar_manual_sync').done(function (response) {
            if (response && response.success) {
                var d = response.data || {};
                var msg = 'Synced ' + (d.total_events_synced || 0) + ' event(s) across ' +
                          (d.calendars_synced || 0) + ' calendar(s)';
                if (d.total_errors) {
                    msg += ' (' + d.total_errors + ' error' + (d.total_errors === 1 ? '' : 's') + ')';
                }
                $('#sync-status-message').text(msg);
                refreshSyncHistory();
                // Refresh stats by reloading after a brief delay.
                setTimeout(function () { window.location.reload(); }, 1200);
            } else {
                $('#sync-status-message').text('Sync failed: ' + errorText(response));
            }
        }).fail(function (xhr, status, error) {
            $('#sync-status-message').text('Sync failed: ' + (error || 'network error'));
        }).always(function () {
            $button.prop('disabled', false).html(originalLabel);
        });
    }

    function runRepair($button) {
        var originalLabel = $button.html();
        $button.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span> Repairing...');

        post('azure_calendar_repair_event_metadata').done(function (response) {
            if (response && response.success) {
                var d = response.data || {};
                alert(d.message || ('Repaired ' + (d.repaired || 0) + ' event(s)'));
            } else {
                alert('Repair failed: ' + errorText(response));
            }
        }).fail(function () {
            alert('Repair failed (network error).');
        }).always(function () {
            $button.prop('disabled', false).html(originalLabel);
        });
    }

    function refreshSyncHistory() {
        var $tbody = $('#sync-history-list');
        if (!$tbody.length) return;

        $tbody.html('<tr><td colspan="5" style="text-align:center; padding:20px;"><em style="color:#666;">Loading sync history...</em></td></tr>');

        post('azure_get_calendar_sync_history').done(function (response) {
            if (!response || !response.success) {
                $tbody.html('<tr><td colspan="5" style="text-align:center; padding:20px;"><em style="color:#999;">' +
                            errorText(response, 'Failed to load history') + '</em></td></tr>');
                return;
            }
            var rows = response.data || [];
            if (!rows.length) {
                $tbody.html('<tr><td colspan="5" style="text-align:center; padding:20px;"><em style="color:#999;">No sync history yet.</em></td></tr>');
                return;
            }
            $tbody.empty();
            rows.forEach(function (row) {
                var statusBadge = row.status === 'success'
                    ? '<span class="azure-status-success">Success</span>'
                    : '<span class="azure-status-failed">Failed</span>';
                var tr = '<tr>' +
                    '<td>' + escapeHtml(row.timestamp) + '</td>' +
                    '<td>' + escapeHtml(row.type) + '</td>' +
                    '<td>' + escapeHtml(row.calendars) + '</td>' +
                    '<td>' + (parseInt(row.events_count, 10) || 0) + '</td>' +
                    '<td>' + statusBadge + '</td>' +
                    '</tr>';
                $tbody.append(tr);
            });
        }).fail(function () {
            $tbody.html('<tr><td colspan="5" style="text-align:center; padding:20px;"><em style="color:#999;">Failed to load history.</em></td></tr>');
        });
    }

    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // -----------------------------------------------------------------
    // Event bindings
    // -----------------------------------------------------------------

    $(function () {
        // Add mapping button
        $(document).on('click', '#add-calendar-mapping', function () {
            resetMappingModal();
            loadOutlookCalendars();
            loadPtaCategories();
            openMappingModal();
        });

        // Edit mapping button (in row)
        $(document).on('click', '.edit-mapping', function () {
            var mappingId = $(this).data('mapping-id');
            if (!mappingId) return;

            resetMappingModal();
            $('#mapping-id').val(mappingId);
            $('#mapping-modal-title').text('Edit Calendar Mapping');
            $('#save-mapping-btn').html('<span class="dashicons dashicons-saved"></span> Update Mapping');
            openMappingModal();

            $.when(loadOutlookCalendars(), loadPtaCategories()).done(function () {
                post('azure_get_calendar_mapping', { mapping_id: mappingId }).done(function (response) {
                    if (!response || !response.success) {
                        alert('Failed to load mapping: ' + errorText(response));
                        closeMappingModal();
                        return;
                    }
                    var m = response.data || {};
                    $('#outlook-calendar-select').val(m.outlook_calendar_id || '');
                    $('#pta-category-select').val(m.category_id || '');
                    $('#sync-enabled-checkbox').prop('checked', parseInt(m.sync_enabled, 10) === 1);
                    $('#schedule-enabled-checkbox').prop('checked', parseInt(m.schedule_enabled, 10) === 1);
                    $('#schedule-frequency-select').val(m.schedule_frequency || 'hourly');
                    $('#schedule-lookback-days').val(m.schedule_lookback_days || 30);
                    $('#schedule-lookahead-days').val(m.schedule_lookahead_days || 365);

                    if (parseInt(m.schedule_enabled, 10) === 1) {
                        $('#schedule-frequency-row, #schedule-daterange-row').show();
                    } else {
                        $('#schedule-frequency-row, #schedule-daterange-row').hide();
                    }
                });
            });
        });

        // Delete mapping
        $(document).on('click', '.delete-mapping', function () {
            var mappingId = $(this).data('mapping-id');
            if (!mappingId) return;
            if (!window.confirm('Delete this calendar mapping? Events already synced will remain.')) return;

            post('azure_delete_calendar_mapping', { mapping_id: mappingId }).done(function (response) {
                if (response && response.success) {
                    window.location.reload();
                } else {
                    alert('Failed to delete mapping: ' + errorText(response));
                }
            });
        });

        // Per-row sync toggle
        $(document).on('change', '.mapping-sync-toggle', function () {
            var $cb = $(this);
            var mappingId = $cb.data('mapping-id');
            var enabled = $cb.is(':checked');
            if (!mappingId) return;

            post('azure_toggle_calendar_sync', {
                mapping_id: mappingId,
                enabled: enabled ? 'true' : 'false'
            }).done(function (response) {
                if (!response || !response.success) {
                    alert('Failed to update sync: ' + errorText(response));
                    $cb.prop('checked', !enabled);
                }
            }).fail(function () {
                $cb.prop('checked', !enabled);
            });
        });

        // Schedule expander
        $(document).on('change', '#schedule-enabled-checkbox', function () {
            if ($(this).is(':checked')) {
                $('#schedule-frequency-row, #schedule-daterange-row').show();
            } else {
                $('#schedule-frequency-row, #schedule-daterange-row').hide();
            }
        });

        // Save mapping form
        $(document).on('submit', '#calendar-mapping-form', function (e) {
            e.preventDefault();

            var $button = $('#save-mapping-btn');
            var mappingId = parseInt($('#mapping-id').val(), 10) || 0;
            var $outlookSelect = $('#outlook-calendar-select');
            var outlookCalendarId = $outlookSelect.val();
            var outlookCalendarName = $outlookSelect.find('option:selected').text();
            var $catSelect = $('#pta-category-select');
            var categoryId = parseInt($catSelect.val(), 10) || 0;
            var categoryName = $catSelect.find('option:selected').text();
            var newCategoryName = ($('#new-category-name').val() || '').trim();
            var syncEnabled = $('#sync-enabled-checkbox').is(':checked') ? 1 : 0;
            var scheduleEnabled = $('#schedule-enabled-checkbox').is(':checked') ? 1 : 0;
            var scheduleFrequency = $('#schedule-frequency-select').val() || 'hourly';
            var scheduleLookback = parseInt($('#schedule-lookback-days').val(), 10) || 30;
            var scheduleLookahead = parseInt($('#schedule-lookahead-days').val(), 10) || 365;

            if (!outlookCalendarId) {
                alert('Please select an Outlook calendar.');
                return;
            }

            var hasExistingCategory = categoryId > 0;
            var hasNewCategory = newCategoryName !== '';
            if (!hasExistingCategory && !hasNewCategory) {
                alert('Pick an existing category or type a new category name.');
                return;
            }
            if (hasExistingCategory && hasNewCategory) {
                alert('Pick an existing category OR type a new one, not both.');
                return;
            }

            $button.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span> Saving...');

            var basePayload = {
                mapping_id: mappingId,
                outlook_calendar_id: outlookCalendarId,
                outlook_calendar_name: outlookCalendarName,
                sync_enabled: syncEnabled,
                schedule_enabled: scheduleEnabled,
                schedule_frequency: scheduleFrequency,
                schedule_lookback_days: scheduleLookback,
                schedule_lookahead_days: scheduleLookahead
            };

            if (hasNewCategory) {
                post('azure_create_pta_event_category', { category_name: newCategoryName }).done(function (response) {
                    if (!response || !response.success) {
                        alert('Failed to create category: ' + errorText(response));
                        $button.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Mapping');
                        return;
                    }
                    var data = response.data || {};
                    saveMapping($.extend({}, basePayload, {
                        category_id: data.term_id,
                        category_name: data.name
                    }), $button);
                }).fail(function () {
                    alert('Failed to create category (network error).');
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Mapping');
                });
            } else {
                saveMapping($.extend({}, basePayload, {
                    category_id: categoryId,
                    category_name: categoryName
                }), $button);
            }
        });

        // Modal close
        $(document).on('click', '.modal-close, .modal-overlay, #cancel-mapping-btn', function () {
            closeMappingModal();
        });

        // Sync Now
        $(document).on('click', '#calendar-manual-sync-btn, #calendar-manual-sync-now-mapping', function () {
            runManualSync($(this));
        });

        // Repair metadata
        $(document).on('click', '#calendar-repair-metadata-btn', function () {
            runRepair($(this));
        });

        // Refresh history button
        $(document).on('click', '#refresh-sync-history', function () {
            refreshSyncHistory();
        });

        // Initial history load
        refreshSyncHistory();
    });

})(jQuery);
