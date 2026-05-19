<?php
/**
 * Combined Selling Module Page
 * Tabs: Auction | Classes | Product Fields
 */
if (!defined('ABSPATH')) {
    exit;
}

$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'auction';
$valid_tabs = array('auction', 'classes', 'product-fields', 'donations', 'reports');
if (!in_array($active_tab, $valid_tabs)) {
    $active_tab = 'auction';
}

$GLOBALS['azure_tab_mode'] = true;
?>
<div class="wrap">
    <h1><span class="dashicons dashicons-cart"></span> <?php _e('Selling', 'azure-plugin'); ?></h1>

    <nav class="azure-tabs-nav">
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-selling&tab=auction')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'auction' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-hammer"></span> Auction
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-selling&tab=classes')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'classes' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-welcome-learn-more"></span> Classes
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-selling&tab=product-fields')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'product-fields' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-forms"></span> Product Fields
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-selling&tab=donations')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'donations' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-heart"></span> Donations
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin-selling&tab=reports')); ?>"
           class="azure-tab-link <?php echo $active_tab === 'reports' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-media-spreadsheet"></span> Reports
        </a>
    </nav>

    <?php
    switch ($active_tab) {
        case 'auction':
            include AZURE_PLUGIN_PATH . 'admin/auction-page.php';
            break;
        case 'classes':
            include AZURE_PLUGIN_PATH . 'admin/classes-page.php';
            break;
        case 'product-fields':
            include AZURE_PLUGIN_PATH . 'admin/product-fields-page.php';
            break;
        case 'donations':
            include AZURE_PLUGIN_PATH . 'admin/donations-page.php';
            break;
        case 'reports':
            include AZURE_PLUGIN_PATH . 'admin/orders-reports-page.php';
            break;
    }
    ?>
</div>
<?php unset($GLOBALS['azure_tab_mode']); ?>
