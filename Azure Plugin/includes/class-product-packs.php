<?php
/**
 * Products sold in packs: a simple product can be sold only in multiples of
 * a pack size (10 concession tickets at a time), with its own "Add" button
 * text and a short buying tip.
 *
 * WooCommerce has two add-to-cart paths. The classic one (product page, the
 * upsell list, the PTA products block) goes through WC_Cart::add_to_cart, so
 * its quantity filter rounds every add up to a whole pack. The block cart and
 * its cross-sells go through the Store API, which rounds a new line up to the
 * minimum and steps the cart's quantity picker by the pack size.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Product_Packs {

    const META_SIZE = '_pta_pack_size';
    const META_BUTTON = '_pta_pack_button';
    const META_TIP = '_pta_pack_tip';

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('woocommerce_product_options_general_product_data', array($this, 'render_fields'));
        add_action('woocommerce_process_product_meta', array($this, 'save_fields'));

        add_filter('woocommerce_store_api_product_quantity_minimum', array($this, 'store_api_minimum'), 10, 2);
        add_filter('woocommerce_store_api_product_quantity_multiple_of', array($this, 'store_api_multiple_of'), 10, 2);
        add_filter('woocommerce_quantity_input_args', array($this, 'quantity_input_args'), 10, 2);
        add_filter('woocommerce_add_to_cart_quantity', array($this, 'add_to_cart_quantity'), 10, 2);
        add_filter('woocommerce_loop_add_to_cart_args', array($this, 'loop_add_to_cart_args'), 10, 2);
        // After the product-fields filter, which sends products with fields to their page.
        add_filter('woocommerce_product_add_to_cart_text', array($this, 'add_to_cart_text'), 30, 2);

        add_action('woocommerce_after_shop_loop_item', array($this, 'print_loop_tip'), 20);
        add_action('woocommerce_after_add_to_cart_form', array($this, 'print_single_tip'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue'));
    }

    /**
     * @return int The pack size, 1 for products sold one at a time.
     */
    public static function size($product) {
        if (!is_object($product) || !method_exists($product, 'is_type') || !$product->is_type('simple')) {
            return 1;
        }
        return max(1, (int) $product->get_meta(self::META_SIZE));
    }

    public static function round_up($quantity, $size) {
        $size = max(1, (int) $size);
        return max($size, (int) ceil(((float) $quantity) / $size) * $size);
    }

    public static function tip($product) {
        return is_object($product) && method_exists($product, 'get_meta') ? trim((string) $product->get_meta(self::META_TIP)) : '';
    }

    public static function tip_html($product) {
        $tip = self::tip($product);
        return $tip === '' ? '' : '<p class="pta-pack-tip">' . esc_html($tip) . '</p>';
    }

    // ── Product editor ────────────────────────────────────────────────

    public function render_fields() {
        echo '<div class="options_group show_if_simple">';
        woocommerce_wp_text_input(array(
            'id'                => self::META_SIZE,
            'label'             => __('Sold in multiples of', 'azure-plugin'),
            'type'              => 'number',
            'custom_attributes' => array('min' => '1', 'step' => '1'),
            'placeholder'       => '1',
            'desc_tip'          => true,
            'description'       => __('Buyers add this many at a time, and the cart only allows whole multiples. Leave empty to sell one at a time.', 'azure-plugin'),
        ));
        woocommerce_wp_text_input(array(
            'id'          => self::META_BUTTON,
            'label'       => __('Add button text', 'azure-plugin'),
            'placeholder' => __('Add to cart', 'azure-plugin'),
            'desc_tip'    => true,
            'description' => __('Shown on this product in upsells, cross-sells and product lists, e.g. "Add 10 tickets".', 'azure-plugin'),
        ));
        woocommerce_wp_text_input(array(
            'id'          => self::META_TIP,
            'label'       => __('Buying tip', 'azure-plugin'),
            'placeholder' => __('e.g. We recommend around 10 tickets per person', 'azure-plugin'),
            'desc_tip'    => true,
            'description' => __('A short line under the Add button.', 'azure-plugin'),
        ));
        echo '</div>';
    }

    public function save_fields($post_id) {
        $size = (int) ($_POST[self::META_SIZE] ?? 0);
        if ($size > 1) {
            update_post_meta($post_id, self::META_SIZE, $size);
        } else {
            delete_post_meta($post_id, self::META_SIZE);
        }
        foreach (array(self::META_BUTTON, self::META_TIP) as $key) {
            $value = sanitize_text_field(wp_unslash((string) ($_POST[$key] ?? '')));
            if ($value !== '') {
                update_post_meta($post_id, $key, $value);
            } else {
                delete_post_meta($post_id, $key);
            }
        }
    }

    // ── Quantities ────────────────────────────────────────────────────

    public function store_api_minimum($value, $product) {
        $size = self::size($product);
        return $size > 1 ? max((int) $value, $size) : $value;
    }

    public function store_api_multiple_of($value, $product) {
        $size = self::size($product);
        return $size > 1 ? $size : $value;
    }

    public function quantity_input_args($args, $product) {
        $size = self::size($product);
        if ($size > 1) {
            $args['min_value'] = $size;
            $args['step'] = $size;
            $args['input_value'] = self::round_up($args['input_value'] ?? $size, $size);
        }
        return $args;
    }

    public function add_to_cart_quantity($quantity, $product_id) {
        $size = self::size(wc_get_product($product_id));
        return $size > 1 ? self::round_up($quantity, $size) : $quantity;
    }

    public function loop_add_to_cart_args($args, $product) {
        $size = self::size($product);
        if ($size > 1) {
            $args['quantity'] = $size;
        }
        return $args;
    }

    public function add_to_cart_text($text, $product) {
        if (!is_object($product) || !method_exists($product, 'get_meta')) {
            return $text;
        }
        $button = trim((string) $product->get_meta(self::META_BUTTON));
        if ($button === '' || (class_exists('Azure_Product_Fields_Module') && Azure_Product_Fields_Module::loop_button_opens_product_page($product))) {
            return $text;
        }
        return $button;
    }

    // ── Tips ──────────────────────────────────────────────────────────

    public function print_loop_tip() {
        global $product;
        echo self::tip_html($product); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in tip_html
    }

    public function print_single_tip() {
        global $product;
        echo self::tip_html($product); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in tip_html
    }

    /**
     * The block cart draws its cross-sells in the browser, so their tips are
     * added there, matched to each card by its product link.
     */
    public function enqueue() {
        if (!function_exists('WC')) {
            return;
        }
        wp_register_style('azure-product-packs', false, array(), AZURE_PLUGIN_VERSION);
        wp_enqueue_style('azure-product-packs');
        wp_add_inline_style('azure-product-packs', '.pta-pack-tip,.cross-sells-product .pta-pack-tip{margin:6px 0 10px;font-size:14px;line-height:1.4;color:#50575e;}');

        if (!function_exists('is_cart') || !is_cart() || !WC()->cart) {
            return;
        }
        $tips = array();
        foreach (WC()->cart->get_cross_sells() as $id) {
            $product = wc_get_product($id);
            $tip = self::tip($product);
            if ($tip !== '') {
                $tips[untrailingslashit((string) $product->get_permalink())] = $tip;
            }
        }
        if (!$tips) {
            return;
        }
        wp_register_script('azure-product-packs', false, array(), AZURE_PLUGIN_VERSION, true);
        wp_enqueue_script('azure-product-packs');
        wp_add_inline_script('azure-product-packs', '(function(tips){'
            . 'function add(){document.querySelectorAll(".cross-sells-product:not([data-pta-tip])").forEach(function(card){'
            . 'var link=card.querySelector("a[href]");if(!link)return;'
            . 'var tip=tips[link.href.replace(/\/$/,"")];card.setAttribute("data-pta-tip","");if(!tip)return;'
            . 'var p=document.createElement("p");p.className="pta-pack-tip";p.textContent=tip;card.appendChild(p);});}'
            . 'new MutationObserver(add).observe(document.body,{childList:true,subtree:true});add();'
            . '})(' . wp_json_encode($tips) . ');');
    }
}
