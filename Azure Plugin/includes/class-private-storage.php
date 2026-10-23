<?php
/**
 * Locations for files that must never be served over HTTP: logs, signing
 * certificates, backup staging and pass-signing scratch space.
 *
 * Per-directory .htaccess files cannot be relied on to protect these: plenty of
 * servers are configured not to read them, and nginx or IIS never do.
 *
 * Persistent files go to AZURE_PLUGIN_PRIVATE_DIR when wp-config.php defines it
 * (point it outside the web root). Otherwise they go to a directory under
 * uploads whose name carries a random per-site token, so it cannot be guessed
 * even on a server that ignores the deny rules. Short-lived files go to the
 * system temp directory, which is outside the web root on any sane host.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Private_Storage {

    const TOKEN_OPTION = 'azure_plugin_private_dir_token';

    private static $base_dir = null;
    private static $temp_base_dir = null;

    /**
     * Directory for files that must survive restarts (logs, certificates).
     *
     * @param string $sub Optional subdirectory, created if missing.
     * @return string Absolute path without a trailing slash.
     */
    public static function dir($sub = '') {
        if (self::$base_dir === null) {
            if (defined('AZURE_PLUGIN_PRIVATE_DIR') && AZURE_PLUGIN_PRIVATE_DIR) {
                $base = rtrim(AZURE_PLUGIN_PRIVATE_DIR, '/\\');
            } else {
                $uploads = wp_upload_dir();
                $base = rtrim($uploads['basedir'], '/\\') . '/azure-plugin/private-' . self::token();
            }
            self::ensure_dir($base);
            self::$base_dir = $base;
        }
        return self::join(self::$base_dir, $sub);
    }

    /**
     * Scratch directory for files that only live for one job (backup staging,
     * pass signing). Falls back to the persistent private directory when the
     * system temp directory is unusable or sits inside the web root.
     *
     * @param string $sub Optional subdirectory, created if missing.
     * @return string Absolute path without a trailing slash.
     */
    public static function temp_dir($sub = '') {
        if (self::$temp_base_dir === null) {
            $tmp = function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir();
            $tmp = rtrim((string) $tmp, '/\\');
            $base = '';
            if ($tmp !== '' && is_dir($tmp) && is_writable($tmp) && !self::is_inside_web_root($tmp)) {
                $base = $tmp . '/azure-plugin-' . self::token();
                if (!self::ensure_dir($base)) {
                    $base = '';
                }
            }
            if ($base === '') {
                $base = self::dir('tmp');
            }
            self::$temp_base_dir = $base;
        }
        return self::join(self::$temp_base_dir, $sub);
    }

    /**
     * True when $path resolves to somewhere under the WordPress root, i.e.
     * somewhere a web server would normally serve files from.
     */
    public static function is_inside_web_root($path) {
        $root = realpath(ABSPATH);
        if ($root === false) {
            return false;
        }
        $resolved = self::resolve_existing($path);
        if ($resolved === false) {
            return false;
        }
        $root = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        $resolved = rtrim($resolved, '/\\') . DIRECTORY_SEPARATOR;
        return strpos($resolved, $root) === 0;
    }

    /**
     * Mask email addresses so logs can still be correlated without holding
     * the address itself: "jamie.parent@example.com" becomes "j***@example.com".
     */
    public static function mask_emails($text) {
        if (!is_string($text) || strpos($text, '@') === false) {
            return $text;
        }
        return preg_replace_callback(
            '/([A-Za-z0-9._%+\-]+)@([A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?)*\.[A-Za-z]{2,})/',
            function ($m) {
                return substr($m[1], 0, 1) . '***@' . $m[2];
            },
            $text
        );
    }

    /** Forget cached paths. Tests only. */
    public static function reset() {
        self::$base_dir = null;
        self::$temp_base_dir = null;
    }

    private static function token() {
        $token = get_option(self::TOKEN_OPTION, '');
        if (is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token)) {
            return $token;
        }
        $token = bin2hex(random_bytes(16));
        // add_option() refuses to overwrite, so two requests racing here
        // settle on whichever token was stored first.
        if (!add_option(self::TOKEN_OPTION, $token, '', 'no')) {
            $stored = get_option(self::TOKEN_OPTION, '');
            if (is_string($stored) && preg_match('/^[a-f0-9]{32}$/', $stored)) {
                return $stored;
            }
            update_option(self::TOKEN_OPTION, $token, false);
        }
        return $token;
    }

    private static function ensure_dir($dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return false;
        }
        // Belt and braces for hosts that do honour these files.
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        return true;
    }

    private static function join($base, $sub) {
        $sub = trim((string) $sub, '/\\');
        if ($sub === '') {
            return $base;
        }
        $path = $base . '/' . $sub;
        self::ensure_dir($path);
        return $path;
    }

    private static function resolve_existing($path) {
        $path = (string) $path;
        while ($path !== '' && !file_exists($path)) {
            $parent = dirname($path);
            if ($parent === $path) {
                return false;
            }
            $path = $parent;
        }
        return $path === '' ? false : realpath($path);
    }
}
