<?php
/**
 * Template Name: PTSA Full Width
 *
 * Classic (non-Elementor) full-width page. Header and footer come from
 * ChromeNews; the content column is widened by ptsa-page-templates.css
 * with grey gutters on either side.
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>
<div id="primary" class="content-area">
    <main id="main" class="site-main" role="main">
        <?php require AZURE_PLUGIN_PATH . 'templates/ptsa-page-content.php'; ?>
    </main>
</div>
<?php
get_footer();
