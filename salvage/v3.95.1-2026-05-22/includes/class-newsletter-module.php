<?php
/**
 * Newsletter Module - Main initialization class
 * 
 * Provides newsletter creation, sending via SMTP services (Mailgun, SendGrid, etc.),
 * webhook-based tracking, queue-based batch sending, and WordPress subscriber integration.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Newsletter_Module {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance == null) {
            self::$instance = new Azure_Newsletter_Module();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Initialize hooks
        add_action('init', array($this, 'init'));
        
        // Register custom post status for published newsletters (WordPress pages)
        add_action('init', array($this, 'register_post_status'));
        
        // Enqueue admin styles and scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // WP-Cron event handlers. Custom intervals + the schedule_event calls
        // are owned by Azure_PTA_Cron; we only bind the action handlers here.
        add_action('azure_newsletter_process_queue', array($this, 'process_queue'));
        add_action('azure_newsletter_check_bounces', array($this, 'check_bounces'));
        add_action('azure_newsletter_weekly_validation', array($this, 'weekly_email_validation'));
        add_action('azure_newsletter_sync_mailgun_stats', array($this, 'sync_mailgun_stats'));
        
        // Dashboard widget
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
        
        // REST API endpoints for webhooks
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        Azure_Logger::debug_module('Newsletter', 'Newsletter module initialized');
    }
    
    /**
     * Enqueue admin styles and scripts for newsletter pages
     */
    public function enqueue_admin_assets($hook) {
        // Only load on newsletter admin pages
        if (strpos($hook, 'azure-plugin-newsletter') === false) {
            return;
        }
        
        // Newsletter admin CSS
        wp_enqueue_style(
            'azure-newsletter-admin',
            AZURE_PLUGIN_URL . 'css/newsletter-admin.css',
            array(),
            AZURE_PLUGIN_VERSION
        );
        
        // Newsletter admin JS
        wp_enqueue_script(
            'azure-newsletter-admin',
            AZURE_PLUGIN_URL . 'js/newsletter-admin.js',
            array('jquery'),
            AZURE_PLUGIN_VERSION,
            true
        );
        
        // Localize script with AJAX URL and nonces
        wp_localize_script('azure-newsletter-admin', 'azureNewsletter', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('azure_newsletter_nonce'),
            'strings' => array(
                'confirmDelete' => __('Are you sure you want to delete this?', 'azure-plugin'),
                'saving' => __('Saving...', 'azure-plugin'),
                'saved' => __('Saved!', 'azure-plugin'),
                'error' => __('Error occurred', 'azure-plugin'),
                'recipients' => __('recipients', 'azure-plugin'),
            )
        ));
    }
    
    /**
     * Initialize module components
     */
    public function init() {
        // Load additional module classes if they exist
        $this->load_module_classes();
    }
    
    /**
     * Load additional module classes
     */
    private function load_module_classes() {
        $classes = array(
            'class-newsletter-queue.php',
            'class-newsletter-sender.php',
            'class-newsletter-tracking.php',
            'class-newsletter-lists.php',
            'class-newsletter-bounce.php',
            'class-newsletter-templates.php',
            'class-newsletter-ajax.php'
        );
        
        foreach ($classes as $class_file) {
            $file_path = AZURE_PLUGIN_PATH . 'includes/' . $class_file;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }
    }
    
    /**
     * Register custom post status
     */
    public function register_post_status() {
        register_post_status('newsletter-archive', array(
            'label'                     => _x('Newsletter Archive', 'post status', 'azure-plugin'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Newsletter Archive (%s)', 'Newsletter Archive (%s)', 'azure-plugin')
        ));
    }
    
    
    /**
     * Process the email queue
     */
    public function process_queue() {
        if (class_exists('Azure_Newsletter_Queue')) {
            $queue = new Azure_Newsletter_Queue();
            $result = $queue->process_batch();
            
            if (!empty($result['sent'])) {
                Azure_Logger::info('Newsletter queue: ' . $result['sent'] . '/' . $result['total'] . ' emails sent successfully');
            }
            
            if (!empty($result['failed'])) {
                Azure_Logger::warning('Newsletter queue: ' . $result['failed'] . ' emails failed');
            }
        }
    }
    
    /**
     * Check bounces via Office 365 IMAP
     */
    public function check_bounces() {
        if (class_exists('Azure_Newsletter_Bounce')) {
            $bounce_handler = new Azure_Newsletter_Bounce();
            $bounce_handler->process_imap_bounces();
        }
    }
    
    /**
     * Weekly email list validation
     */
    public function weekly_email_validation() {
        if (class_exists('Azure_Newsletter_Bounce')) {
            $bounce_handler = new Azure_Newsletter_Bounce();
            $bounce_handler->validate_email_list();
        }
    }
    
    /**
     * Sync Mailgun stats (hourly cron)
     * Pulls delivered/opened/clicked/bounced events from Mailgun API
     */
    public function sync_mailgun_stats() {
        $settings = Azure_Settings::get_all_settings();
        $api_key = $settings['newsletter_mailgun_api_key'] ?? '';
        $domain = $settings['newsletter_mailgun_domain'] ?? '';
        $region = $settings['newsletter_mailgun_region'] ?? 'us';
        
        if (empty($api_key) || empty($domain)) {
            return; // Mailgun not configured
        }
        
        global $wpdb;
        $stats_table = $wpdb->prefix . 'azure_newsletter_stats';
        
        $api_base = $region === 'eu' 
            ? 'https://api.eu.mailgun.net/v3/' 
            : 'https://api.mailgun.net/v3/';
        
        // Fetch last 2 hours of events (for hourly cron with some overlap)
        $begin = date('r', strtotime('-2 hours'));
        $end = date('r');
        
        $url = $api_base . $domain . '/events?' . http_build_query(array(
            'begin' => $begin,
            'end' => $end,
            'limit' => 300,
            'event' => 'delivered OR opened OR clicked OR failed OR complained'
        ));
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode('api:' . $api_key)
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            Azure_Logger::warning('Mailgun stats sync failed', array(
                'error' => is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response)
            ));
            return;
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $events = $data['items'] ?? array();
        $count = 0;
        
        // Event type mapping
        $event_map = array(
            'delivered' => 'delivered',
            'opened' => 'opened',
            'clicked' => 'clicked',
            'failed' => 'bounced',
            'complained' => 'complained'
        );
        
        foreach ($events as $event) {
            $event_type = $event['event'] ?? '';
            $mapped_type = $event_map[$event_type] ?? null;
            
            if (!$mapped_type) continue;
            
            $email = $event['recipient'] ?? '';
            $newsletter_id = $event['user-variables']['newsletter_id'] ?? null;
            $timestamp = $event['timestamp'] ?? time();
            $created_at = date('Y-m-d H:i:s', $timestamp);
            
            // Check for duplicates
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$stats_table} 
                 WHERE email = %s AND event_type = %s AND created_at = %s
                 LIMIT 1",
                $email, $mapped_type, $created_at
            ));
            
            if (!$existing && $email) {
                $insert_data = array(
                    'newsletter_id' => $newsletter_id,
                    'email' => $email,
                    'event_type' => $mapped_type,
                    'created_at' => $created_at,
                    'event_data' => json_encode($event)
                );
                
                if ($mapped_type === 'clicked' && isset($event['url'])) {
                    $insert_data['link_url'] = $event['url'];
                }
                
                if ($wpdb->insert($stats_table, $insert_data)) {
                    $count++;
                }
            }
        }
        
        if ($count > 0) {
            Azure_Logger::info("Mailgun stats sync: imported {$count} events");
        }
    }
    
    /**
     * Add dashboard widget
     */
    public function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'azure_newsletter_stats',
            __('Newsletter Statistics', 'azure-plugin'),
            array($this, 'render_dashboard_widget')
        );
    }
    
    /**
     * Render dashboard widget
     */
    public function render_dashboard_widget() {
        global $wpdb;
        
        $newsletters_table = $wpdb->prefix . 'azure_newsletters';
        $stats_table = $wpdb->prefix . 'azure_newsletter_stats';
        $lists_table = $wpdb->prefix . 'azure_newsletter_list_members';
        
        // Check if tables exist
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$newsletters_table}'") === $newsletters_table;
        
        if (!$table_exists) {
            echo '<p>' . __('Newsletter module tables not yet created.', 'azure-plugin') . '</p>';
            return;
        }
        
        // Get total subscribers (WordPress users with 'subscriber' role)
        $subscriber_count = count_users();
        $total_subscribers = isset($subscriber_count['avail_roles']['subscriber']) ? $subscriber_count['avail_roles']['subscriber'] : 0;
        
        // Get emails sent this month
        $first_of_month = date('Y-m-01 00:00:00');
        $emails_this_month = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$stats_table} WHERE event_type = 'sent' AND created_at >= %s",
            $first_of_month
        ));
        
        // Get average open rate
        $open_stats = $wpdb->get_row("
            SELECT 
                COUNT(CASE WHEN event_type = 'sent' THEN 1 END) as sent,
                COUNT(DISTINCT CASE WHEN event_type = 'opened' THEN email END) as opened
            FROM {$stats_table}
        ");
        $avg_open_rate = ($open_stats && $open_stats->sent > 0) 
            ? round(($open_stats->opened / $open_stats->sent) * 100, 1) 
            : 0;
        
        // Get average click rate
        $click_stats = $wpdb->get_row("
            SELECT 
                COUNT(CASE WHEN event_type = 'sent' THEN 1 END) as sent,
                COUNT(DISTINCT CASE WHEN event_type = 'clicked' THEN email END) as clicked
            FROM {$stats_table}
        ");
        $avg_click_rate = ($click_stats && $click_stats->sent > 0) 
            ? round(($click_stats->clicked / $click_stats->sent) * 100, 1) 
            : 0;
        
        // Get recent campaigns
        $recent_campaigns = $wpdb->get_results("
            SELECT id, name, status, sent_at,
                (SELECT COUNT(*) FROM {$stats_table} WHERE newsletter_id = n.id AND event_type = 'sent') as sent_count,
                (SELECT COUNT(DISTINCT email) FROM {$stats_table} WHERE newsletter_id = n.id AND event_type = 'opened') as open_count
            FROM {$newsletters_table} n
            WHERE status IN ('sent', 'sending')
            ORDER BY sent_at DESC
            LIMIT 5
        ");
        
        ?>
        <div class="azure-newsletter-widget">
            <div class="newsletter-stats-grid">
                <div class="stat-box">
                    <span class="stat-number"><?php echo number_format($total_subscribers ?: 0); ?></span>
                    <span class="stat-label"><?php _e('Subscribers', 'azure-plugin'); ?></span>
                </div>
                <div class="stat-box">
                    <span class="stat-number"><?php echo number_format($emails_this_month ?: 0); ?></span>
                    <span class="stat-label"><?php _e('Sent This Month', 'azure-plugin'); ?></span>
                </div>
                <div class="stat-box">
                    <span class="stat-number"><?php echo $avg_open_rate; ?>%</span>
                    <span class="stat-label"><?php _e('Avg Open Rate', 'azure-plugin'); ?></span>
                </div>
                <div class="stat-box">
                    <span class="stat-number"><?php echo $avg_click_rate; ?>%</span>
                    <span class="stat-label"><?php _e('Avg Click Rate', 'azure-plugin'); ?></span>
                </div>
            </div>
            
            <?php if (!empty($recent_campaigns)): ?>
            <h4><?php _e('Recent Campaigns', 'azure-plugin'); ?></h4>
            <ul class="recent-campaigns">
                <?php foreach ($recent_campaigns as $campaign): 
                    $campaign_open_rate = ($campaign->sent_count > 0) 
                        ? round(($campaign->open_count / $campaign->sent_count) * 100, 1) 
                        : 0;
                ?>
                <li>
                    <span class="campaign-name"><?php echo esc_html($campaign->name); ?></span>
                    <span class="campaign-stats">
                        <?php echo number_format($campaign->sent_count); ?> <?php _e('sent', 'azure-plugin'); ?> · 
                        <?php echo $campaign_open_rate; ?>% <?php _e('opens', 'azure-plugin'); ?>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            
            <p class="newsletter-actions">
                <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter&action=new'); ?>" class="button button-primary">
                    <?php _e('Create Newsletter', 'azure-plugin'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=azure-plugin-newsletter'); ?>" class="button">
                    <?php _e('View All', 'azure-plugin'); ?>
                </a>
            </p>
        </div>
        
        <style>
            .azure-newsletter-widget .newsletter-stats-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
                margin-bottom: 15px;
            }
            .azure-newsletter-widget .stat-box {
                background: #f8f9fa;
                padding: 10px;
                text-align: center;
                border-radius: 4px;
            }
            .azure-newsletter-widget .stat-number {
                display: block;
                font-size: 20px;
                font-weight: 600;
                color: #1d2327;
            }
            .azure-newsletter-widget .stat-label {
                display: block;
                font-size: 11px;
                color: #646970;
                text-transform: uppercase;
            }
            .azure-newsletter-widget .recent-campaigns {
                margin: 0 0 15px;
                padding: 0;
                list-style: none;
            }
            .azure-newsletter-widget .recent-campaigns li {
                display: flex;
                justify-content: space-between;
                padding: 5px 0;
                border-bottom: 1px solid #eee;
            }
            .azure-newsletter-widget .campaign-name {
                font-weight: 500;
            }
            .azure-newsletter-widget .campaign-stats {
                color: #646970;
                font-size: 12px;
            }
            .azure-newsletter-widget .newsletter-actions {
                margin: 0;
            }
        </style>
        <?php
    }
    
    /**
     * Register REST API routes for webhooks
     */
    public function register_rest_routes() {
        // Mailgun webhook
        register_rest_route('azure-plugin/v1', '/newsletter/webhook/mailgun', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_mailgun_webhook'),
            'permission_callback' => '__return_true'
        ));
        
        // SendGrid webhook
        register_rest_route('azure-plugin/v1', '/newsletter/webhook/sendgrid', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_sendgrid_webhook'),
            'permission_callback' => '__return_true'
        ));
        
        // Amazon SES webhook
        register_rest_route('azure-plugin/v1', '/newsletter/webhook/ses', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_ses_webhook'),
            'permission_callback' => '__return_true'
        ));
        
        // View in browser
        register_rest_route('azure-plugin/v1', '/newsletter/view/(?P<token>[a-zA-Z0-9]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_view_in_browser'),
            'permission_callback' => '__return_true'
        ));
        
        // Tracking pixel
        register_rest_route('azure-plugin/v1', '/newsletter/track/open/(?P<token>[a-zA-Z0-9]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_track_open'),
            'permission_callback' => '__return_true'
        ));
        
        // Click tracking redirect
        register_rest_route('azure-plugin/v1', '/newsletter/track/click/(?P<token>[a-zA-Z0-9]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_track_click'),
            'permission_callback' => '__return_true'
        ));
        
        // Unsubscribe
        register_rest_route('azure-plugin/v1', '/newsletter/unsubscribe/(?P<token>[a-zA-Z0-9]+)', array(
            'methods' => array('GET', 'POST'),
            'callback' => array($this, 'handle_unsubscribe'),
            'permission_callback' => '__return_true'
        ));
    }
    
    /**
     * Handle Mailgun webhook
     */
    public function handle_mailgun_webhook($request) {
        if (class_exists('Azure_Newsletter_Tracking')) {
            $tracking = new Azure_Newsletter_Tracking();
            return $tracking->process_mailgun_webhook($request);
        }
        return new WP_REST_Response(array('status' => 'ok'), 200);
    }
    
    /**
     * Handle SendGrid webhook
     */
    public function handle_sendgrid_webhook($request) {
        if (class_exists('Azure_Newsletter_Tracking')) {
            $tracking = new Azure_Newsletter_Tracking();
            return $tracking->process_sendgrid_webhook($request);
        }
        return new WP_REST_Response(array('status' => 'ok'), 200);
    }
    
    /**
     * Handle Amazon SES webhook
     */
    public function handle_ses_webhook($request) {
        if (class_exists('Azure_Newsletter_Tracking')) {
            $tracking = new Azure_Newsletter_Tracking();
            return $tracking->process_ses_webhook($request);
        }
        return new WP_REST_Response(array('status' => 'ok'), 200);
    }
    
    /**
     * Handle view in browser
     */
    public function handle_view_in_browser($request) {
        $token = $request->get_param('token');
        
        global $wpdb;
        $table = $wpdb->prefix . 'azure_newsletters';
        
        $newsletter = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE archive_token = %s",
            $token
        ));
        
        if (!$newsletter) {
            return new WP_REST_Response('Newsletter not found', 404);
        }
        
        // Return HTML content
        header('Content-Type: text/html; charset=utf-8');
        echo $newsletter->content_html;
        exit;
    }
    
    /**
     * Handle open tracking
     */
    public function handle_track_open($request) {
        if (class_exists('Azure_Newsletter_Tracking')) {
            $tracking = new Azure_Newsletter_Tracking();
            $tracking->record_open($request->get_param('token'));
        }
        
        // Return 1x1 transparent GIF
        header('Content-Type: image/gif');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }
    
    /**
     * Handle click tracking
     */
    public function handle_track_click($request) {
        $token = $request->get_param('token');
        $url = $request->get_param('url');
        
        if (class_exists('Azure_Newsletter_Tracking')) {
            $tracking = new Azure_Newsletter_Tracking();
            $tracking->record_click($token, $url);
        }
        
        // Redirect to actual URL
        if (!empty($url)) {
            wp_redirect(esc_url($url));
            exit;
        }
        
        return new WP_REST_Response('Invalid URL', 400);
    }
    
    /**
     * Handle unsubscribe
     */
    public function handle_unsubscribe($request) {
        $token = $request->get_param('token');
        
        if (class_exists('Azure_Newsletter_Lists')) {
            $lists = new Azure_Newsletter_Lists();
            $result = $lists->process_unsubscribe($token);
            
            if ($result['success']) {
                // Show unsubscribe confirmation page
                $html = '<!DOCTYPE html>
                <html>
                <head>
                    <title>' . esc_html__('Unsubscribed', 'azure-plugin') . '</title>
                    <style>
                        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
                               max-width: 600px; margin: 100px auto; text-align: center; padding: 20px; }
                        h1 { color: #1d2327; }
                        p { color: #646970; }
                    </style>
                </head>
                <body>
                    <h1>' . esc_html__('You have been unsubscribed', 'azure-plugin') . '</h1>
                    <p>' . esc_html__('You will no longer receive newsletters from us.', 'azure-plugin') . '</p>
                </body>
                </html>';
                
                return new WP_REST_Response($html, 200, array('Content-Type' => 'text/html'));
            }
        }
        
        return new WP_REST_Response('Invalid unsubscribe link', 400);
    }
    
    /**
     * Create database tables for newsletters
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Newsletters/Campaigns table
        $table_newsletters = $wpdb->prefix . 'azure_newsletters';
        $sql_newsletters = "CREATE TABLE $table_newsletters (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            subject varchar(255) NOT NULL,
            from_name varchar(100) NOT NULL,
            from_email varchar(100) NOT NULL,
            content_html longtext,
            content_json longtext,
            status enum('draft','scheduled','sending','sent','paused') DEFAULT 'draft',
            scheduled_at datetime NULL,
            sent_at datetime NULL,
            archive_token varchar(64) NULL,
            wp_page_id bigint(20) UNSIGNED NULL,
            page_category varchar(100) DEFAULT 'newsletter',
            created_by bigint(20) UNSIGNED,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY archive_token (archive_token),
            KEY status (status),
            KEY scheduled_at (scheduled_at),
            KEY created_by (created_by)
        ) $charset_collate;";
        
        // Newsletter Queue table
        $table_queue = $wpdb->prefix . 'azure_newsletter_queue';
        $sql_queue = "CREATE TABLE $table_queue (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            newsletter_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) UNSIGNED NULL,
            email varchar(255) NOT NULL,
            status enum('pending','sent','failed','bounced') DEFAULT 'pending',
            attempts tinyint UNSIGNED DEFAULT 0,
            scheduled_at datetime NOT NULL,
            sent_at datetime NULL,
            error_message text NULL,
            PRIMARY KEY (id),
            KEY idx_status_scheduled (status, scheduled_at),
            KEY idx_newsletter (newsletter_id),
            UNIQUE KEY unique_send (newsletter_id, email)
        ) $charset_collate;";
        
        // Newsletter Stats table
        $table_stats = $wpdb->prefix . 'azure_newsletter_stats';
        $sql_stats = "CREATE TABLE $table_stats (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            newsletter_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) UNSIGNED NULL,
            email varchar(255) NOT NULL,
            event_type enum('sent','delivered','opened','clicked','bounced','unsubscribed','complained') NOT NULL,
            event_data text NULL,
            link_url varchar(2048) NULL,
            link_text varchar(255) NULL,
            link_position int NULL,
            ip_address varchar(45) NULL,
            user_agent varchar(512) NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_newsletter_event (newsletter_id, event_type),
            KEY idx_email (email),
            KEY idx_created_at (created_at)
        ) $charset_collate;";
        
        // Newsletter Lists table
        $table_lists = $wpdb->prefix . 'azure_newsletter_lists';
        $sql_lists = "CREATE TABLE $table_lists (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            description text,
            type enum('all_users','role','tag','custom') NOT NULL,
            criteria json NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        // Newsletter List Members table
        $table_list_members = $wpdb->prefix . 'azure_newsletter_list_members';
        $sql_list_members = "CREATE TABLE $table_list_members (
            list_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) UNSIGNED NULL,
            email varchar(255) NOT NULL,
            first_name varchar(100) NULL,
            last_name varchar(100) NULL,
            subscribed_at datetime DEFAULT CURRENT_TIMESTAMP,
            unsubscribed_at datetime NULL,
            PRIMARY KEY (list_id, email),
            KEY idx_email (email),
            KEY idx_user_id (user_id)
        ) $charset_collate;";
        
        // Newsletter Bounces table
        $table_bounces = $wpdb->prefix . 'azure_newsletter_bounces';
        $sql_bounces = "CREATE TABLE $table_bounces (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            email varchar(255) NOT NULL,
            bounce_type enum('hard','soft','complaint') NOT NULL,
            bounce_count tinyint UNSIGNED DEFAULT 1,
            last_bounce_at datetime DEFAULT CURRENT_TIMESTAMP,
            is_blocked tinyint(1) DEFAULT 0,
            bounce_reason text NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_email (email),
            KEY idx_blocked (is_blocked)
        ) $charset_collate;";
        
        // Newsletter Templates table
        $table_templates = $wpdb->prefix . 'azure_newsletter_templates';
        $sql_templates = "CREATE TABLE $table_templates (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            description text,
            thumbnail_url varchar(500),
            content_html longtext,
            content_json longtext,
            category varchar(50) DEFAULT 'general',
            is_system tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_category (category),
            KEY idx_is_system (is_system)
        ) $charset_collate;";
        
        // Newsletter Sending Config table
        $table_sending_config = $wpdb->prefix . 'azure_newsletter_sending_config';
        $sql_sending_config = "CREATE TABLE $table_sending_config (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            service_type enum('mailgun','sendgrid','ses','smtp','office365') NOT NULL,
            is_active tinyint(1) DEFAULT 0,
            config json NOT NULL,
            from_addresses json NULL,
            webhook_secret varchar(255) NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_service_type (service_type),
            KEY idx_is_active (is_active)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($sql_newsletters);
        dbDelta($sql_queue);
        dbDelta($sql_stats);
        dbDelta($sql_lists);
        dbDelta($sql_list_members);
        dbDelta($sql_bounces);
        dbDelta($sql_templates);
        dbDelta($sql_sending_config);
        
        // Insert default templates
        self::insert_default_templates();
        
        Azure_Logger::info('Newsletter module database tables created successfully');
    }
    
    /**
     * Insert default email templates
     */
    private static function insert_default_templates() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'azure_newsletter_templates';
        
        // Check if templates already exist
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count > 0) {
            return;
        }
        
        $templates = self::get_default_templates();
        
        foreach ($templates as $template) {
            $wpdb->insert($table, $template);
        }
    }
    
    /**
     * Get default template definitions with HTML content
     */
    public static function get_default_templates() {
        $site_name = get_bloginfo('name');
        $site_url = home_url();
        
        return array(
            // Template 1: Two-Column Feature
            array(
                'name' => 'Two-Column Feature',
                'description' => 'Alternating image and text columns with header and footer',
                'category' => 'general',
                'is_system' => 1,
                'content_html' => self::get_template_two_column_feature($site_name, $site_url)
            ),
            // Template 2: Simple Newsletter
            array(
                'name' => 'Simple Newsletter',
                'description' => 'Clean single-column layout perfect for updates',
                'category' => 'general',
                'is_system' => 1,
                'content_html' => self::get_template_simple_newsletter($site_name, $site_url)
            ),
            // Template 3: Announcement
            array(
                'name' => 'Announcement',
                'description' => 'Bold centered announcement with call-to-action',
                'category' => 'general',
                'is_system' => 1,
                'content_html' => self::get_template_announcement($site_name, $site_url)
            ),
            // Template 4: Event Invite
            array(
                'name' => 'Event Invite',
                'description' => 'Event details with date, time, location, and RSVP',
                'category' => 'events',
                'is_system' => 1,
                'content_html' => self::get_template_event_invite($site_name, $site_url)
            ),
            // Template 5: Welcome Email
            array(
                'name' => 'Welcome',
                'description' => 'Friendly welcome with getting started steps',
                'category' => 'onboarding',
                'is_system' => 1,
                'content_html' => self::get_template_welcome($site_name, $site_url)
            )
        );
    }
    
    /**
     * Template: Two-Column Feature
     */
    private static function get_template_two_column_feature($site_name, $site_url) {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . esc_html($site_name) . '</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f4; font-family: Arial, Helvetica, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f4f4f4;">
        <tr>
            <td align="center" style="padding: 20px 0;">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width: 600px; width: 100%; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <!-- Header Image -->
                    <tr>
                        <td style="padding: 0;">
                            <img src="https://placehold.co/600x200/0073aa/ffffff?text=Your+Header+Image" alt="Header" width="600" style="display: block; width: 100%; max-width: 600px; height: auto;">
                        </td>
                    </tr>
                    
                    <!-- Section 1: Heading Left, Image Right -->
                    <tr>
                        <td style="padding: 30px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td width="50%" valign="top" style="padding-right: 15px;">
                                        <h2 style="margin: 0 0 15px 0; color: #333333; font-size: 24px; line-height: 1.3;">Featured Story Headline</h2>
                                        <p style="margin: 0; color: #666666; font-size: 15px; line-height: 1.6;">Add your main story content here. This template is perfect for featuring important news or updates with eye-catching visuals.</p>
                                    </td>
                                    <td width="50%" valign="top" style="padding-left: 15px;">
                                        <img src="https://placehold.co/270x180/e8e8e8/999999?text=Feature+Image" alt="Feature" width="270" style="display: block; width: 100%; max-width: 270px; height: auto; border-radius: 6px;">
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Divider -->
                    <tr>
                        <td style="padding: 0 30px;">
                            <hr style="border: none; border-top: 1px solid #eeeeee; margin: 0;">
                        </td>
                    </tr>
                    
                    <!-- Section 2: Image Left, Heading Right -->
                    <tr>
                        <td style="padding: 30px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td width="50%" valign="top" style="padding-right: 15px;">
                                        <img src="https://placehold.co/270x180/e8e8e8/999999?text=Second+Image" alt="Second Feature" width="270" style="display: block; width: 100%; max-width: 270px; height: auto; border-radius: 6px;">
                                    </td>
                                    <td width="50%" valign="top" style="padding-left: 15px;">
                                        <h2 style="margin: 0 0 15px 0; color: #333333; font-size: 24px; line-height: 1.3;">Second Story Headline</h2>
                                        <p style="margin: 0; color: #666666; font-size: 15px; line-height: 1.6;">Continue with another piece of content. The alternating layout keeps readers engaged and makes it easy to scan.</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 30px; background-color: #333333;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="center">
                                        <p style="margin: 0 0 10px 0; color: #ffffff; font-size: 16px; font-weight: bold;">' . esc_html($site_name) . '</p>
                                        <p style="margin: 0 0 15px 0; color: #cccccc; font-size: 13px;">
                                            <a href="{{view_in_browser_url}}" style="color: #cccccc; text-decoration: underline;">View in browser</a> &bull;
                                            <a href="{{unsubscribe_url}}" style="color: #cccccc; text-decoration: underline;">Unsubscribe</a>
                                        </p>
                                        <p style="margin: 0; color: #999999; font-size: 12px;">&copy; ' . date('Y') . ' ' . esc_html($site_name) . '. All rights reserved.</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }
    
    /**
     * Template: Simple Newsletter
     */
    private static function get_template_simple_newsletter($site_name, $site_url) {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . esc_html($site_name) . '</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f4; font-family: Arial, Helvetica, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f4f4f4;">
        <tr>
            <td align="center" style="padding: 20px 0;">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width: 600px; width: 100%; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="padding: 40px 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: bold;">' . esc_html($site_name) . '</h1>
                            <p style="margin: 10px 0 0 0; color: rgba(255,255,255,0.9); font-size: 14px;">Newsletter</p>
                        </td>
                    </tr>
                    
                    <!-- Main Content -->
                    <tr>
                        <td style="padding: 40px 30px;">
                            <h2 style="margin: 0 0 20px 0; color: #333333; font-size: 22px;">Your Headline Here</h2>
                            <p style="margin: 0 0 20px 0; color: #666666; font-size: 15px; line-height: 1.7;">
                                Welcome to this edition of our newsletter! We have some exciting updates to share with you. This simple, clean layout puts your content front and center.
                            </p>
                            <p style="margin: 0 0 25px 0; color: #666666; font-size: 15px; line-height: 1.7;">
                                Replace this text with your own message. Keep paragraphs short and scannable for the best reading experience on mobile devices.
                            </p>
                            
                            <!-- CTA Button -->
                            <table role="presentation" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td style="border-radius: 6px; background-color: #667eea;">
                                        <a href="' . esc_url($site_url) . '" style="display: inline-block; padding: 14px 30px; color: #ffffff; text-decoration: none; font-size: 15px; font-weight: bold;">Read More</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 25px 30px; background-color: #f8f9fa; border-top: 1px solid #eeeeee;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="center">
                                        <p style="margin: 0 0 10px 0; color: #666666; font-size: 13px;">
                                            <a href="{{view_in_browser_url}}" style="color: #667eea; text-decoration: underline;">View in browser</a> &bull;
                                            <a href="{{unsubscribe_url}}" style="color: #667eea; text-decoration: underline;">Unsubscribe</a>
                                        </p>
                                        <p style="margin: 0; color: #999999; font-size: 12px;">&copy; ' . date('Y') . ' ' . esc_html($site_name) . '</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }
    
    /**
     * Template: Announcement
     */
    private static function get_template_announcement($site_name, $site_url) {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . esc_html($site_name) . '</title>
</head>
<body style="margin: 0; padding: 0; background-color: #1a1a2e; font-family: Arial, Helvetica, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #1a1a2e;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width: 600px; width: 100%;">
                    <!-- Logo/Brand -->
                    <tr>
                        <td align="center" style="padding-bottom: 30px;">
                            <p style="margin: 0; color: #ffffff; font-size: 20px; font-weight: bold; letter-spacing: 2px;">' . esc_html(strtoupper($site_name)) . '</p>
                        </td>
                    </tr>
                    
                    <!-- Main Announcement Box -->
                    <tr>
                        <td style="padding: 50px 40px; background: linear-gradient(135deg, #16213e 0%, #0f3460 100%); border-radius: 12px; text-align: center;">
                            <p style="margin: 0 0 15px 0; color: #e94560; font-size: 14px; font-weight: bold; text-transform: uppercase; letter-spacing: 3px;">Announcement</p>
                            <h1 style="margin: 0 0 25px 0; color: #ffffff; font-size: 36px; line-height: 1.2;">Big News Is Here!</h1>
                            <p style="margin: 0 0 35px 0; color: #cccccc; font-size: 16px; line-height: 1.7; max-width: 450px; margin-left: auto; margin-right: auto;">
                                We are thrilled to share this exciting announcement with you. Replace this text with your important message.
                            </p>
                            
                            <!-- CTA Button -->
                            <table role="presentation" cellspacing="0" cellpadding="0" style="margin: 0 auto;">
                                <tr>
                                    <td style="border-radius: 50px; background-color: #e94560;">
                                        <a href="' . esc_url($site_url) . '" style="display: inline-block; padding: 16px 40px; color: #ffffff; text-decoration: none; font-size: 16px; font-weight: bold;">Learn More</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td align="center" style="padding-top: 30px;">
                            <p style="margin: 0 0 10px 0; color: #666666; font-size: 13px;">
                                <a href="{{view_in_browser_url}}" style="color: #888888; text-decoration: underline;">View in browser</a> &bull;
                                <a href="{{unsubscribe_url}}" style="color: #888888; text-decoration: underline;">Unsubscribe</a>
                            </p>
                            <p style="margin: 0; color: #555555; font-size: 12px;">&copy; ' . date('Y') . ' ' . esc_html($site_name) . '</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }
    
    /**
     * Template: Event Invite
     */
    private static function get_template_event_invite($site_name, $site_url) {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . esc_html($site_name) . '</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f0f4f8; font-family: Arial, Helvetica, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f0f4f8;">
        <tr>
            <td align="center" style="padding: 20px 0;">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width: 600px; width: 100%; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                    <!-- Header with Accent -->
                    <tr>
                        <td style="padding: 0;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td style="height: 8px; background: linear-gradient(90deg, #00b894, #00cec9);"></td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Event Header -->
                    <tr>
                        <td style="padding: 40px 30px 20px 30px; text-align: center;">
                            <p style="margin: 0 0 10px 0; color: #00b894; font-size: 14px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px;">You\'re Invited</p>
                            <h1 style="margin: 0; color: #2d3436; font-size: 32px; line-height: 1.2;">Event Name Here</h1>
                        </td>
                    </tr>
                    
                    <!-- Event Details -->
                    <tr>
                        <td style="padding: 20px 30px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f8f9fa; border-radius: 8px;">
                                <tr>
                                    <td style="padding: 25px;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td width="40" valign="top" style="padding-right: 15px;">
                                                    <div style="width: 40px; height: 40px; background-color: #00b894; border-radius: 8px; text-align: center; line-height: 40px; color: #ffffff; font-size: 18px;">📅</div>
                                                </td>
                                                <td valign="top">
                                                    <p style="margin: 0 0 3px 0; color: #636e72; font-size: 12px; text-transform: uppercase;">Date & Time</p>
                                                    <p style="margin: 0; color: #2d3436; font-size: 16px; font-weight: bold;">Saturday, January 15, 2025 at 6:00 PM</p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 0 25px 25px 25px;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td width="40" valign="top" style="padding-right: 15px;">
                                                    <div style="width: 40px; height: 40px; background-color: #00b894; border-radius: 8px; text-align: center; line-height: 40px; color: #ffffff; font-size: 18px;">📍</div>
                                                </td>
                                                <td valign="top">
                                                    <p style="margin: 0 0 3px 0; color: #636e72; font-size: 12px; text-transform: uppercase;">Location</p>
                                                    <p style="margin: 0; color: #2d3436; font-size: 16px; font-weight: bold;">School Gymnasium</p>
                                                    <p style="margin: 5px 0 0 0; color: #636e72; font-size: 14px;">123 Main Street, Your City</p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Description -->
                    <tr>
                        <td style="padding: 10px 30px 30px 30px;">
                            <p style="margin: 0 0 25px 0; color: #636e72; font-size: 15px; line-height: 1.7; text-align: center;">
                                Join us for this exciting event! Add your event description here with all the important details attendees need to know.
                            </p>
                            
                            <!-- RSVP Button -->
                            <table role="presentation" cellspacing="0" cellpadding="0" style="margin: 0 auto;">
                                <tr>
                                    <td style="border-radius: 8px; background-color: #00b894;">
                                        <a href="' . esc_url($site_url) . '" style="display: inline-block; padding: 16px 50px; color: #ffffff; text-decoration: none; font-size: 16px; font-weight: bold;">RSVP Now</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 25px 30px; background-color: #2d3436;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="center">
                                        <p style="margin: 0 0 10px 0; color: #ffffff; font-size: 14px; font-weight: bold;">' . esc_html($site_name) . '</p>
                                        <p style="margin: 0 0 10px 0; color: #b2bec3; font-size: 13px;">
                                            <a href="{{view_in_browser_url}}" style="color: #b2bec3; text-decoration: underline;">View in browser</a> &bull;
                                            <a href="{{unsubscribe_url}}" style="color: #b2bec3; text-decoration: underline;">Unsubscribe</a>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }
    
    /**
     * Template: Welcome Email
     */
    private static function get_template_welcome($site_name, $site_url) {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . esc_html($site_name) . '</title>
</head>
<body style="margin: 0; padding: 0; background-color: #faf5f0; font-family: Arial, Helvetica, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #faf5f0;">
        <tr>
            <td align="center" style="padding: 20px 0;">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width: 600px; width: 100%; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.08);">
                    <!-- Header -->
                    <tr>
                        <td style="padding: 50px 30px; background: linear-gradient(135deg, #ff9a56 0%, #ff6b6b 100%); text-align: center;">
                            <h1 style="margin: 0 0 10px 0; color: #ffffff; font-size: 32px;">Welcome! 👋</h1>
                            <p style="margin: 0; color: rgba(255,255,255,0.9); font-size: 16px;">We\'re so glad you\'re here</p>
                        </td>
                    </tr>
                    
                    <!-- Welcome Message -->
                    <tr>
                        <td style="padding: 40px 30px 20px 30px;">
                            <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.7;">
                                Hi there!
                            </p>
                            <p style="margin: 0 0 20px 0; color: #666666; font-size: 15px; line-height: 1.7;">
                                Thank you for joining ' . esc_html($site_name) . '! We\'re excited to have you as part of our community. Here\'s what you can expect:
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Getting Started Steps -->
                    <tr>
                        <td style="padding: 0 30px 30px 30px;">
                            <!-- Step 1 -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 15px;">
                                <tr>
                                    <td width="50" valign="top">
                                        <div style="width: 36px; height: 36px; background-color: #ff9a56; border-radius: 50%; text-align: center; line-height: 36px; color: #ffffff; font-size: 16px; font-weight: bold;">1</div>
                                    </td>
                                    <td valign="top">
                                        <h3 style="margin: 0 0 5px 0; color: #333333; font-size: 16px;">Complete Your Profile</h3>
                                        <p style="margin: 0; color: #666666; font-size: 14px; line-height: 1.5;">Add your details to get the most personalized experience.</p>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Step 2 -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 15px;">
                                <tr>
                                    <td width="50" valign="top">
                                        <div style="width: 36px; height: 36px; background-color: #ff9a56; border-radius: 50%; text-align: center; line-height: 36px; color: #ffffff; font-size: 16px; font-weight: bold;">2</div>
                                    </td>
                                    <td valign="top">
                                        <h3 style="margin: 0 0 5px 0; color: #333333; font-size: 16px;">Explore Resources</h3>
                                        <p style="margin: 0; color: #666666; font-size: 14px; line-height: 1.5;">Check out everything we have to offer.</p>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Step 3 -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td width="50" valign="top">
                                        <div style="width: 36px; height: 36px; background-color: #ff9a56; border-radius: 50%; text-align: center; line-height: 36px; color: #ffffff; font-size: 16px; font-weight: bold;">3</div>
                                    </td>
                                    <td valign="top">
                                        <h3 style="margin: 0 0 5px 0; color: #333333; font-size: 16px;">Get Involved</h3>
                                        <p style="margin: 0; color: #666666; font-size: 14px; line-height: 1.5;">Join events and connect with others.</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- CTA -->
                    <tr>
                        <td style="padding: 0 30px 40px 30px; text-align: center;">
                            <table role="presentation" cellspacing="0" cellpadding="0" style="margin: 0 auto;">
                                <tr>
                                    <td style="border-radius: 8px; background-color: #ff6b6b;">
                                        <a href="' . esc_url($site_url) . '" style="display: inline-block; padding: 16px 40px; color: #ffffff; text-decoration: none; font-size: 16px; font-weight: bold;">Get Started</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 25px 30px; background-color: #f8f8f8; border-top: 1px solid #eeeeee;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="center">
                                        <p style="margin: 0 0 10px 0; color: #999999; font-size: 13px;">
                                            Questions? Reply to this email or visit our website.
                                        </p>
                                        <p style="margin: 0 0 10px 0; color: #999999; font-size: 13px;">
                                            <a href="{{view_in_browser_url}}" style="color: #ff9a56; text-decoration: underline;">View in browser</a> &bull;
                                            <a href="{{unsubscribe_url}}" style="color: #ff9a56; text-decoration: underline;">Unsubscribe</a>
                                        </p>
                                        <p style="margin: 0; color: #cccccc; font-size: 12px;">&copy; ' . date('Y') . ' ' . esc_html($site_name) . '</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }
    
    /**
     * Cleanup on deactivation
     */
    public static function deactivate() {
        wp_clear_scheduled_hook('azure_newsletter_process_queue');
        wp_clear_scheduled_hook('azure_newsletter_check_bounces');
        wp_clear_scheduled_hook('azure_newsletter_weekly_validation');
    }
}

// Note: cron interval registration was moved to Azure_PTA_Cron::register_intervals().
