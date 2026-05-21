<?php
/**
 * Newsletter Signup Shortcode + Public REST Endpoint
 *
 * Replaces the AcyMailing subscribe form on the homepage. A self-contained
 * surface that takes a name + email, applies the same bucketing as the
 * AcyMailing migrator and subscribes the visitor to the appropriate
 * newsletter list:
 *
 *   - @<school_staff_domain> → polite ack only; staff are imported manually
 *                              into the school_staff WP role from a CSV
 *   - @<sso_org_domain>      → polite ack only; Microsoft SSO creates the
 *                              account with the correct role on first sign-in
 *   - existing WP user      → ensure parent role (Parents list is role-bound,
 *                             so subscription is automatic)
 *   - new email             → create parent user (login disabled, magic-link
 *                             token issued, welcome email sent)
 *
 * Resource policy:
 *   - The shortcode handler is registered on init only; the heavy work is
 *     in the REST POST so the page render itself is just markup + tiny JS.
 *   - Inline assets (no external CSS/JS) so we don't enqueue on pages that
 *     never use the shortcode. Render cost when the shortcode IS used is
 *     ~2 KB of inline HTML/CSS/JS.
 *   - Rate-limited at the REST layer (5 submissions / IP / hour) via a
 *     short-lived transient — protects against bots without burning
 *     options-table writes on every render.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Azure_Parent_Migration')) {
    require_once AZURE_PLUGIN_PATH . 'includes/class-parent-migration.php';
}

class Azure_Newsletter_Signup_Shortcode {

    const SHORTCODE     = 'pta_newsletter_signup';
    const REST_NAMESPACE = 'pta-tools/v1';
    const REST_ROUTE    = '/newsletter/signup';
    const RATE_LIMIT_PER_HOUR = 5;
    const RATE_LIMIT_WINDOW   = HOUR_IN_SECONDS;

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode(self::SHORTCODE, array($this, 'render_shortcode'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    // ─────────────────────────────────────────────────────────────────
    //  Shortcode
    // ─────────────────────────────────────────────────────────────────

    public function render_shortcode($atts = array(), $content = null, $tag = '') {
        $atts = shortcode_atts(array(
            'heading'        => __('Subscribe to the Newsletter', 'azure-plugin'),
            'button_text'    => __('Subscribe', 'azure-plugin'),
            'show_terms'     => 'yes',
            'terms_url'      => '',
            'privacy_url'    => get_privacy_policy_url(),
            'redirect_after' => '', // optional URL to navigate to on success
            'compact'        => 'no',
        ), is_array($atts) ? $atts : array(), $tag);

        $endpoint = esc_url_raw(rest_url(self::REST_NAMESPACE . self::REST_ROUTE));
        $nonce = wp_create_nonce('wp_rest'); // standard REST nonce, helps with logged-in submissions
        $unique = 'pta-nl-' . wp_generate_password(8, false, false);
        $heading = esc_html($atts['heading']);
        $button = esc_html($atts['button_text']);
        $compact = ($atts['compact'] === 'yes');
        $terms_html = '';
        if ($atts['show_terms'] === 'yes') {
            $terms = $atts['terms_url'] ? sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url($atts['terms_url']), esc_html__('Terms and conditions', 'azure-plugin')) : esc_html__('the Terms and conditions', 'azure-plugin');
            $privacy = $atts['privacy_url'] ? sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url($atts['privacy_url']), esc_html__('Privacy policy', 'azure-plugin')) : esc_html__('the Privacy policy', 'azure-plugin');
            $terms_html = sprintf(
                '<label class="pta-nl__terms"><input type="checkbox" name="agree" required> %s</label>',
                sprintf(
                    /* translators: %1$s terms link, %2$s privacy link */
                    esc_html__('I agree with the %1$s and the %2$s', 'azure-plugin'),
                    $terms,
                    $privacy
                )
            );
        }

        ob_start();
        ?>
<div class="pta-nl <?php echo $compact ? 'pta-nl--compact' : ''; ?>" id="<?php echo esc_attr($unique); ?>">
    <?php if ($heading !== ''): ?><h3 class="pta-nl__heading"><?php echo $heading; ?></h3><?php endif; ?>
    <form class="pta-nl__form" novalidate>
        <input type="text" class="pta-nl__name" name="name" placeholder="<?php esc_attr_e('Name', 'azure-plugin'); ?>" autocomplete="name" required>
        <input type="email" class="pta-nl__email" name="email" placeholder="<?php esc_attr_e('Email', 'azure-plugin'); ?>" autocomplete="email" required>
        <?php // Honeypot — hidden from real users, bots tend to fill in every field. ?>
        <input type="text" class="pta-nl__hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
        <?php echo $terms_html; ?>
        <button type="submit" class="pta-nl__submit"><?php echo $button; ?></button>
        <div class="pta-nl__msg" role="status" aria-live="polite"></div>
    </form>
</div>
<style>
.pta-nl { max-width: 100%; }
.pta-nl__heading { margin: 0 0 12px 0; font-size: 18px; font-weight: 600; }
.pta-nl__form { display: flex; flex-direction: column; gap: 8px; }
.pta-nl__form input[type="text"],
.pta-nl__form input[type="email"] { padding: 10px 12px; font-size: 14px; border: 1px solid #d0d0d0; border-radius: 4px; background: #fff; }
.pta-nl__hp { position: absolute !important; left: -9999px !important; height: 1px !important; width: 1px !important; opacity: 0 !important; pointer-events: none !important; }
.pta-nl__terms { font-size: 12px; color: #555; line-height: 1.4; display: flex; gap: 6px; align-items: flex-start; }
.pta-nl__terms input { margin-top: 3px; }
.pta-nl__terms a { color: #0073aa; text-decoration: underline; }
.pta-nl__submit { padding: 10px 20px; background: #2271b1; color: #fff; border: 0; border-radius: 4px; font-weight: 600; cursor: pointer; font-size: 14px; }
.pta-nl__submit:hover:not(:disabled) { background: #135e96; }
.pta-nl__submit:disabled { opacity: 0.6; cursor: not-allowed; }
.pta-nl__msg { font-size: 13px; line-height: 1.4; min-height: 1em; }
.pta-nl__msg.is-error { color: #b32d2e; }
.pta-nl__msg.is-ok { color: #00a32a; }
.pta-nl--compact .pta-nl__heading { font-size: 14px; }
.pta-nl--compact .pta-nl__form input,
.pta-nl--compact .pta-nl__submit { padding: 8px 10px; font-size: 13px; }
</style>
<script>
(function(){
    var root = document.getElementById(<?php echo wp_json_encode($unique); ?>);
    if (!root) return;
    var form  = root.querySelector('.pta-nl__form');
    var msg   = root.querySelector('.pta-nl__msg');
    var btn   = root.querySelector('.pta-nl__submit');
    var endpoint = <?php echo wp_json_encode($endpoint); ?>;
    var nonce = <?php echo wp_json_encode($nonce); ?>;
    var redirect = <?php echo wp_json_encode($atts['redirect_after']); ?>;
    form.addEventListener('submit', function(ev){
        ev.preventDefault();
        msg.className = 'pta-nl__msg';
        msg.textContent = '';
        var fd = new FormData(form);
        var payload = {
            name: fd.get('name') || '',
            email: fd.get('email') || '',
            agree: fd.get('agree') ? 1 : 0,
            website: fd.get('website') || ''
        };
        if (!payload.email) {
            msg.className = 'pta-nl__msg is-error';
            msg.textContent = <?php echo wp_json_encode(__('Please enter your email address.', 'azure-plugin')); ?>;
            return;
        }
        btn.disabled = true;
        btn.dataset._t = btn.textContent;
        btn.textContent = <?php echo wp_json_encode(__('Working…', 'azure-plugin')); ?>;
        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            body: JSON.stringify(payload),
            credentials: 'same-origin'
        }).then(function(r){ return r.json().then(function(j){ return { ok: r.ok, status: r.status, body: j }; }); })
          .then(function(res){
                if (res.ok && res.body && res.body.success) {
                    msg.className = 'pta-nl__msg is-ok';
                    msg.textContent = res.body.message || <?php echo wp_json_encode(__('Thanks! Check your email.', 'azure-plugin')); ?>;
                    form.reset();
                    if (redirect) {
                        setTimeout(function(){ window.location.href = redirect; }, 1500);
                    }
                } else {
                    msg.className = 'pta-nl__msg is-error';
                    var emsg = (res.body && (res.body.message || res.body.error)) || <?php echo wp_json_encode(__('Something went wrong. Please try again later.', 'azure-plugin')); ?>;
                    msg.textContent = emsg;
                }
            }).catch(function(){
                msg.className = 'pta-nl__msg is-error';
                msg.textContent = <?php echo wp_json_encode(__('Network error. Please try again.', 'azure-plugin')); ?>;
            }).then(function(){
                btn.disabled = false;
                btn.textContent = btn.dataset._t || <?php echo wp_json_encode($atts['button_text']); ?>;
            });
    });
})();
</script>
        <?php
        return ob_get_clean();
    }

    // ─────────────────────────────────────────────────────────────────
    //  REST
    // ─────────────────────────────────────────────────────────────────

    public function register_rest_routes() {
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_signup'),
            'permission_callback' => '__return_true',
        ));
    }

    public function rest_signup($request) {
        // 1. Honeypot — silent reject.
        $hp = (string) $request->get_param('website');
        if ($hp !== '') {
            return rest_ensure_response(array(
                'success' => true,
                'message' => __('Thanks! Check your email.', 'azure-plugin'),
            ));
        }

        // 2. Rate limit per IP.
        $ip = $this->client_ip();
        $rate_key = 'pta_nl_rate_' . md5($ip);
        $count = (int) get_transient($rate_key);
        if ($count >= self::RATE_LIMIT_PER_HOUR) {
            return new WP_Error(
                'rate_limited',
                __('Too many sign-up attempts from this network. Please try again later.', 'azure-plugin'),
                array('status' => 429)
            );
        }
        set_transient($rate_key, $count + 1, self::RATE_LIMIT_WINDOW);

        // 3. Validate input.
        $email = strtolower(trim((string) $request->get_param('email')));
        $name  = sanitize_text_field((string) $request->get_param('name'));
        $agree = (bool) (int) $request->get_param('agree');
        if (!$email || !is_email($email)) {
            return new WP_Error('invalid_email', __('Please enter a valid email address.', 'azure-plugin'), array('status' => 400));
        }
        // Soft-require terms agreement only if the form sent the field.
        // We don't fail when `agree` is null (form variants without the
        // checkbox should still work), but if it was sent and false, reject.
        if ($request->get_param('agree') !== null && !$agree) {
            return new WP_Error('agree_required', __('Please accept the terms to continue.', 'azure-plugin'), array('status' => 400));
        }

        return rest_ensure_response($this->process_signup($email, $name));
    }

    /**
     * Apply bucketing + side effects. Returns a response array suitable
     * for rest_ensure_response().
     */
    private function process_signup($email, $name) {
        $domain = substr($email, strrpos($email, '@') + 1);
        $school = Azure_Parent_Migration::get_school_staff_domain();
        $sso    = Azure_Parent_Migration::get_sso_org_domain();
        $existing = get_user_by('email', $email);

        // Bucket A: existing WP user — just ensure parent role. Membership
        // in the role-bound Parents list is automatic.
        if ($existing) {
            Azure_Parent_Migration::attach_existing_to_parent($existing->ID);
            return array(
                'success'  => true,
                'message'  => __("You're already on file — we've added you to the newsletter list.", 'azure-plugin'),
                'requires_activation' => false,
            );
        }

        // Bucket B: school staff (configured school_staff_domain). The
        // admin imports staff separately into the school_staff role from
        // a CSV. We acknowledge the signup but don't create an account
        // or list membership here.
        if ($school && $domain === $school) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::info('Newsletter signup deferred (school staff): ' . $email, array('module' => 'NewsletterSignup'));
            }
            return array(
                'success'  => true,
                'message'  => __("Thanks — staff requests are handled by the PTSA office. We'll be in touch from your school email.", 'azure-plugin'),
                'requires_activation' => false,
            );
        }

        // Bucket C: PTSA org_domain — defer to Microsoft SSO. Creating
        // a WP account from email risks the wrong role; SSO will
        // provision it correctly on first sign-in.
        if ($sso && $domain === $sso) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::info('Newsletter signup deferred (SSO domain): ' . $email, array('module' => 'NewsletterSignup'));
            }
            return array(
                'success'  => true,
                'message'  => __("That's a PTSA org email — please sign in with your Microsoft account at the top of the page. Your access will be set up automatically.", 'azure-plugin'),
                'requires_activation' => false,
            );
        }

        // Bucket D: brand new email → create parent + magic link + welcome.
        $created = Azure_Parent_Migration::create_parent_user(
            $email,
            $name,
            'self_signup'
        );
        if (is_wp_error($created)) {
            if ($created->get_error_code() === 'user_exists') {
                return array(
                    'success' => true,
                    'message' => __("You're already on file — we've added you to the newsletter list.", 'azure-plugin'),
                    'requires_activation' => false,
                );
            }
            return array(
                'success' => false,
                'message' => $created->get_error_message(),
            );
        }
        $uid = (int) $created;

        // Send welcome email. The send is best-effort; if it fails we still
        // return success because the user was created and will be picked up
        // by the bulk welcome blast tool later.
        $send_result = Azure_Parent_Migration::send_welcome_email($uid, 'wp_mail');
        $sent = !is_wp_error($send_result);

        if (class_exists('Azure_Logger')) {
            Azure_Logger::info(sprintf(
                'Self-signup created parent user_id=%d email=%s welcome_sent=%s',
                $uid,
                $email,
                $sent ? 'yes' : 'no'
            ), array('module' => 'NewsletterSignup'));
        }

        return array(
            'success'  => true,
            'message'  => $sent
                ? __("Thanks! We've emailed you a link to activate your account and finish subscribing.", 'azure-plugin')
                : __("Thanks! You're on the list. We'll send your account activation email shortly.", 'azure-plugin'),
            'requires_activation' => true,
        );
    }

    private function client_ip() {
        $candidates = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR');
        foreach ($candidates as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', (string) $_SERVER[$k])[0]);
                if ($ip) {
                    return $ip;
                }
            }
        }
        return 'unknown';
    }
}
