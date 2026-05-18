/**
 * Role Editor CRUD: Add / Clone / Rename / Delete role + Re-seed PTA caps.
 *
 * Wires up the toolbar buttons and modals defined in admin/pta-role-editor-page.php
 * against the AJAX endpoints in includes/class-admin.php.
 *
 * Localized vars (provided via wp_localize_script as `ptaRoleCrud`):
 *   ajaxUrl         admin-ajax.php URL
 *   nonce           the azure_plugin_nonce value
 *   protectedRoles  list of role slugs that cannot be deleted from the UI
 *   pageUrl         URL of the role editor page (used to redirect after CRUD)
 *   strings         small l10n table for confirm dialogs / success messages
 */
(function ($) {
    'use strict';

    if (typeof ptaRoleCrud === 'undefined') {
        return;
    }

    var currentRoleSlug = $('.role-crud-bar').data('current-role') || '';
    var $backdrop = $('#pta-role-modal-backdrop');

    // ------------------------------------------------------------------
    // Modal show/hide helpers
    // ------------------------------------------------------------------

    function modalEl(name) {
        return document.getElementById('pta-role-modal-' + name);
    }

    function openModal(name) {
        var el = modalEl(name);
        if (!el) return;
        clearModalError(name);
        el.removeAttribute('hidden');
        $backdrop.removeAttr('hidden');
        // Focus the first input for keyboard users.
        var firstInput = el.querySelector('input:not([readonly]):not([type="hidden"]), select');
        if (firstInput) {
            try { firstInput.focus(); } catch (e) {}
        }
    }

    function closeModal(name) {
        var el = modalEl(name);
        if (!el) return;
        el.setAttribute('hidden', '');
        $backdrop.attr('hidden', '');
        clearModalError(name);
        // Clear any inputs so reopening the modal starts fresh.
        $(el).find('input[type="text"]').val('');
    }

    function setModalError(name, message) {
        var $body = $('#pta-role-modal-' + name + ' .pta-role-modal-body');
        clearModalError(name);
        $body.prepend($('<div class="role-crud-error"></div>').text(message));
    }

    function clearModalError(name) {
        $('#pta-role-modal-' + name + ' .role-crud-error').remove();
    }

    // Close handlers for X and Cancel buttons.
    $(document).on('click', '.pta-role-modal-close, .pta-role-modal-footer [data-action="cancel"]', function () {
        closeModal($(this).data('modal'));
    });

    // Close on backdrop click.
    $backdrop.on('click', function () {
        $('.pta-role-modal').not('[hidden]').each(function () {
            var name = this.id.replace('pta-role-modal-', '');
            closeModal(name);
        });
    });

    // Close on Escape.
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            $('.pta-role-modal').not('[hidden]').each(function () {
                var name = this.id.replace('pta-role-modal-', '');
                closeModal(name);
            });
        }
    });

    // ------------------------------------------------------------------
    // Slug auto-derive: when typing a display name in Add/Clone, suggest
    // a slug if the slug field is empty.
    // ------------------------------------------------------------------

    function suggestSlug(name) {
        return name.toString().toLowerCase()
            .replace(/[^a-z0-9_ ]+/g, '')
            .trim()
            .replace(/\s+/g, '_')
            .substring(0, 39);
    }

    function wireSlugSuggest(nameSelector, slugSelector) {
        $(document).on('input', nameSelector, function () {
            var $slug = $(slugSelector);
            // Don't overwrite a value the user typed themselves.
            if ($slug.data('user-edited')) return;
            $slug.val(suggestSlug($(this).val()));
        });
        $(document).on('input', slugSelector, function () {
            $(this).data('user-edited', $(this).val().length > 0);
        });
    }

    wireSlugSuggest('#pta-role-add-name', '#pta-role-add-slug');
    wireSlugSuggest('#pta-role-clone-name', '#pta-role-clone-slug');

    // ------------------------------------------------------------------
    // Toolbar button handlers
    // ------------------------------------------------------------------

    // Add role -------------------------------------------------------
    $('#role-crud-add').on('click', function () {
        $('#pta-role-add-slug').data('user-edited', false);
        openModal('add');
    });

    $('#pta-role-add-submit').on('click', function () {
        var $btn = $(this);
        var slug = $.trim($('#pta-role-add-slug').val());
        var name = $.trim($('#pta-role-add-name').val());
        var startFrom = $('#pta-role-add-startfrom').val();

        if (!slug || !name) {
            setModalError('add', 'Slug and display name are both required.');
            return;
        }
        if (!/^[a-z][a-z0-9_]{1,38}$/.test(slug)) {
            setModalError('add', 'Slug must start with a letter and contain only a-z, 0-9, and underscores (max 39 chars).');
            return;
        }

        $btn.prop('disabled', true).text('Creating...');
        $.post(ptaRoleCrud.ajaxUrl, {
            action: 'azure_add_role',
            nonce: ptaRoleCrud.nonce,
            slug: slug,
            display_name: name,
            start_from: startFrom
        }).done(function (resp) {
            if (resp && resp.success) {
                window.location.href = ptaRoleCrud.pageUrl + '&role=' + encodeURIComponent(resp.data.slug);
            } else {
                $btn.prop('disabled', false).text('Create role');
                setModalError('add', (resp && resp.data && (resp.data.message || resp.data)) || 'Create failed.');
            }
        }).fail(function (xhr) {
            $btn.prop('disabled', false).text('Create role');
            setModalError('add', 'Network error (HTTP ' + (xhr && xhr.status ? xhr.status : '?') + ').');
        });
    });

    // Clone role -----------------------------------------------------
    $('#role-crud-clone').on('click', function () {
        $('#pta-role-clone-source-label').text(currentRoleSlug);
        $('#pta-role-clone-slug').data('user-edited', false);
        openModal('clone');
    });

    $('#pta-role-clone-submit').on('click', function () {
        var $btn = $(this);
        var slug = $.trim($('#pta-role-clone-slug').val());
        var name = $.trim($('#pta-role-clone-name').val());

        if (!slug || !name) {
            setModalError('clone', 'New slug and display name are both required.');
            return;
        }
        if (!/^[a-z][a-z0-9_]{1,38}$/.test(slug)) {
            setModalError('clone', 'Slug must start with a letter and contain only a-z, 0-9, and underscores (max 39 chars).');
            return;
        }

        $btn.prop('disabled', true).text('Cloning...');
        $.post(ptaRoleCrud.ajaxUrl, {
            action: 'azure_clone_role',
            nonce: ptaRoleCrud.nonce,
            source_slug: currentRoleSlug,
            slug: slug,
            display_name: name
        }).done(function (resp) {
            if (resp && resp.success) {
                window.location.href = ptaRoleCrud.pageUrl + '&role=' + encodeURIComponent(resp.data.slug);
            } else {
                $btn.prop('disabled', false).text('Clone');
                setModalError('clone', (resp && resp.data && (resp.data.message || resp.data)) || 'Clone failed.');
            }
        }).fail(function (xhr) {
            $btn.prop('disabled', false).text('Clone');
            setModalError('clone', 'Network error (HTTP ' + (xhr && xhr.status ? xhr.status : '?') + ').');
        });
    });

    // Rename role ----------------------------------------------------
    $('#role-crud-rename').on('click', function () {
        if ($(this).prop('disabled')) return;
        $('#pta-role-rename-slug').val(currentRoleSlug);
        // Pull the current display name from the visible <option>.
        var displayText = $('#azure-role-select option:selected').text() || '';
        // Strip the trailing " (slug)" suffix.
        var displayName = displayText.replace(/\s*\([^)]+\)\s*$/, '').replace(/\s*—\s*Azure AD\s*$/, '');
        $('#pta-role-rename-name').val(displayName);
        openModal('rename');
    });

    $('#pta-role-rename-submit').on('click', function () {
        var $btn = $(this);
        var name = $.trim($('#pta-role-rename-name').val());
        if (!name) {
            setModalError('rename', 'Display name is required.');
            return;
        }

        $btn.prop('disabled', true).text('Renaming...');
        $.post(ptaRoleCrud.ajaxUrl, {
            action: 'azure_rename_role',
            nonce: ptaRoleCrud.nonce,
            slug: currentRoleSlug,
            display_name: name
        }).done(function (resp) {
            if (resp && resp.success) {
                window.location.href = ptaRoleCrud.pageUrl + '&role=' + encodeURIComponent(currentRoleSlug);
            } else {
                $btn.prop('disabled', false).text('Rename');
                setModalError('rename', (resp && resp.data && (resp.data.message || resp.data)) || 'Rename failed.');
            }
        }).fail(function (xhr) {
            $btn.prop('disabled', false).text('Rename');
            setModalError('rename', 'Network error (HTTP ' + (xhr && xhr.status ? xhr.status : '?') + ').');
        });
    });

    // Delete role ----------------------------------------------------
    function isProtected(slug) {
        var list = ptaRoleCrud.protectedRoles || [];
        for (var i = 0; i < list.length; i++) {
            if (list[i] === slug) return true;
        }
        return false;
    }

    $('#role-crud-delete').on('click', function () {
        if ($(this).prop('disabled')) return;
        if (isProtected(currentRoleSlug)) {
            alert('This role is protected and cannot be deleted.');
            return;
        }

        $('#pta-role-delete-slug-label').text(currentRoleSlug);
        $('#pta-role-delete-user-warning').attr('hidden', '');
        $('#pta-role-delete-reassign-row').attr('hidden', '');
        $('#pta-role-delete-user-count').text('0');

        // Fire a probe call with no reassign_to. If users exist, the server
        // returns user_count and we reveal the reassign picker. If zero
        // users, we just submit on confirm.
        $.post(ptaRoleCrud.ajaxUrl, {
            action: 'azure_delete_role',
            nonce: ptaRoleCrud.nonce,
            slug: currentRoleSlug,
            reassign_to: ''
        }).done(function (resp) {
            if (resp && !resp.success && resp.data && resp.data.user_count) {
                $('#pta-role-delete-user-count').text(resp.data.user_count);
                $('#pta-role-delete-user-warning').removeAttr('hidden');
                $('#pta-role-delete-reassign-row').removeAttr('hidden');
                openModal('delete');
            } else if (resp && resp.success) {
                // Edge case: the probe accidentally deleted the role (no users
                // and no reassign_to required). Refresh.
                window.location.href = ptaRoleCrud.pageUrl;
            } else {
                // No users assigned — open modal without warning.
                openModal('delete');
            }
        }).fail(function () {
            // Fall back to opening modal without the user count.
            openModal('delete');
        });
    });

    $('#pta-role-delete-submit').on('click', function () {
        var $btn = $(this);
        if (!confirm(ptaRoleCrud.strings.confirmDelete + '\n\nRole: ' + currentRoleSlug)) {
            return;
        }
        var reassignTo = $('#pta-role-delete-reassign-row').is(':visible') || !$('#pta-role-delete-reassign-row').attr('hidden')
            ? $('#pta-role-delete-reassign').val()
            : '';

        $btn.prop('disabled', true).text('Deleting...');
        $.post(ptaRoleCrud.ajaxUrl, {
            action: 'azure_delete_role',
            nonce: ptaRoleCrud.nonce,
            slug: currentRoleSlug,
            reassign_to: reassignTo
        }).done(function (resp) {
            if (resp && resp.success) {
                window.location.href = ptaRoleCrud.pageUrl;
            } else {
                $btn.prop('disabled', false).text('Delete role');
                setModalError('delete', (resp && resp.data && (resp.data.message || resp.data)) || 'Delete failed.');
            }
        }).fail(function (xhr) {
            $btn.prop('disabled', false).text('Delete role');
            setModalError('delete', 'Network error (HTTP ' + (xhr && xhr.status ? xhr.status : '?') + ').');
        });
    });

    // Stale plugin caps cleanup --------------------------------------
    $(document).on('click', '.stale-caps-cleanup-btn', function () {
        var $btn = $(this);
        if ($btn.prop('disabled')) return;
        var target = $btn.data('target');
        var label = $btn.data('label');
        var count = $btn.data('count');
        var roles = $btn.data('roles');

        var msg = 'Remove ' + count + ' stale "' + label + '" capability(ies) from ' + roles + ' role(s)?\n\n'
                + 'This is safe — the plugin is no longer active so no code consults these caps. '
                + 'Click cancel if you might re-activate the plugin and want existing user permissions preserved.';
        if (!confirm(msg)) return;

        $btn.prop('disabled', true).text('Cleaning...');
        $.post(ptaRoleCrud.ajaxUrl, {
            action: 'azure_cleanup_stale_caps',
            nonce: ptaRoleCrud.nonce,
            target: target,
            action_kind: 'cleanup'
        }).done(function (resp) {
            if (resp && resp.success) {
                var d = resp.data || {};
                alert('Removed ' + (d.caps_removed || 0) + ' cap(s) from ' + ((d.roles_touched || []).length) + ' role(s).');
                window.location.reload();
            } else {
                $btn.prop('disabled', false).text('Clean up ' + label + ' caps');
                var errMsg = (resp && resp.data && (resp.data.message || resp.data)) || 'Cleanup failed.';
                alert('Cleanup failed: ' + errMsg);
            }
        }).fail(function (xhr) {
            $btn.prop('disabled', false).text('Clean up ' + label + ' caps');
            alert('Cleanup network error (HTTP ' + (xhr && xhr.status ? xhr.status : '?') + ').');
        });
    });

    // Re-seed PTA caps -----------------------------------------------
    $('#role-crud-reseed').on('click', function () {
        var $btn = $(this);
        if ($btn.hasClass('pta-role-reseed-busy')) return;
        if (!confirm('Re-apply the default PTA Tools capability assignments to all roles?\n\nThis only ADDS missing caps — it never removes caps you may have customised.')) {
            return;
        }

        $btn.addClass('pta-role-reseed-busy');
        var origHtml = $btn.html();
        $btn.html('<span class="dashicons dashicons-update"></span> Re-seeding...');

        $.post(ptaRoleCrud.ajaxUrl, {
            action: 'azure_reseed_pta_caps',
            nonce: ptaRoleCrud.nonce
        }).done(function (resp) {
            $btn.removeClass('pta-role-reseed-busy').html(origHtml);
            if (resp && resp.success) {
                var added = (resp.data && resp.data.added) || 0;
                var skipped = (resp.data && resp.data.skipped) || 0;
                var roles = (resp.data && resp.data.roles_touched) ? resp.data.roles_touched.join(', ') : '';
                var msg = ptaRoleCrud.strings.reseedSuccess
                    + '\n\nAdded: ' + added
                    + '\nAlready present: ' + skipped
                    + (roles ? '\nRoles touched: ' + roles : '');
                alert(msg);
                // Reload so the editor's union-of-caps reflects new additions.
                window.location.reload();
            } else {
                alert('Re-seed failed: ' + ((resp && resp.data && (resp.data.message || resp.data)) || 'unknown error'));
            }
        }).fail(function (xhr) {
            $btn.removeClass('pta-role-reseed-busy').html(origHtml);
            alert('Re-seed network error (HTTP ' + (xhr && xhr.status ? xhr.status : '?') + ').');
        });
    });

})(jQuery);
