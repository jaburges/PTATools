<?php
/**
 * Home Screen admin: pin-instruction toggle and notification list.
 *
 * @var string $notice
 * @var object|null $editing
 * @var array $notifications
 * @var array $counts
 * @var array $choices
 * @var bool $show_pin
 */

if (!defined('ABSPATH')) {
    exit;
}

$grades = $choices[0];
$teachers = $choices[1];
$selected = array();
if ($editing && !empty($editing->audience_values)) {
    $decoded = json_decode((string) $editing->audience_values, true);
    if (is_array($decoded)) {
        $selected = $decoded;
    }
}
$audience = $editing ? (string) $editing->audience : 'all';
$send_local = '';
if ($editing && !empty($editing->send_at)) {
    try {
        $send_local = (new DateTimeImmutable($editing->send_at, new DateTimeZone('UTC')))
            ->setTimezone(wp_timezone())
            ->format('Y-m-d\TH:i');
    } catch (Exception $e) {
        $send_local = '';
    }
}
?>
<div class="wrap">
    <h1><?php esc_html_e('Home Screen', 'azure-plugin'); ?></h1>
    <?php Azure_Home_Screen::render_module_switch('home_screen', __('Pinned home-screen icon and scheduled notifications.', 'azure-plugin')); ?>

    <?php if ($notice !== ''): ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
    <?php endif; ?>

    <?php if (!Azure_Home_Screen::is_enabled()): ?>
        <p><?php esc_html_e('Turn the module on to show the pin popup and send notifications.', 'azure-plugin'); ?></p>
    <?php else: ?>

    <form method="post" style="background:#fff;border:1px solid #c3c4c7;padding:16px;margin:0 0 20px;max-width:760px;">
        <?php wp_nonce_field(Azure_Home_Screen::NONCE); ?>
        <input type="hidden" name="pta_home_screen_action" value="pin" />
        <label>
            <input type="checkbox" name="show_pin" value="1" <?php checked($show_pin); ?> />
            <?php esc_html_e('Show Pinning instructions', 'azure-plugin'); ?>
        </label>
        <p class="description"><?php esc_html_e('A small popup on phones tells every visitor how to add the site to their home screen. It stays hidden after they close it, and it does not appear when the site is already opened from that icon.', 'azure-plugin'); ?></p>
        <?php submit_button(__('Save', 'azure-plugin'), 'secondary', 'submit', false); ?>
    </form>

    <p>
        <?php
        printf(
            esc_html__('%1$d devices can receive notifications. %2$d of those are signed in, so a grade or teacher filter can include them.', 'azure-plugin'),
            (int) $counts['total'],
            (int) $counts['signed_in']
        );
        ?>
    </p>

    <h2><?php echo $editing ? esc_html__('Edit notification', 'azure-plugin') : esc_html__('New notification', 'azure-plugin'); ?></h2>
    <form method="post" style="background:#fff;border:1px solid #c3c4c7;padding:16px;margin:0 0 24px;max-width:760px;">
        <?php wp_nonce_field(Azure_Home_Screen::NONCE); ?>
        <input type="hidden" name="pta_home_screen_action" value="save" />
        <?php if ($editing): ?>
            <input type="hidden" name="notification_id" value="<?php echo (int) $editing->id; ?>" />
        <?php endif; ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="pta-push-title"><?php esc_html_e('Title', 'azure-plugin'); ?></label></th>
                <td><input type="text" class="regular-text" id="pta-push-title" name="title" maxlength="120" required value="<?php echo esc_attr($editing ? $editing->title : ''); ?>" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="pta-push-body"><?php esc_html_e('Message', 'azure-plugin'); ?></label></th>
                <td><textarea class="large-text" rows="4" id="pta-push-body" name="body" maxlength="500" required><?php echo esc_textarea($editing ? $editing->body : ''); ?></textarea></td>
            </tr>
            <tr>
                <th scope="row"><label for="pta-push-link"><?php esc_html_e('Link', 'azure-plugin'); ?></label></th>
                <td>
                    <input type="url" class="regular-text" id="pta-push-link" name="link_url" value="<?php echo esc_attr($editing ? $editing->link_url : ''); ?>" placeholder="https://wilderptsa.net/" />
                    <p class="description"><?php esc_html_e('Opened when the notification is tapped. Leave blank for the home page.', 'azure-plugin'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Audience', 'azure-plugin'); ?></th>
                <td>
                    <label><input type="radio" name="audience" value="all" <?php checked($audience, 'all'); ?> /> <?php esc_html_e('Everyone who allowed notifications', 'azure-plugin'); ?></label><br />
                    <label><input type="radio" name="audience" value="grade" <?php checked($audience, 'grade'); ?> /> <?php esc_html_e('Parents of a grade', 'azure-plugin'); ?></label><br />
                    <label><input type="radio" name="audience" value="teacher" <?php checked($audience, 'teacher'); ?> /> <?php esc_html_e('Parents of a teacher', 'azure-plugin'); ?></label>
                    <p class="description"><?php esc_html_e('A grade or teacher notice goes to signed-in parents who have a child in that class. A parent with two children receives it when either child matches. A device that pinned while signed out is included only in an everyone notice.', 'azure-plugin'); ?></p>
                    <div style="margin-top:8px;">
                        <strong><?php esc_html_e('Grades', 'azure-plugin'); ?></strong><br />
                        <?php foreach ($grades as $grade): ?>
                            <label style="display:inline-block;margin:0 12px 6px 0;">
                                <input type="checkbox" name="audience_values[]" value="<?php echo esc_attr($grade); ?>" <?php checked(in_array($grade, $selected, true)); ?> />
                                <?php echo esc_html($grade); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top:8px;">
                        <strong><?php esc_html_e('Teachers', 'azure-plugin'); ?></strong><br />
                        <?php foreach ($teachers as $teacher): ?>
                            <label style="display:inline-block;margin:0 12px 6px 0;">
                                <input type="checkbox" name="audience_values[]" value="<?php echo esc_attr($teacher); ?>" <?php checked(in_array($teacher, $selected, true)); ?> />
                                <?php echo esc_html($teacher); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="pta-push-when"><?php esc_html_e('Send at', 'azure-plugin'); ?></label></th>
                <td><input type="datetime-local" id="pta-push-when" name="send_at" value="<?php echo esc_attr($send_local); ?>" /></td>
            </tr>
        </table>
        <p>
            <button type="submit" class="button" name="save_draft" value="1"><?php esc_html_e('Save draft', 'azure-plugin'); ?></button>
            <button type="submit" class="button button-secondary" name="schedule" value="1"><?php esc_html_e('Schedule', 'azure-plugin'); ?></button>
            <button type="submit" class="button button-primary" name="send_now" value="1"><?php esc_html_e('Send now', 'azure-plugin'); ?></button>
        </p>
    </form>

    <h2><?php esc_html_e('Notifications', 'azure-plugin'); ?></h2>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Title', 'azure-plugin'); ?></th>
                <th><?php esc_html_e('Audience', 'azure-plugin'); ?></th>
                <th><?php esc_html_e('When', 'azure-plugin'); ?></th>
                <th><?php esc_html_e('Status', 'azure-plugin'); ?></th>
                <th><?php esc_html_e('Sent', 'azure-plugin'); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$notifications): ?>
            <tr><td colspan="6"><?php esc_html_e('No notifications yet.', 'azure-plugin'); ?></td></tr>
        <?php else: ?>
            <?php foreach ($notifications as $note): ?>
                <tr>
                    <td><?php echo esc_html($note->title); ?></td>
                    <td>
                        <?php
                        echo esc_html($note->audience);
                        $vals = json_decode((string) $note->audience_values, true);
                        if (is_array($vals) && $vals) {
                            echo ': ' . esc_html(implode(', ', $vals));
                        }
                        ?>
                    </td>
                    <td>
                        <?php
                        if (!empty($note->send_at)) {
                            echo esc_html(get_date_from_gmt($note->send_at, 'M j, Y g:i a'));
                        }
                        ?>
                    </td>
                    <td><?php echo esc_html($note->status); ?></td>
                    <td><?php echo (int) $note->sent_count; ?> / <?php echo (int) $note->failed_count; ?> <?php esc_html_e('failed', 'azure-plugin'); ?></td>
                    <td>
                        <?php if (in_array($note->status, array('draft', 'scheduled'), true)): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-home-screen&action=edit&id=' . (int) $note->id)); ?>"><?php esc_html_e('Edit', 'azure-plugin'); ?></a>
                        <?php endif; ?>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field(Azure_Home_Screen::NONCE); ?>
                            <input type="hidden" name="pta_home_screen_action" value="delete" />
                            <input type="hidden" name="notification_id" value="<?php echo (int) $note->id; ?>" />
                            <button type="submit" class="button-link" style="color:#b32d2e;" onclick="return confirm('<?php echo esc_js(__('Delete this notification?', 'azure-plugin')); ?>');"><?php esc_html_e('Delete', 'azure-plugin'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
