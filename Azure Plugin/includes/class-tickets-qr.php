<?php
/**
 * QR codes for tickets, drawn on this server (lib/qrcode, MIT).
 *
 * Emails can't show inline data: images in Gmail, so a ticket's QR is a
 * PNG served from GET /wp-json/pta-tickets/v1/qr/<code>?sig=<hmac>. The
 * signature means only codes we issued can be drawn, so the endpoint can't
 * be used to guess ticket codes. The QR holds just the ticket code, which
 * is what the check-in scanner reads.
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Tickets_QR {

    const ROUTE_NAMESPACE = 'pta-tickets/v1';

    /** @var self|null */
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', array($this, 'register_route'));
    }

    public static function signature($code) {
        return substr(hash_hmac('sha256', 'ticket-qr|' . strtoupper((string) $code), wp_salt('auth')), 0, 20);
    }

    public static function url($code) {
        return add_query_arg('sig', self::signature($code), rest_url(self::ROUTE_NAMESPACE . '/qr/' . rawurlencode(strtoupper((string) $code))));
    }

    public function register_route() {
        register_rest_route(self::ROUTE_NAMESPACE, '/qr/(?P<code>[A-Za-z0-9]{6,20})', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'serve'),
            'permission_callback' => '__return_true',
        ));
    }

    public function serve($request) {
        $code = strtoupper((string) $request['code']);
        if (!hash_equals(self::signature($code), (string) $request->get_param('sig'))) {
            return new WP_Error('pta_ticket_qr', 'Not found', array('status' => 404));
        }
        $png = self::png($code);
        if ($png === '') {
            return new WP_Error('pta_ticket_qr', 'QR unavailable', array('status' => 500));
        }
        header('Content-Type: image/png');
        header('Content-Length: ' . strlen($png));
        header('Cache-Control: private, max-age=86400');
        echo $png;
        exit;
    }

    /**
     * @return bool[][] Dark modules, row by row.
     */
    public static function matrix($text) {
        if (!class_exists('QRCode')) {
            require_once AZURE_PLUGIN_PATH . 'lib/qrcode/qrcode.php';
        }
        $qr = QRCode::getMinimumQRCode((string) $text, QR_ERROR_CORRECT_LEVEL_M);
        $n = $qr->getModuleCount();
        $rows = array();
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                $rows[$r][$c] = (bool) $qr->isDark($r, $c);
            }
        }
        return $rows;
    }

    /**
     * @return string PNG bytes, '' if GD is missing.
     */
    public static function png($text, $scale = 8) {
        if (!function_exists('imagecreate')) {
            return '';
        }
        $m = self::matrix($text);
        $n = count($m);
        $quiet = 4;
        $size = ($n + 2 * $quiet) * $scale;
        $im = imagecreate($size, $size);
        imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 0, 0, 0);
        foreach ($m as $r => $row) {
            foreach ($row as $c => $dark) {
                if ($dark) {
                    $x = ($c + $quiet) * $scale;
                    $y = ($r + $quiet) * $scale;
                    imagefilledrectangle($im, $x, $y, $x + $scale - 1, $y + $scale - 1, $black);
                }
            }
        }
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }
}
