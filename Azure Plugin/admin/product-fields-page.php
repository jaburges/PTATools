<?php
/**
 * Product Fields Module Admin Page
 */

if (!defined('ABSPATH')) {
    exit;
}

$settings = Azure_Settings::get_all_settings();
$module_enabled = !empty($settings['enable_product_fields']);

global $wpdb;
$grp_table = Azure_Database::get_table_name('product_field_groups');
$fld_table = Azure_Database::get_table_name('product_fields');
$cat_table = Azure_Database::get_table_name('product_field_categories');

$groups = array();
if ($grp_table && $wpdb->get_var("SHOW TABLES LIKE '{$grp_table}'") === $grp_table) {
    $groups = $wpdb->get_results("SELECT g.*, (SELECT COUNT(*) FROM {$fld_table} WHERE group_id = g.id) AS field_count FROM {$grp_table} g ORDER BY g.sort_order ASC, g.id ASC");
    foreach ($groups as &$group) {
        $group->categories = $wpdb->get_col($wpdb->prepare(
            "SELECT term_id FROM {$cat_table} WHERE group_id = %d", $group->id
        ));
        $group->fields = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$fld_table} WHERE group_id = %d ORDER BY sort_order ASC", $group->id
        ));
    }
    unset($group);
}

$product_categories = get_terms(array(
    'taxonomy'   => 'product_cat',
    'hide_empty' => false,
    'orderby'    => 'name',
));
if (is_wp_error($product_categories)) {
    $product_categories = array();
}
?>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
<div class="wrap azure-product-fields-page">
    <h1>
        <span class="dashicons dashicons-forms"></span>
        <?php _e('Product Fields', 'azure-plugin'); ?>
    </h1>
<?php else: ?>
<div class="azure-product-fields-page">
<?php endif; ?>

    <?php if (!class_exists('WooCommerce')): ?>
        <div class="notice notice-error" style="margin: 15px 0;"><p><strong>WooCommerce is required</strong> for the Product Fields module.</p></div>
        <?php return; ?>
    <?php endif; ?>

    <?php if (!$module_enabled): ?>
        <div class="notice notice-warning" style="margin: 15px 0;">
            <p><?php _e('The Product Fields module is currently disabled.', 'azure-plugin'); ?>
            <a href="<?php echo admin_url('admin.php?page=azure-plugin'); ?>"><?php _e('Enable it on the main settings page.', 'azure-plugin'); ?></a></p>
        </div>
    <?php endif; ?>

    <p class="description" style="margin: 8px 0 16px;"><?php _e('Create reusable field groups and assign them to WooCommerce product categories. Fields with "Save to Profile" will remember values for returning customers.', 'azure-plugin'); ?></p>

    <div class="azure-pf-layout" style="display: flex; gap: 20px; margin-top: 20px;">

        <!-- Left: Group List -->
        <div class="azure-pf-groups-panel" style="flex: 1; max-width: 380px;">
            <div class="postbox">
                <div class="postbox-header" style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;">
                    <h2 style="margin:0;">Field Groups</h2>
                    <button type="button" class="button button-primary" id="azure-pf-add-group"><span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;"></span> New Group</button>
                </div>
                <div class="inside" style="padding:0;">
                    <?php if (empty($groups)): ?>
                        <p style="padding: 12px; color: #666;">No field groups yet. Click "New Group" to create one.</p>
                    <?php else: ?>
                        <table class="wp-list-table widefat fixed striped" style="border:0;">
                            <tbody id="azure-pf-group-list">
                                <?php foreach ($groups as $g): ?>
                                <tr class="azure-pf-group-row" data-group-id="<?php echo esc_attr($g->id); ?>">
                                    <td>
                                        <strong class="azure-pf-group-name" style="cursor:pointer;color:#0073aa;"><?php echo esc_html($g->name); ?></strong>
                                        <div style="color:#666;font-size:12px;">
                                            <?php echo intval($g->field_count); ?> field(s)
                                            <?php if (!$g->is_active): ?><span style="color:#dc3232;"> &mdash; Inactive</span><?php endif; ?>
                                        </div>
                                        <div style="font-size:11px;color:#888;margin-top:2px;">
                                            <?php
                                            $cat_names = array();
                                            foreach ($g->categories as $tid) {
                                                $term = get_term($tid, 'product_cat');
                                                if ($term && !is_wp_error($term)) {
                                                    $cat_names[] = $term->name;
                                                }
                                            }
                                            echo $cat_names ? esc_html(implode(', ', $cat_names)) : '<em>No categories</em>';
                                            ?>
                                        </div>
                                    </td>
                                    <td style="width:80px;text-align:right;">
                                        <button type="button" class="button button-small azure-pf-edit-group" title="Edit"><span class="dashicons dashicons-edit" style="vertical-align:middle;"></span></button>
                                        <button type="button" class="button button-small azure-pf-delete-group" title="Delete" style="color:#dc3232;"><span class="dashicons dashicons-trash" style="vertical-align:middle;"></span></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right: Group Editor / Fields -->
        <div class="azure-pf-editor-panel" style="flex: 2;">
            <div id="azure-pf-editor-placeholder" class="postbox" style="padding:40px;text-align:center;color:#666;">
                <span class="dashicons dashicons-forms" style="font-size:48px;color:#ccc;display:block;margin-bottom:10px;"></span>
                <p>Select a field group to edit, or create a new one.</p>
            </div>

            <div id="azure-pf-editor" style="display:none;">
                <div class="postbox">
                    <div class="postbox-header" style="padding:8px 12px;">
                        <h2 id="azure-pf-editor-title" style="margin:0;">Edit Group</h2>
                    </div>
                    <div class="inside">
                        <form id="azure-pf-group-form">
                            <input type="hidden" name="id" id="azure-pf-group-id" value="0" />
                            <table class="form-table">
                                <tr>
                                    <th><label for="azure-pf-group-name">Group Name</label></th>
                                    <td><input type="text" id="azure-pf-group-name" name="name" class="regular-text" required /></td>
                                </tr>
                                <tr>
                                    <th><label for="azure-pf-group-desc">Description</label></th>
                                    <td><textarea id="azure-pf-group-desc" name="description" class="large-text" rows="2"></textarea></td>
                                </tr>
                                <tr>
                                    <th><label>Product Categories</label></th>
                                    <td>
                                        <div id="azure-pf-cat-list" style="max-height:200px;overflow-y:auto;border:1px solid #ddd;padding:8px;border-radius:4px;">
                                            <?php foreach ($product_categories as $cat): ?>
                                                <label style="display:block;margin-bottom:4px;">
                                                    <input type="checkbox" name="categories[]" value="<?php echo esc_attr($cat->term_id); ?>" />
                                                    <?php echo esc_html($cat->name); ?>
                                                    <span style="color:#999;">(<?php echo $cat->count; ?>)</span>
                                                </label>
                                            <?php endforeach; ?>
                                            <?php if (empty($product_categories)): ?>
                                                <p style="color:#999;">No product categories found. Create categories in WooCommerce first.</p>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label>Active</label></th>
                                    <td><label><input type="checkbox" name="is_active" id="azure-pf-group-active" value="1" checked /> Show fields on product pages</label></td>
                                </tr>
                            </table>
                            <p>
                                <button type="submit" class="button button-primary">Save Group</button>
                                <button type="button" class="button" id="azure-pf-cancel-group">Cancel</button>
                            </p>
                        </form>
                    </div>
                </div>

                <!-- Fields Section -->
                <div class="postbox" style="margin-top:15px;" id="azure-pf-fields-section">
                    <div class="postbox-header" style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;">
                        <h2 style="margin:0;">Fields</h2>
                        <button type="button" class="button button-primary button-small" id="azure-pf-add-field"><span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;"></span> Add Field</button>
                    </div>
                    <div class="inside" style="padding:0;">
                        <table class="wp-list-table widefat fixed striped" id="azure-pf-fields-table">
                            <thead>
                                <tr>
                                    <th style="width:30px;"></th>
                                    <th>Label</th>
                                    <th style="width:140px;">Key</th>
                                    <th style="width:80px;">Scope</th>
                                    <th style="width:100px;">Type</th>
                                    <th style="width:70px;">Required</th>
                                    <th style="width:80px;">Profile</th>
                                    <th style="width:100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="azure-pf-fields-body">
                                <tr class="azure-pf-no-fields"><td colspan="8" style="text-align:center;color:#999;">No fields yet. Add one above.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Field Edit Modal -->
    <div id="azure-pf-field-modal" style="display:none;">
        <div class="azure-pf-modal-overlay" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:100000;">
            <div class="azure-pf-modal-content" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:8px;padding:24px;width:520px;max-height:80vh;overflow-y:auto;box-shadow:0 4px 20px rgba(0,0,0,0.3);">
                <h3 id="azure-pf-field-modal-title" style="margin-top:0;">Add Field</h3>
                <form id="azure-pf-field-form">
                    <input type="hidden" name="id" id="azure-pf-field-id" value="0" />
                    <input type="hidden" name="group_id" id="azure-pf-field-group-id" value="0" />

                    <p>
                        <label><strong>Label</strong></label><br>
                        <input type="text" name="label" id="azure-pf-field-label" class="regular-text" style="width:100%;" required />
                        <br><small>The display label shown to customers. Safe to edit later.</small>
                    </p>
                    <p>
                        <label><strong>Field Key</strong></label><br>
                        <input type="text" name="field_key" id="azure-pf-field-key" class="regular-text" style="width:100%;" />
                        <br><small id="azure-pf-field-key-hint">Stable storage slug (used for line-item meta and profile keys). Auto-generated from the label on first save and immutable thereafter.</small>
                    </p>
                    <p>
                        <label><strong>Scope</strong></label><br>
                        <select name="scope" id="azure-pf-field-scope" style="width:100%;">
                            <option value="child">Child profile (saved per child)</option>
                            <option value="parent">Parent profile (saved on the user account)</option>
                            <option value="family">Family profile (shared between co-parents on the same account)</option>
                        </select>
                        <br><small>Determines where the value is read from on the product page and saved to after checkout.</small>
                    </p>
                    <p>
                        <label><strong>Type</strong></label><br>
                        <select name="field_type" id="azure-pf-field-type" style="width:100%;">
                            <option value="text">Text</option>
                            <option value="email">Email</option>
                            <option value="tel">Phone</option>
                            <option value="number">Number</option>
                            <option value="textarea">Text Area</option>
                            <option value="select">Dropdown</option>
                            <option value="checkbox">Checkbox</option>
                        </select>
                    </p>
                    <p>
                        <label><strong>Placeholder</strong></label><br>
                        <input type="text" name="placeholder" id="azure-pf-field-placeholder" class="regular-text" style="width:100%;" />
                    </p>
                    <p id="azure-pf-options-wrap" style="display:none;">
                        <label><strong>Options</strong> <small>(one per line)</small></label><br>
                        <textarea name="options" id="azure-pf-field-options" rows="4" style="width:100%;"></textarea>
                    </p>
                    <p>
                        <label><input type="checkbox" name="required" id="azure-pf-field-required" value="1" /> <strong>Required</strong></label>
                    </p>
                    <p>
                        <label><input type="checkbox" name="save_to_profile" id="azure-pf-field-save-profile" value="1" /> <strong>Save to user profile</strong></label>
                        <br><small>Saves the value to the user's account and auto-fills it on their next purchase.</small>
                    </p>
                    <p id="azure-pf-meta-key-wrap" style="display:none;">
                        <label><strong>User Meta Key</strong></label><br>
                        <input type="text" name="user_meta_key" id="azure-pf-field-meta-key" class="regular-text" style="width:100%;" />
                        <br><small>Auto-generated from label if left blank.</small>
                    </p>
                    <p style="text-align:right;">
                        <button type="button" class="button" id="azure-pf-field-cancel">Cancel</button>
                        <button type="submit" class="button button-primary">Save Field</button>
                    </p>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(function($) {
    var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
    var nonce = '<?php echo esc_js(wp_create_nonce('azure_plugin_nonce')); ?>';
    var currentGroupId = 0;

    // ── Show/hide options for select type ──
    $('#azure-pf-field-type').on('change', function() {
        $('#azure-pf-options-wrap').toggle($(this).val() === 'select');
    });

    // ── Show/hide meta key when save-to-profile checked ──
    $('#azure-pf-field-save-profile').on('change', function() {
        $('#azure-pf-meta-key-wrap').toggle($(this).is(':checked'));
    });

    // ── New Group ──
    $('#azure-pf-add-group').on('click', function() {
        currentGroupId = 0;
        resetGroupForm();
        $('#azure-pf-editor-title').text('New Field Group');
        $('#azure-pf-editor').show();
        $('#azure-pf-editor-placeholder').hide();
        $('#azure-pf-fields-section').hide();
    });

    // ── Edit Group ──
    $(document).on('click', '.azure-pf-edit-group, .azure-pf-group-name', function() {
        var row = $(this).closest('.azure-pf-group-row');
        var id = row.data('group-id');
        loadGroup(id);
    });

    function loadGroup(id) {
        $.post(ajaxUrl, { action: 'azure_pf_get_group', nonce: nonce, id: id }, function(res) {
            if (!res.success) return;
            var g = res.data;
            currentGroupId = g.id;
            $('#azure-pf-group-id').val(g.id);
            $('#azure-pf-group-name').val(g.name);
            $('#azure-pf-group-desc').val(g.description);
            $('#azure-pf-group-active').prop('checked', g.is_active == 1);

            // Check categories
            $('#azure-pf-cat-list input').prop('checked', false);
            (g.categories || []).forEach(function(tid) {
                $('#azure-pf-cat-list input[value="' + tid + '"]').prop('checked', true);
            });

            renderFields(g.fields || []);

            $('#azure-pf-editor-title').text('Edit: ' + g.name);
            $('#azure-pf-editor').show();
            $('#azure-pf-editor-placeholder').hide();
            $('#azure-pf-fields-section').show();
        });
    }

    function resetGroupForm() {
        $('#azure-pf-group-id').val(0);
        $('#azure-pf-group-name').val('');
        $('#azure-pf-group-desc').val('');
        $('#azure-pf-group-active').prop('checked', true);
        $('#azure-pf-cat-list input').prop('checked', false);
        renderFields([]);
    }

    // ── Save Group ──
    $('#azure-pf-group-form').on('submit', function(e) {
        e.preventDefault();
        var cats = [];
        $('#azure-pf-cat-list input:checked').each(function() { cats.push($(this).val()); });

        var $btn = $('#azure-pf-group-form button[type="submit"]');
        $btn.prop('disabled', true).text('Saving...');

        $.post(ajaxUrl, {
            action: 'azure_pf_save_group',
            nonce: nonce,
            id: $('#azure-pf-group-id').val(),
            name: $('#azure-pf-group-name').val(),
            description: $('#azure-pf-group-desc').val(),
            is_active: $('#azure-pf-group-active').is(':checked') ? 1 : 0,
            categories: cats
        }, function(res) {
            if (res.success) {
                location.reload();
            } else {
                alert(res.data || 'Error saving group');
                $btn.prop('disabled', false).text('Save Group');
            }
        }).fail(function(xhr) {
            alert('Save failed: ' + xhr.status + ' ' + xhr.statusText + '\n' + (xhr.responseText || '').substring(0, 200));
            $btn.prop('disabled', false).text('Save Group');
        });
    });

    // ── Cancel ──
    $('#azure-pf-cancel-group').on('click', function() {
        $('#azure-pf-editor').hide();
        $('#azure-pf-editor-placeholder').show();
        currentGroupId = 0;
    });

    // ── Delete Group ──
    $(document).on('click', '.azure-pf-delete-group', function() {
        var row = $(this).closest('.azure-pf-group-row');
        var id = row.data('group-id');
        if (!confirm('Delete this group and all its fields?')) return;

        $.post(ajaxUrl, { action: 'azure_pf_delete_group', nonce: nonce, id: id }, function(res) {
            if (res.success) {
                row.fadeOut(300, function() { $(this).remove(); });
                if (currentGroupId == id) {
                    $('#azure-pf-editor').hide();
                    $('#azure-pf-editor-placeholder').show();
                    currentGroupId = 0;
                }
            }
        });
    });

    // ── Render Fields Table ──
    function renderFields(fields) {
        var $body = $('#azure-pf-fields-body');
        $body.empty();
        if (!fields.length) {
            $body.html('<tr class="azure-pf-no-fields"><td colspan="8" style="text-align:center;color:#999;">No fields yet. Add one above.</td></tr>');
            return;
        }
        fields.forEach(function(f) {
            var scopeLabel = (f.scope === 'parent') ? 'Parent' : (f.scope === 'family' ? 'Family' : 'Child');
            $body.append(
                '<tr data-field-id="' + f.id + '">' +
                '<td><span class="dashicons dashicons-move" style="cursor:grab;color:#999;"></span></td>' +
                '<td><strong>' + escHtml(f.label) + '</strong></td>' +
                '<td><code style="font-size:11px;">' + escHtml(f.field_key || '—') + '</code></td>' +
                '<td>' + escHtml(scopeLabel) + '</td>' +
                '<td>' + escHtml(f.field_type) + '</td>' +
                '<td>' + (f.required == 1 ? '<span class="dashicons dashicons-yes" style="color:#46b450;"></span>' : '') + '</td>' +
                '<td>' + (f.save_to_profile == 1 ? '<span class="dashicons dashicons-admin-users" style="color:#0073aa;" title="' + escHtml(f.user_meta_key) + '"></span>' : '') + '</td>' +
                '<td>' +
                    '<button type="button" class="button button-small azure-pf-edit-field">Edit</button> ' +
                    '<button type="button" class="button button-small azure-pf-delete-field" style="color:#dc3232;">Delete</button>' +
                '</td>' +
                '</tr>'
            );
        });
    }

    function escHtml(str) {
        return $('<span>').text(str || '').html();
    }

    // ── Add Field ──
    $('#azure-pf-add-field').on('click', function() {
        resetFieldForm();
        $('#azure-pf-field-group-id').val(currentGroupId);
        $('#azure-pf-field-modal-title').text('Add Field');
        $('#azure-pf-field-modal').show();
    });

    // ── Edit Field ──
    $(document).on('click', '.azure-pf-edit-field', function() {
        var row = $(this).closest('tr');
        var fid = row.data('field-id');
        // Find the field in the current group data by re-loading group
        $.post(ajaxUrl, { action: 'azure_pf_get_group', nonce: nonce, id: currentGroupId }, function(res) {
            if (!res.success) return;
            var field = (res.data.fields || []).find(function(f) { return f.id == fid; });
            if (!field) return;

            $('#azure-pf-field-id').val(field.id);
            $('#azure-pf-field-group-id').val(field.group_id);
            $('#azure-pf-field-label').val(field.label);
            $('#azure-pf-field-key').val(field.field_key || '').prop('readonly', !!field.field_key);
            $('#azure-pf-field-key-hint').text(field.field_key ? 'Locked: this slug is referenced by existing orders and child profiles.' : 'Auto-generated from the label on first save and immutable thereafter.');
            $('#azure-pf-field-scope').val(field.scope || 'child');
            $('#azure-pf-field-type').val(field.field_type).trigger('change');
            $('#azure-pf-field-placeholder').val(field.placeholder);
            $('#azure-pf-field-required').prop('checked', field.required == 1);
            $('#azure-pf-field-save-profile').prop('checked', field.save_to_profile == 1).trigger('change');
            $('#azure-pf-field-meta-key').val(field.user_meta_key);

            if (field.field_type === 'select' && field.options_json) {
                try {
                    var opts = JSON.parse(field.options_json);
                    $('#azure-pf-field-options').val(opts.join("\n"));
                } catch(e) {}
            }

            $('#azure-pf-field-modal-title').text('Edit Field');
            $('#azure-pf-field-modal').show();
        });
    });

    // ── Delete Field ──
    $(document).on('click', '.azure-pf-delete-field', function() {
        var row = $(this).closest('tr');
        var fid = row.data('field-id');
        if (!confirm('Delete this field?')) return;

        $.post(ajaxUrl, { action: 'azure_pf_delete_field', nonce: nonce, id: fid }, function(res) {
            if (res.success) {
                row.fadeOut(300, function() { $(this).remove(); });
            }
        });
    });

    // ── Save Field ──
    $('#azure-pf-field-form').on('submit', function(e) {
        e.preventDefault();
        var $fbtn = $('#azure-pf-field-form button[type="submit"]');
        $fbtn.prop('disabled', true).text('Saving...');

        $.post(ajaxUrl, {
            action: 'azure_pf_save_field',
            nonce: nonce,
            id: $('#azure-pf-field-id').val(),
            group_id: $('#azure-pf-field-group-id').val(),
            label: $('#azure-pf-field-label').val(),
            field_key: $('#azure-pf-field-key').val(),
            scope: $('#azure-pf-field-scope').val(),
            field_type: $('#azure-pf-field-type').val(),
            placeholder: $('#azure-pf-field-placeholder').val(),
            options: $('#azure-pf-field-options').val(),
            required: $('#azure-pf-field-required').is(':checked') ? 1 : 0,
            save_to_profile: $('#azure-pf-field-save-profile').is(':checked') ? 1 : 0,
            user_meta_key: $('#azure-pf-field-meta-key').val()
        }, function(res) {
            $fbtn.prop('disabled', false).text('Save Field');
            if (res.success) {
                $('#azure-pf-field-modal').hide();
                loadGroup(currentGroupId);
            } else {
                alert(res.data || 'Error saving field');
            }
        }).fail(function(xhr) {
            alert('Save failed: ' + xhr.status + ' ' + xhr.statusText);
            $fbtn.prop('disabled', false).text('Save Field');
        });
    });

    // ── Cancel Field Modal ──
    $('#azure-pf-field-cancel, .azure-pf-modal-overlay').on('click', function(e) {
        if (e.target === this) {
            $('#azure-pf-field-modal').hide();
        }
    });
    // Don't close when clicking inside modal content
    $('.azure-pf-modal-content').on('click', function(e) { e.stopPropagation(); });

    function resetFieldForm() {
        $('#azure-pf-field-id').val(0);
        $('#azure-pf-field-label').val('');
        $('#azure-pf-field-key').val('').prop('readonly', false);
        $('#azure-pf-field-key-hint').text('Auto-generated from the label on first save and immutable thereafter.');
        $('#azure-pf-field-scope').val('child');
        $('#azure-pf-field-type').val('text').trigger('change');
        $('#azure-pf-field-placeholder').val('');
        $('#azure-pf-field-options').val('');
        $('#azure-pf-field-required').prop('checked', false);
        $('#azure-pf-field-save-profile').prop('checked', false).trigger('change');
        $('#azure-pf-field-meta-key').val('');
    }
});
</script>

<style>
.azure-product-fields-page .module-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 4px;
    font-weight: 600;
    margin-bottom: 10px;
}
.azure-product-fields-page .module-status.enabled {
    background: #edfaef;
    color: #2e7d32;
}
.azure-product-fields-page .module-status.disabled {
    background: #fef3f2;
    color: #dc3232;
}
.azure-product-fields-page .postbox {
    margin-bottom: 0;
}
.azure-product-fields-page .postbox-header {
    border-bottom: 1px solid #ddd;
    background: #f9f9f9;
}
.azure-pf-group-row:hover {
    background: #f0f6fc !important;
}
#azure-pf-fields-table .dashicons-move:hover {
    color: #0073aa !important;
}
</style>
