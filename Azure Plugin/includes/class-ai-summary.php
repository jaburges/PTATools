<?php
/**
 * AI summaries and key points for posts: generation, the editor panel
 * where a person can correct or hide them, and the Key points block.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AI_Summary {

    const META = '_pta_ai_summary';
    const MIN_WORDS = 60;

    public static function register_hooks() {
        add_action('add_meta_boxes_post', array(__CLASS__, 'add_meta_box'));
        add_action('save_post_post', array(__CLASS__, 'save_meta_box'), 10, 2);
        add_filter('get_the_excerpt', array(__CLASS__, 'excerpt_fallback'), 9, 2);
    }

    public static function get($post_id) {
        $meta = get_post_meta((int) $post_id, self::META, true);
        return is_array($meta) ? $meta : array();
    }

    /**
     * Summary text and points ready to show, or null when there is
     * nothing to show for this post.
     */
    public static function visible($post_id) {
        if (!Azure_AI_Module::feature_enabled('summary')) {
            return null;
        }
        $meta = self::get($post_id);
        if (!empty($meta['hidden']) || trim((string) ($meta['summary'] ?? '')) === '') {
            return null;
        }
        return array(
            'summary' => (string) $meta['summary'],
            'points' => array_values(array_filter(array_map('strval', (array) ($meta['points'] ?? array())))),
        );
    }

    public static function build_messages($title, $text) {
        return array(
            array('role' => 'system', 'content' =>
                'You summarise news posts for a PTA council website read by parents, families and school volunteers. '
                . 'Return a JSON object: {"summary": string, "key_points": [string]}. '
                . 'summary: one or two plain sentences, at most 45 words, saying what happened or what readers should know. '
                . 'key_points: 3 to 5 short points, each under 20 words, most useful first (dates, deadlines and actions readers can take). '
                . 'Write in the same language as the post. Use only facts stated in the post; never invent names, dates, numbers or links. '
                . 'Plain text only: no markdown, no emoji, no quotation marks around the whole text.'),
            array('role' => 'user', 'content' => "Title: " . $title . "\n\n" . $text),
        );
    }

    /**
     * @return array|WP_Error array('summary' => string, 'points' => string[])
     */
    public static function parse_response($content) {
        $data = json_decode(trim((string) $content), true);
        if (!is_array($data)) {
            return new WP_Error('pta_ai_bad_json', 'The summary response was not JSON.');
        }
        $summary = trim(wp_strip_all_tags((string) ($data['summary'] ?? '')));
        $points = array();
        foreach ((array) ($data['key_points'] ?? array()) as $point) {
            $point = trim(wp_strip_all_tags(is_scalar($point) ? (string) $point : ''));
            $point = ltrim($point, "-•* \t");
            if ($point !== '') {
                $points[] = $point;
            }
        }
        if ($summary === '') {
            return new WP_Error('pta_ai_no_summary', 'The summary response had no summary.');
        }
        return array('summary' => $summary, 'points' => array_slice($points, 0, 6));
    }

    /**
     * @return string|WP_Error 'saved', 'unchanged', 'edited' or 'short'.
     */
    public static function generate($post, Azure_AI_Client $client, $force = false) {
        $meta = self::get($post->ID);
        $hash = Azure_AI_Module::content_hash($post);

        if (!$force && ($meta['hash'] ?? '') === $hash) {
            return 'unchanged';
        }
        if (!$force && !empty($meta['edited'])) {
            return 'edited';
        }

        $text = Azure_AI_Module::post_text($post);
        if (str_word_count(preg_replace('/[^\p{L}\s]/u', '', $text)) < self::MIN_WORDS && mb_strlen($text) < 400) {
            update_post_meta($post->ID, self::META, array(
                'summary' => '',
                'points' => array(),
                'hash' => $hash,
                'short' => true,
                'hidden' => !empty($meta['hidden']),
                'generated_at' => gmdate('c'),
            ));
            return 'short';
        }

        $response = $client->chat(
            self::build_messages(wp_strip_all_tags($post->post_title), $text),
            'summary',
            array('json' => true, 'max_tokens' => 1500, 'post_id' => $post->ID)
        );
        if (is_wp_error($response)) {
            return $response;
        }
        $parsed = self::parse_response($response['content']);
        if (is_wp_error($parsed)) {
            return $parsed;
        }

        update_post_meta($post->ID, self::META, array(
            'summary' => $parsed['summary'],
            'points' => $parsed['points'],
            'hash' => $hash,
            'model' => (string) (Azure_AI_Module::settings()['ai_chat_deployment'] ?? ''),
            'generated_at' => gmdate('c'),
            'hidden' => !empty($meta['hidden']),
            'edited' => false,
        ));
        return 'saved';
    }

    // ── Front end ─────────────────────────────────────────────────────

    public static function render_block($attributes = array(), $content = '', $block = null) {
        $post_id = 0;
        if (is_object($block) && !empty($block->context['postId'])) {
            $post_id = (int) $block->context['postId'];
        }
        return self::render($post_id ?: (int) get_the_ID());
    }

    public static function render_shortcode($atts = array()) {
        $atts = shortcode_atts(array('post_id' => 0), $atts, 'pta_ai_summary');
        return self::render((int) $atts['post_id'] ?: (int) get_the_ID());
    }

    public static function render($post_id) {
        $data = self::visible($post_id);
        if (!$data) {
            return '';
        }
        $translated = Azure_AI_Translate::translated_summary($post_id);
        if ($translated) {
            $data = $translated;
        }
        wp_enqueue_style('pta-ai');

        $html = '<section class="pta-ai-keys" aria-label="' . esc_attr(Azure_AI_Translate::ui('Key points')) . '">';
        $html .= '<div class="pta-ai-keys__head"><h2 class="pta-ai-keys__title">' . esc_html(Azure_AI_Translate::ui('Key points')) . '</h2>';
        if (!empty(Azure_AI_Module::settings()['ai_show_label'])) {
            $html .= '<span class="pta-ai-label">' . esc_html(Azure_AI_Translate::ui('AI-generated summary')) . '</span>';
        }
        $html .= '</div><p class="pta-ai-keys__summary">' . esc_html($data['summary']) . '</p>';
        if (!empty($data['points'])) {
            $html .= '<ul class="pta-ai-keys__points">';
            foreach ($data['points'] as $point) {
                $html .= '<li>' . esc_html($point) . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</section>';
        return $html;
    }

    /**
     * Cards and Up Next use the excerpt; posts without a hand-written one
     * get the AI summary instead of the first words of the body.
     */
    public static function excerpt_fallback($excerpt, $post = null) {
        if ($excerpt !== '' || is_admin() || is_feed() || !$post || $post->post_type !== 'post') {
            return $excerpt;
        }
        $data = self::visible($post->ID);
        return $data ? $data['summary'] : $excerpt;
    }

    // ── Editor panel ──────────────────────────────────────────────────

    public static function add_meta_box() {
        if (!Azure_AI_Module::feature_enabled('summary')) {
            return;
        }
        add_meta_box('pta-ai-summary', __('Key points (AI)', 'azure-plugin'), array(__CLASS__, 'render_meta_box'), 'post', 'normal', 'default');
    }

    public static function render_meta_box($post) {
        $meta = self::get($post->ID);
        wp_nonce_field('pta_ai_summary_' . $post->ID, 'pta_ai_summary_nonce');
        $stale = !empty($meta['hash']) && $meta['hash'] !== Azure_AI_Module::content_hash($post);
        ?>
        <p class="description">
            <?php
            if (!empty($meta['short'])) {
                esc_html_e('This post is too short for a summary.', 'azure-plugin');
            } elseif (empty($meta['summary'])) {
                esc_html_e('No summary yet. One is generated within a minute or so of publishing.', 'azure-plugin');
            } elseif (!empty($meta['edited'])) {
                esc_html_e('Edited by hand; it will not be regenerated unless you tick the box below.', 'azure-plugin');
            } else {
                printf(
                    /* translators: %s: date the summary was generated */
                    esc_html__('Generated by AI on %s. Edit it if anything is wrong.', 'azure-plugin'),
                    esc_html(mysql2date(get_option('date_format'), $meta['generated_at'] ?? ''))
                );
            }
            if ($stale) {
                echo ' <strong>' . esc_html__('The post has changed since this summary was written.', 'azure-plugin') . '</strong>';
            }
            ?>
        </p>
        <p><label for="pta_ai_summary_text"><strong><?php esc_html_e('Summary', 'azure-plugin'); ?></strong></label>
            <textarea id="pta_ai_summary_text" name="pta_ai_summary_text" rows="3" class="widefat"><?php echo esc_textarea($meta['summary'] ?? ''); ?></textarea></p>
        <p><label for="pta_ai_points"><strong><?php esc_html_e('Key points (one per line)', 'azure-plugin'); ?></strong></label>
            <textarea id="pta_ai_points" name="pta_ai_points" rows="5" class="widefat"><?php echo esc_textarea(implode("\n", (array) ($meta['points'] ?? array()))); ?></textarea></p>
        <p>
            <label><input type="checkbox" name="pta_ai_hidden" value="1" <?php checked(!empty($meta['hidden'])); ?>> <?php esc_html_e('Hide key points on this post', 'azure-plugin'); ?></label><br>
            <label><input type="checkbox" name="pta_ai_regenerate" value="1"> <?php esc_html_e('Regenerate with AI when I save', 'azure-plugin'); ?></label>
        </p>
        <?php
    }

    public static function save_meta_box($post_id, $post) {
        if (!isset($_POST['pta_ai_summary_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pta_ai_summary_nonce'])), 'pta_ai_summary_' . $post_id)
            || wp_is_post_revision($post_id)
            || !current_user_can('edit_post', $post_id)) {
            return;
        }
        $meta = self::get($post_id);
        $summary = sanitize_textarea_field(wp_unslash($_POST['pta_ai_summary_text'] ?? ''));
        $points = array_values(array_filter(array_map('trim', explode("\n", sanitize_textarea_field(wp_unslash($_POST['pta_ai_points'] ?? '')))), 'strlen'));
        $meta['hidden'] = !empty($_POST['pta_ai_hidden']);

        if (!empty($_POST['pta_ai_regenerate'])) {
            $meta['hash'] = '';
            $meta['edited'] = false;
        } elseif ($summary !== (string) ($meta['summary'] ?? '') || $points !== array_values((array) ($meta['points'] ?? array()))) {
            $meta['summary'] = $summary;
            $meta['points'] = $points;
            $meta['edited'] = true;
            $meta['short'] = false;
            $meta['hash'] = Azure_AI_Module::content_hash($post);
        }
        update_post_meta($post_id, self::META, $meta);
    }
}
