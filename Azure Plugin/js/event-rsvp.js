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
})(jQuery);
