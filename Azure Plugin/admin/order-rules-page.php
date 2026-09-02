<?php
/**
 * Selling → Rules: product-ordered automations.
 */
if (!defined('ABSPATH')) {
    exit;
}

$rules = Azure_Order_Rules_Module::get_rules();
$products = Azure_Order_Rules_Module::get_sellable_products();
$triggers = Azure_Order_Rules_Module::triggers();
$actions = Azure_Order_Rules_Module::actions();
$edit_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
$editing = $edit_id ? Azure_Order_Rules_Module::get_rule($edit_id) : null;

$product_titles = array();
foreach ($products as $product) {
    $product_titles[(int) $product->get_id()] = $product->get_name();
}

$notice = isset($_GET['azure_rule']) ? sanitize_key(wp_unslash($_GET['azure_rule'])) : '';
$extra  = isset($_GET['azure_rule_extra']) ? sanitize_text_field(wp_unslash($_GET['azure_rule_extra'])) : '';
?>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
<div class="wrap">
    <h1><?php esc_html_e('Order rules', 'azure-plugin'); ?></h1>
<?php endif; ?>

<div class="azure-order-rules-page">

    <?php if ($notice === 'updated'): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Rule saved.', 'azure-plugin'); ?></p></div>
    <?php elseif ($notice === 'deleted'): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Rule deleted.', 'azure-plugin'); ?></p></div>
    <?php elseif ($notice === 'error'): ?>
        <div class="notice notice-error is-dismissible"><p><?php
            if ($extra === 'pick_product') {
                esc_html_e('Choose a product for this rule.', 'azure-plugin');
            } elseif ($extra === 'bad_to') {
                esc_html_e('One or more To addresses are not valid email addresses.', 'azure-plugin');
            } elseif ($extra === 'need_to') {
                esc_html_e('Enter at least one To address.', 'azure-plugin');
            } else {
                esc_html_e('Could not save the rule.', 'azure-plugin');
            }
        ?></p></div>
    <?php endif; ?>

    <div class="azure-order-rules-card">
        <h2><?php echo $editing ? esc_html__('Edit rule', 'azure-plugin') : esc_html__('New rule', 'azure-plugin'); ?></h2>
        <p class="description"><?php esc_html_e('When a product is ordered, send a designed email to the people who need to know — for example the librarian for a celebration book.', 'azure-plugin'); ?></p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="azure-order-rule-form">
            <?php wp_nonce_field('azure_order_rule_save'); ?>
            <input type="hidden" name="action" value="azure_order_rule_save" />
            <input type="hidden" name="rule_id" value="<?php echo $editing ? (int) $editing->id : 0; ?>" />
            <input type="hidden" name="enabled" value="<?php echo $editing ? (int) $editing->enabled : 1; ?>" />
            <input type="hidden" name="email_subject" value="<?php echo esc_attr($editing->email_subject ?? Azure_Order_Rules_Module::default_email_subject()); ?>" />

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="azure_rule_name"><?php esc_html_e('Name', 'azure-plugin'); ?></label></th>
                    <td>
                        <input type="text" class="regular-text" id="azure_rule_name" name="name" required
                               value="<?php echo esc_attr($editing->name ?? ''); ?>"
                               placeholder="<?php esc_attr_e('Celebration book → librarian', 'azure-plugin'); ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="azure_rule_trigger"><?php esc_html_e('Trigger', 'azure-plugin'); ?></label></th>
                    <td>
                        <select id="azure_rule_trigger" name="trigger_type">
                            <?php foreach ($triggers as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($editing->trigger_type ?? Azure_Order_Rules_Module::TRIGGER_PRODUCT_ORDERED, $key); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="azure_rule_product"><?php esc_html_e('Product', 'azure-plugin'); ?></label></th>
                    <td>
                        <select id="azure_rule_product" name="trigger_value" required>
                            <option value=""><?php esc_html_e('Select a product…', 'azure-plugin'); ?></option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?php echo (int) $product->get_id(); ?>" <?php selected((int) ($editing->trigger_value ?? 0), (int) $product->get_id()); ?>>
                                    <?php echo esc_html($product->get_name()); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($products)): ?>
                            <p class="description"><?php esc_html_e('No WooCommerce products found. Publish a product first (yearbook, celebration book, etc.).', 'azure-plugin'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="azure_rule_action"><?php esc_html_e('Action', 'azure-plugin'); ?></label></th>
                    <td>
                        <select id="azure_rule_action" name="action_type">
                            <?php foreach ($actions as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($editing->action_type ?? Azure_Order_Rules_Module::ACTION_SEND_EMAIL, $key); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="azure_rule_to"><?php esc_html_e('To', 'azure-plugin'); ?></label></th>
                    <td>
                        <textarea id="azure_rule_to" name="to_emails" rows="3" class="large-text" required
                                  placeholder="librarian@school.org, volunteer@example.org"><?php
                            echo esc_textarea(implode(', ', $editing->to_email_list ?? array()));
                        ?></textarea>
                        <p class="description"><?php esc_html_e('One or more email addresses, separated by commas or new lines.', 'azure-plugin'); ?></p>
                    </td>
                </tr>
            </table>

            <?php
            submit_button(
                $editing ? __('Save rule', 'azure-plugin') : __('Create rule and edit email', 'azure-plugin'),
                'primary',
                'submit',
                false
            );
            if ($editing):
                ?>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-selling-rule-email&rule_id=' . (int) $editing->id)); ?>">
                    <?php esc_html_e('Edit email', 'azure-plugin'); ?>
                </a>
                <a class="button-link" href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-selling&tab=rules')); ?>">
                    <?php esc_html_e('Cancel', 'azure-plugin'); ?>
                </a>
            <?php endif; ?>
        </form>
    </div>

    <div class="azure-order-rules-card">
        <h2><?php esc_html_e('Rules', 'azure-plugin'); ?></h2>
        <?php if (empty($rules)): ?>
            <p><?php esc_html_e('No rules yet. Create one above — after you save, the newsletter block editor opens so you can design the email.', 'azure-plugin'); ?></p>
        <?php else: ?>
            <table class="widefat striped azure-order-rules-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Enabled', 'azure-plugin'); ?></th>
                        <th><?php esc_html_e('Name', 'azure-plugin'); ?></th>
                        <th><?php esc_html_e('Trigger', 'azure-plugin'); ?></th>
                        <th><?php esc_html_e('Product', 'azure-plugin'); ?></th>
                        <th><?php esc_html_e('Action', 'azure-plugin'); ?></th>
                        <th><?php esc_html_e('To', 'azure-plugin'); ?></th>
                        <th><?php esc_html_e('Email', 'azure-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rules as $rule):
                    $pid = (int) $rule->trigger_value;
                    $pname = $product_titles[$pid] ?? ('#' . $pid);
                    $has_body = !empty($rule->content_html);
                    $delete_url = wp_nonce_url(
                        admin_url('admin-post.php?action=azure_order_rule_delete&rule_id=' . (int) $rule->id),
                        'azure_order_rule_delete_' . (int) $rule->id
                    );
                    ?>
                    <tr data-rule-id="<?php echo (int) $rule->id; ?>">
                        <td>
                            <label class="azure-order-rule-toggle">
                                <input type="checkbox" class="azure-order-rule-enabled" <?php checked((int) $rule->enabled, 1); ?> />
                            </label>
                        </td>
                        <td>
                            <strong><?php echo esc_html($rule->name); ?></strong>
                            <div class="row-actions">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-selling&tab=rules&edit=' . (int) $rule->id)); ?>"><?php esc_html_e('Edit', 'azure-plugin'); ?></a>
                                |
                                <a href="<?php echo esc_url($delete_url); ?>" class="azure-order-rule-delete"><?php esc_html_e('Delete', 'azure-plugin'); ?></a>
                            </div>
                        </td>
                        <td><?php echo esc_html($triggers[$rule->trigger_type] ?? $rule->trigger_type); ?></td>
                        <td><?php echo esc_html($pname); ?></td>
                        <td><?php echo esc_html($actions[$rule->action_type] ?? $rule->action_type); ?></td>
                        <td><?php echo esc_html(implode(', ', $rule->to_email_list)); ?></td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-selling-rule-email&rule_id=' . (int) $rule->id)); ?>">
                                <?php echo $has_body ? esc_html__('Edit email', 'azure-plugin') : esc_html__('Create email', 'azure-plugin'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
</div>
<?php endif; ?>
