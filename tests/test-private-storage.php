<?php
/**
 * Private storage: logs, certificates and backup staging stay out of reach of
 * the web server, and log lines never carry a full email address.
 *
 * Loads the real Azure_Logger (before the shim, which would otherwise stub it)
 * so the masking is checked on what actually lands in the log file.
 *
 * Run: php tests/test-private-storage.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

$uploads_root = '/tmp/wp-uploads';
$legacy_dir = $uploads_root . '/azure-plugin';

function rm_tree($dir) {
    if (!is_dir($dir)) {
        return;
    }
    foreach (array_diff(scandir($dir), array('.', '..')) as $item) {
        $path = $dir . '/' . $item;
        is_dir($path) ? rm_tree($path) : unlink($path);
    }
    rmdir($dir);
}

rm_tree($uploads_root);
mkdir($legacy_dir, 0777, true);
file_put_contents($legacy_dir . '/logs.md', "# Old log\n01-01-2026 10:00:00 [Newsletter] - INFO - Signup from jamie.parent@gmail.com\n");
file_put_contents($legacy_dir . '/logs-backup-2026-01-01-00-00-00.md', "old rotated: kid.parent@example.org\n");

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-logger.php';
require_once __DIR__ . '/wp-shim.php';

$t = new TestRunner('Private storage');

// No activity-log table, so database logging stays quiet.
$GLOBALS['wpdb'] = new class {
    public $prefix = 'wp_';
    public function get_var($query) { return null; }
};

// ---------------------------------------------------------------------------
// Email masking
// ---------------------------------------------------------------------------

$t->equals('j***@gmail.com', Azure_Private_Storage::mask_emails('jamie.parent@gmail.com'), 'masks the local part, keeps the domain');
$t->equals(
    'From a***@school.k12.wa.us to b***@example.co.uk.',
    Azure_Private_Storage::mask_emails('From alice+pta@school.k12.wa.us to bob_smith@example.co.uk.'),
    'masks every address in a sentence'
);
$t->equals('no address here', Azure_Private_Storage::mask_emails('no address here'), 'leaves text without an address alone');
$t->equals('@handle and a@b', Azure_Private_Storage::mask_emails('@handle and a@b'), 'ignores things that are not addresses');
$t->equals(42, Azure_Private_Storage::mask_emails(42), 'passes non-strings through');

// ---------------------------------------------------------------------------
// Locations
// ---------------------------------------------------------------------------

$private = Azure_Private_Storage::dir();
$token = WP_Shim::$options[Azure_Private_Storage::TOKEN_OPTION] ?? '';
$t->check((bool) preg_match('/^[a-f0-9]{32}$/', $token), 'stores a random 32-hex directory token', $token);
$t->equals($legacy_dir . '/private-' . $token, $private, 'persistent storage sits in an unguessable folder');
$t->check(is_file($private . '/.htaccess') && is_file($private . '/index.php'), 'writes deny and index files for servers that read them');

Azure_Private_Storage::reset();
$t->equals($private, Azure_Private_Storage::dir(), 'the token is reused rather than regenerated');

$t->equals($private . '/certificates', Azure_Private_Storage::dir('certificates'), 'subdirectories are created under it');
$t->check(is_dir($private . '/certificates'), 'subdirectory exists on disk');

$temp = Azure_Private_Storage::temp_dir('backups');
$t->check(is_dir($temp), 'temp storage is created', $temp);
$t->check(!Azure_Private_Storage::is_inside_web_root($temp), 'temp storage is outside the WordPress root', $temp);
$t->check(strpos($temp, $token) !== false, 'temp storage is namespaced by the site token');
$t->check(Azure_Private_Storage::is_inside_web_root(__DIR__ . '/not-yet-created/file.txt'), 'paths under ABSPATH count as web root, even before they exist');

// ---------------------------------------------------------------------------
// Logger
// ---------------------------------------------------------------------------

Azure_Logger::init();
$log_file = Azure_Logger::get_log_file_path();
$t->equals($private . '/logs/logs.md', $log_file, 'the log lives in private storage');
$t->check(!file_exists($legacy_dir . '/logs.md'), 'the old log is no longer in the old folder');
$t->check(!file_exists($legacy_dir . '/logs-backup-2026-01-01-00-00-00.md'), 'old rotated logs are moved too');

$migrated = file_get_contents($log_file);
$t->check(strpos($migrated, 'Signup from j***@gmail.com') !== false, 'the moved log keeps its history');
$t->check(strpos($migrated, 'jamie.parent@gmail.com') === false, 'the moved log has its addresses masked');
$rotated = file_get_contents($private . '/logs/logs-backup-2026-01-01-00-00-00.md');
$t->check(strpos($rotated, 'kid.parent@example.org') === false && strpos($rotated, 'k***@example.org') !== false, 'moved rotated logs are masked');

Azure_Logger::info('Newsletter signup deferred (school staff): new.parent@gmail.com', array(
    'module' => 'NewsletterSignup',
    'to' => array('first@example.com', 'second@example.com'),
    'second.key@example.com' => 'value',
));
Azure_Logger::success('Sent receipt to donor.name@example.net');
$log = file_get_contents($log_file);
foreach (array('new.parent@gmail.com', 'first@example.com', 'second@example.com', 'second.key@example.com', 'donor.name@example.net') as $address) {
    $t->check(strpos($log, $address) === false, "no full address in the log: {$address}");
}
$t->check(strpos($log, 'n***@gmail.com') !== false, 'the masked address is still recognisable');
$t->check(strpos($log, 'd***@example.net') !== false, 'formatted log lines are masked too');

// ---------------------------------------------------------------------------
// Callers use private storage
// ---------------------------------------------------------------------------

$plugin_dir = dirname(__DIR__) . '/Azure Plugin';
$sources = array(
    'includes/class-backup.php',
    'includes/class-backup-restore.php',
    'includes/class-tickets-apple-wallet.php',
    'admin/tickets-page.php',
    'admin/sso-page.php',
    'includes/class-email-router.php',
    'azure-plugin.php',
);
foreach ($sources as $rel) {
    $src = file_get_contents($plugin_dir . '/' . $rel);
    $t->check(!preg_match("/AZURE_PLUGIN_PATH \\. 'backups\\/(?:temp|restore|tmp)_/", $src), "{$rel}: no staging under the plugin directory");
    $t->check(strpos($src, "AZURE_PLUGIN_PATH . 'logs.") === false, "{$rel}: no logs in the plugin directory");
    $t->check(!preg_match("#'/azure-plugin/(?:temp|passes)/#", $src), "{$rel}: no pass files in the old uploads folders");
}

$tickets_page = file_get_contents($plugin_dir . '/admin/tickets-page.php');
$t->check(
    strpos($tickets_page, 'Azure_Tickets_Apple_Wallet::certificate_path()') !== false && strpos($tickets_page, 'azure-plugin/certificates') === false,
    'uploaded signing certificates go to private storage'
);

$apache = file_get_contents(dirname(__DIR__) . '/infra/wp-image/apache-wp.conf');
foreach (array(
    '<Directory /var/www/html/wp-content/uploads/azure-plugin>',
    '<Directory /var/www/html/wp-content/uploads/pta-cleanup-backups>',
    '<Directory "/var/www/html/wp-content/plugins/Azure Plugin/backups">',
) as $block) {
    $start = strpos($apache, $block);
    $section = $start === false ? '' : substr($apache, $start, strpos($apache, '</Directory>', $start) - $start);
    $t->check(strpos($section, 'Require all denied') !== false, "Apache denies {$block}");
}

rm_tree($uploads_root);
rm_tree(dirname($temp));

exit($t->finish() > 0 ? 1 : 0);
