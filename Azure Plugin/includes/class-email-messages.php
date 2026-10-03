<?php
/**
 * Editable copies of the plugin's own emails.
 *
 * WooCommerce order mail, class enrollment, auction, tickets, and
 * newsletter campaigns stay in their own editors. This list is the
 * mail the plugin writes itself: volunteer, account, and system notices.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Azure_Email_Messages {

    const OPTION = 'azure_email_messages';

    /** User-created emails: {next:int, emails:{custom_<n>:{label,subject,body}}}. */
    const CUSTOM_OPTION = 'azure_email_custom';
    const CUSTOM_PREFIX = 'custom_';

    public static function site_name() {
        $name = function_exists('get_bloginfo') ? trim((string) get_bloginfo('name')) : '';
        return $name !== '' ? $name : 'PTA';
    }

    /**
     * @return array<string, array>
     */
    public static function catalog() {
        $volunteer_body = self::volunteer_body();
        $volunteer_raw = array('shifts', 'location', 'event_link', 'subscribe_button');
        $volunteer_tokens = array('{name}', '{intro}', '{event}', '{shifts}', '{location}', '{event_link}', '{event_url}', '{subscribe_url}', '{subscribe_button}', '{site_name}');
        return array(
            'volunteer_confirmation' => array(
                'group'       => __('Volunteers', 'azure-plugin'),
                'label'       => __('Volunteer confirmation', 'azure-plugin'),
                'description' => __('Sent when someone claims a volunteer spot.', 'azure-plugin'),
                'format'      => 'html',
                'subject'     => 'Volunteer Confirmation — {event}',
                'body'        => $volunteer_body,
                'intro'       => __('Thank you for volunteering!', 'azure-plugin'),
                'raw'         => $volunteer_raw,
                'tokens'      => $volunteer_tokens,
            ),
            'volunteer_reminder' => array(
                'group'       => __('Volunteers', 'azure-plugin'),
                'label'       => __('Volunteer reminder', 'azure-plugin'),
                'description' => __('Sent before a shift when reminders are turned on.', 'azure-plugin'),
                'format'      => 'html',
                'subject'     => '{site_name} volunteering reminder',
                'body'        => $volunteer_body,
                'intro'       => __('This is a reminder that you are volunteering soon.', 'azure-plugin'),
                'raw'         => $volunteer_raw,
                'tokens'      => $volunteer_tokens,
            ),
            'membership_guest_account' => array(
                'group'       => __('Accounts', 'azure-plugin'),
                'label'       => __('Membership account ready', 'azure-plugin'),
                'description' => __('Sent when a guest membership checkout creates a parent account. A preview send adds its own banner above this message.', 'azure-plugin'),
                'format'      => 'html',
                'subject'     => 'Your {site_name} account is ready',
                'body'        => self::membership_guest_body(),
                'raw'         => array('preview_banner'),
                'tokens'      => array('{site_name}', '{first_name}', '{username}', '{password}', '{login_url}', '{support_email}', '{preview_banner}'),
            ),
            'parent_welcome' => array(
                'group'       => __('Accounts', 'azure-plugin'),
                'label'       => __('Parent welcome', 'azure-plugin'),
                'description' => __('Sent when an imported parent is given a temporary password and allowed to sign in.', 'azure-plugin'),
                'format'      => 'html',
                'subject'     => 'Welcome to {site_name} — your PTA account is ready',
                'body'        => self::parent_welcome_body(),
                'tokens'      => array('{site_name}', '{first_name}', '{username}', '{password}', '{login_url}'),
            ),
            'parent_activation' => array(
                'group'       => __('Accounts', 'azure-plugin'),
                'label'       => __('Parent activation', 'azure-plugin'),
                'description' => __('Sent with the one-time sign-in link and temporary password for a parent who still needs to activate.', 'azure-plugin'),
                'format'      => 'html',
                'subject'     => 'Activate your {site_name} account',
                'body'        => self::parent_activation_body(),
                'tokens'      => array('{site_name}', '{greeting}', '{activation_url}', '{temp_password}', '{support_email}'),
            ),
            'parent_registration' => array(
                'group'       => __('Accounts', 'azure-plugin'),
                'label'       => __('New parent registration', 'azure-plugin'),
                'description' => __('Sent when someone registers through a form with "Create a parent account" turned on. The link activates the account and asks them to choose a password; it expires in 7 days.', 'azure-plugin'),
                'format'      => 'html',
                'subject'     => 'Activate your {site_name} account',
                'body'        => self::parent_registration_body(),
                'tokens'      => array('{site_name}', '{first_name}', '{activation_url}'),
            ),
            'parent_registration_exists' => array(
                'group'       => __('Accounts', 'azure-plugin'),
                'label'       => __('Registration: already registered', 'azure-plugin'),
                'description' => __('Sent instead of a new account when someone registers with an email that already has an account.', 'azure-plugin'),
                'format'      => 'html',
                'subject'     => 'You already have a {site_name} account',
                'body'        => self::parent_registration_exists_body(),
                'tokens'      => array('{site_name}', '{first_name}', '{login_url}', '{reset_url}'),
            ),
            'office365_welcome' => array(
                'group'       => __('Accounts', 'azure-plugin'),
                'label'       => __('Office 365 account', 'azure-plugin'),
                'description' => __('Sent when a board account is created in Microsoft 365.', 'azure-plugin'),
                'format'      => 'plain',
                'subject'     => 'Welcome to {org_name} - Your Office 365 Account',
                'body'        => "Hello {first_name},\n\nWelcome to {org_name}! Your Office 365 account has been created.\n\nYour login credentials:\nUsername: {username}\nTemporary Password: {password}\n\nYou will be required to change your password on first login.\n\nYou can access your account at: https://office.com\n\nIf you have any questions, please contact the PTA administrators.\n\nBest regards,\n{org_team}",
                'tokens'      => array('{first_name}', '{org_name}', '{username}', '{password}', '{org_team}'),
            ),
            'backup_notification' => array(
                'group'       => __('System', 'azure-plugin'),
                'label'       => __('Backup notification', 'azure-plugin'),
                'description' => __('Sent to the backup notification address when a backup finishes or fails.', 'azure-plugin'),
                'format'      => 'plain',
                'subject'     => '{status_label} - {site_name}',
                'body'        => "Backup notification from {site_name}\n\nStatus: {status}\nMessage: {message}\nTime: {time}\nSite URL: {site_url}\nBackup ID: {backup_id}\nNext scheduled backup: {next_backup}\n",
                'tokens'      => array('{status_label}', '{site_name}', '{status}', '{message}', '{time}', '{site_url}', '{backup_id}', '{next_backup}'),
            ),
        ) + self::custom_catalog();
    }

    // ─── Custom (form) emails ─────────────────────────────────────────

    public static function form_tokens() {
        return array('{form_title}', '{field:name}', '{all_fields}', '{submitted_at}', '{entry_link}', '{submitter_email}', '{site_name}');
    }

    public static function is_custom_key($key) {
        return is_string($key) && preg_match('/^' . self::CUSTOM_PREFIX . '\d+$/', $key) === 1;
    }

    private static function custom_store() {
        $saved = function_exists('get_option') ? get_option(self::CUSTOM_OPTION, array()) : array();
        $saved = is_array($saved) ? $saved : array();
        $emails = isset($saved['emails']) && is_array($saved['emails']) ? $saved['emails'] : array();
        return array(
            'next'   => max(1, (int) ($saved['next'] ?? 1)),
            'emails' => $emails,
        );
    }

    /**
     * @return array<string,array{label:string,subject:string,body:string}>
     */
    public static function custom_emails() {
        $out = array();
        foreach (self::custom_store()['emails'] as $key => $email) {
            if (self::is_custom_key($key) && is_array($email)) {
                $out[$key] = array(
                    'label'   => (string) ($email['label'] ?? ''),
                    'subject' => (string) ($email['subject'] ?? ''),
                    'body'    => (string) ($email['body'] ?? ''),
                );
            }
        }
        return $out;
    }

    private static function custom_catalog() {
        $out = array();
        foreach (self::custom_emails() as $key => $email) {
            $out[$key] = array(
                'group'       => __('Forms', 'azure-plugin'),
                'label'       => $email['label'] !== '' ? $email['label'] : $key,
                'description' => __('Your own email. Form rules in System > Rules send it when a form is submitted.', 'azure-plugin'),
                'format'      => 'html',
                'subject'     => $email['subject'],
                'body'        => $email['body'],
                'raw'         => array('all_fields'),
                'tokens'      => self::form_tokens(),
                'custom'      => true,
            );
        }
        return $out;
    }

    public static function default_custom_subject() {
        return '{form_title}: new response';
    }

    public static function default_custom_body() {
        return <<<'HTML'
<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;max-width:600px;margin:0 auto;color:#1d2327;">
  <p style="margin:0 0 8px;font-size:13px;color:#646970;">{site_name}</p>
  <h2 style="margin:0 0 12px;font-size:20px;">{form_title}</h2>
  <p style="margin:0 0 16px;font-size:15px;line-height:1.5;">A new response was submitted on {submitted_at}.</p>
  {all_fields}
  <p style="margin:16px 0 0;font-size:14px;"><a href="{entry_link}" style="color:#2271b1;">View this response in PTA Tools</a></p>
</div>
HTML;
    }

    /**
     * @return string The new key.
     */
    public static function create_custom($label = '') {
        $store = self::custom_store();
        $n = $store['next'];
        while (isset($store['emails'][self::CUSTOM_PREFIX . $n])) {
            $n++;
        }
        $key = self::CUSTOM_PREFIX . $n;
        $label = function_exists('sanitize_text_field') ? sanitize_text_field((string) $label) : trim(strip_tags((string) $label));
        $store['emails'][$key] = array(
            'label'   => $label !== '' ? $label : sprintf(__('Form email %d', 'azure-plugin'), $n),
            'subject' => self::default_custom_subject(),
            'body'    => self::default_custom_body(),
        );
        $store['next'] = $n + 1;
        update_option(self::CUSTOM_OPTION, $store, false);
        return $key;
    }

    public static function save_custom($key, $label, $subject, $body) {
        if (!self::is_custom_key($key)) {
            return;
        }
        $store = self::custom_store();
        if (!isset($store['emails'][$key])) {
            return;
        }
        $clean = function ($v) {
            return function_exists('sanitize_text_field') ? sanitize_text_field((string) $v) : trim(strip_tags((string) $v));
        };
        $label = $clean($label);
        $subject = $clean($subject);
        $body = str_replace("\0", '', (string) $body);
        $store['emails'][$key] = array(
            'label'   => $label !== '' ? $label : (string) ($store['emails'][$key]['label'] ?? $key),
            'subject' => $subject !== '' ? $subject : self::default_custom_subject(),
            'body'    => trim($body) !== '' ? $body : self::default_custom_body(),
        );
        update_option(self::CUSTOM_OPTION, $store, false);
    }

    public static function delete_custom($key) {
        if (!self::is_custom_key($key)) {
            return;
        }
        $store = self::custom_store();
        unset($store['emails'][$key]);
        update_option(self::CUSTOM_OPTION, $store, false);
    }

    public static function overrides() {
        if (!function_exists('get_option')) {
            return array();
        }
        $saved = get_option(self::OPTION, array());
        return is_array($saved) ? $saved : array();
    }

    public static function message_for($key) {
        $defaults = self::catalog();
        if (!isset($defaults[$key])) {
            return null;
        }
        $msg = $defaults[$key];
        if (!empty($msg['custom'])) {
            return $msg;
        }
        $saved = self::overrides();
        if (!empty($saved[$key]['subject'])) {
            $msg['subject'] = (string) $saved[$key]['subject'];
        }
        if (isset($saved[$key]['body']) && trim((string) $saved[$key]['body']) !== '') {
            $msg['body'] = (string) $saved[$key]['body'];
        }
        return $msg;
    }

    public static function apply($text, array $vars) {
        $replace = array();
        foreach ($vars as $key => $value) {
            $replace['{' . $key . '}'] = (string) $value;
        }
        return strtr((string) $text, $replace);
    }

    /**
     * @return array{0:string,1:string} subject, body
     */
    public static function render($key, array $vars) {
        $msg = self::message_for($key);
        if (!$msg) {
            return array('', '');
        }
        if (!isset($vars['intro']) && !empty($msg['intro'])) {
            $vars['intro'] = $msg['intro'];
        }
        if (!array_key_exists('site_name', $vars) || $vars['site_name'] === '') {
            $vars['site_name'] = self::site_name();
        }
        $raw = isset($msg['raw']) && is_array($msg['raw']) ? $msg['raw'] : array();
        $html = isset($msg['format']) && $msg['format'] === 'html';
        $body_vars = $vars;
        if ($html) {
            foreach ($body_vars as $name => $value) {
                if (in_array($name, $raw, true)) {
                    continue;
                }
                $body_vars[$name] = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            }
        }
        return array(
            self::apply($msg['subject'], $vars),
            self::apply($msg['body'], $body_vars),
        );
    }

    public static function save_message($key, $subject, $body) {
        $defaults = self::catalog();
        if (!isset($defaults[$key]) || !empty($defaults[$key]['custom'])) {
            return;
        }
        $subject = function_exists('sanitize_text_field') ? sanitize_text_field($subject) : trim(strip_tags((string) $subject));
        $body = str_replace("\0", '', (string) $body);
        $all = self::overrides();
        if ($subject === $defaults[$key]['subject'] && $body === $defaults[$key]['body']) {
            unset($all[$key]);
        } elseif ($subject === '' && trim($body) === '') {
            unset($all[$key]);
        } else {
            $all[$key] = array(
                'subject' => $subject,
                'body'    => $body,
            );
        }
        update_option(self::OPTION, $all);
    }

    public static function reset_message($key) {
        $all = self::overrides();
        unset($all[$key]);
        update_option(self::OPTION, $all);
    }

    public static function save_from_request() {
        if (empty($_POST['azure_email_messages_save']) && empty($_POST['azure_email_message_reset'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        check_admin_referer('azure_email_messages');

        $reset = isset($_POST['azure_email_message_reset']) ? sanitize_key(wp_unslash($_POST['azure_email_message_reset'])) : '';
        $delete = isset($_POST['azure_email_custom_delete']) ? sanitize_key(wp_unslash($_POST['azure_email_custom_delete'])) : '';
        $create = !empty($_POST['azure_email_custom_create']);
        $subjects = isset($_POST['message_subject']) && is_array($_POST['message_subject']) ? wp_unslash($_POST['message_subject']) : array();
        $bodies = isset($_POST['message_body']) && is_array($_POST['message_body']) ? wp_unslash($_POST['message_body']) : array();
        $labels = isset($_POST['message_label']) && is_array($_POST['message_label']) ? wp_unslash($_POST['message_label']) : array();
        foreach (self::catalog() as $key => $msg) {
            if ($reset !== '' && $key === $reset) {
                self::reset_message($key);
                continue;
            }
            if (!empty($msg['custom'])) {
                if ($key === $delete) {
                    self::delete_custom($key);
                } elseif (isset($subjects[$key]) || isset($bodies[$key])) {
                    self::save_custom($key, $labels[$key] ?? '', $subjects[$key] ?? '', $bodies[$key] ?? '');
                }
                continue;
            }
            self::save_message(
                $key,
                isset($subjects[$key]) ? $subjects[$key] : '',
                isset($bodies[$key]) ? $bodies[$key] : ''
            );
        }

        $anchor = '';
        if ($create) {
            $anchor = '#azure-msg-' . self::create_custom('');
        }

        $args = array(
            'page'  => 'azure-plugin-emails',
            'tab'   => 'messages',
            'saved' => '1',
        );
        $return_rule = isset($_POST['return_rule']) ? absint($_POST['return_rule']) : 0;
        if ($return_rule > 0) {
            $args['return_rule'] = $return_rule;
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')) . $anchor);
        exit;
    }

    private static function volunteer_body() {
        return <<<'HTML'
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f6f6f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f6f6;padding:24px 0;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.05);overflow:hidden;">
        <tr><td style="padding:32px 32px 16px 32px;">
          <p style="margin:0 0 8px 0;font-size:13px;color:#646970;">{site_name}</p>
          <h1 style="margin:0 0 12px 0;font-size:22px;color:#1d2327;">{event}</h1>
          <p style="margin:0 0 16px 0;font-size:15px;line-height:1.5;color:#3c434a;">Hi {name},</p>
          <p style="margin:0 0 16px 0;font-size:15px;line-height:1.5;color:#3c434a;">{intro}</p>
          {shifts}
          {location}
          {subscribe_button}
          {event_link}
          <p style="margin:0;font-size:15px;line-height:1.5;color:#3c434a;">Thank you for helping out!</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;
    }

    private static function membership_guest_body() {
        return <<<'HTML'
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f6f6f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f6f6;padding:24px 0;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.05);overflow:hidden;">
        <tr><td style="padding:32px 32px 16px 32px;">
          {preview_banner}
          <h1 style="margin:0 0 12px 0;font-size:22px;color:#1d2327;">Your {site_name} account is ready</h1>
          <p style="margin:0 0 16px 0;font-size:15px;line-height:1.5;color:#3c434a;">Hi {first_name},</p>
          <p style="margin:0 0 16px 0;font-size:15px;line-height:1.5;color:#3c434a;">
            Thank you for joining the {site_name}. We created an account from your membership checkout so you can sign in and use your member benefits.
          </p>
          <table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 20px 0;border-collapse:collapse;">
            <tr>
              <td style="padding:6px 16px 6px 0;font-size:15px;color:#646970;">Username</td>
              <td style="padding:6px 0;font-size:15px;color:#1d2327;"><strong>{username}</strong></td>
            </tr>
            <tr>
              <td style="padding:6px 16px 6px 0;font-size:15px;color:#646970;">Password</td>
              <td style="padding:6px 0;font-size:15px;color:#1d2327;"><code style="display:inline-block;padding:6px 10px;background:#f1f3f5;border:1px solid #d1d5db;border-radius:4px;font-family:Consolas,Menlo,'SF Mono',monospace;font-size:16px;letter-spacing:0.4px;">{password}</code></td>
            </tr>
          </table>
          <p style="text-align:center;margin:24px 0;">
            <a href="{login_url}" style="display:inline-block;padding:14px 28px;background:#0078d4;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;font-size:15px;">Sign in</a>
          </p>
          <p style="margin:0 0 12px 0;font-size:15px;line-height:1.5;color:#3c434a;">With this account you can:</p>
          <ol style="margin:0 0 16px 24px;padding:0;font-size:15px;line-height:1.6;color:#3c434a;">
            <li>Save your family profile for faster checkout next time</li>
            <li>Get member pricing in the store</li>
            <li>Join the member parent directory (optional — you choose whether to be listed)</li>
          </ol>
          <p style="margin:0 0 8px 0;font-size:15px;line-height:1.5;color:#3c434a;">
            Please change this password after you sign in. If the button does not work, open:<br>
            <span style="word-break:break-all;color:#0073aa;">{login_url}</span>
          </p>
        </td></tr>
        <tr><td style="padding:16px 32px 32px 32px;border-top:1px solid #e0e0e0;">
          <p style="margin:0;font-size:12px;color:#646970;line-height:1.5;">
            Questions? Email <a href="mailto:{support_email}" style="color:#0073aa;">{support_email}</a>.
            <br>You are receiving this because you purchased a {site_name} membership.
          </p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;
    }

    private static function parent_welcome_body() {
        return <<<'HTML'
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <h2 style="color: #333;">Welcome to {site_name}</h2>
    <p>Hi {first_name},</p>
    <p>The {site_name} PTA has set up an account for you so you can review and update your family's information for school activities.</p>
    <table style="margin: 18px 0; border-collapse: collapse;">
        <tr>
            <td style="padding: 6px 12px 6px 0; color: #555;"><strong>Username</strong></td>
            <td style="padding: 6px 0;">{username}</td>
        </tr>
        <tr>
            <td style="padding: 6px 12px 6px 0; color: #555;"><strong>Temporary password</strong></td>
            <td style="padding: 6px 0;"><code style="background: #f5f5f5; padding: 4px 8px; border-radius: 3px;">{password}</code></td>
        </tr>
    </table>
    <p style="margin: 25px 0;">
        <a href="{login_url}" style="background: #0073aa; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 3px; display: inline-block;">Sign in</a>
    </p>
    <p>You'll be asked to set a new password on your first sign-in. From there you can edit your children's grade, teacher, allergies, emergency contact, and other details whenever they change.</p>
    <p style="color: #666; font-size: 13px;">If you weren't expecting this email, please reply to this message and we'll sort it out.</p>
</div>
HTML;
    }

    private static function parent_activation_body() {
        return <<<'HTML'
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f6f6f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f6f6;padding:24px 0;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.05);overflow:hidden;">
        <tr><td style="padding:32px 32px 16px 32px;">
          <h1 style="margin:0 0 12px 0;font-size:22px;color:#1d2327;">Welcome to {site_name}</h1>
          <p style="margin:0 0 16px 0;font-size:15px;line-height:1.5;color:#3c434a;">{greeting}</p>
          <p style="margin:0 0 16px 0;font-size:15px;line-height:1.5;color:#3c434a;">
            We've created an account for you on the {site_name} family portal so you can sign up
            for events, manage volunteer slots, and stay in the loop with PTSA news.
          </p>
          <p style="margin:0 0 16px 0;font-size:15px;line-height:1.5;color:#3c434a;">
            <strong>Step 1.</strong> Click the button below to sign in. This link is single-use
            and expires in 14 days.
          </p>
          <p style="text-align:center;margin:24px 0;">
            <a href="{activation_url}" style="display:inline-block;padding:14px 28px;background:#0078d4;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;font-size:15px;">Sign in &amp; activate</a>
          </p>
          <p style="margin:0 0 16px 0;font-size:15px;line-height:1.5;color:#3c434a;">
            <strong>Step 2.</strong> Once you're in, you'll be asked to pick a password
            you'll remember. Use this temporary password as the &ldquo;Current password&rdquo;:
          </p>
          <p style="text-align:center;margin:8px 0 24px 0;">
            <span style="display:inline-block;padding:12px 18px;background:#f1f3f5;border:1px solid #d1d5db;border-radius:6px;font-family:Consolas,Menlo,'SF Mono',monospace;font-size:18px;letter-spacing:0.5px;color:#1d2327;">{temp_password}</span>
          </p>
          <p style="margin:0 0 8px 0;font-size:13px;line-height:1.5;color:#646970;">
            If the button doesn't work, copy and paste this link into your browser:<br>
            <span style="word-break:break-all;color:#0073aa;">{activation_url}</span>
          </p>
        </td></tr>
        <tr><td style="padding:16px 32px 32px 32px;border-top:1px solid #e0e0e0;">
          <p style="margin:0;font-size:12px;color:#646970;line-height:1.5;">
            Questions? Send an email to <a href="mailto:{support_email}" style="color:#0073aa;">{support_email}</a>.
            <br>You're receiving this because we have you on file as a current {site_name} family.
          </p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;
    }

    private static function parent_registration_body() {
        return <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 560px; margin: 0 auto; color: #1d2327;">
    <h2 style="margin: 0 0 12px;">Welcome to {site_name}</h2>
    <p>Hi {first_name},</p>
    <p>Thanks for registering. Your family's details are saved. Press the button below to activate your account and choose a password.</p>
    <p style="text-align: center; margin: 28px 0;">
        <a href="{activation_url}" style="display: inline-block; padding: 14px 28px; background: #0078d4; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600;">Activate my account</a>
    </p>
    <p style="font-size: 13px; color: #646970;">If the button doesn't work, copy this link into your browser:<br><span style="word-break: break-all;">{activation_url}</span></p>
    <p style="font-size: 13px; color: #646970;">The link expires in 7 days. If you didn't register, ignore this email and the account will be deleted automatically.</p>
</div>
HTML;
    }

    private static function parent_registration_exists_body() {
        return <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 560px; margin: 0 auto; color: #1d2327;">
    <h2 style="margin: 0 0 12px;">You already have an account</h2>
    <p>Hi {first_name},</p>
    <p>Someone tried to register on {site_name} with this email address, but it already has an account, so we didn't create a new one or change anything.</p>
    <p>You can <a href="{login_url}">sign in here</a>. If you've forgotten your password, <a href="{reset_url}">reset it here</a>.</p>
    <p style="font-size: 13px; color: #646970;">If this wasn't you, you can ignore this email.</p>
</div>
HTML;
    }
}

add_action('admin_init', array('Azure_Email_Messages', 'save_from_request'));
