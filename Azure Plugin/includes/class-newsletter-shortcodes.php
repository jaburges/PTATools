<?php
/**
 * Expand WordPress shortcodes in newsletter HTML at send/preview time.
 *
 * The designer keeps a dashed placeholder table so editors can see and
 * edit the tag. Recipients must get the rendered output, not that chrome.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Newsletter_Shortcodes {

    /**
     * Replace designer shortcode placeholders and any remaining registered
     * shortcodes in newsletter HTML.
     *
     * @param string $html
     * @return string
     */
    public static function expand($html) {
        if ($html === '' || $html === null || strpos($html, '[') === false) {
            return $html;
        }
        if (!function_exists('do_shortcode')) {
            return $html;
        }

        $html = self::replace_placeholder_tables($html);
        return self::expand_remaining($html);
    }

    /**
     * Leaf tables with a dashed editor border become the rendered shortcode.
     *
     * @param string $html
     * @return string
     */
    private static function replace_placeholder_tables($html) {
        if (!preg_match_all('/\[([a-zA-Z][a-zA-Z0-9_-]+)(\s[^\]]*)?\]/', $html, $matches, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        for ($i = count($matches[0]) - 1; $i >= 0; $i--) {
            $raw = $matches[0][$i][0];
            $pos = $matches[0][$i][1];
            $tag = $matches[1][$i][0];

            if ($tag === 'your_shortcode') {
                continue;
            }
            if (function_exists('shortcode_exists') && !shortcode_exists($tag)) {
                continue;
            }

            $range = self::placeholder_table_range($html, $pos);
            if ($range === null) {
                continue;
            }

            $rendered = self::render_shortcode($raw);
            if ($rendered === null) {
                continue;
            }

            $html = substr_replace($html, $rendered, $range[0], $range[1]);
        }

        return $html;
    }

    /**
     * If the shortcode sits in a dashed editor table with no nested tables,
     * return [start, length] of that table. Otherwise null.
     *
     * @param string $html
     * @param int    $shortcode_pos
     * @return array{0:int,1:int}|null
     */
    private static function placeholder_table_range($html, $shortcode_pos) {
        $before = substr($html, 0, $shortcode_pos);
        $table_open = strripos($before, '<table');
        if ($table_open === false) {
            return null;
        }

        $from_open = substr($html, $table_open);
        $close = stripos($from_open, '</table>');
        if ($close === false) {
            return null;
        }

        $length = $close + 8;
        $table_html = substr($html, $table_open, $length);
        $inner = substr($table_html, 6);
        if (stripos($inner, '<table') !== false) {
            return null;
        }
        $is_now_next = (bool) preg_match('/class=["\'][^"\']*\bnl-now-next\b/i', $table_html);
        if (stripos($table_html, 'dashed') === false && !$is_now_next) {
            return null;
        }

        return array($table_open, $length);
    }

    /**
     * Expand leftover registered shortcodes (typed into a normal text block).
     *
     * @param string $html
     * @return string
     */
    private static function expand_remaining($html) {
        if (!preg_match_all('/\[([a-zA-Z][a-zA-Z0-9_-]+)(\s[^\]]*)?\]/', $html, $matches, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        for ($i = count($matches[0]) - 1; $i >= 0; $i--) {
            $raw = $matches[0][$i][0];
            $pos = $matches[0][$i][1];
            $tag = $matches[1][$i][0];

            if ($tag === 'your_shortcode') {
                continue;
            }
            if (function_exists('shortcode_exists') && !shortcode_exists($tag)) {
                continue;
            }

            $rendered = self::render_shortcode($raw);
            if ($rendered === null) {
                continue;
            }

            $html = substr_replace($html, $rendered, $pos, strlen($raw));
        }

        return $html;
    }

    /**
     * @param string $raw Shortcode token as it appears in the HTML.
     * @return string|null Rendered HTML, or null to leave the source alone.
     */
    private static function render_shortcode($raw) {
        $token = self::clean_token($raw);
        try {
            ob_start();
            $rendered = do_shortcode($token);
            $echoed = ob_get_clean();
        } catch (Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            return null;
        }

        if ($echoed !== '') {
            $rendered = $echoed . $rendered;
        }
        if ($rendered === $token || $rendered === $raw) {
            return null;
        }

        return $rendered;
    }

    /**
     * The shortcode sits in an editable text block, so the designer's rich
     * text tools can wrap part of it in markup, e.g.
     * exclude-calendars="<span class="c1">Math Adventures"</span>. WordPress
     * would then read the attribute as `<span class=`. Attributes never carry
     * HTML here, so tags inside a registered shortcode's brackets are dropped.
     *
     * @param string $html
     * @return string
     */
    public static function clean_tokens_in_html($html) {
        if (!is_string($html) || strpos($html, '[') === false || strpos($html, '<') === false) {
            return $html;
        }
        $out = preg_replace_callback('/\[([a-zA-Z][a-zA-Z0-9_-]+)(\s[^\]]*)?\]/', function ($m) {
            if (strpos($m[0], '<') === false) {
                return $m[0];
            }
            if (function_exists('shortcode_exists') && !shortcode_exists($m[1])) {
                return $m[0];
            }
            return preg_replace('/<[^>]*>/', '', $m[0]);
        }, $html);
        return is_string($out) ? $out : $html;
    }

    /**
     * One shortcode token as do_shortcode should see it: no tags, entities
     * decoded, non-breaking spaces as plain spaces.
     *
     * @param string $raw
     * @return string
     */
    public static function clean_token($raw) {
        $token = preg_replace('/<[^>]*>/', '', (string) $raw);
        $token = html_entity_decode($token, ENT_QUOTES, 'UTF-8');
        return str_replace("\xC2\xA0", ' ', $token);
    }
}
