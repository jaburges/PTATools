<?php
/**
 * Sidebar for classic PHP templates supplied by plugins (get_sidebar()).
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<aside class="lw-classic-sidebar">
<?php echo do_blocks('<!-- wp:lwptsa/events-strip {"count":4,"layout":"list","heading":"Upcoming events","className":"lw-panel"} /-->'); ?>
</aside>
