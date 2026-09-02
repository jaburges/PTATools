<?php
/**
 * Order-rule email designer — same GrapesJS + newsletter blocks.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Azure_Newsletter_Email_Css')) {
    require_once AZURE_PLUGIN_PATH . 'includes/class-newsletter-email-css.php';
}

wp_enqueue_media();
wp_enqueue_style('grapesjs', 'https://unpkg.com/grapesjs@0.21.10/dist/css/grapes.min.css', array(), '0.21.10');
wp_enqueue_style('azure-newsletter-admin', AZURE_PLUGIN_URL . 'css/newsletter-admin.css', array(), AZURE_PLUGIN_VERSION);
wp_enqueue_style('azure-order-rules-admin', AZURE_PLUGIN_URL . 'css/order-rules-admin.css', array('azure-newsletter-admin'), AZURE_PLUGIN_VERSION);
wp_enqueue_script('grapesjs', 'https://unpkg.com/grapesjs@0.21.10/dist/grapes.min.js', array(), '0.21.10', true);
wp_enqueue_script('grapesjs-newsletter', 'https://unpkg.com/grapesjs-preset-newsletter@1.0.2/dist/index.js', array('grapesjs'), '1.0.2', true);
wp_add_inline_script('grapesjs', 'window.__wpBackbone = window.Backbone; window.__wpUnderscore = window._;', 'before');
wp_add_inline_script('grapesjs-newsletter', 'if(window.__wpBackbone){window.Backbone=window.__wpBackbone;}if(window.__wpUnderscore){window._=window.__wpUnderscore;}', 'after');
wp_enqueue_script('newsletter-editor', AZURE_PLUGIN_URL . 'js/newsletter-editor.js', array('jquery', 'media-views', 'grapesjs', 'grapesjs-newsletter'), AZURE_PLUGIN_VERSION, true);
wp_enqueue_script('azure-order-rule-email-editor', AZURE_PLUGIN_URL . 'js/order-rule-email-editor.js', array('jquery', 'newsletter-editor'), AZURE_PLUGIN_VERSION, true);

$rule_id = isset($_GET['rule_id']) ? absint($_GET['rule_id']) : 0;
$rule = Azure_Order_Rules_Module::get_rule($rule_id);
if (!$rule) {
    echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__('Rule not found.', 'azure-plugin') . '</p></div>';
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=azure-plugin-selling&tab=rules')) . '">&larr; ' . esc_html__('Back to Rules', 'azure-plugin') . '</a></p></div>';
    return;
}

$tokens = Azure_Order_Rules_Module::tokens();
$subject = $rule->email_subject ?: Azure_Order_Rules_Module::default_email_subject();
$initial_html = $rule->content_html ?: Azure_Order_Rules_Module::default_email_html();
$initial_json = $rule->content_json ?: '';
$back_url = admin_url('admin.php?page=azure-plugin-selling&tab=rules');
?>

<div class="wrap newsletter-editor-wrap azure-order-rule-editor-wrap">
    <div class="editor-toolbar">
        <div class="toolbar-left">
            <a href="<?php echo esc_url($back_url); ?>" class="button">&larr; <?php esc_html_e('Back to Rules', 'azure-plugin'); ?></a>
            <strong class="azure-order-rule-editor-title"><?php echo esc_html($rule->name); ?></strong>
        </div>
        <div class="toolbar-center">
            <div class="device-buttons">
                <button type="button" class="device-btn active" data-device="desktop" title="<?php esc_attr_e('Desktop', 'azure-plugin'); ?>">
                    <span class="dashicons dashicons-desktop"></span>
                </button>
                <button type="button" class="device-btn" data-device="tablet" title="<?php esc_attr_e('Tablet', 'azure-plugin'); ?>">
                    <span class="dashicons dashicons-tablet"></span>
                </button>
                <button type="button" class="device-btn" data-device="mobile" title="<?php esc_attr_e('Mobile', 'azure-plugin'); ?>">
                    <span class="dashicons dashicons-smartphone"></span>
                </button>
            </div>
        </div>
        <div class="toolbar-right">
            <span id="save-status"></span>
            <button type="button" class="button" id="btn-undo" title="<?php esc_attr_e('Undo', 'azure-plugin'); ?>">
                <span class="dashicons dashicons-undo"></span>
            </button>
            <button type="button" class="button" id="btn-redo" title="<?php esc_attr_e('Redo', 'azure-plugin'); ?>">
                <span class="dashicons dashicons-redo"></span>
            </button>
            <button type="button" class="button" id="btn-code" title="<?php esc_attr_e('View Code', 'azure-plugin'); ?>">
                <span class="dashicons dashicons-editor-code"></span>
            </button>
            <button type="button" class="button button-primary" id="btn-save-rule-email">
                <?php esc_html_e('Save email', 'azure-plugin'); ?>
            </button>
        </div>
    </div>

    <div class="azure-order-rule-subject-bar">
        <label for="newsletter_subject"><?php esc_html_e('Subject', 'azure-plugin'); ?></label>
        <input type="text" id="newsletter_subject" name="newsletter_subject" value="<?php echo esc_attr($subject); ?>" class="regular-text" />
        <p class="description"><?php esc_html_e('Tokens such as {product_name} and {order_number} work in the subject too.', 'azure-plugin'); ?></p>
    </div>

    <div class="editor-container">
        <div class="editor-sidebar editor-sidebar-left">
            <div class="sidebar-tabs">
                <button type="button" class="sidebar-tab active" data-panel="blocks"><?php esc_html_e('Blocks', 'azure-plugin'); ?></button>
                <button type="button" class="sidebar-tab" data-panel="layers"><?php esc_html_e('Layers', 'azure-plugin'); ?></button>
                <button type="button" class="sidebar-tab" data-panel="tokens"><?php esc_html_e('Variables', 'azure-plugin'); ?></button>
            </div>
            <div id="blocks-panel" class="sidebar-panel"></div>
            <div id="layers-panel" class="sidebar-panel" style="display:none;"></div>
            <div id="tokens-panel" class="sidebar-panel" style="display:none;">
                <p class="description"><?php esc_html_e('Click a variable to copy it, then paste into a text block. They are replaced when the order email is sent.', 'azure-plugin'); ?></p>
                <ul class="azure-order-rule-tokens">
                    <?php foreach ($tokens as $token => $help): ?>
                        <li>
                            <button type="button" class="azure-order-rule-token button" data-token="<?php echo esc_attr($token); ?>">
                                <?php echo esc_html($token); ?>
                            </button>
                            <span><?php echo esc_html($help); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div class="editor-main">
            <div id="gjs-editor"></div>
        </div>

        <div class="editor-sidebar editor-sidebar-right">
            <div class="sidebar-tabs">
                <button type="button" class="sidebar-tab active" data-panel="settings"><?php esc_html_e('Settings', 'azure-plugin'); ?></button>
                <button type="button" class="sidebar-tab" data-panel="styles"><?php esc_html_e('Styles', 'azure-plugin'); ?></button>
            </div>
            <div id="settings-panel" class="sidebar-panel">
                <div class="settings-placeholder">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <p><?php esc_html_e('Select an element to see its settings', 'azure-plugin'); ?></p>
                </div>
                <div id="traits-container"></div>
            </div>
            <div id="styles-panel" class="sidebar-panel" style="display:none;">
                <div id="styles-container"></div>
            </div>
        </div>
    </div>

    <input type="hidden" id="newsletter_id" value="0" />
    <input type="hidden" id="newsletter_name" value="<?php echo esc_attr($rule->name); ?>" />
    <input type="hidden" id="newsletter_from" value="" />
    <input type="hidden" id="newsletter_content_html" value="" />
    <input type="hidden" id="newsletter_content_json" value="" />
</div>

<script>
var newsletterEditorConfig = {
    ajaxUrl: <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
    nonce: <?php echo wp_json_encode(wp_create_nonce('azure_newsletter_nonce')); ?>,
    pluginUrl: <?php echo wp_json_encode(AZURE_PLUGIN_URL); ?>,
    initialContent: <?php echo wp_json_encode($initial_json); ?>,
    initialHtml: <?php echo wp_json_encode($initial_html); ?>,
    columnStackCss: <?php echo wp_json_encode(Azure_Newsletter_Email_Css::column_stack_css()); ?>,
    templateId: 0,
    templateName: '',
    editTemplateId: 0,
    saveAsTemplate: false,
    mode: 'order_rule'
};
var azureOrderRuleEditor = {
    ajaxUrl: <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
    nonce: <?php echo wp_json_encode(wp_create_nonce('azure_order_rules')); ?>,
    ruleId: <?php echo (int) $rule->id; ?>
};
</script>
