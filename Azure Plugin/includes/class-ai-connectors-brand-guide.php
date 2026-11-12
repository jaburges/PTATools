<?php
/**
 * AI Connectors brand guide: a WordPress page an administrator picks under
 * AI Connectors > Settings. While it is on, every connected assistant is
 * told to read it (pta_get_brand_guide) before creating or changing pages
 * and posts.
 *
 * The page is ordinary content, so editing it changes what every assistant
 * follows. Parts are found by convention:
 *   agent prompt  a block with the CSS class pta-brand-prompt, otherwise the
 *                 section under a heading containing "prompt"
 *   colours       every #rgb / #rrggbb code, labelled with the rest of its line
 *   fonts         the section under a heading containing "type" or "font"
 *   images        every <img>, with its alt text
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AI_Connectors_Brand_Guide {

    const PROMPT_CLASS = 'pta-brand-prompt';
    const TOOL = 'pta_get_brand_guide';
    const MAX_TEXT = 20000;
    const MAX_COLORS = 30;
    const MAX_IMAGES = 20;

    /** Block tags that end a line when the page is reduced to text. */
    const LINE_TAGS = 'p|div|li|h[1-6]|tr|pre|figure|figcaption|blockquote|ul|ol|table|thead|tbody|section|header|footer';

    /** The selected page while the brand guide is on, or null. */
    public static function page() {
        $s = Azure_AI_Connectors_Settings::get();
        if (empty($s['brand_guide_enabled']) || empty($s['brand_guide_page_id'])) {
            return null;
        }
        $post = get_post((int) $s['brand_guide_page_id']);
        if (!$post || $post->post_type !== 'page' || in_array($post->post_status, array('trash', 'auto-draft', 'inherit'), true)) {
            return null;
        }
        return $post;
    }

    public static function active() {
        return self::page() !== null;
    }

    /** One sentence for the MCP instructions and tool descriptions. */
    public static function instruction() {
        return 'This site has a brand guide. Before you create or update any page or post, call ' . self::TOOL
            . ' and follow it: its agent prompt, colours, fonts and image style.';
    }

    /** Short status for pta_whoami, or null while the brand guide is off. */
    public static function summary() {
        $post = self::page();
        if (!$post) {
            return null;
        }
        return array(
            'page_id' => (int) $post->ID,
            'title'   => $post->post_title,
            'url'     => get_permalink($post),
            'tool'    => self::TOOL,
            'note'    => self::instruction(),
        );
    }

    /** pta_get_brand_guide result for the selected page. */
    public static function read($post = null) {
        $post = $post ?: self::page();
        if (!$post) {
            return new WP_Error('pta_ai_no_brand_guide', 'This site has no brand guide turned on.');
        }
        $html = (string) $post->post_content;
        $text = self::to_text($html);
        $prompt = self::prompt($html);
        $truncated = strlen($text) > self::MAX_TEXT;
        return array(
            'page_id'      => (int) $post->ID,
            'title'        => $post->post_title,
            'url'          => get_permalink($post),
            'modified'     => $post->post_modified,
            'how_to_use'   => 'Follow agent_prompt for everything you write on this site. Use only the colours listed, '
                . 'let the theme supply the fonts unless the guide says otherwise, and match the style of the example images. '
                . 'If the user asks for something the guide rules out, say so and ask before going ahead.',
            'agent_prompt' => $prompt,
            'colors'       => self::colors($text),
            'fonts'        => self::section($html, '/\b(type|typography|fonts?)\b/i'),
            'images'       => self::images($html),
            'content'      => $truncated ? substr($text, 0, self::MAX_TEXT) : $text,
            'truncated'    => $truncated,
        );
    }

    /**
     * Block markup or HTML as plain text: one line per block, table cells
     * joined with " | ", entities decoded, empty lines dropped.
     */
    public static function to_text($html) {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', (string) $html);
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</(' . self::LINE_TAGS . ')>#i', "\n", $html);
        $html = preg_replace('#</t[dh]>\s*<t[dh]\b#i', ' | <td', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array();
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return implode("\n", $lines);
    }

    /** The agent prompt block, falling back to a "prompt" section. */
    public static function prompt($html) {
        $html = (string) $html;
        $pattern = '#<(\w+)\b[^>]*\bclass\s*=\s*"[^"]*\b' . preg_quote(self::PROMPT_CLASS, '#') . '\b[^"]*"[^>]*>#i';
        if (preg_match($pattern, $html, $open, PREG_OFFSET_CAPTURE)) {
            $tag = strtolower($open[1][0]);
            $start = $open[0][1] + strlen($open[0][0]);
            $end = self::closing_tag($html, $tag, $start);
            return self::to_text(substr($html, $start, $end - $start));
        }
        return self::section($html, '/\bprompt\b/i');
    }

    /** Offset of the tag that closes the element opened just before $from. */
    private static function closing_tag($html, $tag, $from) {
        $depth = 1;
        $pos = $from;
        while ($depth > 0 && preg_match('#<(/?)' . $tag . '\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $depth += $m[1][0] === '/' ? -1 : 1;
            if ($depth === 0) {
                return $m[0][1];
            }
            $pos = $m[0][1] + strlen($m[0][0]);
        }
        return strlen($html);
    }

    /** Text under the first heading matching $heading_regex, up to the next heading of the same or higher level. */
    public static function section($html, $heading_regex) {
        $html = (string) $html;
        if (!preg_match_all('#<h([1-6])\b[^>]*>(.*?)</h\1>#is', $html, $heads, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return '';
        }
        foreach ($heads as $i => $h) {
            if (!preg_match($heading_regex, self::to_text($h[2][0]))) {
                continue;
            }
            $level = (int) $h[1][0];
            $start = $h[0][1] + strlen($h[0][0]);
            $end = strlen($html);
            for ($j = $i + 1; $j < count($heads); $j++) {
                if ((int) $heads[$j][1][0] <= $level) {
                    $end = $heads[$j][0][1];
                    break;
                }
            }
            return self::to_text(substr($html, $start, $end - $start));
        }
        return '';
    }

    /**
     * Each distinct hex colour in the text, labelled with the rest of the
     * line it appears on.
     *
     * @return array<int,array{hex:string,label:string}>
     */
    public static function colors($text) {
        $out = array();
        foreach (preg_split('/\R/u', (string) $text) as $line) {
            if (!preg_match_all('/#(?:[0-9a-f]{6}|[0-9a-f]{3})\b/i', $line, $m)) {
                continue;
            }
            $label = trim(preg_replace('/\s*(\|\s*)+/', ' | ', preg_replace('/#(?:[0-9a-f]{6}|[0-9a-f]{3})\b/i', '', $line)), " |:-\t");
            foreach ($m[0] as $hex) {
                $hex = strtoupper($hex);
                if (!isset($out[$hex]) && count($out) < self::MAX_COLORS) {
                    $out[$hex] = array('hex' => $hex, 'label' => function_exists('mb_substr') ? mb_substr($label, 0, 200) : substr($label, 0, 200));
                }
            }
        }
        return array_values($out);
    }

    /** @return array<int,array{url:string,alt:string}> */
    public static function images($html) {
        $out = array();
        if (!preg_match_all('#<img\b[^>]*>#i', (string) $html, $tags)) {
            return $out;
        }
        foreach ($tags[0] as $tag) {
            if (!preg_match('#\bsrc\s*=\s*"([^"]+)"#i', $tag, $src)) {
                continue;
            }
            $url = html_entity_decode($src[1], ENT_QUOTES, 'UTF-8');
            if (strpos($url, '//') === false) {
                $url = home_url('/' . ltrim($url, '/'));
            }
            $alt = preg_match('#\balt\s*=\s*"([^"]*)"#i', $tag, $a) ? html_entity_decode($a[1], ENT_QUOTES, 'UTF-8') : '';
            $out[$url] = array('url' => $url, 'alt' => $alt);
            if (count($out) >= self::MAX_IMAGES) {
                break;
            }
        }
        return array_values($out);
    }

    /**
     * Block markup for a new Brand Guide page, filled in from what the site
     * already has (name, tagline, logo, recent featured images). Colours and
     * fonts are examples to replace.
     */
    public static function starter_content() {
        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $site = $site !== '' ? $site : 'Our PTA';
        $tagline = wp_specialchars_decode((string) get_bloginfo('description'), ENT_QUOTES);
        $e = function ($s) {
            return esc_html($s);
        };

        $b = array();
        $b[] = self::block('paragraph', '<p>' . $e('How ' . $site . ' looks and sounds online. Assistants connected through AI Connectors read this page before they create or change pages and posts, so keep it current: what you change here is what they follow.') . '</p>');

        $b[] = self::heading('Logo and name');
        $logo_id = (int) get_theme_mod('custom_logo');
        $logo = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';
        if ($logo) {
            $b[] = self::block('image', '<figure class="wp-block-image size-medium"><img src="' . esc_url($logo) . '" alt="' . esc_attr($site . ' logo') . '"/></figure>', array('sizeSlug' => 'medium'));
        }
        $items = array('Full name: ' . $site, 'Short name: ' . $site);
        if ($tagline !== '') {
            $items[] = 'Tagline: ' . $tagline;
        }
        $items[] = 'Use the logo as supplied. Never stretch, recolour or add effects to it.';
        $b[] = self::listing($items);

        $b[] = self::heading('Colours');
        $b[] = self::block('table', '<figure class="wp-block-table"><table><thead><tr><th>Colour</th><th>Hex</th><th>Use it for</th></tr></thead><tbody>'
            . '<tr><td>Primary</td><td>#1F4E9C</td><td>Banners, section accents, links in body text</td></tr>'
            . '<tr><td>Call to action</td><td>#F2B705</td><td>Buttons, always with dark text</td></tr>'
            . '<tr><td>Alert</td><td>#C62828</td><td>Urgent deadlines only, at most once per page</td></tr>'
            . '<tr><td>Text</td><td>#333333</td><td>Body text</td></tr>'
            . '<tr><td>Background</td><td>#FFFFFF</td><td>Page and cards</td></tr>'
            . '</tbody></table></figure>');
        $b[] = self::block('paragraph', '<p>' . $e('These are examples. Replace them with your own colours, keeping one hex code per row.') . '</p>');

        $b[] = self::heading('Typography');
        $b[] = self::listing(array(
            'Headings: your heading font, bold (example: Montserrat 700)',
            'Body text: your reading font (example: Source Serif 400)',
            'Buttons and menus: heading font, bold, uppercase',
            'The theme loads these fonts. Pages should not set fonts or font sizes inline.',
        ));

        $b[] = self::heading('Images');
        $b[] = self::block('paragraph', '<p>' . $e('Describe the style of artwork and photos you use, and add a few good examples below. Square 1:1 images at least 1024px wide work best as featured images. Always write alt text. Only use photos of students with their family\'s consent.') . '</p>');
        $examples = self::recent_images(4);
        if ($examples) {
            $figures = '';
            foreach ($examples as $img) {
                $figures .= self::block('image', '<figure class="wp-block-image size-medium"><img src="' . esc_url($img['url']) . '" alt="' . esc_attr($img['alt']) . '"/></figure>', array('sizeSlug' => 'medium'));
            }
            $columns = count($examples);
            $b[] = '<!-- wp:gallery {"columns":' . $columns . ',"linkTo":"none"} -->' . "\n"
                . '<figure class="wp-block-gallery has-nested-images columns-' . $columns . ' is-cropped">' . $figures . '</figure>' . "\n"
                . '<!-- /wp:gallery -->';
        }

        $b[] = self::heading('Voice');
        $b[] = self::listing(array(
            'Warm, upbeat and plain: write the way one neighbour talks to another.',
            'Name your audience the same way every time (example: "families" rather than "parents", so guardians are included, and "students" rather than "kids").',
            'Short paragraphs and active voice. Lead with what is happening, when, where and what to do.',
            'Welcoming to every family. No jargon, no guilt, no sentences in capitals.',
        ));

        $b[] = self::heading('Agent prompt');
        $prompt = array(
            'You are editing pages on the ' . $site . ' website. Follow this brand guide every time you create or change a page or post.',
            '',
            '- Voice: warm, upbeat, neighbour-to-neighbour. Plain English, short paragraphs, active voice. Lead with what, when, where and what to do.',
            '- Audience: use the names for families and students given under Voice, every time.',
            '- Layout: WordPress blocks (heading, paragraph, list, buttons, image, group, columns). The page title is the H1; start sections at H2. One clear call to action near the top, repeated at the end.',
            '- Colours: only the colours in the Colours table above, used as described there.',
            '- Fonts: never set font-family or font sizes inline; the theme supplies them.',
            '- Images: reuse Media Library images first, match the style above, and always write alt text. No photos of students without their family\'s consent.',
            '- Dates like "Friday, Nov 13, 6:00pm" with the location. Prices like "$25".',
            '- Keep content the user did not ask to change. Save as a draft unless the user asks to publish, and summarise your changes.',
        );
        $b[] = self::block(
            'preformatted',
            '<pre class="wp-block-preformatted ' . self::PROMPT_CLASS . '">' . implode('<br>', array_map($e, $prompt)) . '</pre>',
            array('className' => self::PROMPT_CLASS)
        );

        return implode("\n\n", $b);
    }

    /** Featured images of the latest published posts and pages. */
    private static function recent_images($limit) {
        $out = array();
        if (!function_exists('get_posts')) {
            return $out;
        }
        $posts = get_posts(array(
            'post_type'      => array('post', 'page'),
            'post_status'    => 'publish',
            'posts_per_page' => $limit * 3,
            'meta_key'       => '_thumbnail_id',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ));
        foreach ($posts as $p) {
            $thumb = (int) get_post_thumbnail_id($p);
            $url = $thumb ? wp_get_attachment_image_url($thumb, 'medium') : '';
            if ($url && !isset($out[$thumb])) {
                $alt = (string) get_post_meta($thumb, '_wp_attachment_image_alt', true);
                $out[$thumb] = array('url' => $url, 'alt' => $alt !== '' ? $alt : get_the_title($p));
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return array_values($out);
    }

    private static function block($name, $html, array $attrs = array()) {
        $json = $attrs ? ' ' . wp_json_encode($attrs, JSON_UNESCAPED_SLASHES) : '';
        return '<!-- wp:' . $name . $json . ' -->' . "\n" . $html . "\n" . '<!-- /wp:' . $name . ' -->';
    }

    private static function heading($text) {
        return self::block('heading', '<h2 class="wp-block-heading">' . esc_html($text) . '</h2>');
    }

    private static function listing(array $items) {
        $li = '';
        foreach ($items as $item) {
            $li .= '<!-- wp:list-item -->' . "\n" . '<li>' . esc_html($item) . '</li>' . "\n" . '<!-- /wp:list-item -->' . "\n";
        }
        return self::block('list', '<ul class="wp-block-list">' . "\n" . $li . '</ul>');
    }
}
