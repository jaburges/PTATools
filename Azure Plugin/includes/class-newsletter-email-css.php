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

    /**
     * Mobile stack rules for 2- and 3-column newsletter tables.
     * Matches new `.nl-stack-cols` blocks and the older width="50%"
     * / 33% / 34% cells already in saved campaigns.
     */
    public static function column_stack_css() {
        return '@media only screen and (max-width: 600px) {'
            . ' .nl-stack-cols, .nl-stack-cols tbody, .nl-stack-cols tr { display: block !important; width: 100% !important; }'
            . ' .nl-stack-cols td, td[width="50%"], td[width="33%"], td[width="34%"] { display: block !important; width: 100% !important; max-width: 100% !important; box-sizing: border-box !important; padding-left: 0 !important; padding-right: 0 !important; }'
            . ' .nl-stack-cols img, td[width="50%"] img, td[width="33%"] img, td[width="34%"] img { width: 100% !important; max-width: 100% !important; height: auto !important; }'
            . ' }';
    }

    /**
     * Insert the stack media query once, just before </head>.
     */
    public static function ensure_column_stack_style($html) {
        if ($html === '' || $html === null) {
            return $html;
        }
        if (strpos($html, self::MARKER) !== false) {
            return $html;
        }
        $style = '<style type="text/css">' . self::MARKER . self::column_stack_css() . '</style>';
        if (stripos($html, '</head>') !== false) {
            return preg_replace('/<\/head>/i', $style . '</head>', $html, 1);
        }
        return $style . $html;
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
        return $html;
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
                        $existing_style = $element->getAttribute('style');
                        $new_style = $existing_style
                            ? rtrim($existing_style, '; ') . '; ' . $properties
                            : $properties;
                        $element->setAttribute('style', $new_style);
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
