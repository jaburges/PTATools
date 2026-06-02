/**
 * Product Fields – child profile auto-population.
 *
 * When a logged-in user selects a child from the dropdown, swap each
 * child-scope field's value for that child's stored profile data. Lookup is
 * keyed by `data-field-key` (stable slug), with a label-based fallback so
 * legacy installs still hydrate from old label-keyed meta.
 *
 * Parent-scope fields are pre-filled server-side and ignored by the swap.
 *
 * Parent- and family-scope fields are pre-filled server-side and ignored by
 * the swap (family-scope is shared between co-parents, not per-child).
 *
 * Payload shape (set by class-product-fields-module.php):
 *   window.azurePtaProductFields = {
 *     children: { <child_id>: { name, fields: { <field_key>: value, ... } } },
 *     parent:   { <field_key>: value },
 *     family:   { <field_key>: value }
 *   }
 */
jQuery(function ($) {
    var $selector = $('#azure-pf-select-child');
    if (!$selector.length || typeof window.azurePtaProductFields === 'undefined') {
        return;
    }

    var data = window.azurePtaProductFields || {};
    var children = data.children || {};

    $selector.on('change', function () {
        var childId = parseInt($(this).val(), 10);

        if (!childId) {
            clearChildFields();
            return;
        }

        var child = children[childId];
        if (!child) {
            return;
        }

        populateChildFields(child);
    });

    function populateChildFields(child) {
        var fields = child.fields || {};

        $('.azure-product-fields .azure-pf-field').each(function () {
            var $field = $(this);
            var scope = $field.attr('data-field-scope');
            if (scope === 'parent' || scope === 'family') {
                return;
            }

            var $input = $field.find('input, textarea, select').first();
            if (!$input.length) {
                return;
            }

            var value = resolveValue($field, fields, child.name);
            if (value === null) {
                return;
            }

            applyValue($input, value);
        });
    }

    function resolveValue($field, fields, childName) {
        var fieldKey = $field.attr('data-field-key') || '';
        var labelText = $.trim($field.find('label').first().text().replace(/\*$/, '').replace(/\s+/g, ' '));
        var lower = labelText.toLowerCase();

        if (fieldKey && (fieldKey === 'child_name' || (lower.indexOf('child') !== -1 && lower.indexOf('name') !== -1))) {
            return childName;
        }

        if (fieldKey && Object.prototype.hasOwnProperty.call(fields, fieldKey)) {
            return fields[fieldKey];
        }

        // Legacy fallback: pre-consolidation children may still have meta
        // keyed by the original display label.
        var legacyKey = '__legacy__::' + labelText;
        if (Object.prototype.hasOwnProperty.call(fields, legacyKey)) {
            return fields[legacyKey];
        }
        for (var key in fields) {
            if (Object.prototype.hasOwnProperty.call(fields, key) && key.indexOf('__legacy__::') === 0) {
                if (key.substring('__legacy__::'.length).toLowerCase() === lower) {
                    return fields[key];
                }
            }
        }

        return null;
    }

    function applyValue($input, value) {
        if ($input.is(':checkbox')) {
            $input.prop('checked', value === 'Yes' || value === '1' || value === 'true');
        } else {
            $input.val(value).trigger('change');
        }
    }

    function clearChildFields() {
        $('.azure-product-fields .azure-pf-field').each(function () {
            var $field = $(this);
            var scope = $field.attr('data-field-scope');
            if (scope === 'parent' || scope === 'family') {
                return;
            }
            var $input = $field.find('input, textarea, select').first();
            if (!$input.length) {
                return;
            }
            if ($input.is(':checkbox')) {
                $input.prop('checked', false);
            } else {
                $input.val('');
            }
        });
    }

    // ─── Quick-add child modal ────────────────────────────────────────
    //
    // Opens when the "+ Child" button next to the dropdown is clicked.
    // POSTs to wp_ajax_azure_pf_quick_add_child (handled in
    // class-product-fields-module.php), then on success appends a new
    // <option> to the dropdown, auto-selects it, and triggers the
    // existing field-swap path so the form populates immediately.

    var $addBtn  = $('#azure-pf-add-child');
    var $modal   = $('#azure-pf-add-child-modal');
    var $newName = $('#azure-pf-new-child-name');
    var $error   = $('#azure-pf-add-child-error');
    var ajaxCfg  = (data && data.ajax) ? data.ajax : null;

    function showModal() {
        $error.hide().text('');
        $newName.val('');
        $modal.fadeIn(120).attr('aria-hidden', 'false');
        setTimeout(function () { $newName.trigger('focus'); }, 50);
    }
    function hideModal() {
        $modal.fadeOut(100).attr('aria-hidden', 'true');
    }
    function showError(msg) {
        $error.text(msg).show();
    }

    if ($addBtn.length && $modal.length && ajaxCfg) {
        $addBtn.on('click', function (e) {
            e.preventDefault();
            showModal();
        });
        $modal.on('click', '.azure-pf-modal-backdrop, .azure-pf-cancel-child', function () {
            hideModal();
        });
        $(document).on('keydown.azurePfModal', function (e) {
            if ($modal.is(':visible') && e.key === 'Escape') hideModal();
        });
        $newName.on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $('#azure-pf-save-child').trigger('click');
            }
        });

        $('#azure-pf-save-child').on('click', function () {
            var name = ($newName.val() || '').trim();
            if (!name) {
                showError('Please enter a child name.');
                $newName.trigger('focus');
                return;
            }
            var $btn = $(this).prop('disabled', true).text('Saving…');
            $error.hide();
            $.post(ajaxCfg.url, {
                action: 'azure_pf_quick_add_child',
                nonce: ajaxCfg.nonce_quick_add,
                child_name: name
            }, function (resp) {
                $btn.prop('disabled', false).text('Add child');
                if (resp && resp.success && resp.data && resp.data.id) {
                    var id = parseInt(resp.data.id, 10);
                    var label = resp.data.name || name;
                    // Append + auto-select. Then trigger change so the
                    // existing populateChildFields path runs even though
                    // there's no profile data yet (it'll just no-op the
                    // child-scope fields).
                    if (!$selector.find('option[value="' + id + '"]').length) {
                        $selector.append($('<option/>').val(id).text(label));
                    }
                    children[id] = { name: label, fields: {} };
                    $selector.val(id).trigger('change');
                    hideModal();
                } else {
                    showError((resp && resp.data && resp.data.message) ? resp.data.message : 'Could not add child.');
                }
            }).fail(function () {
                $btn.prop('disabled', false).text('Add child');
                showError('Network error. Please try again.');
            });
        });
    }
});
