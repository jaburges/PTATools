<?php
if (!defined('ABSPATH')) {
    exit;
}

// Get PTA statistics
$pta_manager = null;
$departments = array();
if (class_exists('Azure_PTA_Manager')) {
    try {
        $pta_manager = Azure_PTA_Manager::get_instance();
        $departments = $pta_manager->get_departments(true);
    } catch (Exception $e) {
        Azure_Logger::error('PTA Page: Failed to initialize PTA Manager - ' . $e->getMessage());
    }
}
$total_roles = 0;
$filled_roles = 0;
$total_assignments = 0;

foreach ($departments as $dept) {
    $total_roles += count($dept->roles);
    foreach ($dept->roles as $role) {
        if ($role->assigned_count >= $role->max_occupants) {
            $filled_roles++;
        }
        $total_assignments += $role->assigned_count;
    }
}

// Get sync queue status
global $wpdb;
$sync_table = null;
$sync_stats = array();
$unassigned_users = array();

if (class_exists('Azure_PTA_Database')) {
    try {
        $sync_table = Azure_PTA_Database::get_table_name('sync_queue');
        if ($sync_table) {
            $sync_stats['pending'] = $wpdb->get_var("SELECT COUNT(*) FROM $sync_table WHERE status = 'pending'");
            $sync_stats['failed'] = $wpdb->get_var("SELECT COUNT(*) FROM $sync_table WHERE status = 'failed'");
            $sync_stats['completed_today'] = $wpdb->get_var("SELECT COUNT(*) FROM $sync_table WHERE status = 'completed' AND DATE(processed_at) = CURDATE()");
        }

        // Get users with no role assignments
        $assignments_table = Azure_PTA_Database::get_table_name('assignments');
        if ($assignments_table) {
            $unassigned_users = $wpdb->get_results("
                SELECT u.ID, u.display_name, u.user_email, u.user_registered
                FROM {$wpdb->users} u
                LEFT JOIN $assignments_table ra ON u.ID = ra.user_id AND ra.status = 'active'
                WHERE ra.user_id IS NULL
                AND u.ID NOT IN (SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'wp_capabilities' AND meta_value LIKE '%administrator%')
                ORDER BY u.user_registered DESC
                LIMIT 10
            ");
        }
    } catch (Exception $e) {
        Azure_Logger::error('PTA Page: Failed to get sync stats - ' . $e->getMessage());
    }
}
?>

<div class="wrap">
    <h1>PTA Tools - PTA Roles & Organization</h1>
    
    <!-- Module Toggle Section -->
    <div class="module-status-section">
        <h2>Module Status</h2>
        <div class="module-toggle-card">
            <div class="module-info">
                <h3><span class="dashicons dashicons-networking"></span> PTA Roles Manager Module</h3>
                <p>Manage PTA organizational structure with Azure AD sync</p>
            </div>
            <div class="module-control">
                <label class="switch">
                    <input type="checkbox" class="pta-module-toggle" <?php checked(Azure_Settings::is_module_enabled('pta')); ?> />
                    <span class="slider"></span>
                </label>
                <span class="toggle-status"><?php echo Azure_Settings::is_module_enabled('pta') ? 'Enabled' : 'Disabled'; ?></span>
            </div>
        </div>
        <?php if (!Azure_Settings::is_module_enabled('pta')): ?>
        <div class="notice notice-warning inline">
            <p><strong>PTA module is disabled.</strong> Enable it above or in the <a href="<?php echo admin_url('admin.php?page=azure-plugin'); ?>">main settings</a> to use PTA functionality.</p>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="azure-pta-dashboard">
        <!-- PTA Statistics -->
        <div class="pta-stats-section">
            <h2>Organization Overview</h2>
            
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($departments); ?></div>
                    <div class="stat-label">Departments</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_roles; ?></div>
                    <div class="stat-label">Total Roles</div>
                </div>
                
                <div class="stat-card success">
                    <div class="stat-number"><?php echo $filled_roles; ?></div>
                    <div class="stat-label">Filled Roles</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_assignments; ?></div>
                    <div class="stat-label">Active Assignments</div>
                </div>
            </div>
            
            <?php if (!empty($sync_stats)): ?>
            <h3 style="margin-top: 25px;">Sync Status</h3>
            <div class="stats-cards">
                <div class="stat-card warning">
                    <div class="stat-number"><?php echo intval($sync_stats['pending']); ?></div>
                    <div class="stat-label">Pending</div>
                </div>
                <div class="stat-card error">
                    <div class="stat-number"><?php echo intval($sync_stats['failed']); ?></div>
                    <div class="stat-label">Failed</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-number"><?php echo intval($sync_stats['completed_today']); ?></div>
                    <div class="stat-label">Completed Today</div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Quick Actions -->
        <div class="pta-actions-section">
            <h2>Quick Actions</h2>
            
            <div class="action-buttons">
                <button type="button" class="button button-primary" id="show-org-chart">
                    <span class="dashicons dashicons-networking"></span>
                    View Org Chart
                </button>
                
                <button type="button" class="button" id="manage-people">
                    <span class="dashicons dashicons-admin-users"></span>
                    Manage People
                </button>
                
                <button type="button" class="button" id="manage-roles">
                    <span class="dashicons dashicons-businessperson"></span>
                    Manage Roles
                </button>
                
                <button type="button" class="button" id="manage-departments">
                    <span class="dashicons dashicons-building"></span>
                    Manage Departments
                </button>
                
                <a href="<?php echo admin_url('admin.php?page=azure-plugin-pta-groups'); ?>" class="button">
                    <span class="dashicons dashicons-admin-network"></span>
                    Manage O365 Groups
                </a>

                <a href="<?php echo admin_url('admin.php?page=azure-plugin-pta-role-editor'); ?>" class="button">
                    <span class="dashicons dashicons-admin-users"></span>
                    Role Editor
                </a>

                <button type="button" class="button test-pta-sync">
                    <span class="dashicons dashicons-update"></span>
                    Test Sync Connection
                </button>
                
            </div>
        </div>
        
        <!-- Unassigned Users Alert -->
        <?php if (!empty($unassigned_users)): ?>
        <div class="unassigned-users-section">
            <h2>⚠️ Users Without Role Assignments</h2>
            <p>The following users have no role assignments. Consider assigning roles or removing their accounts.</p>
            
            <div class="unassigned-users-list">
                <?php foreach ($unassigned_users as $user): ?>
                <div class="unassigned-user">
                    <div class="user-info">
                        <strong><?php echo esc_html($user->display_name); ?></strong>
                        <span class="user-email"><?php echo esc_html($user->user_email); ?></span>
                        <span class="user-registered">Registered: <?php echo date('M j, Y', strtotime($user->user_registered)); ?></span>
                    </div>
                    <div class="user-actions">
                        <button type="button" class="button button-small assign-role-btn" data-user-id="<?php echo $user->ID; ?>">
                            Assign Role
                        </button>
                        <button type="button" class="button button-small delete-user-btn" data-user-id="<?php echo $user->ID; ?>" data-user-name="<?php echo esc_attr($user->display_name); ?>">
                            Delete User
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- React App Container -->
        <div id="pta-react-app" style="display: none;">
            <div class="pta-app-loading">
                <div class="loading-spinner"></div>
                <p>Loading PTA Management Interface...</p>
            </div>
        </div>
        
        <!-- Department Quick Overview -->
        <div class="departments-overview-section">
            <h2>Departments Overview</h2>
            
            <div class="departments-grid">
                <?php foreach ($departments as $dept): ?>
                <div class="department-card">
                    <div class="dept-header">
                        <h3><?php echo esc_html($dept->name); ?></h3>
                        <?php if ($dept->vp_info): ?>
                        <div class="dept-vp">
                            VP: <strong><?php echo esc_html($dept->vp_info['display_name']); ?></strong>
                        </div>
                        <?php else: ?>
                        <div class="dept-vp no-vp">No VP Assigned</div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="dept-stats">
                        <div class="dept-stat">
                            <span class="stat-number"><?php echo count($dept->roles); ?></span>
                            <span class="stat-label">Roles</span>
                        </div>
                        <div class="dept-stat">
                            <?php 
                            $dept_assignments = 0;
                            $dept_filled = 0;
                            foreach ($dept->roles as $role) {
                                $dept_assignments += $role->assigned_count;
                                if ($role->assigned_count >= $role->max_occupants) {
                                    $dept_filled++;
                                }
                            }
                            ?>
                            <span class="stat-number"><?php echo $dept_assignments; ?></span>
                            <span class="stat-label">Assignments</span>
                        </div>
                        <div class="dept-stat">
                            <span class="stat-number"><?php echo $dept_filled; ?></span>
                            <span class="stat-label">Filled</span>
                        </div>
                    </div>
                    
                    <div class="dept-roles">
                        <h4>Recent Roles:</h4>
                        <div class="roles-list">
                            <?php 
                            $role_count = 0;
                            foreach ($dept->roles as $role): 
                                if ($role_count >= 5) break;
                                $status_class = '';
                                if ($role->assigned_count >= $role->max_occupants) {
                                    $status_class = 'filled';
                                } elseif ($role->assigned_count > 0) {
                                    $status_class = 'partial';
                                } else {
                                    $status_class = 'open';
                                }
                                $role_count++;
                            ?>
                            <div class="role-item <?php echo $status_class; ?>">
                                <span class="role-name"><?php echo esc_html($role->name); ?></span>
                                <span class="role-status"><?php echo $role->assigned_count; ?>/<?php echo $role->max_occupants; ?></span>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if (count($dept->roles) > 5): ?>
                            <div class="more-roles">
                                +<?php echo count($dept->roles) - 5; ?> more roles
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="dept-actions">
                        <button type="button" class="button button-small view-dept-details" data-dept-id="<?php echo $dept->id; ?>">
                            View Details
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Sync Queue Status -->
        <?php if (!empty($sync_stats) && ($sync_stats['pending'] > 0 || $sync_stats['failed'] > 0)): ?>
        <div class="sync-queue-section">
            <h2>Sync Queue Status</h2>
            <p class="description">The sync queue contains jobs to sync PTA role changes to Azure AD. These are processed automatically every 5 minutes.</p>
            
            <?php if ($sync_stats['failed'] > 0): ?>
            <div class="notice notice-error inline" style="margin: 10px 0;">
                <p><strong><?php echo $sync_stats['failed']; ?> sync jobs have failed.</strong></p>
            </div>
            <?php endif; ?>
            
            <?php if ($sync_stats['pending'] > 0): ?>
            <div class="notice notice-warning inline" style="margin: 10px 0;">
                <p><strong><?php echo $sync_stats['pending']; ?> sync jobs are pending.</strong></p>
            </div>
            <?php endif; ?>
            
            <div class="sync-queue-actions" style="margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="button" class="button button-primary process-sync-queue">
                    <span class="dashicons dashicons-update"></span>
                    Process Queue Now
                </button>
                <button type="button" class="button clear-pending-queue" data-type="pending">
                    <span class="dashicons dashicons-dismiss"></span>
                    Clear Pending (<?php echo intval($sync_stats['pending']); ?>)
                </button>
                <?php if ($sync_stats['failed'] > 0): ?>
                <button type="button" class="button clear-failed-queue" data-type="failed">
                    <span class="dashicons dashicons-trash"></span>
                    Clear Failed (<?php echo intval($sync_stats['failed']); ?>)
                </button>
                <?php endif; ?>
            </div>
            
            <p class="description" style="margin-top: 10px; font-size: 12px; color: #666;">
                <strong>Note:</strong> Pending sync jobs are for syncing WordPress role changes TO Azure AD (e.g., updating job titles). 
                If you're not using Azure AD write-back, you can safely clear these pending items.
            </p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modals -->
<div id="role-assignment-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Assign Role to User</h2>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <form id="role-assignment-form">
                <input type="hidden" id="assignment-user-id" name="user_id">
                
                <div class="form-field">
                    <label for="assignment-role-id">Select Role:</label>
                    <select id="assignment-role-id" name="role_id" required>
                        <option value="">-- Select a Role --</option>
                        <?php foreach ($departments as $dept): ?>
                            <optgroup label="<?php echo esc_attr($dept->name); ?>">
                                <?php foreach ($dept->roles as $role): ?>
                                    <?php if ($role->open_positions > 0): ?>
                                    <option value="<?php echo $role->id; ?>">
                                        <?php echo esc_html($role->name); ?> (<?php echo $role->open_positions; ?> open)
                                    </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-field">
                    <label>
                        <input type="checkbox" id="assignment-is-primary" name="is_primary">
                        Make this the user's primary role
                    </label>
                    <p class="description">Primary roles determine the user's department and manager hierarchy.</p>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="button button-primary">Assign Role</button>
                    <button type="button" class="button modal-cancel">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Unassign Role Modal -->
<div id="unassign-role-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Unassign Roles from User</h2>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <p id="unassign-user-info" style="margin-bottom: 15px;"></p>
            <form id="unassign-role-form">
                <input type="hidden" id="unassign-user-id" name="user_id">
                
                <div class="form-field">
                    <label><strong>Select roles to unassign:</strong></label>
                    <div id="unassign-roles-list" style="margin-top: 10px; max-height: 300px; overflow-y: auto;">
                        <!-- Roles will be loaded dynamically -->
                        <p class="loading">Loading roles...</p>
                    </div>
                    <div style="margin-top: 10px; border-top: 1px solid #ddd; padding-top: 10px;">
                        <label>
                            <input type="checkbox" id="unassign-select-all"> 
                            <strong>Select All</strong>
                        </label>
                    </div>
                </div>
                
                <div class="form-actions" style="margin-top: 20px;">
                    <button type="submit" class="button button-primary button-link-delete">Unassign Selected Roles</button>
                    <button type="button" class="button modal-cancel">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- People Management Modal -->
<div id="people-modal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h2>Manage People & Role Assignments</h2>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="people-management-container">
                <div class="people-search">
                    <input type="text" id="people-search" placeholder="Search people..." class="regular-text">
                    <button type="button" class="button refresh-users">Refresh</button>
                </div>
                
                <div id="people-users-list" class="users-grid">
                    <!-- Users will be loaded here -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Roles Management Modal -->
<div id="roles-modal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h2>Manage Roles & Assignments</h2>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="roles-management-container">
                <div class="roles-filter">
                    <select id="department-filter">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept->id; ?>"><?php echo esc_html($dept->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="status-filter">
                        <option value="">All Statuses</option>
                        <option value="open">Open Positions</option>
                        <option value="filled">Completely Filled</option>
                        <option value="partial">Partially Filled</option>
                    </select>
                    <button type="button" class="button refresh-roles">Refresh</button>
                </div>
                
                <div id="roles-list" class="roles-grid">
                    <!-- Roles will be loaded here -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Departments Management Modal -->
<div id="departments-modal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header">
            <h2>Manage Departments & VPs</h2>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="departments-management-container">
                <div class="departments-actions" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 10px;">
                    <button type="button" class="button refresh-departments">Refresh</button>
                    <button type="button" class="button button-primary auto-assign-vps">Auto-Assign VPs</button>
                    <button type="button" class="button" id="auto-assign-all-vps">Auto-Assign All VPs</button>
                    <button type="button" class="button button-primary" id="add-department-btn">Add New Department</button>
                </div>
                
                <div id="departments-list" class="departments-grid" style="width: 100%; margin-top: 15px;">
                    <!-- Departments will be loaded here -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- PTA Shortcodes Reference -->
<div class="pta-shortcodes-section">
    <h2>Available PTA Shortcodes</h2>
    <p>Use these shortcodes to display PTA roles and organizational information on any page or post.</p>
    
    <div class="shortcode-examples">
        <div class="shortcode-example">
            <h4>Roles Directory</h4>
            <code>[pta-roles-directory department="communications" show_image="true" columns="3" layout="grid"]</code>
            <p><strong>Parameters:</strong></p>
            <ul>
                <li><strong>department:</strong> "communications", "events", "volunteers", etc. (default: all)</li>
                <li><strong>description:</strong> true/false - Show role descriptions (default: false)</li>
                <li><strong>status:</strong> "all", "open", "filled", "partial" - Filter by status (default: all)</li>
                <li><strong>columns:</strong> 1-5 - Number of columns for grid layout (default: 3)</li>
                <li><strong>show_count:</strong> true/false - Show position counts (default: true)</li>
                <li><strong>layout:</strong> "grid", "list", "cards", "team-cards" - Layout style (default: grid)</li>
                <li><strong>show_image:</strong> true/false - Show WordPress profile photos (default: false)</li>
                <li><strong>photo_size:</strong> Number - Photo size in pixels (default: 80)</li>
                <li><strong>show_contact:</strong> true/false - Show email links (default: true)</li>
            </ul>
        </div>
        
        <div class="shortcode-example">
            <h4>Leadership Structure Layout</h4>
            <code>[pta-roles-directory leadership_structure="true" leader_role="president" show_image="true" columns="4"]</code>
            <p>Displays the leader role centered above all other roles in a hierarchical layout.</p>
            <p><strong>Parameters:</strong></p>
            <ul>
                <li><strong>leadership_structure:</strong> true/false - Enable hierarchy layout (default: false)</li>
                <li><strong>leader_role:</strong> Role slug/name to show as leader (default: "president")</li>
                <li><strong>leader_photo_size:</strong> Leader photo size in pixels (default: 120)</li>
                <li>Plus all standard Roles Directory parameters</li>
            </ul>
        </div>
        
        <div class="shortcode-example">
            <h4>Department Roles</h4>
            <code>[pta-department-roles department="communications" show_vp=true show_description=false]</code>
            <p><strong>Parameters:</strong></p>
            <ul>
                <li><strong>department:</strong> Required - Department slug or name</li>
                <li><strong>show_vp:</strong> true/false - Show department VP (default: true)</li>
                <li><strong>show_description:</strong> true/false - Show role descriptions (default: false)</li>
                <li><strong>layout:</strong> "list", "cards" - Layout style (default: list)</li>
            </ul>
        </div>
        
        <div class="shortcode-example">
            <h4>Organization Chart</h4>
            <code>[pta-org-chart department="all" interactive=true height="500px"]</code>
            <p><strong>Parameters:</strong></p>
            <ul>
                <li><strong>department:</strong> "all" or specific department - Scope of chart (default: all)</li>
                <li><strong>interactive:</strong> true/false - Enable click interactions (default: false)</li>
                <li><strong>height:</strong> CSS height value - Chart height (default: 400px)</li>
            </ul>
        </div>
        
        <div class="shortcode-example">
            <h4>Role Card</h4>
            <code>[pta-role-card role="president" show_image="true" photo_size="100" show_contact="true"]</code>
            <p><strong>Parameters:</strong></p>
            <ul>
                <li><strong>role:</strong> Required - Role slug or name</li>
                <li><strong>show_contact:</strong> true/false - Show contact information (default: false)</li>
                <li><strong>show_description:</strong> true/false - Show role description (default: true)</li>
                <li><strong>show_assignments:</strong> true/false - Show current assignments (default: true)</li>
                <li><strong>show_image:</strong> true/false - Show WordPress profile photos (default: false)</li>
                <li><strong>photo_size:</strong> Number - Photo size in pixels (default: 80)</li>
            </ul>
        </div>
        
        <div class="shortcode-example">
            <h4>Department VP</h4>
            <code>[pta-department-vp department="communications" show_email=true]</code>
            <p><strong>Parameters:</strong></p>
            <ul>
                <li><strong>department:</strong> Required - Department slug or name</li>
                <li><strong>show_contact:</strong> true/false - Show contact info (default: false)</li>
                <li><strong>show_email:</strong> true/false - Show email address (default: false)</li>
            </ul>
        </div>
        
        <div class="shortcode-example">
            <h4>Open Positions</h4>
            <code>[pta-open-positions department="all" limit=10 show_description=true]</code>
            <p><strong>Parameters:</strong></p>
            <ul>
                <li><strong>department:</strong> "all" or specific department (default: all)</li>
                <li><strong>limit:</strong> Number of positions to show - use -1 for all (default: -1)</li>
                <li><strong>show_department:</strong> true/false - Show department name (default: true)</li>
                <li><strong>show_description:</strong> true/false - Show role descriptions (default: false)</li>
            </ul>
        </div>
        
        <div class="shortcode-example">
            <h4>User Roles</h4>
            <code>[pta-user-roles user_id=123 show_department=true]</code>
            <p><strong>Parameters:</strong></p>
            <ul>
                <li><strong>user_id:</strong> WordPress user ID - defaults to current user if logged in</li>
                <li><strong>show_department:</strong> true/false - Show department names (default: true)</li>
                <li><strong>show_description:</strong> true/false - Show role descriptions (default: false)</li>
            </ul>
        </div>
    </div>
    
    <div class="beaver-builder-info">
        <h3>🦫 Beaver Builder Integration</h3>
        <p><strong>Drag & Drop Modules Available:</strong></p>
        <ul>
            <li><strong>PTA Roles Directory</strong> - Full-featured roles directory with visual settings</li>
            <li><strong>PTA Department Roles</strong> - Department-specific role display</li>
            <li><strong>PTA Org Chart</strong> - Interactive organizational chart</li>
            <li><strong>PTA Open Positions</strong> - Current openings display</li>
        </ul>
        <p>Find these modules in the <strong>"PTA Tools"</strong> category when editing with Beaver Builder. Each module includes:</p>
        <ul>
            <li>✅ All shortcode parameters as visual form fields</li>
            <li>✅ Color customization options</li>
            <li>✅ Typography controls</li>
            <li>✅ Spacing & layout settings</li>
            <li>✅ Responsive design options</li>
        </ul>
        <p><em>Note: Beaver Builder modules are only available when the Beaver Builder plugin is active.</em></p>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Handle org chart view
    $('#show-org-chart').click(function() {
        loadPTAApp('org-chart');
    });
    
    // Handle management sections
    $('#manage-people').click(function() {
        showPeopleModal();
    });
    
    $('#manage-roles').click(function() {
        showRolesModal();
    });
    
    $('#manage-departments').click(function() {
        showDepartmentsModal();
    });
    
    // Initialize event handlers for elements that exist on page load
    bindDepartmentsActions();
    bindRolesActions();
    
    // Test sync connection
    $('.test-pta-sync').click(function() {
        var button = $(this);
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Testing...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_test_sync',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Test Sync Connection');
            
            if (response.success) {
                alert('✅ Sync connection successful!\n\n' + response.data.message);
            } else {
                alert('❌ Sync connection failed: ' + (response.data || 'Unknown error'));
            }
        }).fail(function() {
            button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Test Sync Connection');
            alert('❌ Network error occurred');
        });
    });

    // Reimport Default Tables functionality
    $('.reimport-default-tables').click(function() {
        var button = $(this);
        var originalHtml = button.html();
        
        if (!confirm('⚠️ Warning: This will DELETE all existing roles and departments and recreate them from the CSV file.\n\nAny custom roles or departments you added will be lost.\n\nAre you sure you want to continue?')) {
            return;
        }
        
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Reimporting...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_reimport_default_tables',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html(originalHtml);
            
            if (response.success) {
                alert('✅ Default tables reimported successfully!\n\n' + response.data.message);
                // Refresh the page to show updated data
                window.location.reload();
            } else {
                alert('❌ Reimport failed:\n\n' + response.data);
            }
        }).fail(function(xhr) {
            button.prop('disabled', false).html(originalHtml);
            alert('❌ Reimport failed:\n\n' + (xhr.responseJSON ? xhr.responseJSON.data : 'Unknown error'));
        });
    });
    
    // Import Roles from Azure AD (One-time pull)
    $('.import-roles-from-azure').click(function() {
        var button = $(this);
        var originalHtml = button.html();
        
        if (!confirm('📥 One-Time Pull from Azure AD\n\nThis will:\n✓ Fetch all users from Azure AD\n✓ Read their jobTitle field (comma-separated roles)\n✓ Assign matching PTA roles to WordPress users\n\nNote: This is a ONE-TIME import. After this, WordPress will be the source of truth and changes will sync TO Azure AD.\n\nContinue?')) {
            return;
        }
        
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Importing from Azure...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_import_roles_from_azure',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html(originalHtml);
            
            if (response.success) {
                var message = '✅ Import from Azure AD complete!\n\n' + response.data.message;
                
                if (response.data.errors && response.data.errors.length > 0) {
                    message += '\n\n⚠️ First few warnings:\n' + response.data.errors.join('\n');
                }
                
                alert(message);
                // Refresh the page to show updated assignments
                window.location.reload();
            } else {
                alert('❌ Import failed:\n\n' + response.data);
            }
        }).fail(function(xhr) {
            button.prop('disabled', false).html(originalHtml);
            alert('❌ Import failed:\n\n' + (xhr.responseJSON ? xhr.responseJSON.data : 'Unknown error'));
        });
    });
    
    // Process Sync Queue
    $('.process-sync-queue').click(function() {
        var button = $(this);
        var originalHtml = button.html();
        
        if (!confirm('This will process pending sync queue items.\n\nNote: Since Azure AD write-back may not be configured, items will be marked as completed.\n\nContinue?')) {
            return;
        }
        
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Processing...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_process_sync_queue',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html(originalHtml);
            
            if (response.success) {
                alert('✅ ' + response.data.message + '\n\nPending before: ' + response.data.pending_before + '\nPending after: ' + response.data.pending_after);
                window.location.reload();
            } else {
                alert('❌ Processing failed: ' + (response.data || 'Unknown error'));
            }
        }).fail(function(xhr) {
            button.prop('disabled', false).html(originalHtml);
            alert('❌ Processing failed:\n\n' + (xhr.responseJSON ? xhr.responseJSON.data : 'Unknown error'));
        });
    });
    
    // Clear Sync Queue (Pending or Failed)
    $('.clear-pending-queue, .clear-failed-queue').click(function() {
        var button = $(this);
        var originalHtml = button.html();
        var clearType = button.data('type');
        var typeLabel = clearType === 'pending' ? 'pending' : 'failed';
        
        if (!confirm('Are you sure you want to clear all ' + typeLabel + ' sync queue items?\n\nThis action cannot be undone.')) {
            return;
        }
        
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Clearing...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_clear_sync_queue',
            clear_type: clearType,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html(originalHtml);
            
            if (response.success) {
                alert('✅ ' + response.data.message + '\n\nRemaining items: ' + response.data.remaining);
                window.location.reload();
            } else {
                alert('❌ Clear failed: ' + (response.data || 'Unknown error'));
            }
        }).fail(function(xhr) {
            button.prop('disabled', false).html(originalHtml);
            alert('❌ Clear failed:\n\n' + (xhr.responseJSON ? xhr.responseJSON.data : 'Unknown error'));
        });
    });
    
    // Event handlers for buttons in the static unassigned users section
    $('.unassigned-users-section .assign-role-btn').on('click', function() {
        var userId = $(this).data('user-id');
        $('#assignment-user-id').val(userId);
        $('#role-assignment-modal').show();
    });
    
    $('.unassigned-users-section .delete-user-btn').on('click', function() {
        var userId = $(this).data('user-id');
        var userName = $(this).data('user-name');
        
        if (!confirm('Are you sure you want to delete ' + userName + '?\n\nThis will:\n- Remove them from WordPress\n- Remove them from Azure AD\n- Revoke all access\n\nThis action cannot be undone.')) {
            return;
        }
        
        var button = $(this);
        var userDiv = button.closest('.unassigned-user');
        
        button.prop('disabled', true).text('Deleting...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_delete_user',
            user_id: userId,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                alert('✅ User deleted successfully!');
                userDiv.fadeOut(function() {
                    userDiv.remove();
                });
            } else {
                alert('❌ Failed to delete user: ' + (response.data || 'Unknown error'));
                button.prop('disabled', false).text('Delete User');
            }
        }).fail(function(xhr, status, error) {
            alert('❌ Network error: ' + error);
            button.prop('disabled', false).text('Delete User');
            console.error('Delete user AJAX error:', xhr, status, error);
        });
    });
    
    // Note: Assign role, delete user, and edit user buttons are now handled 
    // by delegated event handlers in bindPeopleActions() for dynamically created elements
    
    // Handle role assignment form
    $('#role-assignment-form').submit(function(e) {
        e.preventDefault();
        
        var formData = $(this).serialize();
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_assign_role',
            nonce: azure_plugin_ajax.nonce,
            user_id: $('#assignment-user-id').val(),
            role_id: $('#assignment-role-id').val(),
            is_primary: $('#assignment-is-primary').is(':checked')
        }, function(response) {
            if (response.success) {
                alert('✅ Role assigned successfully!');
                $('#role-assignment-modal').css('z-index', '').hide();
                location.reload();
            } else {
                alert('❌ Failed to assign role: ' + (response.data || 'Unknown error'));
            }
        });
    });
    
    // Modal controls
    $('.modal-close, .modal-cancel').click(function() {
        var modal = $(this).closest('.modal');
        // Reset z-index when closing
        modal.css('z-index', '').hide();
    });
    
    // Handle refresh buttons in modals
    $('.refresh-users').click(function() {
        loadUsersAndRoles();
    });
    
    $('.refresh-roles').click(function() {
        loadRolesList();
    });
    
    $('.refresh-departments').click(function() {
        loadDepartmentsList();
    });
    
    $('.auto-assign-vps').click(function() {
        assignAllVPs();
    });
    
    // Load React PTA App
    function loadPTAApp(section) {
        $('#pta-react-app').show();
        
        // This would load the React application
        // For now, show a placeholder message
        $('#pta-react-app').html('<div class="pta-app-placeholder"><h3>' + section.charAt(0).toUpperCase() + section.slice(1) + ' Management</h3><p>React-based interface will be loaded here.</p><button class="button" onclick="$(\'#pta-react-app\').hide();">Close</button></div>');
    }
    
    // Show People Management Modal
    function showPeopleModal() {
        loadUsersAndRoles();
        $('#people-modal').show();
    }
    
    // Show Roles Management Modal  
    function showRolesModal() {
        loadRolesList();
        $('#roles-modal').show();
    }
    
    // Show Departments Management Modal
    function showDepartmentsModal() {
        loadDepartmentsList();
        $('#departments-modal').show();
    }
    
    // Load users and roles for assignment
    function loadUsersAndRoles() {
        // Load WordPress users synced from Azure AD
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_get_users',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                var usersList = $('#people-users-list');
                usersList.empty();
                
                // Add header with actions and bulk controls
                var header = $('<div class="modal-section-header">' +
                    '<h4>People Management - Azure AD Synced Users</h4>' +
                    '<div class="section-actions">' +
                        '<input type="text" id="people-search-input" placeholder="Search people..." class="search-input">' +
                        '<button type="button" class="button" id="select-all-users">Select All</button>' +
                        '<button type="button" class="button" id="clear-selection-users">Clear</button>' +
                    '</div>' +
                '</div>');
                usersList.append(header);
                
                // Add bulk actions bar
                var bulkBar = $('<div class="bulk-actions-bar" style="margin: 10px 0; padding: 10px; background: #f9f9f9; border-left: 4px solid #0073aa; display: none;">' +
                    '<strong>Bulk Actions:</strong> ' +
                    '<select id="bulk-action-select">' +
                        '<option value="">-- Choose Action --</option>' +
                        '<option value="delete">Delete Users</option>' +
                        '<option value="add-role">Add Role</option>' +
                        '<option value="replace-role">Replace All Roles</option>' +
                    '</select> ' +
                    '<select id="bulk-role-select" style="display: none;"></select> ' +
                    '<label style="display: none;"><input type="checkbox" id="bulk-is-primary"> Make Primary Role</label> ' +
                    '<button type="button" class="button button-primary" id="apply-bulk-action">Apply</button> ' +
                    '<span id="selected-count" style="margin-left: 10px;"></span>' +
                '</div>');
                usersList.append(bulkBar);
                
                // Create users table with improved columns
                var table = $('<table class="wp-list-table widefat fixed striped">' +
                    '<thead>' +
                        '<tr>' +
                            '<th style="width: 40px;"><input type="checkbox" id="select-all-checkbox"></th>' +
                            '<th>Name</th>' +
                            '<th>Email</th>' +
                            '<th>Azure Status</th>' +
                            '<th>Current Roles</th>' +
                            '<th>Job Title</th>' +
                            '<th>Last Login</th>' +
                            '<th>Actions</th>' +
                        '</tr>' +
                    '</thead>' +
                    '<tbody id="users-table-body"></tbody>' +
                '</table>');
                usersList.append(table);
                
                var tableBody = $('#users-table-body');
                
                response.data.forEach(function(user) {
                    var isUnassigned = !user.roles;
                    var rolesList = user.roles || '<em style="color: #999;">No roles assigned</em>';
                    var azureStatus = user.is_azure_user ? 
                        '<span style="color: green;">✓ Azure AD</span>' : 
                        '<span style="color: orange;">⚠ Local Only</span>';
                    
                    var jobTitle = user.job_title || '<em style="color: #999;">Not set</em>';
                    var lastLogin = user.last_login ? 
                        new Date(user.last_login).toLocaleDateString() : 
                        '<em style="color: #999;">Never</em>';
                    
                    var actions = '<button type="button" class="button button-small assign-role-btn" data-user-id="' + user.ID + '">Assign Role</button>';
                    // Show Unassign Role button only if user has roles
                    if (!isUnassigned && user.roles && user.roles.length > 0) {
                        actions += ' <button type="button" class="button button-small unassign-role-btn" data-user-id="' + user.ID + '" data-user-name="' + user.display_name + '">Unassign Role</button>';
                    }
                    if (isUnassigned) {
                        actions += ' <button type="button" class="button button-small button-link-delete delete-user-btn" data-user-id="' + user.ID + '" data-user-name="' + user.display_name + '">Delete</button>';
                    }
                    actions += ' <button type="button" class="button button-small edit-user-btn" data-user-id="' + user.ID + '">Details</button>';
                    
                    var rowClass = isUnassigned ? ' class="unassigned-user-row" style="background-color: #fff2cc;"' : '';
                    var row = $('<tr' + rowClass + '>' +
                        '<td><input type="checkbox" class="user-checkbox" value="' + user.ID + '"></td>' +
                        '<td><strong>' + user.display_name + '</strong><br><small>' + user.user_login + '</small></td>' +
                        '<td>' + user.user_email + '</td>' +
                        '<td>' + azureStatus + '</td>' +
                        '<td>' + rolesList + '</td>' +
                        '<td>' + jobTitle + '</td>' +
                        '<td>' + lastLogin + '</td>' +
                        '<td>' + actions + '</td>' +
                    '</tr>');
                    tableBody.append(row);
                });
                
                // Add summary info
                var summary = $('<div style="margin-top: 10px; padding: 10px; background: #f0f0f1;">' +
                    '<strong>Summary:</strong> ' + response.data.length + ' total users, ' +
                    response.data.filter(function(u) { return !u.has_roles; }).length + ' unassigned, ' +
                    response.data.filter(function(u) { return u.is_azure_user; }).length + ' from Azure AD' +
                '</div>');
                usersList.append(summary);
                
                // Add event handlers for new functionality
                bindPeopleActions();
                bindBulkActions();
            } else {
                alert('❌ Failed to load users: ' + (response.data || 'Unknown error'));
            }
        });
        
        // Load available roles
        loadRolesForAssignment();
    }
    
    // Load roles for bulk actions
    function loadRolesForBulkActions() {
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_get_roles',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                var bulkRoleSelect = $('#bulk-role-select');
                bulkRoleSelect.empty().append('<option value="">-- Select Role --</option>');
                
                response.data.forEach(function(role) {
                    var assignedCount = role.assigned_count || 0;
                    var available = role.max_occupants - assignedCount;
                    var statusText = available > 0 ? ' (' + available + ' available)' : ' (FULL)';
                    var disabled = available <= 0 ? 'disabled' : '';
                    bulkRoleSelect.append('<option value="' + role.id + '" ' + disabled + '>' + role.name + statusText + '</option>');
                });
            }
        });
    }
    
    // Bind bulk actions event handlers
    function bindBulkActions() {
        // Select all checkbox functionality
        $('#select-all-checkbox').on('change', function() {
            $('.user-checkbox').prop('checked', $(this).prop('checked'));
            updateBulkActionsBar();
        });
        
        // Individual checkbox functionality
        $(document).on('change', '.user-checkbox', function() {
            updateBulkActionsBar();
            
            // Update select all checkbox state
            var totalCheckboxes = $('.user-checkbox').length;
            var checkedCheckboxes = $('.user-checkbox:checked').length;
            $('#select-all-checkbox').prop('indeterminate', checkedCheckboxes > 0 && checkedCheckboxes < totalCheckboxes);
            $('#select-all-checkbox').prop('checked', checkedCheckboxes === totalCheckboxes);
        });
        
        // Bulk action selection
        $('#bulk-action-select').on('change', function() {
            var action = $(this).val();
            if (action === 'add-role' || action === 'replace-role') {
                $('#bulk-role-select, label').show();
                loadRolesForBulkActions();
            } else {
                $('#bulk-role-select, label').hide();
            }
        });
        
        // Select all/clear buttons
        $('#select-all-users').on('click', function() {
            $('.user-checkbox').prop('checked', true);
            $('#select-all-checkbox').prop('checked', true);
            updateBulkActionsBar();
        });
        
        $('#clear-selection-users').on('click', function() {
            $('.user-checkbox').prop('checked', false);
            $('#select-all-checkbox').prop('checked', false);
            updateBulkActionsBar();
        });
        
        // Apply bulk action
        $('#apply-bulk-action').on('click', function() {
            var selectedUsers = $('.user-checkbox:checked').map(function() {
                return $(this).val();
            }).get();
            
            var action = $('#bulk-action-select').val();
            
            if (selectedUsers.length === 0) {
                alert('Please select at least one user.');
                return;
            }
            
            if (!action) {
                alert('Please select an action.');
                return;
            }
            
            if (action === 'delete') {
                applyBulkDelete(selectedUsers);
            } else if (action === 'add-role' || action === 'replace-role') {
                applyBulkRoleChange(selectedUsers, action);
            }
        });
    }
    
    // Update bulk actions bar visibility and counter
    function updateBulkActionsBar() {
        var checkedCount = $('.user-checkbox:checked').length;
        var bulkBar = $('.bulk-actions-bar');
        
        if (checkedCount > 0) {
            bulkBar.show();
            $('#selected-count').text('(' + checkedCount + ' selected)');
        } else {
            bulkBar.hide();
            $('#selected-count').text('');
        }
    }
    
    // Apply bulk delete
    function applyBulkDelete(userIds) {
        if (!confirm('Are you sure you want to delete ' + userIds.length + ' user(s)?\n\nThis will:\n- Remove them from WordPress\n- Remove them from Azure AD\n- Revoke all access\n\nThis action cannot be undone.')) {
            return;
        }
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_bulk_delete_users',
            user_ids: userIds,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                alert('✅ ' + response.data.message);
                loadUsersAndRoles(); // Refresh the list
            } else {
                alert('❌ Bulk delete failed: ' + (response.data || 'Unknown error'));
            }
        });
    }
    
    // Apply bulk role change
    function applyBulkRoleChange(userIds, actionType) {
        var roleId = $('#bulk-role-select').val();
        var isPrimary = $('#bulk-is-primary').prop('checked');
        
        if (!roleId) {
            alert('Please select a role.');
            return;
        }
        
        var actionText = actionType === 'add-role' ? 'add' : 'replace all roles with';
        var confirmText = 'Are you sure you want to ' + actionText + ' the selected role for ' + userIds.length + ' user(s)?';
        
        if (isPrimary) {
            confirmText += '\n\nThis will also set it as their primary role.';
        }
        
        if (!confirm(confirmText)) {
            return;
        }
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_bulk_change_role',
            user_ids: userIds,
            role_id: roleId,
            action_type: actionType === 'add-role' ? 'add' : 'replace',
            is_primary: isPrimary,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                alert('✅ ' + response.data.message);
                loadUsersAndRoles(); // Refresh the list
            } else {
                alert('❌ Bulk role change failed: ' + (response.data || 'Unknown error'));
            }
        });
    }
    
    // Load roles list for management
    function loadRolesList() {
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_get_roles',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            console.log('PTA Roles AJAX Response:', response); // Debug logging
            if (response.success && response.data && response.data.length > 0) {
                var rolesList = $('#roles-list');
                rolesList.empty();
                
                // Add header with actions
                var header = $('<div class="modal-section-header"><h4>Roles Management</h4><div class="section-actions"><select id="role-dept-filter"><option value="">All Departments</option></select><button type="button" class="button button-primary" id="add-role-btn">Add New Role</button></div></div>');
                rolesList.append(header);
                
                // Create roles table
                var table = $('<table class="wp-list-table widefat fixed striped"><thead><tr><th>Role Name</th><th>Department</th><th>Occupancy</th><th>Status</th><th>Actions</th></tr></thead><tbody id="roles-table-body"></tbody></table>');
                rolesList.append(table);
                
                var tableBody = $('#roles-table-body');
                var deptFilter = $('#role-dept-filter');
                var departments = [];
                
                response.data.forEach(function(role) {
                    // Use assigned_count (number) instead of assignments (array)
                    var assignedCount = role.assigned_count || 0;
                    var maxOccupants = role.max_occupants || 1;
                    
                    var statusClass = assignedCount >= maxOccupants ? 'filled' : (assignedCount > 0 ? 'partial' : 'open');
                    var statusText = assignedCount >= maxOccupants ? 'Filled' : (assignedCount > 0 ? 'Partially Filled' : 'Open');
                    
                    var actions = '<button type="button" class="button button-small view-role-assignments" data-role-id="' + role.id + '">View Assignments</button>';
                    actions += ' <button type="button" class="button button-small edit-role-btn" data-role-id="' + role.id + '">Edit</button>';
                    actions += ' <button type="button" class="button button-small button-link-delete delete-role-btn" data-role-id="' + role.id + '">Delete</button>';
                    
                    var row = $('<tr><td><strong>' + role.name + '</strong><br><small>' + (role.description || '') + '</small></td><td>' + role.department_name + '</td><td>' + assignedCount + ' / ' + maxOccupants + '</td><td><span class="status-badge ' + statusClass + '">' + statusText + '</span></td><td>' + actions + '</td></tr>');
                    tableBody.append(row);
                    
                    // Collect departments for filter
                    if (departments.indexOf(role.department_name) === -1) {
                        departments.push(role.department_name);
                    }
                });
                
                // Populate department filter
                departments.sort().forEach(function(dept) {
                    deptFilter.append('<option value="' + dept + '">' + dept + '</option>');
                });
                
                // Add event handlers for new buttons
                bindRolesActions();
            } else if (response.success && response.data && response.data.length === 0) {
                // No roles found
                var rolesList = $('#roles-list');
                rolesList.empty();
                rolesList.html('<div class="notice notice-info inline"><p><strong>No roles found.</strong> Use the "Reimport Default Tables" button to load roles from the CSV file, or add roles manually using the "Add New Role" button.</p></div>');
            } else {
                // AJAX error
                var rolesList = $('#roles-list');
                rolesList.empty();
                rolesList.html('<div class="notice notice-error inline"><p><strong>Error loading roles:</strong> ' + (response.data || 'Unknown error') + '</p></div>');
                console.error('Roles loading failed:', response);
            }
        });
    }
    
    // Load departments list for management
    function loadDepartmentsList() {
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_get_departments',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                var deptsList = $('#departments-list');
                deptsList.empty();
                
                // Create departments table (full width)
                var table = $('<table class="wp-list-table widefat fixed striped" style="width: 100%;"><thead><tr><th>Department Name</th><th>VP</th><th>Total Roles</th><th>Filled Positions</th><th>Actions</th></tr></thead><tbody id="departments-table-body"></tbody></table>');
                deptsList.append(table);
                
                var tableBody = $('#departments-table-body');
                
                response.data.forEach(function(dept) {
                    var vpName = dept.vp_info && dept.vp_info.display_name ? dept.vp_info.display_name : 'No VP assigned';
                    var vpClass = dept.vp_info ? '' : 'no-vp';
                    
                    var actions = '<button type="button" class="button button-small assign-vp-btn" data-dept-id="' + dept.id + '">Assign VP</button>';
                    actions += ' <button type="button" class="button button-small view-dept-roles" data-dept-id="' + dept.id + '">View Roles</button>';
                    actions += ' <button type="button" class="button button-small edit-dept-btn" data-dept-id="' + dept.id + '">Edit</button>';
                    actions += ' <button type="button" class="button button-small button-link-delete delete-dept-btn" data-dept-id="' + dept.id + '">Delete</button>';
                    
                    var row = $('<tr><td><strong>' + dept.name + '</strong></td><td class="' + vpClass + '">' + vpName + '</td><td>' + (dept.roles ? dept.roles.length : 0) + '</td><td>' + (dept.filled_positions || 0) + '</td><td>' + actions + '</td></tr>');
                    tableBody.append(row);
                });
                
                // Add event handlers for new buttons
                bindDepartmentsActions();
            } else {
                alert('❌ Failed to load departments: ' + (response.data || 'Unknown error'));
            }
        });
    }
    
    // Load roles for assignment dropdown
    function loadRolesForAssignment() {
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_get_roles',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                var roleSelect = $('#assignment-role-id');
                roleSelect.empty().append('<option value="">-- Select Role --</option>');
                
                response.data.forEach(function(role) {
                    var assignedCount = role.assigned_count || 0;
                    var available = role.max_occupants - assignedCount;
                    var disabled = available <= 0 ? 'disabled' : '';
                    roleSelect.append('<option value="' + role.id + '" ' + disabled + '>' + role.name + ' (' + available + ' available)</option>');
                });
            }
        });
    }
    
    // Auto-assign VPs to departments
    function assignAllVPs() {
        if (!confirm('This will automatically assign VPs to departments where none are assigned. Continue?')) {
            return;
        }
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_auto_assign_vps',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                alert('✅ VPs assigned successfully! Assigned ' + (response.data.count || 0) + ' VPs.');
                loadDepartmentsList(); // Refresh the departments list
            } else {
                alert('❌ Failed to assign VPs: ' + (response.data || 'Unknown error'));
            }
        });
    }
    
    // Bind event handlers for People actions
    function bindPeopleActions() {
        $('#people-search-input').on('keyup', function() {
            var searchTerm = $(this).val().toLowerCase();
            $('#users-table-body tr').each(function() {
                var text = $(this).text().toLowerCase();
                $(this).toggle(text.indexOf(searchTerm) > -1);
            });
        });
        
        $(document).on('click', '#add-person-btn', function() {
            // This would open a form to add a new WordPress user
            alert('Add New Person functionality would be implemented here.\nNote: Users are typically created through WordPress admin or SSO.');
        });
        
        $(document).on('click', '.assign-role-btn', function() {
            var userId = $(this).data('user-id');
            $('#assignment-user-id').val(userId);
            // Set higher z-index to appear in front of other modals
            $('#role-assignment-modal').css('z-index', '10000').show();
        });
        
        // Unassign role button handler
        $(document).on('click', '.unassign-role-btn', function() {
            var userId = $(this).data('user-id');
            var userName = $(this).data('user-name');
            showUnassignRoleModal(userId, userName);
        });
        
        $(document).on('click', '.delete-user-btn', function() {
            var userId = $(this).data('user-id');
            var userName = $(this).data('user-name');
            
            if (!confirm('Are you sure you want to delete ' + userName + '?\n\nThis will:\n- Remove them from WordPress\n- Remove them from Azure AD\n- Revoke all access\n\nThis action cannot be undone.')) {
                return;
            }
            
            var button = $(this);
            var row = button.closest('tr');
            
            button.prop('disabled', true).text('Deleting...');
            
            $.post(azure_plugin_ajax.ajax_url, {
                action: 'pta_delete_user',
                user_id: userId,
                nonce: azure_plugin_ajax.nonce
            }, function(response) {
                if (response.success) {
                    alert('✅ User deleted successfully!');
                    row.fadeOut(function() {
                        row.remove();
                    });
                } else {
                    alert('❌ Failed to delete user: ' + (response.data || 'Unknown error'));
                    button.prop('disabled', false).text('Delete');
                }
            }).fail(function(xhr, status, error) {
                alert('❌ Network error: ' + error);
                button.prop('disabled', false).text('Delete');
                console.error('Delete user AJAX error:', xhr, status, error);
            });
        });
        
        $(document).on('click', '.edit-user-btn', function() {
            var userId = $(this).data('user-id');
            showUserDetails(userId);
        });
    }
    
    // Bind event handlers for Roles actions
    function bindRolesActions() {
        $('#role-dept-filter').on('change', function() {
            var selectedDept = $(this).val();
            $('#roles-table-body tr').each(function() {
                var deptCell = $(this).find('td:nth-child(2)').text();
                $(this).toggle(selectedDept === '' || deptCell === selectedDept);
            });
        });
        
        $(document).on('click', '#add-role-btn', function() {
            showAddRoleForm();
        });
        
        $(document).on('click', '.view-role-assignments', function() {
            var roleId = $(this).data('role-id');
            showRoleAssignments(roleId);
        });
        
        $(document).on('click', '.edit-role-btn', function() {
            var roleId = $(this).data('role-id');
            showEditRoleForm(roleId);
        });
        
        $(document).on('click', '.delete-role-btn', function() {
            var roleId = $(this).data('role-id');
            if (confirm('Are you sure you want to delete this role? This action cannot be undone.')) {
                deleteRole(roleId);
            }
        });
    }
    
    // Bind event handlers for Departments actions
    function bindDepartmentsActions() {
        $(document).on('click', '#add-department-btn', function() {
            showAddDepartmentForm();
        });
        
        $(document).on('click', '#auto-assign-all-vps', function() {
            assignAllVPs();
        });
        
        // Assign VP button handler
        $(document).on('click', '.assign-vp-btn', function() {
            var deptId = $(this).data('dept-id');
            showAssignVPModal(deptId);
        });
        
        // View Details button on main page department cards
        $(document).on('click', '.view-dept-details', function() {
            var deptId = $(this).data('dept-id');
            showDepartmentDetails(deptId);
        });
        
        $(document).on('click', '.edit-dept-btn', function() {
            var deptId = $(this).data('dept-id');
            showEditDepartmentForm(deptId);
        });
        
        $(document).on('click', '.delete-dept-btn', function() {
            var deptId = $(this).data('dept-id');
            if (confirm('Are you sure you want to delete this department? This action cannot be undone.')) {
                deleteDepartment(deptId);
            }
        });
        
        $(document).on('click', '.view-dept-roles', function() {
            var deptId = $(this).data('dept-id');
            // Filter roles modal to show only this department's roles
            showRolesModal();
            setTimeout(function() {
                var deptName = $('[data-dept-id="' + deptId + '"]').closest('tr').find('td:first strong').text();
                $('#role-dept-filter').val(deptName).trigger('change');
            }, 500);
        });
    }
    
    // CRUD Functions
    function deleteRole(roleId) {
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_delete_role',
            role_id: roleId,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                alert('✅ Role deleted successfully!');
                loadRolesList(); // Refresh the list
            } else {
                alert('❌ Failed to delete role: ' + (response.data || 'Unknown error'));
            }
        });
    }
    
    function deleteDepartment(deptId) {
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_delete_department',
            dept_id: deptId,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                alert('✅ Department deleted successfully!');
                loadDepartmentsList(); // Refresh the list
            } else {
                alert('❌ Failed to delete department: ' + (response.data || 'Unknown error'));
            }
        });
    }
    
    function showAddRoleForm() {
        // This would show a form modal to add a new role
        var formHtml = `
            <div id="add-role-form-modal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Add New Role</h3>
                        <button type="button" class="modal-close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form id="add-role-form">
                            <table class="form-table">
                                <tr><th>Role Name</th><td><input type="text" name="name" required class="regular-text"></td></tr>
                                <tr><th>Department</th><td><select name="department_id" required><option value="">Select Department</option></select></td></tr>
                                <tr><th>Max Occupants</th><td><input type="number" name="max_occupants" value="1" min="1" class="small-text"></td></tr>
                                <tr><th>Description</th><td><textarea name="description" class="large-text"></textarea></td></tr>
                            </table>
                            <p class="submit">
                                <button type="submit" class="button button-primary">Add Role</button>
                                <button type="button" class="button modal-close">Cancel</button>
                            </p>
                        </form>
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(formHtml);
        
        // Load departments for dropdown
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_get_departments',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                var select = $('#add-role-form select[name="department_id"]');
                response.data.forEach(function(dept) {
                    select.append('<option value="' + dept.id + '">' + dept.name + '</option>');
                });
            }
        });
        
        $('#add-role-form-modal').show();
        
        // Handle form submission
        $('#add-role-form').submit(function(e) {
            e.preventDefault();
            var formData = $(this).serialize();
            
            $.post(azure_plugin_ajax.ajax_url, formData + '&action=pta_create_role&nonce=' + azure_plugin_ajax.nonce, function(response) {
                if (response.success) {
                    alert('✅ Role created successfully!');
                    $('#add-role-form-modal').remove();
                    loadRolesList(); // Refresh the list
                } else {
                    alert('❌ Failed to create role: ' + (response.data || 'Unknown error'));
                }
            });
        });
    }
    
    function showAddDepartmentForm() {
        // This would show a form modal to add a new department
        var formHtml = `
            <div id="add-dept-form-modal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Add New Department</h3>
                        <button type="button" class="modal-close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form id="add-dept-form">
                            <table class="form-table">
                                <tr><th>Department Name</th><td><input type="text" name="name" required class="regular-text"></td></tr>
                                <tr><th>VP (Optional)</th><td><select name="vp_user_id"><option value="">No VP</option></select></td></tr>
                            </table>
                            <p class="submit">
                                <button type="submit" class="button button-primary">Add Department</button>
                                <button type="button" class="button modal-close">Cancel</button>
                            </p>
                        </form>
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(formHtml);
        
        // Load users for VP dropdown
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_get_users',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                var select = $('#add-dept-form select[name="vp_user_id"]');
                response.data.forEach(function(user) {
                    select.append('<option value="' + user.ID + '">' + user.display_name + '</option>');
                });
            }
        });
        
        $('#add-dept-form-modal').show();
        
        // Handle form submission
        $('#add-dept-form').submit(function(e) {
            e.preventDefault();
            var formData = $(this).serialize();
            
            $.post(azure_plugin_ajax.ajax_url, formData + '&action=pta_create_department&nonce=' + azure_plugin_ajax.nonce, function(response) {
                if (response.success) {
                    alert('✅ Department created successfully!');
                    $('#add-dept-form-modal').remove();
                    loadDepartmentsList(); // Refresh the list
                } else {
                    alert('❌ Failed to create department: ' + (response.data || 'Unknown error'));
                }
            });
        });
    }
    
    // Show Unassign Role Modal
    function showUnassignRoleModal(userId, userName) {
        $('#unassign-user-id').val(userId);
        $('#unassign-user-info').html('Unassigning roles from: <strong>' + userName + '</strong>');
        $('#unassign-roles-list').html('<p class="loading">Loading roles...</p>');
        $('#unassign-select-all').prop('checked', false);
        
        // Set higher z-index to appear in front of other modals
        $('#unassign-role-modal').css('z-index', '10001').show();
        
        // Fetch user's current role assignments
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_get_assignments',
            user_id: userId,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success && response.data && response.data.length > 0) {
                var rolesHtml = '';
                response.data.forEach(function(assignment) {
                    var primaryBadge = assignment.is_primary ? ' <span style="color: #0073aa; font-weight: bold;">(Primary)</span>' : '';
                    rolesHtml += '<div class="unassign-role-item" style="padding: 8px; border-bottom: 1px solid #eee;">';
                    rolesHtml += '<label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">';
                    rolesHtml += '<input type="checkbox" class="unassign-role-checkbox" value="' + assignment.role_id + '" data-role-name="' + assignment.role_name + '">';
                    rolesHtml += '<span><strong>' + assignment.role_name + '</strong>' + primaryBadge + '<br><small style="color: #666;">' + (assignment.department_name || 'No Department') + '</small></span>';
                    rolesHtml += '</label>';
                    rolesHtml += '</div>';
                });
                $('#unassign-roles-list').html(rolesHtml);
            } else {
                $('#unassign-roles-list').html('<p><em>This user has no role assignments.</em></p>');
            }
        }).fail(function() {
            $('#unassign-roles-list').html('<p style="color: red;">Failed to load role assignments.</p>');
        });
    }
    
    // Handle select all checkbox for unassign
    $('#unassign-select-all').on('change', function() {
        var isChecked = $(this).prop('checked');
        $('.unassign-role-checkbox').prop('checked', isChecked);
    });
    
    // Handle unassign role form submission
    $('#unassign-role-form').on('submit', function(e) {
        e.preventDefault();
        
        var userId = $('#unassign-user-id').val();
        var selectedRoles = [];
        var selectedRoleNames = [];
        
        $('.unassign-role-checkbox:checked').each(function() {
            selectedRoles.push($(this).val()); // This is now role_id
            selectedRoleNames.push($(this).data('role-name'));
        });
        
        if (selectedRoles.length === 0) {
            alert('Please select at least one role to unassign.');
            return;
        }
        
        var confirmMsg = 'Are you sure you want to unassign the following role(s)?\n\n';
        confirmMsg += selectedRoleNames.join('\n');
        confirmMsg += '\n\nThis action cannot be undone.';
        
        if (!confirm(confirmMsg)) {
            return;
        }
        
        var submitBtn = $(this).find('button[type="submit"]');
        var originalText = submitBtn.text();
        submitBtn.prop('disabled', true).text('Unassigning...');
        
        // Process each role removal
        var completed = 0;
        var errors = [];
        
        selectedRoles.forEach(function(roleId) {
            $.post(azure_plugin_ajax.ajax_url, {
                action: 'pta_remove_assignment',
                user_id: userId,
                role_id: roleId,
                nonce: azure_plugin_ajax.nonce
            }, function(response) {
                completed++;
                if (!response.success) {
                    errors.push(response.data || 'Unknown error');
                }
                
                // Check if all requests completed
                if (completed === selectedRoles.length) {
                    submitBtn.prop('disabled', false).text(originalText);
                    
                    if (errors.length === 0) {
                        alert('✅ Successfully unassigned ' + selectedRoles.length + ' role(s)!');
                        $('#unassign-role-modal').hide().css('z-index', '');
                        loadUsersList(); // Refresh the people list
                    } else {
                        alert('⚠️ Some roles could not be unassigned:\n\n' + errors.join('\n'));
                        $('#unassign-role-modal').hide().css('z-index', '');
                        loadUsersList();
                    }
                }
            }).fail(function() {
                completed++;
                errors.push('Network error');
                
                if (completed === selectedRoles.length) {
                    submitBtn.prop('disabled', false).text(originalText);
                    alert('⚠️ Some roles could not be unassigned:\n\n' + errors.join('\n'));
                    $('#unassign-role-modal').hide().css('z-index', '');
                    loadUsersList();
                }
            });
        });
    });
    
    // Close unassign modal handlers
    $('#unassign-role-modal .modal-close, #unassign-role-modal .modal-cancel').on('click', function() {
        $('#unassign-role-modal').hide().css('z-index', '');
    });
    
    function showUserDetails(userId) {
        // Fetch and display user details and role assignments
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_get_assignments',
            user_id: userId,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                var assignments = response.data;
                var user = $('#users-table-body tr').find('[data-user-id="' + userId + '"]').closest('tr');
                var userName = user.find('td:nth-child(2) strong').text();
                var userEmail = user.find('td:nth-child(3)').text();
                
                var detailsHtml = '<div id="user-details-modal" class="modal"><div class="modal-content"><div class="modal-header"><h3>User Details: ' + userName + '</h3><button type="button" class="modal-close">&times;</button></div><div class="modal-body">';
                detailsHtml += '<p><strong>Email:</strong> ' + userEmail + '</p>';
                detailsHtml += '<h4>Role Assignments:</h4>';
                
                if (assignments && assignments.length > 0) {
                    detailsHtml += '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Role</th><th>Department</th><th>Primary</th><th>Actions</th></tr></thead><tbody>';
                    assignments.forEach(function(assignment) {
                        var primaryBadge = assignment.is_primary ? '<span style="color: green;">✓ Primary</span>' : '';
                        detailsHtml += '<tr><td>' + assignment.role_name + '</td><td>' + assignment.department_name + '</td><td>' + primaryBadge + '</td>';
                        detailsHtml += '<td><button type="button" class="button button-small button-link-delete remove-assignment-btn" data-user-id="' + userId + '" data-role-id="' + assignment.role_id + '">Remove</button></td></tr>';
                    });
                    detailsHtml += '</tbody></table>';
                } else {
                    detailsHtml += '<p><em>No role assignments</em></p>';
                }
                
                detailsHtml += '</div></div></div>';
                $('body').append(detailsHtml);
                $('#user-details-modal').show();
                
                // Bind close handler
                $('.modal-close').on('click', function() {
                    $('#user-details-modal').remove();
                });
                
                // Bind remove assignment handler
                $('.remove-assignment-btn').on('click', function() {
                    var roleId = $(this).data('role-id');
                    if (confirm('Remove this role assignment?')) {
                        $.post(azure_plugin_ajax.ajax_url, {
                            action: 'pta_remove_assignment',
                            user_id: userId,
                            role_id: roleId,
                            nonce: azure_plugin_ajax.nonce
                        }, function(removeResponse) {
                            if (removeResponse.success) {
                                alert('✅ Assignment removed successfully!');
                                $('#user-details-modal').remove();
                                loadUsersAndRoles(); // Refresh
                            } else {
                                alert('❌ Failed to remove assignment: ' + (removeResponse.data || 'Unknown error'));
                            }
                        });
                    }
                });
            } else {
                alert('❌ Failed to load user details: ' + (response.data || 'Unknown error'));
            }
        });
    }
    
    function showRoleAssignments(roleId) {
        // Fetch and display role assignments
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_get_assignments',
            role_id: roleId,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                var assignments = response.data;
                var roleRow = $('#roles-table-body tr').find('[data-role-id="' + roleId + '"]').closest('tr');
                var roleName = roleRow.find('td:first strong').text();
                
                var detailsHtml = '<div id="role-assignments-modal" class="modal"><div class="modal-content"><div class="modal-header"><h3>Assignments for: ' + roleName + '</h3><button type="button" class="modal-close">&times;</button></div><div class="modal-body">';
                
                if (assignments && assignments.length > 0) {
                    detailsHtml += '<table class="wp-list-table widefat fixed striped"><thead><tr><th>User</th><th>Email</th><th>Primary</th><th>Actions</th></tr></thead><tbody>';
                    assignments.forEach(function(assignment) {
                        var primaryBadge = assignment.is_primary ? '<span style="color: green;">✓ Primary</span>' : '';
                        detailsHtml += '<tr><td>' + assignment.display_name + '</td><td>' + assignment.user_email + '</td><td>' + primaryBadge + '</td>';
                        detailsHtml += '<td><button type="button" class="button button-small button-link-delete remove-assignment-btn" data-user-id="' + assignment.user_id + '" data-role-id="' + roleId + '">Remove</button></td></tr>';
                    });
                    detailsHtml += '</tbody></table>';
                } else {
                    detailsHtml += '<p><em>No users assigned to this role</em></p>';
                }
                
                detailsHtml += '</div></div></div>';
                $('body').append(detailsHtml);
                $('#role-assignments-modal').show();
                
                // Bind close handler
                $('.modal-close').on('click', function() {
                    $('#role-assignments-modal').remove();
                });
                
                // Bind remove assignment handler
                $('.remove-assignment-btn').on('click', function() {
                    var userId = $(this).data('user-id');
                    if (confirm('Remove this user from the role?')) {
                        $.post(azure_plugin_ajax.ajax_url, {
                            action: 'pta_remove_assignment',
                            user_id: userId,
                            role_id: roleId,
                            nonce: azure_plugin_ajax.nonce
                        }, function(removeResponse) {
                            if (removeResponse.success) {
                                alert('✅ Assignment removed successfully!');
                                $('#role-assignments-modal').remove();
                                loadRolesList(); // Refresh
                            } else {
                                alert('❌ Failed to remove assignment: ' + (removeResponse.data || 'Unknown error'));
                            }
                        });
                    }
                });
            } else {
                alert('❌ Failed to load role assignments: ' + (response.data || 'Unknown error'));
            }
        });
    }
    
    function showEditRoleForm(roleId) {
        // Remove any existing edit role modal first
        $('#edit-role-form-modal').remove();
        
        // Fetch role data and departments in parallel
        var roleData = null;
        var departmentsData = null;
        
        // Get role data
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_get_roles',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (!response.success) {
                alert('Failed to load role data');
                return;
            }
            
            // Find the role
            roleData = response.data.find(function(r) { return r.id == roleId; });
            if (!roleData) {
                alert('Role not found');
                return;
            }
            
            // Check if we have both data sets
            if (departmentsData !== null) {
                renderEditRoleForm(roleData, departmentsData);
            }
        });
        
        // Get departments data
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_get_departments',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                departmentsData = response.data;
                
                // Check if we have both data sets
                if (roleData !== null) {
                    renderEditRoleForm(roleData, departmentsData);
                }
            }
        });
    }
    
    function renderEditRoleForm(role, departments) {
        // Build department options HTML
        var deptOptionsHtml = '<option value="">-- Select Department --</option>';
        departments.forEach(function(dept) {
            var selected = dept.id == role.department_id ? 'selected' : '';
            deptOptionsHtml += '<option value="' + dept.id + '" ' + selected + '>' + dept.name + '</option>';
        });
        deptOptionsHtml += '<option value="new">➕ Create New Department...</option>';
        
        // Create edit form modal
        var formHtml = `
            <div id="edit-role-form-modal" class="modal" style="z-index: 10001;">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Edit Role: ${role.name}</h3>
                        <button type="button" class="modal-close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <form id="edit-role-form" onsubmit="return false;" method="post">
                            <input type="hidden" name="role_id" value="${role.id}">
                            <table class="form-table">
                                <tr>
                                    <th>Role Name</th>
                                    <td><input type="text" name="name" value="${role.name}" required class="regular-text"></td>
                                </tr>
                                <tr>
                                    <th>Department</th>
                                    <td>
                                        <select name="department_id" id="edit-role-department" required style="min-width: 250px;">
                                            ${deptOptionsHtml}
                                        </select>
                                        <div id="new-dept-fields" style="display: none; margin-top: 10px;">
                                            <input type="text" id="new-dept-name" placeholder="New department name" class="regular-text" style="margin-bottom: 5px;">
                                            <button type="button" class="button" id="create-new-dept-btn">Create Department</button>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Max Occupants</th>
                                    <td><input type="number" name="max_occupants" value="${role.max_occupants}" min="1" class="small-text"></td>
                                </tr>
                                <tr>
                                    <th>Description</th>
                                    <td><textarea name="description" class="large-text">${role.description || ''}</textarea></td>
                                </tr>
                                <tr>
                                    <th>O365 Group</th>
                                    <td>
                                        <select name="o365_group_id" id="edit-role-o365-group" style="min-width: 250px;">
                                            <option value="">-- Loading groups... --</option>
                                        </select>
                                        <p class="description">Assign an O365 group email to this role (for Treasurer, Secretary, etc.)</p>
                                    </td>
                                </tr>
                            </table>
                            <p class="submit" style="display: flex; gap: 10px;">
                                <button type="button" id="save-role-btn" class="button button-primary" style="min-width: 120px;">Save Changes</button>
                                <button type="button" class="button modal-close" style="min-width: 120px;">Cancel</button>
                            </p>
                        </form>
                    </div>
                </div>
            </div>
        `;
        
        // Remove any existing modal first (safety check)
        $('#edit-role-form-modal').remove();
        
        $('body').append(formHtml);
        
        // Load O365 groups and current role mapping into dropdown
        var $groupSelect = $('#edit-role-o365-group');
        var currentGroupId = '';

        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_get_role_group_mapping',
            role_id: role.id,
            nonce: azure_plugin_ajax.nonce
        }, function(resp) {
            if (resp.success && resp.data) {
                currentGroupId = resp.data.group_id || '';
            }
            loadO365GroupsForRole();
        }).fail(function() {
            loadO365GroupsForRole();
        });

        function loadO365GroupsForRole() {
            $.post(azure_plugin_ajax.ajax_url, {
                action: 'pta_get_o365_groups',
                nonce: azure_plugin_ajax.nonce
            }, function(resp) {
                var opts = '<option value="">-- None --</option>';
                if (resp.success && resp.data) {
                    resp.data.forEach(function(g) {
                        var sel = (g.group_id === currentGroupId) ? 'selected' : '';
                        var label = g.display_name + (g.mail ? ' (' + g.mail + ')' : '');
                        opts += '<option value="' + g.group_id + '" ' + sel + '>' + label + '</option>';
                    });
                }
                $groupSelect.html(opts);
            }).fail(function() {
                $groupSelect.html('<option value="">-- Failed to load groups --</option>');
            });
        }

        // Handle "Create New Department" option
        $('#edit-role-department').on('change', function() {
            if ($(this).val() === 'new') {
                $('#new-dept-fields').show();
                $(this).prop('required', false);
            } else {
                $('#new-dept-fields').hide();
                $(this).prop('required', true);
            }
        });
        
        // Handle create new department button
        $('#create-new-dept-btn').on('click', function() {
            var newDeptName = $('#new-dept-name').val().trim();
            if (!newDeptName) {
                alert('Please enter a department name');
                return;
            }
            
            var btn = $(this);
            btn.prop('disabled', true).text('Creating...');
            
            $.post(azure_plugin_ajax.ajax_url, {
                action: 'pta_create_department',
                name: newDeptName,
                nonce: azure_plugin_ajax.nonce
            }, function(response) {
                btn.prop('disabled', false).text('Create Department');
                
                if (response.success) {
                    // Add new department to dropdown and select it
                    var newDeptId = response.data.dept_id;
                    $('#edit-role-department option[value="new"]').before(
                        '<option value="' + newDeptId + '" selected>' + newDeptName + '</option>'
                    );
                    $('#edit-role-department').val(newDeptId).prop('required', true);
                    $('#new-dept-fields').hide();
                    $('#new-dept-name').val('');
                    alert('✅ Department created successfully!');
                } else {
                    alert('❌ Failed to create department: ' + (response.data || 'Unknown error'));
                }
            }).fail(function() {
                btn.prop('disabled', false).text('Create Department');
                alert('❌ Network error occurred');
            });
        });
        
        // Get references to the modal elements
        var $modal = $('#edit-role-form-modal');
        var $saveBtn = $modal.find('#save-role-btn');
        var $closeButtons = $modal.find('.modal-close');
        var $modalContent = $modal.find('.modal-content');
        
        // Close on clicking X or Cancel button
        $closeButtons.on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $modal.remove();
        });
        
        // Close on clicking overlay background (outside modal content)
        $modal.on('click', function(e) {
            if (e.target === this) {
                $modal.remove();
            }
        });
        
        // Prevent clicks inside modal content from closing
        $modalContent.on('click', function(e) {
            e.stopPropagation();
        });
        
        // Save button click handler
        $saveBtn.on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            var $btn = $(this);
            var $form = $('#edit-role-form');
            var roleId = $form.find('[name="role_id"]').val();
            var deptVal = $form.find('[name="department_id"]').val();
            
            console.log('Save button clicked. Role ID:', roleId, 'Dept:', deptVal);
            
            if (!deptVal || deptVal === 'new') {
                alert('Please select a department');
                return false;
            }
            
            var formData = {
                action: 'pta_update_role',
                nonce: azure_plugin_ajax.nonce,
                role_id: roleId,
                name: $form.find('[name="name"]').val(),
                department_id: deptVal,
                max_occupants: $form.find('[name="max_occupants"]').val(),
                description: $form.find('[name="description"]').val()
            };
            
            console.log('Saving role with data:', formData);
            
            // Disable button to prevent double submission
            $btn.prop('disabled', true).text('Saving...');
                
            $.post(azure_plugin_ajax.ajax_url, formData, function(updateResponse) {
                console.log('Update response:', updateResponse);
                if (!updateResponse.success) {
                    $btn.prop('disabled', false).text('Save Changes');
                    alert('❌ Failed to update role: ' + (updateResponse.data || 'Unknown error'));
                    return;
                }

                var selectedGroup = $form.find('[name="o365_group_id"]').val();
                $.post(azure_plugin_ajax.ajax_url, {
                    action: 'pta_set_role_group',
                    nonce: azure_plugin_ajax.nonce,
                    role_id: roleId,
                    o365_group_id: selectedGroup || ''
                }, function() {
                    alert('✅ Role updated successfully!');
                    $modal.remove();
                    loadRolesList();
                }).fail(function() {
                    alert('✅ Role updated but O365 group mapping may not have saved.');
                    $modal.remove();
                    loadRolesList();
                });
            }).fail(function(xhr, status, error) {
                $btn.prop('disabled', false).text('Save Changes');
                console.error('Edit role AJAX error:', xhr, status, error);
                console.error('Response:', xhr.responseText);
                alert('❌ Network error: ' + error);
            });
            
            return false;
        });
    }
    
    // Show Assign VP Modal
    function showAssignVPModal(deptId) {
        // Fetch department data first
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_get_departments',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (!response.success) {
                alert('Failed to load department data');
                return;
            }
            
            // Find the department
            var dept = response.data.find(function(d) { return d.id == deptId; });
            if (!dept) {
                alert('Department not found');
                return;
            }
            
            var currentVP = dept.vp_info ? dept.vp_info.display_name + ' (' + dept.vp_info.user_email + ')' : 'None';
            
            // Create assign VP modal
            var modalHtml = `
                <div id="assign-vp-modal" class="modal" style="z-index: 10001;">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3>Assign VP for: ${dept.name}</h3>
                            <button type="button" class="modal-close">&times;</button>
                        </div>
                        <div class="modal-body">
                            <p><strong>Current VP:</strong> ${currentVP}</p>
                            <form id="assign-vp-form">
                                <input type="hidden" name="dept_id" value="${dept.id}">
                                <table class="form-table">
                                    <tr>
                                        <th>Select VP</th>
                                        <td>
                                            <select name="vp_user_id" id="assign-vp-select" required style="min-width: 300px;">
                                                <option value="">Loading users...</option>
                                            </select>
                                        </td>
                                    </tr>
                                </table>
                                <p class="submit">
                                    <button type="submit" class="button button-primary">Assign VP</button>
                                    <button type="button" class="button modal-close">Cancel</button>
                                </p>
                            </form>
                        </div>
                    </div>
                </div>
            `;
            
            $('body').append(modalHtml);
            
            // Load users for VP dropdown
            $.post(azure_plugin_ajax.ajax_url, {
                action: 'pta_get_users',
                nonce: azure_plugin_ajax.nonce
            }, function(usersResponse) {
                var vpSelect = $('#assign-vp-select');
                vpSelect.empty();
                vpSelect.append('<option value="">-- Select a VP --</option>');
                
                if (usersResponse.success) {
                    usersResponse.data.forEach(function(user) {
                        var selected = dept.vp_user_id == user.ID ? 'selected' : '';
                        vpSelect.append('<option value="' + user.ID + '" ' + selected + '>' + user.display_name + ' (' + user.user_email + ')</option>');
                    });
                }
            });
            
            $('#assign-vp-modal').show();
            
            // Bind close handler
            $('#assign-vp-modal .modal-close').on('click', function() {
                $('#assign-vp-modal').remove();
            });
            
            // Bind form submit
            $('#assign-vp-form').on('submit', function(e) {
                e.preventDefault();
                
                var vpUserId = $('#assign-vp-select').val();
                
                $.post(azure_plugin_ajax.ajax_url, {
                    action: 'pta_update_department',
                    nonce: azure_plugin_ajax.nonce,
                    dept_id: deptId,
                    name: dept.name,
                    vp_user_id: vpUserId || 0
                }, function(updateResponse) {
                    if (updateResponse.success) {
                        alert('✅ VP assigned successfully!');
                        $('#assign-vp-modal').remove();
                        loadDepartmentsList(); // Refresh the departments list
                        location.reload(); // Also refresh the main page
                    } else {
                        alert('❌ Failed to assign VP: ' + (updateResponse.data || 'Unknown error'));
                    }
                });
            });
        });
    }
    
    function showEditDepartmentForm(deptId) {
        // Fetch department data first
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_get_departments',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (!response.success) {
                alert('Failed to load department data');
                return;
            }
            
            // Find the department
            var dept = response.data.find(function(d) { return d.id == deptId; });
            if (!dept) {
                alert('Department not found');
                return;
            }
            
            // Create edit form modal
            var formHtml = `
                <div id="edit-dept-form-modal" class="modal" style="z-index: 10001;">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3>Edit Department: ${dept.name}</h3>
                            <button type="button" class="modal-close">&times;</button>
                        </div>
                        <div class="modal-body">
                            <form id="edit-dept-form">
                                <input type="hidden" name="dept_id" value="${dept.id}">
                                <table class="form-table">
                                    <tr>
                                        <th>Department Name</th>
                                        <td><input type="text" name="name" value="${dept.name}" required class="regular-text"></td>
                                    </tr>
                                    <tr>
                                        <th>VP (Vice President)</th>
                                        <td><select name="vp_user_id" id="edit-dept-vp"></select></td>
                                    </tr>
                                    <tr>
                                        <th>O365 Group</th>
                                        <td>
                                            <select name="o365_group_id" id="edit-dept-o365-group">
                                                <option value="">-- No O365 Group --</option>
                                            </select>
                                            <p class="description">Assign an Office 365 group email to this department.</p>
                                        </td>
                                    </tr>
                                </table>
                                <p class="submit">
                                    <button type="submit" class="button button-primary">Save Changes</button>
                                    <button type="button" class="button modal-close">Cancel</button>
                                </p>
                            </form>
                        </div>
                    </div>
                </div>
            `;
            
            $('body').append(formHtml);
            
            // Load users for VP dropdown
            $.post(azure_plugin_ajax.ajax_url, {
                action: 'pta_get_users',
                nonce: azure_plugin_ajax.nonce
            }, function(usersResponse) {
                if (usersResponse.success) {
                    var vpSelect = $('#edit-dept-vp');
                    vpSelect.append('<option value="">-- No VP Assigned --</option>');
                    usersResponse.data.forEach(function(user) {
                        var selected = dept.vp_user_id == user.ID ? 'selected' : '';
                        vpSelect.append('<option value="' + user.ID + '" ' + selected + '>' + user.display_name + ' (' + user.user_email + ')</option>');
                    });
                }
            });

            // Load O365 groups and current department mapping in parallel
            var currentGroupId = '';
            $.post(azure_plugin_ajax.ajax_url, {
                action: 'pta_get_department_group_mapping',
                nonce: azure_plugin_ajax.nonce,
                dept_id: deptId
            }, function(mappingResponse) {
                if (mappingResponse.success && mappingResponse.data) {
                    currentGroupId = mappingResponse.data.group_id || '';
                }
                // Now load all O365 groups and pre-select the current one
                $.post(azure_plugin_ajax.ajax_url, {
                    action: 'pta_get_o365_groups',
                    nonce: azure_plugin_ajax.nonce
                }, function(groupsResponse) {
                    if (groupsResponse.success) {
                        var groupSelect = $('#edit-dept-o365-group');
                        groupsResponse.data.forEach(function(group) {
                            var selected = (currentGroupId === group.group_id) ? 'selected' : '';
                            var label = group.display_name + (group.mail ? ' (' + group.mail + ')' : '');
                            groupSelect.append('<option value="' + group.group_id + '" ' + selected + '>' + label + '</option>');
                        });
                    }
                });
            });

            $('#edit-dept-form-modal').show();
            
            // Bind close handler
            $('#edit-dept-form-modal .modal-close').on('click', function() {
                $('#edit-dept-form-modal').remove();
            });
            
            // Bind form submit
            $('#edit-dept-form').on('submit', function(e) {
                e.preventDefault();

                var selectedGroupId = $(this).find('[name="o365_group_id"]').val();
                
                var formData = {
                    action: 'pta_update_department',
                    nonce: azure_plugin_ajax.nonce,
                    dept_id: deptId,
                    name: $(this).find('[name="name"]').val(),
                    vp_user_id: $(this).find('[name="vp_user_id"]').val() || 0
                };
                
                $.post(azure_plugin_ajax.ajax_url, formData, function(updateResponse) {
                    if (!updateResponse.success) {
                        alert('❌ Failed to update department: ' + (updateResponse.data || 'Unknown error'));
                        return;
                    }
                    // Save O365 group mapping
                    $.post(azure_plugin_ajax.ajax_url, {
                        action: 'pta_set_department_group',
                        nonce: azure_plugin_ajax.nonce,
                        dept_id: deptId,
                        o365_group_id: selectedGroupId || ''
                    }, function(groupResponse) {
                        if (groupResponse.success) {
                            alert('✅ Department updated successfully!');
                        } else {
                            alert('✅ Department saved but O365 group update failed: ' + (groupResponse.data || 'Unknown error'));
                        }
                        $('#edit-dept-form-modal').remove();
                        location.reload();
                    });
                });
            });
        });
    }
    
    // Show Department Details Modal (from View Details button on main page)
    function showDepartmentDetails(deptId) {
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_get_departments',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (!response.success) {
                alert('Failed to load department data');
                return;
            }
            
            // Find the department
            var dept = response.data.find(function(d) { return d.id == deptId; });
            if (!dept) {
                alert('Department not found');
                return;
            }
            
            // Calculate stats
            var totalRoles = dept.roles ? dept.roles.length : 0;
            var filledRoles = 0;
            var totalAssignments = 0;
            var openPositions = 0;
            
            if (dept.roles) {
                dept.roles.forEach(function(role) {
                    var assignedCount = role.assigned_count || 0;
                    totalAssignments += assignedCount;
                    if (assignedCount >= role.max_occupants) {
                        filledRoles++;
                    }
                    openPositions += Math.max(0, role.max_occupants - assignedCount);
                });
            }
            
            var vpName = dept.vp_info ? dept.vp_info.display_name : '<em>No VP Assigned</em>';
            var vpEmail = dept.vp_info ? ' (' + dept.vp_info.user_email + ')' : '';
            
            // Build roles table
            var rolesHtml = '';
            if (dept.roles && dept.roles.length > 0) {
                rolesHtml = '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Role</th><th>Occupancy</th><th>Status</th><th>Assigned To</th></tr></thead><tbody>';
                dept.roles.forEach(function(role) {
                    var assignedCount = role.assigned_count || 0;
                    var statusClass = assignedCount >= role.max_occupants ? 'filled' : (assignedCount > 0 ? 'partial' : 'open');
                    var statusText = assignedCount >= role.max_occupants ? 'Filled' : (assignedCount > 0 ? 'Partial' : 'Open');
                    
                    var assignedNames = [];
                    if (role.assignments && role.assignments.length > 0) {
                        role.assignments.forEach(function(a) {
                            assignedNames.push(a.display_name || a.name || 'Unknown');
                        });
                    }
                    var assignedText = assignedNames.length > 0 ? assignedNames.join(', ') : '<em>None</em>';
                    
                    rolesHtml += '<tr>';
                    rolesHtml += '<td><strong>' + role.name + '</strong></td>';
                    rolesHtml += '<td>' + assignedCount + ' / ' + role.max_occupants + '</td>';
                    rolesHtml += '<td><span class="status-badge ' + statusClass + '">' + statusText + '</span></td>';
                    rolesHtml += '<td>' + assignedText + '</td>';
                    rolesHtml += '</tr>';
                });
                rolesHtml += '</tbody></table>';
            } else {
                rolesHtml = '<p><em>No roles defined for this department</em></p>';
            }
            
            // Create modal
            var modalHtml = `
                <div id="dept-details-modal" class="modal" style="z-index: 10000;">
                    <div class="modal-content" style="max-width: 900px;">
                        <div class="modal-header">
                            <h3>Department: ${dept.name}</h3>
                            <button type="button" class="modal-close">&times;</button>
                        </div>
                        <div class="modal-body">
                            <!-- Department Info -->
                            <div class="dept-details-section" style="margin-bottom: 20px; padding: 15px; background: #f9f9f9; border-radius: 4px;">
                                <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 20px;">
                                    <div>
                                        <strong>VP:</strong> ${vpName}${vpEmail}
                                    </div>
                                    <div>
                                        <strong>Roles:</strong> ${totalRoles} (${filledRoles} filled)
                                    </div>
                                    <div>
                                        <strong>Assignments:</strong> ${totalAssignments}
                                    </div>
                                    <div>
                                        <strong>Open Positions:</strong> ${openPositions}
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="dept-details-actions" style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
                                <button type="button" class="button button-primary dept-edit-btn" data-dept-id="${dept.id}">
                                    <span class="dashicons dashicons-edit" style="vertical-align: middle;"></span> Edit Department
                                </button>
                                <button type="button" class="button dept-manage-roles-btn" data-dept-id="${dept.id}">
                                    <span class="dashicons dashicons-businessperson" style="vertical-align: middle;"></span> Manage Roles
                                </button>
                                <button type="button" class="button dept-manage-assignments-btn" data-dept-id="${dept.id}">
                                    <span class="dashicons dashicons-admin-users" style="vertical-align: middle;"></span> Manage Assignments
                                </button>
                                <a href="${azure_plugin_ajax.admin_url || '/wp-admin/'}admin.php?page=azure-plugin-pta-groups&dept=${dept.id}" class="button">
                                    <span class="dashicons dashicons-admin-network" style="vertical-align: middle;"></span> O365 Group Mappings
                                </a>
                            </div>
                            
                            <!-- Roles Table -->
                            <h4>Roles & Assignments</h4>
                            ${rolesHtml}
                        </div>
                    </div>
                </div>
            `;
            
            $('body').append(modalHtml);
            $('#dept-details-modal').show();
            
            // Bind close handler
            $('#dept-details-modal .modal-close').on('click', function() {
                $('#dept-details-modal').remove();
            });
            
            // Bind action buttons
            $('#dept-details-modal .dept-edit-btn').on('click', function() {
                $('#dept-details-modal').remove();
                showEditDepartmentForm(deptId);
            });
            
            $('#dept-details-modal .dept-manage-roles-btn').on('click', function() {
                $('#dept-details-modal').remove();
                showRolesModal();
                // Filter to this department after modal loads
                setTimeout(function() {
                    $('#role-dept-filter').val(dept.name).trigger('change');
                }, 500);
            });
            
            $('#dept-details-modal .dept-manage-assignments-btn').on('click', function() {
                $('#dept-details-modal').remove();
                showPeopleModal();
            });
        });
    }
    
    // Handle module toggle
    $('.pta-module-toggle').change(function() {
        var enabled = $(this).is(':checked');
        var statusText = $('.toggle-status');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'azure_toggle_module',
            module: 'pta',
            enabled: enabled,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                statusText.text(enabled ? 'Enabled' : 'Disabled');
                if (enabled) {
                    $('.notice.notice-warning.inline').fadeOut();
                } else {
                    location.reload(); // Refresh to show warning
                }
            } else {
                // Revert toggle if failed
                $('.pta-module-toggle').prop('checked', !enabled);
                alert('Failed to toggle PTA module: ' + (response.data || 'Unknown error'));
            }
        }).fail(function() {
            // Revert toggle if failed
            $('.pta-module-toggle').prop('checked', !enabled);
            alert('Network error occurred');
        });
    });
});
</script>

<style>
/* PTA Page - Enhanced Contrast Styles */
.pta-stats-section {
    margin-bottom: 30px;
}

.pta-actions-section {
    margin-bottom: 30px;
}

.action-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: stretch;
}

.action-buttons .button,
.action-buttons a.button {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center;
    gap: 6px;
    white-space: nowrap;
    padding: 0 12px;
    height: 30px;
    line-height: 28px;
}

.action-buttons .button .dashicons,
.action-buttons a.button .dashicons {
    display: inline-block;
    width: 20px;
    height: 20px;
    font-size: 20px;
    line-height: 20px;
    vertical-align: middle;
    margin: 0;
    padding: 0;
    position: relative;
    top: 0;
}

.user-actions .button .dashicons,
.button .dashicons {
    vertical-align: middle;
    line-height: 1;
}

.sync-stats {
    margin-top: 20px;
    padding: 15px;
    background: #f9f9f9 !important;
    color: #333 !important;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.sync-stats h3 {
    margin-top: 0;
    color: #333 !important;
}

.sync-stats-grid {
    display: flex;
    gap: 20px;
}

.sync-stat {
    text-align: center;
    padding: 10px;
    border-radius: 4px;
    min-width: 80px;
}

.sync-stat.pending {
    background: #fff3cd;
    color: #856404 !important;
}

.sync-stat.failed {
    background: #f8d7da;
    color: #721c24 !important;
}

.sync-stat.completed {
    background: #d4edda;
    color: #155724 !important;
}

.sync-stat .number {
    display: block;
    font-size: 24px;
    font-weight: bold;
}

.sync-stat .label {
    font-size: 12px;
}

.unassigned-users-section {
    background: #fff3cd !important;
    color: #856404 !important;
    border: 1px solid #ffeaa7;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 30px;
}

.unassigned-users-section h3 {
    color: #856404 !important;
}

.unassigned-users-list {
    margin-top: 15px;
}

.unassigned-user {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    background: white !important;
    color: #333 !important;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin-bottom: 10px;
}

.user-info strong {
    color: #0073aa !important;
}

.user-email {
    color: #666 !important;
    margin-left: 10px;
}

.user-registered {
    color: #999 !important;
    font-size: 12px;
    margin-left: 10px;
}

.user-actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

.user-actions .button {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.departments-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 15px;
}

.department-card {
    background: #fff !important;
    color: #333 !important;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.dept-header h3 {
    margin: 0 0 10px 0;
    color: #0073aa !important;
}

.dept-vp {
    font-size: 14px;
    color: #666 !important;
}

.dept-vp.no-vp {
    color: #dc3232 !important;
    font-style: italic;
}

.dept-stats {
    display: flex;
    justify-content: space-between;
    margin: 15px 0;
    padding: 10px 0;
    border-top: 1px solid #eee;
    border-bottom: 1px solid #eee;
}

.dept-stat {
    text-align: center;
}

.dept-stat .stat-number {
    display: block;
    font-size: 20px;
    font-weight: bold;
    color: #0073aa !important;
}

.dept-stat .stat-label {
    font-size: 12px;
    color: #666 !important;
}

.dept-roles h4 {
    margin: 15px 0 10px 0;
    font-size: 14px;
    color: #333 !important;
}

.roles-list {
    max-height: 150px;
    overflow-y: auto;
}

.role-item {
    display: flex;
    justify-content: space-between;
    padding: 5px 0;
    border-bottom: 1px solid #f0f0f0;
    color: #333 !important;
}

.role-item:last-child {
    border-bottom: none;
}

.role-name {
    font-size: 13px;
    color: #333 !important;
}

.role-status {
    font-size: 12px;
    font-weight: bold;
}

.role-item.filled .role-status {
    color: #46b450;
}

.role-item.partial .role-status {
    color: #ffb900;
}

.role-item.open .role-status {
    color: #dc3232;
}

.more-roles {
    text-align: center;
    padding: 10px;
    font-size: 12px;
    color: #666;
    font-style: italic;
}

.dept-actions {
    margin-top: 15px;
    text-align: center;
}

.sync-queue-section {
    margin-top: 30px;
}

/* Modal Styles */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background: #fff !important;
    color: #333 !important;
    border-radius: 8px;
    max-width: 500px;
    width: 90%;
    max-height: 80%;
    overflow-y: auto;
}

.modal-header {
    background: #fff !important;
    color: #333 !important;
    padding: 20px;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h2 {
    margin: 0;
    color: #333 !important;
}

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #333 !important;
}

.modal-body {
    background: #fff !important;
    color: #333 !important;
    padding: 20px;
}

.form-field {
    margin-bottom: 20px;
}

.form-field label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #333 !important;
}

.form-field select,
.form-field input {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: #fff !important;
    color: #333 !important;
}

.form-field .description {
    font-size: 12px;
    color: #666 !important;
    margin-top: 5px;
}

.form-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
}

/* React App Placeholder - Enhanced Contrast */
.pta-app-placeholder {
    text-align: center;
    padding: 40px;
    background: #f9f9f9 !important;
    color: #333 !important;
    border: 1px solid #ddd;
    border-radius: 8px;
}

.pta-app-placeholder h3 {
    color: #333 !important;
}

.pta-app-loading {
    text-align: center;
    padding: 40px;
    background: #fff !important;
    color: #333 !important;
}

.loading-spinner {
    border: 4px solid #f3f3f3;
    border-top: 4px solid #0073aa;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    animation: spin 1s linear infinite;
    margin: 0 auto 20px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Responsive Design */
@media (max-width: 768px) {
    .departments-grid {
        grid-template-columns: 1fr;
    }
    
    .unassigned-user {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .dept-stats {
        flex-direction: column;
        gap: 10px;
    }
    
    .sync-stats-grid {
        flex-direction: column;
        gap: 10px;
    }
}

/* CRUD Modal Enhancements */
.modal-section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding: 15px;
    background: #f9f9f9 !important;
    color: #333 !important;
    border-radius: 4px;
    border: 1px solid #ddd;
}

.modal-section-header h4 {
    margin: 0;
    color: #0073aa !important;
    font-size: 16px;
}

.section-actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

.section-actions .search-input {
    min-width: 200px;
    color: #333 !important;
    background: #fff !important;
}

/* Status Badges */
.status-badge {
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
}

.status-badge.filled {
    background: #d4edda !important;
    color: #155724 !important;
    border: 1px solid #c3e6cb;
}

.status-badge.partial {
    background: #fff3cd !important;
    color: #856404 !important;
    border: 1px solid #ffeaa7;
}

.status-badge.open {
    background: #f8d7da !important;
    color: #721c24 !important;
    border: 1px solid #f5c6cb;
}

/* Table Enhancements */
.wp-list-table th, .wp-list-table td {
    color: #333 !important;
    background: #fff !important;
}

.unassigned-user-row {
    background-color: #fff8dc !important;
}

.no-vp {
    color: #dc3232 !important;
    font-style: italic;
}

/* Form Modal Styles */
.modal .form-table th {
    color: #333 !important;
    font-weight: 600;
    background: #f9f9f9 !important;
}

.modal .form-table input,
.modal .form-table select,
.modal .form-table textarea {
    color: #333 !important;
    background: #fff !important;
    border: 1px solid #ddd;
}

/* Button Spacing */
.button-small {
    margin-right: 5px;
}

@media (max-width: 768px) {
    .modal-section-header {
        flex-direction: column;
        gap: 10px;
    }
    
    .section-actions {
        width: 100%;
        justify-content: space-between;
    }
    
    .wp-list-table {
        font-size: 12px;
    }
    
    .wp-list-table td:nth-child(4),
    .wp-list-table th:nth-child(4) {
        display: none; /* Hide status column on mobile */
    }
}

/* PTA Shortcodes Section */
.pta-shortcodes-section {
    background: #fff;
    padding: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    margin: 20px 0;
}

.pta-shortcodes-section h2 {
    color: #333;
    border-bottom: 2px solid #007cba;
    padding-bottom: 10px;
    margin-bottom: 20px;
}

.shortcode-examples {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.shortcode-example {
    background: #f9f9f9;
    border: 1px solid #e1e1e1;
    border-radius: 6px;
    padding: 15px;
}

.shortcode-example h4 {
    margin: 0 0 10px 0;
    color: #007cba;
    font-size: 16px;
}

.shortcode-example code {
    display: block;
    background: #2c3e50;
    color: #e74c3c;
    padding: 10px;
    border-radius: 4px;
    margin: 10px 0;
    font-family: 'Courier New', monospace;
    font-size: 13px;
    word-wrap: break-word;
    overflow-x: auto;
}

.shortcode-example p {
    margin: 10px 0 5px 0;
    font-weight: 600;
    color: #333;
}

.shortcode-example ul {
    margin: 0;
    padding-left: 20px;
}

.shortcode-example li {
    margin-bottom: 5px;
    font-size: 14px;
    line-height: 1.4;
}

.shortcode-example li strong {
    color: #007cba;
}

.beaver-builder-info {
    background: #f0f8ff;
    border: 1px solid #b3d9ff;
    border-radius: 6px;
    padding: 20px;
    margin-top: 20px;
}

.beaver-builder-info h3 {
    margin: 0 0 15px 0;
    color: #0073aa;
    font-size: 18px;
}

.beaver-builder-info ul {
    margin: 10px 0;
    padding-left: 20px;
}

.beaver-builder-info li {
    margin-bottom: 5px;
    line-height: 1.5;
}

.beaver-builder-info em {
    color: #666;
    font-style: italic;
}

@media (max-width: 768px) {
    .shortcode-examples {
        grid-template-columns: 1fr;
    }
    
    .shortcode-example {
        padding: 12px;
    }
    
    .shortcode-example code {
        font-size: 11px;
    }
}
</style>
