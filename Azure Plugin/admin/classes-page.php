<?php
/**
 * Classes Module Admin Page
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get current settings
$settings = Azure_Settings::get_all_settings();
$classes_enabled = !empty($settings['enable_classes']);
$use_common_credentials = !empty($settings['use_common_credentials']);

// Get class products
$class_products = wc_get_products(array(
    'type'   => 'class',
    'status' => array('publish', 'draft', 'pending'),
    'limit'  => -1
));

// Count stats
$total_classes = count($class_products);
$published_classes = 0;
$draft_classes = 0;
$total_commitments = 0;

foreach ($class_products as $product) {
    if ($product->get_status() === 'publish') {
        $published_classes++;
    } else {
        $draft_classes++;
    }
    
    // Count commitments for variable pricing classes
    $variable_pricing = get_post_meta($product->get_id(), '_class_variable_pricing', true);
    if ($variable_pricing === 'yes' && class_exists('Azure_Classes_Commitment')) {
        $commitment = new Azure_Classes_Commitment();
        $total_commitments += $commitment->get_commitment_count($product->get_id());
    }
}
?>

<?php if (empty($GLOBALS['azure_tab_mode'])): ?>
<div class="wrap azure-classes-page">
    <h1>
        <span class="dashicons dashicons-welcome-learn-more"></span>
        <?php _e('Classes Module', 'azure-plugin'); ?>
    </h1>
<?php else: ?>
<div class="azure-classes-page">
<?php endif; ?>
    
    <?php if (!$classes_enabled): ?>
    <div class="notice notice-warning" style="margin: 15px 0;">
        <p><?php _e('The Classes module is currently disabled.', 'azure-plugin'); ?>
        <a href="<?php echo admin_url('admin.php?page=azure-plugin'); ?>"><?php _e('Enable it on the main settings page.', 'azure-plugin'); ?></a></p>
    </div>
    <?php endif; ?>
    
    <?php if (!class_exists('WooCommerce')) : ?>
    <div class="notice notice-error">
        <p><strong><?php _e('WooCommerce Required:', 'azure-plugin'); ?></strong> 
        <?php _e('The Classes module requires WooCommerce to be installed and activated.', 'azure-plugin'); ?></p>
    </div>
    <?php endif; ?>
    
    <?php if (!class_exists('Tribe__Events__Main')) : ?>
    <div class="notice notice-error">
        <p><strong><?php _e('The Events Calendar Required:', 'azure-plugin'); ?></strong> 
        <?php _e('The Classes module requires The Events Calendar to be installed and activated.', 'azure-plugin'); ?></p>
    </div>
    <?php endif; ?>
    
    <div class="azure-dashboard-grid">
        <!-- Quick Stats -->
        <div class="dashboard-card stats-card">
            <h2><?php _e('Quick Stats', 'azure-plugin'); ?></h2>
            <div class="stats-grid">
                <div class="stat-item">
                    <span class="stat-value"><?php echo $total_classes; ?></span>
                    <span class="stat-label"><?php _e('Total Classes', 'azure-plugin'); ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-value"><?php echo $published_classes; ?></span>
                    <span class="stat-label"><?php _e('Published', 'azure-plugin'); ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-value"><?php echo $draft_classes; ?></span>
                    <span class="stat-label"><?php _e('Draft', 'azure-plugin'); ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-value"><?php echo $total_commitments; ?></span>
                    <span class="stat-label"><?php _e('Commitments', 'azure-plugin'); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="dashboard-card actions-card">
            <h2><?php _e('Quick Actions', 'azure-plugin'); ?></h2>
            <div class="action-buttons">
                <a href="<?php echo admin_url('post-new.php?post_type=product'); ?>" class="button button-primary">
                    <span class="dashicons dashicons-plus-alt"></span>
                    <?php _e('Create New Class', 'azure-plugin'); ?>
                </a>
                <a href="<?php echo admin_url('edit.php?post_type=product&product_type=class'); ?>" class="button">
                    <span class="dashicons dashicons-list-view"></span>
                    <?php _e('View All Classes', 'azure-plugin'); ?>
                </a>
                <a href="<?php echo admin_url('edit-tags.php?taxonomy=class_provider&post_type=product'); ?>" class="button">
                    <span class="dashicons dashicons-building"></span>
                    <?php _e('Manage Providers', 'azure-plugin'); ?>
                </a>
                <a href="<?php echo admin_url('edit.php?post_type=pta_venue'); ?>" class="button">
                    <span class="dashicons dashicons-location"></span>
                    <?php _e('Manage Venues', 'azure-plugin'); ?>
                </a>
            </div>
        </div>
    </div>
    
    <!-- Recent Classes -->
    <div class="dashboard-card">
        <h2><?php _e('Recent Classes', 'azure-plugin'); ?></h2>
        
        <?php if (empty($class_products)) : ?>
        <p class="no-items"><?php _e('No classes created yet.', 'azure-plugin'); ?></p>
        <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Class Name', 'azure-plugin'); ?></th>
                    <th><?php _e('Status', 'azure-plugin'); ?></th>
                    <th><?php _e('Pricing', 'azure-plugin'); ?></th>
                    <th><?php _e('Commitments', 'azure-plugin'); ?></th>
                    <th><?php _e('Start Date', 'azure-plugin'); ?></th>
                    <th><?php _e('Sessions', 'azure-plugin'); ?></th>
                    <th><?php _e('Actions', 'azure-plugin'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $recent_products = array_slice($class_products, 0, 10);
                foreach ($recent_products as $product) : 
                    $product_id = $product->get_id();
                    $variable_pricing = get_post_meta($product_id, '_class_variable_pricing', true);
                    $finalized = get_post_meta($product_id, '_class_finalized', true);
                    $start_date = get_post_meta($product_id, '_class_start_date', true);
                    $occurrences = get_post_meta($product_id, '_class_occurrences', true);
                    
                    $commitment_count = 0;
                    if ($variable_pricing === 'yes' && class_exists('Azure_Classes_Commitment')) {
                        $commitment = new Azure_Classes_Commitment();
                        $commitment_count = $commitment->get_commitment_count($product_id);
                    }
                ?>
                <tr>
                    <td>
                        <strong>
                            <a href="<?php echo get_edit_post_link($product_id); ?>">
                                <?php echo esc_html($product->get_name()); ?>
                            </a>
                        </strong>
                    </td>
                    <td>
                        <span class="status-badge status-<?php echo esc_attr($product->get_status()); ?>">
                            <?php echo esc_html(ucfirst($product->get_status())); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($variable_pricing === 'yes') : ?>
                            <?php if ($finalized === 'yes') : ?>
                                <span class="pricing-badge finalized">
                                    <?php echo wc_price(get_post_meta($product_id, '_class_final_price', true)); ?>
                                    <small>(<?php _e('Final', 'azure-plugin'); ?>)</small>
                                </span>
                            <?php else : ?>
                                <span class="pricing-badge variable">
                                    <?php _e('Variable', 'azure-plugin'); ?>
                                </span>
                            <?php endif; ?>
                        <?php else : ?>
                            <span class="pricing-badge fixed">
                                <?php echo wc_price($product->get_price()); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($variable_pricing === 'yes') : ?>
                            <?php echo $commitment_count; ?> / <?php echo get_post_meta($product_id, '_class_max_attendees', true); ?>
                        <?php else : ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo $start_date ? date_i18n('M j, Y', strtotime($start_date)) : '-'; ?>
                    </td>
                    <td>
                        <?php echo $occurrences ?: '-'; ?>
                    </td>
                    <td>
                        <a href="<?php echo get_edit_post_link($product_id); ?>" class="button button-small">
                            <?php _e('Edit', 'azure-plugin'); ?>
                        </a>
                        <a href="<?php echo get_permalink($product_id); ?>" class="button button-small" target="_blank">
                            <?php _e('View', 'azure-plugin'); ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    
    <!-- Shortcodes Reference -->
    <div class="dashboard-card">
        <h2><?php _e('Available Shortcodes', 'azure-plugin'); ?></h2>
        
        <table class="shortcode-reference">
            <tr>
                <th><?php _e('Shortcode', 'azure-plugin'); ?></th>
                <th><?php _e('Description', 'azure-plugin'); ?></th>
                <th><?php _e('Parameters', 'azure-plugin'); ?></th>
            </tr>
            <tr>
                <td><code>[class_schedule]</code></td>
                <td><?php _e('Displays the class schedule with all sessions.', 'azure-plugin'); ?></td>
                <td>
                    <code>product_id</code> - <?php _e('Product ID (optional on product page)', 'azure-plugin'); ?><br>
                    <code>format</code> - <?php _e('list | table | calendar (default: list)', 'azure-plugin'); ?>
                </td>
            </tr>
            <tr>
                <td><code>[class_pricing]</code></td>
                <td><?php _e('Displays pricing information including likely price for variable pricing.', 'azure-plugin'); ?></td>
                <td>
                    <code>product_id</code> - <?php _e('Product ID (optional on product page)', 'azure-plugin'); ?><br>
                    <code>show_chart</code> - <?php _e('true | false (default: false)', 'azure-plugin'); ?>
                </td>
            </tr>
        </table>
    </div>
    
    <!-- How It Works -->
    <div class="dashboard-card">
        <h2><?php _e('How Classes Work', 'azure-plugin'); ?></h2>
        
        <div class="how-it-works">
            <div class="step">
                <div class="step-number">1</div>
                <h4><?php _e('Create a Class Product', 'azure-plugin'); ?></h4>
                <p><?php _e('Add a new product and select "Class" as the product type. Fill in schedule, provider, venue, and pricing details.', 'azure-plugin'); ?></p>
            </div>
            
            <div class="step">
                <div class="step-number">2</div>
                <h4><?php _e('Events Created Automatically', 'azure-plugin'); ?></h4>
                <p><?php _e('When you save the product, calendar events are automatically created for each session under the "Enrichment" category.', 'azure-plugin'); ?></p>
            </div>
            
            <div class="step">
                <div class="step-number">3</div>
                <h4><?php _e('Families Commit (Variable Pricing)', 'azure-plugin'); ?></h4>
                <p><?php _e('For variable pricing classes, families commit at $0. The likely price updates as more families commit.', 'azure-plugin'); ?></p>
            </div>
            
            <div class="step">
                <div class="step-number">4</div>
                <h4><?php _e('Set Final Price & Request Payment', 'azure-plugin'); ?></h4>
                <p><?php _e('When enrollment closes, set the final price. Payment requests are sent to all committed families.', 'azure-plugin'); ?></p>
            </div>
            
            <div class="step">
                <div class="step-number">5</div>
                <h4><?php _e('Enrollment Confirmed', 'azure-plugin'); ?></h4>
                <p><?php _e('After payment, families receive a confirmation email with the full class schedule.', 'azure-plugin'); ?></p>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Module toggle
    $('#enable-classes-module').on('change', function() {
        var enabled = $(this).is(':checked');
        var header = $('.azure-module-header .module-status');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'azure_toggle_module',
                module: 'classes',
                enabled: enabled,
                nonce: '<?php echo wp_create_nonce('azure_plugin_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    if (enabled) {
                        header.removeClass('disabled').addClass('enabled');
                        header.find('.status-text').text('<?php _e('Module Enabled', 'azure-plugin'); ?>');
                    } else {
                        header.removeClass('enabled').addClass('disabled');
                        header.find('.status-text').text('<?php _e('Module Disabled', 'azure-plugin'); ?>');
                    }
                } else {
                    alert('Failed to toggle module: ' + (response.data || 'Unknown error'));
                    $('#enable-classes-module').prop('checked', !enabled);
                }
            },
            error: function() {
                alert('Network error');
                $('#enable-classes-module').prop('checked', !enabled);
            }
        });
    });
});
</script>

<style>
.azure-classes-page .azure-module-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #fff;
    padding: 15px 20px;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    margin-bottom: 20px;
}

.azure-classes-page .module-status {
    display: flex;
    align-items: center;
    gap: 10px;
}

.azure-classes-page .module-status .status-indicator {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #dc3232;
}

.azure-classes-page .module-status.enabled .status-indicator {
    background: #46b450;
}

.azure-classes-page .azure-dashboard-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.azure-classes-page .dashboard-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
}

.azure-classes-page .dashboard-card h2 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.azure-classes-page .stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    text-align: center;
}

.azure-classes-page .stat-item {
    padding: 15px;
    background: #f7f7f7;
    border-radius: 4px;
}

.azure-classes-page .stat-value {
    display: block;
    font-size: 28px;
    font-weight: bold;
    color: #0073aa;
}

.azure-classes-page .stat-label {
    display: block;
    font-size: 12px;
    color: #666;
    margin-top: 5px;
}

.azure-classes-page .action-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.azure-classes-page .action-buttons .button {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.azure-classes-page .action-buttons .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

.azure-classes-page .status-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 500;
}

.azure-classes-page .status-badge.status-publish {
    background: #e8f4fd;
    color: #0073aa;
}

.azure-classes-page .status-badge.status-draft {
    background: #f7f7f7;
    color: #666;
}

.azure-classes-page .pricing-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
}

.azure-classes-page .pricing-badge.variable {
    background: #fff3cd;
    color: #856404;
}

.azure-classes-page .pricing-badge.finalized {
    background: #d4edda;
    color: #155724;
}

.azure-classes-page .pricing-badge.fixed {
    background: #e8f4fd;
    color: #0073aa;
}

.azure-classes-page .shortcode-reference {
    width: 100%;
    border-collapse: collapse;
}

.azure-classes-page .shortcode-reference th,
.azure-classes-page .shortcode-reference td {
    padding: 10px;
    border: 1px solid #ddd;
    text-align: left;
}

.azure-classes-page .shortcode-reference th {
    background: #f7f7f7;
}

.azure-classes-page .how-it-works {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.azure-classes-page .step {
    text-align: center;
    padding: 20px;
}

.azure-classes-page .step-number {
    width: 40px;
    height: 40px;
    line-height: 40px;
    background: #0073aa;
    color: #fff;
    border-radius: 50%;
    font-size: 18px;
    font-weight: bold;
    margin: 0 auto 10px;
}

.azure-classes-page .step h4 {
    margin: 10px 0;
}

.azure-classes-page .step p {
    color: #666;
    font-size: 13px;
    margin: 0;
}

/* Switch toggle */
.azure-classes-page .switch {
    position: relative;
    display: inline-block;
    width: 50px;
    height: 24px;
}

.azure-classes-page .switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.azure-classes-page .slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 24px;
}

.azure-classes-page .slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}

.azure-classes-page input:checked + .slider {
    background-color: #0073aa;
}

.azure-classes-page input:checked + .slider:before {
    transform: translateX(26px);
}
</style>

