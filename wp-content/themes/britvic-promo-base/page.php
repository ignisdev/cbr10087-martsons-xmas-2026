<?php
/**
 * Default page template (non-promo pages: T&Cs, privacy, etc.)
 *
 * @package britvic-promo-base
 */
get_header();
?>
<main class="standard-page">
    <?php if (have_posts()): while (have_posts()): the_post(); ?>
        <article class="standard-page__content">
            <h1 class="standard-page__title"><?php the_title(); ?></h1>
            <div class="standard-page__body"><?php the_content(); ?></div>
        </article>
    <?php endwhile; endif; ?>
</main>
<?php get_footer(); ?>
