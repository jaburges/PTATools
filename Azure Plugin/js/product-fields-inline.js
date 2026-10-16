/**
 * Product fields inline on block product cards (Product Collection etc.).
 *
 * Each card form posts its azure_pf_* fields to wc-ajax=azure_pf_add_to_cart,
 * which runs WooCommerce's add-to-cart validation and cart-item-data filters.
 * Errors show under the card; on success the cart fragments (header cart,
 * mini-cart) are swapped in. Without JS the form posts normally.
 */
/* global jQuery, azurePfInline */
jQuery(function ($) {
    var cfg = window.azurePfInline || {};
    var t = cfg.i18n || {};

    function childFieldInputs($form) {
        return $form.find('.azure-pf-field').filter(function () {
            var scope = $(this).attr('data-field-scope');
            return scope !== 'parent' && scope !== 'family' && !$(this).hasClass('azure-pf-child-selector');
        }).find('input, select, textarea');
    }

    $(document).on('change', '.azure-pf-inline-form .azure-pf-inline-child', function () {
        var $form = $(this).closest('form');
        var kids = $form.data('children') || {};
        var child = kids[$(this).val()];
        var fields = (child && child.fields) || {};
        childFieldInputs($form).each(function () {
            var key = $(this).closest('.azure-pf-field').attr('data-field-key') || '';
            var value = Object.prototype.hasOwnProperty.call(fields, key) ? fields[key] : '';
            if ($(this).is(':checkbox')) {
                $(this).prop('checked', value === 'Yes' || value === '1' || value === 'true');
            } else {
                if ($(this).is('select') && value !== '' && !$(this).find('option').filter(function () { return this.value === value; }).length) {
                    $(this).append($('<option/>').val(value).text(value));
                }
                $(this).val(value);
            }
        });
    });

    function showErrors($form, errors) {
        var $box = $form.find('.azure-pf-inline-errors');
        var $list = $('<ul/>');
        $.each(errors || [t.error], function (_, msg) {
            $list.append($('<li/>').text(msg));
        });
        $box.empty().append($list).prop('hidden', false);
        $form.find('.azure-pf-field').each(function () {
            var $input = $(this).find('input, select, textarea').first();
            var empty = $input.is(':checkbox') ? !$input.is(':checked') : !$.trim($input.val() || '');
            var required = $input.prop('required');
            $input.attr('aria-invalid', required && empty ? 'true' : null);
        });
        var $first = $form.find('[aria-invalid="true"]').first();
        if ($first.length) {
            $first.trigger('focus');
        }
    }

    function applyFragments(fragments) {
        $.each(fragments || {}, function (selector, html) {
            $(selector).replaceWith(html);
        });
    }

    $(document).on('submit', '.azure-pf-inline-form', function (e) {
        if (!cfg.ajax_url) {
            return;
        }
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('.azure-pf-inline-submit');
        var $ok = $form.find('.azure-pf-inline-success');
        var data = $form.serializeArray();
        data.push({ name: 'azure_pf_product_id', value: $form.data('product-id') });

        $form.find('.azure-pf-inline-errors').prop('hidden', true).empty();
        $ok.prop('hidden', true).empty();
        $form.find('[aria-invalid]').removeAttr('aria-invalid');
        $btn.prop('disabled', true).addClass('loading').text(t.adding || 'Adding…');

        $.post(cfg.ajax_url, $.param(data)).done(function (res) {
            var payload = (res && res.data) || {};
            if (!res || !res.success) {
                showErrors($form, payload.errors);
                return;
            }
            if (payload.redirect) {
                window.location.href = payload.redirect;
                return;
            }
            applyFragments(payload.fragments);
            $(document.body).trigger('added_to_cart', [payload.fragments, payload.cart_hash, $btn]);
            $ok.empty()
                .append(document.createTextNode((payload.message || t.added) + ' '))
                .append($('<a/>').attr('href', payload.cart_url || cfg.cart_url).text(t.view_cart || 'View cart'))
                .prop('hidden', false);
        }).fail(function () {
            showErrors($form, [t.error]);
        }).always(function () {
            $btn.prop('disabled', false).removeClass('loading').text(t.add || 'Add to cart');
        });
    });
});
