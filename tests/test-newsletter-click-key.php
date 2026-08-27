<?php
/**
 * Newsletter click HMAC survives container revisions; failed queue reclaim.
 *
 * Run: php tests/test-newsletter-click-key.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!function_exists('wp_salt')) {
    function wp_salt($scheme = 'auth') {
        return 'test-nonce-salt-' . $scheme;
    }
}

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-module.php';
require_once dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-queue.php';

$t = new TestRunner('Newsletter click key + queue reclaim');

WP_Shim::reset();
WP_Shim::$options['azure_newsletter_click_key'] = str_repeat('k', 64);

$url = 'https://example.com/fundraiser';
$sig = Azure_Newsletter_Module::click_signature($url);
$t->check(Azure_Newsletter_Module::click_signature_matches($url, $sig), 'current MySQL key accepts its own signature');
$t->check(!Azure_Newsletter_Module::click_signature_matches($url, ''), 'empty signature is refused');
$t->check(!Azure_Newsletter_Module::click_signature_matches($url, 'deadbeef'), 'wrong signature is refused');

$legacy = hash_hmac('sha256', $url, wp_salt('nonce'));
$t->check(Azure_Newsletter_Module::click_signature_matches($url, $legacy), 'pre-migration wp_salt signatures still match');

$t->check(Azure_Newsletter_Queue::should_reclaim_failed(0, 3), 'reclaim when only failed rows remain');
$t->check(!Azure_Newsletter_Queue::should_reclaim_failed(2, 3), 'do not reclaim while pending remains');
$t->check(!Azure_Newsletter_Queue::should_reclaim_failed(0, 0), 'nothing to reclaim when queue is empty');

exit($t->finish() === 0 ? 0 : 1);
