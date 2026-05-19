/* PTA Tools — Orders Reports — Builder JS
 *
 * Powers the New Report / Edit Report form: column drag-and-drop reorder,
 * date presets, product search autocomplete, AJAX preview, AJAX save,
 * and Saved Reports row delete.
 */
(function ($) {
    'use strict';

    if (typeof azureOR === 'undefined') {
        return;
    }

    var $form = $('#azure-or-builder');
    var $selectedList = $('#azure-or-selected-list');
    var $preview = $('#azure-or-preview');

    // ── Column toggle (checkbox in Available → row in Selected) ────────
    function addSelectedRow(key, label) {
        if ($selectedList.find('li[data-key="' + cssEscape(key) + '"]').length) return;
        var $li = $('<li/>')
            .attr('data-key', key)
            .append('<span class="dashicons dashicons-menu"></span>')
            .append($('<span class="azure-or-col-label"/>').text(label))
            .append($('<input type="hidden" name="columns[]"/>').val(key))
            .append('<button type="button" class="azure-or-col-remove">&times;</button>');
        $selectedList.append($li);
    }

    function removeSelectedRow(key) {
        $selectedList.find('li[data-key="' + cssEscape(key) + '"]').remove();
        $('input.azure-or-col-toggle[data-key="' + cssEscape(key) + '"]').prop('checked', false);
    }

    function cssEscape(s) {
        return (s || '').replace(/(["\\\.\[\]\(\),'])/g, '\\$1');
    }

    $(document).on('change', '.azure-or-col-toggle', function () {
        var $cb = $(this);
        var key = $cb.data('key');
        var label = $cb.data('label');
        if ($cb.prop('checked')) {
            addSelectedRow(key, label);
        } else {
            removeSelectedRow(key);
        }
    });

    $(document).on('click', '.azure-or-col-remove', function () {
        var $li = $(this).closest('li');
        var key = $li.data('key');
        removeSelectedRow(key);
    });

    // ── Sortable column reorder ────────────────────────────────────────
    if ($selectedList.length && $.fn.sortable) {
        $selectedList.sortable({
            placeholder: 'azure-or-sort-placeholder',
            forcePlaceholderSize: true,
            cursor: 'grabbing',
            handle: '.dashicons-menu',
            tolerance: 'pointer',
        });
    }

    // ── Date presets ───────────────────────────────────────────────────
    function fmtLocal(d) {
        // YYYY-MM-DDTHH:MM in local time
        var pad = function (n) { return ('0' + n).slice(-2); };
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function presetRange(preset) {
        var now = new Date();
        var from = new Date(now), to = new Date(now);
        switch (preset) {
            case 'last_7_days':
                from.setDate(now.getDate() - 7); from.setHours(0, 0, 0, 0);
                to = new Date(now); to.setHours(23, 59, 0, 0);
                break;
            case 'last_30_days':
                from.setDate(now.getDate() - 30); from.setHours(0, 0, 0, 0);
                to = new Date(now); to.setHours(23, 59, 0, 0);
                break;
            case 'previous_month':
                from = new Date(now.getFullYear(), now.getMonth() - 1, 1, 0, 0, 0, 0);
                to   = new Date(now.getFullYear(), now.getMonth(), 1, 0, 0, 0, 0);
                to.setMinutes(to.getMinutes() - 1);
                break;
            case 'previous_quarter':
                var q = Math.floor(now.getMonth() / 3); // 0..3 current quarter index
                var prevStartMonth = (q - 1) * 3;
                var prevStartYear  = now.getFullYear();
                if (prevStartMonth < 0) { prevStartMonth += 12; prevStartYear--; }
                from = new Date(prevStartYear, prevStartMonth, 1, 0, 0, 0, 0);
                to   = new Date(prevStartYear, prevStartMonth + 3, 1, 0, 0, 0, 0);
                to.setMinutes(to.getMinutes() - 1);
                break;
            case 'previous_year':
                from = new Date(now.getFullYear() - 1, 0, 1, 0, 0, 0, 0);
                to   = new Date(now.getFullYear() - 1, 11, 31, 23, 59, 0, 0);
                break;
            default:
                return null;
        }
        return { from: from, to: to };
    }

    $(document).on('click', '.azure-or-preset', function () {
        var preset = $(this).data('preset');
        var range = presetRange(preset);
        if (!range) return;
        $('#azure-or-from').val(fmtLocal(range.from));
        $('#azure-or-to').val(fmtLocal(range.to));
        $('#azure-or-preset').val(preset);
    });

    // Clear preset if user manually edits date inputs.
    $(document).on('input', '#azure-or-from, #azure-or-to', function () {
        $('#azure-or-preset').val('');
    });

    // ── Product search ─────────────────────────────────────────────────
    // The <select class="wc-product-search"> is auto-initialised by WC's
    // wc-enhanced-select script (which we enqueue in the PHP module). It
    // gives us the same Select2-powered 3-letter search used everywhere
    // else in the WC admin. Nothing to do here.

    // ── Preview (AJAX) ─────────────────────────────────────────────────
    $('#azure-or-preview-btn').on('click', function () {
        var data = $form.serializeArray();
        data.push({ name: 'action', value: 'azure_or_preview' });
        data.push({ name: 'nonce',  value: azureOR.nonces.preview });
        $preview.html('<p><em>Loading preview\u2026</em></p>');
        $.post(azureOR.ajaxurl, data, function (resp) {
            if (resp && resp.success) {
                $preview.html(resp.data.html);
            } else {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Preview failed.';
                $preview.html('<div class="notice notice-error"><p>' + msg + '</p></div>');
            }
        }).fail(function () {
            $preview.html('<div class="notice notice-error"><p>Preview request failed.</p></div>');
        });
    });

    // ── Save (AJAX) ────────────────────────────────────────────────────
    $('#azure-or-save-btn').on('click', function () {
        var name = $('#azure-or-name').val();
        if (!name || !name.trim()) {
            alert('Enter a report name first.');
            $('#azure-or-name').focus();
            return;
        }
        var data = $form.serializeArray();
        data.push({ name: 'action', value: 'azure_or_save' });
        data.push({ name: 'nonce',  value: azureOR.nonces.save });
        $.post(azureOR.ajaxurl, data, function (resp) {
            if (resp && resp.success) {
                var newId = resp.data.report_id;
                $form.find('input[name="report_id"]').val(newId);
                // Show a brief success notice and update the URL so refresh
                // reloads this exact saved report into edit mode.
                var $msg = $('<div class="notice notice-success" style="margin:12px 0;"><p>Saved.</p></div>');
                $preview.before($msg);
                setTimeout(function () { $msg.fadeOut(400, function () { $(this).remove(); }); }, 2500);
                var url = new URL(window.location.href);
                url.searchParams.set('edit', newId);
                window.history.replaceState({}, '', url.toString());
            } else {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Save failed.';
                alert(msg);
            }
        });
    });

    // ── Delete saved report (AJAX) ─────────────────────────────────────
    $(document).on('click', '.azure-or-delete', function () {
        var $btn = $(this);
        var id   = $btn.data('id');
        if (!confirm('Delete this report? This cannot be undone.')) return;
        $.post(azureOR.ajaxurl, {
            action: 'azure_or_delete',
            nonce: azureOR.nonces['delete'],
            report_id: id,
        }, function (resp) {
            if (resp && resp.success) {
                $btn.closest('tr').fadeOut(200, function () { $(this).remove(); });
            } else {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Delete failed.';
                alert(msg);
            }
        });
    });
})(jQuery);
