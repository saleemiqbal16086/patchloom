<?php
defined('ABSPATH') || exit;
get_header();
while (have_posts()) : the_post(); ?>
<main class="pl-import-main">
    <div class="container">
        <header class="pl-import-title"><h1><?php the_title(); ?></h1></header>
        <?php the_content(); ?>
    </div>
</main>
<?php endwhile;
get_footer();
