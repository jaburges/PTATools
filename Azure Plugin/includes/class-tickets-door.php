<?php
/**
 * Door PINs: volunteers without a board sign-in scan tickets for one
 * performance by typing that performance's PIN into the PTSA Board app.
 *
 * The PIN is exchanged for a signed door token that only works for that
 * performance and only until the night is over. Changing or clearing the
 * PIN on the website ends every session that used the old one.
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Tickets_Door {

    const META_PIN = '_ticket_door_pin';
    const PIN_MIN = 6;
    const PIN_MAX = 8;

    /** A PIN is accepted from this long before the performance starts... */
    const OPENS_BEFORE = 12 * HOUR_IN_SECONDS;
    /** ...and its sessions last until this long after the start. */
    const NIGHT_LENGTH = 8 * HOUR_IN_SECONDS;

    /** Wrong PINs allowed per address, and for the whole site, per window. */
    const FAILURES_PER_ADDRESS = 10;
    const FAILURES_SITE_WIDE = 50;
    const FAILURE_WINDOW = 15 * MINUTE_IN_SECONDS;

    /** Digits only, PIN_MIN to PIN_MAX of them, or '' (no volunteer scanning). */
    public static function clean_pin($raw) {
        $pin = preg_replace('/\D/', '', (string) $raw);
        $len = strlen($pin);
        return ($len >= self::PIN_MIN && $len <= self::PIN_MAX) ? $pin : '';
    }

    public static function pin($performance_id) {
        return self::clean_pin(get_post_meta((int) $performance_id, self::META_PIN, true));
    }

    /**
     * @return array{0:int,1:int}|null When the PIN is accepted, as [opens, closes]; null without a performance date.
     */
    public static function window($performance_id) {
        $start = Azure_Tickets_Config::event_ts($performance_id);
        return $start ? array($start - self::OPENS_BEFORE, $start + self::NIGHT_LENGTH) : null;
    }

    /**
     * The performance a PIN opens right now: among $candidates, one whose PIN
     * matches and whose window includes now. If two share a PIN, the one
     * starting nearest now wins.
     *
     * @param int[] $candidates
     * @return int 0 when none.
     */
    public static function performance_for_pin($pin, array $candidates) {
        $pin = self::clean_pin($pin);
        if ($pin === '') {
            return 0;
        }
        $now = Azure_Tickets_Config::now();
        $best = 0;
        $best_gap = PHP_INT_MAX;
        foreach ($candidates as $id) {
            $window = self::window($id);
            $own = self::pin($id);
            if (!$window || $own === '' || !hash_equals($own, $pin) || $now < $window[0] || $now > $window[1]) {
                continue;
            }
            $gap = abs(Azure_Tickets_Config::event_ts($id) - $now);
            if ($gap < $best_gap) {
                $best = (int) $id;
                $best_gap = $gap;
            }
        }
        return $best;
    }

    /**
     * A door token for the performance, valid until its window closes.
     *
     * @return array{token:string,expires_at:int}|null
     */
    public static function issue($performance_id) {
        $window = self::window($performance_id);
        $pin = self::pin($performance_id);
        if (!$window || $pin === '') {
            return null;
        }
        $body = self::b64(json_encode(array('p' => (int) $performance_id, 'exp' => $window[1])));
        return array('token' => $body . '.' . self::sign($body, $pin), 'expires_at' => $window[1]);
    }

    /**
     * @return int The performance the token is for, or 0 if it is forged,
     *             expired, or its PIN has since been changed or cleared.
     */
    public static function verify($token) {
        $parts = explode('.', (string) $token);
        if (count($parts) !== 2) {
            return 0;
        }
        $claims = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true);
        $id = (int) ($claims['p'] ?? 0);
        $exp = (int) ($claims['exp'] ?? 0);
        $pin = $id ? self::pin($id) : '';
        if ($pin === '' || $exp < Azure_Tickets_Config::now() || !hash_equals(self::sign($parts[0], $pin), $parts[1])) {
            return 0;
        }
        return $id;
    }

    /** Whether this address, or the site as a whole, has had too many wrong PINs lately. */
    public static function locked_out() {
        return (int) get_transient(self::bucket(self::client_ip())) >= self::FAILURES_PER_ADDRESS
            || (int) get_transient(self::bucket('*')) >= self::FAILURES_SITE_WIDE;
    }

    public static function record_failure() {
        foreach (array(self::client_ip(), '*') as $who) {
            $key = self::bucket($who);
            set_transient($key, (int) get_transient($key) + 1, self::FAILURE_WINDOW);
        }
    }

    private static function bucket($who) {
        return 'pta_door_fail_' . md5($who . '|' . (int) floor(time() / self::FAILURE_WINDOW));
    }

    private static function client_ip() {
        foreach (array('HTTP_X_AZURE_CLIENTIP', 'REMOTE_ADDR') as $key) {
            if (!empty($_SERVER[$key]) && filter_var($_SERVER[$key], FILTER_VALIDATE_IP)) {
                return (string) $_SERVER[$key];
            }
        }
        return '';
    }

    private static function sign($body, $pin) {
        return self::b64(hash_hmac('sha256', $body, wp_salt('auth') . '|door|' . $pin, true));
    }

    private static function b64($bytes) {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
