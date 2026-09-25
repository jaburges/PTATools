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
        );
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
        if (!isset($defaults[$key])) {
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
        $subjects = isset($_POST['message_subject']) && is_array($_POST['message_subject']) ? wp_unslash($_POST['message_subject']) : array();
        $bodies = isset($_POST['message_body']) && is_array($_POST['message_body']) ? wp_unslash($_POST['message_body']) : array();
        foreach (array_keys(self::catalog()) as $key) {
            if ($reset !== '' && $key === $reset) {
                self::reset_message($key);
                continue;
            }
            self::save_message(
                $key,
                isset($subjects[$key]) ? $subjects[$key] : '',
                isset($bodies[$key]) ? $bodies[$key] : ''
            );
        }

        wp_safe_redirect(add_query_arg(array(
            'page'  => 'azure-plugin-emails',
            'tab'   => 'messages',
            'saved' => '1',
        ), admin_url('admin.php')));
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
}

add_action('admin_init', array('Azure_Email_Messages', 'save_from_request'));
