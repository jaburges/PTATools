<?php
/**
 * AcyMailing dynamic tag: {upcoming-events}
 *
 * Adds a one-click "Upcoming Events" entry to AcyMailing's Dynamic
 * Content picker so newsletter editors don't have to type the
 * `[up-next]` shortcode by hand. At preview/send time we substitute
 * each `{upcoming-events …}` token with the rendered output of the
 * existing `[up-next]` shortcode (Azure_Upcoming_Module), so the
 * newsletter and the website always agree on what "upcoming" means.
 *
 * Tag syntax in newsletter body:
 *   {upcoming-events}
 *   {upcoming-events|columns:2}
 *   {upcoming-events|columns:2|exclude-categories:Staff,Private}
 *
 * Pipe-separated options map directly onto `[up-next]` shortcode
 * attributes (kebab-case keys preserved). Anything not listed in
 * the editor options form can still be passed manually with the
 * same syntax.
 *
 * AcyMailing version compat: registers both the legacy and v8
 * filter names for tag declaration + content replacement, so the
 * same code works against AcyMailing 6.x, 7.x, and 8.x without
 * conditional checks against the AcyMailing version constant.
 *
 * @package AzurePlugin
 * @since   3.114
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_AcyMailing_Upcoming_Tag {

    const TAG_NAME  = 'upcoming-events';
    const TAG_LABEL = 'Upcoming Events';

    /** @var bool */
    private static $registered = false;

    /**
     * Idempotent registrar. Safe to call from multiple places.
     */
    public static function register() {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        // AcyMailing's filters become available shortly after `plugins_loaded`.
        // Defer to `init` so its bootstrap has run and class_exists() is reliable.
        add_action('init', array(__CLASS__, 'maybe_attach_hooks'), 20);
    }

    /**
     * Detects AcyMailing and, if present, wires the tag declaration +
     * content replacement filters. Cheap no-op when AcyMailing isn't
     * installed (the calling site uses it as a mail transport but
     * other deployments may not).
     */
    public static function maybe_attach_hooks() {
        if (!self::acymailing_is_loaded()) {
            return;
        }

        // 1. Surface the tag in the Dynamic Content picker. Both the
        //    AcyMailing 6/7 hook name and the v8 hook name are
        //    declared — AcyMailing 8 didn't preserve the old name.
        add_filter('onAcymDeclareTags',           array(__CLASS__, 'declare_tag'), 10, 1);
        add_filter('onAcymDeclareDynamicContent', array(__CLASS__, 'declare_tag'), 10, 1);

        // 2. Replace `{upcoming-events …}` with `do_shortcode('[up-next …]')`
        //    at preview AND send time. AcyMailing fires several
        //    different filters depending on context (web preview,
        //    transactional, queued sending, A/B variant). Hook the
        //    full set so we never miss a substitution path.
        $replace = array(__CLASS__, 'replace_in_email');
        add_filter('acym_replace_user_information', $replace, 10, 3);
        add_filter('onAcymReplaceUserInformation',  $replace, 10, 3);
        add_filter('acym_replace_tags',             $replace, 10, 3);
        add_filter('onAcymGenerateReport',          $replace, 10, 3);
    }

    /**
     * AcyMailing presence detection. We don't rely on a single class
     * because the namespace has shifted across major versions.
     *
     * @return bool
     */
    private static function acymailing_is_loaded() {
        return class_exists('\\AcyMailing\\Helpers\\MailerHelper')
            || class_exists('\\AcyMailing\\Classes\\MailClass')
            || function_exists('acym_get_helper');
    }

    /**
     * Tag declaration entry for AcyMailing's Dynamic Content picker.
     *
     * AcyMailing reads this array to render the picker modal: it
     * knows the tag id (`upcoming-events`), the display name, the
     * icon, and the option form (which produces the
     * `|columns:2|exclude-categories:…` suffix appended to the tag
     * when the user clicks Insert).
     *
     * @param array $tags Existing tag registry from AcyMailing core / other plugins.
     * @return array
     */
    public static function declare_tag($tags) {
        if (!is_array($tags)) {
            $tags = array();
        }

        $tags[self::TAG_NAME] = array(
            'name'        => __(self::TAG_LABEL, 'azure-plugin'),
            'description' => __('Renders the [up-next] PTA Tools upcoming events shortcode at send/preview time.', 'azure-plugin'),
            'icon'        => 'acymicon-calendar',
            'category'    => __('PTA Tools', 'azure-plugin'),
            'options'     => self::tag_options(),
        );

        return $tags;
    }

    /**
     * Schema for the picker's options form. AcyMailing renders these
     * as labelled inputs/selects in the Insert Tag modal. Keys MUST
     * match the `[up-next]` shortcode attribute names (kebab-case)
     * so the resulting tag round-trips into the shortcode without
     * remapping.
     *
     * @return array
     */
    private static function tag_options() {
        $bool = array('true' => __('Yes', 'azure-plugin'), 'false' => __('No', 'azure-plugin'));

        return array(
            'columns' => array(
                'type'    => 'select',
                'label'   => __('Columns', 'azure-plugin'),
                'options' => array('1' => '1', '2' => '2', '3' => '3'),
                'default' => '1',
            ),
            'current-week' => array(
                'type'    => 'select',
                'label'   => __('Show this week', 'azure-plugin'),
                'options' => $bool,
                'default' => 'true',
            ),
            'next-week' => array(
                'type'    => 'select',
                'label'   => __('Show next week', 'azure-plugin'),
                'options' => $bool,
                'default' => 'true',
            ),
            'show-coming-up' => array(
                'type'    => 'select',
                'label'   => __('Show "Coming up" section', 'azure-plugin'),
                'options' => $bool,
                'default' => 'true',
            ),
            'coming-up-days' => array(
                'type'    => 'number',
                'label'   => __('Coming up: days ahead', 'azure-plugin'),
                'min'     => 7,
                'max'     => 90,
                'default' => 30,
            ),
            'exclude-categories' => array(
                'type'        => 'text',
                'label'       => __('Exclude categories (comma-separated)', 'azure-plugin'),
                'placeholder' => __('Staff, Private', 'azure-plugin'),
                'default'     => '',
            ),
            'show-time' => array(
                'type'    => 'select',
                'label'   => __('Show event time', 'azure-plugin'),
                'options' => $bool,
                'default' => 'true',
            ),
            'link-titles' => array(
                'type'    => 'select',
                'label'   => __('Link event titles', 'azure-plugin'),
                'options' => $bool,
                'default' => 'true',
            ),
            'show-join-meeting' => array(
                'type'    => 'select',
                'label'   => __('Show "Join meeting" link', 'azure-plugin'),
                'options' => $bool,
                'default' => 'true',
            ),
        );
    }

    /**
     * AcyMailing's content-replacement filter. Runs at preview and
     * send time. Signature varies by AcyMailing version; we accept
     * up to three args defensively. The body may live on either
     * `$email->body` or `$email->bodyText` depending on context.
     *
     * @param object|array $email
     * @param mixed        $user
     * @param mixed        $send
     * @return object|array
     */
    public static function replace_in_email($email, $user = null, $send = null) {
        if (is_array($email)) {
            // Some AcyMailing builds pass an array {body, subject, …}.
            foreach (array('body', 'bodyText', 'subject') as $key) {
                if (!empty($email[$key]) && is_string($email[$key]) && self::body_has_tag($email[$key])) {
                    $email[$key] = self::substitute($email[$key]);
                }
            }
            return $email;
        }

        if (is_object($email)) {
            foreach (array('body', 'bodyText', 'subject') as $key) {
                if (!empty($email->$key) && is_string($email->$key) && self::body_has_tag($email->$key)) {
                    $email->$key = self::substitute($email->$key);
                }
            }
        }

        return $email;
    }

    private static function body_has_tag($body) {
        return strpos($body, '{' . self::TAG_NAME) !== false;
    }

    /**
     * Locate every `{upcoming-events …}` occurrence in the supplied
     * markup and replace it with the live `[up-next]` shortcode
     * output.
     *
     * @param string $markup
     * @return string
     */
    private static function substitute($markup) {
        $pattern = '/\{' . preg_quote(self::TAG_NAME, '/') . '([^\}]*)\}/';
        return preg_replace_callback(
            $pattern,
            array(__CLASS__, 'render_tag_match'),
            $markup
        );
    }

    /**
     * One match callback. Parses the pipe-separated option suffix
     * into shortcode attributes and runs the shortcode.
     *
     * @param array $matches preg match: $matches[1] is the option suffix or ''.
     * @return string
     */
    public static function render_tag_match($matches) {
        $raw_options    = isset($matches[1]) ? $matches[1] : '';
        $shortcode_attr = self::parse_options_to_shortcode_attrs($raw_options);
        $shortcode      = '[up-next' . ($shortcode_attr !== '' ? ' ' . $shortcode_attr : '') . ']';

        $output = do_shortcode($shortcode);

        // do_shortcode returns the raw shortcode string unchanged when
        // the shortcode isn't registered yet. Detect that case and
        // surface a benign empty string so we don't leak `[up-next]`
        // into the subscriber's inbox.
        if ($output === $shortcode) {
            return '';
        }
        return $output;
    }

    /**
     * Convert AcyMailing's pipe-separated option suffix into
     * shortcode attribute syntax:
     *
     *   "|columns:2|exclude-categories:Staff,Private"
     *     -> 'columns="2" exclude-categories="Staff,Private"'
     *
     * Tolerates the absence of a leading pipe, empty segments, and
     * keys with no value (booleans default to "true").
     *
     * @param string $raw
     * @return string
     */
    private static function parse_options_to_shortcode_attrs($raw) {
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') {
            return '';
        }
        if ($raw[0] === '|') {
            $raw = substr($raw, 1);
        }

        $segments = preg_split('/\|+/', $raw);
        if (!$segments) {
            return '';
        }

        $attrs = array();
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            $parts = explode(':', $segment, 2);
            $key   = sanitize_key(trim($parts[0]));
            if ($key === '') {
                continue;
            }
            $value = isset($parts[1]) ? trim($parts[1]) : 'true';

            // Preserve kebab-case attribute keys; sanitize_key forces
            // lowercase and replaces - with _, so we re-substitute
            // any underscores we know correspond to a hyphen.
            $key = str_replace('_', '-', $key);

            $attrs[] = $key . '="' . esc_attr($value) . '"';
        }

        return implode(' ', $attrs);
    }
}
