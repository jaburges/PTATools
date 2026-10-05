<?php
/**
 * On-demand machine translation of posts.
 *
 * A reader picks a language (?pta_lang=xx). The first request for a
 * post and language queues a background job and shows the original with
 * a "being translated" notice; later requests serve the cached
 * translation with a machine-translation notice. Translations are keyed
 * to a hash of the source, so editing a post retranslates it on the next
 * request instead of serving stale text.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AI_Translate {

    const HOOK = 'pta_ai_translate';
    const PARAM = 'pta_lang';
    const UI_OPTION = 'pta_ai_ui_';
    const DAY_OPTION = 'pta_ai_translate_day';
    const CHUNK_CHARS = 6000;

    private static $lang = null;
    private static $post_id = 0;
    private static $row = null;
    private static $pending = false;
    private static $ui = null;

    public static function register_hooks() {
        add_action(self::HOOK, array(__CLASS__, 'translate_post'), 10, 2);
        add_action('template_redirect', array(__CLASS__, 'on_template_redirect'), 5);
        add_action('wp_head', array(__CLASS__, 'render_hreflang'));
    }

    /**
     * code => array(native name, English name, text direction).
     */
    public static function supported_languages() {
        return array(
            'es' => array('Español', 'Spanish', 'ltr'),
            'zh-Hans' => array('中文（简体）', 'Simplified Chinese', 'ltr'),
            'zh-Hant' => array('中文（繁體）', 'Traditional Chinese', 'ltr'),
            'ru' => array('Русский', 'Russian', 'ltr'),
            'vi' => array('Tiếng Việt', 'Vietnamese', 'ltr'),
            'ko' => array('한국어', 'Korean', 'ltr'),
            'ja' => array('日本語', 'Japanese', 'ltr'),
            'hi' => array('हिन्दी', 'Hindi', 'ltr'),
            'te' => array('తెలుగు', 'Telugu', 'ltr'),
            'ta' => array('தமிழ்', 'Tamil', 'ltr'),
            'uk' => array('Українська', 'Ukrainian', 'ltr'),
            'ar' => array('العربية', 'Arabic', 'rtl'),
            'so' => array('Soomaali', 'Somali', 'ltr'),
            'am' => array('አማርኛ', 'Amharic', 'ltr'),
            'fr' => array('Français', 'French', 'ltr'),
            'pt' => array('Português', 'Portuguese', 'ltr'),
            'tl' => array('Tagalog', 'Tagalog', 'ltr'),
        );
    }

    public static function enabled_languages() {
        if (!Azure_AI_Module::feature_enabled('translate')) {
            return array();
        }
        $all = self::supported_languages();
        $out = array();
        foreach ((array) Azure_AI_Module::settings()['ai_languages'] as $code) {
            if (isset($all[$code])) {
                $out[$code] = $all[$code];
            }
        }
        return $out;
    }

    /**
     * Strings the plugin (and a theme, through pta_ai_ui_strings) shows
     * around a translated story. Translated once per language and cached.
     */
    public static function ui_strings() {
        return array_values(array_unique(array_map('strval', (array) apply_filters('pta_ai_ui_strings', array(
            'Key points',
            'AI-generated summary',
            'Similar story',
            'Machine translation.',
            'This story was translated by AI from the original and may contain errors.',
            'Read the original',
            'Translation in progress.',
            'This story is being translated. The original is shown for now; reload in a minute.',
            'Language',
        )))));
    }

    public static function ui($text) {
        if (!self::$row) {
            return $text;
        }
        if (self::$ui === null) {
            $cached = get_option(self::UI_OPTION . self::$lang);
            self::$ui = (is_array($cached) && isset($cached['strings'])) ? (array) $cached['strings'] : array();
        }
        return isset(self::$ui[$text]) && self::$ui[$text] !== '' ? (string) self::$ui[$text] : $text;
    }

    public static function source_hash($post) {
        return md5(Azure_AI_Module::content_hash($post)
            . '|' . wp_json_encode(Azure_AI_Summary::visible($post->ID))
            . '|' . (string) $post->post_excerpt);
    }

    public static function is_bot($user_agent) {
        return $user_agent === '' || (bool) preg_match('/bot|crawl|spider|slurp|preview|monitor|fetch|curl|wget|python|httpclient|headless|lighthouse/i', $user_agent);
    }

    // ── Request handling ──────────────────────────────────────────────

    public static function requested_lang() {
        $lang = isset($_GET[self::PARAM]) ? sanitize_text_field(wp_unslash($_GET[self::PARAM])) : '';
        return isset(self::enabled_languages()[$lang]) ? $lang : '';
    }

    public static function on_template_redirect() {
        $lang = self::requested_lang();
        if ($lang === '' || !is_singular('post')) {
            return;
        }
        $post = get_queried_object();
        if (!$post || $post->post_status !== 'publish' || !Azure_AI_Store::is_installed()) {
            return;
        }
        self::$lang = $lang;
        self::$post_id = (int) $post->ID;

        $row = Azure_AI_Store::get_translation($post->ID, $lang);
        if ($row && $row->content_hash === self::source_hash($post)) {
            self::$row = $row;
            self::add_display_filters();
            return;
        }

        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) wp_unslash($_SERVER['HTTP_USER_AGENT']) : '';
        if (self::is_bot($ua)) {
            return;
        }
        if (self::queue($post->ID, $lang)) {
            self::$pending = true;
            nocache_headers();
            add_filter('pta_edge_cache_shareable', '__return_false');
            add_action('wp_body_open', array(__CLASS__, 'render_notice'));
            add_action('wp_head', array(__CLASS__, 'noindex'), 1);
        }
    }

    /**
     * @return bool Whether a job is queued (now or already).
     */
    public static function queue($post_id, $lang) {
        $args = array((int) $post_id, (string) $lang);
        if (wp_next_scheduled(self::HOOK, $args)) {
            return true;
        }
        if (!self::under_daily_cap() || Azure_AI_Store::over_budget()) {
            return false;
        }
        wp_schedule_single_event(time(), self::HOOK, $args);
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
        return true;
    }

    public static function under_daily_cap() {
        $cap = (int) (Azure_AI_Module::settings()['ai_translate_daily_cap'] ?? 0);
        $day = get_option(self::DAY_OPTION);
        $count = (is_array($day) && ($day['date'] ?? '') === gmdate('Y-m-d')) ? (int) $day['count'] : 0;
        return $count < $cap;
    }

    private static function count_today() {
        $day = get_option(self::DAY_OPTION);
        $today = gmdate('Y-m-d');
        $count = (is_array($day) && ($day['date'] ?? '') === $today) ? (int) $day['count'] : 0;
        update_option(self::DAY_OPTION, array('date' => $today, 'count' => $count + 1), false);
    }

    private static function add_display_filters() {
        add_filter('the_title', array(__CLASS__, 'filter_title'), 20, 2);
        add_filter('document_title_parts', array(__CLASS__, 'filter_document_title'));
        add_filter('the_content', array(__CLASS__, 'filter_content'), PHP_INT_MAX);
        add_filter('get_the_excerpt', array(__CLASS__, 'filter_excerpt'), 20, 2);
        add_filter('language_attributes', array(__CLASS__, 'filter_language_attributes'));
        add_filter('gettext', array(__CLASS__, 'filter_gettext'), 20, 3);
        add_action('wp_body_open', array(__CLASS__, 'render_notice'));
        remove_action('wp_head', 'rel_canonical');
        add_action('wp_head', array(__CLASS__, 'render_canonical'), 1);
    }

    public static function current_lang() {
        return self::$row ? self::$lang : '';
    }

    public static function translated_summary($post_id) {
        if (!self::$row || (int) $post_id !== self::$post_id) {
            return null;
        }
        $data = json_decode((string) self::$row->summary, true);
        if (!is_array($data) || trim((string) ($data['summary'] ?? '')) === '') {
            return null;
        }
        return array(
            'summary' => (string) $data['summary'],
            'points' => array_values(array_map('strval', (array) ($data['points'] ?? array()))),
        );
    }

    public static function filter_title($title, $id = 0) {
        return ((int) $id === self::$post_id && self::$row->title !== '') ? esc_html(self::$row->title) : $title;
    }

    public static function filter_document_title($parts) {
        if (self::$row->title !== '') {
            $parts['title'] = self::$row->title;
        }
        return $parts;
    }

    public static function filter_content($content) {
        if ((int) get_the_ID() !== self::$post_id || self::$row->content === '') {
            return $content;
        }
        $dir = self::supported_languages()[self::$lang][2];
        return '<div class="pta-ai-translated" lang="' . esc_attr(self::$lang) . '" dir="' . esc_attr($dir) . '">'
            . self::$row->content . '</div>';
    }

    public static function filter_excerpt($excerpt, $post = null) {
        return ($post && (int) $post->ID === self::$post_id && self::$row->excerpt !== '') ? self::$row->excerpt : $excerpt;
    }

    public static function filter_language_attributes($output) {
        $dir = self::supported_languages()[self::$lang][2];
        $output = preg_replace('/\s*(lang|dir)="[^"]*"/', '', $output);
        return trim($output . ' lang="' . esc_attr(self::$lang) . '" dir="' . esc_attr($dir) . '"');
    }

    public static function filter_gettext($translation, $text, $domain) {
        $ui = self::ui($text);
        return $ui !== $text ? $ui : $translation;
    }

    public static function render_canonical() {
        echo '<link rel="canonical" href="' . esc_url(add_query_arg(self::PARAM, self::$lang, get_permalink(self::$post_id))) . '">' . "\n";
    }

    public static function noindex() {
        echo '<meta name="robots" content="noindex">' . "\n";
    }

    /**
     * hreflang alternates for the original page and each current
     * translation. Untranslated languages are not advertised, so crawlers
     * never trigger new translations.
     */
    public static function render_hreflang() {
        if (!is_singular('post') || !self::enabled_languages() || !Azure_AI_Store::is_installed()) {
            return;
        }
        $post = get_queried_object();
        $url = get_permalink($post);
        $source = strtolower(substr((string) get_locale(), 0, 2));
        echo '<link rel="alternate" hreflang="' . esc_attr($source) . '" href="' . esc_url($url) . '">' . "\n";
        echo '<link rel="alternate" hreflang="x-default" href="' . esc_url($url) . '">' . "\n";
        foreach (Azure_AI_Store::translated_langs($post->ID, self::source_hash($post)) as $lang) {
            if (isset(self::enabled_languages()[$lang])) {
                echo '<link rel="alternate" hreflang="' . esc_attr($lang) . '" href="' . esc_url(add_query_arg(self::PARAM, $lang, $url)) . '">' . "\n";
            }
        }
    }

    public static function render_notice() {
        wp_enqueue_style('pta-ai');
        $original = esc_url(get_permalink(self::$post_id));
        if (self::$pending) {
            echo '<div class="pta-ai-mt pta-ai-mt--pending" role="status"><div class="pta-ai-mt__inner"><b>'
                . esc_html(self::ui('Translation in progress.')) . '</b> <span>'
                . esc_html(self::ui('This story is being translated. The original is shown for now; reload in a minute.'))
                . '</span></div></div>';
            return;
        }
        echo '<div class="pta-ai-mt" role="note" lang="' . esc_attr(self::$lang) . '"><div class="pta-ai-mt__inner"><b>'
            . esc_html(self::ui('Machine translation.')) . '</b> <span>'
            . esc_html(self::ui('This story was translated by AI from the original and may contain errors.'))
            . '</span> <a href="' . $original . '">' . esc_html(self::ui('Read the original')) . ' &rarr;</a></div></div>';
    }

    public static function render_switcher($atts = array()) {
        $languages = self::enabled_languages();
        if (!$languages || !is_singular('post')) {
            return '';
        }
        wp_enqueue_style('pta-ai');
        $post_id = (int) get_queried_object_id();
        $url = get_permalink($post_id);
        $current = self::current_lang() ?: self::requested_lang();
        $label = $current ? $languages[$current][0] : self::source_name();

        $html = '<details class="pta-ai-lang"><summary aria-label="' . esc_attr(self::ui('Language')) . '">'
            . esc_html($label) . '</summary><ul class="pta-ai-lang__menu">';
        $html .= '<li><a href="' . esc_url($url) . '" rel="nofollow"' . ($current === '' ? ' aria-current="true"' : '') . '>'
            . esc_html(self::source_name()) . ' <small>' . esc_html__('original', 'azure-plugin') . '</small></a></li>';
        foreach ($languages as $code => $lang) {
            $html .= '<li><a href="' . esc_url(add_query_arg(self::PARAM, $code, $url)) . '" rel="nofollow" lang="' . esc_attr($code) . '" hreflang="' . esc_attr($code) . '"'
                . ($current === $code ? ' aria-current="true"' : '') . '>'
                . esc_html($lang[0]) . ' <small>' . esc_html(strtoupper(substr($code, 0, 2))) . '</small></a></li>';
        }
        return $html . '</ul></details>';
    }

    public static function source_name() {
        $code = strtolower(substr((string) get_locale(), 0, 2));
        $names = array('en' => 'English', 'es' => 'Español', 'fr' => 'Français');
        return $names[$code] ?? strtoupper($code);
    }

    // ── Background job ────────────────────────────────────────────────

    /**
     * Rendered post HTML in pieces of at most CHUNK_CHARS, split on the
     * blank lines WordPress leaves between blocks.
     */
    public static function split_html($html, $max = self::CHUNK_CHARS) {
        $chunks = array();
        $current = '';
        foreach (preg_split('/\n\s*\n/', (string) $html) as $piece) {
            if ($current !== '' && strlen($current) + strlen($piece) > $max) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= ($current === '' ? '' : "\n\n") . $piece;
        }
        if (trim($current) !== '') {
            $chunks[] = $current;
        }
        return $chunks;
    }

    /**
     * A translated chunk must keep the markup: same number of tags and
     * the same link and image targets.
     */
    public static function markup_preserved($original, $translated) {
        $tags = function ($html) {
            return preg_match_all('/<\/?[a-z][^>]*>/i', $html);
        };
        $urls = function ($html) {
            preg_match_all('/\s(?:href|src)\s*=\s*["\']([^"\']*)["\']/i', $html, $m);
            $list = $m[1];
            sort($list);
            return $list;
        };
        return $tags($original) === $tags($translated) && $urls($original) === $urls($translated);
    }

    /**
     * Embeds, forms and scripts are served as rendered: model output is
     * filtered through wp_kses_post, which would strip them.
     */
    public static function is_interactive($html) {
        return (bool) preg_match('/<(script|style|form|iframe|input|select|textarea|object|embed|svg|video|audio)\b/i', $html);
    }

    public static function strip_fences($text) {
        $text = trim((string) $text);
        if (preg_match('/^```[a-z]*\s*\n(.*)\n```$/s', $text, $m)) {
            return trim($m[1]);
        }
        return $text;
    }

    public static function translate_post($post_id, $lang) {
        $post = get_post((int) $post_id);
        $languages = self::enabled_languages();
        if (!$post || $post->post_type !== 'post' || $post->post_status !== 'publish' || !isset($languages[$lang])) {
            return;
        }
        Azure_AI_Store::maybe_install();
        $hash = self::source_hash($post);
        $row = Azure_AI_Store::get_translation($post->ID, $lang);
        if ($row && $row->content_hash === $hash) {
            return;
        }
        $client = new Azure_AI_Client();
        if (!$client->is_configured()) {
            return;
        }
        self::count_today();
        $target = $languages[$lang][1];

        // Meta first: if this fails nothing is saved and the next visit retries.
        $summary = Azure_AI_Summary::visible($post->ID);
        $source = array(
            'title' => wp_strip_all_tags($post->post_title),
            'excerpt' => wp_strip_all_tags((string) $post->post_excerpt),
            'summary' => $summary['summary'] ?? '',
            'key_points' => $summary['points'] ?? array(),
        );
        $meta = $client->chat(array(
            array('role' => 'system', 'content' => 'Translate the string values of this JSON object into ' . $target
                . '. Keep the same keys and the same number of key_points. Leave empty strings empty. Keep names of people, schools and organisations as written. Return only the JSON object.'),
            array('role' => 'user', 'content' => wp_json_encode($source, JSON_UNESCAPED_UNICODE)),
        ), 'translation', array('json' => true, 'max_tokens' => 3000, 'post_id' => $post->ID));
        if (is_wp_error($meta)) {
            self::log_failure($post->ID, $lang, $meta);
            return;
        }
        $meta = json_decode($meta['content'], true);
        if (!is_array($meta)) {
            return;
        }

        $html = self::rendered_content($post);
        $out = array();
        foreach (self::split_html($html) as $chunk) {
            if (trim(wp_strip_all_tags($chunk)) === '' || self::is_interactive($chunk)) {
                $out[] = $chunk;
                continue;
            }
            $r = $client->chat(array(
                array('role' => 'system', 'content' => 'You translate web page HTML into ' . $target . ' for a school parent association website. '
                    . 'Translate only the human-readable text, including alt and title attributes. Keep every tag, attribute, URL, entity and line break exactly as they are; never add, drop or reorder tags. '
                    . 'Leave the contents of script and style elements untouched. Keep names of people, schools and organisations as written. Return only the translated HTML.'),
                array('role' => 'user', 'content' => $chunk),
            ), 'translation', array('max_tokens' => min(16000, 1500 + (int) (strlen($chunk) * 1.5)), 'post_id' => $post->ID, 'timeout' => 120));
            if (is_wp_error($r)) {
                self::log_failure($post->ID, $lang, $r);
                return;
            }
            $translated = self::strip_fences($r['content']);
            $out[] = self::markup_preserved($chunk, $translated) ? wp_kses_post($translated) : $chunk;
        }

        self::ensure_ui_strings($lang, $target, $client);

        $points = array_values(array_filter(array_map('strval', (array) ($meta['key_points'] ?? array()))));
        Azure_AI_Store::save_translation($post->ID, $lang, $hash, array(
            'title' => sanitize_text_field((string) ($meta['title'] ?? '')),
            'excerpt' => sanitize_text_field((string) ($meta['excerpt'] ?? '')),
            'content' => implode("\n\n", $out),
            'summary' => $source['summary'] !== '' ? wp_json_encode(array(
                'summary' => sanitize_text_field((string) ($meta['summary'] ?? '')),
                'points' => array_map('sanitize_text_field', $points),
            ), JSON_UNESCAPED_UNICODE) : '',
        ));
    }

    private static function rendered_content($post) {
        $previous = $GLOBALS['post'] ?? null;
        $GLOBALS['post'] = $post;
        setup_postdata($post);
        $html = apply_filters('the_content', $post->post_content);
        $GLOBALS['post'] = $previous;
        if ($previous) {
            setup_postdata($previous);
        }
        return str_replace(']]>', ']]&gt;', (string) $html);
    }

    private static function ensure_ui_strings($lang, $target, Azure_AI_Client $client) {
        $strings = self::ui_strings();
        $hash = md5(implode("\n", $strings));
        $cached = get_option(self::UI_OPTION . $lang);
        if (is_array($cached) && ($cached['hash'] ?? '') === $hash) {
            return;
        }
        $r = $client->chat(array(
            array('role' => 'system', 'content' => 'Translate each string in this JSON array into ' . $target
                . ' for a school parent association website. Return a JSON object {"t": [...]} with the translations in the same order.'),
            array('role' => 'user', 'content' => wp_json_encode($strings, JSON_UNESCAPED_UNICODE)),
        ), 'translation', array('json' => true, 'max_tokens' => 3000));
        if (is_wp_error($r)) {
            return;
        }
        $data = json_decode($r['content'], true);
        $list = is_array($data) ? array_values((array) ($data['t'] ?? array())) : array();
        if (count($list) !== count($strings)) {
            return;
        }
        update_option(self::UI_OPTION . $lang, array(
            'hash' => $hash,
            'strings' => array_combine($strings, array_map('sanitize_text_field', array_map('strval', $list))),
        ), false);
    }

    private static function log_failure($post_id, $lang, WP_Error $error) {
        if (class_exists('Azure_Logger')) {
            Azure_Logger::warning(sprintf('AI translation of post %d to %s failed: %s', $post_id, $lang, $error->get_error_message()));
        }
    }
}
