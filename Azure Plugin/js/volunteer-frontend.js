/**
 * Volunteer Sign-Up frontend — per-slot signup, confirm modal, withdraw
 */
(function($) {
    'use strict';

    if (typeof azureVolunteer === 'undefined') return;

    var i18n = azureVolunteer.i18n || {};

    function showMessage($el, text, type) {
        if (!$el.length) {
            return;
        }
        $el.removeClass('success error').addClass(type).text(text).show();
        if (type === 'success') {
            setTimeout(function() { $el.fadeOut(); }, 4000);
        }
    }

    function closeModal() {
        $('#azure-vs-confirm-modal').remove();
    }

    /**
     * People requested on a group sheet row; 1 when the row has no picker.
     * Returns 0 when the number is above what is left.
     */
    function partySize($row) {
        var $input = $row.find('.azure-vs-party');
        if (!$input.length) {
            return 1;
        }
        var size = parseInt($input.val(), 10);
        var max = parseInt($input.attr('max'), 10);
        if (!size || size < 1) {
            size = 1;
        }
        if (max && size > max) {
            showMessage($row.closest('.azure-volunteer-sheet').find('.azure-vs-message'),
                (i18n.party_max || 'Only %d spaces are left.').replace('%d', max), 'error');
            return 0;
        }
        return size;
    }

    function openConfirm($row) {
        closeModal();
        var size = partySize($row);
        if (!size) {
            return;
        }
        var name = $row.data('activity-name') || '';
        var time = $row.data('activity-time') || '';
        var title = $row.data('sheet-title') || '';
        var people = size > 1 ? (i18n.party_people || 'For %d people').replace('%d', size) : '';
        var html = '<div id="azure-vs-confirm-modal" class="azure-vs-modal-overlay">' +
            '<div class="azure-vs-modal-card" role="dialog" aria-modal="true">' +
            '<h3>' + $('<div>').text(i18n.confirm_title || 'Confirm sign-up').html() + '</h3>' +
            '<p><strong>' + $('<div>').text(title).html() + '</strong></p>' +
            '<p>' + $('<div>').text(name).html() +
            (time ? '<br /><span class="azure-vs-modal-time">' + $('<div>').text(time).html() + '</span>' : '') +
            (people ? '<br /><strong>' + $('<div>').text(people).html() + '</strong>' : '') +
            '</p>' +
            '<div class="azure-vs-modal-actions">' +
            '<button type="button" class="button button-primary azure-vs-confirm-yes">' +
            $('<div>').text(i18n.confirm_btn || 'Confirm sign-up').html() + '</button>' +
            '<button type="button" class="button azure-vs-confirm-no">' +
            $('<div>').text(i18n.cancel || 'Cancel').html() + '</button>' +
            '</div></div></div>';
        $('body').append(html);
        $('#azure-vs-confirm-modal').data('activity-id', $row.data('activity-id'));
        $('#azure-vs-confirm-modal').data('party-size', size);
        $('#azure-vs-confirm-modal').data('sheet', $row.closest('.azure-volunteer-sheet'));
    }

    function signup(activityId, $sheet, size) {
        var $msg = $sheet.find('.azure-vs-message');
        $msg.hide();
        var data = {
            action: 'azure_volunteer_signup',
            nonce: azureVolunteer.nonce,
            activity_ids: [activityId]
        };
        data['party_sizes[' + activityId + ']'] = size || 1;
        $.post(azureVolunteer.ajaxurl, data)
        .done(function(res) {
            if (res.success) {
                showMessage($msg, res.data.message || i18n.saved || 'Saved!', 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showMessage($msg, (res.data && res.data.message) || i18n.error || 'Error', 'error');
            }
        })
        .fail(function() {
            showMessage($msg, i18n.error || 'Network error.', 'error');
        });
    }

    $(document).on('click', 'button.azure-vs-signup-btn', function(e) {
        e.preventDefault();
        openConfirm($(this).closest('tr'));
    });

    $(document).on('click', '.azure-vs-confirm-yes', function() {
        var $modal = $('#azure-vs-confirm-modal');
        var id = $modal.data('activity-id');
        var size = $modal.data('party-size') || 1;
        var $sheet = $modal.data('sheet');
        closeModal();
        if (id && $sheet && $sheet.length) {
            signup(id, $sheet, size);
        }
    });

    $(document).on('click', '.azure-vs-update-btn', function(e) {
        e.preventDefault();
        var $row = $(this).closest('tr');
        var size = partySize($row);
        var current = parseInt($row.find('.azure-vs-party').attr('value'), 10) || 1;
        if (size && size !== current) {
            signup($row.data('activity-id'), $row.closest('.azure-volunteer-sheet'), size);
        }
    });

    $(document).on('click', '.azure-vs-confirm-no, #azure-vs-confirm-modal', function(e) {
        if (e.target === this || $(e.target).hasClass('azure-vs-confirm-no')) {
            closeModal();
        }
    });

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
