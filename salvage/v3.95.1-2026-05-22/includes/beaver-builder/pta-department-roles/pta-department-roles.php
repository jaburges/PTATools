<?php
/**
 * PTA Department Roles Beaver Builder Module
 */

if (!defined('ABSPATH')) {
    exit;
}

// Declare the module class HERE (not in class-pta-beaver-builder.php) so
// FLBuilderModule's ReflectionClass-based slug computation resolves to
// 'pta-department-roles'. See sibling pta-roles-directory.php for the full
// rationale.
if (!class_exists('PTADepartmentRolesModule') && class_exists('FLBuilderModule')) {
    class PTADepartmentRolesModule extends FLBuilderModule {
        public function __construct() {
            parent::__construct(array(
                'name'            => __('PTA Department Roles', 'azure-plugin'),
                'description'     => __('Display roles for a specific department', 'azure-plugin'),
                'group'           => __('Azure Plugin', 'azure-plugin'),
                'category'        => __('PTA Modules', 'azure-plugin'),
                'dir'             => AZURE_PLUGIN_PATH . 'includes/beaver-builder/pta-department-roles/',
                'url'             => AZURE_PLUGIN_URL . 'includes/beaver-builder/pta-department-roles/',
                'editor_export'   => true,
                'enabled'         => true,
                'icon'            => 'groups.svg',
            ));
        }
    }
}

FLBuilder::register_module('PTADepartmentRolesModule', array(
    'general' => array(
        'title' => __('General', 'azure-plugin'),
        'sections' => array(
            'content' => array(
                'title' => __('Content Settings', 'azure-plugin'),
                'fields' => array(
                    'department' => array(
                        'type' => 'select',
                        'label' => __('Department', 'azure-plugin'),
                        'default' => 'communications',
                        'options' => array(
                            'exec-board' => __('Executive Board', 'azure-plugin'),
                            'communications' => __('Communications', 'azure-plugin'),
                            'enrichment' => __('Enrichment', 'azure-plugin'),
                            'events' => __('Events', 'azure-plugin'),
                            'volunteers' => __('Volunteers', 'azure-plugin'),
                            'ways-and-means' => __('Ways and Means', 'azure-plugin'),
                            'safety' => __('Safety', 'azure-plugin')
                        ),
                        'help' => __('Select the department to display', 'azure-plugin')
                    ),
                    'show_vp' => array(
                        'type' => 'select',
                        'label' => __('Show Department VP', 'azure-plugin'),
                        'default' => 'yes',
                        'options' => array(
                            'yes' => __('Yes', 'azure-plugin'),
                            'no' => __('No', 'azure-plugin')
                        )
                    ),
                    'show_description' => array(
                        'type' => 'select',
                        'label' => __('Show Role Descriptions', 'azure-plugin'),
                        'default' => 'no',
                        'options' => array(
                            'yes' => __('Yes', 'azure-plugin'),
                            'no' => __('No', 'azure-plugin')
                        )
                    ),
                    'layout' => array(
                        'type' => 'select',
                        'label' => __('Layout Style', 'azure-plugin'),
                        'default' => 'list',
                        'options' => array(
                            'list' => __('List Layout', 'azure-plugin'),
                            'cards' => __('Card Layout', 'azure-plugin')
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
                    'accent_color' => array(
                        'type' => 'color',
                        'label' => __('Accent Color', 'azure-plugin'),
                        'default' => '007cba',
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
                    )
                )
            )
        )
    )
));

















