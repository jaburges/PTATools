<?php
/**
 * Tickets Module - WooCommerce Product Type
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register the Ticket product type with WooCommerce
 */
add_filter('product_type_selector', function($types) {
    $types['ticket'] = __('Event Ticket', 'azure-plugin');
    return $types;
});

/**
 * WC_Product_Ticket class
 */
if (class_exists('WC_Product')) {
    
    class WC_Product_Ticket extends WC_Product {
        
        protected $product_type = 'ticket';
        
        public function __construct($product = 0) {
            parent::__construct($product);
        }
        
        public function get_type() {
            return 'ticket';
        }
        
        /**
         * Returns whether the product is virtual
         */
        public function is_virtual() {
            return true; // Tickets are always virtual (no shipping)
        }
        
        /**
         * Returns whether the product is sold individually
         */
        public function is_sold_individually() {
            return false; // Allow multiple tickets in cart
        }
    }
    
    /**
     * Add ticket to product class list
     */
    add_filter('woocommerce_product_class', function($classname, $product_type) {
        if ($product_type === 'ticket') {
            return 'WC_Product_Ticket';
        }
        return $classname;
    }, 10, 2);
}

/**
 * Add custom tabs to product data
 */
add_filter('woocommerce_product_data_tabs', function($tabs) {
    // Event tab
    $tabs['ticket_event'] = array(
        'label' => __('Event', 'azure-plugin'),
        'target' => 'ticket_event_options',
        'class' => array('show_if_ticket'),
        'priority' => 20
    );
    
    // Seating tab
    $tabs['ticket_seating'] = array(
        'label' => __('Seating', 'azure-plugin'),
        'target' => 'ticket_seating_options',
        'class' => array('show_if_ticket'),
        'priority' => 25
    );
    
    // Ticket settings tab
    $tabs['ticket_settings'] = array(
        'label' => __('Ticket Settings', 'azure-plugin'),
        'target' => 'ticket_settings_options',
        'class' => array('show_if_ticket'),
        'priority' => 30
    );
    
    return $tabs;
});

/**
 * Add Event tab content
 */
add_action('woocommerce_product_data_panels', function() {
    global $post;
    
    // Get TEC venues for dropdown (with seating layouts)
    $venues = array();
    if (class_exists('Tribe__Events__Main')) {
        $tec_venues = get_posts(array(
            'post_type' => 'tribe_venue',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish'
        ));
        
        foreach ($tec_venues as $venue) {
            $has_layout = get_post_meta($venue->ID, '_azure_seating_layout', true);
            $venues[] = (object) array(
                'id' => $venue->ID,
                'name' => $venue->post_title,
                'has_layout' => !empty($has_layout)
            );
        }
    }
    
    // Get TEC events if available
    $events = array();
    if (class_exists('Tribe__Events__Main')) {
        $events = tribe_get_events(array(
            'start_date' => 'now',
            'posts_per_page' => 50,
            'orderby' => 'event_date',
            'order' => 'ASC'
        ));
    }
    
    ?>
    <div id="ticket_event_options" class="panel woocommerce_options_panel">
        <div class="options_group">
            <?php
            // TEC Event link
            if (!empty($events)) {
                $event_options = array('' => __('-- Create New Event --', 'azure-plugin'));
                foreach ($events as $event) {
                    $event_options[$event->ID] = $event->post_title . ' (' . tribe_get_start_date($event, false, 'M j, Y') . ')';
                }
                
                woocommerce_wp_select(array(
                    'id' => '_ticket_event_id',
                    'label' => __('Link to Event', 'azure-plugin'),
                    'description' => __('Select an existing TEC event or leave blank to create new.', 'azure-plugin'),
                    'desc_tip' => true,
                    'options' => $event_options
                ));
            } else {
                echo '<p class="form-field"><strong>' . __('Note:', 'azure-plugin') . '</strong> ' . __('Install The Events Calendar to link tickets to events.', 'azure-plugin') . '</p>';
                
                woocommerce_wp_text_input(array(
                    'id' => '_ticket_event_name',
                    'label' => __('Event Name', 'azure-plugin'),
                    'description' => __('Name for this ticketed event.', 'azure-plugin'),
                    'desc_tip' => true
                ));
            }
            
            woocommerce_wp_text_input(array(
                'id' => '_ticket_event_date',
                'label' => __('Event Date', 'azure-plugin'),
                'description' => __('Date and time of the event.', 'azure-plugin'),
                'desc_tip' => true,
                'type' => 'datetime-local'
            ));
            
            // Venue selection (TEC venues)
            $venue_options = array('' => __('-- Select Venue --', 'azure-plugin'));
            foreach ($venues as $venue) {
                $label = $venue->name;
                if (!$venue->has_layout) {
                    $label .= ' ' . __('(no seating layout)', 'azure-plugin');
                }
                $venue_options[$venue->id] = $label;
            }
            
            woocommerce_wp_select(array(
                'id' => '_ticket_venue_id',
                'label' => __('Venue', 'azure-plugin'),
                'description' => __('Select a TEC venue with a seating layout.', 'azure-plugin'),
                'desc_tip' => true,
                'options' => $venue_options
            ));
            ?>
            
            <p class="form-field">
                <a href="<?php echo admin_url('admin.php?page=azure-plugin-tickets&tab=venues'); ?>" class="button" target="_blank">
                    <?php _e('Manage Venue Seating Layouts', 'azure-plugin'); ?>
                </a>
            </p>
        </div>
    </div>
    
    <div id="ticket_seating_options" class="panel woocommerce_options_panel">
        <div class="options_group">
            <?php
            $venue_id = get_post_meta($post->ID, '_ticket_venue_id', true);
            
            if ($venue_id) {
                $venue = get_post($venue_id);
                $layout_json = get_post_meta($venue_id, '_azure_seating_layout', true);
                $capacity = get_post_meta($venue_id, '_azure_seating_capacity', true);
                
                if ($venue && $venue->post_type === 'tribe_venue') {
                    ?>
                    <div class="venue-preview-container" style="padding: 12px;">
                        <h4><?php echo esc_html($venue->post_title); ?></h4>
                        <?php if ($capacity): ?>
                        <p><strong><?php echo number_format($capacity); ?></strong> <?php _e('total seats', 'azure-plugin'); ?></p>
                        <?php endif; ?>
                        <?php if ($layout_json): ?>
                        <div class="venue-mini-preview" style="height: 200px; background: #f6f7f7; border-radius: 4px; position: relative; overflow: hidden;">
                            <div class="mini-layout" data-layout="<?php echo esc_attr($layout_json); ?>" style="width: 100%; height: 100%;"></div>
                        </div>
                        <?php else: ?>
                        <p style="color: #d63638;"><em><?php _e('This venue does not have a seating layout configured.', 'azure-plugin'); ?></em></p>
                        <?php endif; ?>
                        <p style="margin-top: 10px;">
                            <a href="<?php echo admin_url('admin.php?page=azure-plugin-tickets&tab=venues&action=edit&venue_id=' . $venue_id); ?>" class="button" target="_blank">
                                <?php echo $layout_json ? __('Edit Venue Layout', 'azure-plugin') : __('Add Seating Layout', 'azure-plugin'); ?>
                            </a>
                        </p>
                    </div>
                    <?php
                }
            } else {
                echo '<p class="form-field">' . __('Select a venue in the Event tab to configure seating.', 'azure-plugin') . '</p>';
            }
            ?>
        </div>
    </div>
    
    <div id="ticket_settings_options" class="panel woocommerce_options_panel">
        <div class="options_group">
            <?php
            woocommerce_wp_text_input(array(
                'id' => '_tickets_per_order_limit',
                'label' => __('Max Tickets per Order', 'azure-plugin'),
                'description' => __('Maximum number of tickets a customer can buy in one order. Leave blank for unlimited.', 'azure-plugin'),
                'desc_tip' => true,
                'type' => 'number',
                'custom_attributes' => array('min' => 1)
            ));
            
            woocommerce_wp_checkbox(array(
                'id' => '_ticket_require_names',
                'label' => __('Require Attendee Names', 'azure-plugin'),
                'description' => __('Require a name for each ticket at checkout.', 'azure-plugin')
            ));
            
            woocommerce_wp_checkbox(array(
                'id' => '_ticket_allow_transfers',
                'label' => __('Allow Ticket Transfers', 'azure-plugin'),
                'description' => __('Allow customers to transfer tickets to others. (Coming soon)', 'azure-plugin'),
                'custom_attributes' => array('disabled' => 'disabled')
            ));
            ?>
        </div>
    </div>
    
    <style>
        .show_if_ticket { display: none; }
        .product-type-ticket .show_if_ticket { display: block; }
        .product-type-ticket .hide_if_ticket { display: none; }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        // Show/hide tabs based on product type
        function toggleTicketFields() {
            var productType = $('#product-type').val();
            if (productType === 'ticket') {
                $('.show_if_ticket').show();
                $('.hide_if_ticket').hide();
            } else {
                $('.show_if_ticket').hide();
            }
        }
        
        $('#product-type').on('change', toggleTicketFields);
        toggleTicketFields();
    });
    </script>
    <?php
});

/**
 * Save product meta
 */
add_action('woocommerce_process_product_meta', function($post_id) {
    // Event settings
    if (isset($_POST['_ticket_event_id'])) {
        update_post_meta($post_id, '_ticket_event_id', sanitize_text_field($_POST['_ticket_event_id']));
    }
    if (isset($_POST['_ticket_event_name'])) {
        update_post_meta($post_id, '_ticket_event_name', sanitize_text_field($_POST['_ticket_event_name']));
    }
    if (isset($_POST['_ticket_event_date'])) {
        update_post_meta($post_id, '_ticket_event_date', sanitize_text_field($_POST['_ticket_event_date']));
    }
    if (isset($_POST['_ticket_venue_id'])) {
        update_post_meta($post_id, '_ticket_venue_id', intval($_POST['_ticket_venue_id']));
    }
    
    // Ticket settings
    if (isset($_POST['_tickets_per_order_limit'])) {
        update_post_meta($post_id, '_tickets_per_order_limit', intval($_POST['_tickets_per_order_limit']));
    }
    
    $require_names = isset($_POST['_ticket_require_names']) ? 'yes' : 'no';
    update_post_meta($post_id, '_ticket_require_names', $require_names);
});

/**
 * Override single product template for ticket products
 */
add_action('woocommerce_single_product_summary', function() {
    global $product;
    
    if (!$product || $product->get_type() !== 'ticket') {
        return;
    }
    
    // Remove default add to cart button
    remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
}, 5);

/**
 * Add seat selection interface after summary
 */
add_action('woocommerce_single_product_summary', function() {
    global $product;
    
    if (!$product || $product->get_type() !== 'ticket') {
        return;
    }
    
    $venue_id = get_post_meta($product->get_id(), '_ticket_venue_id', true);
    $event_date = get_post_meta($product->get_id(), '_ticket_event_date', true);
    $max_tickets = get_post_meta($product->get_id(), '_tickets_per_order_limit', true) ?: 10;
    $require_names = get_post_meta($product->get_id(), '_ticket_require_names', true) === 'yes';
    
    ?>
    <div class="ticket-selection-wrapper">
        <?php if ($event_date): ?>
        <div class="event-date">
            <span class="dashicons dashicons-calendar-alt"></span>
            <?php echo date('l, F j, Y \a\t g:i A', strtotime($event_date)); ?>
        </div>
        <?php endif; ?>
        
        <?php if ($venue_id): ?>
        <div class="seat-selector-container" 
             data-product-id="<?php echo esc_attr($product->get_id()); ?>"
             data-venue-id="<?php echo esc_attr($venue_id); ?>"
             data-max-tickets="<?php echo esc_attr($max_tickets); ?>"
             data-require-names="<?php echo $require_names ? '1' : '0'; ?>">
            <div class="seat-map-wrapper">
                <div class="loading-seats">
                    <span class="spinner"></span>
                    <?php _e('Loading seating chart...', 'azure-plugin'); ?>
                </div>
                <div class="seat-map" style="display: none;"></div>
            </div>
            
            <div class="selection-summary">
                <h3><?php _e('Your Selection', 'azure-plugin'); ?></h3>
                <div class="selected-seats-list"></div>
                <div class="selection-total">
                    <span class="total-label"><?php _e('Total:', 'azure-plugin'); ?></span>
                    <span class="total-amount"><?php echo wc_price(0); ?></span>
                </div>
                
                <form class="ticket-add-to-cart-form" method="post">
                    <input type="hidden" name="product_id" value="<?php echo esc_attr($product->get_id()); ?>">
                    <input type="hidden" name="selected_seats" value="">
                    
                    <div class="attendee-names" style="display: none;">
                        <h4><?php _e('Attendee Names', 'azure-plugin'); ?></h4>
                        <div class="names-inputs"></div>
                    </div>
                    
                    <button type="submit" class="button alt" disabled>
                        <?php _e('Add to Cart', 'azure-plugin'); ?>
                    </button>
                </form>
            </div>
        </div>
        <?php else: ?>
        <p class="no-venue-warning">
            <?php _e('Seating not configured for this event.', 'azure-plugin'); ?>
        </p>
        <?php endif; ?>
    </div>
    <?php
}, 35);

/**
 * Handle adding ticket to cart
 */
add_action('wp_ajax_azure_tickets_add_to_cart', 'azure_tickets_add_to_cart');
add_action('wp_ajax_nopriv_azure_tickets_add_to_cart', 'azure_tickets_add_to_cart');

function azure_tickets_add_to_cart() {
    $product_id = intval($_POST['product_id'] ?? 0);
    $selected_seats = json_decode(wp_unslash($_POST['selected_seats'] ?? '[]'), true);
    $attendee_names = $_POST['attendee_names'] ?? array();
    
    if (!$product_id || empty($selected_seats)) {
        wp_send_json_error(array('message' => __('Please select at least one seat.', 'azure-plugin')));
    }
    
    $product = wc_get_product($product_id);
    if (!$product || $product->get_type() !== 'ticket') {
        wp_send_json_error(array('message' => __('Invalid product.', 'azure-plugin')));
    }
    
    // Get pricing from venue sections (TEC venue)
    $venue_id = get_post_meta($product_id, '_ticket_venue_id', true);
    $layout_json = get_post_meta($venue_id, '_azure_seating_layout', true);
    $layout = $layout_json ? json_decode($layout_json, true) : array();
    $section_prices = array();
    
    if (!empty($layout['blocks'])) {
        foreach ($layout['blocks'] as $block) {
            if (isset($block['id']) && isset($block['price'])) {
                $section_prices[$block['id']] = floatval($block['price']);
            }
        }
    }
    
    // Calculate total and validate seats
    $total = 0;
    $cart_items = array();
    
    foreach ($selected_seats as $index => $seat) {
        $section_id = $seat['section_id'] ?? '';
        $price = $section_prices[$section_id] ?? floatval($product->get_price());
        $total += $price;
        
        $cart_items[] = array(
            'section_id' => $section_id,
            'row' => $seat['row'] ?? '',
            'seat' => $seat['seat'] ?? '',
            'price' => $price,
            'attendee_name' => sanitize_text_field($attendee_names[$index] ?? '')
        );
    }
    
    // Add to cart with meta
    $cart_item_data = array(
        '_ticket_seats' => $cart_items,
        '_ticket_count' => count($cart_items)
    );
    
    $cart_item_key = WC()->cart->add_to_cart($product_id, count($cart_items), 0, array(), $cart_item_data);
    
    if ($cart_item_key) {
        wp_send_json_success(array(
            'message' => __('Tickets added to cart!', 'azure-plugin'),
            'cart_url' => wc_get_cart_url()
        ));
    } else {
        wp_send_json_error(array('message' => __('Could not add to cart.', 'azure-plugin')));
    }
}

/**
 * Display ticket info in cart and checkout
 */
add_filter('woocommerce_get_item_data', function($item_data, $cart_item) {
    if (isset($cart_item['_ticket_seats']) && is_array($cart_item['_ticket_seats'])) {
        foreach ($cart_item['_ticket_seats'] as $ticket) {
            $seat_label = '';
            if (!empty($ticket['row']) && !empty($ticket['seat'])) {
                $seat_label = sprintf(__('Row %s, Seat %d', 'azure-plugin'), $ticket['row'], $ticket['seat']);
            } else {
                $seat_label = __('General Admission', 'azure-plugin');
            }
            
            if (!empty($ticket['attendee_name'])) {
                $seat_label .= ' - ' . $ticket['attendee_name'];
            }
            
            $item_data[] = array(
                'name' => __('Ticket', 'azure-plugin'),
                'value' => $seat_label
            );
        }
    }
    return $item_data;
}, 10, 2);

/**
 * Save ticket meta to order item
 */
add_action('woocommerce_checkout_create_order_line_item', function($item, $cart_item_key, $values) {
    if (isset($values['_ticket_seats'])) {
        $item->add_meta_data('_ticket_seats', $values['_ticket_seats']);
    }
}, 10, 3);

