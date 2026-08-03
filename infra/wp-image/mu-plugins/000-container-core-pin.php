<?php
/**
 * Plugin Name: Container core pin
 * Description: Suppresses WordPress core update offers, because core ships in the container image and an update applied from wp-admin cannot survive a restart.
 * Author: wilderptsa infrastructure
 *
 * WordPress core lives in the container's own filesystem layer, not on the
 * uploads share. An update applied from wp-admin therefore writes files that
 * disappear on the next restart or revision — which already happened twice in
 * August 2026, when 7.0.2 was applied and silently reverted to the image's
 * version.
 *
 * The bigger risk is the half that *does* persist: the database. If the update
 * completes its schema upgrade before the container recycles, the database is
 * left recording a newer WordPress than the files, and wp-admin then redirects
 * to upgrade.php on every load because it compares the two for inequality.
 *
 * So core updates are hidden rather than left available-but-futile. Changing the
 * WordPress version is a base image change in infra/wp-image/Dockerfile.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Report "no core updates available" without contacting the update API.
 */
function wilderptsa_pin_core_version( $value ) {
    global $wp_version;

    return (object) array(
        'updates'         => array(),
        'version_checked' => $wp_version,
        'last_checked'    => time(),
    );
}
add_filter( 'pre_site_transient_update_core', 'wilderptsa_pin_core_version' );

/**
 * Explain why, so the absence of an update button does not look like a bug.
 */
function wilderptsa_core_pin_notice() {
    if ( ! current_user_can( 'update_core' ) ) {
        return;
    }

    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || ! in_array( $screen->id, array( 'update-core', 'dashboard', 'plugins' ), true ) ) {
        return;
    }

    global $wp_version;
    printf(
        '<div class="notice notice-info"><p><strong>%s</strong> %s</p></div>',
        esc_html__( 'WordPress core is pinned to the container image.', 'azure-plugin' ),
        esc_html(
            sprintf(
                /* translators: %s: WordPress version number. */
                __( 'This site runs WordPress %s from a purpose-built image. Updating from here cannot persist, because core is replaced from the image every time the container restarts. To change version, rebuild the image against a newer base (infra/wp-image/Dockerfile) and deploy a new revision.', 'azure-plugin' ),
                $wp_version
            )
        )
    );
}
add_action( 'admin_notices', 'wilderptsa_core_pin_notice' );
