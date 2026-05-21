<?php
/**
 * /events/ archive — pta_event-driven replacement for TEC's
 * classic calendar-grid view.
 *
 * Two views:
 *   - calendar (default) — month grid with event chips per day
 *   - list — chronological list of upcoming events
 *
 * URL params:
 *   ?pta_view={calendar|list}      view selector (default calendar)
 *   ?pta_month=YYYY-MM             month to display in calendar view
 *
 * Loaded by Azure_Event_CPT::maybe_load_single_template() when
 * data_source=pta and the URL is the post-type archive.
 */

if (!defined('ABSPATH')) {
    exit;
}

$view  = isset($_GET['pta_view']) && $_GET['pta_view'] === 'list' ? 'list' : 'calendar';
$month = isset($_GET['pta_month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['pta_month'])
    ? $_GET['pta_month']
    : date('Y-m', current_time('timestamp'));

// Build month boundary timestamps for the selected month, and
// look up neighbour months for the prev/next links.
$month_ts        = strtotime($month . '-01 00:00:00');
$prev_month      = date('Y-m', strtotime($month . '-01 -1 month'));
$next_month      = date('Y-m', strtotime($month . '-01 +1 month'));
$today_month     = date('Y-m', current_time('timestamp'));
$today_y_m_d     = date('Y-m-d', current_time('timestamp'));
$archive_url     = home_url('/events/');
$build_url = function ($params) use ($archive_url) {
    $base = $archive_url;
    if (!empty($params)) {
        $base .= '?' . http_build_query($params);
    }
    return $base;
};

// Pull events for the selected month directly — we ignore the main
// query here so the archive isn't tied to its pagination/limits.
// 200 events/month is far above any realistic ceiling for this site,
// so a single query is fine.
$events_args = array(
    'post_type'      => Azure_Event_CPT::POST_TYPE_EVENT,
    'post_status'    => 'publish',
    'posts_per_page' => 200,
    'meta_key'       => '_EventStartDate',
    'orderby'        => 'meta_value',
    'order'          => 'ASC',
    'meta_query'     => array(
        array(
            'key'     => '_EventStartDate',
            'value'   => date('Y-m-d 00:00:00', $month_ts),
            'compare' => '>=',
            'type'    => 'DATETIME',
        ),
        array(
            'key'     => '_EventStartDate',
            'value'   => date('Y-m-t 23:59:59', $month_ts),
            'compare' => '<=',
            'type'    => 'DATETIME',
        ),
    ),
);
$events_q = new WP_Query($events_args);
$events_by_day = array(); // 'Y-m-d' => array(WP_Post)
foreach ((array) $events_q->posts as $p) {
    $start = get_post_meta($p->ID, '_EventStartDate', true);
    if (!$start) { continue; }
    $key = date('Y-m-d', strtotime($start));
    if (!isset($events_by_day[$key])) {
        $events_by_day[$key] = array();
    }
    $events_by_day[$key][] = $p;
}
wp_reset_postdata();

get_header();
?>
<div class="pta-events-archive-wrap">
    <div class="pta-events-archive">

        <header class="pta-events-archive-header">
            <h1 class="pta-events-archive-title">Events</h1>
            <nav class="pta-events-archive-views" aria-label="Event views">
                <a class="pta-evview-btn<?php echo $view === 'calendar' ? ' is-active' : ''; ?>"
                   href="<?php echo esc_url($build_url(array('pta_view' => 'calendar', 'pta_month' => $month))); ?>">
                    Calendar
                </a>
                <a class="pta-evview-btn<?php echo $view === 'list' ? ' is-active' : ''; ?>"
                   href="<?php echo esc_url($build_url(array('pta_view' => 'list'))); ?>">
                    List
                </a>
            </nav>
        </header>

        <?php if ($view === 'calendar') : ?>

            <nav class="pta-events-monthnav" aria-label="Month navigation">
                <a class="pta-monthnav-prev"
                   href="<?php echo esc_url($build_url(array('pta_view' => 'calendar', 'pta_month' => $prev_month))); ?>"
                   aria-label="Previous month">
                    &laquo; <?php echo esc_html(date_i18n('F Y', strtotime($prev_month . '-01'))); ?>
                </a>
                <h2 class="pta-monthnav-current">
                    <?php echo esc_html(date_i18n('F Y', $month_ts)); ?>
                </h2>
                <a class="pta-monthnav-next"
                   href="<?php echo esc_url($build_url(array('pta_view' => 'calendar', 'pta_month' => $next_month))); ?>"
                   aria-label="Next month">
                    <?php echo esc_html(date_i18n('F Y', strtotime($next_month . '-01'))); ?> &raquo;
                </a>
            </nav>

            <?php if ($month !== $today_month) : ?>
                <p class="pta-monthnav-today-link">
                    <a href="<?php echo esc_url($build_url(array('pta_view' => 'calendar'))); ?>">
                        Jump to <?php echo esc_html(date_i18n('F Y', current_time('timestamp'))); ?>
                    </a>
                </p>
            <?php endif; ?>

            <?php
            // Build the calendar grid. Start from Sunday of the week
            // containing the 1st of the selected month, and continue
            // for 6 weeks (always 42 cells) so the grid is stable
            // regardless of month length / starting weekday.
            $start_of_week_ts = strtotime('sunday last week', $month_ts);
            // strtotime('sunday last week', <Sunday 00:00>) returns the
            // PRIOR Sunday — but if $month_ts already IS Sunday we want
            // that day. Special-case:
            if (date('w', $month_ts) === '0') {
                $start_of_week_ts = $month_ts;
            }
            ?>

            <div class="pta-events-calendar">
                <div class="pta-events-calendar-row pta-events-calendar-dow">
                    <?php foreach (array('Sun','Mon','Tue','Wed','Thu','Fri','Sat') as $d) : ?>
                        <div class="pta-events-calendar-dowcell"><?php echo esc_html($d); ?></div>
                    <?php endforeach; ?>
                </div>

                <?php
                $cell_ts = $start_of_week_ts;
                for ($w = 0; $w < 6; $w++) :
                ?>
                    <div class="pta-events-calendar-row">
                        <?php for ($d = 0; $d < 7; $d++) :
                            $cell_ymd      = date('Y-m-d', $cell_ts);
                            $cell_in_month = (date('Y-m', $cell_ts) === $month);
                            $cell_is_today = ($cell_ymd === $today_y_m_d);
                            $cell_events   = isset($events_by_day[$cell_ymd]) ? $events_by_day[$cell_ymd] : array();
                            $cell_classes  = 'pta-events-calendar-cell';
                            if (!$cell_in_month) { $cell_classes .= ' is-other-month'; }
                            if ($cell_is_today)  { $cell_classes .= ' is-today'; }
                            if (!empty($cell_events)) { $cell_classes .= ' has-events'; }
                        ?>
                            <div class="<?php echo esc_attr($cell_classes); ?>">
                                <div class="pta-events-calendar-daynum">
                                    <?php echo esc_html((int) date('j', $cell_ts)); ?>
                                </div>
                                <?php foreach ($cell_events as $ev) :
                                    $ev_start = get_post_meta($ev->ID, '_EventStartDate', true);
                                    $ev_all   = get_post_meta($ev->ID, '_EventAllDay', true) === 'yes';
                                    $ev_time  = $ev_all ? '' : date_i18n(get_option('time_format', 'g:i a'), strtotime($ev_start));
                                    // Surface online-meeting events visually in the calendar
                                    // grid via a small camera icon; click still goes to the
                                    // event page where the full Join button lives.
                                    $ev_has_join = Azure_Event_CPT::extract_online_meeting_url($ev->ID) !== '';
                                ?>
                                    <a class="pta-events-calendar-chip<?php echo $ev_has_join ? ' has-meeting' : ''; ?>"
                                       href="<?php echo esc_url(get_permalink($ev)); ?>"
                                       title="<?php echo esc_attr(get_the_title($ev) . ($ev_time ? ' — ' . $ev_time : '') . ($ev_has_join ? ' (online meeting)' : '')); ?>">
                                        <?php if ($ev_time) : ?>
                                            <span class="pta-events-calendar-chip-time"><?php echo esc_html($ev_time); ?></span>
                                        <?php endif; ?>
                                        <?php if ($ev_has_join) : ?>
                                            <span class="pta-events-calendar-chip-meeting" aria-hidden="true">&#128249;</span>
                                        <?php endif; ?>
                                        <span class="pta-events-calendar-chip-title"><?php echo esc_html(get_the_title($ev)); ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php
                            $cell_ts = strtotime('+1 day', $cell_ts);
                        endfor; ?>
                    </div>
                <?php endfor; ?>
            </div>

        <?php else : /* list view */ ?>

            <?php
            // List view always shows upcoming-from-today, regardless of
            // pta_month. Pull a longer horizon than the calendar.
            $list_args = array(
                'post_type'      => Azure_Event_CPT::POST_TYPE_EVENT,
                'post_status'    => 'publish',
                'posts_per_page' => 100,
                'meta_key'       => '_EventStartDate',
                'orderby'        => 'meta_value',
                'order'          => 'ASC',
                'meta_query'     => array(
                    array(
                        'key'     => '_EventStartDate',
                        'value'   => date('Y-m-d 00:00:00', current_time('timestamp')),
                        'compare' => '>=',
                        'type'    => 'DATETIME',
                    ),
                ),
            );
            $list_q = new WP_Query($list_args);
            ?>

            <?php if (!$list_q->have_posts()) : ?>
                <p class="pta-events-list-empty">No upcoming events scheduled.</p>
            <?php else : ?>
                <ul class="pta-events-list">
                    <?php
                    $current_month_label = '';
                    while ($list_q->have_posts()) :
                        $list_q->the_post();
                        $start = get_post_meta(get_the_ID(), '_EventStartDate', true);
                        $end   = get_post_meta(get_the_ID(), '_EventEndDate', true);
                        $all   = get_post_meta(get_the_ID(), '_EventAllDay', true) === 'yes';
                        $sts   = strtotime($start);
                        $month_label = date_i18n('F Y', $sts);

                        if ($month_label !== $current_month_label) :
                            $current_month_label = $month_label;
                    ?>
                        <li class="pta-events-list-month">
                            <h3><?php echo esc_html($month_label); ?></h3>
                        </li>
                    <?php endif; ?>

                    <?php
                    // Per-event extras: featured image, online-meeting
                    // button, venue label. The card itself stays a
                    // single <a> to the event permalink; the Join
                    // button is rendered OUTSIDE the wrapping link as
                    // its own anchor so visitors can click straight
                    // through to the meeting without first opening
                    // the event page (and so it opens in a new tab).
                    $eid       = get_the_ID();
                    $thumb_url = has_post_thumbnail($eid)
                        ? get_the_post_thumbnail_url($eid, 'medium')
                        : '';
                    $join_btn  = Azure_Event_CPT::render_join_meeting_button($eid, 'inline');
                    ?>
                    <li class="pta-events-list-item">
                        <div class="pta-events-list-card<?php echo $thumb_url ? ' has-thumb' : ''; ?><?php echo $join_btn ? ' has-join' : ''; ?>">
                            <a class="pta-events-list-card-link" href="<?php echo esc_url(get_permalink()); ?>" aria-label="<?php echo esc_attr(get_the_title()); ?>">
                                <?php if ($thumb_url) : ?>
                                    <div class="pta-events-list-thumb"
                                         style="background-image:url('<?php echo esc_url($thumb_url); ?>');">
                                    </div>
                                <?php endif; ?>
                                <div class="pta-events-list-date">
                                    <span class="pta-events-list-date-day"><?php echo esc_html(date_i18n('j', $sts)); ?></span>
                                    <span class="pta-events-list-date-dow"><?php echo esc_html(date_i18n('D', $sts)); ?></span>
                                </div>
                                <div class="pta-events-list-meta">
                                    <h4><?php the_title(); ?></h4>
                                    <p>
                                        <?php
                                        if ($all) {
                                            echo 'All day';
                                        } else {
                                            $tf = get_option('time_format', 'g:i A');
                                            echo esc_html(date_i18n($tf, $sts));
                                            if ($end) {
                                                echo ' - ' . esc_html(date_i18n($tf, strtotime($end)));
                                            }
                                        }
                                        $vid = (int) get_post_meta($eid, '_EventVenueID', true);
                                        if ($vid > 0) {
                                            $vb = Azure_Event_CPT::get_venue_block($vid);
                                            if ($vb && !empty($vb['name'])) {
                                                echo ' &nbsp;|&nbsp; ' . esc_html($vb['name']);
                                            }
                                        }
                                        ?>
                                    </p>
                                </div>
                            </a>
                            <?php if ($join_btn) : ?>
                                <div class="pta-events-list-join">
                                    <?php echo $join_btn; // already escaped inside helper ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endwhile; ?>
                </ul>
            <?php endif; ?>
            <?php wp_reset_postdata(); ?>

        <?php endif; ?>

    </div>
</div>
<?php
get_footer();
