<?php
/**
 * Tickets Module - Apple Wallet Pass Generator
 * 
 * Generates .pkpass files for Apple Wallet integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Tickets_Apple_Wallet {
    
    private $pass_type_id;
    private $team_id;
    private $cert_path;
    private $cert_password;
    private $wwdr_cert_path;
    
    public function __construct() {
        $this->pass_type_id = Azure_Settings::get_setting('tickets_apple_pass_type_id', '');
        $this->team_id = Azure_Settings::get_setting('tickets_apple_team_id', '');
        $this->cert_path = Azure_Settings::get_setting('tickets_apple_cert_path', '');
        $this->cert_password = Azure_Settings::get_setting('tickets_apple_cert_password', '');
        
        // WWDR (Apple Worldwide Developer Relations) certificate
        $upload_dir = wp_upload_dir();
        $this->wwdr_cert_path = $upload_dir['basedir'] . '/azure-plugin/certificates/AppleWWDRCA.pem';
    }
    
    /**
     * Check if Apple Wallet is configured
     * 
     * @return bool
     */
    public function is_configured() {
        return !empty($this->pass_type_id) && 
               !empty($this->team_id) && 
               !empty($this->cert_path) && 
               file_exists($this->cert_path);
    }
    
    /**
     * Generate Apple Wallet pass for a ticket
     * 
     * @param int $ticket_id
     * @return string|false Path to .pkpass file or false on failure
     */
    public function generate_pass($ticket_id) {
        if (!$this->is_configured()) {
            Azure_Logger::warning('Tickets: Apple Wallet not configured');
            return false;
        }
        
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tickets_table} WHERE id = %d",
            $ticket_id
        ));
        
        if (!$ticket) {
            return false;
        }
        
        // Get product/event details
        $product = wc_get_product($ticket->product_id);
        $event_name = $product ? $product->get_name() : 'Event';
        $event_date = get_post_meta($ticket->product_id, '_ticket_event_date', true);
        
        // Create pass JSON
        $pass_json = $this->create_pass_json($ticket, $event_name, $event_date);
        
        // Create pass package
        $pass_path = $this->create_pass_package($ticket, $pass_json);
        
        return $pass_path;
    }
    
    /**
     * Create pass.json content
     */
    private function create_pass_json($ticket, $event_name, $event_date) {
        $seat_info = $ticket->row_letter ? 
            'Row ' . $ticket->row_letter . ', Seat ' . $ticket->seat_number : 
            'General Admission';
        
        $org_name = Azure_Settings::get_setting('org_name', get_bloginfo('name'));
        
        $pass = array(
            'formatVersion' => 1,
            'passTypeIdentifier' => $this->pass_type_id,
            'serialNumber' => 'ticket-' . $ticket->id,
            'teamIdentifier' => $this->team_id,
            'organizationName' => $org_name,
            'description' => $event_name . ' Ticket',
            'logoText' => $org_name,
            'foregroundColor' => 'rgb(255, 255, 255)',
            'backgroundColor' => 'rgb(34, 113, 177)',
            'labelColor' => 'rgb(200, 200, 200)',
            
            'barcode' => array(
                'message' => $ticket->qr_data,
                'format' => 'PKBarcodeFormatQR',
                'messageEncoding' => 'iso-8859-1'
            ),
            
            'barcodes' => array(
                array(
                    'message' => $ticket->qr_data,
                    'format' => 'PKBarcodeFormatQR',
                    'messageEncoding' => 'iso-8859-1'
                )
            ),
            
            'eventTicket' => array(
                'primaryFields' => array(
                    array(
                        'key' => 'event',
                        'label' => 'EVENT',
                        'value' => $event_name
                    )
                ),
                'secondaryFields' => array(
                    array(
                        'key' => 'seat',
                        'label' => 'SEAT',
                        'value' => $seat_info
                    )
                ),
                'auxiliaryFields' => array(
                    array(
                        'key' => 'ticket-code',
                        'label' => 'TICKET CODE',
                        'value' => $ticket->ticket_code
                    )
                ),
                'backFields' => array(
                    array(
                        'key' => 'attendee',
                        'label' => 'Attendee',
                        'value' => $ticket->attendee_name ?: 'Guest'
                    ),
                    array(
                        'key' => 'order',
                        'label' => 'Order Number',
                        'value' => '#' . $ticket->order_id
                    ),
                    array(
                        'key' => 'terms',
                        'label' => 'Terms & Conditions',
                        'value' => 'This ticket is non-transferable. Please present this pass at the entrance for check-in.'
                    )
                )
            )
        );
        
        // Add date if available
        if ($event_date) {
            $pass['relevantDate'] = date('c', strtotime($event_date));
            $pass['eventTicket']['headerFields'] = array(
                array(
                    'key' => 'date',
                    'label' => 'DATE',
                    'value' => date('M j, Y', strtotime($event_date)),
                    'textAlignment' => 'PKTextAlignmentRight'
                )
            );
        }
        
        return json_encode($pass, JSON_PRETTY_PRINT);
    }
    
    /**
     * Create the .pkpass file package
     */
    private function create_pass_package($ticket, $pass_json) {
        // Create temp directory
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/azure-plugin/temp/pass-' . $ticket->id . '-' . time();
        
        if (!wp_mkdir_p($temp_dir)) {
            Azure_Logger::error('Tickets: Could not create temp directory for pass');
            return false;
        }
        
        // Write pass.json
        file_put_contents($temp_dir . '/pass.json', $pass_json);
        
        // Copy images (icon, logo)
        // For now, generate simple placeholder images
        $this->create_pass_images($temp_dir);
        
        // Create manifest
        $manifest = $this->create_manifest($temp_dir);
        file_put_contents($temp_dir . '/manifest.json', json_encode($manifest));
        
        // Sign the pass
        if (!$this->sign_pass($temp_dir)) {
            $this->cleanup_temp_dir($temp_dir);
            return false;
        }
        
        // Create .pkpass (ZIP file)
        $pkpass_path = $upload_dir['basedir'] . '/azure-plugin/passes/ticket-' . $ticket->id . '.pkpass';
        wp_mkdir_p(dirname($pkpass_path));
        
        if (!$this->create_pkpass($temp_dir, $pkpass_path)) {
            $this->cleanup_temp_dir($temp_dir);
            return false;
        }
        
        // Cleanup
        $this->cleanup_temp_dir($temp_dir);
        
        return $pkpass_path;
    }
    
    /**
     * Create placeholder pass images
     */
    private function create_pass_images($dir) {
        // Icon (required, 29x29, 58x58, 87x87)
        $this->create_icon_image($dir . '/icon.png', 29);
        $this->create_icon_image($dir . '/icon@2x.png', 58);
        $this->create_icon_image($dir . '/icon@3x.png', 87);
        
        // Logo (optional, 160x50, 320x100)
        $this->create_logo_image($dir . '/logo.png', 160, 50);
        $this->create_logo_image($dir . '/logo@2x.png', 320, 100);
    }
    
    /**
     * Create a simple icon image
     */
    private function create_icon_image($path, $size) {
        $img = imagecreatetruecolor($size, $size);
        $blue = imagecolorallocate($img, 34, 113, 177);
        $white = imagecolorallocate($img, 255, 255, 255);
        
        imagefill($img, 0, 0, $blue);
        
        // Draw ticket icon symbol
        $margin = $size * 0.2;
        imagefilledrectangle($img, $margin, $margin, $size - $margin, $size - $margin, $white);
        
        imagepng($img, $path);
        imagedestroy($img);
    }
    
    /**
     * Create a simple logo image
     */
    private function create_logo_image($path, $width, $height) {
        $img = imagecreatetruecolor($width, $height);
        
        // Transparent background
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);
        
        // White text
        $white = imagecolorallocate($img, 255, 255, 255);
        $org_name = Azure_Settings::get_setting('org_name', 'Event');
        
        $font_size = 3;
        $text_width = imagefontwidth($font_size) * strlen($org_name);
        $x = ($width - $text_width) / 2;
        $y = ($height - imagefontheight($font_size)) / 2;
        
        imagestring($img, $font_size, $x, $y, $org_name, $white);
        
        imagepng($img, $path);
        imagedestroy($img);
    }
    
    /**
     * Create manifest.json with SHA1 hashes
     */
    private function create_manifest($dir) {
        $manifest = array();
        
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_file($file) && basename($file) !== 'manifest.json' && basename($file) !== 'signature') {
                $manifest[basename($file)] = sha1_file($file);
            }
        }
        
        return $manifest;
    }
    
    /**
     * Sign the pass with certificate
     */
    private function sign_pass($dir) {
        if (!function_exists('openssl_pkcs7_sign')) {
            Azure_Logger::error('Tickets: OpenSSL extension not available for pass signing');
            return false;
        }
        
        if (!file_exists($this->cert_path)) {
            Azure_Logger::error('Tickets: Certificate file not found: ' . $this->cert_path);
            return false;
        }
        
        $manifest_path = $dir . '/manifest.json';
        $signature_path = $dir . '/signature';
        
        // Read certificate
        $cert_data = file_get_contents($this->cert_path);
        $pkcs12 = array();
        
        if (!openssl_pkcs12_read($cert_data, $pkcs12, $this->cert_password)) {
            Azure_Logger::error('Tickets: Failed to read certificate. Check password.');
            return false;
        }
        
        // Sign manifest
        $cert = openssl_x509_read($pkcs12['cert']);
        $pkey = openssl_pkey_get_private($pkcs12['pkey'], $this->cert_password);
        
        // Create signature using PKCS7
        $temp_pem = $dir . '/temp_cert.pem';
        file_put_contents($temp_pem, $pkcs12['cert'] . "\n" . $pkcs12['pkey']);
        
        // For proper signing, we'd need the WWDR certificate too
        // This is a simplified version
        $signed = openssl_pkcs7_sign(
            $manifest_path,
            $signature_path . '.tmp',
            'file://' . $temp_pem,
            array('file://' . $temp_pem, $this->cert_password),
            array(),
            PKCS7_BINARY | PKCS7_DETACHED
        );
        
        unlink($temp_pem);
        
        if (!$signed) {
            Azure_Logger::error('Tickets: Failed to sign pass: ' . openssl_error_string());
            return false;
        }
        
        // Extract DER signature from PEM
        $signature_pem = file_get_contents($signature_path . '.tmp');
        $matches = array();
        if (preg_match('/-----BEGIN PKCS7-----(.*?)-----END PKCS7-----/s', $signature_pem, $matches)) {
            $signature_der = base64_decode($matches[1]);
            file_put_contents($signature_path, $signature_der);
        }
        
        unlink($signature_path . '.tmp');
        
        return file_exists($signature_path);
    }
    
    /**
     * Create .pkpass ZIP file
     */
    private function create_pkpass($source_dir, $destination) {
        if (!class_exists('ZipArchive')) {
            Azure_Logger::error('Tickets: ZipArchive extension not available');
            return false;
        }
        
        $zip = new ZipArchive();
        
        if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            Azure_Logger::error('Tickets: Could not create ZIP file');
            return false;
        }
        
        $files = glob($source_dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                $zip->addFile($file, basename($file));
            }
        }
        
        $zip->close();
        
        return file_exists($destination);
    }
    
    /**
     * Clean up temporary directory
     */
    private function cleanup_temp_dir($dir) {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        
        rmdir($dir);
    }
    
    /**
     * Get URL for downloading pass
     * 
     * @param int $ticket_id
     * @return string|false
     */
    public function get_pass_url($ticket_id) {
        $pass_path = $this->generate_pass($ticket_id);
        
        if (!$pass_path || !file_exists($pass_path)) {
            return false;
        }
        
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['basedir'], '', $pass_path);
        
        return $upload_dir['baseurl'] . $relative_path;
    }
    
    /**
     * Serve pass file for download
     * 
     * @param int $ticket_id
     */
    public function serve_pass($ticket_id) {
        $pass_path = $this->generate_pass($ticket_id);
        
        if (!$pass_path || !file_exists($pass_path)) {
            wp_die('Pass not found');
        }
        
        header('Content-Type: application/vnd.apple.pkpass');
        header('Content-Disposition: attachment; filename="ticket-' . $ticket_id . '.pkpass"');
        header('Content-Length: ' . filesize($pass_path));
        
        readfile($pass_path);
        exit;
    }
}

