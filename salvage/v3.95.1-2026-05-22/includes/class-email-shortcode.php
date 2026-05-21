<?php
/**
 * Email shortcode handler for Azure Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Email_Shortcode {
    
    private $mailer;
    
    public function __construct() {
        if (class_exists('Azure_Email_Mailer')) {
            $this->mailer = new Azure_Email_Mailer();
        }
        
        // Register shortcodes
        add_shortcode('azure_contact_form', array($this, 'contact_form_shortcode'));
        add_shortcode('azure_email_status', array($this, 'email_status_shortcode'));
        add_shortcode('azure_email_queue', array($this, 'email_queue_shortcode'));
        
        // Handle form submissions
        add_action('wp_ajax_nopriv_azure_contact_form', array($this, 'handle_contact_form'));
        add_action('wp_ajax_azure_contact_form', array($this, 'handle_contact_form'));
        
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
    }
    
    /**
     * Contact form shortcode
     * Usage: [azure_contact_form to="admin@site.com" subject="Contact Form" success_message="Thank you!"]
     */
    public function contact_form_shortcode($atts) {
        $atts = shortcode_atts(array(
            'to' => get_option('admin_email'),
            'subject' => 'Contact Form Submission',
            'success_message' => 'Thank you for your message! We will get back to you soon.',
            'error_message' => 'There was an error sending your message. Please try again.',
            'show_name' => true,
            'show_email' => true,
            'show_phone' => false,
            'show_subject' => true,
            'required_fields' => 'name,email,message',
            'class' => 'azure-contact-form',
            'button_text' => 'Send Message',
            'form_id' => uniqid('azure_form_')
        ), $atts);
        
        if (!$this->mailer) {
            return '<p class="azure-email-error">Email service is not available.</p>';
        }
        
        $required_fields = array_map('trim', explode(',', $atts['required_fields']));
        
        ob_start();
        ?>
        <div class="<?php echo esc_attr($atts['class']); ?>" id="<?php echo esc_attr($atts['form_id']); ?>">
            <form class="azure-contact-form-form" data-form-id="<?php echo esc_attr($atts['form_id']); ?>">
                <?php wp_nonce_field('azure_contact_form', 'azure_contact_nonce'); ?>
                <input type="hidden" name="form_to" value="<?php echo esc_attr($atts['to']); ?>">
                <input type="hidden" name="form_subject" value="<?php echo esc_attr($atts['subject']); ?>">
                <input type="hidden" name="success_message" value="<?php echo esc_attr($atts['success_message']); ?>">
                <input type="hidden" name="error_message" value="<?php echo esc_attr($atts['error_message']); ?>">
                
                <?php if ($atts['show_name']): ?>
                <div class="form-field">
                    <label for="<?php echo esc_attr($atts['form_id']); ?>_name">
                        Name <?php if (in_array('name', $required_fields)): ?><span class="required">*</span><?php endif; ?>
                    </label>
                    <input type="text" 
                           id="<?php echo esc_attr($atts['form_id']); ?>_name" 
                           name="contact_name" 
                           <?php if (in_array('name', $required_fields)): ?>required<?php endif; ?>>
                </div>
                <?php endif; ?>
                
                <?php if ($atts['show_email']): ?>
                <div class="form-field">
                    <label for="<?php echo esc_attr($atts['form_id']); ?>_email">
                        Email <?php if (in_array('email', $required_fields)): ?><span class="required">*</span><?php endif; ?>
                    </label>
                    <input type="email" 
                           id="<?php echo esc_attr($atts['form_id']); ?>_email" 
                           name="contact_email" 
                           <?php if (in_array('email', $required_fields)): ?>required<?php endif; ?>>
                </div>
                <?php endif; ?>
                
                <?php if ($atts['show_phone']): ?>
                <div class="form-field">
                    <label for="<?php echo esc_attr($atts['form_id']); ?>_phone">
                        Phone <?php if (in_array('phone', $required_fields)): ?><span class="required">*</span><?php endif; ?>
                    </label>
                    <input type="tel" 
                           id="<?php echo esc_attr($atts['form_id']); ?>_phone" 
                           name="contact_phone" 
                           <?php if (in_array('phone', $required_fields)): ?>required<?php endif; ?>>
                </div>
                <?php endif; ?>
                
                <?php if ($atts['show_subject']): ?>
                <div class="form-field">
                    <label for="<?php echo esc_attr($atts['form_id']); ?>_subject">
                        Subject <?php if (in_array('subject', $required_fields)): ?><span class="required">*</span><?php endif; ?>
                    </label>
                    <input type="text" 
                           id="<?php echo esc_attr($atts['form_id']); ?>_subject" 
                           name="contact_subject" 
                           <?php if (in_array('subject', $required_fields)): ?>required<?php endif; ?>>
                </div>
                <?php endif; ?>
                
                <div class="form-field">
                    <label for="<?php echo esc_attr($atts['form_id']); ?>_message">
                        Message <?php if (in_array('message', $required_fields)): ?><span class="required">*</span><?php endif; ?>
                    </label>
                    <textarea id="<?php echo esc_attr($atts['form_id']); ?>_message" 
                              name="contact_message" 
                              rows="5" 
                              <?php if (in_array('message', $required_fields)): ?>required<?php endif; ?>></textarea>
                </div>
                
                <div class="form-field">
                    <button type="submit" class="azure-contact-submit">
                        <?php echo esc_html($atts['button_text']); ?>
                    </button>
                </div>
                
                <div class="form-messages"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Email status shortcode
     * Usage: [azure_email_status]
     */
    public function email_status_shortcode($atts) {
        $atts = shortcode_atts(array(
            'show_queue_count' => true,
            'show_method' => true,
            'show_last_sent' => true,
            'class' => 'azure-email-status'
        ), $atts);
        
        if (!current_user_can('manage_options')) {
            return '<p>You do not have permission to view email status.</p>';
        }
        
        global $wpdb;
        $queue_table = Azure_Database::get_table_name('email_queue');
        
        $status_data = array();
        
        if ($queue_table) {
            $status_data['pending_count'] = $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status = 'pending'");
            $status_data['sent_count'] = $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status = 'sent'");
            $status_data['failed_count'] = $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status = 'failed'");
            
            if ($atts['show_last_sent']) {
                $last_sent = $wpdb->get_var("SELECT sent_at FROM {$queue_table} WHERE status = 'sent' ORDER BY sent_at DESC LIMIT 1");
                $status_data['last_sent'] = $last_sent;
            }
        }
        
        $status_data['method'] = Azure_Settings::get_setting('email_auth_method', 'graph_api');
        
        ob_start();
        ?>
        <div class="<?php echo esc_attr($atts['class']); ?>">
            <h4>Email Status</h4>
            
            <?php if ($atts['show_method']): ?>
            <p><strong>Method:</strong> <?php echo esc_html(strtoupper($status_data['method'])); ?></p>
            <?php endif; ?>
            
            <?php if ($atts['show_queue_count'] && isset($status_data['pending_count'])): ?>
            <div class="email-queue-stats">
                <p><strong>Queue Status:</strong></p>
                <ul>
                    <li>Pending: <?php echo intval($status_data['pending_count']); ?></li>
                    <li>Sent: <?php echo intval($status_data['sent_count']); ?></li>
                    <li>Failed: <?php echo intval($status_data['failed_count']); ?></li>
                </ul>
            </div>
            <?php endif; ?>
            
            <?php if ($atts['show_last_sent'] && !empty($status_data['last_sent'])): ?>
            <p><strong>Last Email Sent:</strong> <?php echo esc_html($status_data['last_sent']); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Email queue shortcode (admin only)
     * Usage: [azure_email_queue limit="10"]
     */
    public function email_queue_shortcode($atts) {
        $atts = shortcode_atts(array(
            'limit' => 10,
            'status' => 'all', // all, pending, sent, failed
            'class' => 'azure-email-queue'
        ), $atts);
        
        if (!current_user_can('manage_options')) {
            return '<p>You do not have permission to view the email queue.</p>';
        }
        
        global $wpdb;
        $queue_table = Azure_Database::get_table_name('email_queue');
        
        if (!$queue_table) {
            return '<p>Email queue not available.</p>';
        }
        
        $where_clause = '';
        if ($atts['status'] !== 'all') {
            $where_clause = $wpdb->prepare(' WHERE status = %s', $atts['status']);
        }
        
        $emails = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$queue_table}{$where_clause} ORDER BY created_at DESC LIMIT %d",
            intval($atts['limit'])
        ));
        
        if (empty($emails)) {
            return '<p>No emails in queue.</p>';
        }
        
        ob_start();
        ?>
        <div class="<?php echo esc_attr($atts['class']); ?>">
            <table class="azure-email-queue-table">
                <thead>
                    <tr>
                        <th>To</th>
                        <th>Subject</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Attempts</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($emails as $email): ?>
                    <tr>
                        <td><?php echo esc_html($email->to_email); ?></td>
                        <td><?php echo esc_html(wp_trim_words($email->subject, 8)); ?></td>
                        <td>
                            <span class="status-<?php echo esc_attr($email->status); ?>">
                                <?php echo esc_html(ucfirst($email->status)); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($email->created_at); ?></td>
                        <td><?php echo intval($email->attempts); ?>/<?php echo intval($email->max_attempts); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Handle contact form submission
     */
    public function handle_contact_form() {
        if (!wp_verify_nonce($_POST['azure_contact_nonce'], 'azure_contact_form')) {
            wp_send_json_error('Security check failed');
        }
        
        // Sanitize inputs
        $form_to = sanitize_email($_POST['form_to'] ?? '');
        $form_subject = sanitize_text_field($_POST['form_subject'] ?? 'Contact Form Submission');
        $success_message = sanitize_text_field($_POST['success_message'] ?? 'Thank you for your message!');
        $error_message = sanitize_text_field($_POST['error_message'] ?? 'There was an error sending your message.');
        
        $contact_name = sanitize_text_field($_POST['contact_name'] ?? '');
        $contact_email = sanitize_email($_POST['contact_email'] ?? '');
        $contact_phone = sanitize_text_field($_POST['contact_phone'] ?? '');
        $contact_subject = sanitize_text_field($_POST['contact_subject'] ?? '');
        $contact_message = sanitize_textarea_field($_POST['contact_message'] ?? '');
        
        // Basic validation
        if (empty($form_to) || empty($contact_message)) {
            wp_send_json_error($error_message);
        }
        
        // Build email content
        $email_subject = !empty($contact_subject) ? $contact_subject : $form_subject;
        
        $email_body = "New contact form submission:\n\n";
        if (!empty($contact_name)) {
            $email_body .= "Name: " . $contact_name . "\n";
        }
        if (!empty($contact_email)) {
            $email_body .= "Email: " . $contact_email . "\n";
        }
        if (!empty($contact_phone)) {
            $email_body .= "Phone: " . $contact_phone . "\n";
        }
        $email_body .= "\nMessage:\n" . $contact_message . "\n";
        $email_body .= "\n---\n";
        $email_body .= "Sent from: " . get_site_url() . "\n";
        $email_body .= "Time: " . current_time('mysql') . "\n";
        $email_body .= "IP: " . $_SERVER['REMOTE_ADDR'] . "\n";
        
        // Set reply-to header if email provided
        $headers = array();
        if (!empty($contact_email)) {
            $headers[] = 'Reply-To: ' . $contact_email;
        }
        
        // Send email
        if ($this->mailer) {
            $auth_method = Azure_Settings::get_setting('email_auth_method', 'graph_api');
            
            $success = false;
            
            switch ($auth_method) {
                case 'graph_api':
                    $success = $this->mailer->send_email_graph($form_to, $email_subject, $email_body, $headers);
                    break;
                case 'hve':
                    $success = $this->mailer->send_email_hve($form_to, $email_subject, $email_body, $headers);
                    break;
                case 'acs':
                    $success = $this->mailer->send_email_acs($form_to, $email_subject, $email_body, $headers);
                    break;
            }
            
            if ($success) {
                // Log the contact form submission
                Azure_Database::log_activity('email', 'contact_form_submitted', 'contact_form', null, array(
                    'to' => $form_to,
                    'from_email' => $contact_email,
                    'from_name' => $contact_name
                ));
                
                wp_send_json_success($success_message);
            } else {
                wp_send_json_error($error_message . ' Email has been queued for retry.');
            }
        } else {
            wp_send_json_error($error_message . ' Email service not available.');
        }
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        // Only enqueue if we have email shortcodes on the page
        global $post;
        
        if (!$post) {
            return;
        }
        
        $has_shortcode = has_shortcode($post->post_content, 'azure_contact_form') ||
                        has_shortcode($post->post_content, 'azure_email_status') ||
                        has_shortcode($post->post_content, 'azure_email_queue');
        
        if (!$has_shortcode) {
            return;
        }
        
        // Enqueue styles
        wp_enqueue_style(
            'azure-email-frontend',
            AZURE_PLUGIN_URL . 'css/email-frontend.css',
            array(),
            AZURE_PLUGIN_VERSION
        );
        
        // Enqueue scripts
        wp_enqueue_script(
            'azure-email-frontend',
            AZURE_PLUGIN_URL . 'js/email-frontend.js',
            array('jquery'),
            AZURE_PLUGIN_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('azure-email-frontend', 'azure_email_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('azure_contact_form')
        ));
    }
}
