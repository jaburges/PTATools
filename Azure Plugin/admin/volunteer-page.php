<?php
if (!defined('ABSPATH')) {
    exit;
}

$settings = Azure_Settings::get_all_settings();
$volunteer_enabled = $settings['enable_volunteer'] ?? false;
$sheets = class_exists('Azure_Volunteer_Signup') ? Azure_Volunteer_Signup::get_sheets() : array();
$pta_events = class_exists('Azure_Volunteer_Signup') ? Azure_Volunteer_Signup::get_pta_events_for_dropdown() : array();
?>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
<div class="wrap">
    <h1><span class="dashicons dashicons-groups"></span> <?php _e('Volunteer Sign Up', 'azure-plugin'); ?></h1>
<?php endif; ?>

<?php if (!$volunteer_enabled): ?>
<div class="notice notice-warning" style="margin: 15px 0;">
    <p><?php _e('The Volunteer Sign Up module is currently disabled.', 'azure-plugin'); ?>
    <a href="<?php echo admin_url('admin.php?page=azure-plugin'); ?>"><?php _e('Enable it on the main settings page.', 'azure-plugin'); ?></a></p>
</div>
<?php endif; ?>

<p class="description" style="margin: 8px 0 16px;">
    <?php _e('Create sign-up sheets. Assign a sheet to an event to show it on that event page automatically. You can still paste the shortcode on any other page.', 'azure-plugin'); ?>
</p>

<div class="azure-module-content">
    <div class="azure-action-row" style="margin-bottom: 16px;">
        <button type="button" class="button button-primary" id="azure-vs-new-sheet">
            <span class="dashicons dashicons-plus-alt2"></span> <?php _e('New Sign-Up Sheet', 'azure-plugin'); ?>
        </button>
    </div>

    <?php if (empty($sheets)): ?>
        <p><?php _e('No sign-up sheets yet. Create one above.', 'azure-plugin'); ?></p>
    <?php else: ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:22%;"><?php _e('Title', 'azure-plugin'); ?></th>
                <th><?php _e('Assigned event', 'azure-plugin'); ?></th>
                <th><?php _e('Event Date', 'azure-plugin'); ?></th>
                <th><?php _e('Location', 'azure-plugin'); ?></th>
                <th><?php _e('Activities', 'azure-plugin'); ?></th>
                <th><?php _e('Status', 'azure-plugin'); ?></th>
                <th><?php _e('Shortcode', 'azure-plugin'); ?></th>
                <th style="width:120px;"><?php _e('Actions', 'azure-plugin'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($sheets as $s):
            $acts = Azure_Volunteer_Signup::get_activities($s->id);
            $total_spots = 0;
            $total_filled = 0;
            foreach ($acts as $a) {
                $total_spots += (int) $a->spots_needed;
                $total_filled += Azure_Volunteer_Signup::count_signups($a->id);
            }
        ?>
            <tr>
                <td><strong><?php echo esc_html($s->title); ?></strong></td>
                <td><?php
                    $linked = (int) ($s->pta_event_id ?? 0);
                    if ($linked && function_exists('get_the_title') && get_the_title($linked)) {
                        $url = get_permalink($linked);
                        echo $url
                            ? '<a href="' . esc_url($url) . '">' . esc_html(get_the_title($linked)) . '</a>'
                            : esc_html(get_the_title($linked));
                    } else {
                        echo '—';
                    }
                ?></td>
                <td><?php echo $s->event_date ? date_i18n(get_option('date_format'), strtotime($s->event_date)) : '—'; ?></td>
                <td><?php echo $s->event_location ? esc_html($s->event_location) : '—'; ?></td>
                <td><?php echo count($acts); ?> <?php _e('roles', 'azure-plugin'); ?> — <?php echo $total_filled; ?>/<?php echo $total_spots; ?> <?php _e('filled', 'azure-plugin'); ?></td>
                <td>
                    <span class="azure-vs-status-badge <?php echo $s->status; ?>">
                        <?php echo $s->status === 'open' ? __('Open', 'azure-plugin') : __('Closed', 'azure-plugin'); ?>
                    </span>
                </td>
                <td><input type="text" readonly value='[volunteer_signup id="<?php echo esc_attr($s->id); ?>"]' onclick="this.select();" class="code" style="width:100%;font-size:11px;" /></td>
                <td>
                    <button type="button" class="button button-small azure-vs-edit-sheet" data-id="<?php echo esc_attr($s->id); ?>">
                        <span class="dashicons dashicons-edit" style="font-size:14px;width:14px;height:14px;line-height:14px;vertical-align:middle;"></span>
                    </button>
                    <button type="button" class="button button-small button-link-delete azure-vs-delete-sheet" data-id="<?php echo esc_attr($s->id); ?>">
                        <span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;line-height:14px;vertical-align:middle;"></span>
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Sheet Editor Modal -->
<div id="azure-vs-modal" class="azure-vs-modal-overlay" style="display:none;">
    <div class="azure-vs-modal-content">
        <div class="azure-vs-modal-header">
            <h2 id="azure-vs-modal-title"><?php _e('New Sign-Up Sheet', 'azure-plugin'); ?></h2>
            <button type="button" class="azure-vs-modal-close">&times;</button>
        </div>
        <div class="azure-vs-modal-body">
            <input type="hidden" id="azure-vs-sheet-id" value="0" />
            <table class="form-table">
                <tr>
                    <th><label for="azure-vs-title"><?php _e('Title', 'azure-plugin'); ?></label></th>
                    <td><input type="text" id="azure-vs-title" class="regular-text" /></td>
                </tr>
                <tr>
                    <th><label for="azure-vs-description"><?php _e('Description', 'azure-plugin'); ?></label></th>
                    <td><textarea id="azure-vs-description" rows="2" class="large-text"></textarea></td>
                </tr>
                <tr>
                    <th><?php _e('Assign to an event', 'azure-plugin'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="azure-vs-assign-event" value="1" />
                            <?php _e('Assign to an event', 'azure-plugin'); ?>
                        </label>
                        <p class="description"><?php _e('When assigned, this sign-up appears on the event page.', 'azure-plugin'); ?></p>
                    </td>
                </tr>
                <tr class="azure-vs-event-fields" style="display:none;">
                    <th><label for="azure-vs-pta-event"><?php _e('Event', 'azure-plugin'); ?></label></th>
                    <td>
                        <select id="azure-vs-pta-event" class="regular-text">
                            <option value="0"><?php _e('— Select an event —', 'azure-plugin'); ?></option>
                            <?php foreach ($pta_events as $ev): ?>
                                <option value="<?php echo esc_attr($ev['id']); ?>"
                                        data-date="<?php echo esc_attr($ev['date']); ?>"
                                        data-location="<?php echo esc_attr($ev['location'] ?? ''); ?>"
                                ><?php echo esc_html($ev['title']); ?><?php echo !empty($ev['date']) ? ' (' . esc_html(date_i18n('M j', strtotime($ev['date']))) . ')' : ''; ?></option>
                            <?php endforeach; ?>
                            <option value="__new__"><?php _e('Create new event…', 'azure-plugin'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr class="azure-vs-new-event-fields" style="display:none;">
                    <th><label for="azure-vs-new-event-title"><?php _e('New event title', 'azure-plugin'); ?></label></th>
                    <td>
                        <input type="text" id="azure-vs-new-event-title" class="regular-text" />
                        <p class="description"><?php _e('Leave blank to use the sign-up title. Date and location below become the new event.', 'azure-plugin'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="azure-vs-event-date"><?php _e('Event Date', 'azure-plugin'); ?></label></th>
                    <td><input type="datetime-local" id="azure-vs-event-date" /></td>
                </tr>
                <tr>
                    <th><label for="azure-vs-event-location"><?php _e('Event Location', 'azure-plugin'); ?></label></th>
                    <td><input type="text" id="azure-vs-event-location" class="regular-text" /></td>
                </tr>
                <tr>
                    <th><label for="azure-vs-status"><?php _e('Status', 'azure-plugin'); ?></label></th>
                    <td>
                        <select id="azure-vs-status">
                            <option value="open"><?php _e('Open', 'azure-plugin'); ?></option>
                            <option value="closed"><?php _e('Closed', 'azure-plugin'); ?></option>
                        </select>
                    </td>
                </tr>
            </table>

            <h3><?php _e('Activities / Roles', 'azure-plugin'); ?></h3>
            <p class="description"><?php _e('Add volunteer roles. Start and end times are Pacific Time and combine with the event date.', 'azure-plugin'); ?></p>
            <div class="azure-vs-activity-headings">
                <span><?php esc_html_e('Activity', 'azure-plugin'); ?></span>
                <span><?php esc_html_e('Start', 'azure-plugin'); ?></span>
                <span><?php esc_html_e('End', 'azure-plugin'); ?></span>
                <span><?php esc_html_e('Spots', 'azure-plugin'); ?></span>
                <span><?php esc_html_e('Description', 'azure-plugin'); ?></span>
            </div>
            <div id="azure-vs-activities-list"></div>
            <button type="button" class="button" id="azure-vs-add-activity">
                <span class="dashicons dashicons-plus-alt2" style="font-size:14px;width:14px;height:14px;line-height:20px;vertical-align:middle;"></span> <?php _e('Add Activity', 'azure-plugin'); ?>
            </button>
        </div>
        <div class="azure-vs-modal-footer">
            <button type="button" class="button button-primary" id="azure-vs-save-sheet"><?php _e('Save Sheet', 'azure-plugin'); ?></button>
            <button type="button" class="button azure-vs-modal-close"><?php _e('Cancel', 'azure-plugin'); ?></button>
        </div>
    </div>
</div>

<script>
jQuery(function($) {
    var activityIdx = 0;

    function timeFromMysql(dt) {
        if (!dt) return '';
        var m = String(dt).match(/(\d{2}:\d{2})/);
        return m ? m[1] : '';
    }

    function addActivityRow(data) {
        data = data || {};
        var id = data.id || 0;
        var name = data.name || '';
        var desc = data.description || '';
        var spots = data.spots_needed || 1;
        var start = timeFromMysql(data.slot_start);
        var end = timeFromMysql(data.slot_end);
        var html = '<div class="azure-vs-activity-row" data-idx="' + activityIdx + '">' +
            '<input type="hidden" class="act-id" value="' + id + '" />' +
            '<input type="text" class="act-name regular-text" placeholder="<?php esc_attr_e('Activity name', 'azure-plugin'); ?>" value="' + $('<div>').text(name).html() + '" />' +
            '<input type="time" class="act-start" value="' + start + '" title="<?php esc_attr_e('Start (Pacific)', 'azure-plugin'); ?>" />' +
            '<input type="time" class="act-end" value="' + end + '" title="<?php esc_attr_e('End (Pacific)', 'azure-plugin'); ?>" />' +
            '<input type="number" class="act-spots" min="1" value="' + spots + '" title="<?php esc_attr_e('Spots needed', 'azure-plugin'); ?>" />' +
            '<input type="text" class="act-desc" placeholder="<?php esc_attr_e('Description (optional)', 'azure-plugin'); ?>" value="' + $('<div>').text(desc).html() + '" />' +
            '<button type="button" class="button button-link-delete azure-vs-remove-activity" title="<?php esc_attr_e('Remove', 'azure-plugin'); ?>">&times;</button>' +
            '</div>';
        $('#azure-vs-activities-list').append(html);
        activityIdx++;
    }

    $('#azure-vs-add-activity').on('click', function() { addActivityRow(); });
    $(document).on('click', '.azure-vs-remove-activity', function() { $(this).closest('.azure-vs-activity-row').remove(); });

    function syncEventFields() {
        var assign = $('#azure-vs-assign-event').is(':checked');
        $('.azure-vs-event-fields').toggle(assign);
        var choice = $('#azure-vs-pta-event').val();
        $('.azure-vs-new-event-fields').toggle(assign && choice === '__new__');
    }

    function openModal(editId) {
        activityIdx = 0;
        $('#azure-vs-activities-list').empty();
        $('#azure-vs-sheet-id').val(0);
        $('#azure-vs-title').val('');
        $('#azure-vs-description').val('');
        $('#azure-vs-assign-event').prop('checked', false);
        $('#azure-vs-pta-event').val(0);
        $('#azure-vs-new-event-title').val('');
        $('#azure-vs-event-date').val('');
        $('#azure-vs-event-location').val('');
        $('#azure-vs-status').val('open');
        syncEventFields();
        $('#azure-vs-modal-title').text(editId ? '<?php echo esc_js(__('Edit Sign-Up Sheet', 'azure-plugin')); ?>' : '<?php echo esc_js(__('New Sign-Up Sheet', 'azure-plugin')); ?>');

        if (editId) {
            $.get(ajaxurl, { action: 'azure_volunteer_get_sheet', sheet_id: editId, nonce: azure_plugin_ajax.nonce }, function(res) {
                if (!res.success) return;
                var s = res.data.sheet;
                $('#azure-vs-sheet-id').val(s.id);
                $('#azure-vs-title').val(s.title);
                $('#azure-vs-description').val(s.description || '');
                var eventId = parseInt(s.pta_event_id || s.tec_event_id || 0, 10);
                $('#azure-vs-assign-event').prop('checked', eventId > 0);
                if (eventId > 0 && $('#azure-vs-pta-event option[value="' + eventId + '"]').length === 0) {
                    var label = res.data.event_title || ('Event #' + eventId);
                    $('#azure-vs-pta-event option[value="__new__"]').before(
                        $('<option>').attr('value', eventId).text(label)
                    );
                }
                $('#azure-vs-pta-event').val(eventId > 0 ? String(eventId) : 0);
                $('#azure-vs-new-event-title').val('');
                if (s.event_date) {
                    var d = s.event_date.replace(' ', 'T').substring(0, 16);
                    $('#azure-vs-event-date').val(d);
                }
                $('#azure-vs-event-location').val(s.event_location || '');
                $('#azure-vs-status').val(s.status);
                syncEventFields();
                (res.data.activities || []).forEach(function(a) { addActivityRow(a); });
            });
        } else {
            addActivityRow();
        }

        $('#azure-vs-modal').show();
    }

    $('#azure-vs-new-sheet').on('click', function() { openModal(0); });
    $(document).on('click', '.azure-vs-edit-sheet', function() { openModal($(this).data('id')); });
    $(document).on('click', '.azure-vs-modal-close', function() { $('#azure-vs-modal').hide(); });
    $('#azure-vs-modal').on('click', function(e) { if (e.target === this) $(this).hide(); });

    $('#azure-vs-save-sheet').on('click', function() {
        var $btn = $(this);
        var activities = [];
        $('#azure-vs-activities-list .azure-vs-activity-row').each(function() {
            var name = $(this).find('.act-name').val();
            if (!name) return;
            activities.push({
                id: $(this).find('.act-id').val() || 0,
                name: name,
                description: $(this).find('.act-desc').val(),
                spots_needed: $(this).find('.act-spots').val() || 1,
                slot_start: $(this).find('.act-start').val() || '',
                slot_end: $(this).find('.act-end').val() || ''
            });
        });

        var eventDate = $('#azure-vs-event-date').val();
        if (eventDate) {
            eventDate = eventDate.replace('T', ' ') + ':00';
        }

        if ($('#azure-vs-assign-event').is(':checked')) {
            var ev = $('#azure-vs-pta-event').val();
            if (!ev || ev === '0') {
                alert('<?php echo esc_js(__('Select an event or choose Create new event.', 'azure-plugin')); ?>');
                return;
            }
        }

        $btn.prop('disabled', true).text('<?php echo esc_js(__('Saving...', 'azure-plugin')); ?>');
        $.post(ajaxurl, {
            action: 'azure_volunteer_save_sheet',
            nonce: azure_plugin_ajax.nonce,
            sheet_id: $('#azure-vs-sheet-id').val(),
            title: $('#azure-vs-title').val(),
            description: $('#azure-vs-description').val(),
            assign_to_event: $('#azure-vs-assign-event').is(':checked') ? 1 : 0,
            pta_event_id: $('#azure-vs-pta-event').val() || 0,
            new_event_title: $('#azure-vs-new-event-title').val() || '',
            event_date: eventDate,
            event_location: $('#azure-vs-event-location').val(),
            status: $('#azure-vs-status').val(),
            activities: JSON.stringify(activities)
        }, function(res) {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('Save Sheet', 'azure-plugin')); ?>');
            if (res.success) {
                location.reload();
            } else {
                alert(res.data || 'Error saving sheet.');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('Save Sheet', 'azure-plugin')); ?>');
            alert('Network error.');
        });
    });

    $(document).on('click', '.azure-vs-delete-sheet', function() {
        if (!confirm('<?php echo esc_js(__('Delete this sign-up sheet and all signups?', 'azure-plugin')); ?>')) return;
        var id = $(this).data('id');
        $.post(ajaxurl, { action: 'azure_volunteer_delete_sheet', nonce: azure_plugin_ajax.nonce, sheet_id: id }, function(res) {
            if (res.success) location.reload();
            else alert(res.data || 'Error');
        });
    });

    $('#azure-vs-assign-event').on('change', syncEventFields);
    $('#azure-vs-pta-event').on('change', function() {
        syncEventFields();
        var $opt = $(this).find(':selected');
        if ($opt.val() === '__new__' || $opt.val() === '0') {
            return;
        }
        var date = $opt.data('date') || '';
        var location = $opt.data('location') || '';
        if (date) {
            var dt = date.replace(' ', 'T').substring(0, 16);
            $('#azure-vs-event-date').val(dt);
        }
        if (location) {
            $('#azure-vs-event-location').val(location);
        }
    });
});
</script>

<style>
.azure-vs-status-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 600;
}
.azure-vs-status-badge.open { background: #d4edda; color: #155724; }
.azure-vs-status-badge.closed { background: #f8d7da; color: #721c24; }

.azure-vs-modal-overlay {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5); z-index: 100000;
    display: flex; align-items: center; justify-content: center;
}
.azure-vs-modal-content {
    background: #fff; border-radius: 8px; width: 780px; max-width: 95vw;
    max-height: 85vh; display: flex; flex-direction: column;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
}
.azure-vs-modal-header {
    padding: 16px 20px; border-bottom: 1px solid #ddd;
    display: flex; justify-content: space-between; align-items: center;
}
.azure-vs-modal-header h2 { margin: 0; font-size: 18px; }
.azure-vs-modal-close {
    background: none; border: none; font-size: 22px; cursor: pointer; padding: 0;
    width: 28px; height: 28px; line-height: 28px; text-align: center;
}
.azure-vs-modal-body { padding: 16px 20px; overflow-y: auto; flex: 1; }
.azure-vs-modal-body .form-table th { padding: 10px 10px 10px 0; width: 130px; }
.azure-vs-modal-body .form-table td { padding: 8px 0; }
.azure-vs-modal-body h3 { margin: 20px 0 4px; }
.azure-vs-modal-footer {
    padding: 14px 20px; border-top: 1px solid #ddd;
    display: flex; gap: 10px; justify-content: flex-end;
    background: #f6f7f7;
    border-radius: 0 0 8px 8px;
}

.azure-vs-activity-headings {
    display: flex; gap: 6px; align-items: center;
    font-size: 11px; text-transform: uppercase; color: #646970;
    letter-spacing: 0.03em; margin-top: 8px;
}
.azure-vs-activity-headings span:nth-child(1) { width: 28%; min-width: 140px; }
.azure-vs-activity-headings span:nth-child(2),
.azure-vs-activity-headings span:nth-child(3) { width: 110px; }
.azure-vs-activity-headings span:nth-child(4) { width: 60px; }
.azure-vs-activity-headings span:nth-child(5) { width: 22%; min-width: 120px; }
.azure-vs-activity-row {
    display: flex; gap: 6px; align-items: center;
    margin-bottom: 6px; padding: 6px 0; flex-wrap: wrap;
}
.azure-vs-activity-row .act-name { width: 28%; min-width: 140px; }
.azure-vs-activity-row .act-desc { width: 22%; min-width: 120px; }
.azure-vs-activity-row .act-start,
.azure-vs-activity-row .act-end { width: 110px; }
.azure-vs-activity-row .act-spots { width: 60px; }
.azure-vs-activity-row input[type="text"],
.azure-vs-activity-row input[type="number"],
.azure-vs-activity-row input[type="time"] {
    font-size: 13px;
}
</style>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
</div>
<?php endif; ?>
