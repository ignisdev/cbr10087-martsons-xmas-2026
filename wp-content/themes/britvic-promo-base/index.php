<?php get_header(); ?>
<main class="standard-page">
    <?php if (have_posts()): while (have_posts()): the_post(); ?>
        <article>
            <h1><?php the_title(); ?></h1>
            <div class="entry-content"><?php the_content(); ?></div>
        </article>
    <?php endwhile; endif; ?>
</main>
<?php get_footer(); ?>
