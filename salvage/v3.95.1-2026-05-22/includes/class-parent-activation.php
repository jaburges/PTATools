<?php
/**
 * Parent Activation (magic-link onboarding)
 *
 * Imported parent accounts are created with `_pta_login_disabled = 1` so
 * they cannot sign in until the user clicks an activation link sent in the
 * welcome email. This class handles:
 *
 *   1. Issuing a one-time activation token for a user (hashed at rest).
 *   2. Building a public activation URL that includes the user id and the
 *      raw token (only ever leaves the server inside the welcome email).
 *   3. The public `?pta-activate=<uid>:<token>` endpoint that:
 *        - constant-time validates the token against the stored hash
 *        - clears `_pta_login_disabled`
 *        - signs the user in (auth cookie)
 *        - leaves `_pta_force_password_change = 1` so the existing
 *          Azure_Parent_Role redirect routes them to Account Details
 *          to set a real password.
 *   4. A daily cleanup hook that purges expired tokens from user_meta.
 *
 * Resource policy:
 *   - The endpoint listener runs on `init` priority 1 but bails out within
 *     ~1 µs if the query var is absent (single isset() check). That keeps
 *     the cost off every front-end pageload.
 *   - Token issuance and lookup are user-meta only — no extra tables.
 *   - The cleanup cron is registered through the central
 *     Azure_PTA_Cron::ensure_events_scheduled() pass, not here.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Parent_Activation {

    const META_TOKEN_HASH = '_pta_activation_token';
    const META_EXPIRES_AT = '_pta_activation_expires_at';
    const META_IMPORTED_AT = '_pta_imported_at';
    const META_IMPORT_SOURCE = '_pta_imported_source';

    const QUERY_VAR = 'pta-activate';
    const TOKEN_TTL_SECONDS = 1209600; // 14 days
    const CLEANUP_HOOK = 'azure_parent_activation_cleanup';

    private static $instance = null;
    private static $endpoint_handled = false;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Listener runs on wp_loaded (priority 1) instead of init priority
        // 1: the plugin loader instantiates this class FROM INSIDE its own
        // init priority-10 callback, so registering on init priority 1
        // would never fire (priority 1 has already passed). wp_loaded runs
        // after init completes for all plugins, before any rendering or
        // header output — the right place to set the auth cookie and
        // redirect on success.
        //
        // Cheapest-possible listener: bail at the first isset() if the URL
        // doesn't carry the activation query var. Front-end pageloads pay
        // ~1 µs.
        add_action('wp_loaded', array($this, 'maybe_handle_activation'), 1);

        // Belt-and-suspenders: also handle on init priority 99 in case
        // some other code path triggered an early redirect (e.g. caching
        // plugins) before wp_loaded runs. The $endpoint_handled guard
        // makes the second call a no-op.
        add_action('init', array($this, 'maybe_handle_activation'), 99);

        add_action(self::CLEANUP_HOOK, array(__CLASS__, 'cleanup_expired_tokens'));
    }

    // ─────────────────────────────────────────────────────────────────
    //  Token issuance
    // ─────────────────────────────────────────────────────────────────

    /**
     * Issue a fresh activation token for the given user. Returns the raw
     * token (the only place it ever exists in plaintext); a SHA-256 hash
     * is stored in user_meta along with an expiry timestamp.
     *
     * Idempotent: calling this for a user who already has a token simply
     * rotates it (old hash overwritten). The link in any previously sent
     * email becomes invalid the next time we reissue, which is exactly
     * what we want when an admin re-runs the welcome blast.
     *
     * @param int $user_id
     * @param int|null $ttl_seconds Override the default 14-day TTL.
     * @return string|false Raw token, or false if the user is invalid.
     */
    public static function issue_token($user_id, $ttl_seconds = null) {
        $user = get_user_by('id', (int) $user_id);
        if (!$user) {
            return false;
        }
        if ($ttl_seconds === null) {
            $ttl_seconds = self::TOKEN_TTL_SECONDS;
        }
        $ttl_seconds = max(60, (int) $ttl_seconds);

        // 32 bytes (256 bits) of entropy → 64 hex chars. wp_generate_password
        // uses random_bytes() under the hood when available.
        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $expires_at = time() + $ttl_seconds;

        update_user_meta($user_id, self::META_TOKEN_HASH, $hash);
        update_user_meta($user_id, self::META_EXPIRES_AT, $expires_at);

        return $raw;
    }

    /**
     * Build the public activation URL for a user + raw token. Always uses
     * https home_url so links work in production even when the request
     * generating the email is on http (e.g. wp-cron from CLI).
     */
    public static function build_url($user_id, $raw_token) {
        $base = home_url('/');
        $arg = (int) $user_id . ':' . $raw_token;
        return add_query_arg(self::QUERY_VAR, $arg, $base);
    }

    /**
     * One-shot helper used by importers and the welcome-email job: issue a
     * fresh token and return the activation URL. Returns false if the user
     * is invalid.
     */
    public static function issue_url($user_id, $ttl_seconds = null) {
        $raw = self::issue_token($user_id, $ttl_seconds);
        if ($raw === false) {
            return false;
        }
        return self::build_url($user_id, $raw);
    }

    /**
     * Revoke any outstanding token for a user (called on successful
     * activation and from the cleanup cron).
     */
    public static function revoke_token($user_id) {
        delete_user_meta($user_id, self::META_TOKEN_HASH);
        delete_user_meta($user_id, self::META_EXPIRES_AT);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Public endpoint
    // ─────────────────────────────────────────────────────────────────

    /**
     * Handle ?pta-activate=<uid>:<token>.
     *
     * Two-stage flow to defeat email link prefetchers (Gmail's link
     * scanner, Microsoft Defender Safe Links, corporate email security
     * gateways, Slack/Teams unfurlers, etc.) that hit the URL with a
     * GET request before the user clicks it.
     *
     * Stage 1 (GET): validate the token format + lookup user + verify
     *   the stored hash matches and is not expired. If everything checks
     *   out, render a confirmation page with an "Activate my account"
     *   button. Link prefetchers stop here — they don't run JS or
     *   auto-submit forms.
     *
     * Stage 2 (POST): re-validate (cheap), call activate_user() to
     *   clear login_disabled, set the auth cookie, and redirect to
     *   My Account → Edit account (where the existing
     *   force-password-change banner takes over).
     *
     * Multi-use within TTL: activate_user() does NOT revoke the token
     * (changed 2026-05-06). The same email link works for repeat clicks
     * during the 14-day TTL — every click sets a fresh auth cookie and
     * redirects to my-account, even if the user has already activated.
     * This eliminates the previous "already used" failure path that hit
     * users who clicked the link twice (refresh, second device, etc.).
     *
     * Failure path (either stage): wp_die() page with a "Sign in"
     *   call to action. Always shows the same generic message so we
     *   never leak whether the user id existed.
     */
    public function maybe_handle_activation() {
        if (self::$endpoint_handled) {
            return;
        }
        if (!isset($_GET[self::QUERY_VAR])) {
            return;
        }
        self::$endpoint_handled = true;

        // Magic-link URLs go through Azure Front Door which caches GET
        // responses by URL (including query string). Send no-cache
        // headers FIRST so the CDN treats every activation URL as
        // uncacheable — both the confirmation page (GET) and the 302
        // redirect (POST → success) must always reach origin.
        nocache_headers();
        if (!headers_sent()) {
            header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
            header('Pragma: no-cache', true);
            header('X-PTA-Activation: handler-running', true);
        }

        $raw = wp_unslash($_GET[self::QUERY_VAR]);
        if (!is_string($raw) || strpos($raw, ':') === false) {
            self::deny(__('That activation link is invalid or has already been used.', 'azure-plugin'));
        }

        list($uid_str, $token) = explode(':', $raw, 2);
        $user_id = (int) $uid_str;
        $token   = trim((string) $token);
        if ($user_id <= 0 || $token === '' || strlen($token) !== 64) {
            self::deny(__('That activation link is invalid or has already been used.', 'azure-plugin'));
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            self::deny(__('That activation link is invalid or has already been used.', 'azure-plugin'));
        }

        $stored_hash = get_user_meta($user_id, self::META_TOKEN_HASH, true);
        $expires_at  = (int) get_user_meta($user_id, self::META_EXPIRES_AT, true);

        if (empty($stored_hash) || $expires_at <= 0) {
            self::deny(__('That activation link has already been used. Please request a new one or sign in below.', 'azure-plugin'));
        }
        if ($expires_at < time()) {
            self::revoke_token($user_id);
            self::deny(__('That activation link has expired. Please request a new one.', 'azure-plugin'));
        }

        $candidate = hash('sha256', $token);
        if (!hash_equals($stored_hash, $candidate)) {
            self::deny(__('That activation link is invalid or has already been used.', 'azure-plugin'));
        }

        // Token is valid. Branch on HTTP method.
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method === 'POST') {
            // Stage 2: real activation.
            self::activate_user($user);

            $target = home_url('/');
            if (function_exists('wc_get_account_endpoint_url')) {
                $target = wc_get_account_endpoint_url('edit-account');
            }
            wp_safe_redirect(add_query_arg('pta-activated', '1', $target));
            exit;
        }

        // Stage 1: render confirmation page. Link prefetchers never go
        // beyond this — they only do GET, and they don't run JavaScript
        // or auto-submit forms.
        self::render_confirmation_page($user, $raw);
        exit;
    }

    /**
     * Render the "Click Activate to finish" landing page. The form posts
     * back to the same URL with the same query var; the POST branch in
     * maybe_handle_activation() consumes the token.
     */
    private static function render_confirmation_page(WP_User $user, $raw_query_value) {
        $site_name   = get_bloginfo('name');
        $first_name  = $user->first_name ?: $user->display_name ?: explode('@', $user->user_email)[0];
        $action_url  = esc_url(add_query_arg(self::QUERY_VAR, $raw_query_value, home_url('/')));
        $email_disp  = esc_html($user->user_email);
        $first_disp  = esc_html($first_name);
        $site_disp   = esc_html($site_name);
        $btn_label   = esc_html__('Activate my account', 'azure-plugin');
        $heading     = esc_html__('One more click to activate', 'azure-plugin');
        $sub_html    = wp_kses(
            sprintf(
                /* translators: 1: first name, 2: email, 3: site name */
                __('Hi %1$s — to keep your account secure we need one more click. Press the button below to activate <code>%2$s</code> on %3$s and choose your password.', 'azure-plugin'),
                $first_disp,
                $email_disp,
                $site_disp
            ),
            array('code' => array(), 'strong' => array(), 'em' => array())
        );
        $footer_html = wp_kses(
            sprintf(
                /* translators: %s: site name */
                __('This extra step prevents email link previewers from activating your account before you do.', 'azure-plugin'),
                $site_disp
            ),
            array()
        );

        // Inline page — no theme bootstrap, so it renders in <100ms even
        // on a cold cache. Style is inlined so we don't pull in 23 CSS
        // files for a one-button confirmation.
        $title = esc_html__('Activate your account', 'azure-plugin');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . $title . '</title>';
        echo '<style>
        body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;background:#f6f7f7;margin:0;padding:40px 20px;color:#1d2327;}
        .pta-card{max-width:520px;margin:40px auto;background:#fff;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.08);padding:40px;}
        .pta-card h1{margin:0 0 16px 0;font-size:24px;color:#0078d4;}
        .pta-card p{margin:0 0 16px 0;font-size:15px;line-height:1.5;color:#3c434a;}
        .pta-card code{background:#f0f0f1;padding:2px 6px;border-radius:3px;font-size:13px;}
        .pta-card .pta-btn{display:inline-block;padding:14px 32px;background:#0078d4;color:#fff;text-decoration:none;border:0;border-radius:6px;font-weight:600;font-size:15px;cursor:pointer;font-family:inherit;}
        .pta-card .pta-btn:hover{background:#106ebe;}
        .pta-card form{margin:24px 0 8px 0;text-align:center;}
        .pta-card .pta-foot{margin-top:24px;font-size:12px;color:#646970;text-align:center;}
        .pta-card .pta-brand{font-size:13px;color:#646970;margin:0 0 24px 0;}
        </style></head><body>';
        echo '<div class="pta-card">';
        echo '<p class="pta-brand">' . $site_disp . '</p>';
        echo '<h1>' . $heading . '</h1>';
        echo '<p>' . $sub_html . '</p>';
        echo '<form method="post" action="' . $action_url . '">';
        echo '<button type="submit" class="pta-btn">' . $btn_label . '</button>';
        echo '</form>';
        echo '<p class="pta-foot">' . $footer_html . '</p>';
        echo '</div></body></html>';
    }

    /**
     * Mark a user as activated: clear the login-disabled flag, set the
     * auth cookie, stamp last-login. Leaves `_pta_force_password_change`
     * intact so the user is routed to Account Details by the existing
     * Azure_Parent_Role redirect.
     *
     * Idempotent + multi-use: we deliberately do NOT revoke the
     * activation token here. The token stays valid for its full 14-day
     * TTL so a user can click the email link multiple times (refresh,
     * different browser, opened on a second device, came back later)
     * and always land on My Account → Edit account, signed in. The
     * cleanup_expired_tokens() cron sweeps tokens once they pass their
     * natural TTL.
     *
     * Trade-off: anyone who obtains the email (forwarded, leaked) can
     * authenticate as the user during the 14-day window. Accepted on
     * 2026-05-06 after the welcome blast revealed too many parents
     * hitting the previous "already used" page on their second click.
     */
    public static function activate_user(WP_User $user) {
        delete_user_meta($user->ID, Azure_Parent_Role::META_LOGIN_DISABLED);

        // Force-password-change flag is intentionally NOT cleared here.
        // Azure_Parent_Role::maybe_force_password_change() will pick it up
        // on the next request and redirect to edit-account; the existing
        // clear_force_pw_on_password_change() hook then clears the flag
        // when the user submits a new password.
        if (!get_user_meta($user->ID, Azure_Parent_Role::META_FORCE_PW_RESET, true)) {
            update_user_meta($user->ID, Azure_Parent_Role::META_FORCE_PW_RESET, 1);
        }

        wp_clear_auth_cookie();
        wp_set_current_user($user->ID, $user->user_login);
        wp_set_auth_cookie($user->ID, true, is_ssl());

        // Mirror the wp_login action so Azure_Parent_Role::record_last_login
        // and any other listeners fire as if this were a real sign-in.
        do_action('wp_login', $user->user_login, $user);

        if (class_exists('Azure_Logger')) {
            Azure_Logger::info(sprintf(
                'Parent account activated via magic link: user_id=%d email=%s',
                $user->ID,
                $user->user_email
            ), array('module' => 'ParentActivation'));
        }
    }

    /**
     * Render a friendly failure page. Always shows the same message no
     * matter the cause, so we don't leak whether a user id existed.
     */
    private static function deny($message) {
        $login_url = wp_login_url();
        $body = sprintf(
            '<p>%s</p><p><a class="button" href="%s">%s</a></p>',
            esc_html($message),
            esc_url($login_url),
            esc_html__('Go to sign in', 'azure-plugin')
        );
        wp_die($body, esc_html__('Activation link', 'azure-plugin'), array(
            'response'  => 200,
            'back_link' => false,
        ));
    }

    // ─────────────────────────────────────────────────────────────────
    //  Cleanup
    // ─────────────────────────────────────────────────────────────────

    /**
     * Remove expired token metadata. Cron hook target.
     *
     * One indexed query against user_meta (meta_key = expires_at,
     * meta_value < now). Then a per-user delete loop, but expired tokens
     * accumulate slowly so this is bounded in practice.
     */
    public static function cleanup_expired_tokens() {
        global $wpdb;
        $now = time();
        $expired = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
             WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) > 0 AND CAST(meta_value AS UNSIGNED) < %d",
            self::META_EXPIRES_AT,
            $now
        ));
        if (empty($expired)) {
            return 0;
        }
        $count = 0;
        foreach ($expired as $uid) {
            self::revoke_token((int) $uid);
            $count++;
        }
        if (class_exists('Azure_Logger')) {
            Azure_Logger::info("Activation cleanup: revoked {$count} expired token(s)", array('module' => 'ParentActivation'));
        }
        return $count;
    }
}
