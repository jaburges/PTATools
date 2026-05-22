<?php
/**
 * SSO Shortcode handler for Azure Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_SSO_Shortcode {
    
    public function __construct() {
        add_shortcode('azure_sso_login', array($this, 'sso_login_shortcode'));
        add_shortcode('azure_sso_logout', array($this, 'sso_logout_shortcode'));
        add_shortcode('azure_user_info', array($this, 'user_info_shortcode'));
    }
    
    /**
     * SSO Login shortcode
     * Usage: [azure_sso_login text="Sign in with My PTA" redirect="/dashboard"]
     */
    public function sso_login_shortcode($atts) {
        $atts = shortcode_atts(array(
            'text' => 'Sign in with Microsoft',
            'redirect' => '',
            'class' => 'azure-sso-login-button',
            'style' => ''
        ), $atts);
        
        // If user is already logged in, show different content
        if (is_user_logged_in()) {
            return '<p>You are already logged in.</p>';
        }
        
        if (!class_exists('Azure_SSO_Auth')) {
            return '<p>SSO is not enabled.</p>';
        }
        
        $sso_auth = new Azure_SSO_Auth();
        $redirect_url = $atts['redirect'] ? home_url($atts['redirect']) : get_permalink();
        $login_url = $sso_auth->get_login_url($redirect_url);
        
        if (!$login_url) {
            return '<p>SSO is not properly configured.</p>';
        }
        
        $style_attr = $atts['style'] ? ' style="' . esc_attr($atts['style']) . '"' : '';
        
        return sprintf(
            '<a href="%s" class="%s"%s>%s</a>',
            esc_url($login_url),
            esc_attr($atts['class']),
            $style_attr,
            esc_html($atts['text'])
        );
    }
    
    /**
     * SSO Logout shortcode
     * Usage: [azure_sso_logout text="Sign out" redirect="/"]
     */
    public function sso_logout_shortcode($atts) {
        $atts = shortcode_atts(array(
            'text' => 'Sign out',
            'redirect' => '',
            'class' => 'azure-sso-logout-button',
            'style' => ''
        ), $atts);
        
        // If user is not logged in, return empty
        if (!is_user_logged_in()) {
            return '';
        }
        
        $redirect_url = $atts['redirect'] ? home_url($atts['redirect']) : home_url();
        $logout_url = wp_logout_url($redirect_url);
        
        $style_attr = $atts['style'] ? ' style="' . esc_attr($atts['style']) . '"' : '';
        
        return sprintf(
            '<a href="%s" class="%s"%s>%s</a>',
            esc_url($logout_url),
            esc_attr($atts['class']),
            $style_attr,
            esc_html($atts['text'])
        );
    }
    
    /**
     * User Info shortcode
     * Usage: [azure_user_info field="display_name"] or [azure_user_info] for all info
     */
    public function user_info_shortcode($atts) {
        $atts = shortcode_atts(array(
            'field' => '',
            'logged_out_text' => 'Please log in to view this information.',
            'format' => 'html'
        ), $atts);
        
        if (!is_user_logged_in()) {
            return '<p>' . esc_html($atts['logged_out_text']) . '</p>';
        }
        
        $current_user = wp_get_current_user();
        
        // Get Azure user info from database
        global $wpdb;
        $sso_users_table = Azure_Database::get_table_name('sso_users');
        
        if (!$sso_users_table) {
            return '<p>User information not available.</p>';
        }
        
        $azure_user = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$sso_users_table} WHERE wordpress_user_id = %d",
            $current_user->ID
        ));
        
        if (!$azure_user) {
            return '<p>Azure user information not found.</p>';
        }
        
        if ($atts['field']) {
            // Return specific field
            switch ($atts['field']) {
                case 'display_name':
                    return esc_html($azure_user->azure_display_name);
                case 'email':
                    return esc_html($azure_user->azure_email);
                case 'azure_id':
                    return esc_html($azure_user->azure_user_id);
                case 'last_login':
                    return esc_html($azure_user->last_login);
                case 'wp_username':
                    return esc_html($current_user->user_login);
                case 'wp_display_name':
                    return esc_html($current_user->display_name);
                default:
                    return '<p>Unknown field: ' . esc_html($atts['field']) . '</p>';
            }
        }
        
        // Return all user info
        if ($atts['format'] === 'json') {
            $user_data = array(
                'wp_username' => $current_user->user_login,
                'wp_display_name' => $current_user->display_name,
                'wp_email' => $current_user->user_email,
                'azure_id' => $azure_user->azure_user_id,
                'azure_email' => $azure_user->azure_email,
                'azure_display_name' => $azure_user->azure_display_name,
                'last_login' => $azure_user->last_login
            );
            
            return '<pre>' . esc_html(json_encode($user_data, JSON_PRETTY_PRINT)) . '</pre>';
        }
        
        // HTML format (default)
        $output = '<div class="azure-user-info">';
        $output .= '<h4>User Information</h4>';
        $output .= '<p><strong>Display Name:</strong> ' . esc_html($azure_user->azure_display_name) . '</p>';
        $output .= '<p><strong>Email:</strong> ' . esc_html($azure_user->azure_email) . '</p>';
        $output .= '<p><strong>WordPress Username:</strong> ' . esc_html($current_user->user_login) . '</p>';
        $output .= '<p><strong>Last Login:</strong> ' . esc_html($azure_user->last_login) . '</p>';
        $output .= '</div>';
        
        return $output;
    }
}