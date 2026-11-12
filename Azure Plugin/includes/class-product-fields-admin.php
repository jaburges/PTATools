<?php
/**
 * Product Fields Module – Admin AJAX
 *
 * Handles the admin-side CRUD AJAX endpoints for the Product Fields admin
 * screen (groups + fields). Split out of `class-product-fields-module.php`
 * to keep that file focused on the front-end rendering / order pipeline.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Product_Fields_Admin {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('wp_ajax_azure_pf_save_group',     array($this, 'ajax_save_group'));
        add_action('wp_ajax_azure_pf_delete_group',   array($this, 'ajax_delete_group'));
        add_action('wp_ajax_azure_pf_get_group',      array($this, 'ajax_get_group'));
        add_action('wp_ajax_azure_pf_save_field',     array($this, 'ajax_save_field'));
        add_action('wp_ajax_azure_pf_delete_field',   array($this, 'ajax_delete_field'));
        add_action('wp_ajax_azure_pf_reorder_fields', array($this, 'ajax_reorder_fields'));
    }

    // ─── Field Groups ──────────────────────────────────────────────────

    public function ajax_save_group() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $result = self::save_group(array(
            'id'          => intval($_POST['id'] ?? 0),
            'name'        => wp_unslash($_POST['name'] ?? ''),
            'description' => wp_unslash($_POST['description'] ?? ''),
            'is_active'   => intval($_POST['is_active'] ?? 1),
            'categories'  => (array) ($_POST['categories'] ?? array()),
        ));
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        wp_send_json_success(array('id' => $result, 'message' => 'Group saved'));
    }

    /**
     * Create or update a field group and its category assignments.
     *
     * @param array $in id, name, description, is_active, categories (term ids)
     * @return int|WP_Error Group id.
     */
    public static function save_group(array $in) {
        global $wpdb;
        $table = Azure_Database::get_table_name('product_field_groups');
        $cat_table = Azure_Database::get_table_name('product_field_categories');

        if (!$table || $wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            Azure_Database::create_tables();
            $table = Azure_Database::get_table_name('product_field_groups');
            $cat_table = Azure_Database::get_table_name('product_field_categories');
            if (!$table || $wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
                return new WP_Error('azure_pf_no_tables', 'Database tables could not be created. Check error logs.');
            }
        }

        $id          = intval($in['id'] ?? 0);
        $name        = sanitize_text_field((string) ($in['name'] ?? ''));
        $description = sanitize_textarea_field((string) ($in['description'] ?? ''));
        $is_active   = intval($in['is_active'] ?? 1);
        $categories  = array_map('intval', (array) ($in['categories'] ?? array()));

        if (empty($name)) {
            return new WP_Error('azure_pf_invalid', 'Group name is required');
        }

        $data = array(
            'name'        => $name,
            'description' => $description,
            'is_active'   => $is_active,
            'updated_at'  => current_time('mysql'),
        );

        if ($id > 0) {
            $result = $wpdb->update($table, $data, array('id' => $id));
            if ($result === false) {
                return new WP_Error('azure_pf_db', 'DB update failed: ' . $wpdb->last_error);
            }
        } else {
            $data['created_at'] = current_time('mysql');
            $result = $wpdb->insert($table, $data);
            if ($result === false) {
                return new WP_Error('azure_pf_db', 'DB insert failed: ' . $wpdb->last_error);
            }
            $id = $wpdb->insert_id;
        }

        $wpdb->delete($cat_table, array('group_id' => $id));
        foreach ($categories as $term_id) {
            $wpdb->insert($cat_table, array('group_id' => $id, 'term_id' => $term_id));
        }

        return (int) $id;
    }

    public function ajax_delete_group() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error('Invalid group ID');
        }

        $grp_table = Azure_Database::get_table_name('product_field_groups');
        $fld_table = Azure_Database::get_table_name('product_fields');
        $cat_table = Azure_Database::get_table_name('product_field_categories');

        $wpdb->delete($fld_table, array('group_id' => $id));
        $wpdb->delete($cat_table, array('group_id' => $id));
        $wpdb->delete($grp_table, array('id' => $id));

        wp_send_json_success('Group deleted');
    }

    public function ajax_get_group() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $id = intval($_GET['id'] ?? $_POST['id'] ?? 0);

        $grp_table = Azure_Database::get_table_name('product_field_groups');
        $fld_table = Azure_Database::get_table_name('product_fields');
        $cat_table = Azure_Database::get_table_name('product_field_categories');

        $group = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$grp_table} WHERE id = %d", $id));
        if (!$group) {
            wp_send_json_error('Group not found');
        }

        $group->fields = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$fld_table} WHERE group_id = %d ORDER BY sort_order ASC", $id
        ));

        $group->categories = $wpdb->get_col($wpdb->prepare(
            "SELECT term_id FROM {$cat_table} WHERE group_id = %d", $id
        ));

        wp_send_json_success($group);
    }

    // ─── Fields ────────────────────────────────────────────────────────

    public function ajax_save_field() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $in = array();
        foreach (array('id', 'group_id', 'label', 'field_type', 'placeholder', 'required', 'save_to_profile', 'user_meta_key', 'scope', 'field_key', 'options') as $key) {
            if (isset($_POST[$key])) {
                $in[$key] = wp_unslash($_POST[$key]);
            }
        }
        $result = self::save_field($in);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        wp_send_json_success($result + array('message' => 'Field saved'));
    }

    /**
     * Create or update a field. field_key is fixed after the first save.
     *
     * @param array $in id, group_id, label, field_type, placeholder, required,
     *                  save_to_profile, user_meta_key, scope, field_key,
     *                  options (one per line, select fields)
     * @return array{id:int,field_key:string}|WP_Error
     */
    public static function save_field(array $in) {
        global $wpdb;
        $table = Azure_Database::get_table_name('product_fields');

        $id              = intval($in['id'] ?? 0);
        $group_id        = intval($in['group_id'] ?? 0);
        $label           = sanitize_text_field((string) ($in['label'] ?? ''));
        $field_type      = sanitize_text_field((string) ($in['field_type'] ?? 'text'));
        $placeholder     = sanitize_text_field((string) ($in['placeholder'] ?? ''));
        $required        = intval($in['required'] ?? 0);
        $save_to_profile = intval($in['save_to_profile'] ?? 0);
        $user_meta_key   = sanitize_key((string) ($in['user_meta_key'] ?? ''));
        $scope           = sanitize_text_field((string) ($in['scope'] ?? 'child'));
        $requested_key   = sanitize_key((string) ($in['field_key'] ?? ''));
        $options_json    = '';

        if (!array_key_exists($scope, Azure_Product_Fields_Module::scope_labels())) {
            $scope = 'child';
        }
        if ($scope === 'order') {
            $save_to_profile = 0;
            $user_meta_key = '';
        }

        if (empty($label) || !$group_id) {
            return new WP_Error('azure_pf_invalid', 'Label and group are required');
        }

        $valid_types = array('text', 'email', 'tel', 'number', 'textarea', 'select', 'checkbox');
        if (!in_array($field_type, $valid_types)) {
            $field_type = 'text';
        }

        if ($field_type === 'select' && !empty($in['options'])) {
            $options = array_filter(array_map('trim', explode("\n", sanitize_textarea_field((string) $in['options']))));
            $options_json = wp_json_encode(array_values($options));
        }

        $existing = ($id > 0)
            ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id))
            : null;

        // field_key is immutable after first save. New fields derive it from
        // the requested slug, then the label, then a synthesized fallback.
        $field_key = $existing && !empty($existing->field_key)
            ? $existing->field_key
            : ($requested_key !== '' ? $requested_key : sanitize_key($label));
        if ($field_key === '') {
            $field_key = 'field_' . time();
        }
        $field_key = self::ensure_unique_field_key($field_key, $existing ? $existing->id : 0);

        if ($save_to_profile && empty($user_meta_key)) {
            $user_meta_key = 'pta_pf_' . $field_key;
        }

        $data = array(
            'group_id'        => $group_id,
            'label'           => $label,
            'field_key'       => $field_key,
            'scope'           => $scope,
            'field_type'      => $field_type,
            'placeholder'     => $placeholder,
            'options_json'    => $options_json,
            'required'        => $required,
            'save_to_profile' => $save_to_profile,
            'user_meta_key'   => $user_meta_key,
        );

        if ($existing) {
            $wpdb->update($table, $data, array('id' => $id));
        } else {
            $max_order = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(sort_order) FROM {$table} WHERE group_id = %d", $group_id
            ));
            $data['sort_order'] = $max_order + 1;
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table, $data);
            $id = $wpdb->insert_id;
        }

        return array('id' => (int) $id, 'field_key' => $field_key);
    }

    public function ajax_delete_field() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $table = Azure_Database::get_table_name('product_fields');
        $id = intval($_POST['id'] ?? 0);

        if (!$id) {
            wp_send_json_error('Invalid field ID');
        }

        $wpdb->delete($table, array('id' => $id));
        wp_send_json_success('Field deleted');
    }

    public function ajax_reorder_fields() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $table = Azure_Database::get_table_name('product_fields');
        $order = (array)($_POST['order'] ?? array());

        foreach ($order as $position => $field_id) {
            $wpdb->update($table, array('sort_order' => (int) $position), array('id' => (int) $field_id));
        }

        wp_send_json_success('Order updated');
    }

    /**
     * Make sure `field_key` is unique across rows (other than the row being
     * saved). Appends a numeric suffix if needed.
     */
    private static function ensure_unique_field_key($candidate, $current_id = 0) {
        global $wpdb;
        $table = Azure_Database::get_table_name('product_fields');
        if (!$table) {
            return $candidate;
        }

        $base = $candidate;
        $i = 2;
        while (true) {
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE field_key = %s AND id <> %d",
                $candidate,
                $current_id
            ));
            if ($exists === 0) {
                return $candidate;
            }
            $candidate = $base . '_' . $i;
            $i++;
        }
    }
}
