<?php
/**
 * How-to video cards, and content shown only to the PTSA team.
 *
 *   [pta-video-gallery]
 *     [pta-video src="…mp4" poster="…jpg" title="1. Signing In"]Short description.[/pta-video]
 *   [/pta-video-gallery]
 *
 *   [pta-team-only message="Shown to everyone else"]…[/pta-team-only]
 *
 * The team is administrators plus accounts that signed in with Microsoft
 * (the azuread role, the custom SSO role, or the azure_ad_user capability).
 * Signed-in responses are never edge-cached, so the team section cannot leak
 * into the anonymous copy of a page.
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_PTA_Video_Gallery {

    const STYLE_HANDLE = 'azure-pta-video-gallery';

    /** @var self|null */
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('pta-video-gallery', array($this, 'render_gallery'));
        add_shortcode('pta-video', array($this, 'render_video'));
        add_shortcode('pta-team-only', array($this, 'render_team_only'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_for_page'));
    }

    public function enqueue_for_page() {
        global $post;
        if (is_a($post, 'WP_Post') && (has_shortcode($post->post_content, 'pta-video-gallery') || has_shortcode($post->post_content, 'pta-video'))) {
            self::enqueue_style();
        }
    }

    /**
     * @param string[] $user_roles
     * @param bool     $has_sso_capability
     * @param string[] $team_roles
     */
    public static function roles_are_team(array $user_roles, $has_sso_capability, array $team_roles) {
        if ($has_sso_capability) {
            return true;
        }
        return (bool) array_intersect(array_map('strval', $user_roles), $team_roles);
    }

    /**
     * @return string[]
     */
    public static function team_roles() {
        $roles = array('administrator', 'azuread');
        if (class_exists('Azure_Settings') && Azure_Settings::get_setting('sso_use_custom_role', false)) {
            $custom = sanitize_key(strtolower((string) Azure_Settings::get_setting('sso_custom_role_name', 'AzureAD')));
            if ($custom !== '') {
                $roles[] = $custom;
            }
        }
        return array_values(array_unique((array) apply_filters('pta_team_roles', $roles)));
    }

    public static function current_user_is_team() {
        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            return false;
        }
        $user = wp_get_current_user();
        if (!$user || empty($user->ID)) {
            return false;
        }
        return self::roles_are_team((array) $user->roles, user_can($user, 'azure_ad_user'), self::team_roles());
    }

    public function render_team_only($atts, $content = '') {
        $atts = shortcode_atts(array('message' => ''), $atts, 'pta-team-only');
        if (self::current_user_is_team()) {
            return do_shortcode(self::strip_autop((string) $content));
        }
        if ($atts['message'] === '') {
            return '';
        }
        return '<p class="pta-team-only-note">' . esc_html($atts['message']) . '</p>';
    }

    public function render_gallery($atts, $content = '') {
        self::enqueue_style();
        return '<div class="pta-video-gallery">' . do_shortcode(self::strip_autop((string) $content)) . '</div>';
    }

    public function render_video($atts, $content = '') {
        $atts = shortcode_atts(array('src' => '', 'poster' => '', 'title' => ''), $atts, 'pta-video');
        self::enqueue_style();
        return self::card_html($atts['src'], $atts['poster'], $atts['title'], trim(wp_strip_all_tags((string) $content)));
    }

    public static function card_html($src, $poster, $title, $description) {
        $src = esc_url((string) $src);
        if ($src === '') {
            return '';
        }
        $poster = esc_url((string) $poster);
        $label = (string) $title !== '' ? (string) $title : __('How-to video', 'azure-plugin');
        $html = '<figure class="pta-video-card">';
        $html .= '<video class="pta-video-card__video" controls playsinline preload="none"'
            . ($poster !== '' ? ' poster="' . $poster . '"' : '')
            . ' aria-label="' . esc_attr($label) . '">'
            . '<source src="' . $src . '" type="video/mp4" />'
            . '<a href="' . $src . '">' . esc_html__('Watch the video', 'azure-plugin') . '</a>'
            . '</video>';
        $html .= '<figcaption class="pta-video-card__body">';
        if ((string) $title !== '') {
            $html .= '<h3 class="pta-video-card__title">' . esc_html($title) . '</h3>';
        }
        if ((string) $description !== '') {
            $html .= '<p class="pta-video-card__text">' . esc_html($description) . '</p>';
        }
        $html .= '</figcaption></figure>';
        return $html;
    }

    /**
     * wpautop wraps nested shortcodes in <p> and <br>, which would land
     * between the cards and break the grid.
     */
    public static function strip_autop($content) {
        $content = preg_replace('#<br\s*/?>\s*(?=\[)|(?<=\])\s*<br\s*/?>#i', '', (string) $content);
        $content = preg_replace('#<p>\s*(?=\[)|(?<=\])\s*</p>#i', '', $content);
        return trim((string) $content);
    }

    private static function enqueue_style() {
        if (function_exists('wp_enqueue_style') && defined('AZURE_PLUGIN_URL')) {
            wp_enqueue_style(self::STYLE_HANDLE, AZURE_PLUGIN_URL . 'css/pta-video-gallery.css', array(), AZURE_PLUGIN_VERSION);
        }
    }
}
