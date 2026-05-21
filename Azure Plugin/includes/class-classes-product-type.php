<?php
/**
 * Classes Module - Custom WooCommerce Product Type
 * 
 * Registers the "Class" product type with custom fields for scheduling,
 * pricing, and class-specific settings.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Classes_Product_Type {
    
    public function __construct() {
        // Register product type
        add_filter('product_type_selector', array($this, 'add_product_type'));
        add_filter('woocommerce_product_class', array($this, 'product_class'), 10, 2);
        
        // Add product data tabs
        add_filter('woocommerce_product_data_tabs', array($this, 'add_product_data_tabs'));
        add_action('woocommerce_product_data_panels', array($this, 'add_product_data_panels'));
        
        // Save product meta
        add_action('woocommerce_process_product_meta', array($this, 'save_product_meta'));
        
        // Show/hide tabs based on product type
        add_action('admin_footer', array($this, 'product_type_js'));
        
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Frontend: Use simple product add to cart template for class products
        add_action('woocommerce_class_add_to_cart', array($this, 'add_to_cart_template'));
        
        // Hide sale badge for variable pricing classes
        add_filter('woocommerce_sale_flash', array($this, 'hide_sale_badge_for_variable_pricing'), 10, 3);
        
        // Custom price display for variable pricing classes
        add_filter('woocommerce_get_price_html', array($this, 'custom_price_html_for_variable_pricing'), 10, 2);
        
        // Hide "on sale" in cart for variable pricing classes
        add_filter('woocommerce_cart_item_price', array($this, 'custom_cart_price_for_variable_pricing'), 10, 3);
    }
    
    /**
     * Use simple product add to cart template for class products
     */
    public function add_to_cart_template() {
        wc_get_template('single-product/add-to-cart/simple.php');
    }
    
    /**
     * Hide sale badge for variable pricing class products
     */
    public function hide_sale_badge_for_variable_pricing($html, $post, $product) {
        if ($product && $product->get_type() === 'class') {
            $variable_pricing = get_post_meta($product->get_id(), '_class_variable_pricing', true);
            if ($variable_pricing === 'yes') {
                return ''; // Hide the sale badge
            }
        }
        return $html;
    }
    
    /**
     * Custom price HTML for variable pricing class products
     */
    public function custom_price_html_for_variable_pricing($price_html, $product) {
        if ($product && $product->get_type() === 'class') {
            $variable_pricing = get_post_meta($product->get_id(), '_class_variable_pricing', true);
            if ($variable_pricing === 'yes') {
                $finalized = get_post_meta($product->get_id(), '_class_finalized', true);
                
                if ($finalized === 'yes') {
                    $final_price = get_post_meta($product->get_id(), '_class_final_price', true);
                    return wc_price($final_price);
                } else {
                    // Show "Price TBD" or likely price
                    $min_price = get_post_meta($product->get_id(), '_class_price_at_min', true);
                    $max_price = get_post_meta($product->get_id(), '_class_price_at_max', true);
                    
                    if ($min_price && $max_price) {
                        return '<span class="price-tbd">' . sprintf(__('Price: %s - %s', 'azure-plugin'), wc_price($max_price), wc_price($min_price)) . '</span>';
                    }
                    return '<span class="price-tbd">' . __('Price TBD', 'azure-plugin') . '</span>';
                }
            }
        }
        return $price_html;
    }
    
    /**
     * Custom cart price for variable pricing class products
     */
    public function custom_cart_price_for_variable_pricing($price_html, $cart_item, $cart_item_key) {
        $product = $cart_item['data'];
        if ($product && $product->get_type() === 'class') {
            $product_id = $product->get_id();
            $variable_pricing = get_post_meta($product_id, '_class_variable_pricing', true);
            
            if ($variable_pricing === 'yes') {
                $finalized = get_post_meta($product_id, '_class_finalized', true);
                
                if ($finalized === 'yes') {
                    $final_price = get_post_meta($product_id, '_class_final_price', true);
                    return wc_price($final_price);
                } else {
                    // Show "Price TBD" in cart
                    return '<span class="price-tbd">' . __('Price TBD', 'azure-plugin') . '</span>';
                }
            }
        }
        return $price_html;
    }
    
    /**
     * Add "Class" to product type selector
     */
    public function add_product_type($types) {
        $types['class'] = __('Class', 'azure-plugin');
        return $types;
    }
    
    /**
     * Return custom product class for "class" type
     */
    public function product_class($classname, $product_type) {
        if ($product_type === 'class') {
            return 'WC_Product_Class';
        }
        return $classname;
    }
    
    /**
     * Add custom product data tabs
     */
    public function add_product_data_tabs($tabs) {
        // Class Schedule tab
        $tabs['class_schedule'] = array(
            'label'    => __('Class Schedule', 'azure-plugin'),
            'target'   => 'class_schedule_data',
            'class'    => array('show_if_class'),
            'priority' => 15
        );
        
        // Class Details tab
        $tabs['class_details'] = array(
            'label'    => __('Class Details', 'azure-plugin'),
            'target'   => 'class_details_data',
            'class'    => array('show_if_class'),
            'priority' => 16
        );
        
        // Class Pricing tab
        $tabs['class_pricing'] = array(
            'label'    => __('Class Pricing', 'azure-plugin'),
            'target'   => 'class_pricing_data',
            'class'    => array('show_if_class'),
            'priority' => 17
        );
        
        return $tabs;
    }
    
    /**
     * Add content to custom product data panels
     */
    public function add_product_data_panels() {
        global $post;
        $product_id = $post->ID;
        
        // Get saved values
        $start_date = get_post_meta($product_id, '_class_start_date', true);
        $season = get_post_meta($product_id, '_class_season', true);
        $recurrence = get_post_meta($product_id, '_class_recurrence', true);
        $occurrences = get_post_meta($product_id, '_class_occurrences', true);
        $start_time = get_post_meta($product_id, '_class_start_time', true);
        $duration = get_post_meta($product_id, '_class_duration', true);
        $duration_unit = get_post_meta($product_id, '_class_duration_unit', true) ?: 'minutes';
        
        $provider_id = get_post_meta($product_id, '_class_provider', true);
        $venue_id = get_post_meta($product_id, '_class_venue', true);
        $chaperone_id = get_post_meta($product_id, '_class_chaperone', true);
        $chaperone_email = get_post_meta($product_id, '_class_chaperone_email', true);
        
        $variable_pricing = get_post_meta($product_id, '_class_variable_pricing', true);
        $min_attendees = get_post_meta($product_id, '_class_min_attendees', true);
        $price_at_min = get_post_meta($product_id, '_class_price_at_min', true);
        $max_attendees = get_post_meta($product_id, '_class_max_attendees', true);
        $price_at_max = get_post_meta($product_id, '_class_price_at_max', true);
        $final_price = get_post_meta($product_id, '_class_final_price', true);
        $finalized = get_post_meta($product_id, '_class_finalized', true);
        
        // Get fixed pricing values (standard WooCommerce price fields)
        $regular_price = get_post_meta($product_id, '_regular_price', true);
        $sale_price = get_post_meta($product_id, '_sale_price', true);
        $fixed_stock = get_post_meta($product_id, '_stock', true);
        
        ?>
        <!-- Class Schedule Panel -->
        <div id="class_schedule_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <?php
                woocommerce_wp_text_input(array(
                    'id'          => '_class_start_date',
                    'label'       => __('Start Date', 'azure-plugin'),
                    'type'        => 'date',
                    'value'       => $start_date,
                    'desc_tip'    => true,
                    'description' => __('The date of the first class session.', 'azure-plugin')
                ));
                
                woocommerce_wp_select(array(
                    'id'          => '_class_season',
                    'label'       => __('Season', 'azure-plugin'),
                    'value'       => $season,
                    'options'     => array(
                        ''       => __('Auto-detect from date', 'azure-plugin'),
                        'Spring' => __('Spring', 'azure-plugin'),
                        'Summer' => __('Summer', 'azure-plugin'),
                        'Fall'   => __('Fall', 'azure-plugin'),
                        'Winter' => __('Winter', 'azure-plugin')
                    ),
                    'desc_tip'    => true,
                    'description' => __('Season for the class. Auto-detected from start date if not set.', 'azure-plugin')
                ));
                
                woocommerce_wp_select(array(
                    'id'          => '_class_recurrence',
                    'label'       => __('Recurrence', 'azure-plugin'),
                    'value'       => $recurrence,
                    'options'     => array(
                        ''         => __('Select...', 'azure-plugin'),
                        'daily'    => __('Daily', 'azure-plugin'),
                        'weekly'   => __('Weekly', 'azure-plugin'),
                        'biweekly' => __('Every 2 Weeks', 'azure-plugin'),
                        'monthly'  => __('Monthly', 'azure-plugin')
                    ),
                    'desc_tip'    => true,
                    'description' => __('How often the class repeats.', 'azure-plugin')
                ));
                
                woocommerce_wp_text_input(array(
                    'id'                => '_class_occurrences',
                    'label'             => __('Number of Sessions', 'azure-plugin'),
                    'type'              => 'number',
                    'value'             => $occurrences,
                    'custom_attributes' => array('min' => '1', 'step' => '1'),
                    'desc_tip'          => true,
                    'description'       => __('Total number of class sessions.', 'azure-plugin')
                ));
                
                woocommerce_wp_text_input(array(
                    'id'          => '_class_start_time',
                    'label'       => __('Start Time', 'azure-plugin'),
                    'type'        => 'time',
                    'value'       => $start_time,
                    'desc_tip'    => true,
                    'description' => __('The time each class session starts.', 'azure-plugin')
                ));
                ?>
                
                <p class="form-field _class_duration_field">
                    <label for="_class_duration"><?php _e('Duration', 'azure-plugin'); ?></label>
                    <input type="number" id="_class_duration" name="_class_duration" value="<?php echo esc_attr($duration); ?>" min="1" step="1" style="width: 80px;" />
                    <select id="_class_duration_unit" name="_class_duration_unit" style="width: auto;">
                        <option value="minutes" <?php selected($duration_unit, 'minutes'); ?>><?php _e('Minutes', 'azure-plugin'); ?></option>
                        <option value="hours" <?php selected($duration_unit, 'hours'); ?>><?php _e('Hours', 'azure-plugin'); ?></option>
                    </select>
                    <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e('Duration of each class session.', 'azure-plugin'); ?>"></span>
                </p>
            </div>
        </div>
        
        <!-- Class Details Panel -->
        <div id="class_details_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <?php
                // Get class providers
                $providers = get_terms(array(
                    'taxonomy'   => 'class_provider',
                    'hide_empty' => false
                ));
                
                $provider_options = array('' => __('Select Provider...', 'azure-plugin'));
                if (!is_wp_error($providers)) {
                    foreach ($providers as $provider) {
                        $company_name = get_term_meta($provider->term_id, 'provider_company_name', true);
                        $display_name = $company_name ? $company_name : $provider->name;
                        $provider_options[$provider->term_id] = $display_name;
                    }
                }
                
                woocommerce_wp_select(array(
                    'id'          => '_class_provider',
                    'label'       => __('Class Provider', 'azure-plugin'),
                    'value'       => $provider_id,
                    'options'     => $provider_options,
                    'desc_tip'    => true,
                    'description' => __('Select the company/organization providing this class.', 'azure-plugin')
                ));
                ?>
                
                <p class="form-field">
                    <a href="<?php echo admin_url('edit-tags.php?taxonomy=class_provider&post_type=product'); ?>" target="_blank" class="button">
                        <?php _e('Manage Providers', 'azure-plugin'); ?>
                    </a>
                </p>
                
                <?php
                // Get TEC Venues
                $venue_options = array('' => __('Select Venue...', 'azure-plugin'));
                if (class_exists('Tribe__Events__Main')) {
                    $venues = get_posts(array(
                        'post_type'      => 'tribe_venue',
                        'posts_per_page' => -1,
                        'orderby'        => 'title',
                        'order'          => 'ASC'
                    ));
                    foreach ($venues as $venue) {
                        $venue_options[$venue->ID] = $venue->post_title;
                    }
                }
                
                woocommerce_wp_select(array(
                    'id'          => '_class_venue',
                    'label'       => __('Location/Venue', 'azure-plugin'),
                    'value'       => $venue_id,
                    'options'     => $venue_options,
                    'desc_tip'    => true,
                    'description' => __('Select the venue where the class will be held.', 'azure-plugin')
                ));
                
                // Get WordPress users for chaperone - build data for JS
                $users = get_users(array('orderby' => 'display_name'));
                $user_options = array(
                    '' => __('Select...', 'azure-plugin'),
                    'new' => __('➕ Add new user via email', 'azure-plugin')
                );
                $user_emails = array(); // For JavaScript lookup
                foreach ($users as $user) {
                    $user_options[$user->ID] = $user->display_name . ' (' . $user->user_email . ')';
                    $user_emails[$user->ID] = $user->user_email;
                }
                
                woocommerce_wp_select(array(
                    'id'          => '_class_chaperone',
                    'label'       => __('Chaperone', 'azure-plugin'),
                    'value'       => $chaperone_id,
                    'options'     => $user_options,
                    'desc_tip'    => true,
                    'description' => __('Select an existing user or add a new one via email.', 'azure-plugin'),
                    'custom_attributes' => array(
                        'data-user-emails' => esc_attr(json_encode($user_emails))
                    )
                ));
                
                // Determine if email field should be shown/editable
                $email_readonly = !empty($chaperone_id) && $chaperone_id !== 'new';
                $show_email = empty($chaperone_id) || $chaperone_id === 'new' || !empty($chaperone_email);
                ?>
                
                <p class="form-field _class_chaperone_email_field" id="chaperone-email-wrapper" style="<?php echo $show_email ? '' : 'display:none;'; ?>">
                    <label for="_class_chaperone_email"><?php _e('Chaperone Email', 'azure-plugin'); ?></label>
                    <input type="email" 
                           id="_class_chaperone_email" 
                           name="_class_chaperone_email" 
                           value="<?php echo esc_attr($chaperone_email); ?>" 
                           class="short"
                           <?php echo $email_readonly ? 'readonly style="background:#f0f0f0;"' : ''; ?>
                    />
                    <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e('Email address for the chaperone. Auto-filled for existing users, or enter email to invite a new user.', 'azure-plugin'); ?>"></span>
                    <span id="chaperone-email-note" class="description" style="display:block; margin-top:5px; <?php echo $email_readonly ? '' : 'display:none;'; ?>">
                        <?php _e('✓ Existing user selected - no invitation email needed.', 'azure-plugin'); ?>
                    </span>
                    <span id="chaperone-invite-note" class="description" style="display:block; margin-top:5px; color:#0073aa; <?php echo (!$email_readonly && $chaperone_id === 'new') ? '' : 'display:none;'; ?>">
                        <?php _e('📧 An invitation email will be sent to this address when you save.', 'azure-plugin'); ?>
                    </span>
                </p>
                
                <?php
                ?>
            </div>
        </div>
        
        <!-- Class Pricing Panel -->
        <div id="class_pricing_data" class="panel woocommerce_options_panel">
            
            <!-- Fixed Pricing Fields (shown when Variable Pricing is NOT checked) -->
            <div class="options_group fixed-pricing-fields" style="<?php echo $variable_pricing === 'yes' ? 'display:none;' : ''; ?>">
                <p class="form-field">
                    <strong><?php _e('Fixed Pricing', 'azure-plugin'); ?></strong><br>
                    <span class="description"><?php _e('Set a fixed price for this class.', 'azure-plugin'); ?></span>
                </p>
                
                <?php
                woocommerce_wp_text_input(array(
                    'id'          => '_regular_price',
                    'label'       => __('Regular Price', 'azure-plugin') . ' (' . get_woocommerce_currency_symbol() . ')',
                    'type'        => 'text',
                    'value'       => $regular_price,
                    'data_type'   => 'price',
                    'desc_tip'    => true,
                    'description' => __('The regular price for this class.', 'azure-plugin')
                ));
                
                woocommerce_wp_text_input(array(
                    'id'          => '_sale_price',
                    'label'       => __('Sale Price', 'azure-plugin') . ' (' . get_woocommerce_currency_symbol() . ')',
                    'type'        => 'text',
                    'value'       => $sale_price,
                    'data_type'   => 'price',
                    'desc_tip'    => true,
                    'description' => __('Optional sale price for this class.', 'azure-plugin')
                ));
                
                woocommerce_wp_text_input(array(
                    'id'                => '_stock',
                    'label'             => __('Available Spots', 'azure-plugin'),
                    'type'              => 'number',
                    'value'             => $fixed_stock,
                    'custom_attributes' => array('min' => '0', 'step' => '1'),
                    'desc_tip'          => true,
                    'description'       => __('Maximum number of students who can enroll. Leave empty for unlimited.', 'azure-plugin')
                ));
                
                // Backorder options
                $backorders = get_post_meta($product_id, '_backorders', true);
                woocommerce_wp_select(array(
                    'id'          => '_backorders',
                    'label'       => __('Allow Waitlist?', 'azure-plugin'),
                    'value'       => $backorders ?: 'no',
                    'options'     => array(
                        'no'     => __('Do not allow', 'azure-plugin'),
                        'notify' => __('Allow, but notify customer', 'azure-plugin'),
                        'yes'    => __('Allow', 'azure-plugin')
                    ),
                    'desc_tip'    => true,
                    'description' => __('If enabled, customers can still enroll when the class is full (waitlist).', 'azure-plugin')
                ));
                
                // Tax options
                $tax_status = get_post_meta($product_id, '_tax_status', true);
                $tax_class = get_post_meta($product_id, '_tax_class', true);
                
                woocommerce_wp_select(array(
                    'id'          => '_tax_status',
                    'label'       => __('Tax status', 'azure-plugin'),
                    'value'       => $tax_status ?: 'taxable',
                    'options'     => array(
                        'taxable'  => __('Taxable', 'azure-plugin'),
                        'shipping' => __('Shipping only', 'azure-plugin'),
                        'none'     => __('None', 'azure-plugin')
                    ),
                    'desc_tip'    => true,
                    'description' => __('Define whether or not the entire product is taxable.', 'azure-plugin')
                ));
                
                // Get tax classes
                $tax_classes = WC_Tax::get_tax_classes();
                $tax_class_options = array('' => __('Standard', 'azure-plugin'));
                foreach ($tax_classes as $class) {
                    $tax_class_options[sanitize_title($class)] = $class;
                }
                
                woocommerce_wp_select(array(
                    'id'          => '_tax_class',
                    'label'       => __('Tax class', 'azure-plugin'),
                    'value'       => $tax_class,
                    'options'     => $tax_class_options,
                    'desc_tip'    => true,
                    'description' => __('Choose a tax class for this product. Tax classes are used to apply different tax rates.', 'azure-plugin')
                ));
                ?>
            </div>
            
            <!-- Variable Pricing Checkbox -->
            <div class="options_group">
                <?php
                woocommerce_wp_checkbox(array(
                    'id'          => '_class_variable_pricing',
                    'label'       => __('Variable Pricing', 'azure-plugin'),
                    'value'       => $variable_pricing,
                    'description' => __('Enable variable pricing based on number of attendees. Price decreases as more families enroll.', 'azure-plugin')
                ));
                ?>
            </div>
            
            <!-- Variable Pricing Fields (shown when Variable Pricing IS checked) -->
            <div class="options_group variable-pricing-fields" style="<?php echo $variable_pricing !== 'yes' ? 'display:none;' : ''; ?>">
                <p class="form-field">
                    <strong><?php _e('Variable Pricing Settings', 'azure-plugin'); ?></strong><br>
                    <span class="description"><?php _e('Set the price range based on number of attendees. Price will be calculated proportionally.', 'azure-plugin'); ?></span>
                </p>
                
                <?php
                woocommerce_wp_text_input(array(
                    'id'                => '_class_min_attendees',
                    'label'             => __('Minimum Attendees', 'azure-plugin'),
                    'type'              => 'number',
                    'value'             => $min_attendees,
                    'custom_attributes' => array('min' => '1', 'step' => '1'),
                    'desc_tip'          => true,
                    'description'       => __('Minimum number of attendees required for the class to run.', 'azure-plugin')
                ));
                
                woocommerce_wp_text_input(array(
                    'id'                => '_class_price_at_min',
                    'label'             => __('Price at Minimum', 'azure-plugin') . ' (' . get_woocommerce_currency_symbol() . ')',
                    'type'              => 'text',
                    'value'             => $price_at_min,
                    'data_type'         => 'price',
                    'desc_tip'          => true,
                    'description'       => __('Price per attendee if only minimum attendees enroll (highest price).', 'azure-plugin')
                ));
                
                woocommerce_wp_text_input(array(
                    'id'                => '_class_max_attendees',
                    'label'             => __('Maximum Attendees', 'azure-plugin'),
                    'type'              => 'number',
                    'value'             => $max_attendees,
                    'custom_attributes' => array('min' => '1', 'step' => '1'),
                    'desc_tip'          => true,
                    'description'       => __('Maximum number of attendees. This also sets the product stock.', 'azure-plugin')
                ));
                
                woocommerce_wp_text_input(array(
                    'id'                => '_class_price_at_max',
                    'label'             => __('Price at Maximum', 'azure-plugin') . ' (' . get_woocommerce_currency_symbol() . ')',
                    'type'              => 'text',
                    'value'             => $price_at_max,
                    'data_type'         => 'price',
                    'desc_tip'          => true,
                    'description'       => __('Price per attendee if maximum attendees enroll (lowest price).', 'azure-plugin')
                ));
                ?>
            </div>
            
            <!-- Finalize Section (only for variable pricing) -->
            <div class="options_group finalize-section" style="<?php echo $variable_pricing !== 'yes' ? 'display:none;' : ''; ?>">
                <?php if ($finalized === 'yes') : ?>
                <p class="form-field">
                    <strong style="color: green;"><?php _e('✓ Class Finalized', 'azure-plugin'); ?></strong><br>
                    <span><?php printf(__('Final Price: %s', 'azure-plugin'), wc_price($final_price)); ?></span>
                </p>
                <?php else : ?>
                <p class="form-field">
                    <strong><?php _e('Finalize Class', 'azure-plugin'); ?></strong><br>
                    <span class="description"><?php _e('Once you set the final price, payment requests will be sent to all committed customers.', 'azure-plugin'); ?></span>
                </p>
                
                <?php
                woocommerce_wp_text_input(array(
                    'id'                => '_class_final_price',
                    'label'             => __('Final Price', 'azure-plugin') . ' (' . get_woocommerce_currency_symbol() . ')',
                    'type'              => 'text',
                    'value'             => $final_price,
                    'data_type'         => 'price',
                    'desc_tip'          => true,
                    'description'       => __('Set the final price and click "Set Final Price" to notify customers.', 'azure-plugin')
                ));
                ?>
                
                <p class="form-field">
                    <button type="button" class="button button-primary" id="set-final-price-btn" data-product-id="<?php echo $product_id; ?>">
                        <?php _e('Set Final Price & Send Payment Requests', 'azure-plugin'); ?>
                    </button>
                    <span class="spinner" style="float: none; margin-top: 0;"></span>
                    <span class="finalize-status"></span>
                </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Save custom product meta
     */
    public function save_product_meta($product_id) {
        // Check if this is a class product
        $product_type = isset($_POST['product-type']) ? sanitize_text_field($_POST['product-type']) : '';
        if ($product_type !== 'class') {
            return;
        }
        
        // Schedule fields
        if (isset($_POST['_class_start_date'])) {
            update_post_meta($product_id, '_class_start_date', sanitize_text_field($_POST['_class_start_date']));
        }
        
        // Auto-detect season if not set
        $season = isset($_POST['_class_season']) ? sanitize_text_field($_POST['_class_season']) : '';
        if (empty($season) && !empty($_POST['_class_start_date'])) {
            $season = Azure_Classes_Module::get_season_from_date($_POST['_class_start_date']);
        }
        update_post_meta($product_id, '_class_season', $season);
        
        if (isset($_POST['_class_recurrence'])) {
            update_post_meta($product_id, '_class_recurrence', sanitize_text_field($_POST['_class_recurrence']));
        }
        if (isset($_POST['_class_occurrences'])) {
            update_post_meta($product_id, '_class_occurrences', intval($_POST['_class_occurrences']));
        }
        if (isset($_POST['_class_start_time'])) {
            update_post_meta($product_id, '_class_start_time', sanitize_text_field($_POST['_class_start_time']));
        }
        if (isset($_POST['_class_duration'])) {
            update_post_meta($product_id, '_class_duration', intval($_POST['_class_duration']));
        }
        if (isset($_POST['_class_duration_unit'])) {
            update_post_meta($product_id, '_class_duration_unit', sanitize_text_field($_POST['_class_duration_unit']));
        }
        
        // Details fields
        if (isset($_POST['_class_provider'])) {
            update_post_meta($product_id, '_class_provider', intval($_POST['_class_provider']));
        }
        if (isset($_POST['_class_venue'])) {
            update_post_meta($product_id, '_class_venue', intval($_POST['_class_venue']));
        }
        
        // Handle chaperone - could be existing user ID, "new", or empty
        $chaperone_value = isset($_POST['_class_chaperone']) ? sanitize_text_field($_POST['_class_chaperone']) : '';
        $chaperone_email = isset($_POST['_class_chaperone_email']) ? sanitize_email($_POST['_class_chaperone_email']) : '';
        
        if ($chaperone_value === 'new' && !empty($chaperone_email)) {
            // New user invitation - store email, clear user ID
            update_post_meta($product_id, '_class_chaperone', '');
            update_post_meta($product_id, '_class_chaperone_email', $chaperone_email);
            update_post_meta($product_id, '_class_chaperone_invited', 'yes');
            
            // Trigger invitation email
            do_action('azure_classes_invite_chaperone', $chaperone_email, $product_id);
        } elseif (!empty($chaperone_value) && $chaperone_value !== 'new') {
            // Existing user selected
            update_post_meta($product_id, '_class_chaperone', intval($chaperone_value));
            // Store their email too for reference
            $user = get_user_by('id', intval($chaperone_value));
            if ($user) {
                update_post_meta($product_id, '_class_chaperone_email', $user->user_email);
            }
            delete_post_meta($product_id, '_class_chaperone_invited');
        } else {
            // No chaperone selected
            update_post_meta($product_id, '_class_chaperone', '');
            update_post_meta($product_id, '_class_chaperone_email', '');
            delete_post_meta($product_id, '_class_chaperone_invited');
        }
        
        // Pricing fields
        $variable_pricing = isset($_POST['_class_variable_pricing']) ? 'yes' : 'no';
        update_post_meta($product_id, '_class_variable_pricing', $variable_pricing);
        
        if ($variable_pricing === 'yes') {
            // Variable pricing - save attendee-based pricing fields
            if (isset($_POST['_class_min_attendees'])) {
                update_post_meta($product_id, '_class_min_attendees', intval($_POST['_class_min_attendees']));
            }
            if (isset($_POST['_class_price_at_min'])) {
                update_post_meta($product_id, '_class_price_at_min', wc_format_decimal($_POST['_class_price_at_min']));
            }
            if (isset($_POST['_class_max_attendees'])) {
                $max_attendees = intval($_POST['_class_max_attendees']);
                update_post_meta($product_id, '_class_max_attendees', $max_attendees);
                
                // Also set stock quantity
                update_post_meta($product_id, '_manage_stock', 'yes');
                update_post_meta($product_id, '_stock', $max_attendees);
                update_post_meta($product_id, '_stock_status', $max_attendees > 0 ? 'instock' : 'outofstock');
            }
            if (isset($_POST['_class_price_at_max'])) {
                update_post_meta($product_id, '_class_price_at_max', wc_format_decimal($_POST['_class_price_at_max']));
            }
            
            // For variable pricing, set product price to 0 (commitment flow)
            update_post_meta($product_id, '_price', 0);
            update_post_meta($product_id, '_regular_price', 0);
            update_post_meta($product_id, '_sale_price', '');
            
        } else {
            // Fixed pricing - save standard WooCommerce price fields
            $regular_price = isset($_POST['_regular_price']) ? wc_format_decimal($_POST['_regular_price']) : '';
            $sale_price = isset($_POST['_sale_price']) ? wc_format_decimal($_POST['_sale_price']) : '';
            $stock = isset($_POST['_stock']) ? intval($_POST['_stock']) : '';
            
            update_post_meta($product_id, '_regular_price', $regular_price);
            update_post_meta($product_id, '_sale_price', $sale_price);
            
            // Set the active price
            if (!empty($sale_price) && $sale_price < $regular_price) {
                update_post_meta($product_id, '_price', $sale_price);
            } else {
                update_post_meta($product_id, '_price', $regular_price);
            }
            
            // Handle stock for fixed pricing
            if (!empty($stock)) {
                update_post_meta($product_id, '_manage_stock', 'yes');
                update_post_meta($product_id, '_stock', $stock);
                update_post_meta($product_id, '_stock_status', $stock > 0 ? 'instock' : 'outofstock');
            } else {
                // No stock limit
                update_post_meta($product_id, '_manage_stock', 'no');
                update_post_meta($product_id, '_stock_status', 'instock');
            }
            
            // Handle backorders (waitlist)
            $backorders = isset($_POST['_backorders']) ? sanitize_text_field($_POST['_backorders']) : 'no';
            update_post_meta($product_id, '_backorders', $backorders);
            
            // Handle tax settings
            $tax_status = isset($_POST['_tax_status']) ? sanitize_text_field($_POST['_tax_status']) : 'taxable';
            $tax_class = isset($_POST['_tax_class']) ? sanitize_text_field($_POST['_tax_class']) : '';
            update_post_meta($product_id, '_tax_status', $tax_status);
            update_post_meta($product_id, '_tax_class', $tax_class);
            
            // Clear variable pricing fields
            update_post_meta($product_id, '_class_min_attendees', '');
            update_post_meta($product_id, '_class_price_at_min', '');
            update_post_meta($product_id, '_class_max_attendees', '');
            update_post_meta($product_id, '_class_price_at_max', '');
        }
        
        // Trigger event generation
        do_action('azure_classes_product_saved', $product_id);
        
        Azure_Logger::info('Classes: Product meta saved', array(
            'product_id' => $product_id,
            'variable_pricing' => $variable_pricing
        ));
    }
    
    /**
     * JavaScript to show/hide tabs based on product type
     */
    public function product_type_js() {
        global $post;
        
        if (!$post || get_post_type($post) !== 'product') {
            return;
        }
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Show/hide class tabs based on product type
            function toggleClassTabs() {
                var productType = $('#product-type').val();
                
                if (productType === 'class') {
                    $('.show_if_class').show();
                    // Hide General tab (pricing is in Class Pricing tab)
                    $('.general_options').hide();
                    $('li.general_options').hide();
                    // Hide inventory tab (stock is managed in Class Pricing tab)
                    $('.inventory_options').hide();
                    $('li.inventory_tab').hide();
                    // Hide shipping (classes are virtual)
                    $('.shipping_options').hide();
                    $('li.shipping_tab').hide();
                    // Hide linked products tab
                    $('li.linked_product_tab').hide();
                    // Hide attributes tab (not needed for classes)
                    $('li.attribute_tab').hide();
                } else {
                    $('.show_if_class').hide();
                    // Restore tabs for other product types
                    $('.general_options').show();
                    $('li.general_options').show();
                    $('.inventory_options').show();
                    $('li.inventory_tab').show();
                }
            }
            
            // Initial toggle
            toggleClassTabs();
            
            // Toggle on product type change
            $('#product-type').on('change', toggleClassTabs);
            
            // Also trigger when page loads to ensure proper state
            setTimeout(toggleClassTabs, 100);
            
            // Variable pricing toggle
            function togglePricingFields() {
                if ($('#_class_variable_pricing').is(':checked')) {
                    $('.variable-pricing-fields').slideDown();
                    $('.finalize-section').slideDown();
                    $('.fixed-pricing-fields').slideUp();
                } else {
                    $('.variable-pricing-fields').slideUp();
                    $('.finalize-section').slideUp();
                    $('.fixed-pricing-fields').slideDown();
                }
            }
            
            // Initial toggle on page load
            togglePricingFields();
            
            // Toggle on checkbox change
            $('#_class_variable_pricing').on('change', togglePricingFields);
            
            // Auto-detect season from date
            $('#_class_start_date').on('change', function() {
                var date = $(this).val();
                if (date && $('#_class_season').val() === '') {
                    var month = new Date(date).getMonth() + 1;
                    var season = '';
                    
                    if (month >= 3 && month <= 5) season = 'Spring';
                    else if (month >= 6 && month <= 8) season = 'Summer';
                    else if (month >= 9 && month <= 11) season = 'Fall';
                    else season = 'Winter';
                    
                    $('#_class_season').val(season);
                }
            });
            
            // Chaperone selection - auto-populate email
            var userEmails = {};
            try {
                userEmails = JSON.parse($('#_class_chaperone').attr('data-user-emails') || '{}');
            } catch(e) {
                userEmails = {};
            }
            
            $('#_class_chaperone').on('change', function() {
                var selectedValue = $(this).val();
                var emailField = $('#_class_chaperone_email');
                var emailWrapper = $('#chaperone-email-wrapper');
                var emailNote = $('#chaperone-email-note');
                var inviteNote = $('#chaperone-invite-note');
                
                if (selectedValue === '') {
                    // Nothing selected - hide email field
                    emailWrapper.hide();
                    emailField.val('').prop('readonly', false).css('background', '');
                    emailNote.hide();
                    inviteNote.hide();
                } else if (selectedValue === 'new') {
                    // Add new user - show editable email field
                    emailWrapper.show();
                    emailField.val('').prop('readonly', false).css('background', '').focus();
                    emailNote.hide();
                    inviteNote.show();
                } else {
                    // Existing user selected - auto-fill and make readonly
                    var email = userEmails[selectedValue] || '';
                    emailWrapper.show();
                    emailField.val(email).prop('readonly', true).css('background', '#f0f0f0');
                    emailNote.show();
                    inviteNote.hide();
                }
            });
            
            // Set Final Price button
            $('#set-final-price-btn').on('click', function() {
                var btn = $(this);
                var productId = btn.data('product-id');
                var finalPrice = $('#_class_final_price').val();
                var spinner = btn.siblings('.spinner');
                var status = btn.siblings('.finalize-status');
                
                if (!finalPrice || parseFloat(finalPrice) <= 0) {
                    alert('Please enter a valid final price.');
                    return;
                }
                
                if (!confirm('Are you sure you want to set the final price? This will send payment requests to all committed customers.')) {
                    return;
                }
                
                btn.prop('disabled', true);
                spinner.addClass('is-active');
                status.text('');
                
                // First set the final price
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'azure_classes_set_final_price',
                        nonce: '<?php echo wp_create_nonce('azure_plugin_nonce'); ?>',
                        product_id: productId,
                        final_price: finalPrice
                    },
                    success: function(response) {
                        if (response.success) {
                            // Now send payment requests
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'azure_classes_send_payment_requests',
                                    nonce: '<?php echo wp_create_nonce('azure_plugin_nonce'); ?>',
                                    product_id: productId
                                },
                                success: function(response2) {
                                    spinner.removeClass('is-active');
                                    btn.prop('disabled', false);
                                    
                                    if (response2.success) {
                                        status.html('<span style="color: green;">✓ ' + response2.data.message + '</span>');
                                        // Reload page to show finalized state
                                        setTimeout(function() {
                                            location.reload();
                                        }, 2000);
                                    } else {
                                        status.html('<span style="color: red;">✗ ' + response2.data + '</span>');
                                    }
                                },
                                error: function() {
                                    spinner.removeClass('is-active');
                                    btn.prop('disabled', false);
                                    status.html('<span style="color: red;">✗ Network error</span>');
                                }
                            });
                        } else {
                            spinner.removeClass('is-active');
                            btn.prop('disabled', false);
                            status.html('<span style="color: red;">✗ ' + response.data + '</span>');
                        }
                    },
                    error: function() {
                        spinner.removeClass('is-active');
                        btn.prop('disabled', false);
                        status.html('<span style="color: red;">✗ Network error</span>');
                    }
                });
            });
        });
        </script>
        
        <style>
        .show_if_class { display: none; }
        #woocommerce-product-data ul.wc-tabs li.class_schedule_options a::before { content: '\f145'; }
        #woocommerce-product-data ul.wc-tabs li.class_details_options a::before { content: '\f307'; }
        #woocommerce-product-data ul.wc-tabs li.class_pricing_options a::before { content: '\f155'; }
        </style>
        <?php
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }
        
        global $post;
        if (!$post || get_post_type($post) !== 'product') {
            return;
        }
        
        wp_enqueue_style('azure-classes-admin', AZURE_PLUGIN_URL . 'css/classes-admin.css', array(), AZURE_PLUGIN_VERSION);
    }
}

/**
 * Custom Product Class for "Class" type
 */
if (!class_exists('WC_Product')) {
    return;
}

class WC_Product_Class extends WC_Product {
    
    public function __construct($product = 0) {
        $this->product_type = 'class';
        parent::__construct($product);
    }
    
    public function get_type() {
        return 'class';
    }
    
    /**
     * Classes are purchasable
     */
    public function is_purchasable() {
        return true;
    }
    
    /**
     * Classes are in stock if they have available spots
     */
    public function is_in_stock() {
        $stock_status = $this->get_stock_status();
        return $stock_status === 'instock' || $stock_status === 'onbackorder';
    }
    
    /**
     * Classes are virtual by default
     */
    public function is_virtual() {
        return true;
    }
    
    /**
     * Classes are not downloadable
     */
    public function is_downloadable() {
        return false;
    }
    
    /**
     * Classes can be sold individually or allow multiple
     */
    public function is_sold_individually() {
        return true; // Each enrollment is for one student
    }
    
    /**
     * Get the price for display
     */
    public function get_price($context = 'view') {
        $price = parent::get_price($context);
        
        // For variable pricing that's not finalized, return 0 (commit to buy)
        if ($this->is_variable_pricing() && !$this->is_finalized()) {
            return 0;
        }
        
        return $price;
    }
    
    /**
     * Classes support stock management
     */
    public function managing_stock() {
        if ('yes' === get_option('woocommerce_manage_stock')) {
            return 'yes' === $this->get_manage_stock();
        }
        return false;
    }
    
    /**
     * Get stock quantity - for variable pricing, this is max attendees
     */
    public function get_stock_quantity($context = 'view') {
        $stock = parent::get_stock_quantity($context);
        
        // If variable pricing and no stock set, use max_attendees
        if ($stock === null || $stock === '') {
            $variable_pricing = get_post_meta($this->get_id(), '_class_variable_pricing', true);
            if ($variable_pricing === 'yes') {
                $max_attendees = get_post_meta($this->get_id(), '_class_max_attendees', true);
                if (!empty($max_attendees)) {
                    return intval($max_attendees);
                }
            }
        }
        
        return $stock;
    }
    
    /**
     * Check if this is a variable pricing class
     */
    public function is_variable_pricing() {
        return get_post_meta($this->get_id(), '_class_variable_pricing', true) === 'yes';
    }
    
    /**
     * Check if class is finalized
     */
    public function is_finalized() {
        return get_post_meta($this->get_id(), '_class_finalized', true) === 'yes';
    }
    
    /**
     * Get the final price (for finalized variable pricing classes)
     */
    public function get_final_price() {
        return get_post_meta($this->get_id(), '_class_final_price', true);
    }
    
    /**
     * Get class schedule info
     */
    public function get_schedule_info() {
        return array(
            'start_date'    => get_post_meta($this->get_id(), '_class_start_date', true),
            'season'        => get_post_meta($this->get_id(), '_class_season', true),
            'recurrence'    => get_post_meta($this->get_id(), '_class_recurrence', true),
            'occurrences'   => get_post_meta($this->get_id(), '_class_occurrences', true),
            'start_time'    => get_post_meta($this->get_id(), '_class_start_time', true),
            'duration'      => get_post_meta($this->get_id(), '_class_duration', true),
            'duration_unit' => get_post_meta($this->get_id(), '_class_duration_unit', true)
        );
    }
    
    /**
     * Get linked TEC event IDs
     */
    public function get_event_ids() {
        $ids = get_post_meta($this->get_id(), '_class_event_ids', true);
        return is_array($ids) ? $ids : array();
    }
}

