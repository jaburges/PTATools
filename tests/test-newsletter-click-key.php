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

$spa = 'https://www.signupgenius.com/go/5080D4FAAA622A2F85-65619462-back#/';
$spa_sig = Azure_Newsletter_Module::click_signature($spa);
$t->check(
    Azure_Newsletter_Module::click_query_destination($spa) === str_replace('#', '%23', $spa),
    'hash fragments are encoded before they go in the tracking query'
);

$built = 'https://wilderptsa.net/click?' . http_build_query(array(
    'sig' => $spa_sig,
    'url' => Azure_Newsletter_Module::click_query_destination($spa),
));
$after_mailgun = rawurldecode($built);
$parts = parse_url($after_mailgun);
$t->check(empty($parts['fragment']), 'Mailgun/SafeLinks decode does not turn the destination # into the tracking fragment');
parse_str($parts['query'] ?? '', $got);
$incoming = Azure_Newsletter_Module::click_incoming_destination($got['url'] ?? '');
$t->equals($spa, $incoming, 'SignUpGenius #/ survives one Location decode');
$t->check(!empty($got['sig']), 'signature is still a query param after one Location decode');
$t->check(
    Azure_Newsletter_Module::click_signature_matches($incoming, $got['sig'] ?? ''),
    'decoded SignUpGenius destination still matches its HMAC'
);

$old = 'https://wilderptsa.net/click?' . http_build_query(array(
    'url' => $spa,
    'sig' => $spa_sig,
));
$old_after = rawurldecode($old);
$old_parts = parse_url($old_after);
$t->check(
    !empty($old_parts['fragment']),
    'the previous url-first encoding lost the signature to a URL fragment'
);

$t->check(Azure_Newsletter_Queue::should_reclaim_failed(0, 3), 'reclaim when only failed rows remain');
$t->check(!Azure_Newsletter_Queue::should_reclaim_failed(2, 3), 'do not reclaim while pending remains');
$t->check(!Azure_Newsletter_Queue::should_reclaim_failed(0, 0), 'nothing to reclaim when queue is empty');

exit($t->finish() === 0 ? 0 : 1);
