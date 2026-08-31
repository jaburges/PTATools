<?php
/**
 * Finance WP role: Shop Manager plus PTA finance surfaces.
 *
 * Shop Manager already covers WooCommerce orders, products, reports,
 * Settings → Payments (Stripe), and PTA Tools → Selling. This role
 * clones those caps and adds `manage_pta_finance` so Membership and
 * donation records/settings are reachable without `manage_options`
 * (SSO, Backup, System stay admin-only).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Finance_Role {

    const ROLE_SLUG = 'finance';
    const CAP       = 'manage_pta_finance';

    /**
     * Create or converge the role. Safe to call on every request.
     * Also grants {@see self::CAP} to administrator so the Membership
     * submenu (which uses that single cap string) stays visible to admins.
     */
    public static function ensure_role() {
        $caps = self::capabilities();
        $role = get_role(self::ROLE_SLUG);
        if (!$role) {
            add_role(self::ROLE_SLUG, __('Finance', 'azure-plugin'), $caps);
        } else {
            foreach ($caps as $cap => $grant) {
                if ($grant && !$role->has_cap($cap)) {
                    $role->add_cap($cap);
                }
            }
            if ($role->has_cap('manage_options')) {
                $role->remove_cap('manage_options');
            }
        }

        $admin = get_role('administrator');
        if ($admin && !$admin->has_cap(self::CAP)) {
            $admin->add_cap(self::CAP);
        }
    }

    /**
     * Shop Manager caps plus finance, never manage_options.
     *
     * @return array<string,bool>
     */
    public static function capabilities() {
        $shop = get_role('shop_manager');
        $caps = $shop ? $shop->capabilities : self::fallback_shop_caps();
        unset($caps['manage_options']);
        $caps['read'] = true;
        $caps['manage_woocommerce'] = true;
        $caps['view_woocommerce_reports'] = true;
        $caps['export'] = true;
        $caps[self::CAP] = true;
        return $caps;
    }

    /**
     * True for administrators and users with {@see self::CAP}.
     * Shop Manager does not get this — they keep their current scope.
     *
     * @param int|null $user_id Null = current user.
     */
    public static function user_can($user_id = null) {
        if ($user_id === null) {
            return current_user_can('manage_options') || current_user_can(self::CAP);
        }
        return user_can($user_id, 'manage_options') || user_can($user_id, self::CAP);
    }

    /**
     * Minimal WooCommerce shop caps when shop_manager is not registered yet.
     *
     * @return array<string,bool>
     */
    private static function fallback_shop_caps() {
        return array(
            'read'                    => true,
            'manage_woocommerce'      => true,
            'view_woocommerce_reports'=> true,
            'edit_products'           => true,
            'edit_others_products'    => true,
            'publish_products'        => true,
            'read_private_products'   => true,
            'delete_products'         => true,
            'delete_others_products'  => true,
            'edit_shop_orders'        => true,
            'edit_others_shop_orders' => true,
            'publish_shop_orders'     => true,
            'read_private_shop_orders'=> true,
            'delete_shop_orders'      => true,
            'delete_others_shop_orders' => true,
            'edit_shop_coupons'       => true,
            'publish_shop_coupons'    => true,
            'upload_files'            => true,
            'export'                  => true,
        );
    }
}
