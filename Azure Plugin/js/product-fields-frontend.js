/**
 * Product Fields – child profile auto-population and family roster.
 *
 * When a logged-in user selects a child from the dropdown, swap each
 * child-scope field's value for that child's stored profile data. Lookup is
 * keyed by `data-field-key` (stable slug), with a label-based fallback so
 * legacy installs still hydrate from old label-keyed meta.
 *
 * Family PTSA membership uses a multi-child roster instead of the dropdown.
 * Logged-in + Child still saves to the profile via AJAX, then appends a card.
 * Guests append a blank name / year / teacher card.
 *
 * Parent- and family-scope fields are pre-filled server-side and ignored by
 * the swap (family-scope is shared between co-parents, not per-child).
 *
 * Payload shape (set by class-product-fields-module.php):
 *   window.azurePtaProductFields = {
 *     children: { <child_id>: { name, fields: { <field_key>: value, ... } } },
 *     parent:   { <field_key>: value },
 *     family:   { <field_key>: value },
 *     family_children_mode: bool
 *   }
 */
jQuery(function ($) {
    var data = window.azurePtaProductFields || {};
    var children = data.children || {};
    var familyMode = !!data.family_children_mode || $('#azure-pf-family-children').length > 0;
    var $selector = $('#azure-pf-select-child');

    if (!familyMode && !$selector.length && typeof window.azurePtaProductFields === 'undefined') {
        return;
    }

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

    // ─── Family roster cards ──────────────────────────────────────────

    var $familyList = $('#azure-pf-child-list');
    var $cardTemplate = $('#azure-pf-child-card-template');
    var nextFamilyIndex = $familyList.length ? $familyList.find('.azure-pf-child-card').length : 0;

    function nextCardIndex() {
        var index = nextFamilyIndex;
        nextFamilyIndex += 1;
        return index;
    }

    function appendFamilyCard(values) {
        if (!$familyList.length || !$cardTemplate.length) {
            return;
        }
        var html = $cardTemplate.html();
        if (!html) {
            return;
        }
        var index = nextCardIndex();
        html = html.split('__INDEX__').join(String(index));
        var $card = $(html);
        if (values) {
            if (values.id) {
                $card.find('.azure-pf-child-id').val(values.id);
            }
            if (values.name) {
                $card.find('.azure-pf-child-name').val(values.name);
                if (values.locked) {
                    $card.find('.azure-pf-child-name').prop('readonly', true);
                }
            }
            if (values.grade) {
                var $grade = $card.find('.azure-pf-child-grade');
                if (values.grade && $grade.find('option[value="' + values.grade + '"]').length === 0) {
                    $grade.append($('<option/>').val(values.grade).text(values.grade));
                }
                $grade.val(values.grade);
            }
            if (values.teacher) {
                var $teacher = $card.find('.azure-pf-child-teacher');
                if ($teacher.is('select') && $teacher.find('option[value="' + values.teacher + '"]').length === 0) {
                    $teacher.append($('<option/>').val(values.teacher).text(values.teacher));
                }
                $teacher.val(values.teacher);
            }
        }
        $familyList.append($card);
        $card.find('.azure-pf-child-name').trigger('focus');
    }

    $familyList.on('click', '.azure-pf-remove-child', function (e) {
        e.preventDefault();
        $(this).closest('.azure-pf-child-card').remove();
    });

    // ─── Quick-add child modal ────────────────────────────────────────
    //
    // Opens when the "+ Child" button next to the dropdown is clicked.
    // POSTs to wp_ajax_azure_pf_quick_add_child (handled in
    // class-product-fields-module.php), then on success appends a new
    // <option> to the dropdown, auto-selects it, and triggers the
    // existing field-swap path so the form populates immediately.

    var $addBtn     = $('#azure-pf-add-child');
    var $modal      = $('#azure-pf-add-child-modal');
    var $newName    = $('#azure-pf-new-child-name');
    var $newGrade   = $('#azure-pf-new-child-grade');
    var $newTeacher = $('#azure-pf-new-child-teacher');
    var $error      = $('#azure-pf-add-child-error');
    var ajaxCfg     = (data && data.ajax) ? data.ajax : null;
    var requireDetails = !!data.require_child_details || familyMode;

    function showModal() {
        $error.hide().text('');
        $newName.val('');
        $newGrade.val('');
        $newTeacher.val('');
        $modal.fadeIn(120).attr('aria-hidden', 'false');
        setTimeout(function () { $newName.trigger('focus'); }, 50);
    }
    function hideModal() {
        $modal.fadeOut(100).attr('aria-hidden', 'true');
    }
    function showError(msg) {
        $error.text(msg).show();
    }

    if ($addBtn.length) {
        $addBtn.on('click', function (e) {
            e.preventDefault();
            if (familyMode && !ajaxCfg) {
                appendFamilyCard(null);
                return;
            }
            if ($modal.length && ajaxCfg) {
                showModal();
                return;
            }
            if (familyMode) {
                appendFamilyCard(null);
            }
        });
    }

    if ($addBtn.length && $modal.length && ajaxCfg) {
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
            var grade = $newGrade.val() || '';
            var teacher = ($newTeacher.val() || '').trim();
            if (requireDetails && !grade) {
                showError('Please choose a year.');
                $newGrade.trigger('focus');
                return;
            }
            if (requireDetails && !teacher) {
                showError('Please enter a teacher.');
                $newTeacher.trigger('focus');
                return;
            }
            var $btn = $(this).prop('disabled', true).text('Saving…');
            $error.hide();
            $.post(ajaxCfg.url, {
                action: 'azure_pf_quick_add_child',
                nonce: ajaxCfg.nonce_quick_add,
                child_name: name,
                child_grade: grade,
                child_teacher: teacher
            }, function (resp) {
                $btn.prop('disabled', false).text('Add child');
                if (resp && resp.success && resp.data && resp.data.id) {
                    var id = parseInt(resp.data.id, 10);
                    var label = resp.data.name || name;
                    var fields = resp.data.fields || {};
                    children[id] = { name: label, fields: fields };
                    if (familyMode) {
                        var gradeKey = Object.keys(fields).filter(function (k) {
                            return k.indexOf('grade') !== -1;
                        })[0];
                        var teacherKey = Object.keys(fields).filter(function (k) {
                            return k.indexOf('teacher') !== -1;
                        })[0];
                        appendFamilyCard({
                            id: id,
                            name: label,
                            grade: gradeKey ? fields[gradeKey] : grade,
                            teacher: teacherKey ? fields[teacherKey] : teacher,
                            locked: true
                        });
                    } else if ($selector.length) {
                        if (!$selector.find('option[value="' + id + '"]').length) {
                            $selector.append($('<option/>').val(id).text(label));
                        }
                        $selector.val(id).trigger('change');
                    }
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
