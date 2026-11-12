(function($) {
    'use strict';

    function config() {
        return window.azureAdminMenuEditor || {};
    }

    function currentRole() {
        return $('#azure-ame-role').val() || config().defaultRole || '';
    }

    function hiddenFor(role) {
        var hidden = config().hidden || {};
        return hidden[role] ? hidden[role].slice() : [];
    }

    function lockedFor(role) {
        var locked = config().locked || {};
        return locked[role] || [];
    }

    function setRowState($row, visible, locked) {
        var $box = $row.is('label')
            ? $row.find('.azure-ame-check').first()
            : $row.children('.azure-ame-row').find('.azure-ame-check').first();
        $row.toggleClass('is-hidden', !visible);
        $row.toggleClass('is-locked', !!locked);
        $box.prop('checked', visible);
        $box.prop('disabled', !!locked);
    }

    function applyRole(role) {
        var hidden = hiddenFor(role);
        var locked = lockedFor(role);
        $('#azure-ame-lock-note').prop('hidden', role !== 'administrator');

        $('#azure-ame-sidebar .azure-ame-item').each(function() {
            var $item = $(this);
            var parentId = String($item.data('id') || '');
            var parentVisible = hidden.indexOf(parentId) === -1;
            var parentLocked = locked.indexOf(parentId) !== -1;
            setRowState($item, parentVisible, parentLocked);
            $item.find('.azure-ame-child').each(function() {
                var $child = $(this);
                var childId = String($child.data('id') || '');
                var ownVisible = hidden.indexOf(childId) === -1;
                var childLocked = locked.indexOf(childId) !== -1;
                $child.data('ownVisible', ownVisible);
                setRowState($child, parentVisible && ownVisible, childLocked);
            });
        });
    }

    // A hidden parent hides its children, so only children of visible
    // parents are saved individually.
    function collectHidden() {
        var hidden = [];
        $('#azure-ame-sidebar .azure-ame-item').each(function() {
            var $item = $(this);
            var $parentBox = $item.children('.azure-ame-row').find('.azure-ame-check').first();
            if (!$parentBox.prop('checked')) {
                hidden.push($parentBox.val());
                return;
            }
            $item.find('.azure-ame-child .azure-ame-check').each(function() {
                if (!this.checked) {
                    hidden.push(this.value);
                }
            });
        });
        return hidden;
    }

    $(function() {
        if (!$('#azure-ame-sidebar').length) {
            return;
        }

        applyRole(currentRole());

        $('#azure-ame-role').on('change', function() {
            applyRole(currentRole());
            $('#azure-ame-status').text('');
        });

        $('#azure-ame-sidebar').on('change', '.azure-ame-check', function() {
            var $box = $(this);
            var $item = $box.closest('.azure-ame-item');
            var isParent = $box.closest('.azure-ame-child').length === 0;
            if (isParent) {
                var checked = $box.prop('checked');
                $item.toggleClass('is-hidden', !checked);
                $item.find('.azure-ame-child').each(function() {
                    var $child = $(this);
                    var $childBox = $child.find('.azure-ame-check').first();
                    if (!checked) {
                        $child.data('ownVisible', $childBox.prop('checked'));
                        $child.addClass('is-hidden');
                        $childBox.prop('checked', false);
                    } else {
                        var own = $child.data('ownVisible') !== false;
                        $child.toggleClass('is-hidden', !own);
                        $childBox.prop('checked', own);
                    }
                });
            } else {
                $box.closest('.azure-ame-child').toggleClass('is-hidden', !$box.prop('checked'));
            }
        });

        $('#azure-ame-save').on('click', function() {
            var role = currentRole();
            var $status = $('#azure-ame-status');
            var $btn = $(this);
            $btn.prop('disabled', true);
            $status.text('Saving…');
            $.post(config().ajaxUrl, {
                action: 'azure_save_admin_menu_visibility',
                nonce: config().nonce,
                role: role,
                hidden: collectHidden()
            }).done(function(res) {
                if (res && res.success) {
                    config().hidden[role] = res.data.hidden || [];
                    $status.text('Saved');
                    applyRole(role);
                } else {
                    $status.text((res && res.data) ? res.data : 'Save failed');
                }
            }).fail(function() {
                $status.text('Save failed');
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });
    });
})(jQuery);
