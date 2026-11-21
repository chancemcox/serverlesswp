<?php
if (!defined('ABSPATH')) { exit; }
get_header();

$era_products = array(
    array(
        'name' => 'Python Cheat Sheet Desk Mat',
        'tag'  => 'Programmer Humor',
        'img'  => 'https://i.etsystatic.com/67195577/r/il/d5be7e/8940025549/il_570xN.8940025549_n4lz.jpg',
        'url'  => 'https://www.etsy.com/listing/4621425251',
    ),
    array(
        'name' => 'Adulting Is Soup And I Am A Fork Shirt',
        'tag'  => 'Dad Jokes',
        'img'  => 'https://i.etsystatic.com/67195577/r/il/05e0f9/8888775376/il_570xN.8888775376_ngdt.jpg',
        'url'  => 'https://www.etsy.com/listing/4620992487',
    ),
    array(
        'name' => 'Cozy Christmas Movie Heart Pillow',
        'tag'  => 'Christmas',
        'img'  => 'https://i.etsystatic.com/67195577/r/il/ef64b9/8934304263/il_570xN.8934304263_6h9m.jpg',
        'url'  => 'https://www.etsy.com/listing/4620728849',
    ),
    array(
        'name' => 'AI Coding Cheat Sheet Desk Mat',
        'tag'  => 'Programmer Humor',
        'img'  => 'https://i.etsystatic.com/67195577/r/il/3d56d1/8940027015/il_570xN.8940027015_lttn.jpg',
        'url'  => 'https://www.etsy.com/listing/4621425521',
    ),
    array(
        'name' => 'Big Bang Happened Canvas Wall Art',
        'tag'  => 'Faith &amp; Funny',
        'img'  => 'https://i.etsystatic.com/67195577/r/il/9ad571/8895858272/il_570xN.8895858272_n5k4.jpg',
        'url'  => 'https://www.etsy.com/listing/4621843371',
    ),
    array(
        'name' => 'Cozy Christmas Movie Heart Mug',
        'tag'  => 'Christmas',
        'img'  => 'https://i.etsystatic.com/67195577/r/il/715c4d/8934281161/il_570xN.8934281161_1e6l.jpg',
        'url'  => 'https://www.etsy.com/listing/4620726537',
    ),
);
?>
<section class="hero">
  <div class="wrap hero-grid">
    <div>
      <h1>DAD JOKES ARE<br><span class="line2">HOW EYE ROLL</span></h1>
      <p class="lede">Funny shirts, desk mats &amp; gifts your family will pretend not to laugh at. Wear the joke. Own the eye roll.</p>
      <div class="btns">
        <a class="btn btn-pink" href="<?php echo esc_url(ERA_ETSY_SHOP); ?>">SHOP THE JOKES</a>
        <a class="btn btn-ghost" href="<?php echo esc_url(ERA_ETSY_SHOP); ?>">CHILD OF THE 90S</a>
      </div>
      <div class="chips">
        <a class="chip" href="<?php echo esc_url(ERA_ETSY_SHOP); ?>">Dad Jokes</a>
        <a class="chip" href="<?php echo esc_url(ERA_ETSY_SHOP); ?>">Programmer Humor</a>
        <a class="chip" href="<?php echo esc_url(ERA_ETSY_SHOP); ?>">90s Kid</a>
        <a class="chip" href="<?php echo esc_url(ERA_ETSY_SHOP); ?>">Christmas</a>
      </div>
    </div>
    <div class="hero-badge">
      <img src="<?php echo esc_url(ERA_LOGO_URL); ?>" alt="Apparel is How Eye Roll badge logo" width="600" height="600" fetchpriority="high">
    </div>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <h2>FRESH OFF THE PRESS</h2>
    <p class="sub">Printed on demand and shipped to your door. Every design starts as a joke somebody groaned at.</p>
    <div class="grid">
      <?php foreach ($era_products as $p) : ?>
      <article class="card">
        <a href="<?php echo esc_url($p['url']); ?>"><img src="<?php echo esc_url($p['img']); ?>" alt="<?php echo esc_attr(wp_strip_all_tags($p['name'])); ?>" width="570" height="570" loading="lazy" decoding="async"></a>
        <div class="card-in">
          <span class="tag"><?php echo $p['tag']; ?></span>
          <h3><?php echo esc_html($p['name']); ?></h3>
          <a class="btn btn-pink" href="<?php echo esc_url($p['url']); ?>">SHOP ON ETSY</a>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <div class="about-strip">
      <h2>BORN IN THE 90S. STILL NOT OVER IT.</h2>
      <p>Apparel is How Eye Roll makes gear for people who rewound tapes with a pencil, debugged code before it was cool, and believe a dad joke is a love language. Every item is printed on demand &mdash; no warehouses, no waste, just jokes delivered.</p>
      <div class="btns">
        <a class="btn btn-ghost" href="<?php echo esc_url(ERA_ETSY_SHOP); ?>">VISIT THE ETSY SHOP</a>
      </div>
    </div>
  </div>
</section>

<?php
$era_recent = new WP_Query(array('post_type' => 'post', 'posts_per_page' => 3, 'post_status' => 'publish'));
if ($era_recent->have_posts()) : ?>
<section class="section">
  <div class="wrap">
    <h2>LATEST FROM THE BLOG</h2>
    <p class="sub">New drops, dumb jokes, and shop news.</p>
    <?php while ($era_recent->have_posts()) : $era_recent->the_post(); ?>
    <article class="post-card">
      <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
      <p class="meta"><?php echo esc_html(get_the_date()); ?></p>
      <?php the_excerpt(); ?>
    </article>
    <?php endwhile; wp_reset_postdata(); ?>
  </div>
</section>
<?php endif; ?>

<?php get_footer(); ?>
