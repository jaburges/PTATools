<?php
/**
 * Title: For PTA leaders
 * Slug: lwptsa-recap/home-leaders
 * Categories: lwptsa
 * Description: Four coloured tiles linking to leader resources.
 */
$tiles = array(
    array('sky', '/training/', 'Training &amp; roundtables', 'Dates, recordings and slides'),
    array('sun', '/money-matters/', 'Money matters', 'Budgets, audits and taxes'),
    array('sage', '/helpful-documents/', 'Documents &amp; forms', 'Bylaws, templates and deadlines'),
    array('rose', '/awards/', 'Awards', 'Council awards and You Rock!'),
);
?>
<!-- wp:group {"className":"lw-section-head","layout":{"type":"flex","justifyContent":"space-between","verticalAlignment":"bottom"}} -->
<div class="wp-block-group lw-section-head"><!-- wp:heading -->
<h2 class="wp-block-heading">For PTA leaders</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"lw-more"} -->
<p class="lw-more"><a href="<?php echo esc_url(home_url('/helpful-documents/')); ?>">Leader resources →</a></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:group {"className":"lw-toolkit","layout":{"type":"grid","columnCount":2,"minimumColumnWidth":null},"style":{"spacing":{"blockGap":"0.75rem"}}} -->
<div class="wp-block-group lw-toolkit"><?php foreach ($tiles as $t) : ?><!-- wp:paragraph {"backgroundColor":"<?php echo $t[0]; ?>"} -->
<p class="has-<?php echo $t[0]; ?>-background-color has-background"><a href="<?php echo esc_url(home_url($t[1])); ?>"><strong><?php echo $t[2]; ?></strong><?php echo $t[3]; ?></a></p>
<!-- /wp:paragraph -->

<?php endforeach; ?></div>
<!-- /wp:group -->
