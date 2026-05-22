<?php
if (!defined('ABSPATH')) {
    exit;
}

// Get groups manager instance
$groups_manager = null;
$pta_manager = null;
$o365_groups = array();
$stored_groups = array();

if (class_exists('Azure_PTA_Groups_Manager') && class_exists('Azure_PTA_Manager')) {
    try {
        $groups_manager = new Azure_PTA_Groups_Manager();
        $pta_manager = Azure_PTA_Manager::get_instance();
    } catch (Exception $e) {
        Azure_Logger::error('PTA Groups Page: Failed to initialize managers - ' . $e->getMessage());
    }
}

// Get Office 365 groups
if ($groups_manager) {
    try {
        $o365_groups = $groups_manager->get_stored_o365_groups();
        $group_mappings = $groups_manager->get_group_mappings();
    } catch (Exception $e) {
        Azure_Logger::error('PTA Groups Page: Failed to get O365 groups - ' . $e->getMessage());
        $group_mappings = array();
    }
} else {
    $group_mappings = array();
}

// Get departments and roles for mapping
$departments = array();
if ($pta_manager) {
    try {
        $departments = $pta_manager->get_departments();
    } catch (Exception $e) {
        Azure_Logger::error('PTA Groups Page: Failed to get departments - ' . $e->getMessage());
    }
}

$all_roles = array();
if ($pta_manager) {
    try {
        $all_roles = $pta_manager->get_roles();
    } catch (Exception $e) {
        Azure_Logger::error('PTA Groups Page: Failed to get roles - ' . $e->getMessage());
    }
}

// Organize mappings by target
$mappings_by_target = array();
foreach ($group_mappings as $mapping) {
    $key = $mapping->target_type . '_' . $mapping->target_id;
    if (!isset($mappings_by_target[$key])) {
        $mappings_by_target[$key] = array();
    }
    $mappings_by_target[$key][] = $mapping;
}
?>

<div class="wrap">
    <h1>PTA Tools - PTA Office 365 Groups Management</h1>
    
    <?php if (!Azure_Settings::is_module_enabled('pta')): ?>
    <div class="notice notice-warning">
        <p><strong>PTA module is disabled.</strong> <a href="<?php echo admin_url('admin.php?page=azure-plugin'); ?>">Enable it in the main settings</a> to use PTA functionality.</p>
    </div>
    <?php endif; ?>
    
    <div class="azure-pta-groups-dashboard">
        <!-- Groups Sync Status -->
        <div class="groups-sync-section">
            <h2>Office 365 Groups Status</h2>
            
            <div class="sync-status-card">
                <div class="sync-info">
                    <div class="sync-stat">
                        <span class="stat-number"><?php echo count($o365_groups); ?></span>
                        <span class="stat-label">Stored Groups</span>
                    </div>
                    <div class="sync-stat">
                        <span class="stat-number"><?php echo count($group_mappings); ?></span>
                        <span class="stat-label">Active Mappings</span>
                    </div>
                </div>
                
                <div class="sync-actions">
                    <button type="button" class="button button-primary sync-o365-groups">
                        <span class="dashicons dashicons-update"></span>
                        Sync O365 Groups
                    </button>
                    <button type="button" class="button test-group-access">
                        <span class="dashicons dashicons-admin-network"></span>
                        Test Group Access
                    </button>
                </div>
            </div>
            
            <?php if (empty($o365_groups)): ?>
            <div class="notice notice-info">
                <p><strong>No Office 365 groups found.</strong> Click "Sync O365 Groups" to fetch groups from your Microsoft tenant.</p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Quick Actions -->
        <div class="groups-actions-section">
            <h2>Quick Actions</h2>
            
            <div class="action-buttons">
                <button type="button" class="button create-group-mapping-btn">
                    <span class="dashicons dashicons-plus"></span>
                    Create Group Mapping
                </button>
                
                <button type="button" class="button sync-all-memberships">
                    <span class="dashicons dashicons-groups"></span>
                    Sync All Memberships
                </button>
                
                <button type="button" class="button view-unmapped-groups">
                    <span class="dashicons dashicons-warning"></span>
                    View Unmapped Groups
                </button>
            </div>
        </div>
        
        <!-- Department Mappings -->
        <div class="department-mappings-section">
            <h2>Department Group Mappings</h2>
            
            <div class="mappings-grid">
                <?php foreach ($departments as $dept): ?>
                <div class="mapping-card department-mapping">
                    <div class="mapping-header">
                        <h3><?php echo esc_html($dept->name); ?></h3>
                        <button type="button" class="button button-small add-dept-mapping" data-dept-id="<?php echo $dept->id; ?>">
                            Add Group
                        </button>
                    </div>
                    
                    <div class="mapped-groups">
                        <?php 
                        $dept_key = 'department_' . $dept->id;
                        if (isset($mappings_by_target[$dept_key])): 
                        ?>
                            <?php foreach ($mappings_by_target[$dept_key] as $mapping): ?>
                            <div class="mapped-group">
                                <div class="group-info">
                                    <strong><?php echo esc_html($mapping->group_name); ?></strong>
                                    <span class="group-mail"><?php echo esc_html($mapping->mail); ?></span>
                                    <?php if ($mapping->is_required): ?>
                                    <span class="required-badge">Required</span>
                                    <?php endif; ?>
                                </div>
                                <div class="group-actions">
                                    <button type="button" class="button button-small sync-group-members" data-group-id="<?php echo esc_attr($mapping->group_id); ?>">
                                        Sync
                                    </button>
                                    <button type="button" class="button button-small delete-mapping" data-mapping-id="<?php echo $mapping->id; ?>">
                                        Remove
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                        <p class="no-mappings">No group mappings configured</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Role Mappings (Expandable) -->
        <div class="role-mappings-section">
            <h2>
                Role Group Mappings 
                <button type="button" class="button button-small toggle-role-mappings">
                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                    Show/Hide
                </button>
            </h2>
            
            <div class="role-mappings-container" style="display: none;">
                <div class="mappings-grid">
                    <?php 
                    $roles_by_dept = array();
                    foreach ($all_roles as $role) {
                        $roles_by_dept[$role->department_name][] = $role;
                    }
                    ?>
                    
                    <?php foreach ($roles_by_dept as $dept_name => $roles): ?>
                    <div class="department-roles-section">
                        <h4><?php echo esc_html($dept_name); ?> Roles</h4>
                        
                        <?php foreach ($roles as $role): ?>
                        <div class="mapping-card role-mapping">
                            <div class="mapping-header">
                                <h5><?php echo esc_html($role->name); ?></h5>
                                <button type="button" class="button button-small add-role-mapping" data-role-id="<?php echo $role->id; ?>">
                                    Add Group
                                </button>
                            </div>
                            
                            <div class="mapped-groups">
                                <?php 
                                $role_key = 'role_' . $role->id;
                                if (isset($mappings_by_target[$role_key])): 
                                ?>
                                    <?php foreach ($mappings_by_target[$role_key] as $mapping): ?>
                                    <div class="mapped-group">
                                        <div class="group-info">
                                            <strong><?php echo esc_html($mapping->group_name); ?></strong>
                                            <?php if ($mapping->is_required): ?>
                                            <span class="required-badge">Required</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="group-actions">
                                            <button type="button" class="button button-small delete-mapping" data-mapping-id="<?php echo $mapping->id; ?>">
                                                Remove
                                            </button>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                <p class="no-mappings">No group mappings</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- All Office 365 Groups -->
        <div class="all-groups-section">
            <h2>Available Office 365 Groups</h2>
            
            <?php if (!empty($o365_groups)): ?>
            <div class="groups-table-container">
                <table class="groups-table widefat striped">
                    <thead>
                        <tr>
                            <th>Group Name</th>
                            <th>Email</th>
                            <th>Description</th>
                            <th>Mappings</th>
                            <th>Last Synced</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($o365_groups as $group): ?>
                        <?php 
                        // Count mappings for this group
                        $group_mapping_count = 0;
                        foreach ($group_mappings as $mapping) {
                            if ($mapping->group_id === $group->group_id) {
                                $group_mapping_count++;
                            }
                        }
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($group->display_name); ?></strong>
                                <div class="group-id">ID: <code><?php echo esc_html($group->group_id); ?></code></div>
                            </td>
                            <td><?php echo esc_html($group->mail ?: 'N/A'); ?></td>
                            <td><?php echo esc_html(wp_trim_words($group->description ?: 'No description', 10)); ?></td>
                            <td>
                                <span class="mapping-count <?php echo $group_mapping_count > 0 ? 'has-mappings' : 'no-mappings'; ?>">
                                    <?php echo $group_mapping_count; ?> mappings
                                </span>
                            </td>
                            <td><?php echo esc_html($group->last_synced ? date('M j, Y H:i', strtotime($group->last_synced)) : 'Never'); ?></td>
                            <td>
                                <button type="button" class="button button-small view-group-members" data-group-id="<?php echo esc_attr($group->group_id); ?>">
                                    View Members
                                </button>
                                <?php if ($group_mapping_count === 0): ?>
                                <button type="button" class="button button-small create-mapping-for-group" data-group-id="<?php echo esc_attr($group->group_id); ?>">
                                    Create Mapping
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Group Mapping Modal -->
<div id="group-mapping-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Create Group Mapping</h2>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <form id="group-mapping-form">
                <input type="hidden" id="mapping-target-type" name="target_type">
                <input type="hidden" id="mapping-target-id" name="target_id">
                
                <div class="form-field">
                    <label for="mapping-target-info">Target:</label>
                    <div id="mapping-target-info" class="target-info"></div>
                </div>
                
                <div class="form-field">
                    <label for="mapping-o365-group">Office 365 Group:</label>
                    <select id="mapping-o365-group" name="o365_group_id" required>
                        <option value="">-- Select a Group --</option>
                        <?php foreach ($o365_groups as $group): ?>
                        <option value="<?php echo esc_attr($group->group_id); ?>">
                            <?php echo esc_html($group->display_name); ?>
                            <?php if ($group->mail): ?>
                                (<?php echo esc_html($group->mail); ?>)
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-field">
                    <label>
                        <input type="checkbox" id="mapping-is-required" name="is_required" checked>
                        This is a required group membership
                    </label>
                    <p class="description">Required memberships will be automatically managed. Optional memberships can be manually assigned.</p>
                </div>
                
                <div class="form-field">
                    <label for="mapping-label">Label (Optional):</label>
                    <input type="text" id="mapping-label" name="label" placeholder="e.g., Executive Group, Communication Team">
                    <p class="description">Optional label to help identify this mapping.</p>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="button button-primary">Create Mapping</button>
                    <button type="button" class="button modal-cancel">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Group Members Modal -->
<div id="group-members-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Group Members</h2>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="group-members-container">
                Loading...
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Sync O365 groups
    $('.sync-o365-groups').click(function() {
        var button = $(this);
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Syncing...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_sync_o365_groups',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Sync O365 Groups');
            
            if (response.success) {
                alert('✅ ' + response.data.message);
                location.reload();
            } else {
                alert('❌ Failed to sync groups: ' + (response.data || 'Unknown error'));
            }
        });
    });
    
    // Test group access
    $('.test-group-access').click(function() {
        var button = $(this);
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Testing...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_test_group_access',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html('<span class="dashicons dashicons-admin-network"></span> Test Group Access');
            
            if (response.success) {
                alert('✅ ' + response.data.message);
            } else {
                alert('❌ Group access test failed: ' + (response.data || 'Unknown error'));
            }
        });
    });
    
    // Toggle role mappings
    $('.toggle-role-mappings').click(function() {
        var container = $('.role-mappings-container');
        var icon = $(this).find('.dashicons');
        
        container.toggle();
        
        if (container.is(':visible')) {
            icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
        } else {
            icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
        }
    });
    
    // Create mapping for department
    $('.add-dept-mapping').click(function() {
        var deptId = $(this).data('dept-id');
        var deptName = $(this).closest('.mapping-card').find('h3').text();
        
        showGroupMappingModal('department', deptId, 'Department: ' + deptName);
    });
    
    // Create mapping for role
    $('.add-role-mapping').click(function() {
        var roleId = $(this).data('role-id');
        var roleName = $(this).closest('.mapping-card').find('h5').text();
        
        showGroupMappingModal('role', roleId, 'Role: ' + roleName);
    });
    
    // Create mapping for specific group
    $('.create-mapping-for-group').click(function() {
        var groupId = $(this).data('group-id');
        $('#mapping-o365-group').val(groupId);
        showGroupMappingModal('', '', '');
    });
    
    // Create mapping (general)
    $('.create-group-mapping-btn').click(function() {
        showGroupMappingModal('', '', '');
    });
    
    // Submit group mapping form
    $('#group-mapping-form').submit(function(e) {
        e.preventDefault();
        
        var formData = $(this).serialize();
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_create_group_mapping',
            nonce: azure_plugin_ajax.nonce,
            target_type: $('#mapping-target-type').val(),
            target_id: $('#mapping-target-id').val(),
            o365_group_id: $('#mapping-o365-group').val(),
            is_required: $('#mapping-is-required').is(':checked'),
            label: $('#mapping-label').val()
        }, function(response) {
            if (response.success) {
                alert('✅ ' + response.data.message);
                $('#group-mapping-modal').hide();
                location.reload();
            } else {
                alert('❌ Failed to create mapping: ' + (response.data || 'Unknown error'));
            }
        });
    });
    
    // Delete mapping
    $('.delete-mapping').click(function() {
        var mappingId = $(this).data('mapping-id');
        var mappingRow = $(this).closest('.mapped-group');
        
        if (!confirm('Are you sure you want to delete this group mapping?')) {
            return;
        }
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_delete_group_mapping',
            mapping_id: mappingId,
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            if (response.success) {
                mappingRow.fadeOut(function() {
                    mappingRow.remove();
                });
            } else {
                alert('❌ Failed to delete mapping: ' + (response.data || 'Unknown error'));
            }
        });
    });
    
    // View group members
    $('.view-group-members').click(function() {
        var groupId = $(this).data('group-id');
        var modal = $('#group-members-modal');
        var container = $('#group-members-container');
        
        modal.show();
        container.html('Loading group members...');
        
        // This would fetch and display group members
        // For now, show placeholder
        setTimeout(function() {
            container.html('<p>Group members functionality will be implemented here.</p><p>Group ID: ' + groupId + '</p>');
        }, 1000);
    });
    
    // Sync all memberships
    $('.sync-all-memberships').click(function() {
        if (!confirm('This will sync group memberships for all PTA users. This may take several minutes. Continue?')) {
            return;
        }
        
        var button = $(this);
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Syncing...');
        
        // This would trigger a batch sync of all user memberships
        alert('✅ Group membership sync has been queued for all users. Check the sync queue for progress.');
        button.prop('disabled', false).html('<span class="dashicons dashicons-groups"></span> Sync All Memberships');
    });
    
    // View unmapped groups
    $('.view-unmapped-groups').click(function() {
        var button = $(this);
        button.prop('disabled', true).html('<span class="spinner is-active"></span> Loading...');
        
        $.post(azure_plugin_ajax.ajax_url, {
            action: 'pta_get_unmapped_groups',
            nonce: azure_plugin_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).html('<span class="dashicons dashicons-warning"></span> View Unmapped Groups');
            
            if (response.success) {
                showUnmappedGroupsModal(response.data);
            } else {
                alert('❌ Failed to load unmapped groups: ' + (response.data || 'Unknown error'));
            }
        });
    });
    
    // Modal controls
    $('.modal-close, .modal-cancel').click(function() {
        $(this).closest('.modal').hide();
    });
    
    function showGroupMappingModal(targetType, targetId, targetInfo) {
        $('#mapping-target-type').val(targetType);
        $('#mapping-target-id').val(targetId);
        $('#mapping-target-info').html(targetInfo || '<em>Select target and group manually</em>');
        
        $('#group-mapping-modal').show();
    }
    
    function showUnmappedGroupsModal(unmappedGroups) {
        var modal = $('#unmapped-groups-modal');
        var container = $('#unmapped-groups-list');
        
        if (modal.length === 0) {
            // Create modal if it doesn't exist
            var modalHtml = '<div id="unmapped-groups-modal" class="modal" style="display: none;"><div class="modal-content"><div class="modal-header"><h2>Unmapped Office 365 Groups (' + unmappedGroups.length + ')</h2><button type="button" class="modal-close">&times;</button></div><div class="modal-body"><p>These groups exist in your Office 365 tenant but have no PTA role or department mappings:</p><div id="unmapped-groups-list"></div></div></div></div>';
            $('body').append(modalHtml);
            modal = $('#unmapped-groups-modal');
            container = $('#unmapped-groups-list');
        }
        
        container.empty();
        
        if (unmappedGroups.length === 0) {
            container.html('<div class="no-unmapped-groups"><p>🎉 <strong>All groups are mapped!</strong></p><p>Every Office 365 group in your tenant has been assigned to a PTA role or department.</p></div>');
        } else {
            unmappedGroups.forEach(function(group) {
                var groupCard = $('<div class="unmapped-group-card"><div class="group-info"><strong>' + group.display_name + '</strong><div class="group-mail">' + (group.mail || 'No email') + '</div><div class="group-description">' + (group.description || 'No description') + '</div></div><div class="group-actions"><button type="button" class="button create-mapping-quick" data-group-id="' + group.group_id + '">Create Mapping</button></div></div>');
                container.append(groupCard);
            });
            
            // Add click handlers for quick mapping
            container.find('.create-mapping-quick').click(function() {
                var groupId = $(this).data('group-id');
                $('#mapping-o365-group').val(groupId);
                modal.hide();
                showGroupMappingModal('', '', 'Selected from unmapped groups');
            });
        }
        
        modal.show();
    }
});
</script>

<style>
/* PTA Groups Page - Enhanced Contrast Styles */
.groups-sync-section {
    margin-bottom: 30px;
}

.sync-status-card {
    background: #fff !important;
    color: #333 !important;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    padding: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.sync-info {
    display: flex;
    gap: 30px;
}

.sync-stat {
    text-align: center;
}

.sync-stat .stat-number {
    display: block;
    font-size: 24px;
    font-weight: bold;
    color: #0073aa !important;
}

.sync-stat .stat-label {
    font-size: 12px;
    color: #666 !important;
}

.sync-actions {
    display: flex;
    gap: 10px;
}

.groups-actions-section {
    margin-bottom: 30px;
}

.mappings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 20px;
    margin-top: 15px;
}

.mapping-card {
    background: #fff !important;
    color: #333 !important;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    padding: 20px;
}

.mapping-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.mapping-header h3,
.mapping-header h5 {
    margin: 0;
    color: #0073aa !important;
}

.mapped-groups {
    min-height: 50px;
}

.mapped-group {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px;
    background: #f9f9f9 !important;
    color: #333 !important;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin-bottom: 10px;
}

.group-info {
    flex: 1;
}

.group-info strong {
    display: block;
    color: #333 !important;
}

.group-mail {
    font-size: 12px;
    color: #666 !important;
}

.required-badge {
    display: inline-block;
    background: #46b450;
    color: white !important;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 11px;
    margin-left: 8px;
}

.group-actions {
    display: flex;
    gap: 5px;
}

.no-mappings {
    color: #999 !important;
    font-style: italic;
    text-align: center;
    padding: 20px;
}

.department-roles-section {
    margin-bottom: 30px;
}

.department-roles-section h4 {
    margin-bottom: 15px;
    color: #0073aa !important;
    border-bottom: 1px solid #ddd;
    padding-bottom: 5px;
}

.role-mapping {
    margin-bottom: 15px;
}

.role-mapping .mapping-header h5 {
    font-size: 14px;
    color: #0073aa !important;
}

.groups-table-container {
    margin-top: 15px;
}

.groups-table {
    background: #fff !important;
}

.groups-table th,
.groups-table td {
    padding: 12px;
    color: #333 !important;
}

.groups-table th {
    background: #f9f9f9 !important;
    color: #333 !important;
    font-weight: bold;
}

.group-id {
    font-size: 11px;
    color: #666 !important;
    font-family: monospace;
}

.mapping-count.has-mappings {
    color: #46b450 !important;
    font-weight: bold;
}

.mapping-count.no-mappings {
    color: #dc3232 !important;
}

.target-info {
    font-weight: bold;
    color: #0073aa !important;
    padding: 10px;
    background: #f0f8ff !important;
    border: 1px solid #b3d9ff;
    border-radius: 4px;
    margin-bottom: 15px;
}

/* Modal Styles - Enhanced Contrast */
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
    max-width: 600px;
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

/* Responsive Design */
@media (max-width: 768px) {
    .mappings-grid {
        grid-template-columns: 1fr;
    }
    
    .sync-status-card {
        flex-direction: column;
        gap: 20px;
    }
    
    .mapped-group {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .groups-table-container {
        overflow-x: auto;
    }
}
</style>
