<?php
/**
 * Calendar connection settings: one OAuth user, many shared mailboxes.
 *
 * Inviteable calendars need their own mailbox (e.g. math@). Display-only
 * calendars can stay as secondary calendars on calendar@. This helper is
 * the single place Embed, Sync, shortcodes, and diagnostics read that list.
 *
 * @package AzurePlugin
 * @since   3.147.80
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Calendar_Connections {

    /**
     * M365 account that holds the delegated Graph token.
     *
     * @return string
     */
    public static function user_email() {
        if (!class_exists('Azure_Settings')) {
            return '';
        }
        $settings = Azure_Settings::get_all_settings();
        return sanitize_email((string) ($settings['calendar_embed_user_email'] ?? ''));
    }

    /**
     * Shared mailboxes whose calendars we may read, unique and lowercased.
     *
     * @return string[]
     */
    public static function mailboxes() {
        $settings = class_exists('Azure_Settings') ? Azure_Settings::get_all_settings() : array();
        $list     = self::normalize_mailboxes($settings['calendar_embed_mailboxes'] ?? array());
        if (!empty($list)) {
            return $list;
        }
        $legacy = sanitize_email((string) ($settings['calendar_embed_mailbox_email'] ?? ''));
        return $legacy !== '' ? array($legacy) : array();
    }

    /**
     * First configured mailbox (legacy single-field readers).
     *
     * @return string
     */
    public static function primary_mailbox() {
        $mailboxes = self::mailboxes();
        return $mailboxes[0] ?? '';
    }

    /**
     * @param mixed $raw Array, comma/newline string, or single email.
     * @return string[]
     */
    public static function normalize_mailboxes($raw) {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,;]+/', $raw) ?: array();
        }
        if (!is_array($raw)) {
            return array();
        }
        $out = array();
        foreach ($raw as $email) {
            $email = strtolower(trim((string) $email));
            if ($email === '') {
                continue;
            }
            if (function_exists('sanitize_email')) {
                $email = sanitize_email($email);
            }
            if ($email === '') {
                continue;
            }
            if (function_exists('is_email') && !is_email($email)) {
                continue;
            }
            $out[$email] = $email;
        }
        return array_values($out);
    }

    /**
     * Persist user + mailbox list. Also writes calendar_embed_mailbox_email
     * as the first mailbox so older readers keep working.
     *
     * @param string $user_email
     * @param mixed  $mailboxes
     * @return bool
     */
    public static function save($user_email, $mailboxes) {
        $user_email = sanitize_email((string) $user_email);
        $list       = self::normalize_mailboxes($mailboxes);
        if (!class_exists('Azure_Settings')) {
            return false;
        }
        return (bool) Azure_Settings::update_settings(array(
            'calendar_embed_user_email'     => $user_email,
            'calendar_embed_mailboxes'      => $list,
            'calendar_embed_mailbox_email'  => $list[0] ?? '',
        ));
    }

    /**
     * Mailbox stored on a mapping row, or the primary mailbox if empty.
     *
     * @param object|null $mapping
     * @param string      $fallback
     * @return string
     */
    public static function mailbox_for_mapping($mapping, $fallback = '') {
        if (is_object($mapping) && !empty($mapping->mailbox_email)) {
            $email = sanitize_email((string) $mapping->mailbox_email);
            if ($email !== '') {
                return $email;
            }
        }
        if ($fallback !== '') {
            return sanitize_email($fallback);
        }
        return self::primary_mailbox();
    }

    /**
     * Scoped embed-enable key so calendar IDs cannot collide across mailboxes.
     *
     * @param string $mailbox
     * @param string $calendar_id
     * @return string
     */
    public static function embed_key($mailbox, $calendar_id) {
        return strtolower(trim((string) $mailbox)) . '::' . (string) $calendar_id;
    }

    /**
     * @param string $key
     * @return array{mailbox:string,calendar_id:string}
     */
    public static function parse_embed_key($key) {
        $key = (string) $key;
        $pos = strpos($key, '::');
        if ($pos === false) {
            return array('mailbox' => '', 'calendar_id' => $key);
        }
        return array(
            'mailbox'     => substr($key, 0, $pos),
            'calendar_id' => substr($key, $pos + 2),
        );
    }

    /**
     * @param mixed  $enabled_list
     * @param string $mailbox
     * @param string $calendar_id
     * @return bool
     */
    public static function is_embed_enabled($enabled_list, $mailbox, $calendar_id) {
        if (!is_array($enabled_list)) {
            return false;
        }
        $key = self::embed_key($mailbox, $calendar_id);
        if (in_array($key, $enabled_list, true)) {
            return true;
        }
        // Legacy rows stored the Graph calendar ID only.
        return in_array((string) $calendar_id, $enabled_list, true);
    }

    /**
     * @param array  $enabled_list
     * @param string $mailbox
     * @param string $calendar_id
     * @param bool   $enabled
     * @return array
     */
    public static function set_embed_enabled(array $enabled_list, $mailbox, $calendar_id, $enabled) {
        $key = self::embed_key($mailbox, $calendar_id);
        $id  = (string) $calendar_id;
        if ($enabled) {
            if (!in_array($key, $enabled_list, true)) {
                $enabled_list[] = $key;
            }
            return array_values($enabled_list);
        }
        $enabled_list = array_values(array_filter($enabled_list, function ($item) use ($key, $id) {
            return $item !== $key && $item !== $id;
        }));
        return $enabled_list;
    }

    /**
     * Composite option value for the Sync mapping dropdown.
     *
     * @param string $mailbox
     * @param string $calendar_id
     * @return string
     */
    public static function mapping_option_value($mailbox, $calendar_id) {
        return self::embed_key($mailbox, $calendar_id);
    }
}
