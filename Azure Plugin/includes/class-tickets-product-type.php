<?php
/**
 * Tickets Module - WooCommerce product type, product edit tabs and the
 * product page container. Cart, pricing and seat holds are in
 * Azure_Tickets_Cart; the seat map itself is js/tickets-seat-selector.js.
 *
 * One ticket product is one performance. For several performances of the
 * same show, set one up and use WooCommerce's Duplicate, then change the
 * date: duplicates share the linked event, which is what puts the
 * "Other performances" links on the product page.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('product_type_selector', function($types) {
    $types['ticket'] = __('Event Ticket', 'azure-plugin');
    return $types;
});

if (class_exists('WC_Product')) {
    
    class WC_Product_Ticket extends WC_Product {
        
        protected $product_type = 'ticket';
        
        public function get_type() {
            return 'ticket';
        }
        
        public function is_virtual() {
            return true;
        }
        
        public function is_sold_individually() {
            return false;
        }

        // Seats are chosen on the product page, so shop lists, product blocks
        // and the PTA Products cards link there instead of adding to the cart.
        public function add_to_cart_url() {
            return apply_filters('woocommerce_product_add_to_cart_url', $this->get_permalink(), $this);
        }

        public function add_to_cart_text() {
            return apply_filters('woocommerce_product_add_to_cart_text', __('Choose seats', 'azure-plugin'), $this);
        }

        public function single_add_to_cart_text() {
            return apply_filters('woocommerce_product_single_add_to_cart_text', __('Choose seats', 'azure-plugin'), $this);
        }
    }
    
    add_filter('woocommerce_product_class', function($classname, $product_type) {
        if ($product_type === 'ticket') {
            return 'WC_Product_Ticket';
        }
        return $classname;
    }, 10, 2);
}

add_filter('woocommerce_product_data_tabs', function($tabs) {
    $tabs['ticket_event'] = array(
        'label' => __('Event', 'azure-plugin'),
        'target' => 'ticket_event_options',
        'class' => array('show_if_ticket'),
        'priority' => 20
    );
    $tabs['ticket_seating'] = array(
        'label' => __('Seating & Prices', 'azure-plugin'),
        'target' => 'ticket_seating_options',
        'class' => array('show_if_ticket'),
        'priority' => 25
    );
    $tabs['ticket_settings'] = array(
        'label' => __('Ticket Settings', 'azure-plugin'),
        'target' => 'ticket_settings_options',
        'class' => array('show_if_ticket'),
        'priority' => 30
    );
    return $tabs;
});

/**
 * Seating & Prices panel body for one product.
 */
function azure_tickets_seating_panel($product_id) {
    $venue_id = Azure_Tickets_Config::venue_id($product_id);
    $venue = $venue_id ? get_post($venue_id) : null;
    if (!$venue) {
        echo '<p class="form-field">' . esc_html__('Choose a venue on the Event tab and save (Update) the product. The venue\'s sections then appear here to price.', 'azure-plugin') . '</p>';
        return;
    }
    $sections = Azure_Tickets_Config::sections($product_id);
    $edit_url = admin_url('admin.php?page=azure-plugin-tickets&tab=venues&action=edit&venue_id=' . $venue_id);
    if (!$sections) {
        echo '<p class="form-field">' . sprintf(
            esc_html__('%s has no seating sections yet.', 'azure-plugin'),
            esc_html($venue->post_title)
        ) . ' <a class="button" target="_blank" href="' . esc_url($edit_url) . '">' . esc_html__('Design the layout', 'azure-plugin') . '</a></p>';
        return;
    }
    $layout = Azure_Tickets_Config::layout($product_id);
    $accessible = 0;
    $companion = 0;
    foreach ($sections as $s) {
        if ($s['type'] === 'rectangle') {
            $types = array_count_values(array_values($s['block']['seatTypes']));
            $accessible += $types[Azure_Tickets_Layout::SEAT_ACCESSIBLE] ?? 0;
            $companion += $types[Azure_Tickets_Layout::SEAT_COMPANION] ?? 0;
        }
    }
    $ada_price = Azure_Tickets_Config::ada_price($product_id);
    $vip = Azure_Tickets_Config::vip_products($product_id);
    $extras = Azure_Tickets_Config::extras($product_id);
    $first_opening = Azure_Tickets_Config::first_opening($product_id);
    $assign_url = admin_url('admin.php?page=azure-plugin-tickets&tab=assign&product=' . $product_id);
    $rules = Azure_Tickets_Config::family_rules($product_id);
    $raw_since = (string) get_post_meta($product_id, Azure_Tickets_Config::META_FAMILY_SINCE, true);
    $currency = get_woocommerce_currency_symbol();
    ?>
    <div class="pta-tickets-admin">
        <p>
            <strong><?php echo esc_html($venue->post_title); ?></strong>
            &middot; <?php echo esc_html(sprintf(_n('%d place', '%d places', Azure_Tickets_Layout::capacity($layout), 'azure-plugin'), Azure_Tickets_Layout::capacity($layout))); ?>
            &middot; <a href="<?php echo esc_url($edit_url); ?>" target="_blank"><?php esc_html_e('Edit layout', 'azure-plugin'); ?></a>
        </p>
        <table class="widefat striped pta-tickets-sections">
            <thead>
                <tr>
                    <th><?php esc_html_e('Section', 'azure-plugin'); ?></th>
                    <th><?php esc_html_e('Places', 'azure-plugin'); ?></th>
                    <th><?php echo esc_html(sprintf(__('Price (%s)', 'azure-plugin'), html_entity_decode($currency))); ?></th>
                    <th><?php esc_html_e('Limit', 'azure-plugin'); ?> <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e('Most tickets to sell in this section. Blank or 0 = every place.', 'azure-plugin'); ?>"></span></th>
                    <th><?php esc_html_e('On sale', 'azure-plugin'); ?></th>
                    <th><?php esc_html_e('Opens', 'azure-plugin'); ?> <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e('Optional. The section is shown but can\'t be bought until this time (site timezone), e.g. rows held for cast and crew.', 'azure-plugin'); ?>"></span></th>
                    <th><?php esc_html_e('Who can buy', 'azure-plugin'); ?></th>
                    <th><?php esc_html_e('Taken', 'azure-plugin'); ?> <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e('Sold, awaiting payment, or held in a cart right now.', 'azure-plugin'); ?>"></span></th>
                </tr>
            </thead>
            <tbody>
            <?php
            $stored = json_decode((string) get_post_meta($product_id, Azure_Tickets_Config::META_SECTIONS, true), true);
            foreach ($sections as $sid => $s):
                $price = is_array($stored) ? ($stored[$sid]['price'] ?? '') : '';
                $name = 'pta_ticket_sections[' . $sid . ']';
            ?>
                <tr>
                    <td><strong><?php echo esc_html($s['name']); ?></strong><br><small><?php echo $s['type'] === 'general_admission' ? esc_html__('General admission', 'azure-plugin') : esc_html__('Reserved seats', 'azure-plugin'); ?></small></td>
                    <td><?php echo (int) $s['capacity']; ?></td>
                    <td><input type="number" min="0" step="0.01" style="width:6em" name="<?php echo esc_attr($name); ?>[price]" value="<?php echo esc_attr($price === '' || $price === null ? '' : $price); ?>" placeholder="<?php echo esc_attr(wc_format_localized_price($s['default_price'])); ?>"></td>
                    <td><input type="number" min="0" step="1" style="width:5em" name="<?php echo esc_attr($name); ?>[limit]" value="<?php echo $s['limit'] ? (int) $s['limit'] : ''; ?>"></td>
                    <td><input type="checkbox" name="<?php echo esc_attr($name); ?>[on_sale]" value="1" <?php checked($s['enabled']); ?>></td>
                    <td><input type="datetime-local" name="<?php echo esc_attr($name); ?>[from]" value="<?php echo esc_attr($s['from']); ?>"></td>
                    <td>
                        <select name="<?php echo esc_attr($name); ?>[access]">
                            <option value="all" <?php selected($s['access'], 'all'); ?>><?php esc_html_e('Everyone', 'azure-plugin'); ?></option>
                            <option value="families" <?php selected($s['access'], 'families'); ?>><?php esc_html_e('Eligible families only', 'azure-plugin'); ?></option>
                        </select>
                    </td>
                    <td><?php echo (int) Azure_Tickets_Inventory::count_taken($product_id, array($sid)); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description"><?php esc_html_e('A blank price uses the price set on the layout (shown in grey). Sections that open later show on the seat map as "On sale from …" until then.', 'azure-plugin'); ?></p>

        <h4><?php esc_html_e('Wheelchair spaces (ADA tickets)', 'azure-plugin'); ?></h4>
        <?php if ($accessible): ?>
        <p>
            <label for="pta_ticket_ada_price"><strong><?php echo esc_html(sprintf(__('ADA ticket price (%s)', 'azure-plugin'), html_entity_decode($currency))); ?></strong></label>
            <input type="number" min="0" step="0.01" style="width:6em" id="pta_ticket_ada_price" name="pta_ticket_ada_price" value="<?php echo $ada_price === null ? '' : esc_attr($ada_price); ?>" placeholder="<?php esc_attr_e('Same', 'azure-plugin'); ?>">
            <span class="description"><?php esc_html_e('Blank = the same price as the section.', 'azure-plugin'); ?></span>
        </p>
        <label>
            <input type="checkbox" name="pta_ticket_release_accessible" value="yes" <?php checked(Azure_Tickets_Config::release_accessible($product_id)); ?>>
            <?php echo esc_html(sprintf(
                __('Release the %1$d wheelchair spaces and %2$d companion seats to everyone', 'azure-plugin'),
                $accessible, $companion
            )); ?>
        </label>
        <p class="description"><?php esc_html_e('Until released, a buyer choosing a wheelchair space confirms that someone in their party needs it, and companion seats can only be bought together with a wheelchair space. Tick this close to the show to sell any left over.', 'azure-plugin'); ?></p>
        <?php else: ?>
        <p class="description"><?php esc_html_e('No wheelchair spaces are marked on this layout. Use the Wheelchair and Companion seat tools in the layout designer to mark them.', 'azure-plugin'); ?></p>
        <?php endif; ?>

        <h4><?php esc_html_e('Cast & crew (VIP) seat assignment', 'azure-plugin'); ?></h4>
        <p class="description"><?php esc_html_e('Orders for these products get their seats assigned by hand on the Assign seats screen, which then emails each family their seats and QR tickets. Pick the product, or the variation for this performance.', 'azure-plugin'); ?></p>
        <p>
            <select class="wc-product-search" multiple="multiple" style="width:100%" name="pta_ticket_vip_products[]" data-placeholder="<?php esc_attr_e('e.g. VIP Cast & Crew ticket', 'azure-plugin'); ?>" data-action="woocommerce_json_search_products_and_variations">
                <?php foreach ($vip as $pid):
                    $p = wc_get_product($pid);
                    if ($p): ?>
                    <option value="<?php echo (int) $pid; ?>" selected><?php echo esc_html(wp_strip_all_tags($p->get_formatted_name())); ?></option>
                <?php endif; endforeach; ?>
            </select>
        </p>
        <label>
            <input type="checkbox" name="pta_ticket_vip_close" value="yes" <?php checked(get_post_meta($product_id, Azure_Tickets_Config::META_VIP_CLOSE, true), 'yes'); ?>>
            <?php
            echo $first_opening
                ? esc_html(sprintf(__('Stop selling these when the held sections open (%s)', 'azure-plugin'), wp_date('D j M, g:ia', $first_opening)))
                : esc_html__('Stop selling these when the held sections open (set an "Opens" time above)', 'azure-plugin');
            ?>
        </label>
        <?php if ($vip): ?>
        <p><a class="button" href="<?php echo esc_url($assign_url); ?>"><?php esc_html_e('Assign seats', 'azure-plugin'); ?></a></p>
        <?php endif; ?>

        <h4><?php esc_html_e('Extras after choosing seats', 'azure-plugin'); ?></h4>
        <p class="description"><?php esc_html_e('Offered on a step between the seat map and the cart, and again on the cart page (e.g. flowers, stargrams, concession tickets). Product fields show on the cards.', 'azure-plugin'); ?></p>
        <p>
            <label for="pta_ticket_extras_heading"><strong><?php esc_html_e('Heading', 'azure-plugin'); ?></strong></label><br>
            <input type="text" style="width:100%" id="pta_ticket_extras_heading" name="pta_ticket_extras_heading" value="<?php echo esc_attr((string) get_post_meta($product_id, Azure_Tickets_Config::META_EXTRAS_HEADING, true)); ?>" placeholder="<?php echo esc_attr__('Jazz up your theater experience', 'azure-plugin'); ?>">
        </p>
        <p>
            <select class="wc-product-search" multiple="multiple" style="width:100%" name="pta_ticket_extras[]" data-placeholder="<?php esc_attr_e('Flowers, Stargrams, Concession tickets…', 'azure-plugin'); ?>" data-action="woocommerce_json_search_products_and_variations">
                <?php foreach ($extras as $pid):
                    $p = wc_get_product($pid);
                    if ($p): ?>
                    <option value="<?php echo (int) $pid; ?>" selected><?php echo esc_html(wp_strip_all_tags($p->get_formatted_name())); ?></option>
                <?php endif; endforeach; ?>
            </select>
        </p>

        <h4><?php esc_html_e('Eligible families', 'azure-plugin'); ?></h4>
        <p class="description"><?php esc_html_e('Who can buy in sections set to "Eligible families only". A signed-in buyer qualifies if their account or billing email is on the list, or they bought one of the products below since the start date. Either is enough.', 'azure-plugin'); ?></p>
        <p>
            <label for="pta_ticket_family_emails"><strong><?php esc_html_e('Email list', 'azure-plugin'); ?></strong></label><br>
            <textarea id="pta_ticket_family_emails" name="pta_ticket_family_emails" rows="5" style="width:100%" placeholder="<?php esc_attr_e('Paste emails, one per line or straight from a spreadsheet', 'azure-plugin'); ?>"><?php echo esc_textarea((string) get_post_meta($product_id, Azure_Tickets_Config::META_FAMILY_EMAILS, true)); ?></textarea>
            <input type="file" accept=".csv,.txt,text/csv,text/plain" class="pta-family-csv">
            <span class="pta-family-count"><?php echo esc_html(sprintf(_n('%d email', '%d emails', count($rules['emails']), 'azure-plugin'), count($rules['emails']))); ?></span>
        </p>
        <p>
            <label><strong><?php esc_html_e('Or bought any of these', 'azure-plugin'); ?></strong></label><br>
            <select class="wc-product-search" multiple="multiple" style="width:100%" name="pta_ticket_family_products[]" data-placeholder="<?php esc_attr_e('e.g. Theater Roles (Cast or Crew)', 'azure-plugin'); ?>" data-action="woocommerce_json_search_products_and_variations">
                <?php foreach ($rules['products'] as $pid):
                    $p = wc_get_product($pid);
                    if ($p): ?>
                    <option value="<?php echo (int) $pid; ?>" selected><?php echo esc_html(wp_strip_all_tags($p->get_formatted_name())); ?></option>
                <?php endif; endforeach; ?>
            </select>
        </p>
        <p>
            <label for="pta_ticket_family_since"><strong><?php esc_html_e('Bought on or after', 'azure-plugin'); ?></strong></label>
            <input type="date" id="pta_ticket_family_since" name="pta_ticket_family_since" value="<?php echo esc_attr($raw_since); ?>">
            <span class="description"><?php echo esc_html(sprintf(__('Blank = start of the school year (%s).', 'azure-plugin'), date_i18n(get_option('date_format'), strtotime(Azure_Tickets_Config::school_year_start())))); ?></span>
        </p>
        <p>
            <label for="pta_ticket_family_limit"><strong><?php esc_html_e('Seats per family', 'azure-plugin'); ?></strong></label>
            <input type="number" min="0" step="1" style="width:5em" id="pta_ticket_family_limit" name="pta_ticket_family_limit" value="<?php echo $rules['limit'] ? (int) $rules['limit'] : ''; ?>">
            <span class="description"><?php esc_html_e('Across all their orders for this performance, in the eligible-families sections. Blank = no limit beyond the per-order limit.', 'azure-plugin'); ?></span>
        </p>
    </div>
    <?php
}

add_action('woocommerce_product_data_panels', function() {
    global $post;

    $venue_posts = get_posts(array(
        'post_type'      => array('pta_venue', 'tribe_venue'),
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'post_status'    => 'publish',
    ));

    $events = get_posts(array(
        'post_type'      => 'pta_event',
        'posts_per_page' => 50,
        'orderby'        => 'meta_value',
        'meta_key'       => '_EventStartDate',
        'order'          => 'ASC',
        'meta_query'     => array(array(
            'key'     => '_EventStartDate',
            'value'   => date('Y-m-d'),
            'compare' => '>=',
            'type'    => 'DATE',
        )),
    ));
    $linked = (int) get_post_meta($post->ID, '_ticket_event_id', true);
    if ($linked && !in_array($linked, wp_list_pluck($events, 'ID'), true) && ($e = get_post($linked))) {
        $events[] = $e;
    }

    ?>
    <div id="ticket_event_options" class="panel woocommerce_options_panel">
        <div class="options_group">
            <?php
            $event_options = array('' => __('-- None --', 'azure-plugin'));
            foreach ($events as $event) {
                $start_date = get_post_meta($event->ID, '_EventStartDate', true);
                $date_label = $start_date ? date_i18n('M j, Y', strtotime($start_date)) : '';
                $event_options[$event->ID] = $event->post_title . ($date_label ? ' (' . $date_label . ')' : '');
            }
            woocommerce_wp_select(array(
                'id' => '_ticket_event_id',
                'label' => __('Link to Event', 'azure-plugin'),
                'description' => __('Performances linked to the same event link to each other on the product page.', 'azure-plugin'),
                'desc_tip' => true,
                'options' => $event_options
            ));

            woocommerce_wp_text_input(array(
                'id' => '_ticket_event_date',
                'label' => __('Performance date', 'azure-plugin'),
                'description' => __('Date and time of this performance.', 'azure-plugin'),
                'desc_tip' => true,
                'type' => 'datetime-local'
            ));

            woocommerce_wp_text_input(array(
                'id' => '_ticket_sales_close',
                'label' => __('Online sales close', 'azure-plugin'),
                'description' => __('Blank = midnight at the start of the performance day.', 'azure-plugin'),
                'desc_tip' => true,
                'type' => 'datetime-local'
            ));
            
            $venue_options = array('' => __('-- Select Venue --', 'azure-plugin'));
            foreach ($venue_posts as $venue) {
                $has_layout = get_post_meta($venue->ID, '_azure_seating_layout', true);
                $venue_options[$venue->ID] = $venue->post_title . ($has_layout ? '' : ' ' . __('(no seating layout)', 'azure-plugin'));
            }
            woocommerce_wp_select(array(
                'id' => '_ticket_venue_id',
                'label' => __('Venue', 'azure-plugin'),
                'description' => __('Save the product after changing the venue to price its sections.', 'azure-plugin'),
                'desc_tip' => true,
                'options' => $venue_options
            ));
            ?>
            <p class="form-field">
                <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-tickets&tab=venues')); ?>" class="button" target="_blank">
                    <?php esc_html_e('Manage Venue Seating Layouts', 'azure-plugin'); ?>
                </a>
            </p>
        </div>
    </div>
    
    <div id="ticket_seating_options" class="panel woocommerce_options_panel" style="padding:0 12px 12px">
        <?php azure_tickets_seating_panel((int) $post->ID); ?>
    </div>
    
    <div id="ticket_settings_options" class="panel woocommerce_options_panel">
        <div class="options_group">
            <?php
            woocommerce_wp_text_input(array(
                'id' => '_tickets_per_order_limit',
                'label' => __('Max Tickets per Order', 'azure-plugin'),
                'description' => sprintf(__('Most tickets one cart can hold for this performance. Blank = %d.', 'azure-plugin'), Azure_Tickets_Config::DEFAULT_ORDER_LIMIT),
                'desc_tip' => true,
                'type' => 'number',
                'custom_attributes' => array('min' => 1)
            ));
            woocommerce_wp_checkbox(array(
                'id' => '_ticket_require_names',
                'label' => __('Require Attendee Names', 'azure-plugin'),
                'description' => __('Ask for a name for every ticket when choosing seats.', 'azure-plugin')
            ));
            ?>
        </div>
    </div>
    
    <style>
        .show_if_ticket { display: none; }
        .product-type-ticket .show_if_ticket { display: block; }
        .product-type-ticket .hide_if_ticket { display: none; }
        #ticket_seating_options .pta-tickets-sections td, #ticket_seating_options .pta-tickets-sections th { vertical-align: middle; padding: 6px 8px; }
        #ticket_seating_options .pta-tickets-sections input, #ticket_seating_options .pta-tickets-sections select { float: none; margin: 0; }
        #ticket_seating_options textarea, #ticket_seating_options input[type=date], #ticket_seating_options input[type=text], #ticket_seating_options input[type=datetime-local] { float: none; }
        #ticket_seating_options label { float: none; width: auto; margin: 0; padding: 0; display: inline; }
        #ticket_seating_options input[type=checkbox], #ticket_seating_options input[type=number] { float: none; margin: 0 6px 0 0; }
        #ticket_seating_options .description { display: block; margin: 4px 0 0; padding: 0; }
        #ticket_seating_options p { padding: 0; margin: 10px 0; }
    </style>
    
    <script>
    jQuery(function($) {
        function toggleTicketFields() {
            var isTicket = $('#product-type').val() === 'ticket';
            $('.show_if_ticket').toggle(isTicket);
            if (isTicket) {
                $('.hide_if_ticket').hide();
            }
        }
        $('#product-type').on('change', toggleTicketFields);
        toggleTicketFields();

        var $emails = $('#pta_ticket_family_emails');
        function countEmails() {
            var found = ($emails.val() || '').toLowerCase().match(/[a-z0-9._%+'-]+@[a-z0-9.-]+\.[a-z]{2,}/g) || [];
            var unique = found.filter(function(e, i) { return found.indexOf(e) === i; }).length;
            $('.pta-family-count').text(unique + (unique === 1 ? ' email' : ' emails'));
        }
        $emails.on('input', countEmails);
        $('.pta-family-csv').on('change', function() {
            var file = this.files && this.files[0];
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function() {
                var current = $emails.val();
                $emails.val((current ? current.replace(/\s+$/, '') + '\n' : '') + reader.result);
                countEmails();
            };
            reader.readAsText(file);
            this.value = '';
        });
    });
    </script>
    <?php
});

add_action('woocommerce_process_product_meta', function($post_id) {
    if (($_POST['product-type'] ?? '') !== 'ticket') {
        return;
    }
    if (isset($_POST['_ticket_event_id'])) {
        update_post_meta($post_id, '_ticket_event_id', absint($_POST['_ticket_event_id']) ?: '');
    }
    if (isset($_POST['_ticket_event_date'])) {
        update_post_meta($post_id, '_ticket_event_date', sanitize_text_field(wp_unslash($_POST['_ticket_event_date'])));
    }
    if (isset($_POST['_ticket_sales_close'])) {
        update_post_meta($post_id, Azure_Tickets_Config::META_SALES_CLOSE, Azure_Tickets_Config::clean_local(wp_unslash($_POST['_ticket_sales_close'])));
    }
    $old_venue = (int) get_post_meta($post_id, '_ticket_venue_id', true);
    if (isset($_POST['_ticket_venue_id'])) {
        update_post_meta($post_id, '_ticket_venue_id', absint($_POST['_ticket_venue_id']));
    }
    if (isset($_POST['_tickets_per_order_limit'])) {
        $limit = absint($_POST['_tickets_per_order_limit']);
        update_post_meta($post_id, '_tickets_per_order_limit', $limit ?: '');
    }
    update_post_meta($post_id, '_ticket_require_names', isset($_POST['_ticket_require_names']) ? 'yes' : 'no');

    // The section rows on screen belong to the venue the page was loaded
    // with; after a venue change they don't apply, so wait for the reload.
    Azure_Tickets_Config::flush();
    if (isset($_POST['pta_ticket_sections']) && $old_venue === (int) get_post_meta($post_id, '_ticket_venue_id', true)) {
        $posted = map_deep(wp_unslash((array) $_POST['pta_ticket_sections']), 'sanitize_text_field');
        update_post_meta($post_id, Azure_Tickets_Config::META_SECTIONS, wp_slash(Azure_Tickets_Config::sanitize_sections($posted, Azure_Tickets_Config::layout($post_id))));
        update_post_meta($post_id, Azure_Tickets_Config::META_RELEASE_ACCESSIBLE, isset($_POST['pta_ticket_release_accessible']) ? 'yes' : 'no');
        $ada = trim((string) wp_unslash($_POST['pta_ticket_ada_price'] ?? ''));
        update_post_meta($post_id, Azure_Tickets_Config::META_ADA_PRICE, $ada === '' ? '' : round(max(0, (float) $ada), 2));
        update_post_meta($post_id, Azure_Tickets_Config::META_VIP_PRODUCTS, array_values(array_filter(array_map('absint', (array) ($_POST['pta_ticket_vip_products'] ?? array())))));
        update_post_meta($post_id, Azure_Tickets_Config::META_VIP_CLOSE, isset($_POST['pta_ticket_vip_close']) ? 'yes' : 'no');
        update_post_meta($post_id, Azure_Tickets_Config::META_EXTRAS, array_values(array_filter(array_map('absint', (array) ($_POST['pta_ticket_extras'] ?? array())))));
        update_post_meta($post_id, Azure_Tickets_Config::META_EXTRAS_HEADING, sanitize_text_field(wp_unslash($_POST['pta_ticket_extras_heading'] ?? '')));
    }
    if (isset($_POST['pta_ticket_family_emails'])) {
        $emails = Azure_Tickets_Config::parse_emails(wp_unslash($_POST['pta_ticket_family_emails']));
        update_post_meta($post_id, Azure_Tickets_Config::META_FAMILY_EMAILS, implode("\n", $emails));
        update_post_meta($post_id, Azure_Tickets_Config::META_FAMILY_PRODUCTS, array_values(array_filter(array_map('absint', (array) ($_POST['pta_ticket_family_products'] ?? array())))));
        $since = sanitize_text_field(wp_unslash($_POST['pta_ticket_family_since'] ?? ''));
        update_post_meta($post_id, Azure_Tickets_Config::META_FAMILY_SINCE, preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) ? $since : '');
        update_post_meta($post_id, Azure_Tickets_Config::META_FAMILY_LIMIT, absint($_POST['pta_ticket_family_limit'] ?? 0) ?: '');
    }
    Azure_Tickets_Config::flush();
    if (class_exists('Azure_Tickets_Access')) {
        Azure_Tickets_Access::flush();
    }
}, 10);

// After WooCommerce has saved the product type and status.
add_action('woocommerce_process_product_meta', function($post_id) {
    if (($_POST['product-type'] ?? '') === 'ticket') {
        Azure_Tickets_Config::rebuild_vip_close_map();
    }
}, 99);

add_action('woocommerce_single_product_summary', function() {
    global $product;
    if ($product && $product->get_type() === 'ticket') {
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
    }
}, 5);

add_action('woocommerce_single_product_summary', function() {
    global $product;
    
    if (!$product || $product->get_type() !== 'ticket') {
        return;
    }
    $product_id = $product->get_id();
    $event_date = get_post_meta($product_id, '_ticket_event_date', true);
    $performances = Azure_Tickets_Config::performances($product_id);
    ?>
    <div class="pta-tickets">
        <?php if ($event_date): ?>
        <p class="pta-tickets__date">
            <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
            <?php echo esc_html(date_i18n('l, F j, Y \a\t g:i A', strtotime($event_date))); ?>
        </p>
        <?php endif; ?>

        <?php if (count($performances) > 1): ?>
        <nav class="pta-tickets__performances" aria-label="<?php esc_attr_e('Performances', 'azure-plugin'); ?>">
            <span class="pta-tickets__performances-label"><?php esc_html_e('Choose a performance:', 'azure-plugin'); ?></span>
            <?php foreach ($performances as $pid):
                $date = get_post_meta($pid, '_ticket_event_date', true);
                $label = $date ? date_i18n('D j M, g:i a', strtotime($date)) : get_the_title($pid);
                if ($pid === $product_id): ?>
                <span class="pta-tickets__performance is-current" aria-current="page"><?php echo esc_html($label); ?></span>
                <?php else: ?>
                <a class="pta-tickets__performance" href="<?php echo esc_url(get_permalink($pid)); ?>"><?php echo esc_html($label); ?></a>
                <?php endif;
            endforeach; ?>
        </nav>
        <?php endif; ?>

        <?php if (Azure_Tickets_Config::sales_closed($product_id)): ?>
        <p class="pta-tickets__unavailable"><?php esc_html_e('Online sales for this performance have closed.', 'azure-plugin'); ?></p>
        <?php elseif (Azure_Tickets_Config::sections($product_id)): ?>
        <div class="pta-seatmap" data-product-id="<?php echo esc_attr($product_id); ?>" data-require-names="<?php echo Azure_Tickets_Config::require_names($product_id) ? '1' : '0'; ?>">
            <p class="pta-seatmap__loading"><?php esc_html_e('Loading seating chart…', 'azure-plugin'); ?></p>
            <noscript><?php esc_html_e('Please turn on JavaScript to choose seats.', 'azure-plugin'); ?></noscript>
        </div>
        <?php echo azure_tickets_extras_html($product_id, 'step'); ?>
        <?php else: ?>
        <p class="pta-tickets__unavailable"><?php esc_html_e('Tickets for this performance aren\'t on sale yet.', 'azure-plugin'); ?></p>
        <?php endif; ?>
    </div>
    <?php
}, 35);

/**
 * The extras offered with a performance, as PTA Products cards with their
 * product fields. $where: "step" (hidden until seats are in the cart) or "cart".
 */
function azure_tickets_extras_html($product_id, $where) {
    $ids = Azure_Tickets_Config::extras($product_id);
    if (!$ids || !class_exists('Azure_PTA_Products_Block')) {
        return '';
    }
    $cards = Azure_PTA_Products_Block::get_instance()->render(Azure_PTA_Products_Block::normalize(array(
        'product_ids' => $ids,
        'show_fields' => true,
        'columns'     => min(3, count($ids)),
    )));
    if ($cards === '') {
        return '';
    }
    $heading = '<h2 class="pta-extras__heading">' . esc_html(Azure_Tickets_Config::extras_heading($product_id)) . '</h2>';
    if ($where === 'cart') {
        return '<section class="pta-extras pta-extras--cart">' . $heading . '<div class="wp-block-pta-products">' . $cards . '</div></section>';
    }
    return '<section class="pta-extras pta-extras--step" hidden>'
        . '<p class="pta-extras__done">' . esc_html__('Your seats are in your cart.', 'azure-plugin') . '</p>'
        . $heading
        . '<div class="wp-block-pta-products">' . $cards . '</div>'
        . '<p class="pta-extras__next"><a class="button alt wp-element-button pta-extras__continue" href="' . esc_url(wc_get_cart_url()) . '">' . esc_html__('Continue to cart', 'azure-plugin') . '</a></p>'
        . '</section>';
}
