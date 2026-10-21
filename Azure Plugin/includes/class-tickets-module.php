<?php
/**
 * Tickets Module - Main initialization class
 * 
 * Provides event ticketing with visual seating design, WooCommerce integration,
 * QR code tickets, Apple Wallet passes, and check-in functionality.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Tickets_Module {

    const DB_VERSION = '2';
    const DB_VERSION_OPTION = 'azure_tickets_db_version';
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance == null) {
            self::$instance = new Azure_Tickets_Module();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // The plugin loader creates this during `init`; a callback added for
        // the running priority would never fire, so set up straight away.
        if (did_action('init')) {
            $this->init();
        } else {
            add_action('init', array($this, 'init'));
        }
        
        // Enqueue admin styles and scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Enqueue frontend styles and scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        
        // Order completion hooks for ticket generation
        add_action('woocommerce_order_status_completed', array($this, 'generate_tickets_for_order'));
        add_action('woocommerce_order_status_processing', array($this, 'generate_tickets_for_order'));
        
        // AJAX handlers
        add_action('wp_ajax_azure_tickets_save_venue', array($this, 'ajax_save_venue'));
        add_action('wp_ajax_azure_tickets_get_venue', array($this, 'ajax_get_venue'));
        add_action('wp_ajax_azure_tickets_delete_venue', array($this, 'ajax_delete_venue'));
        add_action('wp_ajax_azure_tickets_checkin', array($this, 'ajax_checkin'));
        add_action('wp_ajax_azure_tickets_validate_ticket', array($this, 'ajax_validate_ticket'));
        
        // Cleanup expired seat reservations
        add_action('azure_tickets_cleanup_reservations', array($this, 'cleanup_expired_reservations'));
        
        // Dashboard widget
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
        
        Azure_Logger::debug_module('Tickets', 'Tickets module initialized');
    }
    
    /**
     * Initialize module
     */
    public function init() {
        // Load additional module classes
        $this->load_module_classes();

        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            self::create_tables();
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
        }
        Azure_Tickets_Inventory::maybe_create_table();
        if (class_exists('WooCommerce')) {
            Azure_Tickets_Cart::get_instance();
        }
        Azure_Tickets_QR::get_instance();

        // Schedule reservation cleanup
        if (!wp_next_scheduled('azure_tickets_cleanup_reservations')) {
            wp_schedule_event(time(), 'hourly', 'azure_tickets_cleanup_reservations');
        }
        
        // Register custom capability for ticket scanning
        $this->register_capabilities();
    }
    
    /**
     * Load additional module classes
     */
    private function load_module_classes() {
        $classes = array(
            'class-tickets-layout.php',
            'class-tickets-inventory.php',
            'class-tickets-config.php',
            'class-tickets-access.php',
            'class-tickets-cart.php',
            'class-tickets-qr.php',
            'class-tickets-product-type.php',
            'class-tickets-generator.php',
            'class-tickets-apple-wallet.php',
            'class-tickets-checkin.php'
        );
        
        foreach ($classes as $class_file) {
            $file_path = AZURE_PLUGIN_PATH . 'includes/' . $class_file;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }
    }
    
    /**
     * Register custom capabilities
     */
    private function register_capabilities() {
        // Add scan_tickets capability to administrator
        $admin_role = get_role('administrator');
        if ($admin_role && !$admin_role->has_cap('scan_tickets')) {
            $admin_role->add_cap('scan_tickets');
        }
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on tickets admin pages
        if (strpos($hook, 'azure-plugin-tickets') === false) {
            return;
        }
        
        // Tickets admin CSS
        wp_enqueue_style(
            'azure-tickets-admin',
            AZURE_PLUGIN_URL . 'css/tickets-admin.css',
            array(),
            AZURE_PLUGIN_VERSION
        );
        
        // Tickets designer CSS
        wp_enqueue_style(
            'azure-tickets-designer',
            AZURE_PLUGIN_URL . 'css/tickets-designer.css',
            array(),
            AZURE_PLUGIN_VERSION
        );
        
        // Tickets admin JS
        wp_enqueue_script(
            'azure-tickets-admin',
            AZURE_PLUGIN_URL . 'js/tickets-admin.js',
            array('jquery'),
            AZURE_PLUGIN_VERSION,
            true
        );
        
        // Tickets designer JS (for venue designer page)
        // Always load on tickets page when venues tab is active
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : '';
        if ($tab === 'venues') {
            wp_enqueue_script(
                'azure-tickets-designer',
                AZURE_PLUGIN_URL . 'js/tickets-designer.js',
                array('jquery'),
                AZURE_PLUGIN_VERSION,
                true
            );
            
            // Localize designer-specific data
            wp_localize_script('azure-tickets-designer', 'azureTicketsDesigner', array(
                'nonce' => wp_create_nonce('azure_tickets_nonce'),
                'ajaxUrl' => admin_url('admin-ajax.php')
            ));
        }
        
        // Check-in page assets
        if (strpos($hook, 'tickets-checkin') !== false) {
            wp_enqueue_script(
                'html5-qrcode',
                'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js',
                array(),
                '2.3.8',
                true
            );
            
            wp_enqueue_script(
                'azure-tickets-checkin',
                AZURE_PLUGIN_URL . 'js/tickets-checkin.js',
                array('jquery', 'html5-qrcode'),
                AZURE_PLUGIN_VERSION,
                true
            );
        }
        
        // Localize script
        wp_localize_script('azure-tickets-admin', 'azureTickets', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('azure_tickets_nonce'),
            'strings' => array(
                'confirmDelete' => __('Are you sure you want to delete this venue?', 'azure-plugin'),
                'saving' => __('Saving...', 'azure-plugin'),
                'saved' => __('Saved!', 'azure-plugin'),
                'error' => __('Error occurred', 'azure-plugin'),
                'scanSuccess' => __('Valid Ticket!', 'azure-plugin'),
                'scanError' => __('Invalid Ticket', 'azure-plugin'),
                'alreadyUsed' => __('Ticket Already Used', 'azure-plugin'),
            )
        ));
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        // Only on single product pages for ticket products
        if (!is_product()) {
            return;
        }
        
        $product = wc_get_product(get_queried_object_id());
        if ($product && $product->get_type() === 'ticket') {
            wp_enqueue_style(
                'azure-tickets-frontend',
                AZURE_PLUGIN_URL . 'css/tickets-frontend.css',
                array(),
                AZURE_PLUGIN_VERSION
            );
            
            wp_enqueue_script(
                'azure-tickets-seat-selector',
                AZURE_PLUGIN_URL . 'js/tickets-seat-selector.js',
                array('jquery'),
                AZURE_PLUGIN_VERSION,
                true
            );
            
            wp_localize_script('azure-tickets-seat-selector', 'azureTicketsFrontend', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'productId' => $product->get_id(),
                'cartUrl' => wc_get_cart_url(),
                'loginUrl' => wp_login_url(get_permalink($product->get_id())),
                'currency' => html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8'),
            ));
        }
    }
    
    /**
     * Generate tickets when order is completed
     */
    public function generate_tickets_for_order($order_id) {
        global $wpdb;

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // This fires on both `processing` and `completed`, and most orders pass
        // through both — without a guard the same order got a second full set of
        // tickets, so QR codes were duplicated and "tickets sold" double-counted.
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tickets_table} WHERE order_id = %d",
            $order_id
        ));
        if ($existing > 0) {
            return;
        }

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_type() === 'ticket') {
                $this->create_tickets_for_item($order, $item);
            }
        }

        if (class_exists('Azure_Tickets_Generator')) {
            $generator = new Azure_Tickets_Generator();
            $generator->send_ticket_email($order->get_id());
        }
    }
    
    /**
     * Create tickets for a line item
     */
    private function create_tickets_for_item($order, $item) {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        
        $seats = $item->get_meta(Azure_Tickets_Cart::ITEM_SEATS);
        if (!is_array($seats) || !$seats) {
            return;
        }

        $product_id = $item->get_product_id();
        $event_id = get_post_meta($product_id, '_ticket_event_id', true);
        $venue_id = get_post_meta($product_id, '_ticket_venue_id', true);

        foreach ($seats as $seat) {
            $reserved = preg_match('/^([A-Z])-(\d+)$/', (string) ($seat['seat_key'] ?? ''), $m);
            $ticket_code = self::generate_ticket_code();
            $wpdb->insert($tickets_table, array(
                'order_id' => $order->get_id(),
                'product_id' => $product_id,
                'event_id' => $event_id ?: null,
                'venue_id' => $venue_id ?: null,
                'section_id' => $seat['section_id'] ?? null,
                'row_letter' => $reserved ? $m[1] : null,
                'seat_number' => $reserved ? (int) $m[2] : null,
                'attendee_name' => sanitize_text_field($seat['name'] ?? ''),
                'attendee_email' => $order->get_billing_email(),
                'ticket_code' => $ticket_code,
                // The scanner reads the bare code; nothing else needs to be in the QR.
                'qr_data' => $ticket_code,
                'status' => 'active',
                'created_at' => current_time('mysql')
            ));
        }
    }

    /**
     * 10 characters from an alphabet without look-alikes (no 0/O, 1/I/L).
     */
    public static function generate_ticket_code() {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $code;
    }

    /**
     * Hourly: lapsed holds go, unpaid orders past their window are settled.
     */
    public function cleanup_expired_reservations() {
        Azure_Tickets_Inventory::cleanup(array('Azure_Tickets_Cart', 'order_state'));
    }
    
    /**
     * AJAX: Save venue.
     * Creates a pta_venue (or updates an existing one) and stores the
     * seating layout JSON in postmeta. Legacy tribe_venue posts are
     * accepted for update so existing data keeps working until the
     * migration runs.
     */
    public function ajax_save_venue() {
        check_ajax_referer('azure_tickets_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $venue_id = intval($_POST['venue_id'] ?? 0);
        $decoded = json_decode(wp_unslash($_POST['layout_json'] ?? ''), true);
        if (!is_array($decoded)) {
            wp_send_json_error(array('message' => 'Invalid layout JSON'));
        }
        $layout = Azure_Tickets_Layout::sanitize($decoded);

        if ($venue_id === 0) {
            $name = sanitize_text_field($_POST['name'] ?? '');
            $address = sanitize_text_field($_POST['address'] ?? '');
            $city = sanitize_text_field($_POST['city'] ?? '');

            if (empty($name)) {
                wp_send_json_error(array('message' => 'Venue name is required'));
            }

            // Create a new pta_venue post.
            $venue_id = wp_insert_post(array(
                'post_title'  => $name,
                'post_type'   => 'pta_venue',
                'post_status' => 'publish',
            ), true);

            if (is_wp_error($venue_id) || !$venue_id) {
                wp_send_json_error(array('message' => 'Failed to create venue'));
            }

            // Shared meta schema with the legacy TEC tribe_venue (see
            // class-event-cpt.php) so address fields stay portable.
            if ($address !== '') update_post_meta($venue_id, '_VenueAddress', $address);
            if ($city !== '')    update_post_meta($venue_id, '_VenueCity', $city);
        } else {
            $venue = get_post($venue_id);
            if (!$venue || !in_array($venue->post_type, array('pta_venue', 'tribe_venue'), true)) {
                wp_send_json_error(array('message' => 'Invalid venue'));
            }
        }

        update_post_meta($venue_id, '_azure_seating_layout', wp_slash(wp_json_encode($layout)));
        update_post_meta($venue_id, '_azure_seating_capacity', Azure_Tickets_Layout::capacity($layout));

        wp_send_json_success(array(
            'message'  => 'Venue saved successfully',
            'venue_id' => $venue_id,
            'layout'   => $layout,
        ));
    }

    /**
     * AJAX: Get venue (with seating layout if present).
     * Accepts pta_venue or legacy tribe_venue posts.
     */
    public function ajax_get_venue() {
        check_ajax_referer('azure_tickets_nonce', 'nonce');

        // Only reached from the admin venue editor; matches ajax_save_venue.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $venue_id = intval($_POST['venue_id'] ?? 0);
        $venue = get_post($venue_id);

        if (!$venue || !in_array($venue->post_type, array('pta_venue', 'tribe_venue'), true)) {
            wp_send_json_error(array('message' => 'Venue not found'));
        }

        wp_send_json_success(array(
            'venue' => array(
                'id' => $venue_id,
                'name' => $venue->post_title,
                'layout_json' => wp_json_encode(Azure_Tickets_Layout::for_venue($venue_id)),
                'address' => get_post_meta($venue_id, '_VenueAddress', true),
                'city'    => get_post_meta($venue_id, '_VenueCity', true),
            )
        ));
    }
    
    /**
     * AJAX: Delete venue seating layout.
     * Only removes the seating layout, not the venue post itself.
     */
    public function ajax_delete_venue() {
        check_ajax_referer('azure_tickets_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $venue_id = intval($_POST['venue_id'] ?? 0);
        $venue = get_post($venue_id);

        if (!$venue || !in_array($venue->post_type, array('pta_venue', 'tribe_venue'), true)) {
            wp_send_json_error(array('message' => 'Invalid venue'));
        }

        delete_post_meta($venue_id, '_azure_seating_layout');
        delete_post_meta($venue_id, '_azure_seating_capacity');

        wp_send_json_success(array('message' => 'Seating layout removed from venue'));
    }

    /**
     * The ticket code from whatever was scanned or typed: a bare code (current
     * QR), or the JSON the first version put in QR codes ({"code"} / {"c"}).
     */
    public static function scanned_code($raw) {
        $raw = trim((string) $raw);
        if ($raw !== '' && $raw[0] === '{') {
            $data = json_decode($raw, true);
            $raw = is_array($data) ? (string) ($data['code'] ?? ($data['c'] ?? '')) : '';
        }
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw));
        return strlen($code) <= 20 ? $code : '';
    }

    /**
     * AJAX: Check in a ticket
     */
    public function ajax_checkin() {
        check_ajax_referer('azure_tickets_nonce', 'nonce');
        
        if (!current_user_can('scan_tickets')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $ticket_code = self::scanned_code(wp_unslash($_POST['ticket_code'] ?? ($_POST['qr_data'] ?? '')));

        global $wpdb;
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        $checkins_table = $wpdb->prefix . 'azure_ticket_checkins';

        $ticket = $ticket_code === '' ? null : $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tickets_table} WHERE ticket_code = %s",
            $ticket_code
        ));

        if (!$ticket) {
            wp_send_json_error(array('message' => 'Ticket not found', 'status' => 'invalid'));
        }
        
        if ($ticket->status === 'used') {
            wp_send_json_error(array(
                'message' => 'Ticket already used',
                'status' => 'already_used',
                'checked_in_at' => $ticket->checked_in_at
            ));
        }
        
        if ($ticket->status !== 'active') {
            wp_send_json_error(array('message' => 'Ticket is not valid', 'status' => 'invalid'));
        }
        
        // Conditional on 'active' so two scanners can't both admit one ticket.
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$tickets_table} SET status = 'used', checked_in_at = %s, checked_in_by = %d WHERE id = %d AND status = 'active'",
            current_time('mysql'),
            get_current_user_id(),
            $ticket->id
        ));
        if (!$claimed) {
            wp_send_json_error(array('message' => 'Ticket already used', 'status' => 'already_used'));
        }
        
        // Log check-in
        $wpdb->insert($checkins_table, array(
            'ticket_id' => $ticket->id,
            'action' => 'checkin',
            'user_id' => get_current_user_id(),
            'device_info' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'created_at' => current_time('mysql')
        ));
        
        // Get ticket details
        $product = wc_get_product($ticket->product_id);
        
        wp_send_json_success(array(
            'message' => 'Check-in successful!',
            'status' => 'success',
            'ticket' => array(
                'code' => $ticket->ticket_code,
                'attendee' => $ticket->attendee_name,
                'event' => $product ? $product->get_name() : 'Unknown Event',
                'seat' => Azure_Tickets_Config::ticket_seat_label($ticket)
            )
        ));
    }
    
    /**
     * AJAX: Validate ticket without checking in
     */
    public function ajax_validate_ticket() {
        check_ajax_referer('azure_tickets_nonce', 'nonce');

        // Same gate as check-in: the response carries attendee name, event and
        // seat, so a nonce alone would let any logged-in user look up any
        // ticket by code.
        if (!current_user_can('scan_tickets')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $ticket_code = self::scanned_code(wp_unslash($_POST['ticket_code'] ?? ''));
        
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tickets_table} WHERE ticket_code = %s",
            $ticket_code
        ));
        
        if (!$ticket) {
            wp_send_json_error(array('message' => 'Ticket not found', 'valid' => false));
        }
        
        $product = wc_get_product($ticket->product_id);
        
        wp_send_json_success(array(
            'valid' => true,
            'status' => $ticket->status,
            'ticket' => array(
                'code' => $ticket->ticket_code,
                'attendee' => $ticket->attendee_name,
                'event' => $product ? $product->get_name() : 'Unknown Event',
                'seat' => Azure_Tickets_Config::ticket_seat_label($ticket),
                'checked_in' => $ticket->status === 'used',
                'checked_in_at' => $ticket->checked_in_at
            )
        ));
    }
    
    /**
     * Add dashboard widget
     */
    public function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'azure_tickets_stats',
            __('Event Tickets', 'azure-plugin'),
            array($this, 'render_dashboard_widget')
        );
    }
    
    /**
     * Render dashboard widget
     */
    public function render_dashboard_widget() {
        global $wpdb;
        
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        
        // Initialize stats
        $stats = array(
            'total_tickets' => 0,
            'active_tickets' => 0,
            'checked_in' => 0,
            'total_venues' => 0,
            'tickets_today' => 0
        );
        
        // Get stats if tables exist
        if ($wpdb->get_var("SHOW TABLES LIKE '{$tickets_table}'") === $tickets_table) {
            $stats['total_tickets'] = $wpdb->get_var("SELECT COUNT(*) FROM {$tickets_table}") ?: 0;
            $stats['active_tickets'] = $wpdb->get_var("SELECT COUNT(*) FROM {$tickets_table} WHERE status = 'active'") ?: 0;
            $stats['checked_in'] = $wpdb->get_var("SELECT COUNT(*) FROM {$tickets_table} WHERE status = 'used'") ?: 0;
            $stats['tickets_today'] = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tickets_table} WHERE DATE(created_at) = %s",
                current_time('Y-m-d')
            )) ?: 0;
        }
        
        // Count venues with seating layouts (pta_venue + legacy tribe_venue).
        $venues = get_posts(array(
            'post_type'      => array('pta_venue', 'tribe_venue'),
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ));
        foreach ($venues as $venue_id) {
            if (get_post_meta($venue_id, '_azure_seating_layout', true)) {
                $stats['total_venues']++;
            }
        }
        
        // Get recent check-ins
        $recent_checkins = array();
        if ($stats['total_tickets'] > 0) {
            $recent_checkins = $wpdb->get_results("
                SELECT t.ticket_code, t.attendee_name, t.checked_in_at, p.post_title as event_name
                FROM {$tickets_table} t
                LEFT JOIN {$wpdb->posts} p ON t.product_id = p.ID
                WHERE t.status = 'used'
                ORDER BY t.checked_in_at DESC
                LIMIT 5
            ");
        }
        ?>
        <style>
            .azure-tickets-widget .stats-row {
                display: flex;
                gap: 10px;
                margin-bottom: 15px;
            }
            .azure-tickets-widget .stat-box {
                flex: 1;
                text-align: center;
                padding: 10px;
                background: #f9f9f9;
                border-radius: 4px;
            }
            .azure-tickets-widget .stat-number {
                font-size: 24px;
                font-weight: 600;
                color: #1d2327;
            }
            .azure-tickets-widget .stat-label {
                font-size: 11px;
                color: #646970;
                text-transform: uppercase;
            }
            .azure-tickets-widget .stat-box.success { background: #d7f0e5; }
            .azure-tickets-widget .stat-box.success .stat-number { color: #00713a; }
            .azure-tickets-widget .stat-box.warning { background: #fef3cd; }
            .azure-tickets-widget .stat-box.warning .stat-number { color: #856404; }
            .azure-tickets-widget .recent-checkins {
                margin-top: 10px;
                border-top: 1px solid #eee;
                padding-top: 10px;
            }
            .azure-tickets-widget .recent-checkins h4 {
                margin: 0 0 8px;
                font-size: 12px;
            }
            .azure-tickets-widget .checkin-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 5px 0;
                font-size: 12px;
                border-bottom: 1px solid #f0f0f0;
            }
            .azure-tickets-widget .checkin-item:last-child { border-bottom: none; }
            .azure-tickets-widget .checkin-time { color: #888; font-size: 11px; }
            .azure-tickets-widget .widget-actions {
                margin-top: 15px;
                padding-top: 10px;
                border-top: 1px solid #eee;
            }
        </style>
        
        <div class="azure-tickets-widget">
            <div class="stats-row">
                <div class="stat-box">
                    <div class="stat-number"><?php echo number_format($stats['total_tickets']); ?></div>
                    <div class="stat-label"><?php _e('Total', 'azure-plugin'); ?></div>
                </div>
                <div class="stat-box success">
                    <div class="stat-number"><?php echo number_format($stats['active_tickets']); ?></div>
                    <div class="stat-label"><?php _e('Active', 'azure-plugin'); ?></div>
                </div>
                <div class="stat-box warning">
                    <div class="stat-number"><?php echo number_format($stats['checked_in']); ?></div>
                    <div class="stat-label"><?php _e('Used', 'azure-plugin'); ?></div>
                </div>
            </div>
            
            <div class="stats-row">
                <div class="stat-box">
                    <div class="stat-number"><?php echo number_format($stats['total_venues']); ?></div>
                    <div class="stat-label"><?php _e('Venues', 'azure-plugin'); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo number_format($stats['tickets_today']); ?></div>
                    <div class="stat-label"><?php _e('Sold Today', 'azure-plugin'); ?></div>
                </div>
            </div>
            
            <?php if (!empty($recent_checkins)): ?>
            <div class="recent-checkins">
                <h4><?php _e('Recent Check-ins', 'azure-plugin'); ?></h4>
                <?php foreach ($recent_checkins as $checkin): ?>
                <div class="checkin-item">
                    <span>
                        <strong><?php echo esc_html($checkin->attendee_name ?: $checkin->ticket_code); ?></strong>
                        <?php if ($checkin->event_name): ?>
                        <br><small><?php echo esc_html($checkin->event_name); ?></small>
                        <?php endif; ?>
                    </span>
                    <span class="checkin-time">
                        <?php echo human_time_diff(strtotime($checkin->checked_in_at), current_time('timestamp')); ?> ago
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <div class="widget-actions">
                <a href="<?php echo admin_url('admin.php?page=azure-plugin-tickets'); ?>" class="button button-small">
                    <?php _e('View Dashboard', 'azure-plugin'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=azure-plugin-tickets-checkin'); ?>" class="button button-small button-primary" style="float:right;">
                    <span class="dashicons dashicons-smartphone" style="font-size:14px;line-height:1.5;"></span>
                    <?php _e('Check-in', 'azure-plugin'); ?>
                </a>
            </div>
        </div>
        <?php
    }
    
    /**
     * Create database tables
     * Venues are pta_venue posts with the layout in post meta; seat holds and
     * sales are in Azure_Tickets_Inventory's table.
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Tickets table
        $table_tickets = $wpdb->prefix . 'azure_tickets';
        $sql_tickets = "CREATE TABLE $table_tickets (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            event_id bigint(20) unsigned DEFAULT NULL,
            venue_id bigint(20) unsigned DEFAULT NULL,
            section_id varchar(100) DEFAULT NULL,
            row_letter varchar(10) DEFAULT NULL,
            seat_number int(11) DEFAULT NULL,
            attendee_name varchar(255) DEFAULT NULL,
            attendee_email varchar(255) DEFAULT NULL,
            ticket_code varchar(20) NOT NULL,
            qr_data text,
            status varchar(20) DEFAULT 'active',
            checked_in_at datetime DEFAULT NULL,
            checked_in_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ticket_code (ticket_code),
            KEY order_id (order_id),
            KEY product_id (product_id),
            KEY event_id (event_id),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql_tickets);
        
        // Check-ins table
        $table_checkins = $wpdb->prefix . 'azure_ticket_checkins';
        $sql_checkins = "CREATE TABLE $table_checkins (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ticket_id bigint(20) unsigned NOT NULL,
            action varchar(50) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            device_info varchar(255) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ticket_id (ticket_id)
        ) $charset_collate;";
        dbDelta($sql_checkins);
        
        Azure_Tickets_Inventory::maybe_create_table();
        
        Azure_Logger::info('Tickets: Database tables created/updated');
    }
}

