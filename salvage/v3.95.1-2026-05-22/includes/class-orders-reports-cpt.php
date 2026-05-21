<?php
/**
 * Orders Reports — Custom Post Types.
 *
 * Registers azure_or_report (saved report configurations) and reserves
 * azure_or_schedule for Ship 2 (scheduled automation). Both CPTs are
 * intentionally hidden from the main admin menu — the Orders Reports
 * tab under Selling provides their UI.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Orders_Reports_CPT {

    const POST_TYPE_REPORT   = 'azure_or_report';
    const POST_TYPE_SCHEDULE = 'azure_or_schedule';

    public static function register() {
        register_post_type(self::POST_TYPE_REPORT, array(
            'labels' => array(
                'name'          => __('Orders Reports', 'azure-plugin'),
                'singular_name' => __('Orders Report', 'azure-plugin'),
                'edit_item'     => __('Edit Orders Report', 'azure-plugin'),
            ),
            'public'              => false,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'show_in_rest'        => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'supports'            => array('title', 'author'),
            'rewrite'             => false,
            'query_var'           => false,
        ));

        // Reserved for Ship 2 — registered now so the slug is stable
        // and we can write the schedule writer without a follow-up CPT
        // migration. No UI in Ship 1.
        register_post_type(self::POST_TYPE_SCHEDULE, array(
            'labels' => array(
                'name'          => __('Orders Report Schedules', 'azure-plugin'),
                'singular_name' => __('Orders Report Schedule', 'azure-plugin'),
            ),
            'public'              => false,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'show_in_rest'        => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'supports'            => array('title', 'author'),
            'rewrite'             => false,
            'query_var'           => false,
        ));
    }
}
