<?php
/**
 * Email mailer for Azure Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Email_Mailer {
    
    private $settings;
    private $auth;
    
    public function __construct() {
        $this->settings = Azure_Settings::get_all_settings();
        
        if (class_exists('Azure_Email_Auth')) {
            $this->auth = new Azure_Email_Auth();
        }
        
        // Override WordPress mail function if enabled
        $this->setup_mail_override();
        
        // AJAX handlers
        add_action('wp_ajax_azure_send_test_email', array($this, 'ajax_send_test_email'));
        add_action('wp_ajax_azure_process_email_queue', array($this, 'ajax_process_email_queue'));
        add_action('wp_ajax_azure_clear_email_queue', array($this, 'ajax_clear_email_queue'));
        
        // Schedule queue processing
        add_action('init', array($this, 'schedule_queue_processing'));
        add_action('azure_process_email_queue', array($this, 'process_email_queue'));
    }
    
    /**
     * Setup mail override based on configuration
     */
    private function setup_mail_override() {
        $auth_method = Azure_Settings::get_setting('email_auth_method', 'graph_api');
        
        if ($auth_method === 'graph_api' && Azure_Settings::get_setting('email_override_wp_mail', false)) {
            add_filter('pre_wp_mail', array($this, 'override_wp_mail_graph'), 10, 2);
        } elseif ($auth_method === 'hve' && Azure_Settings::get_setting('email_hve_override_wp_mail', false)) {
            add_filter('pre_wp_mail', array($this, 'override_wp_mail_hve'), 10, 2);
        } elseif ($auth_method === 'acs' && Azure_Settings::get_setting('email_acs_override_wp_mail', false)) {
            add_filter('pre_wp_mail', array($this, 'override_wp_mail_acs'), 10, 2);
        }
    }
    
    /**
     * Send email via Microsoft Graph API
     */
    public function send_email_graph($to, $subject, $message, $headers = array(), $attachments = array(), $user_email = null) {
        Azure_Logger::info('Email: Starting Graph API email send');
        
        if (!$this->auth) {
            Azure_Logger::error('Email: Authentication service not available');
            return false;
        }
        
        // Determine sender email
        $from_email = $this->get_from_email($headers, $user_email);
        
        if (!$from_email) {
            Azure_Logger::error('Email: No sender email determined');
            return false;
        }
        
        // Get access token
        $access_token = $this->auth->get_user_access_token($from_email);
        
        if (!$access_token) {
            Azure_Logger::error('Email: No access token available for: ' . $from_email);
            // Try application token as fallback
            $access_token = $this->auth->get_app_access_token();
            
            if (!$access_token) {
                return $this->queue_email($to, $subject, $message, $headers, $attachments, 'No access token available');
            }
        }
        
        try {
            // Build email message
            $email_data = $this->build_graph_email($to, $subject, $message, $headers, $attachments, $from_email);
            
            // Send via Graph API
            $result = $this->send_via_graph_api($email_data, $access_token, $from_email);
            
            if ($result) {
                Azure_Logger::info('Email: Successfully sent via Graph API to: ' . $to);
                Azure_Database::log_activity('email', 'email_sent', 'email', null, array(
                    'to' => $to,
                    'subject' => $subject,
                    'method' => 'graph_api'
                ));
                
                // Log to email logger as well
                $this->log_email_success($to, $from_email, $subject, $message, $headers, $attachments, 'Azure Graph API');
                
                return true;
            } else {
                // Log failed email
                $this->log_email_failure($to, $from_email, $subject, $message, $headers, $attachments, 'Azure Graph API', 'Graph API send failed');
                return $this->queue_email($to, $subject, $message, $headers, $attachments, 'Graph API send failed');
            }
            
        } catch (Exception $e) {
            Azure_Logger::error('Email: Exception in Graph API send: ' . $e->getMessage());
            
            // Log failed email
            $this->log_email_failure($to, $from_email, $subject, $message, $headers, $attachments, 'Azure Graph API', $e->getMessage());
            
            return $this->queue_email($to, $subject, $message, $headers, $attachments, $e->getMessage());
        }
    }
    
    /**
     * Send email via HVE (High Volume Email)
     */
    public function send_email_hve($to, $subject, $message, $headers = array(), $attachments = array()) {
        Azure_Logger::info('Email: Starting HVE SMTP email send');
        
        $smtp_config = array(
            'host' => Azure_Settings::get_setting('email_hve_smtp_server', 'smtp-hve.office365.com'),
            'port' => Azure_Settings::get_setting('email_hve_smtp_port', 587),
            'username' => Azure_Settings::get_setting('email_hve_username', ''),
            'password' => Azure_Settings::get_setting('email_hve_password', ''),
            'encryption' => Azure_Settings::get_setting('email_hve_encryption', 'tls'),
            'from_email' => Azure_Settings::get_setting('email_hve_from_email', ''),
        );
        
        if (empty($smtp_config['username']) || empty($smtp_config['password'])) {
            Azure_Logger::error('Email: HVE credentials not configured');
            return $this->queue_email($to, $subject, $message, $headers, $attachments, 'HVE credentials not configured');
        }
        
        try {
            $result = $this->send_via_smtp($to, $subject, $message, $headers, $attachments, $smtp_config);
            
            if ($result) {
                Azure_Logger::info('Email: Successfully sent via HVE SMTP to: ' . $to);
                Azure_Database::log_activity('email', 'email_sent', 'email', null, array(
                    'to' => $to,
                    'subject' => $subject,
                    'method' => 'hve'
                ));
                return true;
            } else {
                return $this->queue_email($to, $subject, $message, $headers, $attachments, 'HVE SMTP send failed');
            }
            
        } catch (Exception $e) {
            Azure_Logger::error('Email: Exception in HVE send: ' . $e->getMessage());
            return $this->queue_email($to, $subject, $message, $headers, $attachments, $e->getMessage());
        }
    }
    
    /**
     * Send email via ACS (Azure Communication Services)
     */
    public function send_email_acs($to, $subject, $message, $headers = array(), $attachments = array()) {
        Azure_Logger::info('Email: Starting ACS email send');
        
        $acs_config = array(
            'connection_string' => Azure_Settings::get_setting('email_acs_connection_string', ''),
            'endpoint' => Azure_Settings::get_setting('email_acs_endpoint', ''),
            'access_key' => Azure_Settings::get_setting('email_acs_access_key', ''),
            'from_email' => Azure_Settings::get_setting('email_acs_from_email', ''),
            'from_name' => Azure_Settings::get_setting('email_acs_display_name', ''),
        );
        
        if (empty($acs_config['endpoint']) || empty($acs_config['access_key'])) {
            Azure_Logger::error('Email: ACS credentials not configured');
            return $this->queue_email($to, $subject, $message, $headers, $attachments, 'ACS credentials not configured');
        }
        
        try {
            $result = $this->send_via_acs($to, $subject, $message, $headers, $attachments, $acs_config);
            
            if ($result) {
                Azure_Logger::info('Email: Successfully sent via ACS to: ' . $to);
                Azure_Database::log_activity('email', 'email_sent', 'email', null, array(
                    'to' => $to,
                    'subject' => $subject,
                    'method' => 'acs'
                ));
                return true;
            } else {
                return $this->queue_email($to, $subject, $message, $headers, $attachments, 'ACS send failed');
            }
            
        } catch (Exception $e) {
            Azure_Logger::error('Email: Exception in ACS send: ' . $e->getMessage());
            return $this->queue_email($to, $subject, $message, $headers, $attachments, $e->getMessage());
        }
    }
    
    /**
     * Build email data for Graph API
     */
    private function build_graph_email($to, $subject, $message, $headers, $attachments, $from_email) {
        // Parse recipients
        $recipients = $this->parse_recipients($to);
        
        // Parse headers
        $parsed_headers = $this->parse_headers($headers);
        
        // Build message structure
        $email_data = array(
            'message' => array(
                'subject' => $subject,
                'body' => array(
                    'contentType' => $this->is_html($message, $parsed_headers) ? 'HTML' : 'Text',
                    'content' => $message
                ),
                'toRecipients' => $recipients['to'],
                'from' => array(
                    'emailAddress' => array(
                        'address' => $from_email
                    )
                )
            ),
            'saveToSentItems' => true
        );
        
        // Add CC recipients
        if (!empty($recipients['cc'])) {
            $email_data['message']['ccRecipients'] = $recipients['cc'];
        }
        
        // Add BCC recipients
        if (!empty($recipients['bcc'])) {
            $email_data['message']['bccRecipients'] = $recipients['bcc'];
        }
        
        // Add reply-to
        if (!empty($parsed_headers['reply-to'])) {
            $email_data['message']['replyTo'] = array(
                array('emailAddress' => array('address' => $parsed_headers['reply-to']))
            );
        }
        
        // Add attachments
        if (!empty($attachments)) {
            $email_data['message']['attachments'] = $this->build_attachments($attachments);
        }
        
        return $email_data;
    }
    
    /**
     * Send via Microsoft Graph API
     */
    private function send_via_graph_api($email_data, $access_token, $from_email) {
        // Determine API endpoint
        $send_as_alias = Azure_Settings::get_setting('email_send_as_alias', '');
        
        if (!empty($send_as_alias) && $send_as_alias !== $from_email) {
            $api_url = "https://graph.microsoft.com/v1.0/users/{$send_as_alias}/sendMail";
        } else {
            $api_url = 'https://graph.microsoft.com/v1.0/me/sendMail';
        }
        
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($email_data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            Azure_Logger::error('Email: Graph API request failed - ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 202) {
            return true; // Email accepted for sending
        } else {
            $response_body = wp_remote_retrieve_body($response);
            Azure_Logger::error('Email: Graph API send failed with status ' . $response_code . ': ' . $response_body);
            return false;
        }
    }
    
    /**
     * Send via SMTP (HVE)
     */
    private function send_via_smtp($to, $subject, $message, $headers, $attachments, $smtp_config) {
        // Use PHPMailer if available
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
            require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
            require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
        }
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host = $smtp_config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $smtp_config['username'];
            $mail->Password = $smtp_config['password'];
            $mail->SMTPSecure = $smtp_config['encryption'] === 'ssl' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $smtp_config['port'];
            
            // Recipients
            $mail->setFrom($smtp_config['from_email'], get_bloginfo('name'));
            
            if (is_array($to)) {
                foreach ($to as $recipient) {
                    $mail->addAddress($recipient);
                }
            } else {
                $mail->addAddress($to);
            }
            
            // Parse headers for CC, BCC, etc.
            $parsed_headers = $this->parse_headers($headers);
            
            if (!empty($parsed_headers['cc'])) {
                $cc_recipients = explode(',', $parsed_headers['cc']);
                foreach ($cc_recipients as $cc) {
                    $mail->addCC(trim($cc));
                }
            }
            
            if (!empty($parsed_headers['bcc'])) {
                $bcc_recipients = explode(',', $parsed_headers['bcc']);
                foreach ($bcc_recipients as $bcc) {
                    $mail->addBCC(trim($bcc));
                }
            }
            
            // Content
            $mail->isHTML($this->is_html($message, $parsed_headers));
            $mail->Subject = $subject;
            $mail->Body = $message;
            
            // Attachments
            if (!empty($attachments)) {
                foreach ($attachments as $attachment) {
                    if (is_string($attachment)) {
                        $mail->addAttachment($attachment);
                    } elseif (is_array($attachment) && isset($attachment['path'])) {
                        $mail->addAttachment($attachment['path'], $attachment['name'] ?? '');
                    }
                }
            }
            
            $mail->send();
            return true;
            
        } catch (Exception $e) {
            Azure_Logger::error('Email: PHPMailer error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send via Azure Communication Services
     */
    private function send_via_acs($to, $subject, $message, $headers, $attachments, $acs_config) {
        // Build ACS API request
        $api_url = $acs_config['endpoint'] . '/emails:send?api-version=2023-03-31';
        
        $email_data = array(
            'senderAddress' => $acs_config['from_email'],
            'content' => array(
                'subject' => $subject,
                'html' => $this->is_html($message, $this->parse_headers($headers)) ? $message : null,
                'plainText' => !$this->is_html($message, $this->parse_headers($headers)) ? $message : wp_strip_all_tags($message)
            ),
            'recipients' => array(
                'to' => array()
            )
        );
        
        // Add recipients
        if (is_array($to)) {
            foreach ($to as $recipient) {
                $email_data['recipients']['to'][] = array('address' => $recipient);
            }
        } else {
            $email_data['recipients']['to'][] = array('address' => $to);
        }
        
        // Generate authentication signature
        $auth_header = $this->generate_acs_auth_header($acs_config['access_key'], 'POST', $api_url, json_encode($email_data));
        
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => $auth_header,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($email_data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            Azure_Logger::error('Email: ACS request failed - ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 202) {
            return true; // Email accepted for sending
        } else {
            $response_body = wp_remote_retrieve_body($response);
            Azure_Logger::error('Email: ACS send failed with status ' . $response_code . ': ' . $response_body);
            return false;
        }
    }
    
    /**
     * Queue email for later processing
     */
    private function queue_email($to, $subject, $message, $headers, $attachments, $error_message = null) {
        global $wpdb;
        $table = Azure_Database::get_table_name('email_queue');
        
        if (!$table) {
            Azure_Logger::error('Email: Queue table not available');
            return false;
        }
        
        $result = $wpdb->insert(
            $table,
            array(
                'to_email' => is_array($to) ? implode(',', $to) : $to,
                'subject' => $subject,
                'message' => $message,
                'headers' => json_encode($headers),
                'attachments' => json_encode($attachments),
                'status' => 'pending',
                'error_message' => $error_message,
                'scheduled_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        if ($result) {
            Azure_Logger::info('Email: Queued email for later processing');
            return true;
        } else {
            Azure_Logger::error('Email: Failed to queue email');
            return false;
        }
    }
    
    /**
     * Process email queue
     */
    public function process_email_queue() {
        global $wpdb;
        $table = Azure_Database::get_table_name('email_queue');
        
        if (!$table) {
            return;
        }
        
        // Get pending emails
        $emails = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = 'pending' AND attempts < max_attempts ORDER BY priority DESC, scheduled_at ASC LIMIT 10"
        ));
        
        foreach ($emails as $email) {
            Azure_Logger::info('Email: Processing queued email ID: ' . $email->id);
            
            // Increment attempt counter
            $wpdb->update(
                $table,
                array('attempts' => $email->attempts + 1),
                array('id' => $email->id),
                array('%d'),
                array('%d')
            );
            
            $headers = json_decode($email->headers, true) ?: array();
            $attachments = json_decode($email->attachments, true) ?: array();
            
            // Determine send method
            $auth_method = Azure_Settings::get_setting('email_auth_method', 'graph_api');
            
            $success = false;
            
            switch ($auth_method) {
                case 'graph_api':
                    $success = $this->send_email_graph($email->to_email, $email->subject, $email->message, $headers, $attachments);
                    break;
                case 'hve':
                    $success = $this->send_email_hve($email->to_email, $email->subject, $email->message, $headers, $attachments);
                    break;
                case 'acs':
                    $success = $this->send_email_acs($email->to_email, $email->subject, $email->message, $headers, $attachments);
                    break;
            }
            
            if ($success) {
                // Mark as sent
                $wpdb->update(
                    $table,
                    array(
                        'status' => 'sent',
                        'sent_at' => current_time('mysql')
                    ),
                    array('id' => $email->id),
                    array('%s', '%s'),
                    array('%d')
                );
            } elseif ($email->attempts + 1 >= $email->max_attempts) {
                // Mark as failed
                $wpdb->update(
                    $table,
                    array('status' => 'failed'),
                    array('id' => $email->id),
                    array('%s'),
                    array('%d')
                );
            }
        }
    }
    
    /**
     * WordPress mail override handlers
     */
    public function override_wp_mail_graph($null, $atts) {
        $result = $this->send_email_graph(
            $atts['to'],
            $atts['subject'],
            $atts['message'],
            $atts['headers'] ?? array(),
            $atts['attachments'] ?? array()
        );
        
        return $result ? true : null; // Return true to bypass wp_mail, null to continue
    }
    
    public function override_wp_mail_hve($null, $atts) {
        $result = $this->send_email_hve(
            $atts['to'],
            $atts['subject'],
            $atts['message'],
            $atts['headers'] ?? array(),
            $atts['attachments'] ?? array()
        );
        
        return $result ? true : null;
    }
    
    public function override_wp_mail_acs($null, $atts) {
        $result = $this->send_email_acs(
            $atts['to'],
            $atts['subject'],
            $atts['message'],
            $atts['headers'] ?? array(),
            $atts['attachments'] ?? array()
        );
        
        return $result ? true : null;
    }
    
    /**
     * Helper methods
     */
    private function get_from_email($headers, $user_email = null) {
        if ($user_email) {
            return $user_email;
        }
        
        $parsed_headers = $this->parse_headers($headers);
        
        if (!empty($parsed_headers['from'])) {
            return $parsed_headers['from'];
        }
        
        return get_option('admin_email');
    }
    
    private function parse_recipients($to) {
        $recipients = array('to' => array(), 'cc' => array(), 'bcc' => array());
        
        if (is_string($to)) {
            $to = array($to);
        }
        
        foreach ($to as $email) {
            $recipients['to'][] = array('emailAddress' => array('address' => trim($email)));
        }
        
        return $recipients;
    }
    
    private function parse_headers($headers) {
        $parsed = array();
        
        if (is_array($headers)) {
            foreach ($headers as $header) {
                if (strpos($header, ':') !== false) {
                    list($name, $value) = explode(':', $header, 2);
                    $parsed[strtolower(trim($name))] = trim($value);
                }
            }
        }
        
        return $parsed;
    }
    
    private function is_html($message, $headers) {
        return (isset($headers['content-type']) && strpos($headers['content-type'], 'text/html') !== false) ||
               (strpos($message, '<html') !== false || strpos($message, '<body') !== false);
    }
    
    private function build_attachments($attachments) {
        $graph_attachments = array();
        
        foreach ($attachments as $attachment) {
            if (is_string($attachment) && file_exists($attachment)) {
                $content = base64_encode(file_get_contents($attachment));
                $graph_attachments[] = array(
                    '@odata.type' => '#microsoft.graph.fileAttachment',
                    'name' => basename($attachment),
                    'contentType' => mime_content_type($attachment),
                    'contentBytes' => $content
                );
            }
        }
        
        return $graph_attachments;
    }
    
    private function generate_acs_auth_header($access_key, $method, $url, $body) {
        $timestamp = gmdate('D, d M Y H:i:s \G\M\T');
        $parsed_url = parse_url($url);
        $path_and_query = $parsed_url['path'] . (isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '');
        
        $string_to_sign = $method . "\n" . $path_and_query . "\n" . $timestamp . ";" . $parsed_url['host'] . ";" . hash('sha256', $body);
        $signature = base64_encode(hash_hmac('sha256', $string_to_sign, base64_decode($access_key), true));
        
        return "HMAC-SHA256 SignedHeaders=x-ms-date;host;x-ms-content-sha256&Signature=" . $signature;
    }
    
    private function schedule_queue_processing() {
        if (!wp_next_scheduled('azure_process_email_queue')) {
            wp_schedule_event(time() + 300, 'five_minutes', 'azure_process_email_queue'); // Process every 5 minutes
        }
    }
    
    /**
     * AJAX handlers
     */
    public function ajax_send_test_email() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_die('Unauthorized access');
        }
        
        $test_email = sanitize_email($_POST['test_email'] ?? '');
        
        if (empty($test_email)) {
            wp_send_json_error('Test email address is required');
        }
        
        $auth_method = Azure_Settings::get_setting('email_auth_method', 'graph_api');
        
        $subject = 'Azure Plugin Test Email';
        $message = "This is a test email sent from the Azure Plugin.\n\nTime: " . current_time('mysql') . "\nMethod: " . $auth_method;
        
        $success = false;
        
        switch ($auth_method) {
            case 'graph_api':
                $success = $this->send_email_graph($test_email, $subject, $message);
                break;
            case 'hve':
                $success = $this->send_email_hve($test_email, $subject, $message);
                break;
            case 'acs':
                $success = $this->send_email_acs($test_email, $subject, $message);
                break;
        }
        
        if ($success) {
            wp_send_json_success('Test email sent successfully');
        } else {
            wp_send_json_error('Failed to send test email. Check the email queue for more details.');
        }
    }
    
    public function ajax_process_email_queue() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_die('Unauthorized access');
        }
        
        $this->process_email_queue();
        
        wp_send_json_success('Email queue processed');
    }
    
    public function ajax_clear_email_queue() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_die('Unauthorized access');
        }
        
        global $wpdb;
        $table = Azure_Database::get_table_name('email_queue');
        
        if (!$table) {
            wp_send_json_error('Email queue table not found');
        }
        
        $result = $wpdb->query("DELETE FROM {$table} WHERE status IN ('sent', 'failed')");
        
        wp_send_json_success("Cleared {$result} emails from queue");
    }
    
    /**
     * Log successful email to email logs
     */
    private function log_email_success($to, $from, $subject, $message, $headers, $attachments, $method) {
        if (!class_exists('Azure_Email_Logger')) {
            return;
        }
        
        try {
            global $wpdb;
            $table = Azure_Database::get_table_name('email_logs');
            
            if (!$table) {
                return;
            }
            
            $wpdb->insert(
                $table,
                array(
                    'to_email' => is_array($to) ? implode(',', $to) : $to,
                    'from_email' => $from,
                    'subject' => $subject,
                    'message' => Azure_Settings::get_setting('email_log_content', true) ? $message : '[Content not logged]',
                    'headers' => is_array($headers) ? implode("\n", $headers) : $headers,
                    'attachments' => is_array($attachments) ? json_encode($attachments) : $attachments,
                    'method' => $method,
                    'status' => 'sent',
                    'plugin_source' => 'Azure Plugin',
                    'user_id' => get_current_user_id(),
                    'ip_address' => $this->get_client_ip(),
                    'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : ''
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
            );
        } catch (Exception $e) {
            Azure_Logger::error('Email Mailer: Failed to log successful email - ' . $e->getMessage());
        }
    }
    
    /**
     * Log failed email to email logs
     */
    private function log_email_failure($to, $from, $subject, $message, $headers, $attachments, $method, $error) {
        if (!class_exists('Azure_Email_Logger')) {
            return;
        }
        
        try {
            global $wpdb;
            $table = Azure_Database::get_table_name('email_logs');
            
            if (!$table) {
                return;
            }
            
            $wpdb->insert(
                $table,
                array(
                    'to_email' => is_array($to) ? implode(',', $to) : $to,
                    'from_email' => $from,
                    'subject' => $subject,
                    'message' => Azure_Settings::get_setting('email_log_content', true) ? $message : '[Content not logged]',
                    'headers' => is_array($headers) ? implode("\n", $headers) : $headers,
                    'attachments' => is_array($attachments) ? json_encode($attachments) : $attachments,
                    'method' => $method,
                    'status' => 'failed',
                    'error_message' => $error,
                    'plugin_source' => 'Azure Plugin',
                    'user_id' => get_current_user_id(),
                    'ip_address' => $this->get_client_ip(),
                    'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : ''
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
            );
        } catch (Exception $e) {
            Azure_Logger::error('Email Mailer: Failed to log failed email - ' . $e->getMessage());
        }
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
}
