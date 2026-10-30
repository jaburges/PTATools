<?php
/**
 * Apple Wallet passes for tickets.
 *
 * A pass is built when it is downloaded, from GET
 * /wp-json/pta-tickets/v1/wallet/<code>?sig=<hmac>, and is never stored: it
 * carries the ticket's QR code, so a file under uploads would let anyone with
 * the URL pattern collect other people's tickets.
 *
 * The Pass Type ID certificate is kept in a non-autoloaded option, not under
 * uploads, which is a shared, web-served folder. Its private key is stored
 * encrypted with the site's auth salt (wp-config, not the database), so a
 * database dump alone doesn't expose it; changing the salts means uploading
 * the certificate again. Passes are signed with it plus Apple's WWDR G4
 * intermediate (includes/certs, valid to 2030-12-10).
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Tickets_Apple_Wallet {

    const CERT_OPTION = 'azure_tickets_apple_certificate';

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

    public static function wwdr_path() {
        return dirname(__FILE__) . '/certs/AppleWWDRCAG4.pem';
    }

    public static function pass_type_id() {
        return trim((string) Azure_Settings::get_setting('tickets_apple_pass_type_id', ''));
    }

    public static function team_id() {
        return trim((string) Azure_Settings::get_setting('tickets_apple_team_id', ''));
    }

    public static function is_configured() {
        return self::pass_type_id() !== '' && self::team_id() !== '' && get_option(self::CERT_OPTION, '') !== '';
    }

    // ── Certificate ───────────────────────────────────────────────────

    /**
     * Reads an uploaded certificate: a .p12 (as exported by Keychain Access,
     * including its older RC2 encryption) or a PEM file with the certificate
     * and private key.
     *
     * @return array{ok:bool, error?:string, cert?:string, pkey?:OpenSSLAsymmetricKey, pass_type_id?:string, team_id?:string, expires?:int}
     */
    public static function read_upload($bytes, $password) {
        if (!function_exists('openssl_pkcs12_read')) {
            return array('ok' => false, 'error' => __('This server\'s PHP has no OpenSSL, so it can\'t sign passes.', 'azure-plugin'));
        }
        $bytes = (string) $bytes;
        $password = (string) $password;
        $p12 = array();
        if (@openssl_pkcs12_read($bytes, $p12, $password)) {
            return self::describe($p12['cert'], openssl_pkey_get_private($p12['pkey']));
        }
        // OpenSSL 3 can't decrypt RC2, which macOS still uses for exports; its command line can.
        $pem = strpos($bytes, '-----BEGIN') !== false ? $bytes : self::legacy_p12_to_pem($bytes, $password);
        if ($pem !== '') {
            $key = @openssl_pkey_get_private($pem, $password);
            preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m);
            foreach ($m[0] as $cert) {
                if ($key && openssl_x509_check_private_key($cert, $key)) {
                    return self::describe($cert, $key);
                }
            }
        }
        return array('ok' => false, 'error' => __('That file couldn\'t be opened. Check the password, and that you exported the certificate together with its private key (in Keychain Access, under My Certificates).', 'azure-plugin'));
    }

    private static function legacy_p12_to_pem($bytes, $password) {
        if (!function_exists('proc_open')) {
            return '';
        }
        $proc = @proc_open(
            array('openssl', 'pkcs12', '-legacy', '-nodes', '-passin', 'env:PTA_P12_PASSWORD'),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes, null, array('PTA_P12_PASSWORD' => $password, 'PATH' => '/usr/local/bin:/usr/bin:/bin')
        );
        if (!is_resource($proc)) {
            return '';
        }
        fwrite($pipes[0], $bytes);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        return proc_close($proc) === 0 ? $out : '';
    }

    private static function describe($cert_pem, $key) {
        if (!$key) {
            return array('ok' => false, 'error' => __('The private key couldn\'t be read.', 'azure-plugin'));
        }
        $info = openssl_x509_parse($cert_pem);
        $subject = $info['subject'] ?? array();
        return array(
            'ok'           => true,
            'cert'         => $cert_pem,
            'pkey'         => $key,
            'pass_type_id' => (string) ($subject['UID'] ?? ''),
            'team_id'      => (string) ($subject['OU'] ?? ''),
            'expires'      => (int) ($info['validTo_time_t'] ?? 0),
        );
    }

    /**
     * Stores the certificate and fills in the Pass Type ID and Team ID it was issued for.
     *
     * @return array read_upload() result
     */
    public static function save_certificate($bytes, $password) {
        $r = self::read_upload($bytes, $password);
        if (!$r['ok']) {
            return $r;
        }
        if (strpos($r['pass_type_id'], 'pass.') !== 0) {
            return array('ok' => false, 'error' => __('That certificate isn\'t a Pass Type ID certificate. In the Apple Developer site, create it under Identifiers → Pass Type IDs.', 'azure-plugin'));
        }
        if (!openssl_pkey_export($r['pkey'], $key_pem, wp_salt('auth'))) {
            return array('ok' => false, 'error' => __('The private key couldn\'t be stored.', 'azure-plugin'));
        }
        openssl_x509_export($r['cert'], $cert_pem);
        update_option(self::CERT_OPTION, wp_json_encode(array('cert' => $cert_pem, 'key' => $key_pem)), false);
        Azure_Settings::update_setting('tickets_apple_pass_type_id', $r['pass_type_id']);
        if ($r['team_id'] !== '') {
            Azure_Settings::update_setting('tickets_apple_team_id', $r['team_id']);
        }
        return $r;
    }

    public static function remove_certificate() {
        delete_option(self::CERT_OPTION);
    }

    /**
     * @return array|null The stored certificate and key with its details, null when none.
     */
    public static function certificate_info() {
        $stored = json_decode((string) get_option(self::CERT_OPTION, ''), true);
        if (!is_array($stored) || empty($stored['cert'])) {
            return null;
        }
        $key = @openssl_pkey_get_private((string) ($stored['key'] ?? ''), wp_salt('auth'));
        if (!$key) {
            return array('ok' => false, 'error' => __('The stored certificate can\'t be unlocked (the site\'s security keys may have changed). Please upload it again.', 'azure-plugin'));
        }
        return self::describe($stored['cert'], $key);
    }

    // ── The pass ──────────────────────────────────────────────────────

    public static function signature($code) {
        return substr(hash_hmac('sha256', 'ticket-wallet|' . strtoupper((string) $code), wp_salt('auth')), 0, 20);
    }

    public static function url($code) {
        return add_query_arg('sig', self::signature($code), rest_url(Azure_Tickets_QR::ROUTE_NAMESPACE . '/wallet/' . rawurlencode(strtoupper((string) $code))));
    }

    /** Apple Wallet takes at most this many passes from one bundle. */
    const BUNDLE_MAX = 10;

    public static function order_signature($order_id, $product_id, $part) {
        return substr(hash_hmac('sha256', 'ticket-wallet-order|' . (int) $order_id . '|' . (int) $product_id . '|' . (int) $part, wp_salt('auth')), 0, 20);
    }

    /**
     * One tap adds an order's tickets for a performance: part 0 is the first
     * BUNDLE_MAX of them (by row and seat), part 1 the next, and so on.
     */
    public static function order_url($order_id, $product_id, $part = 0) {
        $path = '/wallet-order/' . (int) $order_id . '/' . (int) $product_id . '/' . (int) $part;
        return add_query_arg('sig', self::order_signature($order_id, $product_id, $part), rest_url(Azure_Tickets_QR::ROUTE_NAMESPACE . $path));
    }

    /**
     * A .pkpasses bundle (iOS 15+): a zip of signed .pkpass files.
     *
     * @param array<string,string> $passes ticket code => .pkpass bytes
     * @return string|WP_Error
     */
    public static function bundle(array $passes) {
        if (!class_exists('ZipArchive')) {
            return new WP_Error('pta_wallet', 'ZipArchive is missing.');
        }
        $zip_path = tempnam(get_temp_dir(), 'pkb');
        try {
            $zip = new ZipArchive();
            if ($zip->open($zip_path, ZipArchive::OVERWRITE) !== true) {
                return new WP_Error('pta_wallet', 'Could not create the bundle.');
            }
            foreach ($passes as $code => $bytes) {
                $zip->addFromString('ticket-' . $code . '.pkpass', $bytes);
            }
            $zip->close();
            return (string) file_get_contents($zip_path);
        } finally {
            if ($zip_path && file_exists($zip_path)) {
                unlink($zip_path);
            }
        }
    }

    /**
     * pass.json for a ticket.
     *
     * @param object $ticket Row from the tickets table.
     * @param array  $info   event, starts (Unix time or 0), section, venue, org, order
     */
    public static function pass_data($ticket, array $info) {
        $code = (string) $ticket->ticket_code;
        $barcode = array('message' => (string) $ticket->qr_data, 'format' => 'PKBarcodeFormatQR', 'messageEncoding' => 'iso-8859-1', 'altText' => $code);
        $seat = array();
        if (!empty($ticket->row_letter)) {
            $seat[] = array('key' => 'row', 'label' => 'ROW', 'value' => (string) $ticket->row_letter);
            $seat[] = array('key' => 'seat', 'label' => 'SEAT', 'value' => (string) (int) $ticket->seat_number);
        }
        $seat[] = array('key' => 'section', 'label' => 'SECTION', 'value' => $info['section'] !== '' ? $info['section'] : __('General admission', 'azure-plugin'));

        $aux = array();
        if (!empty($info['starts'])) {
            $aux[] = array('key' => 'time', 'label' => 'TIME', 'value' => wp_date('g:i a', $info['starts']));
        }
        if (!empty($ticket->attendee_name)) {
            $aux[] = array('key' => 'name', 'label' => 'NAME', 'value' => (string) $ticket->attendee_name);
        }

        $back = array(
            array('key' => 'code', 'label' => 'Ticket code', 'value' => $code),
            array('key' => 'order', 'label' => 'Order', 'value' => '#' . $info['order']),
        );
        if ($info['venue'] !== '') {
            $back[] = array('key' => 'venue', 'label' => 'Venue', 'value' => $info['venue']);
        }
        $back[] = array('key' => 'info', 'label' => 'At the door', 'value' => 'Show this pass at the entrance. Each ticket admits one person to the seat shown.');

        $pass = array(
            'formatVersion'      => 1,
            'passTypeIdentifier' => self::pass_type_id(),
            'teamIdentifier'     => self::team_id(),
            'serialNumber'       => $code,
            'organizationName'   => $info['org'],
            'description'        => $info['event'] . ' ticket',
            'logoText'           => $info['org'],
            'foregroundColor'    => 'rgb(255, 255, 255)',
            'backgroundColor'    => 'rgb(29, 35, 39)',
            'labelColor'         => 'rgb(170, 196, 230)',
            'barcode'            => $barcode,
            'barcodes'           => array($barcode),
            'eventTicket'        => array(
                'primaryFields'   => array(array('key' => 'event', 'label' => 'EVENT', 'value' => $info['event'])),
                'secondaryFields' => $seat,
                'auxiliaryFields' => $aux,
                'backFields'      => $back,
            ),
        );
        if (!empty($info['starts'])) {
            $pass['relevantDate'] = gmdate('Y-m-d\TH:i:s\Z', $info['starts']);
            $pass['expirationDate'] = gmdate('Y-m-d\TH:i:s\Z', $info['starts'] + DAY_IN_SECONDS);
            $pass['eventTicket']['headerFields'] = array(array('key' => 'date', 'label' => 'DATE', 'value' => wp_date('D j M', $info['starts']), 'textAlignment' => 'PKTextAlignmentRight'));
        }
        return $pass;
    }

    /**
     * @return string|WP_Error .pkpass bytes
     */
    public static function build($ticket, array $info) {
        if (!self::is_configured()) {
            return new WP_Error('pta_wallet', 'Apple Wallet isn\'t set up.');
        }
        if (!class_exists('ZipArchive')) {
            return new WP_Error('pta_wallet', 'ZipArchive is missing.');
        }
        $signer = self::certificate_info();
        if (!$signer || !$signer['ok']) {
            return new WP_Error('pta_wallet', $signer ? $signer['error'] : 'No certificate.');
        }

        $files = array('pass.json' => wp_json_encode(self::pass_data($ticket, $info), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        foreach (array('icon.png' => 29, 'icon@2x.png' => 58, 'icon@3x.png' => 87) as $name => $size) {
            $files[$name] = self::icon_png($size);
        }
        $manifest = wp_json_encode(array_map('sha1', $files), JSON_UNESCAPED_SLASHES);

        $tmp = get_temp_dir();
        $in = tempnam($tmp, 'pkm');
        $out = tempnam($tmp, 'pks');
        $zip_path = tempnam($tmp, 'pkz');
        try {
            file_put_contents($in, $manifest);
            $signed = openssl_cms_sign($in, $out, $signer['cert'], $signer['pkey'], array(),
                OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER, self::wwdr_path());
            if (!$signed) {
                return new WP_Error('pta_wallet', 'Signing failed: ' . (string) openssl_error_string());
            }
            $files['manifest.json'] = $manifest;
            $files['signature'] = (string) file_get_contents($out);

            $zip = new ZipArchive();
            if ($zip->open($zip_path, ZipArchive::OVERWRITE) !== true) {
                return new WP_Error('pta_wallet', 'Could not create the pass file.');
            }
            foreach ($files as $name => $bytes) {
                $zip->addFromString($name, $bytes);
            }
            $zip->close();
            return (string) file_get_contents($zip_path);
        } finally {
            foreach (array($in, $out, $zip_path) as $f) {
                if ($f && file_exists($f)) {
                    unlink($f);
                }
            }
        }
    }

    /**
     * The site icon when there is one, else a plain ticket shape.
     */
    private static function icon_png($size) {
        if (!function_exists('imagecreatetruecolor')) {
            return '';
        }
        $src = null;
        $icon_id = function_exists('get_option') ? (int) get_option('site_icon') : 0;
        $file = $icon_id && function_exists('get_attached_file') ? get_attached_file($icon_id) : '';
        if ($file && is_readable($file)) {
            $src = @imagecreatefromstring((string) file_get_contents($file));
        }
        $im = imagecreatetruecolor($size, $size);
        imagesavealpha($im, true);
        if ($src) {
            imagealphablending($im, false);
            imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
            imagealphablending($im, true);
            imagecopyresampled($im, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));
        } else {
            imagefill($im, 0, 0, imagecolorallocate($im, 29, 35, 39));
            $m = (int) round($size * 0.2);
            imagefilledrectangle($im, $m, (int) round($size * 0.3), $size - $m, (int) round($size * 0.7), imagecolorallocate($im, 255, 255, 255));
        }
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }

    // ── Download ──────────────────────────────────────────────────────

    public function register_route() {
        register_rest_route(Azure_Tickets_QR::ROUTE_NAMESPACE, '/wallet/(?P<code>[A-Za-z0-9]{6,20})', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'serve'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(Azure_Tickets_QR::ROUTE_NAMESPACE, '/wallet-order/(?P<order>\d+)/(?P<product>\d+)/(?P<part>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'serve_order'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * @return object[] Active tickets in this part of the order's bundle.
     */
    public static function order_part($order_id, $product_id, $part) {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}azure_tickets WHERE order_id = %d AND product_id = %d AND status = 'active'
             ORDER BY row_letter, seat_number, id LIMIT %d OFFSET %d",
            (int) $order_id, (int) $product_id, self::BUNDLE_MAX, self::BUNDLE_MAX * (int) $part
        ));
    }

    public function serve_order($request) {
        $order_id = (int) $request['order'];
        $product_id = (int) $request['product'];
        $part = (int) $request['part'];
        if (!hash_equals(self::order_signature($order_id, $product_id, $part), (string) $request->get_param('sig'))) {
            return new WP_Error('pta_wallet', 'Not found', array('status' => 404));
        }
        $tickets = self::order_part($order_id, $product_id, $part);
        if (!$tickets) {
            return new WP_Error('pta_wallet', 'These tickets are no longer valid.', array('status' => 410));
        }
        $passes = array();
        foreach ($tickets as $ticket) {
            $pkpass = self::build($ticket, self::ticket_info($ticket));
            if (is_wp_error($pkpass)) {
                Azure_Logger::error('Tickets: Apple Wallet pass failed: ' . $pkpass->get_error_message());
                return new WP_Error('pta_wallet', 'The Wallet passes could not be made. Please use the QR codes in your email.', array('status' => 500));
            }
            $passes[(string) $ticket->ticket_code] = $pkpass;
        }
        if (count($passes) === 1) {
            $bytes = reset($passes);
            $type = 'application/vnd.apple.pkpass';
            $name = 'ticket-' . key($passes) . '.pkpass';
        } else {
            $bytes = self::bundle($passes);
            if (is_wp_error($bytes)) {
                Azure_Logger::error('Tickets: Apple Wallet bundle failed: ' . $bytes->get_error_message());
                return new WP_Error('pta_wallet', 'The Wallet passes could not be made. Please use the QR codes in your email.', array('status' => 500));
            }
            $type = 'application/vnd.apple.pkpasses';
            $name = 'tickets-' . $order_id . ($part ? '-' . ($part + 1) : '') . '.pkpasses';
        }
        header('Content-Type: ' . $type);
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: private, no-store');
        echo $bytes;
        exit;
    }

    public function serve($request) {
        global $wpdb;
        $code = strtoupper((string) $request['code']);
        if (!hash_equals(self::signature($code), (string) $request->get_param('sig'))) {
            return new WP_Error('pta_wallet', 'Not found', array('status' => 404));
        }
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}azure_tickets WHERE ticket_code = %s", $code));
        if (!$ticket || $ticket->status !== 'active') {
            return new WP_Error('pta_wallet', 'This ticket is no longer valid.', array('status' => 410));
        }
        $pkpass = self::build($ticket, self::ticket_info($ticket));
        if (is_wp_error($pkpass)) {
            Azure_Logger::error('Tickets: Apple Wallet pass failed: ' . $pkpass->get_error_message());
            return new WP_Error('pta_wallet', 'The Wallet pass could not be made. Please use the QR code in your email.', array('status' => 500));
        }
        header('Content-Type: application/vnd.apple.pkpass');
        header('Content-Disposition: attachment; filename="ticket-' . $code . '.pkpass"');
        header('Content-Length: ' . strlen($pkpass));
        header('Cache-Control: private, no-store');
        echo $pkpass;
        exit;
    }

    public static function ticket_info($ticket) {
        $pid = (int) $ticket->product_id;
        $product = function_exists('wc_get_product') ? wc_get_product($pid) : null;
        $order = function_exists('wc_get_order') ? wc_get_order((int) $ticket->order_id) : null;
        $section = '';
        foreach (Azure_Tickets_Layout::sections(Azure_Tickets_Config::layout($pid)) as $block) {
            if ($block['id'] === (string) $ticket->section_id) {
                $section = (string) $block['name'];
            }
        }
        $venue_id = Azure_Tickets_Config::venue_id($pid);
        return array(
            'event'   => $product ? $product->get_name() : __('Event', 'azure-plugin'),
            'starts'  => Azure_Tickets_Config::event_ts($pid),
            'section' => $section,
            'venue'   => $venue_id ? get_the_title($venue_id) : '',
            'org'     => (string) Azure_Settings::get_setting('org_name', get_bloginfo('name')),
            'order'   => $order ? $order->get_order_number() : (string) $ticket->order_id,
        );
    }
}
