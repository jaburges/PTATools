<?php
/**
 * AI Connectors product tools: list, read, create (as drafts) and edit
 * products, including type, tax, shipping, categories, attributes and
 * variations. Changes go through WooCommerce's product objects, the same
 * as saving in wp-admin.
 *
 * Only WooCommerce's own product types can be created or switched between.
 * Tickets, classes and auctions carry settings that only their wp-admin
 * screens set up, so their type is left alone.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AI_Connectors_Store_Tools {

    const CORE_TYPES = array('simple', 'variable', 'grouped', 'external');
    const CREATE_STATUSES = array('draft', 'pending');
    const STOCK_STATUSES = array('instock', 'outofstock', 'onbackorder');

    /** Fields a variation accepts; products accept these and the rest. */
    const VARIATION_FIELDS = array(
        'description', 'sku', 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status',
        'tax_status', 'tax_class', 'shipping_class', 'weight', 'length', 'width', 'height', 'virtual', 'image_id',
    );

    public static function register() {
        $r = 'Azure_AI_Connectors_Registry';
        $P = 'Azure_AI_Connectors_Policy';
        $self = __CLASS__;
        $wc = array('Azure_AI_Connectors_Tools', 'woocommerce_active');
        $caps = array('edit_products', 'manage_woocommerce');
        $int_list = function ($max, $description = '') {
            $prop = array('type' => 'array', 'items' => array('type' => 'integer'), 'maxItems' => $max);
            if ($description !== '') {
                $prop['description'] = $description;
            }
            return $prop;
        };

        $common = array(
            'description'    => array('type' => 'string', 'description' => 'HTML allowed.'),
            'sku'            => array('type' => 'string', 'maxLength' => 100),
            'regular_price'  => array('type' => 'string', 'maxLength' => 20, 'description' => 'e.g. "12.50"; "" clears it.'),
            'sale_price'     => array('type' => 'string', 'maxLength' => 20, 'description' => '"" clears the sale price.'),
            'manage_stock'   => array('type' => 'boolean'),
            'stock_quantity' => array('type' => 'integer', 'minimum' => 0),
            'stock_status'   => array('type' => 'string', 'enum' => self::STOCK_STATUSES),
            'tax_status'     => array('type' => 'string', 'enum' => array('taxable', 'shipping', 'none')),
            'tax_class'      => array('type' => 'string', 'maxLength' => 100, 'description' => 'Tax class slug from pta_store_options; "" is Standard.'),
            'shipping_class' => array('type' => 'string', 'maxLength' => 200, 'description' => 'Shipping class slug from pta_store_options; "" for none.'),
            'weight'         => array('type' => 'string', 'maxLength' => 20),
            'length'         => array('type' => 'string', 'maxLength' => 20),
            'width'          => array('type' => 'string', 'maxLength' => 20),
            'height'         => array('type' => 'string', 'maxLength' => 20),
            'virtual'        => array('type' => 'boolean', 'description' => 'Not shipped (tickets, donations, classes).'),
        );
        $product_props = array_merge(array(
            'name'              => array('type' => 'string', 'maxLength' => 300),
            'short_description' => array('type' => 'string'),
        ), $common, array(
            'category_ids'       => $int_list(50, 'Replaces the product\'s categories. Ids from pta_list_categories.'),
            'catalog_visibility' => array('type' => 'string', 'enum' => array('visible', 'catalog', 'search', 'hidden')),
            'featured'           => array('type' => 'boolean'),
            'image_ids'          => $int_list(20, 'Media library ids; the first is the main image, the rest the gallery.'),
            'product_url'        => array('type' => 'string', 'maxLength' => 2000, 'description' => 'External products only.'),
            'button_text'        => array('type' => 'string', 'maxLength' => 100, 'description' => 'External products only.'),
            'grouped_ids'        => $int_list(100, 'Grouped products only: the products in the group.'),
        ));
        $variation_props = array_merge($common, array(
            'image_id'   => array('type' => 'integer', 'minimum' => 0, 'description' => 'Media library id; 0 removes it.'),
            'enabled'    => array('type' => 'boolean', 'description' => 'Whether customers can buy this variation. Default true.'),
            'attributes' => array('type' => 'object', 'description' => 'Attribute name to value, e.g. {"Size": "Large"}. Leave an attribute out (or send "") for "any".'),
        ));

        $r::register('pta_list_products', array(
            'group'        => 'store',
            'title'        => 'List products',
            'description'  => 'Store products, newest first, with price, stock and status.',
            'kind'         => $P::KIND_READ,
            'permission'   => $caps,
            'access'       => 'products',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'properties' => array(
                'search'   => array('type' => 'string', 'maxLength' => 200),
                'status'   => array('type' => 'string', 'enum' => array('any', 'publish', 'draft', 'pending', 'private')),
                'type'     => array('type' => 'string', 'maxLength' => 40, 'description' => 'Product type, e.g. simple, variable.'),
                'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100),
                'page'     => array('type' => 'integer', 'minimum' => 1),
            )),
            'callback'     => array($self, 'list_products'),
        ));
        $r::register('pta_get_product', array(
            'group'        => 'store',
            'title'        => 'Get a product',
            'description'  => 'One product by id: description, prices, stock, type, categories, tax, shipping, attributes and variation ids.',
            'kind'         => $P::KIND_READ,
            'permission'   => $caps,
            'access'       => 'products',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'required' => array('id'), 'properties' => array(
                'id' => array('type' => 'integer', 'minimum' => 1),
            )),
            'callback'     => array($self, 'get_product'),
        ));
        $r::register('pta_store_options', array(
            'group'        => 'store',
            'title'        => 'Store options for products',
            'description'  => 'Values products can use: product types, tax classes, shipping classes and stock statuses.',
            'kind'         => $P::KIND_READ,
            'permission'   => $caps,
            'access'       => 'products',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'properties' => array()),
            'callback'     => array($self, 'store_options'),
        ));
        $r::register('pta_create_product', array(
            'group'        => 'store',
            'title'        => 'Create a product',
            'description'  => 'Create a product as a draft (or pending review). It is never published straight away: once the user has checked it, publish it with pta_update_product. For a variable product, add attributes with pta_set_product_attributes and then variations.',
            'kind'         => $P::KIND_CREATE,
            'permission'   => $caps,
            'access'       => 'products',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'required' => array('name'), 'properties' => array_merge($product_props, array(
                'type'   => array('type' => 'string', 'enum' => self::CORE_TYPES, 'description' => 'Default simple.'),
                'status' => array('type' => 'string', 'enum' => self::CREATE_STATUSES, 'description' => 'draft (default) or pending.'),
            ))),
            'callback'     => array($self, 'create_product'),
        ));
        $r::register('pta_update_product', array(
            'group'        => 'store',
            'title'        => 'Update a product',
            'description'  => 'Change a product: name, descriptions, SKU, prices, stock, tax, shipping, dimensions, categories, images, visibility, type or status (publish to publish, draft to unpublish). Only the fields you send change.',
            'kind'         => $P::KIND_UPDATE,
            'permission'   => $caps,
            'access'       => 'products',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'required' => array('id'), 'properties' => array_merge(
                array('id' => array('type' => 'integer', 'minimum' => 1)),
                $product_props,
                array(
                    'type'   => array('type' => 'string', 'enum' => self::CORE_TYPES, 'description' => 'Switch between simple, variable, grouped and external. Ticket, class and auction products cannot be switched.'),
                    'status' => array('type' => 'string', 'enum' => Azure_AI_Connectors_Tools::EDITABLE_STATUSES),
                )
            )),
            'callback'     => array($self, 'update_product'),
        ));
        $r::register('pta_set_product_attributes', array(
            'group'        => 'store',
            'title'        => 'Set a product\'s attributes',
            'description'  => 'Replace the attributes on a product, e.g. Size: Small, Medium, Large. Use attribute_id for a store-wide attribute from pta_list_attributes (its values must already exist), or name for one used only by this product. Set used_for_variations on a variable product to make variations from it.',
            'kind'         => $P::KIND_UPDATE,
            'permission'   => $caps,
            'access'       => 'products',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'required' => array('product_id', 'attributes'), 'properties' => array(
                'product_id' => array('type' => 'integer', 'minimum' => 1),
                'attributes' => array(
                    'type'        => 'array',
                    'items'       => array('type' => 'object'),
                    'maxItems'    => 20,
                    'description' => 'Each item: {attribute_id or name, options: [values], visible: bool, used_for_variations: bool}. A name that matches a store attribute (pta_list_attributes) uses it, and its options must already exist there; any other name makes an attribute for this product only.',
                ),
            )),
            'callback'     => array($self, 'set_product_attributes'),
        ));
        $r::register('pta_list_variations', array(
            'group'        => 'store',
            'title'        => 'List a product\'s variations',
            'description'  => 'Variations of a variable product with their attributes, prices and stock.',
            'kind'         => $P::KIND_READ,
            'permission'   => $caps,
            'access'       => 'products',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'required' => array('product_id'), 'properties' => array(
                'product_id' => array('type' => 'integer', 'minimum' => 1),
            )),
            'callback'     => array($self, 'list_variations'),
        ));
        $r::register('pta_create_variation', array(
            'group'        => 'store',
            'title'        => 'Add a variation',
            'description'  => 'Add a variation to a variable product, e.g. {"Size": "Large"} at "15.00". The product needs attributes marked used_for_variations first.',
            'kind'         => $P::KIND_CREATE,
            'permission'   => $caps,
            'access'       => 'products',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'required' => array('product_id'), 'properties' => array_merge(
                array('product_id' => array('type' => 'integer', 'minimum' => 1)),
                $variation_props
            )),
            'callback'     => array($self, 'create_variation'),
        ));
        $r::register('pta_update_variation', array(
            'group'        => 'store',
            'title'        => 'Update a variation',
            'description'  => 'Change a variation\'s attributes, price, stock, shipping or whether it can be bought. Only the fields you send change.',
            'kind'         => $P::KIND_UPDATE,
            'permission'   => $caps,
            'access'       => 'products',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'required' => array('variation_id'), 'properties' => array_merge(
                array('variation_id' => array('type' => 'integer', 'minimum' => 1)),
                $variation_props
            )),
            'callback'     => array($self, 'update_variation'),
        ));
    }

    // ─── Reading ─────────────────────────────────────────────────────

    public static function list_products(array $args) {
        $data = Azure_AI_Connectors_Tools::ptsa_call('list_products', array(
            'search'   => (string) ($args['search'] ?? ''),
            'status'   => (string) ($args['status'] ?? 'any'),
            'type'     => (string) ($args['type'] ?? ''),
            'per_page' => (int) ($args['per_page'] ?? 20),
            'page'     => (int) ($args['page'] ?? 1),
        ));
        return is_wp_error($data) ? $data : array('products' => array_values((array) $data));
    }

    public static function get_product(array $args) {
        $product = wc_get_product((int) $args['id']);
        if (!$product || $product->is_type('variation')) {
            return new WP_Error('pta_ai_not_found', 'No product with that id.');
        }
        return self::product_details($product);
    }

    public static function store_options(array $args) {
        $types = array();
        foreach (wc_get_product_types() as $slug => $label) {
            $types[] = array('type' => (string) $slug, 'label' => wp_strip_all_tags((string) $label), 'can_create' => in_array($slug, self::CORE_TYPES, true));
        }
        $tax = array(array('slug' => '', 'name' => 'Standard'));
        foreach (WC_Tax::get_tax_classes() as $name) {
            $tax[] = array('slug' => sanitize_title($name), 'name' => (string) $name);
        }
        $shipping = array();
        foreach ((array) get_terms(array('taxonomy' => 'product_shipping_class', 'hide_empty' => false)) as $term) {
            if ($term instanceof WP_Term) {
                $shipping[] = array('slug' => $term->slug, 'name' => $term->name);
            }
        }
        return array(
            'product_types'    => $types,
            'tax_classes'      => $tax,
            'shipping_classes' => $shipping,
            'stock_statuses'   => self::STOCK_STATUSES,
            'currency'         => get_woocommerce_currency(),
            'prices_include_tax' => wc_prices_include_tax(),
        );
    }

    public static function product_details($p) {
        $api = Azure_AI_Connectors_Tools::ptsa();
        $base = $api ? (array) $api->product_to_array($p) : array('id' => (int) $p->get_id(), 'name' => $p->get_name(), 'type' => $p->get_type(), 'status' => $p->get_status());
        $categories = array();
        foreach ((array) $p->get_category_ids() as $term_id) {
            $term = get_term((int) $term_id, 'product_cat');
            if ($term instanceof WP_Term) {
                $categories[] = array('id' => (int) $term->term_id, 'name' => $term->name);
            }
        }
        $details = array(
            'description'        => $p->get_description(),
            'categories'         => $categories,
            'tax_status'         => $p->get_tax_status(),
            'tax_class'          => $p->get_tax_class(),
            'shipping_class'     => $p->get_shipping_class(),
            'weight'             => $p->get_weight(),
            'length'             => $p->get_length(),
            'width'              => $p->get_width(),
            'height'             => $p->get_height(),
            'virtual'            => (bool) $p->get_virtual(),
            'stock_status'       => $p->get_stock_status(),
            'catalog_visibility' => $p->get_catalog_visibility(),
            'featured'           => (bool) $p->get_featured(),
            'attributes'         => self::attributes_to_array($p),
            'variation_ids'      => $p->is_type('variable') ? array_map('intval', $p->get_children()) : array(),
            'edit_link'          => admin_url('post.php?post=' . (int) $p->get_id() . '&action=edit'),
        );
        if ($p->is_type('external')) {
            $details['product_url'] = $p->get_product_url();
            $details['button_text'] = $p->get_button_text();
        }
        if ($p->is_type('grouped')) {
            $details['grouped_ids'] = array_map('intval', $p->get_children());
        }
        return $base + $details;
    }

    private static function attributes_to_array($p) {
        $out = array();
        foreach ($p->get_attributes() as $key => $attr) {
            if (!$attr instanceof WC_Product_Attribute) {
                continue;
            }
            if ($attr->is_taxonomy()) {
                $options = array();
                foreach ($attr->get_options() as $term_id) {
                    $term = get_term((int) $term_id, $attr->get_name());
                    if ($term instanceof WP_Term) {
                        $options[] = $term->name;
                    }
                }
                $name = wc_attribute_label($attr->get_name());
            } else {
                $options = array_values($attr->get_options());
                $name = $attr->get_name();
            }
            $out[] = array(
                'attribute_id'        => (int) $attr->get_id(),
                'name'                => (string) $name,
                'key'                 => (string) $key,
                'options'             => $options,
                'visible'             => (bool) $attr->get_visible(),
                'used_for_variations' => (bool) $attr->get_variation(),
            );
        }
        return $out;
    }

    private static function variation_to_array($v) {
        $parent = wc_get_product($v->get_parent_id());
        $labels = array();
        if ($parent) {
            foreach ($parent->get_attributes() as $key => $attr) {
                $labels[$key] = $attr->is_taxonomy() ? wc_attribute_label($attr->get_name()) : $attr->get_name();
            }
        }
        $attributes = array();
        foreach ($v->get_attributes() as $key => $value) {
            $label = $labels[$key] ?? $key;
            if ($value !== '' && taxonomy_exists($key)) {
                $term = get_term_by('slug', $value, $key);
                $value = $term ? $term->name : $value;
            }
            $attributes[$label] = $value === '' ? 'any' : $value;
        }
        return array(
            'id'             => (int) $v->get_id(),
            'product_id'     => (int) $v->get_parent_id(),
            'attributes'     => $attributes,
            'enabled'        => $v->get_status() === 'publish',
            'sku'            => $v->get_sku(),
            'regular_price'  => $v->get_regular_price(),
            'sale_price'     => $v->get_sale_price(),
            'price'          => $v->get_price(),
            'manage_stock'   => $v->get_manage_stock() === true,
            'stock_quantity' => $v->get_stock_quantity(),
            'stock_status'   => $v->get_stock_status(),
            'tax_class'      => $v->get_tax_class(),
            'shipping_class' => $v->get_shipping_class(),
            'weight'         => $v->get_weight(),
            'virtual'        => (bool) $v->get_virtual(),
            'image_id'       => (int) $v->get_image_id(),
            'description'    => $v->get_description(),
        );
    }

    public static function list_variations(array $args) {
        $p = self::editable_product((int) $args['product_id']);
        if (is_wp_error($p)) {
            return $p;
        }
        if (!$p->is_type('variable')) {
            return new WP_Error('pta_ai_bad_args', 'That product is not a variable product.');
        }
        $out = array();
        foreach ($p->get_children() as $id) {
            $v = wc_get_product($id);
            if ($v) {
                $out[] = self::variation_to_array($v);
            }
        }
        return array('product_id' => (int) $p->get_id(), 'variations' => $out);
    }

    // ─── Writing ─────────────────────────────────────────────────────

    private static function editable_product($id) {
        $p = wc_get_product((int) $id);
        if (!$p || $p->is_type('variation')) {
            return new WP_Error('pta_ai_not_found', 'No product with that id.');
        }
        if (!current_user_can('edit_post', $p->get_id())) {
            return new WP_Error('pta_ai_forbidden', 'You cannot edit that product.');
        }
        return $p;
    }

    private static function can_publish_products() {
        return current_user_can('publish_products') || current_user_can('manage_woocommerce');
    }

    private static function decimal_or_blank($key, $value) {
        $value = trim((string) $value);
        if ($value !== '' && !is_numeric($value)) {
            return new WP_Error('pta_ai_bad_args', $key . ' must be a number such as 12.50, or "" to clear it.');
        }
        return $value === '' ? '' : wc_format_decimal($value);
    }

    /**
     * Set fields on a product or variation. Only keys present in $args are
     * touched.
     *
     * @return true|WP_Error
     */
    public static function apply_fields($p, array $args) {
        foreach (array('name' => 'set_name') as $key => $setter) {
            if (isset($args[$key])) {
                $p->$setter(sanitize_text_field($args[$key]));
            }
        }
        foreach (array('description' => 'set_description', 'short_description' => 'set_short_description') as $key => $setter) {
            if (isset($args[$key])) {
                $p->$setter(wp_kses_post($args[$key]));
            }
        }
        foreach (array('regular_price' => 'set_regular_price', 'sale_price' => 'set_sale_price', 'weight' => 'set_weight', 'length' => 'set_length', 'width' => 'set_width', 'height' => 'set_height') as $key => $setter) {
            if (!isset($args[$key])) {
                continue;
            }
            $value = self::decimal_or_blank($key, $args[$key]);
            if (is_wp_error($value)) {
                return $value;
            }
            $p->$setter($value);
        }
        if (isset($args['regular_price']) || isset($args['sale_price'])) {
            $regular = (string) $p->get_regular_price('edit');
            $sale = (string) $p->get_sale_price('edit');
            if ($sale !== '' && $regular !== '' && (float) $sale >= (float) $regular) {
                return new WP_Error('pta_ai_bad_args', 'The sale price must be lower than the regular price (' . $regular . ').');
            }
        }
        if (isset($args['sku'])) {
            $p->set_sku(sanitize_text_field($args['sku']));
        }
        if (isset($args['manage_stock'])) {
            $p->set_manage_stock((bool) $args['manage_stock']);
        }
        if (isset($args['stock_quantity'])) {
            $p->set_stock_quantity((int) $args['stock_quantity']);
        }
        if (isset($args['stock_status'])) {
            $p->set_stock_status($args['stock_status']);
        }
        if (isset($args['tax_status'])) {
            $p->set_tax_status($args['tax_status']);
        }
        if (isset($args['tax_class'])) {
            $slug = sanitize_title($args['tax_class']);
            $valid = array_merge(array(''), WC_Tax::get_tax_class_slugs());
            if ($p->is_type('variation')) {
                $valid[] = 'parent';
            }
            if (!in_array($slug, $valid, true)) {
                return new WP_Error('pta_ai_bad_args', 'Unknown tax class "' . $args['tax_class'] . '". pta_store_options lists them.');
            }
            $p->set_tax_class($slug);
        }
        if (isset($args['shipping_class'])) {
            $class_id = 0;
            if ($args['shipping_class'] !== '') {
                $term = get_term_by('slug', sanitize_title($args['shipping_class']), 'product_shipping_class');
                if (!$term) {
                    return new WP_Error('pta_ai_bad_args', 'Unknown shipping class "' . $args['shipping_class'] . '". pta_store_options lists them.');
                }
                $class_id = (int) $term->term_id;
            }
            $p->set_shipping_class_id($class_id);
        }
        if (isset($args['virtual'])) {
            $p->set_virtual((bool) $args['virtual']);
        }
        if (isset($args['catalog_visibility'])) {
            $p->set_catalog_visibility($args['catalog_visibility']);
        }
        if (isset($args['featured'])) {
            $p->set_featured((bool) $args['featured']);
        }
        if (isset($args['category_ids'])) {
            foreach ($args['category_ids'] as $term_id) {
                if (!get_term((int) $term_id, 'product_cat') instanceof WP_Term) {
                    return new WP_Error('pta_ai_bad_args', 'No product category with id ' . (int) $term_id . '.');
                }
            }
            $p->set_category_ids(array_map('intval', $args['category_ids']));
        }
        if (isset($args['image_ids'])) {
            foreach ($args['image_ids'] as $id) {
                if (get_post_type((int) $id) !== 'attachment') {
                    return new WP_Error('pta_ai_bad_args', 'No media library item with id ' . (int) $id . '.');
                }
            }
            $ids = array_map('intval', $args['image_ids']);
            $p->set_image_id($ids ? $ids[0] : 0);
            $p->set_gallery_image_ids(array_slice($ids, 1));
        }
        if (isset($args['image_id'])) {
            if ($args['image_id'] && get_post_type((int) $args['image_id']) !== 'attachment') {
                return new WP_Error('pta_ai_bad_args', 'No media library item with id ' . (int) $args['image_id'] . '.');
            }
            $p->set_image_id((int) $args['image_id']);
        }
        foreach (array('product_url' => 'set_product_url', 'button_text' => 'set_button_text') as $key => $setter) {
            if (!isset($args[$key])) {
                continue;
            }
            if (!$p->is_type('external')) {
                return new WP_Error('pta_ai_bad_args', $key . ' only applies to external products.');
            }
            $p->$setter($key === 'product_url' ? esc_url_raw($args[$key]) : sanitize_text_field($args[$key]));
        }
        if (isset($args['grouped_ids'])) {
            if (!$p->is_type('grouped')) {
                return new WP_Error('pta_ai_bad_args', 'grouped_ids only applies to grouped products.');
            }
            $p->set_children(array_map('intval', $args['grouped_ids']));
        }
        return true;
    }

    /** Run a WooCommerce save, turning its exceptions (duplicate SKU, ...) into errors. */
    private static function save($p) {
        try {
            $id = $p->save();
        } catch (Exception $e) {
            return new WP_Error('pta_ai_save_failed', $e->getMessage());
        }
        return $id ? (int) $id : new WP_Error('pta_ai_save_failed', 'WooCommerce did not save the product.');
    }

    /** Apply fields, catching setter exceptions the same way as save(). */
    private static function apply($p, array $args) {
        try {
            return self::apply_fields($p, $args);
        } catch (Exception $e) {
            return new WP_Error('pta_ai_bad_args', $e->getMessage());
        }
    }

    public static function create_product(array $args) {
        $type = in_array($args['type'] ?? '', self::CORE_TYPES, true) ? $args['type'] : 'simple';
        $class = WC_Product_Factory::get_product_classname(0, $type);
        if (!$class || !class_exists($class)) {
            return new WP_Error('pta_ai_unavailable', 'That product type is not available.');
        }
        $p = new $class();
        $status = in_array($args['status'] ?? '', self::CREATE_STATUSES, true) ? $args['status'] : 'draft';
        $p->set_status($status);
        $applied = self::apply($p, $args);
        if (is_wp_error($applied)) {
            return $applied;
        }
        $id = self::save($p);
        if (is_wp_error($id)) {
            return $id;
        }
        return self::product_details(wc_get_product($id)) + array(
            'note' => 'Saved as ' . $status . '. Show the user, then publish with pta_update_product when they are happy.',
        );
    }

    public static function update_product(array $args) {
        $p = self::editable_product((int) $args['id']);
        if (is_wp_error($p)) {
            return $p;
        }
        $patch = $args;
        unset($patch['id']);
        if (empty($patch)) {
            return new WP_Error('pta_ai_nothing', 'Send at least one field to change.');
        }
        $notes = array();
        if (isset($patch['type']) && $patch['type'] !== $p->get_type()) {
            if (!in_array($p->get_type(), self::CORE_TYPES, true)) {
                return new WP_Error('pta_ai_bad_args', 'This is a ' . $p->get_type() . ' product, which is set up in wp-admin; its type cannot be changed here.');
            }
            if ($p->is_type('variable') && $p->get_children()) {
                $notes[] = 'Its variations are kept but hidden while the product is not variable.';
            }
            $class = WC_Product_Factory::get_product_classname($p->get_id(), $patch['type']);
            $p = new $class($p->get_id());
        }
        if (isset($patch['status']) && in_array($patch['status'], array('publish', 'private'), true) && !self::can_publish_products()) {
            return new WP_Error('pta_ai_forbidden', 'You cannot publish products.');
        }
        if (isset($patch['status'])) {
            $p->set_status($patch['status']);
        }
        $applied = self::apply($p, $patch);
        if (is_wp_error($applied)) {
            return $applied;
        }
        $id = self::save($p);
        if (is_wp_error($id)) {
            return $id;
        }
        $out = self::product_details(wc_get_product($id));
        if ($notes) {
            $out['note'] = implode(' ', $notes);
        }
        return $out;
    }

    /**
     * Find a store-wide attribute term by name or slug.
     *
     * @return WP_Term|null
     */
    private static function find_term($taxonomy, $value) {
        $value = trim((string) $value);
        foreach (array('slug' => sanitize_title($value), 'name' => $value) as $field => $needle) {
            $term = get_term_by($field, $needle, $taxonomy);
            if ($term instanceof WP_Term) {
                return $term;
            }
        }
        return null;
    }

    /** Id of the store attribute whose name or slug is $name, or 0. */
    private static function store_attribute_id($name) {
        $name = strtolower(trim($name));
        $slug = sanitize_title($name);
        foreach ((array) wc_get_attribute_taxonomies() as $tax) {
            if (strtolower((string) $tax->attribute_label) === $name || (string) $tax->attribute_name === $slug) {
                return (int) $tax->attribute_id;
            }
        }
        return 0;
    }

    public static function set_product_attributes(array $args) {
        $p = self::editable_product((int) $args['product_id']);
        if (is_wp_error($p)) {
            return $p;
        }
        $list = array();
        foreach (array_values($args['attributes']) as $i => $item) {
            $item = (array) $item;
            $options = isset($item['options']) && is_array($item['options']) ? array_values(array_filter(array_map('trim', array_map('strval', $item['options'])), 'strlen')) : array();
            if (!$options || count($options) > 100) {
                return new WP_Error('pta_ai_bad_args', "attributes[{$i}] needs between 1 and 100 options.");
            }
            $attr = new WC_Product_Attribute();
            $attribute_id = (int) ($item['attribute_id'] ?? 0);
            if ($attribute_id <= 0 && !empty($item['name'])) {
                $attribute_id = self::store_attribute_id((string) $item['name']);
            }
            if ($attribute_id > 0) {
                $taxonomy = wc_attribute_taxonomy_name_by_id($attribute_id);
                if (!$taxonomy || !taxonomy_exists($taxonomy)) {
                    return new WP_Error('pta_ai_bad_args', "attributes[{$i}]: no store attribute with id {$attribute_id}.");
                }
                $term_ids = array();
                $missing = array();
                foreach ($options as $value) {
                    $term = self::find_term($taxonomy, $value);
                    if ($term) {
                        $term_ids[] = (int) $term->term_id;
                    } else {
                        $missing[] = $value;
                    }
                }
                if ($missing) {
                    return new WP_Error('pta_ai_bad_args', 'These values are not set up for ' . wc_attribute_label($taxonomy) . ': ' . implode(', ', $missing) . '. Add them with pta_update_attribute first.');
                }
                $attr->set_id($attribute_id);
                $attr->set_name($taxonomy);
                $attr->set_options($term_ids);
            } else {
                $name = sanitize_text_field((string) ($item['name'] ?? ''));
                if ($name === '') {
                    return new WP_Error('pta_ai_bad_args', "attributes[{$i}] needs an attribute_id or a name.");
                }
                $attr->set_id(0);
                $attr->set_name($name);
                $attr->set_options(array_map('sanitize_text_field', $options));
            }
            $attr->set_position($i);
            $attr->set_visible(!array_key_exists('visible', $item) || !empty($item['visible']));
            $attr->set_variation(!empty($item['used_for_variations']));
            $list[] = $attr;
        }
        $p->set_attributes($list);
        $id = self::save($p);
        if (is_wp_error($id)) {
            return $id;
        }
        return array('product_id' => $id, 'attributes' => self::attributes_to_array(wc_get_product($id)));
    }

    /**
     * Turn {"Size": "Large"} into the variation attribute map WooCommerce
     * stores, checked against the parent's variation attributes. With
     * $only_given, only the attributes named in $input are returned.
     *
     * @return array|WP_Error
     */
    private static function variation_attributes($parent, array $input, $only_given = false) {
        $map = array();
        $lookup = array();
        foreach ($parent->get_attributes() as $key => $attr) {
            if (!$attr->get_variation()) {
                continue;
            }
            $map[$key] = '';
            $label = $attr->is_taxonomy() ? wc_attribute_label($attr->get_name()) : $attr->get_name();
            foreach (array($key, $attr->get_name(), $label) as $alias) {
                $lookup[strtolower((string) $alias)] = $key;
            }
        }
        if (!$map) {
            return new WP_Error('pta_ai_bad_args', 'The product has no attributes used for variations. Set them with pta_set_product_attributes first.');
        }
        $attrs = $parent->get_attributes();
        $given = array();
        foreach ($input as $name => $value) {
            $key = $lookup[strtolower((string) $name)] ?? null;
            if ($key === null) {
                return new WP_Error('pta_ai_bad_args', 'The product has no variation attribute called "' . $name . '".');
            }
            $value = trim((string) $value);
            if ($value === '' || strtolower($value) === 'any') {
                $given[$key] = '';
                continue;
            }
            $attr = $attrs[$key];
            $match = null;
            if ($attr->is_taxonomy()) {
                foreach ($attr->get_options() as $term_id) {
                    $term = get_term((int) $term_id, $attr->get_name());
                    if ($term instanceof WP_Term && (strcasecmp($term->name, $value) === 0 || $term->slug === sanitize_title($value))) {
                        $match = $term->slug;
                        break;
                    }
                }
            } else {
                foreach ($attr->get_options() as $option) {
                    if (strcasecmp((string) $option, $value) === 0) {
                        $match = (string) $option;
                        break;
                    }
                }
            }
            if ($match === null) {
                return new WP_Error('pta_ai_bad_args', '"' . $value . '" is not one of the product\'s values for ' . $name . '.');
            }
            $given[$key] = $match;
        }
        return $only_given ? $given : array_merge($map, $given);
    }

    private static function variation_fields(array $args) {
        return array_intersect_key($args, array_flip(self::VARIATION_FIELDS));
    }

    private static function after_variation_change($parent_id) {
        if (class_exists('WC_Product_Variable')) {
            WC_Product_Variable::sync($parent_id);
        }
        wc_delete_product_transients($parent_id);
    }

    public static function create_variation(array $args) {
        $parent = self::editable_product((int) $args['product_id']);
        if (is_wp_error($parent)) {
            return $parent;
        }
        if (!$parent->is_type('variable')) {
            return new WP_Error('pta_ai_bad_args', 'Variations can only be added to a variable product. Change its type with pta_update_product first.');
        }
        $attributes = self::variation_attributes($parent, (array) ($args['attributes'] ?? array()));
        if (is_wp_error($attributes)) {
            return $attributes;
        }
        $v = new WC_Product_Variation();
        $v->set_parent_id($parent->get_id());
        $v->set_attributes($attributes);
        $v->set_status(!array_key_exists('enabled', $args) || !empty($args['enabled']) ? 'publish' : 'private');
        $applied = self::apply($v, self::variation_fields($args));
        if (is_wp_error($applied)) {
            return $applied;
        }
        $id = self::save($v);
        if (is_wp_error($id)) {
            return $id;
        }
        self::after_variation_change($parent->get_id());
        return self::variation_to_array(wc_get_product($id));
    }

    public static function update_variation(array $args) {
        $v = wc_get_product((int) $args['variation_id']);
        if (!$v || !$v->is_type('variation')) {
            return new WP_Error('pta_ai_not_found', 'No variation with that id.');
        }
        $parent = self::editable_product($v->get_parent_id());
        if (is_wp_error($parent)) {
            return $parent;
        }
        $patch = $args;
        unset($patch['variation_id']);
        if (empty($patch)) {
            return new WP_Error('pta_ai_nothing', 'Send at least one field to change.');
        }
        if (isset($patch['attributes'])) {
            $changes = self::variation_attributes($parent, (array) $patch['attributes'], true);
            if (is_wp_error($changes)) {
                return $changes;
            }
            $v->set_attributes(array_merge((array) $v->get_attributes(), $changes));
        }
        if (isset($patch['enabled'])) {
            $v->set_status($patch['enabled'] ? 'publish' : 'private');
        }
        $applied = self::apply($v, self::variation_fields($patch));
        if (is_wp_error($applied)) {
            return $applied;
        }
        $id = self::save($v);
        if (is_wp_error($id)) {
            return $id;
        }
        self::after_variation_change($parent->get_id());
        return self::variation_to_array(wc_get_product($id));
    }
}
