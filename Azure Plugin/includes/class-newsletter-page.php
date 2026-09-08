<?php
/**
 * Single newsletter archive pages (e.g. /newsletters/wilder-ptsa-newsletter-sept-7th/).
 *
 * Campaign HTML is saved as the page body so the site can show what went
 * out. ChromeNews then styles every table/hr/link like a blog post, which
 * turns leftover empty CTA cells into blue "buttons" and puts boxes around
 * dividers. This class scopes a reset and strips hollow leftovers.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Newsletter_Page {

    const META_ID = '_pta_newsletter_id';
    const BODY_CLASS = 'pta-newsletter-page';
    const WRAP_CLASS = 'pta-newsletter-email';

    /** @var self|null */
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp', array($this, 'disable_wpautop_on_newsletter_pages'));
        add_filter('body_class', array($this, 'body_class'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_filter('the_content', array($this, 'filter_content'), 8);
    }

    /**
     * @param int $page_id
     * @return bool
     */
    public static function is_newsletter_page($page_id = 0) {
        if ($page_id < 1) {
            if (!function_exists('is_singular') || !is_singular('page')) {
                return false;
            }
            $page_id = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
        }
        if ($page_id < 1) {
            return false;
        }
        if (function_exists('get_post_meta') && get_post_meta($page_id, self::META_ID, true) !== '') {
            return true;
        }
        $post = function_exists('get_post') ? get_post($page_id) : null;
        if (!$post) {
            return false;
        }
        if (!empty($post->post_parent) && function_exists('get_post')) {
            $parent = get_post((int) $post->post_parent);
            if ($parent && ($parent->post_name === 'newsletters' || strcasecmp($parent->post_title, 'Newsletters') === 0)) {
                return true;
            }
        }
        return self::content_looks_like_email($post->post_content);
    }

    /**
     * @param string $html
     * @return bool
     */
    public static function content_looks_like_email($html) {
        return (bool) preg_match('/nl-divider|nl-stack-cols|nl-button|pta-nl-divider|pta-nl-button/', (string) $html);
    }

    /**
     * Drop hollow CTA tables, empty anchors, empty divider rows, and
     * Mailgun merge-tag footer links that only belong in the sent email.
     *
     * @param string $html
     * @return string
     */
    public static function clean_archive_html($html) {
        if ($html === '' || $html === null) {
            return (string) $html;
        }
        $html = (string) $html;

        $html = preg_replace(
            '/<table\b[^>]*>\s*(?:<tbody>\s*)?<tr>\s*<td\b[^>]*bgcolor[^>]*>\s*<\/td>\s*<\/tr>\s*(?:<\/tbody>\s*)?<\/table>/is',
            '',
            $html
        );
        $html = is_string($html) ? $html : '';

        $html = preg_replace(
            '/<table\b[^>]*class="[^"]*nl-divider[^"]*"[^>]*>\s*(?:<tbody>\s*)?<tr>\s*<\/tr>\s*(?:<\/tbody>\s*)?<\/table>/is',
            '',
            $html
        );
        $html = is_string($html) ? $html : '';

        $html = preg_replace('/<a\b[^>]*>\s*(?:<br\s*\/?>\s*)*<\/a>/i', '', $html);
        $html = is_string($html) ? $html : '';

        $html = preg_replace(
            '/<p\b[^>]*>\s*(?:<a\b[^>]*\{\{(?:view_in_browser_url|unsubscribe_url)\}\}[^>]*>.*?<\/a>\s*(?:•|&bull;|<br\s*\/?>|\s)*)+\s*<\/p>/is',
            '',
            $html
        );
        $html = is_string($html) ? $html : '';

        $html = preg_replace('/<a\b[^>]*href="\{\{[^"]+\}\}"[^>]*>.*?<\/a>/is', '', $html);
        return is_string($html) ? $html : '';
    }

    public function disable_wpautop_on_newsletter_pages() {
        if (!self::is_newsletter_page()) {
            return;
        }
        remove_filter('the_content', 'wpautop');
        remove_filter('the_content', 'shortcode_unautop');
    }

    public function body_class($classes) {
        if (self::is_newsletter_page()) {
            $classes[] = self::BODY_CLASS;
        }
        return $classes;
    }

    public function enqueue_assets() {
        if (!self::is_newsletter_page()) {
            return;
        }
        wp_enqueue_style(
            'azure-newsletter-page',
            AZURE_PLUGIN_URL . 'css/newsletter-page.css',
            array(),
            defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : '1'
        );
    }

    public function filter_content($html) {
        if (is_admin() || !self::is_newsletter_page()) {
            return $html;
        }
        if (strpos((string) $html, self::WRAP_CLASS) !== false) {
            return $html;
        }
        $cleaned = self::clean_archive_html($html);
        return '<div class="' . self::WRAP_CLASS . '">' . $cleaned . '</div>';
    }
}
