<?php
/**
 * Email CSS helpers for newsletters.
 *
 * Table-based 2/3 column blocks stay side-by-side unless a mobile
 * @media query stacks the cells. GrapesJS does not emit that CSS, and
 * the send-path inliner used to drop @media rules, so phones saw
 * squashed columns. This class is the single source for the stack
 * rules and for inlining while keeping media queries.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Newsletter_Email_Css {

    const MARKER = '/* pta-nl-stack-cols */';
    const GAP_MARKER = '/* pta-nl-col-gap */';
    const DIVIDER_MARKER = '/* pta-nl-divider */';
    const OUTLOOK_LH_MARKER = '/* pta-nl-outlook-lh */';
    const COLUMN_GAP_PX = 10;

    /**
     * Mobile stack rules for 2- and 3-column newsletter tables.
     * Matches new `.nl-stack-cols` blocks and the older width="50%"
     * / 33% / 34% cells already in saved campaigns.
     *
     * Do not zero horizontal padding here. The designer uses 10px cell
     * padding as the image/text gutter; wiping it on narrow panes (or
     * when a client applies this query without stacking) makes columns
     * flush against each other.
     */
    public static function column_stack_css() {
        return '@media only screen and (max-width: 600px) {'
            . ' .nl-stack-cols, .nl-stack-cols tbody, .nl-stack-cols tr { display: block !important; width: 100% !important; }'
            . ' .nl-stack-cols td, .nl-stack-cols .nl-column, .nl-stack-col, td[width="50%"], td[width="33%"], td[width="34%"] { display: block !important; width: 100% !important; max-width: 100% !important; box-sizing: border-box !important; }'
            . ' .nl-stack-cols img, .nl-column img, td[width="50%"] img, td[width="33%"] img, td[width="34%"] img { width: 100% !important; max-width: 100% !important; height: auto !important; }'
            . ' }';
    }

    /**
     * Desktop gutter that matches the designer column cells (10px).
     * Kept as a class rule for clients that honour <style>, and copied
     * onto the td style attribute before send.
     */
    public static function column_gap_css() {
        $pad = (int) self::COLUMN_GAP_PX;
        return self::GAP_MARKER
            . ' .nl-stack-cols .nl-column, .nl-stack-cols .nl-stack-col, td.nl-column, td.nl-stack-col { padding: ' . $pad . 'px; vertical-align: top; }'
            . ' .nl-stack-cols img, .nl-column img, .nl-stack-col img { display: block; max-width: 100%; height: auto; }';
    }

    /**
     * Keep divider rules visible. GrapesJS and email resets often zero
     * <hr> borders; the block now uses a 2px bgcolor row, and this
     * restores older <hr> dividers already saved in campaigns.
     */
    public static function divider_css() {
        return self::DIVIDER_MARKER
            . ' table.nl-divider hr, .nl-divider hr { display: block !important; width: 100% !important; height: 0 !important; margin: 0 !important; border: 0 !important; border-top: 2px solid #dddddd !important; }'
            . ' table.nl-divider .nl-divider-rule { height: 2px !important; line-height: 2px !important; font-size: 1px !important; background-color: #dddddd !important; border: 0 !important; }';
    }

    /**
     * Insert stack + gap CSS and copy the gutter onto column cells.
     */
    public static function ensure_column_stack_style($html) {
        if ($html === '' || $html === null) {
            return $html;
        }
        $html = self::ensure_column_cell_padding($html);
        $html = self::wrap_image_hrefs($html);
        $html = self::strip_outlook_paste($html);
        $html = self::outlook_safe_line_heights($html);
        if (strpos($html, self::OUTLOOK_LH_MARKER) === false) {
            $html = self::append_style($html, self::outlook_block_css());
        }
        if (strpos($html, self::GAP_MARKER) === false) {
            $html = self::append_style($html, self::column_gap_css());
        }
        if (strpos($html, self::MARKER) === false) {
            $html = self::append_style($html, self::MARKER . self::column_stack_css());
        }
        if (strpos($html, self::DIVIDER_MARKER) === false) {
            $html = self::append_style($html, self::divider_css());
        }
        return $html;
    }

    /**
     * GrapesJS stores an image "Link URL" as href on the <img>. That is
     * not clickable in email clients — wrap it in <a> unless it already is.
     */
    public static function wrap_image_hrefs($html) {
        if ($html === '' || $html === null || stripos($html, '<img') === false) {
            return $html;
        }
        return preg_replace_callback(
            '/<img\b([^>]*)>/i',
            array(__CLASS__, 'wrap_one_image_href'),
            $html
        );
    }

    private static function wrap_one_image_href($match) {
        $attrs = $match[1];
        if (!preg_match('/\bhref\s*=\s*(["\'])([^"\']*)\1/i', $attrs, $href_match)) {
            return $match[0];
        }
        $url = trim($href_match[2]);
        if ($url === '' || $url === '#') {
            return $match[0];
        }
        $attrs = preg_replace('/\s*href\s*=\s*(["\'])([^"\']*)\1/i', '', $attrs);
        return '<a href="' . $url . '" target="_blank" style="text-decoration:none;border:0;"><img' . $attrs . '></a>';
    }

    /**
     * Outlook-pasted copy (data-olk-copy-source, Aptos, 12pt,
     * line-height:inherit, div-inside-span) collapses in Word/mobile.
     * Flatten that chrome so text is real paragraphs with a px height.
     *
     * @param string $html
     * @return string
     */
    public static function strip_outlook_paste($html) {
        if ($html === '' || $html === null) {
            return $html;
        }
        if (
            stripos($html, 'data-olk-copy-source') === false
            && stripos($html, 'data-ogsc') === false
            && stripos($html, 'Aptos') === false
            && stripos($html, 'line-height:inherit') === false
            && stripos($html, 'line-height: inherit') === false
            && stripos($html, '12pt') === false
        ) {
            return $html;
        }
        $html = self::outlook_paste_divs_to_paragraphs($html);
        $html = self::unwrap_olk_spans($html);
        $html = preg_replace_callback(
            '/style\s*=\s*(["\'])(.*?)\1/is',
            array(__CLASS__, 'clean_outlook_paste_style_attr'),
            $html
        );
        $html = preg_replace('/\s+data-olk-copy-source\s*=\s*(["\'])[^"\']*\1/i', '', $html);
        $html = preg_replace('/\s+data-ogsc\s*=\s*(["\'])[^"\']*\1/i', '', $html);
        $html = preg_replace('/\s+data-ogsb\s*=\s*(["\'])[^"\']*\1/i', '', $html);
        $html = preg_replace('/\s+draggable\s*=\s*(["\'])[^"\']*\1/i', '', $html);
        return $html;
    }

    private static function is_outlook_paste_markup($attrs) {
        if ($attrs === '' || $attrs === null) {
            return false;
        }
        if (preg_match('/\b(nl-section-handle|nl-section-hint|nl-row-gap|nl-button|nl-now-next|nl-divider)\b/i', $attrs)) {
            return false;
        }
        return (bool) preg_match(
            '/data-olk-copy-source|data-ogsc|Aptos|line-height\s*:\s*inherit|font-variant-numeric\s*:\s*inherit|font-size\s*:\s*\d+(?:\.\d+)?pt/i',
            $attrs
        );
    }

    private static function outlook_paste_divs_to_paragraphs($html) {
        for ($i = 0; $i < 8; $i++) {
            $next = preg_replace_callback(
                '/<div\b([^>]*)>((?:(?!<div\b).)*)<\/div>/is',
                array(__CLASS__, 'outlook_paste_one_div'),
                $html
            );
            if ($next === $html) {
                break;
            }
            $html = $next;
        }
        return $html;
    }

    private static function outlook_paste_one_div($match) {
        if (!self::is_outlook_paste_markup($match[1])) {
            return $match[0];
        }
        if (stripos($match[2], '<table') !== false) {
            return $match[0];
        }
        return '<p' . $match[1] . '>' . $match[2] . '</p>';
    }

    private static function unwrap_olk_spans($html) {
        for ($i = 0; $i < 8; $i++) {
            $next = preg_replace(
                '/<span\b[^>]*data-olk-copy-source[^>]*>((?:(?!<span\b).)*)<\/span>/is',
                '$1',
                $html
            );
            if ($next === null || $next === $html) {
                break;
            }
            $html = $next;
        }
        return $html;
    }

    private static function clean_outlook_paste_style_attr($match) {
        $quote = $match[1];
        $style = $match[2];
        $cleaned = self::clean_outlook_paste_decls($style);
        if ($cleaned === $style) {
            return $match[0];
        }
        if (trim($cleaned) === '') {
            return '';
        }
        return 'style=' . $quote . $cleaned . $quote;
    }

    public static function clean_outlook_paste_decls($style) {
        $parts = preg_split('/;/', (string) $style);
        $out = array();
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || strpos($part, ':') === false) {
                continue;
            }
            $bits = explode(':', $part, 2);
            $prop = strtolower(trim($bits[0]));
            $val = trim($bits[1]);
            if ($prop === '') {
                continue;
            }
            if (preg_match('/^font-variant|^font-optical|^font-kerning|^font-feature|^font-variation|^font-language|^font-stretch|^font-size-adjust/', $prop)) {
                continue;
            }
            if ($prop === 'vertical-align' && strtolower($val) === 'baseline') {
                continue;
            }
            if ($prop === 'border' && preg_match('/^0(px)?$/i', $val)) {
                continue;
            }
            if ($prop === 'font-family' && preg_match('/Aptos|Calibri|Cambria|MSFontService/i', $val)) {
                $val = 'Arial, Helvetica, sans-serif';
            }
            if ($prop === 'font-size' && preg_match('/^(\d+(?:\.\d+)?)pt$/i', $val, $m)) {
                $val = (string) max(12, (int) round(((float) $m[1]) * 96 / 72)) . 'px';
            }
            $out[] = $prop . ': ' . $val;
        }
        return implode('; ', $out);
    }

    /**
     * Outlook iOS/Android honour <style> and treat <div> as inline.
     * Keep text wrappers in the block flow so a collapsed line box
     * cannot paint every child at the same Y.
     */
    public static function outlook_block_css() {
        return self::OUTLOOK_LH_MARKER
            . ' p, h1, h2, h3, h4, h5, h6, li { display: block; }'
            . ' div { display: block; }';
    }

    /**
     * Word-based Outlook (Windows + mobile) treats unitless line-height
     * as pt (1.6 → 1.6pt), so 14px text paints on top of itself and the
     * next block starts at that collapsed height. Convert multipliers
     * in both inline styles and <style> rules (mobile honours the latter).
     *
     * @param string $html
     * @return string
     */
    public static function outlook_safe_line_heights($html) {
        if ($html === '' || $html === null || stripos($html, 'line-height') === false) {
            return $html;
        }
        $html = preg_replace_callback(
            '/(<style\b[^>]*>)(.*?)(<\/style>)/is',
            array(__CLASS__, 'outlook_safe_line_height_style_tag'),
            $html
        );
        return preg_replace_callback(
            '/style\s*=\s*(["\'])(.*?)\1/is',
            array(__CLASS__, 'outlook_safe_line_height_attr'),
            $html
        );
    }

    /**
     * @param string $raw   line-height token
     * @param string $style full style attribute
     * @return int|null px value, or null to leave the token alone
     */
    public static function line_height_to_px($raw, $style = '') {
        $raw = strtolower(trim((string) $raw));
        if ($raw === '' || $raw === '100%') {
            return null;
        }
        if (preg_match('/^(\d+(?:\.\d+)?)px$/', $raw, $m)) {
            $px = (float) $m[1];
            return $px < 4 ? null : (int) round($px);
        }
        if (preg_match('/^(\d+(?:\.\d+)?)pt$/', $raw, $m)) {
            return (int) max(1, round(((float) $m[1]) * 96 / 72));
        }
        $font = 14.0;
        if (preg_match('/font-size\s*:\s*(\d+(?:\.\d+)?)px/i', $style, $fs)) {
            $font = (float) $fs[1];
        } elseif (preg_match('/font-size\s*:\s*(\d+(?:\.\d+)?)pt/i', $style, $fs)) {
            $font = ((float) $fs[1]) * 96 / 72;
        }
        if ($raw === 'inherit' || $raw === 'normal') {
            $mult = $raw === 'normal' ? 1.2 : 1.6;
            return (int) max(1, round($font * $mult));
        }
        if (preg_match('/^(\d+(?:\.\d+)?)%$/', $raw, $m)) {
            return (int) max(1, round($font * ((float) $m[1]) / 100));
        }
        if (!preg_match('/^(\d+(?:\.\d+)?)$/', $raw, $m)) {
            return null;
        }
        $num = (float) $m[1];
        if ($num <= 0) {
            return null;
        }
        if ($num <= 4) {
            return (int) max(1, round($font * $num));
        }
        return (int) round($num);
    }

    private static function outlook_safe_line_height_style_tag($match) {
        return $match[1] . self::rewrite_line_height_blocks($match[2]) . $match[3];
    }

    public static function rewrite_line_height_blocks($css) {
        if ($css === '' || $css === null || stripos($css, 'line-height') === false) {
            return $css;
        }
        return preg_replace_callback(
            '/\{([^{}]*)\}/',
            array(__CLASS__, 'outlook_safe_line_height_block'),
            $css
        );
    }

    private static function outlook_safe_line_height_block($match) {
        $rewritten = self::rewrite_line_height_decls($match[1]);
        return $rewritten === $match[1] ? $match[0] : '{' . $rewritten . '}';
    }

    private static function outlook_safe_line_height_attr($match) {
        $rewritten = self::rewrite_line_height_decls($match[2]);
        if ($rewritten === $match[2]) {
            return $match[0];
        }
        return 'style=' . $match[1] . $rewritten . $match[1];
    }

    private static function rewrite_line_height_decls($css) {
        if (!preg_match('/line-height\s*:\s*([^;]+)/i', $css, $lh)) {
            return $css;
        }
        $px = self::line_height_to_px(trim($lh[1]), $css);
        if ($px === null) {
            return $css;
        }
        $css = preg_replace('/line-height\s*:\s*[^;]+/i', 'line-height: ' . $px . 'px', $css, 1);
        if (stripos($css, 'mso-line-height-rule') === false) {
            $css = rtrim($css, "; \n\t") . '; mso-line-height-rule: exactly';
        }
        return $css;
    }

    /**
     * Write padding onto .nl-column cells so the gutter survives clients
     * that strip <style> tags (Outlook) or class-only GrapesJS CSS.
     */
    public static function ensure_column_cell_padding($html) {
        if ($html === '' || $html === null) {
            return $html;
        }
        if (stripos($html, 'nl-column') === false && stripos($html, 'nl-stack-col') === false) {
            return $html;
        }
        return preg_replace_callback(
            '/<td\b([^>]*)>/i',
            array(__CLASS__, 'ensure_column_td_padding_attr'),
            $html
        );
    }

    /**
     * Pull balanced @media { ... } blocks out of a CSS string.
     *
     * @return string[]
     */
    public static function extract_media_queries($css) {
        $blocks = array();
        $offset = 0;
        $len = strlen((string) $css);
        while (($pos = stripos($css, '@media', $offset)) !== false) {
            $brace = strpos($css, '{', $pos);
            if ($brace === false) {
                break;
            }
            $depth = 0;
            $end = false;
            for ($i = $brace; $i < $len; $i++) {
                if ($css[$i] === '{') {
                    $depth++;
                } elseif ($css[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $blocks[] = trim(substr($css, $pos, $i - $pos + 1));
                        $offset = $i + 1;
                        $end = true;
                        break;
                    }
                }
            }
            if (!$end) {
                break;
            }
        }
        return $blocks;
    }

    /**
     * @return string[]
     */
    public static function extract_media_queries_from_html($html) {
        $blocks = array();
        if (!preg_match_all('/<style[^>]*>(.*?)<\/style>/is', (string) $html, $matches)) {
            return $blocks;
        }
        foreach ($matches[1] as $css) {
            $blocks = array_merge($blocks, self::extract_media_queries($css));
        }
        return $blocks;
    }

    /**
     * Inline regular CSS rules, then put @media blocks back in <style>.
     */
    public static function inline_keeping_media($html) {
        if ($html === '' || $html === null) {
            return $html;
        }
        $media = self::extract_media_queries_from_html($html);
        $html = self::inline_regular_rules($html);
        foreach ($media as $block) {
            if (strpos($html, $block) === false) {
                $html = self::append_style($html, $block);
            }
        }
        return self::ensure_column_cell_padding($html);
    }

    /**
     * Merge stylesheet declarations onto an inline style without
     * overwriting properties the designer already set. Appending
     * `td { padding: 0 }` after `padding: 10px` was collapsing the
     * image/text gutter in the delivered email.
     */
    public static function merge_inline_style($existing, $incoming) {
        $base = self::parse_declaration_map($existing);
        $add = self::parse_declaration_map($incoming);
        $padding_keys = array('padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left');
        $has_padding = false;
        foreach ($padding_keys as $key) {
            if (isset($base[$key])) {
                $has_padding = true;
                break;
            }
        }
        foreach ($add as $prop => $val) {
            if (isset($base[$prop])) {
                continue;
            }
            if ($has_padding && in_array($prop, $padding_keys, true)) {
                continue;
            }
            $base[$prop] = $val;
        }
        $out = array();
        foreach ($base as $prop => $val) {
            $out[] = $prop . ': ' . $val;
        }
        return implode('; ', $out);
    }

    private static function parse_declaration_map($css) {
        $map = array();
        foreach (preg_split('/;/', (string) $css) as $part) {
            $part = trim($part);
            if ($part === '' || strpos($part, ':') === false) {
                continue;
            }
            $bits = explode(':', $part, 2);
            $prop = strtolower(trim($bits[0]));
            $val = trim($bits[1]);
            if ($prop !== '' && $val !== '') {
                $map[$prop] = $val;
            }
        }
        return $map;
    }

    private static function style_has_nonzero_padding($style) {
        $map = self::parse_declaration_map($style);
        $keys = array('padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left');
        foreach ($keys as $key) {
            if (!isset($map[$key])) {
                continue;
            }
            if (preg_match('/[1-9]/', $map[$key])) {
                return true;
            }
        }
        return false;
    }

    private static function ensure_column_td_padding_attr($match) {
        $attrs = $match[1];
        if (!preg_match('/\bclass\s*=\s*([\'"])([^\'"]*)\1/i', $attrs, $class_match)) {
            return $match[0];
        }
        $classes = preg_split('/\s+/', trim($class_match[2]));
        if (!in_array('nl-column', $classes, true) && !in_array('nl-stack-col', $classes, true)) {
            return $match[0];
        }
        $pad = (int) self::COLUMN_GAP_PX;
        if (preg_match('/\bstyle\s*=\s*([\'"])(.*?)\1/is', $attrs, $style_match)) {
            $style = $style_match[2];
            if (!self::style_has_nonzero_padding($style)) {
                $style = $style === '' ? 'padding: ' . $pad . 'px' : rtrim($style, '; ') . '; padding: ' . $pad . 'px';
            }
            $attrs = preg_replace('/\bstyle\s*=\s*([\'"])(.*?)\1/is', 'style="' . str_replace('"', '&quot;', $style) . '"', $attrs, 1);
            return '<td' . $attrs . '>';
        }
        return '<td' . $attrs . ' style="padding: ' . $pad . 'px;">';
    }

    private static function append_style($html, $css) {
        $style = '<style type="text/css">' . $css . '</style>';
        if (stripos($html, '</head>') !== false) {
            return preg_replace('/<\/head>/i', $style . '</head>', $html, 1);
        }
        return $style . $html;
    }

    private static function inline_regular_rules($html) {
        $css_rules = array();
        if (preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $html, $matches)) {
            foreach ($matches[1] as $css) {
                $without_media = $css;
                foreach (self::extract_media_queries($css) as $block) {
                    $without_media = str_replace($block, '', $without_media);
                }
                if (preg_match_all('/([^{]+)\{([^}]+)\}/s', $without_media, $rules, PREG_SET_ORDER)) {
                    foreach ($rules as $rule) {
                        $selectors = trim($rule[1]);
                        $properties = trim($rule[2]);
                        if ($selectors === '' || strpos($selectors, '@') === 0) {
                            continue;
                        }
                        foreach (array_map('trim', explode(',', $selectors)) as $selector) {
                            if ($selector === '') {
                                continue;
                            }
                            $css_rules[$selector] = isset($css_rules[$selector])
                                ? $css_rules[$selector] . ' ' . $properties
                                : $properties;
                        }
                    }
                }
            }
        }

        if (empty($css_rules)) {
            return $html;
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $html_with_meta = '<?xml encoding="UTF-8">' . $html;
        $dom->loadHTML($html_with_meta, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        foreach ($css_rules as $selector => $properties) {
            $xpath_query = self::css_to_xpath($selector);
            if ($xpath_query === '') {
                continue;
            }
            try {
                $elements = $xpath->query($xpath_query);
                if ($elements === false) {
                    continue;
                }
                foreach ($elements as $element) {
                    if ($element instanceof DOMElement) {
                        $element->setAttribute(
                            'style',
                            self::merge_inline_style($element->getAttribute('style'), $properties)
                        );
                    }
                }
            } catch (Exception $e) {
                continue;
            }
        }

        $result = $dom->saveHTML();
        $result = preg_replace('/^<\?xml[^>]*\?>\s*/i', '', $result);
        return $result;
    }

    private static function css_to_xpath($selector) {
        $selector = trim($selector);
        if ($selector === '') {
            return '';
        }
        if (preg_match('/^#([\w-]+)$/', $selector, $m)) {
            return "//*[@id='{$m[1]}']";
        }
        if (preg_match('/^\.([\w-]+)$/', $selector, $m)) {
            return "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$m[1]} ')]";
        }
        if (preg_match('/^([\w]+)$/', $selector, $m)) {
            return "//{$m[1]}";
        }
        if (preg_match('/^([\w]+)\.([\w-]+)$/', $selector, $m)) {
            return "//{$m[1]}[contains(concat(' ', normalize-space(@class), ' '), ' {$m[2]} ')]";
        }
        if (preg_match('/^([\w]+)#([\w-]+)$/', $selector, $m)) {
            return "//{$m[1]}[@id='{$m[2]}']";
        }
        if (preg_match('/^([\w]+)\s+([\w]+)$/', $selector, $m)) {
            return "//{$m[1]}//{$m[2]}";
        }
        if (preg_match('/^\*?\.([\w-]+)$/', $selector, $m)) {
            return "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$m[1]} ')]";
        }
        if (preg_match('/^[\w]+/', $selector, $m)) {
            return "//{$m[0]}";
        }
        return '';
    }
}
