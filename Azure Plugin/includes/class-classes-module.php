<?php
/**
 * Classes Module - Main Module Class
 * 
 * Handles initialization, hooks, and coordination of the Classes module
 * which integrates WooCommerce products with The Events Calendar.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Classes_Module {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance == null) {
            self::$instance = new Azure_Classes_Module();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        // Event-store dependency: the Classes module needs the pta_event CPT
        // to be registered. Azure_Event_CPT registers pta_event on `init`.
        if (!class_exists('Azure_Event_CPT')) {
            add_action('admin_notices', array($this, 'event_cpt_missing_notice'));
            return;
        }

        $this->init_hooks();
        $this->load_dependencies();
        
        Azure_Logger::debug_module('Classes', 'Classes module initialized successfully');
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Register custom order statuses
        add_action('init', array($this, 'register_order_statuses'));
        add_filter('wc_order_statuses', array($this, 'add_order_statuses'));
        
        // Register class_provider taxonomy
        add_action('init', array($this, 'register_taxonomies'));
        
        // Add custom fields to taxonomy
        add_action('class_provider_add_form_fields', array($this, 'add_provider_fields'));
        add_action('class_provider_edit_form_fields', array($this, 'edit_provider_fields'), 10, 2);
        add_action('created_class_provider', array($this, 'save_provider_fields'));
        add_action('edited_class_provider', array($this, 'save_provider_fields'));
        
        // AJAX handlers
        add_action('wp_ajax_azure_classes_set_final_price', array($this, 'ajax_set_final_price'));
        add_action('wp_ajax_azure_classes_get_commitments', array($this, 'ajax_get_commitments'));
        add_action('wp_ajax_azure_classes_send_payment_requests', array($this, 'ajax_send_payment_requests'));
    }
    
    /**
     * Load module dependencies
     */
    private function load_dependencies() {
        $files = array(
            'class-classes-product-type.php',
            'class-classes-event-generator.php',
            'class-classes-pricing.php',
            'class-classes-commitment.php',
            'class-classes-shortcodes.php',
            'class-classes-emails.php'
        );
        
        foreach ($files as $file) {
            $file_path = AZURE_PLUGIN_PATH . 'includes/' . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }
        
        // Initialize sub-components
        if (class_exists('Azure_Classes_Product_Type')) {
            new Azure_Classes_Product_Type();
        }
        
        if (class_exists('Azure_Classes_Event_Generator')) {
            new Azure_Classes_Event_Generator();
        }
        
        if (class_exists('Azure_Classes_Pricing')) {
            new Azure_Classes_Pricing();
        }
        
        if (class_exists('Azure_Classes_Commitment')) {
            new Azure_Classes_Commitment();
        }
        
        if (class_exists('Azure_Classes_Shortcodes')) {
            new Azure_Classes_Shortcodes();
        }
        
        if (class_exists('Azure_Classes_Emails')) {
            new Azure_Classes_Emails();
        }
    }
    
    /**
     * Register custom order statuses for class commitments
     */
    public function register_order_statuses() {
        register_post_status('wc-committed', array(
            'label'                     => _x('Committed', 'Order status', 'azure-plugin'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Committed <span class="count">(%s)</span>', 'Committed <span class="count">(%s)</span>', 'azure-plugin')
        ));
        
        register_post_status('wc-awaiting-payment', array(
            'label'                     => _x('Awaiting Payment', 'Order status', 'azure-plugin'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Awaiting Payment <span class="count">(%s)</span>', 'Awaiting Payment <span class="count">(%s)</span>', 'azure-plugin')
        ));
    }
    
    /**
     * Add custom order statuses to WooCommerce
     */
    public function add_order_statuses($order_statuses) {
        $new_statuses = array();
        
        foreach ($order_statuses as $key => $status) {
            $new_statuses[$key] = $status;
            
            // Add our statuses after 'pending'
            if ($key === 'wc-pending') {
                $new_statuses['wc-committed'] = _x('Committed', 'Order status', 'azure-plugin');
                $new_statuses['wc-awaiting-payment'] = _x('Awaiting Payment', 'Order status', 'azure-plugin');
            }
        }
        
        return $new_statuses;
    }
    
    /**
     * Register class_provider taxonomy
     * Note: This may already be registered by the main plugin file
     */
    public function register_taxonomies() {
        // Skip if already registered (by main plugin file)
        if (taxonomy_exists('class_provider')) {
            return;
        }
        
        $labels = array(
            'name'              => _x('Class Providers', 'taxonomy general name', 'azure-plugin'),
            'singular_name'     => _x('Class Provider', 'taxonomy singular name', 'azure-plugin'),
            'search_items'      => __('Search Providers', 'azure-plugin'),
            'all_items'         => __('All Providers', 'azure-plugin'),
            'edit_item'         => __('Edit Provider', 'azure-plugin'),
            'update_item'       => __('Update Provider', 'azure-plugin'),
            'add_new_item'      => __('Add New Provider', 'azure-plugin'),
            'new_item_name'     => __('New Provider Name', 'azure-plugin'),
            'menu_name'         => __('Class Providers', 'azure-plugin'),
        );
        
        $args = array(
            'labels'            => $labels,
            'hierarchical'      => false,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_nav_menus' => false,
            'show_tagcloud'     => false,
            'show_in_rest'      => true,
            'rewrite'           => array('slug' => 'class-provider'),
        );
        
        register_taxonomy('class_provider', array('product'), $args);
    }
    
    /**
     * Add custom fields to "Add New Provider" form
     */
    public function add_provider_fields() {
        ?>
        <div class="form-field">
            <label for="provider_company_name"><?php _e('Company Name', 'azure-plugin'); ?> <span class="required">*</span></label>
            <input type="text" name="provider_company_name" id="provider_company_name" required />
            <p class="description"><?php _e('The official company or organization name.', 'azure-plugin'); ?></p>
        </div>
        
        <div class="form-field">
            <label for="provider_contact_person"><?php _e('Contact Person', 'azure-plugin'); ?></label>
            <input type="text" name="provider_contact_person" id="provider_contact_person" />
            <p class="description"><?php _e('Primary contact name for this provider.', 'azure-plugin'); ?></p>
        </div>
        
        <div class="form-field">
            <label for="provider_emergency_contact"><?php _e('Emergency Contact', 'azure-plugin'); ?></label>
            <input type="text" name="provider_emergency_contact" id="provider_emergency_contact" />
            <p class="description"><?php _e('Emergency contact phone or email.', 'azure-plugin'); ?></p>
        </div>
        
        <div class="form-field">
            <label for="provider_website"><?php _e('Website', 'azure-plugin'); ?></label>
            <input type="url" name="provider_website" id="provider_website" />
            <p class="description"><?php _e('Provider website URL.', 'azure-plugin'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Add custom fields to "Edit Provider" form
     */
    public function edit_provider_fields($term, $taxonomy) {
        $company_name = get_term_meta($term->term_id, 'provider_company_name', true);
        $contact_person = get_term_meta($term->term_id, 'provider_contact_person', true);
        $emergency_contact = get_term_meta($term->term_id, 'provider_emergency_contact', true);
        $website = get_term_meta($term->term_id, 'provider_website', true);
        ?>
        <tr class="form-field">
            <th scope="row">
                <label for="provider_company_name"><?php _e('Company Name', 'azure-plugin'); ?> <span class="required">*</span></label>
            </th>
            <td>
                <input type="text" name="provider_company_name" id="provider_company_name" value="<?php echo esc_attr($company_name); ?>" required />
                <p class="description"><?php _e('The official company or organization name.', 'azure-plugin'); ?></p>
            </td>
        </tr>
        
        <tr class="form-field">
            <th scope="row">
                <label for="provider_contact_person"><?php _e('Contact Person', 'azure-plugin'); ?></label>
            </th>
            <td>
                <input type="text" name="provider_contact_person" id="provider_contact_person" value="<?php echo esc_attr($contact_person); ?>" />
                <p class="description"><?php _e('Primary contact name for this provider.', 'azure-plugin'); ?></p>
            </td>
        </tr>
        
        <tr class="form-field">
            <th scope="row">
                <label for="provider_emergency_contact"><?php _e('Emergency Contact', 'azure-plugin'); ?></label>
            </th>
            <td>
                <input type="text" name="provider_emergency_contact" id="provider_emergency_contact" value="<?php echo esc_attr($emergency_contact); ?>" />
                <p class="description"><?php _e('Emergency contact phone or email.', 'azure-plugin'); ?></p>
            </td>
        </tr>
        
        <tr class="form-field">
            <th scope="row">
                <label for="provider_website"><?php _e('Website', 'azure-plugin'); ?></label>
            </th>
            <td>
                <input type="url" name="provider_website" id="provider_website" value="<?php echo esc_url($website); ?>" />
                <p class="description"><?php _e('Provider website URL.', 'azure-plugin'); ?></p>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Save provider custom fields
     */
    public function save_provider_fields($term_id) {
        if (isset($_POST['provider_company_name'])) {
            update_term_meta($term_id, 'provider_company_name', sanitize_text_field($_POST['provider_company_name']));
        }
        if (isset($_POST['provider_contact_person'])) {
            update_term_meta($term_id, 'provider_contact_person', sanitize_text_field($_POST['provider_contact_person']));
        }
        if (isset($_POST['provider_emergency_contact'])) {
            update_term_meta($term_id, 'provider_emergency_contact', sanitize_text_field($_POST['provider_emergency_contact']));
        }
        if (isset($_POST['provider_website'])) {
            update_term_meta($term_id, 'provider_website', esc_url_raw($_POST['provider_website']));
        }
    }
    
    /**
     * AJAX: Set final price for a class product
     */
    public function ajax_set_final_price() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $product_id = intval($_POST['product_id'] ?? 0);
        $final_price = floatval($_POST['final_price'] ?? 0);
        
        if (!$product_id || $final_price <= 0) {
            wp_send_json_error('Invalid product ID or final price');
        }
        
        // Save final price
        update_post_meta($product_id, '_class_final_price', $final_price);
        update_post_meta($product_id, '_class_finalized', 'yes');
        update_post_meta($product_id, '_class_finalized_date', current_time('mysql'));
        
        Azure_Logger::info('Classes: Final price set', array(
            'product_id' => $product_id,
            'final_price' => $final_price
        ));
        
        wp_send_json_success(array(
            'message' => 'Final price set successfully',
            'final_price' => $final_price
        ));
    }
    
    /**
     * AJAX: Get commitment count for a product
     */
    public function ajax_get_commitments() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $product_id = intval($_POST['product_id'] ?? 0);
        
        if (!$product_id) {
            wp_send_json_error('Invalid product ID');
        }
        
        if (class_exists('Azure_Classes_Commitment')) {
            $commitment = new Azure_Classes_Commitment();
            $count = $commitment->get_commitment_count($product_id);
            $orders = $commitment->get_committed_orders($product_id);
            
            wp_send_json_success(array(
                'count' => $count,
                'orders' => $orders
            ));
        } else {
            wp_send_json_error('Commitment class not found');
        }
    }
    
    /**
     * AJAX: Send payment requests to all committed customers
     */
    public function ajax_send_payment_requests() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $product_id = intval($_POST['product_id'] ?? 0);
        
        if (!$product_id) {
            wp_send_json_error('Invalid product ID');
        }
        
        // Check if final price is set
        $final_price = get_post_meta($product_id, '_class_final_price', true);
        if (empty($final_price)) {
            wp_send_json_error('Final price must be set before sending payment requests');
        }
        
        if (class_exists('Azure_Classes_Commitment')) {
            $commitment = new Azure_Classes_Commitment();
            $result = $commitment->send_payment_requests($product_id, $final_price);
            
            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result['message']);
            }
        } else {
            wp_send_json_error('Commitment class not found');
        }
    }
    
    /**
     * Admin notice for missing WooCommerce
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><strong><?php _e('Azure Classes Module:', 'azure-plugin'); ?></strong> 
            <?php _e('WooCommerce is required for the Classes module to function. Please install and activate WooCommerce.', 'azure-plugin'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Admin notice for missing pta_event CPT class (extremely unlikely \u2014
     * Azure_Event_CPT is part of the plugin itself).
     */
    public function event_cpt_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><strong><?php _e('Azure Classes Module:', 'azure-plugin'); ?></strong>
            <?php _e('The PTA event CPT failed to register. Disable and re-enable the plugin, or check the error log.', 'azure-plugin'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Get season from date
     */
    public static function get_season_from_date($date) {
        $month = date('n', strtotime($date));
        
        if ($month >= 3 && $month <= 5) {
            return 'Spring';
        } elseif ($month >= 6 && $month <= 8) {
            return 'Summer';
        } elseif ($month >= 9 && $month <= 11) {
            return 'Fall';
        } else {
            return 'Winter';
        }
    }
    
    /**
     * Get year from date
     */
    public static function get_year_from_date($date) {
        return date('Y', strtotime($date));
    }
}

