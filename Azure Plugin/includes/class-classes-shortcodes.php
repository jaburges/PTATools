<?php
/**
 * Classes Module - Shortcodes
 * 
 * Provides shortcodes for displaying class schedule and pricing information.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Classes_Shortcodes {
    
    public function __construct() {
        // Register shortcodes
        add_shortcode('class_schedule', array($this, 'shortcode_class_schedule'));
        add_shortcode('class_pricing', array($this, 'shortcode_class_pricing'));
        
        // Auto-inject on product pages
        add_action('woocommerce_after_single_product_summary', array($this, 'auto_inject_schedule'), 5);
        
        // Hide "Class" product type label on frontend
        add_filter('woocommerce_product_type_query', array($this, 'maybe_hide_product_type_label'), 10, 2);
        
        // Remove product type from single product page for class products
        add_action('woocommerce_single_product_summary', array($this, 'remove_class_type_label'), 1);
        
        // Hide Description tab for Class products (we show it in our schedule section)
        add_filter('woocommerce_product_tabs', array($this, 'hide_description_tab_for_classes'), 98);
    }
    
    /**
     * Hide the Description tab for Class products since we show it in the schedule section
     */
    public function hide_description_tab_for_classes($tabs) {
        global $product;
        if ($product && $product->get_type() === 'class') {
            unset($tabs['description']);
        }
        return $tabs;
    }
    
    /**
     * Remove the "Class" product type label from single product pages
     */
    public function remove_class_type_label() {
        global $product;
        if ($product && $product->get_type() === 'class') {
            // Remove the product type display if it exists
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40);
            add_action('woocommerce_single_product_summary', array($this, 'custom_product_meta'), 40);
        }
    }
    
    /**
     * Custom product meta that excludes product type for classes
     */
    public function custom_product_meta() {
        global $product;
        
        // Only show SKU and categories, not product type
        echo '<div class="product_meta">';
        
        $sku = $product->get_sku();
        if ($sku) {
            echo '<span class="sku_wrapper">' . esc_html__('SKU:', 'woocommerce') . ' <span class="sku">' . esc_html($sku) . '</span></span>';
        }
        
        echo wc_get_product_category_list($product->get_id(), ', ', '<span class="posted_in">' . _n('Category:', 'Categories:', count($product->get_category_ids()), 'woocommerce') . ' ', '</span>');
        
        echo wc_get_product_tag_list($product->get_id(), ', ', '<span class="tagged_as">' . _n('Tag:', 'Tags:', count($product->get_tag_ids()), 'woocommerce') . ' ', '</span>');
        
        echo '</div>';
    }
    
    /**
     * Filter to potentially hide product type
     */
    public function maybe_hide_product_type_label($product_type, $product_id) {
        return $product_type;
    }
    
    /**
     * Shortcode: [class_schedule]
     * 
     * Attributes:
     * - product_id: Product ID (optional, uses current product if on product page)
     * - format: list|table|calendar (default: list)
     */
    public function shortcode_class_schedule($atts) {
        $atts = shortcode_atts(array(
            'product_id' => 0,
            'format'     => 'list'
        ), $atts, 'class_schedule');
        
        $product_id = intval($atts['product_id']);
        
        // If no product ID, try to get from current product
        if (!$product_id) {
            global $product;
            if ($product && $product->get_type() === 'class') {
                $product_id = $product->get_id();
            }
        }
        
        if (!$product_id) {
            return '<p class="class-schedule-error">' . __('No class product specified.', 'azure-plugin') . '</p>';
        }
        
        // Get events
        if (!class_exists('Azure_Classes_Event_Generator')) {
            return '<p class="class-schedule-error">' . __('Event generator not available.', 'azure-plugin') . '</p>';
        }
        
        $events = Azure_Classes_Event_Generator::get_class_events($product_id);
        
        if (empty($events)) {
            return '<p class="class-schedule-notice">' . __('No schedule available yet.', 'azure-plugin') . '</p>';
        }
        
        // Get venue info
        $venue_id = get_post_meta($product_id, '_class_venue', true);
        $venue_name = '';
        $venue_address = '';
        
        if ($venue_id) {
            $venue = get_post($venue_id);
            if ($venue) {
                $venue_name = $venue->post_title;
                $venue_address = tribe_get_full_address($venue_id);
            }
        }
        
        // Render based on format
        switch ($atts['format']) {
            case 'table':
                return $this->render_schedule_table($events, $venue_name, $venue_address);
            case 'calendar':
                return $this->render_schedule_calendar($events, $venue_name);
            case 'list':
            default:
                return $this->render_schedule_list($events, $venue_name, $venue_address);
        }
    }
    
    /**
     * Render schedule as list
     */
    private function render_schedule_list($events, $venue_name, $venue_address) {
        global $product;
        $product_id = $product ? $product->get_id() : 0;
        
        // Get descriptions for right column
        $short_description = $product ? $product->get_short_description() : '';
        $full_description = $product ? $product->get_description() : '';
        
        // Get TEC category for calendar subscription
        $category_id = $product_id ? get_post_meta($product_id, '_class_category_id', true) : 0;
        $calendar_url = '';
        if ($category_id) {
            // Build iCal subscription URL for this class's TEC category
            $category = get_term($category_id, 'tribe_events_cat');
            if ($category && !is_wp_error($category)) {
                $calendar_url = add_query_arg(array(
                    'ical' => 1,
                    'tribe_events_cat' => $category->slug
                ), home_url('/events/'));
            }
        }
        
        // Parse venue address - extract clean address from TEC HTML
        $clean_address = $this->parse_venue_address($venue_address);
        
        ob_start();
        ?>
        <div class="class-schedule class-schedule-list">
            <h3><?php _e('Schedule', 'azure-plugin'); ?></h3>
            
            <!-- Venue + Calendar Subscribe Row -->
            <div class="class-venue-calendar-row">
                <?php if ($venue_name) : ?>
                <div class="class-venue">
                    <span class="dashicons dashicons-location"></span>
                    <div class="venue-details">
                        <strong><?php echo esc_html($venue_name); ?></strong>
                        <?php if ($clean_address) : ?>
                        <div class="venue-address"><?php echo wp_kses_post($clean_address); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="class-calendar-subscribe">
                    <h4><?php _e('Add to Calendar', 'azure-plugin'); ?></h4>
                    <p class="calendar-subscribe-note"><?php _e('Subscribe to receive automatic updates when class dates change.', 'azure-plugin'); ?></p>
                    <?php if ($calendar_url) : ?>
                    <a href="<?php echo esc_url($calendar_url); ?>" class="button calendar-subscribe-button" target="_blank">
                        <span class="dashicons dashicons-calendar-alt"></span>
                        <?php _e('Subscribe to Calendar', 'azure-plugin'); ?>
                    </a>
                    <div class="calendar-subscribe-links">
                        <a href="https://calendar.google.com/calendar/r?cid=<?php echo urlencode($calendar_url); ?>" target="_blank" title="Add to Google Calendar">
                            <span class="dashicons dashicons-google"></span> Google
                        </a>
                        <a href="<?php echo esc_url($calendar_url); ?>" target="_blank" title="Add to Apple Calendar">
                            <span class="dashicons dashicons-smartphone"></span> Apple
                        </a>
                        <a href="https://outlook.live.com/calendar/0/addfromweb?url=<?php echo urlencode($calendar_url); ?>" target="_blank" title="Add to Outlook">
                            <span class="dashicons dashicons-email-alt"></span> Outlook
                        </a>
                    </div>
                    <?php else : ?>
                    <p class="calendar-not-available"><?php _e('Calendar subscription will be available once the class schedule is finalized.', 'azure-plugin'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Session List - Full Width -->
            <ul class="schedule-list">
                <?php foreach ($events as $event) : 
                    $start = strtotime($event['start_date']);
                    $end = strtotime($event['end_date']);
                    $is_cancelled = $event['status'] === 'trash' || $event['status'] === 'cancelled';
                    $is_modified = $event['modified'];
                    $event_url = !empty($event['url']) ? $event['url'] : '';
                ?>
                <li class="schedule-item <?php echo $is_cancelled ? 'cancelled' : ''; ?> <?php echo $is_modified ? 'modified' : ''; ?>">
                    <?php if ($event_url && !$is_cancelled) : ?>
                    <a href="<?php echo esc_url($event_url); ?>" class="session-number-link" title="<?php esc_attr_e('View event details', 'azure-plugin'); ?>">
                        <?php printf(__('Session %d', 'azure-plugin'), $event['session_number']); ?>
                    </a>
                    <?php else : ?>
                    <span class="session-number"><?php printf(__('Session %d', 'azure-plugin'), $event['session_number']); ?></span>
                    <?php endif; ?>
                    <span class="session-date"><?php echo date_i18n('l, F j, Y', $start); ?></span>
                    <span class="session-time">
                        <?php echo date_i18n('g:i A', $start); ?> - <?php echo date_i18n('g:i A', $end); ?>
                    </span>
                    <?php if ($is_cancelled) : ?>
                    <span class="session-status cancelled"><?php _e('Cancelled', 'azure-plugin'); ?></span>
                    <?php elseif ($is_modified) : ?>
                    <span class="session-status modified"><?php _e('Modified', 'azure-plugin'); ?></span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            
            <?php if ($full_description) : ?>
            <div class="class-description-full">
                <h4><?php _e('Description', 'azure-plugin'); ?></h4>
                <?php echo wp_kses_post($full_description); ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Parse venue address from TEC HTML to clean format
     * Returns HTML with street on one line and city/state/zip on another
     */
    private function parse_venue_address($venue_html) {
        if (empty($venue_html)) {
            return '';
        }
        
        // If it's already plain text, return it
        if (strip_tags($venue_html) === $venue_html) {
            return $venue_html;
        }
        
        // Extract address components from TEC HTML structure
        $street = '';
        $city_line = '';
        
        // Try to extract street address
        if (preg_match('/<span class="tribe-street-address">([^<]+)<\/span>/i', $venue_html, $matches)) {
            $street = trim($matches[1]);
        }
        
        // Try to extract city, state, zip
        $city = '';
        $state = '';
        $zip = '';
        
        if (preg_match('/<span class="tribe-locality">([^<]+)<\/span>/i', $venue_html, $matches)) {
            $city = trim($matches[1]);
        }
        
        if (preg_match('/<abbr[^>]*class="[^"]*tribe-region[^"]*"[^>]*>([^<]+)<\/abbr>/i', $venue_html, $state_matches)) {
            $state = trim($state_matches[1]);
        }
        
        if (preg_match('/<span class="tribe-postal-code">([^<]+)<\/span>/i', $venue_html, $zip_matches)) {
            $zip = trim($zip_matches[1]);
        }
        
        // Build city line
        if ($city) {
            $city_line = $city;
            if ($state) {
                $city_line .= ', ' . $state;
            }
            if ($zip) {
                $city_line .= ' ' . $zip;
            }
        }
        
        // Build output with proper line breaks
        $output_parts = array();
        if ($street) {
            $output_parts[] = '<span class="venue-street">' . esc_html($street) . '</span>';
        }
        if ($city_line) {
            $output_parts[] = '<span class="venue-city-state">' . esc_html($city_line) . '</span>';
        }
        
        if (!empty($output_parts)) {
            return implode('<br>', $output_parts);
        }
        
        // If we couldn't parse, just strip tags and return
        if (empty($output_parts)) {
            // Clean up the HTML by removing span tags but keeping content
            $clean = preg_replace('/<span[^>]*>/', '', $venue_html);
            $clean = preg_replace('/<\/span>/', '', $clean);
            $clean = preg_replace('/<abbr[^>]*>/', '', $clean);
            $clean = preg_replace('/<\/abbr>/', '', $clean);
            $clean = preg_replace('/<br\s*\/?>/i', ', ', $clean);
            $clean = strip_tags($clean);
            $clean = preg_replace('/\s+/', ' ', $clean);
            $clean = preg_replace('/,\s*,/', ',', $clean);
            return trim($clean);
        }
        
        return implode('<br>', $address_parts);
    }
    
    /**
     * Render schedule as table
     */
    private function render_schedule_table($events, $venue_name, $venue_address) {
        ob_start();
        ?>
        <div class="class-schedule class-schedule-table">
            <h3><?php _e('Class Schedule', 'azure-plugin'); ?></h3>
            
            <?php if ($venue_name) : ?>
            <p class="class-venue">
                <span class="dashicons dashicons-location"></span>
                <strong><?php echo esc_html($venue_name); ?></strong>
                <?php if ($venue_address) : ?>
                - <?php echo esc_html($venue_address); ?>
                <?php endif; ?>
            </p>
            <?php endif; ?>
            
            <table class="schedule-table">
                <thead>
                    <tr>
                        <th><?php _e('Session', 'azure-plugin'); ?></th>
                        <th><?php _e('Date', 'azure-plugin'); ?></th>
                        <th><?php _e('Time', 'azure-plugin'); ?></th>
                        <th><?php _e('Status', 'azure-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $event) : 
                        $start = strtotime($event['start_date']);
                        $end = strtotime($event['end_date']);
                        $is_cancelled = $event['status'] === 'trash' || $event['status'] === 'cancelled';
                        $is_modified = $event['modified'];
                    ?>
                    <tr class="<?php echo $is_cancelled ? 'cancelled' : ''; ?> <?php echo $is_modified ? 'modified' : ''; ?>">
                        <td><?php echo esc_html($event['session_number']); ?></td>
                        <td><?php echo date_i18n('M j, Y', $start); ?></td>
                        <td><?php echo date_i18n('g:i A', $start); ?> - <?php echo date_i18n('g:i A', $end); ?></td>
                        <td>
                            <?php if ($is_cancelled) : ?>
                            <span class="status-badge cancelled"><?php _e('Cancelled', 'azure-plugin'); ?></span>
                            <?php elseif ($is_modified) : ?>
                            <span class="status-badge modified"><?php _e('Modified', 'azure-plugin'); ?></span>
                            <?php else : ?>
                            <span class="status-badge scheduled"><?php _e('Scheduled', 'azure-plugin'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render schedule as mini calendar
     */
    private function render_schedule_calendar($events, $venue_name) {
        // Group events by month
        $months = array();
        foreach ($events as $event) {
            $month_key = date('Y-m', strtotime($event['start_date']));
            if (!isset($months[$month_key])) {
                $months[$month_key] = array();
            }
            $months[$month_key][] = $event;
        }
        
        ob_start();
        ?>
        <div class="class-schedule class-schedule-calendar">
            <h3><?php _e('Class Schedule', 'azure-plugin'); ?></h3>
            
            <?php if ($venue_name) : ?>
            <p class="class-venue">
                <span class="dashicons dashicons-location"></span>
                <?php echo esc_html($venue_name); ?>
            </p>
            <?php endif; ?>
            
            <div class="calendar-months">
                <?php foreach ($months as $month_key => $month_events) : 
                    $month_date = strtotime($month_key . '-01');
                ?>
                <div class="calendar-month">
                    <h4><?php echo date_i18n('F Y', $month_date); ?></h4>
                    <div class="month-dates">
                        <?php foreach ($month_events as $event) : 
                            $start = strtotime($event['start_date']);
                            $is_cancelled = $event['status'] === 'trash' || $event['status'] === 'cancelled';
                        ?>
                        <div class="date-item <?php echo $is_cancelled ? 'cancelled' : ''; ?>">
                            <span class="date-day"><?php echo date('j', $start); ?></span>
                            <span class="date-weekday"><?php echo date_i18n('D', $start); ?></span>
                            <span class="date-time"><?php echo date_i18n('g:i A', $start); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Shortcode: [class_pricing]
     * 
     * Attributes:
     * - product_id: Product ID (optional, uses current product if on product page)
     * - show_chart: true|false (default: false)
     */
    public function shortcode_class_pricing($atts) {
        $atts = shortcode_atts(array(
            'product_id' => 0,
            'show_chart' => 'false'
        ), $atts, 'class_pricing');
        
        $product_id = intval($atts['product_id']);
        $show_chart = filter_var($atts['show_chart'], FILTER_VALIDATE_BOOLEAN);
        
        // If no product ID, try to get from current product
        if (!$product_id) {
            global $product;
            if ($product && $product->get_type() === 'class') {
                $product_id = $product->get_id();
            }
        }
        
        if (!$product_id) {
            return '<p class="class-pricing-error">' . __('No class product specified.', 'azure-plugin') . '</p>';
        }
        
        // Get pricing info
        $pricing_info = Azure_Classes_Pricing::get_pricing_summary($product_id);
        
        ob_start();
        ?>
        <div class="class-pricing-widget">
            <?php if ($pricing_info['type'] === 'fixed' || $pricing_info['finalized']) : ?>
            
            <div class="pricing-final">
                <span class="price-label"><?php _e('Price:', 'azure-plugin'); ?></span>
                <span class="price-amount"><?php echo wc_price($pricing_info['price']); ?></span>
            </div>
            
            <?php else : ?>
            
            <div class="pricing-variable">
                <div class="likely-price">
                    <span class="price-label"><?php _e('Likely Price:', 'azure-plugin'); ?></span>
                    <span class="price-amount"><?php echo wc_price($pricing_info['likely_price']); ?></span>
                </div>
                
                <div class="price-range">
                    <span class="range-label"><?php _e('Price Range:', 'azure-plugin'); ?></span>
                    <span class="range-values">
                        <?php echo wc_price($pricing_info['max_price']); ?> - <?php echo wc_price($pricing_info['min_price']); ?>
                    </span>
                </div>
                
                <div class="commitment-stats">
                    <div class="stat">
                        <span class="stat-value"><?php echo esc_html($pricing_info['current_commitments']); ?></span>
                        <span class="stat-label"><?php _e('Committed', 'azure-plugin'); ?></span>
                    </div>
                    <div class="stat">
                        <span class="stat-value"><?php echo esc_html($pricing_info['max_attendees'] - $pricing_info['current_commitments']); ?></span>
                        <span class="stat-label"><?php _e('Spots Left', 'azure-plugin'); ?></span>
                    </div>
                </div>
                
                <?php if ($show_chart) : ?>
                <div class="pricing-chart">
                    <?php echo $this->render_pricing_chart($pricing_info); ?>
                </div>
                <?php endif; ?>
                
                <p class="pricing-note">
                    <?php _e('Final price determined when enrollment closes. More families = lower price.', 'azure-plugin'); ?>
                </p>
            </div>
            
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render pricing chart (SVG)
     */
    private function render_pricing_chart($pricing_info) {
        $min_attendees = $pricing_info['min_attendees'];
        $max_attendees = $pricing_info['max_attendees'];
        $min_price = $pricing_info['min_price'];
        $max_price = $pricing_info['max_price'];
        $current = $pricing_info['current_commitments'];
        $likely_price = $pricing_info['likely_price'];
        
        // Chart dimensions
        $width = 300;
        $height = 150;
        $padding = 30;
        $chart_width = $width - ($padding * 2);
        $chart_height = $height - ($padding * 2);
        
        // Calculate positions
        $current_x = $padding + (($current - $min_attendees) / ($max_attendees - $min_attendees)) * $chart_width;
        $current_x = max($padding, min($width - $padding, $current_x));
        $current_y = $padding + (($min_price - $likely_price) / ($min_price - $max_price)) * $chart_height;
        
        ob_start();
        ?>
        <svg width="<?php echo $width; ?>" height="<?php echo $height; ?>" class="pricing-chart-svg">
            <!-- Background -->
            <rect x="<?php echo $padding; ?>" y="<?php echo $padding; ?>" 
                  width="<?php echo $chart_width; ?>" height="<?php echo $chart_height; ?>" 
                  fill="#f7f7f7" stroke="#ddd" />
            
            <!-- Price line (diagonal) -->
            <line x1="<?php echo $padding; ?>" y1="<?php echo $padding; ?>" 
                  x2="<?php echo $width - $padding; ?>" y2="<?php echo $height - $padding; ?>" 
                  stroke="#0073aa" stroke-width="2" />
            
            <!-- Current position marker -->
            <circle cx="<?php echo $current_x; ?>" cy="<?php echo $current_y; ?>" r="6" fill="#0073aa" />
            
            <!-- Labels -->
            <text x="<?php echo $padding; ?>" y="<?php echo $height - 5; ?>" font-size="10" fill="#666">
                <?php echo $min_attendees; ?>
            </text>
            <text x="<?php echo $width - $padding; ?>" y="<?php echo $height - 5; ?>" font-size="10" fill="#666" text-anchor="end">
                <?php echo $max_attendees; ?>
            </text>
            <text x="5" y="<?php echo $padding + 5; ?>" font-size="10" fill="#666">
                <?php echo wc_price($min_price); ?>
            </text>
            <text x="5" y="<?php echo $height - $padding; ?>" font-size="10" fill="#666">
                <?php echo wc_price($max_price); ?>
            </text>
        </svg>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Auto-inject schedule on product pages
     */
    public function auto_inject_schedule() {
        global $product;
        
        if (!$product || $product->get_type() !== 'class') {
            return;
        }
        
        $product_id = $product->get_id();
        
        // Check if auto-inject is disabled for this product
        $disable_auto_inject = get_post_meta($product_id, '_class_disable_auto_schedule', true);
        if ($disable_auto_inject === 'yes') {
            return;
        }
        
        // Display schedule
        echo do_shortcode('[class_schedule product_id="' . $product_id . '" format="list"]');
    }
}

