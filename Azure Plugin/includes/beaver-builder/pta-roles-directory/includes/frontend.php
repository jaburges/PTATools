<?php
/**
 * Frontend template for PTA Roles Directory module
 */

// Get the shortcode handler
$pta_shortcode = new Azure_PTA_Shortcode();

// Build shortcode attributes from module settings
$shortcode_atts = array(
    'department' => !empty($settings->department) ? $settings->department : '',
    'description' => ($settings->show_description === 'yes'),
    'status' => !empty($settings->status) ? $settings->status : 'all',
    'columns' => !empty($settings->columns) ? $settings->columns : 2,
    'show_count' => ($settings->show_count === 'yes'),
    'show_vp' => ($settings->show_vp === 'yes'),
    'layout' => !empty($settings->layout) ? $settings->layout : 'grid'
);

// Add module-specific CSS class
$module_class = 'fl-pta-roles-directory fl-module-' . $id;

?>
<div class="<?php echo esc_attr($module_class); ?>">
    <?php echo $pta_shortcode->roles_directory_shortcode($shortcode_atts); ?>
</div>

















