<?php
/**
 * PTA Roles Directory Beaver Builder Module
 */

if (!defined('ABSPATH')) {
    exit;
}

// Declare the module class HERE (not in class-pta-beaver-builder.php) so
// FLBuilderModule::__construct's ReflectionClass-derived slug resolves to
// 'pta-roles-directory' instead of 'class-pta-beaver-builder'. Co-locating
// stub classes for multiple modules in the same shared file caused all of
// them to share one slug, which silently dropped subsequent modules at
// FLBuilder::register_module() time.
if (!class_exists('PTARolesDirectoryModule') && class_exists('FLBuilderModule')) {
    class PTARolesDirectoryModule extends FLBuilderModule {
        public function __construct() {
            parent::__construct(array(
                'name'            => __('PTA Roles Directory', 'azure-plugin'),
                'description'     => __('Display a directory of PTA roles with filtering options', 'azure-plugin'),
                'group'           => __('Azure Plugin', 'azure-plugin'),
                'category'        => __('PTA Modules', 'azure-plugin'),
                'dir'             => AZURE_PLUGIN_PATH . 'includes/beaver-builder/pta-roles-directory/',
                'url'             => AZURE_PLUGIN_URL . 'includes/beaver-builder/pta-roles-directory/',
                'editor_export'   => true,
                'enabled'         => true,
                'icon'            => 'networking.svg',
            ));
        }
    }
}

FLBuilder::register_module('PTARolesDirectoryModule', array(
    'general' => array(
        'title' => __('General', 'azure-plugin'),
        'sections' => array(
            'content' => array(
                'title' => __('Content Settings', 'azure-plugin'),
                'fields' => array(
                    'department' => array(
                        'type' => 'select',
                        'label' => __('Department', 'azure-plugin'),
                        'default' => '',
                        'options' => array(
                            '' => __('All Departments', 'azure-plugin'),
                            'exec-board' => __('Executive Board', 'azure-plugin'),
                            'communications' => __('Communications', 'azure-plugin'),
                            'enrichment' => __('Enrichment', 'azure-plugin'),
                            'events' => __('Events', 'azure-plugin'),
                            'volunteers' => __('Volunteers', 'azure-plugin'),
                            'ways-and-means' => __('Ways and Means', 'azure-plugin'),
                            'safety' => __('Safety', 'azure-plugin')
                        ),
                        'help' => __('Select a specific department or show all departments', 'azure-plugin')
                    ),
                    'status' => array(
                        'type' => 'select',
                        'label' => __('Status Filter', 'azure-plugin'),
                        'default' => 'all',
                        'options' => array(
                            'all' => __('All Positions', 'azure-plugin'),
                            'open' => __('Open Positions Only', 'azure-plugin'),
                            'filled' => __('Filled Positions Only', 'azure-plugin'),
                            'partial' => __('Partially Filled Only', 'azure-plugin')
                        ),
                        'help' => __('Filter roles by their current status', 'azure-plugin')
                    ),
                    'show_description' => array(
                        'type' => 'select',
                        'label' => __('Show Descriptions', 'azure-plugin'),
                        'default' => 'yes',
                        'options' => array(
                            'yes' => __('Yes', 'azure-plugin'),
                            'no' => __('No', 'azure-plugin')
                        )
                    ),
                    'show_count' => array(
                        'type' => 'select',
                        'label' => __('Show Position Count', 'azure-plugin'),
                        'default' => 'yes',
                        'options' => array(
                            'yes' => __('Yes', 'azure-plugin'),
                            'no' => __('No', 'azure-plugin')
                        )
                    ),
                    'show_vp' => array(
                        'type' => 'select',
                        'label' => __('Show Department VP', 'azure-plugin'),
                        'default' => 'no',
                        'options' => array(
                            'yes' => __('Yes', 'azure-plugin'),
                            'no' => __('No', 'azure-plugin')
                        )
                    )
                )
            )
        )
    ),
    'layout' => array(
        'title' => __('Layout', 'azure-plugin'),
        'sections' => array(
            'display' => array(
                'title' => __('Display Options', 'azure-plugin'),
                'fields' => array(
                    'layout' => array(
                        'type' => 'select',
                        'label' => __('Layout Style', 'azure-plugin'),
                        'default' => 'grid',
                        'options' => array(
                            'grid' => __('Grid Layout', 'azure-plugin'),
                            'list' => __('List Layout', 'azure-plugin'),
                            'cards' => __('Card Layout', 'azure-plugin')
                        )
                    ),
                    'columns' => array(
                        'type' => 'select',
                        'label' => __('Columns (Grid Layout)', 'azure-plugin'),
                        'default' => '2',
                        'options' => array(
                            '1' => __('1 Column', 'azure-plugin'),
                            '2' => __('2 Columns', 'azure-plugin'),
                            '3' => __('3 Columns', 'azure-plugin'),
                            '4' => __('4 Columns', 'azure-plugin'),
                            '5' => __('5 Columns', 'azure-plugin'),
                            '6' => __('6 Columns', 'azure-plugin')
                        ),
                        'toggle' => array(
                            'grid' => array(
                                'fields' => array('columns')
                            )
                        )
                    )
                )
            )
        )
    ),
    'style' => array(
        'title' => __('Style', 'azure-plugin'),
        'sections' => array(
            'colors' => array(
                'title' => __('Colors', 'azure-plugin'),
                'fields' => array(
                    'background_color' => array(
                        'type' => 'color',
                        'label' => __('Background Color', 'azure-plugin'),
                        'default' => 'ffffff',
                        'show_reset' => true,
                        'show_alpha' => true
                    ),
                    'text_color' => array(
                        'type' => 'color',
                        'label' => __('Text Color', 'azure-plugin'),
                        'default' => '333333',
                        'show_reset' => true
                    ),
                    'border_color' => array(
                        'type' => 'color',
                        'label' => __('Border Color', 'azure-plugin'),
                        'default' => 'e1e1e1',
                        'show_reset' => true
                    ),
                    'open_color' => array(
                        'type' => 'color',
                        'label' => __('Open Position Color', 'azure-plugin'),
                        'default' => 'dc3545',
                        'show_reset' => true
                    ),
                    'filled_color' => array(
                        'type' => 'color',
                        'label' => __('Filled Position Color', 'azure-plugin'),
                        'default' => '28a745',
                        'show_reset' => true
                    ),
                    'partial_color' => array(
                        'type' => 'color',
                        'label' => __('Partial Position Color', 'azure-plugin'),
                        'default' => 'ffc107',
                        'show_reset' => true
                    )
                )
            ),
            'spacing' => array(
                'title' => __('Spacing', 'azure-plugin'),
                'fields' => array(
                    'padding' => array(
                        'type' => 'dimension',
                        'label' => __('Padding', 'azure-plugin'),
                        'slider' => true,
                        'units' => array('px', 'em', 'rem', '%'),
                        'responsive' => true
                    ),
                    'margin' => array(
                        'type' => 'dimension',
                        'label' => __('Margin', 'azure-plugin'),
                        'slider' => true,
                        'units' => array('px', 'em', 'rem', '%'),
                        'responsive' => true
                    ),
                    'item_spacing' => array(
                        'type' => 'unit',
                        'label' => __('Item Spacing', 'azure-plugin'),
                        'default' => '20',
                        'units' => array('px'),
                        'slider' => array(
                            'min' => 0,
                            'max' => 100,
                            'step' => 1
                        )
                    )
                )
            ),
            'typography' => array(
                'title' => __('Typography', 'azure-plugin'),
                'fields' => array(
                    'title_typography' => array(
                        'type' => 'typography',
                        'label' => __('Title Typography', 'azure-plugin'),
                        'responsive' => true
                    ),
                    'content_typography' => array(
                        'type' => 'typography',
                        'label' => __('Content Typography', 'azure-plugin'),
                        'responsive' => true
                    )
                )
            )
        )
    )
));

/**
 * Register the module
 */
if (!class_exists('PTARolesDirectoryModule')) {
    class PTARolesDirectoryModule extends FLBuilderModule {
        // Module is already defined in the main beaver builder class
    }
}

















