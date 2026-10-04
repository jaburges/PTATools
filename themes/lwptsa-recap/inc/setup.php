<?php
if (!defined('ABSPATH')) {
    exit;
}

function lwptsa_asset_version($relative) {
    $path = LWPTSA_THEME_DIR . '/' . $relative;
    return file_exists($path) ? (string) filemtime($path) : null;
}

add_action('after_setup_theme', function () {
    add_theme_support('editor-styles');
    add_editor_style('assets/css/theme.css');
    add_theme_support('responsive-embeds');
});

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('lwptsa-theme', get_theme_file_uri('assets/css/theme.css'), array(), lwptsa_asset_version('assets/css/theme.css'));
    wp_enqueue_script('lwptsa-theme', get_theme_file_uri('assets/js/theme.js'), array(), lwptsa_asset_version('assets/js/theme.js'), array('strategy' => 'defer', 'in_footer' => true));
});

add_action('init', function () {
    register_block_pattern_category('lwptsa', array('label' => __('LWPTSA Council', 'lwptsa-recap')));
});