<?php
/**
 * LWSD volunteer roster helpers: name matching, expiry parsing, and status.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Lwsd_Volunteer {

    const META_EXPIRES = 'pta_lwsd_volunteer_expires';
    const META_ACTIVE  = 'pta_lwsd_volunteer_active';

    /**
     * Trim, collapse internal whitespace, and lowercase a name fragment.
     */
    public static function normalize_name($name) {
        $name = trim((string) $name);
        $name = preg_replace('/\s+/', ' ', $name);
        return strtolower($name);
    }

    /**
     * Stable lookup key for first + last name matching.
     */
    public static function name_key($first, $last) {
        return self::normalize_name($first) . '|' . self::normalize_name($last);
    }

    /**
     * Parse an expiry value to Y-m-d, or empty string if unusable.
     *
     * @param mixed $value
     */
    public static function parse_expiry($value) {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $formats = array('Y-m-d', 'Y-m-d H:i:s', 'n/j/Y');
        foreach ($formats as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $value);
            if ($dt instanceof DateTimeImmutable) {
                $errors = DateTimeImmutable::getLastErrors();
                if (empty($errors['warning_count']) && empty($errors['error_count'])) {
                    return $dt->format('Y-m-d');
                }
            }
        }

        return '';
    }

    /**
     * True when clearance expires on or after $today.
     */
    public static function is_active($expires_on, $today) {
        $expires_on = (string) $expires_on;
        if ($expires_on === '') {
            return false;
        }
        return $expires_on >= $today;
    }

    /**
     * True when clearance covers the event date (expiry on or after event day).
     */
    public static function is_approved_for_event($expires_on, $event_date) {
        $expires_on = (string) $expires_on;
        $event_date = (string) $event_date;
        if ($expires_on === '' || $event_date === '') {
            return false;
        }
        return $expires_on >= $event_date;
    }

    /**
     * Match roster rows to WordPress users by normalized first|last name key.
     *
     * @param array $roster_rows
     * @param array $users
     * @return array
     */
    public static function match_rows(array $roster_rows, array $users) {
        $by_key = array();
        foreach ($users as $user) {
            $key = self::name_key($user['first_name'] ?? '', $user['last_name'] ?? '');
            if (!isset($by_key[$key])) {
                $by_key[$key] = array();
            }
            $by_key[$key][] = (int) ($user['ID'] ?? 0);
        }

        $out = array();
        foreach ($roster_rows as $row) {
            $matched = $row;
            $key = self::name_key($row['first'] ?? '', $row['last'] ?? '');
            $ids = $by_key[$key] ?? array();

            if (count($ids) === 1) {
                $matched['match_state'] = 'matched';
                $matched['user_id'] = $ids[0];
            } elseif (count($ids) > 1) {
                $matched['match_state'] = 'ambiguous';
                $matched['user_id'] = 0;
            } else {
                $matched['match_state'] = 'unmatched';
                $matched['user_id'] = 0;
            }

            $out[] = $matched;
        }

        return $out;
    }

    /**
     * Derive volunteer clearance status from stored expiry meta.
     *
     * @return array{active:bool,approved_for_event:bool,reason:string,expires_on:string}
     */
    public static function status_from_meta($expires_on, $event_date, $today) {
        $expires_on = (string) $expires_on;
        $event_date = (string) $event_date;
        $today = (string) $today;

        if ($expires_on === '') {
            return array(
                'active'             => false,
                'approved_for_event' => false,
                'reason'             => 'not_on_roster',
                'expires_on'         => '',
            );
        }

        $active = self::is_active($expires_on, $today);
        $approved = self::is_approved_for_event($expires_on, $event_date);

        if ($expires_on < $today) {
            return array(
                'active'             => false,
                'approved_for_event' => false,
                'reason'             => 'expired',
                'expires_on'         => $expires_on,
            );
        }

        if ($event_date !== '' && $expires_on < $event_date) {
            return array(
                'active'             => $active,
                'approved_for_event' => false,
                'reason'             => 'expires_before_event',
                'expires_on'         => $expires_on,
            );
        }

        return array(
            'active'             => $active,
            'approved_for_event' => $approved,
            'reason'             => 'ok',
            'expires_on'         => $expires_on,
        );
    }

    /**
     * Dashboard widget counts from matched roster rows.
     *
     * @return array{active:int,expiring:int,expired:int,unmatched:int,ambiguous:int,total:int}
     */
    public static function widget_stats(array $roster_rows, $today, $expiring_days = 14) {
        $stats = array(
            'active'    => 0,
            'expiring'  => 0,
            'expired'   => 0,
            'unmatched' => 0,
            'ambiguous' => 0,
            'total'     => count($roster_rows),
        );

        $expiring_until = (new DateTimeImmutable($today))->modify('+' . (int) $expiring_days . ' days')->format('Y-m-d');

        foreach ($roster_rows as $row) {
            $match_state = $row['match_state'] ?? '';
            $expires_on = (string) ($row['expires_on'] ?? '');

            if ($match_state === 'unmatched') {
                $stats['unmatched']++;
            } elseif ($match_state === 'ambiguous') {
                $stats['ambiguous']++;
            }

            if ($expires_on !== '' && $expires_on < $today) {
                $stats['expired']++;
            }

            if ($match_state === 'matched' && self::is_active($expires_on, $today)) {
                $stats['active']++;
            }

            if ($expires_on !== '' && $expires_on >= $today && $expires_on <= $expiring_until) {
                $stats['expiring']++;
            }
        }

        return $stats;
    }

    /**
     * Matched users with email whose clearance expires within the reminder window.
     */
    public static function expiring_contactable(array $matched_users, $today, $days = 14) {
        $expiring_until = (new DateTimeImmutable($today))->modify('+' . (int) $days . ' days')->format('Y-m-d');
        $out = array();

        foreach ($matched_users as $user) {
            if (($user['match_state'] ?? '') !== 'matched') {
                continue;
            }

            $email = trim((string) ($user['user_email'] ?? ''));
            if ($email === '' || !self::is_usable_email($email)) {
                continue;
            }

            $expires_on = (string) ($user['expires_on'] ?? '');
            if ($expires_on === '' || !self::is_active($expires_on, $today) || $expires_on > $expiring_until) {
                continue;
            }

            $out[] = $user;
        }

        return $out;
    }

    /**
     * @param string $email
     */
    private static function is_usable_email($email) {
        if (function_exists('is_email')) {
            return (bool) is_email($email);
        }
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}
