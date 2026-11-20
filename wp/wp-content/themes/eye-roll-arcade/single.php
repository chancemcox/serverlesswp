<?php
if (!defined('ABSPATH')) { exit; }
get_header();
?>
<div class="wrap content-area">
  <?php while (have_posts()) : the_post(); ?>
  <article class="entry-content">
    <h1><?php the_title(); ?></h1>
    <p class="meta"><?php echo esc_html(get_the_date()); ?> &mdash; <?php the_author(); ?></p>
    <?php if (has_post_thumbnail()) { the_post_thumbnail('large'); } ?>
    <?php the_content(); ?>
  </article>
  <?php endwhile; ?>
</div>
<?php get_footer(); ?>
