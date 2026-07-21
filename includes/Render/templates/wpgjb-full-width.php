<?php

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="wpgjb-full-width-content" style="width:100%;max-width:none;margin:0;padding:0;">
<?php
while ( have_posts() ) :
	the_post();
	the_content();
endwhile;
?>
</main>
<?php
get_footer();
