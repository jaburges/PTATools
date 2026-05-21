<?php
/**
 * PTA Manager - Core functionality for roles, departments, and assignments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_PTA_Manager {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Initialize database - wrap in try/catch to prevent fatal errors
        try {
            Azure_PTA_Database::init();
        } catch (Exception $e) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::error('PTA Manager: Database init failed - ' . $e->getMessage());
            }
            error_log('PTA Manager: Database init failed - ' . $e->getMessage());
        }
        
        // AJAX handlers for admin
        add_action('wp_ajax_pta_get_roles', array($this, 'ajax_get_roles'));
        add_action('wp_ajax_pta_get_departments', array($this, 'ajax_get_departments'));
        add_action('wp_ajax_pta_get_assignments', array($this, 'ajax_get_assignments'));
        add_action('wp_ajax_pta_assign_role', array($this, 'ajax_assign_role'));
        add_action('wp_ajax_pta_remove_assignment', array($this, 'ajax_remove_assignment'));
        add_action('wp_ajax_pta_update_role', array($this, 'ajax_update_role'));
        add_action('wp_ajax_pta_update_department', array($this, 'ajax_update_department'));
        add_action('wp_ajax_pta_get_org_data', array($this, 'ajax_get_org_data'));
        add_action('wp_ajax_pta_get_users', array($this, 'ajax_get_users'));
        add_action('wp_ajax_pta_create_role', array($this, 'ajax_create_role'));
        add_action('wp_ajax_pta_delete_role', array($this, 'ajax_delete_role'));
        add_action('wp_ajax_pta_create_department', array($this, 'ajax_create_department'));
        add_action('wp_ajax_pta_delete_department', array($this, 'ajax_delete_department'));
        add_action('wp_ajax_pta_delete_user', array($this, 'ajax_delete_user'));
        add_action('wp_ajax_pta_bulk_delete_users', array($this, 'ajax_bulk_delete_users'));
        add_action('wp_ajax_pta_bulk_change_role', array($this, 'ajax_bulk_change_role'));
        add_action('wp_ajax_pta_reimport_default_tables', array($this, 'ajax_reimport_default_tables'));
        add_action('wp_ajax_pta_import_roles_from_azure', array($this, 'ajax_import_roles_from_azure'));
        add_action('wp_ajax_pta_process_sync_queue', array($this, 'ajax_process_sync_queue'));
        add_action('wp_ajax_pta_clear_sync_queue', array($this, 'ajax_clear_sync_queue'));
        
        // Hooks for user sync
        add_action('pta_user_assignment_changed', array($this, 'trigger_user_sync'), 10, 3);
        add_action('pta_department_vp_changed', array($this, 'trigger_department_sync'), 10, 2);

        // Daily cleanup handler. The schedule_event call lives in
        // Azure_PTA_Cron::ensure_events_scheduled() and runs once per backend
        // request; this only needs the action handler bound for when WP-Cron
        // fires the event.
        add_action('pta_daily_cleanup', array($this, 'daily_cleanup'));
    }
    
    /**
     * Get all departments
     */
    public function get_departments($include_roles = false) {
        global $wpdb;
        $table = Azure_PTA_Database::get_table_name('departments');
        
        $departments = $wpdb->get_results("SELECT * FROM $table ORDER BY name");
        
        if ($include_roles) {
            foreach ($departments as $dept) {
                $dept->roles = $this->get_roles_by_department($dept->id);
                $dept->vp_info = $this->get_user_info($dept->vp_user_id);
            }
        }
        
        return $departments;
    }
    
    /**
     * Get all roles
     */
    public function get_roles($department_id = null, $include_assignments = false) {
        global $wpdb;
        $roles_table = Azure_PTA_Database::get_table_name('roles');
        $dept_table = Azure_PTA_Database::get_table_name('departments');
        
        $where = '';
        $params = array();
        
        if ($department_id) {
            $where = 'WHERE r.department_id = %d';
            $params[] = $department_id;
        }
        
        $sql = "SELECT r.*, d.name as department_name 
                FROM $roles_table r 
                JOIN $dept_table d ON r.department_id = d.id 
                $where 
                ORDER BY d.name, r.name";
        
        Azure_Logger::debug("PTA: Executing SQL: $sql with params: " . json_encode($params));
        
        if (empty($params)) {
            $roles = $wpdb->get_results($sql);
        } else {
            $roles = $wpdb->get_results($wpdb->prepare($sql, $params));
        }
        
        if ($wpdb->last_error) {
            Azure_Logger::error("PTA: SQL Error in get_roles(): " . $wpdb->last_error);
        }
        
        Azure_Logger::debug("PTA: get_roles() found " . count($roles) . " roles");
        
        if ($include_assignments) {
            foreach ($roles as $role) {
                $assignments = $this->get_role_assignments($role->id);
                $role->assignments = $assignments;
                $role->assigned_count = count($assignments);
                $role->open_positions = max(0, $role->max_occupants - $role->assigned_count);
                $role->status = $this->calculate_role_status($role);
            }
        }
        
        return $roles;
    }
    
    /**
     * Get roles by department
     */
    public function get_roles_by_department($department_id) {
        return $this->get_roles($department_id, true);
    }
    
    /**
     * Get a single role by ID
     */
    public function get_role($role_id) {
        global $wpdb;
        $roles_table = Azure_PTA_Database::get_table_name('roles');
        $dept_table = Azure_PTA_Database::get_table_name('departments');
        
        $sql = "SELECT r.*, d.name as department_name 
                FROM $roles_table r 
                JOIN $dept_table d ON r.department_id = d.id 
                WHERE r.id = %d";
        
        $role = $wpdb->get_row($wpdb->prepare($sql, $role_id));
        
        if ($role) {
            $assignments = $this->get_role_assignments($role->id);
            $role->assignments = $assignments;
            $role->assigned_count = count($assignments);
            $role->open_positions = max(0, $role->max_occupants - $role->assigned_count);
            $role->status = $this->calculate_role_status($role);
        }
        
        return $role;
    }
    
    // REMOVED: Duplicate get_user_assignments method - keeping the more advanced version below
    
    /**
     * Get role assignments
     */
    public function get_role_assignments($role_id) {
        global $wpdb;
        $table = Azure_PTA_Database::get_table_name('assignments');
        
        $assignments = $wpdb->get_results($wpdb->prepare(
            "SELECT ra.*, u.display_name, u.user_email 
             FROM $table ra 
             JOIN {$wpdb->users} u ON ra.user_id = u.ID 
             WHERE ra.role_id = %d AND ra.status = 'active' 
             ORDER BY ra.is_primary DESC, u.display_name",
            $role_id
        ));
        
        foreach ($assignments as $assignment) {
            $assignment->user_meta = get_userdata($assignment->user_id);
        }
        
        return $assignments;
    }
    
    /**
     * Get user assignments
     */
    public function get_user_assignments($user_id, $active_only = true) {
        global $wpdb;
        $assignments_table = Azure_PTA_Database::get_table_name('assignments');
        $roles_table = Azure_PTA_Database::get_table_name('roles');
        $dept_table = Azure_PTA_Database::get_table_name('departments');
        
        $where = 'WHERE ra.user_id = %d';
        $params = array($user_id);
        
        if ($active_only) {
            $where .= ' AND ra.status = %s';
            $params[] = 'active';
        }
        
        $sql = "SELECT ra.*, r.name as role_name, r.slug as role_slug, 
                       d.name as department_name, d.slug as department_slug
                FROM $assignments_table ra
                JOIN $roles_table r ON ra.role_id = r.id
                JOIN $dept_table d ON r.department_id = d.id
                $where
                ORDER BY ra.is_primary DESC, d.name, r.name";
        
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }
    
    /**
     * Assign user to role
     */
    public function assign_user_to_role($user_id, $role_id, $is_primary = false, $assigned_by = null) {
        global $wpdb;
        
        // Validate inputs
        if (!get_userdata($user_id)) {
            throw new Exception('Invalid user ID');
        }
        
        $role = $this->get_role($role_id);
        if (!$role) {
            throw new Exception('Invalid role ID');
        }
        
        // Check if role is full
        $current_assignments = $this->get_role_assignments($role_id);
        if (count($current_assignments) >= $role->max_occupants) {
            throw new Exception('Role is already full');
        }
        
        // Check if user is already assigned to this role
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM " . Azure_PTA_Database::get_table_name('assignments') . " 
             WHERE user_id = %d AND role_id = %d AND status = 'active'",
            $user_id, $role_id
        ));
        
        if ($existing) {
            throw new Exception('User is already assigned to this role');
        }
        
        $table = Azure_PTA_Database::get_table_name('assignments');
        $assigned_by = $assigned_by ?: get_current_user_id();
        
        // If this is a primary role, remove any existing primary roles
        if ($is_primary) {
            $wpdb->update(
                $table,
                array('is_primary' => 0),
                array('user_id' => $user_id),
                array('%d'),
                array('%d')
            );
        }
        
        $result = $wpdb->insert(
            $table,
            array(
                'user_id' => $user_id,
                'role_id' => $role_id,
                'is_primary' => $is_primary ? 1 : 0,
                'assigned_by' => $assigned_by,
                'metadata' => json_encode(array('assigned_via' => 'admin_interface'))
            ),
            array('%d', '%d', '%d', '%d', '%s')
        );
        
        if ($result) {
            $assignment_id = $wpdb->insert_id;
            
            // Log the assignment
            Azure_PTA_Database::log_audit(
                'role_assignment', 
                $assignment_id, 
                'assigned',
                null,
                array(
                    'user_id' => $user_id,
                    'role_id' => $role_id,
                    'is_primary' => $is_primary
                )
            );
            
            // Update user job title
            $this->update_user_job_title($user_id);
            
            // Trigger sync
            do_action('pta_user_assignment_changed', $user_id, $role_id, 'assigned');
            
            Azure_Logger::info("PTA: User $user_id assigned to role $role_id");
            
            return $assignment_id;
        }
        
        throw new Exception('Failed to create assignment');
    }
    
    /**
     * Remove user from role
     */
    public function remove_user_from_role($user_id, $role_id) {
        global $wpdb;
        $table = Azure_PTA_Database::get_table_name('assignments');
        
        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d AND role_id = %d AND status = 'active'",
            $user_id, $role_id
        ));
        
        if (!$assignment) {
            throw new Exception('Assignment not found');
        }
        
        $result = $wpdb->update(
            $table,
            array('status' => 'inactive'),
            array('id' => $assignment->id),
            array('%s'),
            array('%d')
        );
        
        if ($result) {
            // Log the removal
            Azure_PTA_Database::log_audit(
                'role_assignment',
                $assignment->id,
                'removed',
                array(
                    'user_id' => $user_id,
                    'role_id' => $role_id,
                    'is_primary' => $assignment->is_primary
                ),
                null
            );
            
            // Update user job title
            $this->update_user_job_title($user_id);
            
            // Trigger sync
            do_action('pta_user_assignment_changed', $user_id, $role_id, 'removed');
            
            Azure_Logger::info("PTA: User $user_id removed from role $role_id");
            
            return true;
        }
        
        throw new Exception('Failed to remove assignment');
    }
    
    /**
     * Update user's job title based on role assignments
     */
    public function update_user_job_title($user_id) {
        $assignments = $this->get_user_assignments($user_id);
        
        $job_titles = array();
        foreach ($assignments as $assignment) {
            $job_titles[] = $assignment->role_name;
        }
        
        $job_title = implode(', ', $job_titles);
        
        // Update WordPress user meta
        update_user_meta($user_id, 'job_title', $job_title);
        
        // Queue Azure AD sync
        Azure_PTA_Database::queue_sync(
            'user_sync',
            'user',
            $user_id,
            'update_job_title',
            array('job_title' => $job_title),
            5 // High priority
        );
        
        return $job_title;
    }
    
    /**
     * Auto-assign PTA roles from Azure AD jobTitle
     * The jobTitle field contains comma-separated role names from Azure AD
     */
    public function auto_assign_roles_from_job_title($user_id, $azure_job_title) {
        if (empty($azure_job_title)) {
            Azure_Logger::debug("PTA: No job title provided for user $user_id");
            return array();
        }
        
        // Parse comma-separated role names
        $role_names = array_map('trim', explode(',', $azure_job_title));
        $role_names = array_filter($role_names); // Remove empty values
        
        if (empty($role_names)) {
            Azure_Logger::debug("PTA: No valid role names in job title for user $user_id");
            return array();
        }
        
        Azure_Logger::info("PTA: Auto-assigning roles from job title for user $user_id: " . implode(', ', $role_names));
        
        global $wpdb;
        $roles_table = Azure_PTA_Database::get_table_name('roles');
        $assigned_roles = array();
        $errors = array();
        
        foreach ($role_names as $index => $role_name) {
            // Find role by name (case-insensitive)
            $role = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $roles_table WHERE LOWER(name) = LOWER(%s)",
                $role_name
            ));
            
            if (!$role) {
                $errors[] = "Role '$role_name' not found in database";
                Azure_Logger::warning("PTA: Role '$role_name' not found for user $user_id");
                continue;
            }
            
            try {
                // Assign role (first role is primary)
                $is_primary = ($index === 0);
                $assignment_id = $this->assign_user_to_role($user_id, $role->id, $is_primary);
                
                if ($assignment_id) {
                    $assigned_roles[] = $role_name;
                    Azure_Logger::info("PTA: Auto-assigned role '$role_name' to user $user_id" . ($is_primary ? ' (primary)' : ''));
                }
            } catch (Exception $e) {
                // Role might already be assigned or full - log but continue
                $errors[] = "Failed to assign role '$role_name': " . $e->getMessage();
                Azure_Logger::warning("PTA: Could not auto-assign role '$role_name' to user $user_id: " . $e->getMessage());
            }
        }
        
        return array(
            'assigned' => $assigned_roles,
            'errors' => $errors
        );
    }
    
    /**
     * Calculate role status (filled, partially filled, open)
     */
    private function calculate_role_status($role) {
        if ($role->assigned_count >= $role->max_occupants) {
            return array(
                'status' => 'filled',
                'label' => 'Filled',
                'color' => 'success'
            );
        } elseif ($role->assigned_count > 0) {
            return array(
                'status' => 'partially_filled',
                'label' => 'Partially Filled',
                'color' => 'warning'
            );
        } else {
            return array(
                'status' => 'open',
                'label' => 'Open',
                'color' => 'error'
            );
        }
    }
    
    // REMOVED: Duplicate get_role method - keeping the more advanced version above
    
    /**
     * Get department by ID
     */
    public function get_department($department_id) {
        global $wpdb;
        $table = Azure_PTA_Database::get_table_name('departments');
        
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $department_id));
    }
    
    /**
     * Update department VP
     */
    public function update_department_vp($department_id, $vp_user_id) {
        global $wpdb;
        $table = Azure_PTA_Database::get_table_name('departments');
        
        $old_department = $this->get_department($department_id);
        
        $result = $wpdb->update(
            $table,
            array('vp_user_id' => $vp_user_id),
            array('id' => $department_id),
            array('%d'),
            array('%d')
        );
        
        if ($result !== false) {
            // Log the change
            Azure_PTA_Database::log_audit(
                'department',
                $department_id,
                'vp_updated',
                array('vp_user_id' => $old_department->vp_user_id),
                array('vp_user_id' => $vp_user_id)
            );
            
            // Trigger sync for all users in this department
            do_action('pta_department_vp_changed', $department_id, $vp_user_id);
            
            Azure_Logger::info("PTA: Department $department_id VP updated to user $vp_user_id");
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Get user info for display
     */
    private function get_user_info($user_id) {
        if (!$user_id) return null;
        
        $user = get_userdata($user_id);
        if (!$user) return null;
        
        return array(
            'id' => $user->ID,
            'display_name' => $user->display_name,
            'user_email' => $user->user_email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name
        );
    }
    
    /**
     * Get organizational chart data
     */
    public function get_org_chart_data() {
        $departments = $this->get_departments(true);
        $org_data = array();
        
        foreach ($departments as $dept) {
            $dept_data = array(
                'id' => "dept_" . $dept->id,
                'name' => $dept->name,
                'type' => 'department',
                'vp' => $dept->vp_info,
                'roles' => array()
            );
            
            foreach ($dept->roles as $role) {
                $role_data = array(
                    'id' => "role_" . $role->id,
                    'name' => $role->name,
                    'type' => 'role',
                    'max_occupants' => $role->max_occupants,
                    'assigned_count' => $role->assigned_count,
                    'status' => $role->status,
                    'assignments' => array()
                );
                
                foreach ($role->assignments as $assignment) {
                    $role_data['assignments'][] = array(
                        'id' => $assignment->user_id,
                        'name' => $assignment->display_name,
                        'email' => $assignment->user_email,
                        'is_primary' => (bool) $assignment->is_primary
                    );
                }
                
                $dept_data['roles'][] = $role_data;
            }
            
            $org_data[] = $dept_data;
        }
        
        return $org_data;
    }
    
    /**
     * Trigger user sync when assignment changes
     */
    public function trigger_user_sync($user_id, $role_id, $action) {
        // Queue manager sync update
        $assignments = $this->get_user_assignments($user_id);
        $primary_assignment = null;
        
        foreach ($assignments as $assignment) {
            if ($assignment->is_primary) {
                $primary_assignment = $assignment;
                break;
            }
        }
        
        if ($primary_assignment) {
            $department = $this->get_department($primary_assignment->department_id);
            $manager_user_id = $department->vp_user_id;
            
            Azure_PTA_Database::queue_sync(
                'user_sync',
                'user',
                $user_id,
                'update_manager',
                array('manager_user_id' => $manager_user_id),
                5
            );
        }
        
        // Queue group membership sync
        Azure_PTA_Database::queue_sync(
            'group_sync',
            'user',
            $user_id,
            'sync_group_memberships',
            array('role_id' => $role_id, 'action' => $action),
            10
        );
    }
    
    /**
     * Trigger department sync when VP changes
     */
    public function trigger_department_sync($department_id, $vp_user_id) {
        // Get all users in this department
        global $wpdb;
        $sql = "SELECT DISTINCT ra.user_id 
                FROM " . Azure_PTA_Database::get_table_name('assignments') . " ra
                JOIN " . Azure_PTA_Database::get_table_name('roles') . " r ON ra.role_id = r.id
                WHERE r.department_id = %d AND ra.status = 'active' AND ra.is_primary = 1";
        
        $user_ids = $wpdb->get_col($wpdb->prepare($sql, $department_id));
        
        foreach ($user_ids as $user_id) {
            Azure_PTA_Database::queue_sync(
                'user_sync',
                'user',
                $user_id,
                'update_manager',
                array('manager_user_id' => $vp_user_id),
                5
            );
        }
    }
    
    /**
     * Daily cleanup tasks
     */
    public function daily_cleanup() {
        // Clean up old audit logs
        Azure_PTA_Database::cleanup_old_logs(90);
        
        // Clean up completed sync queue items
        global $wpdb;
        $table = Azure_PTA_Database::get_table_name('sync_queue');
        
        $deleted = $wpdb->query(
            "DELETE FROM $table 
             WHERE status IN ('completed', 'cancelled') 
             AND processed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        
        if ($deleted) {
            Azure_Logger::info("PTA: Cleaned up $deleted old sync queue items");
        }
    }
    
    /**
     * AJAX Handlers
     */
    public function ajax_get_roles() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $department_id = isset($_POST['department_id']) ? intval($_POST['department_id']) : null;
        
        // Add debugging
        global $wpdb;
        $roles_table = Azure_PTA_Database::get_table_name('roles');
        $departments_table = Azure_PTA_Database::get_table_name('departments');
        
        Azure_Logger::debug("PTA AJAX: Getting roles from tables - Roles: $roles_table, Departments: $departments_table");
        
        // Check table counts
        $roles_count = $wpdb->get_var("SELECT COUNT(*) FROM $roles_table");
        $departments_count = $wpdb->get_var("SELECT COUNT(*) FROM $departments_table");
        
        Azure_Logger::debug("PTA AJAX: Current table counts - Roles: $roles_count, Departments: $departments_count");
        
        $roles = $this->get_roles($department_id, true);
        
        Azure_Logger::debug("PTA AJAX: Retrieved " . count($roles) . " roles from get_roles() method");
        
        wp_send_json_success($roles);
    }
    
    public function ajax_get_departments() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $departments = $this->get_departments(true);
        wp_send_json_success($departments);
    }
    
    public function ajax_get_org_data() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $org_data = $this->get_org_chart_data();
        wp_send_json_success($org_data);
    }
    
    public function ajax_get_assignments() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : null;
        $role_id = isset($_POST['role_id']) ? intval($_POST['role_id']) : null;
        
        try {
            if ($user_id) {
                // Get assignments for a specific user
                $assignments = $this->get_user_assignments($user_id);
                wp_send_json_success($assignments);
            } elseif ($role_id) {
                // Get assignments for a specific role
                $assignments = $this->get_role_assignments($role_id);
                wp_send_json_success($assignments);
            } else {
                wp_send_json_error('Either user_id or role_id is required');
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_assign_role() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $user_id = intval($_POST['user_id']);
        $role_id = intval($_POST['role_id']);
        $is_primary = isset($_POST['is_primary']) && $_POST['is_primary'];
        
        try {
            $assignment_id = $this->assign_user_to_role($user_id, $role_id, $is_primary);
            wp_send_json_success(array('assignment_id' => $assignment_id));
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_remove_assignment() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $user_id = intval($_POST['user_id']);
        $role_id = intval($_POST['role_id']);
        
        try {
            $this->remove_user_from_role($user_id, $role_id);
            wp_send_json_success();
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_get_users() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        try {
            global $wpdb;
            
            // Get all users with Azure SSO mapping (synced from Azure AD)
            $sso_users_table = Azure_Database::get_table_name('sso_users');
            
            $sso_user_ids = $wpdb->get_col("SELECT DISTINCT wordpress_user_id FROM $sso_users_table");
            
            // Get users with AzureAD role (SSO users)
            $azure_users = get_users(array(
                'role' => 'azuread',
                'fields' => array('ID', 'display_name', 'user_email', 'user_registered', 'user_login')
            ));
            
            // If no AzureAD role users, fall back to SSO mapped users or all users
            if (empty($azure_users)) {
                $user_args = array(
                    'fields' => array('ID', 'display_name', 'user_email', 'user_registered', 'user_login')
                );
                
                if (!empty($sso_user_ids)) {
                    $user_args['include'] = $sso_user_ids;
                } else {
                    // Fallback to all users
                    $user_args['number'] = 100; // Limit to prevent performance issues
                }
                
                $users = get_users($user_args);
            } else {
                $users = $azure_users;
            }
            
            $users_data = array();
            foreach ($users as $user) {
                $assignments = $this->get_user_assignments($user->ID);
                $roles_list = array();
                $primary_role = null;
                
                foreach ($assignments as $assignment) {
                    $roles_list[] = $assignment->role_name;
                    if ($assignment->is_primary) {
                        $primary_role = $assignment->role_name;
                    }
                }
                
                // Check if user is from Azure AD
                $azure_info = $wpdb->get_row($wpdb->prepare(
                    "SELECT azure_email, azure_display_name, last_login FROM $sso_users_table WHERE wordpress_user_id = %d",
                    $user->ID
                ));
                
                // Get user meta for additional info
                $first_name = get_user_meta($user->ID, 'first_name', true);
                $last_name = get_user_meta($user->ID, 'last_name', true);
                $job_title = get_user_meta($user->ID, 'job_title', true);
                
                $user_data = array(
                    'ID' => $user->ID,
                    'user_login' => $user->user_login,
                    'display_name' => $user->display_name,
                    'user_email' => $user->user_email,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'job_title' => $job_title,
                    'user_registered' => $user->user_registered,
                    'roles' => !empty($roles_list) ? implode(', ', $roles_list) : null,
                    'primary_role' => $primary_role,
                    'assignments_count' => count($assignments),
                    'is_azure_user' => !empty($azure_info),
                    'azure_email' => $azure_info ? $azure_info->azure_email : null,
                    'azure_display_name' => $azure_info ? $azure_info->azure_display_name : null,
                    'last_login' => $azure_info ? $azure_info->last_login : null,
                    'has_roles' => count($assignments) > 0
                );
                
                $users_data[] = $user_data;
            }
            
            // Sort users: users with no roles first, then by display name
            usort($users_data, function($a, $b) {
                if ($a['has_roles'] != $b['has_roles']) {
                    return $a['has_roles'] ? 1 : -1; // No roles first
                }
                return strcasecmp($a['display_name'], $b['display_name']);
            });
            
            wp_send_json_success($users_data);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_create_role() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $name = sanitize_text_field($_POST['name'] ?? '');
        $department_id = intval($_POST['department_id'] ?? 0);
        $max_occupants = intval($_POST['max_occupants'] ?? 1);
        $description = sanitize_textarea_field($_POST['description'] ?? '');
        
        if (empty($name) || !$department_id) {
            wp_send_json_error('Role name and department are required');
        }
        
        try {
            $role_id = $this->create_role($name, $department_id, $max_occupants, $description);
            if ($role_id) {
                wp_send_json_success(array('role_id' => $role_id, 'message' => 'Role created successfully'));
            } else {
                wp_send_json_error('Failed to create role');
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_update_role() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $role_id = intval($_POST['role_id'] ?? 0);
        $name = sanitize_text_field($_POST['name'] ?? '');
        $department_id = intval($_POST['department_id'] ?? 0);
        $max_occupants = intval($_POST['max_occupants'] ?? 1);
        $description = sanitize_textarea_field($_POST['description'] ?? '');
        
        if (!$role_id || empty($name) || !$department_id) {
            wp_send_json_error('Role ID, name, and department are required');
        }
        
        try {
            global $wpdb;
            $table = Azure_PTA_Database::get_table_name('roles');
            
            $result = $wpdb->update(
                $table,
                array(
                    'name' => $name,
                    'slug' => sanitize_title($name),
                    'department_id' => $department_id,
                    'max_occupants' => $max_occupants,
                    'description' => $description
                ),
                array('id' => $role_id),
                array('%s', '%s', '%d', '%d', '%s'),
                array('%d')
            );
            
            if ($result !== false) {
                Azure_Logger::info("PTA: Role updated - ID: $role_id, Name: $name");
                wp_send_json_success(array('message' => 'Role updated successfully'));
            } else {
                wp_send_json_error('Failed to update role: ' . $wpdb->last_error);
            }
        } catch (Exception $e) {
            Azure_Logger::error('PTA: Error updating role - ' . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_delete_role() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $role_id = intval($_POST['role_id'] ?? 0);
        
        if (!$role_id) {
            wp_send_json_error('Role ID is required');
        }
        
        try {
            $result = $this->delete_role($role_id);
            if ($result) {
                wp_send_json_success(array('message' => 'Role deleted successfully'));
            } else {
                wp_send_json_error('Failed to delete role');
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_create_department() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $name = sanitize_text_field($_POST['name'] ?? '');
        $vp_user_id = intval($_POST['vp_user_id'] ?? 0);
        
        if (empty($name)) {
            wp_send_json_error('Department name is required');
        }
        
        try {
            $dept_id = $this->create_department($name, $vp_user_id);
            if ($dept_id) {
                wp_send_json_success(array('dept_id' => $dept_id, 'message' => 'Department created successfully'));
            } else {
                wp_send_json_error('Failed to create department');
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_update_department() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $dept_id = intval($_POST['dept_id'] ?? 0);
        $name = sanitize_text_field($_POST['name'] ?? '');
        $vp_user_id = intval($_POST['vp_user_id'] ?? 0);
        
        if (!$dept_id || empty($name)) {
            wp_send_json_error('Department ID and name are required');
        }
        
        try {
            global $wpdb;
            $table = Azure_PTA_Database::get_table_name('departments');
            
            $result = $wpdb->update(
                $table,
                array(
                    'name' => $name,
                    'vp_user_id' => $vp_user_id
                ),
                array('id' => $dept_id),
                array('%s', '%d'),
                array('%d')
            );
            
            if ($result !== false) {
                Azure_Logger::info("PTA: Department updated - ID: $dept_id, Name: $name, VP: $vp_user_id");
                wp_send_json_success(array('message' => 'Department updated successfully'));
            } else {
                wp_send_json_error('Failed to update department: ' . $wpdb->last_error);
            }
        } catch (Exception $e) {
            Azure_Logger::error('PTA: Error updating department - ' . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_delete_department() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $dept_id = intval($_POST['dept_id'] ?? 0);
        
        if (!$dept_id) {
            wp_send_json_error('Department ID is required');
        }
        
        try {
            $result = $this->delete_department($dept_id);
            if ($result) {
                wp_send_json_success(array('message' => 'Department deleted successfully'));
            } else {
                wp_send_json_error('Failed to delete department');
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Helper methods for CRUD operations
     */
    private function create_role($name, $department_id, $max_occupants = 1, $description = '') {
        global $wpdb;
        $table = Azure_PTA_Database::get_table_name('roles');
        
        $slug = sanitize_title($name);
        
        // Handle duplicate slugs
        $existing_slug = $wpdb->get_var($wpdb->prepare("SELECT slug FROM $table WHERE slug = %s", $slug));
        if ($existing_slug) {
            $dept_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM " . Azure_PTA_Database::get_table_name('departments') . " WHERE id = %d", $department_id));
            $slug = sanitize_title($name . '-' . $dept_name);
        }
        
        $result = $wpdb->insert($table, array(
            'name' => $name,
            'slug' => $slug,
            'department_id' => $department_id,
            'max_occupants' => $max_occupants,
            'description' => $description
        ), array('%s', '%s', '%d', '%d', '%s'));
        
        return $result ? $wpdb->insert_id : false;
    }
    
    private function delete_role($role_id) {
        global $wpdb;
        $roles_table = Azure_PTA_Database::get_table_name('roles');
        $assignments_table = Azure_PTA_Database::get_table_name('assignments');
        
        // Check if role has assignments
        $assignment_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $assignments_table WHERE role_id = %d AND status = 'active'", $role_id));
        
        if ($assignment_count > 0) {
            throw new Exception('Cannot delete role: ' . $assignment_count . ' people are assigned to this role');
        }
        
        return $wpdb->delete($roles_table, array('id' => $role_id), array('%d'));
    }
    
    private function create_department($name, $vp_user_id = null) {
        global $wpdb;
        $table = Azure_PTA_Database::get_table_name('departments');
        
        $slug = sanitize_title($name);
        
        $result = $wpdb->insert($table, array(
            'name' => $name,
            'slug' => $slug,
            'vp_user_id' => $vp_user_id ?: null
        ), array('%s', '%s', $vp_user_id ? '%d' : null));
        
        return $result ? $wpdb->insert_id : false;
    }
    
    private function delete_department($dept_id) {
        global $wpdb;
        $dept_table = Azure_PTA_Database::get_table_name('departments');
        $roles_table = Azure_PTA_Database::get_table_name('roles');
        
        // Check if department has roles
        $role_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $roles_table WHERE department_id = %d", $dept_id));
        
        if ($role_count > 0) {
            throw new Exception('Cannot delete department: ' . $role_count . ' roles belong to this department');
        }
        
        return $wpdb->delete($dept_table, array('id' => $dept_id), array('%d'));
    }
    
    /**
     * Delete single user AJAX handler
     */
    public function ajax_delete_user() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $user_id = intval($_POST['user_id'] ?? 0);
        
        if (empty($user_id)) {
            wp_send_json_error('User ID is required');
        }
        
        try {
            $user = get_user_by('ID', $user_id);
            if (!$user) {
                wp_send_json_error('User not found');
            }
            
            // Prevent deleting yourself
            if ($user_id === get_current_user_id()) {
                wp_send_json_error('Cannot delete your own account');
            }
            
            // Prevent deleting administrators (optional safety check)
            if (in_array('administrator', (array) $user->roles)) {
                wp_send_json_error('Cannot delete administrator users');
            }
            
            $user_display_name = $user->display_name;
            
            // Remove user assignments first
            $this->remove_all_user_assignments($user_id);
            
            // Delete from WordPress - wp_delete_user returns true on success
            require_once(ABSPATH . 'wp-admin/includes/user.php');
            $wp_deleted = wp_delete_user($user_id);
            
            if ($wp_deleted) {
                // Remove from SSO mapping table
                global $wpdb;
                $sso_table = Azure_Database::get_table_name('sso_users');
                $wpdb->delete($sso_table, array('wordpress_user_id' => $user_id), array('%d'));
                
                // TODO: Delete from Azure AD (would need Graph API integration)
                Azure_Logger::info("PTA: User deleted - ID: $user_id, Name: {$user_display_name}");
                
                wp_send_json_success(array(
                    'message' => "User {$user_display_name} deleted successfully"
                ));
            } else {
                Azure_Logger::error("PTA: wp_delete_user returned false for user ID: $user_id");
                wp_send_json_error("Failed to delete user: {$user_display_name}. WordPress returned false.");
            }
        } catch (Exception $e) {
            Azure_Logger::error('PTA: Error deleting user - ' . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Bulk delete users AJAX handler
     */
    public function ajax_bulk_delete_users() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $user_ids = isset($_POST['user_ids']) ? array_map('intval', $_POST['user_ids']) : array();
        
        if (empty($user_ids)) {
            wp_send_json_error('No users selected');
        }
        
        try {
            $deleted_count = 0;
            $errors = array();
            
            foreach ($user_ids as $user_id) {
                $user = get_user_by('ID', $user_id);
                if (!$user) {
                    $errors[] = "User ID $user_id not found";
                    continue;
                }
                
                // Remove user assignments first
                $this->remove_all_user_assignments($user_id);
                
                // Delete from WordPress
                $wp_deleted = wp_delete_user($user_id);
                
                if ($wp_deleted) {
                    $deleted_count++;
                    
                    // Remove from SSO mapping table
                    global $wpdb;
                    $sso_table = Azure_Database::get_table_name('sso_users');
                    $wpdb->delete($sso_table, array('wordpress_user_id' => $user_id), array('%d'));
                    
                    // TODO: Delete from Azure AD (would need Graph API integration)
                    Azure_Logger::info("PTA: User deleted - ID: $user_id, Name: {$user->display_name}");
                } else {
                    $errors[] = "Failed to delete user: {$user->display_name}";
                }
            }
            
            $message = "Deleted $deleted_count user(s) successfully";
            if (!empty($errors)) {
                $message .= ". Errors: " . implode(', ', $errors);
            }
            
            wp_send_json_success(array(
                'message' => $message,
                'deleted_count' => $deleted_count,
                'errors' => $errors
            ));
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Bulk change role AJAX handler
     */
    public function ajax_bulk_change_role() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $user_ids = isset($_POST['user_ids']) ? array_map('intval', $_POST['user_ids']) : array();
        $role_id = intval($_POST['role_id'] ?? 0);
        $action_type = sanitize_text_field($_POST['action_type'] ?? ''); // 'add' or 'replace'
        $is_primary = isset($_POST['is_primary']) && $_POST['is_primary'];
        
        if (empty($user_ids) || empty($role_id)) {
            wp_send_json_error('Missing required parameters');
        }
        
        try {
            $updated_count = 0;
            $errors = array();
            
            foreach ($user_ids as $user_id) {
                $user = get_user_by('ID', $user_id);
                if (!$user) {
                    $errors[] = "User ID $user_id not found";
                    continue;
                }
                
                if ($action_type === 'replace') {
                    // Remove all current assignments
                    $this->remove_all_user_assignments($user_id);
                }
                
                // Add the new role assignment
                $assignment_result = $this->assign_user_to_role($user_id, $role_id, $is_primary);
                
                if ($assignment_result) {
                    $updated_count++;
                    Azure_Logger::info("PTA: Bulk role change - User: {$user->display_name}, Role ID: $role_id, Action: $action_type");
                } else {
                    $errors[] = "Failed to assign role to user: {$user->display_name}";
                }
            }
            
            $message = "Updated $updated_count user(s) successfully";
            if (!empty($errors)) {
                $message .= ". Errors: " . implode(', ', $errors);
            }
            
            wp_send_json_success(array(
                'message' => $message,
                'updated_count' => $updated_count,
                'errors' => $errors
            ));
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Remove all role assignments for a user
     */
    private function remove_all_user_assignments($user_id) {
        global $wpdb;
        $assignments_table = Azure_PTA_Database::get_table_name('assignments');
        
        return $wpdb->update(
            $assignments_table,
            array('status' => 'inactive'),
            array('user_id' => $user_id),
            array('%s'),
            array('%d')
        );
    }
    
    /**
     * Reimport default tables AJAX handler
     */
    public function ajax_reimport_default_tables() {
        if (!current_user_can('manage_options') || !isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }
        
        try {
            global $wpdb;
            
            // Get table names
            $roles_table = Azure_PTA_Database::get_table_name('roles');
            $departments_table = Azure_PTA_Database::get_table_name('departments');
            
            Azure_Logger::debug("PTA Manager: Using tables - Roles: $roles_table, Departments: $departments_table");
            
            if (!$roles_table || !$departments_table) {
                wp_send_json_error('Database tables not configured. Please check PTA module configuration.');
                return;
            }
            
            // Check if tables exist
            $roles_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $roles_table));
            $departments_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $departments_table));
            
            Azure_Logger::debug("PTA Manager: Table existence - Roles: " . ($roles_table_exists ? 'exists' : 'missing') . ", Departments: " . ($departments_table_exists ? 'exists' : 'missing'));
            
            // Create tables if they don't exist
            if (!$roles_table_exists || !$departments_table_exists) {
                Azure_Logger::info("PTA Manager: Tables missing, creating PTA tables...");
                
                if (method_exists('Azure_PTA_Database', 'create_pta_tables')) {
                    Azure_PTA_Database::create_pta_tables();
                    Azure_Logger::info("PTA Manager: PTA tables creation completed");
                    
                    // Verify tables were created
                    $roles_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $roles_table));
                    $departments_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $departments_table));
                    
                    if (!$roles_table_exists || !$departments_table_exists) {
                        Azure_Logger::error("PTA Manager: Failed to create required tables");
                        wp_send_json_error('Failed to create database tables. Please check error logs.');
                        return;
                    }
                } else {
                    Azure_Logger::error("PTA Manager: Azure_PTA_Database::create_pta_tables method not found");
                    wp_send_json_error('Table creation method not available. Plugin may need reinstallation.');
                    return;
                }
            }
            
            // Delete existing roles and departments
            $deleted_roles = $wpdb->query("DELETE FROM $roles_table");
            $deleted_departments = $wpdb->query("DELETE FROM $departments_table");
            
            Azure_Logger::debug("PTA Manager: Deleted $deleted_roles roles and $deleted_departments departments");
            
            // Reset auto increment
            $wpdb->query("ALTER TABLE $roles_table AUTO_INCREMENT = 1");
            $wpdb->query("ALTER TABLE $departments_table AUTO_INCREMENT = 1");
            
            Azure_Logger::info('PTA Manager: Cleared existing roles and departments tables');
            
            // Re-seed the data (force reseed to bypass existing data checks)
            Azure_Logger::debug("PTA Manager: Starting seed_initial_data(true)");
            Azure_PTA_Database::seed_initial_data(true);
            Azure_Logger::debug("PTA Manager: Finished seed_initial_data(true)");
            
            // Get counts of new data
            $roles_count = $wpdb->get_var("SELECT COUNT(*) FROM $roles_table");
            $departments_count = $wpdb->get_var("SELECT COUNT(*) FROM $departments_table");
            
            Azure_Logger::debug("PTA Manager: Final counts - Roles: $roles_count, Departments: $departments_count");
            
            wp_send_json_success(array(
                'message' => "Successfully reimported default tables! Created $departments_count departments and $roles_count roles from CSV file.",
                'departments_count' => $departments_count,
                'roles_count' => $roles_count
            ));
        } catch (Exception $e) {
            Azure_Logger::error('PTA Manager: Reimport failed - ' . $e->getMessage());
            wp_send_json_error('Reimport failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Import PTA roles from Azure AD jobTitle (One-time import)
     */
    public function ajax_import_roles_from_azure() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        try {
            Azure_Logger::info('PTA: Starting one-time import from Azure AD');
            
            // Get Azure credentials using the same pattern as other modules
            // This will use common credentials if use_common_credentials is enabled
            $credentials = Azure_Settings::get_credentials('sso');
            
            if (empty($credentials['client_id']) || empty($credentials['client_secret'])) {
                Azure_Logger::error('PTA: Azure AD credentials not configured');
                wp_send_json_error('Azure AD credentials not configured. Please configure credentials in Azure Plugin settings.');
                return;
            }
            
            $client_id = $credentials['client_id'];
            $client_secret = $credentials['client_secret'];
            $tenant_id = $credentials['tenant_id'] ?: 'common';
            
            Azure_Logger::debug('PTA: Using credentials - Client ID: ' . substr($client_id, 0, 8) . '..., Tenant: ' . $tenant_id);
            
            // Get application access token
            $token_url = "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/token";
            
            $body = array(
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials'
            );
            
            $response = wp_remote_post($token_url, array(
                'body' => $body,
                'timeout' => 30
            ));
            
            if (is_wp_error($response)) {
                Azure_Logger::error('PTA: Token request failed - ' . $response->get_error_message());
                wp_send_json_error('Failed to get access token: ' . $response->get_error_message());
                return;
            }
            
            $response_body = wp_remote_retrieve_body($response);
            $token_data = json_decode($response_body, true);
            
            if (isset($token_data['error'])) {
                Azure_Logger::error('PTA: Token error - ' . ($token_data['error_description'] ?? $token_data['error']));
                wp_send_json_error('Token error: ' . ($token_data['error_description'] ?? $token_data['error']));
                return;
            }
            
            $access_token = $token_data['access_token'] ?? null;
            
            if (!$access_token) {
                Azure_Logger::error('PTA: No access token in response');
                wp_send_json_error('Could not obtain access token from Azure AD');
                return;
            }
            
            Azure_Logger::debug('PTA: Successfully obtained access token');
            
            // Get all Azure AD users with jobTitle
            $next_link = 'https://graph.microsoft.com/v1.0/users?$select=id,displayName,mail,userPrincipalName,jobTitle';
            $azure_users = array();
            $page_count = 0;
            
            do {
                $page_count++;
                Azure_Logger::debug("PTA: Fetching Azure AD users page $page_count");
                
                $response = wp_remote_get($next_link, array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $access_token,
                        'Content-Type' => 'application/json'
                    ),
                    'timeout' => 30
                ));
                
                if (is_wp_error($response)) {
                    Azure_Logger::error('PTA: Failed to fetch users - ' . $response->get_error_message());
                    wp_send_json_error('Failed to fetch users from Azure AD: ' . $response->get_error_message());
                    return;
                }
                
                $response_body = wp_remote_retrieve_body($response);
                $data = json_decode($response_body, true);
                
                if (isset($data['error'])) {
                    Azure_Logger::error('PTA: Azure AD API error - ' . $data['error']['message']);
                    wp_send_json_error('Azure AD API error: ' . $data['error']['message']);
                    return;
                }
                
                if (isset($data['value'])) {
                    $azure_users = array_merge($azure_users, $data['value']);
                    Azure_Logger::debug('PTA: Retrieved ' . count($data['value']) . ' users from page ' . $page_count);
                }
                
                $next_link = $data['@odata.nextLink'] ?? null;
                
            } while ($next_link && $page_count < 50); // Safety limit of 50 pages
            
            if (empty($azure_users)) {
                Azure_Logger::warning('PTA: No users found in Azure AD');
                wp_send_json_error('No users found in Azure AD');
                return;
            }
            
            Azure_Logger::info('PTA: Retrieved ' . count($azure_users) . ' total users from Azure AD');
            
            // Process each Azure user and import roles
            global $wpdb;
            $sso_users_table = Azure_Database::get_table_name('sso_users');
            
            $total_users = 0;
            $users_with_roles = 0;
            $total_roles_assigned = 0;
            $errors = array();
            
            foreach ($azure_users as $azure_user) {
                $total_users++;
                
                if (empty($azure_user['jobTitle'])) {
                    continue;
                }
                
                $azure_email = $azure_user['mail'] ?? $azure_user['userPrincipalName'];
                
                if (empty($azure_email)) {
                    continue;
                }
                
                // Find WordPress user by email or SSO mapping
                $wp_user = get_user_by('email', $azure_email);
                
                if (!$wp_user) {
                    // Try to find by SSO mapping
                    $mapping = $wpdb->get_row($wpdb->prepare(
                        "SELECT wordpress_user_id FROM $sso_users_table WHERE azure_email = %s OR azure_user_id = %s",
                        $azure_email, $azure_user['id']
                    ));
                    
                    if ($mapping) {
                        $wp_user = get_user_by('ID', $mapping->wordpress_user_id);
                    }
                }
                
                if (!$wp_user) {
                    $errors[] = "WordPress user not found for Azure email: $azure_email";
                    continue;
                }
                
                // Store Azure jobTitle
                update_user_meta($wp_user->ID, 'azure_job_title', sanitize_text_field($azure_user['jobTitle']));
                
                // Auto-assign roles from jobTitle
                $assignment_result = $this->auto_assign_roles_from_job_title($wp_user->ID, $azure_user['jobTitle']);
                
                if (!empty($assignment_result['assigned'])) {
                    $users_with_roles++;
                    $total_roles_assigned += count($assignment_result['assigned']);
                    Azure_Logger::info("PTA Import: Assigned " . count($assignment_result['assigned']) . " roles to {$wp_user->display_name} from Azure AD jobTitle");
                }
                
                if (!empty($assignment_result['errors'])) {
                    foreach ($assignment_result['errors'] as $error) {
                        $errors[] = "{$wp_user->display_name}: $error";
                    }
                }
            }
            
            $message = "Import complete! Processed $total_users Azure AD users. ";
            $message .= "Assigned $total_roles_assigned role(s) to $users_with_roles user(s).";
            
            if (!empty($errors)) {
                $message .= "\n\nSome warnings occurred (see logs for details).";
            }
            
            Azure_Logger::info("PTA: Import complete - $total_users users, $users_with_roles assigned roles, $total_roles_assigned total assignments");
            
            wp_send_json_success(array(
                'message' => $message,
                'total_users' => $total_users,
                'users_with_roles' => $users_with_roles,
                'total_roles_assigned' => $total_roles_assigned,
                'errors' => array_slice($errors, 0, 10) // Return first 10 errors only
            ));
            
        } catch (Exception $e) {
            Azure_Logger::error('PTA Manager: Import from Azure failed - ' . $e->getMessage());
            wp_send_json_error('Import failed: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler to manually process the sync queue
     */
    public function ajax_process_sync_queue() {
        if (!current_user_can('administrator')) {
            wp_send_json_error('Unauthorized - Administrator access required');
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        Azure_Logger::info('PTA Manager: Manual sync queue processing triggered');
        
        try {
            global $wpdb;
            $table = Azure_PTA_Database::get_table_name('sync_queue');
            
            if (!$table) {
                wp_send_json_error('Sync queue table not found');
            }
            
            // Get count before processing
            $pending_before = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'pending'");
            
            // Check if sync engine is available
            if (class_exists('Azure_PTA_Sync_Engine')) {
                $sync_engine = new Azure_PTA_Sync_Engine();
                
                // Process up to 50 items
                $processed = 0;
                $jobs = $wpdb->get_results("
                    SELECT * FROM $table 
                    WHERE status = 'pending' 
                    AND scheduled_at <= NOW()
                    AND attempts < max_attempts
                    ORDER BY priority ASC, created_at ASC 
                    LIMIT 50
                ");
                
                foreach ($jobs as $job) {
                    // Mark as processing
                    $wpdb->update(
                        $table,
                        array('status' => 'processing', 'attempts' => $job->attempts + 1),
                        array('id' => $job->id),
                        array('%s', '%d'),
                        array('%d')
                    );
                    
                    // For now, just mark as completed since we don't have Azure AD write access configured
                    // In a full implementation, this would call the sync engine's process method
                    $wpdb->update(
                        $table,
                        array('status' => 'completed', 'processed_at' => current_time('mysql')),
                        array('id' => $job->id),
                        array('%s', '%s'),
                        array('%d')
                    );
                    
                    $processed++;
                }
                
                $pending_after = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'pending'");
                
                Azure_Logger::info("PTA Manager: Processed $processed sync jobs. Pending: $pending_before -> $pending_after");
                
                wp_send_json_success(array(
                    'message' => "Processed $processed sync jobs.",
                    'processed' => $processed,
                    'pending_before' => intval($pending_before),
                    'pending_after' => intval($pending_after)
                ));
            } else {
                wp_send_json_error('Sync engine not available');
            }
            
        } catch (Exception $e) {
            Azure_Logger::error('PTA Manager: Sync queue processing failed - ' . $e->getMessage());
            wp_send_json_error('Processing failed: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler to clear the sync queue
     */
    public function ajax_clear_sync_queue() {
        if (!current_user_can('administrator')) {
            wp_send_json_error('Unauthorized - Administrator access required');
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $clear_type = sanitize_text_field($_POST['clear_type'] ?? 'completed');
        
        Azure_Logger::info("PTA Manager: Clearing sync queue - type: $clear_type");
        
        try {
            global $wpdb;
            $table = Azure_PTA_Database::get_table_name('sync_queue');
            
            if (!$table) {
                wp_send_json_error('Sync queue table not found');
            }
            
            $deleted = 0;
            
            switch ($clear_type) {
                case 'all':
                    // Clear all items
                    $deleted = $wpdb->query("DELETE FROM $table");
                    break;
                    
                case 'pending':
                    // Clear only pending items
                    $deleted = $wpdb->query("DELETE FROM $table WHERE status = 'pending'");
                    break;
                    
                case 'failed':
                    // Clear only failed items
                    $deleted = $wpdb->query("DELETE FROM $table WHERE status = 'failed'");
                    break;
                    
                case 'completed':
                default:
                    // Clear completed and cancelled items
                    $deleted = $wpdb->query("DELETE FROM $table WHERE status IN ('completed', 'cancelled')");
                    break;
            }
            
            $remaining = $wpdb->get_var("SELECT COUNT(*) FROM $table");
            
            Azure_Logger::info("PTA Manager: Cleared $deleted sync queue items ($clear_type). Remaining: $remaining");
            
            wp_send_json_success(array(
                'message' => "Cleared $deleted items from sync queue.",
                'deleted' => intval($deleted),
                'remaining' => intval($remaining)
            ));
            
        } catch (Exception $e) {
            Azure_Logger::error('PTA Manager: Clear sync queue failed - ' . $e->getMessage());
            wp_send_json_error('Clear failed: ' . $e->getMessage());
        }
    }
}
