<?php
/**
 * Parent Welcome Mailer (v3.67)
 *
 * Admin-triggered tool that, for every imported parent user that is still
 * login-disabled, generates a fresh temporary password, emails it, and
 * unblocks sign-in. The user is required to set a new password on first
 * login (force-change is enforced by Azure_Parent_Role).
 *
 * Failure mode: if `wp_mail` returns false, the user is reverted to the
 * pre-send state (login disabled, no welcome timestamp) so the operator
 * can retry — same recovery contract as the auction-winner email.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Parent_Welcome_Mailer {

    const NONCE_ACTION  = 'azure_pci_welcome_nonce';
    const META_SENT_AT  = '_pta_welcome_email_sent';
    const BATCH_SIZE    = 25;

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!is_admin()) {
            return;
        }
        add_action('wp_ajax_azure_pci_welcome_preview', array($this, 'ajax_preview'));
        add_action('wp_ajax_azure_pci_welcome_send',    array($this, 'ajax_send'));
    }

    // ─── AJAX entry points ─────────────────────────────────────────────

    public function ajax_preview() {
        $this->require_admin();
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        wp_send_json_success(array(
            'pending' => $this->count_pending(),
            'sent'    => $this->count_sent(),
            'batch'   => self::BATCH_SIZE,
        ));
    }

    public function ajax_send() {
        $this->require_admin();
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
        $only_user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

        if ($only_user_id > 0) {
            // Single-user retry path.
            $result = $this->send_to_user($only_user_id);
            wp_send_json_success(array(
                'sent'      => $result ? 1 : 0,
                'failed'    => $result ? 0 : 1,
                'completed' => true,
            ));
        }

        $batch = $this->fetch_pending_batch(self::BATCH_SIZE);
        if (empty($batch)) {
            wp_send_json_success(array(
                'sent'      => 0,
                'failed'    => 0,
                'completed' => true,
                'remaining' => 0,
            ));
        }

        $sent = 0;
        $failed = 0;
        foreach ($batch as $user_id) {
            if ($this->send_to_user((int) $user_id)) {
                $sent++;
            } else {
                $failed++;
            }
        }

        $remaining = $this->count_pending();
        wp_send_json_success(array(
            'sent'      => $sent,
            'failed'    => $failed,
            'remaining' => $remaining,
            'completed' => $remaining === 0,
        ));
    }

    private function require_admin() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
    }

    // ─── Pool queries ──────────────────────────────────────────────────

    public function count_pending() {
        $q = new WP_User_Query(array(
            'role'         => Azure_Parent_Role::ROLE_SLUG,
            'meta_key'     => Azure_Parent_Role::META_LOGIN_DISABLED,
            'meta_value'   => 1,
            'fields'       => 'ID',
            'count_total'  => true,
            'number'       => 1,
        ));
        return (int) $q->get_total();
    }

    public function count_sent() {
        $q = new WP_User_Query(array(
            'role'         => Azure_Parent_Role::ROLE_SLUG,
            'meta_query'   => array(
                array(
                    'key'     => self::META_SENT_AT,
                    'value'   => '0',
                    'compare' => '!=',
                ),
            ),
            'fields'       => 'ID',
            'count_total'  => true,
            'number'       => 1,
        ));
        return (int) $q->get_total();
    }

    /**
     * Pull the next batch of pending parent user IDs. We always pull from
     * the top of the queue and rely on the disabled-flag clearing to
     * advance the pointer, so we don't need stable ordering across calls.
     */
    private function fetch_pending_batch($limit) {
        $q = new WP_User_Query(array(
            'role'         => Azure_Parent_Role::ROLE_SLUG,
            'meta_key'     => Azure_Parent_Role::META_LOGIN_DISABLED,
            'meta_value'   => 1,
            'fields'       => 'ID',
            'orderby'      => 'ID',
            'order'        => 'ASC',
            'number'       => $limit,
        ));
        return $q->get_results();
    }

    // ─── Send routine ──────────────────────────────────────────────────

    /**
     * Send the welcome email to a single user. Returns true on success,
     * false on any failure (and reverts the meta flags so the user is
     * eligible for a retry).
     */
    public function send_to_user($user_id) {
        $user = get_userdata($user_id);
        if (!$user || empty($user->user_email)) {
            return false;
        }

        // Flip meta state *before* sending so a parallel retry doesn't
        // double-send. We revert on failure below.
        $temp_password = wp_generate_password(14, true, false);
        wp_set_password($temp_password, $user_id);
        delete_user_meta($user_id, Azure_Parent_Role::META_LOGIN_DISABLED);
        update_user_meta($user_id, Azure_Parent_Role::META_FORCE_PW_RESET, 1);
        update_user_meta($user_id, self::META_SENT_AT, current_time('mysql'));

        $sent = $this->dispatch_email($user, $temp_password);

        if (!$sent) {
            // Revert: re-disable login and clear the timestamp so the user
            // shows up again in the pending pool. The temp password stays
            // (only the user can read their hash; we'd just mint another
            // one on the next attempt).
            update_user_meta($user_id, Azure_Parent_Role::META_LOGIN_DISABLED, 1);
            update_user_meta($user_id, self::META_SENT_AT, 0);
            delete_user_meta($user_id, Azure_Parent_Role::META_FORCE_PW_RESET);

            if (class_exists('Azure_Logger')) {
                Azure_Logger::error('Parent welcome email failed', array(
                    'user_id' => $user_id,
                    'email'   => $user->user_email,
                ));
            }
            return false;
        }

        if (class_exists('Azure_Logger')) {
            Azure_Logger::info('Parent welcome email sent', array(
                'user_id' => $user_id,
                'email'   => $user->user_email,
            ));
        }
        return true;
    }

    private function dispatch_email($user, $temp_password) {
        $site_name = get_bloginfo('name');
        $login_url = wp_login_url();
        if (function_exists('wc_get_page_id')) {
            $myaccount_id = wc_get_page_id('myaccount');
            if ($myaccount_id > 0) {
                $login_url = wp_login_url(get_permalink($myaccount_id));
            }
        }
        $first_name = $user->first_name ?: $user->display_name ?: $user->user_email;

        $subject = sprintf(__('Welcome to %s — your PTA account is ready', 'azure-plugin'), $site_name);

        ob_start();
        ?>
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <h2 style="color: #333;"><?php printf(esc_html__('Welcome to %s', 'azure-plugin'), esc_html($site_name)); ?></h2>
            <p><?php printf(esc_html__('Hi %s,', 'azure-plugin'), esc_html($first_name)); ?></p>
            <p><?php printf(
                esc_html__('The %s PTA has set up an account for you so you can review and update your family\'s information for school activities.', 'azure-plugin'),
                esc_html($site_name)
            ); ?></p>
            <table style="margin: 18px 0; border-collapse: collapse;">
                <tr>
                    <td style="padding: 6px 12px 6px 0; color: #555;"><strong><?php esc_html_e('Username', 'azure-plugin'); ?></strong></td>
                    <td style="padding: 6px 0;"><?php echo esc_html($user->user_email); ?></td>
                </tr>
                <tr>
                    <td style="padding: 6px 12px 6px 0; color: #555;"><strong><?php esc_html_e('Temporary password', 'azure-plugin'); ?></strong></td>
                    <td style="padding: 6px 0;"><code style="background: #f5f5f5; padding: 4px 8px; border-radius: 3px;"><?php echo esc_html($temp_password); ?></code></td>
                </tr>
            </table>
            <p style="margin: 25px 0;">
                <a href="<?php echo esc_url($login_url); ?>" style="background: #0073aa; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 3px; display: inline-block;"><?php esc_html_e('Sign in', 'azure-plugin'); ?></a>
            </p>
            <p><?php esc_html_e('You\'ll be asked to set a new password on your first sign-in. From there you can edit your children\'s grade, teacher, allergies, emergency contact, and other details whenever they change.', 'azure-plugin'); ?></p>
            <p style="color: #666; font-size: 13px;"><?php esc_html_e('If you weren\'t expecting this email, please reply to this message and we\'ll sort it out.', 'azure-plugin'); ?></p>
        </div>
        <?php
        $message = ob_get_clean();

        $headers = array('Content-Type: text/html; charset=UTF-8');
        return wp_mail($user->user_email, $subject, $message, $headers);
    }
}
