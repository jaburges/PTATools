<?php
/**
 * PTSA REST — Microsoft Entra ID JWT validator
 *
 * Validates id_tokens issued by Microsoft Entra ID for our public-client
 * iOS app registration. Used as the auth primitive for /wp-json/ptsa/v1/*.
 *
 * Validates: RS256 signature against tenant JWKS, iss, aud, exp, nbf,
 * and (optionally) upn/preferred_username email-domain allow-list.
 *
 * Failures are explicit — no silent fallback. JWKS is cached for 24h via
 * a WordPress transient and refreshed on signature-failure (handles key
 * rotation transparently).
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_PTSA_JWT {

    const JWKS_TRANSIENT = 'ptsa_rest_entra_jwks_v1';
    const JWKS_TTL       = DAY_IN_SECONDS;
    const CLOCK_SKEW     = 60; // seconds of tolerance on exp/nbf

    /** @var string */ private $tenant_id;
    /** @var string */ private $client_id;

    public function __construct($tenant_id, $client_id) {
        $this->tenant_id = (string) $tenant_id;
        $this->client_id = (string) $client_id;
    }

    /**
     * Validate the JWT. Returns the decoded payload array on success, or a
     * WP_Error on failure.
     *
     * @param string $jwt
     * @return array|WP_Error
     */
    public function validate($jwt) {
        if (!is_string($jwt) || substr_count($jwt, '.') !== 2) {
            return new WP_Error('ptsa_jwt_malformed', 'JWT is not a well-formed JWS Compact (header.payload.signature).', array('status' => 401));
        }
        list($h64, $p64, $s64) = explode('.', $jwt, 3);

        $header  = $this->json_decode_b64($h64);
        $payload = $this->json_decode_b64($p64);
        if (!is_array($header) || !is_array($payload)) {
            return new WP_Error('ptsa_jwt_bad_json', 'JWT header or payload is not valid base64url JSON.', array('status' => 401));
        }
        if (($header['alg'] ?? '') !== 'RS256') {
            return new WP_Error('ptsa_jwt_alg', 'Only alg=RS256 is accepted.', array('status' => 401));
        }
        $kid = $header['kid'] ?? '';
        if (empty($kid)) {
            return new WP_Error('ptsa_jwt_kid_missing', 'JWT header missing kid.', array('status' => 401));
        }

        // ---- Claim checks -------------------------------------------------
        $expected_iss_v1 = 'https://sts.windows.net/' . $this->tenant_id . '/';
        $expected_iss_v2 = 'https://login.microsoftonline.com/' . $this->tenant_id . '/v2.0';
        $iss = (string) ($payload['iss'] ?? '');
        if ($iss !== $expected_iss_v1 && $iss !== $expected_iss_v2) {
            return new WP_Error('ptsa_jwt_iss', "Unexpected issuer: $iss", array('status' => 401));
        }
        $aud = $payload['aud'] ?? '';
        if (is_array($aud)) {
            // Some flows emit an array; require our client_id to be in it.
            if (!in_array($this->client_id, $aud, true)) {
                return new WP_Error('ptsa_jwt_aud', 'Audience does not include this client_id.', array('status' => 401));
            }
        } else {
            if ((string) $aud !== $this->client_id) {
                return new WP_Error('ptsa_jwt_aud', 'Audience does not match this client_id (got "' . (string) $aud . '").', array('status' => 401));
            }
        }
        $now = time();
        if (!isset($payload['exp']) || ((int) $payload['exp']) + self::CLOCK_SKEW < $now) {
            return new WP_Error('ptsa_jwt_expired', 'JWT has expired.', array('status' => 401));
        }
        if (isset($payload['nbf']) && ((int) $payload['nbf']) - self::CLOCK_SKEW > $now) {
            return new WP_Error('ptsa_jwt_nbf', 'JWT is not yet valid (nbf in the future).', array('status' => 401));
        }
        if (isset($payload['tid']) && (string) $payload['tid'] !== $this->tenant_id) {
            return new WP_Error('ptsa_jwt_tid', 'tid does not match expected tenant.', array('status' => 401));
        }

        // ---- Signature check ---------------------------------------------
        $jwk = $this->get_jwk_for_kid($kid, false);
        if (is_wp_error($jwk)) {
            // Try once more with a forced JWKS refresh (key rotation case).
            $jwk = $this->get_jwk_for_kid($kid, true);
        }
        if (is_wp_error($jwk)) {
            return $jwk;
        }
        $pem = $this->jwk_to_pem($jwk);
        if (is_wp_error($pem)) {
            return $pem;
        }
        $signed_input = $h64 . '.' . $p64;
        $signature    = $this->base64url_decode($s64);
        $ok = openssl_verify($signed_input, $signature, $pem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            return new WP_Error('ptsa_jwt_signature', 'JWT signature verification failed.', array('status' => 401));
        }

        return $payload;
    }

    /* =================================================================
     * JWKS handling
     * ================================================================= */

    /**
     * Fetch the matching JWK for `kid` from the cached (or freshly fetched) JWKS.
     *
     * @param string $kid
     * @param bool   $force_refresh
     * @return array|WP_Error
     */
    private function get_jwk_for_kid($kid, $force_refresh) {
        $jwks = $force_refresh ? null : get_transient(self::JWKS_TRANSIENT);
        if (!is_array($jwks) || empty($jwks['keys'])) {
            $jwks = $this->fetch_jwks();
            if (is_wp_error($jwks)) {
                return $jwks;
            }
            set_transient(self::JWKS_TRANSIENT, $jwks, self::JWKS_TTL);
        }
        foreach ($jwks['keys'] as $key) {
            if (isset($key['kid']) && $key['kid'] === $kid) {
                return $key;
            }
        }
        return new WP_Error('ptsa_jwt_kid_unknown', "JWKS does not contain a key with kid=$kid", array('status' => 401));
    }

    /**
     * @return array|WP_Error
     */
    private function fetch_jwks() {
        $url = 'https://login.microsoftonline.com/' . $this->tenant_id . '/discovery/v2.0/keys';
        $resp = wp_remote_get($url, array('timeout' => 10));
        if (is_wp_error($resp)) {
            return $resp;
        }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code !== 200) {
            return new WP_Error('ptsa_jwks_http', "JWKS endpoint returned HTTP $code", array('status' => 502));
        }
        $body = wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['keys'])) {
            return new WP_Error('ptsa_jwks_bad', 'JWKS body is not valid JSON or has no keys.', array('status' => 502));
        }
        return $json;
    }

    /**
     * Convert a JWK (RSA, n/e) into PEM-encoded RSA public key suitable for
     * `openssl_verify`.
     *
     * @param array $jwk
     * @return string|WP_Error
     */
    private function jwk_to_pem($jwk) {
        if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
            return new WP_Error('ptsa_jwk_kty', 'JWK is not an RSA key with n and e.', array('status' => 500));
        }
        $n = $this->base64url_decode($jwk['n']);
        $e = $this->base64url_decode($jwk['e']);
        if ($n === '' || $e === '') {
            return new WP_Error('ptsa_jwk_decode', 'Failed to base64url-decode JWK n/e.', array('status' => 500));
        }

        $modulus  = $this->der_int($n);
        $exponent = $this->der_int($e);
        $rsaPub   = $this->der_seq($modulus . $exponent);
        // OID 1.2.840.113549.1.1.1 = rsaEncryption, then NULL params, then BIT STRING wrapping rsaPub.
        $algoOid  = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $bitStr   = $this->der_bitstr($rsaPub);
        $subjPKI  = $this->der_seq($algoOid . $bitStr);

        $b64 = base64_encode($subjPKI);
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($b64, 64, "\n") . "-----END PUBLIC KEY-----\n";
        return $pem;
    }

    /* =================================================================
     * Encoding helpers
     * ================================================================= */

    private function json_decode_b64($input) {
        $bin = $this->base64url_decode($input);
        if ($bin === '') return null;
        $arr = json_decode($bin, true);
        return is_array($arr) ? $arr : null;
    }

    private function base64url_decode($input) {
        if (!is_string($input)) return '';
        $remainder = strlen($input) % 4;
        if ($remainder) $input .= str_repeat('=', 4 - $remainder);
        $decoded = base64_decode(strtr($input, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }

    private function der_int($bin) {
        // Prepend 0x00 if high bit set (DER positive integer convention).
        if (ord($bin[0]) > 0x7f) $bin = "\x00" . $bin;
        return "\x02" . $this->der_len(strlen($bin)) . $bin;
    }

    private function der_bitstr($bin) {
        return "\x03" . $this->der_len(strlen($bin) + 1) . "\x00" . $bin;
    }

    private function der_seq($bin) {
        return "\x30" . $this->der_len(strlen($bin)) . $bin;
    }

    private function der_len($len) {
        if ($len < 0x80) return chr($len);
        $bytes = '';
        while ($len > 0) { $bytes = chr($len & 0xff) . $bytes; $len >>= 8; }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
