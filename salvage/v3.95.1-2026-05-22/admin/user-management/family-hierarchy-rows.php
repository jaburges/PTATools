<?php
/**
 * Initial server-rendered family rows for the Family Hierarchy tab.
 * Mirrors the JS renderer output so the page is usable before the AJAX
 * grid wires up.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (empty($initial['rows'])) {
    echo '<div class="azure-um-fam-card" style="text-align:center;color:#666;">' .
         esc_html__('No connected families yet. Run the v3.67 import or visit /my-account/profile/ to start populating.', 'azure-plugin') .
         '</div>';
    return;
}

foreach ($initial['rows'] as $r):
    $p1_url = $r->primary_user_id   ? admin_url('user-edit.php?user_id=' . (int) $r->primary_user_id)   : '#';
    $p2_url = $r->secondary_user_id ? admin_url('user-edit.php?user_id=' . (int) $r->secondary_user_id) : '#';
    $em_name  = isset($r->emergency_contact['pta_pf_emergency_contact_name'])  ? $r->emergency_contact['pta_pf_emergency_contact_name']  : '';
    $em_email = isset($r->emergency_contact['pta_pf_emergency_contact_email']) ? $r->emergency_contact['pta_pf_emergency_contact_email'] : '';
    $em_cell  = isset($r->emergency_contact['pta_pf_emergency_contact_cell'])  ? $r->emergency_contact['pta_pf_emergency_contact_cell']  : '';
    $has_p2 = !empty($r->p2_name) || !empty($r->secondary_user_id);
?>
<div class="azure-um-fam-card">
    <div class="azure-um-fam-card__header">
        <h3 class="azure-um-fam-card__title"><?php echo esc_html($r->family_name ?: '#' . $r->id); ?></h3>
        <span class="azure-um-fam-card__count">
            <?php echo (int) $r->child_count; ?>
            <?php _e('child(ren)', 'azure-plugin'); ?>
        </span>
    </div>
    <div class="azure-um-fam-grid<?php echo $has_p2 ? '' : ' azure-um-fam-grid--single'; ?>">
        <div class="azure-um-fam-cell">
            <span class="azure-um-fam-cell__role"><?php _e('Parent 1', 'azure-plugin'); ?></span>
            <?php if ($r->p1_name): ?>
                <a href="<?php echo esc_url($p1_url); ?>"><?php echo esc_html($r->p1_name); ?></a>
                <br><small style="color:#888;"><?php echo esc_html($r->p1_email); ?></small>
            <?php else: ?>
                <span class="azure-um-fam-cell__empty"><?php _e('Unlinked', 'azure-plugin'); ?></span>
            <?php endif; ?>
        </div>
        <?php if ($has_p2): ?>
        <div class="azure-um-fam-cell">
            <span class="azure-um-fam-cell__role"><?php _e('Parent 2', 'azure-plugin'); ?></span>
            <?php if ($r->p2_name): ?>
                <a href="<?php echo esc_url($p2_url); ?>"><?php echo esc_html($r->p2_name); ?></a>
                <br><small style="color:#888;"><?php echo esc_html($r->p2_email); ?></small>
            <?php else: ?>
                <span class="azure-um-fam-cell__empty"><?php _e('No co-parent on record', 'azure-plugin'); ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="azure-um-fam-children">
        <?php if (!empty($r->children)): ?>
            <?php foreach ($r->children as $kid): ?>
                <span class="azure-um-fam-child"><?php echo esc_html($kid->child_name); ?></span>
            <?php endforeach; ?>
        <?php else: ?>
            <span class="azure-um-fam-cell__empty"><?php _e('No children attached', 'azure-plugin'); ?></span>
        <?php endif; ?>
    </div>

    <?php if ($em_name || $em_email || $em_cell):
        $parts = array_filter(array($em_name, $em_email, $em_cell));
    ?>
    <div class="azure-um-fam-emergency">
        <strong><?php _e('Emergency contact:', 'azure-plugin'); ?></strong>
        <?php echo esc_html(implode(' · ', $parts)); ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach;
