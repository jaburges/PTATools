<?php
/**
 * System → Admin Menu tab
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    wp_die(__('You do not have sufficient permissions to access this page.', 'azure-plugin'));
}

$roles = Azure_Admin_Menu_Customizer::role_choices();
$default_role = isset($roles['azuread']) ? 'azuread' : (isset($roles['editor']) ? 'editor' : (string) array_key_first($roles));
$catalog = is_array(Azure_Admin_Menu_Customizer::$catalog) ? Azure_Admin_Menu_Customizer::$catalog : array();
$stored = Azure_Admin_Menu_Customizer::stored();
$hidden_by_role = array();
foreach (array_keys($roles) as $slug) {
    $hidden_by_role[$slug] = isset($stored[$slug]['hidden']) && is_array($stored[$slug]['hidden'])
        ? array_values($stored[$slug]['hidden'])
        : array();
}
?>
<div class="azure-admin-menu-editor">
    <p class="description">
        <?php esc_html_e('Pick a role, then check the admin sidebar items that role should see. Unchecked items are greyed out here and hidden from that role in wp-admin. This only hides menu links — it does not change capabilities.', 'azure-plugin'); ?>
    </p>

    <div class="azure-ame-toolbar">
        <label for="azure-ame-role">
            <strong><?php esc_html_e('Role', 'azure-plugin'); ?></strong>
        </label>
        <select id="azure-ame-role">
            <?php foreach ($roles as $slug => $label): ?>
                <option value="<?php echo esc_attr($slug); ?>" <?php selected($slug, $default_role); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="button button-primary" id="azure-ame-save"><?php esc_html_e('Save menu for role', 'azure-plugin'); ?></button>
        <span id="azure-ame-status" class="azure-ame-status" aria-live="polite"></span>
    </div>

    <p class="description" id="azure-ame-lock-note" hidden>
        <?php esc_html_e('PTA Tools and System stay visible for Administrator so this editor cannot be locked out.', 'azure-plugin'); ?>
    </p>

    <div class="azure-ame-sidebar" id="azure-ame-sidebar" role="tree">
        <?php foreach ($catalog as $node): ?>
            <?php
            $parent_id = $node['id'];
            $locked_parent = in_array($parent_id, Azure_Admin_Menu_Customizer::locked_ids(), true);
            ?>
            <div class="azure-ame-item" data-id="<?php echo esc_attr($parent_id); ?>">
                <label class="azure-ame-row">
                    <input type="checkbox" class="azure-ame-check" value="<?php echo esc_attr($parent_id); ?>"
                        <?php echo $locked_parent ? 'data-locked="1"' : ''; ?> checked>
                    <span class="azure-ame-icon dashicons dashicons-menu-alt"></span>
                    <span class="azure-ame-label"><?php echo esc_html($node['label']); ?></span>
                </label>
                <?php if (!empty($node['children'])): ?>
                    <div class="azure-ame-children">
                        <?php foreach ($node['children'] as $child): ?>
                            <?php $locked_child = in_array($child['id'], Azure_Admin_Menu_Customizer::locked_ids(), true); ?>
                            <label class="azure-ame-row azure-ame-child" data-id="<?php echo esc_attr($child['id']); ?>">
                                <input type="checkbox" class="azure-ame-check" value="<?php echo esc_attr($child['id']); ?>"
                                    <?php echo $locked_child ? 'data-locked="1"' : ''; ?> checked>
                                <span class="azure-ame-label"><?php echo esc_html($child['label']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<script>
window.azureAdminMenuEditor = {
    ajaxUrl: <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
    nonce: <?php echo wp_json_encode(wp_create_nonce('azure_plugin_nonce')); ?>,
    hidden: <?php echo wp_json_encode($hidden_by_role); ?>,
    locked: <?php echo wp_json_encode(array('administrator' => Azure_Admin_Menu_Customizer::locked_ids())); ?>,
    defaultRole: <?php echo wp_json_encode($default_role); ?>
};
</script>
