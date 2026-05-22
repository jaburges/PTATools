<?php
/**
 * User Management → Family Hierarchy tab
 *
 * Visual list of every connected family. Each card shows Parent 1 / Parent 2
 * (clickable to wp-admin user editor), the children attached to that family,
 * and the family-scope emergency contact. Search box hits an AJAX endpoint
 * for incremental filtering without round-tripping the full grid.
 */

if (!defined('ABSPATH')) {
    exit;
}

$initial = Azure_User_Management_Module::get_families(array('page' => 1));
$nonce   = wp_create_nonce(Azure_User_Management_Module::NONCE_ACTION);
?>
<style>
.azure-um-fam-list { display:grid; grid-template-columns:1fr; gap:14px; max-width:1100px; }
.azure-um-fam-card { background:#fff; border:1px solid #e0e0e0; border-radius:6px; padding:14px 18px; }
.azure-um-fam-card__header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:10px; }
.azure-um-fam-card__title { font-weight:600; font-size:15px; margin:0; }
.azure-um-fam-card__count { color:#666; font-size:12px; }
.azure-um-fam-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px 24px; }
.azure-um-fam-grid--single { grid-template-columns:1fr; }
.azure-um-fam-cell { font-size:13px; line-height:1.45; }
.azure-um-fam-cell__role { display:block; color:#666; font-size:11px; text-transform:uppercase; letter-spacing:.04em; margin-bottom:2px; }
.azure-um-fam-cell__empty { color:#999; font-style:italic; }
.azure-um-fam-children { margin-top:12px; padding-top:10px; border-top:1px dashed #e0e0e0; display:flex; flex-wrap:wrap; gap:6px; }
.azure-um-fam-child { background:#f4f7fb; border:1px solid #d6e0ee; border-radius:14px; padding:3px 12px; font-size:12px; }
.azure-um-fam-emergency { margin-top:10px; padding:8px 12px; background:#fff8e6; border:1px solid #f5d27c; border-radius:4px; font-size:12px; color:#5a4a17; }
.azure-um-fam-emergency strong { color:#3a3a3a; }
.azure-um-fam-toolbar { display:flex; align-items:center; gap:10px; margin-bottom:14px; max-width:1100px; }
.azure-um-fam-toolbar input[type=search] { flex:1; max-width:340px; }
.azure-um-fam-pager { display:flex; align-items:center; gap:8px; margin-top:18px; max-width:1100px; }
.azure-um-fam-pager .button:disabled { opacity:.5; cursor:default; }
@media (max-width: 720px) { .azure-um-fam-grid { grid-template-columns:1fr; } }
</style>

<p class="description" style="max-width:900px;">
    <?php _e('Every connected family in the system. Parent 1 and Parent 2 share the same children and the same emergency contact. Click a parent name to open the WordPress user editor, or click a child name to open their profile in the parent\'s My Account.', 'azure-plugin'); ?>
</p>

<div class="azure-um-fam-toolbar">
    <input type="search" id="azure-um-fam-search" placeholder="<?php esc_attr_e('Search by parent name, child name, or email…', 'azure-plugin'); ?>" />
    <span id="azure-um-fam-count" style="color:#666;"><?php
        printf(
            /* translators: %d: total number of families */
            esc_html(_n('%d family', '%d families', $initial['total'], 'azure-plugin')),
            (int) $initial['total']
        );
    ?></span>
    <span id="azure-um-fam-status" style="color:#666;font-style:italic;"></span>
</div>

<div id="azure-um-fam-list" class="azure-um-fam-list">
    <?php include __DIR__ . '/family-hierarchy-rows.php'; ?>
</div>

<div class="azure-um-fam-pager">
    <button type="button" class="button" id="azure-um-fam-prev" disabled><?php _e('← Previous', 'azure-plugin'); ?></button>
    <span id="azure-um-fam-page-info">
        <?php
        $shown = min((int) $initial['per_page'], (int) $initial['total']);
        $total = (int) $initial['total'];
        printf(esc_html__('Showing 1–%1$d of %2$d', 'azure-plugin'), (int) $shown, (int) $total);
        ?>
    </span>
    <button type="button" class="button" id="azure-um-fam-next" <?php echo ((int) $initial['total'] <= (int) $initial['per_page']) ? 'disabled' : ''; ?>><?php _e('Next →', 'azure-plugin'); ?></button>
</div>

<script>
jQuery(function($){
    var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
    var nonce   = '<?php echo esc_js($nonce); ?>';
    var perPage = <?php echo (int) $initial['per_page']; ?>;
    var state   = { search: '', page: 1, total: <?php echo (int) $initial['total']; ?> };
    var $list   = $('#azure-um-fam-list');
    var $status = $('#azure-um-fam-status');
    var $count  = $('#azure-um-fam-count');
    var $prev   = $('#azure-um-fam-prev');
    var $next   = $('#azure-um-fam-next');
    var $info   = $('#azure-um-fam-page-info');
    var debounceTimer = null;

    function renderRow(r) {
        var p1 = r.p1_name
            ? '<a href="' + adminUserUrl(r.primary_user_id) + '">' + escapeHtml(r.p1_name) + '</a><br><small style="color:#888;">' + escapeHtml(r.p1_email || '') + '</small>'
            : '<span class="azure-um-fam-cell__empty"><?php echo esc_js(__('Unlinked', 'azure-plugin')); ?></span>';
        var p2 = r.p2_name
            ? '<a href="' + adminUserUrl(r.secondary_user_id) + '">' + escapeHtml(r.p2_name) + '</a><br><small style="color:#888;">' + escapeHtml(r.p2_email || '') + '</small>'
            : '<span class="azure-um-fam-cell__empty"><?php echo esc_js(__('No co-parent on record', 'azure-plugin')); ?></span>';

        var kids = '';
        (r.children || []).forEach(function(k) {
            kids += '<span class="azure-um-fam-child">' + escapeHtml(k.child_name || '') + '</span>';
        });
        if (!kids) kids = '<span class="azure-um-fam-cell__empty"><?php echo esc_js(__('No children attached', 'azure-plugin')); ?></span>';

        var emName  = (r.emergency_contact && r.emergency_contact.pta_pf_emergency_contact_name)  || '';
        var emEmail = (r.emergency_contact && r.emergency_contact.pta_pf_emergency_contact_email) || '';
        var emCell  = (r.emergency_contact && r.emergency_contact.pta_pf_emergency_contact_cell)  || '';
        var em = '';
        if (emName || emEmail || emCell) {
            var parts = [];
            if (emName)  parts.push(escapeHtml(emName));
            if (emEmail) parts.push(escapeHtml(emEmail));
            if (emCell)  parts.push(escapeHtml(emCell));
            em = '<div class="azure-um-fam-emergency"><strong><?php echo esc_js(__('Emergency contact:', 'azure-plugin')); ?></strong> ' + parts.join(' · ') + '</div>';
        }

        var gridClass = r.p2_name ? '' : ' azure-um-fam-grid--single';
        return '<div class="azure-um-fam-card">' +
                 '<div class="azure-um-fam-card__header">' +
                   '<h3 class="azure-um-fam-card__title">' + escapeHtml(r.family_name || '#' + r.id) + '</h3>' +
                   '<span class="azure-um-fam-card__count">' + (r.child_count|0) + ' <?php echo esc_js(__('child(ren)', 'azure-plugin')); ?></span>' +
                 '</div>' +
                 '<div class="azure-um-fam-grid' + gridClass + '">' +
                   '<div class="azure-um-fam-cell"><span class="azure-um-fam-cell__role"><?php echo esc_js(__('Parent 1', 'azure-plugin')); ?></span>' + p1 + '</div>' +
                   (r.p2_name || r.secondary_user_id ? '<div class="azure-um-fam-cell"><span class="azure-um-fam-cell__role"><?php echo esc_js(__('Parent 2', 'azure-plugin')); ?></span>' + p2 + '</div>' : '') +
                 '</div>' +
                 '<div class="azure-um-fam-children">' + kids + '</div>' +
                 em +
               '</div>';
    }

    function adminUserUrl(uid) {
        if (!uid) return '#';
        return '<?php echo esc_js(admin_url('user-edit.php?user_id=')); ?>' + encodeURIComponent(uid);
    }

    function escapeHtml(s) {
        return $('<span>').text(s == null ? '' : s).html();
    }

    function fetchPage() {
        $status.text('<?php echo esc_js(__('Loading…', 'azure-plugin')); ?>');
        $.post(ajaxUrl, {
            action: 'pta_um_search_families',
            nonce: nonce,
            search: state.search,
            page: state.page
        }).done(function(res) {
            if (!res || !res.success) {
                $status.text('<?php echo esc_js(__('Search failed', 'azure-plugin')); ?>');
                return;
            }
            state.total = res.data.total;
            $count.text(res.data.total + ' <?php echo esc_js(__('result(s)', 'azure-plugin')); ?>');
            $list.empty();
            (res.data.rows || []).forEach(function(r) { $list.append(renderRow(r)); });
            if (!res.data.rows.length) {
                $list.append('<div class="azure-um-fam-card" style="text-align:center;color:#666;"><?php echo esc_js(__('No matching families.', 'azure-plugin')); ?></div>');
            }
            updatePager();
            $status.text('');
        }).fail(function() {
            $status.text('<?php echo esc_js(__('Network error', 'azure-plugin')); ?>');
        });
    }

    function updatePager() {
        var first = ((state.page - 1) * perPage) + 1;
        var last  = Math.min(state.page * perPage, state.total);
        if (state.total === 0) { first = 0; last = 0; }
        $info.text('<?php echo esc_js(__('Showing', 'azure-plugin')); ?> ' + first + '–' + last + ' <?php echo esc_js(__('of', 'azure-plugin')); ?> ' + state.total);
        $prev.prop('disabled', state.page <= 1);
        $next.prop('disabled', state.page * perPage >= state.total);
    }

    $('#azure-um-fam-search').on('input', function() {
        var q = $(this).val();
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function() {
            state.search = q;
            state.page = 1;
            fetchPage();
        }, 250);
    });

    $prev.on('click', function() { if (state.page > 1) { state.page--; fetchPage(); } });
    $next.on('click', function() { if (state.page * perPage < state.total) { state.page++; fetchPage(); } });

    updatePager();
});
</script>
