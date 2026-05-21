/**
 * Setup Wizard JavaScript
 */
(function($) {
    'use strict';
    
    var wizard = {
        currentStep: 1,
        validations: {
            azure: false,
            backup: false,
            ptaDefaults: false,
            ptaAzure: false
        }
    };
    
    /**
     * Initialize the wizard
     */
    function init() {
        // Get current step from form
        wizard.currentStep = parseInt($('input[name="step"]').val()) || 1;
        
        // Bind event handlers
        bindEvents();
        
        // Initialize step-specific functionality
        initCurrentStep();
    }
    
    /**
     * Bind event handlers
     */
    function bindEvents() {
        // Navigation buttons
        $('#btn-start-wizard, #btn-next').on('click', handleNext);
        $('#btn-prev').on('click', handlePrev);
        $('#btn-finish').on('click', handleFinish);
        $('#skip-wizard').on('click', handleSkip);
        
        // Validation buttons
        $('#btn-validate-azure').on('click', validateAzure);
        $('#btn-validate-backup').on('click', validateBackup);
        
        // PTA action buttons
        $('#btn-import-defaults').on('click', importDefaults);
        $('#btn-pull-azure').on('click', pullAzureAD);
        
        // OneDrive authorization
        $('#btn-authorize-onedrive').on('click', authorizeOneDrive);
        
        // Toggle password visibility
        $('.toggle-password').on('click', togglePassword);
        
        // SSO preview update
        $('#sso_button_text').on('input', updateButtonPreview);
        
        // Role selection toggle
        $('input[name="use_custom_role"]').on('change', toggleRoleSelection);
        
        // Form field validation
        $('input[required]').on('blur', validateField);
    }
    
    /**
     * Initialize current step functionality
     */
    function initCurrentStep() {
        switch (wizard.currentStep) {
            case 4: // Azure - disable next until validated
                updateNextButton(wizard.validations.azure);
                break;
            case 7: // SSO - update button preview
                updateButtonPreview();
                break;
        }
    }
    
    /**
     * Handle next button click
     */
    function handleNext(e) {
        e.preventDefault();
        
        // Validate current step
        if (!validateStep()) {
            return;
        }
        
        // Save current step data
        saveStep(function(response) {
            if (response.success) {
                // Navigate to next step
                var nextStep = response.data.next_step || wizard.currentStep + 1;
                window.location.href = azureWizard.ajaxUrl.replace('admin-ajax.php', 'admin.php') + 
                    '?page=azure-plugin-setup&step=' + nextStep;
            } else {
                showError(response.data.message || azureWizard.strings.error);
            }
        });
    }
    
    /**
     * Handle previous button click
     */
    function handlePrev(e) {
        e.preventDefault();
        
        // Go to previous step without saving
        var prevStep = Math.max(1, wizard.currentStep - 1);
        window.location.href = azureWizard.ajaxUrl.replace('admin-ajax.php', 'admin.php') + 
            '?page=azure-plugin-setup&step=' + prevStep;
    }
    
    /**
     * Handle finish button click
     */
    function handleFinish(e) {
        e.preventDefault();
        
        $.ajax({
            url: azureWizard.ajaxUrl,
            method: 'POST',
            data: {
                action: 'azure_wizard_complete',
                nonce: azureWizard.nonce
            },
            success: function(response) {
                if (response.success) {
                    window.location.href = response.data.redirect || azureWizard.dashboardUrl;
                }
            }
        });
    }
    
    /**
     * Handle skip wizard link
     */
    function handleSkip(e) {
        e.preventDefault();
        
        if (confirm('Are you sure you want to skip the setup wizard? You can configure settings manually in each module.')) {
            $.ajax({
                url: azureWizard.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'azure_wizard_skip',
                    nonce: azureWizard.nonce
                },
                success: function(response) {
                    if (response.success) {
                        window.location.href = response.data.redirect || azureWizard.dashboardUrl;
                    }
                }
            });
        }
    }
    
    /**
     * Validate current step
     */
    function validateStep() {
        var valid = true;
        var $form = $('#wizard-form');
        
        // Check required fields
        $form.find('input[required]:visible').each(function() {
            if (!validateField.call(this)) {
                valid = false;
            }
        });
        
        // Step-specific validation
        switch (wizard.currentStep) {
            case 2: // Organization
                if (!$('#org_domain').val().match(/^[a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.[a-z]{2,}$/i)) {
                    showFieldError($('#org_domain'), azureWizard.strings.invalidDomain);
                    valid = false;
                }
                if (!$('#org_admin_email').val().match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                    showFieldError($('#org_admin_email'), azureWizard.strings.invalidEmail);
                    valid = false;
                }
                break;
                
            case 4: // Azure - require validation
                if (!wizard.validations.azure) {
                    showError('Please validate your Azure connection before continuing.');
                    valid = false;
                }
                break;
        }
        
        return valid;
    }
    
    /**
     * Validate a single field
     */
    function validateField() {
        var $field = $(this);
        var value = $field.val().trim();
        var valid = true;
        
        // Clear previous error
        clearFieldError($field);
        
        // Required check
        if ($field.prop('required') && !value) {
            showFieldError($field, azureWizard.strings.requiredField);
            valid = false;
        }
        
        // Email validation
        if (valid && $field.attr('type') === 'email' && value) {
            if (!value.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                showFieldError($field, azureWizard.strings.invalidEmail);
                valid = false;
            }
        }
        
        return valid;
    }
    
    /**
     * Show field error
     */
    function showFieldError($field, message) {
        $field.addClass('error');
        if (!$field.next('.field-error').length) {
            $field.after('<span class="field-error">' + message + '</span>');
        }
    }
    
    /**
     * Clear field error
     */
    function clearFieldError($field) {
        $field.removeClass('error');
        $field.next('.field-error').remove();
    }
    
    /**
     * Show general error message
     */
    function showError(message) {
        alert(message); // Simple for now, could be improved with a toast/notification
    }
    
    /**
     * Save current step data
     */
    function saveStep(callback) {
        var formData = {
            action: 'azure_wizard_save_step',
            nonce: azureWizard.nonce,
            step: wizard.currentStep,
            data: {}
        };
        
        // Collect form data based on step
        switch (wizard.currentStep) {
            case 2: // Organization
                formData.data = {
                    org_domain: $('#org_domain').val(),
                    org_name: $('#org_name').val(),
                    org_team_name: $('#org_team_name').val(),
                    org_admin_email: $('#org_admin_email').val()
                };
                break;
                
            case 3: // Modules
                formData.data = {
                    modules: $('input[name="modules[]"]:checked').map(function() {
                        return $(this).val();
                    }).get(),
                    use_common_credentials: $('input[name="use_common_credentials"]').is(':checked')
                };
                break;
                
            case 4: // Azure
                formData.data = {
                    client_id: $('#azure_client_id').val(),
                    client_secret: $('#azure_client_secret').val(),
                    tenant_id: $('#azure_tenant_id').val()
                };
                break;
                
            case 5: // Backup
                formData.data = {
                    storage_account: $('#backup_storage_account').val(),
                    container_name: $('#backup_container_name').val(),
                    storage_key: $('#backup_storage_key').val()
                };
                break;
                
            case 7: // SSO
                formData.data = {
                    show_on_login: $('input[name="show_on_login"]').is(':checked'),
                    button_text: $('#sso_button_text').val(),
                    default_role: $('#sso_default_role').val(),
                    use_custom_role: $('input[name="use_custom_role"]:checked').val() === '1',
                    custom_role_name: $('#sso_custom_role').val()
                };
                break;
                
            case 8: // OneDrive
                formData.data = {
                    storage_type: $('input[name="storage_type"]:checked').val(),
                    base_folder: $('#onedrive_base_folder').val(),
                    use_year_folders: $('input[name="use_year_folders"]').is(':checked')
                };
                break;
        }
        
        $.ajax({
            url: azureWizard.ajaxUrl,
            method: 'POST',
            data: formData,
            success: callback,
            error: function() {
                callback({ success: false, data: { message: 'Network error' } });
            }
        });
    }
    
    /**
     * Validate Azure connection
     */
    function validateAzure() {
        var $btn = $('#btn-validate-azure');
        var $result = $('#azure-validation-result');
        
        $btn.prop('disabled', true).text(azureWizard.strings.validating);
        $result.html('<span class="validating"><span class="spinner is-active"></span> ' + azureWizard.strings.validating + '</span>');
        
        $.ajax({
            url: azureWizard.ajaxUrl,
            method: 'POST',
            data: {
                action: 'azure_wizard_validate_azure',
                nonce: azureWizard.nonce,
                client_id: $('#azure_client_id').val(),
                client_secret: $('#azure_client_secret').val(),
                tenant_id: $('#azure_tenant_id').val()
            },
            success: function(response) {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-yes-alt"></span> Validate Connection');
                
                if (response.success) {
                    wizard.validations.azure = true;
                    $result.html('<span class="success"><span class="dashicons dashicons-yes-alt"></span> ' + response.data.message + '</span>');
                    updateNextButton(true);
                } else {
                    wizard.validations.azure = false;
                    var html = '<span class="error"><span class="dashicons dashicons-warning"></span> ' + response.data.message + '</span>';
                    if (response.data.details) {
                        html += '<p class="error-details">' + response.data.details + '</p>';
                    }
                    if (response.data.resolution) {
                        html += '<p class="resolution">' + response.data.resolution + '</p>';
                    }
                    $result.html(html);
                    updateNextButton(false);
                }
            },
            error: function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-yes-alt"></span> Validate Connection');
                $result.html('<span class="error"><span class="dashicons dashicons-warning"></span> Network error. Please try again.</span>');
            }
        });
    }
    
    /**
     * Validate Backup connection
     */
    function validateBackup() {
        var $btn = $('#btn-validate-backup');
        var $result = $('#backup-validation-result');
        
        $btn.prop('disabled', true).text(azureWizard.strings.validating);
        $result.html('<span class="validating"><span class="spinner is-active"></span> ' + azureWizard.strings.validating + '</span>');
        
        $.ajax({
            url: azureWizard.ajaxUrl,
            method: 'POST',
            data: {
                action: 'azure_wizard_validate_backup',
                nonce: azureWizard.nonce,
                storage_account: $('#backup_storage_account').val(),
                container_name: $('#backup_container_name').val(),
                storage_key: $('#backup_storage_key').val()
            },
            success: function(response) {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-yes-alt"></span> Validate Connection');
                
                if (response.success) {
                    wizard.validations.backup = true;
                    $result.html('<span class="success"><span class="dashicons dashicons-yes-alt"></span> ' + response.data.message + '</span>');
                } else {
                    wizard.validations.backup = false;
                    var html = '<span class="error"><span class="dashicons dashicons-warning"></span> ' + response.data.message + '</span>';
                    if (response.data.details) {
                        html += '<p class="error-details">' + response.data.details + '</p>';
                    }
                    $result.html(html);
                }
            },
            error: function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-yes-alt"></span> Validate Connection');
                $result.html('<span class="error"><span class="dashicons dashicons-warning"></span> Network error. Please try again.</span>');
            }
        });
    }
    
    /**
     * Import default PTA tables
     */
    function importDefaults() {
        var $btn = $('#btn-import-defaults');
        var $status = $('#import-defaults-status');
        var $check = $('#check-import-defaults');
        
        $btn.prop('disabled', true).text('Importing...');
        $status.html('<span class="spinner is-active"></span> Importing...');
        
        $.ajax({
            url: azureWizard.ajaxUrl,
            method: 'POST',
            data: {
                action: 'azure_wizard_import_defaults',
                nonce: azureWizard.nonce
            },
            success: function(response) {
                $btn.prop('disabled', false).text('Import Default Tables');
                
                if (response.success) {
                    wizard.validations.ptaDefaults = true;
                    $status.html('<span class="success">' + response.data.message + '</span>');
                    $check.addClass('visible');
                    $('#card-import-defaults').addClass('completed');
                } else {
                    $status.html('<span class="error">' + (response.data.message || 'Import failed') + '</span>');
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Import Default Tables');
                $status.html('<span class="error">Network error. Please try again.</span>');
            }
        });
    }
    
    /**
     * Pull from Azure AD
     */
    function pullAzureAD() {
        var $btn = $('#btn-pull-azure');
        var $status = $('#pull-azure-status');
        var $check = $('#check-pull-azure');
        
        $btn.prop('disabled', true).text('Pulling...');
        $status.html('<span class="spinner is-active"></span> Pulling from Azure AD...');
        
        $.ajax({
            url: azureWizard.ajaxUrl,
            method: 'POST',
            data: {
                action: 'azure_wizard_pull_azure_ad',
                nonce: azureWizard.nonce
            },
            success: function(response) {
                $btn.prop('disabled', false).text('Pull from Azure AD');
                
                if (response.success) {
                    wizard.validations.ptaAzure = true;
                    $status.html('<span class="success">' + response.data.message + '</span>');
                    $check.addClass('visible');
                    $('#card-pull-azure').addClass('completed');
                } else {
                    $status.html('<span class="error">' + (response.data.message || 'Pull failed') + '</span>');
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Pull from Azure AD');
                $status.html('<span class="error">Network error. Please try again.</span>');
            }
        });
    }
    
    /**
     * Authorize OneDrive
     */
    function authorizeOneDrive() {
        // This would typically open a popup for OAuth
        var $status = $('#onedrive-auth-status');
        $status.html('<span class="info">OneDrive authorization will open in a popup window. Please complete the authorization and return here.</span>');
        
        // For now, redirect to the OneDrive module's auth page
        window.open(
            azureWizard.ajaxUrl.replace('admin-ajax.php', 'admin.php') + '?page=azure-plugin-onedrive&action=authorize',
            'onedrive_auth',
            'width=600,height=700'
        );
    }
    
    /**
     * Toggle password visibility
     */
    function togglePassword() {
        var targetId = $(this).data('target');
        var $input = $('#' + targetId);
        var $icon = $(this).find('.dashicons');
        
        if ($input.attr('type') === 'password') {
            $input.attr('type', 'text');
            $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
        } else {
            $input.attr('type', 'password');
            $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
        }
    }
    
    /**
     * Update SSO button preview
     */
    function updateButtonPreview() {
        var text = $('#sso_button_text').val() || 'Sign in with Microsoft';
        $('#preview-button-text').text(text);
    }
    
    /**
     * Toggle role selection inputs
     */
    function toggleRoleSelection() {
        var useCustom = $('input[name="use_custom_role"]:checked').val() === '1';
        
        $('#sso_default_role').toggleClass('disabled', useCustom).prop('disabled', useCustom);
        $('#sso_custom_role').toggleClass('disabled', !useCustom).prop('disabled', !useCustom);
    }
    
    /**
     * Update next button state
     */
    function updateNextButton(enabled) {
        $('#btn-next').prop('disabled', !enabled);
    }
    
    // Initialize on document ready
    $(document).ready(init);
    
})(jQuery);

