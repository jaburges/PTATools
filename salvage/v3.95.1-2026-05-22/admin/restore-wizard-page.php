<?php
/**
 * Restore Wizard Page — guided step-by-step restore with progress tracking
 */

if (!defined('ABSPATH')) {
    exit;
}

$wizard       = Azure_Restore_Wizard::get_instance();
$current_step = $wizard->get_current_step();
$progress     = $wizard->get_progress_percent();
$state        = Azure_Restore_Wizard::get_state();
$settings     = Azure_Settings::get_all_settings();
?>

<style>
.rw-split { display:flex; gap:24px; min-height:360px; }
.rw-sidebar { width:200px; flex-shrink:0; }
.rw-main { flex:1; min-width:0; }
.rw-component-list { list-style:none; padding:0; margin:0; }
.rw-component-list li { padding:8px 0; border-bottom:1px solid #eee; display:flex; align-items:center; gap:8px; color:#999; }
.rw-component-list li.active { color:#333; font-weight:600; }
.rw-component-list li.done { color:#00a32a; }
.rw-component-list li.error { color:#d63638; }
.rw-component-list li .dashicons { font-size:18px; width:18px; height:18px; }
.rw-log { background:#1d2327; color:#c3c4c7; font-family:monospace; font-size:12px; line-height:1.6; padding:12px; border-radius:4px; max-height:360px; overflow-y:auto; }
.rw-log .heading { color:#72aee6; font-weight:bold; }
.rw-log .success { color:#00a32a; }
.rw-log .error { color:#d63638; }
.rw-log .warning { color:#dba617; }
.rw-log .time { color:#8c8f94; margin-right:8px; }
.rw-warnings { margin:16px 0; }
.rw-warnings .notice { margin:8px 0; }
.rw-download-bar { background:#f0f0f0; border-radius:4px; overflow:hidden; height:18px; margin:4px 0; }
.rw-download-bar .fill { background:#0078d4; height:100%; transition:width 0.5s; }
</style>

<div class="wrap azure-setup-wizard">
    <div class="wizard-container">

        <!-- Progress Bar -->
        <div class="wizard-progress">
            <div class="progress-bar">
                <div class="progress-fill" id="rw-progress-fill" style="width: <?php echo esc_attr($progress); ?>%"></div>
            </div>
            <div class="progress-steps">
                <?php foreach ($wizard->steps as $num => $info):
                    $is_current   = ($num == $current_step);
                    $is_completed = ($num < $current_step);
                ?>
                <div class="progress-step <?php echo $is_current ? 'current' : ''; ?> <?php echo $is_completed ? 'completed' : ''; ?>">
                    <span class="step-number"><?php echo $is_completed ? '&#10003;' : $num; ?></span>
                    <span class="step-title"><?php echo esc_html($info['title']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Wizard Content -->
        <div class="wizard-content">
            <input type="hidden" id="rw-current-step" value="<?php echo esc_attr($current_step); ?>">

            <?php switch ($current_step):

            // ==============================================================
            // STEP 1: Connect to Azure Storage
            // ==============================================================
            case 1: ?>
            <div class="wizard-step active">
                <div class="step-header">
                    <span class="dashicons dashicons-cloud" style="font-size:48px; width:48px; height:48px; color:#0078d4;"></span>
                    <h1>Connect to Azure Storage</h1>
                    <p class="lead">Enter the Azure Blob Storage credentials where your backup is stored.</p>
                </div>

                <div class="step-body" style="max-width:500px; margin:0 auto;">
                    <div class="form-group" style="margin-bottom:16px;">
                        <label><strong>Storage Account Name</strong></label>
                        <input type="text" id="rw-storage-account" class="regular-text" style="width:100%"
                               value="<?php echo esc_attr($settings['backup_storage_account_name'] ?? ''); ?>" />
                    </div>
                    <div class="form-group" style="margin-bottom:16px;">
                        <label><strong>Container Name</strong></label>
                        <input type="text" id="rw-container-name" class="regular-text" style="width:100%"
                               value="<?php echo esc_attr($settings['backup_storage_container_name'] ?? 'wordpress-backups'); ?>" />
                    </div>
                    <div class="form-group" style="margin-bottom:16px;">
                        <label><strong>Storage Account Key</strong></label>
                        <input type="password" id="rw-storage-key" class="regular-text" style="width:100%"
                               value="<?php echo esc_attr($settings['backup_storage_account_key'] ?? ''); ?>" />
                    </div>

                    <div id="rw-connect-result" style="margin:12px 0; display:none;"></div>
                </div>

                <div class="step-footer" style="text-align:right; margin-top:24px;">
                    <button type="button" class="button" id="rw-cancel">Cancel</button>
                    <button type="button" class="button button-primary" id="rw-validate-storage">Validate &amp; Connect</button>
                </div>
            </div>
            <?php break;

            // ==============================================================
            // STEP 2: Select Backup
            // ==============================================================
            case 2: ?>
            <div class="wizard-step active">
                <div class="step-header">
                    <span class="dashicons dashicons-backup" style="font-size:48px; width:48px; height:48px; color:#0078d4;"></span>
                    <h1>Select a Backup</h1>
                    <p class="lead">Choose the backup you want to restore from Azure Storage.</p>
                </div>

                <div class="step-body">
                    <div id="rw-backups-loading" style="text-align:center; padding:24px;">
                        <span class="spinner is-active" style="float:none;"></span> Loading backups...
                    </div>
                    <table id="rw-backups-table" class="wp-list-table widefat striped" style="display:none;">
                        <thead>
                            <tr><th></th><th>Site</th><th>Date</th><th>Format</th><th>Files</th></tr>
                        </thead>
                        <tbody id="rw-backups-body"></tbody>
                    </table>
                    <div id="rw-no-backups" style="display:none; text-align:center; padding:24px; color:#666;">
                        No backups found in this storage container.
                    </div>
                </div>

                <div class="step-footer" style="text-align:right; margin-top:24px;">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-restore&step=1')); ?>" class="button">Back</a>
                    <button type="button" class="button button-primary" id="rw-select-backup" disabled>Next</button>
                </div>
            </div>
            <?php break;

            // ==============================================================
            // STEP 3: Restore Database (with migration warnings)
            // ==============================================================
            case 3: ?>
            <div class="wizard-step active">
                <div class="step-header">
                    <span class="dashicons dashicons-database" style="font-size:48px; width:48px; height:48px; color:#d63638;"></span>
                    <h1>Restore Database</h1>
                    <p class="lead">Review the backup details, then restore the database.</p>
                </div>

                <div class="step-body">
                    <!-- Manifest info & warnings (loaded via AJAX) -->
                    <div id="rw-manifest-loading" style="text-align:center; padding:16px;">
                        <span class="spinner is-active" style="float:none;"></span> Analysing backup...
                    </div>

                    <div id="rw-manifest-info" style="display:none;">
                        <div id="rw-manifest-details" style="margin-bottom:16px;"></div>
                        <div id="rw-warnings" class="rw-warnings"></div>

                        <h3>Components in this backup:</h3>
                        <div id="rw-component-preview" style="margin-bottom:16px;"></div>

                        <div class="notice notice-warning" style="padding:12px;">
                            <label>
                                <input type="checkbox" id="rw-migrate-checkbox" checked />
                                <strong>Search and replace site location in the database (migrate)</strong>
                            </label>
                        </div>
                    </div>

                    <!-- Restore progress (shown after clicking restore) -->
                    <div id="rw-db-restore-area" style="display:none;">
                        <div class="rw-split">
                            <div class="rw-sidebar">
                                <h4>Restoration progress:</h4>
                                <ul class="rw-component-list" id="rw-db-steps">
                                    <li id="rw-dbstep-download"><span class="dashicons dashicons-minus"></span> Download</li>
                                    <li id="rw-dbstep-restore"><span class="dashicons dashicons-minus"></span> Database</li>
                                    <li id="rw-dbstep-urls"><span class="dashicons dashicons-minus"></span> URL Replace</li>
                                    <li id="rw-dbstep-fixup"><span class="dashicons dashicons-minus"></span> Post-fixups</li>
                                </ul>
                            </div>
                            <div class="rw-main">
                                <h4>Activity log</h4>
                                <div class="rw-log" id="rw-db-log"></div>
                            </div>
                        </div>
                    </div>

                    <div id="rw-db-result" style="display:none;"></div>
                </div>

                <div class="step-footer" style="text-align:right; margin-top:24px;">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-restore&step=2')); ?>" class="button" id="rw-db-back">Back</a>
                    <button type="button" class="button button-primary" id="rw-run-db-restore" style="display:none;">Restore</button>
                </div>
            </div>
            <?php break;

            // ==============================================================
            // STEP 4: Re-Authenticate
            // ==============================================================
            case 4: ?>
            <div class="wizard-step active">
                <div class="step-header">
                    <span class="dashicons dashicons-yes-alt" style="font-size:48px; width:48px; height:48px; color:#00a32a;"></span>
                    <h1>Database Restored</h1>
                    <p class="lead">The database has been restored successfully. You are now logged in.</p>
                </div>

                <div class="step-body" style="max-width:560px; margin:0 auto;">
                    <div class="notice notice-success" style="padding:12px;">
                        <p>The database from the backup has been applied. Plugin settings, posts, pages, and users have been restored.</p>
                        <p>Azure Storage credentials and the restore wizard state have been preserved.</p>
                    </div>

                    <?php if (!empty($state['temp_admin'])): ?>
                    <div class="notice notice-info" style="padding:12px;">
                        <p><strong>Note:</strong> You are logged in with a temporary admin account.
                        This account will be removed when the restore is completed.
                        After completion, log in with your regular credentials from the source site.</p>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="step-footer" style="text-align:right; margin-top:24px;">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-restore&step=5')); ?>" class="button button-primary">Next: Restore Files</a>
                </div>
            </div>
            <?php break;

            // ==============================================================
            // STEP 5: Restore Files (UpdraftPlus-style split layout)
            // ==============================================================
            case 5: ?>
            <div class="wizard-step active">
                <div class="step-header">
                    <span class="dashicons dashicons-portfolio" style="font-size:48px; width:48px; height:48px; color:#0078d4;"></span>
                    <h1>Restore Files</h1>
                    <p class="lead">Restoring plugins, themes, and other content files from the backup.</p>
                </div>

                <div class="step-body">
                    <!-- Pre-restore info -->
                    <div id="rw-files-preinfo">
                        <p>This step downloads and restores the following components:</p>
                        <ul style="list-style:disc; margin-left:24px;">
                            <li><strong>Must-Use Plugins</strong></li>
                            <li><strong>Plugins</strong></li>
                            <li><strong>Themes</strong></li>
                            <li><strong>Other Content</strong> (custom wp-content files)</li>
                        </ul>
                        <p style="color:#666; font-size:13px;">Media files are handled in the next step via SharePoint/OneDrive sync.</p>
                    </div>

                    <!-- Restore progress (shown after clicking restore) -->
                    <div id="rw-files-restore-area" style="display:none;">
                        <div class="rw-split">
                            <div class="rw-sidebar">
                                <p style="color:#d63638; font-weight:600; font-size:13px;">Do not close this page until finished.</p>
                                <h4>Restoration progress:</h4>
                                <ul class="rw-component-list" id="rw-files-steps">
                                    <li data-entity="mu-plugins"><span class="dashicons dashicons-minus"></span> Must-Use Plugins</li>
                                    <li data-entity="plugins"><span class="dashicons dashicons-minus"></span> Plugins</li>
                                    <li data-entity="themes"><span class="dashicons dashicons-minus"></span> Themes</li>
                                    <li data-entity="others"><span class="dashicons dashicons-minus"></span> Others</li>
                                    <li data-entity="cleaning"><span class="dashicons dashicons-minus"></span> Cleaning</li>
                                    <li data-entity="finished"><span class="dashicons dashicons-minus"></span> Finished</li>
                                </ul>
                            </div>
                            <div class="rw-main">
                                <h4>Activity log</h4>
                                <div class="rw-log" id="rw-files-log"></div>
                            </div>
                        </div>
                    </div>

                    <div id="rw-files-result" style="display:none;"></div>
                </div>

                <div class="step-footer" style="text-align:right; margin-top:24px;">
                    <button type="button" class="button button-primary" id="rw-run-files-restore">Restore Files Now</button>
                </div>
            </div>
            <?php break;

            // ==============================================================
            // STEP 6: Media Sync from SharePoint
            // ==============================================================
            case 6: ?>
            <div class="wizard-step active">
                <div class="step-header">
                    <span class="dashicons dashicons-format-gallery" style="font-size:48px; width:48px; height:48px; color:#0078d4;"></span>
                    <h1>Sync Media from SharePoint</h1>
                    <p class="lead">Pull media files from your SharePoint/OneDrive library into this site.</p>
                </div>

                <div class="step-body" style="max-width:560px; margin:0 auto;">
                    <?php
                    $onedrive_enabled = Azure_Settings::get_setting('enable_onedrive_media', false);
                    $has_auth = !empty(Azure_Settings::get_setting('onedrive_media_client_id', ''))
                             || !empty(Azure_Settings::get_setting('common_client_id', ''));
                    ?>

                    <?php if (!$onedrive_enabled): ?>
                    <div class="notice notice-warning" style="padding:12px;">
                        <p>The OneDrive Media module is not enabled. Enable it in
                        <a href="<?php echo admin_url('admin.php?page=azure-plugin'); ?>">Plugin Settings</a>
                        and return here, or skip this step.</p>
                    </div>
                    <?php elseif (!$has_auth): ?>
                    <div class="notice notice-warning" style="padding:12px;">
                        <p>OneDrive/SharePoint credentials are not configured. Configure them in
                        <a href="<?php echo admin_url('admin.php?page=azure-plugin-onedrive-media'); ?>">OneDrive Media settings</a>
                        and return here.</p>
                    </div>
                    <?php else: ?>
                    <div class="notice notice-info" style="padding:12px;">
                        <p>This will temporarily set the sync direction to <strong>SharePoint &rarr; WordPress (one-way pull)</strong>,
                        pull all media, and then switch back to <strong>two-way sync</strong>.</p>
                    </div>

                    <div id="rw-media-progress" style="display:none;">
                        <div class="rw-download-bar">
                            <div class="fill" id="rw-media-bar" style="width:0%"></div>
                        </div>
                        <p id="rw-media-status" style="text-align:center; color:#666;">Starting media sync...</p>
                    </div>
                    <?php endif; ?>

                    <div id="rw-media-result" style="display:none;"></div>
                </div>

                <div class="step-footer" style="text-align:right; margin-top:24px;">
                    <?php if ($onedrive_enabled && $has_auth): ?>
                    <button type="button" class="button" id="rw-skip-media">Skip</button>
                    <button type="button" class="button button-primary" id="rw-start-media-sync">Start Media Sync</button>
                    <?php else: ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-restore&step=7')); ?>" class="button button-primary">Skip &amp; Continue</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php break;

            // ==============================================================
            // STEP 7: Complete
            // ==============================================================
            case 7: ?>
            <div class="wizard-step active">
                <div class="step-header">
                    <span class="dashicons dashicons-yes" style="font-size:64px; width:64px; height:64px; color:#00a32a;"></span>
                    <h1>Restore Complete!</h1>
                    <p class="lead">Your site has been restored successfully.</p>
                </div>

                <div class="step-body" style="max-width:560px; margin:0 auto;">
                    <div class="notice notice-success" style="padding:12px;">
                        <p>All selected components have been restored:</p>
                        <ul style="list-style:disc; margin-left:24px;">
                            <?php if (!empty($state['db_restored'])): ?>
                            <li>Database restored with URL migration</li>
                            <?php endif; ?>
                            <?php if (!empty($state['files_restored'])): ?>
                            <li>Plugins, themes, and content files restored</li>
                            <?php endif; ?>
                            <?php if (!empty($state['media_synced'])): ?>
                            <li>Media synced from SharePoint/OneDrive</li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <?php if (!empty($state['temp_admin'])): ?>
                    <div class="notice notice-warning" style="padding:12px;">
                        <p><strong>Important:</strong> You are logged in with a temporary admin account.
                        After clicking "Finish", this account will be removed.
                        Log in with a user account from the source site's database.</p>
                    </div>
                    <?php endif; ?>

                    <h3>Post-Restore Checklist</h3>
                    <ul style="list-style:none; padding:0;">
                        <li style="padding:4px 0;">&#9744; Verify the site loads correctly on the front end</li>
                        <li style="padding:4px 0;">&#9744; Check that your regular admin account works</li>
                        <li style="padding:4px 0;">&#9744; Verify media images appear correctly</li>
                        <li style="padding:4px 0;">&#9744; Update permalinks (Settings &rarr; Permalinks &rarr; Save)</li>
                        <li style="padding:4px 0;">&#9744; Check Azure SSO configuration if applicable</li>
                        <li style="padding:4px 0;">&#9744; Review OneDrive sync settings</li>
                    </ul>
                </div>

                <div class="step-footer" style="text-align:right; margin-top:24px;">
                    <button type="button" class="button button-primary" id="rw-finish">Finish</button>
                </div>
            </div>
            <?php break;

            endswitch; ?>
        </div>
    </div>
</div>
