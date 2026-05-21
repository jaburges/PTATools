<?php
/**
 * Parent Role — Admin tools
 *
 * Admin-only companion to Azure_Parent_Role. Provides AJAX endpoints to
 * preview and bulk-migrate existing Subscriber users to the Parent role
 * so the import-driven Parent population can absorb pre-existing
 * subscribers without losing capabilities or login access.
 *
 * Loaded only on admin requests from azure-plugin.php so the class costs
 * nothing on the front-end.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Azure_Parent_Role')) {
    require_once AZURE_PLUGIN_PATH . 'includes/class-parent-role.php';
}

class Azure_Parent_Role_Admin {

    const NONCE_ACTION = 'azure_parent_role_admin';
    const BATCH_SIZE   = 100;

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_azure_pr_preview_subscribers', array($this, 'ajax_preview'));
        add_action('wp_ajax_azure_pr_migrate_subscribers', array($this, 'ajax_migrate'));
    }

    /**
     * Count Subscriber users + how many already carry the Parent role.
     * Cheap: one count query plus the WP_User_Query role filter.
     */
    public function ajax_preview() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }

        $sub_count    = $this->count_users_with_role('subscriber');
        $parent_count = $this->count_users_with_role(Azure_Parent_Role::ROLE_SLUG);
        $both_count   = $this->count_users_with_both_roles('subscriber', Azure_Parent_Role::ROLE_SLUG);

        wp_send_json_success(array(
            'subscribers'        => $sub_count,
            'parents'            => $parent_count,
            'subscriber_and_parent' => $both_count,
        ));
    }

    /**
     * Migrate up to BATCH_SIZE Subscriber users to Parent.
     *
     * Behavior:
     *   - Each user keeps every other role they carry (e.g. customer).
     *   - The Subscriber role is removed and the Parent role is added.
     *   - Login state is untouched: existing subscribers stay enabled,
     *     so we never set _pta_login_disabled on them.
     *   - The client polls until pending == 0.
     */
    public function ajax_migrate() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }

        $users = get_users(array(
            'role'    => 'subscriber',
            'number'  => self::BATCH_SIZE,
            'fields'  => array('ID'),
            'orderby' => 'ID',
            'order'   => 'ASC',
        ));

        $converted = 0;
        $skipped   = 0;
        foreach ($users as $u) {
            $user = get_user_by('id', (int) $u->ID);
            if (!$user) {
                $skipped++;
                continue;
            }
            $had_subscriber = in_array('subscriber', (array) $user->roles, true);
            if (!$had_subscriber) {
                $skipped++;
                continue;
            }
            $user->remove_role('subscriber');
            $user->add_role(Azure_Parent_Role::ROLE_SLUG);
            $converted++;
        }

        $remaining = $this->count_users_with_role('subscriber');

        wp_send_json_success(array(
            'converted' => $converted,
            'skipped'   => $skipped,
            'remaining' => $remaining,
            'done'      => ($remaining === 0),
        ));
    }

    private function count_users_with_role($role) {
        $q = new WP_User_Query(array(
            'role'   => $role,
            'fields' => 'ID',
            'number' => 1,
            'count_total' => true,
        ));
        return (int) $q->get_total();
    }

    private function count_users_with_both_roles($a, $b) {
        $q = new WP_User_Query(array(
            'role__in' => array($a, $b),
            'fields'   => 'ID',
            'number'   => -1,
        ));
        $ids = $q->get_results();
        $both = 0;
        foreach ($ids as $id) {
            $user = get_user_by('id', (int) $id);
            if (!$user) continue;
            if (in_array($a, (array) $user->roles, true) && in_array($b, (array) $user->roles, true)) {
                $both++;
            }
        }
        return $both;
    }
}
