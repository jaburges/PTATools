<?php
/**
 * System → Classes
 *
 * Headcount per teacher. Other modules (class competitions, the
 * membership dashboard) read this list; they do not keep their own copy.
 */
if (!defined('ABSPATH')) {
    exit;
}

$notice = '';
if (!empty($_POST['azure_save_class_sizes'])) {
    check_admin_referer('azure_save_class_sizes');
    if (current_user_can('manage_options') && class_exists('Azure_Class_Competitions') && class_exists('Azure_Settings')) {
        $raw = isset($_POST['class_size']) && is_array($_POST['class_size']) ? wp_unslash($_POST['class_size']) : array();
        Azure_Settings::update_setting(
            Azure_Class_Competitions::SIZES_KEY,
            Azure_Class_Competitions::sanitize_class_sizes($raw, Azure_Class_Competitions::teacher_list())
        );
        $notice = __('Class sizes saved.', 'azure-plugin');
    }
}

$teachers = class_exists('Azure_Class_Competitions') ? Azure_Class_Competitions::teacher_list() : array();
$sizes = class_exists('Azure_Class_Competitions') ? Azure_Class_Competitions::get_class_sizes() : array();
$total = class_exists('Azure_Class_Competitions') ? Azure_Class_Competitions::student_total($sizes) : 0;
$teacher_fields_url = admin_url('admin.php?page=azure-plugin-selling&tab=product-fields');
?>

<?php if ($notice !== ''): ?>
    <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
<?php endif; ?>

<div style="background:#fff; border:1px solid #ccd0d4; padding:20px; margin:16px 0; max-width:920px;">
    <h2 style="margin:0 0 8px;">
        <span class="dashicons dashicons-groups"></span>
        <?php esc_html_e('Class sizes', 'azure-plugin'); ?>
    </h2>
    <p class="description" style="margin:0 0 12px;">
        <?php esc_html_e('Teacher names come from Child Info (the teacher dropdown). Set the number of students in each class here. The membership dashboard and class competition percentages both use this total.', 'azure-plugin'); ?>
        <a href="<?php echo esc_url($teacher_fields_url); ?>"><?php esc_html_e('Edit teacher list', 'azure-plugin'); ?></a>
    </p>
    <p style="margin:0 0 12px;">
        <strong><?php esc_html_e('Students', 'azure-plugin'); ?>:</strong>
        <?php echo esc_html(number_format_i18n($total)); ?>
    </p>

    <?php if (empty($teachers)): ?>
        <p><?php esc_html_e('No teachers yet. Add them as dropdown options on the Child Teacher field in Product Fields.', 'azure-plugin'); ?></p>
    <?php else: ?>
        <form method="post">
            <?php wp_nonce_field('azure_save_class_sizes'); ?>
            <input type="hidden" name="azure_save_class_sizes" value="1" />
            <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:8px 16px; max-height:520px; overflow:auto; border:1px solid #dcdcde; padding:12px; background:#f6f7f7;">
                <?php foreach ($teachers as $teacher): ?>
                    <label style="display:flex; align-items:center; justify-content:space-between; gap:8px; background:#fff; border:1px solid #dcdcde; padding:6px 10px;">
                        <span style="min-width:0; overflow:hidden; text-overflow:ellipsis;"><?php echo esc_html($teacher); ?></span>
                        <input type="number" class="small-text" min="0" max="500" step="1"
                               name="class_size[<?php echo esc_attr($teacher); ?>]"
                               value="<?php echo isset($sizes[$teacher]) ? (int) $sizes[$teacher] : 0; ?>"
                               style="width:72px;" />
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="description" style="margin:10px 0 12px;"><?php esc_html_e('Leave 0 if you do not know the size yet. A competition percentage stays blank for that class until the size is greater than zero.', 'azure-plugin'); ?></p>
            <button type="submit" class="button button-primary"><?php esc_html_e('Save class sizes', 'azure-plugin'); ?></button>
        </form>
    <?php endif; ?>
</div>
