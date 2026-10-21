<?php
/**
 * Who may buy a section set to "Eligible families" (e.g. Parents of
 * performers): a signed-in person whose account or billing email is on the
 * performance's uploaded list, or who bought one of its chosen products
 * (e.g. Theater Roles) on or after its start date. Guest orders count when
 * their billing email matches the account.
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Tickets_Access {

    /** @var array<string,bool> */
    private static $cache = array();

    public static function flush() {
        self::$cache = array();
    }

    /**
     * @return string[] The account's lowercase email addresses.
     */
    public static function user_emails($user_id) {
        $user = get_user_by('id', (int) $user_id);
        if (!$user) {
            return array();
        }
        $emails = array(strtolower((string) $user->user_email), strtolower((string) get_user_meta($user->ID, 'billing_email', true)));
        return array_values(array_unique(array_filter($emails, 'is_email')));
    }

    public static function is_family($user_id, $product_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return false;
        }
        $key = $user_id . ':' . (int) $product_id;
        if (!isset(self::$cache[$key])) {
            self::$cache[$key] = self::check($user_id, Azure_Tickets_Config::family_rules($product_id));
        }
        return self::$cache[$key];
    }

    private static function check($user_id, array $rules) {
        $emails = self::user_emails($user_id);
        if (array_intersect($emails, $rules['emails'])) {
            return true;
        }
        return $rules['products'] && self::bought_since($user_id, $emails, $rules['products'], $rules['since']);
    }

    public static function bought_since($user_id, array $emails, array $product_ids, $since) {
        if (!function_exists('wc_get_orders')) {
            return false;
        }
        $orders = wc_get_orders(array(
            'customer'     => array_merge(array((int) $user_id), $emails),
            'status'       => array('wc-processing', 'wc-completed'),
            'date_created' => '>=' . $since,
            'limit'        => 50,
        ));
        $wanted = array_flip(array_map('intval', $product_ids));
        foreach ((array) $orders as $order) {
            foreach ($order->get_items() as $item) {
                if (isset($wanted[(int) $item->get_product_id()]) || isset($wanted[(int) $item->get_variation_id()])) {
                    return true;
                }
            }
        }
        return false;
    }
}
