<?php
/**
 * Tickets Module - Venues Tab
 *
 * Manages pta_venue posts with reserved-seating layouts. Legacy tribe_venue
 * posts are accepted for back-compat; the v3.97 migration MU-plugin renames
 * them to pta_venue in place so URLs and IDs are preserved.
 */

if (!defined('ABSPATH')) {
    exit;
}

$action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
$venue_id = isset($_GET['venue_id']) ? intval($_GET['venue_id']) : 0;

$venue = null;
$venue_layout = null;

if ($venue_id > 0) {
    $venue = get_post($venue_id);
    if ($venue && in_array($venue->post_type, array('pta_venue', 'tribe_venue'), true)) {
        $venue_layout = get_post_meta($venue_id, '_azure_seating_layout', true);
    }
}

// All venues (pta_venue + legacy tribe_venue).
$pta_venues = get_posts(array(
    'post_type'      => array('pta_venue', 'tribe_venue'),
    'posts_per_page' => -1,
    'orderby'        => 'title',
    'order'          => 'ASC',
    'post_status'    => 'publish',
));
?>

<?php if ($action === 'new' || $action === 'edit'): ?>
<!-- Venue Designer -->
<div class="venue-designer-wrap">
    <div class="venue-designer-header">
        <h2>
            <?php if ($venue): ?>
                <?php printf(__('Edit Seating Layout: %s', 'azure-plugin'), esc_html($venue->post_title)); ?>
            <?php else: ?>
                <?php _e('Create New Venue with Seating Layout', 'azure-plugin'); ?>
            <?php endif; ?>
        </h2>
        <div class="header-actions">
            <button type="button" class="button button-primary" id="save-venue">
                <span class="dashicons dashicons-saved"></span>
                <?php _e('Save Venue', 'azure-plugin'); ?>
            </button>
            <a href="?page=azure-plugin-tickets&tab=venues" class="button">
                <?php _e('Cancel', 'azure-plugin'); ?>
            </a>
        </div>
    </div>
    
    <div class="venue-info-bar">
        <?php if (!$venue): ?>
        <div class="venue-name-input">
            <label for="venue-name"><?php _e('Venue Name', 'azure-plugin'); ?> <span class="required">*</span></label>
            <input type="text" id="venue-name" value="" placeholder="<?php _e('e.g., Main Auditorium', 'azure-plugin'); ?>" required>
        </div>
        <div class="venue-address-input">
            <label for="venue-address"><?php _e('Address', 'azure-plugin'); ?></label>
            <input type="text" id="venue-address" value="" placeholder="<?php _e('Street address', 'azure-plugin'); ?>">
        </div>
        <div class="venue-city-input">
            <label for="venue-city"><?php _e('City', 'azure-plugin'); ?></label>
            <input type="text" id="venue-city" value="" placeholder="<?php _e('City', 'azure-plugin'); ?>">
        </div>
        <?php else: ?>
        <div class="venue-info-display">
            <strong><?php echo esc_html($venue->post_title); ?></strong>
            <?php
            $address = get_post_meta($venue_id, '_VenueAddress', true);
            $city    = get_post_meta($venue_id, '_VenueCity', true);
            if ($address || $city): ?>
            <span class="venue-location">
                <?php echo esc_html(implode(', ', array_filter(array($address, $city)))); ?>
            </span>
            <?php endif; ?>
            <a href="<?php echo get_edit_post_link($venue_id); ?>" target="_blank" class="edit-venue-link">
                <?php _e('Edit venue details', 'azure-plugin'); ?> →
            </a>
        </div>
        <?php endif; ?>
        <div class="venue-capacity">
            <label><?php _e('Total Capacity', 'azure-plugin'); ?></label>
            <span id="total-capacity">0</span> <?php _e('seats', 'azure-plugin'); ?>
        </div>
    </div>
    
    <div class="venue-designer">
        <!-- Left Panel - Blocks -->
        <div class="designer-sidebar designer-blocks">
            <h3><?php _e('Blocks', 'azure-plugin'); ?></h3>
            <div class="block-palette">
                <div class="block-item" data-type="rectangle" draggable="true">
                    <span class="dashicons dashicons-grid-view"></span>
                    <span><?php _e('Rectangle Section', 'azure-plugin'); ?></span>
                </div>
                <div class="block-item" data-type="general_admission" draggable="true">
                    <span class="dashicons dashicons-groups"></span>
                    <span><?php _e('General Admission', 'azure-plugin'); ?></span>
                </div>
                <div class="block-item" data-type="stage" draggable="true">
                    <span class="dashicons dashicons-format-video"></span>
                    <span><?php _e('Stage', 'azure-plugin'); ?></span>
                </div>
                <div class="block-item" data-type="label" draggable="true">
                    <span class="dashicons dashicons-editor-textcolor"></span>
                    <span><?php _e('Label', 'azure-plugin'); ?></span>
                </div>
                <div class="block-item" data-type="aisle" draggable="true">
                    <span class="dashicons dashicons-minus"></span>
                    <span><?php _e('Aisle', 'azure-plugin'); ?></span>
                </div>
            </div>
            
            <h3><?php _e('Canvas', 'azure-plugin'); ?></h3>
            <div class="canvas-controls">
                <label>
                    <?php _e('Width', 'azure-plugin'); ?>
                    <input type="number" id="canvas-width" value="800" min="400" max="2000" step="50">
                </label>
                <label>
                    <?php _e('Height', 'azure-plugin'); ?>
                    <input type="number" id="canvas-height" value="600" min="300" max="1500" step="50">
                </label>
            </div>
            <div class="zoom-controls">
                <button type="button" class="button" id="zoom-out"><span class="dashicons dashicons-minus"></span></button>
                <span id="zoom-level">100%</span>
                <button type="button" class="button" id="zoom-in"><span class="dashicons dashicons-plus"></span></button>
                <button type="button" class="button" id="zoom-reset"><?php _e('Reset', 'azure-plugin'); ?></button>
            </div>
        </div>
        
        <!-- Canvas -->
        <div class="designer-canvas-container">
            <div class="designer-canvas" id="venue-canvas" 
                 data-venue-id="<?php echo esc_attr($venue_id); ?>"
                 data-layout="<?php echo esc_attr($venue_layout ?: '{"canvas":{"width":800,"height":600},"blocks":[]}'); ?>">
                <!-- Blocks will be rendered here -->
            </div>
        </div>
        
        <!-- Right Panel - Settings -->
        <div class="designer-sidebar designer-settings">
            <h3><?php _e('Block Settings', 'azure-plugin'); ?></h3>
            <div id="block-settings-panel">
                <p class="no-selection"><?php _e('Select a block to edit its settings', 'azure-plugin'); ?></p>
            </div>
            
            <!-- Settings templates (hidden) -->
            <template id="rectangle-settings">
                <div class="settings-group">
                    <label><?php _e('Section Name', 'azure-plugin'); ?></label>
                    <input type="text" class="block-setting" data-setting="name" placeholder="<?php _e('e.g., Orchestra Left', 'azure-plugin'); ?>">
                </div>
                <div class="settings-group">
                    <label><?php _e('Price ($)', 'azure-plugin'); ?></label>
                    <input type="number" class="block-setting" data-setting="price" min="0" step="0.01" value="0">
                </div>
                <div class="settings-group">
                    <label><?php _e('Number of Rows', 'azure-plugin'); ?></label>
                    <input type="number" class="block-setting" data-setting="rowCount" min="1" max="26" value="2">
                </div>
                <div class="settings-group">
                    <label><?php _e('Seats per Row', 'azure-plugin'); ?></label>
                    <input type="number" class="block-setting" data-setting="seatsPerRow" min="1" max="100" value="10">
                </div>
                <div class="settings-group">
                    <label><?php _e('Starting Row Letter', 'azure-plugin'); ?></label>
                    <select class="block-setting" data-setting="startRow">
                        <?php foreach (range('A', 'Z') as $letter): ?>
                        <option value="<?php echo $letter; ?>"><?php echo $letter; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="settings-group">
                    <label><?php _e('Section Color', 'azure-plugin'); ?></label>
                    <input type="color" class="block-setting" data-setting="color" value="#4CAF50">
                </div>
                <div class="settings-group">
                    <button type="button" class="button delete-block" style="color: #d63638;">
                        <span class="dashicons dashicons-trash"></span>
                        <?php _e('Delete Block', 'azure-plugin'); ?>
                    </button>
                </div>
            </template>
            
            <template id="general_admission-settings">
                <div class="settings-group">
                    <label><?php _e('Area Name', 'azure-plugin'); ?></label>
                    <input type="text" class="block-setting" data-setting="name" placeholder="<?php _e('e.g., Standing Area', 'azure-plugin'); ?>">
                </div>
                <div class="settings-group">
                    <label><?php _e('Price ($)', 'azure-plugin'); ?></label>
                    <input type="number" class="block-setting" data-setting="price" min="0" step="0.01" value="0">
                </div>
                <div class="settings-group">
                    <label><?php _e('Capacity', 'azure-plugin'); ?></label>
                    <input type="number" class="block-setting" data-setting="capacity" min="1" max="10000" value="100">
                </div>
                <div class="settings-group">
                    <label><?php _e('Area Color', 'azure-plugin'); ?></label>
                    <input type="color" class="block-setting" data-setting="color" value="#FF9800">
                </div>
                <div class="settings-group">
                    <button type="button" class="button delete-block" style="color: #d63638;">
                        <span class="dashicons dashicons-trash"></span>
                        <?php _e('Delete Block', 'azure-plugin'); ?>
                    </button>
                </div>
            </template>
            
            <template id="stage-settings">
                <div class="settings-group">
                    <label><?php _e('Label', 'azure-plugin'); ?></label>
                    <input type="text" class="block-setting" data-setting="label" value="STAGE">
                </div>
                <div class="settings-group">
                    <label><?php _e('Background Color', 'azure-plugin'); ?></label>
                    <input type="color" class="block-setting" data-setting="color" value="#333333">
                </div>
                <div class="settings-group">
                    <button type="button" class="button delete-block" style="color: #d63638;">
                        <span class="dashicons dashicons-trash"></span>
                        <?php _e('Delete Block', 'azure-plugin'); ?>
                    </button>
                </div>
            </template>
            
            <template id="label-settings">
                <div class="settings-group">
                    <label><?php _e('Text', 'azure-plugin'); ?></label>
                    <input type="text" class="block-setting" data-setting="text" placeholder="<?php _e('Enter text', 'azure-plugin'); ?>">
                </div>
                <div class="settings-group">
                    <label><?php _e('Font Size', 'azure-plugin'); ?></label>
                    <input type="number" class="block-setting" data-setting="fontSize" min="10" max="48" value="14">
                </div>
                <div class="settings-group">
                    <label><?php _e('Text Color', 'azure-plugin'); ?></label>
                    <input type="color" class="block-setting" data-setting="color" value="#333333">
                </div>
                <div class="settings-group">
                    <button type="button" class="button delete-block" style="color: #d63638;">
                        <span class="dashicons dashicons-trash"></span>
                        <?php _e('Delete Block', 'azure-plugin'); ?>
                    </button>
                </div>
            </template>
            
            <template id="aisle-settings">
                <div class="settings-group">
                    <label><?php _e('Orientation', 'azure-plugin'); ?></label>
                    <select class="block-setting" data-setting="orientation">
                        <option value="horizontal"><?php _e('Horizontal', 'azure-plugin'); ?></option>
                        <option value="vertical"><?php _e('Vertical', 'azure-plugin'); ?></option>
                    </select>
                </div>
                <div class="settings-group">
                    <button type="button" class="button delete-block" style="color: #d63638;">
                        <span class="dashicons dashicons-trash"></span>
                        <?php _e('Delete Block', 'azure-plugin'); ?>
                    </button>
                </div>
            </template>
        </div>
    </div>
</div>

<?php else: ?>
<!-- Venues List -->
<div class="venues-list-wrap">
    <div class="venues-header">
        <h2><?php _e('Venue Seating Layouts', 'azure-plugin'); ?></h2>
        <p class="description"><?php _e('Add seating layouts to your venues to enable reserved seating for ticketed events.', 'azure-plugin'); ?></p>
    </div>

    <div class="venues-actions-bar">
        <a href="?page=azure-plugin-tickets&tab=venues&action=new" class="button button-primary">
            <span class="dashicons dashicons-plus-alt"></span>
            <?php _e('Create New Venue', 'azure-plugin'); ?>
        </a>
        <a href="<?php echo admin_url('edit.php?post_type=pta_venue'); ?>" class="button" target="_blank">
            <span class="dashicons dashicons-external"></span>
            <?php _e('Manage All Venues', 'azure-plugin'); ?>
        </a>
    </div>

    <?php if (empty($pta_venues)): ?>
    <div class="no-venues">
        <span class="dashicons dashicons-admin-home" style="font-size: 48px; width: 48px; height: 48px; color: #ccc;"></span>
        <h3><?php _e('No venues found', 'azure-plugin'); ?></h3>
        <p><?php _e('Create your first venue with a seating layout to start selling reserved tickets.', 'azure-plugin'); ?></p>
        <div class="no-venues-actions">
            <a href="?page=azure-plugin-tickets&tab=venues&action=new" class="button button-primary button-hero">
                <?php _e('Create New Venue', 'azure-plugin'); ?>
            </a>
        </div>
    </div>
    <?php else: ?>
    <div class="venues-grid">
        <?php foreach ($pta_venues as $v):
            $layout_json = get_post_meta($v->ID, '_azure_seating_layout', true);
            $layout = $layout_json ? json_decode($layout_json) : null;
            $block_count = ($layout && isset($layout->blocks)) ? count($layout->blocks) : 0;
            $has_layout = !empty($layout_json);

            $capacity = 0;
            if ($layout && isset($layout->blocks)) {
                foreach ($layout->blocks as $block) {
                    if ($block->type === 'rectangle') {
                        $capacity += ($block->rowCount ?? 0) * ($block->seatsPerRow ?? 0);
                    } elseif ($block->type === 'general_admission') {
                        $capacity += $block->capacity ?? 0;
                    }
                }
            }

            $address = get_post_meta($v->ID, '_VenueAddress', true);
            $city    = get_post_meta($v->ID, '_VenueCity', true);
        ?>
        <div class="venue-card <?php echo $has_layout ? 'has-layout' : 'no-layout'; ?>" data-venue-id="<?php echo $v->ID; ?>">
            <div class="venue-preview">
                <?php if ($has_layout): ?>
                <!-- Mini preview of layout -->
                <div class="mini-layout" data-layout="<?php echo esc_attr($layout_json); ?>"></div>
                <?php else: ?>
                <div class="no-layout-preview">
                    <span class="dashicons dashicons-layout"></span>
                    <span><?php _e('No seating layout', 'azure-plugin'); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <div class="venue-info">
                <h3><?php echo esc_html($v->post_title); ?></h3>
                <?php if ($address || $city): ?>
                <p class="venue-location">
                    <span class="dashicons dashicons-location"></span>
                    <?php echo esc_html(implode(', ', array_filter(array($address, $city)))); ?>
                </p>
                <?php endif; ?>
                <?php if ($has_layout): ?>
                <p class="venue-meta">
                    <span><strong><?php echo number_format($capacity); ?></strong> <?php _e('seats', 'azure-plugin'); ?></span>
                    <span><strong><?php echo $block_count; ?></strong> <?php _e('sections', 'azure-plugin'); ?></span>
                </p>
                <?php endif; ?>
            </div>
            <div class="venue-actions">
                <?php if ($has_layout): ?>
                <a href="?page=azure-plugin-tickets&tab=venues&action=edit&venue_id=<?php echo $v->ID; ?>" class="button button-primary">
                    <span class="dashicons dashicons-edit"></span>
                    <?php _e('Edit Layout', 'azure-plugin'); ?>
                </a>
                <?php else: ?>
                <a href="?page=azure-plugin-tickets&tab=venues&action=edit&venue_id=<?php echo $v->ID; ?>" class="button button-primary">
                    <span class="dashicons dashicons-plus-alt"></span>
                    <?php _e('Add Layout', 'azure-plugin'); ?>
                </a>
                <?php endif; ?>
                <a href="<?php echo get_edit_post_link($v->ID); ?>" class="button" target="_blank">
                    <?php _e('Edit Venue', 'azure-plugin'); ?>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<style>
/* Critical layout styles (ensure they load) */
.venue-designer-wrap {
    background: #f0f0f1;
}

.venue-designer-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    background: #1d2327;
    color: #fff;
}

.venue-designer-header h2 {
    margin: 0;
    font-size: 18px;
    color: #fff;
}

.header-actions {
    display: flex;
    gap: 10px;
}

.venue-info-bar {
    display: flex;
    gap: 20px;
    padding: 15px 20px;
    background: #fff;
    border-bottom: 1px solid #dcdcde;
    flex-wrap: wrap;
    align-items: flex-end;
}

.venue-name-input {
    flex: 2;
    min-width: 200px;
}

.venue-address-input,
.venue-city-input {
    flex: 1;
    min-width: 150px;
}

.venue-info-bar label {
    display: block;
    font-size: 11px;
    text-transform: uppercase;
    color: #666;
    font-weight: 600;
    margin-bottom: 5px;
}

.venue-info-bar input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #dcdcde;
    border-radius: 4px;
}

.venue-capacity {
    text-align: right;
    min-width: 100px;
}

.venue-capacity span#total-capacity {
    font-size: 28px;
    font-weight: 700;
    color: #2271b1;
}

/* Main designer grid - CRITICAL */
.venue-designer {
    display: grid !important;
    grid-template-columns: 220px 1fr 280px !important;
    min-height: 550px;
    background: #f0f0f1;
}

.designer-sidebar {
    background: #fff;
    padding: 20px;
    overflow-y: auto;
}

.designer-sidebar h3 {
    margin: 0 0 15px;
    font-size: 13px;
    text-transform: uppercase;
    color: #646970;
}

.designer-blocks {
    border-right: 1px solid #dcdcde;
}

.designer-settings {
    border-left: 1px solid #dcdcde;
}

.block-palette {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 20px;
}

.block-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    background: #f6f7f7;
    border: 1px solid #dcdcde;
    border-radius: 4px;
    cursor: grab;
    transition: all 0.2s;
}

.block-item:hover {
    border-color: #2271b1;
    background: #f0f6fc;
}

.block-item .dashicons {
    color: #2271b1;
}

.canvas-controls {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 15px;
}

.canvas-controls label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 12px;
}

.canvas-controls input {
    padding: 6px 8px;
    border: 1px solid #dcdcde;
    border-radius: 4px;
}

.zoom-controls {
    display: flex;
    align-items: center;
    gap: 8px;
}

.zoom-controls .button {
    min-width: 36px;
    height: 36px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.zoom-controls .button .dashicons {
    width: 20px;
    height: 20px;
    font-size: 20px;
}

#zoom-level {
    min-width: 50px;
    text-align: center;
}

/* Canvas container - CRITICAL */
.designer-canvas-container {
    background: #e5e5e5;
    overflow: auto;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 30px;
    position: relative;
}

.designer-canvas {
    background: #fff;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    position: relative;
    min-width: 800px;
    min-height: 600px;
}

.designer-canvas.drag-over {
    outline: 3px dashed #2271b1;
    outline-offset: -3px;
    background: #f0f6fc;
}


/* Block settings */
.settings-group {
    margin-bottom: 15px;
}

.settings-group label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 5px;
}

.settings-group input,
.settings-group select {
    width: 100%;
    padding: 8px;
    border: 1px solid #dcdcde;
    border-radius: 4px;
}

.settings-group input[type="color"] {
    height: 36px;
    padding: 2px;
}

.no-selection {
    color: #646970;
    font-style: italic;
    text-align: center;
    padding: 20px;
}

/* Venue blocks on canvas */
.venue-block {
    position: absolute;
    border-radius: 4px;
    cursor: move;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 12px;
    text-align: center;
    border: 2px solid transparent;
    text-shadow: 0 1px 2px rgba(0,0,0,0.3);
}

.venue-block.selected {
    border-color: #2271b1;
    box-shadow: 0 0 0 3px rgba(34, 113, 177, 0.3);
}

.venue-block .block-label {
    padding: 5px;
}

.venue-block .block-seats {
    font-size: 10px;
    opacity: 0.9;
}

.venue-block.type-label {
    background: transparent !important;
    border: 1px dashed #ccc;
    color: #333;
    text-shadow: none;
}

.venue-block.type-aisle {
    background: repeating-linear-gradient(45deg, #f5f5f5, #f5f5f5 5px, #e0e0e0 5px, #e0e0e0 10px);
}

.resize-handle {
    position: absolute;
    width: 12px;
    height: 12px;
    background: #fff;
    border: 2px solid #2271b1;
    border-radius: 2px;
    opacity: 0;
}

.venue-block:hover .resize-handle,
.venue-block.selected .resize-handle {
    opacity: 1;
}

.resize-handle.se {
    bottom: -6px;
    right: -6px;
    cursor: se-resize;
}

.required {
    color: #d63638;
}

/* Venues list styles */
.venues-list-wrap {
    margin-top: 20px;
}

.venues-header .description {
    margin: 0;
    color: #646970;
}

.venues-actions-bar {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}

.no-venues {
    text-align: center;
    padding: 60px 20px;
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 4px;
}

.no-venues h3 {
    margin: 15px 0 10px;
}

.no-venues p {
    color: #646970;
    margin-bottom: 20px;
}

.no-venues-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
}

.venue-card.no-layout {
    border-style: dashed;
}

.no-layout-preview {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    color: #a7aaad;
}

.no-layout-preview .dashicons {
    font-size: 32px;
    width: 32px;
    height: 32px;
}

.venue-location .dashicons {
    font-size: 14px;
    width: 14px;
    height: 14px;
}

.venue-info-display .venue-location {
    display: block;
    color: #646970;
    margin-top: 3px;
}

.edit-venue-link {
    display: inline-block;
    margin-top: 5px;
    font-size: 12px;
}

/* Responsive */
@media screen and (max-width: 1100px) {
    .venue-designer {
        grid-template-columns: 180px 1fr 240px !important;
    }
}

@media screen and (max-width: 900px) {
    .venue-designer {
        grid-template-columns: 1fr !important;
    }
    
    .designer-sidebar {
        border-right: none !important;
        border-left: none !important;
        border-bottom: 1px solid #dcdcde;
    }
    
    .designer-canvas-container {
        min-height: 400px;
    }
}
</style>

<script>
// Render mini-layout previews in the venue list
jQuery(document).ready(function($) {
    $('.mini-layout').each(function() {
        var $container = $(this);
        var layoutJson = $container.data('layout');
        
        if (!layoutJson) return;
        
        var layout;
        try {
            layout = typeof layoutJson === 'string' ? JSON.parse(layoutJson) : layoutJson;
        } catch (e) {
            return;
        }
        
        if (!layout.blocks || !layout.blocks.length) return;
        
        // Set container size based on canvas
        var canvasWidth = layout.canvas ? layout.canvas.width : 800;
        var canvasHeight = layout.canvas ? layout.canvas.height : 600;
        
        // Calculate scale to fit in preview area
        var containerWidth = $container.parent().width();
        var containerHeight = $container.parent().height();
        var scale = Math.min(containerWidth / canvasWidth, containerHeight / canvasHeight) * 0.9;
        
        $container.css({
            width: canvasWidth + 'px',
            height: canvasHeight + 'px',
            transform: 'scale(' + scale + ')',
            transformOrigin: 'center center'
        });
        
        // Render blocks
        layout.blocks.forEach(function(block) {
            var $block = $('<div>')
                .css({
                    position: 'absolute',
                    left: block.x + 'px',
                    top: block.y + 'px',
                    width: block.width + 'px',
                    height: block.height + 'px',
                    backgroundColor: block.color || '#666',
                    borderRadius: '4px',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    color: '#fff',
                    fontSize: '10px',
                    textAlign: 'center',
                    overflow: 'hidden'
                });
            
            if (block.type === 'label') {
                $block.css({
                    backgroundColor: 'transparent',
                    color: block.color || '#333',
                    border: '1px dashed #ccc'
                });
            } else if (block.type === 'aisle') {
                $block.css({
                    backgroundColor: '#e0e0e0'
                });
            }
            
            var label = block.name || block.label || block.text || '';
            if (label) {
                $block.text(label);
            }
            
            $container.append($block);
        });
    });
});
</script>
