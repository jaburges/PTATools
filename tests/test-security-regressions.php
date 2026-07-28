<?php
/**
 * Regression guards for the security and data-loss bugs fixed in 3.142.0.
 *
 * Each of these shipped at least once, and each is the kind of thing that comes
 * back: a debug line reinstated while chasing a settings bug, a fail-open
 * branch re-added to get a webhook working on a dev box, a nonce check relaxed
 * because an OAuth redirect lost the session cookie. The behavioural fixes are
 * covered by unit checks where the code is reachable without WordPress; the
 * rest are asserted against the shipped source so a reintroduction fails here
 * rather than in production.
 *
 * Run: php tests/test-security-regressions.php
 */

require_once __DIR__ . '/wp-shim.php';

$t = new TestRunner('Security regressions');

$plugin_dir = dirname(__DIR__) . '/Azure Plugin';

function src($plugin_dir, $rel) {
    $path = $plugin_dir . '/' . $rel;
    if (!is_file($path)) {
        throw new RuntimeException("Expected plugin file is missing: {$rel}");
    }
    return file_get_contents($path);
}

// ---------------------------------------------------------------------------
// Credentials must never reach the PHP error log.
// ---------------------------------------------------------------------------
//
// update_setting() handles the array holding every client secret and storage
// key the plugin owns. It used to json_encode that array straight into
// error_log() on each save, so the log became a plaintext credential dump
// readable by anyone with log access.

$settings = src($plugin_dir, 'includes/class-settings.php');

$t->check(
    strpos($settings, 'json_encode($settings)') === false,
    'the settings array is never serialized into a log line'
);
$t->check(
    !preg_match('/error_log\([^)]*\$value/', $settings),
    'no log line interpolates a raw setting value'
);
$t->check(
    !preg_match('/error_log\([^)]*json_encode\(\$current_option\)/', $settings),
    'the stored option is never dumped on the failure path'
);

// ---------------------------------------------------------------------------
// Mailgun webhooks must fail closed.
// ---------------------------------------------------------------------------
//
// The signing key is empty on a default install, and the verifier used to
// return true in that case — so the public webhook route accepted forged bounce
// and complaint events, which unsubscribe real addresses.

$tracking = src($plugin_dir, 'includes/class-newsletter-tracking.php');

if (!preg_match('/function verify_mailgun_signature.*?\n    }/s', $tracking, $m)) {
    $t->check(false, 'verify_mailgun_signature() is present');
} else {
    $verifier = $m[0];
    $empty_key_branch = null;
    if (preg_match('/if \(empty\(\$signing_key\)\) \{(.*?)\}/s', $verifier, $branch)) {
        $empty_key_branch = $branch[1];
    }
    $t->check($empty_key_branch !== null, 'the missing-key case is still handled explicitly');
    $t->check(
        $empty_key_branch !== null && strpos($empty_key_branch, 'return false') !== false,
        'an unconfigured signing key rejects the webhook'
    );
    $t->check(
        $empty_key_branch !== null && strpos($empty_key_branch, 'return true') === false,
        'an unconfigured signing key never accepts the webhook'
    );
}

// ---------------------------------------------------------------------------
// The calendar OAuth callback must not accept unverified state.
// ---------------------------------------------------------------------------
//
// The old code logged an invalid nonce and then continued anyway whenever the
// state carried a recent timestamp. The timestamp is supplied by whoever built
// the state, so the check could always be satisfied and CSRF protection on the
// callback was effectively absent.

$calendar_auth = src($plugin_dir, 'includes/class-calendar-auth.php');

$t->check(
    stripos($calendar_auth, 'Bypassing nonce check') === false,
    'the calendar callback has no nonce bypass'
);
$t->check(
    strpos($calendar_auth, "set_transient('azure_calendar_oauth_") !== false,
    'authorization records the expected state server-side'
);
$t->check(
    strpos($calendar_auth, "delete_transient(\$transient)") !== false,
    'the state record is consumed so it cannot be replayed'
);

// ---------------------------------------------------------------------------
// Expired seat reservations only.
// ---------------------------------------------------------------------------
//
// An unconditional delete ran before the expiry-scoped one, so the hourly
// cleanup released every held seat — including seats someone was checking out
// with.

$tickets = src($plugin_dir, 'includes/class-tickets-module.php');

if (!preg_match('/function cleanup_expired_reservations.*?\n    }/s', $tickets, $m)) {
    $t->check(false, 'cleanup_expired_reservations() is present');
} else {
    $cleanup = $m[0];
    $t->check(
        strpos($cleanup, '$wpdb->delete(') === false,
        'reservation cleanup issues no unscoped delete'
    );
    $t->check(
        strpos($cleanup, 'updated_at < %s') !== false,
        'reservation cleanup is still bounded by the expiry time'
    );
}

// Ticket generation is idempotent: the hook fires on both `processing` and
// `completed`, and most orders pass through both.
$t->check(
    preg_match('/function generate_tickets_for_order.*?FROM \{\$tickets_table\} WHERE order_id = %d/s', $tickets) === 1,
    'ticket generation checks for tickets it already issued'
);

// ---------------------------------------------------------------------------
// A failed calendar fetch must not be read as "the calendar is empty".
// ---------------------------------------------------------------------------
//
// get_calendar_events() returns an empty array on HTTP failure, and the sync
// engine trashes local events absent from the response — so one transient
// 401/429 would have deleted every synced event in the window.

$graph  = src($plugin_dir, 'includes/class-calendar-graph-api.php');
$engine = src($plugin_dir, 'includes/class-calendar-sync-engine.php');

$t->check(
    strpos($graph, 'function get_last_fetch_error') !== false,
    'the Graph client reports why a fetch came back empty'
);
$t->check(
    preg_match('/last_fetch_error = .*Graph returned HTTP/', $graph) === 1,
    'a non-200 response is recorded as an error, not an empty calendar'
);
$t->check(
    strpos($engine, 'get_last_fetch_error') !== false,
    'the sync engine asks whether the fetch actually succeeded'
);
$t->check(
    preg_match('/get_last_fetch_error.*?not pruning local events/s', $engine) === 1,
    'the sync engine skips pruning when the fetch failed'
);

// ---------------------------------------------------------------------------
// Archive extraction must reject traversal.
// ---------------------------------------------------------------------------
//
// ZipArchive::extractTo() honours "../" and absolute entry names, unlike WP's
// unzip_file(), so a tampered backup could overwrite wp-config.php on restore.

$restore = src($plugin_dir, 'includes/class-backup-restore.php');

if (!preg_match('/function extract_zip.*?\n    }/s', $restore, $m)) {
    $t->check(false, 'extract_zip() is present');
} else {
    $extract = $m[0];
    $t->check(
        strpos($extract, 'getNameIndex') !== false,
        'entry names are inspected before extraction'
    );
    $t->check(
        strpos($extract, "'..'") !== false,
        'traversal segments are rejected'
    );
    $t->check(
        preg_match('/unsafe entry path/', $extract) === 1,
        'an unsafe entry aborts the extraction'
    );
    // The guard has to run before the extract call, not after. Matched against
    // the call itself rather than the name, which also appears in a comment.
    $t->check(
        strpos($extract, 'getNameIndex') < strpos($extract, '$zip->extractTo('),
        'the guard runs before anything is written to disk'
    );
}

// ---------------------------------------------------------------------------
// Redirects that leave the site must be validated or signed.
// ---------------------------------------------------------------------------

$sso        = src($plugin_dir, 'includes/class-sso-auth.php');
$newsletter = src($plugin_dir, 'includes/class-newsletter-module.php');
$sender     = src($plugin_dir, 'includes/class-newsletter-sender.php');

// FILTER_VALIDATE_URL proves a string is a URL, not that it points here.
$t->check(
    strpos($sso, 'wp_validate_redirect') !== false,
    'the SSO return URL is confined to an allowed host'
);
$t->check(
    strpos($sso, "\$_SERVER['HTTP_HOST']") === false,
    'the SSO return URL is not built from a client-supplied Host header'
);

// Click tracking must be able to leave the site, so the destination is signed
// rather than host-restricted — otherwise the endpoint is a free redirector
// for phishing under this domain's name.
$t->check(
    strpos($newsletter, 'function click_signature') !== false,
    'click destinations are signed'
);
$t->check(
    strpos($newsletter, 'hash_equals(self::click_signature($url), $sig)') !== false,
    'the click route verifies the signature before redirecting'
);
$t->check(
    strpos($sender, 'Azure_Newsletter_Module::click_signature($url)') !== false,
    'the sender signs every tracked link it emits'
);
$t->check(
    strpos($sender, "'url' => urlencode(\$url)") === false,
    'tracked destinations are no longer double-encoded'
);

// Tracking tokens are base64 of an HMAC; plain base64 emits "+", "/" and "=",
// none of which survive the route patterns, so pixels and unsubscribe links
// silently 404'd.
$t->check(
    preg_match("/strtr\(base64_encode\(hash_hmac\('sha256'.*?'\+\/', '-_'\)/s", $sender) === 1,
    'tracking tokens use URL-safe base64'
);
preg_match_all('/\(\?P<token>\[([^\]]+)\]\+\)/', $newsletter, $patterns);
$t->check(!empty($patterns[1]), 'the token routes are still registered');
$bad_patterns = array_values(array_filter($patterns[1], function ($charset) {
    return strpos($charset, '_-') === false && strpos($charset, '-_') === false;
}));
$t->check(
    empty($bad_patterns),
    'every token route accepts the URL-safe alphabet',
    empty($bad_patterns) ? count($patterns[1]) . ' routes' : 'narrow: ' . implode(', ', $bad_patterns)
);

// ---------------------------------------------------------------------------
// Refresh tokens survive a refresh response that omits them.
// ---------------------------------------------------------------------------
//
// Microsoft frequently returns no refresh_token on refresh and expects the
// caller to keep the existing one. Defaulting to '' wiped it, so the next
// expiry had nothing to refresh with and the connection needed re-authorising
// by hand. Both Graph integrations had this.

foreach (array(
    'includes/class-calendar-auth.php'       => 'azure_calendar_tokens',
    'includes/class-onedrive-media-auth.php' => null,
) as $rel => $option) {
    $body = src($plugin_dir, $rel);
    $t->check(
        preg_match("/\\\$token_data\['refresh_token'\] \?\? ''\s*;?\s*\n\s*(?:if|\\\$)/", $body) === 1
        || strpos($body, 'existing') !== false,
        "a missing refresh_token falls back to the stored one ({$rel})"
    );
}

$calendar_store = null;
if (preg_match('/function store_tokens.*?\n    }/s', $calendar_auth, $m)) {
    $calendar_store = $m[0];
}
$t->check($calendar_store !== null, 'calendar store_tokens() is present');
$t->check(
    $calendar_store !== null && strpos($calendar_store, "get_option('azure_calendar_tokens'") !== false,
    'calendar token storage reads the existing record before overwriting it'
);
$t->check(
    $calendar_store !== null
        && preg_match("/refresh_token === ''.*?\\\$existing\['refresh_token'\]/s", $calendar_store) === 1,
    'calendar token storage keeps the old refresh token when none is returned'
);

// ---------------------------------------------------------------------------
// Read endpoints that return other people's data need a capability, not just a
// nonce — any logged-in subscriber holds a valid nonce.
// ---------------------------------------------------------------------------

$volunteer = src($plugin_dir, 'includes/class-volunteer-signup.php');

foreach (array(
    array($tickets,   'ajax_validate_ticket', 'scan_tickets',  'ticket lookup'),
    array($tickets,   'ajax_get_venue',       'manage_options', 'venue lookup'),
    array($volunteer, 'ajax_get_sheet',       'manage_options', 'volunteer sheet lookup'),
) as list($body, $method, $cap, $label)) {
    $found = preg_match('/function ' . preg_quote($method, '/') . '\(\).*?\n    }/s', $body, $m) === 1;
    $t->check($found, "{$label} handler is present");
    if ($found) {
        $t->check(
            strpos($m[0], "current_user_can('{$cap}')") !== false,
            "{$label} requires the {$cap} capability"
        );
    }
}

// ---------------------------------------------------------------------------
// Bearer-token routes need authority, not just identity.
// ---------------------------------------------------------------------------
//
// The REST permission_callback validates a tenant sign-in and nothing more, so
// every shop route needs its own capability check. Without one, any account
// that could sign in through SSO could list customer orders — names, addresses,
// purchase history — and issue refunds.

$rest = src($plugin_dir, 'includes/class-ptsa-rest-api.php');

$t->check(
    strpos($rest, 'function require_shop_cap') !== false,
    'the shop routes share a capability gate'
);

foreach (array(
    'list_orders'    => 'edit_shop_orders',
    'get_order'      => 'edit_shop_orders',
    'update_order'   => 'edit_shop_orders',
    'refund_order'   => 'edit_shop_orders',
    'add_order_note' => 'edit_shop_orders',
    'list_products'  => 'edit_products',
    'create_product' => 'edit_products',
    'update_product' => 'edit_products',
) as $method => $cap) {
    $found = preg_match(
        '/function ' . preg_quote($method, '/') . '\(WP_REST_Request \$req\) \{(.*?)\n    \}/s',
        $rest,
        $m
    ) === 1;
    $t->check($found, "{$method}() is present");
    if ($found) {
        $t->check(
            strpos($m[1], "require_shop_cap('{$cap}')") !== false,
            "{$method}() requires {$cap}"
        );
    }
}

// The bare WooCommerce-present check is not an authorization check, so it must
// no longer be the only thing standing in front of a shop route.
$t->check(
    !preg_match('/\$req\) \{\n        if \(!(?:\$err = )?\$this->require_wc\(\)\) return \$this->forbidden\(\);/', $rest),
    'no shop route is gated by the WooCommerce-present check alone'
);

// ---------------------------------------------------------------------------
// Inline JSON cannot break out of its <script> block.
// ---------------------------------------------------------------------------
//
// Event titles come from Outlook and org data from the database, so a value
// containing "</script>" would otherwise close the block and execute.

foreach (array(
    'includes/class-calendar-shortcode.php',
    'includes/class-pta-shortcode.php',
) as $rel) {
    $body = src($plugin_dir, $rel);
    $t->check(
        !preg_match('/[^_]json_encode\(/', $body),
        "no bare json_encode() reaches inline script output ({$rel})"
    );
    $t->check(
        strpos($body, 'JSON_HEX_TAG') !== false,
        "inline JSON escapes angle brackets ({$rel})"
    );
}

// ---------------------------------------------------------------------------
// Log pruning targets a table that exists.
// ---------------------------------------------------------------------------
//
// The registry key is 'activity_log'; 'activity' resolved to null, so the
// 90-day cleanup returned early every time and the table grew without bound.

$logger   = src($plugin_dir, 'includes/class-logger.php');
$database = src($plugin_dir, 'includes/class-database.php');

$t->check(
    strpos($logger, "get_table_name('activity_log')") !== false,
    'log cleanup asks for the activity_log table'
);
$t->check(
    strpos($logger, "get_table_name('activity')") === false,
    'log cleanup no longer uses the key that resolves to null'
);
$t->check(
    preg_match("/'activity_log'\s*=>/", $database) === 1,
    'activity_log is the key the registry actually defines'
);

// ---------------------------------------------------------------------------
// Manager sync can resolve the department it was handed.
// ---------------------------------------------------------------------------
//
// get_user_assignments() did not select r.department_id, so the caller read an
// undefined property, looked up department null, and queued every manager sync
// with an empty manager.

$manager = src($plugin_dir, 'includes/class-pta-manager.php');

$t->check(
    preg_match('/SELECT ra\.\*, r\.name as role_name, r\.slug as role_slug, r\.department_id/', $manager) === 1,
    'assignments carry the department id their callers dereference'
);
$t->check(
    preg_match('/if \(!\$department\) \{/', $manager) === 1,
    'a missing department is handled instead of dereferenced'
);

exit($t->finish() > 0 ? 1 : 0);
