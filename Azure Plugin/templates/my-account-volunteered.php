<?php
/**
 * My Account → Signups.
 *
 * Variables from Azure_Volunteer_Signup::render_account_page():
 * $signups, $opportunities, $feed_url, $webcal_url, $nonce, $ajax_url
 */

if (!defined('ABSPATH')) {
    exit;
}

$upcoming = array();
$earlier = array();
foreach ((array) $signups as $row) {
    if (Azure_Volunteer_Signup::slot_is_upcoming($row['sheet'], $row['activity'])) {
        $upcoming[] = $row;
    } else {
        $earlier[] = $row;
    }
}
$earlier = array_reverse($earlier);
$year_label = isset($opportunities['label']) ? (string) $opportunities['label'] : '';

$shift_date = function ($sheet, $activity) {
    $bounds = Azure_Volunteer_Signup::slot_bounds($sheet, $activity);
    if (empty($bounds['start'])) {
        return '';
    }
    try {
        $start = new DateTime($bounds['start'], new DateTimeZone(Azure_Volunteer_Signup::pacific_timezone()));
    } catch (Exception $e) {
        return '';
    }
    $ts = $start->getTimestamp();
    return function_exists('date_i18n') ? date_i18n('D, M j, Y', $ts) : $start->format('D, M j, Y');
};

$event_url = function ($sheet) {
    $id = (int) ($sheet->pta_event_id ?? 0);
    if (!$id || !function_exists('get_permalink')) {
        return '';
    }
    $url = get_permalink($id);
    return is_string($url) ? $url : '';
};

$activity_name = function ($sheet, $activity) use ($event_url) {
    $name = (string) ($activity->name ?? '');
    $url = $event_url($sheet);
    if ($url === '') {
        return '<span class="pta-vol-name">' . esc_html($name) . '</span>';
    }
    return '<a class="pta-vol-name" href="' . esc_url($url) . '">' . esc_html($name) . '</a>';
};

$render_signup_list = function ($rows) use ($shift_date, $activity_name) {
    if (!$rows) {
        return;
    }
    echo '<div class="pta-vol-table">';
    foreach ($rows as $row) {
        $sheet = $row['sheet'];
        $activity = $row['activity'];
        echo '<div class="pta-vol-line">';
        echo '<span></span>';
        echo $activity_name($sheet, $activity);
        echo '<span class="pta-vol-date">' . esc_html($shift_date($sheet, $activity)) . '</span>';
        echo '<span class="pta-vol-time">' . esc_html(Azure_Volunteer_Signup::slot_time_label($sheet, $activity)) . '</span>';
        echo '<span></span>';
        echo '<button type="button" class="button pta-vol-cancel" data-activity="' . esc_attr((string) $activity->id) . '">' . esc_html__('Cancel', 'azure-plugin') . '</button>';
        echo '</div>';
    }
    echo '</div>';
};

$render_groups = function ($groups) use ($shift_date, $activity_name) {
    if (!$groups) {
        echo '<p class="pta-volunteered-empty">' . esc_html__('Nothing open right now.', 'azure-plugin') . '</p>';
        return;
    }
    foreach ($groups as $label => $entries) {
        echo '<details class="pta-volunteered-group" open>';
        echo '<summary>' . esc_html((string) $label) . '</summary>';
        echo '<div class="pta-vol-table">';
        echo '<div class="pta-vol-line pta-vol-head">';
        echo '<span></span>';
        echo '<span>' . esc_html__('Name', 'azure-plugin') . '</span>';
        echo '<span>' . esc_html__('Date', 'azure-plugin') . '</span>';
        echo '<span>' . esc_html__('Time', 'azure-plugin') . '</span>';
        echo '<span>' . esc_html__('Slots', 'azure-plugin') . '</span>';
        echo '<span></span>';
        echo '</div>';
        foreach ($entries as $entry) {
            $sheet = $entry['sheet'];
            foreach ($entry['slots'] as $slot) {
                $activity = $slot['activity'];
                $filled = (int) $slot['filled'];
                $needed = (int) $slot['needed'];
                $open = max(0, $needed - $filled);
                if ($needed > 0 && $filled >= $needed) {
                    $state = 'is-full';
                } elseif ($filled > 0) {
                    $state = 'is-partial';
                } else {
                    $state = 'is-empty';
                }
                echo '<div class="pta-vol-line">';
                if ($open > 0) {
                    echo '<input type="checkbox" class="pta-vol-pick" value="' . esc_attr((string) $activity->id) . '" aria-label="' . esc_attr(sprintf(
                        /* translators: %s: activity name */
                        __('Select %s', 'azure-plugin'),
                        (string) $activity->name
                    )) . '" />';
                } else {
                    echo '<span></span>';
                }
                echo $activity_name($sheet, $activity);
                echo '<span class="pta-vol-date">' . esc_html($shift_date($sheet, $activity)) . '</span>';
                echo '<span class="pta-vol-time">' . esc_html(Azure_Volunteer_Signup::slot_time_label($sheet, $activity)) . '</span>';
                echo '<span class="pta-vol-slots ' . esc_attr($state) . '">' . esc_html($filled . '/' . $needed) . '</span>';
                if ($open > 0) {
                    echo '<button type="button" class="button pta-vol-signup" data-activity="' . esc_attr((string) $activity->id) . '">' . esc_html__('Sign up', 'azure-plugin') . '</button>';
                } else {
                    echo '<span></span>';
                }
                echo '</div>';
            }
        }
        echo '</div>';
        echo '</details>';
    }
};
?>
<div class="pta-volunteered" data-ajax="<?php echo esc_url($ajax_url); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
    <section class="pta-volunteered-section">
        <h2><?php esc_html_e('Your signups', 'azure-plugin'); ?></h2>
        <p class="pta-volunteered-subscribe">
            <a class="button button-primary" href="<?php echo esc_url($webcal_url); ?>"><?php esc_html_e('Subscribe', 'azure-plugin'); ?></a>
            <button type="button" class="button pta-vol-copy" data-url="<?php echo esc_attr($feed_url); ?>"><?php esc_html_e('Copy calendar link', 'azure-plugin'); ?></button>
        </p>
        <p class="pta-volunteered-note">
            <?php esc_html_e('Subscribe once. Your calendar checks this private link and adds or drops shifts when you sign up or cancel. Google and Outlook refresh on their own schedule, often within a few hours.', 'azure-plugin'); ?>
        </p>
        <?php if (!$upcoming && !$earlier): ?>
            <p class="pta-volunteered-empty"><?php esc_html_e('You are not signed up for anything yet.', 'azure-plugin'); ?></p>
        <?php else: ?>
            <?php $render_signup_list($upcoming); ?>
            <?php if ($earlier): ?>
                <h3><?php esc_html_e('Earlier', 'azure-plugin'); ?></h3>
                <?php $render_signup_list($earlier); ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="pta-volunteered-section">
        <h2>
            <?php
            echo esc_html(sprintf(
                /* translators: %s: school year, e.g. 2026–2027 */
                __('Open signups for %s', 'azure-plugin'),
                $year_label
            ));
            ?>
        </h2>
        <h3><?php esc_html_e('General volunteer opportunities', 'azure-plugin'); ?></h3>
        <?php $render_groups(isset($opportunities['general']) ? $opportunities['general'] : array()); ?>

        <h3><?php esc_html_e('Teacher-specific volunteering', 'azure-plugin'); ?></h3>
        <?php
        $children = isset($opportunities['children']) ? (array) $opportunities['children'] : array();
        $has_class = false;
        foreach ($children as $child) {
            if (!empty($child['teacher']) || !empty($child['grade'])) {
                $has_class = true;
                break;
            }
        }
        if ($children && !$has_class):
        ?>
            <p class="pta-volunteered-note">
                <?php esc_html_e('Add each child’s teacher or grade on Family Info to see class signups.', 'azure-plugin'); ?>
            </p>
        <?php endif; ?>
        <?php $render_groups(isset($opportunities['specific']) ? $opportunities['specific'] : array()); ?>
    </section>

    <div class="pta-vol-bulk" hidden>
        <span class="pta-vol-bulk-label"></span>
        <button type="button" class="button button-primary pta-vol-bulk-go"><?php esc_html_e('Sign up', 'azure-plugin'); ?></button>
    </div>
</div>
<script>
(function () {
    var root = document.querySelector('.pta-volunteered');
    if (!root) return;
    var ajax = root.getAttribute('data-ajax');
    var nonce = root.getAttribute('data-nonce');
    var bulk = root.querySelector('.pta-vol-bulk');
    var bulkLabel = root.querySelector('.pta-vol-bulk-label');
    var bulkGo = root.querySelector('.pta-vol-bulk-go');
    function post(body) {
        return fetch(ajax, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(function (res) { return res.json(); });
    }
    function selectedIds() {
        var ids = [];
        root.querySelectorAll('.pta-vol-pick:checked').forEach(function (box) {
            if (box.value) ids.push(box.value);
        });
        return ids;
    }
    function refreshBulk() {
        var ids = selectedIds();
        if (!bulk) return;
        if (!ids.length) {
            bulk.hidden = true;
            return;
        }
        bulk.hidden = false;
        if (bulkLabel) {
            bulkLabel.textContent = ids.length === 1
                ? '<?php echo esc_js(__('1 selected', 'azure-plugin')); ?>'
                : ids.length + ' <?php echo esc_js(__('selected', 'azure-plugin')); ?>';
        }
    }
    function signupIds(ids, button) {
        if (!ids.length) return;
        if (button) button.disabled = true;
        var body = 'action=azure_volunteer_signup&nonce=' + encodeURIComponent(nonce);
        ids.forEach(function (id) {
            body += '&activity_ids[]=' + encodeURIComponent(id);
        });
        post(body).then(function (res) {
            if (res && res.success) window.location.reload();
            else {
                if (button) button.disabled = false;
                window.alert((res && res.data && res.data.message) || '<?php echo esc_js(__('Could not sign up.', 'azure-plugin')); ?>');
            }
        });
    }
    root.addEventListener('change', function (event) {
        if (event.target.classList && event.target.classList.contains('pta-vol-pick')) {
            refreshBulk();
        }
    });
    root.addEventListener('click', function (event) {
        var copy = event.target.closest('.pta-vol-copy');
        if (copy) {
            var url = copy.getAttribute('data-url') || '';
            var done = function () { copy.textContent = '<?php echo esc_js(__('Copied', 'azure-plugin')); ?>'; };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(done);
            } else {
                window.prompt('<?php echo esc_js(__('Calendar link', 'azure-plugin')); ?>', url);
            }
            return;
        }
        var cancel = event.target.closest('.pta-vol-cancel');
        if (cancel) {
            if (!window.confirm('<?php echo esc_js(__('Cancel this signup?', 'azure-plugin')); ?>')) return;
            cancel.disabled = true;
            post('action=azure_volunteer_withdraw&nonce=' + encodeURIComponent(nonce) + '&activity_id=' + encodeURIComponent(cancel.getAttribute('data-activity') || ''))
                .then(function (res) {
                    if (res && res.success) window.location.reload();
                    else {
                        cancel.disabled = false;
                        window.alert((res && res.data && res.data.message) || '<?php echo esc_js(__('Could not cancel.', 'azure-plugin')); ?>');
                    }
                });
            return;
        }
        var bulkButton = event.target.closest('.pta-vol-bulk-go');
        if (bulkButton) {
            signupIds(selectedIds(), bulkButton);
            return;
        }
        var signup = event.target.closest('.pta-vol-signup');
        if (signup) {
            signupIds([signup.getAttribute('data-activity') || ''], signup);
        }
    });
})();
</script>
