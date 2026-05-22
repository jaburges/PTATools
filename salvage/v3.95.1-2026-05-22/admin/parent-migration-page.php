<?php
/**
 * Admin page: PTA Tools → User Management → Parent Migration
 *
 * Three workflows on one page:
 *   1. Test me first — provision a single user (default: Jamie) + send the
 *      welcome email so the admin can verify the magic link end-to-end.
 *   2. AcyMailing import — preview the bucket counts, then run in batches.
 *   3. Welcome blast — send the activation email to every parent who still
 *      has the login-disabled flag, in batches.
 *
 * All AJAX endpoints are defined in Azure_Parent_Migration; this page is
 * mostly markup + a small jQuery driver.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    wp_die(esc_html__('Forbidden', 'azure-plugin'));
}

if (!class_exists('Azure_Parent_Migration')) {
    require_once AZURE_PLUGIN_PATH . 'includes/class-parent-migration.php';
}

$nonce          = wp_create_nonce(Azure_Parent_Migration::NONCE_ACTION);
$ajax_url       = admin_url('admin-ajax.php');
$acy_present    = Azure_Parent_Migration::acymailing_present();
$sso_role       = Azure_Parent_Migration::get_sso_role_slug();
$sso_domain     = Azure_Parent_Migration::get_sso_org_domain();
$school_domain  = Azure_Parent_Migration::get_school_staff_domain();
$pending_count  = Azure_Parent_Migration::count_pending_parents();

// Test-user inputs are blank by default. The admin enters their own
// "send-the-welcome-email-to-me-first" address before the bulk run.
$test_email     = '';
$test_name      = '';
$test_phone     = '';
$test_child     = '';
$test_grade     = '';
$test_teacher   = '';
?>
<div class="wrap" id="pta-parent-migration">
    <h1><?php esc_html_e('Parent Migration & Welcome', 'azure-plugin'); ?></h1>
    <p style="max-width:780px;color:#3c434a;">
        <?php esc_html_e('Centralizes the AcyMailing import, single-user testing, and welcome-email rollout for the new Parent role. Always test with a single user first; the bulk send is intentionally separated.', 'azure-plugin'); ?>
    </p>

    <div class="notice notice-info" style="padding:10px;">
        <strong><?php esc_html_e('Site configuration', 'azure-plugin'); ?>:</strong>
        <?php
        printf(
            esc_html__('PTSA SSO role = %1$s · SSO domain = %2$s · School staff domain = %3$s · AcyMailing detected = %4$s · Parents pending activation = %5$d', 'azure-plugin'),
            '<code>' . esc_html($sso_role) . '</code>',
            $sso_domain ? '<code>' . esc_html($sso_domain) . '</code>' : '<em>(not set)</em>',
            '<code>' . esc_html($school_domain) . '</code>',
            $acy_present ? '<strong style="color:#00a32a;">yes</strong>' : '<strong style="color:#d63638;">no</strong>',
            (int) $pending_count
        );
        ?>
    </div>

    <h2 class="title" style="margin-top:24px;"><?php esc_html_e('Step 1 — Test me first (single user)', 'azure-plugin'); ?></h2>
    <p style="max-width:780px;color:#646970;">
        <?php esc_html_e('Provision one user (no role assignment via SSO, no bulk effects), then send the welcome email so you can verify the activation link end-to-end before triggering the rollout.', 'azure-plugin'); ?>
    </p>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="pta-pm-email"><?php esc_html_e('Email', 'azure-plugin'); ?></label></th>
            <td><input type="email" id="pta-pm-email" class="regular-text" value="<?php echo esc_attr($test_email); ?>" /></td>
        </tr>
        <tr>
            <th><label for="pta-pm-name"><?php esc_html_e('Display name', 'azure-plugin'); ?></label></th>
            <td><input type="text" id="pta-pm-name" class="regular-text" value="<?php echo esc_attr($test_name); ?>" /></td>
        </tr>
        <tr>
            <th><label for="pta-pm-phone"><?php esc_html_e('Phone', 'azure-plugin'); ?></label></th>
            <td><input type="text" id="pta-pm-phone" class="regular-text" value="<?php echo esc_attr($test_phone); ?>" /></td>
        </tr>
        <tr>
            <th><label for="pta-pm-child"><?php esc_html_e('Child name', 'azure-plugin'); ?></label></th>
            <td>
                <input type="text" id="pta-pm-child" class="regular-text" value="<?php echo esc_attr($test_child); ?>" />
                <input type="text" id="pta-pm-grade" class="small-text" value="<?php echo esc_attr($test_grade); ?>" placeholder="<?php esc_attr_e('Grade', 'azure-plugin'); ?>" />
                <input type="text" id="pta-pm-teacher" class="regular-text" value="<?php echo esc_attr($test_teacher); ?>" placeholder="<?php esc_attr_e('Teacher', 'azure-plugin'); ?>" />
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e('Force re-issue', 'azure-plugin'); ?></th>
            <td><label><input type="checkbox" id="pta-pm-force" /> <?php esc_html_e('If the user already exists, attach the parent role and re-issue the activation token.', 'azure-plugin'); ?></label></td>
        </tr>
    </table>
    <p>
        <button type="button" class="button button-primary" id="pta-pm-create-test"><?php esc_html_e('1a. Provision test user', 'azure-plugin'); ?></button>
        <button type="button" class="button" id="pta-pm-preview-welcome" disabled><?php esc_html_e('1b. Preview welcome email', 'azure-plugin'); ?></button>
        <button type="button" class="button button-secondary" id="pta-pm-send-test" disabled><?php esc_html_e('1c. Send welcome to test user', 'azure-plugin'); ?></button>
        <select id="pta-pm-test-transport" style="margin-left:8px;">
            <option value="wp_mail"><?php esc_html_e('Transport: wp_mail (Azure ACS / default)', 'azure-plugin'); ?></option>
            <option value="mailgun" selected><?php esc_html_e('Transport: Mailgun (Newsletter sender)', 'azure-plugin'); ?></option>
        </select>
    </p>
    <div id="pta-pm-test-result" class="pta-pm-result"></div>

    <hr>

    <h2 class="title"><?php esc_html_e('Step 2 — AcyMailing import', 'azure-plugin'); ?></h2>
    <?php if (!$acy_present): ?>
        <div class="notice notice-warning"><p><?php esc_html_e('AcyMailing tables (wp_acym_user) were not detected on this site. Skip to Step 3 if all imports are already done.', 'azure-plugin'); ?></p></div>
    <?php endif; ?>
    <p style="max-width:780px;color:#646970;">
        <?php esc_html_e('Reads every wp_acym_user row, dedupes by email, and routes each one through the bucketing rules. Existing WP users keep their existing role (no replacement). Run the preview first.', 'azure-plugin'); ?>
    </p>
    <p>
        <button type="button" class="button button-primary" id="pta-pm-acy-preview" <?php disabled(!$acy_present); ?>><?php esc_html_e('Preview buckets', 'azure-plugin'); ?></button>
        <button type="button" class="button" id="pta-pm-acy-run" disabled><?php esc_html_e('Run import (batches of 100)', 'azure-plugin'); ?></button>
        <span style="margin-left:12px;color:#646970;font-style:italic;">
            <?php
            printf(
                /* translators: %1$s = configured school-staff domain; %2$s = configured SSO org_domain */
                esc_html__('%1$s and %2$s emails are skipped — staff are imported manually to the school_staff role; PTSA volunteers are created by Microsoft SSO on first sign-in.', 'azure-plugin'),
                $school_domain ? '<code>' . esc_html($school_domain) . '</code>' : esc_html__('the configured school-staff domain', 'azure-plugin'),
                $sso_domain ? '<code>' . esc_html($sso_domain) . '</code>' : esc_html__('the configured SSO org domain', 'azure-plugin')
            );
            ?>
        </span>
    </p>
    <div id="pta-pm-acy-result" class="pta-pm-result"></div>

    <hr>

    <h2 class="title"><?php esc_html_e('Step 3 — Welcome blast (parents pending activation)', 'azure-plugin'); ?></h2>
    <p style="max-width:780px;color:#646970;">
        <?php esc_html_e('Send the magic-link welcome email to every parent who still has login disabled (i.e., has not activated yet). Mailgun is recommended for branding; wp_mail uses whatever transport is currently configured.', 'azure-plugin'); ?>
    </p>
    <p>
        <strong><?php esc_html_e('Pending recipients:', 'azure-plugin'); ?></strong>
        <span id="pta-pm-pending-count"><?php echo (int) $pending_count; ?></span>
    </p>
    <p>
        <button type="button" class="button button-primary" id="pta-pm-bulk-send"><?php esc_html_e('Send next batch', 'azure-plugin'); ?></button>
        <button type="button" class="button button-secondary" id="pta-pm-bulk-loop"><?php esc_html_e('Run continuously until done', 'azure-plugin'); ?></button>
        <select id="pta-pm-bulk-transport" style="margin-left:8px;">
            <option value="mailgun" selected><?php esc_html_e('Mailgun (recommended)', 'azure-plugin'); ?></option>
            <option value="wp_mail"><?php esc_html_e('wp_mail (default)', 'azure-plugin'); ?></option>
        </select>
        <select id="pta-pm-bulk-size" style="margin-left:8px;">
            <option value="10">10/batch</option>
            <option value="25">25/batch</option>
            <option value="50" selected>50/batch</option>
            <option value="100">100/batch</option>
        </select>
    </p>
    <div id="pta-pm-bulk-result" class="pta-pm-result"></div>

    <style>
        .pta-pm-result { margin-top:12px; padding:10px; background:#f9f9f9; border:1px solid #e0e0e0; border-radius:4px; min-height:24px; font-family:monospace; font-size:12px; white-space:pre-wrap; max-height:360px; overflow:auto; }
        .pta-pm-result.error { background:#fef0f0; border-color:#d63638; }
        .pta-pm-result.ok    { background:#f0f8ec; border-color:#00a32a; }
        .pta-pm-bucket { display:inline-block; margin:4px 8px 4px 0; padding:4px 10px; background:#e7f3fe; border-radius:3px; font-family:monospace; }
    </style>

    <script>
    (function($){
        var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
        var nonce   = <?php echo wp_json_encode($nonce); ?>;
        var lastTestUserId = 0;

        function show($el, status, html) {
            $el.removeClass('ok error').addClass(status === 'error' ? 'error' : (status === 'ok' ? 'ok' : ''));
            $el.html(html);
        }
        function fmt(obj) { return JSON.stringify(obj, null, 2); }

        // ─── Step 1: test user ───────────────────────────────
        $('#pta-pm-create-test').on('click', function(){
            var $btn = $(this), $out = $('#pta-pm-test-result');
            $btn.prop('disabled', true);
            show($out, '', 'Provisioning…');
            $.post(ajaxUrl, {
                action: 'azure_pm_test_user',
                nonce: nonce,
                email: $('#pta-pm-email').val(),
                name:  $('#pta-pm-name').val(),
                phone: $('#pta-pm-phone').val(),
                child_name:    $('#pta-pm-child').val(),
                child_grade:   $('#pta-pm-grade').val(),
                child_teacher: $('#pta-pm-teacher').val(),
                force: $('#pta-pm-force').is(':checked') ? 1 : 0
            }).done(function(res){
                if (res && res.success) {
                    lastTestUserId = res.data.user_id;
                    show($out, 'ok', 'OK ' + fmt(res.data));
                    $('#pta-pm-preview-welcome, #pta-pm-send-test').prop('disabled', false);
                } else {
                    show($out, 'error', 'ERROR ' + fmt(res && res.data ? res.data : res));
                }
            }).fail(function(xhr){
                show($out, 'error', 'HTTP ' + xhr.status + ' ' + xhr.responseText);
            }).always(function(){ $btn.prop('disabled', false); });
        });

        $('#pta-pm-preview-welcome').on('click', function(){
            var $out = $('#pta-pm-test-result');
            if (!lastTestUserId) { show($out, 'error', 'Provision the test user first.'); return; }
            $.post(ajaxUrl, { action: 'azure_pm_welcome_preview', nonce: nonce, user_id: lastTestUserId })
                .done(function(res){
                    if (res && res.success) {
                        var win = window.open('', 'pta_welcome_preview', 'width=720,height=820');
                        if (win) {
                            win.document.write(res.data.html);
                            win.document.title = res.data.subject;
                            win.document.close();
                        }
                        show($out, 'ok', 'Preview opened in new window.\nSubject: ' + res.data.subject + '\nActivation URL: ' + res.data.activation_url);
                    } else {
                        show($out, 'error', 'ERROR ' + fmt(res && res.data ? res.data : res));
                    }
                });
        });

        $('#pta-pm-send-test').on('click', function(){
            var $btn = $(this), $out = $('#pta-pm-test-result');
            if (!lastTestUserId) { show($out, 'error', 'Provision the test user first.'); return; }
            if (!confirm('Send the activation email to this user now?')) return;
            $btn.prop('disabled', true);
            show($out, '', 'Sending…');
            $.post(ajaxUrl, {
                action: 'azure_pm_send_welcome',
                nonce: nonce,
                user_id: lastTestUserId,
                transport: $('#pta-pm-test-transport').val()
            }).done(function(res){
                if (res && res.success) {
                    show($out, 'ok', 'Sent. ' + fmt(res.data));
                } else {
                    show($out, 'error', 'ERROR ' + fmt(res && res.data ? res.data : res));
                }
            }).always(function(){ $btn.prop('disabled', false); });
        });

        // ─── Step 2: AcyMailing ──────────────────────────────
        $('#pta-pm-acy-preview').on('click', function(){
            var $btn = $(this), $out = $('#pta-pm-acy-result');
            $btn.prop('disabled', true);
            show($out, '', 'Counting buckets…');
            $.post(ajaxUrl, { action: 'azure_pm_acy_preview', nonce: nonce })
                .done(function(res){
                    if (res && res.success) {
                        var d = res.data, html = '';
                        html += 'Total AcyMailing rows: ' + d.total + '\n\n';
                        html += 'SSO domain: ' + (d.sso_domain || '(not set)') + ' → role: ' + d.sso_role + '\n';
                        html += 'School staff domain: ' + d.school_domain + '\n\n';
                        html += 'Buckets:\n';
                        for (var k in d.buckets) html += '  ' + k + ': ' + d.buckets[k] + '\n';
                        html += '\nFirst 25:\n';
                        d.sample.forEach(function(r){
                            html += '  [' + r.action + '] ' + r.email + (r.name ? ' (' + r.name + ')' : '') + ' — ' + r.reason + '\n';
                        });
                        show($out, 'ok', html);
                        $('#pta-pm-acy-run').prop('disabled', false);
                    } else {
                        show($out, 'error', 'ERROR ' + fmt(res && res.data ? res.data : res));
                    }
                }).always(function(){ $btn.prop('disabled', false); });
        });

        $('#pta-pm-acy-run').on('click', function(){
            var $btn = $(this), $out = $('#pta-pm-acy-result');
            if (!confirm('Run the AcyMailing import now? This creates new WP users for non-existing parent emails. Existing users are not modified.')) return;
            $btn.prop('disabled', true);
            var offset = 0;
            var totals = { created_parent:0, attached_existing:0, skipped_school:0, skipped_sso:0, errors:0, invalid:0 };
            function step() {
                $.post(ajaxUrl, {
                    action: 'azure_pm_acy_run',
                    nonce: nonce,
                    offset: offset
                }).done(function(res){
                    if (!res || !res.success) {
                        show($out, 'error', 'ERROR ' + fmt(res && res.data ? res.data : res));
                        $btn.prop('disabled', false);
                        return;
                    }
                    var d = res.data;
                    for (var k in totals) totals[k] += (d.results[k] || 0);
                    var lines = ['Processed ' + (d.next_offset) + ' / ' + d.total + '...'];
                    for (var k in totals) lines.push('  ' + k + ': ' + totals[k]);
                    show($out, '', lines.join('\n'));
                    offset = d.next_offset;
                    if (d.done) {
                        lines.unshift('AcyMailing import complete.');
                        show($out, 'ok', lines.join('\n'));
                        $btn.prop('disabled', false);
                        // Refresh pending count.
                        $('#pta-pm-pending-count').text('(refresh page to see updated count)');
                    } else {
                        setTimeout(step, 250);
                    }
                }).fail(function(xhr){
                    show($out, 'error', 'HTTP ' + xhr.status + ' — stopping. ' + xhr.responseText);
                    $btn.prop('disabled', false);
                });
            }
            step();
        });

        // ─── Step 3: Welcome blast ───────────────────────────
        function bulkOnce(loop) {
            var $out = $('#pta-pm-bulk-result');
            $.post(ajaxUrl, {
                action: 'azure_pm_send_welcome',
                nonce: nonce,
                scope: 'disabled_parents',
                limit: $('#pta-pm-bulk-size').val(),
                transport: $('#pta-pm-bulk-transport').val()
            }).done(function(res){
                if (!res || !res.success) {
                    show($out, 'error', 'ERROR ' + fmt(res && res.data ? res.data : res));
                    return;
                }
                var d = res.data;
                var msg = 'Sent: ' + d.sent + '   Failed: ' + d.failed + '   Remaining: ' + d.remaining + '\n';
                d.details.forEach(function(r){
                    msg += '  [' + (r.ok ? 'ok ' : 'err') + '] uid=' + r.user_id + (r.email ? (' ' + r.email) : '') + (r.error ? (' — ' + r.error) : '') + '\n';
                });
                show($out, d.failed > 0 ? '' : 'ok', msg);
                $('#pta-pm-pending-count').text(d.remaining);
                if (loop && !d.done) {
                    setTimeout(function(){ bulkOnce(true); }, 500);
                }
            }).fail(function(xhr){
                show($out, 'error', 'HTTP ' + xhr.status + ' ' + xhr.responseText);
            });
        }
        $('#pta-pm-bulk-send').on('click', function(){ bulkOnce(false); });
        $('#pta-pm-bulk-loop').on('click', function(){
            if (!confirm('Send the welcome email to ALL pending parents in batches until done?')) return;
            bulkOnce(true);
        });
    })(jQuery);
    </script>
</div>
