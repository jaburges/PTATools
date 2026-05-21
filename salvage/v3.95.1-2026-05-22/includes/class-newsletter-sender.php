<?php
/**
 * Newsletter Sender - Email sending abstraction layer
 * 
 * Provides a unified interface for sending emails via different services:
 * - Mailgun
 * - SendGrid
 * - Amazon SES
 * - Custom SMTP
 * - Office 365 (via Azure)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Newsletter_Sender {
    
    private $service_type;
    private $config;
    
    public function __construct($service_type = null) {
        if ($service_type) {
            $this->service_type = $service_type;
        } else {
            $settings = Azure_Settings::get_all_settings();
            $this->service_type = $settings['newsletter_sending_service'] ?? 'smtp';
        }
        
        $this->load_config();
    }
    
    /**
     * Load configuration for the selected service
     */
    private function load_config() {
        $settings = Azure_Settings::get_all_settings();
        
        switch ($this->service_type) {
            case 'mailgun':
                $this->config = array(
                    'api_key' => $settings['newsletter_mailgun_api_key'] ?? '',
                    'domain' => $settings['newsletter_mailgun_domain'] ?? '',
                    'region' => $settings['newsletter_mailgun_region'] ?? 'us'
                );
                break;
                
            case 'sendgrid':
                $this->config = array(
                    'api_key' => $settings['newsletter_sendgrid_api_key'] ?? ''
                );
                break;
                
            case 'ses':
                $this->config = array(
                    'access_key' => $settings['newsletter_ses_access_key'] ?? '',
                    'secret_key' => $settings['newsletter_ses_secret_key'] ?? '',
                    'region' => $settings['newsletter_ses_region'] ?? 'us-east-1'
                );
                break;
                
            case 'smtp':
                $this->config = array(
                    'host' => $settings['newsletter_smtp_host'] ?? '',
                    'port' => $settings['newsletter_smtp_port'] ?? 587,
                    'username' => $settings['newsletter_smtp_username'] ?? '',
                    'password' => $settings['newsletter_smtp_password'] ?? '',
                    'encryption' => $settings['newsletter_smtp_encryption'] ?? 'tls'
                );
                break;
                
            case 'office365':
                // Uses Azure credentials from main plugin
                $this->config = array(
                    'use_azure' => true
                );
                break;
                
            default:
                $this->config = array();
        }
    }
    
    /**
     * Send an email
     * 
     * @param array $args Email arguments
     * @return array Result with 'success' and 'message' or 'error'
     */
    public function send($args) {
        $defaults = array(
            'to' => '',
            'to_name' => '',
            'from' => '',
            'from_name' => '',
            'reply_to' => '',
            'subject' => '',
            'html' => '',
            'text' => '',
            'headers' => array(),
            'tracking_id' => '',
            'newsletter_id' => 0
        );
        
        $args = wp_parse_args($args, $defaults);
        
        // Add tracking pixel and click tracking if newsletter_id is set
        if (!empty($args['newsletter_id']) && !empty($args['html'])) {
            $args['html'] = $this->add_tracking($args['html'], $args['newsletter_id'], $args['to']);
        }
        
        // Choose sending method based on service type
        error_log('[Newsletter Sender] Service type: ' . $this->service_type);
        error_log('[Newsletter Sender] Sending to: ' . $args['to']);
        
        switch ($this->service_type) {
            case 'mailgun':
                error_log('[Newsletter Sender] Routing to Mailgun...');
                return $this->send_via_mailgun($args);
                
            case 'sendgrid':
                error_log('[Newsletter Sender] Routing to SendGrid...');
                return $this->send_via_sendgrid($args);
                
            case 'ses':
                error_log('[Newsletter Sender] Routing to SES...');
                return $this->send_via_ses($args);
                
            case 'smtp':
                error_log('[Newsletter Sender] Routing to SMTP...');
                return $this->send_via_smtp($args);
                
            case 'office365':
                error_log('[Newsletter Sender] Routing to Office 365...');
                return $this->send_via_office365($args);
                
            default:
                error_log('[Newsletter Sender] ERROR: Unknown service type: ' . $this->service_type);
                return array(
                    'success' => false,
                    'error' => 'Unknown sending service: ' . $this->service_type
                );
        }
    }
    
    /**
     * Send via Mailgun API
     */
    private function send_via_mailgun($args) {
        error_log('[Mailgun] Starting send_via_mailgun');
        error_log('[Mailgun] To: ' . $args['to'] . ', Subject: ' . $args['subject']);
        
        if (empty($this->config['api_key']) || empty($this->config['domain'])) {
            error_log('[Mailgun] ERROR: API key or domain not configured');
            return array(
                'success' => false,
                'error' => 'Mailgun API key or domain not configured'
            );
        }
        
        error_log('[Mailgun] Config OK - Domain: ' . $this->config['domain'] . ', Region: ' . ($this->config['region'] ?? 'us'));
        
        $api_base = $this->config['region'] === 'eu' 
            ? 'https://api.eu.mailgun.net/v3/' 
            : 'https://api.mailgun.net/v3/';
        
        $url = $api_base . $this->config['domain'] . '/messages';
        error_log('[Mailgun] API URL: ' . $url);
        
        $data = array(
            'from' => $args['from_name'] ? $args['from_name'] . ' <' . $args['from'] . '>' : $args['from'],
            'to' => $args['to_name'] ? $args['to_name'] . ' <' . $args['to'] . '>' : $args['to'],
            'subject' => $args['subject'],
            'html' => $args['html']
        );
        
        if (!empty($args['text'])) {
            $data['text'] = $args['text'];
        }
        
        if (!empty($args['reply_to'])) {
            $data['h:Reply-To'] = $args['reply_to'];
        }
        
        // Add custom tracking variables
        if (!empty($args['newsletter_id'])) {
            $data['v:newsletter_id'] = $args['newsletter_id'];
        }
        
        error_log('[Mailgun] Making API call to Mailgun...');
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode('api:' . $this->config['api_key'])
            ),
            'body' => $data,
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            error_log('[Mailgun] WP_Error: ' . $error_msg);
            return array(
                'success' => false,
                'error' => $error_msg
            );
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        $body = json_decode($body_raw, true);
        
        error_log('[Mailgun] Response code: ' . $code);
        error_log('[Mailgun] Response body: ' . $body_raw);
        
        if ($code >= 200 && $code < 300) {
            $message_id = $body['id'] ?? null;
            error_log('[Mailgun] SUCCESS! Message ID: ' . $message_id);
            return array(
                'success' => true,
                'message_id' => $message_id
            );
        }
        
        $error_msg = $body['message'] ?? 'Mailgun error (HTTP ' . $code . ')';
        error_log('[Mailgun] FAILED: ' . $error_msg);
        return array(
            'success' => false,
            'error' => $error_msg
        );
    }
    
    /**
     * Send via SendGrid API
     */
    private function send_via_sendgrid($args) {
        if (empty($this->config['api_key'])) {
            return array(
                'success' => false,
                'error' => 'SendGrid API key not configured'
            );
        }
        
        $url = 'https://api.sendgrid.com/v3/mail/send';
        
        $data = array(
            'personalizations' => array(
                array(
                    'to' => array(
                        array(
                            'email' => $args['to'],
                            'name' => $args['to_name'] ?: null
                        )
                    )
                )
            ),
            'from' => array(
                'email' => $args['from'],
                'name' => $args['from_name'] ?: null
            ),
            'subject' => $args['subject'],
            'content' => array(
                array(
                    'type' => 'text/html',
                    'value' => $args['html']
                )
            )
        );
        
        if (!empty($args['text'])) {
            array_unshift($data['content'], array(
                'type' => 'text/plain',
                'value' => $args['text']
            ));
        }
        
        if (!empty($args['reply_to'])) {
            $data['reply_to'] = array('email' => $args['reply_to']);
        }
        
        // Add custom tracking
        if (!empty($args['newsletter_id'])) {
            $data['custom_args'] = array(
                'newsletter_id' => (string)$args['newsletter_id']
            );
        }
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->config['api_key'],
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message()
            );
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $headers = wp_remote_retrieve_headers($response);
        
        if ($code >= 200 && $code < 300) {
            return array(
                'success' => true,
                'message_id' => $headers['x-message-id'] ?? null
            );
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $error = isset($body['errors'][0]['message']) 
            ? $body['errors'][0]['message'] 
            : 'SendGrid error (HTTP ' . $code . ')';
        
        return array(
            'success' => false,
            'error' => $error
        );
    }
    
    /**
     * Send via Amazon SES
     */
    private function send_via_ses($args) {
        if (empty($this->config['access_key']) || empty($this->config['secret_key'])) {
            return array(
                'success' => false,
                'error' => 'Amazon SES credentials not configured'
            );
        }
        
        // Implement AWS SES SDK or raw API call
        // For simplicity, using wp_mail with SES SMTP as fallback
        return $this->send_via_smtp(array_merge($args, array(
            '_host' => 'email-smtp.' . $this->config['region'] . '.amazonaws.com',
            '_port' => 587,
            '_username' => $this->config['access_key'],
            '_password' => $this->config['secret_key'],
            '_encryption' => 'tls'
        )));
    }
    
    /**
     * Send via custom SMTP
     */
    private function send_via_smtp($args) {
        $host = $args['_host'] ?? $this->config['host'];
        $port = $args['_port'] ?? $this->config['port'];
        $username = $args['_username'] ?? $this->config['username'];
        $password = $args['_password'] ?? $this->config['password'];
        $encryption = $args['_encryption'] ?? $this->config['encryption'];
        
        if (empty($host)) {
            return array(
                'success' => false,
                'error' => 'SMTP host not configured'
            );
        }
        
        // Use PHPMailer
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
            require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
            require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
        }
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        try {
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = $port;
            
            if (!empty($username)) {
                $mail->SMTPAuth = true;
                $mail->Username = $username;
                $mail->Password = $password;
            }
            
            if ($encryption !== 'none') {
                $mail->SMTPSecure = $encryption === 'ssl' 
                    ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS 
                    : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }
            
            $mail->setFrom($args['from'], $args['from_name']);
            $mail->addAddress($args['to'], $args['to_name']);
            
            if (!empty($args['reply_to'])) {
                $mail->addReplyTo($args['reply_to']);
            }
            
            $mail->isHTML(true);
            $mail->Subject = $args['subject'];
            $mail->Body = $args['html'];
            
            if (!empty($args['text'])) {
                $mail->AltBody = $args['text'];
            }
            
            $mail->send();
            
            return array(
                'success' => true,
                'message_id' => $mail->getLastMessageID()
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $mail->ErrorInfo
            );
        }
    }
    
    /**
     * Send via Office 365 (Microsoft Graph API)
     */
    private function send_via_office365($args) {
        // Check if we have Azure credentials
        if (!class_exists('Azure_GraphAPI')) {
            return array(
                'success' => false,
                'error' => 'Azure Graph API class not available'
            );
        }
        
        $graph = new Azure_GraphAPI();
        
        $message = array(
            'message' => array(
                'subject' => $args['subject'],
                'body' => array(
                    'contentType' => 'HTML',
                    'content' => $args['html']
                ),
                'toRecipients' => array(
                    array(
                        'emailAddress' => array(
                            'address' => $args['to'],
                            'name' => $args['to_name']
                        )
                    )
                ),
                'from' => array(
                    'emailAddress' => array(
                        'address' => $args['from'],
                        'name' => $args['from_name']
                    )
                )
            ),
            'saveToSentItems' => true
        );
        
        if (!empty($args['reply_to'])) {
            $message['message']['replyTo'] = array(
                array(
                    'emailAddress' => array(
                        'address' => $args['reply_to']
                    )
                )
            );
        }
        
        try {
            $result = $graph->send_mail($message);
            
            if ($result) {
                return array(
                    'success' => true,
                    'message_id' => null
                );
            }
            
            return array(
                'success' => false,
                'error' => 'Failed to send via Office 365'
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Add tracking pixel and click tracking to HTML
     */
    private function add_tracking($html, $newsletter_id, $email) {
        // Generate unique tracking token
        $token = $this->generate_tracking_token($newsletter_id, $email);
        
        // Add open tracking pixel before </body>
        $tracking_pixel = '<img src="' . esc_url(rest_url('azure-plugin/v1/newsletter/track/open/' . $token)) . '" width="1" height="1" style="display:none;" />';
        $html = str_replace('</body>', $tracking_pixel . '</body>', $html);
        
        // Replace links with click tracking
        $html = preg_replace_callback(
            '/<a\s+([^>]*?)href=["\']([^"\']+)["\']([^>]*)>/i',
            function($matches) use ($token) {
                $url = $matches[2];
                
                // Skip tracking for unsubscribe links and anchor links
                if (strpos($url, 'unsubscribe') !== false || strpos($url, '#') === 0) {
                    return $matches[0];
                }
                
                $tracked_url = add_query_arg(array(
                    'url' => urlencode($url)
                ), rest_url('azure-plugin/v1/newsletter/track/click/' . $token));
                
                return '<a ' . $matches[1] . 'href="' . esc_url($tracked_url) . '"' . $matches[3] . '>';
            },
            $html
        );
        
        // Add view in browser link variable replacement
        $view_url = rest_url('azure-plugin/v1/newsletter/view/' . $this->get_newsletter_archive_token($newsletter_id));
        // Replace both regular and URL-encoded versions of the placeholder
        $html = str_replace(
            array('{{view_in_browser_url}}', '%7B%7Bview_in_browser_url%7D%7D', urlencode('{{view_in_browser_url}}')),
            $view_url,
            $html
        );
        
        // Add unsubscribe link variable replacement
        $unsubscribe_url = rest_url('azure-plugin/v1/newsletter/unsubscribe/' . $token);
        // Replace both regular and URL-encoded versions of the placeholder
        $html = str_replace(
            array('{{unsubscribe_url}}', '%7B%7Bunsubscribe_url%7D%7D', urlencode('{{unsubscribe_url}}')),
            $unsubscribe_url,
            $html
        );
        
        return $html;
    }
    
    /**
     * Generate a tracking token
     */
    private function generate_tracking_token($newsletter_id, $email) {
        $data = $newsletter_id . '|' . $email . '|' . wp_salt();
        return base64_encode(hash_hmac('sha256', $data, wp_salt('auth'), true));
    }
    
    /**
     * Get newsletter archive token
     */
    private function get_newsletter_archive_token($newsletter_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'azure_newsletters';
        
        $token = $wpdb->get_var($wpdb->prepare(
            "SELECT archive_token FROM {$table} WHERE id = %d",
            $newsletter_id
        ));
        
        if (!$token) {
            $token = wp_generate_password(32, false);
            $wpdb->update($table, array('archive_token' => $token), array('id' => $newsletter_id));
        }
        
        return $token;
    }
    
    /**
     * Test connection to the configured service
     */
    public function test_connection() {
        switch ($this->service_type) {
            case 'mailgun':
                return $this->test_mailgun();
            case 'sendgrid':
                return $this->test_sendgrid();
            case 'ses':
                return $this->test_ses();
            case 'smtp':
                return $this->test_smtp();
            case 'office365':
                return $this->test_office365();
            default:
                return array('success' => false, 'error' => 'Unknown service');
        }
    }
    
    private function test_mailgun() {
        if (empty($this->config['api_key']) || empty($this->config['domain'])) {
            return array('success' => false, 'error' => 'API key or domain not configured');
        }
        
        $api_base = $this->config['region'] === 'eu' 
            ? 'https://api.eu.mailgun.net/v3/' 
            : 'https://api.mailgun.net/v3/';
        
        $response = wp_remote_get($api_base . 'domains/' . $this->config['domain'], array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode('api:' . $this->config['api_key'])
            )
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        return array('success' => $code === 200, 'error' => $code !== 200 ? 'Invalid credentials' : null);
    }
    
    private function test_sendgrid() {
        if (empty($this->config['api_key'])) {
            return array('success' => false, 'error' => 'API key not configured');
        }
        
        $response = wp_remote_get('https://api.sendgrid.com/v3/scopes', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->config['api_key']
            )
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        return array('success' => $code === 200, 'error' => $code !== 200 ? 'Invalid API key' : null);
    }
    
    private function test_ses() {
        // For SES, we'll try a simple SMTP connection
        return $this->test_smtp();
    }
    
    private function test_smtp() {
        if (empty($this->config['host'])) {
            return array('success' => false, 'error' => 'SMTP host not configured');
        }
        
        if (!class_exists('PHPMailer\PHPMailer\SMTP')) {
            require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
        }
        
        $smtp = new PHPMailer\PHPMailer\SMTP();
        
        try {
            $connected = $smtp->connect($this->config['host'], $this->config['port']);
            
            if ($connected && !empty($this->config['username'])) {
                $smtp->hello(gethostname());
                $encryption = $this->config['encryption'];
                
                if ($encryption === 'tls') {
                    $smtp->startTLS();
                }
                
                $smtp->authenticate($this->config['username'], $this->config['password']);
            }
            
            $smtp->quit();
            
            return array('success' => true);
            
        } catch (Exception $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }
    
    private function test_office365() {
        if (!class_exists('Azure_GraphAPI')) {
            return array('success' => false, 'error' => 'Azure integration not available');
        }
        
        try {
            $graph = new Azure_GraphAPI();
            $user = $graph->get_current_user();
            return array('success' => !empty($user));
        } catch (Exception $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }
}




