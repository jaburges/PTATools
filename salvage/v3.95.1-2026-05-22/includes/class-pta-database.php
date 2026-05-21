<?php
/**
 * PTA Database management for Azure Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_PTA_Database {
    
    private static $initialized = false;
    private static $tables_created = false;
    
    public static function init() {
        if (self::$initialized) {
            return;
        }
        
        self::$initialized = true;
        
        // DO NOT create tables during normal initialization - only during activation
        // Tables should be created via activate() hook in main plugin file
        Azure_Logger::debug('PTA: Database class initialized (tables created during activation only)');
    }
    
    /**
     * Check if core PTA tables exist (used only during activation, not regular page loads)
     */
    public static function tables_exist() {
        global $wpdb;
        
        $core_tables = [
            $wpdb->prefix . 'pta_departments',
            $wpdb->prefix . 'pta_roles', 
            $wpdb->prefix . 'pta_role_assignments'
        ];
        
        foreach ($core_tables as $table) {
            $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
            if (!$exists) {
                Azure_Logger::debug("PTA: Missing core table: $table");
                return false;
            }
        }
        
        Azure_Logger::debug('PTA: All core tables exist');
        return true;
    }
    
    /**
     * Get table name with prefix
     */
    public static function get_table_name($table_type) {
        global $wpdb;
        
        switch ($table_type) {
            case 'departments':
                return $wpdb->prefix . 'pta_departments';
            case 'roles':
                return $wpdb->prefix . 'pta_roles';
            case 'assignments':
                return $wpdb->prefix . 'pta_role_assignments';
            case 'o365_groups':
                return $wpdb->prefix . 'pta_o365_groups';
            case 'group_mappings':
                return $wpdb->prefix . 'pta_group_mappings';
            case 'audit_logs':
                return $wpdb->prefix . 'pta_audit_logs';
            case 'sync_queue':
                return $wpdb->prefix . 'pta_sync_queue';
            default:
                return null;
        }
    }
    
    /**
     * Create all PTA-related database tables
     */
    public static function create_pta_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // PTA Departments table
        $table_name_departments = $wpdb->prefix . 'pta_departments';
        $sql_departments = "CREATE TABLE $table_name_departments (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL UNIQUE,
            vp_user_id bigint(20) DEFAULT NULL,
            metadata longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY vp_user_id (vp_user_id)
        ) $charset_collate;";
        
        // PTA Roles table
        $table_name_roles = $wpdb->prefix . 'pta_roles';
        $sql_roles = "CREATE TABLE $table_name_roles (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL UNIQUE,
            department_id bigint(20) NOT NULL,
            max_occupants int(11) DEFAULT 1,
            description text DEFAULT NULL,
            metadata longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY department_id (department_id)
        ) $charset_collate;";
        
        // PTA Role Assignments table (junction table)
        $table_name_assignments = $wpdb->prefix . 'pta_role_assignments';
        $sql_assignments = "CREATE TABLE $table_name_assignments (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            role_id bigint(20) NOT NULL,
            is_primary tinyint(1) DEFAULT 0,
            assigned_at datetime DEFAULT CURRENT_TIMESTAMP,
            assigned_by bigint(20) DEFAULT NULL,
            status varchar(20) DEFAULT 'active',
            metadata longtext DEFAULT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY role_id (role_id),
            KEY is_primary (is_primary),
            KEY assigned_by (assigned_by),
            UNIQUE KEY unique_user_role (user_id, role_id)
        ) $charset_collate;";
        
        // Office 365 Groups table
        $table_name_o365_groups = $wpdb->prefix . 'pta_o365_groups';
        $sql_o365_groups = "CREATE TABLE $table_name_o365_groups (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            group_id varchar(255) NOT NULL UNIQUE,
            display_name varchar(255) NOT NULL,
            mail_nickname varchar(255) DEFAULT NULL,
            mail varchar(255) DEFAULT NULL,
            description text DEFAULT NULL,
            group_type varchar(50) DEFAULT 'unified',
            is_active tinyint(1) DEFAULT 1,
            last_synced datetime DEFAULT NULL,
            metadata longtext DEFAULT NULL,
            PRIMARY KEY (id),
            KEY is_active (is_active)
        ) $charset_collate;";
        
        // Group Mappings table (roles/departments to O365 groups)
        $table_name_group_mappings = $wpdb->prefix . 'pta_group_mappings';
        $sql_group_mappings = "CREATE TABLE $table_name_group_mappings (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            target_type varchar(20) NOT NULL,
            target_id bigint(20) NOT NULL,
            o365_group_id bigint(20) NOT NULL,
            is_required tinyint(1) DEFAULT 1,
            sync_enabled tinyint(1) DEFAULT 1,
            label varchar(255) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY target_type_id (target_type, target_id),
            KEY o365_group_id (o365_group_id),
            UNIQUE KEY unique_mapping (target_type, target_id, o365_group_id)
        ) $charset_collate;";
        
        // Audit Logs table
        $table_name_audit_logs = $wpdb->prefix . 'pta_audit_logs';
        $sql_audit_logs = "CREATE TABLE $table_name_audit_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            actor_user_id bigint(20) DEFAULT NULL,
            entity_type varchar(50) NOT NULL,
            entity_id bigint(20) DEFAULT NULL,
            action varchar(100) NOT NULL,
            old_values longtext DEFAULT NULL,
            new_values longtext DEFAULT NULL,
            payload_json longtext DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY actor_user_id (actor_user_id),
            KEY entity_type_id (entity_type, entity_id),
            KEY action (action),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Sync Queue table
        $table_name_sync_queue = $wpdb->prefix . 'pta_sync_queue';
        $sql_sync_queue = "CREATE TABLE $table_name_sync_queue (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            queue_type varchar(50) NOT NULL,
            entity_type varchar(50) NOT NULL,
            entity_id bigint(20) NOT NULL,
            action varchar(100) NOT NULL,
            priority int(11) DEFAULT 10,
            payload longtext DEFAULT NULL,
            status varchar(20) DEFAULT 'pending',
            attempts int(11) DEFAULT 0,
            max_attempts int(11) DEFAULT 3,
            error_message text DEFAULT NULL,
            scheduled_at datetime DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY queue_type (queue_type),
            KEY status (status),
            KEY priority (priority),
            KEY scheduled_at (scheduled_at),
            KEY entity_type_id (entity_type, entity_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Track which tables get created
        $tables_before = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}pta_%'");
        Azure_Logger::debug('PTA: Tables before creation: ' . json_encode($tables_before));
        
        $results = array();
        
        Azure_Logger::debug('PTA: Creating departments table...');
        $wpdb->flush(); // Clear any previous errors
        $results['departments'] = dbDelta($sql_departments);
        if ($wpdb->last_error) Azure_Logger::error('PTA: Error creating departments: ' . $wpdb->last_error);
        
        Azure_Logger::debug('PTA: Creating roles table...');
        $results['roles'] = dbDelta($sql_roles);
        if ($wpdb->last_error) Azure_Logger::error('PTA: Error creating roles: ' . $wpdb->last_error);
        
        Azure_Logger::debug('PTA: Creating assignments table...');
        $results['assignments'] = dbDelta($sql_assignments);
        if ($wpdb->last_error) Azure_Logger::error('PTA: Error creating assignments: ' . $wpdb->last_error);
        
        Azure_Logger::debug('PTA: Creating o365_groups table...');
        $results['o365_groups'] = dbDelta($sql_o365_groups);
        if ($wpdb->last_error) Azure_Logger::error('PTA: Error creating o365_groups: ' . $wpdb->last_error);
        
        Azure_Logger::debug('PTA: Creating group_mappings table...');
        $results['group_mappings'] = dbDelta($sql_group_mappings);
        if ($wpdb->last_error) Azure_Logger::error('PTA: Error creating group_mappings: ' . $wpdb->last_error);
        
        Azure_Logger::debug('PTA: Creating audit_logs table...');
        $results['audit_logs'] = dbDelta($sql_audit_logs);
        if ($wpdb->last_error) Azure_Logger::error('PTA: Error creating audit_logs: ' . $wpdb->last_error);
        
        Azure_Logger::debug('PTA: Creating sync_queue table...');
        $results['sync_queue'] = dbDelta($sql_sync_queue);
        if ($wpdb->last_error) Azure_Logger::error('PTA: Error creating sync_queue: ' . $wpdb->last_error);
        
        // Log dbDelta results for debugging
        foreach ($results as $table => $result) {
            Azure_Logger::debug("PTA: dbDelta result for $table: " . json_encode($result));
        }
        
        // Check if tables actually exist
        $tables_after = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}pta_%'");
        Azure_Logger::debug('PTA: Tables after creation: ' . json_encode($tables_after));
        
        // Verify each table exists
        $expected_tables = ['departments', 'roles', 'role_assignments', 'o365_groups', 'group_mappings', 'audit_logs', 'sync_queue'];
        $missing_tables = array();
        
        foreach ($expected_tables as $table_suffix) {
            $full_table_name = $wpdb->prefix . 'pta_' . $table_suffix;
            $exists = $wpdb->get_var("SHOW TABLES LIKE '$full_table_name'");
            if (!$exists) {
                $missing_tables[] = $full_table_name;
            }
        }
        
        if (!empty($missing_tables)) {
            Azure_Logger::error('PTA: Failed to create tables: ' . implode(', ', $missing_tables));
            Azure_Logger::error('PTA: Last WordPress DB error: ' . $wpdb->last_error);
            Azure_Logger::error('PTA: Last WordPress DB query: ' . $wpdb->last_query);
            
            // Try to get more specific error info
            foreach ($missing_tables as $table_name) {
                $test_result = $wpdb->get_var("SHOW CREATE TABLE $table_name");
                if ($wpdb->last_error) {
                    Azure_Logger::error("PTA: Error checking table $table_name: " . $wpdb->last_error);
                }
            }
        } else {
            Azure_Logger::info('PTA database tables created/updated successfully');
        }
    }
    
    /**
     * Seed initial data
     */
    public static function seed_initial_data($force_reseed = false) {
        self::seed_departments($force_reseed);
        self::seed_roles($force_reseed);
        Azure_Logger::info('PTA initial data seeded successfully');
    }
    
    /**
     * Read roles and departments data from the CSV file
     */
    private static function read_roles_departments_csv() {
        // Look for the CSV file in the plugin data folder
        $csv_file = AZURE_PLUGIN_PATH . 'data/roles-departments.csv';
        
        Azure_Logger::debug('PTA: Looking for CSV file at: ' . $csv_file);
        
        if (!file_exists($csv_file)) {
            Azure_Logger::error('PTA: roles-departments.csv file not found at: ' . $csv_file);
            return array();
        }
        
        Azure_Logger::debug('PTA: CSV file found, file size: ' . filesize($csv_file) . ' bytes');
        
        $csv_data = array();
        $handle = fopen($csv_file, 'r');
        
        if (!$handle) {
            Azure_Logger::error('PTA: Could not open roles and departments CSV file: ' . $csv_file);
            return array();
        }
        
        $header = null;
        $line_number = 0;
        
        while (($line = fgets($handle)) !== false) {
            $line_number++;
            $line = trim($line);
            
            // Skip empty lines
            if (empty($line)) {
                continue;
            }
            
            // Parse CSV line
            $data = str_getcsv($line);
            
            if ($header === null) {
                // First line is header
                $header = array_map('trim', $data);
                continue;
            }
            
            // Ensure we have at least 3 columns
            if (count($data) < 3) {
                Azure_Logger::warning('PTA: Invalid CSV line ' . $line_number . ' (not enough columns): ' . $line);
                continue;
            }
            
            // Create associative array using header
            $row_data = array();
            foreach ($header as $index => $column) {
                $row_data[$column] = isset($data[$index]) ? trim($data[$index]) : '';
            }
            
            $csv_data[] = $row_data;
        }
        
        fclose($handle);
        
        Azure_Logger::info('PTA: Successfully read ' . count($csv_data) . ' rows from CSV file: ' . $csv_file);
        
        return $csv_data;
    }
    
    /**
     * Seed departments from the roles and departments data file
     */
    private static function seed_departments($force_reseed = false) {
        global $wpdb;
        $table = $wpdb->prefix . 'pta_departments';
        
        // Verify table exists first (only during activation/seeding, not regular page loads)
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            Azure_Logger::error("PTA: Departments table '$table' does not exist. Plugin may need reactivation.");
            return; // Do not create tables during regular operations
        }
        
        // Verify table structure
        $columns = $wpdb->get_results("DESCRIBE $table");
        Azure_Logger::debug("PTA: Departments table structure: " . json_encode($columns));
        
        // Check if departments already exist (skip check if force reseeding)
        if (!$force_reseed) {
            $existing_count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
            if ($existing_count > 0) {
                return; // Already seeded
            }
        }
        
        // Read from the Roles and departments.md file
        $csv_data = self::read_roles_departments_csv();
        if (empty($csv_data)) {
            Azure_Logger::error('PTA: Could not read roles and departments CSV data');
            return;
        }
        
        // Extract unique departments from the CSV data
        $departments = array();
        foreach ($csv_data as $row) {
            $dept_name = trim($row['pta_department']);
            if (!empty($dept_name) && !in_array($dept_name, $departments)) {
                $departments[] = $dept_name;
            }
        }
        
        // Sort departments for consistent ordering
        sort($departments);
        
        foreach ($departments as $dept_name) {
            $slug = sanitize_title($dept_name);
            
            // Check if department already exists before inserting
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE name = %s", $dept_name));
            if ($existing) {
                Azure_Logger::debug("PTA: Department '$dept_name' already exists with ID $existing");
                continue;
            }
            
            Azure_Logger::debug("PTA: Inserting department '$dept_name' with slug '$slug'");
            
            $result = $wpdb->insert(
                $table,
                array(
                    'name' => $dept_name,
                    'slug' => $slug,
                    'metadata' => json_encode(array('auto_created' => true, 'source' => 'csv'))
                ),
                array('%s', '%s', '%s')
            );
            
            if ($result === false) {
                Azure_Logger::error("PTA: Failed to insert department '$dept_name' - SQL Error: " . $wpdb->last_error);
                Azure_Logger::error("PTA: Last SQL Query: " . $wpdb->last_query);
            } else {
                Azure_Logger::info("PTA: Successfully inserted department '$dept_name' with ID " . $wpdb->insert_id);
            }
        }
        
        Azure_Logger::info('PTA: Seeded ' . count($departments) . ' departments from CSV');
    }
    
    /**
     * Seed roles from the CSV data provided
     */
    private static function seed_roles($force_reseed = false) {
        global $wpdb;
        $roles_table = $wpdb->prefix . 'pta_roles';
        $dept_table = $wpdb->prefix . 'pta_departments';
        
        // Check if roles already exist (skip check if force reseeding)
        if (!$force_reseed) {
            $existing_count = $wpdb->get_var("SELECT COUNT(*) FROM $roles_table");
            if ($existing_count > 0) {
                return; // Already seeded
            }
        }
        
        // Read from the Roles and departments.md file
        $csv_data = self::read_roles_departments_csv();
        if (empty($csv_data)) {
            Azure_Logger::error('PTA: Could not read roles and departments CSV data');
            return;
        }
        
        $roles_data = array();
        foreach ($csv_data as $row) {
            $role_name = trim($row['pta_role']);
            $max_occupants = intval(trim($row['Max Occupants']));
            $dept_name = trim($row['pta_department']);
            
            if (!empty($role_name) && !empty($dept_name) && $max_occupants > 0) {
                $roles_data[] = array($role_name, $max_occupants, $dept_name);
            }
        }
        
        foreach ($roles_data as $role_data) {
            list($role_name, $max_occupants, $dept_name) = $role_data;
            
            // Get department ID
            $dept_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $dept_table WHERE name = %s",
                $dept_name
            ));
            
            if (!$dept_id) {
                Azure_Logger::warning("PTA: Department '$dept_name' not found for role '$role_name'");
                continue;
            }
            
            $slug = sanitize_title($role_name);
            
            // Handle duplicate slugs by appending department
            $existing_slug = $wpdb->get_var($wpdb->prepare(
                "SELECT slug FROM $roles_table WHERE slug = %s",
                $slug
            ));
            
            if ($existing_slug) {
                $slug = sanitize_title($role_name . '-' . $dept_name);
            }
            
            $result = $wpdb->insert(
                $roles_table,
                array(
                    'name' => $role_name,
                    'slug' => $slug,
                    'department_id' => $dept_id,
                    'max_occupants' => $max_occupants,
                    'metadata' => json_encode(array('auto_created' => true))
                ),
                array('%s', '%s', '%d', '%d', '%s')
            );
            
            if ($result === false) {
                Azure_Logger::error("PTA: Failed to insert role: $role_name - " . $wpdb->last_error);
            } else {
                Azure_Logger::debug("PTA: Successfully inserted role: $role_name");
            }
        }
        
        // Log final count
        $final_count = $wpdb->get_var("SELECT COUNT(*) FROM $roles_table");
        Azure_Logger::info("PTA: Seeded roles from CSV - Final count: $final_count");
    }
    
    
    /**
     * Log audit trail
     */
    public static function log_audit($entity_type, $entity_id, $action, $old_values = null, $new_values = null, $additional_data = null) {
        global $wpdb;
        $table = self::get_table_name('audit_logs');
        
        if (!$table) {
            return false;
        }
        
        $actor_user_id = get_current_user_id() ?: null;
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        return $wpdb->insert(
            $table,
            array(
                'actor_user_id' => $actor_user_id,
                'entity_type' => $entity_type,
                'entity_id' => $entity_id,
                'action' => $action,
                'old_values' => $old_values ? json_encode($old_values) : null,
                'new_values' => $new_values ? json_encode($new_values) : null,
                'payload_json' => $additional_data ? json_encode($additional_data) : null,
                'ip_address' => $ip_address,
                'user_agent' => $user_agent
            ),
            array('%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }
    
    /**
     * Add item to sync queue
     */
    public static function queue_sync($queue_type, $entity_type, $entity_id, $action, $payload = null, $priority = 10) {
        global $wpdb;
        $table = self::get_table_name('sync_queue');
        
        if (!$table) {
            return false;
        }
        
        return $wpdb->insert(
            $table,
            array(
                'queue_type' => $queue_type,
                'entity_type' => $entity_type,
                'entity_id' => $entity_id,
                'action' => $action,
                'priority' => $priority,
                'payload' => $payload ? json_encode($payload) : null
            ),
            array('%s', '%s', '%d', '%s', '%d', '%s')
        );
    }
    
    /**
     * Clean up old audit logs
     */
    public static function cleanup_old_logs($days = 90) {
        global $wpdb;
        $table = self::get_table_name('audit_logs');
        
        if (!$table) {
            return false;
        }
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        if ($deleted) {
            Azure_Logger::info("PTA Database: Cleaned up $deleted old audit log entries");
        }
        
        return $deleted;
    }
}
