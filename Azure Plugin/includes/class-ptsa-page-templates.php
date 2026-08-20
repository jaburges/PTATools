<?php
/**
 * Plugin-registered page templates for PTSA pages.
 *
 * ChromeNews's own "Page Builder Full Width" template zeros padding for
 * Elementor. These two templates keep the theme header/footer, add inner
 * padding, and use a wider content column with grey gutters on either side.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_PTSA_Page_Templates {

    const FULL_WIDTH = 'ptsa-full-width.php';
    const SIDEBAR    = 'ptsa-sidebar.php';

    public static function init() {
        add_filter('theme_page_templates', array(__CLASS__, 'register_templates'));
        add_filter('template_include', array(__CLASS__, 'include_template'), 99);
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_styles'), 20);
        self::maybe_assign_ptsa_preview_page();
    }

    public static function register_templates($templates) {
        $templates[self::FULL_WIDTH] = __('PTSA Full Width', 'azure-plugin');
        $templates[self::SIDEBAR]    = __('PTSA Sidebar', 'azure-plugin');
        return $templates;
    }

    public static function include_template($template) {
        if (!is_singular('page')) {
            return $template;
        }
        $file = self::file_for_slug(get_page_template_slug());
        return $file ? $file : $template;
    }

    public static function body_class($classes) {
        $slug = is_singular('page') ? get_page_template_slug() : '';
        if ($slug === self::FULL_WIDTH) {
            $classes[] = 'ptsa-template';
            $classes[] = 'ptsa-template-full-width';
        } elseif ($slug === self::SIDEBAR) {
            $classes[] = 'ptsa-template';
            $classes[] = 'ptsa-template-sidebar';
        }
        return $classes;
    }

    public static function enqueue_styles() {
        if (!is_singular('page')) {
            return;
        }
        $slug = get_page_template_slug();
        if ($slug !== self::FULL_WIDTH && $slug !== self::SIDEBAR) {
            return;
        }
        wp_enqueue_style(
            'ptsa-page-templates',
            AZURE_PLUGIN_URL . 'css/ptsa-page-templates.css',
            array(),
            AZURE_PLUGIN_VERSION
        );
    }

    /**
     * Point /ptsa at PTSA Full Width once, when it still uses the old
     * ChromeNews page-builder template (or no template). Does not overwrite
     * PTSA Sidebar if an editor already switched.
     */
    public static function maybe_assign_ptsa_preview_page() {
        if (get_option('azure_ptsa_fullwidth_preview_assigned')) {
            return;
        }
        $page = get_page_by_path('ptsa');
        if (!$page instanceof WP_Post) {
            return;
        }
        self::assign_ptsa_preview_page();
        update_option('azure_ptsa_fullwidth_preview_assigned', 1);
    }

    public static function assign_ptsa_preview_page() {
        $page = get_page_by_path('ptsa');
        if (!$page instanceof WP_Post) {
            return;
        }
        $current = (string) get_page_template_slug($page->ID);
        $legacy  = array('', 'page-templates/full-width.php', 'full-width.php');
        if (!in_array($current, $legacy, true)) {
            return;
        }
        update_post_meta($page->ID, '_wp_page_template', self::FULL_WIDTH);
        if (class_exists('Azure_Logger')) {
            Azure_Logger::info('PTSA page templates: assigned PTSA Full Width to /ptsa');
        }
    }

    private static function file_for_slug($slug) {
        $map = array(
            self::FULL_WIDTH => AZURE_PLUGIN_PATH . 'templates/ptsa-full-width.php',
            self::SIDEBAR    => AZURE_PLUGIN_PATH . 'templates/ptsa-sidebar.php',
        );
        if (!isset($map[$slug]) || !file_exists($map[$slug])) {
            return null;
        }
        return $map[$slug];
    }
}
