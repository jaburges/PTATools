<?php
/**
 * Newsletter Editor - Progressive Workflow with Template Selection
 */

if (!defined('ABSPATH')) {
    exit;
}

// Enqueue GrapesJS and dependencies
wp_enqueue_media();
wp_enqueue_style('grapesjs', 'https://unpkg.com/grapesjs@0.21.10/dist/css/grapes.min.css', array(), '0.21.10');
wp_enqueue_script('grapesjs', 'https://unpkg.com/grapesjs@0.21.10/dist/grapes.min.js', array(), '0.21.10', true);
wp_enqueue_script('grapesjs-newsletter', 'https://unpkg.com/grapesjs-preset-newsletter@1.0.2/dist/index.js', array('grapesjs'), '1.0.2', true);
wp_enqueue_script('newsletter-editor', AZURE_PLUGIN_URL . 'js/newsletter-editor.js', array('jquery', 'media-views', 'grapesjs', 'grapesjs-newsletter'), AZURE_PLUGIN_VERSION, true);

// Save WordPress Backbone/underscore before GrapesJS scripts load, in case they overwrite globals
wp_add_inline_script('grapesjs', 'window.__wpBackbone = window.Backbone; window.__wpUnderscore = window._;', 'before');
// Restore after GrapesJS scripts are done
wp_add_inline_script('grapesjs-newsletter', 'if(window.__wpBackbone){window.Backbone=window.__wpBackbone;}if(window.__wpUnderscore){window._=window.__wpUnderscore;}', 'after');

$settings = Azure_Settings::get_all_settings();
$from_addresses = $settings['newsletter_from_addresses'] ?? array();

// Get current user for test send
$current_user = wp_get_current_user();

// Check if editing existing newsletter
$newsletter_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$newsletter = null;

if ($newsletter_id > 0) {
    global $wpdb;
    $table = $wpdb->prefix . 'azure_newsletters';
    $newsletter = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $newsletter_id));
}

// Load template if specified
$template_id = isset($_GET['template']) ? intval($_GET['template']) : 0;
$template = null;
if ($template_id > 0 && !$newsletter) {
    global $wpdb;
    $templates_table = $wpdb->prefix . 'azure_newsletter_templates';
    $template = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$templates_table} WHERE id = %d", $template_id));
}

// Load all templates for template selection step
$all_templates = array();
global $wpdb;
$templates_table = $wpdb->prefix . 'azure_newsletter_templates';
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$templates_table}'") === $templates_table;
if ($table_exists) {
    $all_templates = $wpdb->get_results("SELECT * FROM {$templates_table} ORDER BY is_system DESC, name ASC");
}

// Determine the step
// Step 0 = Template selection (only for new newsletters without template specified)
// Steps 1-4 = Regular workflow
$step = isset($_GET['step']) ? intval($_GET['step']) : 1;

// Show template selection for new newsletters without a template pre-selected
$show_template_selection = (!$newsletter && !$template && $step === 1 && !isset($_GET['blank']));

// Get saved recipient lists for editing
$saved_lists = array('all'); // Default to all
if ($newsletter && !empty($newsletter->recipient_lists)) {
    $saved_lists = json_decode($newsletter->recipient_lists, true);
    if (!is_array($saved_lists)) {
        $saved_lists = array('all');
    }
}
?>

<div class="wrap newsletter-editor-wrap">
    
    <?php if ($show_template_selection): ?>
    <!-- Template Selection Screen -->
    <div class="template-selection-screen">
        <div class="template-selection-header">
            <h1><?php _e('Choose a Template', 'azure-plugin'); ?></h1>
            <p class="description"><?php _e('Select a template to get started, or start with a blank canvas.', 'azure-plugin'); ?></p>
        </div>
        
        <div class="template-selection-grid">
            <!-- Start from Scratch Option -->
            <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter&action=new&blank=1'); ?>" class="template-selection-card blank-template">
                <div class="template-preview blank">
                    <span class="dashicons dashicons-plus-alt2"></span>
                </div>
                <div class="template-info">
                    <h3><?php _e('Start from Scratch', 'azure-plugin'); ?></h3>
                    <p><?php _e('Begin with a blank canvas and build your email from scratch.', 'azure-plugin'); ?></p>
                </div>
            </a>
            
            <?php foreach ($all_templates as $tpl): 
                // Prepare HTML content for preview
                $preview_html = '';
                if (!empty($tpl->content_html)) {
                    // Wrap the content in a basic HTML structure with reset styles for consistent preview
                    $preview_html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
                        body { margin: 0; padding: 0; font-family: Arial, sans-serif; font-size: 14px; line-height: 1.5; }
                        img { max-width: 100%; height: auto; }
                        table { border-collapse: collapse; }
                    </style></head><body>' . $tpl->content_html . '</body></html>';
                }
            ?>
            <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter&action=new&template=' . $tpl->id); ?>" class="template-selection-card">
                <div class="template-preview">
                    <?php if (!empty($preview_html)): ?>
                    <div class="preview-wrapper">
                        <iframe srcdoc="<?php echo esc_attr($preview_html); ?>" sandbox="allow-same-origin" scrolling="no"></iframe>
                    </div>
                    <?php else: ?>
                    <div class="template-placeholder">
                        <span class="dashicons dashicons-email-alt"></span>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="template-info">
                    <h3><?php echo esc_html($tpl->name); ?></h3>
                    <?php if ($tpl->is_system): ?>
                    <span class="template-badge system"><?php _e('System', 'azure-plugin'); ?></span>
                    <?php else: ?>
                    <span class="template-badge custom"><?php _e('Custom', 'azure-plugin'); ?></span>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        
        <div class="template-selection-footer">
            <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter&tab=campaigns'); ?>" class="button">
                <?php _e('Cancel', 'azure-plugin'); ?>
            </a>
        </div>
    </div>
    
    <?php else: ?>
    <!-- Regular Editor -->
    <div class="editor-header">
        <h1><?php echo $newsletter ? __('Edit Newsletter', 'azure-plugin') : __('Create Newsletter', 'azure-plugin'); ?></h1>
        <div class="editor-header-actions">
            <span id="save-status"></span>
            <button type="button" class="button" id="save-draft-top">
                <span class="dashicons dashicons-cloud-saved"></span>
                <?php _e('Save Draft', 'azure-plugin'); ?>
            </button>
            <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter&tab=campaigns'); ?>" class="button">
                <?php _e('Back to Campaigns', 'azure-plugin'); ?>
            </a>
        </div>
    </div>
    
    <!-- Arrow Flow Progress Steps -->
    <div class="arrow-flow-steps">
        <div class="arrow-step <?php echo $step === 1 ? 'current' : ''; ?> <?php echo $step > 1 ? 'completed' : ''; ?>" data-step="1">
            <div class="arrow-content">
                <?php if ($step > 1): ?>
                <span class="dashicons dashicons-yes-alt"></span>
                <?php else: ?>
                <span class="step-num">1</span>
                <?php endif; ?>
                <span class="step-text"><?php _e('Setup', 'azure-plugin'); ?></span>
            </div>
        </div>
        <div class="arrow-step <?php echo $step === 2 ? 'current' : ''; ?> <?php echo $step > 2 ? 'completed' : ''; ?> <?php echo $step < 2 ? 'pending' : ''; ?>" data-step="2">
            <div class="arrow-content">
                <?php if ($step > 2): ?>
                <span class="dashicons dashicons-yes-alt"></span>
                <?php else: ?>
                <span class="step-num">2</span>
                <?php endif; ?>
                <span class="step-text"><?php _e('Design', 'azure-plugin'); ?></span>
            </div>
        </div>
        <div class="arrow-step <?php echo $step === 3 ? 'current' : ''; ?> <?php echo $step > 3 ? 'completed' : ''; ?> <?php echo $step < 3 ? 'pending' : ''; ?>" data-step="3">
            <div class="arrow-content">
                <?php if ($step > 3): ?>
                <span class="dashicons dashicons-yes-alt"></span>
                <?php else: ?>
                <span class="step-num">3</span>
                <?php endif; ?>
                <span class="step-text"><?php _e('Review', 'azure-plugin'); ?></span>
            </div>
        </div>
        <div class="arrow-step <?php echo $step === 4 ? 'current' : ''; ?> <?php echo $step < 4 ? 'pending' : ''; ?>" data-step="4">
            <div class="arrow-content">
                <span class="step-num">4</span>
                <span class="step-text"><?php _e('Send', 'azure-plugin'); ?></span>
            </div>
        </div>
    </div>
    
    <form id="newsletter-form" method="post">
        <?php wp_nonce_field('newsletter_editor', 'newsletter_nonce'); ?>
        <input type="hidden" name="newsletter_id" id="newsletter_id" value="<?php echo esc_attr($newsletter_id); ?>">
        <input type="hidden" name="current_step" id="current_step" value="<?php echo esc_attr($step); ?>">
        
        <!-- Step 1: Setup -->
        <div class="step-content" id="step-1-content" style="<?php echo $step !== 1 ? 'display:none;' : ''; ?>">
            <!-- Top Navigation -->
            <div class="step-nav step-nav-top">
                <div class="nav-left">
                    <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter&tab=campaigns'); ?>" class="button">
                        &larr; <?php _e('Cancel', 'azure-plugin'); ?>
                    </a>
                </div>
                <div class="nav-right">
                    <button type="button" class="button button-primary next-step" data-next="2">
                        <?php _e('Continue to Design', 'azure-plugin'); ?> &rarr;
                    </button>
                </div>
            </div>
            
            <div class="step-panel">
                <h2><?php _e('Newsletter Setup', 'azure-plugin'); ?></h2>
                
                <table class="form-table">
                    <tr>
                        <th><label for="newsletter_name"><?php _e('Internal Name', 'azure-plugin'); ?> <span class="required">*</span></label></th>
                        <td>
                            <input type="text" id="newsletter_name" name="newsletter_name" class="regular-text" required
                                   value="<?php echo esc_attr($newsletter->name ?? ''); ?>"
                                   placeholder="<?php _e('e.g., December 2025 Newsletter', 'azure-plugin'); ?>">
                            <p class="description"><?php _e('For your reference only. Not shown to subscribers.', 'azure-plugin'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="newsletter_subject"><?php _e('Email Subject', 'azure-plugin'); ?> <span class="required">*</span></label></th>
                        <td>
                            <input type="text" id="newsletter_subject" name="newsletter_subject" class="large-text" required
                                   value="<?php echo esc_attr($newsletter->subject ?? ''); ?>"
                                   placeholder="<?php _e('e.g., Your December Newsletter is here!', 'azure-plugin'); ?>">
                            <div class="subject-tools">
                                <button type="button" class="button button-small insert-personalization" data-tag="{{first_name}}">
                                    <?php _e('Insert First Name', 'azure-plugin'); ?>
                                </button>
                                <span class="char-count"><span id="subject-chars">0</span>/60</span>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="newsletter_from_name_input"><?php _e('From', 'azure-plugin'); ?> <span class="required">*</span></label></th>
                        <td>
                            <?php
                            // Editor pre-fills from the saved newsletter (if editing) or
                            // from sensible site defaults so the form is always submittable
                            // even when no sender addresses are pre-configured under
                            // Newsletter -> Settings.
                            $current_email = !empty($newsletter->from_email) ? $newsletter->from_email : get_option('admin_email');
                            $current_name  = !empty($newsletter->from_name)  ? $newsletter->from_name  : get_bloginfo('name');
                            ?>
                            <?php if (!empty($from_addresses)): ?>
                            <select id="newsletter_from_picker" class="regular-text" style="margin-bottom:8px;">
                                <option value=""><?php _e('— Choose a saved sender —', 'azure-plugin'); ?></option>
                                <?php foreach ($from_addresses as $addr): ?>
                                <option value="<?php echo esc_attr($addr['email'] . '|' . $addr['name']); ?>"
                                        <?php selected($current_email . '|' . $current_name, $addr['email'] . '|' . $addr['name']); ?>>
                                    <?php echo esc_html($addr['name'] . ' <' . $addr['email'] . '>'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description" style="margin:0 0 6px 0;"><?php _e('Pick a saved sender, or enter one directly below.', 'azure-plugin'); ?></p>
                            <?php endif; ?>
                            <div class="newsletter-from-inputs" style="display:flex;gap:8px;flex-wrap:wrap;">
                                <input type="text"
                                       id="newsletter_from_name_input"
                                       name="newsletter_from_name_input"
                                       placeholder="<?php esc_attr_e('From name (e.g., Wilder PTSA)', 'azure-plugin'); ?>"
                                       value="<?php echo esc_attr($current_name); ?>"
                                       style="flex:1;min-width:180px;"
                                       required>
                                <input type="email"
                                       id="newsletter_from_email_input"
                                       name="newsletter_from_email_input"
                                       placeholder="<?php esc_attr_e('From email', 'azure-plugin'); ?>"
                                       value="<?php echo esc_attr($current_email); ?>"
                                       style="flex:1;min-width:220px;"
                                       required>
                            </div>
                            <input type="hidden"
                                   id="newsletter_from"
                                   name="newsletter_from"
                                   value="<?php echo esc_attr($current_email . '|' . $current_name); ?>">
                            <?php if (empty($from_addresses)): ?>
                            <p class="description" style="margin-top:6px;">
                                <?php _e('Tip: save reusable sender addresses in', 'azure-plugin'); ?>
                                <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter&tab=settings'); ?>"><?php _e('Newsletter Settings', 'azure-plugin'); ?></a>.
                            </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e('Recipients', 'azure-plugin'); ?> <span class="required">*</span></label></th>
                        <td>
                            <div class="recipient-checkboxes">
                                <?php
                                // Get count of all WordPress users
                                $all_users_count = count_users()['total_users'];
                                ?>
                                <label class="recipient-checkbox">
                                    <input type="checkbox" name="newsletter_lists[]" value="all" data-count="<?php echo esc_attr($all_users_count); ?>" <?php checked(in_array('all', $saved_lists)); ?>>
                                    <span class="checkbox-label">
                                        <strong><?php _e('All WordPress Subscribers', 'azure-plugin'); ?></strong>
                                        <span class="list-count">(<?php echo number_format($all_users_count); ?>)</span>
                                    </span>
                                </label>
                                <?php
                                // Load custom lists with counts
                                global $wpdb;
                                $lists_table = $wpdb->prefix . 'azure_newsletter_lists';
                                $members_table = $wpdb->prefix . 'azure_newsletter_list_members';
                                if ($wpdb->get_var("SHOW TABLES LIKE '{$lists_table}'") === $lists_table) {
                                    $lists = $wpdb->get_results("SELECT id, name, type, criteria FROM {$lists_table} ORDER BY name");
                                    foreach ($lists as $list):
                                        // Calculate count based on list type
                                        $list_count = 0;
                                        if ($list->type === 'custom') {
                                            $list_count = $wpdb->get_var($wpdb->prepare(
                                                "SELECT COUNT(*) FROM {$members_table} WHERE list_id = %d AND unsubscribed_at IS NULL",
                                                $list->id
                                            ));
                                        } elseif ($list->type === 'role') {
                                            $criteria = json_decode($list->criteria, true);
                                            if (!empty($criteria['roles'])) {
                                                foreach ($criteria['roles'] as $role) {
                                                    $list_count += count(get_users(array('role' => $role, 'fields' => 'ID')));
                                                }
                                            }
                                        } elseif ($list->type === 'all_users') {
                                            // For all_users type lists, count all WordPress users
                                            $list_count = count_users()['total_users'];
                                        }
                                        // Debug output (hidden)
                                        // error_log("Newsletter list {$list->name} (ID:{$list->id}): type={$list->type}, count={$list_count}");
                                    ?>
                                <label class="recipient-checkbox">
                                    <input type="checkbox" name="newsletter_lists[]" value="<?php echo esc_attr($list->id); ?>" data-count="<?php echo esc_attr($list_count); ?>" <?php checked(in_array((string)$list->id, $saved_lists) || in_array($list->id, $saved_lists)); ?>>
                                    <span class="checkbox-label">
                                        <strong><?php echo esc_html($list->name); ?></strong>
                                        <span class="list-type"><?php echo esc_html(ucfirst($list->type)); ?></span>
                                        <span class="list-count">(<?php echo number_format($list_count); ?>)</span>
                                    </span>
                                </label>
                                    <?php endforeach;
                                }
                                ?>
                            </div>
                            <p class="description">
                                <span class="dashicons dashicons-info-outline"></span>
                                <?php _e('Select one or more lists. Duplicate emails are automatically removed.', 'azure-plugin'); ?>
                            </p>
                            <div id="recipient-summary" class="recipient-summary">
                                <strong><?php _e('Total recipients:', 'azure-plugin'); ?></strong>
                                <span id="total-recipient-count">0</span>
                            </div>
                        </td>
                    </tr>
                </table>
                
            </div>
            
            <!-- Bottom Navigation -->
            <div class="step-nav step-nav-bottom">
                <div class="nav-left">
                    <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter&tab=campaigns'); ?>" class="button">
                        &larr; <?php _e('Cancel', 'azure-plugin'); ?>
                    </a>
                </div>
                <div class="nav-right">
                    <button type="button" class="button button-primary next-step" data-next="2">
                        <?php _e('Continue to Design', 'azure-plugin'); ?> &rarr;
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Step 2: Design (GrapesJS Editor) -->
        <div class="step-content step-design" id="step-2-content" style="<?php echo $step !== 2 ? 'display:none;' : ''; ?>">
            <div class="editor-toolbar">
                <div class="toolbar-left">
                    <button type="button" class="button prev-step" data-prev="1">&larr; <?php _e('Back', 'azure-plugin'); ?></button>
                </div>
                <div class="toolbar-center">
                    <div class="device-buttons">
                        <button type="button" class="device-btn active" data-device="desktop" title="<?php _e('Desktop', 'azure-plugin'); ?>">
                            <span class="dashicons dashicons-desktop"></span>
                        </button>
                        <button type="button" class="device-btn" data-device="tablet" title="<?php _e('Tablet', 'azure-plugin'); ?>">
                            <span class="dashicons dashicons-tablet"></span>
                        </button>
                        <button type="button" class="device-btn" data-device="mobile" title="<?php _e('Mobile', 'azure-plugin'); ?>">
                            <span class="dashicons dashicons-smartphone"></span>
                        </button>
                    </div>
                </div>
                <div class="toolbar-right">
                    <button type="button" class="button" id="btn-undo" title="<?php _e('Undo', 'azure-plugin'); ?>">
                        <span class="dashicons dashicons-undo"></span>
                    </button>
                    <button type="button" class="button" id="btn-redo" title="<?php _e('Redo', 'azure-plugin'); ?>">
                        <span class="dashicons dashicons-redo"></span>
                    </button>
                    <button type="button" class="button" id="btn-code" title="<?php _e('View Code', 'azure-plugin'); ?>">
                        <span class="dashicons dashicons-editor-code"></span>
                    </button>
                    <button type="button" class="button" id="btn-update-design" title="<?php _e('Update design and save changes', 'azure-plugin'); ?>">
                        <span class="dashicons dashicons-update"></span> <?php _e('Update', 'azure-plugin'); ?>
                    </button>
                    <button type="button" class="button button-primary next-step" data-next="3">
                        <?php _e('Review', 'azure-plugin'); ?> &rarr;
                    </button>
                </div>
            </div>
            
            <div class="editor-container">
                <!-- LEFT SIDEBAR: Blocks & Layers -->
                <div class="editor-sidebar editor-sidebar-left">
                    <div class="sidebar-tabs">
                        <button type="button" class="sidebar-tab active" data-panel="blocks"><?php _e('Blocks', 'azure-plugin'); ?></button>
                        <button type="button" class="sidebar-tab" data-panel="layers"><?php _e('Layers', 'azure-plugin'); ?></button>
                    </div>
                    <div id="blocks-panel" class="sidebar-panel"></div>
                    <div id="layers-panel" class="sidebar-panel" style="display:none;"></div>
                </div>
                
                <!-- MAIN CANVAS -->
                <div class="editor-main">
                    <div class="editor-help-bar" id="editor-help-bar">
                        <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                        <span class="editor-help-text">
                            <?php _e('Tip: drag blocks from the left. <strong>Double-click any text</strong> to edit it — the floating toolbar lets you bold, italicize, and add <strong>links</strong>.', 'azure-plugin'); ?>
                        </span>
                        <button type="button" class="editor-help-dismiss" aria-label="<?php esc_attr_e('Dismiss tip', 'azure-plugin'); ?>">&times;</button>
                    </div>
                    <div id="gjs-editor"></div>
                </div>
                
                <!-- RIGHT SIDEBAR: Settings & Styles -->
                <div class="editor-sidebar editor-sidebar-right">
                    <div class="sidebar-tabs">
                        <button type="button" class="sidebar-tab active" data-panel="settings"><?php _e('Settings', 'azure-plugin'); ?></button>
                        <button type="button" class="sidebar-tab" data-panel="styles"><?php _e('Styles', 'azure-plugin'); ?></button>
                    </div>
                    <div id="settings-panel" class="sidebar-panel">
                        <div class="settings-placeholder">
                            <span class="dashicons dashicons-admin-generic"></span>
                            <p><?php _e('Select an element to see its settings', 'azure-plugin'); ?></p>
                        </div>
                        <div id="traits-container"></div>
                    </div>
                    <div id="styles-panel" class="sidebar-panel" style="display:none;">
                        <div class="selected-element-indicator" id="selected-element-name">
                            <span class="dashicons dashicons-info-outline"></span>
                            <span class="element-name"><?php _e('No element selected', 'azure-plugin'); ?></span>
                        </div>
                        <div id="styles-container"></div>
                    </div>
                </div>
            </div>
            
            <input type="hidden" id="newsletter_content_html" name="newsletter_content_html" value="">
            <input type="hidden" id="newsletter_content_json" name="newsletter_content_json" value="">
            
            <!-- Bottom Navigation for Design Step -->
            <div class="step-nav step-nav-bottom design-bottom-nav">
                <div class="nav-left">
                    <button type="button" class="button prev-step" data-prev="1">&larr; <?php _e('Back to Setup', 'azure-plugin'); ?></button>
                </div>
                <div class="nav-right">
                    <button type="button" class="button button-primary next-step" data-next="3">
                        <?php _e('Review', 'azure-plugin'); ?> &rarr;
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Step 3: Review & Test -->
        <div class="step-content" id="step-3-content" style="<?php echo $step !== 3 ? 'display:none;' : ''; ?>">
            <!-- Top Navigation -->
            <div class="step-nav step-nav-top">
                <div class="nav-left">
                    <button type="button" class="button prev-step" data-prev="2">&larr; <?php _e('Back to Editor', 'azure-plugin'); ?></button>
                </div>
                <div class="nav-right">
                    <button type="button" class="button button-primary next-step" data-next="4">
                        <?php _e('Schedule / Send', 'azure-plugin'); ?> &rarr;
                    </button>
                </div>
            </div>
            
            <div class="step-panel">
                <h2><?php _e('Review & Test', 'azure-plugin'); ?></h2>
                
                <div class="review-grid">
                    <div class="review-preview">
                        <h3><?php _e('Preview', 'azure-plugin'); ?></h3>
                        <div class="preview-device-toggle">
                            <button type="button" class="preview-device active" data-device="desktop"><?php _e('Desktop', 'azure-plugin'); ?></button>
                            <button type="button" class="preview-device" data-device="mobile"><?php _e('Mobile', 'azure-plugin'); ?></button>
                        </div>
                        <iframe id="preview-frame" class="preview-frame"></iframe>
                    </div>
                    
                    <div class="review-sidebar">
                        <!-- Summary -->
                        <div class="review-section">
                            <h4><?php _e('Summary', 'azure-plugin'); ?></h4>
                            <table class="summary-table">
                                <tr>
                                    <td><?php _e('Subject:', 'azure-plugin'); ?></td>
                                    <td id="summary-subject"></td>
                                </tr>
                                <tr>
                                    <td><?php _e('From:', 'azure-plugin'); ?></td>
                                    <td id="summary-from"></td>
                                </tr>
                                <tr>
                                    <td><?php _e('Recipients:', 'azure-plugin'); ?></td>
                                    <td id="summary-recipients"></td>
                                </tr>
                            </table>
                        </div>
                        
                        <!-- Spam Score -->
                        <div class="review-section">
                            <h4><?php _e('Spam Score', 'azure-plugin'); ?></h4>
                            <div id="spam-score-container">
                                <div style="margin-bottom: 10px;">
                                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                        <input type="checkbox" id="use-spamassassin" checked>
                                        <span><?php _e('Include SpamAssassin check', 'azure-plugin'); ?></span>
                                        <span class="dashicons dashicons-info" title="<?php esc_attr_e('Uses Postmark\'s free SpamAssassin API for detailed analysis', 'azure-plugin'); ?>" style="color: #2271b1; cursor: help;"></span>
                                    </label>
                                </div>
                                <button type="button" class="button" id="check-spam-score">
                                    <?php _e('Check Spam Score', 'azure-plugin'); ?>
                                </button>
                                <div id="spam-score-result" style="display:none; margin-top: 15px;"></div>
                            </div>
                        </div>
                        
                        <!-- Accessibility -->
                        <div class="review-section">
                            <h4><?php _e('Accessibility', 'azure-plugin'); ?></h4>
                            <div id="accessibility-container">
                                <button type="button" class="button" id="check-accessibility">
                                    <?php _e('Check Accessibility', 'azure-plugin'); ?>
                                </button>
                                <div id="accessibility-result" style="display:none;"></div>
                            </div>
                        </div>
                        
                        <!-- Test Send -->
                        <div class="review-section">
                            <h4><?php _e('Send Test Email', 'azure-plugin'); ?></h4>
                            <div class="test-send-form">
                                <input type="email" id="test_email" value="<?php echo esc_attr($current_user->user_email); ?>" class="regular-text">
                                <button type="button" class="button" id="send-test-email">
                                    <?php _e('Send Test', 'azure-plugin'); ?>
                                </button>
                            </div>
                            <div id="test-send-result" style="display:none;"></div>
                        </div>
                    </div>
                </div>
                
            </div>
            
            <!-- Bottom Navigation -->
            <div class="step-nav step-nav-bottom">
                <div class="nav-left">
                    <button type="button" class="button prev-step" data-prev="2">&larr; <?php _e('Back to Editor', 'azure-plugin'); ?></button>
                </div>
                <div class="nav-right">
                    <button type="button" class="button button-primary next-step" data-next="4">
                        <?php _e('Schedule / Send', 'azure-plugin'); ?> &rarr;
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Step 4: Schedule & Send -->
        <div class="step-content" id="step-4-content" style="<?php echo $step !== 4 ? 'display:none;' : ''; ?>">
            <!-- Top Navigation -->
            <div class="step-nav step-nav-top">
                <div class="nav-left">
                    <button type="button" class="button prev-step" data-prev="3">&larr; <?php _e('Back to Review', 'azure-plugin'); ?></button>
                </div>
                <div class="nav-right">
                    <button type="submit" name="send_newsletter" class="button button-primary" id="final-send-btn-top">
                        <span class="dashicons dashicons-email"></span>
                        <?php _e('Send Newsletter', 'azure-plugin'); ?>
                    </button>
                </div>
            </div>
            
            <div class="step-panel">
                <h2><?php _e('Schedule & Send', 'azure-plugin'); ?></h2>
                
                <!-- Campaign Summary -->
                <div class="campaign-summary-panel">
                    <h3><span class="dashicons dashicons-info-outline"></span> <?php _e('Campaign Summary', 'azure-plugin'); ?></h3>
                    <div class="summary-grid">
                        <div class="summary-item">
                            <label><?php _e('Name', 'azure-plugin'); ?></label>
                            <span id="summary-name-final">-</span>
                        </div>
                        <div class="summary-item">
                            <label><?php _e('Subject', 'azure-plugin'); ?></label>
                            <span id="summary-subject-final">-</span>
                        </div>
                        <div class="summary-item">
                            <label><?php _e('From', 'azure-plugin'); ?></label>
                            <span id="summary-from-final">-</span>
                        </div>
                        <div class="summary-item recipients-summary">
                            <label><?php _e('Recipients', 'azure-plugin'); ?></label>
                            <span id="summary-recipients-final">-</span>
                        </div>
                        <div class="summary-item">
                            <label><?php _e('Spam Score', 'azure-plugin'); ?></label>
                            <span id="summary-spam-final">
                                <em><?php _e('Check in Review step', 'azure-plugin'); ?></em>
                            </span>
                        </div>
                        <div class="summary-item">
                            <label><?php _e('Sending Service', 'azure-plugin'); ?></label>
                            <span id="summary-service-final">
                                <?php 
                                $service = $settings['newsletter_sending_service'] ?? 'not configured';
                                $service_names = array(
                                    'mailgun' => 'Mailgun',
                                    'sendgrid' => 'SendGrid',
                                    'ses' => 'Amazon SES',
                                    'smtp' => 'SMTP',
                                    'office365' => 'Office 365'
                                );
                                echo esc_html($service_names[$service] ?? ucfirst($service));
                                
                                // Show connection status
                                $configured = false;
                                switch ($service) {
                                    case 'mailgun':
                                        $configured = !empty($settings['newsletter_mailgun_api_key']) && !empty($settings['newsletter_mailgun_domain']);
                                        break;
                                    case 'sendgrid':
                                        $configured = !empty($settings['newsletter_sendgrid_api_key']);
                                        break;
                                    case 'ses':
                                        $configured = !empty($settings['newsletter_ses_access_key']);
                                        break;
                                    case 'smtp':
                                        $configured = !empty($settings['newsletter_smtp_host']);
                                        break;
                                    case 'office365':
                                        $configured = !empty($settings['newsletter_office365_client_id']);
                                        break;
                                }
                                if ($configured) {
                                    echo ' <span class="status-configured">✓</span>';
                                } else {
                                    echo ' <span class="status-not-configured">⚠ ' . __('Not configured', 'azure-plugin') . '</span>';
                                }
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="send-options">
                    <div class="send-option">
                        <label>
                            <input type="radio" name="send_option" value="now" checked>
                            <span class="option-card">
                                <span class="dashicons dashicons-controls-play"></span>
                                <strong><?php _e('Send Now', 'azure-plugin'); ?></strong>
                                <span><?php _e('Start sending immediately', 'azure-plugin'); ?></span>
                            </span>
                        </label>
                    </div>
                    
                    <div class="send-option">
                        <label>
                            <input type="radio" name="send_option" value="schedule">
                            <span class="option-card">
                                <span class="dashicons dashicons-calendar-alt"></span>
                                <strong><?php _e('Schedule', 'azure-plugin'); ?></strong>
                                <span><?php _e('Send at a specific time', 'azure-plugin'); ?></span>
                            </span>
                        </label>
                    </div>
                    
                    <div class="send-option">
                        <label>
                            <input type="radio" name="send_option" value="draft">
                            <span class="option-card">
                                <span class="dashicons dashicons-edit"></span>
                                <strong><?php _e('Save as Draft', 'azure-plugin'); ?></strong>
                                <span><?php _e('Continue editing later', 'azure-plugin'); ?></span>
                            </span>
                        </label>
                    </div>
                </div>
                
                <div class="schedule-options" id="schedule-options" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th><label><?php _e('Date & Time (PST)', 'azure-plugin'); ?></label></th>
                            <td>
                                <input type="date" id="schedule_date" name="schedule_date" 
                                       min="<?php echo date('Y-m-d'); ?>">
                                <input type="time" id="schedule_time" name="schedule_time" value="09:00">
                                <p class="description"><?php _e('Pacific Standard Time (PST)', 'azure-plugin'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Create WordPress Page Option -->
                <div class="page-options">
                    <h4><?php _e('Archive Page Options', 'azure-plugin'); ?></h4>
                    <label>
                        <input type="checkbox" name="create_wp_page" id="create_wp_page" value="1">
                        <?php _e('Create a WordPress page for this newsletter (view in browser)', 'azure-plugin'); ?>
                    </label>
                    
                    <div class="page-settings" id="page-settings" style="display:none;">
                        <table class="form-table">
                            <tr>
                                <th><label><?php _e('Page Category/Tag', 'azure-plugin'); ?></label></th>
                                <td>
                                    <input type="text" name="page_category" id="page_category" class="regular-text"
                                           value="<?php echo esc_attr($settings['newsletter_default_category'] ?? 'newsletter'); ?>">
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
            </div>
            
            <!-- Bottom Navigation -->
            <div class="step-nav step-nav-bottom">
                <div class="nav-left">
                    <button type="button" class="button prev-step" data-prev="3">&larr; <?php _e('Back to Review', 'azure-plugin'); ?></button>
                </div>
                <div class="nav-center">
                    <button type="submit" name="save_newsletter" class="button" id="save-draft-btn">
                        <?php _e('Save Draft', 'azure-plugin'); ?>
                    </button>
                </div>
                <div class="nav-right">
                    <button type="submit" name="send_newsletter" class="button button-primary button-hero" id="final-send-btn">
                        <span class="dashicons dashicons-email"></span>
                        <?php _e('Send Newsletter', 'azure-plugin'); ?>
                    </button>
                </div>
            </div>
        </div>
    </form>
    <?php endif; // End of else (not template selection) ?>
</div>

<script>
var newsletterEditorConfig = {
    ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>',
    nonce: '<?php echo wp_create_nonce('azure_newsletter_nonce'); ?>',
    pluginUrl: '<?php echo AZURE_PLUGIN_URL; ?>',
    initialContent: <?php echo json_encode($newsletter->content_json ?? ($template ? $template->content_json : '') ?? ''); ?>,
    initialHtml: <?php echo json_encode($newsletter->content_html ?? ($template ? $template->content_html : '') ?? ''); ?>,
    templateId: <?php echo $template_id; ?>,
    templateName: <?php echo json_encode($template ? $template->name : ''); ?>
};
</script>




