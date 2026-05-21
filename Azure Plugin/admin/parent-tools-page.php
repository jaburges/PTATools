<?php
/**
 * Selling → Parent Tools
 *
 * Lightweight admin tools for the Parent role. Currently only contains the
 * one-shot Subscriber → Parent migration. Lives in the Selling area because
 * it pairs with the rest of the family / role surface (My Family, parent
 * profile fields, etc.).
 *
 * Replaces the v3.67 Parent Import + Welcome email cards which were
 * removed once the canonical CSV import had been applied successfully.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    return;
}

require_once AZURE_PLUGIN_PATH . 'includes/class-parent-role-admin.php';
Azure_Parent_Role_Admin::get_instance();

$role_nonce = wp_create_nonce(Azure_Parent_Role_Admin::NONCE_ACTION);
?>
<div class="wrap azure-parent-tools-page">
    <h1><span class="dashicons dashicons-groups"></span> <?php _e('Parent tools', 'azure-plugin'); ?></h1>
    <p class="description" style="max-width:820px;">
        <?php _e('Maintenance tools for the Parent user role. The Parent role inherits Subscriber capabilities on every plugin upgrade, so you can edit it like any other role from your role editor.', 'azure-plugin'); ?>
    </p>

    <div class="card" style="max-width:820px;margin-top:18px;">
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
</div>

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
            $status.text('Migrating…');
            $.post(ajaxUrl, { action: 'azure_pr_migrate_subscribers', nonce: roleNonce }, function(res) {
                if (!res || !res.success) {
                    $status.text((res && res.data) || 'Error');
                    $btn.prop('disabled', false);
                    return;
                }
                totalConverted += res.data.converted;
                $status.text('Converted ' + totalConverted + ' so far · ' + res.data.remaining + ' remaining');
                refreshRoleCounts();
                if (res.data.done || res.data.converted === 0) {
                    $status.append(' · Done.');
                    $btn.prop('disabled', false);
                } else {
                    runBatch();
                }
            }).fail(function() {
                $status.text('Network error');
                $btn.prop('disabled', false);
            });
        }

        runBatch();
    });
});
</script>
