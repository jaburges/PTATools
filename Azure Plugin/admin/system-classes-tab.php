<?php
/**
 * System → Classes
 *
 * Teacher, grade, and student count. Other modules read this roster.
 * The Child Teacher dropdown is filled from it.
 */
if (!defined('ABSPATH')) {
    exit;
}

$notice = '';
if (!empty($_POST['azure_save_class_roster'])) {
    check_admin_referer('azure_save_class_roster');
    if (current_user_can('manage_options') && class_exists('Azure_Class_Competitions') && class_exists('Azure_Settings')) {
        $raw = isset($_POST['class_roster']) && is_array($_POST['class_roster']) ? wp_unslash($_POST['class_roster']) : array();
        Azure_Class_Competitions::save_roster($raw);
        $notice = __('Classes saved. The Child Teacher dropdown now uses this list.', 'azure-plugin');
    }
}

$roster = class_exists('Azure_Class_Competitions') ? Azure_Class_Competitions::get_roster() : array();
$sizes = array();
foreach ($roster as $row) {
    $sizes[$row['name']] = (int) $row['students'];
}
$total = class_exists('Azure_Class_Competitions') ? Azure_Class_Competitions::student_total($sizes) : 0;
?>

<?php if ($notice !== ''): ?>
    <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
<?php endif; ?>

<div style="background:#fff; border:1px solid #ccd0d4; padding:20px; margin:16px 0; max-width:920px;">
    <h2 style="margin:0 0 8px;">
        <span class="dashicons dashicons-groups"></span>
        <?php esc_html_e('Classes', 'azure-plugin'); ?>
    </h2>
    <p class="description" style="margin:0 0 12px;">
        <?php esc_html_e('This is the teacher list. Each row is a teacher, the grade they teach, and how many students are in the class. A mixed class is written 4/5. Saving here updates the Child Teacher dropdown.', 'azure-plugin'); ?>
    </p>
    <p style="margin:0 0 12px;">
        <strong><?php esc_html_e('Students', 'azure-plugin'); ?>:</strong>
        <?php echo esc_html(number_format_i18n($total)); ?>
    </p>

    <form method="post" id="azure-class-roster-form">
        <?php wp_nonce_field('azure_save_class_roster'); ?>
        <input type="hidden" name="azure_save_class_roster" value="1" />
        <table class="widefat striped" style="max-width:720px;">
            <thead>
                <tr>
                    <th><?php esc_html_e('Teacher', 'azure-plugin'); ?></th>
                    <th><?php esc_html_e('Grade', 'azure-plugin'); ?></th>
                    <th><?php esc_html_e('Students', 'azure-plugin'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="azure-class-roster-rows">
                <?php
                $rows = $roster ? $roster : array(array('name' => '', 'grade' => '', 'students' => 0));
                foreach ($rows as $i => $row):
                ?>
                    <tr class="azure-class-roster-row">
                        <td><input type="text" class="regular-text" name="class_roster[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr($row['name']); ?>" /></td>
                        <td><input type="text" class="small-text" name="class_roster[<?php echo (int) $i; ?>][grade]" value="<?php echo esc_attr($row['grade']); ?>" placeholder="<?php esc_attr_e('4 or 4/5', 'azure-plugin'); ?>" /></td>
                        <td><input type="number" class="small-text" min="0" max="500" step="1" name="class_roster[<?php echo (int) $i; ?>][students]" value="<?php echo (int) $row['students']; ?>" /></td>
                        <td><button type="button" class="button-link-delete azure-class-roster-remove"><?php esc_html_e('Remove', 'azure-plugin'); ?></button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin:12px 0;">
            <button type="button" class="button" id="azure-class-roster-add"><?php esc_html_e('Add teacher', 'azure-plugin'); ?></button>
        </p>
        <p class="description" style="margin:0 0 12px;"><?php esc_html_e('Leave students at 0 if you do not know the size yet. A competition percentage stays blank for that class until the size is greater than zero. Leave grade blank until you know it.', 'azure-plugin'); ?></p>
        <button type="submit" class="button button-primary"><?php esc_html_e('Save classes', 'azure-plugin'); ?></button>
    </form>
</div>
<script>
(function () {
    var body = document.getElementById('azure-class-roster-rows');
    var add = document.getElementById('azure-class-roster-add');
    if (!body || !add) return;
    function reindex() {
        var rows = body.querySelectorAll('.azure-class-roster-row');
        rows.forEach(function (row, i) {
            row.querySelectorAll('input').forEach(function (input) {
                input.name = input.name.replace(/class_roster\[\d+\]/, 'class_roster[' + i + ']');
            });
        });
    }
    add.addEventListener('click', function () {
        var rows = body.querySelectorAll('.azure-class-roster-row');
        var last = rows[rows.length - 1];
        var next = last.cloneNode(true);
        next.querySelectorAll('input').forEach(function (input) {
            if (input.type === 'number') input.value = '0';
            else input.value = '';
        });
        body.appendChild(next);
        reindex();
    });
    body.addEventListener('click', function (event) {
        var button = event.target.closest('.azure-class-roster-remove');
        if (!button) return;
        var rows = body.querySelectorAll('.azure-class-roster-row');
        if (rows.length === 1) {
            rows[0].querySelectorAll('input').forEach(function (input) {
                if (input.type === 'number') input.value = '0';
                else input.value = '';
            });
            return;
        }
        button.closest('tr').remove();
        reindex();
    });
})();
</script>
