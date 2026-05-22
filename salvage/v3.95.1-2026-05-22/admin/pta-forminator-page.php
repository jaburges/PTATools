<?php
/**
 * PTA Forminator Integration - Admin Settings Page
 */

if (!defined('ABSPATH')) {
    exit;
}

$forminator_active = class_exists('Forminator');
$current_form_id   = Azure_Settings::get_setting('pta_forminator_form_id', '');
$role_field         = Azure_Settings::get_setting('pta_forminator_role_field_id', '');
$dept_field         = Azure_Settings::get_setting('pta_forminator_dept_field_id', '');
$fname_field        = Azure_Settings::get_setting('pta_forminator_fname_field_id', '');
$lname_field        = Azure_Settings::get_setting('pta_forminator_lname_field_id', '');
$email_field        = Azure_Settings::get_setting('pta_forminator_email_field_id', '');
$open_roles_only    = Azure_Settings::get_setting('pta_forminator_open_roles_only', true);
?>

<div class="wrap">
    <h1>PTA Roles - Forminator Customization</h1>

    <?php if (!$forminator_active): ?>
        <div class="notice notice-warning">
            <p><strong>Forminator plugin is not active.</strong> Please install and activate <a href="https://wordpress.org/plugins/forminator/" target="_blank">Forminator</a> to use this integration.</p>
        </div>
    <?php endif; ?>

    <div class="card" style="max-width: 800px;">
        <h2>Form Selection</h2>
        <p>Select the Forminator form to use for PTA role signups. Visitors will see this form when they click a role on the org chart.</p>

        <table class="form-table" id="forminator-settings-form">
            <tr>
                <th><label for="pta-form-select">Signup Form</label></th>
                <td>
                    <select id="pta-form-select" <?php echo !$forminator_active ? 'disabled' : ''; ?>>
                        <option value="">-- Select a Forminator Form --</option>
                    </select>
                    <p class="description">Choose the form you created in Forminator for role signups.</p>
                </td>
            </tr>
        </table>
    </div>

    <div class="card" style="max-width: 800px; margin-top: 20px;">
        <h2>Field Mapping</h2>
        <p>Map Forminator form field IDs to PTA data. Enter the Forminator field element ID (e.g., <code>text-1</code>, <code>email-1</code>, <code>select-1</code>) for each piece of data you want to pre-populate.</p>
        <p class="description">To find field IDs: Open your form in Forminator &rarr; click a field &rarr; look at the "Field ID" or "Element ID" in the field settings sidebar.</p>

        <table class="form-table">
            <tr>
                <th><label for="pta-field-role">Role Field ID</label></th>
                <td>
                    <input type="text" id="pta-field-role" value="<?php echo esc_attr($role_field); ?>" class="regular-text" placeholder="e.g. select-1 or text-1">
                    <p class="description">The field where the selected PTA role name will be pre-filled.</p>
                </td>
            </tr>
            <tr>
                <th><label for="pta-field-dept">Department Field ID</label></th>
                <td>
                    <input type="text" id="pta-field-dept" value="<?php echo esc_attr($dept_field); ?>" class="regular-text" placeholder="e.g. text-2">
                    <p class="description">The field where the department name will be pre-filled.</p>
                </td>
            </tr>
            <tr>
                <th><label for="pta-field-fname">First Name Field ID</label></th>
                <td>
                    <input type="text" id="pta-field-fname" value="<?php echo esc_attr($fname_field); ?>" class="regular-text" placeholder="e.g. name-1-first-name">
                    <p class="description">Pre-filled from the logged-in user's first name.</p>
                </td>
            </tr>
            <tr>
                <th><label for="pta-field-lname">Last Name Field ID</label></th>
                <td>
                    <input type="text" id="pta-field-lname" value="<?php echo esc_attr($lname_field); ?>" class="regular-text" placeholder="e.g. name-1-last-name">
                    <p class="description">Pre-filled from the logged-in user's last name.</p>
                </td>
            </tr>
            <tr>
                <th><label for="pta-field-email">Email Field ID</label></th>
                <td>
                    <input type="text" id="pta-field-email" value="<?php echo esc_attr($email_field); ?>" class="regular-text" placeholder="e.g. email-1">
                    <p class="description">Pre-filled from the logged-in user's email address.</p>
                </td>
            </tr>
        </table>
    </div>

    <div class="card" style="max-width: 800px; margin-top: 20px;">
        <h2>Behavior</h2>
        <table class="form-table">
            <tr>
                <th><label for="pta-open-roles-only">Show Signup on Open Roles Only</label></th>
                <td>
                    <label>
                        <input type="checkbox" id="pta-open-roles-only" <?php checked($open_roles_only); ?>>
                        Only show the signup button on roles that have unfilled positions.
                    </label>
                </td>
            </tr>
        </table>
    </div>

    <p class="submit" style="max-width: 800px;">
        <button type="button" id="save-forminator-settings" class="button button-primary" <?php echo !$forminator_active ? 'disabled' : ''; ?>>Save Settings</button>
    </p>
</div>

<script>
jQuery(document).ready(function($) {
    // Load available Forminator forms
    <?php if ($forminator_active): ?>
    $.post(azure_plugin_ajax.ajax_url, {
        action: 'pta_get_forminator_forms',
        nonce: azure_plugin_ajax.nonce
    }, function(response) {
        if (response.success && response.data) {
            var select = $('#pta-form-select');
            response.data.forEach(function(form) {
                var selected = (form.id == '<?php echo esc_js($current_form_id); ?>') ? 'selected' : '';
                select.append('<option value="' + form.id + '" ' + selected + '>' + form.name + ' (ID: ' + form.id + ')</option>');
            });
        }
    });
    <?php endif; ?>

    // Save settings
    $('#save-forminator-settings').on('click', function() {
        var btn = $(this);
        btn.prop('disabled', true).text('Saving...');

        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_save_forminator_settings',
            nonce: azure_plugin_ajax.nonce,
            pta_forminator_form_id: $('#pta-form-select').val(),
            pta_forminator_role_field_id: $('#pta-field-role').val(),
            pta_forminator_dept_field_id: $('#pta-field-dept').val(),
            pta_forminator_fname_field_id: $('#pta-field-fname').val(),
            pta_forminator_lname_field_id: $('#pta-field-lname').val(),
            pta_forminator_email_field_id: $('#pta-field-email').val(),
            pta_forminator_open_roles_only: $('#pta-open-roles-only').is(':checked') ? 1 : 0
        }, function(response) {
            btn.prop('disabled', false).text('Save Settings');
            if (response.success) {
                alert('Settings saved successfully!');
            } else {
                alert('Failed to save: ' + (response.data || 'Unknown error'));
            }
        }).fail(function() {
            btn.prop('disabled', false).text('Save Settings');
            alert('Request failed. Please try again.');
        });
    });
});
</script>
