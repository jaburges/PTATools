<?php
/**
 * Classes Module - Commitment Flow Handler
 * 
 * Manages the commit-to-buy flow for variable pricing classes:
 * - $0 checkout for commitments
 * - Payment request generation
 * - Order status management
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Classes_Commitment {
    
    public function __construct() {
        // Modify checkout for variable pricing classes
        add_action('woocommerce_before_calculate_totals', array($this, 'set_commitment_price'), 20, 1);
        
        // Set order status after checkout
        add_action('woocommerce_checkout_order_processed', array($this, 'set_commitment_order_status'), 10, 3);
        
        // Prevent payment for $0 orders (commitment orders)
        add_filter('woocommerce_cart_needs_payment', array($this, 'cart_needs_payment'), 10, 2);
        
        // Handle payment completion for awaiting-payment orders
        add_action('woocommerce_payment_complete', array($this, 'handle_payment_complete'));
        
        // Add custom order actions
        add_filter('woocommerce_order_actions', array($this, 'add_order_actions'));
        add_action('woocommerce_order_action_send_payment_request', array($this, 'action_send_payment_request'));
    }
    
    /**
     * Set price to $0 for variable pricing class commitments
     */
    public function set_commitment_price($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        
        if (did_action('woocommerce_before_calculate_totals') >= 2) {
            return;
        }
        
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            
            if ($product->get_type() !== 'class') {
                continue;
            }
            
            $product_id = $product->get_id();
            $variable_pricing = get_post_meta($product_id, '_class_variable_pricing', true);
            $finalized = get_post_meta($product_id, '_class_finalized', true);
            
            // Only set to $0 for variable pricing that's not finalized
            if ($variable_pricing === 'yes' && $finalized !== 'yes') {
                $product->set_price(0);
            } elseif ($variable_pricing === 'yes' && $finalized === 'yes') {
                // Use final price
                $final_price = get_post_meta($product_id, '_class_final_price', true);
                $product->set_price($final_price);
            }
        }
    }
    
    /**
     * Set order status to 'committed' for variable pricing class orders
     */
    public function set_commitment_order_status($order_id, $posted_data, $order) {
        $is_commitment_order = false;
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            
            if (!$product || $product->get_type() !== 'class') {
                continue;
            }
            
            $product_id = $product->get_id();
            $variable_pricing = get_post_meta($product_id, '_class_variable_pricing', true);
            $finalized = get_post_meta($product_id, '_class_finalized', true);
            
            if ($variable_pricing === 'yes' && $finalized !== 'yes') {
                $is_commitment_order = true;
                break;
            }
        }
        
        if ($is_commitment_order && $order->get_total() == 0) {
            $order->set_status('wc-committed');
            $order->add_order_note(__('Order placed as commitment for variable-price class. Payment will be requested when final price is set.', 'azure-plugin'));
            $order->save();
            
            Azure_Logger::info('Classes: Commitment order created', array(
                'order_id' => $order_id
            ));
        }
    }
    
    /**
     * Check if cart needs payment
     */
    public function cart_needs_payment($needs_payment, $cart) {
        if ($cart->get_total() == 0) {
            // Check if this is a class commitment
            foreach ($cart->get_cart() as $cart_item) {
                $product = $cart_item['data'];
                
                if ($product->get_type() === 'class') {
                    $product_id = $product->get_id();
                    $variable_pricing = get_post_meta($product_id, '_class_variable_pricing', true);
                    $finalized = get_post_meta($product_id, '_class_finalized', true);
                    
                    if ($variable_pricing === 'yes' && $finalized !== 'yes') {
                        return false; // No payment needed for commitment
                    }
                }
            }
        }
        
        return $needs_payment;
    }
    
    /**
     * Handle payment completion for awaiting-payment orders
     */
    public function handle_payment_complete($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        // Check if this was an awaiting-payment order for a class
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            
            if ($product && $product->get_type() === 'class') {
                // Trigger class confirmed email
                do_action('azure_classes_payment_complete', $order_id, $product->get_id());
                
                Azure_Logger::info('Classes: Payment completed for class order', array(
                    'order_id' => $order_id,
                    'product_id' => $product->get_id()
                ));
            }
        }
    }
    
    /**
     * Add custom order actions
     */
    public function add_order_actions($actions) {
        global $theorder;
        
        if ($theorder && $theorder->get_status() === 'committed') {
            $actions['send_payment_request'] = __('Send Payment Request', 'azure-plugin');
        }
        
        return $actions;
    }
    
    /**
     * Action: Send payment request for a single order
     */
    public function action_send_payment_request($order) {
        $product_id = 0;
        $final_price = 0;
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            
            if ($product && $product->get_type() === 'class') {
                $product_id = $product->get_id();
                $final_price = get_post_meta($product_id, '_class_final_price', true);
                break;
            }
        }
        
        if (!$product_id || !$final_price) {
            $order->add_order_note(__('Cannot send payment request: Final price not set.', 'azure-plugin'));
            return;
        }
        
        $result = $this->send_payment_request_for_order($order, $final_price);
        
        if ($result) {
            $order->add_order_note(__('Payment request sent to customer.', 'azure-plugin'));
        } else {
            $order->add_order_note(__('Failed to send payment request.', 'azure-plugin'));
        }
    }
    
    /**
     * Get commitment count for a product
     */
    public function get_commitment_count($product_id) {
        $orders = wc_get_orders(array(
            'status' => array('wc-committed', 'wc-awaiting-payment', 'wc-processing', 'wc-completed'),
            'limit'  => -1,
            'return' => 'ids'
        ));
        
        $count = 0;
        
        foreach ($orders as $order_id) {
            $order = wc_get_order($order_id);
            
            foreach ($order->get_items() as $item) {
                if ($item->get_product_id() == $product_id || $item->get_variation_id() == $product_id) {
                    $count += $item->get_quantity();
                }
            }
        }
        
        return $count;
    }
    
    /**
     * Get all committed orders for a product
     */
    public function get_committed_orders($product_id) {
        $orders = wc_get_orders(array(
            'status' => array('wc-committed'),
            'limit'  => -1,
            'return' => 'ids'
        ));
        
        $committed_orders = array();
        
        foreach ($orders as $order_id) {
            $order = wc_get_order($order_id);
            
            foreach ($order->get_items() as $item) {
                if ($item->get_product_id() == $product_id || $item->get_variation_id() == $product_id) {
                    $committed_orders[] = array(
                        'order_id'    => $order_id,
                        'customer'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                        'email'       => $order->get_billing_email(),
                        'quantity'    => $item->get_quantity(),
                        'date'        => $order->get_date_created()->format('Y-m-d H:i:s'),
                        'status'      => $order->get_status()
                    );
                }
            }
        }
        
        return $committed_orders;
    }
    
    /**
     * Send payment requests to all committed customers
     */
    public function send_payment_requests($product_id, $final_price) {
        $orders = wc_get_orders(array(
            'status' => array('wc-committed'),
            'limit'  => -1
        ));
        
        $sent_count = 0;
        $error_count = 0;
        $product_orders = array();
        
        // Find orders for this product
        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                if ($item->get_product_id() == $product_id || $item->get_variation_id() == $product_id) {
                    $product_orders[] = $order;
                    break;
                }
            }
        }
        
        if (empty($product_orders)) {
            return array(
                'success' => false,
                'message' => __('No committed orders found for this product.', 'azure-plugin')
            );
        }
        
        // Send payment request to each order
        foreach ($product_orders as $order) {
            $result = $this->send_payment_request_for_order($order, $final_price);
            
            if ($result) {
                $sent_count++;
            } else {
                $error_count++;
            }
        }
        
        Azure_Logger::info('Classes: Payment requests sent', array(
            'product_id' => $product_id,
            'final_price' => $final_price,
            'sent_count' => $sent_count,
            'error_count' => $error_count
        ));
        
        return array(
            'success' => true,
            'message' => sprintf(
                __('Payment requests sent to %d customers. Errors: %d', 'azure-plugin'),
                $sent_count,
                $error_count
            ),
            'sent_count' => $sent_count,
            'error_count' => $error_count
        );
    }
    
    /**
     * Send payment request for a single order
     */
    private function send_payment_request_for_order($order, $final_price) {
        $order_id = $order->get_id();
        
        // Update order total with final price
        $this->update_order_total($order, $final_price);
        
        // Update order status
        $order->set_status('wc-awaiting-payment');
        $order->add_order_note(sprintf(
            __('Final price set to %s. Payment request sent to customer.', 'azure-plugin'),
            wc_price($final_price)
        ));
        $order->save();
        
        // Generate payment link
        $payment_url = $order->get_checkout_payment_url();
        
        // Send email
        $result = $this->send_payment_request_email($order, $final_price, $payment_url);
        
        return $result;
    }
    
    /**
     * Update order total with final price
     */
    private function update_order_total($order, $final_price) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            
            if ($product && $product->get_type() === 'class') {
                $quantity = $item->get_quantity();
                $item->set_subtotal($final_price * $quantity);
                $item->set_total($final_price * $quantity);
                $item->save();
            }
        }
        
        $order->calculate_totals();
        $order->save();
    }
    
    /**
     * Send payment request email
     */
    private function send_payment_request_email($order, $final_price, $payment_url) {
        $to = $order->get_billing_email();
        $customer_name = $order->get_billing_first_name();
        
        // Get class info
        $class_name = '';
        $class_schedule = '';
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_type() === 'class') {
                $class_name = $product->get_name();
                
                // Get schedule info
                if (class_exists('Azure_Classes_Event_Generator')) {
                    $events = Azure_Classes_Event_Generator::get_class_events($product->get_id());
                    if (!empty($events)) {
                        $first_event = reset($events);
                        $last_event = end($events);
                        $class_schedule = sprintf(
                            __('%s to %s (%d sessions)', 'azure-plugin'),
                            date('M j, Y', strtotime($first_event['start_date'])),
                            date('M j, Y', strtotime($last_event['start_date'])),
                            count($events)
                        );
                    }
                }
                break;
            }
        }
        
        $subject = sprintf(__('%s - Final Price Set - Payment Required', 'azure-plugin'), $class_name);
        
        // Build email content
        ob_start();
        ?>
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <h2 style="color: #333;"><?php _e('Payment Required for Your Class Enrollment', 'azure-plugin'); ?></h2>
            
            <p><?php printf(__('Hi %s,', 'azure-plugin'), esc_html($customer_name)); ?></p>
            
            <p><?php printf(
                __('Great news! The final price for <strong>%s</strong> has been set.', 'azure-plugin'),
                esc_html($class_name)
            ); ?></p>
            
            <div style="background: #f7f7f7; padding: 20px; border-radius: 5px; margin: 20px 0;">
                <p style="margin: 0 0 10px 0;"><strong><?php _e('Class:', 'azure-plugin'); ?></strong> <?php echo esc_html($class_name); ?></p>
                <?php if ($class_schedule) : ?>
                <p style="margin: 0 0 10px 0;"><strong><?php _e('Schedule:', 'azure-plugin'); ?></strong> <?php echo esc_html($class_schedule); ?></p>
                <?php endif; ?>
                <p style="margin: 0; font-size: 24px; color: #0073aa;"><strong><?php _e('Final Price:', 'azure-plugin'); ?></strong> <?php echo wc_price($final_price); ?></p>
            </div>
            
            <p><?php _e('Please complete your payment to confirm your enrollment.', 'azure-plugin'); ?></p>
            
            <p style="text-align: center; margin: 30px 0;">
                <a href="<?php echo esc_url($payment_url); ?>" 
                   style="background: #0073aa; color: #fff; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-size: 16px;">
                    <?php _e('Complete Payment', 'azure-plugin'); ?>
                </a>
            </p>
            
            <p style="color: #666; font-size: 14px;">
                <?php _e('If the button above doesn\'t work, copy and paste this link into your browser:', 'azure-plugin'); ?><br>
                <a href="<?php echo esc_url($payment_url); ?>"><?php echo esc_url($payment_url); ?></a>
            </p>
            
            <hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0;">
            
            <p style="color: #999; font-size: 12px;">
                <?php printf(__('Order #%s', 'azure-plugin'), $order->get_order_number()); ?>
            </p>
        </div>
        <?php
        $message = ob_get_clean();
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $result = wp_mail($to, $subject, $message, $headers);
        
        if ($result) {
            Azure_Logger::info('Classes: Payment request email sent', array(
                'order_id' => $order->get_id(),
                'email' => $to
            ));
        } else {
            Azure_Logger::error('Classes: Failed to send payment request email', array(
                'order_id' => $order->get_id(),
                'email' => $to
            ));
        }
        
        return $result;
    }
}

