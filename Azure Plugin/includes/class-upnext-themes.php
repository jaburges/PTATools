<?php
/**
 * [up-next] theme presets
 *
 * Storage + CSS engine + AJAX for named theme presets that the
 * `[up-next theme="..."]` shortcode references. Admin defines
 * themes on the Calendar > Upcoming Events admin tab (a Themes
 * panel added in v3.125). At render time the shortcode adds a
 * `up-next-theme-<slug>` class to the output wrapper, and a
 * generated stylesheet (cached, regenerated on save) scopes
 * every visual knob under that class.
 *
 * Storage shape (wp_options.azure_upnext_themes):
 *   {
 *     "version": 1,
 *     "themes": [
 *       { ...theme... },
 *       ...
 *     ],
 *     "saved_at": "2026-06-02 12:34:56"
 *   }
 *
 * Theme schema (all keys optional except slug + label):
 *
 *   slug                  kebab-case unique key (referenced by shortcode)
 *   label                 admin-friendly display name
 *   layout                "rows" | "grid" | "compact"
 *   columns               1 | 2 | 3 (effective for layout=grid)
 *   show_image            bool   - render featured image on each card
 *   image_position        "left" | "top"
 *   image_size            WP image size slug
 *   show_time / show_location / show_category / show_join_button / show_section_headers
 *   bg_color / text_color / accent_color / accent_text_color / muted_color / border_color
 *   border_width / border_radius / card_padding / card_gap / section_gap (all px)
 *   title_size / date_size / section_header_size (px)
 *   section_header_bg / section_header_text
 *
 * @package AzurePlugin
 * @since   3.125
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_UpNext_Themes {

    const OPTION_KEY       = 'azure_upnext_themes';
    const STORAGE_VERSION  = 1;
    const ENQUEUE_HANDLE   = 'azure-upnext-themes-generated';

    /** @var bool */
    private static $bootstrapped = false;

    /** @var array|null */
    private static $cached_themes = null;

    public static function bootstrap() {
        if (self::$bootstrapped) return;
        self::$bootstrapped = true;

        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_generated_css'), 20);

        add_action('wp_ajax_azure_upnext_themes_save',    array(__CLASS__, 'ajax_save'));
        add_action('wp_ajax_azure_upnext_themes_delete',  array(__CLASS__, 'ajax_delete'));
        add_action('wp_ajax_azure_upnext_themes_reset',   array(__CLASS__, 'ajax_reset'));
        add_action('wp_ajax_azure_upnext_themes_preview', array(__CLASS__, 'ajax_preview'));
        add_action('wp_ajax_azure_upnext_themes_clone',   array(__CLASS__, 'ajax_clone'));
    }

    // -----------------------------------------------------------------
    // Storage
    // -----------------------------------------------------------------

    /**
     * Return the full theme list — seeded defaults + any saved user
     * themes. Defaults always appear first and are flagged
     * `is_builtin` so the admin UI can disable destructive controls.
     *
     * @return array<int,array>
     */
    public static function get_themes() {
        if (self::$cached_themes !== null) {
            return self::$cached_themes;
        }

        $user_themes = array();
        $stored = get_option(self::OPTION_KEY, null);
        if (is_array($stored) && isset($stored['themes']) && is_array($stored['themes'])) {
            $user_themes = $stored['themes'];
        }

        $builtins = self::seed_themes();
        // Builtins win on slug collision so admins can never break
        // the "default" theme by saving over it.
        $builtin_slugs = array_flip(array_column($builtins, 'slug'));
        $filtered = array();
        foreach ($user_themes as $t) {
            if (!is_array($t) || empty($t['slug'])) continue;
            if (isset($builtin_slugs[$t['slug']])) continue;
            $filtered[] = $t;
        }

        $all = array_merge($builtins, $filtered);
        // Tag builtin flag for the UI; deserialized arrays may lack it.
        foreach ($all as $i => $t) {
            $all[$i]['is_builtin'] = isset($builtin_slugs[$t['slug']]);
        }

        self::$cached_themes = $all;
        return $all;
    }

    /**
     * Return one theme by slug, or null if not found.
     *
     * @param string $slug
     * @return array|null
     */
    public static function get_theme($slug) {
        $slug = sanitize_key((string) $slug);
        if ($slug === '') return null;
        foreach (self::get_themes() as $t) {
            if (($t['slug'] ?? '') === $slug) return $t;
        }
        return null;
    }

    /**
     * Persist the supplied user-themes list (builtins are filtered
     * out before save). Returns the normalized payload.
     */
    public static function save_themes(array $themes) {
        $builtin_slugs = array_flip(array_column(self::seed_themes(), 'slug'));
        $clean = array();
        $seen_slugs = array();

        foreach ($themes as $t) {
            if (!is_array($t)) continue;
            $slug = isset($t['slug']) ? sanitize_key((string) $t['slug']) : '';
            if ($slug === '' || isset($builtin_slugs[$slug])) continue;
            if (isset($seen_slugs[$slug])) continue;
            $seen_slugs[$slug] = true;
            $clean[] = self::normalize_theme($t, $slug);
        }

        $payload = array(
            'version'  => self::STORAGE_VERSION,
            'themes'   => $clean,
            'saved_at' => current_time('mysql'),
        );
        update_option(self::OPTION_KEY, $payload, false);
        self::$cached_themes = null;
        self::flush_generated_css_cache();
        return $payload;
    }

    public static function reset_themes() {
        delete_option(self::OPTION_KEY);
        self::$cached_themes = null;
        self::flush_generated_css_cache();
        return self::get_themes();
    }

    /**
     * Coerce a saved/posted theme into a known shape with safe types,
     * sane fallbacks, and sanitized colors. Anything not in the
     * schema gets dropped so admins can't smuggle arbitrary CSS.
     */
    private static function normalize_theme(array $t, $slug) {
        $col = function ($v, $fallback) {
            $v = is_string($v) ? trim($v) : '';
            return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v) ? $v : $fallback;
        };
        $px = function ($v, $fallback, $min, $max) {
            $v = is_numeric($v) ? (int) $v : $fallback;
            return max($min, min($max, $v));
        };

        return array(
            'slug'                  => $slug,
            'label'                 => isset($t['label']) ? sanitize_text_field((string) $t['label']) : ucfirst(str_replace('-', ' ', $slug)),

            // Layout
            'layout'                => in_array(($t['layout'] ?? ''), array('rows','grid','compact'), true) ? $t['layout'] : 'rows',
            'columns'               => $px($t['columns'] ?? 1, 1, 1, 4),

            // Card content visibility
            'show_image'            => !empty($t['show_image']),
            'image_position'        => in_array(($t['image_position'] ?? ''), array('left','top'), true) ? $t['image_position'] : 'left',
            'image_size'            => in_array(($t['image_size'] ?? ''), array('thumbnail','medium','large'), true) ? $t['image_size'] : 'medium',
            'show_time'             => isset($t['show_time']) ? !empty($t['show_time']) : true,
            'show_location'         => isset($t['show_location']) ? !empty($t['show_location']) : true,
            'show_category'         => isset($t['show_category']) ? !empty($t['show_category']) : false,
            'show_join_button'      => isset($t['show_join_button']) ? !empty($t['show_join_button']) : true,
            'show_section_headers'  => isset($t['show_section_headers']) ? !empty($t['show_section_headers']) : true,

            // Card colors
            'bg_color'              => $col($t['bg_color']            ?? '', '#ffffff'),
            'text_color'            => $col($t['text_color']          ?? '', '#1d2327'),
            'accent_color'          => $col($t['accent_color']        ?? '', '#2271b1'),
            'accent_text_color'     => $col($t['accent_text_color']   ?? '', '#ffffff'),
            'muted_color'           => $col($t['muted_color']         ?? '', '#646970'),
            'border_color'          => $col($t['border_color']        ?? '', '#dcdcde'),
            'section_header_bg'     => $col($t['section_header_bg']   ?? '', '#f6f7f7'),
            'section_header_text'   => $col($t['section_header_text'] ?? '', '#1d2327'),

            // Card sizing / typography
            'border_width'          => $px($t['border_width']        ?? 1,  1, 0, 12),
            'border_radius'         => $px($t['border_radius']       ?? 4,  4, 0, 32),
            'card_padding'          => $px($t['card_padding']        ?? 12, 12, 0, 64),
            'card_gap'              => $px($t['card_gap']            ?? 10, 10, 0, 64),
            'section_gap'           => $px($t['section_gap']         ?? 24, 24, 0, 96),
            'title_size'            => $px($t['title_size']          ?? 16, 16, 10, 36),
            'date_size'             => $px($t['date_size']           ?? 13, 13, 9,  28),
            'section_header_size'   => $px($t['section_header_size'] ?? 18, 18, 12, 36),

            // v3.128 — Outer container (newsletter-style wrapper)
            // Renders only when outer_bg_color != bg_color OR
            // outer_border_width > 0 OR outer_padding > 0. Lets
            // admins wrap the whole render in a colored frame
            // (e.g. dark navy background with an orange border)
            // distinct from the per-event card chrome.
            'outer_bg_color'        => $col($t['outer_bg_color']     ?? '', '#ffffff'),
            'outer_border_color'    => $col($t['outer_border_color'] ?? '', '#dcdcde'),
            'outer_border_width'    => $px($t['outer_border_width']  ?? 0, 0, 0, 24),
            'outer_border_radius'   => $px($t['outer_border_radius'] ?? 0, 0, 0, 48),
            'outer_padding'         => $px($t['outer_padding']       ?? 0, 0, 0, 96),
            'outer_max_width'       => $px($t['outer_max_width']     ?? 0, 0, 0, 1200), // 0 = no max-width

            // v3.128 — Top header text (e.g. "Week of June 1").
            // Empty = no header. Supports {date} placeholder which
            // resolves to date_i18n('F j', <current week start>).
            // The native per-section "This Week" / "Next Week"
            // headers are still controlled separately by
            // show_section_headers.
            'header_text'           => isset($t['header_text']) ? wp_kses_post((string) $t['header_text']) : '',
            'header_color'          => $col($t['header_color']       ?? '', '#1d2327'),
            'header_size'           => $px($t['header_size']         ?? 28, 28, 12, 72),
            'header_align'          => in_array(($t['header_align'] ?? ''), array('left','center','right'), true) ? $t['header_align'] : 'left',
            'header_underline'      => !empty($t['header_underline']),
            'header_font'           => in_array(($t['header_font'] ?? ''), array('default','serif','display','mono'), true) ? $t['header_font'] : 'default',

            // v3.128 — Footer HTML (e.g. "Find out more on our
            // website: ..."). Allowed HTML is limited to safe
            // inline tags via wp_kses_post.
            'footer_html'           => isset($t['footer_html']) ? wp_kses_post((string) $t['footer_html']) : '',
            'footer_color'          => $col($t['footer_color']       ?? '', '#646970'),
            'footer_align'          => in_array(($t['footer_align'] ?? ''), array('left','center','right'), true) ? $t['footer_align'] : 'center',
            'footer_size'           => $px($t['footer_size']         ?? 14, 14, 10, 28),

            // v3.128 — Date pill on the left of each event card.
            // 'none' = no pill (current behavior).
            // 'left' = stacked "Mon" / "1" pill on the left of
            //          each card, replacing the thumb position.
            // When set to 'left', the inline .upcoming-date text
            // is hidden because the pill carries the date.
            'date_pill'             => in_array(($t['date_pill'] ?? ''), array('none','left'), true) ? $t['date_pill'] : 'none',
            'pill_bg_color'         => $col($t['pill_bg_color']      ?? '', '#f4a623'),
            'pill_text_color'       => $col($t['pill_text_color']    ?? '', '#0a2d57'),
            'pill_radius'           => $px($t['pill_radius']         ?? 12, 12, 0, 32),
            'pill_width'            => $px($t['pill_width']          ?? 72, 72, 40, 160),

            // v3.128 — Location badge (IN PERSON / ONLINE) on
            // each card. Auto-derived from event's online_url:
            // truthy → online; otherwise → in person.
            'show_location_badge'   => !empty($t['show_location_badge']),
            'badge_in_person_text'  => isset($t['badge_in_person_text']) ? sanitize_text_field((string) $t['badge_in_person_text']) : 'IN PERSON',
            'badge_online_text'     => isset($t['badge_online_text'])    ? sanitize_text_field((string) $t['badge_online_text'])    : 'ONLINE',
            'badge_color'           => $col($t['badge_color']        ?? '', '#0a2d57'),
            'badge_bg_color'        => $col($t['badge_bg_color']     ?? '', '#ffffff'),
        );
    }

    /**
     * Built-in themes shipped with the plugin. `default` mirrors the
     * pre-v3.125 inline-list look so existing `[up-next]` calls with
     * no theme attribute render identically. The other two are
     * starting points editors can clone.
     */
    private static function seed_themes() {
        return array(
            array(
                'slug'  => 'default',
                'label' => 'Default (inline list)',
                'layout' => 'rows', 'columns' => 1,
                'show_image' => false, 'image_position' => 'left', 'image_size' => 'medium',
                'show_time' => true, 'show_location' => false, 'show_category' => false,
                'show_join_button' => true, 'show_section_headers' => true,
                'bg_color' => '#ffffff', 'text_color' => '#1d2327',
                'accent_color' => '#2271b1', 'accent_text_color' => '#ffffff',
                'muted_color' => '#646970', 'border_color' => '#dcdcde',
                'section_header_bg' => '#f6f7f7', 'section_header_text' => '#1d2327',
                'border_width' => 0, 'border_radius' => 0, 'card_padding' => 4,
                'card_gap' => 6, 'section_gap' => 20,
                'title_size' => 15, 'date_size' => 13, 'section_header_size' => 18,
                'is_builtin' => true,
            ),
            array(
                'slug'  => 'card-light',
                'label' => 'Card (light)',
                'layout' => 'grid', 'columns' => 2,
                'show_image' => true, 'image_position' => 'left', 'image_size' => 'medium',
                'show_time' => true, 'show_location' => true, 'show_category' => true,
                'show_join_button' => true, 'show_section_headers' => true,
                'bg_color' => '#ffffff', 'text_color' => '#1d2327',
                'accent_color' => '#2271b1', 'accent_text_color' => '#ffffff',
                'muted_color' => '#646970', 'border_color' => '#dcdcde',
                'section_header_bg' => '#f6f7f7', 'section_header_text' => '#1d2327',
                'border_width' => 1, 'border_radius' => 8, 'card_padding' => 14,
                'card_gap' => 12, 'section_gap' => 28,
                'title_size' => 16, 'date_size' => 13, 'section_header_size' => 20,
                'is_builtin' => true,
            ),
            array(
                'slug'  => 'card-dark',
                'label' => 'Card (dark)',
                'layout' => 'grid', 'columns' => 2,
                'show_image' => true, 'image_position' => 'top', 'image_size' => 'medium',
                'show_time' => true, 'show_location' => true, 'show_category' => false,
                'show_join_button' => true, 'show_section_headers' => true,
                'bg_color' => '#1d2327', 'text_color' => '#f0f0f1',
                'accent_color' => '#72aee6', 'accent_text_color' => '#1d2327',
                'muted_color' => '#a7aaad', 'border_color' => '#3c434a',
                'section_header_bg' => '#2c3338', 'section_header_text' => '#ffffff',
                'border_width' => 1, 'border_radius' => 6, 'card_padding' => 14,
                'card_gap' => 12, 'section_gap' => 28,
                'title_size' => 16, 'date_size' => 13, 'section_header_size' => 20,
                'is_builtin' => true,
            ),

            // v3.128 — Newsletter style. Modeled on the LWPTSA
            // weekly newsletter image: dark navy outer container
            // with an orange border, top header "Week of {date}",
            // each event a white card with an orange day/number
            // pill on the left and an IN PERSON badge on the
            // right. Footer line with calendar URL. Admins can
            // clone this into a user theme and tweak colors /
            // copy / sizing without losing the built-in source.
            array(
                'slug'  => 'newsletter',
                'label' => 'Newsletter (LWPTSA style)',
                'layout' => 'rows', 'columns' => 1,
                'show_image' => false, 'image_position' => 'left', 'image_size' => 'medium',
                'show_time' => true, 'show_location' => false, 'show_category' => false,
                'show_join_button' => false, 'show_section_headers' => false,
                'bg_color' => '#ffffff', 'text_color' => '#0a2d57',
                'accent_color' => '#0a2d57', 'accent_text_color' => '#ffffff',
                'muted_color' => '#0a2d57', 'border_color' => '#ffffff',
                'section_header_bg' => '#0a2d57', 'section_header_text' => '#ffffff',
                'border_width' => 0, 'border_radius' => 14, 'card_padding' => 16,
                'card_gap' => 14, 'section_gap' => 18,
                'title_size' => 18, 'date_size' => 14, 'section_header_size' => 22,
                'outer_bg_color' => '#0a2d57', 'outer_border_color' => '#f4a623',
                'outer_border_width' => 16, 'outer_border_radius' => 4,
                'outer_padding' => 32, 'outer_max_width' => 720,
                'header_text' => 'Week of {date}',
                'header_color' => '#dbeaff', 'header_size' => 36, 'header_align' => 'center',
                'header_underline' => true, 'header_font' => 'display',
                'footer_html' => 'Find out more on our website:<br><strong>LWPTSA.net/calendar</strong>',
                'footer_color' => '#ffffff', 'footer_align' => 'center', 'footer_size' => 16,
                'date_pill' => 'left',
                'pill_bg_color' => '#f4a623', 'pill_text_color' => '#0a2d57',
                'pill_radius' => 14, 'pill_width' => 76,
                'show_location_badge' => true,
                'badge_in_person_text' => 'IN PERSON', 'badge_online_text' => 'ONLINE',
                'badge_color' => '#0a2d57', 'badge_bg_color' => '#ffffff',
                'is_builtin' => true,
            ),
        );
    }

    // -----------------------------------------------------------------
    // CSS generator
    // -----------------------------------------------------------------

    /**
     * Build the CSS for every defined theme as one string.
     * Cached for the lifetime of the request via static, and stored
     * in a transient between requests so we don't regenerate on
     * every page view.
     *
     * @return string
     */
    public static function generate_css() {
        static $req_cache = null;
        if ($req_cache !== null) return $req_cache;

        $cached = get_transient('azure_upnext_themes_css');
        if (is_string($cached)) {
            $req_cache = $cached;
            return $cached;
        }

        $css = self::build_css();
        set_transient('azure_upnext_themes_css', $css, 12 * HOUR_IN_SECONDS);
        $req_cache = $css;
        return $css;
    }

    public static function flush_generated_css_cache() {
        delete_transient('azure_upnext_themes_css');
    }

    private static function build_css() {
        $out = "/* PTA Tools [up-next] theme presets — generated. Edit themes in WP Admin > Calendar > Upcoming Events. */\n";

        foreach (self::get_themes() as $t) {
            $slug   = $t['slug'];
            $sel    = '.up-next-theme-' . $slug;
            $listSel = $sel . ' .upcoming-list';
            $itemSel = $sel . ' .upcoming-event';

            $hide_section_headers = empty($t['show_section_headers']);
            $hide_time            = empty($t['show_time']);
            $hide_join            = empty($t['show_join_button']);

            // v3.128 — Outer container styling. The renderer wraps
            // the events block in <div class="up-next-outer">
            // when the theme defines outer_padding or
            // outer_border_width or outer_bg_color != bg_color.
            // The .up-next-outer is a SIBLING of the upcoming-
            // events wrapper if it exists, so we target its
            // descendant selector for the colors and the wrapper
            // itself for the frame/padding.
            $outer_active = ($t['outer_padding'] > 0)
                          || ($t['outer_border_width'] > 0)
                          || (strtolower($t['outer_bg_color']) !== '#ffffff');
            if ($outer_active) {
                $out .= "{$sel}.up-next-outer{";
                $out .= "background:" . self::c($t['outer_bg_color']) . ";";
                $out .= "border:" . (int) $t['outer_border_width'] . "px solid " . self::c($t['outer_border_color']) . ";";
                $out .= "border-radius:" . (int) $t['outer_border_radius'] . "px;";
                $out .= "padding:" . (int) $t['outer_padding'] . "px;";
                if ((int) $t['outer_max_width'] > 0) {
                    $out .= "max-width:" . (int) $t['outer_max_width'] . "px;";
                    $out .= "margin-left:auto;margin-right:auto;";
                }
                $out .= "}\n";
                // When outer is active, the inner .upcoming-events
                // shouldn't double-paint its own background.
                $out .= "{$sel}.up-next-outer .upcoming-events{background:transparent;padding:0;}\n";
            }

            // Header (v3.128). Theme-driven title above all
            // section blocks. Only present in markup when
            // header_text is non-empty (renderer-side gate).
            $font_stack = 'inherit';
            if ($t['header_font'] === 'serif')   $font_stack = "'Georgia', 'Times New Roman', serif";
            if ($t['header_font'] === 'display') $font_stack = "'Bungee', 'Lilita One', 'Fredoka One', 'Arial Black', Impact, sans-serif";
            if ($t['header_font'] === 'mono')    $font_stack = "ui-monospace, 'SFMono-Regular', Menlo, monospace";

            $out .= "{$sel} .up-next-header{";
            $out .= "color:" . self::c($t['header_color']) . ";";
            $out .= "font-size:" . (int) $t['header_size'] . "px;";
            $out .= "font-family:" . $font_stack . ";";
            $out .= "text-align:" . esc_attr($t['header_align']) . ";";
            $out .= "margin:0 0 18px;font-weight:800;letter-spacing:0.5px;";
            $out .= "}\n";
            if (!empty($t['header_underline'])) {
                // Underline alignment tracks header_align so a
                // centered title gets a centered line and a
                // right-aligned title gets a right-flush line.
                $under_margin = '8px auto 0 0';                      // left
                if ($t['header_align'] === 'center') $under_margin = '8px auto 0 auto';
                if ($t['header_align'] === 'right')  $under_margin = '8px 0 0 auto';
                $out .= "{$sel} .up-next-header::after{";
                $out .= "content:'';display:block;height:2px;width:80px;background:currentColor;";
                $out .= "margin:" . $under_margin . ";";
                $out .= "opacity:0.85;";
                $out .= "}\n";
            }

            // Footer (v3.128).
            $out .= "{$sel} .up-next-footer{";
            $out .= "color:" . self::c($t['footer_color']) . ";";
            $out .= "font-size:" . (int) $t['footer_size'] . "px;";
            $out .= "text-align:" . esc_attr($t['footer_align']) . ";";
            $out .= "margin:24px 0 0;line-height:1.4;";
            $out .= "}\n";
            $out .= "{$sel} .up-next-footer a{color:inherit;text-decoration:underline;}\n";

            // Root: card colors, gap, base typography
            $out .= "{$sel}{";
            $out .= "color:" . self::c($t['text_color']) . ";";
            $out .= "background:" . self::c($t['bg_color']) . ";";
            $out .= "padding:0;";
            $out .= "}\n";

            // Section headers (This Week / Next Week / Coming up).
            // The shortcode renders each section's heading as the
            // first <h3> inside the .upcoming-week wrapper.
            if ($hide_section_headers) {
                $out .= "{$sel} .upcoming-week > h3,{$sel} .upcoming-week > h2{display:none;}\n";
            } else {
                $out .= "{$sel} .upcoming-week > h3,{$sel} .upcoming-week > h2{";
                $out .= "background:" . self::c($t['section_header_bg']) . ";";
                $out .= "color:"      . self::c($t['section_header_text']) . ";";
                $out .= "font-size:"  . (int) $t['section_header_size'] . "px;";
                $out .= "padding:8px 12px;border-radius:" . (int) $t['border_radius'] . "px;margin:0 0 10px;";
                $out .= "}\n";
            }
            // Sections gap
            $out .= "{$sel} .upcoming-week{margin-bottom:" . (int) $t['section_gap'] . "px;}\n";

            // Column layout when the theme requests "grid"
            if ($t['layout'] === 'grid') {
                $cols = (int) $t['columns'];
                if ($cols < 1) $cols = 1;
                $out .= "{$listSel}{display:grid;grid-template-columns:repeat({$cols},minmax(0,1fr));gap:" . (int) $t['card_gap'] . "px;list-style:none;margin:0;padding:0;}\n";
                $out .= "{$itemSel}{flex-direction:" . ($t['image_position'] === 'top' ? 'column' : 'row') . ";align-items:" . ($t['image_position'] === 'top' ? 'stretch' : 'flex-start') . ";}\n";
            } elseif ($t['layout'] === 'compact') {
                $out .= "{$listSel}{list-style:none;margin:0;padding:0;}\n";
                $out .= "{$itemSel}{flex-direction:row;align-items:baseline;}\n";
            } else { // rows (default)
                $out .= "{$listSel}{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:" . (int) $t['card_gap'] . "px;}\n";
                $out .= "{$itemSel}{flex-direction:" . ($t['image_position'] === 'top' ? 'column' : 'row') . ";align-items:" . ($t['image_position'] === 'top' ? 'stretch' : 'flex-start') . ";}\n";
            }

            // Card chrome
            $out .= "{$itemSel}{";
            $out .= "display:flex;gap:" . (int) $t['card_padding'] . "px;";
            $out .= "background:" . self::c($t['bg_color']) . ";";
            $out .= "color:" . self::c($t['text_color']) . ";";
            $out .= "border:" . (int) $t['border_width'] . "px solid " . self::c($t['border_color']) . ";";
            $out .= "border-radius:" . (int) $t['border_radius'] . "px;";
            $out .= "padding:" . (int) $t['card_padding'] . "px;";
            $out .= "list-style:none;";
            $out .= "}\n";

            // Image cell
            if (!empty($t['show_image'])) {
                if ($t['image_position'] === 'top') {
                    $out .= "{$itemSel} .upcoming-thumb{width:100%;aspect-ratio:16/9;background-size:cover;background-position:center;border-radius:" . max(0, (int) $t['border_radius'] - 2) . "px;flex:0 0 auto;}\n";
                } else {
                    $out .= "{$itemSel} .upcoming-thumb{width:96px;height:96px;flex:0 0 96px;background-size:cover;background-position:center;border-radius:" . max(0, (int) $t['border_radius'] - 2) . "px;}\n";
                }
                $out .= "{$itemSel}:not(.has-thumb) .upcoming-thumb{display:none;}\n";
            } else {
                $out .= "{$itemSel} .upcoming-thumb{display:none;}\n";
            }

            // Inline text bits
            $out .= "{$itemSel} .upcoming-date{color:" . self::c($t['accent_color']) . ";font-weight:600;font-size:" . (int) $t['date_size'] . "px;}\n";
            $out .= "{$itemSel} .upcoming-separator{color:" . self::c($t['muted_color']) . ";}\n";
            $out .= "{$itemSel} .upcoming-title{font-size:" . (int) $t['title_size'] . "px;color:" . self::c($t['text_color']) . ";}\n";
            $out .= "{$itemSel} .upcoming-meta-row{color:" . self::c($t['muted_color']) . ";font-size:" . max(11, (int) $t['date_size'] - 1) . "px;display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;}\n";

            if ($hide_time) {
                $out .= "{$sel} .upcoming-time-only{display:none;}\n";
            }

            // Join button
            if ($hide_join) {
                $out .= "{$sel} .upcoming-join-meeting,{$sel} .upcoming-online-meeting{display:none;}\n";
            } else {
                $out .= "{$sel} .upcoming-join-meeting .pta-join-meeting{background:" . self::c($t['accent_color']) . ";color:" . self::c($t['accent_text_color']) . ";border-radius:" . max(2, (int) $t['border_radius'] - 2) . "px;padding:4px 10px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;font-size:" . max(11, (int) $t['date_size'] - 1) . "px;}\n";
            }

            // Date pill (v3.128). When date_pill='left', a
            // stacked day/number pill renders on the left of
            // every card, replacing the thumb position. The
            // inline .upcoming-date text in the card body is
            // hidden because the pill carries the date — keeping
            // it would just duplicate.
            if ($t['date_pill'] === 'left') {
                $out .= "{$itemSel}{flex-direction:row;align-items:stretch;}\n";
                $out .= "{$itemSel} .upcoming-thumb{display:none !important;}\n";
                $out .= "{$itemSel} .upcoming-date,{$itemSel} .upcoming-separator{display:none;}\n";
                $out .= "{$sel} .upcoming-date-pill{";
                $out .= "background:" . self::c($t['pill_bg_color']) . ";";
                $out .= "color:" . self::c($t['pill_text_color']) . ";";
                $out .= "border-radius:" . (int) $t['pill_radius'] . "px;";
                $out .= "flex:0 0 " . (int) $t['pill_width'] . "px;";
                $out .= "display:flex;flex-direction:column;align-items:center;justify-content:center;";
                $out .= "padding:8px 6px;text-transform:uppercase;line-height:1.05;";
                $out .= "}\n";
                $out .= "{$sel} .upcoming-date-pill-day{font-size:" . max(12, (int) $t['date_size']) . "px;font-weight:700;}\n";
                $out .= "{$sel} .upcoming-date-pill-num{font-size:" . max(18, (int) $t['title_size'] + 4) . "px;font-weight:800;margin-top:2px;}\n";
                // Card body grows to fill remaining width.
                $out .= "{$itemSel} .upcoming-body{flex:1;display:flex;flex-direction:column;justify-content:center;gap:4px;}\n";
            } else {
                $out .= "{$sel} .upcoming-date-pill{display:none;}\n";
            }

            // Location badge (v3.128). Auto-shown when
            // show_location_badge=true, choosing between
            // "IN PERSON" / "ONLINE" labels based on whether
            // the event has an online URL. Renders inside the
            // card body as an absolute-positioned chip in the
            // top-right corner, which works for both row and
            // grid layouts.
            if (!empty($t['show_location_badge'])) {
                $out .= "{$itemSel}{position:relative;}\n";
                $out .= "{$sel} .upcoming-location-badge{";
                $out .= "position:absolute;top:8px;right:8px;";
                $out .= "background:" . self::c($t['badge_bg_color']) . ";";
                $out .= "color:" . self::c($t['badge_color']) . ";";
                $out .= "border:1px solid " . self::c($t['badge_color']) . ";";
                $out .= "border-radius:6px;padding:3px 8px;";
                $out .= "font-size:10px;font-weight:700;letter-spacing:0.5px;";
                $out .= "text-transform:uppercase;line-height:1;";
                $out .= "display:inline-flex;align-items:center;gap:4px;";
                $out .= "}\n";
                $out .= "{$sel} .upcoming-location-badge .dashicons{font-size:14px;width:14px;height:14px;}\n";
                // Give the title some right-padding so it
                // doesn't run under the badge.
                $out .= "{$itemSel} .upcoming-title{padding-right:96px;}\n";
            } else {
                $out .= "{$sel} .upcoming-location-badge{display:none;}\n";
            }
        }

        return $out;
    }

    /**
     * Belt-and-braces color escape — values are already validated
     * with a regex in normalize_theme(), but we still strip anything
     * exotic in case CSS gets injected before storage.
     */
    private static function c($v) {
        $v = is_string($v) ? trim($v) : '';
        return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v) ? $v : 'inherit';
    }

    /**
     * Enqueue the generated stylesheet (inline). We use
     * wp_register_style with an empty src + wp_add_inline_style so
     * the CSS only ships when something on the page actually uses
     * a theme. The render path calls wp_enqueue_style() lazily.
     */
    public static function enqueue_generated_css() {
        wp_register_style(self::ENQUEUE_HANDLE, false, array(), AZURE_PLUGIN_VERSION);
        wp_add_inline_style(self::ENQUEUE_HANDLE, self::generate_css());
    }

    // -----------------------------------------------------------------
    // AJAX
    // -----------------------------------------------------------------

    private static function guard() {
        if (!current_user_can('manage_options')) { wp_send_json_error('Unauthorized'); return false; }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce'); return false;
        }
        return true;
    }

    public static function ajax_save() {
        if (!self::guard()) return;
        $raw = isset($_POST['themes']) ? wp_unslash($_POST['themes']) : '';
        $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        if (!is_array($decoded)) {
            wp_send_json_error('themes payload must be a JSON array');
        }
        wp_send_json_success(self::save_themes($decoded));
    }

    public static function ajax_delete() {
        if (!self::guard()) return;
        $slug = sanitize_key((string) ($_POST['slug'] ?? ''));
        $existing = get_option(self::OPTION_KEY, array('themes' => array()));
        $themes = isset($existing['themes']) && is_array($existing['themes']) ? $existing['themes'] : array();
        $remaining = array_values(array_filter($themes, function ($t) use ($slug) {
            return is_array($t) && isset($t['slug']) && $t['slug'] !== $slug;
        }));
        wp_send_json_success(self::save_themes($remaining));
    }

    public static function ajax_reset() {
        if (!self::guard()) return;
        wp_send_json_success(self::reset_themes());
    }

    /**
     * Clone an existing theme (built-in or user) into a new
     * user-editable theme. Lets admins start from a complete
     * preset (e.g. `newsletter`) and tweak rather than recreating
     * 25+ fields from defaults.
     */
    public static function ajax_clone() {
        if (!self::guard()) return;
        $source_slug = sanitize_key((string) ($_POST['source_slug'] ?? ''));
        $new_slug    = sanitize_key((string) ($_POST['new_slug'] ?? ''));
        $new_label   = isset($_POST['new_label']) ? sanitize_text_field((string) $_POST['new_label']) : '';

        if ($source_slug === '' || $new_slug === '') {
            wp_send_json_error('Both source_slug and new_slug are required.');
        }
        $source = self::get_theme($source_slug);
        if (!$source) {
            wp_send_json_error('Source theme not found: ' . $source_slug);
        }
        // Prevent overwriting an existing user theme via clone.
        // Admin must delete first if they want to reuse a slug.
        $existing = self::get_theme($new_slug);
        if ($existing) {
            wp_send_json_error('A theme with slug "' . $new_slug . '" already exists. Pick a different slug or delete the existing one first.');
        }

        $clone = $source;
        unset($clone['is_builtin']);
        $clone['slug']  = $new_slug;
        $clone['label'] = $new_label !== '' ? $new_label : ($source['label'] . ' (copy)');

        // Merge into existing user themes list, drop builtins,
        // append clone, save.
        $stored = get_option(self::OPTION_KEY, array('themes' => array()));
        $themes = isset($stored['themes']) && is_array($stored['themes']) ? $stored['themes'] : array();
        $themes[] = $clone;
        wp_send_json_success(self::save_themes($themes));
    }

    /**
     * Server-side render of `[up-next theme="<slug>"]` for the
     * admin Live Preview panel. Returns the rendered HTML along
     * with the canonical shortcode string so the UI can show
     * both the result and the snippet admins can paste elsewhere.
     *
     * Bypasses the shortcode's transient cache (cache="false") so
     * preview always reflects the latest saved theme — admins
     * tweaking colors expect to see their last save immediately,
     * not yesterday's cached HTML.
     *
     * The CSS for every defined theme is already enqueued on this
     * admin page via `Azure_UpNext_Themes::enqueue_generated_css`,
     * so the returned HTML inherits its scoped styles without
     * needing an extra <style> block.
     */
    public static function ajax_preview() {
        if (!self::guard()) return;

        $slug    = sanitize_key((string) ($_POST['slug'] ?? ''));
        $columns = isset($_POST['columns']) ? max(1, min(4, (int) $_POST['columns'])) : 2;
        if ($slug === '') $slug = 'default';

        // v3.128: optional themes payload. When the admin tweaks
        // fields and clicks Preview without saving first, the JS
        // posts the full current form state as `themes` JSON.
        // Persist it before rendering so the preview reflects
        // the unsaved tweaks. If `themes` is omitted (legacy
        // callers, built-in theme preview), skip save.
        if (isset($_POST['themes'])) {
            $raw = wp_unslash($_POST['themes']);
            $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
            if (is_array($decoded)) {
                self::save_themes($decoded);
                self::$cached_themes = null; // ensure get_theme() reads fresh
            }
        }

        // Validate the slug actually exists so we don't render an
        // empty wrapper that silently fails to apply any theme.
        $theme = self::get_theme($slug);
        if (!$theme) {
            wp_send_json_error('Unknown theme slug: ' . $slug);
        }

        $shortcode = '[up-next theme="' . $slug . '" columns="' . $columns . '" cache="false"]';
        $html      = do_shortcode($shortcode);

        // If the shortcode rendered an empty string (legitimate
        // case: no upcoming events at all and show-empty=false),
        // fall back to an informational placeholder so the admin
        // doesn't see an empty preview box and assume the theme
        // is broken.
        if (trim((string) $html) === '') {
            $html = '<p style="padding:14px;color:#646970;font-style:italic;">'
                  . esc_html__('Shortcode rendered no output (no upcoming events match the current filters).', 'azure-plugin')
                  . '</p>';
        }

        wp_send_json_success(array(
            'slug'      => $slug,
            'label'     => isset($theme['label']) ? $theme['label'] : $slug,
            'shortcode' => $shortcode,
            'html'      => $html,
        ));
    }
}
