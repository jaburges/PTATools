<?php
/**
 * Single pta_event template — replicates The Events Calendar's
 * classic single-event layout (back-link, title, date heading,
 * details/venue/map row, related events carousel) but renders
 * entirely from pta_event data.
 *
 * Loaded by Azure_Event_CPT::maybe_load_single_template() when
 * data_source=pta and the URL is /event/<slug>/.
 *
 * Theme chrome (header/footer) comes from the active theme so the
 * page sits inside the same shell as every other site page.
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

while (have_posts()) :
    the_post();

    $post_id     = get_the_ID();
    $start_meta  = get_post_meta($post_id, '_EventStartDate', true);
    $end_meta    = get_post_meta($post_id, '_EventEndDate', true);
    $event_url   = get_post_meta($post_id, '_EventURL', true);
    $cost        = get_post_meta($post_id, '_EventCost', true);
    $venue_id    = (int) get_post_meta($post_id, '_EventVenueID', true);
    $venue_text  = get_post_meta($post_id, '_EventVenue', true);
    $datetime_h  = Azure_Event_CPT::format_event_datetime_heading($post_id);
    $venue_block = $venue_id > 0 ? Azure_Event_CPT::get_venue_block($venue_id) : false;
    $g_cal_url   = Azure_Event_CPT::google_calendar_url($post_id);
    $cats        = wp_get_object_terms($post_id, Azure_Event_CPT::TAXONOMY_CATEGORY);

    // Featured image — set manually by editors (Outlook sync doesn't
    // attach images). Renders above the title when present; nothing
    // renders when absent so we don't ship an empty placeholder block.
    $hero_image_url = has_post_thumbnail($post_id)
        ? get_the_post_thumbnail_url($post_id, 'large')
        : '';

    // Online-meeting URL extracted from post_content / venue / event
    // URL by the shared helper. Empty string when this event isn't
    // an online meeting.
    $join_meeting_btn = Azure_Event_CPT::render_join_meeting_button($post_id, 'block');

    // The "All Events" link should point at the existing /events/
    // archive — same place TEC's link goes — so users land in the
    // same calendar list they came from.
    $all_events_url = home_url('/events/');

    $start_ts = $start_meta ? strtotime($start_meta) : 0;
    $end_ts   = $end_meta   ? strtotime($end_meta)   : $start_ts;
?>
<div class="pta-event-single-wrap">
    <div class="pta-event-single">
        <p class="pta-event-back">
            <a href="<?php echo esc_url($all_events_url); ?>">&laquo; All Events</a>
        </p>

        <?php if ($hero_image_url) : ?>
            <div class="pta-event-hero">
                <img src="<?php echo esc_url($hero_image_url); ?>"
                     alt="<?php echo esc_attr(get_the_title($post_id)); ?>"
                     loading="lazy" />
            </div>
        <?php endif; ?>

        <h1 class="pta-event-title"><?php the_title(); ?></h1>

        <?php if ($datetime_h) : ?>
            <p class="pta-event-when"><?php echo esc_html($datetime_h); ?></p>
        <?php endif; ?>

        <?php if ($join_meeting_btn) : ?>
            <div class="pta-event-join">
                <?php echo $join_meeting_btn; // already escaped inside helper ?>
            </div>
        <?php endif; ?>

        <?php if ($g_cal_url) : ?>
            <div class="pta-event-add-to-cal">
                <details>
                    <summary>
                        <span class="pta-cal-icon" aria-hidden="true">&#128197;</span>
                        Add to calendar
                        <span class="pta-cal-chev" aria-hidden="true">&#9662;</span>
                    </summary>
                    <ul>
                        <li>
                            <a href="<?php echo esc_url($g_cal_url); ?>" target="_blank" rel="noopener">
                                Google Calendar
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo esc_url(add_query_arg(array('pta_ical' => $post_id), home_url('/'))); ?>">
                                iCal / Outlook (.ics)
                            </a>
                        </li>
                    </ul>
                </details>
            </div>
        <?php endif; ?>

        <hr class="pta-event-rule" />

        <div class="pta-event-grid">

            <div class="pta-event-details">
                <h3 class="pta-event-section">DETAILS</h3>
                <?php if ($start_ts) : ?>
                    <p>
                        <strong>Date:</strong><br />
                        <?php echo esc_html(date_i18n(get_option('date_format', 'F j'), $start_ts)); ?>
                    </p>
                <?php endif; ?>
                <?php if ($start_ts && get_post_meta($post_id, '_EventAllDay', true) !== 'yes') : ?>
                    <p>
                        <strong>Time:</strong><br />
                        <?php
                        $tf = get_option('time_format', 'g:i A');
                        echo esc_html(date_i18n($tf, $start_ts) . ' - ' . date_i18n($tf, $end_ts));
                        ?>
                    </p>
                <?php endif; ?>
                <?php if ($cost) : ?>
                    <p>
                        <strong>Cost:</strong><br />
                        <?php echo esc_html($cost); ?>
                    </p>
                <?php endif; ?>
                <?php if (!empty($cats) && !is_wp_error($cats)) : ?>
                    <p>
                        <strong>Event Categories:</strong><br />
                        <?php
                        $links = array();
                        foreach ($cats as $cat) {
                            $links[] = '<a href="' . esc_url(get_term_link($cat)) . '">'
                                . esc_html($cat->name) . '</a>';
                        }
                        echo wp_kses_post(implode(', ', $links));
                        ?>
                    </p>
                <?php endif; ?>
                <?php if ($event_url) : ?>
                    <p>
                        <strong>Website:</strong><br />
                        <a href="<?php echo esc_url($event_url); ?>" target="_blank" rel="noopener">
                            <?php echo esc_html($event_url); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>

            <div class="pta-event-venue">
                <?php if ($venue_block) : ?>
                    <h3 class="pta-event-section">VENUE</h3>
                    <p>
                        <a href="<?php echo esc_url($venue_block['permalink']); ?>">
                            <?php echo esc_html($venue_block['name']); ?>
                        </a>
                    </p>
                    <?php if ($venue_block['address']) : ?>
                        <p><?php echo esc_html($venue_block['address']); ?></p>
                    <?php endif; ?>
                    <?php if ($venue_block['city_state_zip']) : ?>
                        <p>
                            <?php echo esc_html($venue_block['city_state_zip']); ?>
                            <?php if ($venue_block['country']) : ?>
                                <?php echo esc_html($venue_block['country']); ?>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($venue_block['map_link_url']) : ?>
                        <p>
                            <a href="<?php echo esc_url($venue_block['map_link_url']); ?>" target="_blank" rel="noopener">
                                + Google Map
                            </a>
                        </p>
                    <?php endif; ?>
                <?php elseif ($venue_text) : ?>
                    <h3 class="pta-event-section">VENUE</h3>
                    <p><?php echo esc_html($venue_text); ?></p>
                <?php endif; ?>
            </div>

            <?php if ($venue_block && $venue_block['map_embed_url']) : ?>
                <div class="pta-event-map">
                    <iframe
                        src="<?php echo esc_url($venue_block['map_embed_url']); ?>"
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                        title="<?php echo esc_attr($venue_block['name']); ?>"
                    ></iframe>
                </div>
            <?php endif; ?>

        </div>

        <?php
        $body_html = apply_filters('the_content', get_the_content());
        if (trim(wp_strip_all_tags($body_html)) !== '') :
        ?>
            <hr class="pta-event-rule" />
            <div class="pta-event-body">
                <?php echo $body_html; // already filtered ?>
            </div>
        <?php endif; ?>

        <?php
        $related = Azure_Event_CPT::get_related_events($post_id, 3);
        if (!empty($related)) :
        ?>
            <hr class="pta-event-rule" />
            <h2 class="pta-event-related-heading">Related Events</h2>
            <div class="pta-event-related">
                <?php foreach ($related as $rel) :
                    $rstart = get_post_meta($rel->ID, '_EventStartDate', true);
                    $rend   = get_post_meta($rel->ID, '_EventEndDate', true);
                    $rday   = get_post_meta($rel->ID, '_EventAllDay', true) === 'yes';
                    $rstart_ts = $rstart ? strtotime($rstart) : 0;
                    $rend_ts   = $rend   ? strtotime($rend)   : $rstart_ts;
                    $rline = '';
                    if ($rstart_ts) {
                        $rline = date_i18n(get_option('date_format', 'F j'), $rstart_ts);
                        if (!$rday && $rend_ts) {
                            $tf = get_option('time_format', 'g:i A');
                            $rline .= ' @ ' . date_i18n($tf, $rstart_ts) . ' - ' . date_i18n($tf, $rend_ts);
                        }
                    }
                    $thumb = has_post_thumbnail($rel->ID)
                        ? get_the_post_thumbnail_url($rel->ID, 'medium')
                        : '';
                ?>
                    <a class="pta-event-related-card" href="<?php echo esc_url(get_permalink($rel)); ?>">
                        <div class="pta-event-related-thumb<?php echo $thumb ? ' has-thumb' : ''; ?>"
                             <?php if ($thumb) : ?>style="background-image:url('<?php echo esc_url($thumb); ?>');"<?php endif; ?>>
                            <?php if (!$thumb) : ?>
                                <span class="pta-event-related-placeholder" aria-hidden="true">&#128197;</span>
                            <?php endif; ?>
                        </div>
                        <div class="pta-event-related-meta">
                            <h4><?php echo esc_html(get_the_title($rel)); ?></h4>
                            <?php if ($rline) : ?>
                                <p><?php echo esc_html($rline); ?></p>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</div>
<?php
endwhile;

get_footer();
