/* global jQuery, azureRsvp */
(function ($) {
    'use strict';

    if (typeof azureRsvp === 'undefined') {
        return;
    }

    // − Jamie +1 + : the hidden guests input holds the count; the label and
    // the buttons' limits follow it.
    function syncParty($party) {
        if ($party.data('locked')) {
            return;
        }
        var max = parseInt($party.data('max'), 10) || 0;
        var $input = $party.find('input[name="guests"]');
        var n = Math.min(max, Math.max(0, parseInt($input.val(), 10) || 0));
        $input.val(n);
        $party.find('.pta-rsvp-party-label').text($party.data('name') + (n > 0 ? ' +' + n : ''));
        $party.find('.pta-rsvp-step[data-step="-1"]').prop('disabled', n <= 0).attr('aria-disabled', n <= 0 ? 'true' : 'false');
        $party.find('.pta-rsvp-step[data-step="1"]').prop('disabled', n >= max).attr('aria-disabled', n >= max ? 'true' : 'false');
    }

    function guestsIn($scope) {
        var $input = $scope.find('.pta-rsvp-party input[name="guests"]');
        return $input.length ? (parseInt($input.val(), 10) || 0) : 0;
    }

    function enableButtons($scope) {
        $scope.find('button').prop('disabled', false);
        $scope.find('.pta-rsvp-party').each(function () {
            syncParty($(this));
        });
    }

    $(document).on('click', '.pta-rsvp-party .pta-rsvp-step', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $party = $(this).closest('.pta-rsvp-party');
        var $input = $party.find('input[name="guests"]');
        $input.val((parseInt($input.val(), 10) || 0) + parseInt($(this).data('step'), 10));
        syncParty($party);
    });

    function send($box, action, data) {
        var $msg = $box.find('.pta-rsvp-message');
        var $buttons = $box.find('button');
        $buttons.prop('disabled', true);
        $msg.removeClass('is-error').text(azureRsvp.i18n.saving);
        $.post(azureRsvp.ajaxurl, $.extend({
            action: action,
            nonce: azureRsvp.nonce,
            event_id: $box.data('event-id')
        }, data)).done(function (res) {
            var payload = (res && res.data) || {};
            if (payload.html) {
                var $fresh = $(payload.html);
                $box.replaceWith($fresh);
                $box = $fresh;
                $msg = $box.find('.pta-rsvp-message');
            }
            $msg.toggleClass('is-error', !res || !res.success)
                .text(payload.message || (res && res.success ? '' : azureRsvp.i18n.error));
        }).fail(function () {
            $msg.addClass('is-error').text(azureRsvp.i18n.error);
        }).always(function () {
            enableButtons($box);
        });
    }

    $(document).on('click', '.pta-event-rsvp .pta-rsvp-submit', function () {
        var $box = $(this).closest('.pta-event-rsvp');
        send($box, 'azure_rsvp_create', { guests: guestsIn($box) });
    });

    $(document).on('click', '.pta-event-rsvp .pta-rsvp-cancel', function () {
        if (!window.confirm(azureRsvp.i18n.confirm_cancel)) {
            return;
        }
        send($(this).closest('.pta-event-rsvp'), 'azure_rsvp_cancel', {});
    });

    // The RSVP control next to "Join meeting" on event lists. The server
    // sends back the refreshed control; its message line shows the result.
    function sendInline($wrap, action, guests) {
        var $buttons = $wrap.find('button');
        $buttons.prop('disabled', true);
        $wrap.find('.pta-rsvp-inline-msg').removeClass('is-error').text(azureRsvp.i18n.saving);
        $.post(azureRsvp.ajaxurl, {
            action: action,
            nonce: azureRsvp.nonce,
            event_id: $wrap.data('event-id'),
            variant: $wrap.data('variant'),
            guests: guests || 0,
            'return': window.location.href
        }).done(function (res) {
            var payload = (res && res.data) || {};
            var ok = !!(res && res.success);
            if (payload.html) {
                var $fresh = $(payload.html);
                $wrap.replaceWith($fresh);
                $wrap = $fresh;
            }
            $wrap.find('.pta-rsvp-inline-msg').toggleClass('is-error', !ok)
                .text(ok ? '' : (payload.message || azureRsvp.i18n.error));
        }).fail(function () {
            $wrap.find('.pta-rsvp-inline-msg').addClass('is-error').text(azureRsvp.i18n.error);
        }).always(function () {
            enableButtons($wrap);
        });
    }

    // Events that allow guests open the − / + picker first; Confirm saves.
    $(document).on('click', '.pta-rsvp-inline .pta-rsvp-inline-go', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $wrap = $(this).closest('.pta-rsvp-inline');
        var $party = $wrap.find('.pta-rsvp-inline-party');
        if (!$party.length) {
            sendInline($wrap, 'azure_rsvp_create', 0);
            return;
        }
        $(this).prop('hidden', true);
        $party.prop('hidden', false);
        syncParty($party.find('.pta-rsvp-party'));
        $party.find('.pta-rsvp-step[data-step="1"]').trigger('focus');
    });

    $(document).on('click', '.pta-rsvp-inline .pta-rsvp-inline-confirm', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $wrap = $(this).closest('.pta-rsvp-inline');
        sendInline($wrap, 'azure_rsvp_create', guestsIn($wrap));
    });

    $(document).on('click', '.pta-rsvp-inline .pta-rsvp-inline-back', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $wrap = $(this).closest('.pta-rsvp-inline');
        $wrap.find('.pta-rsvp-inline-party').prop('hidden', true);
        $wrap.find('.pta-rsvp-inline-go').prop('hidden', false).trigger('focus');
    });

    $(document).on('click', '.pta-rsvp-inline .pta-rsvp-inline-cancel', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (!window.confirm(azureRsvp.i18n.confirm_cancel)) {
            return;
        }
        sendInline($(this).closest('.pta-rsvp-inline'), 'azure_rsvp_cancel');
    });
})(jQuery);
