<?php
/**
 * PTA Tools Email Sending tab
 *
 * Per-service routing table. Each row decides where wp_mail()
 * calls with a matching `From:` header should be dispatched
 * (Microsoft Graph, Azure Communication Services, AcyMailing
 * native, default WP transport, or drop). The engine that reads
 * this table lives in includes/class-email-router.php.
 *
 * @package AzurePlugin
 * @since   3.123
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    wp_die(__('You do not have sufficient permissions.', 'azure-plugin'));
}

// Make sure the router class is loaded so we can render the
// current routing table — class lives in `includes/`.
if (!class_exists('Azure_Email_Router')) {
    $router_path = AZURE_PLUGIN_PATH . 'includes/class-email-router.php';
    if (file_exists($router_path)) {
        require_once $router_path;
    }
}

$routing = class_exists('Azure_Email_Router') ? Azure_Email_Router::get_routing() : array('routes' => array());
$routes  = isset($routing['routes']) ? $routing['routes'] : array();
$saved_at = isset($routing['saved_at']) ? $routing['saved_at'] : null;

$provider_choices = array(
    'graph'  => 'Microsoft Graph (M365)',
    'acs'    => 'Azure Communication Services',
    'acy'    => 'AcyMailing (pass through)',
);

$current_user = wp_get_current_user();
$default_test_to = $current_user && $current_user->user_email ? $current_user->user_email : get_option('admin_email');
?>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
<div class="wrap azure-admin-wrap">
    <h1><span class="dashicons dashicons-randomize"></span> <?php esc_html_e('Email Sending', 'azure-plugin'); ?></h1>
<?php endif; ?>

<div class="azure-admin-content">

    <div class="azure-card">
        <h2><span class="dashicons dashicons-randomize"></span> <?php esc_html_e('Routing rules', 'azure-plugin'); ?></h2>
        <p class="description">
            Every <code>wp_mail()</code> call is matched against this table top-to-bottom; the first row whose <strong>From matcher</strong> matches wins.
            Matcher supports an exact address (<code>shop@wilderptsa.net</code>), a prefix-with-trailing-@ (<code>news@</code> matches any <code>news@*</code>),
            or <code>*</code> for the default catch-all (which always sits at the bottom and can't be deleted).
            The chosen <strong>provider</strong> then sends the email as the configured <strong>Send as</strong> address.
        </p>
        <p class="description" style="margin-top:6px;">
            <strong>Sticky decisions:</strong>
            <code>graph</code>/<code>acs</code> short-circuit the dispatch and short-circuit AcyMailing/ACS-plugin pre_wp_mail hooks too.
            <code>acy</code> is a pass-through (AcyMailing keeps owning that send) and is the right choice for the newsletter row.
            Disabled rows still match but act as a pass-through.
        </p>

        <table class="wp-list-table widefat fixed striped" id="azure-routing-table">
            <thead>
                <tr>
                    <th style="width:22px;"></th>
                    <th style="width:60px;"><?php esc_html_e('On', 'azure-plugin'); ?></th>
                    <th><?php esc_html_e('Service / Label', 'azure-plugin'); ?></th>
                    <th><?php esc_html_e('From: matcher', 'azure-plugin'); ?></th>
                    <th><?php esc_html_e('Send as', 'azure-plugin'); ?></th>
                    <th><?php esc_html_e('Reply-To', 'azure-plugin'); ?></th>
                    <th style="width:170px;"><?php esc_html_e('Provider', 'azure-plugin'); ?></th>
                    <th style="width:130px;"><?php esc_html_e('Auth', 'azure-plugin'); ?></th>
                    <th style="width:200px;"><?php esc_html_e('Actions', 'azure-plugin'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($routes as $r): $is_default = !empty($r['is_default']); ?>
                <tr data-route-id="<?php echo esc_attr($r['id']); ?>" data-is-default="<?php echo $is_default ? '1' : '0'; ?>">
                    <td class="drag-handle" style="cursor:<?php echo $is_default ? 'not-allowed' : 'grab'; ?>; color:#a7aaad; text-align:center;" title="<?php echo $is_default ? esc_attr__('Default row stays at the bottom', 'azure-plugin') : esc_attr__('Drag to reorder', 'azure-plugin'); ?>">
                        <span class="dashicons dashicons-menu"></span>
                    </td>
                    <td><input type="checkbox" class="route-enabled" <?php checked(!empty($r['enabled'])); ?>></td>
                    <td>
                        <input type="text" class="route-label regular-text" value="<?php echo esc_attr($r['label']); ?>" placeholder="e.g. WooCommerce shop">
                        <div style="font-size:11px;color:#646970;margin-top:2px;">id: <code><?php echo esc_html($r['id']); ?></code><?php if ($is_default): ?> &middot; <em>default / catch-all</em><?php endif; ?></div>
                    </td>
                    <td>
                        <input type="text" class="route-from-match regular-text" value="<?php echo esc_attr($r['from_match']); ?>" <?php disabled($is_default); ?> placeholder="<?php esc_attr_e('shop@x.com, news@, *', 'azure-plugin'); ?>">
                    </td>
                    <td><input type="email" class="route-from-address regular-text" value="<?php echo esc_attr($r['from_address']); ?>"></td>
                    <td><input type="email" class="route-reply-to regular-text" value="<?php echo esc_attr($r['reply_to'] ?? ''); ?>" placeholder="<?php esc_attr_e('(optional)', 'azure-plugin'); ?>"></td>
                    <td>
                        <select class="route-provider">
                            <?php foreach ($provider_choices as $val => $lab): ?>
                                <option value="<?php echo esc_attr($val); ?>" <?php selected(($r['provider'] ?? 'graph'), $val); ?>><?php echo esc_html($lab); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><span class="route-auth-badge" data-state="unknown" style="display:inline-block;padding:2px 8px;border-radius:3px;background:#f0f0f1;color:#646970;font-size:12px;">unknown</span></td>
                    <td>
                        <button type="button" class="button button-small route-check-auth" title="<?php esc_attr_e('Verify provider can send as this address', 'azure-plugin'); ?>">Check auth</button>
                        <button type="button" class="button button-small route-test" title="<?php esc_attr_e('Send a real test email through this route', 'azure-plugin'); ?>">Test</button>
                        <?php if (!$is_default): ?><button type="button" class="button button-small button-link-delete route-delete">Delete</button><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="display:flex; gap:10px; margin-top:14px; align-items:center; flex-wrap:wrap;">
            <input type="text" id="route-label-new" placeholder="<?php esc_attr_e('New route label\u2026', 'azure-plugin'); ?>" class="regular-text" style="max-width:240px;">
            <input type="text" id="route-match-new" placeholder="<?php esc_attr_e('From: matcher (addr / prefix@ / *)', 'azure-plugin'); ?>" class="regular-text" style="max-width:260px;">
            <input type="email" id="route-from-new" placeholder="<?php esc_attr_e('Send as address', 'azure-plugin'); ?>" class="regular-text" style="max-width:240px;">
            <select id="route-provider-new">
                <?php foreach ($provider_choices as $val => $lab): ?>
                    <option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($lab); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="button" id="route-add">+ Add route</button>

            <span style="flex:1"></span>

            <button type="button" class="button" id="route-reset" title="<?php esc_attr_e('Discard customizations and restore the seeded defaults', 'azure-plugin'); ?>">Reset to defaults</button>
            <button type="button" class="button button-primary" id="route-save">Save routing table</button>
        </div>

        <p class="description" style="margin-top:6px;">
            <?php if ($saved_at): ?>
                <em>Last saved: <?php echo esc_html($saved_at); ?></em>
            <?php else: ?>
                <em>Showing seeded defaults (nothing saved yet)</em>
            <?php endif; ?>
        </p>

        <div id="route-test-result" style="margin-top:14px;"></div>
    </div>

    <div class="azure-card">
        <h2><span class="dashicons dashicons-info"></span> <?php esc_html_e('How dispatch is decided', 'azure-plugin'); ?></h2>
        <ol>
            <li>Outgoing WP <code>wp_mail()</code> call is intercepted by the router on <code>pre_wp_mail</code> priority 1.</li>
            <li>The router parses the <code>From:</code> header and walks this routing table top-to-bottom.</li>
            <li>First match wins. <code>graph</code>/<code>acs</code> short-circuit and dispatch through PTA Tools' mailer (with the row's <strong>Send as</strong> address + name). <code>acy</code> hands the call back to AcyMailing's pre_wp_mail. The <code>*</code> default catches anything else.</li>
            <li>Every routed dispatch is logged to <code>wp_azure_email_logs</code> with <code>method=router-&lt;provider&gt;</code> so the Logs tab shows the routing decision next to the body.</li>
        </ol>
    </div>

</div>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
</div>
<?php endif; ?>

<style>
#azure-routing-table .route-label,
#azure-routing-table .route-from-match,
#azure-routing-table .route-from-address,
#azure-routing-table .route-reply-to { width:100%; }
#azure-routing-table .route-provider { width:100%; }
#azure-routing-table .route-auth-badge[data-state="ok"]    { background:#e7f5ea; color:#1f6e2a; }
#azure-routing-table .route-auth-badge[data-state="warn"]  { background:#fff8e5; color:#8a6100; }
#azure-routing-table .route-auth-badge[data-state="info"]  { background:#e7f1fb; color:#1d4775; }
#azure-routing-table .route-auth-badge[data-state="error"] { background:#fdecea; color:#b32d2e; }
#azure-routing-table tr.is-dragging { opacity:0.6; }
#route-test-result pre { background:#f6f7f7; padding:10px 12px; border-radius:4px; font-size:12px; max-height:280px; overflow:auto; }
</style>

<script>
jQuery(function ($) {
    var nonce = (window.azure_plugin_ajax && azure_plugin_ajax.nonce) ? azure_plugin_ajax.nonce : '';
    var ajaxUrl = (window.azure_plugin_ajax && azure_plugin_ajax.ajax_url) ? azure_plugin_ajax.ajax_url : (window.ajaxurl || '/wp-admin/admin-ajax.php');

    function escHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }

    function collectRoutes() {
        var routes = [];
        $('#azure-routing-table tbody tr').each(function () {
            var $tr = $(this);
            routes.push({
                id:           $tr.data('route-id'),
                label:        $tr.find('.route-label').val(),
                from_match:   $tr.find('.route-from-match').val(),
                from_address: $tr.find('.route-from-address').val(),
                from_name:    '',
                reply_to:     $tr.find('.route-reply-to').val(),
                provider:     $tr.find('.route-provider').val(),
                enabled:      $tr.find('.route-enabled').is(':checked'),
                is_default:   $tr.data('is-default') === 1 || $tr.data('is-default') === '1'
            });
        });
        return routes;
    }

    // --- Save ---
    $('#route-save').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('Saving\u2026');
        var routes = collectRoutes();
        $.post(ajaxUrl, {
            action: 'azure_email_routing_save',
            nonce: nonce,
            routes: JSON.stringify(routes)
        }).done(function (r) {
            if (r && r.success) {
                window.location.reload();
            } else {
                alert('Save failed: ' + (r && r.data ? r.data : 'unknown error'));
                $btn.prop('disabled', false).text('Save routing table');
            }
        }).fail(function () {
            alert('Save failed (network)');
            $btn.prop('disabled', false).text('Save routing table');
        });
    });

    // --- Reset ---
    $('#route-reset').on('click', function () {
        if (!window.confirm('Reset to the seeded defaults? Your current routing customizations will be discarded.')) return;
        $.post(ajaxUrl, { action: 'azure_email_routing_reset', nonce: nonce }).done(function (r) {
            if (r && r.success) window.location.reload();
            else alert('Reset failed: ' + (r && r.data ? r.data : 'unknown'));
        });
    });

    // --- Add row ---
    $('#route-add').on('click', function () {
        var label = $('#route-label-new').val();
        var match = $('#route-match-new').val();
        var from  = $('#route-from-new').val();
        var prov  = $('#route-provider-new').val();
        if (!label || !match || !from) { alert('Label, matcher, and Send as are required.'); return; }
        var id = 'r' + Math.random().toString(36).slice(2, 8);
        var $defaultRow = $('#azure-routing-table tbody tr[data-is-default="1"]');
        var providerHtml = $('#route-provider-new').html();
        var $row = $(
            '<tr data-route-id="' + escHtml(id) + '" data-is-default="0">' +
            '<td class="drag-handle" style="cursor:grab;color:#a7aaad;text-align:center;"><span class="dashicons dashicons-menu"></span></td>' +
            '<td><input type="checkbox" class="route-enabled" checked></td>' +
            '<td><input type="text" class="route-label regular-text" value="' + escHtml(label) + '"><div style="font-size:11px;color:#646970;margin-top:2px;">id: <code>' + escHtml(id) + '</code></div></td>' +
            '<td><input type="text" class="route-from-match regular-text" value="' + escHtml(match) + '"></td>' +
            '<td><input type="email" class="route-from-address regular-text" value="' + escHtml(from) + '"></td>' +
            '<td><input type="email" class="route-reply-to regular-text" placeholder="(optional)"></td>' +
            '<td><select class="route-provider">' + providerHtml + '</select></td>' +
            '<td><span class="route-auth-badge" data-state="unknown" style="display:inline-block;padding:2px 8px;border-radius:3px;background:#f0f0f1;color:#646970;font-size:12px;">unknown</span></td>' +
            '<td><button type="button" class="button button-small route-check-auth">Check auth</button> <button type="button" class="button button-small route-test">Test</button> <button type="button" class="button button-small button-link-delete route-delete">Delete</button></td>' +
            '</tr>'
        );
        $row.find('.route-provider').val(prov);
        if ($defaultRow.length) $defaultRow.before($row); else $('#azure-routing-table tbody').append($row);
        $('#route-label-new, #route-match-new, #route-from-new').val('');
    });

    // --- Delete row ---
    $(document).on('click', '.route-delete', function () {
        var $tr = $(this).closest('tr');
        if (String($tr.data('is-default')) === '1') return;
        if (!window.confirm('Delete this routing rule? (Not saved until you click Save routing table.)')) return;
        $tr.fadeOut(120, function () { $(this).remove(); });
    });

    // --- Check auth ---
    $(document).on('click', '.route-check-auth', function () {
        var $tr = $(this).closest('tr');
        var rowId = $tr.data('route-id');
        var $badge = $tr.find('.route-auth-badge');
        $badge.attr('data-state', 'unknown').text('checking\u2026');
        $.post(ajaxUrl, { action: 'azure_email_routing_check_auth', nonce: nonce, row_id: rowId }).done(function (r) {
            if (!r || !r.success) {
                $badge.attr('data-state', 'error').text('error').attr('title', (r && r.data) ? r.data : 'unknown');
                return;
            }
            var d = r.data || {};
            var label = d.state === 'ok'   ? '\u2713 ok' :
                        d.state === 'warn' ? '! warn'   :
                        d.state === 'info' ? 'info'     :
                        d.state === 'error' ? '\u2717 ' + (d.provider === 'graph' ? 'no Send-As' : 'error') :
                        'unknown';
            $badge.attr('data-state', d.state || 'info').text(label).attr('title', d.message || '');
        });
    });

    // --- Test send ---
    $(document).on('click', '.route-test', function () {
        var $tr = $(this).closest('tr');
        var rowId = $tr.data('route-id');
        var to = window.prompt('Send a test email to which address?', <?php echo wp_json_encode($default_test_to); ?>);
        if (!to) return;
        var $btn = $(this).prop('disabled', true);
        var $out = $('#route-test-result');
        $out.html('<div style="padding:8px 12px; background:#f6f7f7; border-radius:4px;">Sending test through route <code>' + escHtml(rowId) + '</code> to <code>' + escHtml(to) + '</code>\u2026</div>');
        $.post(ajaxUrl, { action: 'azure_email_routing_test_row', nonce: nonce, row_id: rowId, to: to }).done(function (r) {
            $btn.prop('disabled', false);
            if (!r || !r.success) {
                $out.html('<div style="padding:8px 12px; background:#fdecea; color:#b32d2e; border-radius:4px;">Test failed: ' + escHtml((r && r.data) || 'unknown') + '</div>');
                return;
            }
            var d = r.data || {};
            var pieces = [
                '<strong>Route:</strong> <code>' + escHtml(d.route_id || '?') + '</code> &middot; provider <code>' + escHtml(d.provider || '?') + '</code>',
                '<strong>wp_mail returned:</strong> ' + (d.wp_mail_ok ? '<span style="color:#1f6e2a;">true</span>' : '<span style="color:#b32d2e;">false</span>') + ' &middot; <code>' + (d.elapsed_ms || 0) + 'ms</code>',
                '<strong>From:</strong> <code>' + escHtml(d.from || '') + '</code> &rarr; <code>' + escHtml(d.to || '') + '</code>',
            ];
            if (d.log_row) {
                pieces.push('<strong>Log row:</strong> id=' + escHtml(d.log_row.id) +
                            ' status=' + escHtml(d.log_row.status) +
                            ' method=' + escHtml(d.log_row.method) +
                            ' body_bytes=' + escHtml(d.log_row.body_bytes));
                if (d.log_row.error_message) pieces.push('<strong>Error:</strong> ' + escHtml(d.log_row.error_message));
            } else {
                pieces.push('<em>No corresponding log row found (interceptor short-circuited before our logger).</em>');
            }
            $out.html('<div style="padding:8px 12px; background:#e7f5ea; border-radius:4px;">' + pieces.join('<br>') + '</div>');
        }).fail(function () {
            $btn.prop('disabled', false);
            $out.html('<div style="padding:8px 12px; background:#fdecea; color:#b32d2e; border-radius:4px;">Network error sending test</div>');
        });
    });

    // --- Drag-to-reorder (vanilla, no jQueryUI dependency) ---
    var dragSrc = null;
    $(document).on('mousedown', '#azure-routing-table .drag-handle', function (e) {
        var $tr = $(this).closest('tr');
        if (String($tr.data('is-default')) === '1') return;
        $tr.attr('draggable', 'true');
        dragSrc = $tr[0];
    });
    $(document).on('dragstart', '#azure-routing-table tbody tr', function (e) {
        if (this !== dragSrc) return;
        $(this).addClass('is-dragging');
        e.originalEvent.dataTransfer.effectAllowed = 'move';
        e.originalEvent.dataTransfer.setData('text/plain', $(this).data('route-id') || '');
    });
    $(document).on('dragover', '#azure-routing-table tbody tr', function (e) { e.preventDefault(); });
    $(document).on('drop', '#azure-routing-table tbody tr', function (e) {
        e.preventDefault();
        if (!dragSrc || this === dragSrc) return;
        var $target = $(this);
        if (String($target.data('is-default')) === '1') {
            // Don't let users drop after the default row's "below" position;
            // place before it instead so the default stays at the bottom.
            $target.before(dragSrc);
        } else {
            $target.before(dragSrc);
        }
        $(dragSrc).removeClass('is-dragging').removeAttr('draggable');
        dragSrc = null;
    });
    $(document).on('dragend', '#azure-routing-table tbody tr', function () {
        $(this).removeClass('is-dragging').removeAttr('draggable');
        dragSrc = null;
    });
});
</script>
