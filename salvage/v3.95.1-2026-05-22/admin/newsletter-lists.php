<?php
/**
 * Newsletter Lists Management
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

$lists_table = $wpdb->prefix . 'azure_newsletter_lists';
$members_table = $wpdb->prefix . 'azure_newsletter_list_members';

// Check if table exists
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$lists_table}'") === $lists_table;

if (!$table_exists) {
    echo '<div class="notice notice-info"><p>' . __('Newsletter tables not yet created.', 'azure-plugin') . '</p></div>';
    return;
}

// Handle list creation
if (isset($_POST['create_list']) && wp_verify_nonce($_POST['_wpnonce'], 'create_newsletter_list')) {
    $name = sanitize_text_field($_POST['list_name']);
    $description = sanitize_textarea_field($_POST['list_description']);
    $type = sanitize_key($_POST['list_type']);
    $criteria = array();
    
    if ($type === 'role') {
        $criteria['roles'] = array_map('sanitize_key', (array)$_POST['list_roles']);
    } elseif ($type === 'tag') {
        $criteria['tags'] = array_map('sanitize_text_field', (array)$_POST['list_tags']);
    }
    
    $wpdb->insert($lists_table, array(
        'name' => $name,
        'description' => $description,
        'type' => $type,
        'criteria' => json_encode($criteria),
        'created_at' => current_time('mysql')
    ));
    
    echo '<div class="notice notice-success"><p>' . __('List created successfully.', 'azure-plugin') . '</p></div>';
}

// Handle list deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    if (wp_verify_nonce($_GET['_wpnonce'], 'delete_list')) {
        $id = intval($_GET['id']);
        $wpdb->delete($lists_table, array('id' => $id), array('%d'));
        $wpdb->delete($members_table, array('list_id' => $id), array('%d'));
        echo '<div class="notice notice-success"><p>' . __('List deleted.', 'azure-plugin') . '</p></div>';
    }
}

// Get list being edited (if any)
$editing_list = null;
$editing_members = array();
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_id = intval($_GET['id']);
    $editing_list = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$lists_table} WHERE id = %d", $edit_id));
    
    if ($editing_list && $editing_list->type === 'custom') {
        $editing_members = $wpdb->get_results($wpdb->prepare(
            "SELECT m.*, u.user_email, u.display_name 
             FROM {$members_table} m
             LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID
             WHERE m.list_id = %d AND m.unsubscribed_at IS NULL
             ORDER BY u.display_name ASC",
            $edit_id
        ));
    }
}

// Get all lists
$lists = $wpdb->get_results("SELECT * FROM {$lists_table} ORDER BY name ASC");

// Get WordPress user count (all subscribers)
$total_users = count_users();
$all_users_count = $total_users['total_users'];

// Get WordPress roles
$wp_roles = wp_roles();
$roles = $wp_roles->get_names();
?>

<div class="newsletter-lists-page">
    
    <!-- Default List (All WordPress Users) -->
    <div class="default-list-section">
        <h3><?php _e('Default List', 'azure-plugin'); ?></h3>
        <div class="list-card default-list">
            <h4>
                <span class="dashicons dashicons-admin-users"></span>
                <?php _e('All WordPress Subscribers', 'azure-plugin'); ?>
            </h4>
            <div class="list-meta">
                <span><?php printf(__('%s subscribers', 'azure-plugin'), number_format($all_users_count)); ?></span>
            </div>
            <p class="description"><?php _e('All registered WordPress users will receive your newsletters by default.', 'azure-plugin'); ?></p>
        </div>
    </div>
    
    <!-- Custom Lists -->
    <div class="custom-lists-section">
        <h3>
            <?php _e('Custom Lists', 'azure-plugin'); ?>
            <button type="button" class="button button-primary" id="create-list-btn">
                <?php _e('+ Create New List', 'azure-plugin'); ?>
            </button>
        </h3>
        
        <?php if (empty($lists)): ?>
        <div class="empty-lists">
            <p><?php _e('No custom lists yet. Create a list to segment your subscribers by role, tag, or custom criteria.', 'azure-plugin'); ?></p>
        </div>
        <?php else: ?>
        <div class="lists-grid">
            <?php foreach ($lists as $list): ?>
            <?php
            // Count members
            $member_count = 0;
            $criteria = json_decode($list->criteria, true);
            
            if ($list->type === 'all_users') {
                $member_count = $all_users_count;
            } elseif ($list->type === 'role' && !empty($criteria['roles'])) {
                foreach ($criteria['roles'] as $role) {
                    $users = get_users(array('role' => $role, 'count_total' => true));
                    $member_count += count($users);
                }
            } elseif ($list->type === 'custom') {
                $member_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$members_table} WHERE list_id = %d AND unsubscribed_at IS NULL",
                    $list->id
                ));
            }
            ?>
            <div class="list-card">
                <h4><?php echo esc_html($list->name); ?></h4>
                <div class="list-meta">
                    <span class="type-badge type-<?php echo esc_attr($list->type); ?>">
                        <?php echo ucfirst($list->type); ?>
                    </span>
                    <span><?php printf(__('%s subscribers', 'azure-plugin'), number_format($member_count)); ?></span>
                </div>
                <p><?php echo esc_html($list->description); ?></p>
                
                <?php if ($list->type === 'role' && !empty($criteria['roles'])): ?>
                <div class="list-criteria">
                    <strong><?php _e('Roles:', 'azure-plugin'); ?></strong>
                    <?php echo implode(', ', array_map(function($r) use ($roles) { return $roles[$r] ?? $r; }, $criteria['roles'])); ?>
                </div>
                <?php endif; ?>
                
                <div class="list-actions">
                    <button type="button" class="button button-small edit-list-btn" 
                            data-id="<?php echo $list->id; ?>"
                            data-name="<?php echo esc_attr($list->name); ?>"
                            data-description="<?php echo esc_attr($list->description); ?>"
                            data-type="<?php echo esc_attr($list->type); ?>">
                        <?php _e('Edit', 'azure-plugin'); ?>
                    </button>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=azure-plugin-newsletter&tab=lists&action=delete&id=' . $list->id), 'delete_list'); ?>" 
                       class="button button-small" onclick="return confirm('<?php _e('Are you sure?', 'azure-plugin'); ?>')">
                        <?php _e('Delete', 'azure-plugin'); ?>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Create List Modal -->
<div id="create-list-modal" class="modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?php _e('Create New List', 'azure-plugin'); ?></h3>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <form method="post">
            <?php wp_nonce_field('create_newsletter_list'); ?>
            <div class="modal-body">
                <table class="form-table">
                    <tr>
                        <th><label for="list_name"><?php _e('List Name', 'azure-plugin'); ?></label></th>
                        <td><input type="text" name="list_name" id="list_name" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="list_description"><?php _e('Description', 'azure-plugin'); ?></label></th>
                        <td><textarea name="list_description" id="list_description" class="regular-text" rows="2"></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="list_type"><?php _e('List Type', 'azure-plugin'); ?></label></th>
                        <td>
                            <select name="list_type" id="list_type" required>
                                <option value="role"><?php _e('Based on User Role', 'azure-plugin'); ?></option>
                                <option value="custom"><?php _e('Manual / Custom', 'azure-plugin'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <div id="role-options" class="type-options">
                    <h4><?php _e('Select Roles', 'azure-plugin'); ?></h4>
                    <div class="roles-checkboxes">
                        <?php foreach ($roles as $role_key => $role_name): ?>
                        <label>
                            <input type="checkbox" name="list_roles[]" value="<?php echo esc_attr($role_key); ?>">
                            <?php echo esc_html($role_name); ?>
                            (<?php echo number_format($total_users['avail_roles'][$role_key] ?? 0); ?>)
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div id="custom-options" class="type-options" style="display:none;">
                    <p class="description"><?php _e('Manual lists allow you to add subscribers individually or import from CSV.', 'azure-plugin'); ?></p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="button modal-cancel"><?php _e('Cancel', 'azure-plugin'); ?></button>
                <button type="submit" name="create_list" class="button button-primary"><?php _e('Create List', 'azure-plugin'); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Edit List Modal -->
<div id="edit-list-modal" class="modal" style="display:none;">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h3><?php _e('Edit List', 'azure-plugin'); ?>: <span id="edit-list-name"></span></h3>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="edit-list-id" value="">
            
            <!-- Role-based list info -->
            <div id="edit-role-info" style="display:none;">
                <p class="description"><?php _e('This is a role-based list. Members are automatically determined by user roles. To change the roles, delete this list and create a new one.', 'azure-plugin'); ?></p>
            </div>
            
            <!-- Custom list management -->
            <div id="edit-custom-list">
                <div class="user-search-section">
                    <h4><?php _e('Add Members', 'azure-plugin'); ?></h4>
                    <div class="search-box-wrapper">
                        <input type="text" id="user-search-input" class="regular-text" 
                               placeholder="<?php _e('Search users by name or email...', 'azure-plugin'); ?>">
                        <span class="spinner" id="search-spinner"></span>
                    </div>
                    <div id="user-search-results"></div>
                </div>
                
                <div class="current-members-section">
                    <h4><?php _e('Current Members', 'azure-plugin'); ?> (<span id="member-count">0</span>)</h4>
                    <div id="current-members-list">
                        <p class="no-members"><?php _e('No members in this list yet.', 'azure-plugin'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <span id="edit-list-status" style="flex:1;color:#00a32a;"></span>
            <button type="button" class="button button-primary" id="save-list-btn"><?php _e('Done', 'azure-plugin'); ?></button>
        </div>
    </div>
</div>

<style>
.newsletter-lists-page h3 {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 30px;
}
.newsletter-lists-page .default-list-section h3 {
    margin-top: 0;
}
.newsletter-lists-page .lists-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 15px;
}
.newsletter-lists-page .list-card {
    background: #f8f9fa;
    border: 1px solid #ccd0d4;
    padding: 20px;
    border-radius: 4px;
}
.newsletter-lists-page .list-card.default-list {
    background: #f0f6fc;
    border-color: #2271b1;
    max-width: 400px;
}
.newsletter-lists-page .list-card h4 {
    margin: 0 0 10px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.newsletter-lists-page .list-meta {
    display: flex;
    gap: 15px;
    margin-bottom: 10px;
    color: #646970;
    font-size: 13px;
}
.newsletter-lists-page .type-badge {
    background: #dcdcde;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
}
.newsletter-lists-page .type-badge.type-role {
    background: #f0f6fc;
    color: #2271b1;
}
.newsletter-lists-page .list-criteria {
    margin: 10px 0;
    padding: 10px;
    background: #fff;
    border-radius: 3px;
    font-size: 13px;
}
.newsletter-lists-page .list-actions {
    margin-top: 15px;
    display: flex;
    gap: 10px;
}
.newsletter-lists-page .empty-lists {
    background: #f8f9fa;
    padding: 30px;
    text-align: center;
    color: #646970;
    margin-top: 15px;
}

/* Modal Styles */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.6);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
}
.modal-content {
    background: #fff;
    width: 90%;
    max-width: 600px;
    border-radius: 4px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    border-bottom: 1px solid #ddd;
}
.modal-header h3 {
    margin: 0;
}
.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #666;
}
.modal-body {
    padding: 20px;
    max-height: 60vh;
    overflow-y: auto;
}
.modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #ddd;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
.type-options {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #eee;
}
.roles-checkboxes {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}
.roles-checkboxes label {
    display: flex;
    align-items: center;
    gap: 5px;
}

/* Edit Modal Styles */
.modal-large {
    max-width: 800px;
}
.user-search-section {
    margin-bottom: 25px;
}
.search-box-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    gap: 10px;
}
.search-box-wrapper input {
    width: 100%;
    max-width: 400px;
    padding: 8px 12px;
    font-size: 14px;
}
.search-box-wrapper .spinner {
    visibility: hidden;
}
.search-box-wrapper .spinner.is-active {
    visibility: visible;
}
#user-search-results {
    margin-top: 10px;
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid #ddd;
    border-radius: 4px;
    display: none;
}
#user-search-results.has-results {
    display: block;
}
.search-result-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 12px;
    border-bottom: 1px solid #eee;
    cursor: pointer;
    transition: background 0.2s;
}
.search-result-item:last-child {
    border-bottom: none;
}
.search-result-item:hover {
    background: #f0f6fc;
}
.search-result-item.already-member {
    background: #f8f9fa;
    color: #999;
    cursor: default;
}
.search-result-item .user-info {
    display: flex;
    flex-direction: column;
}
.search-result-item .user-name {
    font-weight: 500;
}
.search-result-item .user-email {
    font-size: 12px;
    color: #666;
}
.search-result-item .add-btn {
    color: #2271b1;
    font-size: 12px;
}
.search-result-item.already-member .add-btn {
    color: #999;
}

/* Current Members List */
.current-members-section h4 {
    margin-bottom: 10px;
}
#current-members-list {
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: #f8f9fa;
}
.member-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 12px;
    background: #fff;
    border-bottom: 1px solid #eee;
}
.member-item:last-child {
    border-bottom: none;
}
.member-item .member-info {
    display: flex;
    flex-direction: column;
}
.member-item .member-name {
    font-weight: 500;
}
.member-item .member-email {
    font-size: 12px;
    color: #666;
}
.member-item .remove-btn {
    color: #d63638;
    cursor: pointer;
    font-size: 12px;
}
.member-item .remove-btn:hover {
    text-decoration: underline;
}
.no-members {
    padding: 20px;
    text-align: center;
    color: #666;
    margin: 0;
}
</style>

<script>
jQuery(document).ready(function($) {
    var searchTimeout = null;
    var currentListId = null;
    var currentMembers = [];
    
    // Open create modal
    $('#create-list-btn').on('click', function() {
        $('#create-list-modal').show();
    });
    
    // Close modals
    $('.modal-close, .modal-cancel').on('click', function() {
        closeModal($(this).closest('.modal'));
    });
    
    // Save/Done button for edit list
    $('#save-list-btn').on('click', function() {
        closeModal($('#edit-list-modal'));
    });
    
    // Close on background click
    $('.modal').on('click', function(e) {
        if (e.target === this) {
            closeModal($(this));
        }
    });
    
    function closeModal($modal) {
        // If closing edit list modal, update the UI
        if ($modal.attr('id') === 'edit-list-modal' && currentListId) {
            // Update the member count in the list card
            var $listCard = $('.edit-list-btn[data-id="' + currentListId + '"]').closest('.list-card');
            var memberCount = currentMembers.length;
            $listCard.find('.list-meta span:last-child').text(memberCount + ' subscribers');
        }
        
        $modal.hide();
        $('#user-search-input').val('');
        $('#user-search-results').removeClass('has-results').empty();
        $('#edit-list-status').text('');
        $('body').css('overflow', '');
    }
    
    // Toggle type options
    $('#list_type').on('change', function() {
        $('.type-options').hide();
        if ($(this).val() === 'role') {
            $('#role-options').show();
        } else if ($(this).val() === 'custom') {
            $('#custom-options').show();
        }
    });
    
    // Edit list button click
    $('.edit-list-btn').on('click', function() {
        var listId = $(this).data('id');
        var listName = $(this).data('name');
        var listType = $(this).data('type');
        
        currentListId = listId;
        $('#edit-list-id').val(listId);
        $('#edit-list-name').text(listName);
        
        // Show/hide appropriate sections based on list type
        if (listType === 'custom') {
            $('#edit-role-info').hide();
            $('#edit-custom-list').show();
            loadListMembers(listId);
        } else {
            $('#edit-role-info').show();
            $('#edit-custom-list').hide();
        }
        
        $('#edit-list-modal').show();
    });
    
    // Load list members
    function loadListMembers(listId) {
        $('#current-members-list').html('<p class="no-members">Loading...</p>');
        
        $.post(ajaxurl, {
            action: 'azure_newsletter_get_list_members',
            nonce: '<?php echo wp_create_nonce('azure_newsletter_lists'); ?>',
            list_id: listId
        }, function(response) {
            if (response.success) {
                currentMembers = response.data.members || [];
                renderMembers();
            } else {
                $('#current-members-list').html('<p class="no-members">Error loading members.</p>');
            }
        });
    }
    
    // Render members list
    function renderMembers() {
        var html = '';
        $('#member-count').text(currentMembers.length);
        
        if (currentMembers.length === 0) {
            html = '<p class="no-members"><?php _e('No members in this list yet.', 'azure-plugin'); ?></p>';
        } else {
            currentMembers.forEach(function(member) {
                html += '<div class="member-item" data-user-id="' + member.user_id + '">';
                html += '<div class="member-info">';
                html += '<span class="member-name">' + escapeHtml(member.display_name || 'User #' + member.user_id) + '</span>';
                html += '<span class="member-email">' + escapeHtml(member.user_email || '') + '</span>';
                html += '</div>';
                html += '<span class="remove-btn" data-user-id="' + member.user_id + '"><?php _e('Remove', 'azure-plugin'); ?></span>';
                html += '</div>';
            });
        }
        
        $('#current-members-list').html(html);
    }
    
    // User search
    $('#user-search-input').on('input', function() {
        var query = $(this).val().trim();
        
        clearTimeout(searchTimeout);
        
        if (query.length < 2) {
            $('#user-search-results').removeClass('has-results').empty();
            return;
        }
        
        $('#search-spinner').addClass('is-active');
        
        searchTimeout = setTimeout(function() {
            $.post(ajaxurl, {
                action: 'azure_newsletter_search_users',
                nonce: '<?php echo wp_create_nonce('azure_newsletter_lists'); ?>',
                query: query,
                list_id: currentListId
            }, function(response) {
                $('#search-spinner').removeClass('is-active');
                
                if (response.success && response.data.users.length > 0) {
                    var html = '';
                    response.data.users.forEach(function(user) {
                        var isMember = currentMembers.some(m => m.user_id == user.ID);
                        html += '<div class="search-result-item ' + (isMember ? 'already-member' : '') + '" data-user-id="' + user.ID + '">';
                        html += '<div class="user-info">';
                        html += '<span class="user-name">' + escapeHtml(user.display_name) + '</span>';
                        html += '<span class="user-email">' + escapeHtml(user.user_email) + '</span>';
                        html += '</div>';
                        html += '<span class="add-btn">' + (isMember ? '<?php _e('Already added', 'azure-plugin'); ?>' : '<?php _e('+ Add', 'azure-plugin'); ?>') + '</span>';
                        html += '</div>';
                    });
                    $('#user-search-results').addClass('has-results').html(html);
                } else {
                    $('#user-search-results').addClass('has-results').html('<div style="padding:10px;color:#666;"><?php _e('No users found', 'azure-plugin'); ?></div>');
                }
            });
        }, 300);
    });
    
    // Add user to list
    $(document).on('click', '.search-result-item:not(.already-member)', function() {
        var userId = $(this).data('user-id');
        var userName = $(this).find('.user-name').text();
        var userEmail = $(this).find('.user-email').text();
        var $item = $(this);
        
        $item.addClass('already-member');
        $item.find('.add-btn').text('Adding...');
        
        $.post(ajaxurl, {
            action: 'azure_newsletter_add_list_member',
            nonce: '<?php echo wp_create_nonce('azure_newsletter_lists'); ?>',
            list_id: currentListId,
            user_id: userId
        }, function(response) {
            if (response.success) {
                $item.find('.add-btn').text('<?php _e('Already added', 'azure-plugin'); ?>');
                currentMembers.push({
                    user_id: userId,
                    display_name: userName,
                    user_email: userEmail
                });
                renderMembers();
                showStatus('<?php _e('Member added', 'azure-plugin'); ?>');
            } else {
                $item.removeClass('already-member');
                $item.find('.add-btn').text('<?php _e('+ Add', 'azure-plugin'); ?>');
                alert(response.data || 'Error adding user');
            }
        });
    });
    
    function showStatus(message) {
        $('#edit-list-status').text('✓ ' + message);
        setTimeout(function() {
            $('#edit-list-status').text('');
        }, 2000);
    }
    
    // Remove user from list
    $(document).on('click', '.remove-btn', function() {
        var userId = $(this).data('user-id');
        var $item = $(this).closest('.member-item');
        
        if (!confirm('<?php _e('Remove this member from the list?', 'azure-plugin'); ?>')) {
            return;
        }
        
        $item.css('opacity', '0.5');
        
        $.post(ajaxurl, {
            action: 'azure_newsletter_remove_list_member',
            nonce: '<?php echo wp_create_nonce('azure_newsletter_lists'); ?>',
            list_id: currentListId,
            user_id: userId
        }, function(response) {
            if (response.success) {
                currentMembers = currentMembers.filter(m => m.user_id != userId);
                renderMembers();
                // Update search results if visible
                $('.search-result-item[data-user-id="' + userId + '"]')
                    .removeClass('already-member')
                    .find('.add-btn').text('<?php _e('+ Add', 'azure-plugin'); ?>');
            } else {
                $item.css('opacity', '1');
                alert(response.data || 'Error removing user');
            }
        });
    });
    
    // Escape HTML helper
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});
</script>
