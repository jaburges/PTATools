<?php
/**
 * AI Connectors store setup tools: product categories, store-wide
 * attributes (Size, Colour, ...) and their values, and PTA Tools product
 * fields. Nothing here deletes; removing things stays in wp-admin.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AI_Connectors_Catalog_Tools {

    const FIELD_TYPES = array('text', 'email', 'tel', 'number', 'textarea', 'select', 'checkbox');
    const FIELD_SCOPES = array('child', 'parent', 'family', 'order');

    public static function register() {
        $r = 'Azure_AI_Connectors_Registry';
        $P = 'Azure_AI_Connectors_Policy';
        $self = __CLASS__;
        $wc = array('Azure_AI_Connectors_Tools', 'woocommerce_active');
        $product_caps = array('edit_products', 'manage_woocommerce');
        $term_caps = array('manage_product_terms', 'manage_woocommerce');
        $fields_on = array($self, 'product_fields_active');
        $names = array('type' => 'array', 'items' => array('type' => 'string'), 'maxItems' => 100);

        $category_props = array(
            'name'        => array('type' => 'string', 'maxLength' => 200),
            'parent'      => array('type' => 'integer', 'minimum' => 0, 'description' => 'Parent category id; 0 for top level.'),
            'description' => array('type' => 'string', 'maxLength' => 2000),
            'slug'        => array('type' => 'string', 'maxLength' => 200),
        );

        $r::register('pta_list_categories', array(
            'group'        => 'catalog',
            'title'        => 'List product categories',
            'description'  => 'Product categories with ids, parents and product counts.',
            'kind'         => $P::KIND_READ,
            'permission'   => $product_caps,
            'access'       => 'products',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'properties' => array(
                'search' => array('type' => 'string', 'maxLength' => 100),
            )),
            'callback'     => array($self, 'list_categories'),
        ));
        $r::register('pta_list_attributes', array(
            'group'        => 'catalog',
            'title'        => 'List store attributes',
            'description'  => 'Store-wide product attributes (e.g. Size) and their values, with ids.',
            'kind'         => $P::KIND_READ,
            'permission'   => $product_caps,
            'access'       => 'products',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'properties' => array()),
            'callback'     => array($self, 'list_attributes'),
        ));
        $r::register('pta_create_category', array(
            'group'        => 'catalog',
            'title'        => 'Create a product category',
            'description'  => 'Add a product category, optionally under a parent.',
            'kind'         => $P::KIND_CREATE,
            'permission'   => $term_caps,
            'access'       => 'catalog',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'required' => array('name'), 'properties' => $category_props),
            'callback'     => array($self, 'create_category'),
        ));
        $r::register('pta_update_category', array(
            'group'        => 'catalog',
            'title'        => 'Edit a product category',
            'description'  => 'Rename a product category, or change its parent, description or slug. Only the fields you send change.',
            'kind'         => $P::KIND_UPDATE,
            'permission'   => $term_caps,
            'access'       => 'catalog',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'required' => array('category_id'), 'properties' => array_merge(
                array('category_id' => array('type' => 'integer', 'minimum' => 1)),
                $category_props
            )),
            'callback'     => array($self, 'update_category'),
        ));
        $r::register('pta_create_attribute', array(
            'group'        => 'catalog',
            'title'        => 'Create a store attribute',
            'description'  => 'Add a store-wide attribute such as Size or Colour, with its values. Products then use it through pta_set_product_attributes.',
            'kind'         => $P::KIND_CREATE,
            'permission'   => $term_caps,
            'access'       => 'catalog',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'required' => array('name'), 'properties' => array(
                'name'   => array('type' => 'string', 'maxLength' => 100),
                'slug'   => array('type' => 'string', 'maxLength' => 28, 'description' => 'Optional; made from the name.'),
                'values' => $names,
            )),
            'callback'     => array($self, 'create_attribute'),
        ));
        $r::register('pta_update_attribute', array(
            'group'        => 'catalog',
            'title'        => 'Edit a store attribute',
            'description'  => 'Rename a store attribute, add values to it, or rename its values. Values are never removed here.',
            'kind'         => $P::KIND_UPDATE,
            'permission'   => $term_caps,
            'access'       => 'catalog',
            'available'    => $wc,
            'input_schema' => array('type' => 'object', 'required' => array('attribute_id'), 'properties' => array(
                'attribute_id'  => array('type' => 'integer', 'minimum' => 1),
                'name'          => array('type' => 'string', 'maxLength' => 100),
                'add_values'    => $names,
                'rename_values' => array('type' => 'array', 'items' => array('type' => 'object'), 'maxItems' => 100, 'description' => 'Each item: {value_id, name}.'),
            )),
            'callback'     => array($self, 'update_attribute'),
        ));

        $r::register('pta_list_product_fields', array(
            'group'        => 'catalog',
            'title'        => 'List product fields',
            'description'  => 'PTA Tools product field groups (questions asked when buying, e.g. the student\'s teacher), the categories each applies to, and their fields.',
            'kind'         => $P::KIND_READ,
            'permission'   => array('manage_woocommerce'),
            'access'       => 'catalog',
            'available'    => $fields_on,
            'input_schema' => array('type' => 'object', 'properties' => array()),
            'callback'     => array($self, 'list_product_fields'),
        ));
        $r::register('pta_save_product_field_group', array(
            'group'        => 'catalog',
            'title'        => 'Create or edit a product field group',
            'description'  => 'Create a product field group, or edit one by group_id. Its fields are asked on products in its categories. Only the settings you send change; category_ids replaces the list.',
            'kind'         => $P::KIND_CREATE,
            'permission'   => array('manage_woocommerce'),
            'access'       => 'catalog',
            'available'    => $fields_on,
            'input_schema' => array('type' => 'object', 'properties' => array(
                'group_id'     => array('type' => 'integer', 'minimum' => 1, 'description' => 'Leave out to create a group.'),
                'name'         => array('type' => 'string', 'maxLength' => 200),
                'description'  => array('type' => 'string', 'maxLength' => 2000),
                'active'       => array('type' => 'boolean'),
                'category_ids' => array('type' => 'array', 'items' => array('type' => 'integer'), 'maxItems' => 200),
            )),
            'callback'     => array($self, 'save_product_field_group'),
        ));
        $r::register('pta_save_product_field', array(
            'group'        => 'catalog',
            'title'        => 'Create or edit a product field',
            'description'  => 'Add a field to a product field group, or edit one by field_id. Only the settings you send change. A field\'s key is fixed once created, because order reports and saved answers use it.',
            'kind'         => $P::KIND_CREATE,
            'permission'   => array('manage_woocommerce'),
            'access'       => 'catalog',
            'available'    => $fields_on,
            'input_schema' => array('type' => 'object', 'properties' => array(
                'field_id'        => array('type' => 'integer', 'minimum' => 1, 'description' => 'Leave out to create a field.'),
                'group_id'        => array('type' => 'integer', 'minimum' => 1, 'description' => 'Required when creating.'),
                'label'           => array('type' => 'string', 'maxLength' => 200),
                'field_type'      => array('type' => 'string', 'enum' => self::FIELD_TYPES),
                'options'         => $names + array('description' => 'Choices for a select field.'),
                'placeholder'     => array('type' => 'string', 'maxLength' => 200),
                'required'        => array('type' => 'boolean'),
                'scope'           => array('type' => 'string', 'enum' => self::FIELD_SCOPES, 'description' => 'Whose answer it is: child (a student), parent, family, or order (kept on the order only).'),
                'save_to_profile' => array('type' => 'boolean', 'description' => 'Remember the answer for next time.'),
                'field_key'       => array('type' => 'string', 'maxLength' => 64, 'description' => 'Only when creating; made from the label if left out.'),
            )),
            'callback'     => array($self, 'save_product_field'),
        ));
    }

    // ─── Categories ──────────────────────────────────────────────────

    private static function category_to_array($term) {
        return array(
            'id'          => (int) $term->term_id,
            'name'        => $term->name,
            'slug'        => $term->slug,
            'parent'      => (int) $term->parent,
            'description' => $term->description,
            'count'       => (int) $term->count,
        );
    }

    public static function list_categories(array $args) {
        $query = array('taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name');
        if (!empty($args['search'])) {
            $query['search'] = (string) $args['search'];
        }
        $terms = get_terms($query);
        if (is_wp_error($terms)) {
            return $terms;
        }
        return array('categories' => array_map(array(__CLASS__, 'category_to_array'), (array) $terms));
    }

    private static function check_parent($parent, $self_id = 0) {
        if (!$parent) {
            return true;
        }
        if ($parent === $self_id || !get_term($parent, 'product_cat') instanceof WP_Term) {
            return new WP_Error('pta_ai_bad_args', 'No other product category with id ' . $parent . ' to use as the parent.');
        }
        if ($self_id && in_array($self_id, get_ancestors($parent, 'product_cat', 'taxonomy'), true)) {
            return new WP_Error('pta_ai_bad_args', 'A category cannot be moved under one of its own subcategories.');
        }
        return true;
    }

    private static function term_args(array $args) {
        $out = array();
        if (isset($args['parent'])) {
            $out['parent'] = (int) $args['parent'];
        }
        if (isset($args['description'])) {
            $out['description'] = sanitize_textarea_field($args['description']);
        }
        if (isset($args['slug'])) {
            $out['slug'] = sanitize_title($args['slug']);
        }
        if (isset($args['name'])) {
            $out['name'] = sanitize_text_field($args['name']);
        }
        return $out;
    }

    public static function create_category(array $args) {
        $ok = self::check_parent((int) ($args['parent'] ?? 0));
        if (is_wp_error($ok)) {
            return $ok;
        }
        $term_args = self::term_args($args);
        unset($term_args['name']);
        $result = wp_insert_term(sanitize_text_field($args['name']), 'product_cat', $term_args);
        if (is_wp_error($result)) {
            return $result;
        }
        return self::category_to_array(get_term((int) $result['term_id'], 'product_cat'));
    }

    public static function update_category(array $args) {
        $id = (int) $args['category_id'];
        if (!get_term($id, 'product_cat') instanceof WP_Term) {
            return new WP_Error('pta_ai_not_found', 'No product category with that id.');
        }
        $term_args = self::term_args($args);
        if (!$term_args) {
            return new WP_Error('pta_ai_nothing', 'Send at least one field to change.');
        }
        if (isset($term_args['parent'])) {
            $ok = self::check_parent($term_args['parent'], $id);
            if (is_wp_error($ok)) {
                return $ok;
            }
        }
        $result = wp_update_term($id, 'product_cat', $term_args);
        if (is_wp_error($result)) {
            return $result;
        }
        return self::category_to_array(get_term($id, 'product_cat'));
    }

    // ─── Store attributes ────────────────────────────────────────────

    private static function attribute_to_array($attribute) {
        $taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);
        $values = array();
        if (taxonomy_exists($taxonomy)) {
            foreach ((array) get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false)) as $term) {
                if ($term instanceof WP_Term) {
                    $values[] = array('value_id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug);
                }
            }
        }
        return array(
            'attribute_id' => (int) $attribute->attribute_id,
            'name'         => (string) $attribute->attribute_label,
            'slug'         => (string) $attribute->attribute_name,
            'taxonomy'     => $taxonomy,
            'values'       => $values,
        );
    }

    private static function find_attribute($id) {
        foreach (wc_get_attribute_taxonomies() as $attribute) {
            if ((int) $attribute->attribute_id === (int) $id) {
                return $attribute;
            }
        }
        return null;
    }

    public static function list_attributes(array $args) {
        return array('attributes' => array_values(array_map(array(__CLASS__, 'attribute_to_array'), wc_get_attribute_taxonomies())));
    }

    /** A new attribute's taxonomy is only registered on the next request; values need it now. */
    private static function ensure_taxonomy($taxonomy) {
        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, array('product'), array('hierarchical' => false, 'show_ui' => false, 'query_var' => false, 'rewrite' => false));
        }
    }

    /** @return string[] Error messages for values that could not be added. */
    private static function add_values($taxonomy, array $values) {
        $errors = array();
        foreach ($values as $value) {
            $value = sanitize_text_field((string) $value);
            if ($value === '' || term_exists($value, $taxonomy)) {
                continue;
            }
            $result = wp_insert_term($value, $taxonomy);
            if (is_wp_error($result)) {
                $errors[] = $value . ': ' . $result->get_error_message();
            }
        }
        return $errors;
    }

    public static function create_attribute(array $args) {
        $id = wc_create_attribute(array(
            'name'         => sanitize_text_field($args['name']),
            'slug'         => isset($args['slug']) ? (string) $args['slug'] : '',
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false,
        ));
        if (is_wp_error($id)) {
            return $id;
        }
        delete_transient('wc_attribute_taxonomies');
        $attribute = wc_get_attribute($id);
        $taxonomy = $attribute ? $attribute->slug : '';
        $errors = array();
        if ($taxonomy !== '' && !empty($args['values'])) {
            self::ensure_taxonomy($taxonomy);
            $errors = self::add_values($taxonomy, $args['values']);
        }
        $out = self::attribute_to_array(self::find_attribute($id) ?: (object) array(
            'attribute_id' => $id, 'attribute_label' => $args['name'], 'attribute_name' => preg_replace('/^pa_/', '', $taxonomy),
        ));
        if ($errors) {
            $out['errors'] = $errors;
        }
        return $out;
    }

    public static function update_attribute(array $args) {
        $attribute = self::find_attribute((int) $args['attribute_id']);
        if (!$attribute) {
            return new WP_Error('pta_ai_not_found', 'No store attribute with that id.');
        }
        if (!isset($args['name']) && empty($args['add_values']) && empty($args['rename_values'])) {
            return new WP_Error('pta_ai_nothing', 'Send name, add_values or rename_values.');
        }
        $taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);
        if (isset($args['name'])) {
            $result = wc_update_attribute((int) $attribute->attribute_id, array('name' => sanitize_text_field($args['name'])));
            if (is_wp_error($result)) {
                return $result;
            }
            delete_transient('wc_attribute_taxonomies');
        }
        self::ensure_taxonomy($taxonomy);
        $errors = self::add_values($taxonomy, (array) ($args['add_values'] ?? array()));
        foreach ((array) ($args['rename_values'] ?? array()) as $i => $item) {
            $item = (array) $item;
            $term_id = (int) ($item['value_id'] ?? 0);
            $name = sanitize_text_field((string) ($item['name'] ?? ''));
            if (!$term_id || $name === '' || !get_term($term_id, $taxonomy) instanceof WP_Term) {
                $errors[] = "rename_values[{$i}]: needs the value_id of one of this attribute's values and a new name.";
                continue;
            }
            $result = wp_update_term($term_id, $taxonomy, array('name' => $name));
            if (is_wp_error($result)) {
                $errors[] = $name . ': ' . $result->get_error_message();
            }
        }
        $out = self::attribute_to_array(self::find_attribute((int) $attribute->attribute_id) ?: $attribute);
        if ($errors) {
            $out['errors'] = $errors;
        }
        return $out;
    }

    // ─── Product fields ──────────────────────────────────────────────

    public static function product_fields_active() {
        return Azure_AI_Connectors_Tools::woocommerce_active()
            && class_exists('Azure_Settings') && Azure_Settings::is_module_enabled('product_fields');
    }

    private static function load_product_fields() {
        foreach (array('class-product-fields-module.php', 'class-product-fields-admin.php') as $file) {
            $path = AZURE_PLUGIN_PATH . 'includes/' . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
        return class_exists('Azure_Product_Fields_Admin') && class_exists('Azure_Database');
    }

    private static function tables() {
        return array(
            'groups' => Azure_Database::get_table_name('product_field_groups'),
            'fields' => Azure_Database::get_table_name('product_fields'),
            'cats'   => Azure_Database::get_table_name('product_field_categories'),
        );
    }

    private static function field_to_array($row) {
        $options = json_decode((string) $row->options_json, true);
        return array(
            'field_id'        => (int) $row->id,
            'group_id'        => (int) $row->group_id,
            'label'           => (string) $row->label,
            'field_key'       => (string) $row->field_key,
            'field_type'      => (string) $row->field_type,
            'options'         => is_array($options) ? $options : array(),
            'placeholder'     => (string) $row->placeholder,
            'required'        => (bool) $row->required,
            'scope'           => (string) $row->scope,
            'save_to_profile' => (bool) $row->save_to_profile,
        );
    }

    private static function group_to_array($group) {
        global $wpdb;
        $t = self::tables();
        $fields = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['fields']} WHERE group_id = %d ORDER BY sort_order ASC", $group->id));
        $categories = array();
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT term_id FROM {$t['cats']} WHERE group_id = %d", $group->id)) as $term_id) {
            $term = get_term((int) $term_id, 'product_cat');
            $categories[] = array('id' => (int) $term_id, 'name' => $term instanceof WP_Term ? $term->name : '');
        }
        return array(
            'group_id'    => (int) $group->id,
            'name'        => (string) $group->name,
            'description' => (string) $group->description,
            'active'      => (bool) $group->is_active,
            'categories'  => $categories,
            'fields'      => array_map(array(__CLASS__, 'field_to_array'), (array) $fields),
        );
    }

    private static function get_group($id) {
        global $wpdb;
        $t = self::tables();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['groups']} WHERE id = %d", $id));
    }

    public static function list_product_fields(array $args) {
        if (!self::load_product_fields()) {
            return new WP_Error('pta_ai_unavailable', 'Product fields are not available.');
        }
        global $wpdb;
        $t = self::tables();
        $groups = $wpdb->get_results("SELECT * FROM {$t['groups']} ORDER BY name ASC");
        return array('groups' => array_map(array(__CLASS__, 'group_to_array'), (array) $groups));
    }

    public static function save_product_field_group(array $args) {
        if (!self::load_product_fields()) {
            return new WP_Error('pta_ai_unavailable', 'Product fields are not available.');
        }
        global $wpdb;
        $t = self::tables();
        $in = array('id' => 0, 'name' => '', 'description' => '', 'is_active' => 1, 'categories' => array());
        if (!empty($args['group_id'])) {
            $group = self::get_group((int) $args['group_id']);
            if (!$group) {
                return new WP_Error('pta_ai_not_found', 'No product field group with that id.');
            }
            $in = array(
                'id'          => (int) $group->id,
                'name'        => $group->name,
                'description' => $group->description,
                'is_active'   => (int) $group->is_active,
                'categories'  => array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT term_id FROM {$t['cats']} WHERE group_id = %d", $group->id))),
            );
        } elseif (empty($args['name'])) {
            return new WP_Error('pta_ai_bad_args', 'A new group needs a name.');
        }
        foreach (array('name' => 'name', 'description' => 'description') as $from => $to) {
            if (isset($args[$from])) {
                $in[$to] = $args[$from];
            }
        }
        if (isset($args['active'])) {
            $in['is_active'] = $args['active'] ? 1 : 0;
        }
        if (isset($args['category_ids'])) {
            foreach ($args['category_ids'] as $term_id) {
                if (!get_term((int) $term_id, 'product_cat') instanceof WP_Term) {
                    return new WP_Error('pta_ai_bad_args', 'No product category with id ' . (int) $term_id . '.');
                }
            }
            $in['categories'] = $args['category_ids'];
        }
        $id = Azure_Product_Fields_Admin::save_group($in);
        if (is_wp_error($id)) {
            return $id;
        }
        return self::group_to_array(self::get_group($id));
    }

    public static function save_product_field(array $args) {
        if (!self::load_product_fields()) {
            return new WP_Error('pta_ai_unavailable', 'Product fields are not available.');
        }
        global $wpdb;
        $t = self::tables();
        $in = array();
        if (!empty($args['field_id'])) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['fields']} WHERE id = %d", (int) $args['field_id']));
            if (!$row) {
                return new WP_Error('pta_ai_not_found', 'No product field with that id.');
            }
            $current = self::field_to_array($row);
            $in = array(
                'id'              => (int) $row->id,
                'group_id'        => (int) $row->group_id,
                'label'           => $current['label'],
                'field_type'      => $current['field_type'],
                'options'         => implode("\n", $current['options']),
                'placeholder'     => $current['placeholder'],
                'required'        => (int) $current['required'],
                'scope'           => $current['scope'],
                'save_to_profile' => (int) $current['save_to_profile'],
                'user_meta_key'   => (string) $row->user_meta_key,
            );
        } else {
            if (empty($args['group_id']) || empty($args['label'])) {
                return new WP_Error('pta_ai_bad_args', 'A new field needs a group_id and a label.');
            }
            if (isset($args['field_key'])) {
                $in['field_key'] = $args['field_key'];
            }
        }
        if (isset($args['group_id']) && !self::get_group((int) $args['group_id'])) {
            return new WP_Error('pta_ai_bad_args', 'No product field group with id ' . (int) $args['group_id'] . '.');
        }
        foreach (array('group_id', 'label', 'field_type', 'placeholder', 'scope') as $key) {
            if (isset($args[$key])) {
                $in[$key] = $args[$key];
            }
        }
        foreach (array('required', 'save_to_profile') as $key) {
            if (isset($args[$key])) {
                $in[$key] = $args[$key] ? 1 : 0;
            }
        }
        if (isset($args['options'])) {
            $in['options'] = implode("\n", array_map('sanitize_text_field', $args['options']));
        }
        if (($in['field_type'] ?? 'text') === 'select' && trim((string) ($in['options'] ?? '')) === '') {
            return new WP_Error('pta_ai_bad_args', 'A select field needs options.');
        }
        $result = Azure_Product_Fields_Admin::save_field($in);
        if (is_wp_error($result)) {
            return $result;
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['fields']} WHERE id = %d", $result['id']));
        return $row ? self::field_to_array($row) : $result;
    }
}
