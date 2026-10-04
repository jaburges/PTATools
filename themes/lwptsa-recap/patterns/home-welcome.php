<?php
/**
 * Title: Welcome panel
 * Slug: lwptsa-recap/home-welcome
 * Categories: lwptsa
 * Description: Navy welcome with the PTA finder, the next event and quick links.
 */
$find = esc_url(home_url('/lwsd-ptas/'));
?>
<!-- wp:group {"className":"lw-welcome","backgroundColor":"navy","textColor":"base","layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"},"style":{"border":{"radius":"24px"},"spacing":{"padding":{"top":"2.6rem","bottom":"2.6rem","left":"2.6rem","right":"2.6rem"},"blockGap":"1.1rem"}}} -->
<div class="wp-block-group lw-welcome has-base-color has-navy-background-color has-text-color has-background" style="border-radius:24px;padding-top:2.6rem;padding-right:2.6rem;padding-bottom:2.6rem;padding-left:2.6rem"><!-- wp:heading {"level":1,"textColor":"base","fontSize":"xxl"} -->
<h1 class="wp-block-heading has-base-color has-text-color has-xxl-font-size">Stronger together for every LWSD student.</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"lw-welcome__lede","textColor":"navy-soft"} -->
<p class="lw-welcome__lede has-navy-soft-color has-text-color">Lake Washington PTSA Council supports the 44 local PTAs and PTSAs across the district with training, advocacy and programs.</p>
<!-- /wp:paragraph -->

<!-- wp:html -->
<form class="lw-find" action="<?php echo $find; ?>" method="get" role="search">
	<label class="screen-reader-text" for="lw-find-input">Find your school's PTA</label>
	<input id="lw-find-input" type="search" name="pta" placeholder="Find your school's PTA…" autocomplete="off" required>
	<button type="submit">Search</button>
</form>
<!-- /wp:html -->

<!-- wp:lwptsa/next-event /-->

<!-- wp:group {"className":"lw-quick","layout":{"type":"grid","columnCount":4,"minimumColumnWidth":null},"style":{"spacing":{"blockGap":"0.6rem"}}} -->
<div class="wp-block-group lw-quick"><!-- wp:paragraph -->
<p><a href="<?php echo esc_url(home_url('/lwsd-ptas/')); ?>"><strong>Find your PTA</strong>44 local units</a></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><a href="<?php echo esc_url(home_url('/training/')); ?>"><strong>Leader training</strong>Trainings &amp; roundtables</a></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><a href="<?php echo esc_url(home_url('/advocacy/legislative-priorities/')); ?>"><strong>Advocacy</strong>Legislative priorities</a></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><a href="<?php echo esc_url(home_url('/reflections/')); ?>"><strong>Reflections</strong>Arts program</a></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
