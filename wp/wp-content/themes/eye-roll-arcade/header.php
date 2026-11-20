<?php if (!defined('ABSPATH')) { exit; } ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="sale-bar">15% OFF EVERYTHING &mdash; CODE <strong>NOVEMBERSALE</strong> ENDS NOV 30</div>
<header class="site-header">
  <div class="wrap header-in">
    <a class="brand" href="<?php echo esc_url(home_url('/')); ?>">
      <img src="<?php echo esc_url(ERA_LOGO_URL); ?>" alt="Apparel is How Eye Roll logo" width="810" height="810">
      <span class="brand-name">APPAREL IS<br>HOW EYE ROLL</span>
    </a>
    <nav class="main-nav" aria-label="Primary">
      <?php wp_nav_menu(array('theme_location' => 'primary', 'container' => false, 'fallback_cb' => 'era_fallback_menu')); ?>
    </nav>
  </div>
</header>
<main>
