<?php
/**
 * Plugin Name: One-off — List roles + caps relevant to Selling > Auction
 * Description: READ-ONLY. Returns the WP roles defined on this site, their
 *              capabilities relevant to Selling/Auction admin access
 *              (manage_options, manage_woocommerce, edit_shop_orders, plus
 *              any azure_* caps), so we can decide which role should get
 *              Selling > Auction access without creating duplicates.
 *
 * Trigger: GET /?pta_role_audit=<TOKEN>
 */

if (!defined('ABSPATH')) {
    return;
}

add_action('init', function () {
    if (empty($_GET['pta_role_audit'])) {
        return;
    }
    $expected_token = 'd4d4306c0c8ddc80312c3554aefbedbd';
    if (!hash_equals($expected_token, (string) $_GET['pta_role_audit'])) {
        status_header(403);
        header('Content-Type: text/plain');
        echo 'forbidden';
        exit;
    }

    $relevant_caps = array(
        'manage_options',
        'manage_woocommerce',
        'edit_shop_orders',
        'view_woocommerce_reports',
    );

    $roles = wp_roles()->roles;
    $out_roles = array();
    foreach ($roles as $slug => $data) {
        $caps = isset($data['capabilities']) ? array_keys(array_filter($data['capabilities'])) : array();
        $relevant = array_values(array_intersect($caps, $relevant_caps));
        $azure_caps = array_values(array_filter($caps, function ($c) {
            return strpos($c, 'azure_') === 0;
        }));
        $out_roles[$slug] = array(
            'display_name'        => $data['name'] ?? $slug,
            'total_caps'          => count($caps),
            'relevant_wc_caps'    => $relevant,
            'azure_plugin_caps'   => $azure_caps,
            'looks_like_admin'    => in_array('manage_options', $caps, true),
            'looks_like_shop_mgr' => in_array('manage_woocommerce', $caps, true),
        );
    }

    $out = array(
        'as_of'             => current_time('mysql'),
        'roles'             => $out_roles,
        'admins_count'      => count(get_users(array('role' => 'administrator', 'fields' => 'ID'))),
        'shop_manager_count'=> count(get_users(array('role' => 'shop_manager', 'fields' => 'ID'))),
        'azure_caps_known'  => class_exists('Azure_Capabilities') ? array_column(Azure_Capabilities::get_registry(), 'slug') : '(class not loaded)',
    );

    header('Content-Type: application/json');
    echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
});
