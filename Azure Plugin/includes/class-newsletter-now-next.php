<?php
/**
 * Compact This Week / Next Week table for newsletter HTML.
 *
 * Website [up-next] themes are card-heavy and not email-safe. This
 * renderer is a 2-column table with short date+title lines.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Newsletter_Now_Next {

    const LIMIT = 5;
    const SHORTCODE = 'nl-now-next';

    /**
     * Build the email table from two event lists.
     *
     * Each event is an array with title, url, start_date, all_day.
     *
     * @param array $this_week
     * @param array $next_week
     * @param array $options this_week_title, next_week_title, empty_message, limit, enable_links
     * @return string
     */
    public static function render($this_week, $next_week, $options = array()) {
        $this_title = isset($options['this_week_title']) ? (string) $options['this_week_title'] : 'This Week';
        $next_title = isset($options['next_week_title']) ? (string) $options['next_week_title'] : 'Next Week';
        $empty = isset($options['empty_message']) ? (string) $options['empty_message'] : 'No events';
        $limit = isset($options['limit']) ? (int) $options['limit'] : self::LIMIT;
        if ($limit < 1) {
            $limit = self::LIMIT;
        }
        $enable_links = self::parse_enable_links(isset($options['enable_links']) ? $options['enable_links'] : false);

        $this_week = array_slice(is_array($this_week) ? $this_week : array(), 0, $limit);
        $next_week = array_slice(is_array($next_week) ? $next_week : array(), 0, $limit);

        $cell = 'width="50%" valign="top" class="nl-stack-col nl-column" style="width:50%;padding:10px;vertical-align:top;font-family:Arial,Helvetica,sans-serif;"';

        return '<table class="nl-now-next nl-stack-cols" width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">'
            . '<tr>'
            . '<td ' . $cell . '>' . self::render_column($this_title, $this_week, $empty, $enable_links) . '</td>'
            . '<td ' . $cell . '>' . self::render_column($next_title, $next_week, $empty, $enable_links) . '</td>'
            . '</tr></table>';
    }

    /**
     * Shortcode / option values: true, 1, yes, on.
     *
     * @param mixed $value
     * @return bool
     */
    public static function parse_enable_links($value) {
        if ($value === true || $value === 1) {
            return true;
        }
        if ($value === false || $value === 0 || $value === null) {
            return false;
        }
        $v = strtolower(trim((string) $value));
        return in_array($v, array('1', 'true', 'yes', 'on'), true);
    }

    /**
     * One compact event line: date · title · time.
     *
     * @param array $event
     * @param bool  $enable_links
     * @return string
     */
    public static function format_line($event, $enable_links = false) {
        if (!is_array($event)) {
            return '';
        }
        $title = isset($event['title']) ? trim((string) $event['title']) : '';
        if ($title === '') {
            return '';
        }
        $start = isset($event['start_date']) ? strtotime((string) $event['start_date']) : false;
        $date = $start ? self::format_date($start, 'D n/j') : '';
        $all_day = !empty($event['all_day']);
        $time = ($start && !$all_day) ? self::format_date($start, 'g:ia') : '';
        $url = isset($event['url']) ? (string) $event['url'] : '';
        $enable_links = self::parse_enable_links($enable_links);

        $parts = array();
        if ($date !== '') {
            $parts[] = '<span style="color:#666666;">' . esc_html($date) . '</span>';
        }
        $title_html = '<span style="color:#2271b1;">' . esc_html($title) . '</span>';
        if ($enable_links && $url !== '') {
            $href = function_exists('esc_url') ? esc_url($url) : htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $title_html = '<a href="' . $href . '" target="_blank" style="color:#2271b1;text-decoration:none;">' . esc_html($title) . '</a>';
        }
        $parts[] = $title_html;
        if ($time !== '') {
            $parts[] = '<span style="color:#666666;">' . esc_html($time) . '</span>';
        }

        return '<p style="margin:0 0 6px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:19px;mso-line-height-rule:exactly;color:#333333;">'
            . implode(' · ', $parts)
            . '</p>';
    }

    /**
     * @param string $title
     * @param array  $events
     * @param string $empty
     * @param bool   $enable_links
     * @return string
     */
    private static function render_column($title, $events, $empty, $enable_links = false) {
        $html = '<p style="margin:0 0 8px 0;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:18px;mso-line-height-rule:exactly;font-weight:bold;color:#2271b1;">'
            . esc_html($title)
            . '</p>';

        $lines = '';
        foreach ($events as $event) {
            $lines .= self::format_line($event, $enable_links);
        }
        if ($lines === '') {
            $lines = '<p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:19px;mso-line-height-rule:exactly;color:#888888;">'
                . esc_html($empty)
                . '</p>';
        }

        return $html . $lines;
    }

    /**
     * @param int    $timestamp
     * @param string $format
     * @return string
     */
    private static function format_date($timestamp, $format) {
        if (function_exists('date_i18n')) {
            return date_i18n($format, $timestamp);
        }
        return date($format, $timestamp);
    }
}
