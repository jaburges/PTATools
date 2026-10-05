<?php
/**
 * AI module: Foundry client requests and usage logging, the spending cap,
 * summary parsing, vector maths, and the translation helpers that keep
 * markup intact.
 *
 * Run: php tests/test-ai-module.php
 */

require_once __DIR__ . '/wp-shim.php';

function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function strip_shortcodes($s) { return preg_replace('/\[[^\]]*\]/', '', (string) $s); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_kses_post($s) { return preg_replace('/\son[a-z]+="[^"]*"/i', '', (string) $s); }

$wpdb = new Fake_WPDB();

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-ai-module.php';

function set_static($class, $property, $value) {
    $ref = new ReflectionProperty($class, $property);
    if (PHP_VERSION_ID < 80100) {
        $ref->setAccessible(true);
    }
    $ref->setValue(null, $value);
}

function configure(array $overrides = array()) {
    WP_Shim::$settings = array_merge(array(
        'ai_endpoint' => 'https://example-ai.cognitiveservices.azure.com/',
        'ai_api_key' => 'test-key',
        'ai_monthly_budget' => 10,
    ), $overrides);
    Azure_AI_Module::reset_settings_cache();
    set_static('Azure_AI_Store', 'month_cost', 0.0);
}

$t = new TestRunner('AI module');
WP_Shim::reset();

// --- settings ----------------------------------------------------------------
configure(array('ai_chat_deployment' => 'gpt'));
$s = Azure_AI_Module::settings();
$t->equals('gpt', $s['ai_chat_deployment'], 'saved settings override defaults');
$t->equals('embed', $s['ai_embed_deployment'], 'unset settings fall back to defaults');
$t->equals(false, Azure_AI_Module::feature_enabled('translate'), 'translation is off by default');
$t->equals(true, Azure_AI_Module::feature_enabled('summary'), 'summaries are on by default');
foreach (array('summary', 'related', 'translate') as $f) {
    $t->check(array_key_exists('ai_feature_' . $f, Azure_AI_Module::defaults()), "feature toggle ai_feature_{$f} has a default");
}

// --- vectors -----------------------------------------------------------------
$v = Azure_AI_Vectors::normalize(array(3.0, 4.0));
$t->check(abs($v[0] - 0.6) < 1e-9 && abs($v[1] - 0.8) < 1e-9, 'normalize gives a unit vector');
$round = Azure_AI_Vectors::unpack(Azure_AI_Vectors::pack(array(0.1, -0.25, 0.5)));
$t->check(count($round) === 3 && abs($round[1] + 0.25) < 1e-6 && abs($round[0] - 0.1) < 1e-6, 'pack/unpack round-trips within float32 precision');
$t->equals(array(), Azure_AI_Vectors::unpack('not base64!'), 'a corrupt vector unpacks to nothing');
$near = Azure_AI_Vectors::nearest(array(1.0, 0.0), array(
    1 => array(1.0, 0.0),
    2 => array(0.0, 1.0),
    3 => array(0.8, 0.6),
    4 => array(0.6, 0.8),
), 2, array(1));
$t->equals(array(3, 4), array_keys($near), 'nearest ranks by similarity and honours the exclude list');

// --- related: reposted stories ---------------------------------------------------
$GLOBALS['pta_test_titles'] = array(
    1 => 'Financial Sustainability of the Council',
    2 => 'Financial Sustainability of the Council',
    3 => 'Lunch &amp; Learn: Enrichment 101',
    4 => 'Lunch & Learn — Enrichment 101 ',
    5 => 'Levy results',
);
function get_the_title($id = 0) { return $GLOBALS['pta_test_titles'][$id] ?? ''; }
$t->equals(array(1, 3, 5), Azure_AI_Related::distinct_titles(array(1, 2, 3, 4, 5)), 'stories reposted under the same title are listed once');
$t->equals(array(3, 5), Azure_AI_Related::distinct_titles(array(1, 3, 5), array('Financial Sustainability of the Council')), "a story with the current post's title is skipped");

// --- cost ----------------------------------------------------------------------
$prices = array('ai_embed_deployment' => 'embed', 'ai_price_input' => 1.0, 'ai_price_output' => 4.0, 'ai_price_embed' => 0.02);
$t->check(abs(Azure_AI_Store::cost_of('chat', 1000000, 500000, $prices) - 3.0) < 1e-9, 'chat cost = input + output at per-million prices');
$t->check(abs(Azure_AI_Store::cost_of('embed', 1000000, 0, $prices) - 0.02) < 1e-9, 'embedding cost uses the embedding price');

// --- client: chat ----------------------------------------------------------------
configure();
WP_Shim::on_request('/openai/v1/chat/completions', 200, array(
    'choices' => array(array('message' => array('content' => '{"ok":true}'), 'finish_reason' => 'stop')),
    'usage' => array('prompt_tokens' => 1000, 'completion_tokens' => 200),
));
$client = new Azure_AI_Client();
$r = $client->chat(array(array('role' => 'user', 'content' => 'hi')), 'summary', array('json' => true, 'post_id' => 7));
$t->equals('{"ok":true}', is_array($r) ? $r['content'] : $r, 'chat returns the message content');
$call = WP_Shim::$http_calls[0];
$body = json_decode($call['args']['body'], true);
$t->equals('https://example-ai.cognitiveservices.azure.com/openai/v1/chat/completions', $call['url'], 'chat goes to the v1 endpoint without a doubled slash');
$t->equals('test-key', $call['args']['headers']['api-key'], 'key sent in the api-key header');
$t->equals('chat', $body['model'], 'deployment name sent as the model');
$t->check(isset($body['max_completion_tokens']) && !isset($body['max_tokens']), 'uses max_completion_tokens (gpt-5 family)');
$t->equals(array('type' => 'json_object'), $body['response_format'] ?? null, 'json mode requested');
$t->equals(60, $call['args']['timeout'], 'chat calls give up after 60 s by default');
$usage = $wpdb->tables['wp_pta_ai_usage'][0] ?? array();
$t->equals(7, $usage['post_id'] ?? null, 'usage row records the post');
$t->check(abs(($usage['cost'] ?? 0) - (1000 * 0.825 + 200 * 4.95) / 1000000) < 1e-12, 'usage row records the estimated cost');
$t->check(Azure_AI_Store::month_cost() > 0, 'month-to-date cost includes the call');

WP_Shim::reset();
configure();
WP_Shim::on_request('/chat/completions', 200, array('choices' => array(array('message' => array('content' => 'partial'), 'finish_reason' => 'length'))));
$r = (new Azure_AI_Client())->chat(array(), 'summary');
$t->check(is_wp_error($r), 'a truncated answer is an error, not saved as a summary');

WP_Shim::reset();
configure();
WP_Shim::$http_responses['/chat/completions'] = array(
    array('code' => 429, 'body' => '{"error":{"message":"slow down"}}', 'headers' => array('retry-after' => '1')),
    array('code' => 200, 'body' => json_encode(array('choices' => array(array('message' => array('content' => 'ok'), 'finish_reason' => 'stop')))), 'headers' => array()),
);
$r = (new Azure_AI_Client())->chat(array(), 'summary');
$t->check(is_array($r) && $r['content'] === 'ok' && count(WP_Shim::$http_calls) === 2, 'a 429 is retried after Retry-After');

WP_Shim::reset();
configure();
WP_Shim::on_request('/chat/completions', 400, array('error' => array('message' => 'bad request')));
$r = (new Azure_AI_Client())->chat(array(), 'summary');
$t->check(is_wp_error($r) && count(WP_Shim::$http_calls) === 1 && strpos($r->get_error_message(), 'bad request') !== false, 'a 400 is not retried and its message is kept');

// --- client: budget and configuration ---------------------------------------------
WP_Shim::reset();
configure();
set_static('Azure_AI_Store', 'month_cost', 10.0);
$r = (new Azure_AI_Client())->chat(array(), 'summary');
$t->check(is_wp_error($r) && empty(WP_Shim::$http_calls), 'no calls once the monthly budget is spent');

configure(array('ai_monthly_budget' => 0));
$t->equals(true, Azure_AI_Store::over_budget(), 'a zero budget means off');

configure(array('ai_api_key' => ''));
$t->equals(false, (new Azure_AI_Client())->is_configured(), 'not configured without a key');

// --- client: embeddings -----------------------------------------------------------
WP_Shim::reset();
configure();
WP_Shim::on_request('/openai/v1/embeddings', 200, array(
    'data' => array(
        array('index' => 1, 'embedding' => array(0.0, 1.0)),
        array('index' => 0, 'embedding' => array(1.0, 0.0)),
    ),
    'usage' => array('prompt_tokens' => 20),
));
$r = (new Azure_AI_Client())->embed(array('a', 'b'));
$body = json_decode(WP_Shim::$http_calls[0]['args']['body'], true);
$t->equals(Azure_AI_Vectors::DIMENSIONS, $body['dimensions'] ?? null, 'embeddings request the stored dimension count');
$t->equals(30, WP_Shim::$http_calls[0]['args']['timeout'], 'embedding calls give up after 30 s');
$t->equals(array(array(1.0, 0.0), array(0.0, 1.0)), $r, 'vectors come back in input order');

// --- summaries ---------------------------------------------------------------------
$p = Azure_AI_Summary::parse_response('{"summary":"Board retreat tips.","key_points":["- Survey early","• Ask the principal","",3]}');
$t->equals('Board retreat tips.', $p['summary'], 'summary parsed');
$t->equals(array('Survey early', 'Ask the principal', '3'), $p['points'], 'bullets stripped, empty points dropped');
$t->check(is_wp_error(Azure_AI_Summary::parse_response('Sure! Here is a summary')), 'non-JSON reply is an error');
$t->check(is_wp_error(Azure_AI_Summary::parse_response('{"key_points":["x"]}')), 'missing summary is an error');
$m = Azure_AI_Summary::build_messages('Levy results', 'Text');
$t->check(strpos($m[0]['content'], 'same language') !== false && strpos($m[0]['content'], 'never invent') !== false, 'prompt keeps the post language and forbids invented facts');
$t->check(strpos($m[1]['content'], 'Levy results') !== false, 'title is sent with the text');

$post = (object) array('ID' => 5, 'post_title' => 'T', 'post_content' => "<!-- wp:paragraph --><p>Hello&nbsp;<b>world</b></p><!-- /wp:paragraph -->\n\n[gallery ids=\"1\"]", 'post_excerpt' => '');
$t->equals('Hello world', str_replace("\xC2\xA0", ' ', Azure_AI_Module::post_text($post)), 'post text drops block comments, shortcodes and tags');

// --- translation helpers -------------------------------------------------------------
$html = "<p>One</p>\n\n<p>Two</p>\n\n<p>" . str_repeat('x', 50) . "</p>";
$chunks = Azure_AI_Translate::split_html($html, 30);
$t->equals(2, count($chunks), 'blocks are grouped into chunks under the size limit');
$t->equals($html, implode("\n\n", $chunks), 'chunks rejoin to the original');

$orig = '<p>Read <a href="https://example.test/a">this</a> <img src="/x.jpg" alt="Cat"></p>';
$t->equals(true, Azure_AI_Translate::markup_preserved($orig, '<p>Lea <a href="https://example.test/a">esto</a> <img src="/x.jpg" alt="Gato"></p>'), 'a faithful translation keeps its markup');
$t->equals(false, Azure_AI_Translate::markup_preserved($orig, '<p>Lea <a href="https://evil.test/">esto</a> <img src="/x.jpg" alt="Gato"></p>'), 'a changed link is rejected');
$t->equals(false, Azure_AI_Translate::markup_preserved($orig, '<p>Lea esto <img src="/x.jpg" alt="Gato"></p>'), 'a dropped tag is rejected');
$t->equals('<p>Hola</p>', Azure_AI_Translate::strip_fences("```html\n<p>Hola</p>\n```"), 'code fences around the reply are removed');
$t->equals(true, Azure_AI_Translate::is_interactive('<figure><iframe src="https://www.youtube.com/embed/x"></iframe></figure>'), 'embeds are served untranslated');
$t->equals(false, Azure_AI_Translate::is_interactive('<p>Plain</p>'), 'plain paragraphs are translated');
$t->equals(true, Azure_AI_Translate::is_bot('Mozilla/5.0 (compatible; Googlebot/2.1)'), 'crawlers do not trigger translations');
$t->equals(true, Azure_AI_Translate::is_bot(''), 'an empty user agent counts as a bot');
$t->equals(false, Azure_AI_Translate::is_bot('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Safari/604.1'), 'a phone browser is a reader');
$t->equals('rtl', Azure_AI_Translate::supported_languages()['ar'][2], 'Arabic is right-to-left');

configure(array('ai_feature_translate' => true, 'ai_languages' => array('es', 'xx', 'ko')));
$t->equals(array('es', 'ko'), array_keys(Azure_AI_Translate::enabled_languages()), 'only supported, selected languages are offered');

configure(array('ai_translate_daily_cap' => 2));
WP_Shim::$options['pta_ai_translate_day'] = array('date' => gmdate('Y-m-d'), 'count' => 2);
$t->equals(false, Azure_AI_Translate::under_daily_cap(), 'the daily cap stops new translations');
WP_Shim::$options['pta_ai_translate_day'] = array('date' => '2000-01-01', 'count' => 99);
$t->equals(true, Azure_AI_Translate::under_daily_cap(), 'the cap resets each day');

$a = (object) array('ID' => 9, 'post_title' => 'T', 'post_content' => 'C', 'post_excerpt' => '');
configure();
$h1 = Azure_AI_Translate::source_hash($a);
WP_Shim::$post_meta[9][Azure_AI_Summary::META] = array('summary' => 'Edited by hand', 'points' => array('One'));
$t->check($h1 !== Azure_AI_Translate::source_hash($a), 'editing the summary invalidates cached translations');

// --- batch lock ----------------------------------------------------------------------
// The fake database never grants GET_LOCK, which is what a second, overlapping
// run sees while the hourly job holds the lock.
WP_Shim::reset();
configure();
$module = (new ReflectionClass('Azure_AI_Module'))->newInstanceWithoutConstructor();
$r = $module->run_batch(40, 10);
$t->check(!empty($r['locked']) && $r['processed'] === 0 && empty(WP_Shim::$http_calls), 'an overlapping batch does nothing and says so');

// --- wiring ------------------------------------------------------------------------
$root = dirname(__DIR__) . '/Azure Plugin/';
$main = file_get_contents($root . 'azure-plugin.php');
$t->check(strpos($main, "enable_ai") !== false && strpos($main, 'class-ai-module.php') !== false, 'module loads from the main plugin file behind enable_ai');
$admin = file_get_contents($root . 'includes/class-admin.php');
$t->check(strpos($admin, "'anti_spam_filter', 'ai')") !== false, 'module can be toggled from the main page');
$t->check(strpos($admin, "'azure-plugin-ai'") !== false, 'settings page is in the menu');
$cron = file_get_contents($root . 'includes/class-pta-cron.php');
$t->check(strpos($cron, "'pta_ai_process', 'hourly'") !== false, 'hourly backlog is scheduled centrally');
$t->check(preg_match("/'enable_ai' => array\(\s*'pta_ai_process'/", $cron) === 1, 'AI cron rows are cleared when the module is off');
$edge = file_get_contents($root . 'includes/class-edge-cache.php');
$translate = file_get_contents($root . 'includes/class-ai-translate.php');
$t->check(strpos($edge, "apply_filters('pta_edge_cache_shareable', true)") !== false
    && strpos($translate, "add_filter('pta_edge_cache_shareable', '__return_false')") !== false,
    'a "translation in progress" page is kept out of the edge cache');
$module = file_get_contents($root . 'includes/class-ai-module.php');
$t->check(strpos($module, "did_action('init')") !== false, 'block registration does not wait for an init that already ran');

exit($t->finish() > 0 ? 1 : 0);
