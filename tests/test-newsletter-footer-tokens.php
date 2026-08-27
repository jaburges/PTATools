<?php
/**
 * Test sends must replace View in browser / Unsubscribe tokens.
 *
 * Those URLs are filled in add_tracking(), which only ran when a
 * newsletter_id was present. Review & Test emails skip that path, so
 * clients render the raw href as [{{view_in_browser_url}}].
 *
 * Run: php tests/test-newsletter-footer-tokens.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-sender.php';

$t = new TestRunner('Newsletter footer tokens');

$html = '<p><a href="{{view_in_browser_url}}">View in browser</a> • '
    . '<a href="{{unsubscribe_url}}">Unsubscribe</a></p>';

$encoded = '<a href="%7B%7Bview_in_browser_url%7D%7D">View</a>'
    . '<a href="%7B%7Bunsubscribe_url%7D%7D">Unsub</a>';

$view = 'https://example.test/wp-json/azure-plugin/v1/newsletter/view/abc';
$unsub = 'https://example.test/wp-json/azure-plugin/v1/newsletter/unsubscribe/xyz';

$has_helper = method_exists('Azure_Newsletter_Sender', 'replace_footer_tokens');
$t->check($has_helper, 'replace_footer_tokens is callable without a live send');

$out = $has_helper ? Azure_Newsletter_Sender::replace_footer_tokens($html, $view, $unsub) : $html;
$t->check(strpos($out, '{{view_in_browser_url}}') === false, 'plain view token is replaced');
$t->check(strpos($out, '{{unsubscribe_url}}') === false, 'plain unsubscribe token is replaced');
$t->check(strpos($out, $view) !== false, 'view URL is in the output');
$t->check(strpos($out, $unsub) !== false, 'unsubscribe URL is in the output');

$enc_out = $has_helper ? Azure_Newsletter_Sender::replace_footer_tokens($encoded, $view, $unsub) : $encoded;
$t->check(strpos($enc_out, '%7B%7Bview_in_browser_url%7D%7D') === false, 'URL-encoded view token is replaced');
$t->check(strpos($enc_out, '%7B%7Bunsubscribe_url%7D%7D') === false, 'URL-encoded unsubscribe token is replaced');

$ajax = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-ajax.php');
$send_test = '';
if (preg_match('/function send_test_email\(\)\s*\{(.*)\n    public function /s', $ajax, $m)) {
    $send_test = $m[1];
}
$t->check(
    strpos($send_test, "'newsletter_id'") !== false,
    'editor test send passes newsletter_id so tracking/token replacement can run'
);

$editor = file_get_contents(dirname(__DIR__) . '/Azure Plugin/js/newsletter-editor.js');
$t->check(
    preg_match("/action:\\s*'azure_newsletter_send_test'[\\s\\S]{0,500}newsletter_id:/", $editor) === 1,
    'editor test AJAX includes newsletter_id'
);

$sender_src = file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-sender.php');
$send_fn = '';
if (preg_match('/public function send\(\$args\)\s*\{(.*)\n    private function /s', $sender_src, $m)) {
    $send_fn = $m[1];
}
$t->check(
    strpos($send_fn, 'replace_footer_tokens') !== false,
    'send() replaces footer tokens even when newsletter_id is missing'
);

exit($t->finish() === 0 ? 0 : 1);
