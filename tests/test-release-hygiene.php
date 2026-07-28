<?php
/**
 * Release hygiene: nothing organization-specific may ship in the plugin.
 *
 * The plugin is developed against one live site, so per-install values have a
 * habit of settling into defaults where they are invisible until someone else
 * installs it — outgoing mail branded with the wrong PTA, an allow-list that
 * rejects every sign-in, a cache purge aimed at another tenant's resource
 * group. This suite is the tripwire for that: a static scan of every shipped
 * PHP file plus unit checks that the replaced defaults really do derive from
 * the site's own configuration.
 *
 * Run: php tests/test-release-hygiene.php
 */

require_once __DIR__ . '/wp-shim.php';

$t = new TestRunner('Release hygiene');

$plugin_dir = dirname(__DIR__) . '/Azure Plugin';

// ---------------------------------------------------------------------------
// 1. Static scan for organization-specific identifiers.
// ---------------------------------------------------------------------------

/**
 * Every file that ends up inside the release zip, excluding the checked-in
 * wiki/log dumps that are not part of the plugin.
 *
 * Markdown counts. The release zip is built from the whole plugin directory, so
 * the developer notes under `docs/` and the README travel with it — that is how
 * a set of install-specific domains reached a public release once already.
 *
 * @param string[] $extensions Lowercase extensions to include.
 */
function shipped_files($root, array $extensions = array('php')) {
    $skip_dirs = array('PTATools.wiki', 'salvage', 'node_modules', 'vendor');
    $files = array();
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile() || !in_array(strtolower($file->getExtension()), $extensions, true)) {
            continue;
        }
        $rel = str_replace($root . '/', '', $file->getPathname());
        $first = explode('/', $rel)[0];
        if (in_array($first, $skip_dirs, true)) {
            continue;
        }
        $files[$rel] = $file->getPathname();
    }
    ksort($files);
    return $files;
}

$php_files = shipped_files($plugin_dir, array('php'));
$t->check(count($php_files) > 90, 'the scan sees the whole plugin', count($php_files) . ' php files');

// Docs ship in the zip alongside the code, so they are scanned on equal terms.
$files = shipped_files($plugin_dir, array('php', 'md'));
$t->check(
    count($files) > count($php_files),
    'the scan includes shipped documentation',
    (count($files) - count($php_files)) . ' markdown files'
);

// Identifiers that belong to the site this plugin was built for. Any of these
// appearing anywhere in shipped PHP — default value, admin copy, or comment —
// is a leak that a fresh install would inherit.
$forbidden = array(
    'wilderptsa'     => 'production domain of the origin site',
    'lwptsa'         => 'sibling PTSA domain',
    'ltptsa'         => 'sibling PTSA domain',
    'lwsd.org'       => 'school district domain',
    'Wilder PTSA'    => 'organization display name',
    'WilderPTSA'     => 'organization display name',
    'Wilder Staff'   => 'AcyMailing list name from the origin install',
    'PTSAWebsite'    => 'Azure resource group of the origin install',
    'WilderPTSAAFD'  => 'Azure Front Door profile of the origin install',
);

foreach ($forbidden as $needle => $why) {
    $hits = array();
    foreach ($files as $rel => $path) {
        $contents = file_get_contents($path);
        if (stripos($contents, $needle) === false) {
            continue;
        }
        // Report the first offending line so a failure is actionable.
        foreach (explode("\n", $contents) as $n => $line) {
            if (stripos($line, $needle) !== false) {
                $hits[] = $rel . ':' . ($n + 1);
                break;
            }
        }
    }
    $t->check(
        empty($hits),
        sprintf('nothing in the release zip mentions "%s" (%s)', $needle, $why),
        empty($hits) ? '' : implode(', ', array_slice($hits, 0, 4))
    );
}

// A real person's address must never be committed as an example. Spam-wave
// cleanup docs used to name actual accounts.
$pii_pattern = '/\b[a-z]+\.[a-z]+\d{3,}@/i';
$pii_hits = array();
foreach ($files as $rel => $path) {
    if (preg_match($pii_pattern, file_get_contents($path))) {
        $pii_hits[] = $rel;
    }
}
$t->check(empty($pii_hits), 'no firstname.lastname####@domain addresses are committed', implode(', ', $pii_hits));

// ---------------------------------------------------------------------------
// 2. Email router: seeded sender identity comes from the site, not a constant.
// ---------------------------------------------------------------------------

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('AZURE_PLUGIN_PATH')) {
    define('AZURE_PLUGIN_PATH', $plugin_dir . '/');
}

// Minimal extra shims the router needs.
if (!function_exists('wp_specialchars_decode')) {
    function wp_specialchars_decode($text, $quote_style = ENT_NOQUOTES) {
        return html_entity_decode((string) $text, ENT_QUOTES);
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $key)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) { return trim(strip_tags((string) $str)); }
}
if (!function_exists('sanitize_email')) {
    function sanitize_email($email) { return (string) $email; }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($length = 12, $special = true) { return substr(str_repeat('a1b2c3d4', 4), 0, $length); }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
}

require_once $plugin_dir . '/includes/class-email-router.php';

$routing = call_private('Azure_Email_Router', 'default_routing');
$routes  = $routing['routes'];

$t->equals(3, count($routes), 'three rules are seeded');

$names = array();
foreach ($routes as $r) {
    $names[$r['id']] = $r['from_name'];
}

// get_bloginfo('name') is "Test Site" in the shim; home_url() is example.test.
$t->equals('Test Site Newsletter', $names['newsletter'], 'newsletter sender is named after the site');
$t->equals('Test Site Shop', $names['shop'], 'shop sender is named after the site');
$t->equals('Test Site', $names['wpcore'], 'catch-all sender is named after the site');

foreach ($routes as $r) {
    $t->check(
        substr($r['from_address'], -strlen('@example.test')) === '@example.test',
        sprintf('"%s" sends from the site\'s own domain', $r['id']),
        $r['from_address']
    );
}

$t->check(
    stripos(wp_json_encode($routing), 'wilder') === false,
    'the seeded table carries no trace of the origin organization'
);

// The catch-all must survive a table that lost it, or wp_mail falls into a
// black hole where nothing matches and no provider is chosen.
$stored = array('version' => 1, 'routes' => array(
    array('id' => 'only', 'from_match' => 'x@y.z', 'is_default' => false, 'enabled' => true),
));
WP_Shim::$options['azure_email_routing'] = $stored;
$loaded = Azure_Email_Router::get_routing();
$has_default = false;
foreach ($loaded['routes'] as $r) {
    if (!empty($r['is_default'])) { $has_default = true; }
}
$t->check($has_default, 'a stored table missing its catch-all gets one restored');
unset(WP_Shim::$options['azure_email_routing']);

// ---------------------------------------------------------------------------
// 3. Contact form: the recipient is pinned by the nonce.
// ---------------------------------------------------------------------------
//
// The recipient rides in a hidden field, so before this fix any visitor could
// rewrite it and have the site relay mail to an arbitrary address using the
// organization's own authenticated sender — ideal for phishing. Binding the
// nonce action to the address means a rewritten recipient no longer verifies.

require_once $plugin_dir . '/includes/class-email-shortcode.php';

$action_configured = call_private('Azure_Email_Shortcode', 'contact_nonce_action', array('info@example.test'));
$action_attacker   = call_private('Azure_Email_Shortcode', 'contact_nonce_action', array('victim@evil.example'));

$t->check($action_configured !== $action_attacker, 'a different recipient yields a different nonce action');
$t->equals(
    $action_configured,
    call_private('Azure_Email_Shortcode', 'contact_nonce_action', array('INFO@EXAMPLE.TEST')),
    'recipient casing does not change the action'
);
$t->check(
    strpos($action_configured, 'info@example.test') !== false,
    'the action embeds the recipient so it cannot be swapped'
);

exit($t->finish() > 0 ? 1 : 0);
