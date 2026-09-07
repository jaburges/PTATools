<?php
/**
 * Template Name: PTSA Full Width
 *
 * Classic (non-Elementor) full-width page. Header and footer come from
 * the active theme; the content column is widened by ptsa-page-templates.css.
 *
 * Catch Box already opens #main > #primary > #content in header.php (and
 * closes #main in footer.php). Nesting another #primary/#main inside that
 * overflowed the right edge past the nav. Close those wrappers the same
 * way page.php does. ChromeNews does not open them, so it still gets a wrap.
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

$catchbox = (get_template() === 'catch-box');

if (!$catchbox) :
    ?>
<div id="primary" class="content-area">
    <main id="main" class="site-main" role="main">
    <?php
endif;

require AZURE_PLUGIN_PATH . 'templates/ptsa-page-content.php';

if ($catchbox) :
    ?>
                </div><!-- #content -->
                <?php do_action('catchbox_after_content'); ?>
            </div><!-- #primary -->
            <?php
            do_action('catchbox_after_primary');
            get_sidebar();
else :
    ?>
    </main>
</div>
    <?php
endif;

get_footer();
