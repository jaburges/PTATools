<?php
/**
 * PTA Groups Manager - Handles Office 365 groups synchronization
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_PTA_Groups_Manager {
    
    private $credentials;
    private $sync_engine;
    
    public function __construct() {
        $this->credentials = Azure_Settings::get_credentials('pta'); // Reuse PTA/SSO credentials
        
        // AJAX handlers
        add_action('wp_ajax_pta_sync_o365_groups', array($this, 'ajax_sync_o365_groups'));
        add_action('wp_ajax_pta_get_o365_groups', array($this, 'ajax_get_o365_groups'));
        add_action('wp_ajax_pta_create_group_mapping', array($this, 'ajax_create_group_mapping'));
        add_action('wp_ajax_pta_delete_group_mapping', array($this, 'ajax_delete_group_mapping'));
        add_action('wp_ajax_pta_sync_user_group_memberships', array($this, 'ajax_sync_user_group_memberships'));
        add_action('wp_ajax_pta_test_group_access', array($this, 'ajax_test_group_access'));
        add_action('wp_ajax_pta_get_unmapped_groups', array($this, 'ajax_get_unmapped_groups'));
        add_action('wp_ajax_pta_get_department_group_mapping', array($this, 'ajax_get_department_group_mapping'));
        add_action('wp_ajax_pta_set_department_group', array($this, 'ajax_set_department_group'));
        add_action('wp_ajax_pta_get_role_group_mapping', array($this, 'ajax_get_role_group_mapping'));
        add_action('wp_ajax_pta_set_role_group', array($this, 'ajax_set_role_group'));
        
        // Scheduled sync handlers. The `six_hours` interval and event
        // scheduling are owned by Azure_PTA_Cron; only the action handlers
        // need to be bound here.
        add_action('pta_sync_o365_groups_scheduled', array($this, 'sync_all_o365_groups'));
        add_action('pta_sync_group_memberships_scheduled', array($this, 'sync_all_group_memberships'));
    }
    
    /**
     * Get Microsoft Graph access token for group operations
     */
    private function get_access_token() {
        $cache_key = 'azure_pta_groups_app_token';
        $cached_token = get_transient($cache_key);
        
        if ($cached_token) {
            return $cached_token;
        }
        
        $tenant_id = $this->credentials['tenant_id'] ?: 'common';
        $token_url = "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/token";
        
        $body = array(
            'client_id' => $this->credentials['client_id'],
            'client_secret' => $this->credentials['client_secret'],
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials'
        );
        
        $response = wp_remote_post($token_url, array(
            'body' => $body,
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('Token request failed: ' . $response->get_error_message());
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $token_data = json_decode($response_body, true);
        
        if (isset($token_data['error'])) {
            throw new Exception('Token error: ' . $token_data['error_description']);
        }
        
        $access_token = $token_data['access_token'];
        $expires_in = $token_data['expires_in'] ?? 3600;
        
        // Cache token for slightly less than expiry time
        set_transient($cache_key, $access_token, $expires_in - 60);
        
        return $access_token;
    }
    
    /**
     * Fetch all Office 365 groups from Microsoft Graph
     */
    public function fetch_o365_groups() {
        try {
            $access_token = $this->get_access_token();
            
            $api_url = 'https://graph.microsoft.com/v1.0/groups?$select=id,displayName,mailNickname,mail,description,groupTypes,visibility&$top=999';
            
            $response = wp_remote_get($api_url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json'
                ),
                'timeout' => 60
            ));
            
            if (is_wp_error($response)) {
                throw new Exception('Failed to fetch groups: ' . $response->get_error_message());
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                $response_body = wp_remote_retrieve_body($response);
                throw new Exception("Groups fetch failed with status $response_code: $response_body");
            }
            
            $response_body = wp_remote_retrieve_body($response);
            $data = json_decode($response_body, true);
            
            if (!isset($data['value'])) {
                throw new Exception('Invalid response format from Microsoft Graph');
            }
            
            $groups = $data['value'];
            
            // Handle pagination if there are more groups
            while (isset($data['@odata.nextLink'])) {
                $next_url = $data['@odata.nextLink'];
                
                $response = wp_remote_get($next_url, array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $access_token,
                        'Content-Type' => 'application/json'
                    ),
                    'timeout' => 60
                ));
                
                if (is_wp_error($response)) {
                    break; // Stop pagination on error
                }
                
                $response_body = wp_remote_retrieve_body($response);
                $data = json_decode($response_body, true);
                
                if (isset($data['value'])) {
                    $groups = array_merge($groups, $data['value']);
                }
            }
            
            Azure_Logger::info('PTA Groups: Fetched ' . count($groups) . ' Office 365 groups');
            
            return $groups;
            
        } catch (Exception $e) {
            Azure_Logger::error('PTA Groups: Failed to fetch O365 groups - ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Store Office 365 groups in database
     */
    public function store_o365_groups($groups) {
        global $wpdb;
        $table = Azure_PTA_Database::get_table_name('o365_groups');
        
        Azure_Logger::info('PTA Groups: Starting to store ' . count($groups) . ' groups');
        Azure_Logger::info('PTA Groups: Using table name: ' . ($table ?: 'NULL'));
        
        if (!$table) {
            throw new Exception('O365 groups table not found - table name resolution failed');
        }
        
        // Check if table actually exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            Azure_Logger::error('PTA Groups: Table does not exist: ' . $table);
            throw new Exception('O365 groups table does not exist in database: ' . $table);
        }
        
        Azure_Logger::info('PTA Groups: Table exists: ' . $table);
        
        $stored_count = 0;
        $updated_count = 0;
        $unified_groups_count = 0;
        
        foreach ($groups as $group) {
            // Skip non-unified groups (we only want Microsoft 365 groups)
            if (empty($group['groupTypes']) || !in_array('Unified', $group['groupTypes'])) {
                Azure_Logger::debug('PTA Groups: Skipping non-unified group: ' . ($group['displayName'] ?? 'Unknown'));
                continue;
            }
            
            $unified_groups_count++;
            
            $group_data = array(
                'group_id' => $group['id'],
                'display_name' => $group['displayName'],
                'mail_nickname' => $group['mailNickname'] ?? '',
                'mail' => $group['mail'] ?? '',
                'description' => $group['description'] ?? '',
                'group_type' => 'unified',
                'is_active' => 1,
                'last_synced' => current_time('mysql'),
                'metadata' => json_encode(array(
                    'visibility' => $group['visibility'] ?? 'Private',
                    'groupTypes' => $group['groupTypes'] ?? array()
                ))
            );
            
            // Check if group already exists
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $table WHERE group_id = %s",
                $group['id']
            ));
            
            if ($existing) {
                // Update existing group
                Azure_Logger::debug('PTA Groups: Updating existing group: ' . $group['displayName']);
                $result = $wpdb->update(
                    $table,
                    $group_data,
                    array('group_id' => $group['id']),
                    array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'),
                    array('%s')
                );
                
                if ($result !== false) {
                    $updated_count++;
                    Azure_Logger::debug('PTA Groups: Successfully updated: ' . $group['displayName']);
                } else {
                    Azure_Logger::error('PTA Groups: Failed to update group: ' . $group['displayName'] . ' - Error: ' . $wpdb->last_error);
                }
            } else {
                // Insert new group
                Azure_Logger::debug('PTA Groups: Inserting new group: ' . $group['displayName']);
                $result = $wpdb->insert(
                    $table,
                    $group_data,
                    array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
                );
                
                if ($result) {
                    $stored_count++;
                    Azure_Logger::debug('PTA Groups: Successfully inserted: ' . $group['displayName']);
                } else {
                    Azure_Logger::error('PTA Groups: Failed to insert group: ' . $group['displayName'] . ' - Error: ' . $wpdb->last_error);
                    Azure_Logger::error('PTA Groups: Insert data: ' . json_encode($group_data));
                }
            }
        }
        
        // Mark groups as inactive if they weren't in the fetched list
        $fetched_group_ids = array_column($groups, 'id');
        if (!empty($fetched_group_ids)) {
            $placeholders = implode(',', array_fill(0, count($fetched_group_ids), '%s'));
            $wpdb->query($wpdb->prepare(
                "UPDATE $table SET is_active = 0 WHERE group_id NOT IN ($placeholders)",
                $fetched_group_ids
            ));
        }
        
        Azure_Logger::info("PTA Groups: Processed $unified_groups_count unified groups out of " . count($groups) . " total groups");
        Azure_Logger::info("PTA Groups: Stored $stored_count new groups, updated $updated_count existing groups");
        
        return array(
            'stored' => $stored_count,
            'updated' => $updated_count,
            'total' => count($groups),
            'unified_groups' => $unified_groups_count
        );
    }
    
    /**
     * Get stored Office 365 groups
     */
    public function get_stored_o365_groups($active_only = true) {
        global $wpdb;
        
        if (!class_exists('Azure_PTA_Database')) {
            Azure_Logger::error('PTA Groups: Azure_PTA_Database class not found');
            return array();
        }
        
        $table = Azure_PTA_Database::get_table_name('o365_groups');
        Azure_Logger::debug('PTA Groups: Getting stored groups from table: ' . ($table ?: 'NULL'));
        
        if (!$table) {
            Azure_Logger::error('PTA Groups: Table name resolution failed');
            return array();
        }
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            Azure_Logger::error('PTA Groups: Table does not exist: ' . $table);
            return array();
        }
        
        $where = $active_only ? 'WHERE is_active = 1' : '';
        $query = "SELECT * FROM $table $where ORDER BY display_name";
        
        Azure_Logger::debug('PTA Groups: Executing query: ' . $query);
        
        $results = $wpdb->get_results($query);
        $count = count($results ?: array());
        
        Azure_Logger::debug('PTA Groups: Found ' . $count . ' stored groups');
        
        if ($wpdb->last_error) {
            Azure_Logger::error('PTA Groups: Database query error: ' . $wpdb->last_error);
        }
        
        return $results ?: array();
    }
    
    /**
     * Create group mapping between role/department and O365 group
     */
    public function create_group_mapping($target_type, $target_id, $o365_group_id, $is_required = true, $label = null) {
        global $wpdb;
        $table = Azure_PTA_Database::get_table_name('group_mappings');
        
        if (!$table) {
            throw new Exception('Group mappings table not found');
        }
        
        // Validate target type
        if (!in_array($target_type, array('role', 'department'))) {
            throw new Exception('Invalid target type. Must be role or department.');
        }
        
        // Get O365 group internal ID
        $o365_table = Azure_PTA_Database::get_table_name('o365_groups');
        $o365_internal_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $o365_table WHERE group_id = %s AND is_active = 1",
            $o365_group_id
        ));
        
        if (!$o365_internal_id) {
            throw new Exception('O365 group not found or inactive');
        }
        
        // Check if mapping already exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE target_type = %s AND target_id = %d AND o365_group_id = %d",
            $target_type, $target_id, $o365_internal_id
        ));
        
        if ($existing) {
            throw new Exception('Mapping already exists');
        }
        
        $result = $wpdb->insert(
            $table,
            array(
                'target_type' => $target_type,
                'target_id' => $target_id,
                'o365_group_id' => $o365_internal_id,
                'is_required' => $is_required ? 1 : 0,
                'sync_enabled' => 1,
                'label' => $label
            ),
            array('%s', '%d', '%d', '%d', '%d', '%s')
        );
        
        if ($result) {
            $mapping_id = $wpdb->insert_id;
            
            // Log the mapping creation
            Azure_PTA_Database::log_audit(
                'group_mapping',
                $mapping_id,
                'created',
                null,
                array(
                    'target_type' => $target_type,
                    'target_id' => $target_id,
                    'o365_group_id' => $o365_group_id,
                    'is_required' => $is_required
                )
            );
            
            Azure_Logger::info("PTA Groups: Created mapping for $target_type $target_id to group $o365_group_id");
            
            // Queue sync for all users affected by this mapping
            $this->queue_affected_users_sync($target_type, $target_id);
            
            return $mapping_id;
        }
        
        throw new Exception('Failed to create mapping');
    }
    
    /**
     * Delete group mapping
     */
    public function delete_group_mapping($mapping_id) {
        global $wpdb;
        $table = Azure_PTA_Database::get_table_name('group_mappings');
        
        if (!$table) {
            throw new Exception('Group mappings table not found');
        }
        
        $mapping = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $mapping_id
        ));
        
        if (!$mapping) {
            throw new Exception('Mapping not found');
        }
        
        $result = $wpdb->delete(
            $table,
            array('id' => $mapping_id),
            array('%d')
        );
        
        if ($result) {
            // Log the mapping deletion
            Azure_PTA_Database::log_audit(
                'group_mapping',
                $mapping_id,
                'deleted',
                array(
                    'target_type' => $mapping->target_type,
                    'target_id' => $mapping->target_id,
                    'o365_group_id' => $mapping->o365_group_id
                ),
                null
            );
            
            Azure_Logger::info("PTA Groups: Deleted mapping $mapping_id");
            
            // Queue sync for all users affected by this mapping
            $this->queue_affected_users_sync($mapping->target_type, $mapping->target_id);
            
            return true;
        }
        
        throw new Exception('Failed to delete mapping');
    }
    
    /**
     * Get group mappings for a role or department
     */
    public function get_group_mappings($target_type = null, $target_id = null) {
        global $wpdb;
        $mappings_table = Azure_PTA_Database::get_table_name('group_mappings');
        $groups_table = Azure_PTA_Database::get_table_name('o365_groups');
        
        if (!$mappings_table || !$groups_table) {
            return array();
        }
        
        $where = 'WHERE 1=1';
        $params = array();
        
        if ($target_type) {
            $where .= ' AND gm.target_type = %s';
            $params[] = $target_type;
            
            if ($target_id) {
                $where .= ' AND gm.target_id = %d';
                $params[] = $target_id;
            }
        }
        
        $sql = "
            SELECT gm.*, og.group_id, og.display_name as group_name, og.mail, og.description
            FROM $mappings_table gm
            JOIN $groups_table og ON gm.o365_group_id = og.id
            $where
            ORDER BY gm.target_type, gm.target_id, og.display_name
        ";
        
        if (!empty($params)) {
            return $wpdb->get_results($wpdb->prepare($sql, $params));
        } else {
            return $wpdb->get_results($sql);
        }
    }
    
    /**
     * Calculate required group memberships for a user based on their role assignments
     */
    public function calculate_user_group_memberships($user_id) {
        $pta_manager = Azure_PTA_Manager::get_instance();
        $assignments = $pta_manager->get_user_assignments($user_id);
        
        $required_groups = array();
        $role_ids = array();
        $department_ids = array();
        
        foreach ($assignments as $assignment) {
            $role_ids[] = $assignment->role_id;
            $department_ids[] = $assignment->department_id;
        }
        
        // Get role-based mappings
        if (!empty($role_ids)) {
            $role_mappings = $this->get_group_mappings_for_targets('role', $role_ids);
            foreach ($role_mappings as $mapping) {
                $required_groups[$mapping->group_id] = array(
                    'group_id' => $mapping->group_id,
                    'group_name' => $mapping->group_name,
                    'source' => 'role',
                    'source_id' => $mapping->target_id,
                    'is_required' => (bool) $mapping->is_required
                );
            }
        }
        
        // Get department-based mappings
        if (!empty($department_ids)) {
            $dept_mappings = $this->get_group_mappings_for_targets('department', array_unique($department_ids));
            foreach ($dept_mappings as $mapping) {
                $required_groups[$mapping->group_id] = array(
                    'group_id' => $mapping->group_id,
                    'group_name' => $mapping->group_name,
                    'source' => 'department',
                    'source_id' => $mapping->target_id,
                    'is_required' => (bool) $mapping->is_required
                );
            }
        }
        
        return array_values($required_groups);
    }
    
    /**
     * Get group mappings for multiple targets
     */
    private function get_group_mappings_for_targets($target_type, $target_ids) {
        global $wpdb;
        $mappings_table = Azure_PTA_Database::get_table_name('group_mappings');
        $groups_table = Azure_PTA_Database::get_table_name('o365_groups');
        
        if (empty($target_ids) || !$mappings_table || !$groups_table) {
            return array();
        }
        
        $placeholders = implode(',', array_fill(0, count($target_ids), '%d'));
        $params = array_merge(array($target_type), $target_ids);
        
        $sql = "
            SELECT gm.*, og.group_id, og.display_name as group_name
            FROM $mappings_table gm
            JOIN $groups_table og ON gm.o365_group_id = og.id
            WHERE gm.target_type = %s 
            AND gm.target_id IN ($placeholders)
            AND gm.sync_enabled = 1
            AND og.is_active = 1
        ";
        
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }
    
    /**
     * Sync user's group memberships
     */
    public function sync_user_group_memberships($user_id) {
        try {
            $access_token = $this->get_access_token();
            
            // Get user's Azure AD object ID
            $azure_object_id = get_user_meta($user_id, 'azure_object_id', true);
            if (!$azure_object_id) {
                throw new Exception("Azure AD object ID not found for user $user_id");
            }
            
            // Calculate required group memberships
            $required_groups = $this->calculate_user_group_memberships($user_id);
            $required_group_ids = array_column($required_groups, 'group_id');
            
            // Get current group memberships
            $current_groups = $this->get_user_current_group_memberships($azure_object_id, $access_token);
            $current_group_ids = array_column($current_groups, 'id');
            
            // Calculate changes
            $groups_to_add = array_diff($required_group_ids, $current_group_ids);
            $groups_to_remove = array_diff($current_group_ids, $required_group_ids);
            
            $added_count = 0;
            $removed_count = 0;
            
            // Add user to new groups
            foreach ($groups_to_add as $group_id) {
                if ($this->add_user_to_group($azure_object_id, $group_id, $access_token)) {
                    $added_count++;
                }
            }
            
            // Remove user from old groups (only if they're PTA-managed groups)
            $managed_groups = array_column($this->get_stored_o365_groups(), 'group_id');
            foreach ($groups_to_remove as $group_id) {
                if (in_array($group_id, $managed_groups)) {
                    if ($this->remove_user_from_group($azure_object_id, $group_id, $access_token)) {
                        $removed_count++;
                    }
                }
            }
            
            Azure_Logger::info("PTA Groups: Synced user $user_id memberships - Added: $added_count, Removed: $removed_count");
            
            return array(
                'success' => true,
                'added' => $added_count,
                'removed' => $removed_count,
                'required_groups' => count($required_groups)
            );
            
        } catch (Exception $e) {
            Azure_Logger::error("PTA Groups: Failed to sync user $user_id memberships - " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get user's current group memberships from Microsoft Graph
     */
    private function get_user_current_group_memberships($azure_object_id, $access_token) {
        $api_url = "https://graph.microsoft.com/v1.0/users/{$azure_object_id}/memberOf?\$select=id,displayName";
        
        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('Failed to get current memberships: ' . $response->get_error_message());
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        if (isset($data['error'])) {
            throw new Exception('Memberships error: ' . $data['error']['message']);
        }
        
        return $data['value'] ?? array();
    }
    
    /**
     * Add user to Office 365 group
     */
    private function add_user_to_group($azure_object_id, $group_id, $access_token) {
        $api_url = "https://graph.microsoft.com/v1.0/groups/{$group_id}/members/\$ref";
        
        $member_ref = array(
            '@odata.id' => "https://graph.microsoft.com/v1.0/users/{$azure_object_id}"
        );
        
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($member_ref),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            Azure_Logger::error("PTA Groups: Failed to add user to group $group_id - " . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 204) {
            Azure_Logger::info("PTA Groups: Added user $azure_object_id to group $group_id");
            return true;
        } else {
            $response_body = wp_remote_retrieve_body($response);
            Azure_Logger::error("PTA Groups: Failed to add user to group with status $response_code: $response_body");
            return false;
        }
    }
    
    /**
     * Remove user from Office 365 group
     */
    private function remove_user_from_group($azure_object_id, $group_id, $access_token) {
        $api_url = "https://graph.microsoft.com/v1.0/groups/{$group_id}/members/{$azure_object_id}/\$ref";
        
        $response = wp_remote_request($api_url, array(
            'method' => 'DELETE',
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            Azure_Logger::error("PTA Groups: Failed to remove user from group $group_id - " . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 204) {
            Azure_Logger::info("PTA Groups: Removed user $azure_object_id from group $group_id");
            return true;
        } else {
            $response_body = wp_remote_retrieve_body($response);
            Azure_Logger::error("PTA Groups: Failed to remove user from group with status $response_code: $response_body");
            return false;
        }
    }
    
    /**
     * Queue sync for users affected by mapping changes
     */
    private function queue_affected_users_sync($target_type, $target_id) {
        global $wpdb;
        
        if ($target_type === 'role') {
            // Get all users assigned to this role
            $assignments_table = Azure_PTA_Database::get_table_name('assignments');
            $user_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT user_id FROM $assignments_table WHERE role_id = %d AND status = 'active'",
                $target_id
            ));
        } elseif ($target_type === 'department') {
            // Get all users assigned to roles in this department
            $assignments_table = Azure_PTA_Database::get_table_name('assignments');
            $roles_table = Azure_PTA_Database::get_table_name('roles');
            
            $user_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT ra.user_id 
                 FROM $assignments_table ra
                 JOIN $roles_table r ON ra.role_id = r.id
                 WHERE r.department_id = %d AND ra.status = 'active'",
                $target_id
            ));
        } else {
            return;
        }
        
        foreach ($user_ids as $user_id) {
            Azure_PTA_Database::queue_sync(
                'group_sync',
                'user',
                $user_id,
                'sync_group_memberships',
                array('reason' => 'mapping_changed'),
                10
            );
        }
    }
    
    
    /**
     * Sync all Office 365 groups (scheduled task)
     */
    public function sync_all_o365_groups() {
        try {
            $groups = $this->fetch_o365_groups();
            $result = $this->store_o365_groups($groups);
            
            Azure_Logger::info('PTA Groups: Scheduled O365 groups sync completed - ' . json_encode($result));
        } catch (Exception $e) {
            Azure_Logger::error('PTA Groups: Scheduled O365 groups sync failed - ' . $e->getMessage());
        }
    }
    
    /**
     * Sync all group memberships (scheduled task)
     */
    public function sync_all_group_memberships() {
        global $wpdb;
        $assignments_table = Azure_PTA_Database::get_table_name('assignments');
        
        if (!$assignments_table) {
            return;
        }
        
        // Get all active users with role assignments
        $user_ids = $wpdb->get_col("SELECT DISTINCT user_id FROM $assignments_table WHERE status = 'active'");
        
        $synced_count = 0;
        $error_count = 0;
        
        foreach ($user_ids as $user_id) {
            try {
                $this->sync_user_group_memberships($user_id);
                $synced_count++;
            } catch (Exception $e) {
                $error_count++;
                Azure_Logger::error("PTA Groups: Failed to sync user $user_id in scheduled task - " . $e->getMessage());
            }
            
            // Add small delay to avoid API throttling
            usleep(500000); // 0.5 seconds
        }
        
        Azure_Logger::info("PTA Groups: Scheduled memberships sync completed - Synced: $synced_count, Errors: $error_count");
    }
    
    /**
     * AJAX Handlers
     */
    public function ajax_sync_o365_groups() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }
        
        try {
            $groups = $this->fetch_o365_groups();
            Azure_Logger::info('PTA Groups: Fetched ' . count($groups) . ' groups from Microsoft Graph');
            
            $result = $this->store_o365_groups($groups);
            Azure_Logger::info('PTA Groups: Store result: ' . json_encode($result));
            
            wp_send_json_success(array(
                'message' => "Successfully synced {$result['total']} total groups. Found {$result['unified_groups']} Microsoft 365 groups ({$result['stored']} new, {$result['updated']} updated)",
                'result' => $result
            ));
        } catch (Exception $e) {
            Azure_Logger::error('PTA Groups: Sync failed - ' . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_get_o365_groups() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }
        
        $groups = $this->get_stored_o365_groups();
        wp_send_json_success($groups);
    }
    
    public function ajax_create_group_mapping() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }
        
        $target_type = sanitize_text_field($_POST['target_type']);
        $target_id = intval($_POST['target_id']);
        $o365_group_id = sanitize_text_field($_POST['o365_group_id']);
        $is_required = isset($_POST['is_required']) && $_POST['is_required'];
        $label = sanitize_text_field($_POST['label'] ?? '');
        
        try {
            $mapping_id = $this->create_group_mapping($target_type, $target_id, $o365_group_id, $is_required, $label);
            wp_send_json_success(array(
                'message' => 'Group mapping created successfully',
                'mapping_id' => $mapping_id
            ));
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_delete_group_mapping() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }
        
        $mapping_id = intval($_POST['mapping_id']);
        
        try {
            $this->delete_group_mapping($mapping_id);
            wp_send_json_success('Group mapping deleted successfully');
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_get_department_group_mapping() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }

        $dept_id = intval($_POST['dept_id'] ?? 0);
        if (!$dept_id) {
            wp_send_json_error('Department ID is required');
        }

        $mappings = $this->get_group_mappings('department', $dept_id);
        $mapping = !empty($mappings) ? $mappings[0] : null;

        wp_send_json_success($mapping);
    }

    /**
     * Set or replace the O365 group for a department (single group per department).
     * Removes any existing department mapping before creating the new one.
     */
    public function ajax_set_department_group() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }

        $dept_id = intval($_POST['dept_id'] ?? 0);
        $o365_group_id = sanitize_text_field($_POST['o365_group_id'] ?? '');

        if (!$dept_id) {
            wp_send_json_error('Department ID is required');
        }

        try {
            $existing_mappings = $this->get_group_mappings('department', $dept_id);
            foreach ($existing_mappings as $mapping) {
                $this->delete_group_mapping($mapping->id);
            }

            if (!empty($o365_group_id)) {
                $mapping_id = $this->create_group_mapping('department', $dept_id, $o365_group_id, true);
                wp_send_json_success(array('message' => 'Department group updated', 'mapping_id' => $mapping_id));
            } else {
                wp_send_json_success(array('message' => 'Department group cleared'));
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajax_get_role_group_mapping() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }

        $role_id = intval($_POST['role_id'] ?? 0);
        if (!$role_id) {
            wp_send_json_error('Role ID is required');
        }

        $mappings = $this->get_group_mappings('role', $role_id);
        $mapping = !empty($mappings) ? $mappings[0] : null;

        wp_send_json_success($mapping);
    }

    /**
     * Set or replace the O365 group for a role (single group per role).
     */
    public function ajax_set_role_group() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }

        $role_id = intval($_POST['role_id'] ?? 0);
        $o365_group_id = sanitize_text_field($_POST['o365_group_id'] ?? '');

        if (!$role_id) {
            wp_send_json_error('Role ID is required');
        }

        try {
            $existing_mappings = $this->get_group_mappings('role', $role_id);
            foreach ($existing_mappings as $mapping) {
                $this->delete_group_mapping($mapping->id);
            }

            if (!empty($o365_group_id)) {
                $mapping_id = $this->create_group_mapping('role', $role_id, $o365_group_id, true);
                wp_send_json_success(array('message' => 'Role group updated', 'mapping_id' => $mapping_id));
            } else {
                wp_send_json_success(array('message' => 'Role group cleared'));
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajax_sync_user_group_memberships() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }
        
        $user_id = intval($_POST['user_id']);
        
        try {
            $result = $this->sync_user_group_memberships($user_id);
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_test_group_access() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }
        
        try {
            $access_token = $this->get_access_token();
            
            // Test by fetching first few groups
            $api_url = 'https://graph.microsoft.com/v1.0/groups?$select=id,displayName&$top=5';
            
            $response = wp_remote_get($api_url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json'
                ),
                'timeout' => 30
            ));
            
            if (is_wp_error($response)) {
                throw new Exception('Failed to test group access: ' . $response->get_error_message());
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code === 200) {
                $response_body = wp_remote_retrieve_body($response);
                $data = json_decode($response_body, true);
                $group_count = count($data['value'] ?? array());
                
                wp_send_json_success(array(
                    'message' => "Group access test successful! Found $group_count groups.",
                    'token_available' => !empty($access_token)
                ));
            } else {
                throw new Exception("Group access test failed with status $response_code");
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function ajax_get_unmapped_groups() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }
        
        try {
            $unmapped_groups = $this->get_unmapped_groups();
            wp_send_json_success($unmapped_groups);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Get groups that don't have any PTA mappings
     */
    private function get_unmapped_groups() {
        global $wpdb;
        $groups_table = Azure_PTA_Database::get_table_name('o365_groups');
        $mappings_table = Azure_PTA_Database::get_table_name('group_mappings');
        
        if (!$groups_table || !$mappings_table) {
            return array();
        }
        
        // Get all groups that don't have any mappings
        $sql = "
            SELECT og.group_id, og.display_name, og.mail, og.description
            FROM $groups_table og
            LEFT JOIN $mappings_table gm ON og.id = gm.o365_group_id
            WHERE og.is_active = 1 
            AND gm.id IS NULL
            ORDER BY og.display_name
        ";
        
        return $wpdb->get_results($sql);
    }
}
