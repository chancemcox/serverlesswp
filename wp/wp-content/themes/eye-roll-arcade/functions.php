<?php
if (!defined('ABSPATH')) { exit; }

define('ERA_LOGO_URL', 'https://pub-87eb4317aae54feca2d4ef09d8bcf8a8.r2.dev/site-assets/logo-badge.webp');
define('ERA_ETSY_SHOP', 'https://www.etsy.com/shop/ApparelisHowEyeRollU');

function era_setup() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('automatic-feed-links');
    add_theme_support('html5', array('search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script'));
    add_theme_support('woocommerce');
    register_nav_menus(array('primary' => __('Primary Menu', 'eye-roll-arcade')));
}
add_action('after_setup_theme', 'era_setup');

function era_assets() {
    wp_enqueue_style('era-fonts', 'https://fonts.googleapis.com/css2?family=Bungee&family=Space+Grotesk:wght@400;500;700&display=swap', array(), null);
    wp_enqueue_style('era-style', get_stylesheet_uri(), array('era-fonts'), '1.0.0');
}
add_action('wp_enqueue_scripts', 'era_assets');

function era_fallback_menu() {
    echo '<ul>';
    echo '<li><a href="' . esc_url(ERA_ETSY_SHOP) . '">Shop</a></li>';
    echo '<li><a href="' . esc_url(ERA_ETSY_SHOP) . '">Dad Jokes</a></li>';
    echo '<li><a href="' . esc_url(ERA_ETSY_SHOP) . '">Programmer</a></li>';
    echo '<li><a href="' . esc_url(home_url('/about/')) . '">About</a></li>';
    echo '</ul>';
}
