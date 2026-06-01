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
        
        <!-- Preview Card -->
        <div class="azure-card">
            <h2><?php _e('Live Preview', 'azure-plugin'); ?></h2>
            <div class="azure-preview-container">
                <?php echo do_shortcode('[up-next columns="2"]'); ?>
            </div>
        </div>
        
    </div>
<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
</div>
<?php endif; ?>

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

