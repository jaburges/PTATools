<?php
/**
 * Selling → Parent + Children Import (v3.67)
 *
 * Two cards:
 *   1. Import — upload or paste the canonical CSV, run Scan (dry-run) to
 *      see classifications, then Apply to commit.
 *   2. Welcome emails — counts pending parents and sends temp-password
 *      emails in batches of 25.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    return;
}

require_once AZURE_PLUGIN_PATH . 'includes/class-parent-children-importer.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-parent-welcome-mailer.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-parent-role-admin.php';
Azure_Parent_Children_Importer::get_instance();
$mailer = Azure_Parent_Welcome_Mailer::get_instance();
Azure_Parent_Role_Admin::get_instance();

$import_nonce   = wp_create_nonce(Azure_Parent_Children_Importer::NONCE_ACTION);
$welcome_nonce  = wp_create_nonce(Azure_Parent_Welcome_Mailer::NONCE_ACTION);
$role_nonce     = wp_create_nonce(Azure_Parent_Role_Admin::NONCE_ACTION);
?>
<div class="wrap azure-pci-page">
    <h1><span class="dashicons dashicons-groups"></span> <?php _e('Parent + Children import', 'azure-plugin'); ?></h1>
    <p class="description" style="max-width:820px;">
        <?php _e('Bulk-create Parent users, link them into connected families, and populate child profiles from the canonical "Kids and parents export" CSV. Dry-run is safe — no writes happen until you click Apply.', 'azure-plugin'); ?>
    </p>

    <div class="card" style="max-width:820px;margin-top:18px;">
        <h2 style="margin-top:0;"><?php _e('1. Import CSV', 'azure-plugin'); ?></h2>
        <p>
            <?php _e('Required headers:', 'azure-plugin'); ?>
            <code>Parent 1 Email</code>, <code>Parent 1 Full Name</code>, <code>Child / Student Name</code>.
            <?php _e('Optional: Parent 2 fields, Grade, Teacher, Allergies, Photos OK, Other notes, Self Carry Epi Pen, Emergency Contact (Name, Email, Number).', 'azure-plugin'); ?>
        </p>

        <p>
            <input type="file" id="azure-pci-file" accept=".csv,text/csv" />
        </p>
        <details>
            <summary style="cursor:pointer;color:#2271b1;"><?php _e('…or paste rows', 'azure-plugin'); ?></summary>
            <p>
                <textarea id="azure-pci-text" rows="6" style="width:100%;font-family:monospace;" placeholder="Parent 1 Email,Parent 1 Cell Number,..."></textarea>
            </p>
        </details>

        <p>
            <button type="button" class="button button-secondary" id="azure-pci-scan"><?php _e('Scan (dry-run)', 'azure-plugin'); ?></button>
            <button type="button" class="button button-primary" id="azure-pci-apply" disabled><?php _e('Apply changes', 'azure-plugin'); ?></button>
            <span id="azure-pci-status" style="margin-left:12px;color:#666;"></span>
        </p>

        <div id="azure-pci-totals" style="display:none;margin-top:16px;background:#f6f7f7;padding:12px;border-radius:4px;"></div>

        <table class="wp-list-table widefat striped" id="azure-pci-rows" style="display:none;margin-top:16px;">
            <thead>
                <tr>
                    <th style="width:5%;"><?php _e('Line', 'azure-plugin'); ?></th>
                    <th style="width:18%;"><?php _e('Child', 'azure-plugin'); ?></th>
                    <th style="width:24%;"><?php _e('Parent 1', 'azure-plugin'); ?></th>
                    <th style="width:24%;"><?php _e('Parent 2', 'azure-plugin'); ?></th>
                    <th style="width:14%;"><?php _e('Family', 'azure-plugin'); ?></th>
                    <th style="width:15%;"><?php _e('Child decision', 'azure-plugin'); ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>

        <pre id="azure-pci-detail" style="display:none;background:#f7f7f7;padding:12px;border-radius:4px;max-height:300px;overflow:auto;margin-top:14px;"></pre>
    </div>

    <div class="card" style="max-width:820px;margin-top:18px;">
        <h2 style="margin-top:0;"><?php _e('2. Send welcome emails', 'azure-plugin'); ?></h2>
        <p class="description">
            <?php _e('Generates a fresh temporary password for each pending parent, emails it, and unlocks login. Recipients are required to set a new password on first sign-in.', 'azure-plugin'); ?>
        </p>

        <p>
            <strong><?php _e('Pending:', 'azure-plugin'); ?></strong>
            <span id="azure-pci-welcome-pending">…</span>
            &nbsp; · &nbsp;
            <strong><?php _e('Already sent:', 'azure-plugin'); ?></strong>
            <span id="azure-pci-welcome-sent">…</span>
        </p>

        <p>
            <button type="button" class="button button-primary" id="azure-pci-welcome-send">
                <?php _e('Send to all pending', 'azure-plugin'); ?>
            </button>
            <span id="azure-pci-welcome-status" style="margin-left:12px;color:#666;"></span>
        </p>

        <p style="margin-top:18px;">
            <label for="azure-pci-welcome-retry"><?php _e('Resend to a specific user (ID or email):', 'azure-plugin'); ?></label><br>
            <input type="text" id="azure-pci-welcome-retry" style="width:280px;" />
            <button type="button" class="button" id="azure-pci-welcome-retry-btn"><?php _e('Resend', 'azure-plugin'); ?></button>
            <span id="azure-pci-welcome-retry-status" style="margin-left:8px;color:#666;"></span>
        </p>
    </div>

    <div class="card" style="max-width:820px;margin-top:18px;">
        <h2 style="margin-top:0;"><?php _e('3. Migrate Subscribers → Parents', 'azure-plugin'); ?></h2>
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
    var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
    var importNonce  = '<?php echo esc_js($import_nonce); ?>';
    var welcomeNonce = '<?php echo esc_js($welcome_nonce); ?>';
    var roleNonce    = '<?php echo esc_js($role_nonce); ?>';
    var lastReport = null;

    function buildFormData(action, nonce) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', nonce);
        var fileInput = document.getElementById('azure-pci-file');
        if (fileInput && fileInput.files.length) {
            fd.append('csv', fileInput.files[0]);
        } else {
            var text = $('#azure-pci-text').val() || '';
            fd.append('csv_text', text);
        }
        return fd;
    }

    function renderTotals(totals) {
        var html = '';
        $.each(totals, function(k, v) {
            html += '<span style="display:inline-block;margin-right:18px;"><strong>' + k.replace(/_/g, ' ') + ':</strong> ' + v + '</span>';
        });
        $('#azure-pci-totals').html(html).show();
    }

    function renderRows(rows) {
        var $tbody = $('#azure-pci-rows tbody').empty();
        if (!rows || !rows.length) {
            $('#azure-pci-rows').hide();
            return;
        }
        rows.forEach(function(r) {
            var err = r.error ? ' <span style="color:#d63638;">[' + r.error + ']</span>' : '';
            var p1 = r.parent_1 ? (r.parent_1.email + ' — ' + r.parent_1.decision) : '';
            var p2 = r.parent_2 ? (r.parent_2.email ? (r.parent_2.email + ' — ' + r.parent_2.decision) : '—') : '—';
            var fam = r.family ? r.family.decision : '';
            var child = r.child ? r.child.decision : '';
            var $tr = $('<tr></tr>');
            $tr.append($('<td></td>').text(r.line));
            $tr.append($('<td></td>').html($('<span></span>').text(r.child_name).html() + err));
            $tr.append($('<td></td>').text(p1));
            $tr.append($('<td></td>').text(p2));
            $tr.append($('<td></td>').text(fam));
            $tr.append($('<td></td>').text(child));
            $tbody.append($tr);
        });
        $('#azure-pci-rows').show();
    }

    $('#azure-pci-scan').on('click', function() {
        var $btn = $(this).prop('disabled', true);
        $('#azure-pci-status').text('Scanning…');
        $('#azure-pci-detail').hide().text('');

        $.ajax({
            url: ajaxUrl, method: 'POST',
            data: buildFormData('azure_pci_scan', importNonce),
            processData: false, contentType: false
        }).done(function(res) {
            if (!res || !res.success) {
                $('#azure-pci-status').text('').end();
                alert((res && res.data) || 'Scan failed');
                return;
            }
            lastReport = res.data;
            renderTotals(res.data.totals);
            renderRows(res.data.rows);
            $('#azure-pci-apply').prop('disabled', false);
            $('#azure-pci-status').text('Scan complete — review above, then click Apply.');
        }).fail(function() {
            $('#azure-pci-status').text('Scan failed.');
        }).always(function() {
            $btn.prop('disabled', false);
        });
    });

    $('#azure-pci-apply').on('click', function() {
        if (!confirm('<?php echo esc_js(__('Commit the import? This will create users, families, and child rows. Existing data will not be overwritten — only blanks are filled.', 'azure-plugin')); ?>')) return;
        var $btn = $(this).prop('disabled', true);
        $('#azure-pci-status').text('Applying…');

        $.ajax({
            url: ajaxUrl, method: 'POST',
            data: buildFormData('azure_pci_apply', importNonce),
            processData: false, contentType: false
        }).done(function(res) {
            if (!res || !res.success) {
                alert((res && res.data) || 'Apply failed');
                return;
            }
            $('#azure-pci-status').text('Apply complete.');
            $('#azure-pci-detail').text(JSON.stringify(res.data, null, 2)).show();
            refreshWelcomeCounts();
        }).fail(function() {
            $('#azure-pci-status').text('Apply failed.');
        }).always(function() {
            $btn.prop('disabled', false);
        });
    });

    function refreshWelcomeCounts() {
        $.post(ajaxUrl, { action: 'azure_pci_welcome_preview', nonce: welcomeNonce }, function(res) {
            if (res && res.success) {
                $('#azure-pci-welcome-pending').text(res.data.pending);
                $('#azure-pci-welcome-sent').text(res.data.sent);
            }
        });
    }
    refreshWelcomeCounts();

    $('#azure-pci-welcome-send').on('click', function() {
        var $btn = $(this);
        var $status = $('#azure-pci-welcome-status').text('Starting…');
        var totalSent = 0, totalFailed = 0;

        function runBatch() {
            $btn.prop('disabled', true);
            $.post(ajaxUrl, { action: 'azure_pci_welcome_send', nonce: welcomeNonce }, function(res) {
                if (!res || !res.success) {
                    $status.text('Stopped: ' + ((res && res.data) || 'error'));
                    $btn.prop('disabled', false);
                    return;
                }
                totalSent += res.data.sent;
                totalFailed += res.data.failed;
                $status.text('Sent: ' + totalSent + ' · Failed: ' + totalFailed + ' · Remaining: ' + res.data.remaining);
                refreshWelcomeCounts();
                if (!res.data.completed && res.data.remaining > 0) {
                    setTimeout(runBatch, 400);
                } else {
                    $status.append(' · Done.');
                    $btn.prop('disabled', false);
                }
            }).fail(function() {
                $status.text('Network error');
                $btn.prop('disabled', false);
            });
        }

        if (!confirm('<?php echo esc_js(__('Send welcome emails to every pending parent? This will email out their temp passwords.', 'azure-plugin')); ?>')) return;
        runBatch();
    });

    $('#azure-pci-welcome-retry-btn').on('click', function() {
        var raw = $.trim($('#azure-pci-welcome-retry').val());
        if (!raw) return;
        var payload = { action: 'azure_pci_welcome_send', nonce: welcomeNonce };
        if (/^\d+$/.test(raw)) {
            payload.user_id = parseInt(raw, 10);
        } else {
            // Email — fetch the user via WP_User_Query is admin-only; just
            // pass user_id=0 and let the operator enter the numeric ID for now.
            $('#azure-pci-welcome-retry-status').text('Enter a numeric user ID.');
            return;
        }
        $('#azure-pci-welcome-retry-status').text('Sending…');
        $.post(ajaxUrl, payload, function(res) {
            if (!res || !res.success) {
                $('#azure-pci-welcome-retry-status').text((res && res.data) || 'Error');
                return;
            }
            $('#azure-pci-welcome-retry-status').text(res.data.sent ? 'Sent' : 'Failed');
            refreshWelcomeCounts();
        }).fail(function() {
            $('#azure-pci-welcome-retry-status').text('Network error');
        });
    });

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
