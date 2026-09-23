<?php
/**
 * My Account → Volunteered.
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

$format_when = function ($sheet, $activity) {
    $raw = '';
    if (!empty($activity->slot_start)) {
        $raw = (string) $activity->slot_start;
    } elseif (!empty($sheet->event_date)) {
        $raw = (string) $sheet->event_date;
    }
    $day = '';
    if ($raw !== '') {
        $ts = strtotime($raw);
        if ($ts) {
            $day = function_exists('date_i18n') ? date_i18n('D, M j, Y', $ts) : date('D, M j, Y', $ts);
        }
    }
    $time = Azure_Volunteer_Signup::slot_time_label($sheet, $activity);
    if ($day !== '' && $time !== '') {
        return $day . ' · ' . $time;
    }
    return $day !== '' ? $day : $time;
};

$render_signup_list = function ($rows) use ($format_when) {
    if (!$rows) {
        return;
    }
    echo '<ul class="pta-volunteered-list">';
    foreach ($rows as $row) {
        $sheet = $row['sheet'];
        $activity = $row['activity'];
        $when = $format_when($sheet, $activity);
        echo '<li class="pta-volunteered-row">';
        echo '<div class="pta-volunteered-row-main">';
        echo '<strong>' . esc_html((string) $sheet->title) . '</strong>';
        echo '<span>' . esc_html((string) $activity->name) . '</span>';
        if ($when !== '') {
            echo '<span class="pta-volunteered-when">' . esc_html($when) . '</span>';
        }
        echo '</div>';
        echo '<button type="button" class="button pta-vol-cancel" data-activity="' . esc_attr((string) $activity->id) . '">' . esc_html__('Cancel', 'azure-plugin') . '</button>';
        echo '</li>';
    }
    echo '</ul>';
};

$render_groups = function ($groups) use ($format_when) {
    if (!$groups) {
        echo '<p class="pta-volunteered-empty">' . esc_html__('Nothing open right now.', 'azure-plugin') . '</p>';
        return;
    }
    foreach ($groups as $label => $entries) {
        echo '<details class="pta-volunteered-group">';
        echo '<summary>' . esc_html((string) $label) . '</summary>';
        echo '<ul class="pta-volunteered-list">';
        foreach ($entries as $entry) {
            $sheet = $entry['sheet'];
            foreach ($entry['slots'] as $slot) {
                $activity = $slot['activity'];
                $when = $format_when($sheet, $activity);
                $open = max(0, (int) $slot['needed'] - (int) $slot['filled']);
                echo '<li class="pta-volunteered-row">';
                echo '<div class="pta-volunteered-row-main">';
                echo '<strong>' . esc_html((string) $activity->name) . '</strong>';
                if ($when !== '') {
                    echo '<span class="pta-volunteered-when">' . esc_html($when) . '</span>';
                }
                echo '<span class="pta-volunteered-spots">' . esc_html(sprintf(
                    /* translators: 1: spots filled, 2: spots needed */
                    __('%1$d/%2$d filled', 'azure-plugin'),
                    (int) $slot['filled'],
                    (int) $slot['needed']
                )) . '</span>';
                echo '</div>';
                if ($open > 0) {
                    echo '<button type="button" class="button pta-vol-signup" data-activity="' . esc_attr((string) $activity->id) . '">' . esc_html__('Sign up', 'azure-plugin') . '</button>';
                }
                echo '</li>';
            }
        }
        echo '</ul>';
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
</div>
<script>
(function () {
    var root = document.querySelector('.pta-volunteered');
    if (!root) return;
    var ajax = root.getAttribute('data-ajax');
    var nonce = root.getAttribute('data-nonce');
    function post(body) {
        return fetch(ajax, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(function (res) { return res.json(); });
    }
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
        var signup = event.target.closest('.pta-vol-signup');
        if (signup) {
            signup.disabled = true;
            post('action=azure_volunteer_signup&nonce=' + encodeURIComponent(nonce) + '&activity_ids[]=' + encodeURIComponent(signup.getAttribute('data-activity') || ''))
                .then(function (res) {
                    if (res && res.success) window.location.reload();
                    else {
                        signup.disabled = false;
                        window.alert((res && res.data && res.data.message) || '<?php echo esc_js(__('Could not sign up.', 'azure-plugin')); ?>');
                    }
                });
        }
    });
})();
</script>
