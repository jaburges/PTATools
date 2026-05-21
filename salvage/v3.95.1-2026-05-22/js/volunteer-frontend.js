/**
 * Volunteer Sign-Up frontend — handles signup, withdraw, messaging
 */
(function($) {
    'use strict';

    if (typeof azureVolunteer === 'undefined') return;

    var i18n = azureVolunteer.i18n || {};

    function showMessage($el, text, type) {
        $el.removeClass('success error').addClass(type).text(text).show();
        if (type === 'success') {
            setTimeout(function() { $el.fadeOut(); }, 4000);
        }
    }

    // Save sign-ups
    $(document).on('click', '.azure-vs-save-btn', function() {
        var $btn = $(this);
        var $sheet = $btn.closest('.azure-volunteer-sheet');
        var $msg = $sheet.find('.azure-vs-message');
        var ids = [];

        $sheet.find('input[name="azure_vs_activity[]"]:checked').each(function() {
            ids.push($(this).val());
        });

        if (!ids.length) {
            showMessage($msg, 'Please select at least one activity.', 'error');
            return;
        }

        $btn.prop('disabled', true).text(i18n.saving || 'Saving...');
        $msg.hide();

        $.post(azureVolunteer.ajaxurl, {
            action: 'azure_volunteer_signup',
            nonce: azureVolunteer.nonce,
            activity_ids: ids
        })
        .done(function(res) {
            if (res.success) {
                showMessage($msg, res.data.message || i18n.saved || 'Saved!', 'success');
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                showMessage($msg, (res.data && res.data.message) || i18n.error || 'Error', 'error');
            }
        })
        .fail(function() {
            showMessage($msg, i18n.error || 'Network error.', 'error');
        })
        .always(function() {
            $btn.prop('disabled', false).text('Save my sign-ups');
        });
    });

    // Withdraw
    $(document).on('click', '.azure-vs-withdraw', function(e) {
        e.preventDefault();
        if (!confirm(i18n.confirm_withdraw || 'Withdraw from this activity?')) return;

        var $btn = $(this);
        var actId = $btn.data('activity-id');
        var $sheet = $btn.closest('.azure-volunteer-sheet');
        var $msg = $sheet.find('.azure-vs-message');

        $.post(azureVolunteer.ajaxurl, {
            action: 'azure_volunteer_withdraw',
            nonce: azureVolunteer.nonce,
            activity_id: actId
        })
        .done(function(res) {
            if (res.success) {
                location.reload();
            } else {
                showMessage($msg, (res.data && res.data.message) || 'Error', 'error');
            }
        })
        .fail(function() {
            showMessage($msg, i18n.error || 'Network error.', 'error');
        });
    });

})(jQuery);
