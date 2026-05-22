<?php
/**
 * Azure Plugin Logger Class - Enhanced with log rotation and crash-safe logging
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Logger {
    
    private static $log_file = '';
    private static $max_file_size = 20971520; // 20MB in bytes
    private static $initialized = false;
    private static $db_table_verified = null;
    private static $db_logging_disabled = false;
    private static $rotation_checked = false;
    
    public static function is_initialized() {
        return self::$initialized;
    }
    
    public static function init() {
        if (self::$initialized) {
            return;
        }
        
        // Store logs in wp-content/uploads to avoid plugin update issues
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/azure-plugin';
        
        // Create log directory if it doesn't exist
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            // Add .htaccess to protect logs
            file_put_contents($log_dir . '/.htaccess', 'Deny from all');
        }
        
        self::$log_file = $log_dir . '/logs.md';
        self::$initialized = true;
        
        // Create log file header if it doesn't exist
        if (!file_exists(self::$log_file)) {
            $header = "# Microsoft WP Debug Logs\n\n";
            $header .= "**Started:** " . date('Y-m-d H:i:s') . "  \n";
            $header .= "**Plugin Version:** " . (defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : 'Unknown') . "  \n";
            $header .= "**WordPress Version:** " . get_bloginfo('version') . "  \n";
            $header .= "**PHP Version:** " . PHP_VERSION . "  \n\n";
            $header .= "---\n\n";
            
            self::write_to_file($header);
        }
        
        // Check file size and rotate if needed
        self::rotate_log_if_needed();
    }
    
    /**
     * Standard logging methods
     * Note: $context can be either an array or a string (for backward compatibility)
     * If string is passed, it's treated as a module name and converted to context array
     */
    public static function info($message, $context = array()) {
        $context = self::normalize_context($context);
        self::log('INFO', $message, $context, '✅');
    }
    
    public static function error($message, $context = array()) {
        $context = self::normalize_context($context);
        self::log('ERROR', $message, $context, '❌');
    }
    
    public static function warning($message, $context = array()) {
        $context = self::normalize_context($context);
        self::log('WARNING', $message, $context, '⚠️');
    }
    
    public static function debug($message, $context = array()) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $context = self::normalize_context($context);
            self::log('DEBUG', $message, $context, '🔍');
        }
    }
    
    /**
     * Normalize context parameter to always be an array
     * Handles backward compatibility where context was passed as a string (module name)
     */
    private static function normalize_context($context) {
        // If context is a string, treat it as a module name (backward compatibility)
        if (is_string($context)) {
            return array('module' => $context);
        }
        
        // If context is already an array, return as-is
        if (is_array($context)) {
            return $context;
        }
        
        // If context is something else (null, etc.), return empty array
        return array();
    }
    
    /**
     * Enhanced logging methods with custom emojis and formatting
     */
    public static function step($message, $step_number = '', $emoji = '⏳') {
        $prefix = !empty($step_number) ? "[STEP {$step_number}]" : "[STEP]";
        self::log_formatted($emoji, $prefix, $message);
    }
    
    public static function success($message, $category = 'SUCCESS', $emoji = '🎉') {
        self::log_formatted($emoji, "[{$category}]", $message);
    }
    
    public static function loading($message, $category = 'LOAD', $emoji = '⏳') {
        self::log_formatted($emoji, "[{$category}]", $message);
    }
    
    public static function complete($message, $category = 'COMPLETE', $emoji = '✅') {
        self::log_formatted($emoji, "[{$category}]", $message);
    }
    
    public static function fatal($message, $location = '', $emoji = '💀') {
        $full_message = $message;
        if (!empty($location)) {
            $full_message .= " - Location: {$location}";
        }
        self::log_formatted($emoji, '[FATAL ERROR]', $full_message);
        self::log('ERROR', $full_message, array('location' => $location));
    }
    
    public static function system($message, $category = 'SYSTEM', $emoji = '🔧') {
        self::log_formatted($emoji, "[{$category}]", $message);
    }
    
    /**
     * Core logging method
     */
    private static function log($level, $message, $context = array(), $emoji = '') {
        if (!self::$initialized) {
            self::init();
        }
        
        $timestamp = date('m-d-Y H:i:s');
        $context_str = !empty($context) ? ' - Context: ' . json_encode($context) : '';
        
        // Extract module name from the message (e.g., "SSO: message" -> "[SSO]")
        $module = 'System';
        if (preg_match('/^([A-Za-z\s]+):\s*(.*)/', $message, $matches)) {
            $module = trim($matches[1]);
            $message = $matches[2];
        }
        
        // Standard log format for parsing
        $log_entry = "{$timestamp} [{$module}] - {$level} - {$message}{$context_str}\n";
        
        self::write_to_file($log_entry);
        
        try {
            self::log_to_database($level, $module, $message, $context);
        } catch (\Throwable $e) {
            error_log('Azure Logger: Database logging failed - ' . $e->getMessage());
            self::$db_logging_disabled = true;
        }
    }
    
    /**
     * Enhanced formatted logging for debugging
     */
    private static function log_formatted($emoji, $category, $message) {
        if (!self::$initialized) {
            self::init();
        }
        
        $timestamp = date('Y-m-d H:i:s');
        
        // Format: **2025-09-18 03:47:36** 🎉 **[SUCCESS]** Message
        $log_entry = "**{$timestamp}** {$emoji} **{$category}** {$message}  \n";
        
        self::write_to_file($log_entry);
    }
    
    /**
     * Safe file writing with crash protection
     */
    private static function write_to_file($content) {
        try {
            if (!self::$rotation_checked) {
                $log_dir = dirname(self::$log_file);
                if (!is_dir($log_dir)) {
                    wp_mkdir_p($log_dir);
                }
                self::rotate_log_if_needed();
                self::$rotation_checked = true;
            }
            
            @file_put_contents(self::$log_file, $content, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Silently fail
        }
    }
    
    /**
     * Log rotation to keep file under 20MB
     */
    private static function rotate_log_if_needed() {
        if (!file_exists(self::$log_file)) {
            return;
        }
        
        $file_size = filesize(self::$log_file);
        
        if ($file_size >= self::$max_file_size) {
            // Create backup with timestamp
            $backup_file = dirname(self::$log_file) . '/logs-backup-' . date('Y-m-d-H-i-s') . '.md';
            
            try {
                // Move current log to backup
                rename(self::$log_file, $backup_file);
                
                // Create new log file with header
                $header = "# Microsoft WP Debug Logs (Rotated)\n\n";
                $header .= "**Previous log backed up to:** " . basename($backup_file) . "  \n";
                $header .= "**Rotated at:** " . date('Y-m-d H:i:s') . "  \n";
                $header .= "**File size was:** " . number_format($file_size / 1024 / 1024, 2) . " MB  \n\n";
                $header .= "---\n\n";
                
                file_put_contents(self::$log_file, $header, LOCK_EX);
                
                // Clean up old backups (keep only last 5)
                self::cleanup_old_backups();
                
            } catch (\Throwable $e) {
                error_log('Azure Logger: Failed to rotate log file - ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Clean up old backup files
     */
    private static function cleanup_old_backups() {
        $log_dir = dirname(self::$log_file);
        $backup_files = glob($log_dir . '/logs-backup-*.md');
        
        if (count($backup_files) > 5) {
            // Sort by modification time (oldest first)
            usort($backup_files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            // Delete oldest files, keep only 5 most recent
            $files_to_delete = array_slice($backup_files, 0, -5);
            foreach ($files_to_delete as $file) {
                @unlink($file);
            }
        }
    }
    
    /**
     * Scheduled cleanup - called daily by WP-Cron
     * Deletes backup logs older than 30 days
     * Cleans up database activity logs older than 90 days
     */
    public static function scheduled_cleanup() {
        try {
            // Delete backup logs older than 30 days
            $log_dir = dirname(self::$log_file);
            $backup_files = glob($log_dir . '/logs-backup-*.md');
            $thirty_days_ago = strtotime('-30 days');
            
            $deleted_count = 0;
            foreach ($backup_files as $file) {
                if (filemtime($file) < $thirty_days_ago) {
                    if (@unlink($file)) {
                        $deleted_count++;
                    }
                }
            }
            
            if ($deleted_count > 0) {
                self::info('Cleaned up old log backups', array(
                    'module' => 'Logger',
                    'deleted_count' => $deleted_count
                ));
            }
            
            // Clean up database activity logs older than 90 days
            if (!class_exists('Azure_Database')) {
                return;
            }
            
            global $wpdb;
            $activity_table = Azure_Database::get_table_name('activity');
            
            if (!$activity_table) {
                return;
            }
            
            $ninety_days_ago = date('Y-m-d H:i:s', strtotime('-90 days'));
            
            $deleted_rows = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$activity_table} WHERE created_at < %s",
                $ninety_days_ago
            ));
            
            if ($deleted_rows > 0) {
                self::info('Cleaned up old database activity logs', array(
                    'module' => 'Logger',
                    'deleted_rows' => $deleted_rows
                ));
            }
            
        } catch (\Throwable $e) {
            self::error('Failed to run scheduled cleanup: ' . $e->getMessage(), array(
                'module' => 'Logger'
            ));
        }
    }
    
    /**
     * Check if debug logging is enabled for a specific module
     * 
     * @param string $module Module name (SSO, Calendar, TEC, etc.)
     * @return bool True if debug is enabled for this module
     */
    public static function is_debug_enabled($module = '') {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return false;
        }
        
        // If no specific module or Azure_Settings not available, allow all
        if (empty($module) || !class_exists('Azure_Settings')) {
            return true;
        }
        
        $debug_mode = Azure_Settings::get_setting('debug_mode', false);
        if (!$debug_mode) {
            return false;
        }
        
        $debug_modules = Azure_Settings::get_setting('debug_modules', array());
        
        // If no specific modules selected, debug all
        if (empty($debug_modules)) {
            return true;
        }
        
        return in_array($module, $debug_modules);
    }
    
    /**
     * Log debug message only if module debugging is enabled
     * 
     * @param string $module Module name (SSO, Calendar, TEC, etc.)
     * @param string $message Log message
     * @param array $context Additional context
     */
    public static function debug_module($module, $message, $context = array()) {
        if (!self::is_debug_enabled($module)) {
            return;
        }
        
        $context['module'] = $module;
        self::debug($message, $context);
    }
    
    /**
     * Get logs for display
     */
    public static function get_logs($lines = 100) {
        if (!file_exists(self::$log_file)) {
            return array();
        }
        
        $logs = file(self::$log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_slice($logs, -$lines);
    }
    
    /**
     * Get formatted logs for display with filtering
     */
    public static function get_formatted_logs($lines = 500, $level_filter = '', $module_filter = '') {
        if (!file_exists(self::$log_file)) {
            return array();
        }
        
        $all_logs = file(self::$log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        // Filter out header lines and apply filters
        $log_lines = array();
        foreach ($all_logs as $line) {
            // Match both standard and formatted log lines
            $is_log_line = (
                preg_match('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2} \[/', $line) ||
                preg_match('/^\*\*\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\*\*/', $line)
            );
            
            if ($is_log_line) {
                $line_lower = strtolower($line);
                if (!empty($level_filter) && strpos($line_lower, ' - ' . strtolower($level_filter) . ' - ') === false) {
                    continue;
                }
                if (!empty($module_filter) && strpos($line_lower, '[' . strtolower($module_filter) . ']') === false) {
                    continue;
                }
                $log_lines[] = $line;
            }
        }
        
        // Return most recent lines
        return array_slice($log_lines, -$lines);
    }
    
    /**
     * Clear all logs
     */
    public static function clear_logs() {
        if (file_exists(self::$log_file)) {
            unlink(self::$log_file);
        }
        
        // Clear backup files
        $log_dir = dirname(self::$log_file);
        $backup_files = glob($log_dir . '/logs-backup-*.md');
        foreach ($backup_files as $file) {
            unlink($file);
        }
        
        // Also clear database logs
        try {
            global $wpdb;
            if (class_exists('Azure_Database')) {
                $activity_table = Azure_Database::get_table_name('activity_log');
                if ($activity_table) {
                    $wpdb->query("DELETE FROM {$activity_table}");
                }
            }
        } catch (\Throwable $e) {
            error_log('Azure Logger: Failed to clear database logs - ' . $e->getMessage());
        }
        
        // Reinitialize with new header
        self::$initialized = false;
        self::init();
    }
    
    /**
     * Get log file size in MB
     */
    public static function get_log_file_size() {
        if (!file_exists(self::$log_file)) {
            return 0;
        }
        
        return round(filesize(self::$log_file) / 1024 / 1024, 2);
    }
    
    /**
     * Get log file path for direct access
     */
    public static function get_log_file_path() {
        return self::$log_file;
    }
    
    /**
     * Log to database for activity tracking
     */
    private static function log_to_database($level, $module, $message, $context = array()) {
        // Never write DEBUG to database - too chatty
        if ($level === 'DEBUG') {
            return;
        }

        // If a previous DB write failed this request, stop trying
        if (self::$db_logging_disabled) {
            return;
        }

        global $wpdb;
        
        if (!class_exists('Azure_Database')) {
            return;
        }
        
        $activity_table = Azure_Database::get_table_name('activity_log');
        if (!$activity_table) {
            return;
        }
        
        // Cache table existence check - only query DB once per request
        if (self::$db_table_verified === null) {
            self::$db_table_verified = ($wpdb->get_var("SHOW TABLES LIKE '$activity_table'") === $activity_table);
        }
        if (!self::$db_table_verified) {
            return;
        }
        
        $status_map = array(
            'ERROR' => 'error',
            'WARNING' => 'warning',
            'INFO' => 'success',
        );
        
        $result = $wpdb->insert(
            $activity_table,
            array(
                'module' => $module,
                'action' => strtolower($module) . '_log',
                'object_type' => 'log_entry',
                'object_id' => null,
                'user_id' => get_current_user_id(),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'details' => json_encode(array(
                    'level' => $level,
                    'message' => $message,
                    'context' => $context
                )),
                'status' => $status_map[$level] ?? 'info'
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
        );

        // If insert failed, disable DB logging for this request
        if ($result === false) {
            self::$db_logging_disabled = true;
        }
    }
}