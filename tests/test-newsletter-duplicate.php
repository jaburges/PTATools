<?php
/**
 * Duplicating a sent campaign creates a draft copy of the design
 * with the same recipient lists and a clean send history.
 *
 * Run: php tests/test-newsletter-duplicate.php
 */

require_once __DIR__ . '/wp-shim.php';

if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', dirname(__DIR__) . '/Azure Plugin/');
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-module.php';

$t = new TestRunner('Newsletter campaign duplicate');

$t->check(
    method_exists('Azure_Newsletter_Module', 'prepare_duplicate_campaign'),
    'prepare_duplicate_campaign exists'
);

$original = array(
    'id' => 42,
    'name' => 'August welcome',
    'subject' => 'Welcome back',
    'from_name' => 'Wilder PTSA',
    'from_email' => 'hello@example.test',
    'content_html' => '<p>Hello</p>',
    'content_json' => '{"pages":[]}',
    'recipient_lists' => '["3","all"]',
    'status' => 'sent',
    'scheduled_at' => '2026-08-20 09:00:00',
    'sent_at' => '2026-08-20 09:05:00',
    'archive_token' => 'original-token-must-not-reuse',
    'wp_page_id' => 23140,
    'page_category' => 'newsletter',
    'created_by' => 7,
    'created_at' => '2026-08-01 12:00:00',
    'updated_at' => '2026-08-20 09:05:00',
);

$copy = method_exists('Azure_Newsletter_Module', 'prepare_duplicate_campaign')
    ? Azure_Newsletter_Module::prepare_duplicate_campaign($original)
    : $original;

$t->check(!isset($copy['id']), 'copy has no id so insert assigns a new campaign');
$t->equals('August welcome - copy', $copy['name'] ?? '', 'name gets a - copy suffix');
$t->equals('Welcome back', $copy['subject'] ?? '', 'subject is copied');
$t->equals('<p>Hello</p>', $copy['content_html'] ?? '', 'HTML design is copied');
$t->equals('{"pages":[]}', $copy['content_json'] ?? '', 'editor JSON is copied');
$t->equals('["3","all"]', $copy['recipient_lists'] ?? '', 'recipient lists stay selected');
$t->equals('draft', $copy['status'] ?? '', 'copy is a draft');
$t->check(empty($copy['sent_at']), 'sent_at is cleared');
$t->check(empty($copy['scheduled_at']), 'scheduled_at is cleared');
$t->check(empty($copy['wp_page_id']), 'archive page is not shared');
$t->check(
    empty($copy['archive_token']) || $copy['archive_token'] !== 'original-token-must-not-reuse',
    'archive token is not reused (UNIQUE would silently fail the insert)'
);

$campaigns = file_get_contents(dirname(__DIR__) . '/Azure Plugin/admin/newsletter-campaigns.php');
$t->check(
    strpos($campaigns, 'prepare_duplicate_campaign') !== false,
    'row and bulk Duplicate use the shared prepare helper'
);

exit($t->finish() === 0 ? 0 : 1);
