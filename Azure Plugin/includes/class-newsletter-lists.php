<?php
/**
 * Newsletter Lists Management
 * 
 * Handles subscriber lists, unsubscribes, and list operations
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Newsletter_Lists {
    
    private $lists_table;
    private $members_table;
    private $bounces_table;
    
    public function __construct() {
        global $wpdb;
        $this->lists_table = $wpdb->prefix . 'azure_newsletter_lists';
        $this->members_table = $wpdb->prefix . 'azure_newsletter_list_members';
        $this->bounces_table = $wpdb->prefix . 'azure_newsletter_bounces';
    }
    
    /**
     * Process an unsubscribe request
     */
    public function process_unsubscribe($token) {
        $data = $this->decode_unsubscribe_token($token);
        
        if (!$data || empty($data['email'])) {
            return array('success' => false, 'error' => 'Invalid token');
        }
        
        global $wpdb;
        
        // Mark as unsubscribed in all lists
        $wpdb->update(
            $this->members_table,
            array('unsubscribed_at' => current_time('mysql')),
            array('email' => $data['email'])
        );
        
        // Record the unsubscribe event
        $stats_table = $wpdb->prefix . 'azure_newsletter_stats';
        $wpdb->insert($stats_table, array(
            'newsletter_id' => $data['newsletter_id'] ?? null,
            'email' => $data['email'],
            'event_type' => 'unsubscribed',
            'created_at' => current_time('mysql')
        ));
        
        Azure_Logger::info("Newsletter: Email unsubscribed: {$data['email']}");
        
        return array('success' => true, 'email' => $data['email']);
    }
    
    /**
     * Decode unsubscribe token
     */
    private function decode_unsubscribe_token($token) {
        // Token format: base64(email|newsletter_id|hmac)
        // For simplicity, we'll use a reversible encoding
        
        $decoded = base64_decode($token);
        if (!$decoded) {
            return null;
        }
        
        // Try to extract email
        $parts = explode('|', $decoded);
        if (count($parts) >= 2) {
            return array(
                'email' => $parts[0],
                'newsletter_id' => $parts[1] ?? null
            );
        }
        
        return null;
    }
    
    /**
     * Generate unsubscribe token
     */
    public function generate_unsubscribe_token($email, $newsletter_id = null) {
        $data = $email . '|' . ($newsletter_id ?? '0') . '|' . wp_hash($email . $newsletter_id);
        return base64_encode($data);
    }
    
    /**
     * Create a new list
     */
    public function create_list($name, $type, $description = '', $criteria = array()) {
        global $wpdb;
        
        $result = $wpdb->insert($this->lists_table, array(
            'name' => $name,
            'description' => $description,
            'type' => $type,
            'criteria' => json_encode($criteria),
            'created_at' => current_time('mysql')
        ));
        
        if ($result) {
            return $wpdb->insert_id;
        }
        
        return false;
    }
    
    /**
     * Update a list
     */
    public function update_list($list_id, $data) {
        global $wpdb;
        
        $update_data = array();
        
        if (isset($data['name'])) {
            $update_data['name'] = $data['name'];
        }
        if (isset($data['description'])) {
            $update_data['description'] = $data['description'];
        }
        if (isset($data['criteria'])) {
            $update_data['criteria'] = json_encode($data['criteria']);
        }
        
        if (empty($update_data)) {
            return false;
        }
        
        return $wpdb->update($this->lists_table, $update_data, array('id' => $list_id));
    }
    
    /**
     * Delete a list
     */
    public function delete_list($list_id) {
        global $wpdb;
        
        // Delete members first
        $wpdb->delete($this->members_table, array('list_id' => $list_id));
        
        // Delete list
        return $wpdb->delete($this->lists_table, array('id' => $list_id));
    }
    
    /**
     * Get a list by ID
     */
    public function get_list($list_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->lists_table} WHERE id = %d",
            $list_id
        ));
    }
    
    /**
     * Get all lists
     */
    public function get_all_lists() {
        global $wpdb;
        
        return $wpdb->get_results("SELECT * FROM {$this->lists_table} ORDER BY name ASC");
    }
    
    /**
     * Add a subscriber to a list
     */
    public function add_subscriber($list_id, $email, $user_id = null, $first_name = '', $last_name = '') {
        global $wpdb;
        
        // Check if already subscribed
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->members_table} WHERE list_id = %d AND email = %s",
            $list_id,
            $email
        ));
        
        if ($existing) {
            if ($existing->unsubscribed_at) {
                // Re-subscribe
                return $wpdb->update(
                    $this->members_table,
                    array(
                        'unsubscribed_at' => null,
                        'subscribed_at' => current_time('mysql')
                    ),
                    array('list_id' => $list_id, 'email' => $email)
                );
            }
            return true; // Already subscribed
        }
        
        // Add new subscriber
        return $wpdb->insert($this->members_table, array(
            'list_id' => $list_id,
            'user_id' => $user_id,
            'email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'subscribed_at' => current_time('mysql')
        ));
    }
    
    /**
     * Remove a subscriber from a list
     */
    public function remove_subscriber($list_id, $email) {
        global $wpdb;
        
        return $wpdb->update(
            $this->members_table,
            array('unsubscribed_at' => current_time('mysql')),
            array('list_id' => $list_id, 'email' => $email)
        );
    }
    
    /**
     * Get subscribers for a list
     */
    public function get_subscribers($list_id, $include_unsubscribed = false) {
        global $wpdb;
        
        $list = $this->get_list($list_id);
        
        if (!$list) {
            return array();
        }
        
        $subscribers = array();
        
        switch ($list->type) {
            case 'all_users':
                $users = get_users(array('fields' => array('ID', 'user_email', 'display_name')));
                foreach ($users as $user) {
                    $subscribers[] = array(
                        'user_id' => $user->ID,
                        'email' => $user->user_email,
                        'name' => $user->display_name
                    );
                }
                break;
                
            case 'role':
                $criteria = json_decode($list->criteria, true);
                $roles = $criteria['roles'] ?? array();
                
                foreach ($roles as $role) {
                    $users = get_users(array(
                        'role' => $role,
                        'fields' => array('ID', 'user_email', 'display_name')
                    ));
                    
                    foreach ($users as $user) {
                        $subscribers[$user->user_email] = array(
                            'user_id' => $user->ID,
                            'email' => $user->user_email,
                            'name' => $user->display_name
                        );
                    }
                }
                
                $subscribers = array_values($subscribers);
                break;
                
            case 'custom':
                $where = "list_id = %d";
                if (!$include_unsubscribed) {
                    $where .= " AND unsubscribed_at IS NULL";
                }
                
                $members = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$this->members_table} WHERE {$where}",
                    $list_id
                ));
                
                foreach ($members as $member) {
                    $subscribers[] = array(
                        'user_id' => $member->user_id,
                        'email' => $member->email,
                        'name' => trim($member->first_name . ' ' . $member->last_name)
                    );
                }
                break;
        }
        
        // Filter out blocked emails
        $blocked = $this->get_blocked_emails();
        
        return array_filter($subscribers, function($sub) use ($blocked) {
            return !in_array(strtolower($sub['email']), $blocked);
        });
    }
    
    /**
     * Get blocked emails
     */
    private function get_blocked_emails() {
        global $wpdb;
        
        return $wpdb->get_col(
            "SELECT LOWER(email) FROM {$this->bounces_table} WHERE is_blocked = 1"
        );
    }
    
    /**
     * Get subscriber count for a list
     */
    public function get_subscriber_count($list_id) {
        return count($this->get_subscribers($list_id, false));
    }
    
    /**
     * Import subscribers from CSV
     */
    public function import_csv($list_id, $file_path) {
        if (!file_exists($file_path)) {
            return array('success' => false, 'error' => 'File not found');
        }
        
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return array('success' => false, 'error' => 'Could not open file');
        }
        
        $imported = 0;
        $skipped = 0;
        $header = fgetcsv($handle);
        
        // Find column indexes
        $email_col = array_search('email', array_map('strtolower', $header));
        $first_name_col = array_search('first_name', array_map('strtolower', $header));
        $last_name_col = array_search('last_name', array_map('strtolower', $header));
        
        if ($email_col === false) {
            $email_col = 0; // Default to first column
        }
        
        while (($row = fgetcsv($handle)) !== false) {
            $email = trim($row[$email_col] ?? '');
            
            if (!is_email($email)) {
                $skipped++;
                continue;
            }
            
            $first_name = trim($row[$first_name_col] ?? '');
            $last_name = trim($row[$last_name_col] ?? '');
            
            // Check for WordPress user
            $user = get_user_by('email', $email);
            $user_id = $user ? $user->ID : null;
            
            $result = $this->add_subscriber($list_id, $email, $user_id, $first_name, $last_name);
            
            if ($result) {
                $imported++;
            } else {
                $skipped++;
            }
        }
        
        fclose($handle);
        
        Azure_Logger::info("Newsletter: CSV import - {$imported} imported, {$skipped} skipped");
        
        return array(
            'success' => true,
            'imported' => $imported,
            'skipped' => $skipped
        );
    }
    
    /**
     * Export subscribers to CSV
     */
    public function export_csv($list_id) {
        $subscribers = $this->get_subscribers($list_id, true);
        
        $output = fopen('php://temp', 'r+');
        
        // Header
        fputcsv($output, array('email', 'name', 'user_id', 'subscribed'));
        
        // Data
        foreach ($subscribers as $sub) {
            fputcsv($output, array(
                $sub['email'],
                $sub['name'],
                $sub['user_id'],
                'yes'
            ));
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }
    
    /**
     * Sync WordPress users to default list
     */
    public function sync_wordpress_users() {
        // Get or create "All WordPress Users" list
        global $wpdb;
        
        $list = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->lists_table} WHERE type = %s",
            'all_users'
        ));
        
        if (!$list) {
            $list_id = $this->create_list(
                'All WordPress Users',
                'all_users',
                'Automatically synced with WordPress user database'
            );
        } else {
            $list_id = $list->id;
        }
        
        // Get all users
        $users = get_users(array('fields' => array('ID', 'user_email', 'display_name')));
        
        $synced = 0;
        
        foreach ($users as $user) {
            if (is_email($user->user_email)) {
                $result = $this->add_subscriber(
                    $list_id,
                    $user->user_email,
                    $user->ID,
                    '', // First name (could extract from display_name)
                    ''  // Last name
                );
                
                if ($result) {
                    $synced++;
                }
            }
        }
        
        return $synced;
    }
}




