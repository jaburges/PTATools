<?php
if (!defined('ABSPATH')) {
    exit;
}

$settings = Azure_Settings::get_all_settings();
$volunteer_enabled = $settings['enable_volunteer'] ?? false;
$sheets = class_exists('Azure_Volunteer_Signup') ? Azure_Volunteer_Signup::get_sheets() : array();
$tec_events = class_exists('Azure_Volunteer_Signup') ? Azure_Volunteer_Signup::get_tec_events_for_dropdown() : array();
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
    <?php _e('Create sign-up sheets for events. Add activity roles with volunteer spots. Share the shortcode on any page so members can sign up.', 'azure-plugin'); ?>
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
                <th style="width:30%;"><?php _e('Title', 'azure-plugin'); ?></th>
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
                <?php if (!empty($tec_events)): ?>
                <tr>
                    <th><label for="azure-vs-tec-event"><?php _e('Link to TEC Event', 'azure-plugin'); ?></label></th>
                    <td>
                        <select id="azure-vs-tec-event" class="regular-text">
                            <option value="0"><?php _e('— None (standalone) —', 'azure-plugin'); ?></option>
                            <?php foreach ($tec_events as $ev): ?>
                                <option value="<?php echo esc_attr($ev['id']); ?>"
                                        data-date="<?php echo esc_attr($ev['date']); ?>"
                                        data-location="<?php echo esc_attr($ev['location'] ?? ''); ?>"
                                ><?php echo esc_html($ev['title']); ?> (<?php echo esc_html(date_i18n('M j', strtotime($ev['date']))); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <?php endif; ?>
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
            <p class="description"><?php _e('Add the volunteer roles needed. Each role has a name and number of spots.', 'azure-plugin'); ?></p>
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

    function addActivityRow(data) {
        data = data || {};
        var id = data.id || 0;
        var name = data.name || '';
        var desc = data.description || '';
        var spots = data.spots_needed || 1;
        var html = '<div class="azure-vs-activity-row" data-idx="' + activityIdx + '">' +
            '<input type="hidden" class="act-id" value="' + id + '" />' +
            '<input type="text" class="act-name regular-text" placeholder="<?php esc_attr_e('Activity name', 'azure-plugin'); ?>" value="' + $('<div>').text(name).html() + '" style="width:40%;" />' +
            '<input type="text" class="act-desc" placeholder="<?php esc_attr_e('Description (optional)', 'azure-plugin'); ?>" value="' + $('<div>').text(desc).html() + '" style="width:30%;" />' +
            '<input type="number" class="act-spots" min="1" value="' + spots + '" style="width:60px;" title="<?php esc_attr_e('Spots needed', 'azure-plugin'); ?>" />' +
            '<button type="button" class="button button-link-delete azure-vs-remove-activity" title="<?php esc_attr_e('Remove', 'azure-plugin'); ?>">&times;</button>' +
            '</div>';
        $('#azure-vs-activities-list').append(html);
        activityIdx++;
    }

    $('#azure-vs-add-activity').on('click', function() { addActivityRow(); });
    $(document).on('click', '.azure-vs-remove-activity', function() { $(this).closest('.azure-vs-activity-row').remove(); });

    function openModal(editId) {
        activityIdx = 0;
        $('#azure-vs-activities-list').empty();
        $('#azure-vs-sheet-id').val(0);
        $('#azure-vs-title').val('');
        $('#azure-vs-description').val('');
        $('#azure-vs-tec-event').val(0);
        $('#azure-vs-event-date').val('');
        $('#azure-vs-event-location').val('');
        $('#azure-vs-status').val('open');
        $('#azure-vs-modal-title').text(editId ? '<?php echo esc_js(__('Edit Sign-Up Sheet', 'azure-plugin')); ?>' : '<?php echo esc_js(__('New Sign-Up Sheet', 'azure-plugin')); ?>');

        if (editId) {
            $.get(ajaxurl, { action: 'azure_volunteer_get_sheet', sheet_id: editId, nonce: azure_plugin_ajax.nonce }, function(res) {
                if (!res.success) return;
                var s = res.data.sheet;
                $('#azure-vs-sheet-id').val(s.id);
                $('#azure-vs-title').val(s.title);
                $('#azure-vs-description').val(s.description || '');
                $('#azure-vs-tec-event').val(s.tec_event_id || 0);
                if (s.event_date) {
                    var d = s.event_date.replace(' ', 'T').substring(0, 16);
                    $('#azure-vs-event-date').val(d);
                }
                $('#azure-vs-event-location').val(s.event_location || '');
                $('#azure-vs-status').val(s.status);
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
                spots_needed: $(this).find('.act-spots').val() || 1
            });
        });

        var eventDate = $('#azure-vs-event-date').val();
        if (eventDate) {
            eventDate = eventDate.replace('T', ' ') + ':00';
        }

        $btn.prop('disabled', true).text('<?php echo esc_js(__('Saving...', 'azure-plugin')); ?>');
        $.post(ajaxurl, {
            action: 'azure_volunteer_save_sheet',
            nonce: azure_plugin_ajax.nonce,
            sheet_id: $('#azure-vs-sheet-id').val(),
            title: $('#azure-vs-title').val(),
            description: $('#azure-vs-description').val(),
            tec_event_id: $('#azure-vs-tec-event').val() || 0,
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

    $('#azure-vs-tec-event').on('change', function() {
        var $opt = $(this).find(':selected');
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
    background: #fff; border-radius: 8px; width: 680px; max-width: 95vw;
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

.azure-vs-activity-row {
    display: flex; gap: 6px; align-items: center;
    margin-bottom: 6px; padding: 6px 0;
}
.azure-vs-activity-row input[type="text"],
.azure-vs-activity-row input[type="number"] {
    font-size: 13px;
}
</style>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
</div>
<?php endif; ?>
