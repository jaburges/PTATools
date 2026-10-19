<?php
/**
 * Product cards for a page or a sidebar: the "PTA Products" block (Widgets
 * category, every option in its settings panel) and the same cards as
 *
 *   [pta-products category="membership" products="123,456" fields="off" columns="1"]
 *
 * Fields off: every card opens its product page; nothing is added from the
 * card, so required product fields are filled in there.
 * Fields on: simple products with mapped product fields show them on the
 * card with Add to cart (the Product Collection card form); other products
 * get WooCommerce's normal button, which already opens the product page for
 * anything that needs it.
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_PTA_Products_Block {

    const BLOCK = 'pta/products';
    const SHORTCODE = 'pta-products';
    const STYLE_HANDLE = 'azure-pta-products';
    const EDITOR_HANDLE = 'azure-pta-products-editor';
    const MAX_COLUMNS = 4;
    const MAX_LIMIT = 24;
    const MAX_PICKED = 50;

    /** @var self|null */
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode(self::SHORTCODE, array($this, 'render_shortcode'));
        // The plugin builds its modules from its own init callback, and a
        // callback added to the init priority that is already running never fires.
        if (did_action('init')) {
            $this->register_block();
        } else {
            add_action('init', array($this, 'register_block'));
        }
    }

    public static function block_attributes() {
        return array(
            'category'   => array('type' => 'string', 'default' => ''),
            'productIds' => array('type' => 'array', 'items' => array('type' => 'integer'), 'default' => array()),
            'showFields' => array('type' => 'boolean', 'default' => false),
            'columns'    => array('type' => 'integer', 'default' => 1),
            'limit'      => array('type' => 'integer', 'default' => 6),
            'showImage'  => array('type' => 'boolean', 'default' => true),
            'showPrice'  => array('type' => 'boolean', 'default' => true),
            'buttonText' => array('type' => 'string', 'default' => ''),
        );
    }

    public function register_block() {
        if (!function_exists('register_block_type')) {
            return;
        }
        $ver = defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : '1';
        wp_register_style(self::STYLE_HANDLE, AZURE_PLUGIN_URL . 'css/pta-products.css', array(), $ver);
        wp_register_script(
            self::EDITOR_HANDLE,
            AZURE_PLUGIN_URL . 'js/pta-products-block.js',
            array('wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-api-fetch', 'wp-html-entities', 'wp-i18n'),
            $ver,
            true
        );
        register_block_type(self::BLOCK, array(
            'api_version'     => 2,
            'title'           => __('PTA Products', 'azure-plugin'),
            'description'     => __('Product cards from a category or a list you pick. Show the product fields on the card, or send people to the product page.', 'azure-plugin'),
            'category'        => 'widgets',
            'icon'            => 'cart',
            'keywords'        => array('membership', 'woocommerce', 'shop'),
            'editor_script'   => self::EDITOR_HANDLE,
            'style'           => self::STYLE_HANDLE,
            'attributes'      => self::block_attributes(),
            'supports'        => array('html' => false),
            'render_callback' => array($this, 'render_block'),
        ));
    }

    /**
     * Block attributes or shortcode atts → one settings shape.
     *
     * @return array{category:string,product_ids:int[],show_fields:bool,columns:int,limit:int,show_image:bool,show_price:bool,button_text:string}
     */
    public static function normalize(array $in) {
        $ids = $in['product_ids'] ?? array();
        if (is_string($ids)) {
            $ids = preg_split('/[\s,]+/', $ids, -1, PREG_SPLIT_NO_EMPTY);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids), function ($id) {
            return $id > 0;
        })));
        return array(
            'category'    => sanitize_title((string) ($in['category'] ?? '')),
            'product_ids' => array_slice($ids, 0, self::MAX_PICKED),
            'show_fields' => self::truthy($in['show_fields'] ?? false),
            'columns'     => max(1, min(self::MAX_COLUMNS, (int) ($in['columns'] ?? 1))),
            'limit'       => max(1, min(self::MAX_LIMIT, (int) ($in['limit'] ?? 6))),
            'show_image'  => self::truthy($in['show_image'] ?? true),
            'show_price'  => self::truthy($in['show_price'] ?? true),
            'button_text' => sanitize_text_field((string) ($in['button_text'] ?? '')),
        );
    }

    private static function truthy($value) {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), array('1', 'yes', 'on', 'true'), true);
    }

    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'category' => '',
            'products' => '',
            'fields'   => 'off',
            'columns'  => 1,
            'limit'    => 6,
            'image'    => 'yes',
            'price'    => 'yes',
            'button'   => '',
        ), $atts, self::SHORTCODE);
        $html = $this->render(self::normalize(array(
            'category'    => $atts['category'],
            'product_ids' => $atts['products'],
            'show_fields' => $atts['fields'],
            'columns'     => $atts['columns'],
            'limit'       => $atts['limit'],
            'show_image'  => $atts['image'],
            'show_price'  => $atts['price'],
            'button_text' => $atts['button'],
        )));
        return $html !== '' ? '<div class="wp-block-pta-products">' . $html . '</div>' : '';
    }

    public function render_block($attributes) {
        $a = is_array($attributes) ? $attributes : array();
        $html = $this->render(self::normalize(array(
            'category'    => $a['category'] ?? '',
            'product_ids' => $a['productIds'] ?? array(),
            'show_fields' => $a['showFields'] ?? false,
            'columns'     => $a['columns'] ?? 1,
            'limit'       => $a['limit'] ?? 6,
            'show_image'  => $a['showImage'] ?? true,
            'show_price'  => $a['showPrice'] ?? true,
            'button_text' => $a['buttonText'] ?? '',
        )));
        if ($html === '') {
            if (defined('REST_REQUEST') && REST_REQUEST) {
                return '<p class="pta-products-empty">' . esc_html__('No products to show yet. Choose a category or tick products in the block settings.', 'azure-plugin') . '</p>';
            }
            return '';
        }
        $wrapper = function_exists('get_block_wrapper_attributes') ? get_block_wrapper_attributes() : 'class="wp-block-pta-products"';
        return '<div ' . $wrapper . '>' . $html . '</div>';
    }

    /**
     * Ticked products first, in the order they were ticked, then up to
     * `limit` more from the category. Only the category list skips products
     * hidden from the shop; a product ticked by name is shown either way.
     *
     * @return int[]
     */
    public static function product_ids_for(array $s) {
        $ids = $s['product_ids'];
        if ($s['category'] !== '' && function_exists('wc_get_products')) {
            $from_category = wc_get_products(array(
                'status'     => 'publish',
                'category'   => array($s['category']),
                'visibility' => 'catalog',
                'limit'      => $s['limit'],
                'orderby'    => 'menu_order title',
                'order'      => 'ASC',
                'return'     => 'ids',
            ));
            foreach ((array) $from_category as $id) {
                if (!in_array((int) $id, $ids, true)) {
                    $ids[] = (int) $id;
                }
            }
        }
        return $ids;
    }

    /**
     * @return string The list, or '' when there is nothing to show.
     */
    public function render(array $s) {
        if (!function_exists('wc_get_product')) {
            return '';
        }
        $items = '';
        foreach (self::product_ids_for($s) as $id) {
            $product = wc_get_product($id);
            if (!$product || (method_exists($product, 'get_status') && $product->get_status() !== 'publish')) {
                continue;
            }
            $items .= $this->card_html($product, $s);
        }
        if ($items === '') {
            return '';
        }
        if (function_exists('wp_enqueue_style')) {
            wp_enqueue_style(self::STYLE_HANDLE);
        }
        $cols = (int) $s['columns'];
        return '<ul class="pta-products pta-products--cols-' . $cols . '" style="--pta-products-columns:' . $cols . ';">' . $items . '</ul>';
    }

    private function card_html($product, array $s) {
        $url = (string) $product->get_permalink();
        $name = (string) $product->get_name();
        // The card is a div inside the li so theme widget-list rules
        // (li padding, li:last-child borders) don't reshape it.
        $out = '<li class="pta-products__item"><div class="pta-products__card">';
        if ($s['show_image'] && method_exists($product, 'get_image')) {
            $out .= '<a class="pta-products__image" href="' . esc_url($url) . '" tabindex="-1" aria-hidden="true">' . $product->get_image('woocommerce_thumbnail', array('loading' => 'lazy')) . '</a>';
        }
        $out .= '<h3 class="pta-products__title"><a href="' . esc_url($url) . '">' . esc_html($name) . '</a></h3>';
        if ($s['show_price'] && method_exists($product, 'get_price_html')) {
            $price = (string) $product->get_price_html();
            if ($price !== '') {
                $out .= '<div class="pta-products__price">' . wp_kses_post($price) . '</div>';
            }
        }
        $out .= '<div class="pta-products__action">' . ($s['show_fields'] ? $this->add_to_cart_html($product, $s) : self::link_button($url, self::link_text($product, $s))) . '</div>';
        return $out . '</div></li>';
    }

    private function add_to_cart_html($product, array $s) {
        if (class_exists('Azure_Product_Fields_Module') && Azure_Product_Fields_Module::product_supports_inline_fields($product)) {
            $form = Azure_Product_Fields_Module::get_instance()->inline_form_html($product);
            if ($form !== '') {
                return $form;
            }
        }
        // add_to_cart_url()/text() already point products that need their page at it.
        $text = $s['button_text'] !== '' ? $s['button_text'] : (string) $product->add_to_cart_text();
        $ajax = method_exists($product, 'supports') && $product->supports('ajax_add_to_cart')
            && $product->is_purchasable() && $product->is_in_stock();
        if (!$ajax) {
            return self::link_button((string) $product->add_to_cart_url(), $text);
        }
        return '<div class="pta-products__button-wrap"><a href="' . esc_url((string) $product->add_to_cart_url()) . '"'
            . ' class="wp-block-button__link wp-element-button pta-products__button add_to_cart_button ajax_add_to_cart"'
            . ' data-product_id="' . esc_attr((string) $product->get_id()) . '" data-quantity="1" rel="nofollow">'
            . esc_html($text) . '</a></div>';
    }

    /**
     * "Select options" for products that are filled in or chosen on their
     * page, "View product" for the rest, unless the block sets its own text.
     */
    public static function link_text($product, array $s) {
        if ($s['button_text'] !== '') {
            return $s['button_text'];
        }
        $needs_page = (method_exists($product, 'is_type') && ($product->is_type('variable') || $product->is_type('grouped')))
            || (class_exists('Azure_Product_Fields_Module') && Azure_Product_Fields_Module::product_needs_product_page((int) $product->get_id()));
        return $needs_page ? __('Select options', 'azure-plugin') : __('View product', 'azure-plugin');
    }

    private static function link_button($url, $text) {
        return '<div class="pta-products__button-wrap"><a class="wp-block-button__link wp-element-button pta-products__button" href="' . esc_url($url) . '">'
            . esc_html($text) . '</a></div>';
    }
}
