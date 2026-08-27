(function ($) {
    'use strict';

    var min = (window.ptaCustomDonation && parseFloat(window.ptaCustomDonation.min)) || 5;

    function formatMoney(amount) {
        return '$' + amount.toFixed(2).replace(/\.00$/, '');
    }

    function toggleCustomAmount($form, variation) {
        var $wrap = $form.find('.pta-custom-donation-amount');
        var $qty = $form.find('.quantity');
        var useCustom = !!(variation && variation.pta_custom_amount);
        if (useCustom) {
            $wrap.prop('hidden', false);
            $wrap.find('input').prop('required', true);
            $qty.hide();
            $form.find('input.qty').val(1);
            updatePricePreview($form);
        } else {
            $wrap.prop('hidden', true);
            $wrap.find('input').prop('required', false);
            $qty.show();
        }
    }

    function updatePricePreview($form) {
        var raw = $form.find('#pta_custom_donation_amount').val();
        var amount = parseFloat(String(raw).replace(/[$,\s]/g, ''));
        if (!(amount >= min)) {
            return;
        }
        var $price = $form.find('.woocommerce-variation-price .amount').first();
        if ($price.length) {
            $price.text(formatMoney(amount));
        }
    }

    $(document).on('found_variation', 'form.variations_form', function (e, variation) {
        toggleCustomAmount($(this), variation);
    });

    $(document).on('reset_data hide_variation', 'form.variations_form', function () {
        toggleCustomAmount($(this), null);
    });

    $(document).on('input', '#pta_custom_donation_amount', function () {
        updatePricePreview($(this).closest('form'));
    });
})(jQuery);
