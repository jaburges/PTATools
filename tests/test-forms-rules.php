<?php
/**
 * Form rules: trigger matching, conditions, recipients, form tokens,
 * custom emails, and order rules left unchanged.
 *
 * Run: php tests/test-forms-rules.php
 */

require __DIR__ . '/wp-shim.php';

function sanitize_email($e) { return strtolower(trim((string) $e)); }
function is_email($e) { return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : false; }
function sanitize_text_field($s) { return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $s))); }
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function wp_date($format, $ts) { return gmdate($format, $ts); }
function get_userdata($id) { return $id === 42 ? (object) array('user_email' => 'member@example.org') : false; }
function current_user_can($cap) { return true; }
function add_query_arg($key, $value, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . $key . '=' . $value; }

$GLOBALS['sent_mail'] = array();
function wp_mail($to, $subject, $body, $headers = array()) {
    $GLOBALS['sent_mail'][] = compact('to', 'subject', 'body', 'headers');
    return true;
}

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

/** Stand-in so the rules module offers the form trigger. */
class Azure_Forms_Module {
    public static function entry_link($form_id, $entry_id) {
        return 'https://example.test/wp-admin/admin.php?page=azure-plugin-forms&tab=entries&form=' . (int) $form_id . '&entry=' . (int) $entry_id;
    }
}

require __DIR__ . '/../Azure Plugin/includes/class-forms-schema.php';
require __DIR__ . '/../Azure Plugin/includes/class-email-messages.php';
require __DIR__ . '/../Azure Plugin/includes/class-order-rules-module.php';

global $wpdb;
$wpdb = new Fake_WPDB();

$t = new TestRunner('Form rules');

$form = array(
    'id'     => 7,
    'title'  => 'Art <Donation>',
    'schema' => Azure_Forms_Schema::sanitize_schema(array(
        array('type' => 'text', 'label' => 'Your name'),
        array('type' => 'email', 'label' => 'Email'),
        array('type' => 'email', 'label' => 'Teacher email'),
        array('type' => 'radio', 'label' => 'Need pickup', 'options' => array('Yes', 'No')),
        array('type' => 'checkboxes', 'label' => 'Items', 'options' => array('Paint', 'Paper')),
        array('type' => 'textarea', 'label' => 'Notes'),
    )),
);
$data = array(
    'your_name'     => 'Sam <b>Lee</b>',
    'email'         => 'sam@example.org',
    'teacher_email' => 'teacher@school.org',
    'need_pickup'   => 'Yes',
    'items'         => array('Paint', 'Paper'),
    'notes'         => "Line one\nLine <two>",
);

function make_rule(array $overrides) {
    return Azure_Order_Rules_Module::hydrate_rule((object) array_merge(array(
        'id'             => 1,
        'enabled'        => 1,
        'trigger_type'   => 'form_submitted',
        'trigger_value'  => '7',
        'action_type'    => 'send_email',
        'to_emails'      => '["chair@example.org"]',
        'email_subject'  => '',
        'content_html'   => '',
        'email_key'      => null,
        'condition_json' => '',
    ), $overrides));
}

// ─── Trigger and conditions ───────────────────────────────────────────

$t->check(isset(Azure_Order_Rules_Module::triggers()['form_submitted']), 'form trigger is offered when the forms module is loaded');

$rule = make_rule(array());
$t->check(Azure_Order_Rules_Module::form_rule_matches($rule, $form, $data), 'rule matches its own form');
$t->check(!Azure_Order_Rules_Module::form_rule_matches($rule, array('id' => 8) + $form, $data), 'rule ignores other forms');
$t->check(!Azure_Order_Rules_Module::form_rule_matches(make_rule(array('trigger_type' => 'product_ordered')), $form, $data), 'product rules never match a form');

$cond = function ($field, $value) {
    return make_rule(array('condition_json' => json_encode(array('field' => $field, 'value' => $value))));
};
$t->check(Azure_Order_Rules_Module::form_rule_matches($cond('need_pickup', 'yes'), $form, $data), 'condition equals is case-insensitive');
$t->check(!Azure_Order_Rules_Module::form_rule_matches($cond('need_pickup', 'No'), $form, $data), 'condition with a different value does not match');
$t->check(Azure_Order_Rules_Module::form_rule_matches($cond('items', 'paper'), $form, $data), 'checkbox condition matches a ticked option');
$t->check(!Azure_Order_Rules_Module::form_rule_matches($cond('items', 'Glue'), $form, $data), 'checkbox condition rejects an unticked option');
$t->check(Azure_Order_Rules_Module::form_rule_matches($cond('notes', ''), $form, $data), 'empty condition value matches any answer');
$t->check(!Azure_Order_Rules_Module::form_rule_matches($cond('missing', ''), $form, $data), 'empty condition value needs an answer');
$t->equals(null, Azure_Order_Rules_Module::decode_condition('{"field":"bad name!","value":"x"}'), 'invalid condition field is ignored');

// ─── Recipients ───────────────────────────────────────────────────────

$recips = make_rule(array('to_emails' => json_encode(array('chair@example.org', '{submitter_email}', '{field:teacher_email}', '{field:your_name}'))));
$t->equals(array('{submitter_email}', '{field:teacher_email}', '{field:your_name}'), $recips->to_token_list, 'form rules keep recipient tokens');
$t->equals(
    array('chair@example.org', 'sam@example.org', 'teacher@school.org'),
    Azure_Order_Rules_Module::resolve_form_recipients($recips, $form, $data),
    'recipients resolve from fixed addresses, submitter and fields; non-emails are skipped'
);
$no_email = $data;
unset($no_email['email']);
$t->equals(
    array('member@example.org'),
    Azure_Order_Rules_Module::resolve_form_recipients(make_rule(array('to_emails' => '["{submitter_email}"]')), $form, array('email' => '') + $no_email, 42),
    '{submitter_email} falls back to the signed-in account'
);

// ─── Saving ───────────────────────────────────────────────────────────

$clean = Azure_Order_Rules_Module::sanitize_rule_input(array(
    'name'            => 'Art pickup',
    'trigger_type'    => 'form_submitted',
    'trigger_value'   => '99',
    'trigger_form'    => '7',
    'to_emails'       => "{submitter_email}, chair@example.org, {field:Teacher_Email}, {bogus}",
    'condition_field' => 'need_pickup',
    'condition_value' => ' Yes ',
    'email_key'       => 'custom_3',
));
$t->equals('7', $clean['trigger_value'], 'form rules take their id from the form picker');
$t->equals(array('chair@example.org'), $clean['to_email_list'], 'fixed addresses kept');
$t->equals(array('{submitter_email}', '{field:teacher_email}'), $clean['to_token_list'], 'tokens kept and lower-cased');
$t->equals(array('{bogus}'), $clean['to_errors'], 'unknown tokens are reported');
$t->equals('{"field":"need_pickup","value":"Yes"}', $clean['condition_json'], 'condition stored as JSON');
$t->equals('custom_3', $clean['email_key'], 'custom email key kept');

$bad_key = Azure_Order_Rules_Module::sanitize_rule_input(array('trigger_type' => 'form_submitted', 'trigger_form' => '7', 'email_key' => 'volunteer_reminder'));
$t->equals('', $bad_key['email_key'], 'only custom emails can be picked');
$new_key = Azure_Order_Rules_Module::sanitize_rule_input(array('trigger_type' => 'form_submitted', 'trigger_form' => '7', 'email_key' => '__new'));
$t->equals('__new', $new_key['email_key'], '"create new" passes through to the save handler');

$product = Azure_Order_Rules_Module::sanitize_rule_input(array(
    'trigger_type'  => 'product_ordered',
    'trigger_value' => '17',
    'trigger_form'  => '7',
    'to_emails'     => 'librarian@school.org, {submitter_email}',
    'email_key'     => 'custom_3',
    'condition_field' => 'x',
));
$t->equals('17', $product['trigger_value'], 'product rules still use the product id');
$t->equals(array('{submitter_email}'), $product['to_errors'], 'product rules do not accept recipient tokens');
$t->equals('', $product['email_key'], 'product rules never store an email key');
$t->equals('', $product['condition_json'], 'product rules never store a condition');
$t->equals('["librarian@school.org"]', $product['to_emails'], 'product rule recipients stored as before');

// ─── Tokens ───────────────────────────────────────────────────────────

$ctx = Azure_Order_Rules_Module::build_form_context($form, 55, $data, 0, 1_800_000_000);
$t->equals('Sam <b>Lee</b>', $ctx['text']['field:your_name'], 'text context keeps raw values for callers to escape');
$t->equals('Paint, Paper', $ctx['text']['field:items'], 'checkbox answers are joined');
$t->equals('sam@example.org', $ctx['text']['submitter_email'], 'submitter_email in context');
$t->check(strpos($ctx['text']['entry_link'], 'form=7&entry=55') !== false, 'entry_link points at the entry');
$t->check(strpos($ctx['all_fields_html'], '&lt;b&gt;Lee&lt;/b&gt;') !== false, 'all_fields escapes answers');
$t->check(strpos($ctx['all_fields_html'], 'Line one<br />') !== false, 'all_fields keeps line breaks');
$t->check(strpos($ctx['all_fields_html'], '<b>') === false, 'no raw HTML from answers in all_fields');

$designed = make_rule(array(
    'email_subject' => 'New {form_title} from {field:your_name}',
    'content_html'  => '<p>{field:your_name}</p>{all_fields}<a href="{entry_link}">x</a> {unknown}',
));
list($subj, $body) = Azure_Order_Rules_Module::render_form_email($designed, $ctx);
$t->equals('New Art <Donation> from Sam <b>Lee</b>', $subj, 'subject gets plain text values');
$t->check(strpos($body, '<p>Sam &lt;b&gt;Lee&lt;/b&gt;</p>') !== false, '{field:x} is HTML-escaped in the body');
$t->check(strpos($body, '<table') !== false, '{all_fields} inserts the table');
$t->check(strpos($body, 'form=7&amp;entry=55') !== false, 'entry_link is attribute-safe');
$t->check(strpos($body, '{unknown}') !== false, 'unknown tokens are left alone');

$nl_ctx = Azure_Order_Rules_Module::build_form_context($form, 1, array('your_name' => "Eve\r\nBcc: x@evil.test") + $data);
list($nl_subject) = Azure_Order_Rules_Module::render_form_email(make_rule(array('email_subject' => 'Hi {field:your_name}')), $nl_ctx);
$t->check(strpos($nl_subject, "\n") === false && strpos($nl_subject, "\r") === false, 'line breaks never reach the subject');

// ─── Custom emails ────────────────────────────────────────────────────

WP_Shim::$options = array();
$key = Azure_Email_Messages::create_custom('Art thank-you');
$t->equals('custom_1', $key, 'first custom email key');
$t->equals('custom_2', Azure_Email_Messages::create_custom(''), 'keys increment');
$catalog = Azure_Email_Messages::catalog();
$t->check(isset($catalog['volunteer_confirmation']), 'built-in emails still listed');
$t->equals('Forms', $catalog[$key]['group'] ?? null, 'custom emails are in the Forms group');
$t->equals('Form email 2', $catalog['custom_2']['label'], 'unnamed emails get a default name');
$t->check(!empty($catalog[$key]['custom']), 'custom emails are flagged');

Azure_Email_Messages::save_custom($key, 'Art thanks', 'Thanks {field:your_name}', '<p>Hi {field:your_name}</p>{all_fields}');
$msg = Azure_Email_Messages::message_for($key);
$t->equals('Art thanks', $msg['label'], 'label saved');
$t->equals('Thanks {field:your_name}', $msg['subject'], 'subject saved');

Azure_Email_Messages::save_message($key, 'ignored', 'ignored');
$t->equals('Thanks {field:your_name}', Azure_Email_Messages::message_for($key)['subject'], 'built-in save path does not touch custom emails');

$keyed = make_rule(array('email_key' => $key));
list($ksubj, $kbody) = Azure_Order_Rules_Module::render_form_email($keyed, $ctx);
$t->equals('Thanks Sam <b>Lee</b>', $ksubj, 'custom email subject uses plain values');
$t->check(strpos($kbody, '<p>Hi Sam &lt;b&gt;Lee&lt;/b&gt;</p>') !== false, 'custom email body escapes answers');
$t->check(strpos($kbody, '<table') !== false && strpos($kbody, '&lt;table') === false, 'custom email inserts all_fields as HTML');

Azure_Email_Messages::delete_custom($key);
$t->check(!isset(Azure_Email_Messages::catalog()[$key]), 'custom emails can be deleted');
list($gone_subject, $gone_body) = Azure_Order_Rules_Module::render_form_email($keyed, $ctx);
$t->equals('', $gone_body, 'a rule whose email was deleted renders nothing');
$t->equals('custom_3', Azure_Email_Messages::create_custom('x'), 'deleted keys are not reused');
$t->check(!Azure_Email_Messages::is_custom_key('custom_x') && !Azure_Email_Messages::is_custom_key('volunteer_reminder'), 'is_custom_key is strict');

// ─── Sending ──────────────────────────────────────────────────────────

$wpdb->tables['wp_azure_order_rules'] = array(
    array('id' => 1, 'enabled' => 1, 'trigger_type' => 'form_submitted', 'trigger_value' => '7', 'action_type' => 'send_email',
          'to_emails' => '["{submitter_email}"]', 'email_subject' => '', 'content_html' => '', 'email_key' => 'custom_3', 'condition_json' => ''),
    array('id' => 2, 'enabled' => 1, 'trigger_type' => 'form_submitted', 'trigger_value' => '8', 'action_type' => 'send_email',
          'to_emails' => '["other@example.org"]', 'email_subject' => '', 'content_html' => '', 'email_key' => 'custom_3', 'condition_json' => ''),
    array('id' => 3, 'enabled' => 1, 'trigger_type' => 'form_submitted', 'trigger_value' => '7', 'action_type' => 'send_email',
          'to_emails' => '["pickup@example.org"]', 'email_subject' => '', 'content_html' => '', 'email_key' => 'custom_3',
          'condition_json' => '{"field":"need_pickup","value":"No"}'),
);

$ref = new ReflectionClass('Azure_Order_Rules_Module');
$module = $ref->newInstanceWithoutConstructor();
$GLOBALS['sent_mail'] = array();
$module->run_form_rules($form, 55, $data, 0);
$t->equals(1, count($GLOBALS['sent_mail']), 'only the matching rule sends');
$t->equals(array('sam@example.org'), $GLOBALS['sent_mail'][0]['to'] ?? null, 'sent to the submitter');
$t->check(in_array('Content-Type: text/html; charset=UTF-8', $GLOBALS['sent_mail'][0]['headers'] ?? array(), true), 'sent as HTML');
$t->check(!preg_grep('/^Reply-To:/', $GLOBALS['sent_mail'][0]['headers'] ?? array()), 'no Reply-To when the email goes to the submitter');

$headers = Azure_Order_Rules_Module::form_email_headers($form, $data, 0, array('office@example.org'));
$t->check(in_array('Reply-To: sam@example.org', $headers, true), 'staff notifications reply to the submitter');
$evil = Azure_Order_Rules_Module::form_email_headers($form, array('email' => "x@example.org\r\nBcc: a@b.c") + $data, 0, array('office@example.org'));
$t->check(!preg_grep('/^Reply-To:/', $evil), 'an injected address never becomes a header');

$GLOBALS['sent_mail'] = array();
$module->run_form_rules($form, 56, array('email' => '') + $data, 0);
$t->equals(0, count($GLOBALS['sent_mail']), 'no mail when the recipient cannot be resolved');

// ─── "Send responses to" in the form's Settings ───────────────────────

/** Filters by the two placeholders form_rules() binds. */
class Form_Rules_WPDB extends Fake_WPDB {
    public function get_results($sql, $output = null) {
        $args = $this->prepare_args;
        $this->prepare_args = array();
        $rows = parent::get_results($sql, $output);
        return array_values(array_filter($rows, function ($r) use ($args) {
            return ($r->trigger_type ?? '') === ($args[0] ?? '') && (string) ($r->trigger_value ?? '') === (string) ($args[1] ?? '');
        }));
    }
}
$wpdb = new Form_Rules_WPDB();
$rules_table = 'wp_azure_order_rules';
$wpdb->tables[$rules_table] = array(
    array('id' => 1, 'enabled' => 1, 'trigger_type' => 'product_ordered', 'trigger_value' => '9', 'to_emails' => '["shop@example.org"]', 'email_key' => null, 'condition_json' => ''),
);
$wpdb->next_ids[$rules_table] = 2;

$t->equals(null, Azure_Order_Rules_Module::basic_form_rule(Azure_Order_Rules_Module::form_rules(9)), 'a product rule is never a form rule');

$bad = Azure_Order_Rules_Module::set_form_recipients(9, 'Contact', 'office@example.org, not-an-email');
$t->check(is_wp_error($bad) && strpos($bad->get_error_message(), 'not-an-email') !== false, 'invalid addresses are named and nothing is saved');
$t->equals(1, count($wpdb->tables[$rules_table]), 'no rule is created for invalid input');

$t->check(Azure_Order_Rules_Module::set_form_recipients(9, 'Contact', '') === true, 'an empty field on a form without rules does nothing');
$t->equals(1, count($wpdb->tables[$rules_table]), 'no empty rule is created');

Azure_Order_Rules_Module::set_form_recipients(9, 'Contact <PTSA>', 'Office@Example.org; {submitter_email}');
$created = Azure_Order_Rules_Module::form_rules(9);
$t->equals(1, count($created), 'the first recipients create a rule for the form');
$t->equals('["office@example.org","{submitter_email}"]', $created[0]->to_emails ?? null, 'addresses and the submitter token are stored');
$t->equals('Contact: new response', $created[0]->name, 'the rule is named after the form');
$t->check(Azure_Email_Messages::is_custom_key($created[0]->email_key) && isset(Azure_Email_Messages::catalog()[$created[0]->email_key]), 'the rule gets its own editable email');
$t->equals(1, (int) $created[0]->enabled, 'the new rule is on');

$wpdb->insert($rules_table, array('enabled' => 1, 'trigger_type' => 'form_submitted', 'trigger_value' => '9', 'name' => 'Events only',
    'to_emails' => '["events@example.org"]', 'email_key' => 'custom_9', 'condition_json' => '{"field":"topic","value":"Events"}'));
Azure_Order_Rules_Module::set_form_recipients(9, 'Contact', 'webmaster@example.org');
$after = Azure_Order_Rules_Module::form_rules(9);
$t->equals('["webmaster@example.org"]', $after[0]->to_emails, 'changing the field updates the basic rule');
$t->equals('["events@example.org"]', $after[1]->to_emails, 'conditional rules are left alone');

$summary = Azure_Order_Rules_Module::form_notify_summary(9);
$t->equals('webmaster@example.org', $summary['to'], 'the field shows the basic rule\'s recipients');
$t->equals(1, count($summary['others']), 'other rules are listed');
$t->equals(array('field' => 'topic', 'value' => 'Events'), $summary['others'][0]['condition'], 'with their condition');
$t->check(strpos($summary['others'][0]['edit_url'], 'tab=rules&edit=') !== false, 'with a link to edit them');

Azure_Order_Rules_Module::set_form_recipients(9, 'Contact', '');
$cleared = Azure_Order_Rules_Module::form_rules(9)[0];
$t->equals(0, (int) $cleared->enabled, 'clearing the field pauses the rule');
$t->equals('[]', $cleared->to_emails, 'and empties its recipients');
$t->check(Azure_Email_Messages::is_custom_key($cleared->email_key), 'the email is kept');
$t->equals(false, Azure_Order_Rules_Module::form_notify_summary(9)['paused'], 'an empty field is not reported as paused');

Azure_Order_Rules_Module::set_form_recipients(9, 'Contact', 'webmaster@example.org');
$t->equals(1, (int) Azure_Order_Rules_Module::form_rules(9)[0]->enabled, 'filling it again turns the rule back on');

$wpdb->update($rules_table, array('enabled' => 0), array('id' => $cleared->id));
$writes = count($wpdb->writes);
Azure_Order_Rules_Module::set_form_recipients(9, 'Contact', 'WEBMASTER@example.org');
$t->equals($writes, count($wpdb->writes), 'saving the same recipients writes nothing');
$t->equals(0, (int) Azure_Order_Rules_Module::form_rules(9)[0]->enabled, 'so a rule paused under Rules stays paused');
$t->equals(true, Azure_Order_Rules_Module::form_notify_summary(9)['paused'], 'and the form shows it as paused');

exit($t->finish() > 0 ? 1 : 0);
