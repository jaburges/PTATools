<?php
/**
 * Per-role wp-admin sidebar visibility, plus the access_pta_tools cap
 * so Azure AD users can see PTA Tools.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Admin_Menu_Customizer {

    const OPTION = 'azure_admin_menu_visibility';
    const CAP = 'access_pta_tools';
    const ID_SEP = '::';

    /** @var array|null Snapshot of $menu/$submenu before hides are applied. */
    public static $catalog = null;

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        self::ensure_capabilities();
        add_action('admin_menu', array($this, 'snapshot_menu'), 9998);
        add_action('admin_menu', array($this, 'apply_hidden_items'), 9999);
        add_action('wp_ajax_azure_save_admin_menu_visibility', array($this, 'ajax_save'));
    }

    /**
     * Administrator gets the cap explicitly (WP does not grant custom
     * caps to admin automatically). Azure AD User gets it via role sync.
     */
    public static function ensure_capabilities() {
        if (!function_exists('get_role')) {
            return;
        }
        foreach (array('administrator', 'azuread') as $slug) {
            $role = get_role($slug);
            if ($role && !$role->has_cap(self::CAP)) {
                $role->add_cap(self::CAP);
            }
        }
    }

    public static function user_can_use_pta_tools($user_id = null) {
        if ($user_id === null) {
            return current_user_can('manage_options') || current_user_can(self::CAP);
        }
        return user_can($user_id, 'manage_options') || user_can($user_id, self::CAP);
    }

    /**
     * IDs that must stay visible for administrators so they cannot
     * hide the last path into this editor.
     *
     * @return string[]
     */
    public static function locked_ids() {
        return array(
            'azure-plugin',
            'azure-plugin' . self::ID_SEP . 'azure-plugin-system',
        );
    }

    /**
     * @param array $menu    WordPress $menu
     * @param array $submenu WordPress $submenu
     * @return array<int,array{id:string,label:string,children:array}>
     */
    public static function catalog_from_globals($menu, $submenu) {
        $tree = array();
        if (!is_array($menu)) {
            return $tree;
        }
        foreach ($menu as $item) {
            if (!is_array($item) || empty($item[2])) {
                continue;
            }
            $slug = (string) $item[2];
            $classes = isset($item[4]) ? (string) $item[4] : '';
            if (strpos($classes, 'wp-menu-separator') !== false || strpos($slug, 'separator') === 0) {
                continue;
            }
            $label = self::clean_label(isset($item[0]) ? $item[0] : '');
            if ($label === '') {
                continue;
            }
            $node = array(
                'id' => $slug,
                'label' => $label,
                'children' => array(),
            );
            if (is_array($submenu) && !empty($submenu[$slug]) && is_array($submenu[$slug])) {
                foreach ($submenu[$slug] as $sub) {
                    if (!is_array($sub) || empty($sub[2])) {
                        continue;
                    }
                    $sub_label = self::clean_label(isset($sub[0]) ? $sub[0] : '');
                    if ($sub_label === '') {
                        continue;
                    }
                    $child_slug = (string) $sub[2];
                    $node['children'][] = array(
                        'id' => $slug . self::ID_SEP . $child_slug,
                        'parent' => $slug,
                        'slug' => $child_slug,
                        'label' => $sub_label,
                    );
                }
            }
            $tree[] = $node;
        }
        return $tree;
    }

    public static function clean_label($html) {
        $text = is_string($html) ? $html : '';
        $text = preg_replace('/<[^>]*>/', '', $text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Valid checkbox IDs from a catalog tree.
     *
     * @param array $catalog
     * @return string[]
     */
    public static function catalog_ids($catalog) {
        $ids = array();
        foreach ((array) $catalog as $node) {
            if (!empty($node['id'])) {
                $ids[] = $node['id'];
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                foreach ($node['children'] as $child) {
                    if (!empty($child['id'])) {
                        $ids[] = $child['id'];
                    }
                }
            }
        }
        return $ids;
    }

    /**
     * Hide an item only when every role the user holds has it unchecked.
     * Empty hidden list = all items visible (default).
     *
     * @param string[]             $user_roles
     * @param array<string,array>  $stored     option payload role => ['hidden' => ids]
     * @param bool                 $can_manage_options
     * @return string[]
     */
    public static function hidden_for_roles(array $user_roles, array $stored, $can_manage_options) {
        $user_roles = array_values(array_filter(array_map('strval', $user_roles)));
        if ($user_roles === array()) {
            return array();
        }
        $lists = array();
        foreach ($user_roles as $role) {
            $hidden = array();
            if (isset($stored[$role]['hidden']) && is_array($stored[$role]['hidden'])) {
                $hidden = array_values(array_map('strval', $stored[$role]['hidden']));
            }
            $lists[] = $hidden;
        }
        if (count($lists) === 1) {
            $hidden = $lists[0];
        } else {
            $hidden = array_values(array_intersect(...$lists));
        }
        if ($can_manage_options) {
            $hidden = array_values(array_diff($hidden, self::locked_ids()));
        }
        return $hidden;
    }

    /**
     * @param string[] $ids
     * @param string[] $valid_ids
     * @param string   $role
     * @return string[]
     */
    public static function sanitize_hidden(array $ids, array $valid_ids, $role) {
        $valid = array_flip($valid_ids);
        $out = array();
        foreach ($ids as $id) {
            $id = (string) $id;
            if ($id !== '' && isset($valid[$id])) {
                $out[] = $id;
            }
        }
        $out = array_values(array_unique($out));
        if ($role === 'administrator') {
            $out = array_values(array_diff($out, self::locked_ids()));
        }
        return $out;
    }

    public function snapshot_menu() {
        global $menu, $submenu;
        self::$catalog = self::catalog_from_globals(
            is_array($menu) ? $menu : array(),
            is_array($submenu) ? $submenu : array()
        );
    }

    public function apply_hidden_items() {
        if (!is_admin() || !function_exists('wp_get_current_user')) {
            return;
        }
        $user = wp_get_current_user();
        if (!$user || empty($user->roles)) {
            return;
        }
        $stored = get_option(self::OPTION, array());
        if (!is_array($stored)) {
            $stored = array();
        }
        $hidden = self::hidden_for_roles(
            (array) $user->roles,
            $stored,
            current_user_can('manage_options')
        );
        foreach ($hidden as $id) {
            $pos = strpos($id, self::ID_SEP);
            if ($pos === false) {
                remove_menu_page($id);
            } else {
                $parent = substr($id, 0, $pos);
                $child = substr($id, $pos + strlen(self::ID_SEP));
                remove_submenu_page($parent, $child);
            }
        }
    }

    public static function stored() {
        $stored = get_option(self::OPTION, array());
        return is_array($stored) ? $stored : array();
    }

    public static function role_choices() {
        $choices = array();
        if (!function_exists('wp_roles')) {
            return $choices;
        }
        $roles = wp_roles();
        $items = $roles && isset($roles->roles) ? $roles->roles : array();
        foreach ($items as $slug => $data) {
            $choices[$slug] = isset($data['name']) ? translate_user_role($data['name']) : $slug;
        }
        return $choices;
    }

    public function ajax_save() {
        check_ajax_referer('azure_plugin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        $role = isset($_POST['role']) ? sanitize_key(wp_unslash($_POST['role'])) : '';
        $roles = self::role_choices();
        if ($role === '' || !isset($roles[$role])) {
            wp_send_json_error('Unknown role');
        }
        $raw = isset($_POST['hidden']) ? wp_unslash($_POST['hidden']) : array();
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : array();
        }
        if (!is_array($raw)) {
            $raw = array();
        }
        $catalog = is_array(self::$catalog) ? self::$catalog : array();
        $hidden = self::sanitize_hidden($raw, self::catalog_ids($catalog), $role);
        $stored = self::stored();
        $stored[$role] = array('hidden' => $hidden);
        update_option(self::OPTION, $stored, false);
        wp_send_json_success(array(
            'role' => $role,
            'hidden' => $hidden,
        ));
    }
}
