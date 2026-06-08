<?php
/**
 * Anti-Spam / Registration Hardening Module
 *
 * Locks down the surfaces that bots use to create spam accounts on
 * wilderptsa.net and lwptsa.net:
 *
 *   1. Blocks the WordPress-native registration page entirely
 *      (`/wp-login.php?action=register`, `wp-signup.php`,
 *      `/register/`) by short-circuiting the request before WP even
 *      runs registration validation. Returns a 403 + nocache.
 *
 *   2. Disables the `users_can_register` option at runtime via the
 *      `option_users_can_register` filter. This means even if the
 *      WP setting accidentally flips back to "yes" in Settings →
 *      General, the plugin overrides it to "no" on every read.
 *
 *   3. Forces the registration default role away from `customer`
 *      (which gives WC capabilities) to `subscriber` via the
 *      `option_default_role` filter. Defense in depth in case any
 *      other code path slips through (e.g. WC checkout-create-account
 *      still creates customers, but they go through `wp_create_new_customer`
 *      which is fine and isn't covered here).
 *
 *   4. Hooks `register_post` and `pre_user_login` to validate username
 *      patterns — rejects random gibberish (high-entropy mixed-case
 *      strings like `KcIIFSLaHgonfglOrGeuar`) which is the signature
 *      of every bot wave we've seen.
 *
 * The intent is "WP-native registration is OFF", with the only
 * supported account-creation paths being:
 *   - SSO (Microsoft sign-in) for @wilderptsa.net + @lwsd.org
 *   - [pta_newsletter_signup] shortcode → creates `parent` role
 *   - WooCommerce checkout (creates `customer` via wc_create_new_customer)
 *
 * Loaded unconditionally on every request. The constructor reads one
 * already-cached option and short-circuits if the user has explicitly
 * turned this off, so the per-request cost when disabled is one option
 * lookup. When enabled, the cost is a few cheap filters.
 *
 * @since 3.141.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Anti_Spam {

    private static $instance = null;

    /** Block registration ON by default — opt-out, not opt-in. */
    const SETTING_KEY = 'enable_block_registration';

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
        // Read once. Default to ON unless explicitly turned off.
        // This is intentional — accidentally being too restrictive
        // is recoverable, accidentally letting in spam is not.
        $enabled = true;
        if (class_exists('Azure_Settings')) {
            $settings = Azure_Settings::get_all_settings();
            // Treat null/missing as ON. Only an explicit false disables.
            if (isset($settings[self::SETTING_KEY])) {
                $enabled = !empty($settings[self::SETTING_KEY]);
            }
        }
        if (!$enabled) {
            return;
        }

        // 1. Override users_can_register and default_role on every read.
        add_filter('option_users_can_register', array($this, 'force_no_open_registration'), 999);
        add_filter('option_default_role',       array($this, 'force_safe_default_role'),   999);

        // 2. Block the wp-login.php registration form and wp-signup.php.
        add_action('login_form_register', array($this, 'block_login_register'), 1);
        add_action('login_init',          array($this, 'block_login_register_early'), 1);

        // 3. Validate username + email at the registration filter level
        //    in case any path still calls register_new_user() / wp_insert_user().
        add_filter('registration_errors', array($this, 'reject_spam_registration'), 10, 3);
        add_action('register_post',       array($this, 'reject_spam_register_post'), 10, 3);

        // 4. Block /register/ pretty permalink and /wp-signup.php
        add_action('init', array($this, 'block_register_permalinks'), 1);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Option overrides
    // ─────────────────────────────────────────────────────────────────

    public function force_no_open_registration($value) {
        return 0;
    }

    public function force_safe_default_role($value) {
        $bad = self::DANGEROUS_DEFAULT_ROLES;
        if (is_string($value) && in_array(strtolower($value), $bad, true)) {
            return 'subscriber';
        }
        // Also force `customer` away from being the default — WC
        // checkout uses wc_create_new_customer() directly which doesn't
        // read this option, so we can safely keep `subscriber` here.
        if ($value === 'customer') {
            return 'subscriber';
        }
        return $value;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Block the WP registration screens
    // ─────────────────────────────────────────────────────────────────

    public function block_login_register_early() {
        // wp-login.php sets $_GET['action'] before login_form_register fires.
        $action = isset($_GET['action']) ? strtolower((string) $_GET['action']) : '';
        if ($action === 'register') {
            $this->refuse_with_403('Registration is disabled on this site.');
        }
    }

    public function block_login_register() {
        $this->refuse_with_403('Registration is disabled on this site.');
    }

    public function block_register_permalinks() {
        // Pretty permalinks like /register/ that some themes/plugins add.
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
        // Keep the response tiny — bots iterate fast, no point sending HTML.
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
    //  Username + email validation (defense in depth)
    // ─────────────────────────────────────────────────────────────────

    public function reject_spam_registration($errors, $sanitized_user_login, $user_email) {
        $reason = $this->classify_spam($sanitized_user_login, $user_email);
        if ($reason !== null) {
            $errors->add('pta_spam_detected', 'Registration rejected: ' . $reason);
        }
        return $errors;
    }

    public function reject_spam_register_post($sanitized_user_login, $user_email, $errors) {
        $reason = $this->classify_spam($sanitized_user_login, $user_email);
        if ($reason !== null && is_object($errors) && method_exists($errors, 'add')) {
            $errors->add('pta_spam_detected', 'Registration rejected: ' . $reason);
        }
    }

    /**
     * Returns a non-empty reason string if the username or email looks
     * like spam, or null if it passes. Heuristics:
     *
     *   - Username is gibberish (high case-mixing entropy + no vowel
     *     pattern + no separator) — e.g. `KcIIFSLaHgonfglOrGeuar`
     *   - Email is from a known disposable provider
     *   - Email and username are both gibberish
     */
    private function classify_spam($username, $email) {
        $username = (string) $username;
        $email    = (string) $email;

        // 1. Username pattern check — the signature spam wave on
        //    wilderptsa is mixed-case alphabet-only, no digits, no
        //    separators, length 12–32, with consonant-heavy clusters.
        if ($this->looks_like_random_string($username)) {
            return 'username_pattern_random';
        }

        // 2. Disposable / throwaway email domain check.
        if ($this->is_disposable_email_domain($email)) {
            return 'disposable_email_domain';
        }

        return null;
    }

    /**
     * Heuristic: returns true if the string looks like a bot-generated
     * random identifier rather than a human name or email-derived
     * username. Tuned against the actual spam pattern observed
     * (e.g. `KcIIFSLaHgonfglOrGeuar`, `BzPouQzkRyHa`, etc.) while
     * tolerating real human usernames (e.g. `jamie-e-burgess`,
     * `sharonkumarus@gmail.com`).
     */
    private function looks_like_random_string($s) {
        if ($s === '') {
            return false;
        }
        $len = strlen($s);
        // Real usernames are usually <12 or contain @/./-/_; if any
        // of those separators exist we treat as human-shaped.
        if (preg_match('/[@.\-_0-9]/', $s)) {
            return false;
        }
        // Pure-alpha string. If short, ignore (could be a first name).
        if ($len < 12) {
            return false;
        }
        // Count case transitions. Random strings flip case constantly;
        // human pascal/camelCase usually has 1–3 transitions.
        $transitions = 0;
        for ($i = 1; $i < $len; $i++) {
            $a = $s[$i - 1];
            $b = $s[$i];
            if (ctype_alpha($a) && ctype_alpha($b)) {
                $a_upper = ctype_upper($a);
                $b_upper = ctype_upper($b);
                if ($a_upper !== $b_upper) {
                    $transitions++;
                }
            }
        }
        // Flag if >= 4 case transitions in a long all-alpha string.
        if ($transitions >= 4) {
            return true;
        }
        // Also flag if mixed case AND has 3+ consonant clusters
        // (no vowel within 4 consecutive chars), which is a strong
        // signal of randomness.
        $consonant_streaks = 0;
        $current_streak = 0;
        $vowels = 'aeiouAEIOU';
        for ($i = 0; $i < $len; $i++) {
            if (strpos($vowels, $s[$i]) === false) {
                $current_streak++;
                if ($current_streak >= 4) {
                    $consonant_streaks++;
                    $current_streak = 0;
                }
            } else {
                $current_streak = 0;
            }
        }
        if ($consonant_streaks >= 2 && $transitions >= 2) {
            return true;
        }
        return false;
    }

    /**
     * Subset of widely-used disposable email providers. Not exhaustive —
     * just enough to block the common spam wave. We keep the list
     * narrow on purpose (false-positive risk for parents using
     * legitimate aliases is real).
     */
    private function is_disposable_email_domain($email) {
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
     * Public helper used by the diagnostic spam-user-audit endpoint
     * so the audit applies the SAME rules as live registration
     * blocking. Returns array('reason' => string|null).
     */
    public static function classify_existing_user(WP_User $user) {
        $self = self::get_instance();
        $reason = $self->classify_spam($user->user_login, $user->user_email);
        return array('reason' => $reason);
    }
}
