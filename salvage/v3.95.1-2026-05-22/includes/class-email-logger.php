<?php
/**
 * Email Logger for Azure Plugin
 * Logs all emails sent through WordPress (any plugin)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Email_Logger {
    
    private static $instance = null;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Hook into wp_mail to log all emails
        add_filter('wp_mail', array($this, 'log_email'), PHP_INT_MAX);
        add_action('wp_mail_failed', array($this, 'log_email_failed'));
        
        // AJAX handlers
        add_action('wp_ajax_azure_get_email_logs', array($this, 'ajax_get_email_logs'));
        add_action('wp_ajax_azure_delete_email_log', array($this, 'ajax_delete_email_log'));
        add_action('wp_ajax_azure_bulk_delete_email_logs', array($this, 'ajax_bulk_delete_email_logs'));
        add_action('wp_ajax_azure_clear_email_logs', array($this, 'ajax_clear_email_logs'));
        add_action('wp_ajax_azure_resend_email', array($this, 'ajax_resend_email'));
        
        // Debug: Log that AJAX handlers are registered
        if (class_exists('Azure_Logger')) {
            Azure_Logger::debug('Email Logger: AJAX handlers registered successfully', 'EmailLogger');
        }
    }
    
    /**
     * Log email being sent
     */
    public function log_email($mail_data) {
        // Don't log if email logging is disabled
        if (!Azure_Settings::get_setting('email_logging_enabled', true)) {
            return $mail_data;
        }
        
        try {
            // Extract email data
            $to = is_array($mail_data['to']) ? implode(',', $mail_data['to']) : $mail_data['to'];
            $subject = $mail_data['subject'];
            $message = $mail_data['message'];
            $headers = is_array($mail_data['headers']) ? implode("\n", $mail_data['headers']) : $mail_data['headers'];
            $attachments = is_array($mail_data['attachments']) ? json_encode($mail_data['attachments']) : $mail_data['attachments'];
            
            // Extract from email from headers
            $from_email = $this->extract_from_email($headers);
            
            // Determine plugin source
            $plugin_source = $this->detect_plugin_source();
            
            // Determine method
            $method = $this->detect_email_method();
            
            // Log to database
            $this->save_email_log(array(
                'to_email' => $to,
                'from_email' => $from_email,
                'subject' => $subject,
                'message' => $message,
                'headers' => $headers,
                'attachments' => $attachments,
                'method' => $method,
                'status' => 'sent',
                'plugin_source' => $plugin_source,
                'user_id' => get_current_user_id(),
                'ip_address' => $this->get_client_ip(),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : ''
            ));
            
            // Note: Email logging is now handled in the email_logs table only
            // Do not log to main system logs to keep them separate
            
        } catch (Exception $e) {
            // Log error to email logs table instead of system logs
            error_log('Azure Email Logger: Failed to log email - ' . $e->getMessage());
        }
        
        return $mail_data;
    }
    
    /**
     * Log failed email
     */
    public function log_email_failed($wp_error) {
        try {
            // Get the last email data from wp_mail args
            $mail_data = $this->get_last_mail_data();
            
            if (!$mail_data) {
                return;
            }
            
            $to = is_array($mail_data['to']) ? implode(',', $mail_data['to']) : $mail_data['to'];
            $error_message = $wp_error->get_error_message();
            
            // Log failed email
            $this->save_email_log(array(
                'to_email' => $to,
                'from_email' => $this->extract_from_email($mail_data['headers'] ?? ''),
                'subject' => $mail_data['subject'] ?? '',
                'message' => $mail_data['message'] ?? '',
                'headers' => is_array($mail_data['headers']) ? implode("\n", $mail_data['headers']) : ($mail_data['headers'] ?? ''),
                'attachments' => is_array($mail_data['attachments']) ? json_encode($mail_data['attachments']) : ($mail_data['attachments'] ?? ''),
                'method' => $this->detect_email_method(),
                'status' => 'failed',
                'error_message' => $error_message,
                'plugin_source' => $this->detect_plugin_source(),
                'user_id' => get_current_user_id(),
                'ip_address' => $this->get_client_ip(),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : ''
            ));
            
            Azure_Logger::error('Email Logger: Logged failed email to ' . $to . ' - ' . $error_message);
            
        } catch (Exception $e) {
            Azure_Logger::error('Email Logger: Failed to log failed email - ' . $e->getMessage());
        }
    }
    
    /**
     * Save email log to database
     */
    private function save_email_log($data) {
        global $wpdb;
        
        $table = Azure_Database::get_table_name('email_logs');
        if (!$table) {
            return false;
        }
        
        return $wpdb->insert(
            $table,
            $data,
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );
    }
    
    /**
     * Extract from email from headers
     */
    private function extract_from_email($headers) {
        if (empty($headers)) {
            return get_option('admin_email');
        }
        
        $headers_array = is_array($headers) ? $headers : explode("\n", $headers);
        
        foreach ($headers_array as $header) {
            if (preg_match('/^From:\s*(.*)$/i', trim($header), $matches)) {
                // Extract email from "Name <email>" format
                if (preg_match('/<([^>]+)>/', $matches[1], $email_matches)) {
                    return trim($email_matches[1]);
                }
                // Return as-is if just email
                return trim($matches[1]);
            }
        }
        
        return get_option('admin_email');
    }
    
    /**
     * Detect plugin source by examining call stack
     */
    private function detect_plugin_source() {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        
        foreach ($trace as $frame) {
            if (isset($frame['file'])) {
                $file = $frame['file'];
                
                // Check if it's from a plugin
                if (strpos($file, WP_PLUGIN_DIR) !== false) {
                    $plugin_path = str_replace(WP_PLUGIN_DIR . '/', '', $file);
                    $plugin_folder = explode('/', $plugin_path)[0];
                    
                    // Skip our own plugin
                    if ($plugin_folder !== 'Azure Plugin') {
                        return $plugin_folder;
                    }
                }
                
                // Check if it's from a theme
                if (strpos($file, get_template_directory()) !== false) {
                    return 'Theme: ' . get_template();
                }
            }
        }
        
        return 'WordPress Core';
    }
    
    /**
     * Detect email sending method
     */
    private function detect_email_method() {
        // Check if Azure Plugin email is overriding wp_mail
        $auth_method = Azure_Settings::get_setting('email_auth_method', 'graph_api');
        
        if ($auth_method === 'graph_api' && Azure_Settings::get_setting('email_override_wp_mail', false)) {
            return 'Azure Graph API';
        } elseif ($auth_method === 'hve' && Azure_Settings::get_setting('email_hve_override_wp_mail', false)) {
            return 'Azure HVE';
        } elseif ($auth_method === 'acs' && Azure_Settings::get_setting('email_acs_override_wp_mail', false)) {
            return 'Azure ACS';
        }
        
        // Check for common SMTP plugins
        if (function_exists('wp_mail_smtp')) {
            return 'WP Mail SMTP';
        }
        
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            return 'PHPMailer';
        }
        
        return 'WordPress Default';
    }
    
    /**
     * Get last mail data (for failed email logging)
     */
    private function get_last_mail_data() {
        // This is a simplified version - in a real implementation,
        // you might need to store the mail data temporarily
        return null;
    }
    
    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip_fields = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        
        foreach ($ip_fields as $field) {
            if (!empty($_SERVER[$field])) {
                $ip = $_SERVER[$field];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    /**
     * Get email logs (AJAX)
     */
    public function ajax_get_email_logs() {
        // Debug: Log that AJAX handler was called
        if (class_exists('Azure_Logger')) {
            Azure_Logger::debug('Email Logger: ajax_get_email_logs called', 'EmailLogger');
        }
        
        if (!current_user_can('manage_options') || !isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::error('Email Logger: ajax_get_email_logs unauthorized access', 'EmailLogger');
            }
            wp_send_json_error('Unauthorized');
        }
        
        try {
            $page = intval($_POST['page'] ?? 1);
            $per_page = intval($_POST['per_page'] ?? 50);
            $search = sanitize_text_field($_POST['search'] ?? '');
            $status_filter = sanitize_text_field($_POST['status'] ?? '');
            $method_filter = sanitize_text_field($_POST['method'] ?? '');
            $date_from = sanitize_text_field($_POST['date_from'] ?? '');
            $date_to = sanitize_text_field($_POST['date_to'] ?? '');
            
            $logs = $this->get_email_logs($page, $per_page, $search, $status_filter, $method_filter, $date_from, $date_to);
            $total = $this->get_email_logs_count($search, $status_filter, $method_filter, $date_from, $date_to);
            
            wp_send_json_success(array(
                'logs' => $logs,
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => ceil($total / $per_page)
            ));
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Get email logs from database
     */
    public function get_email_logs($page = 1, $per_page = 50, $search = '', $status_filter = '', $method_filter = '', $date_from = '', $date_to = '') {
        global $wpdb;
        
        $table = Azure_Database::get_table_name('email_logs');
        if (!$table) {
            return array();
        }
        
        $offset = ($page - 1) * $per_page;
        
        $where_conditions = array('1=1');
        $where_values = array();
        
        // Search conditions
        if (!empty($search)) {
            $where_conditions[] = "(to_email LIKE %s OR subject LIKE %s OR from_email LIKE %s)";
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }
        
        // Status filter
        if (!empty($status_filter)) {
            $where_conditions[] = "status = %s";
            $where_values[] = $status_filter;
        }
        
        // Method filter
        if (!empty($method_filter)) {
            $where_conditions[] = "method = %s";
            $where_values[] = $method_filter;
        }
        
        // Date range filter
        if (!empty($date_from)) {
            $where_conditions[] = "timestamp >= %s";
            $where_values[] = $date_from . ' 00:00:00';
        }
        
        if (!empty($date_to)) {
            $where_conditions[] = "timestamp <= %s";
            $where_values[] = $date_to . ' 23:59:59';
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $query = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY timestamp DESC LIMIT %d OFFSET %d";
        $where_values[] = $per_page;
        $where_values[] = $offset;
        
        if (!empty($where_values)) {
            $prepared_query = $wpdb->prepare($query, $where_values);
        } else {
            $prepared_query = $query;
        }
        
        return $wpdb->get_results($prepared_query);
    }
    
    /**
     * Get total email logs count
     */
    public function get_email_logs_count($search = '', $status_filter = '', $method_filter = '', $date_from = '', $date_to = '') {
        global $wpdb;
        
        $table = Azure_Database::get_table_name('email_logs');
        if (!$table) {
            return 0;
        }
        
        $where_conditions = array('1=1');
        $where_values = array();
        
        // Same filtering logic as get_email_logs
        if (!empty($search)) {
            $where_conditions[] = "(to_email LIKE %s OR subject LIKE %s OR from_email LIKE %s)";
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }
        
        if (!empty($status_filter)) {
            $where_conditions[] = "status = %s";
            $where_values[] = $status_filter;
        }
        
        if (!empty($method_filter)) {
            $where_conditions[] = "method = %s";
            $where_values[] = $method_filter;
        }
        
        if (!empty($date_from)) {
            $where_conditions[] = "timestamp >= %s";
            $where_values[] = $date_from . ' 00:00:00';
        }
        
        if (!empty($date_to)) {
            $where_conditions[] = "timestamp <= %s";
            $where_values[] = $date_to . ' 23:59:59';
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        $query = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";
        
        if (!empty($where_values)) {
            $prepared_query = $wpdb->prepare($query, $where_values);
        } else {
            $prepared_query = $query;
        }
        
        return intval($wpdb->get_var($prepared_query));
    }
    
    /**
     * Clear email logs (AJAX)
     */
    public function ajax_clear_email_logs() {
        if (!current_user_can('manage_options') || !isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }
        
        try {
            global $wpdb;
            $table = Azure_Database::get_table_name('email_logs');
            
            if (!$table) {
                wp_send_json_error('Email logs table not found');
                return;
            }
            
            $deleted = $wpdb->query("DELETE FROM {$table}");
            
            Azure_Logger::info('Email Logger: Cleared all email logs (' . $deleted . ' records)');
            
            wp_send_json_success(array(
                'message' => "Successfully deleted {$deleted} email log records",
                'deleted' => $deleted
            ));
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Delete specific email log (AJAX)
     */
    public function ajax_delete_email_log() {
        if (!current_user_can('manage_options') || !isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Unauthorized');
        }
        
        try {
            $log_id = intval($_POST['log_id'] ?? 0);
            
            if (!$log_id) {
                wp_send_json_error('Invalid log ID');
                return;
            }
            
            global $wpdb;
            $table = Azure_Database::get_table_name('email_logs');
            
            if (!$table) {
                wp_send_json_error('Email logs table not found');
                return;
            }
            
            $deleted = $wpdb->delete($table, array('id' => $log_id), array('%d'));
            
            if ($deleted) {
                wp_send_json_success('Email log deleted successfully');
            } else {
                wp_send_json_error('Failed to delete email log');
            }
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Get email statistics
     */
    public function get_email_stats() {
        global $wpdb;
        
        $table = Azure_Database::get_table_name('email_logs');
        if (!$table) {
            return array();
        }
        
        $stats = array();
        
        // Total emails
        $stats['total'] = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table}"));
        
        // Today's emails
        $stats['today'] = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE DATE(timestamp) = CURDATE()"));
        
        // Failed emails today
        $stats['failed_today'] = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE DATE(timestamp) = CURDATE() AND status = 'failed'"));
        
        // Success rate today
        if ($stats['today'] > 0) {
            $stats['success_rate_today'] = round((($stats['today'] - $stats['failed_today']) / $stats['today']) * 100, 1);
        } else {
            $stats['success_rate_today'] = 100;
        }
        
        // Top methods
        $stats['methods'] = $wpdb->get_results("
            SELECT method, COUNT(*) as count 
            FROM {$table} 
            WHERE DATE(timestamp) >= DATE_SUB(CURDATE(), INTERVAL 7 DAYS)
            GROUP BY method 
            ORDER BY count DESC 
            LIMIT 5
        ");
        
        // Recent errors
        $stats['recent_errors'] = $wpdb->get_results("
            SELECT to_email, subject, error_message, timestamp 
            FROM {$table} 
            WHERE status = 'failed' 
            ORDER BY timestamp DESC 
            LIMIT 5
        ");
        
        return $stats;
    }
}

// Initialize email logger
Azure_Email_Logger::get_instance();
