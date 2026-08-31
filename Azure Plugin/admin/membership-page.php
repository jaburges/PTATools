<?php
/**
 * PTA Tools → Membership
 *
 * Roster of parents + staff, filterable by paid membership this school year,
 * plus product-ID settings and a CSV export for WA / LW PTSA.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!Azure_Membership_Module::current_user_can_manage()) {
    return;
}

$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['azure_membership_save'])) {
    check_admin_referer(Azure_Membership_Module::NONCE_ADMIN);
    $family = isset($_POST['family_product_ids']) ? (array) $_POST['family_product_ids'] : array();
    $indiv  = isset($_POST['individual_product_ids']) ? (array) $_POST['individual_product_ids'] : array();
    Azure_Membership_Module::save_product_ids($family, $indiv);
    $saved = true;
}

$range        = Azure_Membership_Module::school_year_range();
$family_ids   = Azure_Membership_Module::get_family_product_ids();
$indiv_ids    = Azure_Membership_Module::get_individual_product_ids();
$products     = array();
if (function_exists('wc_get_products')) {
    $products = wc_get_products(array(
        'status'  => array('publish', 'private'),
        'limit'   => -1,
        'orderby' => 'title',
        'order'   => 'ASC',
    ));
}

$roster_ids = Azure_Membership_Module::roster_user_ids();
$rows       = Azure_Membership_Module::build_roster_rows($roster_ids);
$export_url = wp_nonce_url(
    admin_url('admin.php?page=azure-plugin-membership&export=csv'),
    Azure_Membership_Module::NONCE_ADMIN
);

$member_count = 0;
$family_count = 0;
$indiv_count  = 0;
foreach ($rows as $r) {
    if ($r['membership'] === 'family') {
        $member_count++;
        $family_count++;
    } elseif ($r['membership'] === 'individual') {
        $member_count++;
        $indiv_count++;
    }
}
?>
<div class="wrap azure-membership-admin">
    <h1><?php esc_html_e('Membership', 'azure-plugin'); ?></h1>
    <p class="description">
        <?php
        printf(
            /* translators: %s: school year label like 2026–2027 */
            esc_html__('Paid Family, Individual, or Staff membership this school year (%s). The directory is a separate list — only parents who opted in.', 'azure-plugin'),
            esc_html($range['label'])
        );
        ?>
    </p>

    <?php if ($saved): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Membership products saved. The member list has been refreshed.', 'azure-plugin'); ?></p></div>
    <?php endif; ?>

    <div class="azure-membership-stats">
        <div class="azure-membership-stat">
            <strong><?php echo (int) count($rows); ?></strong>
            <span><?php esc_html_e('People in roster', 'azure-plugin'); ?></span>
        </div>
        <div class="azure-membership-stat">
            <strong><?php echo (int) $member_count; ?></strong>
            <span><?php esc_html_e('Members this year', 'azure-plugin'); ?></span>
        </div>
        <div class="azure-membership-stat">
            <strong><?php echo (int) $family_count; ?></strong>
            <span><?php esc_html_e('Family', 'azure-plugin'); ?></span>
        </div>
        <div class="azure-membership-stat">
            <strong><?php echo (int) $indiv_count; ?></strong>
            <span><?php esc_html_e('Individual', 'azure-plugin'); ?></span>
        </div>
    </div>

    <form method="post" class="azure-membership-settings">
        <?php wp_nonce_field(Azure_Membership_Module::NONCE_ADMIN); ?>
        <h2><?php esc_html_e('Membership products', 'azure-plugin'); ?></h2>
        <p class="description">
            <?php esc_html_e('Pick the WooCommerce products that count as Family and Individual membership. A Family purchase also marks the connected co-parent as a member.', 'azure-plugin'); ?>
        </p>
        <?php if (empty($products)): ?>
            <p><?php esc_html_e('No WooCommerce products found. Activate WooCommerce and create the membership products first.', 'azure-plugin'); ?></p>
        <?php else: ?>
            <div class="azure-membership-pickers">
                <label>
                    <span><?php esc_html_e('Family membership', 'azure-plugin'); ?></span>
                    <select name="family_product_ids[]" multiple size="8">
                        <?php foreach ($products as $product): ?>
                            <option value="<?php echo (int) $product->get_id(); ?>" <?php selected(in_array((int) $product->get_id(), $family_ids, true)); ?>>
                                <?php echo esc_html($product->get_name()); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?php esc_html_e('Individual membership', 'azure-plugin'); ?></span>
                    <select name="individual_product_ids[]" multiple size="8">
                        <?php foreach ($products as $product): ?>
                            <option value="<?php echo (int) $product->get_id(); ?>" <?php selected(in_array((int) $product->get_id(), $indiv_ids, true)); ?>>
                                <?php echo esc_html($product->get_name()); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <p>
                <button type="submit" name="azure_membership_save" class="button button-primary"><?php esc_html_e('Save products', 'azure-plugin'); ?></button>
            </p>
        <?php endif; ?>
        <p class="description">
            <?php esc_html_e('The “List me in the parent directory” checkboxes are Parent Core product fields. If that group is already attached to these products, the boxes appear at checkout automatically. Parents can also change them later on My Account → Profile.', 'azure-plugin'); ?>
        </p>
    </form>

    <div class="azure-membership-shortcode">
        <h2><?php esc_html_e('Parent directory shortcode', 'azure-plugin'); ?></h2>
        <p class="description">
            <?php esc_html_e('Put this on any page. Only opted-in parents are listed. Visitors must be signed in as a parent, staff, or Azure AD user. Names never appear to guests.', 'azure-plugin'); ?>
        </p>
        <p>
            <code id="azure-mem-shortcode">[parent-directory]</code>
            <button type="button" class="button button-small" id="azure-mem-copy-shortcode"><?php esc_html_e('Copy', 'azure-plugin'); ?></button>
        </p>
        <table class="widefat striped" style="max-width:720px;">
            <thead>
                <tr>
                    <th><?php esc_html_e('Attribute', 'azure-plugin'); ?></th>
                    <th><?php esc_html_e('Default', 'azure-plugin'); ?></th>
                    <th><?php esc_html_e('What it does', 'azure-plugin'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>show_email</code></td>
                    <td><code>false</code></td>
                    <td><?php esc_html_e('Set to true to show the parent email column.', 'azure-plugin'); ?></td>
                </tr>
                <tr>
                    <td><code>show_cell</code></td>
                    <td><code>false</code></td>
                    <td><?php esc_html_e('Set to true to show the parent phone column.', 'azure-plugin'); ?></td>
                </tr>
            </tbody>
        </table>
        <p class="description" style="margin-top:12px;">
            <?php esc_html_e('Examples:', 'azure-plugin'); ?>
            <code>[parent-directory]</code>
            &nbsp;
            <code>[parent-directory show_email="true"]</code>
            &nbsp;
            <code>[parent-directory show_email="true" show_cell="true"]</code>
        </p>
        <p class="description">
            <?php esc_html_e('[Parent-directory] is accepted as an alias. This list is not the paid roster — it is opt-in only.', 'azure-plugin'); ?>
        </p>
    </div>

    <h2><?php esc_html_e('Roster', 'azure-plugin'); ?></h2>
    <div class="azure-membership-toolbar">
        <input type="search" id="azure-mem-search" placeholder="<?php esc_attr_e('Search name or email…', 'azure-plugin'); ?>" />
        <select id="azure-mem-status">
            <option value=""><?php esc_html_e('All — members and non-members', 'azure-plugin'); ?></option>
            <option value="member"><?php esc_html_e('Members', 'azure-plugin'); ?></option>
            <option value="nonmember"><?php esc_html_e('Non-members', 'azure-plugin'); ?></option>
        </select>
        <select id="azure-mem-type">
            <option value=""><?php esc_html_e('Any membership type', 'azure-plugin'); ?></option>
            <option value="family"><?php esc_html_e('Family', 'azure-plugin'); ?></option>
            <option value="individual"><?php esc_html_e('Individual', 'azure-plugin'); ?></option>
            <option value="staff"><?php esc_html_e('Staff', 'azure-plugin'); ?></option>
            <option value="none"><?php esc_html_e('None', 'azure-plugin'); ?></option>
        </select>
        <select id="azure-mem-role">
            <option value=""><?php esc_html_e('Any role type', 'azure-plugin'); ?></option>
            <option value="Parent"><?php esc_html_e('Parent', 'azure-plugin'); ?></option>
            <option value="School staff"><?php esc_html_e('School staff', 'azure-plugin'); ?></option>
            <option value="Azure AD"><?php esc_html_e('Azure AD', 'azure-plugin'); ?></option>
        </select>
        <span id="azure-mem-count"></span>
        <a class="button" href="<?php echo esc_url($export_url); ?>"><?php esc_html_e('Export sold memberships CSV (WA / LW)', 'azure-plugin'); ?></a>
    </div>

    <table class="widefat striped" id="azure-mem-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Name', 'azure-plugin'); ?></th>
                <th><?php esc_html_e('Email', 'azure-plugin'); ?></th>
                <th><?php esc_html_e('Role types', 'azure-plugin'); ?></th>
                <th><?php esc_html_e('Membership', 'azure-plugin'); ?></th>
                <th><?php esc_html_e('Paid', 'azure-plugin'); ?></th>
                <th><?php esc_html_e('Children', 'azure-plugin'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="6"><?php esc_html_e('No parents or staff found.', 'azure-plugin'); ?></td></tr>
        <?php else: ?>
            <?php foreach ($rows as $row):
                $child_bits = array();
                foreach ($row['children'] as $child) {
                    $child_bits[] = $child['grade'] !== ''
                        ? $child['name'] . ' (' . $child['grade'] . ')'
                        : $child['name'];
                }
                $status = $row['membership'] === 'none' ? 'nonmember' : 'member';
                ?>
            <tr data-status="<?php echo esc_attr($status); ?>"
                data-type="<?php echo esc_attr($row['membership']); ?>"
                data-roles="<?php echo esc_attr(implode('|', $row['role_types'])); ?>"
                data-search="<?php echo esc_attr(strtolower($row['name'] . ' ' . $row['email'])); ?>">
                <td>
                    <a href="<?php echo esc_url(get_edit_user_link($row['user_id'])); ?>">
                        <?php echo esc_html($row['name']); ?>
                    </a>
                </td>
                <td><?php echo esc_html($row['email']); ?></td>
                <td><?php echo esc_html(implode(', ', $row['role_types'])); ?></td>
                <td><?php echo $row['membership'] === 'none' ? '—' : esc_html(ucfirst($row['membership'])); ?></td>
                <td><?php echo $row['paid_at'] !== '' ? esc_html(substr($row['paid_at'], 0, 10)) : '—'; ?></td>
                <td><?php echo esc_html(implode(', ', $child_bits)); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<style>
.azure-membership-stats { display:flex; gap:16px; flex-wrap:wrap; margin:16px 0 24px; }
.azure-membership-stat { background:#fff; border:1px solid #dcdcde; border-radius:4px; padding:12px 16px; min-width:120px; }
.azure-membership-stat strong { display:block; font-size:22px; line-height:1.2; }
.azure-membership-stat span { color:#646970; font-size:12px; }
.azure-membership-settings,
.azure-membership-shortcode { background:#fff; border:1px solid #dcdcde; border-radius:4px; padding:16px 18px; margin:0 0 24px; max-width:960px; }
.azure-membership-shortcode h2 { margin-top:0; }
.azure-membership-shortcode code { font-size:13px; }
.azure-membership-pickers { display:grid; grid-template-columns:1fr 1fr; gap:16px; max-width:800px; }
.azure-membership-pickers label span { display:block; font-weight:600; margin-bottom:6px; }
.azure-membership-pickers select { width:100%; }
.azure-membership-toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:12px 0; }
.azure-membership-toolbar input[type=search] { min-width:220px; }
#azure-mem-count { color:#646970; }
#azure-mem-table { margin-top:8px; }
@media (max-width: 720px) { .azure-membership-pickers { grid-template-columns:1fr; } }
</style>
<script>
(function(){
    var search = document.getElementById('azure-mem-search');
    var status = document.getElementById('azure-mem-status');
    var type = document.getElementById('azure-mem-type');
    var role = document.getElementById('azure-mem-role');
    var count = document.getElementById('azure-mem-count');
    var rows = document.querySelectorAll('#azure-mem-table tbody tr[data-search]');
    function apply(){
        var q = (search.value || '').toLowerCase().trim();
        var st = status.value;
        var ty = type.value;
        var rl = role.value;
        var shown = 0;
        rows.forEach(function(tr){
            var ok = true;
            if (q && (tr.getAttribute('data-search') || '').indexOf(q) === -1) ok = false;
            if (st && tr.getAttribute('data-status') !== st) ok = false;
            if (ty && tr.getAttribute('data-type') !== ty) ok = false;
            if (rl) {
                var roles = (tr.getAttribute('data-roles') || '').split('|');
                if (roles.indexOf(rl) === -1) ok = false;
            }
            tr.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        if (count) count.textContent = shown + ' shown';
    }
    [search, status, type, role].forEach(function(el){
        if (!el) return;
        el.addEventListener('input', apply);
        el.addEventListener('change', apply);
    });
    apply();
    var copyBtn = document.getElementById('azure-mem-copy-shortcode');
    if (copyBtn && navigator.clipboard) {
        copyBtn.addEventListener('click', function(){
            navigator.clipboard.writeText('[parent-directory]').then(function(){
                copyBtn.textContent = 'Copied';
                setTimeout(function(){ copyBtn.textContent = 'Copy'; }, 1500);
            });
        });
    }
})();
</script>
