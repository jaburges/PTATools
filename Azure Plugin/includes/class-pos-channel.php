<?php
/**
 * Sales channel: where a product can be bought.
 *
 * - Online only (the default): the shop, as before.
 * - Online and Tap to Pay: the shop, and volunteers' phones at events
 *   (PTSA Board app, Tap to Pay).
 * - Tap to Pay only: still published, but hidden from the shop and search
 *   and refused by the online cart, so online sales stop while volunteers
 *   keep selling it at events.
 *
 * Hiding uses WooCommerce's own catalog visibility, so every list, search
 * and block hides it. The visibility it had before is kept and put back
 * when the product goes back online. Hidden products can still be opened
 * by their link, which is why the cart refuses them too.
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_POS_Channel {

    const META = '_pta_sales_channel';
    const META_PREVIOUS_VISIBILITY = '_pta_pos_previous_visibility';

    const ONLINE = 'online';
    const BOTH = 'both';
    const POS_ONLY = 'pos';

    const NO_CHANGE = '';

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('woocommerce_product_options_general_product_data', array($this, 'render_field'));
        add_action('woocommerce_admin_process_product_object', array($this, 'save_field'));
        add_action('woocommerce_product_bulk_edit_end', array($this, 'render_bulk_field'));
        add_action('woocommerce_product_bulk_edit_save', array($this, 'save_bulk_field'));

        add_filter('woocommerce_is_purchasable', array($this, 'filter_purchasable'), 20, 2);
        add_filter('woocommerce_variation_is_purchasable', array($this, 'filter_purchasable'), 20, 2);
    }

    /** @return array<string,string> */
    public static function labels() {
        return array(
            self::ONLINE   => __('Online only', 'azure-plugin'),
            self::BOTH     => __('Online and Tap to Pay', 'azure-plugin'),
            self::POS_ONLY => __('Tap to Pay only (hidden online)', 'azure-plugin'),
        );
    }

    public static function clean($raw) {
        $raw = (string) $raw;
        return isset(self::labels()[$raw]) ? $raw : self::ONLINE;
    }

    /**
     * A variation follows its parent product.
     *
     * @param WC_Product|int|null $product
     */
    public static function channel($product) {
        if (is_numeric($product)) {
            $product = function_exists('wc_get_product') ? wc_get_product((int) $product) : null;
        }
        if (!is_object($product) || !method_exists($product, 'get_meta')) {
            return self::ONLINE;
        }
        if (method_exists($product, 'get_parent_id') && $product->get_parent_id() && method_exists($product, 'is_type') && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                $product = $parent;
            }
        }
        return self::clean($product->get_meta(self::META));
    }

    /** Volunteers can sell it from the app. */
    public static function sells_in_pos($product) {
        return self::channel($product) !== self::ONLINE;
    }

    public static function online_stopped($product) {
        return self::channel($product) === self::POS_ONLY;
    }

    /**
     * Sets the channel and hides or unhides the product to match.
     * Callers save the product.
     *
     * @param WC_Product $product
     */
    public static function apply($product, $channel) {
        $channel = self::clean($channel);
        $was = self::clean($product->get_meta(self::META));
        if ($channel === self::ONLINE) {
            $product->delete_meta_data(self::META);
        } else {
            $product->update_meta_data(self::META, $channel);
        }

        if ($channel === self::POS_ONLY) {
            if ($was !== self::POS_ONLY) {
                $product->update_meta_data(self::META_PREVIOUS_VISIBILITY, (string) $product->get_catalog_visibility());
            }
            $product->set_catalog_visibility('hidden');
        } elseif ($was === self::POS_ONLY) {
            $previous = (string) $product->get_meta(self::META_PREVIOUS_VISIBILITY);
            $product->set_catalog_visibility(in_array($previous, array('visible', 'catalog', 'search', 'hidden'), true) ? $previous : 'visible');
            $product->delete_meta_data(self::META_PREVIOUS_VISIBILITY);
        }
    }

    /**
     * The online cart and checkout refuse Tap to Pay only products. Orders
     * made by the app are built directly, so they don't pass through here.
     */
    public function filter_purchasable($purchasable, $product) {
        return $purchasable && !self::online_stopped($product);
    }

    // ── Product editor ────────────────────────────────────────────────

    public function render_field() {
        global $product_object;
        $value = is_object($product_object) ? self::clean($product_object->get_meta(self::META)) : self::ONLINE;
        echo '<div class="options_group">';
        woocommerce_wp_select(array(
            'id'          => self::META,
            'label'       => __('Sales channel', 'azure-plugin'),
            'options'     => self::labels(),
            'value'       => $value,
            'desc_tip'    => true,
            'description' => __('Tap to Pay sells it from volunteers\' phones at events in the PTSA Board app. "Tap to Pay only" stops online sales: the product stays published but is hidden from the shop and can\'t be added to an online cart. Products that ask the buyer for details (such as a child\'s name) can\'t be sold with Tap to Pay.', 'azure-plugin'),
        ));
        echo '</div>';
    }

    public function save_field($product) {
        if (!isset($_POST[self::META])) {
            return;
        }
        self::apply($product, sanitize_key(wp_unslash($_POST[self::META])));
    }

    public function render_bulk_field() {
        echo '<div class="inline-edit-group"><label class="alignleft"><span class="title">' . esc_html__('Sales channel', 'azure-plugin') . '</span><span class="input-text-wrap"><select class="pta_sales_channel" name="pta_sales_channel_bulk">';
        echo '<option value="">' . esc_html__('— No change —', 'azure-plugin') . '</option>';
        foreach (self::labels() as $value => $label) {
            echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></span></label></div>';
    }

    public function save_bulk_field($product) {
        $value = isset($_REQUEST['pta_sales_channel_bulk']) ? sanitize_key(wp_unslash($_REQUEST['pta_sales_channel_bulk'])) : self::NO_CHANGE;
        if ($value === self::NO_CHANGE || !isset(self::labels()[$value])) {
            return;
        }
        self::apply($product, $value);
        $product->save();
    }
}
