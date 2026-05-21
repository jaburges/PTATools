<?php
/**
 * PTA Open Positions Beaver Builder Module
 */

if (!defined('ABSPATH')) {
    exit;
}

// Guard the CLASS declaration only — do NOT early-return from this file.
// FLBuilder::register_module() at the bottom must always run. See
// pta-org-chart.php for the full rationale.
if (!class_exists('PTAOpenPositionsModule') && class_exists('FLBuilderModule')) {

/**
 * PTA Open Positions Module for Beaver Builder
 */
class PTAOpenPositionsModule extends FLBuilderModule {
    
    public function __construct() {
        parent::__construct(array(
            'name'            => __('PTA Open Positions', 'azure-plugin'),
            'description'     => __('Display available PTA positions for volunteer recruitment', 'azure-plugin'),
            'category'        => __('PTA Modules', 'azure-plugin'),
            'dir'             => AZURE_PLUGIN_PATH . 'includes/beaver-builder/pta-open-positions/',
            'url'             => AZURE_PLUGIN_URL . 'includes/beaver-builder/pta-open-positions/',
            'editor_export'   => true,
            'enabled'         => true,
            'partial_refresh' => true,
        ));
        
        Azure_Logger::debug('PTA Open Positions Module: Initialized for Beaver Builder');
    }
    
    /**
     * Render the module output
     */
    public function render_module($settings) {
        $department_filter = isset($settings->department_filter) ? $settings->department_filter : '';
        $show_descriptions = isset($settings->show_descriptions) ? $settings->show_descriptions : 'yes';
        $show_apply_button = isset($settings->show_apply_button) ? $settings->show_apply_button : 'yes';
        $layout = isset($settings->layout) ? $settings->layout : 'grid';
        
        Azure_Logger::debug('PTA Open Positions Module: Rendering open positions for department ' . $department_filter);
        
        echo '<div class="pta-open-positions-container">';
        echo '<div class="open-positions-' . esc_attr($layout) . '">';
        
        // Get open positions data
        $open_positions = $this->get_open_positions($department_filter);
        
        if (empty($open_positions)) {
            echo '<div class="no-positions">';
            echo '<h3>Great News!</h3>';
            echo '<p>All positions are currently filled. Thank you to all our wonderful volunteers!</p>';
            echo '<p>Check back later for new volunteer opportunities.</p>';
            echo '</div>';
        } else {
            echo '<div class="positions-header">';
            echo '<h3>Open Volunteer Positions</h3>';
            echo '<p>Join our amazing PTA team! The following positions are currently available:</p>';
            echo '</div>';
            
            if ($layout === 'grid') {
                $this->render_positions_grid($open_positions, $show_descriptions, $show_apply_button);
            } else {
                $this->render_positions_list($open_positions, $show_descriptions, $show_apply_button);
            }
        }
        
        echo '</div>';
        echo '</div>';
    }
    
    /**
     * Get open positions data
     */
    private function get_open_positions($department_filter = '') {
        // Placeholder for open positions data retrieval
        // In a full implementation, this would query the PTA database for vacant roles
        
        $positions = array(
            array(
                'id' => 1,
                'title' => 'Volunteer Coordinator',
                'department' => 'Events',
                'description' => 'Help organize and coordinate volunteers for school events and activities.',
                'requirements' => 'Strong organizational skills, friendly personality',
                'time_commitment' => '3-5 hours per week',
                'urgency' => 'high'
            ),
            array(
                'id' => 2,
                'title' => 'Social Media Manager',
                'department' => 'Communications',
                'description' => 'Manage our social media presence and help spread the word about PTA activities.',
                'requirements' => 'Familiarity with Facebook, Instagram, and Twitter',
                'time_commitment' => '2-3 hours per week',
                'urgency' => 'medium'
            ),
            array(
                'id' => 3,
                'title' => 'Fundraising Assistant',
                'department' => 'Fundraising',
                'description' => 'Support fundraising activities and help plan fundraising events.',
                'requirements' => 'Creative thinking, good communication skills',
                'time_commitment' => '4-6 hours per week',
                'urgency' => 'low'
            )
        );
        
        // Filter by department if specified
        if (!empty($department_filter) && $department_filter !== 'all') {
            $positions = array_filter($positions, function($position) use ($department_filter) {
                return strtolower($position['department']) === strtolower($department_filter);
            });
        }
        
        return $positions;
    }
    
    /**
     * Render positions in grid layout
     */
    private function render_positions_grid($positions, $show_descriptions, $show_apply_button) {
        echo '<div class="positions-grid">';
        
        foreach ($positions as $position) {
            $urgency_class = 'urgency-' . $position['urgency'];
            echo '<div class="position-card ' . $urgency_class . '">';
            
            echo '<div class="position-header">';
            echo '<h4>' . esc_html($position['title']) . '</h4>';
            echo '<span class="department-badge">' . esc_html($position['department']) . '</span>';
            if ($position['urgency'] === 'high') {
                echo '<span class="urgency-badge urgent">Urgent</span>';
            }
            echo '</div>';
            
            if ($show_descriptions === 'yes') {
                echo '<div class="position-details">';
                echo '<p class="description">' . esc_html($position['description']) . '</p>';
                echo '<p class="time-commitment"><strong>Time:</strong> ' . esc_html($position['time_commitment']) . '</p>';
                if (!empty($position['requirements'])) {
                    echo '<p class="requirements"><strong>Requirements:</strong> ' . esc_html($position['requirements']) . '</p>';
                }
                echo '</div>';
            }
            
            if ($show_apply_button === 'yes') {
                echo '<div class="position-actions">';
                echo '<button class="apply-button" data-position-id="' . $position['id'] . '">Apply Now</button>';
                echo '<button class="info-button" data-position-id="' . $position['id'] . '">More Info</button>';
                echo '</div>';
            }
            
            echo '</div>';
        }
        
        echo '</div>';
    }
    
    /**
     * Render positions in list layout
     */
    private function render_positions_list($positions, $show_descriptions, $show_apply_button) {
        echo '<div class="positions-list">';
        
        foreach ($positions as $position) {
            $urgency_class = 'urgency-' . $position['urgency'];
            echo '<div class="position-item ' . $urgency_class . '">';
            
            echo '<div class="position-title-row">';
            echo '<h4>' . esc_html($position['title']) . '</h4>';
            echo '<span class="department">' . esc_html($position['department']) . '</span>';
            if ($position['urgency'] === 'high') {
                echo '<span class="urgency-indicator">🔥 Urgent</span>';
            }
            echo '</div>';
            
            if ($show_descriptions === 'yes') {
                echo '<div class="position-description">';
                echo '<p>' . esc_html($position['description']) . '</p>';
                echo '<div class="position-meta">';
                echo '<span class="time-commitment">⏱️ ' . esc_html($position['time_commitment']) . '</span>';
                echo '</div>';
                echo '</div>';
            }
            
            if ($show_apply_button === 'yes') {
                echo '<div class="position-buttons">';
                echo '<button class="apply-button-small" data-position-id="' . $position['id'] . '">Apply</button>';
                echo '</div>';
            }
            
            echo '</div>';
        }
        
        echo '</div>';
    }
}

} // end if (!class_exists('PTAOpenPositionsModule') && class_exists('FLBuilderModule'))

// Register the module — always call this when the file is required, even
// if the class was already declared by another path. See pta-org-chart.php
// for the full rationale.
if (class_exists('PTAOpenPositionsModule') && class_exists('FLBuilder')) {
    FLBuilder::register_module('PTAOpenPositionsModule', array(
    'general' => array(
        'title' => __('General', 'azure-plugin'),
        'sections' => array(
            'general' => array(
                'title' => __('Open Positions Settings', 'azure-plugin'),
                'fields' => array(
                    'department_filter' => array(
                        'type' => 'select',
                        'label' => __('Filter by Department', 'azure-plugin'),
                        'default' => 'all',
                        'options' => array(
                            'all' => __('All Departments', 'azure-plugin'),
                            'executive' => __('Executive Board', 'azure-plugin'),
                            'fundraising' => __('Fundraising', 'azure-plugin'),
                            'events' => __('Events', 'azure-plugin'),
                            'communications' => __('Communications', 'azure-plugin'),
                        )
                    ),
                    'layout' => array(
                        'type' => 'select',
                        'label' => __('Layout', 'azure-plugin'),
                        'default' => 'grid',
                        'options' => array(
                            'grid' => __('Grid Cards', 'azure-plugin'),
                            'list' => __('Simple List', 'azure-plugin'),
                        )
                    ),
                    'show_descriptions' => array(
                        'type' => 'select',
                        'label' => __('Show Descriptions', 'azure-plugin'),
                        'default' => 'yes',
                        'options' => array(
                            'yes' => __('Yes', 'azure-plugin'),
                            'no' => __('No', 'azure-plugin'),
                        )
                    ),
                    'show_apply_button' => array(
                        'type' => 'select',
                        'label' => __('Show Apply Button', 'azure-plugin'),
                        'default' => 'yes',
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
