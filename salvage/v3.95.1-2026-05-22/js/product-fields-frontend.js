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
});
