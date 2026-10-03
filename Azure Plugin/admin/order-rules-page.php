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

$form_trigger = Azure_Order_Rules_Module::TRIGGER_FORM_SUBMITTED;
$forms_on = isset($triggers[$form_trigger]);
$forms = $forms_on ? Azure_Forms_Module::get_instance()->get_forms() : array();
$form_titles = array();
$form_fields = array();
foreach ($forms as $f) {
    $form_titles[$f['id']] = $f['title'];
    $form_fields[$f['id']] = array();
    foreach (Azure_Forms_Schema::input_fields($f['schema']) as $field) {
        $form_fields[$f['id']][] = array(
            'name'  => $field['name'],
            'label' => wp_strip_all_tags($field['label']) ?: $field['name'],
            'type'  => $field['type'],
        );
    }
}
$custom_emails = class_exists('Azure_Email_Messages') ? Azure_Email_Messages::custom_emails() : array();
$editing_form = $editing && ($editing->trigger_type ?? '') === $form_trigger;
$current_trigger = $editing->trigger_type ?? Azure_Order_Rules_Module::TRIGGER_PRODUCT_ORDERED;
$current_recipients = $editing ? array_merge($editing->to_email_list ?? array(), $editing->to_token_list ?? array()) : array();

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
            } elseif ($extra === 'pick_form') {
                esc_html_e('Choose a form for this rule.', 'azure-plugin');
            } elseif ($extra === 'pick_email') {
                esc_html_e('Choose the email this rule sends, or create a new one.', 'azure-plugin');
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
        <p class="description"><?php
            echo $forms_on
                ? esc_html__('When a product is ordered or a form is submitted, send an email to the people who need to know — for example the librarian for a celebration book, or a thank-you to whoever filled in a form.', 'azure-plugin')
                : esc_html__('When a product is ordered, send a designed email to the people who need to know — for example the librarian for a celebration book.', 'azure-plugin');
        ?></p>

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
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($current_trigger, $key); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <?php if ($forms_on): ?>
                <tr class="azure-rule-row-form">
                    <th scope="row"><label for="azure_rule_form"><?php esc_html_e('Form', 'azure-plugin'); ?></label></th>
                    <td>
                        <select id="azure_rule_form" name="trigger_form">
                            <option value=""><?php esc_html_e('Select a form…', 'azure-plugin'); ?></option>
                            <?php foreach ($forms as $f): ?>
                                <option value="<?php echo (int) $f['id']; ?>" <?php selected($editing_form ? (int) $editing->trigger_value : 0, (int) $f['id']); ?>>
                                    <?php echo esc_html($f['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($forms)): ?>
                            <p class="description"><?php esc_html_e('No forms yet. Build one in Communications > Forms first.', 'azure-plugin'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr class="azure-rule-row-form">
                    <th scope="row"><label for="azure_rule_cond_field"><?php esc_html_e('Only when', 'azure-plugin'); ?></label></th>
                    <td>
                        <select id="azure_rule_cond_field" name="condition_field"
                                data-current="<?php echo esc_attr($editing_form && $editing->condition ? $editing->condition['field'] : ''); ?>">
                            <option value=""><?php esc_html_e('Every submission', 'azure-plugin'); ?></option>
                        </select>
                        <span class="azure-rule-cond-value">
                            <?php esc_html_e('equals', 'azure-plugin'); ?>
                            <input type="text" class="regular-text" id="azure_rule_cond_value" name="condition_value"
                                   value="<?php echo esc_attr($editing_form && $editing->condition ? $editing->condition['value'] : ''); ?>"
                                   placeholder="<?php esc_attr_e('e.g. Yes', 'azure-plugin'); ?>" />
                        </span>
                        <p class="description"><?php esc_html_e('Not case-sensitive. For checkboxes, matches if that option was ticked. Leave the value empty to match any answer.', 'azure-plugin'); ?></p>
                    </td>
                </tr>
                <tr class="azure-rule-row-form">
                    <th scope="row"><label for="azure_rule_email_key"><?php esc_html_e('Email', 'azure-plugin'); ?></label></th>
                    <td>
                        <select id="azure_rule_email_key" name="email_key">
                            <option value=""><?php esc_html_e('Choose an email…', 'azure-plugin'); ?></option>
                            <?php foreach ($custom_emails as $key => $email): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($editing_form ? $editing->email_key : '', $key); ?>>
                                    <?php echo esc_html($email['label'] !== '' ? $email['label'] : $key); ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="<?php echo esc_attr(Azure_Order_Rules_Module::NEW_EMAIL); ?>"><?php esc_html_e('+ Create new email', 'azure-plugin'); ?></option>
                        </select>
                        <a id="azure_rule_email_edit" class="button-link" hidden
                           data-base="<?php echo esc_url(Azure_Order_Rules_Module::email_edit_url('', $editing_form ? (int) $editing->id : 0)); ?>"
                           href="#"><?php esc_html_e('Edit this email', 'azure-plugin'); ?></a>
                        <p class="description"><?php esc_html_e('Form emails live in Communications > Emails > Messages, under Forms. "Create new email" makes one when you save this rule and opens it for editing.', 'azure-plugin'); ?></p>
                    </td>
                </tr>
                <?php endif; ?>
                <tr class="azure-rule-row-product">
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
                            echo esc_textarea(implode(', ', $current_recipients));
                        ?></textarea>
                        <p class="description"><?php esc_html_e('One or more email addresses, separated by commas or new lines.', 'azure-plugin'); ?></p>
                        <?php if ($forms_on): ?>
                            <p class="description azure-rule-row-form-hint">
                                <?php esc_html_e('For forms you can also use:', 'azure-plugin'); ?>
                                <code>{submitter_email}</code>
                                <?php esc_html_e('(the form\'s first email field, or the signed-in account if it is blank) or', 'azure-plugin'); ?>
                                <code>{field:<em>name</em>}</code>
                                <span id="azure_rule_field_tokens"></span>
                            </p>
                        <?php endif; ?>
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
                <?php if (!$editing_form): ?>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-selling-rule-email&rule_id=' . (int) $editing->id)); ?>">
                    <?php esc_html_e('Edit email', 'azure-plugin'); ?>
                </a>
                <?php endif; ?>
                <a class="button-link" href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-system&tab=rules')); ?>">
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
                        <th><?php echo $forms_on ? esc_html__('Product or form', 'azure-plugin') : esc_html__('Product', 'azure-plugin'); ?></th>
                        <th><?php esc_html_e('Action', 'azure-plugin'); ?></th>
                        <th><?php esc_html_e('To', 'azure-plugin'); ?></th>
                        <th><?php esc_html_e('Email', 'azure-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rules as $rule):
                    $pid = (int) $rule->trigger_value;
                    $is_form_rule = $rule->trigger_type === $form_trigger;
                    if ($is_form_rule) {
                        $pname = $form_titles[$pid] ?? sprintf(__('Form #%d', 'azure-plugin'), $pid);
                        if (!empty($rule->condition)) {
                            $pname .= ' — ' . sprintf(
                                $rule->condition['value'] === '' ? __('when %1$s is answered', 'azure-plugin') : __('when %1$s = %2$s', 'azure-plugin'),
                                $rule->condition['field'],
                                $rule->condition['value']
                            );
                        }
                    } else {
                        $pname = $product_titles[$pid] ?? ('#' . $pid);
                    }
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
                                <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-system&tab=rules&edit=' . (int) $rule->id)); ?>"><?php esc_html_e('Edit', 'azure-plugin'); ?></a>
                                |
                                <a href="<?php echo esc_url($delete_url); ?>" class="azure-order-rule-delete"><?php esc_html_e('Delete', 'azure-plugin'); ?></a>
                            </div>
                        </td>
                        <td><?php echo esc_html($triggers[$rule->trigger_type] ?? $rule->trigger_type); ?></td>
                        <td><?php echo esc_html($pname); ?></td>
                        <td><?php echo esc_html($actions[$rule->action_type] ?? $rule->action_type); ?></td>
                        <td><?php echo esc_html(implode(', ', array_merge($rule->to_email_list, $rule->to_token_list ?? array()))); ?></td>
                        <td>
                            <?php if ($is_form_rule): ?>
                                <?php if ($rule->email_key !== '' && isset($custom_emails[$rule->email_key])): ?>
                                    <a class="button button-small" href="<?php echo esc_url(Azure_Order_Rules_Module::email_edit_url($rule->email_key, (int) $rule->id)); ?>">
                                        <?php echo esc_html($custom_emails[$rule->email_key]['label'] ?: __('Edit email', 'azure-plugin')); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="description" style="color:#b32d2e;"><?php esc_html_e('Email missing — edit the rule to pick one', 'azure-plugin'); ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                            <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-selling-rule-email&rule_id=' . (int) $rule->id)); ?>">
                                <?php echo $has_body ? esc_html__('Edit email', 'azure-plugin') : esc_html__('Create email', 'azure-plugin'); ?>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php if ($forms_on): ?>
<script>
(function () {
    var trigger = document.getElementById('azure_rule_trigger');
    var formSel = document.getElementById('azure_rule_form');
    var condField = document.getElementById('azure_rule_cond_field');
    var condValue = document.querySelector('.azure-rule-cond-value');
    var emailSel = document.getElementById('azure_rule_email_key');
    var emailLink = document.getElementById('azure_rule_email_edit');
    var productSel = document.getElementById('azure_rule_product');
    var tokenHint = document.getElementById('azure_rule_field_tokens');
    var submit = document.querySelector('.azure-order-rule-form [type="submit"]');
    if (!trigger || !formSel) {
        return;
    }
    var fields = <?php echo wp_json_encode((object) $form_fields); ?>;
    var isNew = <?php echo $editing ? 'false' : 'true'; ?>;
    var labels = {
        product: <?php echo wp_json_encode(__('Create rule and edit email', 'azure-plugin')); ?>,
        form: <?php echo wp_json_encode(__('Create rule', 'azure-plugin')); ?>
    };

    function each(selector, fn) {
        Array.prototype.forEach.call(document.querySelectorAll(selector), fn);
    }

    function syncTrigger() {
        var isForm = trigger.value === 'form_submitted';
        each('.azure-rule-row-form, .azure-rule-row-form-hint', function (el) { el.hidden = !isForm; });
        each('.azure-rule-row-product', function (el) { el.hidden = isForm; });
        if (productSel) { productSel.required = !isForm; }
        formSel.required = isForm;
        emailSel.required = isForm;
        if (isNew && submit) { submit.value = isForm ? labels.form : labels.product; }
    }

    function syncFields() {
        var list = fields[formSel.value] || [];
        var current = condField.value || condField.getAttribute('data-current') || '';
        while (condField.options.length > 1) { condField.remove(1); }
        var tokens = [];
        list.forEach(function (f) {
            var opt = document.createElement('option');
            opt.value = f.name;
            opt.textContent = f.label + ' (' + f.name + ')';
            if (f.name === current) { opt.selected = true; }
            condField.appendChild(opt);
            if (f.type === 'email') { tokens.push('{field:' + f.name + '}'); }
        });
        condField.setAttribute('data-current', '');
        syncCondition();
        if (tokenHint) {
            tokenHint.textContent = tokens.length ? '— <?php echo esc_js(__('this form:', 'azure-plugin')); ?> ' + tokens.join(', ') : '';
        }
    }

    function syncCondition() {
        if (condValue) { condValue.hidden = condField.value === ''; }
    }

    function syncEmail() {
        var key = emailSel.value;
        var show = key !== '' && key !== '<?php echo esc_js(Azure_Order_Rules_Module::NEW_EMAIL); ?>';
        emailLink.hidden = !show;
        if (show) {
            emailLink.href = emailLink.getAttribute('data-base').split('#')[0] + '#azure-msg-' + key;
        }
    }

    trigger.addEventListener('change', syncTrigger);
    formSel.addEventListener('change', syncFields);
    condField.addEventListener('change', syncCondition);
    emailSel.addEventListener('change', syncEmail);
    syncTrigger();
    syncFields();
    syncEmail();
})();
</script>
<?php endif; ?>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
</div>
<?php endif; ?>
