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
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance == null) {
            self::$instance = new Azure_Tickets_Module();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Initialize hooks
        add_action('init', array($this, 'init'));
        
        // Enqueue admin styles and scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Enqueue frontend styles and scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        
        // WooCommerce hooks
        add_action('woocommerce_loaded', array($this, 'init_woocommerce_integration'));
        
        // Order completion hooks for ticket generation
        add_action('woocommerce_order_status_completed', array($this, 'generate_tickets_for_order'));
        add_action('woocommerce_order_status_processing', array($this, 'generate_tickets_for_order'));
        
        // AJAX handlers
        add_action('wp_ajax_azure_tickets_save_venue', array($this, 'ajax_save_venue'));
        add_action('wp_ajax_azure_tickets_get_venue', array($this, 'ajax_get_venue'));
        add_action('wp_ajax_azure_tickets_delete_venue', array($this, 'ajax_delete_venue'));
        add_action('wp_ajax_azure_tickets_get_availability', array($this, 'ajax_get_availability'));
        add_action('wp_ajax_nopriv_azure_tickets_get_availability', array($this, 'ajax_get_availability'));
        add_action('wp_ajax_azure_tickets_reserve_seats', array($this, 'ajax_reserve_seats'));
        add_action('wp_ajax_nopriv_azure_tickets_reserve_seats', array($this, 'ajax_reserve_seats'));
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
            'class-tickets-venue.php',
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
     * Initialize WooCommerce integration
     */
    public function init_woocommerce_integration() {
        // Load product type class
        if (class_exists('WC_Product') && !class_exists('WC_Product_Ticket')) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-tickets-product-type.php';
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
        
        global $product;
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
                'nonce' => wp_create_nonce('azure_tickets_frontend_nonce'),
                'productId' => $product->get_id(),
                'cartUrl' => wc_get_cart_url(),
                'strings' => array(
                    'selectSeats' => __('Select your seats', 'azure-plugin'),
                    'seatsSelected' => __('seats selected', 'azure-plugin'),
                    'addToCart' => __('Add to Cart', 'azure-plugin'),
                    'enterName' => __('Enter attendee name', 'azure-plugin'),
                    'seatUnavailable' => __('This seat is no longer available', 'azure-plugin'),
                    'reservationExpired' => __('Your seat reservation has expired', 'azure-plugin'),
                )
            ));
        }
    }
    
    /**
     * Generate tickets when order is completed
     */
    public function generate_tickets_for_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_type() === 'ticket') {
                $this->create_tickets_for_item($order, $item);
            }
        }
    }
    
    /**
     * Create tickets for a line item
     */
    private function create_tickets_for_item($order, $item) {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        
        // Get ticket meta (seat info, attendee names)
        $seat_data = $item->get_meta('_ticket_seats');
        $attendee_names = $item->get_meta('_attendee_names');
        
        if (!is_array($seat_data)) {
            $seat_data = array();
        }
        if (!is_array($attendee_names)) {
            $attendee_names = array();
        }
        
        $product_id = $item->get_product_id();
        $event_id = get_post_meta($product_id, '_ticket_event_id', true);
        $venue_id = get_post_meta($product_id, '_ticket_venue_id', true);
        
        // Generate tickets
        $quantity = $item->get_quantity();
        for ($i = 0; $i < $quantity; $i++) {
            $seat = isset($seat_data[$i]) ? $seat_data[$i] : array();
            $attendee_name = isset($attendee_names[$i]) ? $attendee_names[$i] : '';
            
            $ticket_code = $this->generate_ticket_code();
            $qr_data = json_encode(array(
                'ticket_id' => 0, // Will be updated after insert
                'code' => $ticket_code,
                'event' => $event_id,
                'product' => $product_id,
                'checksum' => hash('sha256', $ticket_code . $order->get_id() . $i)
            ));
            
            $wpdb->insert($tickets_table, array(
                'order_id' => $order->get_id(),
                'product_id' => $product_id,
                'event_id' => $event_id,
                'venue_id' => $venue_id,
                'section_id' => $seat['section_id'] ?? null,
                'row_letter' => $seat['row'] ?? null,
                'seat_number' => $seat['seat'] ?? null,
                'attendee_name' => sanitize_text_field($attendee_name),
                'attendee_email' => $order->get_billing_email(),
                'ticket_code' => $ticket_code,
                'qr_data' => $qr_data,
                'status' => 'active',
                'created_at' => current_time('mysql')
            ));
            
            $ticket_id = $wpdb->insert_id;
            
            // Update QR data with ticket ID
            $qr_data = json_encode(array(
                'ticket_id' => $ticket_id,
                'code' => $ticket_code,
                'event' => $event_id,
                'product' => $product_id,
                'checksum' => hash('sha256', $ticket_code . $order->get_id() . $i)
            ));
            
            $wpdb->update($tickets_table, array('qr_data' => $qr_data), array('id' => $ticket_id));
            
            // Mark seat as sold
            $this->mark_seat_sold($venue_id, $seat);
        }
        
        // Generate and send ticket email
        if (class_exists('Azure_Tickets_Generator')) {
            $generator = new Azure_Tickets_Generator();
            $generator->send_ticket_email($order->get_id());
        }
    }
    
    /**
     * Generate unique ticket code
     */
    private function generate_ticket_code() {
        return strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
    }
    
    /**
     * Mark a seat as sold
     */
    private function mark_seat_sold($venue_id, $seat) {
        global $wpdb;
        $table = $wpdb->prefix . 'azure_ticket_seat_status';
        
        if (!empty($seat['section_id']) && !empty($seat['row']) && !empty($seat['seat'])) {
            $wpdb->replace($table, array(
                'venue_id' => $venue_id,
                'section_id' => $seat['section_id'],
                'row_letter' => $seat['row'],
                'seat_number' => $seat['seat'],
                'status' => 'sold',
                'updated_at' => current_time('mysql')
            ));
        }
    }
    
    /**
     * Cleanup expired seat reservations
     */
    public function cleanup_expired_reservations() {
        global $wpdb;
        $table = $wpdb->prefix . 'azure_ticket_seat_status';
        
        // Reservations expire after 15 minutes
        $expiry_time = date('Y-m-d H:i:s', strtotime('-15 minutes'));
        
        $wpdb->delete($table, array('status' => 'reserved'), array('%s'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE status = 'reserved' AND updated_at < %s",
            $expiry_time
        ));
    }
    
    /**
     * AJAX: Save venue (TEC integration)
     * Saves seating layout to TEC venue post meta
     */
    public function ajax_save_venue() {
        check_ajax_referer('azure_tickets_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        // Check if TEC is active
        if (!class_exists('Tribe__Events__Main')) {
            wp_send_json_error(array('message' => 'The Events Calendar is required'));
        }
        
        $venue_id = intval($_POST['venue_id'] ?? 0);
        $layout_json = wp_unslash($_POST['layout_json'] ?? '{}');
        
        // Validate JSON
        $layout = json_decode($layout_json);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(array('message' => 'Invalid layout JSON'));
        }
        
        // If no venue_id, we're creating a new TEC venue
        if ($venue_id === 0) {
            $name = sanitize_text_field($_POST['name'] ?? '');
            $address = sanitize_text_field($_POST['address'] ?? '');
            $city = sanitize_text_field($_POST['city'] ?? '');
            
            if (empty($name)) {
                wp_send_json_error(array('message' => 'Venue name is required'));
            }
            
            // Create a new TEC venue
            $venue_data = array(
                'Venue' => $name,
                'Address' => $address,
                'City' => $city,
            );
            
            $venue_id = tribe_create_venue($venue_data);
            
            if (is_wp_error($venue_id) || !$venue_id) {
                wp_send_json_error(array('message' => 'Failed to create venue'));
            }
        } else {
            // Verify venue exists and is a TEC venue
            $venue = get_post($venue_id);
            if (!$venue || $venue->post_type !== 'tribe_venue') {
                wp_send_json_error(array('message' => 'Invalid venue'));
            }
        }
        
        // Save the seating layout as post meta
        update_post_meta($venue_id, '_azure_seating_layout', $layout_json);
        
        // Calculate and store capacity
        $capacity = $this->calculate_capacity_from_layout($layout);
        update_post_meta($venue_id, '_azure_seating_capacity', $capacity);
        
        wp_send_json_success(array(
            'message' => 'Venue saved successfully',
            'venue_id' => $venue_id
        ));
    }
    
    /**
     * Calculate capacity from layout
     */
    private function calculate_capacity_from_layout($layout) {
        $capacity = 0;
        
        if (isset($layout->blocks) && is_array($layout->blocks)) {
            foreach ($layout->blocks as $block) {
                if (!isset($block->type)) continue;
                
                if ($block->type === 'general_admission') {
                    $capacity += intval($block->capacity ?? 0);
                } elseif ($block->type === 'rectangle') {
                    $rows = intval($block->rowCount ?? 0);
                    $seats_per_row = intval($block->seatsPerRow ?? 0);
                    $capacity += $rows * $seats_per_row;
                }
            }
        }
        
        return $capacity;
    }
    
    /**
     * AJAX: Get venue (TEC integration)
     */
    public function ajax_get_venue() {
        check_ajax_referer('azure_tickets_nonce', 'nonce');
        
        $venue_id = intval($_POST['venue_id'] ?? 0);
        $venue = get_post($venue_id);
        
        if (!$venue || $venue->post_type !== 'tribe_venue') {
            wp_send_json_error(array('message' => 'Venue not found'));
        }
        
        $layout_json = get_post_meta($venue_id, '_azure_seating_layout', true);
        
        wp_send_json_success(array(
            'venue' => array(
                'id' => $venue_id,
                'name' => $venue->post_title,
                'layout_json' => $layout_json ?: '{"canvas":{"width":800,"height":600},"blocks":[]}',
                'address' => tribe_get_address($venue_id),
                'city' => tribe_get_city($venue_id)
            )
        ));
    }
    
    /**
     * AJAX: Delete venue
     */
    /**
     * AJAX: Delete venue seating layout (TEC integration)
     * Only removes the seating layout, not the TEC venue itself
     */
    public function ajax_delete_venue() {
        check_ajax_referer('azure_tickets_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $venue_id = intval($_POST['venue_id'] ?? 0);
        $venue = get_post($venue_id);
        
        if (!$venue || $venue->post_type !== 'tribe_venue') {
            wp_send_json_error(array('message' => 'Invalid venue'));
        }
        
        // Remove the seating layout from the venue
        delete_post_meta($venue_id, '_azure_seating_layout');
        delete_post_meta($venue_id, '_azure_seating_capacity');
        
        wp_send_json_success(array('message' => 'Seating layout removed from venue'));
    }
    
    /**
     * AJAX: Get seat availability (TEC integration)
     */
    public function ajax_get_availability() {
        $product_id = intval($_POST['product_id'] ?? 0);
        $venue_id = get_post_meta($product_id, '_ticket_venue_id', true);
        
        if (!$venue_id) {
            wp_send_json_error(array('message' => 'No venue configured'));
        }
        
        // Get venue from TEC
        $venue = get_post($venue_id);
        if (!$venue || $venue->post_type !== 'tribe_venue') {
            wp_send_json_error(array('message' => 'Venue not found'));
        }
        
        // Get seating layout from venue meta
        $layout_json = get_post_meta($venue_id, '_azure_seating_layout', true);
        if (!$layout_json) {
            wp_send_json_error(array('message' => 'No seating layout configured for this venue'));
        }
        
        global $wpdb;
        $status_table = $wpdb->prefix . 'azure_ticket_seat_status';
        
        // Get sold/reserved seats
        $unavailable = $wpdb->get_results($wpdb->prepare(
            "SELECT section_id, row_letter, seat_number, status FROM {$status_table} WHERE venue_id = %d",
            $venue_id
        ));
        
        $unavailable_map = array();
        foreach ($unavailable as $seat) {
            $key = $seat->section_id . '-' . $seat->row_letter . '-' . $seat->seat_number;
            $unavailable_map[$key] = $seat->status;
        }
        
        wp_send_json_success(array(
            'layout' => json_decode($layout_json),
            'unavailable' => $unavailable_map
        ));
    }
    
    /**
     * AJAX: Reserve seats temporarily
     */
    public function ajax_reserve_seats() {
        $product_id = intval($_POST['product_id'] ?? 0);
        $seats = json_decode(wp_unslash($_POST['seats'] ?? '[]'), true);
        
        if (!is_array($seats) || empty($seats)) {
            wp_send_json_error(array('message' => 'No seats selected'));
        }
        
        $venue_id = get_post_meta($product_id, '_ticket_venue_id', true);
        
        global $wpdb;
        $table = $wpdb->prefix . 'azure_ticket_seat_status';
        
        // Check if all seats are available
        foreach ($seats as $seat) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$table} WHERE venue_id = %d AND section_id = %s AND row_letter = %s AND seat_number = %d",
                $venue_id, $seat['section_id'], $seat['row'], $seat['seat']
            ));
            
            if ($existing && $existing !== 'available') {
                wp_send_json_error(array(
                    'message' => sprintf('Seat %s%d is no longer available', $seat['row'], $seat['seat'])
                ));
            }
        }
        
        // Reserve all seats
        $session_id = session_id() ?: wp_generate_uuid4();
        
        foreach ($seats as $seat) {
            $wpdb->replace($table, array(
                'venue_id' => $venue_id,
                'section_id' => $seat['section_id'],
                'row_letter' => $seat['row'],
                'seat_number' => $seat['seat'],
                'status' => 'reserved',
                'session_id' => $session_id,
                'updated_at' => current_time('mysql')
            ));
        }
        
        wp_send_json_success(array(
            'message' => 'Seats reserved',
            'session_id' => $session_id,
            'expires_in' => 900 // 15 minutes
        ));
    }
    
    /**
     * AJAX: Check in a ticket
     */
    public function ajax_checkin() {
        check_ajax_referer('azure_tickets_nonce', 'nonce');
        
        if (!current_user_can('scan_tickets')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $ticket_code = sanitize_text_field($_POST['ticket_code'] ?? '');
        $qr_data = wp_unslash($_POST['qr_data'] ?? '');
        
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        $checkins_table = $wpdb->prefix . 'azure_ticket_checkins';
        
        // Find ticket
        $ticket = null;
        if (!empty($ticket_code)) {
            $ticket = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$tickets_table} WHERE ticket_code = %s",
                $ticket_code
            ));
        } elseif (!empty($qr_data)) {
            $data = json_decode($qr_data, true);
            if (isset($data['code'])) {
                $ticket = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$tickets_table} WHERE ticket_code = %s",
                    $data['code']
                ));
            }
        }
        
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
        
        // Mark as used
        $wpdb->update($tickets_table, array(
            'status' => 'used',
            'checked_in_at' => current_time('mysql'),
            'checked_in_by' => get_current_user_id()
        ), array('id' => $ticket->id));
        
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
                'seat' => $ticket->row_letter ? $ticket->row_letter . $ticket->seat_number : 'General Admission'
            )
        ));
    }
    
    /**
     * AJAX: Validate ticket without checking in
     */
    public function ajax_validate_ticket() {
        check_ajax_referer('azure_tickets_nonce', 'nonce');
        
        $ticket_code = sanitize_text_field($_POST['ticket_code'] ?? '');
        
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
                'seat' => $ticket->row_letter ? $ticket->row_letter . $ticket->seat_number : 'General Admission',
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
        
        // Count TEC venues with seating layouts
        if (class_exists('Tribe__Events__Main')) {
            $tec_venues = get_posts(array(
                'post_type' => 'tribe_venue',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'fields' => 'ids'
            ));
            
            foreach ($tec_venues as $venue_id) {
                if (get_post_meta($venue_id, '_azure_seating_layout', true)) {
                    $stats['total_venues']++;
                }
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
     * Note: Venues are now stored as TEC venues with seating layouts in post meta
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
        
        // Seat status table (for reservations and sold seats)
        $table_seat_status = $wpdb->prefix . 'azure_ticket_seat_status';
        $sql_seat_status = "CREATE TABLE $table_seat_status (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            venue_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned DEFAULT NULL,
            section_id varchar(100) NOT NULL,
            row_letter varchar(10) NOT NULL,
            seat_number int(11) NOT NULL,
            status varchar(20) DEFAULT 'available',
            session_id varchar(100) DEFAULT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY venue_seat (venue_id, product_id, section_id, row_letter, seat_number),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql_seat_status);
        
        Azure_Logger::info('Tickets: Database tables created/updated');
    }
}

// Initialize the module if tickets are enabled
add_action('plugins_loaded', function() {
    if (Azure_Settings::is_module_enabled('tickets')) {
        Azure_Tickets_Module::get_instance();
    }
}, 20);

