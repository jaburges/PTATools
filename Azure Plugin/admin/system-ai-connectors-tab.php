<?php
/**
 * System > AI Connectors tab: the module switch, then the AI Connectors
 * admin screen when the module is on.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('Azure_Home_Screen')) {
    Azure_Home_Screen::render_module_switch(
        'ai_connectors',
        __('Let people connect Claude, ChatGPT, Gemini, Copilot or any MCP assistant to the site. It acts with their own permissions, with an audit log.', 'azure-plugin'),
        __('AI Connectors', 'azure-plugin')
    );
}

if (class_exists('Azure_AI_Connectors_Admin')) {
    Azure_AI_Connectors_Admin::get_instance()->render_admin_page();
} else {
    echo '<p>' . esc_html__('Turn AI Connectors on above to see connections, the audit log, apps, tools and settings.', 'azure-plugin') . '</p>';
}
