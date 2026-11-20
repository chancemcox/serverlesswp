<?php
if (!defined('ABSPATH')) { exit; }
get_header();
?>
<div class="wrap content-area">
  <h1 style="font-family:'Bungee',cursive;color:#fff;text-shadow:2px 2px 0 var(--purple)"><?php the_archive_title(); ?></h1>
  <?php if (have_posts()) : ?>
    <?php while (have_posts()) : the_post(); ?>
    <article class="post-card">
      <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
      <p class="meta"><?php echo esc_html(get_the_date()); ?></p>
      <?php the_excerpt(); ?>
    </article>
    <?php endwhile; ?>
    <div class="pagination"><?php echo paginate_links(); ?></div>
  <?php else : ?>
    <article class="post-card"><h2>Nothing here yet</h2><p>No posts in this archive.</p></article>
  <?php endif; ?>
</div>
<?php get_footer(); ?>
