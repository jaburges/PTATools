<?php
/**
 * Setup Wizard Page
 */

if (!defined('ABSPATH')) {
    exit;
}

$wizard = Azure_Setup_Wizard::get_instance();
$current_step = $wizard->get_current_step();
$active_steps = $wizard->get_active_steps();
$progress = $wizard->get_progress_percent();

// Get saved settings
$settings = Azure_Settings::get_all_settings();
$modules = Azure_Settings::get_setting('setup_wizard_modules', array());

// Get WordPress roles for SSO step
$wp_roles = wp_roles();
$available_roles = $wp_roles->get_names();
?>

<div class="wrap azure-setup-wizard">
    <div class="wizard-container">
        
        <!-- Progress Bar -->
        <div class="wizard-progress">
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo esc_attr($progress); ?>%"></div>
            </div>
            <div class="progress-steps">
                <?php foreach ($active_steps as $index => $step_num): 
                    $step_info = $wizard->steps[$step_num] ?? array('title' => 'Step ' . $step_num);
                    $is_current = $step_num == $current_step;
                    $is_completed = $step_num < $current_step;
                ?>
                <div class="progress-step <?php echo $is_current ? 'current' : ''; ?> <?php echo $is_completed ? 'completed' : ''; ?>">
                    <span class="step-number"><?php echo $is_completed ? '✓' : ($index + 1); ?></span>
                    <span class="step-title"><?php echo esc_html($step_info['title']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Wizard Content -->
        <div class="wizard-content">
            <form id="wizard-form" method="post">
                <input type="hidden" name="step" value="<?php echo esc_attr($current_step); ?>">
                <?php wp_nonce_field('azure_setup_wizard', 'wizard_nonce'); ?>
                
                <?php
                // Render the appropriate step
                switch ($current_step):
                    case 1: // Welcome
                ?>
                <!-- Step 1: Welcome -->
                <div class="wizard-step step-welcome active">
                    <div class="step-header">
                        <div class="plugin-logo">
                            <span class="dashicons dashicons-admin-plugins" style="font-size: 64px; width: 64px; height: 64px; color: #0078d4;"></span>
                        </div>
                        <h1><?php _e('Welcome to PTA Tools', 'azure-plugin'); ?></h1>
                        <p class="lead"><?php _e('Integrate your WordPress site with Microsoft Azure and Office 365 services.', 'azure-plugin'); ?></p>
                    </div>
                    
                    <div class="feature-grid">
                        <div class="feature-item">
                            <span class="dashicons dashicons-lock"></span>
                            <h3><?php _e('Single Sign-On', 'azure-plugin'); ?></h3>
                            <p><?php _e('Allow users to sign in with their Microsoft accounts', 'azure-plugin'); ?></p>
                        </div>
                        <div class="feature-item">
                            <span class="dashicons dashicons-calendar-alt"></span>
                            <h3><?php _e('Calendar Integration', 'azure-plugin'); ?></h3>
                            <p><?php _e('Embed and sync Outlook calendars on your site', 'azure-plugin'); ?></p>
                        </div>
                        <div class="feature-item">
                            <span class="dashicons dashicons-email-alt"></span>
                            <h3><?php _e('Newsletter', 'azure-plugin'); ?></h3>
                            <p><?php _e('Create and send beautiful email newsletters', 'azure-plugin'); ?></p>
                        </div>
                        <div class="feature-item">
                            <span class="dashicons dashicons-backup"></span>
                            <h3><?php _e('Cloud Backup', 'azure-plugin'); ?></h3>
                            <p><?php _e('Backup your site to Azure Blob Storage', 'azure-plugin'); ?></p>
                        </div>
                        <div class="feature-item">
                            <span class="dashicons dashicons-groups"></span>
                            <h3><?php _e('PTA Roles', 'azure-plugin'); ?></h3>
                            <p><?php _e('Manage volunteer roles and O365 accounts', 'azure-plugin'); ?></p>
                        </div>
                        <div class="feature-item">
                            <span class="dashicons dashicons-cloud-upload"></span>
                            <h3><?php _e('OneDrive Media', 'azure-plugin'); ?></h3>
                            <p><?php _e('Use OneDrive/SharePoint for media storage', 'azure-plugin'); ?></p>
                        </div>
                    </div>
                    
                    <p class="description"><?php _e('This wizard will help you configure the essential settings to get started. You can always adjust settings later in each module.', 'azure-plugin'); ?></p>
                    
                    <div class="step-actions">
                        <button type="button" class="button button-hero button-primary" id="btn-start-wizard">
                            <?php _e('Get Started', 'azure-plugin'); ?>
                            <span class="dashicons dashicons-arrow-right-alt"></span>
                        </button>
                        <p style="margin-top:12px;">
                            <a href="<?php echo admin_url('admin.php?page=azure-plugin-restore'); ?>" class="button" style="margin-right:8px;">
                                <span class="dashicons dashicons-backup" style="margin-right:4px;"></span>
                                <?php _e('Restore from Backup', 'azure-plugin'); ?>
                            </a>
                        </p>
                        <p><a href="#" id="skip-wizard" class="skip-link"><?php _e('Skip wizard and configure manually', 'azure-plugin'); ?></a></p>
                    </div>
                </div>
                
                <?php break; case 2: // Organization Info ?>
                <!-- Step 2: Organization Information -->
                <div class="wizard-step step-organization active">
                    <div class="step-header">
                        <h1><?php _e('Organization Information', 'azure-plugin'); ?></h1>
                        <p class="lead"><?php _e('Tell us about your organization. This information is used throughout the plugin.', 'azure-plugin'); ?></p>
                    </div>
                    
                    <div class="form-section">
                        <div class="form-row">
                            <label for="org_domain">
                                <?php _e('Organization Domain', 'azure-plugin'); ?>
                                <span class="required">*</span>
                            </label>
                            <input type="text" 
                                   id="org_domain" 
                                   name="org_domain" 
                                   value="<?php echo esc_attr($settings['org_domain'] ?? ''); ?>" 
                                   placeholder="yourptsa.net"
                                   class="regular-text"
                                   required>
                            <p class="description"><?php _e('Your organization\'s email domain (e.g., yourptsa.net). Used for creating user accounts.', 'azure-plugin'); ?></p>
                        </div>
                        
                        <div class="form-row">
                            <label for="org_name">
                                <?php _e('Organization Name', 'azure-plugin'); ?>
                                <span class="required">*</span>
                            </label>
                            <input type="text" 
                                   id="org_name" 
                                   name="org_name" 
                                   value="<?php echo esc_attr($settings['org_name'] ?? ''); ?>" 
                                   placeholder="<?php esc_attr_e('Maple Elementary PTSA', 'azure-plugin'); ?>"
                                   class="regular-text"
                                   required>
                            <p class="description"><?php _e('What you call yourselves (e.g., "Maple Elementary PTSA", "Lakeside PTA")', 'azure-plugin'); ?></p>
                        </div>
                        
                        <div class="form-row">
                            <label for="org_team_name">
                                <?php _e('Team Name', 'azure-plugin'); ?>
                            </label>
                            <input type="text" 
                                   id="org_team_name" 
                                   name="org_team_name" 
                                   value="<?php echo esc_attr($settings['org_team_name'] ?? ''); ?>" 
                                   placeholder="<?php esc_attr_e('Maple PTSA Team', 'azure-plugin'); ?>"
                                   class="regular-text">
                            <p class="description"><?php _e('How you sign off emails (e.g., "Maple PTSA Team", "Your PTA Administration"). Defaults to Organization Name + "Administration" if left blank.', 'azure-plugin'); ?></p>
                        </div>
                        
                        <div class="form-row">
                            <label for="org_admin_email">
                                <?php _e('Admin Email Address', 'azure-plugin'); ?>
                                <span class="required">*</span>
                            </label>
                            <input type="email" 
                                   id="org_admin_email" 
                                   name="org_admin_email" 
                                   value="<?php echo esc_attr($settings['org_admin_email'] ?? ''); ?>" 
                                   placeholder="admin@yourptsa.net"
                                   class="regular-text"
                                   required>
                            <p class="description"><?php _e('Email address used as the "From" address for system emails (welcome emails, etc.).', 'azure-plugin'); ?></p>
                        </div>
                    </div>
                    
                    <div class="step-actions">
                        <button type="button" class="button button-secondary" id="btn-prev">
                            <span class="dashicons dashicons-arrow-left-alt"></span>
                            <?php _e('Back', 'azure-plugin'); ?>
                        </button>
                        <button type="button" class="button button-primary" id="btn-next">
                            <?php _e('Continue', 'azure-plugin'); ?>
                            <span class="dashicons dashicons-arrow-right-alt"></span>
                        </button>
                    </div>
                </div>
                
                <?php break; case 3: // Module Selection ?>
                <!-- Step 3: Module Selection -->
                <div class="wizard-step step-modules active">
                    <div class="step-header">
                        <h1><?php _e('Select Modules', 'azure-plugin'); ?></h1>
                        <p class="lead"><?php _e('Choose which features you want to enable. You can always change this later.', 'azure-plugin'); ?></p>
                    </div>
                    
                    <div class="modules-grid">
                        <label class="module-card">
                            <input type="checkbox" name="modules[]" value="sso" <?php checked(in_array('sso', $modules) || ($settings['enable_sso'] ?? false)); ?>>
                            <div class="module-content">
                                <span class="dashicons dashicons-lock"></span>
                                <h3><?php _e('Single Sign-On (SSO)', 'azure-plugin'); ?></h3>
                                <p><?php _e('Allow users to sign in with Microsoft accounts', 'azure-plugin'); ?></p>
                            </div>
                        </label>
                        
                        <label class="module-card">
                            <input type="checkbox" name="modules[]" value="calendar" <?php checked(in_array('calendar', $modules) || ($settings['enable_calendar'] ?? false)); ?>>
                            <div class="module-content">
                                <span class="dashicons dashicons-calendar-alt"></span>
                                <h3><?php _e('Calendar Integration', 'azure-plugin'); ?></h3>
                                <p><?php _e('Embed Outlook calendars and sync with TEC', 'azure-plugin'); ?></p>
                            </div>
                        </label>
                        
                        <label class="module-card">
                            <input type="checkbox" name="modules[]" value="newsletter" <?php checked(in_array('newsletter', $modules) || ($settings['enable_newsletter'] ?? false)); ?>>
                            <div class="module-content">
                                <span class="dashicons dashicons-email-alt"></span>
                                <h3><?php _e('Newsletter', 'azure-plugin'); ?></h3>
                                <p><?php _e('Create and send email newsletters', 'azure-plugin'); ?></p>
                            </div>
                        </label>
                        
                        <label class="module-card">
                            <input type="checkbox" name="modules[]" value="backup" <?php checked(in_array('backup', $modules) || ($settings['enable_backup'] ?? false)); ?>>
                            <div class="module-content">
                                <span class="dashicons dashicons-backup"></span>
                                <h3><?php _e('Cloud Backup', 'azure-plugin'); ?></h3>
                                <p><?php _e('Backup site to Azure Blob Storage', 'azure-plugin'); ?></p>
                            </div>
                        </label>
                        
                        <label class="module-card">
                            <input type="checkbox" name="modules[]" value="pta" <?php checked(in_array('pta', $modules) || ($settings['enable_pta'] ?? false)); ?>>
                            <div class="module-content">
                                <span class="dashicons dashicons-groups"></span>
                                <h3><?php _e('PTA Roles', 'azure-plugin'); ?></h3>
                                <p><?php _e('Manage roles, departments, and O365 accounts', 'azure-plugin'); ?></p>
                            </div>
                        </label>
                        
                        <label class="module-card">
                            <input type="checkbox" name="modules[]" value="onedrive" <?php checked(in_array('onedrive', $modules) || ($settings['enable_onedrive_media'] ?? false)); ?>>
                            <div class="module-content">
                                <span class="dashicons dashicons-cloud-upload"></span>
                                <h3><?php _e('OneDrive Media', 'azure-plugin'); ?></h3>
                                <p><?php _e('Use OneDrive/SharePoint for media', 'azure-plugin'); ?></p>
                            </div>
                        </label>
                    </div>
                    
                    <div class="form-section credentials-option">
                        <label class="checkbox-label">
                            <input type="checkbox" name="use_common_credentials" value="1" <?php checked($settings['use_common_credentials'] ?? true); ?>>
                            <strong><?php _e('Use a single Azure App Registration for all modules', 'azure-plugin'); ?></strong>
                        </label>
                        <p class="description"><?php _e('Recommended: Register one Azure app with all required permissions. Uncheck to configure separate credentials for each module.', 'azure-plugin'); ?></p>
                    </div>
                    
                    <div class="step-actions">
                        <button type="button" class="button button-secondary" id="btn-prev">
                            <span class="dashicons dashicons-arrow-left-alt"></span>
                            <?php _e('Back', 'azure-plugin'); ?>
                        </button>
                        <button type="button" class="button button-primary" id="btn-next">
                            <?php _e('Continue', 'azure-plugin'); ?>
                            <span class="dashicons dashicons-arrow-right-alt"></span>
                        </button>
                    </div>
                </div>
                
                <?php break; case 4: // Azure App Registration ?>
                <!-- Step 4: Azure App Registration -->
                <div class="wizard-step step-azure active">
                    <div class="step-header">
                        <h1><?php _e('Azure App Registration', 'azure-plugin'); ?></h1>
                        <p class="lead"><?php _e('Connect your site to Microsoft Azure. Watch the video below for guidance.', 'azure-plugin'); ?></p>
                    </div>
                    
                    <div class="video-placeholder">
                        <div class="video-frame">
                            <span class="dashicons dashicons-video-alt3"></span>
                            <p><?php _e('Tutorial Video: How to Register an Azure App', 'azure-plugin'); ?></p>
                            <p class="small"><?php _e('Video placeholder - Coming soon', 'azure-plugin'); ?></p>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="form-row">
                            <label for="azure_client_id">
                                <?php _e('Application (Client) ID', 'azure-plugin'); ?>
                                <span class="required">*</span>
                            </label>
                            <input type="text" 
                                   id="azure_client_id" 
                                   name="client_id" 
                                   value="<?php echo esc_attr($settings['common_client_id'] ?? ''); ?>" 
                                   placeholder="12345678-1234-1234-1234-123456789abc"
                                   class="regular-text"
                                   required>
                        </div>
                        
                        <div class="form-row">
                            <label for="azure_client_secret">
                                <?php _e('Client Secret', 'azure-plugin'); ?>
                                <span class="required">*</span>
                            </label>
                            <input type="password" 
                                   id="azure_client_secret" 
                                   name="client_secret" 
                                   value="<?php echo esc_attr($settings['common_client_secret'] ?? ''); ?>" 
                                   placeholder="Enter your client secret"
                                   class="regular-text"
                                   required>
                            <button type="button" class="button button-small toggle-password" data-target="azure_client_secret">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>
                        </div>
                        
                        <div class="form-row">
                            <label for="azure_tenant_id">
                                <?php _e('Directory (Tenant) ID', 'azure-plugin'); ?>
                                <span class="required">*</span>
                            </label>
                            <input type="text" 
                                   id="azure_tenant_id" 
                                   name="tenant_id" 
                                   value="<?php echo esc_attr($settings['common_tenant_id'] ?? ''); ?>" 
                                   placeholder="12345678-1234-1234-1234-123456789abc"
                                   class="regular-text"
                                   required>
                        </div>
                        
                        <div class="validation-section">
                            <button type="button" class="button button-secondary" id="btn-validate-azure">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <?php _e('Validate Connection', 'azure-plugin'); ?>
                            </button>
                            <div id="azure-validation-result" class="validation-result"></div>
                        </div>
                    </div>
                    
                    <div class="info-box">
                        <h4><?php _e('Required API Permissions', 'azure-plugin'); ?></h4>
                        <p><?php _e('Ensure your Azure app has these permissions (based on selected modules):', 'azure-plugin'); ?></p>
                        <ul class="permissions-list">
                            <li><code>User.Read</code> - <?php _e('Basic sign-in', 'azure-plugin'); ?></li>
                            <li><code>User.Read.All</code> - <?php _e('Read all users (for PTA sync)', 'azure-plugin'); ?></li>
                            <li><code>Calendars.Read</code> - <?php _e('Read calendars', 'azure-plugin'); ?></li>
                            <li><code>Mail.Send</code> - <?php _e('Send emails', 'azure-plugin'); ?></li>
                            <li><code>Group.ReadWrite.All</code> - <?php _e('Manage groups (for PTA)', 'azure-plugin'); ?></li>
                            <li><code>Files.ReadWrite.All</code> - <?php _e('OneDrive access', 'azure-plugin'); ?></li>
                        </ul>
                    </div>
                    
                    <div class="step-actions">
                        <button type="button" class="button button-secondary" id="btn-prev">
                            <span class="dashicons dashicons-arrow-left-alt"></span>
                            <?php _e('Back', 'azure-plugin'); ?>
                        </button>
                        <button type="button" class="button button-primary" id="btn-next" disabled>
                            <?php _e('Continue', 'azure-plugin'); ?>
                            <span class="dashicons dashicons-arrow-right-alt"></span>
                        </button>
                    </div>
                </div>
                
                <?php break; case 5: // Backup Setup ?>
                <!-- Step 5: Backup Setup -->
                <div class="wizard-step step-backup active">
                    <div class="step-header">
                        <h1><?php _e('Backup Storage Setup', 'azure-plugin'); ?></h1>
                        <p class="lead"><?php _e('Configure Azure Blob Storage for site backups.', 'azure-plugin'); ?></p>
                    </div>
                    
                    <div class="video-placeholder">
                        <div class="video-frame">
                            <span class="dashicons dashicons-video-alt3"></span>
                            <p><?php _e('Tutorial Video: Setting up Azure Blob Storage', 'azure-plugin'); ?></p>
                            <p class="small"><?php _e('Video placeholder - Coming soon', 'azure-plugin'); ?></p>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="form-row">
                            <label for="backup_storage_account">
                                <?php _e('Storage Account Name', 'azure-plugin'); ?>
                                <span class="required">*</span>
                            </label>
                            <input type="text" 
                                   id="backup_storage_account" 
                                   name="storage_account" 
                                   value="<?php echo esc_attr($settings['backup_storage_account_name'] ?? ''); ?>" 
                                   placeholder="mystorageaccount"
                                   class="regular-text"
                                   required>
                        </div>
                        
                        <div class="form-row">
                            <label for="backup_container_name">
                                <?php _e('Container Name', 'azure-plugin'); ?>
                                <span class="required">*</span>
                            </label>
                            <input type="text" 
                                   id="backup_container_name" 
                                   name="container_name" 
                                   value="<?php echo esc_attr($settings['backup_storage_container_name'] ?? 'wordpress-backups'); ?>" 
                                   placeholder="wordpress-backups"
                                   class="regular-text"
                                   required>
                        </div>
                        
                        <div class="form-row">
                            <label for="backup_storage_key">
                                <?php _e('Access Key', 'azure-plugin'); ?>
                                <span class="required">*</span>
                            </label>
                            <input type="password" 
                                   id="backup_storage_key" 
                                   name="storage_key" 
                                   value="<?php echo esc_attr($settings['backup_storage_account_key'] ?? ''); ?>" 
                                   placeholder="Enter your storage access key"
                                   class="regular-text"
                                   required>
                            <button type="button" class="button button-small toggle-password" data-target="backup_storage_key">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>
                        </div>
                        
                        <div class="validation-section">
                            <button type="button" class="button button-secondary" id="btn-validate-backup">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <?php _e('Validate Connection', 'azure-plugin'); ?>
                            </button>
                            <div id="backup-validation-result" class="validation-result"></div>
                        </div>
                    </div>
                    
                    <div class="step-actions">
                        <button type="button" class="button button-secondary" id="btn-prev">
                            <span class="dashicons dashicons-arrow-left-alt"></span>
                            <?php _e('Back', 'azure-plugin'); ?>
                        </button>
                        <button type="button" class="button button-primary" id="btn-next">
                            <?php _e('Continue', 'azure-plugin'); ?>
                            <span class="dashicons dashicons-arrow-right-alt"></span>
                        </button>
                    </div>
                </div>
                
                <?php break; case 6: // PTA Roles Setup ?>
                <!-- Step 6: PTA Roles Setup -->
                <div class="wizard-step step-pta active">
                    <div class="step-header">
                        <h1><?php _e('PTA Roles Setup', 'azure-plugin'); ?></h1>
                        <p class="lead"><?php _e('Initialize the PTA Roles module with default data.', 'azure-plugin'); ?></p>
                    </div>
                    
                    <div class="video-placeholder">
                        <div class="video-frame">
                            <span class="dashicons dashicons-video-alt3"></span>
                            <p><?php _e('Tutorial Video: Understanding Roles, Departments & O365 Groups', 'azure-plugin'); ?></p>
                            <p class="small"><?php _e('Video placeholder - Coming soon', 'azure-plugin'); ?></p>
                        </div>
                    </div>
                    
                    <div class="action-cards">
                        <div class="action-card" id="card-import-defaults">
                            <div class="action-icon">
                                <span class="dashicons dashicons-database-import"></span>
                            </div>
                            <div class="action-content">
                                <h3><?php _e('Import Default Tables', 'azure-plugin'); ?></h3>
                                <p><?php _e('Load the default roles and departments structure. This creates the database tables and populates them with a standard PTA organization structure.', 'azure-plugin'); ?></p>
                                <button type="button" class="button button-secondary" id="btn-import-defaults">
                                    <?php _e('Import Default Tables', 'azure-plugin'); ?>
                                </button>
                                <div class="action-status" id="import-defaults-status"></div>
                            </div>
                            <div class="action-check" id="check-import-defaults">
                                <span class="dashicons dashicons-yes-alt"></span>
                            </div>
                        </div>
                        
                        <div class="action-card" id="card-pull-azure">
                            <div class="action-icon">
                                <span class="dashicons dashicons-cloud-saved"></span>
                            </div>
                            <div class="action-content">
                                <h3><?php _e('One-Time Pull from Azure AD', 'azure-plugin'); ?></h3>
                                <p><?php _e('Import existing users and groups from your Azure Active Directory. This syncs your current O365 users with the plugin.', 'azure-plugin'); ?></p>
                                <button type="button" class="button button-secondary" id="btn-pull-azure">
                                    <?php _e('Pull from Azure AD', 'azure-plugin'); ?>
                                </button>
                                <div class="action-status" id="pull-azure-status"></div>
                            </div>
                            <div class="action-check" id="check-pull-azure">
                                <span class="dashicons dashicons-yes-alt"></span>
                            </div>
                        </div>
                    </div>
                    
                    <p class="description"><?php _e('Complete both steps above to fully initialize the PTA Roles module.', 'azure-plugin'); ?></p>
                    
                    <div class="step-actions">
                        <button type="button" class="button button-secondary" id="btn-prev">
                            <span class="dashicons dashicons-arrow-left-alt"></span>
                            <?php _e('Back', 'azure-plugin'); ?>
                        </button>
                        <button type="button" class="button button-primary" id="btn-next">
                            <?php _e('Continue', 'azure-plugin'); ?>
                            <span class="dashicons dashicons-arrow-right-alt"></span>
                        </button>
                    </div>
                </div>
                
                <?php break; case 7: // SSO Configuration ?>
                <!-- Step 7: SSO Configuration -->
                <div class="wizard-step step-sso active">
                    <div class="step-header">
                        <h1><?php _e('SSO Configuration', 'azure-plugin'); ?></h1>
                        <p class="lead"><?php _e('Configure how users sign in with their Microsoft accounts.', 'azure-plugin'); ?></p>
                    </div>
                    
                    <div class="video-placeholder">
                        <div class="video-frame">
                            <span class="dashicons dashicons-video-alt3"></span>
                            <p><?php _e('Tutorial Video: Setting up Single Sign-On', 'azure-plugin'); ?></p>
                            <p class="small"><?php _e('Video placeholder - Coming soon', 'azure-plugin'); ?></p>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="form-row">
                            <label class="checkbox-label">
                                <input type="checkbox" name="show_on_login" value="1" <?php checked($settings['sso_show_on_login_page'] ?? true); ?>>
                                <strong><?php _e('Show "Sign in" button on WordPress login page', 'azure-plugin'); ?></strong>
                            </label>
                        </div>
                        
                        <div class="form-row">
                            <label for="sso_button_text"><?php _e('Button Text', 'azure-plugin'); ?></label>
                            <input type="text" 
                                   id="sso_button_text" 
                                   name="button_text" 
                                   value="<?php echo esc_attr($settings['sso_login_button_text'] ?? 'Sign in with Microsoft'); ?>" 
                                   placeholder="Sign in with Microsoft"
                                   class="regular-text">
                            <p class="description"><?php _e('Customize the text shown on the sign-in button. The Microsoft icon will still be displayed.', 'azure-plugin'); ?></p>
                        </div>
                        
                        <div class="sso-preview">
                            <p><?php _e('Button Preview:', 'azure-plugin'); ?></p>
                            <button type="button" class="azure-sso-button preview-button" disabled>
                                <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 21 21">
                                    <rect x="1" y="1" width="9" height="9" fill="#f25022"/>
                                    <rect x="11" y="1" width="9" height="9" fill="#7fba00"/>
                                    <rect x="1" y="11" width="9" height="9" fill="#00a4ef"/>
                                    <rect x="11" y="11" width="9" height="9" fill="#ffb900"/>
                                </svg>
                                <span id="preview-button-text"><?php echo esc_html($settings['sso_login_button_text'] ?? 'Sign in with Microsoft'); ?></span>
                            </button>
                        </div>
                        
                        <div class="form-row">
                            <label for="sso_default_role"><?php _e('Default User Role', 'azure-plugin'); ?></label>
                            <div class="role-selection">
                                <label class="radio-label">
                                    <input type="radio" name="use_custom_role" value="0" <?php checked(!($settings['sso_use_custom_role'] ?? false)); ?>>
                                    <?php _e('Use existing role:', 'azure-plugin'); ?>
                                </label>
                                <select name="default_role" id="sso_default_role" class="<?php echo ($settings['sso_use_custom_role'] ?? false) ? 'disabled' : ''; ?>">
                                    <?php foreach ($available_roles as $role_key => $role_name): ?>
                                    <option value="<?php echo esc_attr($role_key); ?>" <?php selected($settings['sso_default_role'] ?? 'subscriber', $role_key); ?>>
                                        <?php echo esc_html($role_name); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <label class="radio-label">
                                    <input type="radio" name="use_custom_role" value="1" <?php checked($settings['sso_use_custom_role'] ?? false); ?>>
                                    <?php _e('Create custom role:', 'azure-plugin'); ?>
                                </label>
                                <input type="text" 
                                       name="custom_role_name" 
                                       id="sso_custom_role" 
                                       value="<?php echo esc_attr($settings['sso_custom_role_name'] ?? 'AzureAD'); ?>" 
                                       placeholder="AzureAD"
                                       class="<?php echo ($settings['sso_use_custom_role'] ?? false) ? '' : 'disabled'; ?>">
                            </div>
                            <p class="description"><?php _e('Role assigned to new users who sign in with SSO.', 'azure-plugin'); ?></p>
                        </div>
                    </div>
                    
                    <div class="step-actions">
                        <button type="button" class="button button-secondary" id="btn-prev">
                            <span class="dashicons dashicons-arrow-left-alt"></span>
                            <?php _e('Back', 'azure-plugin'); ?>
                        </button>
                        <button type="button" class="button button-primary" id="btn-next">
                            <?php _e('Continue', 'azure-plugin'); ?>
                            <span class="dashicons dashicons-arrow-right-alt"></span>
                        </button>
                    </div>
                </div>
                
                <?php break; case 8: // OneDrive Media ?>
                <!-- Step 8: OneDrive Media -->
                <div class="wizard-step step-onedrive active">
                    <div class="step-header">
                        <h1><?php _e('OneDrive Media Setup', 'azure-plugin'); ?></h1>
                        <p class="lead"><?php _e('Connect OneDrive or SharePoint for media storage.', 'azure-plugin'); ?></p>
                    </div>
                    
                    <div class="video-placeholder">
                        <div class="video-frame">
                            <span class="dashicons dashicons-video-alt3"></span>
                            <p><?php _e('Tutorial Video: Setting up OneDrive Media Library', 'azure-plugin'); ?></p>
                            <p class="small"><?php _e('Video placeholder - Coming soon', 'azure-plugin'); ?></p>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="info-box warning">
                            <span class="dashicons dashicons-warning"></span>
                            <div>
                                <strong><?php _e('Important:', 'azure-plugin'); ?></strong>
                                <p><?php _e('Use an admin or service account (e.g., admin@yourorg.net) to authorize access, not a personal account (e.g., jimmy@yourorg.net). This ensures the media library remains accessible even if individual staff change.', 'azure-plugin'); ?></p>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <label><?php _e('Storage Location', 'azure-plugin'); ?></label>
                            <div class="storage-options">
                                <label class="radio-card">
                                    <input type="radio" name="storage_type" value="onedrive" <?php checked(($settings['onedrive_media_storage_type'] ?? 'onedrive'), 'onedrive'); ?>>
                                    <div class="radio-content">
                                        <span class="dashicons dashicons-portfolio"></span>
                                        <span><?php _e('OneDrive', 'azure-plugin'); ?></span>
                                    </div>
                                </label>
                                <label class="radio-card">
                                    <input type="radio" name="storage_type" value="sharepoint" <?php checked(($settings['onedrive_media_storage_type'] ?? 'onedrive'), 'sharepoint'); ?>>
                                    <div class="radio-content">
                                        <span class="dashicons dashicons-networking"></span>
                                        <span><?php _e('SharePoint', 'azure-plugin'); ?></span>
                                    </div>
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <button type="button" class="button button-primary" id="btn-authorize-onedrive">
                                <span class="dashicons dashicons-admin-links"></span>
                                <?php _e('Authorize OneDrive Access', 'azure-plugin'); ?>
                            </button>
                            <div id="onedrive-auth-status" class="validation-result"></div>
                        </div>
                        
                        <div class="form-row">
                            <label for="onedrive_base_folder"><?php _e('Base Folder', 'azure-plugin'); ?></label>
                            <input type="text" 
                                   id="onedrive_base_folder" 
                                   name="base_folder" 
                                   value="<?php echo esc_attr($settings['onedrive_media_base_folder'] ?? 'WordPress Media'); ?>" 
                                   placeholder="WordPress Media"
                                   class="regular-text">
                            <p class="description"><?php _e('Folder in OneDrive/SharePoint where media will be stored.', 'azure-plugin'); ?></p>
                        </div>
                        
                        <div class="form-row">
                            <label class="checkbox-label">
                                <input type="checkbox" name="use_year_folders" value="1" <?php checked($settings['onedrive_media_use_year_folders'] ?? true); ?>>
                                <strong><?php _e('Organize by year', 'azure-plugin'); ?></strong>
                            </label>
                            <p class="description"><?php _e('Create subfolders by year (e.g., "WordPress Media/2025/image.jpg")', 'azure-plugin'); ?></p>
                        </div>
                    </div>
                    
                    <div class="step-actions">
                        <button type="button" class="button button-secondary" id="btn-prev">
                            <span class="dashicons dashicons-arrow-left-alt"></span>
                            <?php _e('Back', 'azure-plugin'); ?>
                        </button>
                        <button type="button" class="button button-primary" id="btn-next">
                            <?php _e('Continue', 'azure-plugin'); ?>
                            <span class="dashicons dashicons-arrow-right-alt"></span>
                        </button>
                    </div>
                </div>
                
                <?php break; case 9: // Summary ?>
                <!-- Step 9: Summary -->
                <div class="wizard-step step-summary active">
                    <div class="step-header">
                        <div class="success-icon">
                            <span class="dashicons dashicons-yes-alt"></span>
                        </div>
                        <h1><?php _e('Setup Complete!', 'azure-plugin'); ?></h1>
                        <p class="lead"><?php _e('Your PTA Tools plugin is configured and ready to use.', 'azure-plugin'); ?></p>
                    </div>
                    
                    <div class="summary-section">
                        <h3><?php _e('Configured Modules', 'azure-plugin'); ?></h3>
                        <div class="summary-grid">
                            <?php 
                            $module_names = array(
                                'sso' => __('Single Sign-On', 'azure-plugin'),
                                'calendar' => __('Calendar Integration', 'azure-plugin'),
                                'newsletter' => __('Newsletter', 'azure-plugin'),
                                'backup' => __('Cloud Backup', 'azure-plugin'),
                                'pta' => __('PTA Roles', 'azure-plugin'),
                                'onedrive' => __('OneDrive Media', 'azure-plugin')
                            );
                            foreach ($modules as $mod): 
                                if (isset($module_names[$mod])):
                            ?>
                            <div class="summary-item enabled">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <?php echo esc_html($module_names[$mod]); ?>
                            </div>
                            <?php endif; endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="info-box">
                        <span class="dashicons dashicons-info"></span>
                        <div>
                            <strong><?php _e('What\'s Next?', 'azure-plugin'); ?></strong>
                            <p><?php _e('This wizard configured the essential settings to get you started. For advanced configuration:', 'azure-plugin'); ?></p>
                            <ul>
                                <li><?php _e('Visit each module\'s settings page for additional options', 'azure-plugin'); ?></li>
                                <li><?php _e('Check the System Logs for any issues that need attention', 'azure-plugin'); ?></li>
                                <li><?php _e('Review the documentation for detailed guidance', 'azure-plugin'); ?></li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="step-actions">
                        <button type="button" class="button button-hero button-primary" id="btn-finish">
                            <?php _e('Go to Dashboard', 'azure-plugin'); ?>
                            <span class="dashicons dashicons-arrow-right-alt"></span>
                        </button>
                    </div>
                </div>
                
                <?php endswitch; ?>
                
            </form>
        </div>
        
    </div>
</div>

