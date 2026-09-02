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
        $('#mapping-mode-single').prop('checked', true);
        $('#category-rules-list').empty();
        setMappingMode('single');
        $('#mapping-modal-title').text('Add Calendar Mapping');
        $('#save-mapping-btn').prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Mapping');
    }

    function setMappingMode(mode) {
        var isRules = mode === 'rules';
        $('#mapping-mode-single').prop('checked', !isRules);
        $('#mapping-mode-rules').prop('checked', isRules);
        $('#single-category-row').toggle(!isRules);
        $('#rules-category-row').toggle(isRules);
        if (isRules && $('#category-rules-list .azure-category-rule').length === 0) {
            addCategoryRuleRow();
        }
    }

    function categoryOptionsHtml(includeBlank) {
        var html = includeBlank ? '<option value="">— Select category —</option>' : '';
        $('#pta-category-select option').each(function () {
            var val = $(this).val();
            var text = $(this).text();
            if (val === '') {
                return;
            }
            html += '<option value="' + escapeHtml(val) + '">' + escapeHtml(text) + '</option>';
        });
        return html;
    }

    function syncFallbackCategoryOptions() {
        var current = $('#fallback-category-select').val();
        $('#fallback-category-select').html(
            '<option value="">None — leave unmatched events uncategorized</option>' + categoryOptionsHtml(false)
        );
        if (current) {
            $('#fallback-category-select').val(current);
        }
        $('#category-rules-list .rule-category-select').each(function () {
            var $sel = $(this);
            var val = $sel.val();
            $sel.html(categoryOptionsHtml(true));
            $sel.val(val);
        });
    }

    function addCategoryRuleRow(rule) {
        rule = rule || {};
        var $row = $(
            '<div class="azure-category-rule" style="border:1px solid #dcdcde;padding:10px;margin:0 0 8px;border-radius:4px;">' +
                '<p style="margin:0 0 6px;"><label>Look for <input type="text" class="rule-term regular-text" placeholder="WAPTA"></label></p>' +
                '<p style="margin:0 0 6px;"><label>In ' +
                    '<select class="rule-look-in">' +
                        '<option value="subject">subject of calendar event</option>' +
                        '<option value="body">body of calendar event</option>' +
                        '<option value="subject_or_body">subject or body</option>' +
                    '</select></label></p>' +
                '<p style="margin:0 0 6px;"><label>Assign category ' +
                    '<select class="rule-category-select regular-text"></select></label></p>' +
                '<p style="margin:0 0 6px;"><input type="text" class="rule-new-category regular-text" placeholder="Or create a new category…"></p>' +
                '<p style="margin:0;"><button type="button" class="button-link-delete remove-category-rule">Remove rule</button></p>' +
            '</div>'
        );
        $row.find('.rule-category-select').html(categoryOptionsHtml(true));
        if (rule.term) {
            $row.find('.rule-term').val(rule.term);
        }
        if (rule.look_in) {
            $row.find('.rule-look-in').val(rule.look_in);
        }
        if (rule.category_id) {
            $row.find('.rule-category-select').val(String(rule.category_id));
        }
        $('#category-rules-list').append($row);
    }

    function collectCategoryRules() {
        var rules = [];
        var errors = [];
        $('#category-rules-list .azure-category-rule').each(function () {
            var $row = $(this);
            var term = ($row.find('.rule-term').val() || '').trim();
            var lookIn = $row.find('.rule-look-in').val() || 'subject';
            var categoryId = parseInt($row.find('.rule-category-select').val(), 10) || 0;
            var categoryName = $row.find('.rule-category-select option:selected').text();
            var newName = ($row.find('.rule-new-category').val() || '').trim();
            if (!term && !categoryId && !newName) {
                return;
            }
            if (!term) {
                errors.push('Each rule needs a term to look for.');
                return;
            }
            if (categoryId && newName) {
                errors.push('Pick an existing category OR type a new one for “' + term + '”, not both.');
                return;
            }
            if (!categoryId && !newName) {
                errors.push('Choose a category for the term “' + term + '”.');
                return;
            }
            rules.push({
                term: term,
                look_in: lookIn,
                category_id: newName ? 0 : categoryId,
                category_name: newName || categoryName
            });
        });
        return { rules: rules, errors: errors };
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
            syncFallbackCategoryOptions();
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
                var synced = parseInt(d.total_events_synced, 10) || 0;
                var deleted = parseInt(d.total_events_deleted, 10) || 0;
                var cals = parseInt(d.calendars_synced, 10) || 0;
                var errs = parseInt(d.total_errors, 10) || 0;

                var msg = 'Synced ' + synced + ' event(s)';
                if (deleted) {
                    msg += ', trashed ' + deleted + ' deleted-in-Outlook event(s)';
                }
                msg += ' across ' + cals + ' calendar(s)';
                if (errs) {
                    msg += ' (' + errs + ' error' + (errs === 1 ? '' : 's') + ')';
                }
                $('#sync-status-message').text(msg);
                refreshSyncHistory();
                // Refresh stats by reloading after a brief delay.
                setTimeout(function () { window.location.reload(); }, 1500);
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
                    var mode = m.mapping_mode === 'rules' ? 'rules' : 'single';
                    setMappingMode(mode);
                    $('#category-rules-list').empty();
                    var rules = Array.isArray(m.category_rules) ? m.category_rules : [];
                    if (mode === 'rules') {
                        if (rules.length) {
                            rules.forEach(function (rule) { addCategoryRuleRow(rule); });
                        } else {
                            addCategoryRuleRow();
                        }
                    }
                    $('#fallback-category-select').val(m.category_id || '');
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
        $(document).on('change', 'input[name="mapping_mode"]', function () {
            setMappingMode($(this).val());
        });

        $(document).on('click', '#add-category-rule', function () {
            addCategoryRuleRow();
        });

        $(document).on('click', '.remove-category-rule', function () {
            $(this).closest('.azure-category-rule').remove();
            if ($('#category-rules-list .azure-category-rule').length === 0) {
                addCategoryRuleRow();
            }
        });

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
            var mappingMode = $('#mapping-mode-rules').is(':checked') ? 'rules' : 'single';
            var $catSelect = $('#pta-category-select');
            var categoryId = parseInt($catSelect.val(), 10) || 0;
            var categoryName = $catSelect.find('option:selected').text();
            var newCategoryName = ($('#new-category-name').val() || '').trim();
            var collected = { rules: [], errors: [] };
            if (mappingMode === 'rules') {
                collected = collectCategoryRules();
                categoryId = parseInt($('#fallback-category-select').val(), 10) || 0;
                categoryName = categoryId ? $('#fallback-category-select option:selected').text() : '';
                newCategoryName = '';
            }
            var syncEnabled = $('#sync-enabled-checkbox').is(':checked') ? 1 : 0;
            var scheduleEnabled = $('#schedule-enabled-checkbox').is(':checked') ? 1 : 0;
            var scheduleFrequency = $('#schedule-frequency-select').val() || 'hourly';
            var scheduleLookback = parseInt($('#schedule-lookback-days').val(), 10) || 30;
            var scheduleLookahead = parseInt($('#schedule-lookahead-days').val(), 10) || 365;

            if (!outlookCalendarId) {
                alert('Please select an Outlook calendar.');
                return;
            }

            if (mappingMode === 'rules') {
                if (collected.errors.length) {
                    alert(collected.errors[0]);
                    return;
                }
                if (!collected.rules.length) {
                    alert('Add at least one term → category rule.');
                    return;
                }
            } else {
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
            }

            $button.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span> Saving...');

            var basePayload = {
                mapping_id: mappingId,
                outlook_calendar_id: outlookCalendarId,
                outlook_calendar_name: outlookCalendarName,
                mapping_mode: mappingMode,
                category_rules: JSON.stringify(collected.rules),
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
