<?php
/**
 * Header for classic PHP templates supplied by plugins (PTA Tools events,
 * calendar and page templates call get_header()). Renders the block header.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Render first so the header's block styles are enqueued before wp_head().
ob_start();
block_template_part('header');
$lwptsa_header = ob_get_clean();
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class('lw-classic-template'); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
<?php echo $lwptsa_header; ?>
<div class="lw-classic">
