<?php
/**
 * My Account → Profile template
 *
 * Rendered by `Azure_User_Children::render_my_account_page()`. Expects the
 * following variables to be set in scope:
 *   $module          (Azure_User_Children instance, used for render helpers)
 *   $children        array of child rows for the user
 *   $parent_meta     array of parent-scope field defs ({key,label,type,...})
 *   $parent_vals     array of saved parent values keyed by user-meta key
 *   $child_fields    array of child-scope field defs (used by the modal JS)
 *   $child_label_map array mapping meta key → display label
 *   $family          connected_family row for the user, or null
 *   $family_meta     array of family-scope field defs ({key,label,type,...})
 *   $family_vals     array of saved family values keyed by user-meta key
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="azure-my-children-page">

    <?php if (!empty($parent_meta)):
        // Pair parent_1_* / parent_2_* fields into the same row (Name | Name,
        // Email | Email, Cell | Cell). Keys we pair: parent_1_name with
        // parent_2_name, etc. Anything else falls into a single-column tail.
        $parent_pairs = array();
        $parent_tail  = array();
        $by_key = array();
        foreach ($parent_meta as $f) {
            $by_key[$f['key']] = $f;
        }
        $sub_keys = array('name', 'email', 'cell');
        foreach ($sub_keys as $sub) {
            $k1 = 'pta_pf_parent_1_' . $sub;
            $k2 = 'pta_pf_parent_2_' . $sub;
            if (isset($by_key[$k1]) || isset($by_key[$k2])) {
                $parent_pairs[] = array(
                    'left'  => isset($by_key[$k1]) ? $by_key[$k1] : null,
                    'right' => isset($by_key[$k2]) ? $by_key[$k2] : null,
                );
                unset($by_key[$k1], $by_key[$k2]);
            }
        }
        $parent_tail = array_values($by_key);
    ?>
    <div class="azure-uc-section">
        <h3 style="margin: 0 0 15px;"><?php _e('My Profile', 'azure-plugin'); ?></h3>
        <p class="description" style="margin-bottom: 15px;"><?php _e('Information that applies to you. Saved values auto-fill on every product page.', 'azure-plugin'); ?></p>
        <form id="azure-uc-profile-form">
            <?php if (!empty($parent_pairs)): ?>
            <div class="azure-uc-parent-grid">
                <?php foreach ($parent_pairs as $pair):
                    foreach (array('left', 'right') as $side):
                        $field = $pair[$side];
                        if (!$field) {
                            echo '<span class="azure-uc-parent-cell azure-uc-parent-empty"></span>';
                            continue;
                        }
                        $val = isset($parent_vals[$field['key']]) ? $parent_vals[$field['key']] : '';
                        ?>
                        <span class="azure-uc-parent-cell azure-uc-profile-row">
                            <label><?php echo esc_html($field['label']); ?></label>
                            <?php $module->render_profile_input($field, $val, 'meta[' . $field['key'] . ']'); ?>
                        </span>
                        <?php
                    endforeach;
                endforeach; ?>
            </div>
            <?php endif; ?>

            <?php foreach ($parent_tail as $field):
                $val = isset($parent_vals[$field['key']]) ? $parent_vals[$field['key']] : ''; ?>
                <p class="azure-uc-profile-row azure-uc-profile-row--narrow">
                    <label><?php echo esc_html($field['label']); ?></label>
                    <?php $module->render_profile_input($field, $val, 'meta[' . $field['key'] . ']'); ?>
                </p>
            <?php endforeach; ?>
            <p>
                <button type="submit" class="button button-primary"><?php _e('Save profile', 'azure-plugin'); ?></button>
                <span class="azure-uc-save-status" style="margin-left:10px;color:#46b450;display:none;"><?php _e('Saved', 'azure-plugin'); ?></span>
            </p>
        </form>
    </div>
    <?php endif; ?>

    <?php if (!empty($family_meta)): ?>
    <div class="azure-uc-section" style="margin-top:30px;">
        <h3 style="margin: 0 0 15px;"><?php _e('My Family', 'azure-plugin'); ?></h3>
        <p class="description" style="margin-bottom: 15px;">
            <?php _e('Shared with your co-parent on this account. Used when emergency contact info is required at checkout.', 'azure-plugin'); ?>
        </p>
        <form id="azure-uc-family-form">
            <div class="azure-uc-family-grid">
                <?php foreach ($family_meta as $field):
                    $val = isset($family_vals[$field['key']]) ? $family_vals[$field['key']] : ''; ?>
                    <span class="azure-uc-family-cell azure-uc-profile-row">
                        <label><?php echo esc_html($field['label']); ?></label>
                        <?php $module->render_profile_input($field, $val, 'meta[' . $field['key'] . ']'); ?>
                    </span>
                <?php endforeach; ?>
            </div>
            <p>
                <button type="submit" class="button button-primary"><?php _e('Save family info', 'azure-plugin'); ?></button>
                <span class="azure-uc-save-status" style="margin-left:10px;color:#46b450;display:none;"><?php _e('Saved', 'azure-plugin'); ?></span>
            </p>
        </form>
    </div>
    <?php endif; ?>

    <div class="azure-uc-section" style="margin-top:30px;">
        <div class="azure-uc-children-header">
            <h3 style="margin: 0;"><?php _e('My Children', 'azure-plugin'); ?></h3>
            <button type="button" class="azure-uc-add-child" aria-label="<?php esc_attr_e('Add Child', 'azure-plugin'); ?>" title="<?php esc_attr_e('Add Child', 'azure-plugin'); ?>">+</button>
        </div>

        <?php if (empty($children)): ?>
        <p class="azure-uc-empty"><?php _e('No children added yet. Add a child profile to speed up checkout for enrichment programs and events.', 'azure-plugin'); ?></p>
        <?php endif; ?>

        <div class="azure-uc-children-list">
            <?php foreach ($children as $child):
                $meta = Azure_User_Children::get_child_meta($child->id);
                // Show every saved meta value with a non-empty value. Skip
                // child_name (already in the card header) and any obvious
                // system keys. Unknown keys fall back to a humanized version
                // of the meta key so legacy data still surfaces.
                $skip_keys = array('pta_pf_child_name', 'pta_pf_childs_name', 'pta_pf_child_s_name');
                $visible_meta = array();
                foreach ($meta as $mkey => $mvalue) {
                    if (in_array($mkey, $skip_keys, true)) {
                        continue;
                    }
                    if (trim((string) $mvalue) === '') {
                        continue;
                    }
                    $visible_meta[$mkey] = $mvalue;
                }
            ?>
                <div class="azure-uc-child-card" data-child-id="<?php echo esc_attr($child->id); ?>">
                    <div class="azure-uc-child-header">
                        <button type="button" class="azure-uc-toggle" aria-expanded="false" aria-label="<?php esc_attr_e('Show details', 'azure-plugin'); ?>">
                            <span class="azure-uc-toggle-icon" aria-hidden="true">▸</span>
                        </button>
                        <strong class="azure-uc-child-name"><?php echo esc_html($child->child_name); ?></strong>
                        <span class="azure-uc-child-actions">
                            <a href="#" class="azure-uc-edit-child" data-id="<?php echo esc_attr($child->id); ?>"><?php _e('Edit', 'azure-plugin'); ?></a>
                            <a href="#" class="azure-uc-delete-child" data-id="<?php echo esc_attr($child->id); ?>" style="color: #dc3545;"><?php _e('Remove', 'azure-plugin'); ?></a>
                        </span>
                    </div>
                    <?php if (!empty($visible_meta)): ?>
                    <div class="azure-uc-child-meta" hidden>
                        <?php foreach ($visible_meta as $mkey => $mvalue):
                            $display_label = isset($child_label_map[$mkey])
                                ? $child_label_map[$mkey]
                                : Azure_User_Children::humanize_meta_key($mkey);
                        ?>
                            <div class="azure-uc-meta-row">
                                <span class="azure-uc-meta-label"><?php echo esc_html($display_label); ?>:</span>
                                <span class="azure-uc-meta-value"><?php echo esc_html($mvalue); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Add/Edit Child Modal -->
<div id="azure-uc-child-modal" style="display:none;">
    <div class="azure-uc-modal-overlay"></div>
    <div class="azure-uc-modal-content">
        <h3 id="azure-uc-modal-title"><?php _e('Add Child', 'azure-plugin'); ?></h3>
        <form id="azure-uc-child-form">
            <input type="hidden" name="child_id" id="azure-uc-child-id" value="0" />
            <p>
                <label for="azure-uc-child-name"><?php _e('Child\'s Name', 'azure-plugin'); ?> <span class="required">*</span></label>
                <input type="text" id="azure-uc-child-name" name="child_name" required />
            </p>
            <div id="azure-uc-meta-fields"></div>
            <p class="azure-uc-modal-actions">
                <button type="submit" class="button button-primary"><?php _e('Save', 'azure-plugin'); ?></button>
                <button type="button" class="button azure-uc-modal-close"><?php _e('Cancel', 'azure-plugin'); ?></button>
            </p>
        </form>
    </div>
</div>

<script>
jQuery(function($) {
    var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
    var nonce = '<?php echo esc_js(wp_create_nonce('azure_uc_nonce')); ?>';
    var childFieldDefs = <?php echo wp_json_encode($child_fields); ?>;

    function findValueForField(meta, field) {
        if (Object.prototype.hasOwnProperty.call(meta, field.key)) {
            return meta[field.key];
        }
        if (Object.prototype.hasOwnProperty.call(meta, field.label)) {
            return meta[field.label];
        }
        var lower = field.label.toLowerCase();
        for (var k in meta) {
            if (Object.prototype.hasOwnProperty.call(meta, k) && k.toLowerCase() === lower) {
                return meta[k];
            }
        }
        return '';
    }

    function openModal(id, name, meta) {
        $('#azure-uc-child-id').val(id || 0);
        $('#azure-uc-child-name').val(name || '');
        $('#azure-uc-modal-title').text(id ? '<?php echo esc_js(__('Edit Child', 'azure-plugin')); ?>' : '<?php echo esc_js(__('Add Child', 'azure-plugin')); ?>');

        var $metaContainer = $('#azure-uc-meta-fields').empty();
        meta = meta || {};

        $.each(childFieldDefs, function(i, field) {
            var val = findValueForField(meta, field);
            var html = '<p><label>' + field.label + '</label>';
            if (field.type === 'select' && field.options) {
                html += '<select name="meta[' + field.key + ']">';
                html += '<option value="">-- Select --</option>';
                $.each(field.options, function(j, opt) {
                    html += '<option value="' + opt + '"' + (val === opt ? ' selected' : '') + '>' + opt + '</option>';
                });
                html += '</select>';
            } else if (field.type === 'checkbox') {
                html += '<label class="azure-pf-checkbox-label"><input type="checkbox" name="meta[' + field.key + ']" value="Yes"' + (val === 'Yes' ? ' checked' : '') + ' /> Yes</label>';
            } else {
                html += '<input type="' + (field.type || 'text') + '" name="meta[' + field.key + ']" value="' + $('<span>').text(val).html() + '" />';
            }
            html += '</p>';
            $metaContainer.append(html);
        });

        $('#azure-uc-child-modal').show();
    }

    function closeModal() {
        $('#azure-uc-child-modal').hide();
    }

    $('.azure-uc-add-child').on('click', function() { openModal(); });
    $(document).on('click', '.azure-uc-modal-close, .azure-uc-modal-overlay', closeModal);

    // Collapsible child cards: toggle the meta drawer + chevron icon.
    $(document).on('click', '.azure-uc-toggle', function() {
        var $btn = $(this);
        var $card = $btn.closest('.azure-uc-child-card');
        var $meta = $card.find('.azure-uc-child-meta');
        if (!$meta.length) return;
        var expanded = $btn.attr('aria-expanded') === 'true';
        $btn.attr('aria-expanded', expanded ? 'false' : 'true');
        $card.toggleClass('is-expanded', !expanded);
        $meta.prop('hidden', expanded);
    });

    $(document).on('click', '.azure-uc-edit-child', function(e) {
        e.preventDefault();
        var childId = $(this).data('id');
        $.post(ajaxUrl, { action: 'azure_uc_get_child_meta', nonce: nonce, child_id: childId }, function(res) {
            if (res.success) {
                openModal(res.data.id, res.data.child_name, res.data.meta);
            }
        });
    });

    $(document).on('click', '.azure-uc-delete-child', function(e) {
        e.preventDefault();
        if (!confirm('<?php echo esc_js(__('Remove this child profile?', 'azure-plugin')); ?>')) return;
        var childId = $(this).data('id');
        $.post(ajaxUrl, { action: 'azure_uc_delete_child', nonce: nonce, child_id: childId }, function(res) {
            if (res.success) location.reload();
        });
    });

    $('#azure-uc-child-form').on('submit', function(e) {
        e.preventDefault();
        var formData = $(this).serializeArray();
        var postData = { action: 'azure_uc_save_child', nonce: nonce };
        $.each(formData, function(i, field) {
            if (postData[field.name] !== undefined) {
                return;
            }
            postData[field.name] = field.value;
        });

        $.post(ajaxUrl, postData, function(res) {
            if (res.success) location.reload();
            else alert(res.data || 'Error saving child');
        });
    });

    function bindMetaForm(formId, action, errorLabel) {
        $(formId).on('submit', function(e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $form.find('button[type="submit"]').prop('disabled', true);
            var $status = $form.find('.azure-uc-save-status').hide();

            var formData = $form.serializeArray();
            var postData = { action: action, nonce: nonce };
            $.each(formData, function(i, field) { postData[field.name] = field.value; });

            $.post(ajaxUrl, postData, function(res) {
                $btn.prop('disabled', false);
                if (res.success) {
                    $status.fadeIn(150).delay(1500).fadeOut(300);
                } else {
                    alert(res.data || ('Error saving ' + errorLabel));
                }
            }).fail(function() {
                $btn.prop('disabled', false);
                alert('Save failed');
            });
        });
    }

    bindMetaForm('#azure-uc-profile-form', 'azure_uc_save_profile', 'profile');
    bindMetaForm('#azure-uc-family-form',  'azure_uc_save_family',  'family info');
});
</script>
