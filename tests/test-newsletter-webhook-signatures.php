<?php
/**
 * SendGrid and SES (SNS) webhooks must be signed, like Mailgun's.
 *
 * Run: php tests/test-newsletter-webhook-signatures.php
 */

require_once __DIR__ . '/wp-shim.php';
ini_set('error_log', '/dev/null');

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public $data;
        public $status;
        public function __construct($data = null, $status = 200) { $this->data = $data; $this->status = $status; }
        public function get_status() { return $this->status; }
    }
}
class Test_Request {
    private $body;
    private $headers;
    public function __construct($body, $headers = array()) {
        $this->body = $body;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }
    public function get_body() { return $this->body; }
    public function get_header($name) { return $this->headers[strtolower($name)] ?? null; }
}
if (!function_exists('get_user_by')) {
    function get_user_by($field, $value) { return false; }
}

class Webhook_WPDB {
    public $prefix = 'wp_';
    public $inserts = array();
    public function insert($table, $row) { $this->inserts[] = array($table, $row); return 1; }
    public function update($table, $row, $where) { return 1; }
    public function prepare($sql, ...$args) { return $sql; }
    public function get_row($q) { return null; }
    public function get_var($q) { return null; }
    public function bounces() {
        return array_values(array_filter($this->inserts, function ($i) { return $i[0] === 'wp_azure_newsletter_bounces'; }));
    }
}

require_once dirname(__DIR__) . '/Azure Plugin/includes/class-newsletter-tracking.php';

$t = new TestRunner('Newsletter webhook signatures');

function fresh() {
    global $wpdb;
    WP_Shim::reset();
    $wpdb = new Webhook_WPDB();
    return new Azure_Newsletter_Tracking();
}

// ---------------------------------------------------------------------------
// SendGrid
// ---------------------------------------------------------------------------

$ec = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
$ec_pem = openssl_pkey_get_details($ec)['key'];
$ec_b64 = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $ec_pem);
$other_ec = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));

$sg_body = json_encode(array(array('event' => 'spamreport', 'email' => 'parent@example.com')));

function sendgrid_request($body, $key, $ts = null) {
    $ts = (string) ($ts ?? time());
    openssl_sign($ts . $body, $sig, $key, OPENSSL_ALGO_SHA256);
    return new Test_Request($body, array(
        'X-Twilio-Email-Event-Webhook-Signature' => base64_encode($sig),
        'X-Twilio-Email-Event-Webhook-Timestamp' => $ts,
    ));
}

$tracking = fresh();
$res = $tracking->process_sendgrid_webhook(new Test_Request($sg_body));
$t->equals(403, $res->get_status(), 'SendGrid: rejected when no verification key is configured');
$t->equals(0, count($wpdb->bounces()), 'SendGrid: nothing recorded without a key');

$tracking = fresh();
WP_Shim::$settings['newsletter_sendgrid_webhook_public_key'] = $ec_b64;
$res = $tracking->process_sendgrid_webhook(new Test_Request($sg_body));
$t->equals(403, $res->get_status(), 'SendGrid: unsigned request rejected');
$t->equals(0, count($wpdb->bounces()), 'SendGrid: unsigned request records nothing');

$tracking = fresh();
WP_Shim::$settings['newsletter_sendgrid_webhook_public_key'] = $ec_b64;
$res = $tracking->process_sendgrid_webhook(sendgrid_request($sg_body, $other_ec));
$t->equals(403, $res->get_status(), 'SendGrid: request signed by another key rejected');

$tracking = fresh();
WP_Shim::$settings['newsletter_sendgrid_webhook_public_key'] = $ec_b64;
$req = sendgrid_request($sg_body, $ec);
$tampered = new Test_Request(
    json_encode(array(array('event' => 'spamreport', 'email' => 'victim@example.com'))),
    array(
        'X-Twilio-Email-Event-Webhook-Signature' => $req->get_header('x-twilio-email-event-webhook-signature'),
        'X-Twilio-Email-Event-Webhook-Timestamp' => $req->get_header('x-twilio-email-event-webhook-timestamp'),
    )
);
$res = $tracking->process_sendgrid_webhook($tampered);
$t->equals(403, $res->get_status(), 'SendGrid: tampered body rejected');

$tracking = fresh();
WP_Shim::$settings['newsletter_sendgrid_webhook_public_key'] = $ec_b64;
$res = $tracking->process_sendgrid_webhook(sendgrid_request($sg_body, $ec, time() - 3600));
$t->equals(403, $res->get_status(), 'SendGrid: stale timestamp rejected');

$tracking = fresh();
WP_Shim::$settings['newsletter_sendgrid_webhook_public_key'] = $ec_b64;
$res = $tracking->process_sendgrid_webhook(sendgrid_request($sg_body, $ec));
$t->equals(200, $res->get_status(), 'SendGrid: correctly signed request accepted (bare base64 key)');
$t->equals(1, count($wpdb->bounces()), 'SendGrid: signed complaint is recorded');

$tracking = fresh();
WP_Shim::$settings['newsletter_sendgrid_webhook_public_key'] = str_replace("\n", ' ', $ec_pem);
$res = $tracking->process_sendgrid_webhook(sendgrid_request($sg_body, $ec));
$t->equals(200, $res->get_status(), 'SendGrid: PEM key with collapsed newlines also accepted');

// ---------------------------------------------------------------------------
// SES / SNS
// ---------------------------------------------------------------------------

$topic = 'arn:aws:sns:us-east-1:123456789012:ses-bounces';
$cert_url = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-abc123.pem';

$rsa = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048));
$csr = openssl_csr_new(array('commonName' => 'sns.amazonaws.com'), $rsa, array('digest_alg' => 'sha256'));
openssl_x509_export(openssl_csr_sign($csr, null, $rsa, 1, array('digest_alg' => 'sha256')), $cert_pem);
$other_rsa = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048));

function sns_sign(array $msg, $key, $version = '1') {
    $fields = $msg['Type'] === 'Notification'
        ? array('Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type')
        : array('Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type');
    $s = '';
    foreach ($fields as $f) {
        if (isset($msg[$f])) {
            $s .= "$f\n{$msg[$f]}\n";
        }
    }
    openssl_sign($s, $sig, $key, $version === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1);
    $msg['SignatureVersion'] = $version;
    $msg['Signature'] = base64_encode($sig);
    return $msg;
}

function sns_notification($topic, $cert_url, $email = 'parent@example.com', $id = 'msg-1') {
    return array(
        'Type' => 'Notification',
        'MessageId' => $id,
        'TopicArn' => $topic,
        'Message' => json_encode(array(
            'notificationType' => 'Complaint',
            'complaint' => array('complainedRecipients' => array(array('emailAddress' => $email))),
        )),
        'Timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'),
        'SigningCertURL' => $cert_url,
    );
}

function ses_fresh($topic, $cert_pem) {
    $tracking = fresh();
    WP_Shim::$settings['newsletter_ses_sns_topic_arn'] = $topic;
    WP_Shim::on_request('sns.us-east-1.amazonaws.com', 200, $cert_pem);
    return $tracking;
}

function post_ses($tracking, array $msg) {
    return $tracking->process_ses_webhook(new Test_Request(json_encode($msg)));
}

// Forged, unsigned complaint (the original attack).
$tracking = ses_fresh($topic, $cert_pem);
$res = post_ses($tracking, sns_notification($topic, $cert_url));
$t->equals(403, $res->get_status(), 'SES: unsigned notification rejected');
$t->equals(0, count($wpdb->bounces()), 'SES: unsigned notification records nothing');
$t->equals(0, count(WP_Shim::$http_calls), 'SES: unsigned notification triggers no HTTP fetch');

// Unsigned SubscriptionConfirmation pointing at an internal URL (the SSRF).
$tracking = ses_fresh($topic, $cert_pem);
$res = post_ses($tracking, array(
    'Type' => 'SubscriptionConfirmation',
    'SubscribeURL' => 'http://169.254.169.254/latest/meta-data/',
    'TopicArn' => $topic,
));
$t->equals(403, $res->get_status(), 'SES: unsigned subscription confirmation rejected');
$t->equals(0, count(WP_Shim::$http_calls), 'SES: attacker SubscribeURL is never fetched');

// Not configured.
$tracking = ses_fresh('', $cert_pem);
$res = post_ses($tracking, sns_sign(sns_notification($topic, $cert_url), $rsa));
$t->equals(403, $res->get_status(), 'SES: rejected when no topic ARN is configured');

// Valid signature, v1 and v2.
$tracking = ses_fresh($topic, $cert_pem);
$res = post_ses($tracking, sns_sign(sns_notification($topic, $cert_url), $rsa, '1'));
$t->equals(200, $res->get_status(), 'SES: SignatureVersion 1 notification accepted');
$t->equals(1, count($wpdb->bounces()), 'SES: signed complaint is recorded');
$t->equals(array($cert_url), array_column(WP_Shim::$http_calls, 'url'), 'SES: only the SigningCertURL is fetched');
$t->equals(0, WP_Shim::$http_calls[0]['args']['redirection'] ?? null, 'SES: certificate fetch does not follow redirects');

$tracking = ses_fresh($topic, $cert_pem);
$res = post_ses($tracking, sns_sign(sns_notification($topic, $cert_url), $rsa, '2'));
$t->equals(200, $res->get_status(), 'SES: SignatureVersion 2 notification accepted');

// Replay of the same MessageId.
$tracking = ses_fresh($topic, $cert_pem);
$signed = sns_sign(sns_notification($topic, $cert_url), $rsa);
post_ses($tracking, $signed);
post_ses($tracking, $signed);
$t->equals(1, count($wpdb->bounces()), 'SES: replayed MessageId is processed once');

// Wrong key, tampered message, wrong topic.
$tracking = ses_fresh($topic, $cert_pem);
$res = post_ses($tracking, sns_sign(sns_notification($topic, $cert_url), $other_rsa));
$t->equals(403, $res->get_status(), 'SES: signature from a different key rejected');

$tracking = ses_fresh($topic, $cert_pem);
$msg = sns_sign(sns_notification($topic, $cert_url), $rsa);
$msg['Message'] = str_replace('parent@example.com', 'victim@example.com', $msg['Message']);
$res = post_ses($tracking, $msg);
$t->equals(403, $res->get_status(), 'SES: tampered message rejected');

$tracking = ses_fresh($topic, $cert_pem);
$attacker_topic = 'arn:aws:sns:us-east-1:999999999999:attacker';
$res = post_ses($tracking, sns_sign(sns_notification($attacker_topic, $cert_url), $rsa));
$t->equals(403, $res->get_status(), 'SES: validly signed message from another topic rejected');
$t->equals(0, count(WP_Shim::$http_calls), 'SES: foreign topic is rejected before any fetch');

// Untrusted certificate URLs are never fetched.
$bad_cert_urls = array(
    'http://sns.us-east-1.amazonaws.com/SimpleNotificationService-abc123.pem',
    'https://sns.us-east-1.amazonaws.com.evil.com/SimpleNotificationService-abc123.pem',
    'https://evil.com/sns.us-east-1.amazonaws.com/cert.pem',
    'https://s3.amazonaws.com/attacker-bucket/cert.pem',
    'https://sns.s3.amazonaws.com/cert.pem',
    'https://sns.s3-us-west-2.amazonaws.com/cert.pem',
    'https://user@sns.us-east-1.amazonaws.com/SimpleNotificationService-abc123.pem',
    'https://sns.us-east-1.amazonaws.com:8443/SimpleNotificationService-abc123.pem',
    'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-abc123.pem?x=1',
    'https://sns.us-east-1.amazonaws.com/../latest/meta-data',
    'https://169.254.169.254/latest/meta-data/cert.pem',
);
foreach ($bad_cert_urls as $url) {
    $tracking = ses_fresh($topic, $cert_pem);
    $res = post_ses($tracking, sns_sign(sns_notification($topic, $url), $rsa));
    $t->check(
        $res->get_status() === 403 && count(WP_Shim::$http_calls) === 0,
        'SES: untrusted SigningCertURL rejected without fetching',
        $url
    );
}

// Subscription confirmations: verified, parked for the admin, never fetched.
$confirm = array(
    'Type' => 'SubscriptionConfirmation',
    'MessageId' => 'sub-1',
    'Token' => 'tok',
    'TopicArn' => $topic,
    'Message' => 'You have chosen to subscribe...',
    'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn=' . rawurlencode($topic) . '&Token=tok',
    'Timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'),
    'SigningCertURL' => $cert_url,
);
$tracking = ses_fresh($topic, $cert_pem);
$res = post_ses($tracking, sns_sign($confirm, $rsa));
$t->equals(200, $res->get_status(), 'SES: signed subscription confirmation accepted');
$t->equals(array($cert_url), array_column(WP_Shim::$http_calls, 'url'), 'SES: SubscribeURL is not fetched, only the certificate');
$t->equals($confirm['SubscribeURL'], get_option('azure_newsletter_ses_pending_subscription')['subscribe_url'] ?? null, 'SES: confirmation URL is stored for the admin');

$tracking = ses_fresh($topic, $cert_pem);
$bad_confirm = $confirm;
$bad_confirm['SubscribeURL'] = 'https://evil.example/confirm';
$res = post_ses($tracking, sns_sign($bad_confirm, $rsa));
$t->equals(403, $res->get_status(), 'SES: confirmation with a non-SNS SubscribeURL rejected');
$t->equals(false, get_option('azure_newsletter_ses_pending_subscription'), 'SES: rejected confirmation is not stored');

exit($t->finish() > 0 ? 1 : 0);
