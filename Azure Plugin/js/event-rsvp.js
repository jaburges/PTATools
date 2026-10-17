/* global jQuery, azureRsvp */
(function ($) {
    'use strict';

    if (typeof azureRsvp === 'undefined') {
        return;
    }

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
            $box.find('button').prop('disabled', false);
        });
    }

    $(document).on('click', '.pta-event-rsvp .pta-rsvp-submit', function () {
        var $box = $(this).closest('.pta-event-rsvp');
        var $guests = $box.find('select[name="guests"]');
        send($box, 'azure_rsvp_create', { guests: $guests.length ? $guests.val() : 0 });
    });

    $(document).on('click', '.pta-event-rsvp .pta-rsvp-cancel', function () {
        if (!window.confirm(azureRsvp.i18n.confirm_cancel)) {
            return;
        }
        send($(this).closest('.pta-event-rsvp'), 'azure_rsvp_cancel', {});
    });

    // The RSVP control next to "Join meeting" on event lists. The server
    // sends back the refreshed control; its message line shows the result.
    function sendInline($wrap, action) {
        var $buttons = $wrap.find('button');
        $buttons.prop('disabled', true);
        $wrap.find('.pta-rsvp-inline-msg').removeClass('is-error').text(azureRsvp.i18n.saving);
        $.post(azureRsvp.ajaxurl, {
            action: action,
            nonce: azureRsvp.nonce,
            event_id: $wrap.data('event-id'),
            variant: $wrap.data('variant'),
            guests: 0,
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
            $wrap.find('button').prop('disabled', false);
        });
    }

    $(document).on('click', '.pta-rsvp-inline .pta-rsvp-inline-go', function (e) {
        e.preventDefault();
        e.stopPropagation();
        sendInline($(this).closest('.pta-rsvp-inline'), 'azure_rsvp_create');
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
