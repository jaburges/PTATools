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
     * @param array $options this_week_title, next_week_title, empty_message, limit
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

        $this_week = array_slice(is_array($this_week) ? $this_week : array(), 0, $limit);
        $next_week = array_slice(is_array($next_week) ? $next_week : array(), 0, $limit);

        $cell = 'width="50%" valign="top" class="nl-stack-col nl-column" style="width:50%;padding:10px;vertical-align:top;font-family:Arial,Helvetica,sans-serif;"';

        return '<table class="nl-now-next nl-stack-cols" width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">'
            . '<tr>'
            . '<td ' . $cell . '>' . self::render_column($this_title, $this_week, $empty) . '</td>'
            . '<td ' . $cell . '>' . self::render_column($next_title, $next_week, $empty) . '</td>'
            . '</tr></table>';
    }

    /**
     * One compact event line: date · title · time.
     *
     * @param array $event
     * @return string
     */
    public static function format_line($event) {
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

        $parts = array();
        if ($date !== '') {
            $parts[] = '<span style="color:#666666;">' . esc_html($date) . '</span>';
        }
        $title_html = esc_html($title);
        if ($url !== '') {
            $href = function_exists('esc_url') ? esc_url($url) : htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $title_html = '<a href="' . $href . '" target="_blank" style="color:#2271b1;text-decoration:none;">' . $title_html . '</a>';
        }
        $parts[] = $title_html;
        if ($time !== '') {
            $parts[] = '<span style="color:#666666;">' . esc_html($time) . '</span>';
        }

        return '<p style="margin:0 0 6px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.45;color:#333333;">'
            . implode(' · ', $parts)
            . '</p>';
    }

    /**
     * @param string $title
     * @param array  $events
     * @param string $empty
     * @return string
     */
    private static function render_column($title, $events, $empty) {
        $html = '<p style="margin:0 0 8px 0;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.3;font-weight:bold;color:#2271b1;">'
            . esc_html($title)
            . '</p>';

        $lines = '';
        foreach ($events as $event) {
            $lines .= self::format_line($event);
        }
        if ($lines === '') {
            $lines = '<p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.45;color:#888888;">'
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
