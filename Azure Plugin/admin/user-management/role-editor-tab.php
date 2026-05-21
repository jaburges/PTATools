<?php
/**
 * User Management → Role Editor tab
 *
 * Embeds the existing pta-role-editor-page.php cap matrix and adds a
 * Subscriber → Parent migration card on top. We deliberately re-use the
 * existing page rather than duplicating the editor — single source of
 * truth, no resource cost increase.
 */

if (!defined('ABSPATH')) {
    exit;
}

$role_nonce = wp_create_nonce(Azure_Parent_Role_Admin::NONCE_ACTION);
?>

<div class="card" style="max-width:820px;">
    <h2 style="margin-top:0;"><?php _e('Migrate Subscribers → Parents', 'azure-plugin'); ?></h2>
    <p class="description">
        <?php _e('Converts every user with the Subscriber role to the Parent role so they all live under one role with the same capabilities. Other roles (e.g. Customer) are preserved. Login state is unchanged.', 'azure-plugin'); ?>
    </p>

    <p>
        <strong><?php _e('Subscribers:', 'azure-plugin'); ?></strong>
        <span id="azure-pr-sub-count">…</span>
        &nbsp; · &nbsp;
        <strong><?php _e('Parents:', 'azure-plugin'); ?></strong>
        <span id="azure-pr-parent-count">…</span>
        &nbsp; · &nbsp;
        <strong><?php _e('Both roles:', 'azure-plugin'); ?></strong>
        <span id="azure-pr-both-count">…</span>
    </p>

    <p>
        <button type="button" class="button button-primary" id="azure-pr-migrate">
            <?php _e('Convert all Subscribers to Parents', 'azure-plugin'); ?>
        </button>
        <span id="azure-pr-status" style="margin-left:12px;color:#666;"></span>
    </p>
</div>

<hr style="margin:30px 0;">

<h2 style="margin-top:0;"><?php _e('Role capability editor', 'azure-plugin'); ?></h2>
<p class="description" style="max-width:820px;">
    <?php _e('Pick any WordPress role and toggle its capabilities. Changes are saved to the wp_user_roles option and apply to every user in that role immediately.', 'azure-plugin'); ?>
</p>

<?php
// Reuse the existing visual cap editor.
include AZURE_PLUGIN_PATH . 'admin/pta-role-editor-page.php';
?>

<script>
jQuery(function($){
    var ajaxUrl   = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
    var roleNonce = '<?php echo esc_js($role_nonce); ?>';

    function refreshRoleCounts() {
        $.post(ajaxUrl, { action: 'azure_pr_preview_subscribers', nonce: roleNonce }, function(res) {
            if (!res || !res.success) return;
            $('#azure-pr-sub-count').text(res.data.subscribers);
            $('#azure-pr-parent-count').text(res.data.parents);
            $('#azure-pr-both-count').text(res.data.subscriber_and_parent);
        });
    }
    refreshRoleCounts();

    $('#azure-pr-migrate').on('click', function() {
        var $btn = $(this);
        var $status = $('#azure-pr-status');

        if (!confirm('<?php echo esc_js(__('Convert every Subscriber to a Parent? Other roles (e.g. Customer) are kept. This is reversible from the user editor on a per-user basis.', 'azure-plugin')); ?>')) return;

        var totalConverted = 0;

        function runBatch() {
            $btn.prop('disabled', true);
            $status.text('<?php echo esc_js(__('Migrating…', 'azure-plugin')); ?>');
            $.post(ajaxUrl, { action: 'azure_pr_migrate_subscribers', nonce: roleNonce }, function(res) {
                if (!res || !res.success) {
                    $status.text((res && res.data) || 'Error');
                    $btn.prop('disabled', false);
                    return;
                }
                totalConverted += res.data.converted;
                $status.text('<?php echo esc_js(__('Converted', 'azure-plugin')); ?> ' + totalConverted + ' <?php echo esc_js(__('so far', 'azure-plugin')); ?> · ' + res.data.remaining + ' <?php echo esc_js(__('remaining', 'azure-plugin')); ?>');
                refreshRoleCounts();
                if (res.data.done || res.data.converted === 0) {
                    $status.append(' · <?php echo esc_js(__('Done.', 'azure-plugin')); ?>');
                    $btn.prop('disabled', false);
                } else {
                    runBatch();
                }
            }).fail(function() {
                $status.text('<?php echo esc_js(__('Network error', 'azure-plugin')); ?>');
                $btn.prop('disabled', false);
            });
        }

        runBatch();
    });
});
</script>
