<?php
/**
 * Upcoming events from PTA Tools' shared events feed (in-process, no HTTP).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @return array[] Each: title, url, start (DateTimeImmutable), all_day, venue.
 */
function lwptsa_upcoming_events($count, $category = '') {
    if (!class_exists('Azure_Shared_Feeds') || !class_exists('WP_REST_Request')) {
        return array();
    }
    $request = new WP_REST_Request('GET', '/pta-tools/v1/feed/events');
    $request->set_param('limit', (int) $count);
    $request->set_param('days', 120);
    if ($category !== '') {
        $request->set_param('category', $category);
    }
    $response = Azure_Shared_Feeds::get_instance()->rest_events($request);
    if (is_wp_error($response)) {
        return array();
    }
    $data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
    if (!is_array($data) || empty($data['events']) || !is_array($data['events'])) {
        return array();
    }

    $tz     = wp_timezone();
    $events = array();
    foreach ($data['events'] as $e) {
        try {
            $start = (new DateTimeImmutable((string) $e['start']))->setTimezone($tz);
        } catch (Exception $ex) {
            continue;
        }
        $events[] = array(
            'title'   => (string) $e['title'],
            'url'     => (string) $e['url'],
            'start'   => $start,
            'all_day' => !empty($e['all_day']),
            'venue'   => (string) $e['venue'],
        );
    }
    return $events;
}

function lwptsa_event_when(array $event, $with_day = false) {
    $parts = array();
    if ($with_day) {
        $parts[] = wp_date('D, M j', $event['start']->getTimestamp());
    }
    $parts[] = $event['all_day'] ? __('All day', 'lwptsa-recap') : wp_date('g:i a', $event['start']->getTimestamp());
    return implode(' · ', $parts);
}

function lwptsa_calendar_url() {
    return (string) apply_filters('lwptsa_calendar_url', home_url('/calendar/'));
}
