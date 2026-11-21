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
    wp_enqueue_style('era-style', get_stylesheet_uri(), array(), '1.0.1');
}
add_action('wp_enqueue_scripts', 'era_assets');

function era_preload_fonts() {
    echo '<link rel="preload" href="https://fonts.gstatic.com/s/bungee/v17/N0bU2SZBIuF2PU_0DXR1C9zfmQ.woff2" as="font" type="font/woff2" crossorigin>' . "\n";
    echo '<link rel="preload" href="https://fonts.gstatic.com/s/spacegrotesk/v22/V8mDoQDjQSkFtoMM3T6r8E7mPbF4C_k3HqU.woff2" as="font" type="font/woff2" crossorigin>' . "\n";
}
add_action('wp_head', 'era_preload_fonts', 1);

function era_fallback_menu() {
    echo '<ul>';
    echo '<li><a href="' . esc_url(home_url('/shop/')) . '">Shop</a></li>';
    echo '<li><a href="' . esc_url(home_url('/product-category/dad-jokes/')) . '">Dad Jokes</a></li>';
    echo '<li><a href="' . esc_url(home_url('/product-category/programmer-humor/')) . '">Programmer</a></li>';
    echo '<li><a href="' . esc_url(ERA_ETSY_SHOP) . '">Shop on Etsy</a></li>';
    echo '<li><a href="' . esc_url(home_url('/about/')) . '">About</a></li>';
    echo '</ul>';
}
