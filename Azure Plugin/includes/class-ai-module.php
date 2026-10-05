<?php
/**
 * AI module: story summaries and key points, related stories / Up Next
 * from embeddings, and on-demand machine translation, all through an
 * Azure AI Foundry resource. Off unless enable_ai is set.
 *
 * Work happens in wp-cron (hourly backlog, plus a single event shortly
 * after a post is published or updated) and from the settings page's
 * "Process now" button, never during a visitor's page load.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-ai-vectors.php';
require_once __DIR__ . '/class-ai-store.php';
require_once __DIR__ . '/class-ai-client.php';
require_once __DIR__ . '/class-ai-summary.php';
require_once __DIR__ . '/class-ai-related.php';
require_once __DIR__ . '/class-ai-translate.php';

class Azure_AI_Module {

    const CRON_HOOK = 'pta_ai_process';
    const POST_HOOK = 'pta_ai_process_post';
    const DONE_META = '_pta_ai_done';
    const FAILS_META = '_pta_ai_fails';
    const MAX_FAILS = 3;

    // Plain text sent per post is capped; long posts are summarised from
    // their opening, which is where the news is.
    const MAX_INPUT_CHARS = 12000;

    private static $instance = null;
    private static $settings = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function defaults() {
        return array(
            'ai_endpoint' => '',
            'ai_api_key' => '',
            'ai_chat_deployment' => 'chat',
            'ai_embed_deployment' => 'embed',
            'ai_feature_summary' => true,
            'ai_feature_related' => true,
            'ai_feature_translate' => false,
            'ai_languages' => array('es', 'zh-Hans', 'ru', 'ko', 'vi', 'hi'),
            'ai_monthly_budget' => 10,
            'ai_price_input' => 0.825,
            'ai_price_output' => 4.95,
            'ai_price_embed' => 0.022,
            'ai_show_label' => true,
            'ai_batch_size' => 20,
            'ai_translate_daily_cap' => 40,
        );
    }

    public static function settings() {
        if (self::$settings === null) {
            $all = class_exists('Azure_Settings') ? (array) Azure_Settings::get_all_settings() : array();
            $s = self::defaults();
            foreach ($s as $key => $default) {
                if (array_key_exists($key, $all)) {
                    $s[$key] = $all[$key];
                }
            }
            self::$settings = $s;
        }
        return self::$settings;
    }

    public static function reset_settings_cache() {
        self::$settings = null;
    }

    public static function feature_enabled($feature) {
        return !empty(self::settings()['ai_feature_' . $feature]);
    }

    private function __construct() {
        // Built from the plugin's init callback; an init hook added now
        // would not fire on this request.
        if (did_action('init')) {
            $this->on_init();
        } else {
            add_action('init', array($this, 'on_init'));
        }

        add_action(self::CRON_HOOK, array($this, 'run_cron'));
        add_action(self::POST_HOOK, array($this, 'process_post'), 10, 2);
        add_action('save_post_post', array($this, 'on_save_post'), 20, 3);
        add_action('before_delete_post', array($this, 'on_delete_post'));
        add_action('wp_ajax_pta_ai_run_batch', array($this, 'ajax_run_batch'));
        add_action('wp_ajax_pta_ai_test', array($this, 'ajax_test'));

        Azure_AI_Summary::register_hooks();
        Azure_AI_Related::register_hooks();
        Azure_AI_Translate::register_hooks();
    }

    public function on_init() {
        if (is_admin() || (function_exists('wp_doing_cron') && wp_doing_cron())) {
            Azure_AI_Store::maybe_install();
        }
        $this->register_blocks();
    }

    private function register_blocks() {
        if (!function_exists('register_block_type')) {
            return;
        }
        wp_register_script(
            'pta-ai-blocks-editor',
            AZURE_PLUGIN_URL . 'js/ai-blocks-editor.js',
            array('wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-i18n'),
            AZURE_PLUGIN_VERSION,
            true
        );
        wp_register_style('pta-ai', AZURE_PLUGIN_URL . 'css/ai.css', array(), AZURE_PLUGIN_VERSION);

        register_block_type('pta-tools/ai-summary', array(
            'api_version' => 2,
            'title' => __('Key points (AI)', 'azure-plugin'),
            'category' => 'widgets',
            'editor_script' => 'pta-ai-blocks-editor',
            'style' => 'pta-ai',
            'uses_context' => array('postId'),
            'render_callback' => array('Azure_AI_Summary', 'render_block'),
        ));
        register_block_type('pta-tools/ai-language-switcher', array(
            'api_version' => 2,
            'title' => __('Language switcher (AI)', 'azure-plugin'),
            'category' => 'widgets',
            'editor_script' => 'pta-ai-blocks-editor',
            'style' => 'pta-ai',
            'render_callback' => array('Azure_AI_Translate', 'render_switcher'),
        ));
        add_shortcode('pta_ai_summary', array('Azure_AI_Summary', 'render_shortcode'));
        add_shortcode('pta_ai_language_switcher', array('Azure_AI_Translate', 'render_switcher'));
    }

    // ── Content helpers ───────────────────────────────────────────────

    public static function content_hash($post) {
        return md5((string) $post->post_title . "\n" . (string) $post->post_content);
    }

    /**
     * Plain text of a post for the model: no markup, shortcodes or block
     * comments, whitespace collapsed, capped at MAX_INPUT_CHARS.
     */
    public static function post_text($post) {
        $text = (string) $post->post_content;
        $text = preg_replace('/<!--.*?-->/s', ' ', $text);
        $text = function_exists('strip_shortcodes') ? strip_shortcodes($text) : $text;
        $text = preg_replace('/\[[^\]]{1,200}\]/', ' ', $text);
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if (function_exists('mb_substr') && mb_strlen($text) > self::MAX_INPUT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_INPUT_CHARS);
        }
        return $text;
    }

    private static function done_marker($post) {
        return (string) $post->post_modified_gmt . '|'
            . (self::feature_enabled('summary') ? 's' : '')
            . (self::feature_enabled('related') ? 'r' : '');
    }

    // ── Processing ────────────────────────────────────────────────────

    public function on_save_post($post_id, $post, $update) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || $post->post_status !== 'publish') {
            return;
        }
        if (!wp_next_scheduled(self::POST_HOOK, array((int) $post_id, false))) {
            wp_schedule_single_event(time() + 30, self::POST_HOOK, array((int) $post_id, false));
        }
    }

    public function on_delete_post($post_id) {
        if (Azure_AI_Store::is_installed()) {
            Azure_AI_Store::delete_post($post_id);
        }
    }

    /**
     * Summary and embedding for one post. Each step skips itself when the
     * stored result already matches the post's content hash.
     *
     * @return array Step results, for the settings page.
     */
    public function process_post($post_id, $force = false) {
        $post = get_post((int) $post_id);
        if (!$post || $post->post_type !== 'post' || $post->post_status !== 'publish') {
            return array();
        }
        $client = new Azure_AI_Client();
        if (!$client->is_configured()) {
            return array('error' => 'not configured');
        }

        $out = array();
        $failed = false;
        if (self::feature_enabled('summary')) {
            $r = Azure_AI_Summary::generate($post, $client, (bool) $force);
            $out['summary'] = is_wp_error($r) ? $r->get_error_message() : $r;
            $failed = $failed || is_wp_error($r);
        }
        if (self::feature_enabled('related')) {
            $r = Azure_AI_Related::embed_post($post, $client, (bool) $force);
            $out['embedding'] = is_wp_error($r) ? $r->get_error_message() : $r;
            $failed = $failed || is_wp_error($r);
        }
        if (!$failed) {
            update_post_meta($post->ID, self::DONE_META, self::done_marker($post));
            delete_post_meta($post->ID, self::FAILS_META);
        } elseif (!Azure_AI_Store::over_budget()) {
            // Give up on a post that keeps failing so it does not hold the
            // backlog; editing the post queues it again.
            $fails = (int) get_post_meta($post->ID, self::FAILS_META, true) + 1;
            update_post_meta($post->ID, self::FAILS_META, $fails);
            if ($fails >= self::MAX_FAILS) {
                update_post_meta($post->ID, self::DONE_META, self::done_marker($post));
                delete_post_meta($post->ID, self::FAILS_META);
            }
        }
        return $out;
    }

    /**
     * Published posts whose AI work is missing or older than their last edit.
     */
    public static function backlog_ids($limit) {
        global $wpdb;
        $sig = (self::feature_enabled('summary') ? 's' : '') . (self::feature_enabled('related') ? 'r' : '');
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_type = 'post' AND p.post_status = 'publish'
               AND (m.meta_value IS NULL OR m.meta_value <> CONCAT(p.post_modified_gmt, '|', %s))
             ORDER BY p.post_date DESC
             LIMIT %d",
            self::DONE_META,
            $sig,
            (int) $limit
        )));
    }

    public static function backlog_count() {
        global $wpdb;
        $sig = (self::feature_enabled('summary') ? 's' : '') . (self::feature_enabled('related') ? 'r' : '');
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_type = 'post' AND p.post_status = 'publish'
               AND (m.meta_value IS NULL OR m.meta_value <> CONCAT(p.post_modified_gmt, '|', %s))",
            self::DONE_META,
            $sig
        ));
    }

    /**
     * Work through the backlog until $seconds have passed or the budget
     * stops the client.
     */
    public function run_batch($seconds, $limit) {
        global $wpdb;
        $lock = $wpdb->prefix . 'pta_ai_batch';
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
            return array(
                'processed' => 0,
                'related_refreshed' => 0,
                'remaining' => self::backlog_count(),
                'errors' => array(),
                'over_budget' => Azure_AI_Store::over_budget(),
                'locked' => true,
            );
        }
        try {
            return $this->run_batch_locked($seconds, $limit);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private function run_batch_locked($seconds, $limit) {
        $started = time();
        $done = 0;
        $errors = array();
        if (self::feature_enabled('summary') || self::feature_enabled('related')) {
            foreach (self::backlog_ids($limit) as $id) {
                $r = $this->process_post($id);
                $done++;
                foreach ($r as $step => $result) {
                    if (is_string($result) && !in_array($result, array('skipped', 'unchanged', 'saved', 'short', 'edited'), true)) {
                        $errors[] = '#' . $id . ' ' . $step . ': ' . $result;
                    }
                }
                if (Azure_AI_Store::over_budget() || time() - $started >= $seconds) {
                    break;
                }
            }
        }
        $related = 0;
        if (self::feature_enabled('related') && time() - $started < $seconds) {
            $related = Azure_AI_Related::refresh_stale(200);
        }
        return array(
            'processed' => $done,
            'related_refreshed' => $related,
            'remaining' => self::backlog_count(),
            'errors' => array_slice($errors, 0, 5),
            'over_budget' => Azure_AI_Store::over_budget(),
        );
    }

    public function run_cron() {
        Azure_AI_Store::maybe_install();
        $result = $this->run_batch(240, max(1, (int) self::settings()['ai_batch_size']));
        if (!empty($result['errors']) && class_exists('Azure_Logger')) {
            Azure_Logger::warning('AI: ' . implode(' | ', $result['errors']));
        }
    }

    // ── Admin ─────────────────────────────────────────────────────────

    public function ajax_run_batch() {
        check_ajax_referer('pta_ai_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        Azure_AI_Store::maybe_install();
        wp_send_json_success($this->run_batch(40, 10));
    }

    public function ajax_test() {
        check_ajax_referer('pta_ai_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        Azure_AI_Store::maybe_install();
        $client = new Azure_AI_Client();
        $chat = $client->chat(array(
            array('role' => 'user', 'content' => 'Reply with the single word: ready'),
        ), 'test', array('max_tokens' => 1000));
        if (is_wp_error($chat)) {
            wp_send_json_error('Chat: ' . $chat->get_error_message());
        }
        $embed = $client->embed(array('connection test'), 'test');
        if (is_wp_error($embed)) {
            wp_send_json_error('Embeddings: ' . $embed->get_error_message());
        }
        wp_send_json_success(sprintf(
            'Chat replied "%s"; embeddings returned %d dimensions.',
            trim(wp_strip_all_tags($chat['content'])),
            count($embed[0])
        ));
    }

    /**
     * Settings form handler, called by the settings page itself so it
     * works while the module is still switched off.
     */
    public static function save_settings(array $in) {
        $new = array(
            'ai_endpoint' => esc_url_raw(trim((string) ($in['ai_endpoint'] ?? ''))),
            'ai_chat_deployment' => sanitize_text_field($in['ai_chat_deployment'] ?? 'chat'),
            'ai_embed_deployment' => sanitize_text_field($in['ai_embed_deployment'] ?? 'embed'),
            'ai_feature_summary' => !empty($in['ai_feature_summary']),
            'ai_feature_related' => !empty($in['ai_feature_related']),
            'ai_feature_translate' => !empty($in['ai_feature_translate']),
            'ai_show_label' => !empty($in['ai_show_label']),
            'ai_monthly_budget' => max(0, (float) ($in['ai_monthly_budget'] ?? 0)),
            'ai_price_input' => max(0, (float) ($in['ai_price_input'] ?? 0)),
            'ai_price_output' => max(0, (float) ($in['ai_price_output'] ?? 0)),
            'ai_price_embed' => max(0, (float) ($in['ai_price_embed'] ?? 0)),
            'ai_batch_size' => max(1, min(100, (int) ($in['ai_batch_size'] ?? 20))),
            'ai_translate_daily_cap' => max(0, min(1000, (int) ($in['ai_translate_daily_cap'] ?? 40))),
            'ai_languages' => array_values(array_intersect(
                array_keys(Azure_AI_Translate::supported_languages()),
                array_map('strval', (array) ($in['ai_languages'] ?? array()))
            )),
        );
        $key = trim((string) ($in['ai_api_key'] ?? ''));
        if ($key !== '') {
            $new['ai_api_key'] = $key;
        }
        Azure_Settings::update_settings($new);
        self::reset_settings_cache();

        wp_safe_redirect(add_query_arg('updated', '1', admin_url('admin.php?page=azure-plugin-ai')));
        exit;
    }
}
