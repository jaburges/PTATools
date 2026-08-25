<?php
/**
 * [newsletter-archive] — live list of newsletter pages under the
 * Newsletters parent. New child pages appear on the next view; no
 * re-publish of the parent is required.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Newsletter_Archive_Shortcode {

    const SHORTCODE = 'newsletter-archive';

    /** @var self|null */
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode(self::SHORTCODE, array($this, 'render_shortcode'));
        add_shortcode('newsletter_archive', array($this, 'render_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    public function enqueue_assets($force = false) {
        if (!$force) {
            global $post;
            if (!is_a($post, 'WP_Post')) {
                return;
            }
            if (
                !has_shortcode($post->post_content, self::SHORTCODE)
                && !has_shortcode($post->post_content, 'newsletter_archive')
            ) {
                return;
            }
        }

        wp_enqueue_style(
            'azure-newsletter-archive',
            AZURE_PLUGIN_URL . 'css/newsletter-archive.css',
            array(),
            defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : '1'
        );
    }

    /**
     * Attributes:
     *   parent  page ID, slug, or empty (Newsletters parent / current page)
     *   order   DESC (newest first) or ASC
     *   limit   max items, 0 = all
     *   empty   message when there are no issues yet
     */
    public function render_shortcode($atts = array()) {
        $this->enqueue_assets(true);

        $atts = shortcode_atts(array(
            'parent' => '',
            'order'  => 'DESC',
            'limit'  => '0',
            'empty'  => __('No newsletters have been published yet.', 'azure-plugin'),
        ), is_array($atts) ? $atts : array(), self::SHORTCODE);

        $parent_id = $this->resolve_parent_id($atts['parent']);
        if ($parent_id < 1) {
            return '<div class="newsletter-archive newsletter-archive-empty"><p>'
                . esc_html__('No Newsletters parent page was found.', 'azure-plugin')
                . '</p></div>';
        }

        $order = strtoupper($atts['order']) === 'ASC' ? 'ASC' : 'DESC';
        $limit = intval($atts['limit']);

        $pages = get_pages(array(
            'parent'      => $parent_id,
            'sort_column' => 'post_date',
            'sort_order'  => $order,
            'post_status' => 'publish',
        ));

        if ($limit > 0 && !empty($pages)) {
            $pages = array_slice($pages, 0, $limit);
        }

        if (empty($pages)) {
            return '<div class="newsletter-archive newsletter-archive-empty"><p>'
                . esc_html($atts['empty'])
                . '</p></div>';
        }

        $current_id = get_queried_object_id();
        $html = '<nav class="newsletter-archive" aria-label="'
            . esc_attr__('Newsletter archive', 'azure-plugin') . '">';
        $html .= '<ul class="newsletter-archive-list">';

        foreach ($pages as $page) {
            $url   = get_permalink($page);
            $title = get_the_title($page);
            $date  = get_the_date('', $page);
            $iso   = get_the_date('c', $page);
            $current = ((int) $page->ID === (int) $current_id) ? ' is-current' : '';

            $html .= '<li class="newsletter-archive-item' . $current . '">';
            $html .= '<a class="newsletter-archive-link" href="' . esc_url($url) . '">';
            $html .= '<span class="newsletter-archive-title">' . esc_html($title) . '</span>';
            if ($date) {
                $html .= ' <time class="newsletter-archive-date" datetime="'
                    . esc_attr($iso) . '">' . esc_html($date) . '</time>';
            }
            $html .= '</a></li>';
        }

        $html .= '</ul></nav>';
        return $html;
    }

    /**
     * @param string $parent ID, slug, or empty for auto
     */
    private function resolve_parent_id($parent) {
        $parent = trim((string) $parent);

        if ($parent !== '' && ctype_digit($parent)) {
            $id = (int) $parent;
            return get_post_type($id) === 'page' ? $id : 0;
        }

        if ($parent !== '') {
            $by_path = get_page_by_path($parent);
            return $by_path ? (int) $by_path->ID : 0;
        }

        if (class_exists('Azure_Settings')) {
            $saved = (int) Azure_Settings::get_setting('newsletter_default_parent_page', 0);
            if ($saved > 0 && get_post_type($saved) === 'page') {
                return $saved;
            }
        }

        $by_path = get_page_by_path('newsletters');
        if ($by_path) {
            return (int) $by_path->ID;
        }

        $matches = get_posts(array(
            'post_type'   => 'page',
            'title'       => 'Newsletters',
            'post_status' => array('publish', 'private'),
            'numberposts' => 1,
        ));
        if (!empty($matches)) {
            return (int) $matches[0]->ID;
        }

        $current = get_queried_object_id();
        if ($current > 0 && get_post_type($current) === 'page') {
            return (int) $current;
        }

        return 0;
    }
}
