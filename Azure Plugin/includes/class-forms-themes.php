<?php
/**
 * Form themes: branding only, stored in wp_options.azure_form_themes.
 *
 * Field names match Azure_UpNext_Themes::normalize_theme() so the two
 * lists can later merge into one shared brand layer. Rendering is CSS
 * variables on .pta-form-theme-<slug>; pta-forms.css reads them, so only
 * the themes used on a page are ever printed.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Forms_Themes {

    const OPTION_KEY = 'azure_form_themes';
    const STORAGE_VERSION = 1;

    /** Branding keys copied from an up-next theme. */
    const UPNEXT_KEYS = array(
        'bg_color', 'text_color', 'accent_color', 'accent_text_color', 'muted_color', 'border_color',
        'border_width', 'border_radius', 'title_size',
        'outer_bg_color', 'outer_border_color', 'outer_border_width', 'outer_border_radius', 'outer_padding', 'outer_max_width',
        'header_text', 'header_color', 'header_size', 'header_align', 'header_underline', 'header_font',
        'footer_html', 'footer_color', 'footer_align', 'footer_size',
    );

    /** @var array|null */
    private static $cache = null;

    /** @var array<string,bool> Slugs whose CSS was already printed this request. */
    private static $printed = array();

    public static function builtin_themes() {
        return array(
            self::normalize_theme(array('label' => __('Default', 'azure-plugin')), 'default'),
        );
    }

    /**
     * @return array<int,array>
     */
    public static function get_themes() {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $builtins = self::builtin_themes();
        $builtin_slugs = array_flip(array_column($builtins, 'slug'));
        $themes = $builtins;
        foreach ($themes as $i => $t) {
            $themes[$i]['is_builtin'] = true;
        }

        $stored = get_option(self::OPTION_KEY, array());
        $user = (is_array($stored) && isset($stored['themes']) && is_array($stored['themes'])) ? $stored['themes'] : array();
        foreach ($user as $t) {
            if (!is_array($t) || empty($t['slug'])) {
                continue;
            }
            $slug = self::clean_slug($t['slug']);
            if ($slug === '' || isset($builtin_slugs[$slug])) {
                continue;
            }
            $n = self::normalize_theme($t, $slug);
            $n['is_builtin'] = false;
            $themes[] = $n;
        }
        self::$cache = $themes;
        return $themes;
    }

    /**
     * Unknown slugs fall back to the default theme.
     */
    public static function get_theme($slug) {
        $slug = self::clean_slug($slug);
        $themes = self::get_themes();
        foreach ($themes as $t) {
            if ($t['slug'] === $slug) {
                return $t;
            }
        }
        return $themes[0];
    }

    public static function theme_exists($slug) {
        $slug = self::clean_slug($slug);
        foreach (self::get_themes() as $t) {
            if ($t['slug'] === $slug) {
                return true;
            }
        }
        return false;
    }

    /**
     * Replace the saved (non-builtin) theme list.
     *
     * @param array $themes
     * @return array Saved themes.
     */
    public static function save_themes(array $themes) {
        $builtin_slugs = array_flip(array_column(self::builtin_themes(), 'slug'));
        $clean = array();
        $seen = array();
        foreach ($themes as $t) {
            if (!is_array($t)) {
                continue;
            }
            $slug = self::clean_slug($t['slug'] ?? '');
            if ($slug === '' || isset($builtin_slugs[$slug]) || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $clean[] = self::normalize_theme($t, $slug);
        }
        update_option(self::OPTION_KEY, array(
            'version'  => self::STORAGE_VERSION,
            'themes'   => $clean,
            'saved_at' => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
        ), false);
        self::$cache = null;
        return $clean;
    }

    public static function save_theme(array $theme) {
        $slug = self::clean_slug($theme['slug'] ?? '');
        if ($slug === '') {
            return null;
        }
        $list = array();
        $replaced = false;
        foreach (self::get_themes() as $t) {
            if (!empty($t['is_builtin'])) {
                continue;
            }
            if ($t['slug'] === $slug) {
                $list[] = array_merge($theme, array('slug' => $slug));
                $replaced = true;
            } else {
                $list[] = $t;
            }
        }
        if (!$replaced) {
            $list[] = array_merge($theme, array('slug' => $slug));
        }
        self::save_themes($list);
        return self::theme_exists($slug) ? self::get_theme($slug) : null;
    }

    public static function delete_theme($slug) {
        $slug = self::clean_slug($slug);
        $list = array();
        foreach (self::get_themes() as $t) {
            if (empty($t['is_builtin']) && $t['slug'] !== $slug) {
                $list[] = $t;
            }
        }
        self::save_themes($list);
    }

    /**
     * Branding from an up-next theme, read-only.
     *
     * @param array $upnext Normalised up-next theme.
     */
    public static function from_upnext(array $upnext, $slug = '', $label = '') {
        $theme = array();
        foreach (self::UPNEXT_KEYS as $key) {
            if (array_key_exists($key, $upnext)) {
                $theme[$key] = $upnext[$key];
            }
        }
        $slug = self::clean_slug($slug !== '' ? $slug : ($upnext['slug'] ?? ''));
        $theme['label'] = $label !== '' ? $label : (string) ($upnext['label'] ?? $slug);
        return self::normalize_theme($theme, $slug);
    }

    public static function clean_slug($slug) {
        $slug = strtolower(trim((string) $slug));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug);
        return trim(substr((string) $slug, 0, 60), '-');
    }

    public static function normalize_theme(array $t, $slug) {
        $col = function ($v, $fallback) {
            $v = is_string($v) ? trim($v) : '';
            return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v) ? $v : $fallback;
        };
        $px = function ($v, $fallback, $min, $max) {
            $v = is_numeric($v) ? (int) $v : $fallback;
            return max($min, min($max, $v));
        };
        $pick = function ($v, array $allowed, $fallback) {
            return in_array($v, $allowed, true) ? $v : $fallback;
        };
        $kses = function ($v) {
            $v = (string) $v;
            return function_exists('wp_kses_post') ? wp_kses_post($v) : strip_tags($v, '<a><strong><em><b><i><br><span>');
        };
        $text = function ($v) {
            $v = (string) $v;
            return function_exists('sanitize_text_field') ? sanitize_text_field($v) : trim(strip_tags($v));
        };

        return array(
            'slug'                => $slug,
            'label'               => isset($t['label']) && $t['label'] !== '' ? $text($t['label']) : ucfirst(str_replace('-', ' ', $slug)),

            'bg_color'            => $col($t['bg_color'] ?? '', '#ffffff'),
            'text_color'          => $col($t['text_color'] ?? '', '#1d2327'),
            'accent_color'        => $col($t['accent_color'] ?? '', '#2271b1'),
            'accent_text_color'   => $col($t['accent_text_color'] ?? '', '#ffffff'),
            'muted_color'         => $col($t['muted_color'] ?? '', '#646970'),
            'border_color'        => $col($t['border_color'] ?? '', '#c3c4c7'),

            'border_width'        => $px($t['border_width'] ?? 1, 1, 0, 6),
            'border_radius'       => $px($t['border_radius'] ?? 6, 6, 0, 32),
            'title_size'          => $px($t['title_size'] ?? 16, 16, 12, 24),

            'outer_bg_color'      => $col($t['outer_bg_color'] ?? '', '#ffffff'),
            'outer_border_color'  => $col($t['outer_border_color'] ?? '', '#dcdcde'),
            'outer_border_width'  => $px($t['outer_border_width'] ?? 0, 0, 0, 24),
            'outer_border_radius' => $px($t['outer_border_radius'] ?? 0, 0, 0, 48),
            'outer_padding'       => $px($t['outer_padding'] ?? 0, 0, 0, 96),
            'outer_max_width'     => $px($t['outer_max_width'] ?? 0, 0, 0, 1200),

            'header_text'         => isset($t['header_text']) ? $kses($t['header_text']) : '',
            'header_color'        => $col($t['header_color'] ?? '', '#1d2327'),
            'header_size'         => $px($t['header_size'] ?? 28, 28, 12, 72),
            'header_align'        => $pick($t['header_align'] ?? '', array('left', 'center', 'right'), 'left'),
            'header_underline'    => !empty($t['header_underline']),
            'header_font'         => $pick($t['header_font'] ?? '', array('default', 'serif', 'display', 'mono'), 'default'),

            'footer_html'         => isset($t['footer_html']) ? $kses($t['footer_html']) : '',
            'footer_color'        => $col($t['footer_color'] ?? '', '#646970'),
            'footer_align'        => $pick($t['footer_align'] ?? '', array('left', 'center', 'right'), 'center'),
            'footer_size'         => $px($t['footer_size'] ?? 14, 14, 10, 28),
        );
    }

    public static function font_stack($font) {
        switch ($font) {
            case 'serif':
                return "'Georgia', 'Times New Roman', serif";
            case 'display':
                return "'Bungee', 'Lilita One', 'Fredoka One', 'Arial Black', Impact, sans-serif";
            case 'mono':
                return "ui-monospace, 'SFMono-Regular', Menlo, monospace";
        }
        return 'inherit';
    }

    /**
     * One rule of CSS variables. Every value has been through
     * normalize_theme(), so nothing user-typed reaches the stylesheet
     * except validated colours and integers.
     */
    public static function css_for_theme(array $theme) {
        $slug = self::clean_slug($theme['slug'] ?? '');
        $t = self::normalize_theme($theme, $slug !== '' ? $slug : 'default');
        $vars = array(
            '--pta-bg'             => $t['bg_color'],
            '--pta-text'           => $t['text_color'],
            '--pta-accent'         => $t['accent_color'],
            '--pta-accent-text'    => $t['accent_text_color'],
            '--pta-muted'          => $t['muted_color'],
            '--pta-border'         => $t['border_color'],
            '--pta-border-width'   => $t['border_width'] . 'px',
            '--pta-radius'         => $t['border_radius'] . 'px',
            '--pta-font-size'      => $t['title_size'] . 'px',
            '--pta-outer-bg'       => $t['outer_bg_color'],
            '--pta-outer-border'   => $t['outer_border_width'] . 'px solid ' . $t['outer_border_color'],
            '--pta-outer-radius'   => $t['outer_border_radius'] . 'px',
            '--pta-outer-padding'  => $t['outer_padding'] . 'px',
            '--pta-outer-max'      => $t['outer_max_width'] > 0 ? $t['outer_max_width'] . 'px' : 'none',
            '--pta-header-color'   => $t['header_color'],
            '--pta-header-size'    => $t['header_size'] . 'px',
            '--pta-header-align'   => $t['header_align'],
            '--pta-header-font'    => self::font_stack($t['header_font']),
            '--pta-header-line'    => $t['header_underline'] ? 'underline' : 'none',
            '--pta-footer-color'   => $t['footer_color'],
            '--pta-footer-align'   => $t['footer_align'],
            '--pta-footer-size'    => $t['footer_size'] . 'px',
        );
        $decl = '';
        foreach ($vars as $k => $v) {
            $decl .= $k . ':' . $v . ';';
        }
        return '.pta-form-theme-' . $t['slug'] . '{' . $decl . '}';
    }

    /**
     * Inline <style> the first time a theme is used on a page, '' after.
     */
    public static function style_tag_once(array $theme) {
        $slug = self::clean_slug($theme['slug'] ?? '');
        if ($slug === '') {
            $slug = 'default';
        }
        $theme['slug'] = $slug;
        if (isset(self::$printed[$slug])) {
            return '';
        }
        self::$printed[$slug] = true;
        return '<style id="pta-form-theme-' . $slug . '">' . self::css_for_theme($theme) . '</style>';
    }

    public static function reset_request_state() {
        self::$printed = array();
        self::$cache = null;
    }
}
