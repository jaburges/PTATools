<?php
/**
 * Emails → Messages
 *
 * Editable copies of the plugin's own mail. Placeholders are filled
 * when the message is sent.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Azure_Email_Messages')) {
    require_once AZURE_PLUGIN_PATH . 'includes/class-email-messages.php';
}

$messages = Azure_Email_Messages::catalog();
$group = '';
$return_rule = isset($_GET['return_rule']) ? absint($_GET['return_rule']) : 0;
$has_custom = false;
foreach ($messages as $m) {
    if (!empty($m['custom'])) {
        $has_custom = true;
        break;
    }
}
?>

<?php if (!empty($_GET['saved'])): ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Messages saved.', 'azure-plugin'); ?></p></div>
<?php endif; ?>

<?php if ($return_rule > 0): ?>
    <div class="notice notice-info">
        <p>
            <?php esc_html_e('Edit the form email below and save. When you are done:', 'azure-plugin'); ?>
            <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-system&tab=rules&edit=' . $return_rule)); ?>"><?php esc_html_e('Back to the rule', 'azure-plugin'); ?></a>
        </p>
    </div>
<?php endif; ?>

<p class="description" style="margin:8px 0 16px;">
    <?php esc_html_e('These are the emails the plugin writes itself. Leave a placeholder in the text and it is filled in for each send. Store order mail, class and auction messages, tickets, and newsletters stay in their own editors.', 'azure-plugin'); ?>
</p>

<form method="post">
    <?php wp_nonce_field('azure_email_messages'); ?>
    <input type="hidden" name="azure_email_messages_save" value="1" />
    <button type="submit" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;"><?php esc_html_e('Save messages', 'azure-plugin'); ?></button>
    <?php if ($return_rule > 0): ?>
        <input type="hidden" name="return_rule" value="<?php echo (int) $return_rule; ?>" />
    <?php endif; ?>

    <?php foreach ($messages as $key => $fallback): ?>
        <?php
        $msg = Azure_Email_Messages::message_for($key);
        if (!$msg) {
            continue;
        }
        $subject = (string) $msg['subject'];
        $intro = isset($msg['intro']) ? (string) $msg['intro'] : '';
        $body = $intro !== '' ? str_replace('{intro}', $intro, (string) $msg['body']) : (string) $msg['body'];
        $rows = (isset($msg['format']) && $msg['format'] === 'html') ? 18 : 12;
        if ($group !== (string) $msg['group']) {
            $group = (string) $msg['group'];
            echo '<h2 style="margin:24px 0 8px;">' . esc_html($group) . '</h2>';
        }
        ?>
        <div id="azure-msg-<?php echo esc_attr($key); ?>" style="background:#fff; border:1px solid #ccd0d4; padding:16px 20px; margin-bottom:16px; scroll-margin-top:48px;">
            <?php if (!empty($msg['custom'])): ?>
                <p style="margin:0 0 8px;">
                    <label for="azure-msg-label-<?php echo esc_attr($key); ?>"><strong><?php esc_html_e('Name', 'azure-plugin'); ?></strong></label><br>
                    <input type="text" class="regular-text" id="azure-msg-label-<?php echo esc_attr($key); ?>"
                           name="message_label[<?php echo esc_attr($key); ?>]"
                           value="<?php echo esc_attr($msg['label']); ?>" />
                </p>
            <?php else: ?>
                <h3 style="margin:0 0 4px;"><?php echo esc_html($msg['label']); ?></h3>
            <?php endif; ?>
            <p class="description" style="margin:0 0 12px;"><?php echo esc_html($msg['description']); ?></p>
            <?php if (!empty($msg['tokens'])): ?>
                <p class="description" style="margin:0 0 12px;">
                    <?php foreach ($msg['tokens'] as $token): ?>
                        <code><?php echo esc_html($token); ?></code>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
            <p>
                <label for="azure-msg-subject-<?php echo esc_attr($key); ?>"><strong><?php esc_html_e('Subject', 'azure-plugin'); ?></strong></label><br>
                <input type="text" class="large-text" id="azure-msg-subject-<?php echo esc_attr($key); ?>"
                       name="message_subject[<?php echo esc_attr($key); ?>]"
                       value="<?php echo esc_attr($subject); ?>" />
            </p>
            <p>
                <label for="azure-msg-body-<?php echo esc_attr($key); ?>"><strong><?php esc_html_e('Message', 'azure-plugin'); ?></strong></label><br>
                <textarea class="large-text" rows="<?php echo (int) $rows; ?>" id="azure-msg-body-<?php echo esc_attr($key); ?>"
                          name="message_body[<?php echo esc_attr($key); ?>]"><?php echo esc_textarea($body); ?></textarea>
            </p>
            <p style="margin:0;">
                <?php if (!empty($msg['custom'])): ?>
                    <button type="submit" class="button button-link-delete" name="azure_email_custom_delete" value="<?php echo esc_attr($key); ?>"
                            onclick="return confirm('<?php echo esc_js(__('Delete this email? Rules that send it will stop sending until you pick another.', 'azure-plugin')); ?>');">
                        <?php esc_html_e('Delete this email', 'azure-plugin'); ?>
                    </button>
                <?php else: ?>
                    <button type="submit" class="button" name="azure_email_message_reset" value="<?php echo esc_attr($key); ?>">
                        <?php esc_html_e('Reset this message', 'azure-plugin'); ?>
                    </button>
                <?php endif; ?>
            </p>
        </div>
    <?php endforeach; ?>

    <?php if (!$has_custom): ?>
        <h2 style="margin:24px 0 8px;"><?php esc_html_e('Forms', 'azure-plugin'); ?></h2>
        <p class="description"><?php esc_html_e('Emails you write yourself for form rules, such as a thank-you to the person who filled in a form or a notice to a committee chair.', 'azure-plugin'); ?></p>
    <?php endif; ?>
    <p>
        <button type="submit" class="button" name="azure_email_custom_create" value="1"><?php esc_html_e('+ Add a form email', 'azure-plugin'); ?></button>
    </p>

    <p>
        <button type="submit" class="button button-primary"><?php esc_html_e('Save messages', 'azure-plugin'); ?></button>
    </p>
</form>
