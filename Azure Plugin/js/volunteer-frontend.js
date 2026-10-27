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

    function rowMessage($row, text, type) {
        showMessage($row.closest('.azure-volunteer-sheet').find('.azure-vs-message'), text, type);
    }

    /**
     * People requested on a group sheet row; 1 when the row has no picker.
     * Returns 0, after telling the person why, when the entry is not a whole
     * number of at least 1 or is above what is left.
     */
    function partySize($row) {
        var $input = $row.find('.azure-vs-party');
        if (!$input.length) {
            return 1;
        }
        var raw = $.trim(String($input.val()));
        if (!/^\d+$/.test(raw) || parseInt(raw, 10) < 1) {
            rowMessage($row, i18n.party_whole || 'Enter a whole number of people, 1 or more.', 'error');
            $input.trigger('focus');
            return 0;
        }
        var size = parseInt(raw, 10);
        var max = parseInt($input.attr('max'), 10);
        if (max && size > max) {
            rowMessage($row, (i18n.party_max || 'Only %d spaces are left.').replace('%d', max), 'error');
            $input.trigger('focus');
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

    function esc(text) {
        return $('<div>').text(String(text)).html();
    }

    /**
     * Donate buttons shown straight after a signup for an activity that asks
     * for a donation. The page reloads only when the person presses Done, so
     * the buttons don't vanish before they can tap one.
     */
    function showDonate($sheet, donate) {
        $sheet.find('.azure-vs-donate').remove();
        var buttons = '';
        (donate.amounts || []).forEach(function(a) {
            buttons += '<a class="azure-vs-donate-amount" href="' + esc(a.url) + '">' + esc(a.label) + '</a>';
        });
        var html = '<div class="azure-vs-donate" role="region" aria-label="' + esc(donate.label) + '">' +
            '<p class="azure-vs-donate-intro">' + esc(i18n.donate_intro || 'Thanks for signing up! You chose to donate. Pick an amount:') + '</p>' +
            '<a class="azure-vs-donate-main" href="' + esc(donate.url) + '">' + esc(donate.label) + '</a>' +
            (buttons ? '<div class="azure-vs-donate-amounts">' + buttons + '</div>' : '') +
            '<button type="button" class="button azure-vs-donate-done">' + esc(i18n.donate_done || 'Done') + '</button>' +
            '</div>';
        $sheet.find('.azure-vs-message').after(html);
        var el = $sheet.find('.azure-vs-donate').get(0);
        if (el && el.scrollIntoView) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    $(document).on('click', '.azure-vs-donate-done', function() {
        location.reload();
    });

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
                if (res.data.donate && res.data.donate.url) {
                    $msg.removeClass('error').addClass('success').text(res.data.message || i18n.saved || 'Saved!').show();
                    showDonate($sheet, res.data.donate);
                    return;
                }
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
        if (!size) {
            return;
        }
        var current = parseInt($row.find('.azure-vs-party').attr('value'), 10) || 1;
        if (size === current) {
            rowMessage($row, (i18n.party_same || 'You are already signed up for %d. Change the number, then press Update.').replace('%d', current), 'error');
            return;
        }
        signup($row.data('activity-id'), $row.closest('.azure-volunteer-sheet'), size);
    });

    function modalShell(title, $row, body) {
        var name = $row.data('activity-name') || '';
        var time = $row.data('activity-time') || '';
        return '<div id="azure-vs-confirm-modal" class="azure-vs-modal-overlay">' +
            '<div class="azure-vs-modal-card" role="dialog" aria-modal="true">' +
            '<h3>' + esc(title) + '</h3>' +
            '<p><strong>' + esc($row.data('sheet-title') || '') + '</strong><br />' + esc(name) +
            (time ? '<br /><span class="azure-vs-modal-time">' + esc(time) + '</span>' : '') + '</p>' +
            body + '</div></div>';
    }

    /** Signed out on a sheet that allows teens: choose sign in or teen sign-up. */
    $(document).on('click', 'a.azure-vs-guest-btn', function(e) {
        e.preventDefault();
        closeModal();
        var $row = $(this).closest('tr');
        $('body').append(modalShell(i18n.guest_title || 'Sign up to volunteer', $row,
            '<p>' + esc(i18n.guest_intro || '') + '</p>' +
            '<div class="azure-vs-modal-actions">' +
            '<a class="button button-primary" href="' + esc($(this).attr('href')) + '">' + esc(i18n.sign_in || 'Sign in') + '</a>' +
            '<button type="button" class="button azure-vs-teen-start">' + esc(i18n.teen_btn || 'Teen volunteer') + '</button>' +
            '<button type="button" class="button azure-vs-confirm-no">' + esc(i18n.cancel || 'Cancel') + '</button>' +
            '</div>'));
        $('#azure-vs-confirm-modal').data('row', $row);
    });

    $(document).on('click', '.azure-vs-teen-start', function() {
        var $row = $('#azure-vs-confirm-modal').data('row');
        closeModal();
        var training = esc(i18n.teen_training || '').replace('%s', '<a href="mailto:' + esc(i18n.teen_contact || '') + '">' + esc(i18n.teen_contact || '') + '</a>');
        $('body').append(modalShell(i18n.teen_title || 'Teen volunteer sign-up', $row,
            '<form class="azure-vs-teen-form" novalidate>' +
            '<label class="azure-vs-teen-check"><input type="checkbox" name="attest_age" value="1" /> <span>' + esc(i18n.teen_age || '') + '</span></label>' +
            '<label class="azure-vs-teen-check"><input type="checkbox" name="attest_training" value="1" /> <span>' + training + '</span></label>' +
            '<label class="azure-vs-teen-email">' + esc(i18n.teen_email || 'Email address') +
            '<input type="email" name="email" autocomplete="email" required /></label>' +
            '<p class="azure-vs-teen-privacy">' + esc(i18n.teen_privacy || '') + '</p>' +
            '<p class="azure-vs-teen-error" role="alert" style="display:none;"></p>' +
            '<div class="azure-vs-modal-actions">' +
            '<button type="submit" class="button button-primary">' + esc(i18n.teen_submit || 'Sign up') + '</button>' +
            '<button type="button" class="button azure-vs-confirm-no">' + esc(i18n.cancel || 'Cancel') + '</button>' +
            '</div></form>'));
        $('#azure-vs-confirm-modal').data('row', $row).find('input[name="attest_age"]').trigger('focus');
    });

    $(document).on('submit', '.azure-vs-teen-form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $row = $('#azure-vs-confirm-modal').data('row');
        var $err = $form.find('.azure-vs-teen-error');
        var email = $.trim(String($form.find('input[name="email"]').val()));
        function fail(text) {
            $err.text(text).show();
        }
        if (!$form.find('input[name="attest_age"]').is(':checked') || !$form.find('input[name="attest_training"]').is(':checked')) {
            return fail(i18n.teen_ticks || 'Please tick both boxes to sign up.');
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            return fail(i18n.teen_bad_email || 'Please enter a valid email address.');
        }
        $err.hide();
        var $submit = $form.find('button[type="submit"]').prop('disabled', true);
        $.post(azureVolunteer.ajaxurl, {
            action: 'azure_volunteer_teen_signup',
            nonce: azureVolunteer.nonce,
            activity_id: $row.data('activity-id'),
            email: email,
            attest_age: 1,
            attest_training: 1
        })
        .done(function(res) {
            if (res.success) {
                $form.replaceWith('<p class="azure-vs-teen-done">' + esc(res.data.message || i18n.saved || 'Saved!') + '</p>' +
                    '<div class="azure-vs-modal-actions"><button type="button" class="button button-primary azure-vs-donate-done">' +
                    esc(i18n.donate_done || 'Done') + '</button></div>');
                return;
            }
            $submit.prop('disabled', false);
            fail((res.data && res.data.message) || i18n.error || 'Something went wrong.');
            if (res.data && res.data.code === 'account_exists') {
                $err.append(' <a href="' + esc($row.find('a.azure-vs-guest-btn').attr('href') || '') + '">' + esc(i18n.sign_in || 'Sign in') + '</a>');
            }
        })
        .fail(function() {
            $submit.prop('disabled', false);
            fail(i18n.error || 'Something went wrong.');
        });
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
