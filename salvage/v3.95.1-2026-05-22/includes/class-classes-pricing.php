<?php
/**
 * Classes Module - Pricing Calculator and Display
 * 
 * Handles likely price calculation for variable pricing classes
 * and modifies WooCommerce price display on frontend.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Classes_Pricing {
    
    public function __construct() {
        // Modify price display on frontend
        add_filter('woocommerce_get_price_html', array($this, 'modify_price_html'), 10, 2);
        
        // Modify cart item price for variable pricing
        add_filter('woocommerce_cart_item_price', array($this, 'modify_cart_price'), 10, 3);
        
        // Modify add to cart button text
        add_filter('woocommerce_product_single_add_to_cart_text', array($this, 'modify_add_to_cart_text'), 10, 2);
        add_filter('woocommerce_product_add_to_cart_text', array($this, 'modify_add_to_cart_text'), 10, 2);
        
        // Add pricing info below add to cart
        add_action('woocommerce_single_product_summary', array($this, 'display_pricing_info'), 25);
        
        // Enqueue frontend styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_styles'));
    }
    
    /**
     * Calculate likely price based on current commitments
     */
    public function calculate_likely_price($product_id) {
        $min_attendees = intval(get_post_meta($product_id, '_class_min_attendees', true));
        $min_price = floatval(get_post_meta($product_id, '_class_price_at_min', true));
        $max_attendees = intval(get_post_meta($product_id, '_class_max_attendees', true));
        $max_price = floatval(get_post_meta($product_id, '_class_price_at_max', true));
        
        // Get current commitment count
        $current_commitments = $this->get_commitment_count($product_id);
        
        // If no commitments yet, return the max price (worst case)
        if ($current_commitments <= 0) {
            return $min_price; // Price at minimum attendees
        }
        
        // If at or below minimum, return min price
        if ($current_commitments <= $min_attendees) {
            return $min_price;
        }
        
        // If at or above maximum, return max price (best case)
        if ($current_commitments >= $max_attendees) {
            return $max_price;
        }
        
        // Calculate proportional price
        // As attendees increase, price decreases
        $ratio = ($current_commitments - $min_attendees) / ($max_attendees - $min_attendees);
        $likely_price = $min_price - (($min_price - $max_price) * $ratio);
        
        return round($likely_price, 2);
    }
    
    /**
     * Get commitment count for a product
     */
    public function get_commitment_count($product_id) {
        if (!class_exists('Azure_Classes_Commitment')) {
            return 0;
        }
        
        $commitment = new Azure_Classes_Commitment();
        return $commitment->get_commitment_count($product_id);
    }
    
    /**
     * Modify price HTML on product pages
     */
    public function modify_price_html($price_html, $product) {
        if (!$product || $product->get_type() !== 'class') {
            return $price_html;
        }
        
        $product_id = $product->get_id();
        $variable_pricing = get_post_meta($product_id, '_class_variable_pricing', true);
        
        // Fixed pricing - use standard display
        if ($variable_pricing !== 'yes') {
            return $price_html;
        }
        
        // Check if finalized
        $finalized = get_post_meta($product_id, '_class_finalized', true);
        if ($finalized === 'yes') {
            $final_price = get_post_meta($product_id, '_class_final_price', true);
            return '<span class="class-final-price">' . wc_price($final_price) . '</span>';
        }
        
        // Variable pricing - show likely price
        $likely_price = $this->calculate_likely_price($product_id);
        $min_price = floatval(get_post_meta($product_id, '_class_price_at_min', true));
        $max_price = floatval(get_post_meta($product_id, '_class_price_at_max', true));
        
        $html = '<span class="class-likely-price">';
        $html .= '<span class="price-label">' . __('Likely Price:', 'azure-plugin') . '</span> ';
        $html .= '<span class="amount">' . wc_price($likely_price) . '</span>';
        $html .= '</span>';
        $html .= '<span class="class-price-range">';
        $html .= sprintf(__('Range: %s - %s', 'azure-plugin'), wc_price($max_price), wc_price($min_price));
        $html .= '</span>';
        
        return $html;
    }
    
    /**
     * Modify cart item price for variable pricing
     */
    public function modify_cart_price($price_html, $cart_item, $cart_item_key) {
        $product = $cart_item['data'];
        
        if (!$product || $product->get_type() !== 'class') {
            return $price_html;
        }
        
        $product_id = $product->get_id();
        $variable_pricing = get_post_meta($product_id, '_class_variable_pricing', true);
        
        if ($variable_pricing !== 'yes') {
            return $price_html;
        }
        
        // Check if finalized
        $finalized = get_post_meta($product_id, '_class_finalized', true);
        if ($finalized === 'yes') {
            $final_price = get_post_meta($product_id, '_class_final_price', true);
            return wc_price($final_price);
        }
        
        // Show "Price TBD" for uncommitted variable pricing
        return '<span class="class-price-tbd">' . __('Price TBD', 'azure-plugin') . '</span>';
    }
    
    /**
     * Modify add to cart button text
     */
    public function modify_add_to_cart_text($text, $product) {
        if (!$product || $product->get_type() !== 'class') {
            return $text;
        }
        
        $product_id = $product->get_id();
        $variable_pricing = get_post_meta($product_id, '_class_variable_pricing', true);
        
        if ($variable_pricing !== 'yes') {
            return __('Enroll Now', 'azure-plugin');
        }
        
        // Check if finalized
        $finalized = get_post_meta($product_id, '_class_finalized', true);
        if ($finalized === 'yes') {
            return __('Enroll Now', 'azure-plugin');
        }
        
        // Variable pricing - show commitment text
        return __('Commit to Enroll', 'azure-plugin');
    }
    
    /**
     * Display additional pricing info on single product page
     */
    public function display_pricing_info() {
        global $product;
        
        if (!$product || $product->get_type() !== 'class') {
            return;
        }
        
        $product_id = $product->get_id();
        $variable_pricing = get_post_meta($product_id, '_class_variable_pricing', true);
        
        if ($variable_pricing !== 'yes') {
            return;
        }
        
        // Check if finalized
        $finalized = get_post_meta($product_id, '_class_finalized', true);
        if ($finalized === 'yes') {
            return;
        }
        
        $current_commitments = $this->get_commitment_count($product_id);
        $min_attendees = intval(get_post_meta($product_id, '_class_min_attendees', true));
        $max_attendees = intval(get_post_meta($product_id, '_class_max_attendees', true));
        $spots_remaining = max(0, $max_attendees - $current_commitments);
        
        ?>
        <div class="class-pricing-info">
            <div class="commitment-status">
                <span class="dashicons dashicons-groups"></span>
                <strong><?php echo esc_html($current_commitments); ?></strong> 
                <?php echo esc_html(_n('family committed', 'families committed', $current_commitments, 'azure-plugin')); ?>
                <span class="separator">|</span>
                <strong><?php echo esc_html($spots_remaining); ?></strong> 
                <?php echo esc_html(_n('spot remaining', 'spots remaining', $spots_remaining, 'azure-plugin')); ?>
            </div>
            
            <?php if ($current_commitments < $min_attendees) : ?>
            <div class="minimum-notice">
                <span class="dashicons dashicons-info"></span>
                <?php printf(
                    __('Minimum %d families needed for class to run. Currently %d more needed.', 'azure-plugin'),
                    $min_attendees,
                    $min_attendees - $current_commitments
                ); ?>
            </div>
            <?php endif; ?>
            
            <div class="pricing-explanation">
                <span class="dashicons dashicons-editor-help"></span>
                <?php _e('Final price determined when enrollment closes. More families = lower price per family.', 'azure-plugin'); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Enqueue frontend styles
     */
    public function enqueue_frontend_styles() {
        if (!is_product()) {
            return;
        }
        
        global $post;
        $product = wc_get_product($post);
        
        if (!$product || $product->get_type() !== 'class') {
            return;
        }
        
        wp_enqueue_style('azure-classes-frontend', AZURE_PLUGIN_URL . 'css/classes-frontend.css', array(), AZURE_PLUGIN_VERSION);
    }
    
    /**
     * Get pricing summary for a product
     */
    public static function get_pricing_summary($product_id) {
        $variable_pricing = get_post_meta($product_id, '_class_variable_pricing', true);
        $finalized = get_post_meta($product_id, '_class_finalized', true);
        
        if ($variable_pricing !== 'yes') {
            $product = wc_get_product($product_id);
            return array(
                'type' => 'fixed',
                'price' => $product ? $product->get_price() : 0,
                'finalized' => true
            );
        }
        
        if ($finalized === 'yes') {
            return array(
                'type' => 'variable',
                'price' => floatval(get_post_meta($product_id, '_class_final_price', true)),
                'finalized' => true
            );
        }
        
        $pricing = new self();
        
        return array(
            'type' => 'variable',
            'likely_price' => $pricing->calculate_likely_price($product_id),
            'min_price' => floatval(get_post_meta($product_id, '_class_price_at_min', true)),
            'max_price' => floatval(get_post_meta($product_id, '_class_price_at_max', true)),
            'min_attendees' => intval(get_post_meta($product_id, '_class_min_attendees', true)),
            'max_attendees' => intval(get_post_meta($product_id, '_class_max_attendees', true)),
            'current_commitments' => $pricing->get_commitment_count($product_id),
            'finalized' => false
        );
    }
}

