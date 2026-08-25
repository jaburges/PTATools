<?php
/**
 * Membership module (v3.145)
 *
 * Two lists, kept separate on purpose:
 *   1. Paid members this school year — WooCommerce Family/Individual products.
 *      Powers the admin roster + the WA/LW CSV export.
 *   2. Parent directory — only parents whose own opt-in checkbox is truthy.
 *
 * No custom table. Membership is derived from orders; directory rows come
 * from existing `pta_pf_*` user meta on the purchaser / family-primary profile.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Membership_Module {

    const TRANSIENT_MAP     = 'azure_membership_map';
    const OPTION_FAMILY     = 'membership_family_product_ids';
    const OPTION_INDIVIDUAL = 'membership_individual_product_ids';
    const META_P1_OPT_IN    = 'pta_pf_parent_1_opt_in';
    const META_P2_OPT_IN    = 'pta_pf_parent_2_opt_in';
    const META_P1_NAME      = 'pta_pf_parent_1_name';
    const META_P2_NAME      = 'pta_pf_parent_2_name';
    const META_P1_EMAIL     = 'pta_pf_parent_1_email';
    const META_P2_EMAIL     = 'pta_pf_parent_2_email';
    const META_P1_CELL      = 'pta_pf_parent_1_cell';
    const META_P2_CELL      = 'pta_pf_parent_2_cell';
    const NONCE_ADMIN       = 'azure_membership_admin';
    const SHORTCODE_A       = 'parent-directory';
    const SHORTCODE_B       = 'Parent-directory';

    private static $instance = null;
    private static $assets_enqueued = false;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (class_exists('Azure_Database')) {
            Azure_Database::seed_membership_optin_fields();
        }

        add_shortcode(self::SHORTCODE_A, array($this, 'render_directory_shortcode'));
        add_shortcode(self::SHORTCODE_B, array($this, 'render_directory_shortcode'));

        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_frontend'));
        add_action('template_redirect', array($this, 'maybe_gate_directory_page'));

        add_action('woocommerce_order_status_changed', array($this, 'maybe_invalidate_map'), 10, 4);

        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            add_action('admin_menu', array($this, 'register_admin_page'), 25);
            add_action('admin_init', array($this, 'maybe_export_csv'));
            add_action('wp_dashboard_setup', array($this, 'register_dashboard_widget'));
        }
    }

    // ─── School year + product settings ─────────────────────────────

    /**
     * Same window as Orders Reports `this_school_year`: Aug 1 of the
     * current year if today is Aug–Dec, otherwise Aug 1 of the prior year,
     * through end of today.
     *
     * @return array{from:string,to:string,label:string}
     */
    public static function school_year_range() {
        $tz    = wp_timezone();
        $now   = new DateTimeImmutable('now', $tz);
        $month = (int) $now->format('n');
        $year  = (int) $now->format('Y');
        $start_year = ($month >= 8) ? $year : ($year - 1);
        $from = sprintf('%04d-08-01 00:00:00', $start_year);
        $to   = $now->setTime(23, 59, 59)->format('Y-m-d H:i:s');
        $label = $start_year . '–' . ($start_year + 1);
        return array('from' => $from, 'to' => $to, 'label' => $label);
    }

    public static function get_family_product_ids() {
        return self::normalize_id_list(Azure_Settings::get_setting(self::OPTION_FAMILY, array()));
    }

    public static function get_individual_product_ids() {
        return self::normalize_id_list(Azure_Settings::get_setting(self::OPTION_INDIVIDUAL, array()));
    }

    public static function save_product_ids($family_ids, $individual_ids) {
        Azure_Settings::update_setting(self::OPTION_FAMILY, self::normalize_id_list($family_ids));
        Azure_Settings::update_setting(self::OPTION_INDIVIDUAL, self::normalize_id_list($individual_ids));
        self::flush_member_map();
    }

    private static function normalize_id_list($ids) {
        if (!is_array($ids)) {
            $ids = preg_split('/[\s,]+/', (string) $ids, -1, PREG_SPLIT_NO_EMPTY);
        }
        $out = array();
        foreach ((array) $ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[] = $id;
            }
        }
        return array_values(array_unique($out));
    }

    // ─── Member map ─────────────────────────────────────────────────

    /**
     * @return array<int, array{type:string,order_id:int,paid_at:string}>
     */
    public static function get_member_map() {
        $range = self::school_year_range();
        $ver = (int) get_option('azure_membership_map_ver', 1);
        $cache_key = self::TRANSIENT_MAP . '_' . $ver . '_' . md5($range['from'] . '|' . implode(',', self::get_family_product_ids()) . '|' . implode(',', self::get_individual_product_ids()));
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $map = self::build_member_map($range);
        set_transient($cache_key, $map, HOUR_IN_SECONDS);
        return $map;
    }

    public static function flush_member_map() {
        update_option('azure_membership_map_ver', (int) get_option('azure_membership_map_ver', 1) + 1, false);
    }

    /**
     * Drop the cached map when a membership product order changes status.
     */
    public function maybe_invalidate_map($order_id, $old_status, $new_status, $order) {
        if (!is_object($order) || !method_exists($order, 'get_items')) {
            return;
        }
        $watched = array_flip(array_merge(self::get_family_product_ids(), self::get_individual_product_ids()));
        if (empty($watched)) {
            return;
        }
        foreach ($order->get_items() as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product_id')) {
                continue;
            }
            $pid = (int) $item->get_product_id();
            $vid = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
            if (isset($watched[$pid]) || ($vid && isset($watched[$vid]))) {
                self::flush_member_map();
                return;
            }
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            if ($product && method_exists($product, 'get_parent_id')) {
                $parent = (int) $product->get_parent_id();
                if ($parent && isset($watched[$parent])) {
                    self::flush_member_map();
                    return;
                }
            }
        }
    }

    private static function build_member_map(array $range) {
        $map = array();
        $family_ids = array_flip(self::get_family_product_ids());
        $indiv_ids  = array_flip(self::get_individual_product_ids());
        if (empty($family_ids) && empty($indiv_ids)) {
            return $map;
        }
        if (!function_exists('wc_get_orders')) {
            return $map;
        }

        $orders = wc_get_orders(array(
            'status'       => array('processing', 'completed'),
            'type'         => 'shop_order',
            'date_created' => $range['from'] . '...' . $range['to'],
            'limit'        => -1,
            'return'       => 'objects',
        ));

        foreach ($orders as $order) {
            $type = self::order_membership_type($order, $family_ids, $indiv_ids);
            if (!$type) {
                continue;
            }
            $user_id = (int) $order->get_user_id();
            if (!$user_id) {
                continue;
            }
            $paid_at = $order->get_date_paid()
                ? $order->get_date_paid()->date('Y-m-d H:i:s')
                : $order->get_date_created()->date('Y-m-d H:i:s');
            $entry = array(
                'type'     => $type,
                'order_id' => (int) $order->get_id(),
                'paid_at'  => $paid_at,
            );
            self::apply_member_entry($map, $user_id, $entry);

            if ($type === 'family') {
                $other = self::co_parent_user_id($user_id);
                if ($other) {
                    self::apply_member_entry($map, $other, $entry);
                }
            }
        }

        return $map;
    }

    private static function order_membership_type($order, array $family_ids, array $indiv_ids) {
        $found_family = false;
        $found_indiv  = false;
        foreach ($order->get_items() as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product_id')) {
                continue;
            }
            if (method_exists($item, 'get_meta') && $item->get_meta('_pta_donated_product')) {
                continue;
            }
            $candidates = array((int) $item->get_product_id());
            if (method_exists($item, 'get_variation_id')) {
                $vid = (int) $item->get_variation_id();
                if ($vid) {
                    $candidates[] = $vid;
                }
            }
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            if ($product && method_exists($product, 'get_parent_id')) {
                $parent = (int) $product->get_parent_id();
                if ($parent) {
                    $candidates[] = $parent;
                }
            }
            foreach ($candidates as $pid) {
                if (isset($family_ids[$pid])) {
                    $found_family = true;
                }
                if (isset($indiv_ids[$pid])) {
                    $found_indiv = true;
                }
            }
        }
        if ($found_family) {
            return 'family';
        }
        if ($found_indiv) {
            return 'individual';
        }
        return '';
    }

    /**
     * Family wins over individual when the same person appears on both.
     */
    private static function apply_member_entry(array &$map, $user_id, array $entry) {
        $user_id = (int) $user_id;
        if (!$user_id) {
            return;
        }
        if (!isset($map[$user_id]) || ($entry['type'] === 'family' && $map[$user_id]['type'] !== 'family')) {
            $map[$user_id] = $entry;
        }
    }

    private static function co_parent_user_id($user_id) {
        if (!class_exists('Azure_User_Children')) {
            return 0;
        }
        $family = Azure_User_Children::get_family_for_user((int) $user_id);
        if (!$family) {
            return 0;
        }
        $primary   = (int) $family->primary_user_id;
        $secondary = (int) $family->secondary_user_id;
        if ($primary === (int) $user_id) {
            return $secondary;
        }
        if ($secondary === (int) $user_id) {
            return $primary;
        }
        return 0;
    }

    // ─── Opt-in helpers ─────────────────────────────────────────────

    public static function is_opted_in($value) {
        if ($value === true || $value === 1) {
            return true;
        }
        $v = strtolower(trim((string) $value));
        return in_array($v, array('1', 'yes', 'true', 'on'), true);
    }

    private static function opted_in_user_ids($meta_key) {
        global $wpdb;
        $placeholders = implode(',', array_fill(0, 5, '%s'));
        $sql = $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
             WHERE meta_key = %s AND meta_value IN ($placeholders)",
            $meta_key,
            '1',
            'Yes',
            'yes',
            'true',
            'on'
        );
        $ids = $wpdb->get_col($sql);
        return array_values(array_unique(array_map('intval', $ids)));
    }

    // ─── Directory visibility ───────────────────────────────────────

    public static function user_can_view_directory($user = null) {
        if ($user === null) {
            $user = wp_get_current_user();
        }
        if (!$user || !$user->exists()) {
            return false;
        }
        if (user_can($user, 'manage_options')) {
            return true;
        }
        if (user_can($user, 'azure_ad_user')) {
            return true;
        }
        $roles = (array) $user->roles;
        if (in_array('parent', $roles, true) || in_array('school_staff', $roles, true)) {
            return true;
        }
        self::ensure_sso_sync();
        if (class_exists('Azure_SSO_Sync')) {
            $sso = Azure_SSO_Sync::resolve_configured_role_slug();
            if ($sso && in_array($sso, $roles, true)) {
                return true;
            }
        }
        if (get_user_meta($user->ID, 'azure_object_id', true)) {
            return true;
        }
        return self::user_has_active_pta_role((int) $user->ID);
    }

    private static function user_has_active_pta_role($user_id) {
        $ids = self::active_pta_assignee_ids();
        return isset($ids[(int) $user_id]);
    }

    /**
     * @return array<int,true>
     */
    private static function active_pta_assignee_ids() {
        static $lookup = null;
        if ($lookup !== null) {
            return $lookup;
        }
        $lookup = array();
        self::ensure_pta_database();
        if (!class_exists('Azure_PTA_Database')) {
            return $lookup;
        }
        global $wpdb;
        $table = Azure_PTA_Database::get_table_name('assignments');
        if (!$table) {
            return $lookup;
        }
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$table} WHERE status = %s",
            'active'
        ));
        foreach ($ids as $id) {
            $lookup[(int) $id] = true;
        }
        return $lookup;
    }

    // ─── Admin roster universe ──────────────────────────────────────

    /**
     * Union of parents, school staff, PTA role-holders, and Azure AD users.
     *
     * @return int[]
     */
    public static function roster_user_ids() {
        $ids = array();

        foreach (array('parent', 'school_staff') as $role) {
            $users = get_users(array('role' => $role, 'fields' => 'ID'));
            foreach ($users as $id) {
                $ids[(int) $id] = true;
            }
        }

        $sso_roles = self::azure_ad_role_slugs();
        foreach ($sso_roles as $role) {
            $users = get_users(array('role' => $role, 'fields' => 'ID'));
            foreach ($users as $id) {
                $ids[(int) $id] = true;
            }
        }

        $ad_users = get_users(array(
            'meta_key'     => 'azure_object_id',
            'meta_compare' => '!=',
            'meta_value'   => '',
            'fields'       => 'ID',
        ));
        foreach ($ad_users as $id) {
            $ids[(int) $id] = true;
        }

        foreach (array_keys(self::active_pta_assignee_ids()) as $id) {
            $ids[(int) $id] = true;
        }

        return array_keys($ids);
    }

    private static function azure_ad_role_slugs() {
        $slugs = array();
        self::ensure_sso_sync();
        if (class_exists('Azure_SSO_Sync')) {
            $configured = Azure_SSO_Sync::resolve_configured_role_slug();
            if ($configured) {
                $slugs[] = $configured;
            }
        }
        global $wp_roles;
        if ($wp_roles instanceof WP_Roles) {
            foreach ($wp_roles->roles as $slug => $data) {
                if (!empty($data['capabilities']['azure_ad_user'])) {
                    $slugs[] = $slug;
                }
            }
        }
        return array_values(array_unique($slugs));
    }

    /**
     * Build hydrated roster rows for the given user IDs.
     *
     * @param int[] $user_ids
     * @return array<int, array>
     */
    public static function build_roster_rows(array $user_ids) {
        $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
        if (empty($user_ids)) {
            return array();
        }

        $users = get_users(array('include' => $user_ids, 'orderby' => 'display_name', 'order' => 'ASC'));
        $map   = self::get_member_map();
        $pta   = self::pta_roles_by_user($user_ids);
        $kids  = self::children_by_user($user_ids);
        $sso_slugs = array_flip(self::azure_ad_role_slugs());

        $rows = array();
        foreach ($users as $user) {
            $uid = (int) $user->ID;
            $roles = (array) $user->roles;
            $role_labels = array();
            if (in_array('parent', $roles, true)) {
                $role_labels[] = 'Parent';
            }
            if (in_array('school_staff', $roles, true)) {
                $role_labels[] = 'School staff';
            }
            if (isset($sso_slugs) && array_intersect(array_keys($sso_slugs), $roles)) {
                $role_labels[] = 'Azure AD';
            } elseif (user_can($user, 'azure_ad_user') || get_user_meta($uid, 'azure_object_id', true)) {
                $role_labels[] = 'Azure AD';
            }
            if (!empty($pta[$uid])) {
                foreach ($pta[$uid] as $role_name) {
                    $role_labels[] = $role_name;
                }
            }
            $role_labels = array_values(array_unique($role_labels));

            $membership = isset($map[$uid]) ? $map[$uid] : null;
            $children   = isset($kids[$uid]) ? $kids[$uid] : array();

            $rows[] = array(
                'user_id'      => $uid,
                'name'         => $user->display_name,
                'email'        => $user->user_email,
                'role_types'   => $role_labels,
                'membership'   => $membership ? $membership['type'] : 'none',
                'paid_at'      => $membership ? $membership['paid_at'] : '',
                'children'     => $children,
            );
        }
        return $rows;
    }

    /**
     * @param int[] $user_ids
     * @return array<int, string[]>
     */
    private static function pta_roles_by_user(array $user_ids) {
        $out = array();
        self::ensure_pta_manager();
        if (!class_exists('Azure_PTA_Manager')) {
            return $out;
        }
        $manager = Azure_PTA_Manager::get_instance();
        if (!method_exists($manager, 'get_assignments_for_users')) {
            return $out;
        }
        $rows = $manager->get_assignments_for_users($user_ids);
        foreach ($rows as $row) {
            $uid = (int) $row->user_id;
            $label = !empty($row->role_name) ? $row->role_name : '';
            if ($label === '') {
                continue;
            }
            $out[$uid][] = $label;
        }
        return $out;
    }

    /**
     * @param int[] $user_ids
     * @return array<int, array<int, array{name:string,grade:string}>>
     */
    private static function children_by_user(array $user_ids) {
        $out = array();
        if (!class_exists('Azure_User_Children') || !class_exists('Azure_Database')) {
            return $out;
        }
        global $wpdb;
        $fam_table = Azure_Database::get_table_name('connected_family');
        $kid_table = Azure_Database::get_table_name('user_children');
        $meta_table = Azure_Database::get_table_name('user_children_meta');
        if (!$fam_table || !$kid_table || empty($user_ids)) {
            return $out;
        }

        $id_list = implode(',', array_map('intval', $user_ids));
        $families = $wpdb->get_results(
            "SELECT id, primary_user_id, secondary_user_id
             FROM {$fam_table}
             WHERE primary_user_id IN ({$id_list}) OR secondary_user_id IN ({$id_list})"
        );
        if (empty($families)) {
            return $out;
        }

        $family_ids = array();
        $users_by_family = array();
        foreach ($families as $f) {
            $fid = (int) $f->id;
            $family_ids[] = $fid;
            foreach (array((int) $f->primary_user_id, (int) $f->secondary_user_id) as $uid) {
                if ($uid) {
                    $users_by_family[$fid][] = $uid;
                }
            }
        }
        $fid_list = implode(',', array_map('intval', array_unique($family_ids)));
        $kids = $wpdb->get_results(
            "SELECT id, family_id, child_name FROM {$kid_table}
             WHERE family_id IN ({$fid_list}) AND is_active = 1
             ORDER BY child_name ASC"
        );
        if (empty($kids)) {
            return $out;
        }

        $grade_by_child = array();
        if ($meta_table) {
            $grade_keys = self::grade_meta_keys();
            $kid_ids = array_map(function ($k) { return (int) $k->id; }, $kids);
            $kid_list = implode(',', $kid_ids);
            $key_in = implode(',', array_fill(0, count($grade_keys), '%s'));
            $sql = $wpdb->prepare(
                "SELECT child_id, meta_value FROM {$meta_table}
                 WHERE child_id IN ({$kid_list}) AND meta_key IN ($key_in)",
                $grade_keys
            );
            foreach ($wpdb->get_results($sql) as $row) {
                if ($row->meta_value !== '') {
                    $grade_by_child[(int) $row->child_id] = $row->meta_value;
                }
            }
        }

        foreach ($kids as $kid) {
            $entry = array(
                'name'  => $kid->child_name,
                'grade' => isset($grade_by_child[(int) $kid->id]) ? $grade_by_child[(int) $kid->id] : '',
            );
            $fid = (int) $kid->family_id;
            if (empty($users_by_family[$fid])) {
                continue;
            }
            foreach ($users_by_family[$fid] as $uid) {
                $out[$uid][] = $entry;
            }
        }
        return $out;
    }

    private static function grade_meta_keys() {
        $keys = array('pta_pf_child_grade', 'pta_pf_childsgrade', 'pta_pf_childs_grade');
        if (class_exists('Azure_Product_Fields_Module')) {
            $resolved = Azure_Product_Fields_Module::get_child_profile_field_keys();
            if (!empty($resolved['grade'])) {
                $keys[] = 'pta_pf_' . $resolved['grade'];
            }
        }
        return array_values(array_unique($keys));
    }

    // ─── Directory rows (opt-in only) ───────────────────────────────

    /**
     * Rows for the public-facing directory. Built only from opted-in
     * parent-1 / parent-2 meta. Missing or empty opt-in is excluded.
     *
     * @return array<int, array>
     */
    public static function build_directory_rows() {
        $p1_ids = self::opted_in_user_ids(self::META_P1_OPT_IN);
        $p2_ids = self::opted_in_user_ids(self::META_P2_OPT_IN);
        $owner_ids = array_values(array_unique(array_merge($p1_ids, $p2_ids)));
        if (empty($owner_ids)) {
            return array();
        }

        $p1_lookup = array_flip($p1_ids);
        $p2_lookup = array_flip($p2_ids);
        $kids_by_user = self::children_by_user($owner_ids);
        $rows = array();

        foreach ($owner_ids as $uid) {
            $user = get_userdata($uid);
            if (!$user) {
                continue;
            }
            $children = isset($kids_by_user[$uid]) ? $kids_by_user[$uid] : array();

            if (isset($p1_lookup[$uid]) && self::is_opted_in(get_user_meta($uid, self::META_P1_OPT_IN, true))) {
                $name = trim((string) get_user_meta($uid, self::META_P1_NAME, true));
                if ($name === '') {
                    $name = $user->display_name;
                }
                $rows[] = array(
                    'slot'     => 'parent_1',
                    'owner_id' => $uid,
                    'name'     => $name,
                    'email'    => (string) get_user_meta($uid, self::META_P1_EMAIL, true),
                    'cell'     => (string) get_user_meta($uid, self::META_P1_CELL, true),
                    'children' => $children,
                );
            }

            if (isset($p2_lookup[$uid]) && self::is_opted_in(get_user_meta($uid, self::META_P2_OPT_IN, true))) {
                $name = trim((string) get_user_meta($uid, self::META_P2_NAME, true));
                if ($name === '') {
                    continue;
                }
                $rows[] = array(
                    'slot'     => 'parent_2',
                    'owner_id' => $uid,
                    'name'     => $name,
                    'email'    => (string) get_user_meta($uid, self::META_P2_EMAIL, true),
                    'cell'     => (string) get_user_meta($uid, self::META_P2_CELL, true),
                    'children' => $children,
                );
            }
        }

        usort($rows, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        return $rows;
    }

    // ─── Shortcode ──────────────────────────────────────────────────

    public function render_directory_shortcode($atts = array()) {
        $atts = shortcode_atts(array(
            'show_email' => 'false',
            'show_cell'  => 'false',
        ), $atts, self::SHORTCODE_A);
        $show_email = filter_var($atts['show_email'], FILTER_VALIDATE_BOOLEAN);
        $show_cell  = filter_var($atts['show_cell'], FILTER_VALIDATE_BOOLEAN);

        $this->enqueue_frontend_assets();

        if (!is_user_logged_in()) {
            return $this->render_login_shell();
        }
        if (!self::user_can_view_directory()) {
            return '<div class="pta-parent-directory pta-parent-directory--denied">'
                . '<p>' . esc_html__('This directory is for Wilder families and staff.', 'azure-plugin') . '</p>'
                . '</div>';
        }

        $rows = self::build_directory_rows();
        return $this->render_directory_table($rows, $show_email, $show_cell);
    }

    private function render_login_shell() {
        $login_url = wp_login_url();
        $html  = '<div class="pta-parent-directory pta-parent-directory--login">';
        $html .= '<p>' . esc_html__('Sign in to view the parent directory.', 'azure-plugin') . '</p>';
        $html .= '<p><a class="pta-parent-directory__login" href="' . esc_url($login_url) . '">'
            . esc_html__('Sign in', 'azure-plugin') . '</a></p>';
        $html .= '</div>';
        return $html;
    }

    private function render_directory_table(array $rows, $show_email, $show_cell) {
        $grades = array();
        foreach ($rows as $row) {
            foreach ($row['children'] as $child) {
                if (!empty($child['grade'])) {
                    $grades[$child['grade']] = true;
                }
            }
        }
        ksort($grades, SORT_NATURAL | SORT_FLAG_CASE);

        ob_start();
        ?>
        <div class="pta-parent-directory" data-pta-parent-directory="1">
            <div class="pta-parent-directory__toolbar">
                <input type="search" class="pta-parent-directory__search" placeholder="<?php esc_attr_e('Search names or children…', 'azure-plugin'); ?>" />
                <?php if (!empty($grades)): ?>
                <label class="pta-parent-directory__grade-label">
                    <span><?php esc_html_e('Grade', 'azure-plugin'); ?></span>
                    <select class="pta-parent-directory__grade">
                        <option value=""><?php esc_html_e('All grades', 'azure-plugin'); ?></option>
                        <?php foreach (array_keys($grades) as $grade): ?>
                            <option value="<?php echo esc_attr($grade); ?>"><?php echo esc_html($grade); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php endif; ?>
                <span class="pta-parent-directory__count"></span>
            </div>
            <table class="pta-parent-directory__table">
                <thead>
                    <tr>
                        <th data-sort="name"><?php esc_html_e('Name', 'azure-plugin'); ?></th>
                        <th data-sort="children"><?php esc_html_e('Children', 'azure-plugin'); ?></th>
                        <?php if ($show_email): ?><th data-sort="email"><?php esc_html_e('Email', 'azure-plugin'); ?></th><?php endif; ?>
                        <?php if ($show_cell): ?><th data-sort="cell"><?php esc_html_e('Phone', 'azure-plugin'); ?></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr class="pta-parent-directory__empty"><td colspan="<?php echo $show_email && $show_cell ? 4 : ($show_email || $show_cell ? 3 : 2); ?>">
                        <?php esc_html_e('No parents have opted in to the directory yet.', 'azure-plugin'); ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row):
                        $child_bits = array();
                        $grade_bits = array();
                        foreach ($row['children'] as $child) {
                            $label = $child['name'];
                            if ($child['grade'] !== '') {
                                $label .= ' (' . $child['grade'] . ')';
                                $grade_bits[] = $child['grade'];
                            }
                            $child_bits[] = $label;
                        }
                        $children_text = implode(', ', $child_bits);
                        ?>
                    <tr data-name="<?php echo esc_attr($row['name']); ?>"
                        data-children="<?php echo esc_attr($children_text); ?>"
                        data-email="<?php echo esc_attr($row['email']); ?>"
                        data-cell="<?php echo esc_attr($row['cell']); ?>"
                        data-grades="<?php echo esc_attr(implode('|', $grade_bits)); ?>">
                        <td><?php echo esc_html($row['name']); ?></td>
                        <td><?php echo esc_html($children_text); ?></td>
                        <?php if ($show_email): ?><td><?php echo esc_html($row['email']); ?></td><?php endif; ?>
                        <?php if ($show_cell): ?><td><?php echo esc_html($row['cell']); ?></td><?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    public function maybe_enqueue_frontend() {
        if (!$this->current_post_has_directory_shortcode()) {
            return;
        }
        $this->enqueue_frontend_assets();
    }

    private function enqueue_frontend_assets() {
        if (self::$assets_enqueued) {
            return;
        }
        self::$assets_enqueued = true;
        $css = AZURE_PLUGIN_PATH . 'css/membership-directory.css';
        $js  = AZURE_PLUGIN_PATH . 'js/membership-directory.js';
        wp_enqueue_style(
            'pta-membership-directory',
            AZURE_PLUGIN_URL . 'css/membership-directory.css',
            array(),
            file_exists($css) ? (string) filemtime($css) : AZURE_PLUGIN_VERSION
        );
        wp_enqueue_script(
            'pta-membership-directory',
            AZURE_PLUGIN_URL . 'js/membership-directory.js',
            array(),
            file_exists($js) ? (string) filemtime($js) : AZURE_PLUGIN_VERSION,
            true
        );
    }

    /**
     * Defense in depth: the shortcode already renders zero names to guests.
     * This keeps a guessed URL from serving a stale signed-in table if a
     * page cache ever ignored Cache-Control — guests still get the generic
     * login shell (cacheable); signed-in unauthorized users get the denial.
     */
    public function maybe_gate_directory_page() {
        if (is_admin() || !is_singular()) {
            return;
        }
        if (!$this->current_post_has_directory_shortcode()) {
            return;
        }
        if (is_user_logged_in() && !self::user_can_view_directory()) {
            // Shortcode renders the denial; nothing else to do.
            return;
        }
    }

    private function current_post_has_directory_shortcode() {
        $post = get_post();
        if (!$post || empty($post->post_content)) {
            return false;
        }
        return has_shortcode($post->post_content, self::SHORTCODE_A)
            || has_shortcode($post->post_content, self::SHORTCODE_B);
    }

    // ─── Dashboard widget ───────────────────────────────────────────

    public function register_dashboard_widget() {
        if (!current_user_can('manage_options')) {
            return;
        }
        wp_add_dashboard_widget(
            'azure_membership_stats',
            __('Membership', 'azure-plugin'),
            array($this, 'render_dashboard_widget')
        );
    }

    /**
     * @return array{parents:int,memberships:int,bought_week:int,year_label:string}
     */
    public static function dashboard_stats() {
        $counts  = function_exists('count_users') ? count_users() : array();
        $role    = class_exists('Azure_Parent_Role') ? Azure_Parent_Role::ROLE_SLUG : 'parent';
        $parents = isset($counts['avail_roles'][$role]) ? (int) $counts['avail_roles'][$role] : 0;

        $map = self::get_member_map();
        $range = self::school_year_range();

        return array(
            'parents'      => $parents,
            'memberships'  => count($map),
            'bought_week'  => self::count_membership_orders_since('-7 days'),
            'year_label'   => $range['label'],
        );
    }

    /**
     * Paid membership orders (not donated) created since a relative time.
     */
    public static function count_membership_orders_since($relative) {
        $family_ids = array_flip(self::get_family_product_ids());
        $indiv_ids  = array_flip(self::get_individual_product_ids());
        if ((empty($family_ids) && empty($indiv_ids)) || !function_exists('wc_get_orders')) {
            return 0;
        }

        $tz   = wp_timezone();
        $to   = new DateTimeImmutable('now', $tz);
        $from = $to->modify($relative);
        $orders = wc_get_orders(array(
            'status'       => array('processing', 'completed'),
            'type'         => 'shop_order',
            'date_created' => $from->format('Y-m-d H:i:s') . '...' . $to->format('Y-m-d H:i:s'),
            'limit'        => -1,
            'return'       => 'objects',
        ));

        $count = 0;
        foreach ($orders as $order) {
            if (self::order_membership_type($order, $family_ids, $indiv_ids)) {
                $count++;
            }
        }
        return $count;
    }

    public function render_dashboard_widget() {
        $stats = self::dashboard_stats();
        $page  = admin_url('admin.php?page=azure-plugin-membership');
        ?>
        <style>
            .azure-membership-widget .stat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 12px; }
            .azure-membership-widget .stat-card { background: #f9f9f9; padding: 12px; text-align: center; border-radius: 4px; border-left: 3px solid #0078d4; }
            .azure-membership-widget .stat-card .stat-number { font-size: 22px; font-weight: 700; color: #1d2327; line-height: 1.2; }
            .azure-membership-widget .stat-card .stat-label { font-size: 11px; color: #646970; text-transform: uppercase; letter-spacing: 0.3px; }
            .azure-membership-widget .stat-card.warm { border-left-color: #2271b1; }
            .azure-membership-widget .stat-card.fresh { border-left-color: #00a32a; }
        </style>
        <div class="azure-membership-widget">
            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo esc_html(number_format_i18n($stats['parents'])); ?></div>
                    <div class="stat-label"><?php esc_html_e('Parents', 'azure-plugin'); ?></div>
                </div>
                <div class="stat-card warm">
                    <div class="stat-number"><?php echo esc_html(number_format_i18n($stats['memberships'])); ?></div>
                    <div class="stat-label"><?php esc_html_e('Memberships', 'azure-plugin'); ?></div>
                </div>
                <div class="stat-card fresh">
                    <div class="stat-number"><?php echo esc_html(number_format_i18n($stats['bought_week'])); ?></div>
                    <div class="stat-label"><?php esc_html_e('Bought last week', 'azure-plugin'); ?></div>
                </div>
            </div>
            <p class="description" style="margin:0 0 8px;font-size:11px;color:#646970;">
                <?php
                printf(
                    /* translators: %s: school year label like 2026–2027 */
                    esc_html__('Memberships are paid Family or Individual products this school year (%s). Last week counts orders, not people. Donated memberships are excluded.', 'azure-plugin'),
                    esc_html($stats['year_label'])
                );
                ?>
            </p>
            <p style="margin:0;">
                <a class="button button-small" href="<?php echo esc_url($page); ?>"><?php esc_html_e('Open Membership', 'azure-plugin'); ?></a>
            </p>
        </div>
        <?php
    }

    // ─── Admin page ─────────────────────────────────────────────────

    public function register_admin_page() {
        add_submenu_page(
            'azure-plugin',
            __('Membership', 'azure-plugin'),
            __('Membership', 'azure-plugin'),
            'manage_options',
            'azure-plugin-membership',
            array($this, 'render_admin_page')
        );
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Forbidden', 'azure-plugin'));
        }
        $page = AZURE_PLUGIN_PATH . 'admin/membership-page.php';
        if (file_exists($page)) {
            include $page;
            return;
        }
        echo '<div class="wrap"><h1>' . esc_html__('Membership', 'azure-plugin') . '</h1></div>';
    }

    public function maybe_export_csv() {
        if (!is_admin() || empty($_GET['page']) || $_GET['page'] !== 'azure-plugin-membership') {
            return;
        }
        if (empty($_GET['export']) || $_GET['export'] !== 'csv') {
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Forbidden', 'azure-plugin'));
        }
        check_admin_referer(self::NONCE_ADMIN);

        $map = self::get_member_map();
        $member_ids = array_keys($map);
        $rows = self::build_roster_rows($member_ids);

        $filename = 'wilderptsa-membership-' . gmdate('Y-m-d') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, array('Name', 'Email', 'Membership Type', 'Paid Date', 'Children', 'Role Types'));
        foreach ($rows as $row) {
            if ($row['membership'] === 'none') {
                continue;
            }
            $child_bits = array();
            foreach ($row['children'] as $child) {
                $child_bits[] = $child['grade'] !== ''
                    ? $child['name'] . ' (' . $child['grade'] . ')'
                    : $child['name'];
            }
            fputcsv($out, array(
                $row['name'],
                $row['email'],
                ucfirst($row['membership']),
                $row['paid_at'],
                implode('; ', $child_bits),
                implode('; ', $row['role_types']),
            ));
        }
        fclose($out);
        exit;
    }

    private static function ensure_sso_sync() {
        if (class_exists('Azure_SSO_Sync')) {
            return;
        }
        $path = AZURE_PLUGIN_PATH . 'includes/class-sso-sync.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }

    private static function ensure_pta_database() {
        if (class_exists('Azure_PTA_Database')) {
            return;
        }
        $path = AZURE_PLUGIN_PATH . 'includes/class-pta-database.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }

    private static function ensure_pta_manager() {
        self::ensure_pta_database();
        if (class_exists('Azure_PTA_Manager')) {
            return;
        }
        $path = AZURE_PLUGIN_PATH . 'includes/class-pta-manager.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }
}
