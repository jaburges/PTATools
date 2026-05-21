<?php
/**
 * User Management → Account Menu tab
 *
 * Documents the [pta_user_dropdown] shortcode and the pta-account-menu
 * theme location, and surfaces the current menu assignment so the admin
 * can confirm everything is wired up before retiring MemberPlus's
 * memb-plus-nav.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

$location_slug   = Azure_User_Management_Module::NAV_LOCATION;
$locations       = get_nav_menu_locations();
$assigned_id     = !empty($locations[$location_slug]) ? (int) $locations[$location_slug] : 0;
$assigned_menu   = $assigned_id ? wp_get_nav_menu_object($assigned_id) : null;
$menu_items      = $assigned_menu ? wp_get_nav_menu_items($assigned_menu->term_id) : array();
$menus_admin_url = admin_url('nav-menus.php?action=locations');
$all_menus       = wp_get_nav_menus();
$assign_nonce    = wp_create_nonce(Azure_User_Management_Module::NONCE_ACTION);
?>

<div class="card" style="max-width:880px;">
    <h2 style="margin-top:0;"><?php _e('Header dropdown shortcode', 'azure-plugin'); ?></h2>
    <p class="description">
        <?php _e('Drop this shortcode into the Kadence "Header HTML" element (or any theme HTML slot) to render the user account dropdown. Replaces MemberPlus\'s built-in dropdown so the plugin can be retired once you\'re ready.', 'azure-plugin'); ?>
    </p>

    <p>
        <input type="text" id="pta-um-shortcode" value="[pta_user_dropdown]" readonly
               style="width:280px;font-family:monospace;font-size:14px;background:#f6f7f7;" />
        <button type="button" class="button" id="pta-um-shortcode-copy"><?php _e('Copy', 'azure-plugin'); ?></button>
    </p>

    <details style="margin-top:14px;">
        <summary style="cursor:pointer;color:#2271b1;"><?php _e('Optional shortcode attributes', 'azure-plugin'); ?></summary>
        <pre style="background:#f6f7f7;padding:10px;border-radius:4px;font-size:12px;line-height:1.5;">[pta_user_dropdown
    logged_out_text="Sign in"
    show_avatar="yes"
    show_name="yes"]</pre>
    </details>
</div>

<div class="card" style="max-width:880px;margin-top:18px;">
    <h2 style="margin-top:0;"><?php _e('Menu items', 'azure-plugin'); ?></h2>
    <p class="description">
        <?php _e('Pick which WordPress nav menu drives the dropdown. Edit the items under Appearance → Menus → pick the chosen menu.', 'azure-plugin'); ?>
    </p>

    <p>
        <label for="pta-um-menu-select" style="margin-right:8px;"><strong><?php _e('Assigned menu:', 'azure-plugin'); ?></strong></label>
        <select id="pta-um-menu-select" style="min-width:240px;">
            <option value="0"><?php _e('— None (use built-in fallback) —', 'azure-plugin'); ?></option>
            <?php foreach ($all_menus as $m): ?>
                <option value="<?php echo (int) $m->term_id; ?>" <?php selected($assigned_id, (int) $m->term_id); ?>>
                    <?php echo esc_html($m->name); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="button button-primary" id="pta-um-menu-save"><?php _e('Save assignment', 'azure-plugin'); ?></button>
        <span id="pta-um-menu-status" style="margin-left:10px;color:#666;"></span>
    </p>

    <p style="margin-top:14px;">
        <a href="<?php echo esc_url($assigned_menu ? admin_url('nav-menus.php?action=edit&menu=' . (int) $assigned_menu->term_id) : $menus_admin_url); ?>" class="button button-small">
            <?php echo $assigned_menu ? esc_html__('Edit this menu', 'azure-plugin') : esc_html__('Open Appearance → Menus', 'azure-plugin'); ?>
        </a>
    </p>

    <div id="pta-um-menu-items">
        <?php if ($assigned_menu): ?>
            <table class="widefat striped" style="max-width:540px;margin-top:14px;">
                <thead>
                    <tr>
                        <th style="width:40%;"><?php _e('Label', 'azure-plugin'); ?></th>
                        <th><?php _e('URL', 'azure-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($menu_items)): ?>
                    <tr><td colspan="2" style="color:#666;font-style:italic;"><?php _e('No items in this menu yet.', 'azure-plugin'); ?></td></tr>
                <?php else:
                    foreach ($menu_items as $item): ?>
                    <tr>
                        <td><?php echo esc_html($item->title); ?></td>
                        <td style="font-family:monospace;font-size:12px;"><?php echo esc_html($item->url); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color:#a16207;background:#fef9c3;border:1px solid #f7d674;padding:10px 14px;border-radius:4px;margin-top:14px;">
                <strong><?php _e('No menu assigned yet.', 'azure-plugin'); ?></strong>
                <?php _e('Until a menu is assigned, the shortcode falls back to a sensible default (Dashboard, Orders, Family Info, Account details, Log out).', 'azure-plugin'); ?>
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="max-width:880px;margin-top:18px;">
    <h2 style="margin-top:0;"><?php _e('Recommended menu items', 'azure-plugin'); ?></h2>
    <p class="description">
        <?php _e('Add these as Custom Links in your menu so the shortcode mirrors what MemberPlus is currently rendering, plus the new Family Info page.', 'azure-plugin'); ?>
    </p>
    <table class="widefat" style="max-width:540px;">
        <thead><tr><th><?php _e('Label', 'azure-plugin'); ?></th><th><?php _e('URL', 'azure-plugin'); ?></th></tr></thead>
        <tbody>
            <tr><td>Dashboard</td><td><code>/my-account/</code></td></tr>
            <tr><td>Orders</td><td><code>/my-account/orders/</code></td></tr>
            <tr><td>Family Info</td><td><code>/my-account/profile/</code></td></tr>
            <tr><td>Account Details</td><td><code>/my-account/edit-account/</code></td></tr>
            <tr><td>Log Out</td><td><code><?php echo esc_html(wp_logout_url(home_url())); ?></code></td></tr>
        </tbody>
    </table>
</div>

<script>
jQuery(function($){
    $('#pta-um-shortcode-copy').on('click', function() {
        var $input = $('#pta-um-shortcode');
        $input[0].select();
        try { document.execCommand('copy'); } catch(e) {}
        var $btn = $(this);
        var label = $btn.text();
        $btn.text('<?php echo esc_js(__('Copied!', 'azure-plugin')); ?>');
        setTimeout(function(){ $btn.text(label); }, 1500);
    });

    var nonce = '<?php echo esc_js($assign_nonce); ?>';
    $('#pta-um-menu-save').on('click', function(){
        var $btn    = $(this);
        var $status = $('#pta-um-menu-status');
        var menuId  = parseInt($('#pta-um-menu-select').val(), 10) || 0;

        $btn.prop('disabled', true);
        $status.css('color', '#666').text('<?php echo esc_js(__('Saving…', 'azure-plugin')); ?>');

        $.post(ajaxurl, {
            action:  'pta_um_assign_account_menu',
            nonce:   nonce,
            menu_id: menuId
        }).done(function(resp){
            if (resp && resp.success) {
                $status.css('color', '#1e7e1e').text('<?php echo esc_js(__('Saved. Reloading…', 'azure-plugin')); ?>');
                setTimeout(function(){ window.location.reload(); }, 600);
            } else {
                $status.css('color', '#b32d2e').text('<?php echo esc_js(__('Save failed.', 'azure-plugin')); ?>');
                $btn.prop('disabled', false);
            }
        }).fail(function(){
            $status.css('color', '#b32d2e').text('<?php echo esc_js(__('Network error.', 'azure-plugin')); ?>');
            $btn.prop('disabled', false);
        });
    });
});
</script>
