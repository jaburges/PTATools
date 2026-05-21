<?php
/**
 * PTA Shortcode handler for Azure Plugin
 * 
 * ROLES DIRECTORY SHORTCODE:
 * [pta-roles-directory layout="grid" columns="3" show_image="true" photo_size="80"]
 * 
 * Parameters:
 * - layout: grid, list, cards, team-cards (default: grid)
 * - columns: 1-5 (default: 3)
 * - department: filter by department slug/name
 * - status: all, open, filled, partial (default: all)
 * - description: true/false - show role description
 * - show_count: true/false - show filled/total count
 * - show_image: true/false - show WordPress profile photos (default: false)
 * - include_photo: true/false - alias for show_image (default: false)
 * - photo_size: number - photo size in pixels (default: 80)
 * - show_avatars: true/false (for team-cards layout)
 * - show_contact: true/false (show email links)
 * 
 * LEADERSHIP STRUCTURE LAYOUT:
 * [pta-roles-directory leadership_structure="true" leader_role="president" 
 *  leader_photo_size="120" show_image="true" columns="4"]
 * 
 * This displays the leader role (e.g., President) centered above all other roles:
 * 
 *                    ┌─────────────┐
 *                    │   Photo     │
 *                    │  President  │
 *                    │    Name     │
 *                    └─────────────┘
 *  ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌─────────┐
 *  │  Photo  │  │  Photo  │  │  Photo  │  │  Photo  │
 *  │  Role   │  │  Role   │  │  Role   │  │  Role   │
 *  │  Name   │  │  Name   │  │  Name   │  │  Name   │
 *  └─────────┘  └─────────┘  └─────────┘  └─────────┘
 * 
 * Leadership Structure Parameters:
 * - leadership_structure: true/false - enable hierarchy layout (default: false)
 * - leader_role: role slug/name to show as leader (default: "president")
 * - leader_photo_size: leader photo size in pixels (default: 120)
 * 
 * ROLE CARD SHORTCODE:
 * [pta-role-card role="president" show_image="true" photo_size="100" show_contact="true"]
 * 
 * Parameters:
 * - role: role slug or name (required)
 * - show_image: true/false - show WordPress profile photos (default: false)
 * - include_photo: true/false - alias for show_image (default: false)
 * - photo_size: number - photo size in pixels (default: 80)
 * - show_contact: true/false - show email links
 * - show_description: true/false - show role description
 * - show_assignments: true/false - show assigned users
 * 
 * TEAM MEMBERS INSPIRED LAYOUT:
 * [pta-roles-directory layout="team-cards" columns="4" show_avatars="true" show_contact="true"]
 * 
 * Team Cards Specific Options:
 * - show_avatars: true/false (show user avatars in circular style)
 * - avatar_size: number (avatar size in pixels, default 80)
 * - columns: 1-5 (responsive: desktop full, tablet 2, mobile 1)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_PTA_Shortcode {
    
    private $pta_manager;
    
    public function __construct() {
        // Check if PTA Manager is available before using it
        if (class_exists('Azure_PTA_Manager')) {
            $this->pta_manager = Azure_PTA_Manager::get_instance();
        } else {
            $this->pta_manager = null;
            Azure_Logger::warning('PTA Shortcode: PTA Manager class not available - shortcodes will display placeholder content');
        }
        
        // Register PTA shortcodes
        add_shortcode('pta-roles-directory', array($this, 'roles_directory_shortcode'));
        add_shortcode('pta-department-roles', array($this, 'department_roles_shortcode'));
        add_shortcode('pta-org-chart', array($this, 'org_chart_shortcode'));
        add_shortcode('pta-role-card', array($this, 'role_card_shortcode'));
        add_shortcode('pta-department-vp', array($this, 'department_vp_shortcode'));
        add_shortcode('pta-open-positions', array($this, 'open_positions_shortcode'));
        add_shortcode('pta-user-roles', array($this, 'user_roles_shortcode'));
        
        // Enqueue frontend styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
    }
    
    /**
     * Check if PTA Manager is available and show appropriate message
     */
    private function check_pta_manager_availability() {
        if ($this->pta_manager === null) {
            return '<div class="pta-shortcode-unavailable">' . 
                   '<p><strong>PTA Manager Unavailable:</strong> This shortcode requires the PTA Manager component which is currently disabled.</p>' . 
                   '</div>';
        }
        return false;
    }
    
    /**
     * Enqueue frontend assets only when a PTA shortcode is present.
     */
    public function enqueue_frontend_assets() {
        global $post;
        if (!is_a($post, 'WP_Post')) {
            return;
        }

        $shortcodes = array('pta-roles-directory', 'pta-department-roles', 'pta-org-chart', 'pta-role-card', 'pta-department-vp', 'pta-open-positions', 'pta-user-roles');
        $found = false;
        foreach ($shortcodes as $sc) {
            if (has_shortcode($post->post_content, $sc)) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return;
        }

        wp_enqueue_style('pta-roles-frontend', AZURE_PLUGIN_URL . 'css/pta-roles-frontend.css', array(), AZURE_PLUGIN_VERSION);
        wp_enqueue_script('pta-shortcodes', AZURE_PLUGIN_URL . 'assets/pta-shortcodes.js', array('jquery'), AZURE_PLUGIN_VERSION, true);

        if (class_exists('Azure_PTA_Forminator') && Azure_PTA_Forminator::is_configured()) {
            wp_localize_script('pta-shortcodes', 'ptaSignupConfig', Azure_PTA_Forminator::get_frontend_config());
        }
    }
    
    /**
     * Roles Directory shortcode
     * Usage: [pta-roles-directory department="communications" description=true status="open" columns=3]
     */
    public function roles_directory_shortcode($atts) {
        // Check if PTA Manager is available
        $availability_check = $this->check_pta_manager_availability();
        if ($availability_check !== false) {
            return $availability_check;
        }
        
        $atts = shortcode_atts(array(
            'department' => '',
            'description' => false,
            'status' => 'all', // all, open, filled, partial
            'columns' => 3,
            'show_count' => true,
            'show_vp' => false,
            'layout' => 'grid', // grid, list, cards, team-cards
            'show_avatars' => true,
            'show_contact' => true,
            'avatar_size' => 80,
            'include_photo' => false, // Show WordPress profile photos
            'show_image' => false, // Alias for include_photo
            'photo_size' => 80, // Photo size in pixels
            'leadership_structure' => false, // Show leader role centered above others
            'leader_role' => 'president', // Role slug/name to show as leader
            'leader_photo_size' => 120 // Leader photo size (larger than others)
        ), $atts);
        
        // Convert string boolean values to actual booleans
        $atts['description'] = filter_var($atts['description'], FILTER_VALIDATE_BOOLEAN);
        $atts['show_count'] = filter_var($atts['show_count'], FILTER_VALIDATE_BOOLEAN);
        $atts['show_vp'] = filter_var($atts['show_vp'], FILTER_VALIDATE_BOOLEAN);
        $atts['show_avatars'] = filter_var($atts['show_avatars'], FILTER_VALIDATE_BOOLEAN);
        $atts['show_contact'] = filter_var($atts['show_contact'], FILTER_VALIDATE_BOOLEAN);
        $atts['include_photo'] = filter_var($atts['include_photo'], FILTER_VALIDATE_BOOLEAN);
        $atts['leadership_structure'] = filter_var($atts['leadership_structure'], FILTER_VALIDATE_BOOLEAN);
        
        // Handle show_image as alias for include_photo
        if (filter_var($atts['show_image'], FILTER_VALIDATE_BOOLEAN)) {
            $atts['include_photo'] = true;
        }
        
        // Get roles
        $department_id = null;
        if ($atts['department']) {
            $departments = $this->pta_manager->get_departments();
            foreach ($departments as $dept) {
                if (strtolower($dept->slug) === strtolower($atts['department']) || 
                    strtolower($dept->name) === strtolower($atts['department'])) {
                    $department_id = $dept->id;
                    break;
                }
            }
        }
        
        $roles = $this->pta_manager->get_roles($department_id, true);
        
        if (empty($roles)) {
            return '<p class="pta-no-roles">No roles found.</p>';
        }
        
        // Filter by status
        if ($atts['status'] !== 'all') {
            $roles = array_filter($roles, function($role) use ($atts) {
                $status = $this->get_role_status($role);
                return $status === $atts['status'];
            });
        }

        // Load department O365 group emails for display on role cards
        $dept_ids = array_unique(wp_list_pluck($roles, 'department_id'));
        $atts['_dept_emails'] = $this->get_department_group_emails($dept_ids);

        // For leadership structure, build per-role email map using all department emails
        if ($atts['leadership_structure']) {
            $all_dept_emails = $this->get_department_group_emails(
                wp_list_pluck($this->pta_manager->get_departments(false), 'id')
            );
            $atts['_role_emails'] = $this->get_leadership_role_emails($roles, $all_dept_emails);
        }

        return $this->render_roles_directory($roles, $atts);
    }
    
    /**
     * Department Roles shortcode
     * Usage: [pta-department-roles department="communications" show_vp=true]
     */
    public function department_roles_shortcode($atts) {
        // Check if PTA Manager is available
        $availability_check = $this->check_pta_manager_availability();
        if ($availability_check !== false) {
            return $availability_check;
        }
        
        $atts = shortcode_atts(array(
            'department' => '',
            'show_vp' => true,
            'show_description' => false,
            'layout' => 'list'
        ), $atts);
        
        // Convert string boolean values to actual booleans
        $atts['show_vp'] = filter_var($atts['show_vp'], FILTER_VALIDATE_BOOLEAN);
        $atts['show_description'] = filter_var($atts['show_description'], FILTER_VALIDATE_BOOLEAN);
        
        if (empty($atts['department'])) {
            return '<p class="pta-error">Department parameter is required.</p>';
        }
        
        // Get department
        $departments = $this->pta_manager->get_departments(true);
        $department = null;
        foreach ($departments as $dept) {
            if (strtolower($dept->slug) === strtolower($atts['department']) || 
                strtolower($dept->name) === strtolower($atts['department'])) {
                $department = $dept;
                break;
            }
        }
        
        if (!$department) {
            return '<p class="pta-error">Department not found.</p>';
        }
        
        $roles = $this->pta_manager->get_roles($department->id, true);
        
        $output = '<div class="pta-department-roles">';
        $output .= '<h3>' . esc_html($department->name) . '</h3>';
        
        if ($atts['show_vp'] && $department->vp_user_id) {
            $vp_user = get_user_by('ID', $department->vp_user_id);
            if ($vp_user) {
                $output .= '<p class="pta-department-vp"><strong>VP:</strong> ' . esc_html($vp_user->display_name) . '</p>';
            }
        }
        
        if (!empty($roles)) {
            $output .= $this->render_roles_list($roles, $atts);
        } else {
            $output .= '<p class="pta-no-roles">No roles in this department.</p>';
        }
        
        $output .= '</div>';
        return $output;
    }
    
    /**
     * Org Chart shortcode
     * Usage: [pta-org-chart department="all" interactive=true]
     */
    public function org_chart_shortcode($atts) {
        // Check if PTA Manager is available
        $availability_check = $this->check_pta_manager_availability();
        if ($availability_check !== false) {
            return $availability_check;
        }
        
        $atts = shortcode_atts(array(
            'department' => 'all',
            'interactive' => false,
            'height' => '400px'
        ), $atts);
        
        // Convert string boolean values to actual booleans
        $atts['interactive'] = filter_var($atts['interactive'], FILTER_VALIDATE_BOOLEAN);
        
        wp_enqueue_script('d3', 'https://d3js.org/d3.v7.min.js', array(), '7.0.0', true);
        
        $org_data = $this->get_org_chart_data($atts['department']);
        
        $chart_id = 'pta-org-chart-' . uniqid();
        
        $output = '<div class="pta-org-chart-container">';
        $output .= '<div id="' . $chart_id . '" class="pta-org-chart" style="height: ' . esc_attr($atts['height']) . ';"></div>';
        $output .= '</div>';
        
        // Add inline JavaScript for the chart
        $output .= '<script>
        jQuery(document).ready(function($) {
            var orgData = ' . json_encode($org_data) . ';
            if (typeof renderPTAOrgChart === "function") {
                renderPTAOrgChart("' . $chart_id . '", orgData, ' . json_encode($atts) . ');
            }
        });
        </script>';
        
        return $output;
    }
    
    /**
     * Role Card shortcode
     * Usage: [pta-role-card role="president" show_contact=true include_photo=true photo_size=80]
     */
    public function role_card_shortcode($atts) {
        // Check if PTA Manager is available
        $availability_check = $this->check_pta_manager_availability();
        if ($availability_check !== false) {
            return $availability_check;
        }
        
        $atts = shortcode_atts(array(
            'role' => '',
            'show_contact' => false,
            'show_description' => true,
            'show_assignments' => true,
            'include_photo' => false,
            'show_image' => false, // Alias for include_photo
            'photo_size' => 80
        ), $atts);
        
        // Convert string boolean values to actual booleans
        $atts['show_contact'] = filter_var($atts['show_contact'], FILTER_VALIDATE_BOOLEAN);
        $atts['show_description'] = filter_var($atts['show_description'], FILTER_VALIDATE_BOOLEAN);
        $atts['show_assignments'] = filter_var($atts['show_assignments'], FILTER_VALIDATE_BOOLEAN);
        $atts['include_photo'] = filter_var($atts['include_photo'], FILTER_VALIDATE_BOOLEAN);
        
        // Handle show_image as alias for include_photo
        if (filter_var($atts['show_image'], FILTER_VALIDATE_BOOLEAN)) {
            $atts['include_photo'] = true;
        }
        
        if (empty($atts['role'])) {
            return '<p class="pta-error">Role parameter is required.</p>';
        }
        
        $roles = $this->pta_manager->get_roles(null, true);
        $role = null;
        
        foreach ($roles as $r) {
            if (strtolower($r->slug) === strtolower($atts['role']) || 
                strtolower($r->name) === strtolower($atts['role'])) {
                $role = $r;
                break;
            }
        }
        
        if (!$role) {
            return '<p class="pta-error">Role not found.</p>';
        }
        
        return $this->render_role_card($role, $atts);
    }
    
    /**
     * Department VP shortcode
     * Usage: [pta-department-vp department="communications"]
     */
    public function department_vp_shortcode($atts) {
        // Check if PTA Manager is available
        $availability_check = $this->check_pta_manager_availability();
        if ($availability_check !== false) {
            return $availability_check;
        }
        
        $atts = shortcode_atts(array(
            'department' => '',
            'show_contact' => false,
            'show_email' => false
        ), $atts);
        
        // Convert string boolean values to actual booleans
        $atts['show_contact'] = filter_var($atts['show_contact'], FILTER_VALIDATE_BOOLEAN);
        $atts['show_email'] = filter_var($atts['show_email'], FILTER_VALIDATE_BOOLEAN);
        
        if (empty($atts['department'])) {
            return '<p class="pta-error">Department parameter is required.</p>';
        }
        
        $departments = $this->pta_manager->get_departments(true);
        $department = null;
        
        foreach ($departments as $dept) {
            if (strtolower($dept->slug) === strtolower($atts['department']) || 
                strtolower($dept->name) === strtolower($atts['department'])) {
                $department = $dept;
                break;
            }
        }
        
        if (!$department || !$department->vp_user_id) {
            return '<p class="pta-no-vp">No VP assigned for this department.</p>';
        }
        
        $vp_user = get_user_by('ID', $department->vp_user_id);
        if (!$vp_user) {
            return '<p class="pta-no-vp">VP user not found.</p>';
        }
        
        // Use O365 group email if mapped, otherwise fall back to VP's personal email
        $dept_emails = $this->get_department_group_emails(array($department->id));
        $display_email = $dept_emails[$department->id] ?? $vp_user->user_email;

        $output = '<div class="pta-department-vp-card">';
        $output .= '<h4>' . esc_html($department->name) . ' VP</h4>';
        $output .= '<div class="pta-vp-name">' . esc_html($vp_user->display_name) . '</div>';
        
        if ($atts['show_email'] && $display_email) {
            $output .= '<div class="pta-vp-email"><a href="mailto:' . esc_attr($display_email) . '">' . esc_html($display_email) . '</a></div>';
        }
        
        $output .= '</div>';
        return $output;
    }
    
    /**
     * Open Positions shortcode
     * Usage: [pta-open-positions department="all" limit=10]
     */
    public function open_positions_shortcode($atts) {
        // Check if PTA Manager is available
        $availability_check = $this->check_pta_manager_availability();
        if ($availability_check !== false) {
            return $availability_check;
        }
        
        $atts = shortcode_atts(array(
            'department' => 'all',
            'limit' => -1,
            'show_department' => true,
            'show_description' => false
        ), $atts);
        
        // Convert string boolean values to actual booleans
        $atts['show_department'] = filter_var($atts['show_department'], FILTER_VALIDATE_BOOLEAN);
        $atts['show_description'] = filter_var($atts['show_description'], FILTER_VALIDATE_BOOLEAN);
        
        $department_id = null;
        if ($atts['department'] !== 'all') {
            $departments = $this->pta_manager->get_departments();
            foreach ($departments as $dept) {
                if (strtolower($dept->slug) === strtolower($atts['department']) || 
                    strtolower($dept->name) === strtolower($atts['department'])) {
                    $department_id = $dept->id;
                    break;
                }
            }
        }
        
        $roles = $this->pta_manager->get_roles($department_id, true);
        
        // Filter to only open positions
        $open_roles = array_filter($roles, function($role) {
            return $role->assigned_count < $role->max_occupants;
        });
        
        if (empty($open_roles)) {
            return '<p class="pta-no-openings">No open positions available.</p>';
        }
        
        // Limit results
        if ($atts['limit'] > 0) {
            $open_roles = array_slice($open_roles, 0, $atts['limit']);
        }
        
        return $this->render_open_positions($open_roles, $atts);
    }
    
    /**
     * User Roles shortcode
     * Usage: [pta-user-roles user_id=123] or [pta-user-roles] (current user)
     */
    public function user_roles_shortcode($atts) {
        // Check if PTA Manager is available
        $availability_check = $this->check_pta_manager_availability();
        if ($availability_check !== false) {
            return $availability_check;
        }
        
        $atts = shortcode_atts(array(
            'user_id' => get_current_user_id(),
            'show_department' => true,
            'show_description' => false
        ), $atts);
        
        // Convert string boolean values to actual booleans
        $atts['show_department'] = filter_var($atts['show_department'], FILTER_VALIDATE_BOOLEAN);
        $atts['show_description'] = filter_var($atts['show_description'], FILTER_VALIDATE_BOOLEAN);
        
        if (empty($atts['user_id'])) {
            return '<p class="pta-error">No user specified and no current user.</p>';
        }
        
        $user = get_user_by('ID', $atts['user_id']);
        if (!$user) {
            return '<p class="pta-error">User not found.</p>';
        }
        
        $assignments = $this->pta_manager->get_user_assignments($atts['user_id']);
        
        if (empty($assignments)) {
            return '<p class="pta-no-assignments">No role assignments found.</p>';
        }
        
        return $this->render_user_roles($assignments, $atts);
    }
    
    /**
     * Helper methods for rendering
     */
    private function render_roles_directory($roles, $atts) {
        $columns = max(1, min(5, intval($atts['columns'])));
        $layout = $atts['layout'];
        $include_photo = filter_var($atts['include_photo'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $photo_size = intval($atts['photo_size'] ?? 80);
        $leadership_structure = filter_var($atts['leadership_structure'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $leader_role_name = strtolower($atts['leader_role'] ?? 'president');
        $leader_photo_size = intval($atts['leader_photo_size'] ?? 120);
        
        $output = '';
        $leader_role = null;
        $other_roles = array();
        
        // If leadership_structure is enabled, separate the leader role from others
        if ($leadership_structure) {
            foreach ($roles as $role) {
                if (strtolower($role->slug) === $leader_role_name || strtolower($role->name) === $leader_role_name) {
                    $leader_role = $role;
                } else {
                    $other_roles[] = $role;
                }
            }
            
            // Render the leader section if found
            if ($leader_role) {
                $output .= '<div class="pta-leadership-structure">';
                $output .= '<div class="pta-leader-section">';
                $output .= $this->render_single_role_card($leader_role, $atts, $leader_photo_size, true);
                $output .= '</div>';
                $output .= '<div class="pta-roles-directory pta-layout-' . esc_attr($layout) . '" data-columns="' . $columns . '">';
                
                foreach ($other_roles as $role) {
                    $output .= $this->render_single_role_card($role, $atts, $photo_size, false);
                }
                
                $output .= '</div>';
                $output .= '</div>';
                return $output;
            }
            // If leader role not found, fall through to normal rendering
            $roles = array_merge(array($leader_role), $other_roles);
            $roles = array_filter($roles); // Remove null
        }
        
        // Normal rendering (no leadership structure or leader not found)
        $output .= '<div class="pta-roles-directory pta-layout-' . esc_attr($layout) . '" data-columns="' . $columns . '">';
        
        foreach ($roles as $role) {
            $output .= $this->render_single_role_card($role, $atts, $photo_size, false);
        }
        
        $output .= '</div>';
        return $output;
    }
    
    /**
     * Render a single role card (used by render_roles_directory)
     */
    private function render_single_role_card($role, $atts, $photo_size, $is_leader = false) {
        $layout = $atts['layout'];
        $include_photo = filter_var($atts['include_photo'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $status = $this->get_role_status($role);
        
        if ($layout === 'team-cards') {
            return $this->render_team_card($role, $atts, $status);
        }
        
        // Original grid/list/cards layout
        $leader_class = $is_leader ? ' pta-leader-card' : '';
        $data_attrs = ' data-role-id="' . esc_attr($role->id) . '"'
            . ' data-role-name="' . esc_attr($role->name) . '"'
            . ' data-department-name="' . esc_attr($role->department_name) . '"';
        $output = '<div class="pta-role-item pta-status-' . esc_attr($status) . $leader_class . '"' . $data_attrs . '>';
        
        // Show photo if include_photo is true and role has assignments
        if ($include_photo && !empty($role->assignments)) {
            $first_user = get_user_by('ID', $role->assignments[0]->user_id);
            if ($first_user) {
                $output .= '<div class="pta-role-photo">';
                $output .= get_avatar($first_user->ID, $photo_size, '', $first_user->display_name, array('class' => 'pta-user-avatar'));
                $output .= '</div>';
            }
        } elseif ($include_photo) {
            // Show placeholder for unfilled roles
            $initials = $this->get_role_initials($role->name);
            $output .= '<div class="pta-role-photo pta-photo-placeholder" style="width: ' . $photo_size . 'px; height: ' . $photo_size . 'px;">';
            $output .= '<span class="pta-initials">' . esc_html($initials) . '</span>';
            $output .= '</div>';
        }
        
        $output .= '<h4 class="pta-role-name">' . esc_html($role->name) . '</h4>';
        
        // Show assigned user names if include_photo is true
        if ($include_photo && !empty($role->assignments)) {
            $user_names = array();
            foreach ($role->assignments as $assignment) {
                $user = get_user_by('ID', $assignment->user_id);
                if ($user) {
                    $user_names[] = esc_html($user->display_name);
                }
            }
            if (!empty($user_names)) {
                $output .= '<div class="pta-role-assigned-names">' . implode(', ', $user_names) . '</div>';
            }
        }

        // Show O365 group email for leadership structure roles
        $role_emails = $atts['_role_emails'] ?? array();
        $role_email = $role_emails[$role->id] ?? '';
        if (!empty($role_email)) {
            $output .= '<div class="pta-role-email"><a href="mailto:' . esc_attr($role_email) . '">' . esc_html($role_email) . '</a></div>';
        }
        
        if ($atts['show_count']) {
            $output .= '<div class="pta-role-count">' . $role->assigned_count . ' of ' . $role->max_occupants . ' filled</div>';
        }
        
        if ($atts['description'] && $role->description) {
            $output .= '<div class="pta-role-description">' . esc_html($role->description) . '</div>';
        }
        
        $output .= '<div class="pta-role-department">' . esc_html($role->department_name) . '</div>';
        $output .= '<div class="pta-role-status pta-status-' . esc_attr($status) . '">' . ucfirst($status) . '</div>';

        // Signup button (only when Forminator is configured and role has openings)
        $output .= $this->maybe_render_signup_button($role, $status);

        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Render team member style card (Team Members plugin inspired)
     */
    private function render_team_card($role, $atts, $status) {
        $data_attrs = ' data-role-id="' . esc_attr($role->id) . '"'
            . ' data-role-name="' . esc_attr($role->name) . '"'
            . ' data-department-name="' . esc_attr($role->department_name) . '"';
        $output = '<div class="pta-role-item pta-status-' . esc_attr($status) . '"' . $data_attrs . '>';
        
        // Get first assigned user for avatar (or show placeholder)
        $assigned_user = null;
        if (!empty($role->assignments)) {
            $assigned_user = get_user_by('ID', $role->assignments[0]->user_id);
        }
        
        // Avatar/Photo section
        if ($atts['show_avatars'] && $assigned_user) {
            $avatar_url = get_avatar_url($assigned_user->ID, array('size' => intval($atts['avatar_size'])));
            $output .= '<div class="pta-role-avatar" style="background-image: url(' . esc_url($avatar_url) . ');"></div>';
        } else {
            // Placeholder avatar with initials or icon
            $initials = $this->get_role_initials($role->name);
            $output .= '<div class="pta-role-avatar">' . esc_html($initials) . '</div>';
        }
        
        // Text content section
        $output .= '<div class="pta-role-textblock">';
        
        // Role name
        $output .= '<h4 class="pta-role-name">' . esc_html($role->name) . '</h4>';
        
        // Department
        $output .= '<div class="pta-role-department">' . esc_html($role->department_name) . '</div>';
        
        // Assignment count
        if ($atts['show_count']) {
            $output .= '<div class="pta-role-count">' . $role->assigned_count . ' of ' . $role->max_occupants . ' filled</div>';
        }
        
        // Description
        if ($atts['description'] && $role->description) {
            $output .= '<div class="pta-role-description">' . esc_html($role->description) . '</div>';
        }
        
        // Contact links section
        if ($atts['show_contact'] && $assigned_user) {
            $output .= '<div class="pta-role-contacts">';
            
            // Prefer role-level O365 email, then department email, then user email
            $role_emails = $atts['_role_emails'] ?? array();
            $dept_emails = $atts['_dept_emails'] ?? array();
            $contact_email = $role_emails[$role->id] ?? $dept_emails[$role->department_id] ?? $assigned_user->user_email;
            if ($contact_email) {
                $output .= '<a href="mailto:' . esc_attr($contact_email) . '" class="pta-role-contact-link" title="Email ' . esc_attr($contact_email) . '">@</a>';
            }
            
            // Phone link (if available in user meta)
            $phone = get_user_meta($assigned_user->ID, 'phone', true);
            if ($phone) {
                $output .= '<a href="tel:' . esc_attr($phone) . '" class="pta-role-contact-link" title="Call ' . esc_attr($assigned_user->display_name) . '">📞</a>';
            }
            
            $output .= '</div>';
        }
        
        $output .= '</div>'; // Close text block
        
        // Status badge
        $output .= '<div class="pta-role-status pta-status-' . esc_attr($status) . '">' . ucfirst($status) . '</div>';

        // Signup button
        $output .= $this->maybe_render_signup_button($role, $status);
        
        $output .= '</div>'; // Close role item
        
        return $output;
    }

    /**
     * Conditionally render a signup button for a role card.
     */
    private function maybe_render_signup_button($role, $status) {
        if (!class_exists('Azure_PTA_Forminator') || !Azure_PTA_Forminator::is_configured()) {
            return '';
        }

        $open_only = Azure_Settings::get_setting('pta_forminator_open_roles_only', true);
        if ($open_only && $status === 'filled') {
            return '';
        }

        return '<button type="button" class="pta-signup-btn" '
            . 'data-role-id="' . esc_attr($role->id) . '" '
            . 'data-role-name="' . esc_attr($role->name) . '" '
            . 'data-department-name="' . esc_attr($role->department_name) . '">'
            . esc_html__('Sign Up', 'azure-plugin')
            . '</button>';
    }
    
    /**
     * Generate initials from role name for placeholder avatars
     */
    private function get_role_initials($name) {
        $words = explode(' ', trim($name));
        if (count($words) >= 2) {
            return strtoupper(substr($words[0], 0, 1) . substr($words[1], 0, 1));
        } else {
            return strtoupper(substr($name, 0, 2));
        }
    }
    
    private function render_role_card($role, $atts) {
        $status = $this->get_role_status($role);
        $include_photo = filter_var($atts['include_photo'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $photo_size = intval($atts['photo_size'] ?? 80);
        
        $output = '<div class="pta-role-card pta-status-' . esc_attr($status) . '">';
        
        // Show photo of first assigned user if include_photo is true
        if ($include_photo && !empty($role->assignments)) {
            $first_user = get_user_by('ID', $role->assignments[0]->user_id);
            if ($first_user) {
                $output .= '<div class="pta-role-photo">';
                $output .= get_avatar($first_user->ID, $photo_size, '', $first_user->display_name, array('class' => 'pta-user-avatar'));
                $output .= '</div>';
            }
        }
        
        $output .= '<h3 class="pta-role-title">' . esc_html($role->name) . '</h3>';
        $output .= '<div class="pta-role-department">' . esc_html($role->department_name) . '</div>';
        
        if ($atts['show_description'] && $role->description) {
            $output .= '<div class="pta-role-description">' . esc_html($role->description) . '</div>';
        }
        
        $output .= '<div class="pta-role-count">' . $role->assigned_count . ' of ' . $role->max_occupants . ' positions filled</div>';
        
        if ($atts['show_assignments'] && !empty($role->assignments)) {
            $output .= '<div class="pta-role-assignments">';
            $output .= '<h5>Current Assignments:</h5>';
            $output .= '<ul class="pta-assignments-list' . ($include_photo ? ' with-photos' : '') . '">';
            foreach ($role->assignments as $assignment) {
                $user = get_user_by('ID', $assignment->user_id);
                if ($user) {
                    $output .= '<li class="pta-assignment-item">';
                    if ($include_photo) {
                        $output .= '<span class="pta-assignment-photo">' . get_avatar($user->ID, 32, '', $user->display_name) . '</span>';
                    }
                    $output .= '<span class="pta-assignment-name">' . esc_html($user->display_name) . '</span>';
                    if ($atts['show_contact'] && $user->user_email) {
                        $output .= ' <span class="pta-assignment-email">- <a href="mailto:' . esc_attr($user->user_email) . '">' . esc_html($user->user_email) . '</a></span>';
                    }
                    $output .= '</li>';
                }
            }
            $output .= '</ul>';
            $output .= '</div>';
        }
        
        $output .= '</div>';
        return $output;
    }
    
    private function get_role_status($role) {
        if ($role->assigned_count >= $role->max_occupants) {
            return 'filled';
        } elseif ($role->assigned_count > 0) {
            return 'partial';
        } else {
            return 'open';
        }
    }
    
    private function get_org_chart_data($department_filter) {
        $departments = $this->pta_manager->get_departments(true);
        $roles = $this->pta_manager->get_roles(null, true);

        // Sort departments so "Exec Board" appears first
        usort($departments, function($a, $b) {
            $a_exec = (strtolower($a->name) === 'exec board') ? 0 : 1;
            $b_exec = (strtolower($b->name) === 'exec board') ? 0 : 1;
            if ($a_exec !== $b_exec) return $a_exec - $b_exec;
            return strcmp($a->name, $b->name);
        });

        // Pre-load all department group mappings for email display
        $dept_emails = $this->get_department_group_emails(wp_list_pluck($departments, 'id'));
        
        $org_data = array(
            'departments' => array(),
            'roles' => array(),
            'assignments' => array()
        );
        
        foreach ($departments as $dept) {
            if ($department_filter !== 'all' && strtolower($dept->slug) !== strtolower($department_filter)) {
                continue;
            }
            
            $vp_name = '';
            if ($dept->vp_user_id) {
                $vp_user = get_user_by('ID', $dept->vp_user_id);
                if ($vp_user) {
                    $vp_name = $vp_user->display_name;
                }
            }
            
            $org_data['departments'][] = array(
                'id' => $dept->id,
                'name' => $dept->name,
                'vp' => $vp_name,
                'email' => $dept_emails[$dept->id] ?? ''
            );
        }
        
        foreach ($roles as $role) {
            if ($department_filter !== 'all') {
                $dept_match = false;
                foreach ($departments as $dept) {
                    if ($dept->id == $role->department_id && strtolower($dept->slug) === strtolower($department_filter)) {
                        $dept_match = true;
                        break;
                    }
                }
                if (!$dept_match) continue;
            }
            
            $org_data['roles'][] = array(
                'id' => $role->id,
                'name' => $role->name,
                'department_id' => $role->department_id,
                'max_occupants' => $role->max_occupants,
                'assigned_count' => $role->assigned_count
            );
            
            if (!empty($role->assignments)) {
                foreach ($role->assignments as $assignment) {
                    $user = get_user_by('ID', $assignment->user_id);
                    if ($user) {
                        $org_data['assignments'][] = array(
                            'role_id' => $role->id,
                            'user_name' => $user->display_name,
                            'user_email' => $user->user_email
                        );
                    }
                }
            }
        }
        
        return $org_data;
    }
    
    /**
     * Render a simple list of roles
     */
    private function render_roles_list($roles, $atts) {
        $output = '<ul class="pta-roles-list">';
        
        foreach ($roles as $role) {
            $status = $this->get_role_status($role);
            $output .= '<li class="pta-role-list-item pta-status-' . esc_attr($status) . '">';
            $output .= '<strong>' . esc_html($role->name) . '</strong>';
            $output .= ' <span class="pta-role-count">(' . $role->assigned_count . '/' . $role->max_occupants . ')</span>';
            
            if (!empty($atts['show_description']) && $role->description) {
                $output .= '<div class="pta-role-description">' . esc_html($role->description) . '</div>';
            }
            
            // Show assigned users
            if (!empty($role->assignments)) {
                $output .= '<ul class="pta-role-assignments">';
                foreach ($role->assignments as $assignment) {
                    $user = get_user_by('ID', $assignment->user_id);
                    if ($user) {
                        $output .= '<li>' . esc_html($user->display_name) . '</li>';
                    }
                }
                $output .= '</ul>';
            }
            
            $output .= '</li>';
        }
        
        $output .= '</ul>';
        return $output;
    }
    
    private function render_open_positions($roles, $atts) {
        $output = '<div class="pta-open-positions">';
        $output .= '<h3>Open Positions</h3>';
        $output .= '<ul class="pta-positions-list">';
        
        foreach ($roles as $role) {
            $open_count = $role->max_occupants - $role->assigned_count;
            $output .= '<li class="pta-position-item">';
            $output .= '<strong>' . esc_html($role->name) . '</strong>';
            
            if ($atts['show_department']) {
                $output .= ' <span class="pta-department">(' . esc_html($role->department_name) . ')</span>';
            }
            
            $output .= ' <span class="pta-open-count">' . $open_count . ' opening' . ($open_count > 1 ? 's' : '') . '</span>';
            
            if ($atts['show_description'] && $role->description) {
                $output .= '<div class="pta-position-description">' . esc_html($role->description) . '</div>';
            }
            
            $output .= '</li>';
        }
        
        $output .= '</ul>';
        $output .= '</div>';
        return $output;
    }
    
    private function render_user_roles($assignments, $atts) {
        $output = '<div class="pta-user-roles">';
        $output .= '<ul class="pta-assignments-list">';
        
        foreach ($assignments as $assignment) {
            $role = $this->pta_manager->get_role($assignment->role_id);
            if ($role) {
                $output .= '<li class="pta-assignment-item">';
                $output .= '<strong>' . esc_html($role->name) . '</strong>';
                
                if ($atts['show_department']) {
                    $output .= ' <span class="pta-department">(' . esc_html($role->department_name) . ')</span>';
                }
                
                if ($atts['show_description'] && $role->description) {
                    $output .= '<div class="pta-assignment-description">' . esc_html($role->description) . '</div>';
                }
                
                $output .= '</li>';
            }
        }
        
        $output .= '</ul>';
        $output .= '</div>';
        return $output;
    }

    /**
     * Batch-load O365 group emails for an array of department IDs.
     * Returns [ dept_id => email_string, ... ]
     */
    private function get_department_group_emails($dept_ids) {
        $emails = array();

        if (empty($dept_ids) || !class_exists('Azure_PTA_Groups_Manager')) {
            return $emails;
        }

        $groups_manager = new Azure_PTA_Groups_Manager();
        $all_mappings = $groups_manager->get_group_mappings('department');

        foreach ($all_mappings as $mapping) {
            if (in_array($mapping->target_id, $dept_ids) && !empty($mapping->mail)) {
                $emails[$mapping->target_id] = $mapping->mail;
            }
        }

        return $emails;
    }

    /**
     * Batch-load O365 group emails for an array of role IDs.
     * Returns [ role_id => email_string, ... ]
     */
    private function get_role_group_emails($role_ids) {
        $emails = array();

        if (empty($role_ids) || !class_exists('Azure_PTA_Groups_Manager')) {
            return $emails;
        }

        $groups_manager = new Azure_PTA_Groups_Manager();
        $all_mappings = $groups_manager->get_group_mappings('role');

        foreach ($all_mappings as $mapping) {
            if (in_array($mapping->target_id, $role_ids) && !empty($mapping->mail)) {
                $emails[$mapping->target_id] = $mapping->mail;
            }
        }

        return $emails;
    }

    /**
     * Build a per-role email map for leadership structure display.
     * Priority: role-level O365 mapping → VP's department O365 mapping → own department mapping.
     */
    private function get_leadership_role_emails($roles, $dept_emails) {
        $role_emails = array();

        $role_ids = wp_list_pluck($roles, 'id');
        $direct_role_emails = $this->get_role_group_emails($role_ids);

        $all_departments = $this->pta_manager->get_departments(false);
        $vp_to_dept = array();
        foreach ($all_departments as $dept) {
            if (!empty($dept->vp_user_id)) {
                $vp_to_dept[$dept->vp_user_id] = $dept->id;
            }
        }

        foreach ($roles as $role) {
            if (!empty($direct_role_emails[$role->id])) {
                $role_emails[$role->id] = $direct_role_emails[$role->id];
                continue;
            }

            if (!empty($role->assignments)) {
                $assigned_user_id = $role->assignments[0]->user_id;
                if (isset($vp_to_dept[$assigned_user_id])) {
                    $vp_dept_id = $vp_to_dept[$assigned_user_id];
                    if (!empty($dept_emails[$vp_dept_id])) {
                        $role_emails[$role->id] = $dept_emails[$vp_dept_id];
                        continue;
                    }
                }
            }

            if (!empty($dept_emails[$role->department_id])) {
                $role_emails[$role->id] = $dept_emails[$role->department_id];
            }
        }

        return $role_emails;
    }
}
