<?php
if (!defined('ABSPATH')) {
    exit;
}

$rsvp_loaded = class_exists('Azure_Event_Rsvp');
$event_id = isset($_GET['event_id']) ? absint($_GET['event_id']) : 0;
$event = ($rsvp_loaded && $event_id) ? get_post($event_id) : null;
if ($event && $event->post_type !== Azure_Event_Rsvp::POST_TYPE) {
    $event = null;
}
$list_url = admin_url('admin.php?page=azure-plugin-rsvp');
?>
<div class="wrap">
    <h1><span class="dashicons dashicons-tickets-alt"></span> <?php esc_html_e('RSVP', 'azure-plugin'); ?></h1>

<?php
if (class_exists('Azure_Home_Screen')) {
    Azure_Home_Screen::render_module_switch('rsvp', __('Let signed-in families RSVP to events. RSVPs go into each person\'s private calendar feed.', 'azure-plugin'), __('RSVP', 'azure-plugin'));
}

if (!$rsvp_loaded) : ?>
    <p><?php esc_html_e('Turn RSVP on above, then reload this page.', 'azure-plugin'); ?></p>
</div>
<?php
    return;
endif;

if ($event) :
    $settings = Azure_Event_Rsvp::event_settings($event->ID);
    $rows = Azure_Event_Rsvp::rsvps_for_event($event->ID);
    $cancelled = Azure_Event_Rsvp::rsvps_for_event($event->ID, Azure_Event_Rsvp::STATUS_CANCELLED);
    $taken = Azure_Event_Rsvp::seats_taken($event->ID);
    $details = Azure_Event_Rsvp::event_details($event->ID);
    ?>
    <p><a href="<?php echo esc_url($list_url); ?>">&larr; <?php esc_html_e('All RSVP events', 'azure-plugin'); ?></a></p>
    <h2><?php echo esc_html(get_the_title($event)); ?></h2>
    <p>
        <?php echo esc_html($details['when']); ?>
        <?php if ($details['location'] !== '') : ?> · <?php echo esc_html($details['location']); ?><?php endif; ?>
    </p>
    <p>
        <strong><?php
        echo esc_html($settings['capacity'] > 0
            /* translators: 1: people coming, 2: capacity */
            ? sprintf(__('%1$d of %2$d spots taken', 'azure-plugin'), $taken, $settings['capacity'])
            /* translators: %d: people coming */
            : sprintf(_n('%d person going', '%d people going', $taken, 'azure-plugin'), $taken));
        ?></strong>
        · <?php
        /* translators: %d: number of RSVPs */
        echo esc_html(sprintf(_n('%d RSVP', '%d RSVPs', count($rows), 'azure-plugin'), count($rows)));
        ?>
        <?php if ($cancelled) : ?>
            · <?php
            /* translators: %d: cancelled RSVPs */
            echo esc_html(sprintf(_n('%d cancelled', '%d cancelled', count($cancelled), 'azure-plugin'), count($cancelled)));
            ?>
        <?php endif; ?>
        <?php if (!$settings['enabled']) : ?>
            · <em><?php esc_html_e('RSVP is turned off for this event.', 'azure-plugin'); ?></em>
        <?php endif; ?>
    </p>
    <p>
        <a class="button button-primary" href="<?php echo esc_url(Azure_Event_Rsvp::export_url($event->ID)); ?>"><?php esc_html_e('Export CSV', 'azure-plugin'); ?></a>
        <a class="button" href="<?php echo esc_url(get_edit_post_link($event->ID)); ?>"><?php esc_html_e('Edit event', 'azure-plugin'); ?></a>
    </p>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Name', 'azure-plugin'); ?></th>
                <th><?php esc_html_e('Email', 'azure-plugin'); ?></th>
                <th><?php esc_html_e('Guests', 'azure-plugin'); ?></th>
                <th><?php esc_html_e('RSVP\'d at', 'azure-plugin'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows) : ?>
            <tr><td colspan="4"><?php esc_html_e('No RSVPs yet.', 'azure-plugin'); ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row) : ?>
            <tr>
                <td><?php echo esc_html(Azure_Event_Rsvp::display_name($row)); ?></td>
                <td><a href="mailto:<?php echo esc_attr(Azure_Event_Rsvp::display_email($row)); ?>"><?php echo esc_html(Azure_Event_Rsvp::display_email($row)); ?></a></td>
                <td><?php echo (int) $row->guests; ?></td>
                <td><?php echo esc_html((string) $row->created_at); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php else :
    $events = Azure_Event_Rsvp::rsvp_events();
    ?>
    <p><?php esc_html_e('To take RSVPs, open an event and tick "Enable RSVP" in its RSVP box. Confirmation and cancellation emails can be edited under Emails → Messages.', 'azure-plugin'); ?></p>
    <?php echo Azure_Event_Rsvp::render_cards(Azure_Event_Rsvp::dashboard_cards($events)); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render_cards() ?>
    <h2><?php esc_html_e('All RSVP events', 'azure-plugin'); ?></h2>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Event', 'azure-plugin'); ?></th>
                <th><?php esc_html_e('Date', 'azure-plugin'); ?></th>
                <th><?php esc_html_e('Going', 'azure-plugin'); ?></th>
                <th><?php esc_html_e('Capacity', 'azure-plugin'); ?></th>
                <th><?php esc_html_e('Deadline', 'azure-plugin'); ?></th>
                <th><?php esc_html_e('Guests allowed', 'azure-plugin'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$events) : ?>
            <tr><td colspan="6"><?php esc_html_e('No events take RSVPs yet.', 'azure-plugin'); ?></td></tr>
        <?php endif; ?>
        <?php foreach ($events as $ev) :
            $s = Azure_Event_Rsvp::event_settings($ev->ID);
            $view = add_query_arg('event_id', (int) $ev->ID, $list_url);
            ?>
            <tr>
                <td><a href="<?php echo esc_url($view); ?>"><strong><?php echo esc_html(get_the_title($ev)); ?></strong></a>
                    <?php if ($ev->post_status !== 'publish') : ?> — <em><?php echo esc_html($ev->post_status); ?></em><?php endif; ?></td>
                <td><?php echo esc_html((string) get_post_meta($ev->ID, '_EventStartDate', true)); ?></td>
                <td><?php echo (int) Azure_Event_Rsvp::seats_taken($ev->ID); ?></td>
                <td><?php echo $s['capacity'] > 0 ? (int) $s['capacity'] : esc_html__('No limit', 'azure-plugin'); ?></td>
                <td><?php echo $s['deadline'] !== '' ? esc_html($s['deadline']) : esc_html__('Event start', 'azure-plugin'); ?></td>
                <td><?php echo (int) $s['max_guests']; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>
