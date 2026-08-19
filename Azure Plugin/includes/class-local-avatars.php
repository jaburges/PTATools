<?php
/**
 * Admin-managed local profile photos.
 *
 * PTA Roles (and anything else using get_avatar / get_avatar_url) reads
 * these instead of Gravatar when a photo has been set. Admins can upload
 * a picture for any user from Users → Edit or PTA Roles → Assignments.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Local_Avatars {

    const META_KEY = 'azure_local_avatar_id';

    public static function init() {
        add_filter('get_avatar_data', array(__CLASS__, 'filter_avatar_data'), 10, 2);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin'));
        add_action('show_user_profile', array(__CLASS__, 'render_profile_field'));
        add_action('edit_user_profile', array(__CLASS__, 'render_profile_field'));
        add_action('personal_options_update', array(__CLASS__, 'save_profile_field'));
        add_action('edit_user_profile_update', array(__CLASS__, 'save_profile_field'));
        add_action('wp_ajax_pta_set_user_photo', array(__CLASS__, 'ajax_set_user_photo'));
        add_action('wp_ajax_pta_remove_user_photo', array(__CLASS__, 'ajax_remove_user_photo'));
    }

    public static function attachment_id($user_id) {
        return (int) get_user_meta((int) $user_id, self::META_KEY, true);
    }

    public static function url($user_id, $size = 96) {
        $att_id = self::attachment_id($user_id);
        if ($att_id < 1) {
            return '';
        }
        $size = max(32, min(512, (int) $size));
        $url = wp_get_attachment_image_url($att_id, array($size, $size));
        if (!$url) {
            $url = wp_get_attachment_image_url($att_id, 'thumbnail');
        }
        if (!$url) {
            $url = wp_get_attachment_url($att_id);
        }
        return is_string($url) ? $url : '';
    }

    public static function filter_avatar_data($args, $id_or_email) {
        $user_id = self::resolve_user_id($id_or_email);
        if ($user_id < 1) {
            return $args;
        }
        $size = isset($args['size']) ? (int) $args['size'] : 96;
        $url = self::url($user_id, $size);
        if ($url === '') {
            return $args;
        }
        $args['url'] = $url;
        $args['found_avatar'] = true;
        return $args;
    }

    public static function enqueue_admin($hook) {
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        $need = (
            $page === 'azure-plugin-pta'
            || $hook === 'user-edit.php'
            || $hook === 'profile.php'
        );
        if ($need) {
            wp_enqueue_media();
        }
    }

    public static function render_profile_field($user) {
        if (!($user instanceof WP_User) || !current_user_can('edit_user', $user->ID)) {
            return;
        }
        $url = self::url($user->ID, 150);
        $att_id = self::attachment_id($user->ID);
        ?>
        <h2><?php esc_html_e('Board / profile photo', 'azure-plugin'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="azure_local_avatar_id"><?php esc_html_e('Photo', 'azure-plugin'); ?></label></th>
                <td>
                    <div id="azure-local-avatar-preview" style="margin-bottom:8px;">
                        <?php if ($url): ?>
                            <img src="<?php echo esc_url($url); ?>" alt="" width="96" height="96" style="border-radius:50%;object-fit:cover;">
                        <?php else: ?>
                            <p class="description"><?php esc_html_e('No local photo yet. Gravatar is not required — upload one here.', 'azure-plugin'); ?></p>
                        <?php endif; ?>
                    </div>
                    <input type="hidden" name="azure_local_avatar_id" id="azure_local_avatar_id" value="<?php echo esc_attr($att_id); ?>" />
                    <button type="button" class="button" id="azure-local-avatar-choose"><?php esc_html_e('Choose photo', 'azure-plugin'); ?></button>
                    <button type="button" class="button" id="azure-local-avatar-clear" <?php disabled($att_id < 1); ?>><?php esc_html_e('Remove', 'azure-plugin'); ?></button>
                    <p class="description"><?php esc_html_e('Used on the PTSA board and anywhere WordPress shows this user’s avatar.', 'azure-plugin'); ?></p>
                    <script>
                    jQuery(function($) {
                        var $id = $('#azure_local_avatar_id');
                        var $preview = $('#azure-local-avatar-preview');
                        $('#azure-local-avatar-choose').on('click', function(e) {
                            e.preventDefault();
                            if (typeof wp === 'undefined' || !wp.media) { return; }
                            var frame = wp.media({
                                title: <?php echo wp_json_encode(__('Choose a photo', 'azure-plugin')); ?>,
                                button: { text: <?php echo wp_json_encode(__('Use this photo', 'azure-plugin')); ?> },
                                library: { type: 'image' },
                                multiple: false
                            });
                            frame.on('select', function() {
                                var att = frame.state().get('selection').first().toJSON();
                                $id.val(att.id);
                                var src = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
                                $preview.html('<img src="' + src + '" alt="" width="96" height="96" style="border-radius:50%;object-fit:cover;">');
                                $('#azure-local-avatar-clear').prop('disabled', false);
                            });
                            frame.open();
                        });
                        $('#azure-local-avatar-clear').on('click', function(e) {
                            e.preventDefault();
                            $id.val('0');
                            $preview.html('<p class="description"><?php echo esc_js(__('Photo will be removed when you click Update Profile.', 'azure-plugin')); ?></p>');
                            $(this).prop('disabled', true);
                        });
                    });
                    </script>
                </td>
            </tr>
        </table>
        <?php
    }

    public static function save_profile_field($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }
        if (!isset($_POST['azure_local_avatar_id'])) {
            return;
        }
        $att_id = (int) $_POST['azure_local_avatar_id'];
        self::set_attachment($user_id, $att_id);
    }

    public static function ajax_set_user_photo() {
        if (!current_user_can('edit_users') && !current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $att_id  = isset($_POST['attachment_id']) ? (int) $_POST['attachment_id'] : 0;
        if ($user_id < 1 || !get_userdata($user_id)) {
            wp_send_json_error('Unknown user');
        }
        if ($att_id < 1 || wp_attachment_is_image($att_id) !== true) {
            wp_send_json_error('Choose an image from the media library');
        }
        self::set_attachment($user_id, $att_id);
        wp_send_json_success(array(
            'user_id' => $user_id,
            'url'     => self::url($user_id, 150),
        ));
    }

    public static function ajax_remove_user_photo() {
        if (!current_user_can('edit_users') && !current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'azure_plugin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        if ($user_id < 1 || !get_userdata($user_id)) {
            wp_send_json_error('Unknown user');
        }
        self::set_attachment($user_id, 0);
        wp_send_json_success(array('user_id' => $user_id, 'url' => ''));
    }

    public static function set_attachment($user_id, $att_id) {
        $user_id = (int) $user_id;
        $att_id  = (int) $att_id;
        if ($att_id > 0) {
            update_user_meta($user_id, self::META_KEY, $att_id);
        } else {
            delete_user_meta($user_id, self::META_KEY);
        }
        clean_user_cache($user_id);
    }

    private static function resolve_user_id($id_or_email) {
        if (is_numeric($id_or_email)) {
            return (int) $id_or_email;
        }
        if ($id_or_email instanceof WP_User) {
            return (int) $id_or_email->ID;
        }
        if ($id_or_email instanceof WP_Post) {
            return (int) $id_or_email->post_author;
        }
        if (is_object($id_or_email) && isset($id_or_email->user_id)) {
            return (int) $id_or_email->user_id;
        }
        if (is_string($id_or_email) && is_email($id_or_email)) {
            $user = get_user_by('email', $id_or_email);
            return $user ? (int) $user->ID : 0;
        }
        return 0;
    }
}
