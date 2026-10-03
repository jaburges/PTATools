<?php
/**
 * Order rules: when a product is ordered, run an action (first: send email).
 *
 * Lives under Selling → Rules. Email body is designed with the newsletter
 * GrapesJS blocks and sent through wp_mail() so the existing Emails router
 * picks the provider.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Order_Rules_Module {

    const TRIGGER_PRODUCT_ORDERED = 'product_ordered';
    const TRIGGER_FORM_SUBMITTED = 'form_submitted';
    const ACTION_SEND_EMAIL = 'send_email';
    const NEW_EMAIL = '__new';
    const META_FIRED_PREFIX = '_azure_order_rule_';

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function current_user_can_manage() {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    private function __construct() {
        add_action('woocommerce_payment_complete', array($this, 'maybe_run'), 30, 1);
        add_action('woocommerce_order_status_processing', array($this, 'maybe_run'), 30, 1);
        add_action('woocommerce_order_status_completed', array($this, 'maybe_run'), 30, 1);

        add_action('admin_post_azure_order_rule_save', array($this, 'handle_save_rule'));
        add_action('admin_post_azure_order_rule_delete', array($this, 'handle_delete_rule'));
        add_action('wp_ajax_azure_order_rule_toggle', array($this, 'ajax_toggle'));
        add_action('wp_ajax_azure_order_rule_save_email', array($this, 'ajax_save_email'));

        add_action('pta_form_submitted', array($this, 'run_form_rules'), 10, 4);
    }

    public static function table_name() {
        if (class_exists('Azure_Database')) {
            $name = Azure_Database::get_table_name('order_rules');
            if ($name) {
                return $name;
            }
        }
        global $wpdb;
        return $wpdb->prefix . 'azure_order_rules';
    }

    public static function triggers() {
        $triggers = array(
            self::TRIGGER_PRODUCT_ORDERED => __('Product ordered', 'azure-plugin'),
        );
        if (class_exists('Azure_Forms_Module')) {
            $triggers[self::TRIGGER_FORM_SUBMITTED] = __('Form submitted', 'azure-plugin');
        }
        return $triggers;
    }

    public static function actions() {
        return array(
            self::ACTION_SEND_EMAIL => __('Send email', 'azure-plugin'),
        );
    }

    /**
     * Merge tokens shown in the editor. Keys are the canonical {token} form.
     *
     * @return array<string,string> token => description
     */
    public static function tokens() {
        return array(
            '{customer_name}'       => __('Billing first + last name', 'azure-plugin'),
            '{customer_first_name}' => __('Billing first name', 'azure-plugin'),
            '{customer_email}'      => __('Billing email', 'azure-plugin'),
            '{order_number}'        => __('Order number', 'azure-plugin'),
            '{order_date}'          => __('Order date', 'azure-plugin'),
            '{order_total}'         => __('Order total (formatted)', 'azure-plugin'),
            '{order_status}'        => __('Order status', 'azure-plugin'),
            '{product_name}'        => __('Matched product name(s)', 'azure-plugin'),
            '{product_qty}'         => __('Matched product quantity', 'azure-plugin'),
            '{order_items}'         => __('All line items as a list', 'azure-plugin'),
            '{order_info}'          => __('Summary: number, customer, items, total', 'azure-plugin'),
            '{billing_address}'     => __('Formatted billing address', 'azure-plugin'),
            '{child_name}'          => __('Child name from product fields, if present', 'azure-plugin'),
            '{product_fields}'      => __('Custom product-field values on matched items', 'azure-plugin'),
        );
    }

    /**
     * Split and validate a free-text list of recipient addresses.
     *
     * @return array{emails: string[], errors: string[]}
     */
    public static function parse_to_emails($raw) {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        }
        $emails = array();
        $errors = array();
        foreach ((array) $parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $clean = function_exists('sanitize_email') ? sanitize_email($part) : strtolower($part);
            $valid = function_exists('is_email') ? (bool) is_email($clean) : (bool) filter_var($clean, FILTER_VALIDATE_EMAIL);
            if (!$valid) {
                $errors[] = $part;
                continue;
            }
            $emails[$clean] = $clean;
        }
        return array(
            'emails' => array_values($emails),
            'errors' => $errors,
        );
    }

    /**
     * Recipients for a rule: fixed addresses, plus (form rules only)
     * {submitter_email} and {field:name} resolved per submission.
     *
     * @return array{emails: string[], tokens: string[], errors: string[]}
     */
    public static function parse_recipients($raw, $allow_tokens = false) {
        $parts = is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array();
        $plain = array();
        foreach ((array) $parts as $part) {
            $part = trim((string) $part);
            if ($allow_tokens && preg_match('/^\{(submitter_email|field:[a-z0-9_]{1,40})\}$/i', $part)) {
                $tokens[strtolower($part)] = strtolower($part);
                continue;
            }
            $plain[] = $part;
        }
        $parsed = self::parse_to_emails($plain);
        return array(
            'emails' => $parsed['emails'],
            'tokens' => array_values($tokens),
            'errors' => $parsed['errors'],
        );
    }

    /**
     * Replace {token} / {token name} / {{token}} / {field:name} placeholders.
     * Unknown tokens are left unchanged.
     */
    public static function replace_tokens($text, array $context) {
        if ($text === '' || $text === null) {
            return (string) $text;
        }
        $normalized = array();
        foreach ($context as $key => $value) {
            $normalized[self::normalize_token_key($key)] = (string) $value;
        }

        return preg_replace_callback(
            '/\{\{?\s*([a-z0-9_: ]+)\s*\}?\}/i',
            function ($m) use ($normalized) {
                $key = self::normalize_token_key($m[1]);
                if (array_key_exists($key, $normalized)) {
                    return $normalized[$key];
                }
                return $m[0];
            },
            (string) $text
        );
    }

    public static function normalize_token_key($key) {
        $key = strtolower(trim((string) $key));
        $key = preg_replace('/^\{+|\}+$/', '', $key);
        $key = preg_replace('/\s+/', '_', $key);
        return $key;
    }

    /**
     * Whether any line item is the given product (or a variation of it).
     *
     * @param object $order WC_Order-like with get_items()
     */
    public static function order_contains_product($order, $product_id) {
        $product_id = (int) $product_id;
        if ($product_id < 1 || !is_object($order) || !method_exists($order, 'get_items')) {
            return false;
        }
        foreach ($order->get_items() as $item) {
            if (self::item_matches_product($item, $product_id)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param object $item WC_Order_Item_Product-like
     */
    public static function item_matches_product($item, $product_id) {
        $product_id = (int) $product_id;
        $ids = self::item_product_ids($item);
        return in_array($product_id, $ids, true);
    }

    public static function item_product_ids($item) {
        $ids = array();
        if (is_object($item) && method_exists($item, 'get_product_id')) {
            $ids[] = (int) $item->get_product_id();
            if (method_exists($item, 'get_variation_id')) {
                $ids[] = (int) $item->get_variation_id();
            }
        } elseif (is_array($item)) {
            $ids[] = (int) ($item['product_id'] ?? 0);
            $ids[] = (int) ($item['variation_id'] ?? 0);
        }
        return array_values(array_filter($ids));
    }

    public static function default_email_subject() {
        return __('New {product_name} order #{order_number}', 'azure-plugin');
    }

    public static function default_email_html() {
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>'
            . '<body style="margin:0;padding:0;background:#f4f4f4;">'
            . '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f4f4;"><tr><td align="center" style="padding:24px 12px;">'
            . '<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-collapse:collapse;">'
            . '<tr><td style="padding:24px 28px;background:#0b2545;color:#ffffff;font-family:Arial,sans-serif;">'
            . '<h1 style="margin:0;font-size:22px;line-height:1.3;">New {product_name} order</h1>'
            . '</td></tr>'
            . '<tr><td style="padding:24px 28px;font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#333333;">'
            . '<p style="margin:0 0 16px;">A {product_name} was just ordered.</p>'
            . '<p style="margin:0 0 8px;"><strong>Order info</strong></p>'
            . '<p style="margin:0 0 16px;white-space:pre-line;">{order_info}</p>'
            . '<p style="margin:0 0 8px;"><strong>Customer</strong><br>{customer_name}<br>{customer_email}</p>'
            . '<p style="margin:16px 0 0;"><strong>Child / fields</strong><br>{child_name}<br>{product_fields}</p>'
            . '</td></tr>'
            . '<tr><td style="padding:16px 28px;font-family:Arial,sans-serif;font-size:12px;color:#777777;border-top:1px solid #eeeeee;">'
            . 'Order #{order_number} &middot; {order_date} &middot; {order_total}'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    public static function get_rule($id) {
        global $wpdb;
        $id = (int) $id;
        if ($id < 1) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $id));
        return $row ? self::hydrate_rule($row) : null;
    }

    public static function get_rules() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table_name() . ' ORDER BY id DESC');
        $out = array();
        foreach ((array) $rows as $row) {
            $out[] = self::hydrate_rule($row);
        }
        return $out;
    }

    public static function get_enabled_rules_for_trigger($trigger_type) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table_name() . ' WHERE enabled = 1 AND trigger_type = %s',
            $trigger_type
        ));
        $out = array();
        foreach ((array) $rows as $row) {
            $out[] = self::hydrate_rule($row);
        }
        return $out;
    }

    public static function hydrate_rule($row) {
        $rule = is_object($row) ? $row : (object) $row;
        $is_form = ($rule->trigger_type ?? '') === self::TRIGGER_FORM_SUBMITTED;
        $parsed = self::parse_recipients(self::decode_to_emails($rule->to_emails ?? ''), $is_form);
        $rule->to_email_list = $parsed['emails'];
        $rule->to_token_list = $parsed['tokens'];
        $rule->condition = self::decode_condition($rule->condition_json ?? '');
        $rule->email_key = isset($rule->email_key) && $rule->email_key !== null ? (string) $rule->email_key : '';
        return $rule;
    }

    /**
     * @return array{field:string,value:string}|null
     */
    public static function decode_condition($stored) {
        $cond = is_array($stored) ? $stored : json_decode((string) $stored, true);
        if (!is_array($cond)) {
            return null;
        }
        $field = strtolower((string) ($cond['field'] ?? ''));
        if (!preg_match('/^[a-z0-9_]{1,40}$/', $field)) {
            return null;
        }
        return array('field' => $field, 'value' => (string) ($cond['value'] ?? ''));
    }

    /**
     * Case-insensitive equals. Checkbox answers match if any ticked
     * option equals the value; an empty value means "answered at all".
     */
    public static function condition_matches($condition, array $data) {
        if (!$condition) {
            return true;
        }
        $want = strtolower(trim($condition['value']));
        $actual = $data[$condition['field']] ?? null;
        $values = is_array($actual) ? $actual : array($actual);
        foreach ($values as $v) {
            $v = strtolower(trim(is_scalar($v) ? (string) $v : ''));
            if ($want === '' ? $v !== '' : $v === $want) {
                return true;
            }
        }
        return false;
    }

    public static function decode_to_emails($stored) {
        if (is_array($stored)) {
            return $stored;
        }
        $stored = (string) $stored;
        if ($stored === '') {
            return array();
        }
        $json = json_decode($stored, true);
        if (is_array($json)) {
            return $json;
        }
        return $stored;
    }

    public static function sanitize_rule_input($input) {
        $trigger = sanitize_key($input['trigger_type'] ?? self::TRIGGER_PRODUCT_ORDERED);
        if (!isset(self::triggers()[$trigger])) {
            $trigger = self::TRIGGER_PRODUCT_ORDERED;
        }
        $is_form = $trigger === self::TRIGGER_FORM_SUBMITTED;
        $parsed = self::parse_recipients($input['to_emails'] ?? '', $is_form);
        $trigger_value = $is_form ? ($input['trigger_form'] ?? 0) : ($input['trigger_value'] ?? 0);

        $condition_json = '';
        $email_key = '';
        if ($is_form) {
            $cond = self::decode_condition(array(
                'field' => sanitize_key($input['condition_field'] ?? ''),
                'value' => substr(sanitize_text_field($input['condition_value'] ?? ''), 0, 200),
            ));
            $condition_json = $cond ? wp_json_encode($cond) : '';
            $email_key = sanitize_key($input['email_key'] ?? '');
            if ($email_key !== self::NEW_EMAIL && !(class_exists('Azure_Email_Messages') && Azure_Email_Messages::is_custom_key($email_key))) {
                $email_key = '';
            }
        }
        $action = sanitize_key($input['action_type'] ?? self::ACTION_SEND_EMAIL);
        if (!isset(self::actions()[$action])) {
            $action = self::ACTION_SEND_EMAIL;
        }
        $name = sanitize_text_field($input['name'] ?? '');
        if ($name === '') {
            $name = __('Untitled rule', 'azure-plugin');
        }
        return array(
            'name'          => $name,
            'enabled'       => empty($input['enabled']) ? 0 : 1,
            'trigger_type'  => $trigger,
            'trigger_value' => (string) max(0, (int) $trigger_value),
            'action_type'   => $action,
            'to_emails'     => wp_json_encode(array_merge($parsed['emails'], $parsed['tokens'])),
            'to_email_list' => $parsed['emails'],
            'to_token_list' => $parsed['tokens'],
            'to_errors'     => $parsed['errors'],
            'email_subject' => sanitize_text_field($input['email_subject'] ?? self::default_email_subject()),
            'email_key'     => $email_key,
            'condition_json'=> $condition_json,
        );
    }

    public function handle_save_rule() {
        if (!self::current_user_can_manage()) {
            wp_die('Unauthorized', 403);
        }
        check_admin_referer('azure_order_rule_save');

        $id = isset($_POST['rule_id']) ? absint($_POST['rule_id']) : 0;
        $clean = self::sanitize_rule_input(wp_unslash($_POST));
        $is_form = $clean['trigger_type'] === self::TRIGGER_FORM_SUBMITTED;
        if ($clean['trigger_value'] === '0' || $clean['trigger_value'] === '') {
            $this->redirect_rules('error', $is_form ? 'pick_form' : 'pick_product', $id);
        }
        if (empty($clean['to_email_list']) && empty($clean['to_token_list'])) {
            $this->redirect_rules('error', empty($clean['to_errors']) ? 'need_to' : 'bad_to', $id);
        }
        if ($is_form && $clean['email_key'] === '') {
            $this->redirect_rules('error', 'pick_email', $id);
        }

        $new_email = $is_form && $clean['email_key'] === self::NEW_EMAIL;
        if ($new_email) {
            if (!class_exists('Azure_Email_Messages')) {
                require_once AZURE_PLUGIN_PATH . 'includes/class-email-messages.php';
            }
            $clean['email_key'] = Azure_Email_Messages::create_custom($clean['name']);
        }

        global $wpdb;
        $table = self::table_name();
        $data = array(
            'name'           => $clean['name'],
            'enabled'        => $clean['enabled'],
            'trigger_type'   => $clean['trigger_type'],
            'trigger_value'  => $clean['trigger_value'],
            'action_type'    => $clean['action_type'],
            'to_emails'      => $clean['to_emails'],
            'email_subject'  => $clean['email_subject'] !== '' ? $clean['email_subject'] : self::default_email_subject(),
            'email_key'      => $clean['email_key'] !== '' ? $clean['email_key'] : null,
            'condition_json' => $clean['condition_json'],
        );
        $formats = array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s');

        if ($id > 0) {
            $wpdb->update($table, $data, array('id' => $id), $formats, array('%d'));
        } else {
            $data['content_html'] = $is_form ? '' : self::default_email_html();
            $data['content_json'] = '';
            $formats[] = '%s';
            $formats[] = '%s';
            $wpdb->insert($table, $data, $formats);
            $id = (int) $wpdb->insert_id;
            if ($id < 1) {
                $this->redirect_rules('error', 'save_failed');
            }
            if (!$is_form) {
                wp_safe_redirect(admin_url('admin.php?page=azure-plugin-selling-rule-email&rule_id=' . $id));
                exit;
            }
        }

        if ($new_email) {
            wp_safe_redirect(self::email_edit_url($clean['email_key'], $id));
            exit;
        }
        $this->redirect_rules('updated', $id);
    }

    // ─── Form "Send responses to" setting ─────────────────────────────

    /**
     * @return object[] Hydrated rules for one form, oldest first.
     */
    public static function form_rules($form_id) {
        global $wpdb;
        $form_id = (int) $form_id;
        if ($form_id < 1) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table_name() . ' WHERE trigger_type = %s AND trigger_value = %s ORDER BY id ASC',
            self::TRIGGER_FORM_SUBMITTED,
            (string) $form_id
        ));
        $out = array();
        foreach ((array) $rows as $row) {
            $out[] = self::hydrate_rule($row);
        }
        return $out;
    }

    /**
     * The rule the form's Settings field edits: its oldest rule without
     * a condition. Conditional rules are only ever edited under Rules.
     *
     * @param object[] $rules From form_rules().
     */
    public static function basic_form_rule(array $rules) {
        foreach ($rules as $rule) {
            if (empty($rule->condition)) {
                return $rule;
            }
        }
        return null;
    }

    public static function recipients_text($rule) {
        return implode(', ', array_merge((array) ($rule->to_email_list ?? array()), (array) ($rule->to_token_list ?? array())));
    }

    public static function rule_edit_url($rule_id) {
        return admin_url('admin.php?page=azure-plugin-system&tab=rules&edit=' . (int) $rule_id);
    }

    /**
     * What the form's Settings pane shows about who gets emailed.
     */
    public static function form_notify_summary($form_id) {
        $rules = self::form_rules($form_id);
        $basic = self::basic_form_rule($rules);
        $others = array();
        foreach ($rules as $rule) {
            if ($basic && (int) $rule->id === (int) $basic->id) {
                continue;
            }
            $others[] = array(
                'name'      => (string) $rule->name,
                'to'        => self::recipients_text($rule),
                'condition' => $rule->condition,
                'enabled'   => !empty($rule->enabled),
                'edit_url'  => self::rule_edit_url($rule->id),
            );
        }
        $to = $basic ? self::recipients_text($basic) : '';
        return array(
            'to'        => $to,
            'paused'    => $basic && empty($basic->enabled) && $to !== '',
            'email_url' => $basic && $basic->email_key !== '' ? self::email_edit_url($basic->email_key, $basic->id) : '',
            'rule_url'  => $basic ? self::rule_edit_url($basic->id) : '',
            'others'    => $others,
            'rules_url' => admin_url('admin.php?page=azure-plugin-system&tab=rules'),
            'can_edit'  => self::current_user_can_manage(),
        );
    }

    /**
     * Point the form's basic rule at these recipients, creating the rule
     * (and its email) the first time. Clearing the list pauses the rule
     * rather than deleting it, so a customised email is never lost.
     *
     * @return true|WP_Error
     */
    public static function set_form_recipients($form_id, $form_title, $raw) {
        global $wpdb;
        $form_id = (int) $form_id;
        $parsed = self::parse_recipients($raw, true);
        if (!empty($parsed['errors'])) {
            return new WP_Error('bad_to', sprintf(
                /* translators: %s: comma-separated invalid addresses. */
                __('Not saved. These are not valid email addresses: %s', 'azure-plugin'),
                implode(', ', $parsed['errors'])
            ));
        }
        $list = array_merge($parsed['emails'], $parsed['tokens']);
        $basic = self::basic_form_rule(self::form_rules($form_id));

        if ($basic) {
            $old = array_merge($basic->to_email_list, $basic->to_token_list);
            if ($old === $list) {
                return true;
            }
            $data = array('to_emails' => wp_json_encode($list));
            if (empty($list)) {
                $data['enabled'] = 0;
            } elseif (empty($old)) {
                $data['enabled'] = 1;
            }
            $ok = $wpdb->update(self::table_name(), $data, array('id' => (int) $basic->id));
            return $ok === false ? new WP_Error('db', __('Could not save the recipients.', 'azure-plugin')) : true;
        }

        if (empty($list)) {
            return true;
        }
        if (!class_exists('Azure_Email_Messages')) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-email-messages.php';
        }
        $name = sprintf(
            /* translators: %s: form title. */
            __('%s: new response', 'azure-plugin'),
            sanitize_text_field((string) $form_title)
        );
        $ok = $wpdb->insert(self::table_name(), array(
            'name'           => $name,
            'enabled'        => 1,
            'trigger_type'   => self::TRIGGER_FORM_SUBMITTED,
            'trigger_value'  => (string) $form_id,
            'action_type'    => self::ACTION_SEND_EMAIL,
            'to_emails'      => wp_json_encode($list),
            'email_subject'  => self::default_email_subject(),
            'email_key'      => Azure_Email_Messages::create_custom($name),
            'condition_json' => '',
            'content_html'   => '',
            'content_json'   => '',
        ));
        return $ok === false ? new WP_Error('db', __('Could not save the recipients.', 'azure-plugin')) : true;
    }

    /**
     * Emails > Messages, scrolled to one email, with a way back to the rule.
     */
    public static function email_edit_url($email_key, $rule_id = 0) {
        $url = admin_url('admin.php?page=azure-plugin-emails&tab=messages');
        if ($rule_id > 0) {
            $url = add_query_arg('return_rule', (int) $rule_id, $url);
        }
        return $url . '#azure-msg-' . sanitize_key($email_key);
    }

    public function handle_delete_rule() {
        if (!self::current_user_can_manage()) {
            wp_die('Unauthorized', 403);
        }
        $id = isset($_GET['rule_id']) ? absint($_GET['rule_id']) : 0;
        check_admin_referer('azure_order_rule_delete_' . $id);
        if ($id > 0) {
            global $wpdb;
            $wpdb->delete(self::table_name(), array('id' => $id), array('%d'));
        }
        $this->redirect_rules('deleted', $id);
    }

    public function ajax_toggle() {
        check_ajax_referer('azure_order_rules', 'nonce');
        if (!self::current_user_can_manage()) {
            wp_send_json_error('Permission denied', 403);
        }
        $id = isset($_POST['rule_id']) ? absint($_POST['rule_id']) : 0;
        $enabled = empty($_POST['enabled']) ? 0 : 1;
        if ($id < 1) {
            wp_send_json_error('Missing rule');
        }
        global $wpdb;
        $wpdb->update(self::table_name(), array('enabled' => $enabled), array('id' => $id), array('%d'), array('%d'));
        wp_send_json_success(array('enabled' => $enabled));
    }

    public function ajax_save_email() {
        check_ajax_referer('azure_order_rules', 'nonce');
        if (!self::current_user_can_manage()) {
            wp_send_json_error('Permission denied', 403);
        }
        $id = isset($_POST['rule_id']) ? absint($_POST['rule_id']) : 0;
        $rule = self::get_rule($id);
        if (!$rule) {
            wp_send_json_error('Rule not found');
        }
        $subject = sanitize_text_field(wp_unslash($_POST['email_subject'] ?? $rule->email_subject));
        $html = wp_unslash($_POST['content_html'] ?? '');
        $json = wp_unslash($_POST['content_json'] ?? '');
        if (class_exists('Azure_Newsletter_Email_Css') && $html !== '') {
            $html = Azure_Newsletter_Email_Css::ensure_column_stack_style($html);
        }
        global $wpdb;
        $wpdb->update(
            self::table_name(),
            array(
                'email_subject' => $subject !== '' ? $subject : self::default_email_subject(),
                'content_html'  => $html,
                'content_json'  => $json,
            ),
            array('id' => $id),
            array('%s', '%s', '%s'),
            array('%d')
        );
        wp_send_json_success(array('rule_id' => $id));
    }

    /**
     * WooCommerce hook entry. Safe if $order_id is already an order object.
     */
    public function maybe_run($order_id) {
        $order = $order_id;
        if (!is_object($order) && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
        }
        if (!is_object($order) || !method_exists($order, 'get_id')) {
            return;
        }
        $status = method_exists($order, 'get_status') ? (string) $order->get_status() : '';
        if (in_array($status, array('cancelled', 'refunded', 'failed', 'checkout-draft'), true)) {
            return;
        }

        foreach (self::get_enabled_rules_for_trigger(self::TRIGGER_PRODUCT_ORDERED) as $rule) {
            $this->run_rule_for_order($rule, $order);
        }
    }

    public function run_rule_for_order($rule, $order) {
        if (empty($rule->id) || (int) $rule->trigger_value < 1) {
            return false;
        }
        if (!self::order_contains_product($order, $rule->trigger_value)) {
            return false;
        }
        $meta_key = self::META_FIRED_PREFIX . (int) $rule->id;
        if (method_exists($order, 'get_meta') && $order->get_meta($meta_key)) {
            return false;
        }
        if (($rule->action_type ?? '') !== self::ACTION_SEND_EMAIL) {
            return false;
        }

        $sent = $this->send_rule_email($rule, $order);
        if ($sent && method_exists($order, 'update_meta_data')) {
            $order->update_meta_data($meta_key, current_time('mysql'));
            if (method_exists($order, 'save')) {
                $order->save();
            }
        }
        return $sent;
    }

    public function send_rule_email($rule, $order) {
        $to = isset($rule->to_email_list) ? $rule->to_email_list : self::parse_to_emails(self::decode_to_emails($rule->to_emails ?? ''))['emails'];
        if (empty($to)) {
            return false;
        }
        $context = self::build_token_context($order, (int) $rule->trigger_value);
        $subject = self::replace_tokens($rule->email_subject ?: self::default_email_subject(), $context);
        $html = self::replace_tokens($rule->content_html ?: self::default_email_html(), $context);
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $ok = wp_mail($to, $subject, $html, $headers);
        if (class_exists('Azure_Logger')) {
            Azure_Logger::info('Order rule email ' . ($ok ? 'sent' : 'failed'), array(
                'module'  => 'OrderRules',
                'rule_id' => (int) $rule->id,
                'order_id'=> method_exists($order, 'get_id') ? $order->get_id() : 0,
                'to'      => implode(',', $to),
            ));
        }
        return (bool) $ok;
    }

    // ─── Form rules ───────────────────────────────────────────────────

    /**
     * `pta_form_submitted` listener.
     *
     * @param array $form    Hydrated form (Azure_Forms_Module).
     * @param int   $entry_id
     * @param array $data    Validated answers, name => value.
     * @param int   $user_id
     */
    public function run_form_rules($form, $entry_id, $data, $user_id = 0) {
        if (!is_array($form) || empty($form['id']) || !is_array($data)) {
            return;
        }
        foreach (self::get_enabled_rules_for_trigger(self::TRIGGER_FORM_SUBMITTED) as $rule) {
            if (!self::form_rule_matches($rule, $form, $data)) {
                continue;
            }
            if (($rule->action_type ?? '') !== self::ACTION_SEND_EMAIL) {
                continue;
            }
            try {
                $this->send_form_rule_email($rule, $form, (int) $entry_id, $data, (int) $user_id);
            } catch (\Throwable $e) {
                if (class_exists('Azure_Logger')) {
                    Azure_Logger::error('Form rule email failed: ' . $e->getMessage(), array('module' => 'OrderRules', 'rule_id' => (int) $rule->id));
                }
            }
        }
    }

    public static function form_rule_matches($rule, array $form, array $data) {
        if (($rule->trigger_type ?? '') !== self::TRIGGER_FORM_SUBMITTED) {
            return false;
        }
        if ((int) ($rule->trigger_value ?? 0) !== (int) ($form['id'] ?? 0)) {
            return false;
        }
        $condition = isset($rule->condition) ? $rule->condition : self::decode_condition($rule->condition_json ?? '');
        return self::condition_matches($condition, $data);
    }

    /**
     * Fixed addresses plus {submitter_email} / {field:name} resolved
     * against this submission. Invalid resolved values are skipped.
     *
     * @return string[]
     */
    public static function resolve_form_recipients($rule, array $form, array $data, $user_id = 0) {
        $to = array();
        foreach ((array) ($rule->to_email_list ?? array()) as $email) {
            $to[strtolower($email)] = $email;
        }
        foreach ((array) ($rule->to_token_list ?? array()) as $token) {
            $value = '';
            if ($token === '{submitter_email}') {
                $value = self::submitter_email($form, $data, $user_id);
            } elseif (preg_match('/^\{field:([a-z0-9_]+)\}$/', $token, $m)) {
                $raw = $data[$m[1]] ?? '';
                $value = is_scalar($raw) ? trim((string) $raw) : '';
            }
            if ($value === '') {
                continue;
            }
            $parsed = self::parse_to_emails(array($value));
            foreach ($parsed['emails'] as $email) {
                $to[strtolower($email)] = $email;
            }
        }
        return array_values($to);
    }

    /**
     * Replies go to the person who filled the form in, unless the email
     * is being sent to them.
     *
     * @param string[] $to
     * @return string[]
     */
    public static function form_email_headers(array $form, array $data, $user_id, array $to) {
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $parsed = self::parse_to_emails(array(self::submitter_email($form, $data, $user_id)));
        $reply = $parsed['emails'][0] ?? '';
        if ($reply !== '' && !in_array(strtolower($reply), array_map('strtolower', $to), true)) {
            $headers[] = 'Reply-To: ' . $reply;
        }
        return $headers;
    }

    /**
     * The form's first email field only. A later field such as "Teacher
     * email" is never treated as the person who filled the form in.
     */
    private static function submitter_email(array $form, array $data, $user_id) {
        if (class_exists('Azure_Forms_Schema')) {
            foreach (Azure_Forms_Schema::input_fields($form['schema'] ?? array()) as $field) {
                if ($field['type'] === 'email') {
                    $value = $data[$field['name']] ?? '';
                    if (is_string($value) && trim($value) !== '') {
                        return trim($value);
                    }
                    break;
                }
            }
        }
        if ($user_id > 0 && function_exists('get_userdata')) {
            $user = get_userdata($user_id);
            if ($user && !empty($user->user_email)) {
                return (string) $user->user_email;
            }
        }
        return '';
    }

    /**
     * Plain-text values for every form token. Callers escape for HTML;
     * `all_fields_html` is the only pre-built HTML and is escaped here.
     *
     * @return array{text:array<string,string>,all_fields_html:string}
     */
    public static function build_form_context(array $form, $entry_id, array $data, $user_id = 0, $submitted_ts = null) {
        $one_line = function ($v) {
            return trim(preg_replace('/[\r\n]+/', ' ', (string) $v));
        };
        $display = function ($v) {
            if (class_exists('Azure_Forms_Schema')) {
                return Azure_Forms_Schema::display_value($v);
            }
            return is_array($v) ? implode(', ', array_map('strval', $v)) : (string) $v;
        };

        $fields = class_exists('Azure_Forms_Schema') ? Azure_Forms_Schema::input_fields($form['schema'] ?? array()) : array();
        $text = array();
        $rows_html = '';
        $rows_text = array();
        $seen = array();
        foreach ($fields as $field) {
            $name = $field['name'];
            $seen[$name] = true;
            $value = $display($data[$name] ?? '');
            $label = trim(strip_tags((string) ($field['label'] ?? ''))) ?: $name;
            $text['field:' . $name] = $value;
            $rows_text[] = $label . ': ' . $value;
            $rows_html .= self::all_fields_row($label, $value);
        }
        foreach ($data as $name => $value) {
            if (!isset($seen[$name])) {
                $text['field:' . $name] = $display($value);
            }
        }

        $ts = $submitted_ts === null ? time() : (int) $submitted_ts;
        $date_format = function_exists('get_option') ? trim(get_option('date_format', 'F j, Y') . ' ' . get_option('time_format', 'g:i a')) : 'Y-m-d H:i';
        $submitted_at = function_exists('wp_date') ? wp_date($date_format, $ts) : gmdate('Y-m-d H:i', $ts);

        $entry_link = class_exists('Azure_Forms_Module')
            ? Azure_Forms_Module::entry_link((int) ($form['id'] ?? 0), (int) $entry_id)
            : admin_url('admin.php?page=azure-plugin-forms&tab=entries&form=' . (int) ($form['id'] ?? 0) . '&entry=' . (int) $entry_id);

        $text += array(
            'form_title'      => $one_line($form['title'] ?? ''),
            'submitted_at'    => $submitted_at,
            'entry_link'      => $entry_link,
            'submitter_email' => self::submitter_email($form, $data, $user_id),
            'site_name'       => class_exists('Azure_Email_Messages') ? Azure_Email_Messages::site_name() : (string) get_bloginfo('name'),
            'all_fields'      => implode("\n", $rows_text),
        );

        $all_html = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:0 0 16px;font-size:14px;">'
            . $rows_html . '</table>';

        return array('text' => $text, 'all_fields_html' => $all_html);
    }

    private static function all_fields_row($label, $value) {
        $esc = function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        };
        return '<tr>'
            . '<th align="left" valign="top" style="padding:6px 12px 6px 0;border-bottom:1px solid #eee;color:#646970;font-weight:600;width:35%;">' . $esc($label) . '</th>'
            . '<td valign="top" style="padding:6px 0;border-bottom:1px solid #eee;color:#1d2327;">' . nl2br($esc($value)) . '</td>'
            . '</tr>';
    }

    /**
     * @return array{0:string,1:string} subject, HTML body ('' when the email no longer exists).
     */
    public static function render_form_email($rule, array $context) {
        $text = $context['text'];
        $subject_vars = array();
        foreach ($text as $k => $v) {
            $subject_vars[$k] = trim(preg_replace('/[\r\n]+/', ' ', (string) $v));
        }

        $email_key = (string) ($rule->email_key ?? '');
        if ($email_key !== '') {
            if (!class_exists('Azure_Email_Messages')) {
                return array('', '');
            }
            $vars = $text;
            $vars['all_fields'] = $context['all_fields_html'];
            list($subject, $body) = Azure_Email_Messages::render($email_key, $vars);
            if ($body === '') {
                return array('', '');
            }
            $subject = Azure_Email_Messages::apply(Azure_Email_Messages::message_for($email_key)['subject'] ?? $subject, $subject_vars);
            return array($subject, $body);
        }

        $html_vars = array();
        foreach ($text as $k => $v) {
            $html_vars[$k] = htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        }
        $html_vars['all_fields'] = $context['all_fields_html'];
        return array(
            self::replace_tokens($rule->email_subject ?: '{form_title}: new response', $subject_vars),
            self::replace_tokens((string) ($rule->content_html ?? ''), $html_vars),
        );
    }

    public function send_form_rule_email($rule, array $form, $entry_id, array $data, $user_id = 0) {
        $to = self::resolve_form_recipients($rule, $form, $data, $user_id);
        $log = function ($msg, $extra = array()) use ($rule, $form, $entry_id) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::info($msg, array_merge(array(
                    'module'   => 'OrderRules',
                    'rule_id'  => (int) $rule->id,
                    'form_id'  => (int) $form['id'],
                    'entry_id' => (int) $entry_id,
                ), $extra));
            }
        };
        if (empty($to)) {
            $log('Form rule skipped: no recipients for this submission');
            return false;
        }
        list($subject, $html) = self::render_form_email($rule, self::build_form_context($form, $entry_id, $data, $user_id));
        if (trim($html) === '') {
            $log('Form rule skipped: its email is missing or empty', array('email_key' => (string) ($rule->email_key ?? '')));
            return false;
        }
        $ok = wp_mail($to, $subject, $html, self::form_email_headers($form, $data, $user_id, $to));
        $log('Form rule email ' . ($ok ? 'sent' : 'failed'), array('to' => implode(',', $to)));
        return (bool) $ok;
    }

    /**
     * @param object $order WC_Order-like
     * @return array<string,string>
     */
    public static function build_token_context($order, $matched_product_id) {
        $customer_first = self::order_get($order, 'get_billing_first_name');
        $customer_last  = self::order_get($order, 'get_billing_last_name');
        $customer_name  = trim($customer_first . ' ' . $customer_last);
        $customer_email = self::order_get($order, 'get_billing_email');
        $order_number   = self::order_get($order, 'get_order_number');
        if ($order_number === '' && method_exists($order, 'get_id')) {
            $order_number = (string) $order->get_id();
        }
        $order_status = self::order_get($order, 'get_status');
        $order_total  = '';
        if (method_exists($order, 'get_formatted_order_total')) {
            $order_total = wp_strip_all_tags($order->get_formatted_order_total());
        } elseif (method_exists($order, 'get_total')) {
            $order_total = (string) $order->get_total();
        }
        $order_date = '';
        if (method_exists($order, 'get_date_created') && $order->get_date_created()) {
            $created = $order->get_date_created();
            $order_date = method_exists($created, 'date_i18n')
                ? $created->date_i18n(get_option('date_format') . ' ' . get_option('time_format'))
                : (string) $created;
        }

        $matched_names = array();
        $matched_qty = 0;
        $all_items = array();
        $child_names = array();
        $field_lines = array();

        $items = method_exists($order, 'get_items') ? $order->get_items() : array();
        foreach ($items as $item) {
            $name = is_object($item) && method_exists($item, 'get_name') ? $item->get_name() : (string) ($item['name'] ?? '');
            $qty  = is_object($item) && method_exists($item, 'get_quantity') ? (int) $item->get_quantity() : (int) ($item['qty'] ?? 1);
            $all_items[] = $name . ' × ' . $qty;
            $is_match = self::item_matches_product($item, $matched_product_id);
            if ($is_match) {
                $matched_names[] = $name;
                $matched_qty += $qty;
            }
            $fields = self::item_product_fields($item);
            foreach ($fields as $label => $value) {
                if ($value === '') {
                    continue;
                }
                $line = $label . ': ' . $value;
                if ($is_match) {
                    $field_lines[] = $line;
                }
                if (self::label_looks_like_child_name($label) && $is_match) {
                    $child_names[] = $value;
                }
            }
        }

        $product_name = implode(', ', array_unique($matched_names));
        $child_name = implode(', ', array_unique($child_names));
        $product_fields = implode("\n", $field_lines);
        $order_items = implode("\n", $all_items);

        $info = array();
        $info[] = 'Order #' . $order_number;
        if ($customer_name !== '') {
            $info[] = 'Customer: ' . $customer_name . ($customer_email !== '' ? ' (' . $customer_email . ')' : '');
        } elseif ($customer_email !== '') {
            $info[] = 'Customer: ' . $customer_email;
        }
        if ($product_name !== '') {
            $info[] = 'Product: ' . $product_name . ' × ' . $matched_qty;
        }
        if ($child_name !== '') {
            $info[] = 'Child: ' . $child_name;
        }
        if ($product_fields !== '') {
            $info[] = $product_fields;
        }
        if ($order_items !== '') {
            $info[] = 'Items:' . "\n" . $order_items;
        }
        if ($order_total !== '') {
            $info[] = 'Total: ' . $order_total;
        }

        $address = '';
        if (method_exists($order, 'get_formatted_billing_address')) {
            $address = wp_strip_all_tags(str_replace('<br/>', "\n", $order->get_formatted_billing_address()));
        }

        return array(
            'customer_name'       => $customer_name,
            'customer_first_name' => $customer_first,
            'customer_email'      => $customer_email,
            'order_number'        => (string) $order_number,
            'order_date'          => $order_date,
            'order_total'         => $order_total,
            'order_status'        => $order_status,
            'product_name'        => $product_name,
            'product_qty'         => (string) $matched_qty,
            'order_items'         => $order_items,
            'order_info'          => implode("\n", $info),
            'billing_address'     => $address,
            'child_name'          => $child_name,
            'product_fields'      => $product_fields,
            'order_name'          => $customer_name,
        );
    }

    public static function item_product_fields($item) {
        $fields = array();
        if (!is_object($item) || !method_exists($item, 'get_meta_data')) {
            return $fields;
        }
        foreach ($item->get_meta_data() as $meta) {
            $key = is_object($meta) ? (string) $meta->key : '';
            $value = is_object($meta) ? $meta->value : '';
            if ($key === '' || strpos($key, '_') === 0 && strpos($key, '_pta_') !== 0) {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                $value = wp_json_encode($value);
            }
            $label = $key;
            if (strpos($key, '_pta_') === 0) {
                $label = substr($key, 5);
            }
            $label = ucwords(str_replace(array('_', '-'), ' ', $label));
            $fields[$label] = is_scalar($value) ? (string) $value : '';
        }
        return $fields;
    }

    public static function label_looks_like_child_name($label) {
        $l = strtolower((string) $label);
        return (strpos($l, 'child') !== false && strpos($l, 'name') !== false);
    }

    public static function get_sellable_products() {
        if (!function_exists('wc_get_products')) {
            return array();
        }
        return wc_get_products(array(
            'status'  => array('publish', 'private'),
            'limit'   => -1,
            'orderby' => 'title',
            'order'   => 'ASC',
            'type'    => array('simple', 'variable', 'subscription', 'variable-subscription'),
        ));
    }

    private static function order_get($order, $method) {
        if (is_object($order) && method_exists($order, $method)) {
            $val = $order->{$method}();
            return $val === null ? '' : (string) $val;
        }
        return '';
    }

    private function redirect_rules($flag, $extra = '', $edit_id = 0) {
        $url = admin_url('admin.php?page=azure-plugin-system&tab=rules');
        $url = add_query_arg('azure_rule', sanitize_key($flag), $url);
        if ($edit_id > 0) {
            $url = add_query_arg('edit', (int) $edit_id, $url);
        }
        if ($extra !== '' && $extra !== null) {
            $url = add_query_arg('azure_rule_extra', sanitize_text_field((string) $extra), $url);
        }
        wp_safe_redirect($url);
        exit;
    }
}
