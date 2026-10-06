<?php
/**
 * Newsletter site-wide opt-outs
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Azure_Newsletter_Lists')) {
    echo '<div class="notice notice-error"><p>' . esc_html__('Newsletter lists are not loaded.', 'azure-plugin') . '</p></div>';
    return;
}

$unsub_lists = new Azure_Newsletter_Lists();
$base_url = admin_url('admin.php?page=azure-plugin-newsletter&tab=unsubscribed');

if (isset($_POST['pta_unsub_add']) && check_admin_referer('pta_unsub_add')) {
    $email = sanitize_email(wp_unslash($_POST['unsub_email'] ?? ''));
    if ($email && $unsub_lists->unsubscribe_email($email)) {
        echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
            /* translators: %s: email address */
            esc_html__('%s is unsubscribed from all newsletters.', 'azure-plugin'),
            '<strong>' . esc_html($email) . '</strong>'
        ) . '</p></div>';
    } else {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Enter a valid email address.', 'azure-plugin') . '</p></div>';
    }
}

if (isset($_POST['pta_unsub_resubscribe']) && check_admin_referer('pta_unsub_resubscribe')) {
    $email = sanitize_email(wp_unslash($_POST['unsub_email'] ?? ''));
    if ($email && $unsub_lists->resubscribe_email($email)) {
        echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
            /* translators: %s: email address */
            esc_html__('%s will receive newsletters again.', 'azure-plugin'),
            '<strong>' . esc_html($email) . '</strong>'
        ) . '</p></div>';
    }
}

$search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
$per_page = 50;
$paged = max(1, intval($_GET['paged'] ?? 1));
$result = $unsub_lists->get_opt_outs($search, $per_page, ($paged - 1) * $per_page);
$pages = max(1, (int) ceil($result['total'] / $per_page));
$date_format = get_option('date_format') . ' ' . get_option('time_format');
?>

<div class="newsletter-unsubscribed-page">
    <div class="unsub-header">
        <div>
            <h3><?php printf(esc_html__('Unsubscribed (%s)', 'azure-plugin'), number_format_i18n($result['total'])); ?></h3>
            <p class="description">
                <?php esc_html_e('These addresses are skipped for every list, including role-based lists. They come from the unsubscribe link in a newsletter, the mail app\'s Unsubscribe button, or being added here.', 'azure-plugin'); ?>
            </p>
        </div>
        <form method="post" class="unsub-add">
            <?php wp_nonce_field('pta_unsub_add'); ?>
            <label class="screen-reader-text" for="unsub-add-email"><?php esc_html_e('Email address', 'azure-plugin'); ?></label>
            <input type="email" id="unsub-add-email" name="unsub_email" class="regular-text" required
                   placeholder="<?php esc_attr_e('name@example.com', 'azure-plugin'); ?>">
            <button type="submit" name="pta_unsub_add" class="button"><?php esc_html_e('Unsubscribe address', 'azure-plugin'); ?></button>
        </form>
    </div>

    <form method="get" class="unsub-search">
        <input type="hidden" name="page" value="azure-plugin-newsletter">
        <input type="hidden" name="tab" value="unsubscribed">
        <input type="search" name="s" value="<?php echo esc_attr($search); ?>"
               placeholder="<?php esc_attr_e('Search email or name', 'azure-plugin'); ?>">
        <button type="submit" class="button"><?php esc_html_e('Search', 'azure-plugin'); ?></button>
        <?php if ($search !== ''): ?>
            <a href="<?php echo esc_url($base_url); ?>"><?php esc_html_e('Clear', 'azure-plugin'); ?></a>
        <?php endif; ?>
    </form>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Email', 'azure-plugin'); ?></th>
                <th><?php esc_html_e('User', 'azure-plugin'); ?></th>
                <th><?php esc_html_e('Unsubscribed', 'azure-plugin'); ?></th>
                <th><?php esc_html_e('From campaign', 'azure-plugin'); ?></th>
                <th class="column-actions"></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($result['rows'])): ?>
                <tr><td colspan="5"><?php echo $search !== ''
                    ? esc_html__('No unsubscribed addresses match.', 'azure-plugin')
                    : esc_html__('Nobody has unsubscribed.', 'azure-plugin'); ?></td></tr>
            <?php else: foreach ($result['rows'] as $row): ?>
                <tr>
                    <td><?php echo esc_html($row->email); ?></td>
                    <td>
                        <?php if (!empty($row->user_id) && !empty($row->display_name)): ?>
                            <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . (int) $row->user_id)); ?>"><?php echo esc_html($row->display_name); ?></a>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html(mysql2date($date_format, $row->unsubscribed_at)); ?></td>
                    <td><?php echo $row->campaign ? esc_html($row->campaign) : '&mdash;'; ?></td>
                    <td class="column-actions">
                        <form method="post" onsubmit="return confirm('<?php echo esc_js(sprintf(
                            /* translators: %s: email address */
                            __('Only resubscribe %s if they asked to receive newsletters again. Continue?', 'azure-plugin'),
                            $row->email
                        )); ?>');">
                            <?php wp_nonce_field('pta_unsub_resubscribe'); ?>
                            <input type="hidden" name="unsub_email" value="<?php echo esc_attr($row->email); ?>">
                            <button type="submit" name="pta_unsub_resubscribe" class="button-link"><?php esc_html_e('Resubscribe', 'azure-plugin'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
        <div class="tablenav"><div class="tablenav-pages">
            <?php echo paginate_links(array(
                'base' => add_query_arg('paged', '%#%', $search !== '' ? add_query_arg('s', rawurlencode($search), $base_url) : $base_url),
                'format' => '',
                'current' => $paged,
                'total' => $pages,
            )); ?>
        </div></div>
    <?php endif; ?>
</div>

<style>
.newsletter-unsubscribed-page .unsub-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    flex-wrap: wrap;
}
.newsletter-unsubscribed-page .unsub-header h3 {
    margin-top: 0;
}
.newsletter-unsubscribed-page .unsub-header .description {
    max-width: 640px;
}
.newsletter-unsubscribed-page .unsub-add {
    display: flex;
    gap: 8px;
}
.newsletter-unsubscribed-page .unsub-search {
    margin: 15px 0 10px;
    display: flex;
    gap: 8px;
    align-items: center;
}
.newsletter-unsubscribed-page .column-actions {
    width: 110px;
    text-align: right;
}
</style>
