<?php
/**
 * PTA Organization Chart Beaver Builder Module
 */

if (!defined('ABSPATH')) {
    exit;
}

// Guard the CLASS declaration only — do NOT early-return from this file.
// FLBuilder::register_module() at the bottom of this file must always run
// when the file is required, otherwise BB never picks the module up. If
// the class is already declared (e.g. an autoloader or another require_once
// path beat us to it), we still need to call register_module so BB's
// in-memory registry knows about the module for THIS request.
if (!class_exists('PTAOrgChartModule') && class_exists('FLBuilderModule')) {

/**
 * PTA Organization Chart Module for Beaver Builder
 */
class PTAOrgChartModule extends FLBuilderModule {
    
    public function __construct() {
        parent::__construct(array(
            'name'            => __('PTA Organization Chart', 'azure-plugin'),
            'description'     => __('Display PTA organization chart with role hierarchy', 'azure-plugin'),
            'category'        => __('PTA Modules', 'azure-plugin'),
            'dir'             => AZURE_PLUGIN_PATH . 'includes/beaver-builder/pta-org-chart/',
            'url'             => AZURE_PLUGIN_URL . 'includes/beaver-builder/pta-org-chart/',
            'editor_export'   => true,
            'enabled'         => true,
            'partial_refresh' => true,
        ));
        
        Azure_Logger::debug('PTA Org Chart Module: Initialized for Beaver Builder');
    }
    
    /**
     * Render the module output
     */
    public function render_module($settings) {
        $department_id = isset($settings->department_id) ? $settings->department_id : '';
        $show_vacant = isset($settings->show_vacant) ? $settings->show_vacant : 'no';
        $chart_style = isset($settings->chart_style) ? $settings->chart_style : 'hierarchical';
        
        Azure_Logger::debug('PTA Org Chart Module: Rendering organization chart for department ' . $department_id);
        
        echo '<div class="pta-org-chart-container">';
        echo '<div class="org-chart-' . esc_attr($chart_style) . '">';
        
        if (empty($department_id)) {
            echo '<div class="org-chart-placeholder">';
            echo '<p>Please select a department to display the organization chart.</p>';
            echo '</div>';
        } else {
            // Get organization chart data
            $chart_data = $this->get_org_chart_data($department_id, $show_vacant === 'yes');
            
            if (empty($chart_data)) {
                echo '<div class="org-chart-empty">';
                echo '<p>No organization chart data available for this department.</p>';
                echo '</div>';
            } else {
                $this->render_org_chart($chart_data, $chart_style);
            }
        }
        
        echo '</div>';
        echo '</div>';
    }
    
    /**
     * Get organization chart data
     */
    private function get_org_chart_data($department_id, $show_vacant = false) {
        // Placeholder for organization chart data retrieval
        // In a full implementation, this would query the PTA database for role hierarchy
        
        return array(
            array(
                'id' => 1,
                'title' => 'President',
                'name' => 'Jane Doe',
                'level' => 0,
                'parent_id' => null,
                'vacant' => false
            ),
            array(
                'id' => 2,
                'title' => 'Vice President',
                'name' => 'John Smith',
                'level' => 1,
                'parent_id' => 1,
                'vacant' => false
            ),
            array(
                'id' => 3,
                'title' => 'Secretary',
                'name' => '',
                'level' => 1,
                'parent_id' => 1,
                'vacant' => true
            )
        );
    }
    
    /**
     * Render the organization chart
     */
    private function render_org_chart($chart_data, $style) {
        if ($style === 'hierarchical') {
            $this->render_hierarchical_chart($chart_data);
        } elseif ($style === 'flat') {
            $this->render_flat_chart($chart_data);
        } else {
            $this->render_tree_chart($chart_data);
        }
    }
    
    /**
     * Render hierarchical chart
     */
    private function render_hierarchical_chart($chart_data) {
        echo '<div class="org-chart-hierarchical">';
        
        // Group by level
        $levels = array();
        foreach ($chart_data as $role) {
            $levels[$role['level']][] = $role;
        }
        
        foreach ($levels as $level => $roles) {
            echo '<div class="org-level org-level-' . $level . '">';
            foreach ($roles as $role) {
                $vacant_class = $role['vacant'] ? ' vacant' : ' filled';
                echo '<div class="org-role' . $vacant_class . '">';
                echo '<h4>' . esc_html($role['title']) . '</h4>';
                if (!$role['vacant'] && !empty($role['name'])) {
                    echo '<p class="role-name">' . esc_html($role['name']) . '</p>';
                } elseif ($role['vacant']) {
                    echo '<p class="role-vacant">Position Vacant</p>';
                }
                echo '</div>';
            }
            echo '</div>';
        }
        
        echo '</div>';
    }
    
    /**
     * Render flat chart
     */
    private function render_flat_chart($chart_data) {
        echo '<div class="org-chart-flat">';
        foreach ($chart_data as $role) {
            $vacant_class = $role['vacant'] ? ' vacant' : ' filled';
            echo '<div class="org-role-flat' . $vacant_class . '">';
            echo '<strong>' . esc_html($role['title']) . '</strong>';
            if (!$role['vacant'] && !empty($role['name'])) {
                echo ' - ' . esc_html($role['name']);
            } elseif ($role['vacant']) {
                echo ' - <em>Position Vacant</em>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
    
    /**
     * Render tree chart
     */
    private function render_tree_chart($chart_data) {
        echo '<div class="org-chart-tree">';
        echo '<p><em>Tree view coming soon...</em></p>';
        echo '</div>';
    }
}

} // end if (!class_exists('PTAOrgChartModule') && class_exists('FLBuilderModule'))

// Register the module — always call this when the file is required, even
// if the class was already declared by another path. BB's $modules registry
// is per-request so we must re-register on every load_modules() pass.
if (class_exists('PTAOrgChartModule') && class_exists('FLBuilder')) {
    FLBuilder::register_module('PTAOrgChartModule', array(
    'general' => array(
        'title' => __('General', 'azure-plugin'),
        'sections' => array(
            'general' => array(
                'title' => __('Organization Chart Settings', 'azure-plugin'),
                'fields' => array(
                    'department_id' => array(
                        'type' => 'select',
                        'label' => __('Department', 'azure-plugin'),
                        'default' => '',
                        'options' => array(
                            '' => __('Select Department', 'azure-plugin'),
                            '1' => __('Executive Board', 'azure-plugin'),
                            '2' => __('Fundraising', 'azure-plugin'),
                            '3' => __('Events', 'azure-plugin'),
                        )
                    ),
                    'chart_style' => array(
                        'type' => 'select',
                        'label' => __('Chart Style', 'azure-plugin'),
                        'default' => 'hierarchical',
                        'options' => array(
                            'hierarchical' => __('Hierarchical', 'azure-plugin'),
                            'flat' => __('Flat List', 'azure-plugin'),
                            'tree' => __('Tree View', 'azure-plugin'),
                        )
                    ),
                    'show_vacant' => array(
                        'type' => 'select',
                        'label' => __('Show Vacant Positions', 'azure-plugin'),
                        'default' => 'no',
                        'options' => array(
                            'yes' => __('Yes', 'azure-plugin'),
                            'no' => __('No', 'azure-plugin'),
                        )
                    )
                )
            )
        )
    )
));
}
