<?php
/**
 * Printed Calendars
 *
 * Turns one synced calendar's month into a wall poster (A3, 11×17″,
 * Letter or A4, landscape). The poster is a standalone HTML page sized in
 * millimetres, so the browser prints it — or saves it as a PDF — sharp at
 * any size. Each calendar keeps its own header picture and poster text.
 *
 * Admin UI: Calendar → Printed Calendars (admin/calendar-printed-page.php).
 *
 * @package AzurePlugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Printed_Calendar {

    const OPTION      = 'azure_printed_calendars';
    const ACTION      = 'azure_printed_calendar';
    const SAVE_ACTION = 'azure_printed_calendar_save';

    /** Landscape paper sizes in millimetres. The poster is laid out at A3 and zoomed to fit the others. */
    const PAPERS = array(
        'a3'      => array('label' => 'A3', 'note' => 'landscape', 'w' => 420.0, 'h' => 297.0),
        'tabloid' => array('label' => '11×17″', 'note' => 'tabloid', 'w' => 431.8, 'h' => 279.4),
        'letter'  => array('label' => 'Letter', 'note' => '8.5×11″', 'w' => 279.4, 'h' => 215.9),
        'a4'      => array('label' => 'A4', 'note' => 'landscape', 'w' => 297.0, 'h' => 210.0),
    );

    /** Title prefixes that name a grade, in match order (4/5 before 4th). */
    const GRADES = array(
        array('/^(kindergarten|k)\b/i', 'K', '#e8505b', 'Kindergarten'),
        array('/^4\s*\/\s*5/', '4/5', '#8e44ad', '4th/5th'),
        array('/^(1(st)?|grade\s*1)\b/i', '1st', '#f59f00', '1st grade'),
        array('/^(2(nd)?|grade\s*2)\b/i', '2nd', '#2fb380', '2nd grade'),
        array('/^(3(rd)?|grade\s*3)\b/i', '3rd', '#1c9bd6', '3rd grade'),
        array('/^(4(th)?|grade\s*4)\b/i', '4th', '#5b5fe0', '4th grade'),
        array('/^(5(th)?|grade\s*5)\b/i', '5th', '#d63384', '5th grade'),
    );

    const LEGEND_ORDER = array('K', '1st', '2nd', '3rd', '4th', '4/5', '5th');

    const DEFAULT_COLOUR = '#1f4fa8';

    public function __construct() {
        add_action('admin_post_' . self::ACTION, array($this, 'serve'));
        add_action('wp_ajax_' . self::SAVE_ACTION, array($this, 'ajax_save'));
    }

    public static function cap() {
        return class_exists('Azure_Admin_Menu_Customizer') ? Azure_Admin_Menu_Customizer::CAP : 'manage_options';
    }

    // ── Calendars and their poster settings ─────────────────────────────

    /**
     * Synced calendars that can be printed, as id => label.
     *
     * @return array<string,string>
     */
    public static function calendars() {
        if (!class_exists('Azure_Calendar_Mapping_Manager')) {
            $file = AZURE_PLUGIN_PATH . 'includes/class-calendar-mapping-manager.php';
            if (!file_exists($file)) {
                return array();
            }
            require_once $file;
        }
        $manager = new Azure_Calendar_Mapping_Manager();
        $out = array();
        foreach ((array) $manager->get_all_mappings() as $mapping) {
            $id = trim(Azure_Calendar_Mapping_Manager::mapping_field($mapping, 'outlook_calendar_id'));
            if ($id !== '' && !isset($out[$id])) {
                $out[$id] = Azure_Calendar_Mapping_Manager::mapping_label($mapping);
            }
        }
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    public static function default_settings() {
        return array(
            'header_id'      => 0,
            'kicker'         => (string) get_bloginfo('name'),
            'subtitle'       => '',
            'footer'         => '',
            'usual_location' => '',
            'link'           => '',
        );
    }

    /** Saved poster settings for one calendar, over the defaults. */
    public static function settings($calendar_id) {
        $all = get_option(self::OPTION, array());
        $saved = (is_array($all) && isset($all[$calendar_id]) && is_array($all[$calendar_id])) ? $all[$calendar_id] : array();
        return array_merge(self::default_settings(), array_intersect_key($saved, self::default_settings()));
    }

    public static function sanitize_settings(array $input) {
        $header = isset($input['header_id']) ? absint($input['header_id']) : 0;
        if ($header && !wp_attachment_is_image($header)) {
            $header = 0;
        }
        return array(
            'header_id'      => $header,
            'kicker'         => sanitize_text_field(wp_unslash($input['kicker'] ?? '')),
            'subtitle'       => sanitize_text_field(wp_unslash($input['subtitle'] ?? '')),
            'footer'         => sanitize_text_field(wp_unslash($input['footer'] ?? '')),
            'usual_location' => sanitize_text_field(wp_unslash($input['usual_location'] ?? '')),
            'link'           => esc_url_raw(trim((string) wp_unslash($input['link'] ?? ''))),
        );
    }

    public function ajax_save() {
        if (!current_user_can(self::cap())) {
            wp_send_json_error(array('message' => __('You do not have permission to change printed calendars.', 'azure-plugin')), 403);
        }
        check_ajax_referer(self::SAVE_ACTION, 'nonce');
        $calendar_id = sanitize_text_field(wp_unslash($_POST['calendar'] ?? ''));
        if (!isset(self::calendars()[$calendar_id])) {
            wp_send_json_error(array('message' => __('Unknown calendar.', 'azure-plugin')), 400);
        }
        $all = get_option(self::OPTION, array());
        $all = is_array($all) ? $all : array();
        $all[$calendar_id] = self::sanitize_settings((array) ($_POST['settings'] ?? array()));
        update_option(self::OPTION, $all, false);
        wp_send_json_success(array('settings' => self::settings($calendar_id)));
    }

    /**
     * Live calendar page for the QR code: the saved link, else the first
     * published page that embeds this calendar.
     */
    public static function live_link($calendar_id, array $settings) {
        if ($settings['link'] !== '') {
            return $settings['link'];
        }
        global $wpdb;
        $page_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID ASC LIMIT 1",
            '%' . $wpdb->esc_like($calendar_id) . '%'
        ));
        return $page_id ? (string) get_permalink((int) $page_id) : '';
    }

    public static function logo_url() {
        if (class_exists('Azure_Home_Screen')) {
            return home_url('/pta-icon-512.png');
        }
        return function_exists('get_site_icon_url') ? (string) get_site_icon_url(512) : '';
    }

    // ── Request ─────────────────────────────────────────────────────────

    /**
     * Poster options from a request, each one validated.
     *
     * @return array{calendar:string,year:int,month:int,days:string,paper:string,header:bool,qr:bool}
     */
    public static function request_args(array $src, $today = null) {
        $today = $today ?: new DateTimeImmutable('now', wp_timezone());
        $year  = (int) $today->format('Y');
        $month = (int) $today->format('n');
        if (isset($src['month']) && preg_match('/^(\d{4})-(\d{2})$/', (string) $src['month'], $m)
            && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            $year  = (int) $m[1];
            $month = (int) $m[2];
        }
        $days  = ($src['days'] ?? '') === 'week' ? 'week' : 'weekdays';
        $paper = (isset($src['paper']) && is_string($src['paper']) && isset(self::PAPERS[$src['paper']])) ? $src['paper'] : 'a3';
        return array(
            'calendar' => sanitize_text_field(wp_unslash((string) ($src['calendar'] ?? ''))),
            'year'     => $year,
            'month'    => $month,
            'days'     => $days,
            'paper'    => $paper,
            'header'   => !isset($src['header']) || (string) $src['header'] !== '0',
            'qr'       => !isset($src['qr']) || (string) $src['qr'] !== '0',
        );
    }

    public function serve() {
        if (!current_user_can(self::cap())) {
            wp_die(esc_html__('You do not have permission to print calendars.', 'azure-plugin'), 403);
        }
        check_admin_referer(self::ACTION);
        $args = self::request_args($_GET);
        $calendars = self::calendars();
        if (!isset($calendars[$args['calendar']])) {
            wp_die(esc_html__('Choose a calendar to print.', 'azure-plugin'), 400);
        }
        $settings = self::settings($args['calendar']);
        if (isset($_GET['s']) && is_array($_GET['s'])) {
            // Unsaved edits from the Printed Calendars form, so the preview matches what is typed.
            $settings = self::sanitize_settings($_GET['s']);
        }
        $header_id = $args['header'] ? (int) $settings['header_id'] : 0;
        $context = array(
            'title'      => $calendars[$args['calendar']],
            'logo'       => self::logo_url(),
            'header_url' => $header_id ? (string) wp_get_attachment_image_url($header_id, 'full') : '',
            'link'       => $args['qr'] ? self::live_link($args['calendar'], $settings) : '',
            'printed'    => wp_date('j F Y'),
            'site'       => (string) get_bloginfo('name'),
            'autoprint'  => !empty($_GET['autoprint']),
        );
        $events = self::events($args['calendar'], $args['year'], $args['month']);

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo self::poster_html($args, $settings, $context, $events);
        exit;
    }

    // ── Events ──────────────────────────────────────────────────────────

    /**
     * One calendar's events starting in a month, oldest first.
     *
     * @return array<int,array{title:string,start:string,end:string,all_day:bool,location:string}>
     *         start/end are site-local "Y-m-d H:i:s".
     */
    public static function events($calendar_id, $year, $month) {
        if (!post_type_exists('pta_event') || trim((string) $calendar_id) === '') {
            return array();
        }
        $first = sprintf('%04d-%02d-01', $year, $month);
        $last  = (new DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d');
        $meta_query = array(
            'relation' => 'AND',
            array(
                'key'     => '_EventStartDate',
                'value'   => array($first . ' 00:00:00', $last . ' 23:59:59'),
                'compare' => 'BETWEEN',
                'type'    => 'DATETIME',
            ),
        );
        if (class_exists('Azure_Event_CPT')) {
            foreach (Azure_Event_CPT::calendar_scope_clauses($calendar_id) as $clause) {
                $meta_query[] = $clause;
            }
        }
        $query = new WP_Query(array(
            'post_type'              => 'pta_event',
            'post_status'            => array('publish', 'future'),
            'posts_per_page'         => 500,
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'meta_query'             => $meta_query,
            'orderby'                => 'meta_value',
            'meta_key'               => '_EventStartDate',
            'order'                  => 'ASC',
        ));
        $out = array();
        foreach ((array) $query->posts as $post) {
            $venue = '';
            $venue_id = (int) get_post_meta($post->ID, '_EventVenueID', true);
            if ($venue_id && get_post($venue_id)) {
                $venue = get_the_title($venue_id);
            }
            if ($venue === '') {
                $venue = (string) get_post_meta($post->ID, '_EventVenue', true);
            }
            $all_day = strtolower((string) get_post_meta($post->ID, '_EventAllDay', true));
            $out[] = array(
                'title'    => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
                'start'    => (string) get_post_meta($post->ID, '_EventStartDate', true),
                'end'      => (string) get_post_meta($post->ID, '_EventEndDate', true),
                'all_day'  => in_array($all_day, array('yes', '1', 'true'), true),
                'location' => html_entity_decode($venue, ENT_QUOTES, 'UTF-8'),
            );
        }
        return $out;
    }

    // ── Layout helpers ──────────────────────────────────────────────────

    /**
     * Grade named at the start of a title, e.g. "4th- Harewood".
     *
     * @return array{0:string,1:string,2:string} label ('' if none), colour, title without the grade
     */
    public static function grade_of($title) {
        $t = trim((string) $title);
        foreach (self::GRADES as $g) {
            if (preg_match($g[0], $t, $m)) {
                $rest = substr($t, strlen($m[0]));
                $rest = preg_replace('/^\s*(grade)?\s*(art)?\s*[-–:]?\s*/iu', '', $rest);
                return array($g[1], $g[2], trim($rest) !== '' ? trim($rest) : $t);
            }
        }
        return array('', self::DEFAULT_COLOUR, $t);
    }

    /** "10:00 am – 1:00 pm", or "12:30 – 2:30 pm" when both are in the same half of the day. */
    public static function time_span($start, $end) {
        $s = strtotime($start);
        if ($s === false) {
            return '';
        }
        $e = $end !== '' ? strtotime($end) : false;
        $a = date('g:i', $s);
        $ap = date('a', $s);
        if ($e === false || $e <= $s) {
            return $a . ' ' . $ap;
        }
        $bp = date('a', $e);
        return $a . ($ap === $bp ? '' : ' ' . $ap) . ' – ' . date('g:i', $e) . ' ' . $bp;
    }

    /**
     * Calendar rows for a month. Weekdays mode is Monday–Friday; full-week
     * mode is Sunday–Saturday. Rows with no shown day in the month are dropped.
     *
     * @return string[][] "Y-m-d" dates, row by row
     */
    public static function weeks($year, $month, $days) {
        $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $week_start = $days === 'week' ? 0 : 1;
        $offset = ((int) $first->format('w') - $week_start + 7) % 7;
        $cursor = $first->modify('-' . $offset . ' days');
        $columns = $days === 'week' ? 7 : 5;
        $rows = array();
        while (true) {
            $row = array();
            $in_month = false;
            for ($i = 0; $i < $columns; $i++) {
                $d = $cursor->modify('+' . $i . ' days');
                $row[] = $d->format('Y-m-d');
                if ((int) $d->format('n') === $month) {
                    $in_month = true;
                }
            }
            if (!$in_month && !empty($rows)) {
                break;
            }
            if ($in_month) {
                $rows[] = $row;
            }
            $cursor = $cursor->modify('+7 days');
            if (count($rows) > 6) {
                break;
            }
        }
        return $rows;
    }

    /** Events by "Y-m-d"; an all-day event spanning several days appears on each. */
    public static function group_by_day(array $events) {
        $by_day = array();
        foreach ($events as $e) {
            $start = substr($e['start'], 0, 10);
            if ($start === '') {
                continue;
            }
            $days = array($start);
            if ($e['all_day'] && $e['end'] !== '') {
                $end = substr($e['end'], 0, 10);
                if (substr($e['end'], 11) === '00:00:00' && $end > $start) {
                    $end = (new DateTimeImmutable($end))->modify('-1 day')->format('Y-m-d');
                }
                $d = new DateTimeImmutable($start);
                while (($next = $d->modify('+1 day')->format('Y-m-d')) <= $end && count($days) < 31) {
                    $days[] = $next;
                    $d = $d->modify('+1 day');
                }
            }
            foreach ($days as $day) {
                $by_day[$day][] = $e;
            }
        }
        foreach ($by_day as &$list) {
            usort($list, function ($a, $b) {
                if ($a['all_day'] !== $b['all_day']) {
                    return $a['all_day'] ? -1 : 1;
                }
                return strcmp($a['start'], $b['start']);
            });
        }
        unset($list);
        return $by_day;
    }

    /** QR code as an inline SVG, '' if the text can't be encoded. */
    public static function qr_svg($text) {
        if ($text === '') {
            return '';
        }
        if (!class_exists('QRCode')) {
            require_once AZURE_PLUGIN_PATH . 'lib/qrcode/qrcode.php';
        }
        try {
            $qr = QRCode::getMinimumQRCode((string) $text, QR_ERROR_CORRECT_LEVEL_M);
        } catch (Exception $e) {
            return '';
        }
        $n = $qr->getModuleCount();
        $path = '';
        for ($r = 0; $r < $n; $r++) {
            $c = 0;
            while ($c < $n) {
                if (!$qr->isDark($r, $c)) {
                    $c++;
                    continue;
                }
                $run = 1;
                while ($c + $run < $n && $qr->isDark($r, $c + $run)) {
                    $run++;
                }
                $path .= 'M' . $c . ' ' . $r . 'h' . $run . 'v1h-' . $run . 'z';
                $c += $run;
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $n . ' ' . $n . '" width="100%" height="100%" shape-rendering="crispEdges" role="img" aria-label="QR code"><path fill="#1d2327" d="' . $path . '"/></svg>';
    }

    // ── Poster ──────────────────────────────────────────────────────────

    /**
     * The printable poster as a complete HTML document.
     *
     * @param array $args     request_args()
     * @param array $settings settings() for the calendar
     * @param array $context  title, logo, header_url, link, printed, site, autoprint
     * @param array $events   events()
     */
    public static function poster_html(array $args, array $settings, array $context, array $events) {
        $paper = self::PAPERS[$args['paper']] ?? self::PAPERS['a3'];
        $zoom = min($paper['w'] / 420, $paper['h'] / 297);
        $sheet_w = round($paper['w'] / $zoom, 2);
        $sheet_h = round($paper['h'] / $zoom, 2);

        $year  = (int) $args['year'];
        $month = (int) $args['month'];
        $month_label = wp_date('F Y', (new DateTimeImmutable(sprintf('%04d-%02d-15 12:00:00', $year, $month), wp_timezone()))->getTimestamp());
        $weeks = self::weeks($year, $month, $args['days']);
        $by_day = self::group_by_day($events);
        $usual = strtolower(trim($settings['usual_location']));

        $grades_used = array();
        $shown = 0;
        $cells = '';
        foreach ($weeks as $row) {
            foreach ($row as $ymd) {
                $date = new DateTimeImmutable($ymd);
                $inside = (int) $date->format('n') === $month;
                $items = '';
                foreach ($inside ? ($by_day[$ymd] ?? array()) : array() as $e) {
                    list($grade, $colour, $who) = self::grade_of($e['title']);
                    if ($grade !== '') {
                        $grades_used[$grade] = $colour;
                    }
                    $where = trim($e['location']);
                    $place = ($where === '' || strtolower($where) === $usual) ? '' : $where;
                    $when = $e['all_day'] ? __('All day', 'azure-plugin') : self::time_span($e['start'], $e['end']);
                    $items .= '<div class="ev" style="--c:' . esc_attr($colour) . '">'
                        . '<div class="ev-top">' . ($grade !== '' ? '<span class="chip">' . esc_html($grade) . '</span>' : '')
                        . '<span class="who">' . esc_html($who) . '</span></div>'
                        . '<div class="when">' . esc_html($when)
                        . ($place !== '' ? ' · <span class="where">' . esc_html($place) . '</span>' : '') . '</div>'
                        . '</div>';
                    $shown++;
                }
                $num = esc_html($date->format('j'));
                if (!$inside) {
                    $num .= ' <span class="mon">' . esc_html(wp_date('M', $date->setTime(12, 0)->getTimestamp())) . '</span>';
                }
                $cells .= '<div class="day' . ($inside ? '' : ' out') . '"><div class="num">' . $num . '</div>' . $items . '</div>';
            }
        }

        $weekend_hidden = 0;
        if ($args['days'] !== 'week') {
            foreach ($by_day as $ymd => $list) {
                $d = new DateTimeImmutable($ymd);
                if ((int) $d->format('n') === $month && (int) $d->format('N') >= 6) {
                    $weekend_hidden += count($list);
                }
            }
        }

        $legend = '';
        foreach (self::LEGEND_ORDER as $label) {
            if (isset($grades_used[$label])) {
                foreach (self::GRADES as $g) {
                    if ($g[1] === $label) {
                        $legend .= '<span class="lg"><span class="chip" style="--c:' . esc_attr($g[2]) . '">' . esc_html($label) . '</span>' . esc_html__($g[3], 'azure-plugin') . '</span>';
                    }
                }
            }
        }

        $day_names = $args['days'] === 'week'
            ? array(__('Sunday', 'azure-plugin'), __('Monday', 'azure-plugin'), __('Tuesday', 'azure-plugin'), __('Wednesday', 'azure-plugin'), __('Thursday', 'azure-plugin'), __('Friday', 'azure-plugin'), __('Saturday', 'azure-plugin'))
            : array(__('Monday', 'azure-plugin'), __('Tuesday', 'azure-plugin'), __('Wednesday', 'azure-plugin'), __('Thursday', 'azure-plugin'), __('Friday', 'azure-plugin'));
        $dow = '';
        foreach ($day_names as $name) {
            $dow .= '<div>' . esc_html($name) . '</div>';
        }
        $columns = count($day_names);

        $qr = $context['link'] !== '' ? self::qr_svg($context['link']) : '';
        $link_label = preg_replace('#^https?://(www\.)?#i', '', untrailingslashit($context['link']));
        $small = sprintf(__('Printed %s. Times can change — the live calendar is always up to date.', 'azure-plugin'), $context['printed']);
        if ($weekend_hidden) {
            $small .= ' ' . sprintf(_n('%d weekend event is not shown.', '%d weekend events are not shown.', $weekend_hidden, 'azure-plugin'), $weekend_hidden);
        }

        $hero_class = $context['header_url'] !== '' ? 'hero' : 'hero plain';
        $title = $context['title'];
        $doc_title = sprintf('%s — %s', $title, $month_label);

        ob_start();
        ?><!doctype html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>"><head><meta charset="utf-8">
<meta name="robots" content="noindex">
<title><?php echo esc_html($doc_title); ?></title>
<style>
@page { size: <?php echo esc_html($paper['w']); ?>mm <?php echo esc_html($paper['h']); ?>mm; margin: 0; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; background: #fff; }
body { font-family: "Avenir Next", "Segoe UI", "Helvetica Neue", Arial, sans-serif; color: #1d2327;
       -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.sheet { --s: 1; zoom: <?php echo esc_html(round($zoom, 4)); ?>; width: <?php echo esc_html($sheet_w); ?>mm; height: <?php echo esc_html($sheet_h); ?>mm;
  display: grid; grid-template-rows: 60mm auto 1fr 19mm; overflow: hidden; background: #fff; }
.hero { position: relative; overflow: hidden; background: #eef3fb; }
.hero.plain { background: linear-gradient(120deg, #eef3fb, #fdf1f2); }
.hero img.pic { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: center 40%; }
.hero::before { content: ""; position: absolute; inset: 0; z-index: 1;
  background: linear-gradient(90deg, rgba(255,255,255,.97) 0%, rgba(255,255,255,.93) 40%, rgba(255,255,255,0) 66%); }
.hero.plain::before { display: none; }
.hero-in { position: absolute; inset: 0; z-index: 2; display: flex; align-items: center; gap: 7mm; padding: 0 14mm; }
.logo { width: 34mm; height: 34mm; border-radius: 50%; background: #fff; box-shadow: 0 1mm 3mm rgba(0,0,0,.15);
        display: grid; place-items: center; flex: none; overflow: hidden; }
.logo img { width: 30mm; height: 30mm; object-fit: contain; }
.kicker { font-size: 6mm; letter-spacing: .5mm; text-transform: uppercase; color: #1f4fa8; font-weight: 700; }
h1 { margin: 1mm 0 0; font-size: 16mm; line-height: 1; font-weight: 800; letter-spacing: -.3mm; }
h1 .month { display: block; color: #e8505b; font-size: 11mm; margin-top: 1.5mm; }
.sub { margin-top: 2.5mm; font-size: 5mm; color: #3c434a; }
.bar { display: flex; align-items: center; min-height: 4mm; padding: 4mm 14mm 0; }
.legend { display: flex; flex-wrap: wrap; gap: 5mm; font-size: 4.4mm; color: #3c434a; align-items: center; }
.legend .title { font-weight: 700; color: #1d2327; }
.lg { display: inline-flex; align-items: center; gap: 1.6mm; }
.dow { display: grid; grid-template-columns: repeat(<?php echo (int) $columns; ?>, 1fr); gap: 3mm; padding: 3mm 14mm 0; }
.dow div { font-size: 5.5mm; font-weight: 700; text-transform: uppercase; letter-spacing: .4mm; color: #1f4fa8; padding-left: 2mm; }
.grid { display: grid; grid-template-columns: repeat(<?php echo (int) $columns; ?>, minmax(0, 1fr)); grid-auto-rows: 1fr; gap: 3mm; padding: 2mm 14mm 3mm; min-height: 0; }
.day { border: .5mm solid #dcdcde; border-radius: 3mm; padding: 2.2mm 2.6mm; display: flex; flex-direction: column; gap: calc(1.4mm * var(--s)); background: #fff; min-width: 0; }
.day.out { background: #f6f7f7; border-style: dashed; }
.day.out .num { color: #a7aaad; }
.num { font-size: 7mm; font-weight: 800; line-height: 1; }
.num .mon { font-size: 4mm; font-weight: 600; text-transform: uppercase; }
.ev { border-left: 2mm solid var(--c); background: color-mix(in srgb, var(--c) 10%, #fff); border-radius: 1.5mm; padding: calc(1.2mm * var(--s)) 2mm; }
.ev-top { display: flex; align-items: center; gap: 2mm; }
.chip { flex: none; display: inline-block; min-width: 9mm; text-align: center; padding: .6mm 1.6mm; border-radius: 5mm; background: var(--c);
        color: #fff; font-weight: 800; font-size: calc(3.8mm * var(--s)); line-height: 1.2; }
.who { font-size: calc(4.7mm * var(--s)); font-weight: 700; line-height: 1.1; overflow-wrap: anywhere; }
.when { font-size: calc(3.9mm * var(--s)); margin-top: .6mm; color: #3c434a; }
.where { font-style: italic; color: #50575e; }
.foot { display: flex; align-items: center; gap: 5mm; padding: 0 14mm; border-top: .5mm solid #f0f0f1; overflow: hidden; }
.qr { width: 15mm; height: 15mm; flex: none; }
.foot .live { font-size: 4.6mm; }
.foot .live b { color: #1f4fa8; }
.foot .small { font-size: 3.6mm; color: #646970; margin-top: .6mm; }
.foot .org { margin-left: auto; text-align: right; font-size: 4.2mm; color: #3c434a; }
.foot .org b { display: block; font-size: 5mm; color: #1d2327; }
@media screen { body { background: #f0f0f1; } .sheet { margin: 0 auto; box-shadow: 0 2mm 8mm rgba(0,0,0,.12); } }
</style></head>
<body>
<div class="sheet" data-events="<?php echo (int) $shown; ?>">
  <header class="<?php echo esc_attr($hero_class); ?>">
    <?php if ($context['header_url'] !== ''): ?><img class="pic" src="<?php echo esc_url($context['header_url']); ?>" alt=""><?php endif; ?>
    <div class="hero-in">
      <?php if ($context['logo'] !== ''): ?><div class="logo"><img src="<?php echo esc_url($context['logo']); ?>" alt="" onerror="this.parentNode.remove()"></div><?php endif; ?>
      <div>
        <?php if ($settings['kicker'] !== ''): ?><div class="kicker"><?php echo esc_html($settings['kicker']); ?></div><?php endif; ?>
        <h1><?php echo esc_html($title); ?> <span class="month"><?php echo esc_html($month_label); ?></span></h1>
        <?php if ($settings['subtitle'] !== ''): ?><div class="sub"><?php echo esc_html($settings['subtitle']); ?></div><?php endif; ?>
      </div>
    </div>
  </header>

  <div>
    <div class="bar"><?php if ($legend !== ''): ?><div class="legend"><span class="title"><?php esc_html_e('Grades', 'azure-plugin'); ?></span><?php echo $legend; ?></div><?php endif; ?></div>
    <div class="dow"><?php echo $dow; ?></div>
  </div>

  <main class="grid"><?php echo $cells; ?></main>

  <footer class="foot">
    <?php if ($qr !== ''): ?><div class="qr"><?php echo $qr; ?></div><?php endif; ?>
    <div>
      <?php if ($qr !== ''): ?><div class="live"><?php esc_html_e('Scan for the live calendar', 'azure-plugin'); ?> · <b><?php echo esc_html($link_label); ?></b></div><?php endif; ?>
      <div class="small"><?php echo esc_html($small); ?></div>
    </div>
    <div class="org"><b><?php echo esc_html($context['site']); ?></b><?php echo esc_html($settings['footer']); ?></div>
  </footer>
</div>
<script>
(function () {
  var sheet = document.querySelector('.sheet');
  var grid = sheet.querySelector('.grid');
  var s = 1;
  function over() {
    return grid.scrollHeight > grid.clientHeight + 1 || sheet.scrollHeight > sheet.clientHeight + 1;
  }
  while (over() && s > 0.6) {
    s = Math.round((s - 0.05) * 100) / 100;
    sheet.style.setProperty('--s', s);
  }
  if (over()) {
    sheet.setAttribute('data-overflow', '1');
  }
  sheet.setAttribute('data-scale', s);
<?php if (!empty($context['autoprint'])): ?>
  window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });
<?php endif; ?>
})();
</script>
</body></html>
<?php
        return (string) ob_get_clean();
    }
}
