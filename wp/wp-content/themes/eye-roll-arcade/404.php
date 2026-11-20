<?php
if (!defined('ABSPATH')) { exit; }
get_header();
?>
<div class="wrap content-area" style="text-align:center">
  <h1 style="font-family:'Bungee',cursive;font-size:clamp(2.4rem,8vw,4.5rem);color:var(--yellow);text-shadow:3px 3px 0 var(--pink)">404</h1>
  <p style="color:var(--muted)">This page got rewound and lost. Classic.</p>
  <p><a class="btn btn-pink" href="<?php echo esc_url(home_url('/')); ?>">BACK TO THE JOKES</a></p>
</div>
<?php get_footer(); ?>
