<?php
/**
 * Inner loop for PTSA page templates.
 *
 * Matches ChromeNews page content (article + entry-content) but skips the
 * extra H1 — /ptsa already has its headings in the page body.
 */

if (!defined('ABSPATH')) {
    exit;
}

while (have_posts()) :
    the_post();
    ?>
    <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
        <div class="entry-content-wrap">
            <div class="entry-content">
                <?php
                the_content();
                wp_link_pages(array(
                    'before' => '<div class="page-links">' . esc_html__('Pages:', 'azure-plugin'),
                    'after'  => '</div>',
                ));
                ?>
            </div>
        </div>
    </article>
    <?php
endwhile;
