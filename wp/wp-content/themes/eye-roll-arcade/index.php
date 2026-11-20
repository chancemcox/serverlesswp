<?php
if (!defined('ABSPATH')) { exit; }
get_header();
?>
<div class="wrap content-area">
  <?php if (have_posts()) : ?>
    <?php while (have_posts()) : the_post(); ?>
    <article class="post-card">
      <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
      <p class="meta"><?php echo esc_html(get_the_date()); ?> &mdash; <?php the_author(); ?></p>
      <?php the_excerpt(); ?>
      <p><a href="<?php the_permalink(); ?>">Read more &rarr;</a></p>
    </article>
    <?php endwhile; ?>
    <div class="pagination">
      <?php echo paginate_links(); ?>
    </div>
  <?php else : ?>
    <article class="post-card">
      <h2>Nothing here yet</h2>
      <p>No posts found. Check back after the next joke lands.</p>
    </article>
  <?php endif; ?>
</div>
<?php get_footer(); ?>
