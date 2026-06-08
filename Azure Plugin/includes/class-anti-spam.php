<?php
/**
 * Anti-Spam / Registration Hardening Module
 *
 * Two independent toggles:
 *
 *   1. `enable_block_registration` (default ON) — STRUCTURAL block.
 *      Returns 403 for `/wp-login.php?action=register`,
 *      `/wp-signup.php`, and `/register/`. Forces
 *      `users_can_register=0` and overrides `default_role` away from
 *      `customer`/admin-tier roles. Use this when the WP-native
 *      registration page should not be exposed at all.
 *
 *   2. `enable_anti_spam_filter` (default ON) — PATTERN block.
 *      Rejects bot-pattern usernames (random gibberish like
 *      `KcIIFSLaHgonfglOrGeuar`), random-string email local parts
 *      (e.g. `KcIIFSLaHgonfglOrGeuar@gmail.com`), and known
 *      disposable email providers. Hooks `registration_errors` so it
 *      catches anything that does sneak past toggle (1), and exposes
 *      a static `check_signup()` so other signup endpoints (the
 *      `[pta_newsletter_signup]` REST route, etc.) can reuse the
 *      same heuristic. Use this when parents need to be able to
 *      register but bots should be filtered.
 *
 * Typical configuration on wilderptsa: both toggles ON. The
 * registration form stays blocked (parents sign up via the
 * `[pta_newsletter_signup]` shortcode + Microsoft SSO), and the
 * pattern filter protects the shortcode endpoint from bots.
 *
 * The intent is "WP-native registration is OFF", with the only
 * supported account-creation paths being:
 *   - SSO (Microsoft sign-in) for @wilderptsa.net + @lwsd.org
 *   - [pta_newsletter_signup] shortcode → creates `parent` role
 *   - WooCommerce checkout (creates `customer` via wc_create_new_customer)
 *
 * Loaded unconditionally on every request. Constructor reads the
 * already-cached settings option once and only registers the hooks
 * that apply to the enabled toggles.
 *
 * @since 3.141.0  (split into two toggles in 3.141.1)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Anti_Spam {

    private static $instance = null;

    /** Toggle 1 — structural block of the WP registration page. Default ON. */
    const SETTING_BLOCK_FORM = 'enable_block_registration';

    /** Toggle 2 — pattern-based bot filter. Default ON. */
    const SETTING_FILTER     = 'enable_anti_spam_filter';

    /** Roles that should never become the WP "default role" for new
     *  registrations. If any of these slip into `default_role`, force
     *  back to `subscriber`. */
    const DANGEROUS_DEFAULT_ROLES = array(
        'administrator',
        'editor',
        'shop_manager',
        'pta_manager',
        'azuread',
        'parent',
        'school_staff',
        'dev_admin',
        'sign_up_plugin_administrators',
        'project_plugin_administrators',
        'fundraiser_plugin_administrators',
        'membership_admin',
        'messenger_admin',
        'bbp_keymaster',
        'bbp_moderator',
    );

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Each toggle wires its own set of hooks. They are independent
        // so an operator can run pattern-filter-only or form-block-only.
        if (self::should_block_form()) {
            $this->register_form_block_hooks();
        }
        if (self::should_filter_spam()) {
            $this->register_spam_filter_hooks();
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Setting accessors
    // ─────────────────────────────────────────────────────────────────

    /** Returns true if toggle 1 (form block) is enabled. Default ON. */
    public static function should_block_form() {
        return self::_setting_default_on(self::SETTING_BLOCK_FORM);
    }

    /** Returns true if toggle 2 (pattern filter) is enabled. Default ON. */
    public static function should_filter_spam() {
        return self::_setting_default_on(self::SETTING_FILTER);
    }

    /** Treat null/missing as ON. Only an explicit false disables. */
    private static function _setting_default_on($key) {
        if (class_exists('Azure_Settings')) {
            $settings = Azure_Settings::get_all_settings();
            if (isset($settings[$key])) {
                return !empty($settings[$key]);
            }
        }
        return true;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Toggle 1 — block the WP registration form
    // ─────────────────────────────────────────────────────────────────

    private function register_form_block_hooks() {
        add_filter('option_users_can_register', array($this, 'force_no_open_registration'), 999);
        add_filter('option_default_role',       array($this, 'force_safe_default_role'),   999);
        add_action('login_form_register',       array($this, 'block_login_register'), 1);
        add_action('login_init',                array($this, 'block_login_register_early'), 1);
        add_action('init',                      array($this, 'block_register_permalinks'), 1);
    }

    public function force_no_open_registration($value) {
        return 0;
    }

    public function force_safe_default_role($value) {
        $bad = self::DANGEROUS_DEFAULT_ROLES;
        if (is_string($value) && in_array(strtolower($value), $bad, true)) {
            return 'subscriber';
        }
        if ($value === 'customer') {
            return 'subscriber';
        }
        return $value;
    }

    public function block_login_register_early() {
        $action = isset($_GET['action']) ? strtolower((string) $_GET['action']) : '';
        if ($action === 'register') {
            $this->refuse_with_403('Registration is disabled on this site.');
        }
    }

    public function block_login_register() {
        $this->refuse_with_403('Registration is disabled on this site.');
    }

    public function block_register_permalinks() {
        if (!isset($_SERVER['REQUEST_URI'])) {
            return;
        }
        $uri  = (string) $_SERVER['REQUEST_URI'];
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return;
        }
        $path = strtolower(trim($path, '/'));
        $blocked_paths = array(
            'register',
            'signup',
            'sign-up',
            'wp-signup.php',
            'wp-register.php',
        );
        foreach ($blocked_paths as $bp) {
            if ($path === $bp || $path === $bp . '/') {
                $this->refuse_with_403('Registration is disabled on this site.');
            }
        }
    }

    private function refuse_with_403($message) {
        if (!headers_sent()) {
            nocache_headers();
            status_header(403);
        }
        wp_die(
            esc_html($message),
            'Forbidden',
            array(
                'response'  => 403,
                'back_link' => false,
            )
        );
    }

    // ─────────────────────────────────────────────────────────────────
    //  Toggle 2 — pattern-based spam filter
    // ─────────────────────────────────────────────────────────────────

    private function register_spam_filter_hooks() {
        add_filter('registration_errors', array($this, 'reject_spam_registration'), 10, 3);
        add_action('register_post',       array($this, 'reject_spam_register_post'), 10, 3);
    }

    public function reject_spam_registration($errors, $sanitized_user_login, $user_email) {
        $reason = self::run_classifier($sanitized_user_login, $user_email, '');
        if ($reason !== null) {
            $errors->add('pta_spam_detected', 'Registration rejected: ' . $reason);
            $this->log_rejection('wp_native', $sanitized_user_login, $user_email, '', $reason);
        }
        return $errors;
    }

    public function reject_spam_register_post($sanitized_user_login, $user_email, $errors) {
        $reason = self::run_classifier($sanitized_user_login, $user_email, '');
        if ($reason !== null && is_object($errors) && method_exists($errors, 'add')) {
            $errors->add('pta_spam_detected', 'Registration rejected: ' . $reason);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Public static helpers — used by other signup endpoints to apply
    //  the same spam classifier without forcing the caller to load the
    //  full module.
    // ─────────────────────────────────────────────────────────────────

    /**
     * Returns a non-empty reason string if the inputs look like a bot
     * signup, or null if they pass. Honors the `enable_anti_spam_filter`
     * toggle — when the filter is off, this always returns null so
     * callers don't need to gate their own call sites.
     *
     * Pass whichever fields the caller has. Empty strings are skipped.
     *
     * @param string $username  Optional. WP user_login if known.
     * @param string $email     Required by most callers.
     * @param string $name      Optional. Display name from the form.
     * @return string|null      reason code on reject, null on pass.
     */
    public static function check_signup($username, $email, $name = '') {
        if (!self::should_filter_spam()) {
            return null;
        }
        return self::run_classifier($username, $email, $name);
    }

    /**
     * Public helper used by the `spam-user-audit` diagnostic so the
     * audit applies the SAME rules as live registration blocking.
     */
    public static function classify_existing_user(WP_User $user) {
        $reason = self::run_classifier($user->user_login, $user->user_email, $user->display_name);
        return array('reason' => $reason);
    }

    /**
     * The classifier itself. Static so `check_signup()` and the audit
     * endpoint can both reach it without instantiating. Heuristics:
     *
     *   - Username is gibberish (mixed-case alphabet-only, ≥12 chars,
     *     ≥4 case transitions or 2+ consonant streaks ≥4)
     *   - Email local-part is gibberish (same heuristic)
     *   - Email domain is on the disposable-providers list
     *
     * Name fields are intentionally NOT classified — single-word names
     * exist (e.g. "Cher", "Beyoncé", non-Latin scripts) and the
     * false-positive cost on real parents outweighs the marginal
     * spam-catch benefit.
     */
    private static function run_classifier($username, $email, $name) {
        $username = (string) $username;
        $email    = (string) $email;

        if ($username !== '' && self::looks_like_random_string($username)) {
            return 'username_pattern_random';
        }

        if ($email !== '' && strpos($email, '@') !== false) {
            $local = substr($email, 0, strpos($email, '@'));
            // Tokenize the local part on common separators so bots
            // can't bypass the heuristic by appending digits or a
            // period to their gibberish (e.g. `KcIIFSLa.123` or
            // `kciifslahgonfglorgeuar_x9`). Each segment is
            // independently classified — if ANY token looks random,
            // we treat the whole address as bot-generated.
            $segments = preg_split('/[.\-_+0-9]+/', $local);
            foreach ((array) $segments as $seg) {
                if ($seg !== '' && self::looks_like_random_string($seg)) {
                    return 'email_local_pattern_random';
                }
            }
            if (self::is_disposable_email_domain($email)) {
                return 'disposable_email_domain';
            }
            // Random-looking domain second-level (e.g.
            // `falderewonek.site`, `xnzqrkbjvm.online`). Common bot
            // pattern is generated TLD with random gibberish SLD.
            if (self::has_suspicious_domain($email)) {
                return 'suspicious_email_domain';
            }
        }

        return null;
    }

    /**
     * Classifies the email's domain second-level label as
     * suspicious if it's gibberish on a "throwaway" TLD. Throwaway
     * TLDs are ones that legit domains rarely use (`.site`,
     * `.online`, `.xyz`, `.click`, `.top`, `.click`, `.cn`-style
     * spammy newgTLDs). Mainstream TLDs (.com/.org/.net/.edu/.gov
     * + country-code TLDs of well-known countries) are exempt to
     * avoid false positives on legitimate small businesses.
     */
    private static function has_suspicious_domain($email) {
        if ($email === '' || strpos($email, '@') === false) {
            return false;
        }
        $domain = strtolower(trim(substr($email, strpos($email, '@') + 1)));
        $parts  = explode('.', $domain);
        if (count($parts) < 2) {
            return false;
        }
        $tld = end($parts);
        $sld = $parts[count($parts) - 2];
        $throwaway_tlds = array(
            'site', 'online', 'xyz', 'click', 'top', 'fun', 'cyou',
            'monster', 'rest', 'work', 'space', 'website', 'icu',
            'live', 'shop', 'store', 'buzz', 'lol',
        );
        if (!in_array($tld, $throwaway_tlds, true)) {
            return false;
        }
        return self::looks_like_random_string($sld);
    }

    /**
     * Heuristic: returns true if the string looks like a bot-generated
     * random identifier rather than a human name or email-derived
     * username. Three independent signals — any one fires a hit:
     *
     *   1. CASE THRASH — ≥4 case transitions in a long alpha-only
     *      string (catches `KcIIFSLaHgonfglOrGeuar`,
     *      `LkAXxlVzLjaFkOqEHuiqyJq`).
     *
     *   2. MULTIPLE CONSONANT RUNS — ≥2 consonant runs of length ≥3
     *      in a long alpha-only string (catches lowercased gibberish
     *      like `kciifslahgonfglorgeuar` which has runs `fsl`, `nfgl`,
     *      and slips past the case-transition rule).
     *
     *   3. EXTREME CONSONANT RUN — any single consonant run of
     *      length ≥6 (catches all-consonant strings like
     *      `qytcnxcsjfykvcsyb`).
     *
     * Tolerated: any string <12 chars, anything containing
     * separators (`. - _ @ + space digit`), and the typical English
     * vowel/consonant cadence found in real names (`jordanhalpern`,
     * `sharonkumarus`, `christopherson`).
     */
    private static function looks_like_random_string($s) {
        if ($s === '') {
            return false;
        }
        $len = strlen($s);
        if (preg_match('/[\s@.\-_+0-9]/', $s)) {
            return false;
        }
        if ($len < 12) {
            return false;
        }

        // Signal 1 — case-transition density.
        $transitions = 0;
        for ($i = 1; $i < $len; $i++) {
            $a = $s[$i - 1];
            $b = $s[$i];
            if (ctype_alpha($a) && ctype_alpha($b)) {
                if (ctype_upper($a) !== ctype_upper($b)) {
                    $transitions++;
                }
            }
        }
        if ($transitions >= 4) {
            return true;
        }

        // Signals 2 + 3 — consonant-run analysis. y is treated as a
        // consonant here because in random strings it shows up as a
        // separator-replacement, not as a vowel surrogate.
        $long_runs = 0;
        $max_run   = 0;
        $current   = 0;
        $vowels    = 'aeiouAEIOU';
        for ($i = 0; $i < $len; $i++) {
            if (strpos($vowels, $s[$i]) === false) {
                $current++;
                if ($current > $max_run) {
                    $max_run = $current;
                }
            } else {
                if ($current >= 3) {
                    $long_runs++;
                }
                $current = 0;
            }
        }
        if ($current >= 3) {
            $long_runs++;
        }
        if ($long_runs >= 2) {
            return true;
        }
        if ($max_run >= 6) {
            return true;
        }

        return false;
    }

    /**
     * Subset of widely-used disposable email providers. Not exhaustive —
     * just enough to block the common spam wave. Kept narrow on purpose
     * (false-positive risk for parents using legitimate aliases is real).
     */
    private static function is_disposable_email_domain($email) {
        if ($email === '' || strpos($email, '@') === false) {
            return false;
        }
        $domain = strtolower(trim(substr($email, strpos($email, '@') + 1)));
        $blocked = array(
            'mailinator.com', 'guerrillamail.com', 'guerrillamail.net',
            'yopmail.com', 'tempmail.com', 'temp-mail.org', 'dispostable.com',
            '10minutemail.com', '10minutemail.net', 'sharklasers.com',
            'trashmail.com', 'getnada.com', 'maildrop.cc', 'fakeinbox.com',
            'spamgourmet.com', 'mintemail.com', 'mailcatch.com',
            'throwawaymail.com', 'tempinbox.com', 'mvrht.net',
        );
        return in_array($domain, $blocked, true);
    }

    /**
     * Best-effort log of a rejected signup attempt. Falls back silently
     * if Azure_Logger isn't loaded.
     */
    private function log_rejection($source, $username, $email, $name, $reason) {
        if (!class_exists('Azure_Logger')) {
            return;
        }
        Azure_Logger::info(
            sprintf('Anti-spam rejected %s: %s [%s] (login=%s, name=%s)',
                $source, $reason, $email, $username, $name),
            array('module' => 'AntiSpam')
        );
    }
}
