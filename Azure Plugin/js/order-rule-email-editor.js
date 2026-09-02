/**
 * Save + variable helpers for the Selling → Rules email designer.
 * GrapesJS and newsletter blocks are initialized by newsletter-editor.js.
 */
(function($) {
    'use strict';

    function getReadyHtml() {
        var api = window.azureNewsletterEditorApi;
        if (api && typeof api.getEmailReadyHtml === 'function') {
            return api.getEmailReadyHtml();
        }
        return $('#newsletter_content_html').val() || '';
    }

    function getProjectJson() {
        var api = window.azureNewsletterEditorApi;
        var editor = api && typeof api.getEditor === 'function' ? api.getEditor() : null;
        if (editor && typeof editor.getProjectData === 'function') {
            return JSON.stringify(editor.getProjectData());
        }
        return $('#newsletter_content_json').val() || '';
    }

    function insertOrCopyToken(token) {
        var api = window.azureNewsletterEditorApi;
        var editor = api && typeof api.getEditor === 'function' ? api.getEditor() : null;
        var inserted = false;
        if (editor && editor.getSelected) {
            var selected = editor.getSelected();
            if (selected && selected.get) {
                var type = selected.get('type');
                if (type === 'text' || type === 'textnode') {
                    var current = selected.get('content') || '';
                    selected.set('content', current + token);
                    inserted = true;
                }
            }
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(token);
        }
        var status = inserted
            ? 'Inserted ' + token
            : 'Copied ' + token + ' — paste into a text block';
        $('#save-status').html('<span class="saved">' + status + '</span>');
        setTimeout(function() { $('#save-status').html(''); }, 2500);
    }

    $(document).on('click', '.azure-order-rule-token', function(e) {
        e.preventDefault();
        insertOrCopyToken($(this).data('token'));
    });

    $(document).on('click', '.sidebar-tab[data-panel="tokens"]', function() {
        $('.editor-sidebar-left .sidebar-tab').removeClass('active');
        $(this).addClass('active');
        $('.editor-sidebar-left .sidebar-panel').hide();
        $('#tokens-panel').show();
    });

    $(document).on('click', '#btn-save-rule-email', function(e) {
        e.preventDefault();
        var btn = $(this);
        var original = btn.text();
        btn.prop('disabled', true).text('Saving…');

        var html = getReadyHtml();
        var json = getProjectJson();
        $('#newsletter_content_html').val(html);
        $('#newsletter_content_json').val(json);

        $.post(azureOrderRuleEditor.ajaxUrl, {
            action: 'azure_order_rule_save_email',
            nonce: azureOrderRuleEditor.nonce,
            rule_id: azureOrderRuleEditor.ruleId,
            email_subject: $('#newsletter_subject').val(),
            content_html: html,
            content_json: json
        }).done(function(response) {
            btn.prop('disabled', false).text(original);
            if (response && response.success) {
                $('#save-status').html('<span class="saved">✓ Saved</span>');
                setTimeout(function() { $('#save-status').html(''); }, 2500);
            } else {
                alert((response && response.data) || 'Could not save the email.');
            }
        }).fail(function() {
            btn.prop('disabled', false).text(original);
            alert('Network error. Please try again.');
        });
    });
})(jQuery);
