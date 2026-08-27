<?php
/**
 * Product Fields Module
 *
 * Reusable custom fields for WooCommerce products, assigned by category.
 * Field values persist to user/child profiles for auto-population on
 * repeat purchases.
 *
 * Storage contract (set by v3.64+):
 *   - `field_key` on `azure_product_fields` is the stable storage slug.
 *   - Order line items receive both a `_pta_<field_key>` (machine-stable)
 *     and a `<Display Label>` (human-readable) meta entry.
 *   - Profile write-back routes by scope:
 *       parent → user meta `pta_pf_<field_key>`
 *       child  → `azure_user_children_meta` row keyed by `pta_pf_<field_key>`
 *                for the child id selected on the product page.
 *
 * Admin AJAX lives in `class-product-fields-admin.php`.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Product_Fields_Module {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        $this->init_hooks();

        if (is_admin()) {
            require_once AZURE_PLUGIN_PATH . 'includes/class-product-fields-admin.php';
            Azure_Product_Fields_Admin::get_instance();
        }

        if (class_exists('Azure_Logger')) {
            Azure_Logger::debug_module('ProductFields', 'Product Fields module initialized');
        }
    }

    public function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p><strong>' . esc_html__('Product Fields Module:', 'azure-plugin') . '</strong> ' . esc_html__('WooCommerce is required.', 'azure-plugin') . '</p></div>';
    }

    private function init_hooks() {
        // Frontend: render fields on product page
        add_action('woocommerce_before_add_to_cart_button', array($this, 'render_product_fields'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));

        // Cart: carry field data through
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_cart_item_data'), 10, 3);
        add_filter('woocommerce_get_item_data', array($this, 'display_cart_item_data'), 10, 2);

        // Validation
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_fields'), 10, 3);

        // Order: save to line item meta
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_order_item_meta'), 10, 4);

        // Save to user/child profile on order completion
        add_action('woocommerce_order_status_completed', array($this, 'save_to_user_profile'));
        add_action('woocommerce_payment_complete', array($this, 'save_to_user_profile'));

        // Quick-add child from the product-page "+ Child" button.
        add_action('wp_ajax_azure_pf_quick_add_child', array($this, 'ajax_quick_add_child'));
    }

    /**
     * True when the child-picker dropdown was rendered above the field
     * list on the current product page. Set by render_product_fields()
     * just before render_single_field() runs so the latter can suppress
     * a duplicate text input for the canonical `child_name` field.
     *
     * @var bool
     */
    private $child_selector_rendered = false;

    /**
     * True when this product page is Family PTSA membership (multi-child roster).
     *
     * @var bool
     */
    private $family_children_mode = false;

    // ─── Helpers ───────────────────────────────────────────────────────

    /**
     * Get all active field groups assigned to the product's categories,
     * each with its fields preloaded.
     */
    public static function get_groups_for_product($product_id) {
        global $wpdb;

        $cat_table = Azure_Database::get_table_name('product_field_categories');
        $grp_table = Azure_Database::get_table_name('product_field_groups');
        $fld_table = Azure_Database::get_table_name('product_fields');

        if (!$cat_table || !$grp_table || !$fld_table) {
            return array();
        }

        $terms = wc_get_product_term_ids($product_id, 'product_cat');
        if (empty($terms)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($terms), '%d'));

        $groups = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT g.* FROM {$grp_table} g
             INNER JOIN {$cat_table} c ON g.id = c.group_id
             WHERE c.term_id IN ({$placeholders}) AND g.is_active = 1
             ORDER BY g.sort_order ASC",
            ...$terms
        ));

        foreach ($groups as &$group) {
            $group->fields = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$fld_table} WHERE group_id = %d ORDER BY sort_order ASC",
                $group->id
            ));
        }

        return $groups;
    }

    /**
     * Resolve the canonical user-meta key used for a field's profile write.
     * Falls back to legacy `user_meta_key` for rows that pre-date `field_key`.
     */
    public static function get_user_meta_key($field) {
        if (!empty($field->field_key)) {
            return 'pta_pf_' . $field->field_key;
        }
        if (!empty($field->user_meta_key)) {
            return $field->user_meta_key;
        }
        return '';
    }

    /**
     * Public export contract.
     *
     * Returns a [field_key => Display Label] map of every canonical field
     * defined in `wp_azure_product_fields`. External order-export plugins can
     * use this list to register columns without guessing label spellings.
     *
     * Order line items expose each value at meta key `_pta_<field_key>`, which
     * is stable across label edits. Use that key when reading values from
     * `WC_Order_Item_Product` or directly from `wp_woocommerce_order_itemmeta`.
     *
     * Filter: `pta_product_fields_export_columns` ([field_key=>label]).
     */
    public static function get_export_columns() {
        global $wpdb;
        $fld_table = Azure_Database::get_table_name('product_fields');
        $columns = array();
        if ($fld_table) {
            $rows = $wpdb->get_results(
                "SELECT field_key, label FROM {$fld_table}
                 WHERE field_key <> ''
                 ORDER BY scope, sort_order, label ASC"
            );
            foreach ($rows as $r) {
                $columns[$r->field_key] = $r->label;
            }
        }
        return apply_filters('pta_product_fields_export_columns', $columns);
    }

    /**
     * Whether a field's label semantically refers to the child's name.
     * Used to decide which value identifies the child during auto-save.
     */
    public static function is_child_name_field($field) {
        if (!empty($field->field_key)) {
            $k = strtolower($field->field_key);
            if ($k === 'child_name' || strpos($k, 'child') !== false && strpos($k, 'name') !== false) {
                return true;
            }
        }
        if (!empty($field->label)) {
            $l = strtolower($field->label);
            if (strpos($l, 'child') !== false && strpos($l, 'name') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Field types that legacy rows accidentally stored in `field_key`. Such
     * rows are unusable for key resolution and must be skipped.
     */
    private static function get_field_type_slugs() {
        return array('text', 'select', 'email', 'textarea', 'checkbox', 'radio', 'number', 'date', 'tel', 'url');
    }

    /**
     * Resolve the storage keys for the Grade and Teacher child fields.
     * The slug differs per install (`childsgrade` on this site, but seeded
     * as `child_grade` in class-database.php), so match the registry rather
     * than hardcoding, and keep the in-use keys as the fallback so existing
     * saved values keep hydrating.
     */
    public static function get_child_profile_field_keys() {
        global $wpdb;

        $keys = array('grade' => 'childsgrade', 'teacher' => 'child_teacher');
        $fld_table = Azure_Database::get_table_name('product_fields');
        if (!$fld_table) {
            return $keys;
        }

        $rows = $wpdb->get_results("SELECT field_key, label FROM {$fld_table} WHERE field_key <> ''");
        $types = self::get_field_type_slugs();
        foreach ($rows as $r) {
            if (in_array(strtolower($r->field_key), $types, true)) {
                continue;
            }
            $haystack = strtolower($r->field_key . ' ' . $r->label);
            if (strpos($haystack, 'grade') !== false) {
                $keys['grade'] = $r->field_key;
            } elseif (strpos($haystack, 'teacher') !== false) {
                $keys['teacher'] = $r->field_key;
            }
        }

        return apply_filters('azure_pf_child_profile_field_keys', $keys);
    }

    /**
     * Grade choices for the quick-add modal, preferring whatever the Grade
     * field is configured with so the modal can't drift from the product form.
     */
    public static function get_grade_options() {
        global $wpdb;

        $options = array();
        $fld_table = Azure_Database::get_table_name('product_fields');
        if ($fld_table) {
            $keys = self::get_child_profile_field_keys();
            $json = $wpdb->get_var($wpdb->prepare(
                "SELECT options_json FROM {$fld_table} WHERE field_key = %s LIMIT 1",
                $keys['grade']
            ));
            $decoded = $json ? json_decode($json, true) : null;
            if (is_array($decoded)) {
                $options = array_values(array_filter(array_map('strval', $decoded), 'strlen'));
            }
        }

        if (empty($options)) {
            $options = array('PreK', 'K', '1', '2', '3', '4', '5');
        }

        return apply_filters('azure_pf_grade_options', $options);
    }

    /**
     * Family PTSA membership uses a multi-child roster instead of the
     * single-child dropdown.
     */
    public static function is_family_membership_product($product_id) {
        $product_id = (int) $product_id;
        $parent_id = 0;
        $name = '';
        if (function_exists('wc_get_product')) {
            $product = wc_get_product($product_id);
            if ($product) {
                $parent_id = (int) $product->get_parent_id();
                $name = strtolower((string) $product->get_name());
                if ($name === '' && $parent_id > 0) {
                    $parent = wc_get_product($parent_id);
                    if ($parent) {
                        $name = strtolower((string) $parent->get_name());
                    }
                }
            }
        }
        if (class_exists('Azure_Membership_Module') && Azure_Membership_Module::product_is_family($product_id, $parent_id)) {
            return true;
        }
        return $name !== ''
            && strpos($name, 'family') !== false
            && (strpos($name, 'membership') !== false || strpos($name, 'ptsa') !== false || strpos($name, 'pta ') !== false);
    }

    /**
     * Child's Name / year / teacher — collected per child on family membership.
     */
    public static function is_family_child_core_field($field) {
        if (self::is_child_name_field($field)) {
            return true;
        }
        $scope = !empty($field->scope) ? $field->scope : 'child';
        if ($scope !== 'child') {
            return false;
        }
        $haystack = strtolower((isset($field->field_key) ? $field->field_key : '') . ' ' . (isset($field->label) ? $field->label : ''));
        return strpos($haystack, 'grade') !== false
            || strpos($haystack, 'teacher') !== false
            || preg_match('/\byear\b/', $haystack);
    }

    /**
     * @param array $children Azure_User_Children rows
     * @return array
     */
    public static function filter_family_membership_children($children) {
        $out = array();
        if (empty($children) || !class_exists('Azure_User_Children')) {
            return $out;
        }
        foreach ($children as $child) {
            if (empty($child->id)) {
                continue;
            }
            $meta = Azure_User_Children::get_child_meta($child->id);
            if (Azure_User_Children::include_on_family_membership($meta)) {
                $out[] = $child;
            }
        }
        return $out;
    }

    /**
     * Normalize posted or stored family-membership child rows.
     *
     * @param mixed $raw
     * @return array<int, array{id:int,name:string,grade:string,teacher:string}>
     */
    public static function sanitize_family_children($raw) {
        if (!is_array($raw)) {
            return array();
        }
        $out = array();
        $seen = array();
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            $name = isset($row['name']) ? trim(sanitize_text_field($row['name'])) : '';
            $grade = isset($row['grade']) ? trim(sanitize_text_field($row['grade'])) : '';
            $teacher = isset($row['teacher']) ? trim(sanitize_text_field($row['teacher'])) : '';
            if ($id <= 0 && $name === '' && $grade === '' && $teacher === '') {
                continue;
            }
            $key = $id > 0 ? 'id:' . $id : 'name:' . strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = array(
                'id'      => $id,
                'name'    => $name,
                'grade'   => $grade,
                'teacher' => $teacher,
            );
        }
        return $out;
    }

    // ─── Frontend: render fields ───────────────────────────────────────

    public function render_product_fields() {
        global $product;
        if (!$product instanceof WC_Product) {
            return;
        }

        $groups = self::get_groups_for_product($product->get_id());
        if (empty($groups)) {
            return;
        }

        $user_id = get_current_user_id();
        $this->family_children_mode = self::is_family_membership_product($product->get_id());
        $children = array();
        $family   = null;
        if ($user_id && class_exists('Azure_User_Children')) {
            $children = Azure_User_Children::get_children_for_user($user_id);
            $family   = Azure_User_Children::get_family_for_user($user_id);
        }
        $family_children = $this->family_children_mode
            ? self::filter_family_membership_children($children)
            : $children;

        // Defaults map: parent-scope is current user's saved meta. Child-scope
        // values live under the child id and are swapped in via JS when the
        // dropdown changes. Family-scope is one map shared across both
        // co-parents (emergency contact, etc.) — pre-filled like parent.
        $parent_defaults = $this->build_parent_defaults($user_id, $groups);
        $family_defaults = $this->build_family_defaults($family, $groups);
        $child_data      = $this->build_children_data($children);

        echo '<div class="azure-product-fields">';

        if ($this->family_children_mode) {
            $this->child_selector_rendered = true;
            $this->render_family_children_block($user_id, $family_children);
        } elseif ($user_id) {
            // Child picker — always rendered for logged-in parents so the
            // "Child's Name" input is *always* a dropdown choice (not free
            // text). Guests still fall through to the regular text-field
            // renderer at the bottom because they have no family/children
            // to bind a dropdown to.
            $this->child_selector_rendered = true;
            echo '<div class="azure-pf-child-selector">';
            echo '<label for="azure-pf-select-child">' . esc_html__("Child's Name", 'azure-plugin') . ' <span class="required">*</span></label>';
            echo '<div class="azure-pf-select-row">';
            echo '<select id="azure-pf-select-child" name="azure_pf_child_id" required>';
            echo '<option value="">' . esc_html__('-- Select child --', 'azure-plugin') . '</option>';
            foreach ($children as $child) {
                echo '<option value="' . esc_attr($child->id) . '">' . esc_html($child->child_name) . '</option>';
            }
            echo '</select>';
            echo '<button type="button" class="button azure-pf-add-child-btn" id="azure-pf-add-child" aria-label="' . esc_attr__('Add a new child', 'azure-plugin') . '">+ ' . esc_html__('Child', 'azure-plugin') . '</button>';
            echo '</div>';
            echo '</div>';
        }

        // Single inline payload: keyed-by-field_key map for all scopes,
        // plus the AJAX endpoint + nonce for the quick-add child modal.
        echo '<script>window.azurePtaProductFields = ' . wp_json_encode(array(
            'children' => $child_data,
            'parent'   => $parent_defaults,
            'family'   => $family_defaults,
            'ajax'     => array(
                'url'             => admin_url('admin-ajax.php'),
                'nonce_quick_add' => wp_create_nonce('azure_pf_quick_add_child'),
            ),
            'is_user_logged_in'     => $user_id > 0,
            'family_children_mode'  => $this->family_children_mode,
            'require_child_details' => $this->family_children_mode,
        )) . ';</script>';

        foreach ($groups as $group) {
            if (empty($group->fields)) {
                continue;
            }
            echo '<div class="azure-pf-group" data-group-id="' . esc_attr($group->id) . '">';
            if (!empty($group->name)) {
                echo '<h4 class="azure-pf-group-title">' . esc_html($group->name) . '</h4>';
            }
            foreach ($group->fields as $field) {
                $this->render_single_field($field, $parent_defaults, $family_defaults);
            }
            echo '</div>';
        }

        // Quick-add child modal. Hidden by default, opened by the
        // "+ Child" button. Logged-in users save to their profile.
        // Guests on family membership add a card in JS instead.
        if ($user_id) {
            ?>
            <div id="azure-pf-add-child-modal" class="azure-pf-modal" style="display:none;" aria-hidden="true">
                <div class="azure-pf-modal-backdrop"></div>
                <div class="azure-pf-modal-dialog" role="dialog" aria-labelledby="azure-pf-add-child-title">
                    <h3 id="azure-pf-add-child-title"><?php esc_html_e('Add a child', 'azure-plugin'); ?></h3>
                    <p>
                        <label for="azure-pf-new-child-name"><?php esc_html_e("Child's name", 'azure-plugin'); ?></label>
                        <input type="text" id="azure-pf-new-child-name" placeholder="<?php esc_attr_e('Child\'s name', 'azure-plugin'); ?>" autocomplete="off" />
                    </p>
                    <p>
                        <label for="azure-pf-new-child-grade"><?php esc_html_e('Grade', 'azure-plugin'); ?></label>
                        <select id="azure-pf-new-child-grade">
                            <option value=""><?php esc_html_e('-- Select grade --', 'azure-plugin'); ?></option>
                            <?php foreach (self::get_grade_options() as $grade) : ?>
                                <option value="<?php echo esc_attr($grade); ?>"><?php echo esc_html($grade); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p>
                        <label for="azure-pf-new-child-teacher"><?php esc_html_e('Teacher', 'azure-plugin'); ?></label>
                        <input type="text" id="azure-pf-new-child-teacher" placeholder="<?php esc_attr_e('e.g. Congdon', 'azure-plugin'); ?>" autocomplete="off" />
                    </p>
                    <p class="azure-pf-modal-actions">
                        <button type="button" class="button azure-pf-cancel-child"><?php esc_html_e('Cancel', 'azure-plugin'); ?></button>
                        <button type="button" class="button button-primary" id="azure-pf-save-child"><?php esc_html_e('Add child', 'azure-plugin'); ?></button>
                    </p>
                    <p id="azure-pf-add-child-error" class="azure-pf-modal-error" style="display:none;"></p>
                </div>
            </div>
            <?php
        }

        echo '</div>';
    }

    /**
     * Roster of children on Family PTSA membership: auto-include PreK–5
     * for logged-in parents; guests start with one blank card.
     *
     * @param int   $user_id
     * @param array $children
     */
    private function render_family_children_block($user_id, $children) {
        $help = $user_id
            ? __('Every PreK–5 child in your family is included. Use + Child to add another.', 'azure-plugin')
            : __('Add each child with their year and teacher.', 'azure-plugin');

        echo '<div class="azure-pf-family-children" id="azure-pf-family-children">';
        echo '<div class="azure-pf-family-heading-row">';
        echo '<label>' . esc_html__('Children', 'azure-plugin') . ' <span class="required">*</span></label>';
        echo '<button type="button" class="button azure-pf-add-child-btn" id="azure-pf-add-child" aria-label="' . esc_attr__('Add a child', 'azure-plugin') . '">+ ' . esc_html__('Child', 'azure-plugin') . '</button>';
        echo '</div>';
        echo '<p class="azure-pf-family-help">' . esc_html($help) . '</p>';
        echo '<div class="azure-pf-child-list" id="azure-pf-child-list">';

        $index = 0;
        if ($user_id) {
            foreach ($children as $child) {
                $meta = class_exists('Azure_User_Children')
                    ? Azure_User_Children::get_child_meta($child->id)
                    : array();
                $this->render_family_child_card($index, array(
                    'id'         => (int) $child->id,
                    'name'       => $child->child_name,
                    'grade'      => class_exists('Azure_User_Children') ? Azure_User_Children::grade_from_meta($meta) : '',
                    'teacher'    => class_exists('Azure_User_Children') ? Azure_User_Children::teacher_from_meta($meta) : '',
                    'locked'     => true,
                    'removable'  => false,
                ));
                $index++;
            }
        } else {
            $this->render_family_child_card($index, array(
                'id'        => 0,
                'name'      => '',
                'grade'     => '',
                'teacher'   => '',
                'locked'    => false,
                'removable' => false,
            ));
            $index++;
        }

        echo '</div>';
        echo '<template id="azure-pf-child-card-template">';
        $this->render_family_child_card('__INDEX__', array(
            'id'        => 0,
            'name'      => '',
            'grade'     => '',
            'teacher'   => '',
            'locked'    => false,
            'removable' => true,
        ));
        echo '</template>';
        echo '</div>';
    }

    /**
     * @param int|string $index
     * @param array      $data
     */
    private function render_family_child_card($index, $data) {
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        $name = isset($data['name']) ? (string) $data['name'] : '';
        $grade = isset($data['grade']) ? (string) $data['grade'] : '';
        $teacher = isset($data['teacher']) ? (string) $data['teacher'] : '';
        $locked = !empty($data['locked']);
        $removable = !empty($data['removable']);
        $name_attr = 'azure_pf_children[' . $index . '][name]';
        $grade_attr = 'azure_pf_children[' . $index . '][grade]';
        $teacher_attr = 'azure_pf_children[' . $index . '][teacher]';
        $id_attr = 'azure_pf_children[' . $index . '][id]';

        echo '<div class="azure-pf-child-card"' . ($removable ? ' data-removable="1"' : '') . '>';
        echo '<input type="hidden" name="' . esc_attr($id_attr) . '" value="' . esc_attr((string) $id) . '" class="azure-pf-child-id" />';
        echo '<p class="form-row azure-pf-field">';
        echo '<label>' . esc_html__("Child's name", 'azure-plugin') . ' <span class="required">*</span></label>';
        echo '<input type="text" name="' . esc_attr($name_attr) . '" value="' . esc_attr($name) . '" class="azure-pf-child-name" autocomplete="off"' . ($locked ? ' readonly' : ' required') . ' />';
        echo '</p>';
        echo '<p class="form-row azure-pf-field">';
        echo '<label>' . esc_html__('Year', 'azure-plugin') . ' <span class="required">*</span></label>';
        echo '<select name="' . esc_attr($grade_attr) . '" class="azure-pf-child-grade" required>';
        echo '<option value="">' . esc_html__('-- Select year --', 'azure-plugin') . '</option>';
        foreach (self::get_grade_options() as $opt) {
            $selected = ((string) $opt === $grade) ? ' selected' : '';
            echo '<option value="' . esc_attr($opt) . '"' . $selected . '>' . esc_html($opt) . '</option>';
        }
        if ($grade !== '' && !in_array($grade, self::get_grade_options(), true)) {
            echo '<option value="' . esc_attr($grade) . '" selected>' . esc_html($grade) . '</option>';
        }
        echo '</select>';
        echo '</p>';
        echo '<p class="form-row azure-pf-field">';
        echo '<label>' . esc_html__('Teacher', 'azure-plugin') . ' <span class="required">*</span></label>';
        echo '<input type="text" name="' . esc_attr($teacher_attr) . '" value="' . esc_attr($teacher) . '" class="azure-pf-child-teacher" placeholder="' . esc_attr__('e.g. Congdon', 'azure-plugin') . '" autocomplete="off" required />';
        echo '</p>';
        if ($removable) {
            echo '<button type="button" class="button-link azure-pf-remove-child">' . esc_html__('Remove', 'azure-plugin') . '</button>';
        }
        echo '</div>';
    }

    private function render_single_field($field, $parent_defaults, $family_defaults = array()) {
        // Family membership collects name / year / teacher on the roster.
        if ($this->family_children_mode && self::is_family_child_core_field($field)) {
            return;
        }

        // The canonical child_name field is rendered as a dropdown selector
        // at the top of the form (see render_product_fields). Skip the
        // text-input render here so we don't get a duplicate input — but
        // only when the selector was actually emitted (logged-in users).
        if (self::is_child_name_field($field) && $this->child_selector_rendered) {
            return;
        }

        $scope = !empty($field->scope) ? $field->scope : 'child';
        $value = '';

        // Parent + family scope fields pre-fill from saved profile data on
        // initial load. Child-scope fields stay blank until the user picks a
        // child (the JS swap fills them from the child profile).
        if ($scope === 'parent' && !empty($field->field_key) && isset($parent_defaults[$field->field_key])) {
            $value = (string) $parent_defaults[$field->field_key];
        } elseif ($scope === 'family' && !empty($field->field_key) && isset($family_defaults[$field->field_key])) {
            $value = (string) $family_defaults[$field->field_key];
        }

        $name = 'azure_pf_' . $field->id;
        $required = $field->required ? ' required' : '';
        $req_star = $field->required ? ' <span class="required">*</span>' : '';
        $field_key_attr = !empty($field->field_key) ? ' data-field-key="' . esc_attr($field->field_key) . '"' : '';
        $scope_attr     = ' data-field-scope="' . esc_attr($scope) . '"';

        echo '<p class="form-row azure-pf-field azure-pf-field-' . esc_attr($field->field_type) . '"' . $field_key_attr . $scope_attr . '>';
        echo '<label for="' . esc_attr($name) . '">' . esc_html($field->label) . $req_star . '</label>';

        switch ($field->field_type) {
            case 'textarea':
                echo '<textarea name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" placeholder="' . esc_attr($field->placeholder) . '"' . $required . '>' . esc_textarea($value) . '</textarea>';
                break;

            case 'select':
                $options = json_decode($field->options_json, true) ?: array();
                echo '<select name="' . esc_attr($name) . '" id="' . esc_attr($name) . '"' . $required . '>';
                echo '<option value="">' . esc_html($field->placeholder ?: '-- Select --') . '</option>';
                foreach ($options as $opt) {
                    $selected = ($value === $opt) ? ' selected' : '';
                    echo '<option value="' . esc_attr($opt) . '"' . $selected . '>' . esc_html($opt) . '</option>';
                }
                echo '</select>';
                break;

            case 'checkbox':
                $checked = $value ? ' checked' : '';
                echo '<label class="azure-pf-checkbox-label"><input type="checkbox" name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" value="1"' . $checked . ' /> ' . esc_html($field->placeholder ?: $field->label) . '</label>';
                break;

            case 'number':
                echo '<input type="number" name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($field->placeholder) . '"' . $required . ' />';
                break;

            default: // text, email, tel, etc.
                echo '<input type="' . esc_attr($field->field_type) . '" name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($field->placeholder) . '"' . $required . ' />';
                break;
        }

        echo '</p>';
    }

    /**
     * Build a [field_key => value] map of saved parent-scope values for
     * the current user, sourced from `pta_pf_<field_key>` user meta with
     * a fallback to the legacy `user_meta_key` column.
     */
    private function build_parent_defaults($user_id, $groups) {
        $defaults = array();
        if (!$user_id) {
            return $defaults;
        }

        foreach ($groups as $group) {
            if (empty($group->fields)) {
                continue;
            }
            foreach ($group->fields as $field) {
                $scope = !empty($field->scope) ? $field->scope : 'child';
                if ($scope !== 'parent' || empty($field->field_key)) {
                    continue;
                }
                $val = get_user_meta($user_id, 'pta_pf_' . $field->field_key, true);
                if ($val === '' && !empty($field->user_meta_key)) {
                    $val = get_user_meta($user_id, $field->user_meta_key, true);
                }
                if ($val !== '') {
                    $defaults[$field->field_key] = $val;
                }
            }
        }

        return $defaults;
    }

    /**
     * Build a [field_key => value] map of saved family-scope values shared
     * by both co-parents (emergency contact, etc.). Returns an empty map if
     * the user has no connected_family yet — the family is created on
     * demand when an order with family-scope meta is paid for.
     */
    private function build_family_defaults($family, $groups) {
        $defaults = array();
        if (!$family || empty($family->id) || !class_exists('Azure_User_Children')) {
            return $defaults;
        }

        // Single round-trip; reuse for every family-scope field.
        $stored = Azure_User_Children::get_family_meta($family->id);

        foreach ($groups as $group) {
            if (empty($group->fields)) {
                continue;
            }
            foreach ($group->fields as $field) {
                $scope = !empty($field->scope) ? $field->scope : 'child';
                if ($scope !== 'family' || empty($field->field_key)) {
                    continue;
                }
                $key = 'pta_pf_' . $field->field_key;
                if (isset($stored[$key]) && $stored[$key] !== '') {
                    $defaults[$field->field_key] = $stored[$key];
                }
            }
        }

        return $defaults;
    }

    /**
     * Build a [child_id => { field_key: value, _name: child_name }] map of
     * saved child-scope values for the current user's children. The map is
     * exposed to the front-end JS so swapping the child dropdown can hydrate
     * inputs by field_key (label-edit safe).
     */
    private function build_children_data($children) {
        $out = array();
        if (empty($children) || !class_exists('Azure_User_Children')) {
            return $out;
        }
        foreach ($children as $child) {
            $meta_raw = Azure_User_Children::get_child_meta($child->id);
            $by_key = array();
            foreach ($meta_raw as $k => $v) {
                if (strpos($k, 'pta_pf_') === 0) {
                    $by_key[substr($k, strlen('pta_pf_'))] = $v;
                } else {
                    // Legacy keys (label-as-meta-key) survive so old data still
                    // pre-populates until the consolidation tool migrates them.
                    $by_key['__legacy__::' . $k] = $v;
                }
            }
            $out[$child->id] = array(
                'name'   => $child->child_name,
                'fields' => $by_key,
            );
        }
        return $out;
    }

    public function enqueue_frontend_assets() {
        if (!is_product()) {
            return;
        }

        // The `$product` global is not populated until `the_post` fires
        // inside the loop, which is *after* `wp_enqueue_scripts`. Reading it
        // here always yielded null, so the assets were never enqueued and
        // the "+ Child" button had no click handler bound. Resolve from the
        // queried object instead, which is available this early.
        $product = wc_get_product(get_queried_object_id());
        if (!$product instanceof WC_Product) {
            return;
        }

        $groups = self::get_groups_for_product($product->get_id());
        if (empty($groups)) {
            return;
        }

        wp_enqueue_style(
            'azure-product-fields',
            AZURE_PLUGIN_URL . 'css/product-fields-frontend.css',
            array(),
            AZURE_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'azure-product-fields',
            AZURE_PLUGIN_URL . 'js/product-fields-frontend.js',
            array('jquery'),
            AZURE_PLUGIN_VERSION,
            true
        );
    }

    // ─── Validation ────────────────────────────────────────────────────

    public function validate_fields($passed, $product_id, $quantity) {
        if (!empty($_POST['pta_donated_product'])) {
            return $passed;
        }

        $groups = self::get_groups_for_product($product_id);
        $is_family = self::is_family_membership_product($product_id);

        if ($is_family) {
            $family_children = $this->resolve_family_children_for_cart($product_id);
            if (empty($family_children)) {
                wc_add_notice(__('Add at least one child with a name, year, and teacher.', 'azure-plugin'), 'error');
                $passed = false;
            } else {
                foreach ($family_children as $child) {
                    if ($child['name'] === '' || $child['grade'] === '' || $child['teacher'] === '') {
                        wc_add_notice(__('Each child needs a name, year, and teacher.', 'azure-plugin'), 'error');
                        $passed = false;
                        break;
                    }
                }
            }
        }

        // For logged-in parents the "Child's Name" field is collected via
        // the child-picker dropdown (name="azure_pf_child_id"), and its
        // azure_pf_{id} text input is intentionally NOT rendered (see
        // render_single_field). The dropdown is always submitted when it's
        // on the page, so isset() tells us it was the input method.
        $has_child_selector = isset($_POST['azure_pf_child_id']);
        $selected_child_id  = $has_child_selector ? intval($_POST['azure_pf_child_id']) : 0;

        foreach ($groups as $group) {
            foreach ($group->fields as $field) {
                if ($is_family && self::is_family_child_core_field($field)) {
                    continue;
                }
                if (!$field->required) {
                    continue;
                }

                // Validate the canonical child-name field against the
                // dropdown selection rather than the (absent) text input —
                // otherwise selecting a child still fails with
                // "Child's name is a required field".
                if ($has_child_selector && self::is_child_name_field($field)) {
                    if ($selected_child_id <= 0) {
                        wc_add_notice(sprintf(__('"%s" is a required field.', 'azure-plugin'), $field->label), 'error');
                        $passed = false;
                    }
                    continue;
                }

                $key = 'azure_pf_' . $field->id;
                $val = isset($_POST[$key]) ? sanitize_text_field($_POST[$key]) : '';
                if ($val === '') {
                    wc_add_notice(sprintf(__('"%s" is a required field.', 'azure-plugin'), $field->label), 'error');
                    $passed = false;
                }
            }
        }

        return $passed;
    }

    /**
     * Posted family-membership children, with logged-in PreK–5 kids
     * re-attached so they cannot be omitted from the cart.
     *
     * @param int $product_id
     * @return array<int, array{id:int,name:string,grade:string,teacher:string}>
     */
    public function resolve_family_children_for_cart($product_id = 0) {
        unset($product_id);
        $raw = isset($_POST['azure_pf_children']) ? wp_unslash($_POST['azure_pf_children']) : array();
        $posted = self::sanitize_family_children($raw);
        $user_id = function_exists('get_current_user_id') ? get_current_user_id() : 0;
        if (!$user_id || !class_exists('Azure_User_Children')) {
            return $posted;
        }

        $by_key = array();
        foreach ($posted as $row) {
            $key = $row['id'] > 0 ? 'id:' . $row['id'] : 'name:' . strtolower($row['name']);
            $by_key[$key] = $row;
        }

        $merged = array();
        foreach (self::filter_family_membership_children(Azure_User_Children::get_children_for_user($user_id)) as $child) {
            $key = 'id:' . (int) $child->id;
            $meta = Azure_User_Children::get_child_meta($child->id);
            $defaults = array(
                'id'      => (int) $child->id,
                'name'    => (string) $child->child_name,
                'grade'   => Azure_User_Children::grade_from_meta($meta),
                'teacher' => Azure_User_Children::teacher_from_meta($meta),
            );
            if (isset($by_key[$key])) {
                $row = $by_key[$key];
                $row['id'] = $defaults['id'];
                $row['name'] = $defaults['name'];
                if ($row['grade'] === '') {
                    $row['grade'] = $defaults['grade'];
                }
                if ($row['teacher'] === '') {
                    $row['teacher'] = $defaults['teacher'];
                }
                $merged[] = $row;
                unset($by_key[$key]);
            } else {
                $merged[] = $defaults;
            }
        }

        foreach ($by_key as $row) {
            $merged[] = $row;
        }

        return $merged;
    }

    // ─── Cart ──────────────────────────────────────────────────────────

    public function add_cart_item_data($cart_item_data, $product_id, $variation_id) {
        if (!empty($cart_item_data['_pta_donated_product']) || !empty($_POST['pta_donated_product'])) {
            return $cart_item_data;
        }

        $groups = self::get_groups_for_product($product_id);
        $field_values = array();

        foreach ($groups as $group) {
            foreach ($group->fields as $field) {
                $key = 'azure_pf_' . $field->id;
                if (isset($_POST[$key])) {
                    $field_values[$field->id] = array(
                        'field_key'       => isset($field->field_key) ? $field->field_key : '',
                        'scope'           => !empty($field->scope) ? $field->scope : 'child',
                        'label'           => $field->label,
                        'value'           => sanitize_text_field($_POST[$key]),
                        'save_to_profile' => (bool) $field->save_to_profile,
                        'user_meta_key'   => isset($field->user_meta_key) ? $field->user_meta_key : '',
                    );
                }
            }
        }

        if (self::is_family_membership_product($product_id)) {
            $family_children = $this->resolve_family_children_for_cart($product_id);
            if (!empty($family_children)) {
                $cart_item_data['azure_pf_children'] = $family_children;
                if ($family_children[0]['id'] > 0) {
                    $cart_item_data['azure_pf_child_id'] = $family_children[0]['id'];
                }
                $this->inject_family_children_field_values($groups, $family_children, $field_values);
            }
        }

        // Resolve the child-picker dropdown selection. Its azure_pf_{id}
        // text input is never rendered for logged-in parents, so the only
        // submitted value is the child id. Look up the child's NAME and
        // inject it into the field map under the canonical child-name field
        // so it persists to the cart, order line item, emails, and exports
        // as "Child's Name" — not just an opaque child id.
        $child_id = isset($_POST['azure_pf_child_id']) ? intval($_POST['azure_pf_child_id']) : 0;
        if ($child_id > 0 && empty($cart_item_data['azure_pf_children'])) {
            $cart_item_data['azure_pf_child_id'] = $child_id;

            $child = class_exists('Azure_User_Children')
                ? Azure_User_Children::get_child($child_id, get_current_user_id())
                : null;
            if ($child && !empty($child->child_name)) {
                foreach ($groups as $group) {
                    foreach ($group->fields as $field) {
                        if (!self::is_child_name_field($field)) {
                            continue;
                        }
                        // Don't clobber a value already captured from a
                        // submitted text input (guest path).
                        if (isset($field_values[$field->id]) && $field_values[$field->id]['value'] !== '') {
                            continue;
                        }
                        $field_values[$field->id] = array(
                            'field_key'       => (!empty($field->field_key)) ? $field->field_key : 'child_name',
                            'scope'           => !empty($field->scope) ? $field->scope : 'child',
                            'label'           => $field->label,
                            'value'           => sanitize_text_field($child->child_name),
                            'save_to_profile' => (bool) $field->save_to_profile,
                            'user_meta_key'   => isset($field->user_meta_key) ? $field->user_meta_key : '',
                        );
                    }
                }
            }
        }

        if (!empty($field_values)) {
            $cart_item_data['azure_product_fields'] = $field_values;
        }

        return $cart_item_data;
    }

    public function display_cart_item_data($item_data, $cart_item) {
        $skip_core = array();
        if (!empty($cart_item['azure_pf_children']) && is_array($cart_item['azure_pf_children'])) {
            foreach ($cart_item['azure_pf_children'] as $i => $child) {
                $bits = array();
                if (!empty($child['name'])) {
                    $bits[] = $child['name'];
                }
                if (!empty($child['grade'])) {
                    $bits[] = $child['grade'];
                }
                if (!empty($child['teacher'])) {
                    $bits[] = $child['teacher'];
                }
                if (empty($bits)) {
                    continue;
                }
                $item_data[] = array(
                    'key'   => sprintf(__('Child %d', 'azure-plugin'), $i + 1),
                    'value' => implode(' — ', $bits),
                );
            }
            $skip_core = array('child_name', 'child_grade', 'childsgrade', 'child_teacher');
        }

        if (empty($cart_item['azure_product_fields'])) {
            return $item_data;
        }

        foreach ($cart_item['azure_product_fields'] as $field) {
            if ($field['value'] === '') {
                continue;
            }
            $field_key = isset($field['field_key']) ? $field['field_key'] : '';
            if ($field_key !== '' && in_array($field_key, $skip_core, true)) {
                continue;
            }
            if ($field_key === '' && self::is_family_child_core_field((object) $field)) {
                continue;
            }
            $item_data[] = array(
                'key'   => $field['label'],
                'value' => $field['value'],
            );
        }

        return $item_data;
    }

    /**
     * Copy roster values into the canonical child_name / grade / teacher
     * field map so emails and exports still see a Child's Name value.
     *
     * @param array $groups
     * @param array $children
     * @param array $field_values
     */
    private function inject_family_children_field_values($groups, $children, &$field_values) {
        $names = array();
        $grades = array();
        $teachers = array();
        foreach ($children as $child) {
            if ($child['name'] !== '') {
                $names[] = $child['name'];
            }
            if ($child['grade'] !== '') {
                $grades[] = $child['grade'];
            }
            if ($child['teacher'] !== '') {
                $teachers[] = $child['teacher'];
            }
        }
        $by_kind = array(
            'name'    => implode(', ', $names),
            'grade'   => implode(', ', $grades),
            'teacher' => implode(', ', $teachers),
        );

        foreach ($groups as $group) {
            if (empty($group->fields)) {
                continue;
            }
            foreach ($group->fields as $field) {
                if (!self::is_family_child_core_field($field)) {
                    continue;
                }
                $haystack = strtolower((isset($field->field_key) ? $field->field_key : '') . ' ' . (isset($field->label) ? $field->label : ''));
                $kind = 'name';
                if (strpos($haystack, 'grade') !== false || preg_match('/\byear\b/', $haystack)) {
                    $kind = 'grade';
                } elseif (strpos($haystack, 'teacher') !== false) {
                    $kind = 'teacher';
                }
                $field_values[$field->id] = array(
                    'field_key'       => (!empty($field->field_key)) ? $field->field_key : '',
                    'scope'           => !empty($field->scope) ? $field->scope : 'child',
                    'label'           => $field->label,
                    'value'           => $by_kind[$kind],
                    'save_to_profile' => false,
                    'user_meta_key'   => isset($field->user_meta_key) ? $field->user_meta_key : '',
                );
            }
        }
    }

    // ─── Order line item meta ──────────────────────────────────────────

    public function save_order_item_meta($item, $cart_item_key, $values, $order) {
        if (empty($values['azure_product_fields']) && empty($values['azure_pf_children'])) {
            return;
        }

        if (!empty($values['azure_product_fields']) && is_array($values['azure_product_fields'])) {
            foreach ($values['azure_product_fields'] as $field) {
                if ($field['value'] === '') {
                    continue;
                }

                // Human-readable label retained for admin order screen / emails.
                $item->update_meta_data($field['label'], $field['value']);

                // Machine-stable key retained for export/reporting. Survives label
                // edits because it is keyed by `field_key`, not the display label.
                if (!empty($field['field_key'])) {
                    $item->update_meta_data('_pta_' . $field['field_key'], $field['value']);
                }
            }

            $item->update_meta_data('_azure_product_fields_raw', $values['azure_product_fields']);
        }

        if (!empty($values['azure_pf_child_id'])) {
            $item->update_meta_data('_azure_pf_child_id', intval($values['azure_pf_child_id']));
        }
        if (!empty($values['azure_pf_children']) && is_array($values['azure_pf_children'])) {
            $item->update_meta_data('_azure_pf_children', $values['azure_pf_children']);
        }
    }

    // ─── Save to user profile on order completion (parent scope only) ──

    public function save_to_user_profile($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $user_id = $order->get_user_id();
        if (!$user_id) {
            return;
        }

        foreach ($order->get_items() as $item) {
            $raw = $item->get_meta('_azure_product_fields_raw', true);
            if (empty($raw) || !is_array($raw)) {
                continue;
            }

            foreach ($raw as $field) {
                if (empty($field['save_to_profile']) || !isset($field['value']) || $field['value'] === '') {
                    continue;
                }

                $scope = !empty($field['scope']) ? $field['scope'] : 'child';
                if ($scope === 'child' || $scope === 'family') {
                    // Child + family scope writes are owned by
                    // Azure_User_Children — child needs the child row, and
                    // family writes go to azure_connected_family_meta.
                    continue;
                }

                if (!empty($field['field_key'])) {
                    update_user_meta($user_id, 'pta_pf_' . $field['field_key'], $field['value']);
                }
                if (!empty($field['user_meta_key'])) {
                    // Legacy compatibility: keep writing to the configured key.
                    update_user_meta($user_id, $field['user_meta_key'], $field['value']);
                }
            }
        }
    }

    // ─── Quick-add child (AJAX, called from the "+ Child" modal) ───────

    /**
     * Create a new child for the current user via the product-page modal.
     * The new child is auto-attached to the parent's connected_family,
     * creating one if missing (Azure_User_Children::save_child handles
     * the family resolution). Returns id + name for the dropdown to
     * append + auto-select.
     */
    public function ajax_quick_add_child() {
        check_ajax_referer('azure_pf_quick_add_child', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(array('message' => __('You must be logged in to add a child.', 'azure-plugin')), 401);
        }

        $name = isset($_POST['child_name']) ? sanitize_text_field(wp_unslash($_POST['child_name'])) : '';
        $name = trim($name);
        if ($name === '') {
            wp_send_json_error(array('message' => __('Child name is required.', 'azure-plugin')), 400);
        }
        if (function_exists('mb_strlen') ? mb_strlen($name) > 80 : strlen($name) > 80) {
            wp_send_json_error(array('message' => __('Child name is too long (max 80 characters).', 'azure-plugin')), 400);
        }

        if (!class_exists('Azure_User_Children')) {
            $p = AZURE_PLUGIN_PATH . 'includes/class-user-children.php';
            if (file_exists($p)) require_once $p;
        }
        if (!class_exists('Azure_User_Children')) {
            wp_send_json_error(array('message' => __('Children module not available.', 'azure-plugin')), 500);
        }

        // Grade is constrained to the configured choices so a tampered POST
        // can't write an arbitrary value into the child profile.
        $grade = isset($_POST['child_grade']) ? sanitize_text_field(wp_unslash($_POST['child_grade'])) : '';
        $grade = trim($grade);
        if ($grade !== '' && !in_array($grade, self::get_grade_options(), true)) {
            wp_send_json_error(array('message' => __('Please choose a valid grade.', 'azure-plugin')), 400);
        }

        $teacher = isset($_POST['child_teacher']) ? sanitize_text_field(wp_unslash($_POST['child_teacher'])) : '';
        $teacher = trim($teacher);
        if (function_exists('mb_strlen') ? mb_strlen($teacher) > 80 : strlen($teacher) > 80) {
            wp_send_json_error(array('message' => __('Teacher name is too long (max 80 characters).', 'azure-plugin')), 400);
        }

        $keys = self::get_child_profile_field_keys();
        $meta = array();
        if ($grade !== '') {
            $meta['pta_pf_' . $keys['grade']] = $grade;
        }
        if ($teacher !== '') {
            $meta['pta_pf_' . $keys['teacher']] = $teacher;
        }

        $id = Azure_User_Children::save_child($user_id, array(
            'child_name' => $name,
            'meta'       => $meta,
        ));
        if (!$id) {
            wp_send_json_error(array('message' => __('Could not save child. Please try again.', 'azure-plugin')), 500);
        }

        // Return the saved values keyed by field_key so the product form can
        // hydrate the matching inputs without a page reload.
        $fields = array();
        if ($grade !== '') {
            $fields[$keys['grade']] = $grade;
        }
        if ($teacher !== '') {
            $fields[$keys['teacher']] = $teacher;
        }

        wp_send_json_success(array(
            'id'     => (int) $id,
            'name'   => $name,
            'fields' => $fields,
        ));
    }
}
