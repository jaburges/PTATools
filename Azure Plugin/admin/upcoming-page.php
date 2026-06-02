<?php
/**
 * Upcoming Events Admin Page
 * 
 * Provides documentation and preview for the [up-next] shortcode.
 * 
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get native pta_event categories for reference
$event_categories = array();
if (class_exists('Azure_Upcoming_Module')) {
    $event_categories = Azure_Upcoming_Module::get_event_categories();
}

// Theme presets (v3.125). The Themes class is loaded lazily by
// azure-plugin.php on plugins_loaded; force-require here so this
// page can render the editor even if the class isn't already in
// memory.
if (!class_exists('Azure_UpNext_Themes')) {
    $themes_path = AZURE_PLUGIN_PATH . 'includes/class-upnext-themes.php';
    if (file_exists($themes_path)) {
        require_once $themes_path;
    }
}
$upnext_themes = class_exists('Azure_UpNext_Themes') ? Azure_UpNext_Themes::get_themes() : array();
?>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
<div class="wrap azure-admin-wrap">
    <h1>
        <span class="dashicons dashicons-calendar-alt" style="margin-right: 8px;"></span>
        <?php _e('Upcoming Events Shortcode', 'azure-plugin'); ?>
    </h1>
<?php endif; ?>
    
    <div class="azure-admin-content">
        
        <!-- Overview Card -->
        <div class="azure-card">
            <h2><?php _e('Overview', 'azure-plugin'); ?></h2>
            <p>
                <?php _e('The <code>[up-next]</code> shortcode displays upcoming events from PTA Tools native <code>pta_event</code> posts in a clean, customizable format. Perfect for sidebars, home pages, or anywhere you want to highlight upcoming events.', 'azure-plugin'); ?>
            </p>
        </div>
        
        <!-- Basic Usage Card -->
        <div class="azure-card">
            <h2><?php _e('Basic Usage', 'azure-plugin'); ?></h2>
            
            <h3><?php _e('Simple Example', 'azure-plugin'); ?></h3>
            <pre class="azure-code">[up-next]</pre>
            <p class="description"><?php _e('Shows events for this week and next week in a single column.', 'azure-plugin'); ?></p>
            
            <h3><?php _e('Two Column Layout', 'azure-plugin'); ?></h3>
            <pre class="azure-code">[up-next columns="2"]</pre>
            <p class="description"><?php _e('Shows this week and next week side by side.', 'azure-plugin'); ?></p>
            
            <h3><?php _e('Exclude Categories', 'azure-plugin'); ?></h3>
            <pre class="azure-code">[up-next exclude-categories="Art,Music,Private Events"]</pre>
            <p class="description"><?php _e('Hides events from specific pta_event categories.', 'azure-plugin'); ?></p>
            
            <h3><?php _e('This Week Only', 'azure-plugin'); ?></h3>
            <pre class="azure-code">[up-next next-week="false"]</pre>
            <p class="description"><?php _e('Shows only this week\'s events.', 'azure-plugin'); ?></p>
        </div>
        
        <!-- All Attributes Card -->
        <div class="azure-card">
            <h2><?php _e('All Shortcode Attributes', 'azure-plugin'); ?></h2>
            
            <table class="widefat azure-attributes-table">
                <thead>
                    <tr>
                        <th><?php _e('Attribute', 'azure-plugin'); ?></th>
                        <th><?php _e('Default', 'azure-plugin'); ?></th>
                        <th><?php _e('Description', 'azure-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>current-week</code></td>
                        <td><code>"true"</code></td>
                        <td><?php _e('Show this week\'s events. Set to "false" to hide.', 'azure-plugin'); ?></td>
                    </tr>
                    <tr>
                        <td><code>next-week</code></td>
                        <td><code>"true"</code></td>
                        <td><?php _e('Show next week\'s events. Set to "false" to hide.', 'azure-plugin'); ?></td>
                    </tr>
                    <tr>
                        <td><code>columns</code></td>
                        <td><code>"1"</code></td>
                        <td><?php _e('Number of columns (1, 2, or 3). Stacks on mobile.', 'azure-plugin'); ?></td>
                    </tr>
                    <tr>
                        <td><code>exclude-categories</code></td>
                        <td><code>""</code></td>
                        <td><?php _e('Comma-separated list of pta_event category names to exclude.', 'azure-plugin'); ?></td>
                    </tr>
                    <tr>
                        <td><code>week-start</code></td>
                        <td><code>"monday"</code></td>
                        <td><?php _e('Day the week starts on: "monday" or "sunday".', 'azure-plugin'); ?></td>
                    </tr>
                    <tr>
                        <td><code>show-time</code></td>
                        <td><code>"true"</code></td>
                        <td><?php _e('Show event start time. All-day events never show time.', 'azure-plugin'); ?></td>
                    </tr>
                    <tr>
                        <td><code>link-titles</code></td>
                        <td><code>"true"</code></td>
                        <td><?php _e('Make event titles clickable links to the event page.', 'azure-plugin'); ?></td>
                    </tr>
                    <tr>
                        <td><code>show-empty</code></td>
                        <td><code>"true"</code></td>
                        <td><?php _e('Show week sections even if no events. Set to "false" to hide empty weeks.', 'azure-plugin'); ?></td>
                    </tr>
                    <tr>
                        <td><code>empty-message</code></td>
                        <td><code>"No upcoming events."</code></td>
                        <td><?php _e('Message shown when a week has no events.', 'azure-plugin'); ?></td>
                    </tr>
                    <tr>
                        <td><code>this-week-title</code></td>
                        <td><code>"This Week"</code></td>
                        <td><?php _e('Custom heading for current week section.', 'azure-plugin'); ?></td>
                    </tr>
                    <tr>
                        <td><code>next-week-title</code></td>
                        <td><code>"Next Week"</code></td>
                        <td><?php _e('Custom heading for next week section.', 'azure-plugin'); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- Full Example Card -->
        <div class="azure-card">
            <h2><?php _e('Full Example', 'azure-plugin'); ?></h2>
            <pre class="azure-code">[up-next 
    current-week="true" 
    next-week="true" 
    columns="2" 
    exclude-categories="Private,Staff Only" 
    week-start="monday" 
    show-time="true" 
    link-titles="true"
    this-week-title="Happening Now"
    next-week-title="Coming Up"]</pre>
        </div>
        
        <?php if (!empty($event_categories)) : ?>
        <!-- Available Categories Card -->
        <div class="azure-card">
            <h2><?php _e('Available Event Categories', 'azure-plugin'); ?></h2>
            <p><?php _e('These are the event categories currently configured for PTA Tools events. Use these exact names (case-sensitive) in the <code>exclude-categories</code> attribute:', 'azure-plugin'); ?></p>
            <div class="azure-category-list">
                <?php foreach ($event_categories as $cat) : ?>
                <span class="azure-category-tag"><?php echo esc_html($cat); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Theme presets editor (v3.125) -->
        <div class="azure-card">
            <h2><span class="dashicons dashicons-art" style="margin-right:6px;"></span><?php _e('Theme presets', 'azure-plugin'); ?></h2>
            <p>
                <?php _e('Define named theme presets and apply them via the shortcode\u2019s <code>theme</code> attribute, e.g. <code>[up-next theme="custom1"]</code>. Built-in themes (Default / Card light / Card dark) are read-only and always available; user themes you add here can be edited or removed at any time.', 'azure-plugin'); ?>
            </p>

            <div id="upnext-themes-editor" style="margin-top:12px;">
                <table class="wp-list-table widefat fixed striped" id="upnext-themes-table">
                    <thead>
                        <tr>
                            <th style="width:170px;"><?php _e('Slug', 'azure-plugin'); ?></th>
                            <th><?php _e('Label', 'azure-plugin'); ?></th>
                            <th style="width:90px;"><?php _e('Layout', 'azure-plugin'); ?></th>
                            <th style="width:70px;"><?php _e('Cols', 'azure-plugin'); ?></th>
                            <th style="width:80px;"><?php _e('Image', 'azure-plugin'); ?></th>
                            <th style="width:200px;"><?php _e('Accent', 'azure-plugin'); ?></th>
                            <th style="width:340px;"><?php _e('Actions', 'azure-plugin'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($upnext_themes as $t): $builtin = !empty($t['is_builtin']); ?>
                        <tr data-slug="<?php echo esc_attr($t['slug']); ?>" data-builtin="<?php echo $builtin ? '1' : '0'; ?>" class="upnext-theme-main-row">
                            <td><code><?php echo esc_html($t['slug']); ?></code><?php if ($builtin): ?> <em style="color:#646970;font-size:11px;">built-in</em><?php endif; ?></td>
                            <td>
                                <input type="text" class="t-label regular-text" value="<?php echo esc_attr($t['label']); ?>" <?php disabled($builtin); ?>>
                            </td>
                            <td>
                                <select class="t-layout" <?php disabled($builtin); ?>>
                                    <?php foreach (array('rows' => 'Rows', 'grid' => 'Grid', 'compact' => 'Compact') as $v => $lab): ?>
                                        <option value="<?php echo esc_attr($v); ?>" <?php selected(($t['layout'] ?? 'rows'), $v); ?>><?php echo esc_html($lab); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="number" min="1" max="4" class="t-columns small-text" value="<?php echo esc_attr((int) ($t['columns'] ?? 1)); ?>" <?php disabled($builtin); ?>>
                            </td>
                            <td>
                                <label style="display:flex;align-items:center;gap:4px;font-size:12px;">
                                    <input type="checkbox" class="t-show-image" <?php checked(!empty($t['show_image'])); ?> <?php disabled($builtin); ?>>
                                    <select class="t-image-position" <?php disabled($builtin || empty($t['show_image'])); ?> style="min-width:60px;">
                                        <option value="left" <?php selected(($t['image_position'] ?? 'left'), 'left'); ?>>left</option>
                                        <option value="top"  <?php selected(($t['image_position'] ?? 'left'), 'top'); ?>>top</option>
                                    </select>
                                </label>
                            </td>
                            <td>
                                <input type="color" class="t-accent-color"     value="<?php echo esc_attr($t['accent_color']      ?? '#2271b1'); ?>" <?php disabled($builtin); ?>>
                                <input type="color" class="t-bg-color"         value="<?php echo esc_attr($t['bg_color']          ?? '#ffffff'); ?>" <?php disabled($builtin); ?> title="Card background">
                                <input type="color" class="t-text-color"       value="<?php echo esc_attr($t['text_color']        ?? '#1d2327'); ?>" <?php disabled($builtin); ?> title="Body text">
                                <input type="color" class="t-border-color"     value="<?php echo esc_attr($t['border_color']      ?? '#dcdcde'); ?>" <?php disabled($builtin); ?> title="Border">
                            </td>
                            <td>
                                <button type="button" class="button button-small upnext-theme-preview" data-slug="<?php echo esc_attr($t['slug']); ?>">Preview</button>
                                <button type="button" class="button button-small upnext-theme-copy"    data-slug="<?php echo esc_attr($t['slug']); ?>">Copy</button>
                                <?php if ($builtin): ?>
                                    <button type="button" class="button button-small upnext-theme-clone"  data-slug="<?php echo esc_attr($t['slug']); ?>">Clone</button>
                                <?php else: ?>
                                    <button type="button" class="button button-small upnext-theme-edit-advanced" data-slug="<?php echo esc_attr($t['slug']); ?>">Edit advanced</button>
                                    <button type="button" class="button button-small button-link-delete upnext-theme-delete" data-slug="<?php echo esc_attr($t['slug']); ?>">Delete</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if (!$builtin):
                            // Advanced editor row — hidden by default, shown
                            // when the admin clicks Edit advanced. Holds all
                            // the v3.128 newsletter-style fields (outer
                            // container, header, footer, date pill, location
                            // badge) plus the more granular sizing fields
                            // that don't fit in the main row.
                        ?>
                        <tr data-slug="<?php echo esc_attr($t['slug']); ?>" class="upnext-theme-advanced-row" style="display:none;">
                            <td colspan="7" style="background:#f6f7f7;padding:14px 16px;">
                                <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px 18px;">
                                    <fieldset style="grid-column:1/-1;border:1px solid #dcdcde;padding:10px 12px;border-radius:4px;background:#fff;">
                                        <legend style="padding:0 6px;font-weight:600;">Outer container (frame around all events)</legend>
                                        <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;">
                                            <label>Background <input type="color" class="t-outer-bg-color"     value="<?php echo esc_attr($t['outer_bg_color']     ?? '#ffffff'); ?>"></label>
                                            <label>Border color <input type="color" class="t-outer-border-color" value="<?php echo esc_attr($t['outer_border_color'] ?? '#dcdcde'); ?>"></label>
                                            <label>Border width (px) <input type="number" min="0" max="24" class="t-outer-border-width small-text" value="<?php echo esc_attr((int) ($t['outer_border_width'] ?? 0)); ?>"></label>
                                            <label>Border radius (px) <input type="number" min="0" max="48" class="t-outer-border-radius small-text" value="<?php echo esc_attr((int) ($t['outer_border_radius'] ?? 0)); ?>"></label>
                                            <label>Padding (px) <input type="number" min="0" max="96" class="t-outer-padding small-text" value="<?php echo esc_attr((int) ($t['outer_padding'] ?? 0)); ?>"></label>
                                            <label>Max width (px, 0 = none) <input type="number" min="0" max="1200" class="t-outer-max-width small-text" value="<?php echo esc_attr((int) ($t['outer_max_width'] ?? 0)); ?>"></label>
                                        </div>
                                    </fieldset>

                                    <fieldset style="grid-column:1/-1;border:1px solid #dcdcde;padding:10px 12px;border-radius:4px;background:#fff;">
                                        <legend style="padding:0 6px;font-weight:600;">Header text (above all events)</legend>
                                        <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;">
                                            <label style="grid-column:1/3;">Text (supports <code>{date}</code>) <input type="text" class="t-header-text regular-text" value="<?php echo esc_attr($t['header_text'] ?? ''); ?>" style="width:100%;" placeholder="e.g. Week of {date}"></label>
                                            <label>Color <input type="color" class="t-header-color" value="<?php echo esc_attr($t['header_color'] ?? '#1d2327'); ?>"></label>
                                            <label>Size (px) <input type="number" min="12" max="72" class="t-header-size small-text" value="<?php echo esc_attr((int) ($t['header_size'] ?? 28)); ?>"></label>
                                            <label>Align
                                                <select class="t-header-align">
                                                    <?php foreach (array('left','center','right') as $a): ?>
                                                        <option value="<?php echo $a; ?>" <?php selected(($t['header_align'] ?? 'left'), $a); ?>><?php echo $a; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label>Font
                                                <select class="t-header-font">
                                                    <?php foreach (array('default'=>'Default','serif'=>'Serif','display'=>'Display (chunky)','mono'=>'Monospace') as $v=>$lab): ?>
                                                        <option value="<?php echo esc_attr($v); ?>" <?php selected(($t['header_font'] ?? 'default'), $v); ?>><?php echo esc_html($lab); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label><input type="checkbox" class="t-header-underline" <?php checked(!empty($t['header_underline'])); ?>> Underline</label>
                                        </div>
                                    </fieldset>

                                    <fieldset style="grid-column:1/-1;border:1px solid #dcdcde;padding:10px 12px;border-radius:4px;background:#fff;">
                                        <legend style="padding:0 6px;font-weight:600;">Footer HTML (below all events)</legend>
                                        <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;">
                                            <label style="grid-column:1/3;">HTML (links allowed) <textarea class="t-footer-html" rows="2" style="width:100%;" placeholder='e.g. Find out more on our website:&lt;br&gt;&lt;strong&gt;LWPTSA.net/calendar&lt;/strong&gt;'><?php echo esc_textarea($t['footer_html'] ?? ''); ?></textarea></label>
                                            <label>Color <input type="color" class="t-footer-color" value="<?php echo esc_attr($t['footer_color'] ?? '#646970'); ?>"></label>
                                            <label>Size (px) <input type="number" min="10" max="28" class="t-footer-size small-text" value="<?php echo esc_attr((int) ($t['footer_size'] ?? 14)); ?>"></label>
                                            <label>Align
                                                <select class="t-footer-align">
                                                    <?php foreach (array('left','center','right') as $a): ?>
                                                        <option value="<?php echo $a; ?>" <?php selected(($t['footer_align'] ?? 'center'), $a); ?>><?php echo $a; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                        </div>
                                    </fieldset>

                                    <fieldset style="grid-column:1/3;border:1px solid #dcdcde;padding:10px 12px;border-radius:4px;background:#fff;">
                                        <legend style="padding:0 6px;font-weight:600;">Date pill (on left of each card)</legend>
                                        <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;">
                                            <label>Style
                                                <select class="t-date-pill">
                                                    <option value="none" <?php selected(($t['date_pill'] ?? 'none'), 'none'); ?>>None</option>
                                                    <option value="left" <?php selected(($t['date_pill'] ?? 'none'), 'left'); ?>>Left pill (Day / #)</option>
                                                </select>
                                            </label>
                                            <label>Background <input type="color" class="t-pill-bg-color"   value="<?php echo esc_attr($t['pill_bg_color']   ?? '#f4a623'); ?>"></label>
                                            <label>Text color <input type="color" class="t-pill-text-color" value="<?php echo esc_attr($t['pill_text_color'] ?? '#0a2d57'); ?>"></label>
                                            <label>Radius (px) <input type="number" min="0" max="32" class="t-pill-radius small-text" value="<?php echo esc_attr((int) ($t['pill_radius'] ?? 12)); ?>"></label>
                                            <label>Width (px) <input type="number" min="40" max="160" class="t-pill-width small-text" value="<?php echo esc_attr((int) ($t['pill_width'] ?? 72)); ?>"></label>
                                        </div>
                                    </fieldset>

                                    <fieldset style="grid-column:3/-1;border:1px solid #dcdcde;padding:10px 12px;border-radius:4px;background:#fff;">
                                        <legend style="padding:0 6px;font-weight:600;">Location badge (IN PERSON / ONLINE)</legend>
                                        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;">
                                            <label style="grid-column:1/-1;"><input type="checkbox" class="t-show-location-badge" <?php checked(!empty($t['show_location_badge'])); ?>> Show badge on each card (auto-derives IN PERSON vs ONLINE from event)</label>
                                            <label>In-person text <input type="text" class="t-badge-in-person-text" value="<?php echo esc_attr($t['badge_in_person_text'] ?? 'IN PERSON'); ?>" style="width:100%;"></label>
                                            <label>Online text <input type="text" class="t-badge-online-text" value="<?php echo esc_attr($t['badge_online_text'] ?? 'ONLINE'); ?>" style="width:100%;"></label>
                                            <label>Text/border color <input type="color" class="t-badge-color"    value="<?php echo esc_attr($t['badge_color']    ?? '#0a2d57'); ?>"></label>
                                            <label>Background <input type="color" class="t-badge-bg-color" value="<?php echo esc_attr($t['badge_bg_color'] ?? '#ffffff'); ?>"></label>
                                        </div>
                                    </fieldset>

                                    <fieldset style="grid-column:1/-1;border:1px solid #dcdcde;padding:10px 12px;border-radius:4px;background:#fff;">
                                        <legend style="padding:0 6px;font-weight:600;">Card sizing / typography</legend>
                                        <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;">
                                            <label>Border width (px) <input type="number" min="0" max="12" class="t-border-width small-text" value="<?php echo esc_attr((int) ($t['border_width'] ?? 1)); ?>"></label>
                                            <label>Border radius (px) <input type="number" min="0" max="32" class="t-border-radius small-text" value="<?php echo esc_attr((int) ($t['border_radius'] ?? 4)); ?>"></label>
                                            <label>Card padding (px) <input type="number" min="0" max="64" class="t-card-padding small-text" value="<?php echo esc_attr((int) ($t['card_padding'] ?? 12)); ?>"></label>
                                            <label>Card gap (px) <input type="number" min="0" max="64" class="t-card-gap small-text" value="<?php echo esc_attr((int) ($t['card_gap'] ?? 10)); ?>"></label>
                                            <label>Title size (px) <input type="number" min="10" max="36" class="t-title-size small-text" value="<?php echo esc_attr((int) ($t['title_size'] ?? 16)); ?>"></label>
                                            <label>Date size (px) <input type="number" min="9" max="28" class="t-date-size small-text" value="<?php echo esc_attr((int) ($t['date_size'] ?? 13)); ?>"></label>
                                            <label><input type="checkbox" class="t-show-time" <?php checked(!empty($t['show_time'])); ?>> Show time</label>
                                            <label><input type="checkbox" class="t-show-section-headers" <?php checked(!empty($t['show_section_headers'])); ?>> Show "This Week" / "Next Week" headers</label>
                                            <label><input type="checkbox" class="t-show-join-button" <?php checked(!empty($t['show_join_button'])); ?>> Show Join meeting button</label>
                                        </div>
                                    </fieldset>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top:14px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <input type="text" id="upnext-theme-new-slug"  placeholder="<?php esc_attr_e('slug (kebab-case, e.g. custom1)', 'azure-plugin'); ?>" class="regular-text" style="max-width:260px;">
                    <input type="text" id="upnext-theme-new-label" placeholder="<?php esc_attr_e('Display label', 'azure-plugin'); ?>" class="regular-text" style="max-width:240px;">
                    <button type="button" class="button" id="upnext-theme-add">+ Add theme</button>

                    <span style="flex:1;"></span>

                    <button type="button" class="button" id="upnext-theme-reset" title="<?php esc_attr_e('Discard user themes and restore the built-in defaults', 'azure-plugin'); ?>">Reset user themes</button>
                    <button type="button" class="button button-primary" id="upnext-theme-save">Save themes</button>
                </p>
            </div>

        </div>

        <!-- Preview Card. The Themes panel's per-row Preview
             buttons swap the contents of .azure-preview-container
             via AJAX (azure_upnext_themes_preview), and the
             #upnext-preview-banner shows which theme is currently
             rendered along with the shortcode snippet to copy and
             a Show default link to reset.  -->
        <div class="azure-card">
            <h2><?php _e('Live Preview', 'azure-plugin'); ?></h2>

            <div id="upnext-preview-banner"
                 style="display:none; align-items:center; gap:10px; flex-wrap:wrap; background:#f0f6fc; border:1px solid #c3d9ee; border-radius:4px; padding:8px 12px; margin-bottom:12px;">
                <span style="font-weight:600;">Previewing:</span>
                <code id="upnext-preview-shortcode" style="background:#fff; padding:3px 8px; border:1px solid #dcdcde; border-radius:3px;"></code>
                <button type="button" class="button button-small" id="upnext-preview-copy">Copy</button>
                <span style="flex:1;"></span>
                <button type="button" class="button button-small" id="upnext-preview-reset">Show default</button>
            </div>

            <div class="azure-preview-container" id="upnext-preview-container">
                <?php echo do_shortcode('[up-next columns="2"]'); ?>
            </div>
        </div>

    </div>
<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
</div>
<?php endif; ?>

<script>
jQuery(function ($) {
    var ajaxUrl = (window.azure_plugin_ajax && azure_plugin_ajax.ajax_url) ? azure_plugin_ajax.ajax_url : (window.ajaxurl || '/wp-admin/admin-ajax.php');
    var nonce   = (window.azure_plugin_ajax && azure_plugin_ajax.nonce)    ? azure_plugin_ajax.nonce    : '';

    function escHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    // Collect the current state of user-editable rows into the
    // payload shape Azure_UpNext_Themes::save_themes() expects.
    // Built-in rows are skipped server-side anyway but we filter
    // here too so the AJAX payload is lean.
    //
    // Reads from BOTH the main row (basic fields) AND the
    // sibling advanced row (extended v3.128 fields). Sibling is
    // matched by data-slug attribute, not adjacency, so the
    // collector is resilient to row reorder.
    function collectThemes() {
        var out = [];
        $('#upnext-themes-table tbody tr.upnext-theme-main-row').each(function () {
            var $main = $(this);
            if ($main.data('builtin') === 1 || $main.data('builtin') === '1') return;
            var slug = $main.data('slug');
            var $adv = $('#upnext-themes-table tbody tr.upnext-theme-advanced-row[data-slug="' + slug + '"]');

            // Basic fields (always present in main row)
            var t = {
                slug:               slug,
                label:              $main.find('.t-label').val(),
                layout:             $main.find('.t-layout').val(),
                columns:            parseInt($main.find('.t-columns').val(), 10) || 1,
                show_image:         $main.find('.t-show-image').is(':checked'),
                image_position:     $main.find('.t-image-position').val(),
                accent_color:       $main.find('.t-accent-color').val(),
                bg_color:           $main.find('.t-bg-color').val(),
                text_color:         $main.find('.t-text-color').val(),
                border_color:       $main.find('.t-border-color').val()
            };

            // Advanced fields (v3.128). When the advanced row
            // hasn't been built yet (newly-added theme that
            // hasn't been saved + reloaded), fall back to
            // safe defaults so the save still succeeds.
            function adv(sel, fallback) {
                if (!$adv.length) return fallback;
                var $el = $adv.find(sel);
                if (!$el.length) return fallback;
                if ($el.is(':checkbox')) return $el.is(':checked');
                if ($el.attr('type') === 'number') return parseInt($el.val(), 10) || 0;
                return $el.val();
            }

            $.extend(t, {
                // Outer container
                outer_bg_color:        adv('.t-outer-bg-color',        '#ffffff'),
                outer_border_color:    adv('.t-outer-border-color',    '#dcdcde'),
                outer_border_width:    adv('.t-outer-border-width',    0),
                outer_border_radius:   adv('.t-outer-border-radius',   0),
                outer_padding:         adv('.t-outer-padding',         0),
                outer_max_width:       adv('.t-outer-max-width',       0),
                // Header
                header_text:           adv('.t-header-text',           ''),
                header_color:          adv('.t-header-color',          '#1d2327'),
                header_size:           adv('.t-header-size',           28),
                header_align:          adv('.t-header-align',          'left'),
                header_font:           adv('.t-header-font',           'default'),
                header_underline:      adv('.t-header-underline',      false),
                // Footer
                footer_html:           adv('.t-footer-html',           ''),
                footer_color:          adv('.t-footer-color',          '#646970'),
                footer_size:           adv('.t-footer-size',           14),
                footer_align:          adv('.t-footer-align',          'center'),
                // Date pill
                date_pill:             adv('.t-date-pill',             'none'),
                pill_bg_color:         adv('.t-pill-bg-color',         '#f4a623'),
                pill_text_color:       adv('.t-pill-text-color',       '#0a2d57'),
                pill_radius:           adv('.t-pill-radius',           12),
                pill_width:            adv('.t-pill-width',            72),
                // Location badge
                show_location_badge:   adv('.t-show-location-badge',   false),
                badge_in_person_text:  adv('.t-badge-in-person-text',  'IN PERSON'),
                badge_online_text:     adv('.t-badge-online-text',     'ONLINE'),
                badge_color:           adv('.t-badge-color',           '#0a2d57'),
                badge_bg_color:        adv('.t-badge-bg-color',        '#ffffff'),
                // Card sizing / typography
                border_width:          adv('.t-border-width',          1),
                border_radius:         adv('.t-border-radius',         6),
                card_padding:          adv('.t-card-padding',          12),
                card_gap:              adv('.t-card-gap',              10),
                title_size:            adv('.t-title-size',            16),
                date_size:             adv('.t-date-size',             13),
                show_time:             adv('.t-show-time',             true),
                show_section_headers:  adv('.t-show-section-headers',  true),
                show_join_button:      adv('.t-show-join-button',      true),
                // Defaults for fields not yet surfaced in the UI
                show_location:         true,
                show_category:         false,
                accent_text_color:     '#ffffff',
                muted_color:           '#646970',
                section_header_bg:     '#f6f7f7',
                section_header_text:   '#1d2327',
                section_gap:           24,
                section_header_size:   18
            });

            out.push(t);
        });
        return out;
    }

    // Save the editor's current state.
    $('#upnext-theme-save').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('Saving\u2026');
        $.post(ajaxUrl, {
            action: 'azure_upnext_themes_save',
            nonce: nonce,
            themes: JSON.stringify(collectThemes())
        }).done(function (r) {
            if (r && r.success) {
                window.location.reload();
            } else {
                alert('Save failed: ' + (r && r.data ? r.data : 'unknown error'));
                $btn.prop('disabled', false).text('Save themes');
            }
        }).fail(function () {
            alert('Save failed (network)');
            $btn.prop('disabled', false).text('Save themes');
        });
    });

    // Reset to built-in defaults (drops user themes only).
    $('#upnext-theme-reset').on('click', function () {
        if (!window.confirm('Reset user themes? Built-in themes will remain. Your custom themes will be deleted.')) return;
        $.post(ajaxUrl, { action: 'azure_upnext_themes_reset', nonce: nonce }).done(function (r) {
            if (r && r.success) window.location.reload();
            else alert('Reset failed: ' + (r && r.data ? r.data : 'unknown'));
        });
    });

    // Add a new user-editable row to the table. Slug uniqueness is
    // enforced server-side at save; UI just blocks empty/duplicate
    // slugs visible on this page right now.
    $('#upnext-theme-add').on('click', function () {
        var slug  = ($('#upnext-theme-new-slug').val() || '').trim().toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '');
        var label = ($('#upnext-theme-new-label').val() || '').trim();
        if (!slug)  { alert('Slug is required (kebab-case, e.g. custom1).'); return; }
        if (!label) { label = slug.replace(/-/g, ' ').replace(/\b\w/g, function (m) { return m.toUpperCase(); }); }
        var exists = false;
        $('#upnext-themes-table tbody tr').each(function () {
            if ($(this).data('slug') === slug) exists = true;
        });
        if (exists) { alert('Slug already exists on this page.'); return; }

        var row = ''
            + '<tr data-slug="' + escHtml(slug) + '" data-builtin="0" class="upnext-theme-main-row">'
            + '<td><code>' + escHtml(slug) + '</code></td>'
            + '<td><input type="text" class="t-label regular-text" value="' + escHtml(label) + '"></td>'
            + '<td><select class="t-layout">'
            +   '<option value="rows" selected>Rows</option>'
            +   '<option value="grid">Grid</option>'
            +   '<option value="compact">Compact</option>'
            + '</select></td>'
            + '<td><input type="number" min="1" max="4" class="t-columns small-text" value="1"></td>'
            + '<td><label style="display:flex;align-items:center;gap:4px;font-size:12px;">'
            +   '<input type="checkbox" class="t-show-image">'
            +   '<select class="t-image-position" disabled style="min-width:60px;"><option value="left">left</option><option value="top">top</option></select>'
            + '</label></td>'
            + '<td>'
            +   '<input type="color" class="t-accent-color" value="#2271b1">'
            +   '<input type="color" class="t-bg-color" value="#ffffff" title="Card background">'
            +   '<input type="color" class="t-text-color" value="#1d2327" title="Body text">'
            +   '<input type="color" class="t-border-color" value="#dcdcde" title="Border">'
            + '</td>'
            + '<td>'
            +   '<button type="button" class="button button-small upnext-theme-preview" data-slug="' + escHtml(slug) + '">Preview</button>'
            +   '<button type="button" class="button button-small upnext-theme-copy"    data-slug="' + escHtml(slug) + '">Copy</button>'
            +   '<button type="button" class="button button-small upnext-theme-edit-advanced" data-slug="' + escHtml(slug) + '" disabled title="Save first to reveal advanced editor">Edit advanced</button>'
            +   '<button type="button" class="button button-small button-link-delete upnext-theme-delete" data-slug="' + escHtml(slug) + '">Delete</button>'
            + '</td>'
            + '</tr>';
        $('#upnext-themes-table tbody').append(row);
        $('#upnext-theme-new-slug, #upnext-theme-new-label').val('');
        alert('Theme row added. Click Save themes — after the page reloads you can Edit advanced to access outer container / header / footer / date pill / location badge fields.');
    });

    // Toggle image-position select based on show_image checkbox.
    $(document).on('change', '.t-show-image', function () {
        $(this).closest('td').find('.t-image-position').prop('disabled', !this.checked);
    });

    // Edit advanced — toggle the sibling advanced row matched by
    // data-slug attribute (siblings are matched by attribute, not
    // adjacency, so this works even after row reorder).
    $(document).on('click', '.upnext-theme-edit-advanced', function () {
        var slug = String($(this).data('slug') || '').trim();
        if (!slug) return;
        var $adv = $('#upnext-themes-table tbody tr.upnext-theme-advanced-row[data-slug="' + JSON.stringify(slug).slice(1, -1) + '"]');
        if (!$adv.length) {
            alert('No advanced editor row found for this theme. Save themes and reload to refresh the editor.');
            return;
        }
        $adv.toggle();
        var $btn = $(this);
        $btn.text($adv.is(':visible') ? 'Hide advanced' : 'Edit advanced');
    });

    // Clone a theme (built-in or user) into a new user theme,
    // copying every field. Server-side endpoint enforces unique
    // slug. Reloads on success so the new editor row appears.
    $(document).on('click', '.upnext-theme-clone', function () {
        var sourceSlug = String($(this).data('slug') || '').trim();
        if (!sourceSlug) return;
        var newSlug = window.prompt('New slug for the cloned theme (kebab-case, e.g. my-newsletter):', sourceSlug + '-copy');
        if (newSlug === null) return;
        newSlug = (newSlug || '').trim().toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '');
        if (!newSlug) { alert('Slug is required.'); return; }
        var newLabel = window.prompt('Display label for the cloned theme:', sourceSlug + ' (copy)') || '';

        $.post(ajaxUrl, {
            action:      'azure_upnext_themes_clone',
            nonce:       nonce,
            source_slug: sourceSlug,
            new_slug:    newSlug,
            new_label:   newLabel
        }).done(function (r) {
            if (r && r.success) {
                window.location.reload();
            } else {
                alert('Clone failed: ' + (r && r.data ? r.data : 'unknown'));
            }
        }).fail(function () {
            alert('Clone failed (network)');
        });
    });

    // Delete a user-defined theme. Built-in rows have no Delete
    // button so we don't need to defend against deleting them here.
    $(document).on('click', '.upnext-theme-delete', function () {
        var slug = $(this).data('slug');
        if (!window.confirm('Delete theme "' + slug + '"? Pages using [up-next theme="' + slug + '"] will fall back to the default style.')) return;
        $.post(ajaxUrl, { action: 'azure_upnext_themes_delete', nonce: nonce, slug: slug }).done(function (r) {
            if (r && r.success) window.location.reload();
            else alert('Delete failed: ' + (r && r.data ? r.data : 'unknown'));
        });
    });

    // Copy the [up-next] shortcode for this theme to clipboard.
    $(document).on('click', '.upnext-theme-copy', function () {
        var slug = $(this).data('slug');
        var snippet = '[up-next theme="' + slug + '"]';
        try {
            navigator.clipboard.writeText(snippet);
            var $btn = $(this);
            var orig = $btn.text();
            $btn.text('Copied!');
            setTimeout(function () { $btn.text(orig); }, 1500);
        } catch (e) {
            window.prompt('Copy this shortcode:', snippet);
        }
    });

    // Preview re-renders the Live Preview section below with the
    // chosen theme via AJAX. Always uses the saved theme state
    // (the server renders [up-next theme="<slug>" cache="false"]),
    // so admins should Save themes before clicking Preview to see
    // their latest tweaks. The current default-theme HTML is held
    // in #upnext-default-html so Show default can restore it
    // without a round-trip.
    var $previewContainer = $('#upnext-preview-container');
    var $previewBanner    = $('#upnext-preview-banner');
    var $previewShortcode = $('#upnext-preview-shortcode');
    // Snapshot the server-rendered default HTML so Show default
    // can restore instantly without another AJAX call.
    var defaultPreviewHtml = $previewContainer.html();

    function setPreviewRendering() {
        $previewContainer.html('<p style="padding:14px;color:#646970;">Rendering preview\u2026</p>');
    }

    function showPreviewBanner(slug, shortcode) {
        $previewShortcode.text(shortcode);
        $previewBanner.css('display', 'flex').data('active-slug', slug);
    }

    function hidePreviewBanner() {
        $previewBanner.hide().removeData('active-slug');
    }

    $(document).on('click', '.upnext-theme-preview', function () {
        var slug = String($(this).data('slug') || '').trim();
        if (!slug) return;
        setPreviewRendering();
        showPreviewBanner(slug, '[up-next theme="' + slug + '"]');

        // v3.128 — Always POST the current form state as the
        // `themes` payload so the server saves the unsaved
        // tweaks BEFORE rendering. Without this, the preview
        // shows the last saved state and tweaks don't appear
        // until the admin clicks Save themes first.
        $.post(ajaxUrl, {
            action:  'azure_upnext_themes_preview',
            nonce:   nonce,
            slug:    slug,
            columns: 2,
            themes:  JSON.stringify(collectThemes())
        }).done(function (r) {
            if (r && r.success && r.data && typeof r.data.html === 'string') {
                $previewContainer.html(r.data.html);
                $previewShortcode.text(r.data.shortcode || ('[up-next theme="' + slug + '"]'));
                // Scroll the preview into view so admins immediately
                // see the result rather than wondering whether
                // anything happened.
                var top = $previewBanner.offset() ? $previewBanner.offset().top - 60 : null;
                if (top !== null) $('html, body').animate({ scrollTop: top }, 250);
            } else {
                $previewContainer.html(
                    '<p style="padding:14px;color:#b32d2e;">'
                    + 'Preview failed: '
                    + escHtml(r && r.data ? (typeof r.data === 'string' ? r.data : JSON.stringify(r.data)) : 'unknown error')
                    + '</p>'
                );
            }
        }).fail(function (xhr) {
            $previewContainer.html(
                '<p style="padding:14px;color:#b32d2e;">'
                + 'Preview request failed ('
                + (xhr && xhr.status ? xhr.status : 'network')
                + '). Check the browser console.'
                + '</p>'
            );
        });
    });

    // Show default reverts the preview container to the default-
    // theme HTML that the page was server-rendered with.
    $('#upnext-preview-reset').on('click', function () {
        $previewContainer.html(defaultPreviewHtml);
        hidePreviewBanner();
    });

    // Copy the previewed theme's shortcode to clipboard from the
    // banner — saves a trip back to the row's Copy shortcode
    // button when the admin is already focused on the preview.
    $('#upnext-preview-copy').on('click', function () {
        var snippet = $previewShortcode.text();
        if (!snippet) return;
        var $btn = $(this);
        var orig = $btn.text();
        try {
            navigator.clipboard.writeText(snippet);
            $btn.text('Copied!');
            setTimeout(function () { $btn.text(orig); }, 1500);
        } catch (e) {
            window.prompt('Copy this shortcode:', snippet);
        }
    });
});
</script>

<style>
.azure-admin-wrap .azure-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
}

.azure-admin-wrap .azure-card h2 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.azure-admin-wrap .azure-card h3 {
    margin-top: 1.5em;
    margin-bottom: 0.5em;
    color: #23282d;
}

.azure-admin-wrap .azure-code {
    background: #f1f1f1;
    padding: 12px 15px;
    border-radius: 4px;
    font-family: 'Monaco', 'Consolas', monospace;
    font-size: 13px;
    overflow-x: auto;
    white-space: pre-wrap;
    word-wrap: break-word;
}

.azure-admin-wrap .azure-attributes-table {
    margin-top: 10px;
}

.azure-admin-wrap .azure-attributes-table td,
.azure-admin-wrap .azure-attributes-table th {
    padding: 10px 12px;
    vertical-align: top;
}

.azure-admin-wrap .azure-attributes-table code {
    background: #f1f1f1;
    padding: 2px 6px;
    border-radius: 3px;
}

.azure-admin-wrap .azure-category-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}

.azure-admin-wrap .azure-category-tag {
    background: #0073aa;
    color: #fff;
    padding: 4px 10px;
    border-radius: 3px;
    font-size: 13px;
}

.azure-admin-wrap .azure-preview-container {
    background: #f9f9f9;
    padding: 20px;
    border: 1px dashed #ccc;
    border-radius: 4px;
    margin-top: 10px;
}

.azure-admin-wrap .notice.inline {
    margin: 15px 0;
}
</style>

