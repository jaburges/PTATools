<?php
/**
 * Template Name: PTSA Sidebar
 *
 * Classic (non-Elementor) page with the theme sidebar. Same wider content
 * column as PTSA Full Width, with grey gutters on either side.
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>
<div class="section-block-upper ptsa-sidebar-row">
    <div id="primary" class="content-area">
        <main id="main" class="site-main" role="main">
            <?php require AZURE_PLUGIN_PATH . 'templates/ptsa-page-content.php'; ?>
        </main>
    </div>
    <?php get_sidebar(); ?>
</div>
<?php
get_footer();
