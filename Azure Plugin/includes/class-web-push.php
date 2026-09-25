<?php
/**
 * Web Push (VAPID + aes128gcm) without a Composer dependency.
 *
 * The payload format follows RFC 8291. A browser that has allowed
 * notifications can decrypt what send() posts to its push service.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Web_Push {

    const CURVE = 'prime256v1';

    /**
     * @return array{public:string,private:string}|null public is base64url, private is PEM
     */
    public static function generate_vapid_keys() {
        $key = openssl_pkey_new(array(
            'curve_name' => self::CURVE,
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ));
        if (!$key) {
            return null;
        }
        $private = '';
        openssl_pkey_export($key, $private);
        $raw = self::public_raw_from_key($key);
        if ($raw === '' || $private === '') {
            return null;
        }
        return array(
            'public' => self::b64url($raw),
            'private' => $private,
        );
    }

    /**
     * @param string $endpoint Push service URL.
     * @param string $public_b64 VAPID public key, base64url.
     * @param string $private_pem VAPID private key PEM.
     * @param string $subject mailto: contact.
     * @return string
     */
    public static function vapid_authorization($endpoint, $public_b64, $private_pem, $subject) {
        $parts = parse_url($endpoint);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $aud = $parts['scheme'] . '://' . $parts['host'];
        $header = self::b64url(wp_json_encode(array('typ' => 'JWT', 'alg' => 'ES256')));
        $payload = self::b64url(wp_json_encode(array(
            'aud' => $aud,
            'exp' => time() + 12 * HOUR_IN_SECONDS,
            'sub' => $subject,
        )));
        $signing = $header . '.' . $payload;
        $key = openssl_pkey_get_private($private_pem);
        if (!$key) {
            return '';
        }
        $der = '';
        if (!openssl_sign($signing, $der, $key, OPENSSL_ALGO_SHA256)) {
            return '';
        }
        $raw = self::ecdsa_der_to_raw($der);
        if (strlen($raw) !== 64) {
            return '';
        }
        return 'vapid t=' . $signing . '.' . self::b64url($raw) . ',k=' . $public_b64;
    }

    /**
     * Encrypt a payload for one subscription.
     *
     * @param string $plaintext
     * @param string $ua_public_raw 65-byte uncompressed P-256 point.
     * @param string $auth_raw 16-byte authentication secret.
     * @param mixed  $as_private Application server ephemeral private key.
     * @return string
     */
    public static function encrypt_payload($plaintext, $ua_public_raw, $auth_raw, $as_private) {
        $as_public = self::public_raw_from_key($as_private);
        if (strlen($as_public) !== 65 || strlen($ua_public_raw) !== 65 || strlen($auth_raw) !== 16) {
            return '';
        }
        $shared = openssl_pkey_derive(self::raw_public_to_pem($ua_public_raw), $as_private);
        if (!is_string($shared) || $shared === '') {
            return '';
        }
        $shared = str_pad(ltrim($shared, "\x00"), 32, "\x00", STR_PAD_LEFT);
        if (strlen($shared) > 32) {
            $shared = substr($shared, -32);
        }
        $key_info = "WebPush: info\x00" . $ua_public_raw . $as_public;
        $ikm = hash_hkdf('sha256', $shared, 32, $key_info, $auth_raw);
        $salt = random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);
        $padded = $plaintext . "\x02";
        $tag = '';
        $cipher = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if (!is_string($cipher) || strlen($tag) !== 16) {
            return '';
        }
        return $salt . pack('N', 4096) . chr(65) . $as_public . $cipher . $tag;
    }

    /**
     * @param string $record
     * @param mixed  $ua_private User agent private key.
     * @param string $auth_raw
     * @return string
     */
    public static function decrypt_payload($record, $ua_private, $auth_raw) {
        if (strlen($record) < 16 + 4 + 1 + 65 + 16) {
            return '';
        }
        $salt = substr($record, 0, 16);
        $idlen = ord($record[20]);
        $as_public = substr($record, 21, $idlen);
        $cipher_and_tag = substr($record, 21 + $idlen);
        if (strlen($as_public) !== 65 || strlen($cipher_and_tag) < 17) {
            return '';
        }
        $ua_public = self::public_raw_from_key($ua_private);
        $shared = openssl_pkey_derive(self::raw_public_to_pem($as_public), $ua_private);
        if (!is_string($shared) || $shared === '') {
            return '';
        }
        $shared = str_pad(ltrim($shared, "\x00"), 32, "\x00", STR_PAD_LEFT);
        if (strlen($shared) > 32) {
            $shared = substr($shared, -32);
        }
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\x00" . $ua_public . $as_public, $auth_raw);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);
        $tag = substr($cipher_and_tag, -16);
        $cipher = substr($cipher_and_tag, 0, -16);
        $plain = openssl_decrypt($cipher, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '');
        if (!is_string($plain)) {
            return '';
        }
        $mark = strrpos($plain, "\x02");
        if ($mark === false) {
            return '';
        }
        return substr($plain, 0, $mark);
    }

    /**
     * @param string $endpoint
     * @param string $payload_json
     * @param string $ua_public_b64
     * @param string $auth_b64
     * @param string $vapid_public
     * @param string $vapid_private
     * @param string $subject
     * @return array{ok:bool,code:int,error:string}
     */
    public static function send($endpoint, $payload_json, $ua_public_b64, $auth_b64, $vapid_public, $vapid_private, $subject) {
        $as = openssl_pkey_new(array(
            'curve_name' => self::CURVE,
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ));
        $ua_raw = self::b64url_decode($ua_public_b64);
        $auth_raw = self::b64url_decode($auth_b64);
        if (!$as) {
            return array('ok' => false, 'code' => 0, 'error' => 'key');
        }
        $body = self::encrypt_payload($payload_json, $ua_raw, $auth_raw, $as);
        $authz = self::vapid_authorization($endpoint, $vapid_public, $vapid_private, $subject);
        if ($body === '' || $authz === '') {
            return array('ok' => false, 'code' => 0, 'error' => 'encrypt');
        }
        $response = wp_remote_post($endpoint, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => $authz,
                'Content-Type' => 'application/octet-stream',
                'Content-Encoding' => 'aes128gcm',
                'TTL' => '86400',
            ),
            'body' => $body,
        ));
        if (is_wp_error($response)) {
            return array('ok' => false, 'code' => 0, 'error' => $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        return array(
            'ok' => $code >= 200 && $code < 300,
            'code' => $code,
            'error' => $code >= 200 && $code < 300 ? '' : wp_remote_retrieve_body($response),
        );
    }

    public static function b64url($bin) {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64url_decode($text) {
        $text = strtr((string) $text, '-_', '+/');
        $pad = strlen($text) % 4;
        if ($pad) {
            $text .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode($text, true);
        return is_string($raw) ? $raw : '';
    }

    public static function public_raw_from_key($key) {
        $details = openssl_pkey_get_details($key);
        if (empty($details['ec']['x']) || empty($details['ec']['y'])) {
            return '';
        }
        return "\x04"
            . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    }

    public static function raw_public_to_pem($raw) {
        $prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
        $der = $prefix . $raw;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    public static function ecdsa_der_to_raw($der) {
        $der = (string) $der;
        $len = strlen($der);
        if ($len < 8 || ord($der[0]) !== 0x30) {
            return '';
        }
        $offset = 2;
        if (ord($der[1]) & 0x80) {
            $offset = 2 + (ord($der[1]) & 0x7f);
        }
        if (!isset($der[$offset]) || ord($der[$offset]) !== 0x02) {
            return '';
        }
        $rlen = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rlen);
        $offset = $offset + 2 + $rlen;
        if (!isset($der[$offset]) || ord($der[$offset]) !== 0x02) {
            return '';
        }
        $slen = ord($der[$offset + 1]);
        $s = substr($der, $offset + 2, $slen);
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");
        if (strlen($r) > 32 || strlen($s) > 32) {
            return '';
        }
        return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
    }
}
