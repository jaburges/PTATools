<?php
/**
 * Title: Story grid (current query)
 * Slug: lwptsa-recap/story-loop
 * Categories: lwptsa
 * Inserter: no
 * Description: Cards for the posts of the current archive, search or blog page, with pagination.
 */
?>
<!-- wp:query {"queryId":1,"query":{"inherit":true,"perPage":12},"align":"wide","className":"lw-loop"} -->
<div class="wp-block-query alignwide lw-loop"><!-- wp:post-template {"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"17rem"},"style":{"spacing":{"blockGap":"2rem"}}} -->
<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"3/2","sizeSlug":"medium_large","className":"lw-fallback","style":{"border":{"radius":"14px"}}} /-->

<!-- wp:lwptsa/story-category /-->

<!-- wp:post-title {"level":2,"isLink":true,"className":"lw-card__title","fontSize":"lg"} /-->

<!-- wp:post-excerpt {"excerptLength":22,"className":"lw-card__excerpt"} /-->

<!-- wp:post-date {"format":"M j, Y","className":"lw-meta"} /-->
<!-- /wp:post-template -->

<!-- wp:query-pagination {"paginationArrow":"arrow","className":"lw-pager","layout":{"type":"flex","justifyContent":"center"}} -->
<!-- wp:query-pagination-previous /-->

<!-- wp:query-pagination-numbers /-->

<!-- wp:query-pagination-next /-->
<!-- /wp:query-pagination -->

<!-- wp:query-no-results -->
<!-- wp:paragraph -->
<p>Nothing here yet. Try a search, or see the <a href="/news/">latest news</a>.</p>
<!-- /wp:paragraph -->
<!-- /wp:query-no-results --></div>
<!-- /wp:query -->
