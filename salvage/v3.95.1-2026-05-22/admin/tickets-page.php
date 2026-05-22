<?php
/**
 * Tickets Module - Main Admin Page
 */

if (!defined('ABSPATH')) {
    exit;
}

$settings = Azure_Settings::get_all_settings();

// Get current tab
$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'dashboard';

global $wpdb;
$tickets_table = $wpdb->prefix . 'azure_tickets';

// Check if tables exist
$tables_exist = $wpdb->get_var("SHOW TABLES LIKE '{$tickets_table}'") === $tickets_table;

// Get stats
$stats = array(
    'total_venues' => 0,
    'total_tickets_sold' => 0,
    'tickets_today' => 0,
    'revenue_month' => 0,
    'upcoming_events' => 0
);

if ($tables_exist) {
    $stats['total_tickets_sold'] = $wpdb->get_var("SELECT COUNT(*) FROM {$tickets_table}") ?: 0;
    $stats['tickets_today'] = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$tickets_table} WHERE DATE(created_at) = %s",
        current_time('Y-m-d')
    )) ?: 0;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tickets_settings_nonce'])) {
    if (wp_verify_nonce($_POST['tickets_settings_nonce'], 'tickets_settings')) {
        // Save Apple Wallet settings
        if (isset($_POST['apple_wallet_pass_type_id'])) {
            Azure_Settings::update_setting('tickets_apple_pass_type_id', sanitize_text_field($_POST['apple_wallet_pass_type_id']));
        }
        if (isset($_POST['apple_wallet_team_id'])) {
            Azure_Settings::update_setting('tickets_apple_team_id', sanitize_text_field($_POST['apple_wallet_team_id']));
        }
        
        // Handle certificate upload
        if (!empty($_FILES['apple_wallet_certificate']['tmp_name'])) {
            $upload_dir = wp_upload_dir();
            $cert_dir = $upload_dir['basedir'] . '/azure-plugin/certificates/';
            
            if (!file_exists($cert_dir)) {
                wp_mkdir_p($cert_dir);
                file_put_contents($cert_dir . '.htaccess', 'deny from all');
            }
            
            $cert_path = $cert_dir . 'apple-wallet.p12';
            move_uploaded_file($_FILES['apple_wallet_certificate']['tmp_name'], $cert_path);
            Azure_Settings::update_setting('tickets_apple_cert_path', $cert_path);
        }
        
        if (isset($_POST['apple_wallet_cert_password'])) {
            Azure_Settings::update_setting('tickets_apple_cert_password', sanitize_text_field($_POST['apple_wallet_cert_password']));
        }
        
        // General settings
        if (isset($_POST['tickets_require_names'])) {
            Azure_Settings::update_setting('tickets_require_names', $_POST['tickets_require_names'] === '1');
        }
        if (isset($_POST['tickets_reservation_timeout'])) {
            Azure_Settings::update_setting('tickets_reservation_timeout', intval($_POST['tickets_reservation_timeout']));
        }
        
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Settings saved.', 'azure-plugin') . '</p></div>';
        $settings = Azure_Settings::get_all_settings(); // Reload
    }
}

// Handle table creation
if (isset($_POST['create_tickets_tables']) && wp_verify_nonce($_POST['tickets_danger_nonce'], 'tickets_danger_zone')) {
    Azure_Tickets_Module::create_tables();
    echo '<div class="notice notice-success is-dismissible"><p>' . __('Database tables created successfully.', 'azure-plugin') . '</p></div>';
    $tables_exist = true;
}
?>

<div class="wrap azure-tickets-page">
    <h1>
        <span class="dashicons dashicons-tickets-alt"></span>
        <?php _e('Event Tickets', 'azure-plugin'); ?>
    </h1>
    
    <!-- Module Toggle -->
    <div class="module-status-section">
        <div class="module-toggle-card">
            <div class="module-info">
                <h3><span class="dashicons dashicons-tickets-alt"></span> <?php _e('Event Tickets Module', 'azure-plugin'); ?></h3>
                <p><?php _e('Visual seating designer, QR code tickets, Apple Wallet passes, and event check-in.', 'azure-plugin'); ?></p>
            </div>
            <div class="module-control">
                <label class="switch">
                    <input type="checkbox" class="module-toggle" data-module="tickets" <?php checked($settings['enable_tickets'] ?? false); ?> />
                    <span class="slider"></span>
                </label>
                <span class="module-status"><?php echo ($settings['enable_tickets'] ?? false) ? __('Enabled', 'azure-plugin') : __('Disabled', 'azure-plugin'); ?></span>
            </div>
        </div>
    </div>
    
    <?php if (!$tables_exist): ?>
    <div class="notice notice-warning">
        <p>
            <strong><?php _e('Database tables not created.', 'azure-plugin'); ?></strong>
            <?php _e('Please create the required database tables to use the Tickets module.', 'azure-plugin'); ?>
        </p>
        <form method="post" style="margin: 10px 0;">
            <?php wp_nonce_field('tickets_danger_zone', 'tickets_danger_nonce'); ?>
            <button type="submit" name="create_tickets_tables" class="button button-primary">
                <?php _e('Create Database Tables', 'azure-plugin'); ?>
            </button>
        </form>
    </div>
    <?php endif; ?>
    
    <!-- Tab Navigation -->
    <nav class="nav-tab-wrapper">
        <a href="?page=azure-plugin-tickets&tab=dashboard" class="nav-tab <?php echo $current_tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-dashboard"></span> <?php _e('Dashboard', 'azure-plugin'); ?>
        </a>
        <a href="?page=azure-plugin-tickets&tab=venues" class="nav-tab <?php echo $current_tab === 'venues' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-admin-home"></span> <?php _e('Venues', 'azure-plugin'); ?>
        </a>
        <a href="?page=azure-plugin-tickets&tab=tickets" class="nav-tab <?php echo $current_tab === 'tickets' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-tickets-alt"></span> <?php _e('Tickets', 'azure-plugin'); ?>
        </a>
        <a href="<?php echo admin_url('admin.php?page=azure-plugin-tickets-checkin'); ?>" class="nav-tab <?php echo $current_tab === 'checkin' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-smartphone"></span> <?php _e('Check-in', 'azure-plugin'); ?>
        </a>
        <a href="?page=azure-plugin-tickets&tab=settings" class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-admin-settings"></span> <?php _e('Settings', 'azure-plugin'); ?>
        </a>
    </nav>
    
    <div class="tab-content">
        <?php
        switch ($current_tab) {
            case 'dashboard':
                include AZURE_PLUGIN_PATH . 'admin/tickets-dashboard.php';
                break;
            case 'venues':
                include AZURE_PLUGIN_PATH . 'admin/tickets-venues.php';
                break;
            case 'tickets':
                include AZURE_PLUGIN_PATH . 'admin/tickets-list.php';
                break;
            case 'settings':
                include AZURE_PLUGIN_PATH . 'admin/tickets-settings.php';
                break;
            default:
                include AZURE_PLUGIN_PATH . 'admin/tickets-dashboard.php';
        }
        ?>
    </div>
</div>

<style>
.azure-tickets-page .module-status-section {
    margin-bottom: 20px;
}

.azure-tickets-page .module-toggle-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    padding: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.azure-tickets-page .module-info h3 {
    margin: 0 0 5px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.azure-tickets-page .module-info p {
    margin: 0;
    color: #646970;
}

.azure-tickets-page .module-control {
    display: flex;
    align-items: center;
    gap: 15px;
}

.azure-tickets-page .nav-tab-wrapper {
    margin-bottom: 20px;
}

.azure-tickets-page .nav-tab {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.azure-tickets-page .tab-content {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    padding: 20px;
}

/* Switch toggle */
.azure-tickets-page .switch {
    position: relative;
    display: inline-block;
    width: 50px;
    height: 26px;
}

.azure-tickets-page .switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.azure-tickets-page .slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: 0.4s;
    border-radius: 26px;
}

.azure-tickets-page .slider:before {
    position: absolute;
    content: "";
    height: 20px;
    width: 20px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: 0.4s;
    border-radius: 50%;
}

.azure-tickets-page input:checked + .slider {
    background-color: #2271b1;
}

.azure-tickets-page input:checked + .slider:before {
    transform: translateX(24px);
}
</style>

<script>
jQuery(document).ready(function($) {
    // Module toggle
    $('.module-toggle').on('change', function() {
        var $toggle = $(this);
        var module = $toggle.data('module');
        var enabled = $toggle.is(':checked');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'azure_toggle_module',
                module: module,
                enabled: enabled,
                nonce: '<?php echo wp_create_nonce('azure_plugin_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    $toggle.closest('.module-toggle-card').find('.module-status').text(enabled ? 'Enabled' : 'Disabled');
                } else {
                    alert('Error: ' + response.data);
                    $toggle.prop('checked', !enabled);
                }
            },
            error: function() {
                alert('Error toggling module');
                $toggle.prop('checked', !enabled);
            }
        });
    });
});
</script>

