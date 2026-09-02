(function($) {
    'use strict';

    $(document).on('change', '.azure-order-rule-enabled', function() {
        var $cb = $(this);
        var id = $cb.closest('tr').data('rule-id');
        $.post(azureOrderRules.ajaxUrl, {
            action: 'azure_order_rule_toggle',
            nonce: azureOrderRules.nonce,
            rule_id: id,
            enabled: $cb.is(':checked') ? 1 : 0
        });
    });

    $(document).on('click', '.azure-order-rule-delete', function(e) {
        if (!window.confirm(azureOrderRules.confirmDelete)) {
            e.preventDefault();
        }
    });
})(jQuery);
