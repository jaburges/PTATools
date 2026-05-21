<?php
/**
 * Product Fields → Consolidate Legacy Fields
 *
 * Admin UI for the one-time migration that maps old, duplicated visible
 * line-item meta keys (e.g. "Child name", "Childs name", "Child(s) name")
 * onto a single canonical `field_key` in `wp_azure_product_fields`.
 *
 * Dry-run is the default. The "Apply" button is only enabled after a scan.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    return;
}

require_once AZURE_PLUGIN_PATH . 'includes/class-product-fields-migrator.php';
$migrator  = Azure_Product_Fields_Migrator::get_instance();
$canonical = $migrator->get_canonical_fields();
?>
<div class="wrap azure-pf-consolidate-page">
    <h1><span class="dashicons dashicons-update"></span> <?php _e('Consolidate legacy product fields', 'azure-plugin'); ?></h1>
    <p class="description" style="max-width:780px;">
        <?php _e('Scan the order database for visible line-item meta (the duplicate "Child name" / "Childs name" labels left by older plugins), map each variation to a canonical field, and rewrite the meta to a stable key. This makes order exports clean and lets you safely remove the old plugin.', 'azure-plugin'); ?>
    </p>
    <p class="description" style="max-width:780px;">
        <strong><?php _e('Dry-run is the default.', 'azure-plugin'); ?></strong>
        <?php _e('Old labels are never deleted. The new `_pta_<field_key>` meta is added alongside, so reports written against the legacy keys keep working.', 'azure-plugin'); ?>
    </p>

    <div class="notice notice-info inline" style="max-width:780px;margin:14px 0;">
        <p style="margin:8px 12px;">
            <strong><?php _e('Map your child-name labels to', 'azure-plugin'); ?>
            <code>child_name</code> <?php _e('first.', 'azure-plugin'); ?></strong>
            <?php _e('Every other child-scope mapping (allergies, grade, EpiPen, etc.) uses the value from the same line item to find or create the right child under the order\'s parent.', 'azure-plugin'); ?>
        </p>
    </div>

    <?php if (empty($canonical)): ?>
        <div class="notice notice-warning">
            <p><?php _e('You have no canonical fields yet. Create them first under', 'azure-plugin'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-selling&tab=product-fields')); ?>"><?php _e('Selling → Product Fields', 'azure-plugin'); ?></a>.
            </p>
        </div>
    <?php endif; ?>

    <p>
        <button type="button" class="button button-primary" id="azure-pf-mig-scan"><?php _e('Scan order data', 'azure-plugin'); ?></button>
        <span id="azure-pf-mig-status" style="margin-left:12px;color:#666;"></span>
    </p>

    <div id="azure-pf-mig-results" style="display:none;margin-top:20px;">
        <table class="wp-list-table widefat striped" id="azure-pf-mig-table">
            <thead>
                <tr>
                    <th style="width:30%;"><?php _e('Legacy meta key', 'azure-plugin'); ?></th>
                    <th style="width:8%;"><?php _e('Rows', 'azure-plugin'); ?></th>
                    <th style="width:25%;"><?php _e('Sample value', 'azure-plugin'); ?></th>
                    <th style="width:25%;"><?php _e('Map to canonical field', 'azure-plugin'); ?></th>
                    <th style="width:12%;"><?php _e('Scope', 'azure-plugin'); ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>

        <p style="margin-top:18px;">
            <button type="button" class="button" id="azure-pf-mig-dry"><?php _e('Run dry-run', 'azure-plugin'); ?></button>
            <button type="button" class="button button-primary" id="azure-pf-mig-apply" disabled><?php _e('Apply changes', 'azure-plugin'); ?></button>
            <span id="azure-pf-mig-summary" style="margin-left:12px;"></span>
        </p>

        <pre id="azure-pf-mig-detail" style="display:none;background:#f7f7f7;padding:12px;border-radius:4px;max-height:400px;overflow:auto;"></pre>
    </div>
</div>

<script>
jQuery(function($) {
    var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
    var nonce   = '<?php echo esc_js(wp_create_nonce('azure_plugin_nonce')); ?>';
    var canonical = <?php echo wp_json_encode(array_map(function ($f) {
        return array(
            'field_key' => $f->field_key,
            'label'     => $f->label,
            'scope'     => $f->scope,
        );
    }, $canonical)); ?>;
    var lastDryRunOk = false;

    function escHtml(s) { return $('<span>').text(s == null ? '' : s).html(); }

    function buildSelect(legacyKey) {
        var html = '<select class="azure-pf-mig-target" data-legacy="' + escHtml(legacyKey) + '">';
        html += '<option value="">— Skip —</option>';
        canonical.forEach(function(f) {
            html += '<option value="' + escHtml(f.field_key) + '" data-scope="' + escHtml(f.scope) + '">' +
                escHtml(f.label) + ' (' + escHtml(f.field_key) + ')' +
                '</option>';
        });
        html += '</select>';
        return html;
    }

    function buildScope(legacyKey) {
        return '<select class="azure-pf-mig-scope" data-legacy="' + escHtml(legacyKey) + '">' +
               '<option value="child">Child</option>' +
               '<option value="parent">Parent</option>' +
               '</select>';
    }

    $('#azure-pf-mig-scan').on('click', function() {
        var $btn = $(this).prop('disabled', true);
        $('#azure-pf-mig-status').text('Scanning…');

        $.post(ajaxUrl, { action: 'azure_pf_mig_scan', nonce: nonce }, function(res) {
            $btn.prop('disabled', false);
            if (!res.success) {
                $('#azure-pf-mig-status').text('Scan failed: ' + (res.data || ''));
                return;
            }
            var legacy = res.data.legacy || [];
            $('#azure-pf-mig-status').text(legacy.length + ' distinct meta keys found.');

            var $body = $('#azure-pf-mig-table tbody').empty();
            if (!legacy.length) {
                $body.html('<tr><td colspan="5" style="text-align:center;color:#999;padding:20px;">No legacy line-item meta found.</td></tr>');
            } else {
                legacy.forEach(function(row) {
                    $body.append(
                        '<tr>' +
                        '<td><strong>' + escHtml(row.key) + '</strong></td>' +
                        '<td>' + escHtml(row.count) + '</td>' +
                        '<td>' + escHtml(row.sample) + '</td>' +
                        '<td>' + buildSelect(row.key) + '</td>' +
                        '<td>' + buildScope(row.key) + '</td>' +
                        '</tr>'
                    );
                });
            }
            $('#azure-pf-mig-results').show();
            lastDryRunOk = false;
            $('#azure-pf-mig-apply').prop('disabled', true);
            $('#azure-pf-mig-summary').text('');
            $('#azure-pf-mig-detail').hide().text('');
        }).fail(function() {
            $btn.prop('disabled', false);
            $('#azure-pf-mig-status').text('Scan request failed');
        });
    });

    // When admin picks a target, default the scope to that field's scope.
    $(document).on('change', '.azure-pf-mig-target', function() {
        var scope = $(this).find('option:selected').data('scope');
        if (scope) {
            $(this).closest('tr').find('.azure-pf-mig-scope').val(scope);
        }
    });

    function collectMap() {
        var map = {};
        $('.azure-pf-mig-target').each(function() {
            var key = $(this).val();
            if (!key) return;
            var legacy = $(this).data('legacy');
            var scope  = $(this).closest('tr').find('.azure-pf-mig-scope').val() || 'child';
            map[legacy] = { field_key: key, scope: scope };
        });
        return map;
    }

    function runApply(apply) {
        var map = collectMap();
        if ($.isEmptyObject(map)) {
            alert('Map at least one row before running.');
            return;
        }

        var $applyBtn = $('#azure-pf-mig-apply').prop('disabled', true);
        var $dryBtn   = $('#azure-pf-mig-dry').prop('disabled', true);
        $('#azure-pf-mig-summary').text(apply ? 'Applying…' : 'Dry-running…');

        var data = { action: 'azure_pf_mig_apply', nonce: nonce, map: map };
        if (apply) data.apply = '1';

        $.post(ajaxUrl, data, function(res) {
            $dryBtn.prop('disabled', false);
            if (!res.success) {
                $('#azure-pf-mig-summary').text('Failed: ' + (res.data || ''));
                $applyBtn.prop('disabled', !lastDryRunOk);
                return;
            }
            var s = res.data;
            var prefix = s.dry_run ? 'Dry-run: ' : 'Applied: ';
            var verb   = s.dry_run ? 'would ' : '';
            $('#azure-pf-mig-summary').html(prefix +
                s.items_touched + ' line items scanned · ' +
                s.meta_added    + ' canonical meta ' + verb + 'added · ' +
                s.parent_meta_writes + ' parent profile ' + verb + 'writes · ' +
                s.child_meta_writes  + ' child profile '  + verb + 'writes · ' +
                s.children_matched   + ' children matched, ' +
                s.children_created   + ' ' + verb + 'created · ' +
                '<strong style="color:' + (s.unresolvable > 0 ? '#dc3232' : '#46b450') + ';">' +
                s.unresolvable + ' unresolvable</strong>'
            );
            $('#azure-pf-mig-detail').show().text(JSON.stringify(s, null, 2));
            if (s.dry_run) {
                lastDryRunOk = true;
                $applyBtn.prop('disabled', false);
            } else {
                lastDryRunOk = false;
                $applyBtn.prop('disabled', true);
            }
        }).fail(function() {
            $dryBtn.prop('disabled', false);
            $applyBtn.prop('disabled', !lastDryRunOk);
            $('#azure-pf-mig-summary').text('Request failed');
        });
    }

    $('#azure-pf-mig-dry').on('click', function() { runApply(false); });
    $('#azure-pf-mig-apply').on('click', function() {
        if (!confirm('Rewrite order item meta with the canonical keys? This is reversible only by deleting the new `_pta_*` meta entries.')) return;
        runApply(true);
    });
});
</script>

<style>
.azure-pf-consolidate-page select.azure-pf-mig-target,
.azure-pf-consolidate-page select.azure-pf-mig-scope {
    width: 100%;
}
</style>
