<?php
/**
 * Classes Module - Email Templates and Handlers
 * 
 * Handles sending custom emails for class commitments, payment requests,
 * and enrollment confirmations.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Classes_Emails {
    
    public function __construct() {
        // Hook into order status changes
        add_action('woocommerce_order_status_committed', array($this, 'send_commitment_email'), 10, 2);
        add_action('azure_classes_payment_complete', array($this, 'send_class_confirmed_email'), 10, 2);
        
        // Hook into chaperone invitation
        add_action('azure_classes_invite_chaperone', array($this, 'send_chaperone_invitation'), 10, 2);
    }
    
    /**
     * Send commitment confirmation email
     */
    public function send_commitment_email($order_id, $order = null) {
        if (!$order) {
            $order = wc_get_order($order_id);
        }
        
        if (!$order) {
            return;
        }
        
        $to = $order->get_billing_email();
        $customer_name = $order->get_billing_first_name();
        
        // Get class info
        $class_name = '';
        $class_schedule = '';
        $likely_price = 0;
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_type() === 'class') {
                $class_name = $product->get_name();
                $product_id = $product->get_id();
                
                // Get likely price
                if (class_exists('Azure_Classes_Pricing')) {
                    $pricing = new Azure_Classes_Pricing();
                    $likely_price = $pricing->calculate_likely_price($product_id);
                }
                
                // Get schedule preview
                if (class_exists('Azure_Classes_Event_Generator')) {
                    $events = Azure_Classes_Event_Generator::get_class_events($product_id);
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
        
        $subject = sprintf(__("You're committed to %s!", 'azure-plugin'), $class_name);
        
        // Build email content
        ob_start();
        ?>
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <h2 style="color: #333;"><?php _e("You're Committed!", 'azure-plugin'); ?></h2>
            
            <p><?php printf(__('Hi %s,', 'azure-plugin'), esc_html($customer_name)); ?></p>
            
            <p><?php printf(
                __('Thank you for committing to <strong>%s</strong>! Your spot has been reserved.', 'azure-plugin'),
                esc_html($class_name)
            ); ?></p>
            
            <div style="background: #e8f4fd; padding: 20px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #0073aa;">
                <h3 style="margin-top: 0; color: #0073aa;"><?php _e('What happens next?', 'azure-plugin'); ?></h3>
                <ol style="margin-bottom: 0;">
                    <li><?php _e('We\'re gathering commitments from other families.', 'azure-plugin'); ?></li>
                    <li><?php _e('Once enrollment closes, the final price will be calculated.', 'azure-plugin'); ?></li>
                    <li><?php _e('You\'ll receive an email with the final price and a payment link.', 'azure-plugin'); ?></li>
                    <li><?php _e('Complete payment to confirm your enrollment.', 'azure-plugin'); ?></li>
                </ol>
            </div>
            
            <div style="background: #f7f7f7; padding: 20px; border-radius: 5px; margin: 20px 0;">
                <p style="margin: 0 0 10px 0;"><strong><?php _e('Class:', 'azure-plugin'); ?></strong> <?php echo esc_html($class_name); ?></p>
                <?php if ($class_schedule) : ?>
                <p style="margin: 0 0 10px 0;"><strong><?php _e('Schedule:', 'azure-plugin'); ?></strong> <?php echo esc_html($class_schedule); ?></p>
                <?php endif; ?>
                <?php if ($likely_price > 0) : ?>
                <p style="margin: 0;"><strong><?php _e('Current Likely Price:', 'azure-plugin'); ?></strong> <?php echo wc_price($likely_price); ?>*</p>
                <?php endif; ?>
            </div>
            
            <p style="color: #666; font-size: 14px;">
                * <?php _e('The likely price may change as more families commit. More families = lower price!', 'azure-plugin'); ?>
            </p>
            
            <hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0;">
            
            <p style="color: #999; font-size: 12px;">
                <?php printf(__('Commitment #%s', 'azure-plugin'), $order->get_order_number()); ?>
            </p>
        </div>
        <?php
        $message = ob_get_clean();
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $result = wp_mail($to, $subject, $message, $headers);
        
        if ($result) {
            Azure_Logger::info('Classes: Commitment email sent', array(
                'order_id' => $order_id,
                'email' => $to
            ));
        } else {
            Azure_Logger::error('Classes: Failed to send commitment email', array(
                'order_id' => $order_id,
                'email' => $to
            ));
        }
    }
    
    /**
     * Send class confirmed email (after payment)
     */
    public function send_class_confirmed_email($order_id, $product_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        $to = $order->get_billing_email();
        $customer_name = $order->get_billing_first_name();
        
        $product = wc_get_product($product_id);
        $class_name = $product ? $product->get_name() : __('Class', 'azure-plugin');
        
        // Get full schedule
        $schedule_html = '';
        if (class_exists('Azure_Classes_Event_Generator')) {
            $events = Azure_Classes_Event_Generator::get_class_events($product_id);
            if (!empty($events)) {
                $schedule_html = '<table style="width: 100%; border-collapse: collapse; margin: 20px 0;">';
                $schedule_html .= '<tr style="background: #0073aa; color: #fff;">';
                $schedule_html .= '<th style="padding: 10px; text-align: left;">' . __('Session', 'azure-plugin') . '</th>';
                $schedule_html .= '<th style="padding: 10px; text-align: left;">' . __('Date', 'azure-plugin') . '</th>';
                $schedule_html .= '<th style="padding: 10px; text-align: left;">' . __('Time', 'azure-plugin') . '</th>';
                $schedule_html .= '</tr>';
                
                foreach ($events as $event) {
                    $start = strtotime($event['start_date']);
                    $end = strtotime($event['end_date']);
                    $is_cancelled = $event['status'] === 'trash' || $event['status'] === 'cancelled';
                    
                    $row_style = $is_cancelled ? 'text-decoration: line-through; color: #999;' : '';
                    
                    $schedule_html .= '<tr style="border-bottom: 1px solid #ddd; ' . $row_style . '">';
                    $schedule_html .= '<td style="padding: 10px;">' . $event['session_number'] . '</td>';
                    $schedule_html .= '<td style="padding: 10px;">' . date_i18n('l, M j, Y', $start) . '</td>';
                    $schedule_html .= '<td style="padding: 10px;">' . date_i18n('g:i A', $start) . ' - ' . date_i18n('g:i A', $end) . '</td>';
                    $schedule_html .= '</tr>';
                }
                
                $schedule_html .= '</table>';
            }
        }
        
        // Get venue info
        $venue_html = '';
        $venue_id = get_post_meta($product_id, '_class_venue', true);
        if ($venue_id) {
            $venue = get_post($venue_id);
            if ($venue) {
                $venue_name = $venue->post_title;
                $venue_address = function_exists('tribe_get_full_address') ? tribe_get_full_address($venue_id) : '';
                
                $venue_html = '<div style="background: #f7f7f7; padding: 15px; border-radius: 5px; margin: 20px 0;">';
                $venue_html .= '<strong>' . __('Location:', 'azure-plugin') . '</strong> ' . esc_html($venue_name);
                if ($venue_address) {
                    $venue_html .= '<br>' . esc_html($venue_address);
                }
                $venue_html .= '</div>';
            }
        }
        
        // Get provider info
        $provider_html = '';
        $provider_id = get_post_meta($product_id, '_class_provider', true);
        if ($provider_id) {
            $provider = get_term($provider_id, 'class_provider');
            if ($provider && !is_wp_error($provider)) {
                $company_name = get_term_meta($provider_id, 'provider_company_name', true);
                $contact_person = get_term_meta($provider_id, 'provider_contact_person', true);
                $emergency_contact = get_term_meta($provider_id, 'provider_emergency_contact', true);
                
                $provider_html = '<div style="background: #f7f7f7; padding: 15px; border-radius: 5px; margin: 20px 0;">';
                $provider_html .= '<strong>' . __('Provider:', 'azure-plugin') . '</strong> ' . esc_html($company_name ?: $provider->name);
                if ($contact_person) {
                    $provider_html .= '<br><strong>' . __('Contact:', 'azure-plugin') . '</strong> ' . esc_html($contact_person);
                }
                if ($emergency_contact) {
                    $provider_html .= '<br><strong>' . __('Emergency:', 'azure-plugin') . '</strong> ' . esc_html($emergency_contact);
                }
                $provider_html .= '</div>';
            }
        }
        
        $subject = sprintf(__("You're enrolled in %s!", 'azure-plugin'), $class_name);
        
        // Build email content
        ob_start();
        ?>
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <div style="background: #46b450; color: #fff; padding: 20px; text-align: center; border-radius: 5px 5px 0 0;">
                <h2 style="margin: 0;"><?php _e('Enrollment Confirmed!', 'azure-plugin'); ?></h2>
            </div>
            
            <div style="padding: 20px;">
                <p><?php printf(__('Hi %s,', 'azure-plugin'), esc_html($customer_name)); ?></p>
                
                <p><?php printf(
                    __('Your payment has been received and your enrollment in <strong>%s</strong> is now confirmed!', 'azure-plugin'),
                    esc_html($class_name)
                ); ?></p>
                
                <h3><?php _e('Class Schedule', 'azure-plugin'); ?></h3>
                <?php echo $schedule_html; ?>
                
                <?php echo $venue_html; ?>
                
                <?php echo $provider_html; ?>
                
                <div style="background: #e8f4fd; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #0073aa;">
                    <strong><?php _e('Important:', 'azure-plugin'); ?></strong>
                    <?php _e('Please add these dates to your calendar. If you need to miss a session, please contact us in advance.', 'azure-plugin'); ?>
                </div>
                
                <p><?php _e('We look forward to seeing you!', 'azure-plugin'); ?></p>
            </div>
            
            <hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0;">
            
            <p style="color: #999; font-size: 12px; text-align: center;">
                <?php printf(__('Order #%s', 'azure-plugin'), $order->get_order_number()); ?>
            </p>
        </div>
        <?php
        $message = ob_get_clean();
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $result = wp_mail($to, $subject, $message, $headers);
        
        if ($result) {
            Azure_Logger::info('Classes: Confirmed email sent', array(
                'order_id' => $order_id,
                'product_id' => $product_id,
                'email' => $to
            ));
        } else {
            Azure_Logger::error('Classes: Failed to send confirmed email', array(
                'order_id' => $order_id,
                'product_id' => $product_id,
                'email' => $to
            ));
        }
    }
    
    /**
     * Send chaperone invitation email
     */
    public function send_chaperone_invitation($email, $product_id) {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return;
        }
        
        $class_name = $product->get_name();
        $register_url = wp_registration_url();
        
        $subject = sprintf(__('Invitation: Chaperone for %s', 'azure-plugin'), $class_name);
        
        // Build email content
        ob_start();
        ?>
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <h2 style="color: #333;"><?php _e('Chaperone Invitation', 'azure-plugin'); ?></h2>
            
            <p><?php _e('Hello,', 'azure-plugin'); ?></p>
            
            <p><?php printf(
                __('You have been invited to be a chaperone for <strong>%s</strong>.', 'azure-plugin'),
                esc_html($class_name)
            ); ?></p>
            
            <p><?php _e('To accept this invitation and access class details, please create an account:', 'azure-plugin'); ?></p>
            
            <p style="text-align: center; margin: 30px 0;">
                <a href="<?php echo esc_url($register_url); ?>" 
                   style="background: #0073aa; color: #fff; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-size: 16px;">
                    <?php _e('Create Account', 'azure-plugin'); ?>
                </a>
            </p>
            
            <p style="color: #666; font-size: 14px;">
                <?php _e('Please use this email address when registering so we can link you to the class.', 'azure-plugin'); ?>
            </p>
        </div>
        <?php
        $message = ob_get_clean();
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $result = wp_mail($email, $subject, $message, $headers);
        
        if ($result) {
            Azure_Logger::info('Classes: Chaperone invitation sent', array(
                'product_id' => $product_id,
                'email' => $email
            ));
        } else {
            Azure_Logger::error('Classes: Failed to send chaperone invitation', array(
                'product_id' => $product_id,
                'email' => $email
            ));
        }
    }
}

