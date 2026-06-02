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
                            <th style="width:260px;"><?php _e('Actions', 'azure-plugin'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($upnext_themes as $t): $builtin = !empty($t['is_builtin']); ?>
                        <tr data-slug="<?php echo esc_attr($t['slug']); ?>" data-builtin="<?php echo $builtin ? '1' : '0'; ?>">
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
                                <button type="button" class="button button-small upnext-theme-copy"    data-slug="<?php echo esc_attr($t['slug']); ?>">Copy shortcode</button>
                                <?php if (!$builtin): ?>
                                    <button type="button" class="button button-small button-link-delete upnext-theme-delete" data-slug="<?php echo esc_attr($t['slug']); ?>">Delete</button>
                                <?php endif; ?>
                            </td>
                        </tr>
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
    function collectThemes() {
        var out = [];
        $('#upnext-themes-table tbody tr').each(function () {
            var $tr = $(this);
            if ($tr.data('builtin') === 1 || $tr.data('builtin') === '1') return;
            out.push({
                slug:               $tr.data('slug'),
                label:              $tr.find('.t-label').val(),
                layout:             $tr.find('.t-layout').val(),
                columns:            parseInt($tr.find('.t-columns').val(), 10) || 1,
                show_image:         $tr.find('.t-show-image').is(':checked'),
                image_position:     $tr.find('.t-image-position').val(),
                accent_color:       $tr.find('.t-accent-color').val(),
                bg_color:           $tr.find('.t-bg-color').val(),
                text_color:         $tr.find('.t-text-color').val(),
                border_color:       $tr.find('.t-border-color').val(),
                show_join_button:   true,   // default-on for now (admin can later edit)
                show_section_headers: true,
                show_time:          true,
                show_location:      true,
                show_category:      false,
                accent_text_color:  '#ffffff',
                muted_color:        '#646970',
                section_header_bg:  '#f6f7f7',
                section_header_text:'#1d2327',
                border_width:       1,
                border_radius:      6,
                card_padding:       12,
                card_gap:           10,
                section_gap:        24,
                title_size:         16,
                date_size:          13,
                section_header_size:18,
            });
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
            + '<tr data-slug="' + escHtml(slug) + '" data-builtin="0">'
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
            +   '<button type="button" class="button button-small upnext-theme-copy" data-slug="' + escHtml(slug) + '">Copy shortcode</button>'
            +   '<button type="button" class="button button-small button-link-delete upnext-theme-delete" data-slug="' + escHtml(slug) + '">Delete</button>'
            + '</td>'
            + '</tr>';
        $('#upnext-themes-table tbody').append(row);
        $('#upnext-theme-new-slug, #upnext-theme-new-label').val('');
        alert('Theme row added. Click Save themes to persist before previewing.');
    });

    // Toggle image-position select based on show_image checkbox.
    $(document).on('change', '.t-show-image', function () {
        $(this).closest('td').find('.t-image-position').prop('disabled', !this.checked);
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

        $.post(ajaxUrl, {
            action:  'azure_upnext_themes_preview',
            nonce:   nonce,
            slug:    slug,
            columns: 2
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

