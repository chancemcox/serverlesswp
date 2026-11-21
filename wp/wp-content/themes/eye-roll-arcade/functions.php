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

/* No sidebar on WooCommerce pages. This theme ships no sidebar.php and
 * registers no widget areas, so WooCommerce's default sidebar action
 * (woocommerce_get_sidebar -> get_sidebar('shop')) fell through to
 * WordPress's theme-compat fallback, rendering the default Search /
 * Pages / Archives widgets full-width under the products on shop,
 * product-category and single-product pages. Removing the action keeps
 * those pages full-width, as the theme's layout already assumes. */
remove_action('woocommerce_sidebar', 'woocommerce_get_sidebar', 10);

function era_assets() {
    wp_enqueue_style('era-style', get_stylesheet_uri(), array(), '1.0.2');
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
    echo '<li><a href="' . esc_url(home_url('/blog/')) . '">Blog</a></li>';
    echo '<li><a href="' . esc_url(home_url('/cart/')) . '">Cart</a></li>';
    echo '<li><a href="' . esc_url(home_url('/my-account/')) . '">My Account</a></li>';
    echo '</ul>';
}

function era_menu_extra_items($items, $args) {
    if (isset($args->theme_location) && $args->theme_location === 'primary') {
        if (strpos($items, '/blog/') === false) {
            $items .= '<li><a href="' . esc_url(home_url('/blog/')) . '">Blog</a></li>';
        }
        $items .= '<li><a href="' . esc_url(home_url('/cart/')) . '">Cart</a></li>';
        $items .= '<li><a href="' . esc_url(home_url('/my-account/')) . '">My Account</a></li>';
    }
    return $items;
}
add_filter('wp_nav_menu_items', 'era_menu_extra_items', 10, 2);

/* -------------------------------------------------------------------------
 * SEO: keyword-rich homepage title, per-context meta descriptions,
 * Open Graph / Twitter cards, and Organization + WebSite JSON-LD.
 * ---------------------------------------------------------------------- */

function era_homepage_title($title) {
    if (is_front_page()) {
        return 'Funny Dad Joke Shirts, Programmer Humor & 90s Kid Gifts | Apparel is How Eye Roll';
    }
    return $title;
}
add_filter('pre_get_document_title', 'era_homepage_title');

function era_trim_to_160($text) {
    $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($text)));
    if (strlen($text) <= 160) { return $text; }
    $cut = substr($text, 0, 157);
    $pos = strrpos($cut, ' ');
    if ($pos) { $cut = substr($cut, 0, $pos); }
    return $cut . '...';
}

function era_meta_description() {
    $default = 'Funny dad-joke shirts, desk mats, mugs and gifts from Apparel is How Eye Roll. Shop programmer humor, 90s kid gear and Christmas jokes — printed on demand and shipped worldwide.';
    if (is_product_category()) {
        $term = get_queried_object();
        if ($term && !empty($term->description)) {
            return era_trim_to_160($term->description);
        }
    }
    if (function_exists('is_shop') && is_shop()) {
        return 'Shop funny shirts, desk mats, mugs and gifts from Apparel is How Eye Roll — dad jokes, programmer humor, 90s nostalgia and Christmas groaners, printed on demand.';
    }
    if (is_singular('product') && function_exists('wc_get_product')) {
        $product = wc_get_product(get_the_ID());
        if ($product) {
            $raw = $product->get_short_description();
            if (!$raw) { $raw = $product->get_description(); }
            if ($raw) { return era_trim_to_160($raw); }
        }
    }
    if (is_singular('post')) {
        $excerpt = get_the_excerpt();
        if ($excerpt) { return era_trim_to_160($excerpt); }
    }
    if (is_page()) {
        if (is_page('about')) {
            return 'About Apparel is How Eye Roll: funny dad-joke shirts, programmer humor tees, 90s nostalgia gifts, desk mats and mugs, printed on demand and shipped worldwide.';
        }
        $excerpt = get_the_excerpt();
        if ($excerpt) { return era_trim_to_160($excerpt); }
        $content = get_post_field('post_content', get_the_ID());
        if ($content) { return era_trim_to_160($content); }
    }
    return $default;
}

function era_social_tags() {
    $desc  = era_meta_description();
    $title = wp_get_document_title();
    $type  = 'website';
    $image = ERA_LOGO_URL;
    $url   = home_url('/');
    if (is_product_category()) {
        $term = get_queried_object();
        if ($term) {
            $url   = get_term_link($term);
            $title = $term->name . ' | Apparel is How Eye Roll';
        }
    } elseif (function_exists('is_shop') && is_shop()) {
        $url   = get_permalink(wc_get_page_id('shop'));
        $title = 'Shop Funny Shirts, Desk Mats, Mugs & Gifts | Apparel is How Eye Roll';
    } elseif (is_singular('product')) {
        $type  = 'product';
        $url   = get_permalink();
        $title = get_the_title() . ' | Apparel is How Eye Roll';
        if (function_exists('wc_get_product')) {
            $product = wc_get_product(get_the_ID());
            if ($product) {
                $img_id = $product->get_image_id();
                if (!$img_id) {
                    $gallery = $product->get_gallery_image_ids();
                    if (!empty($gallery)) { $img_id = $gallery[0]; }
                }
                if ($img_id) {
                    $src = wp_get_attachment_url($img_id);
                    if ($src) { $image = $src; }
                }
            }
        }
    } elseif (is_singular('post')) {
        $type  = 'article';
        $url   = get_permalink();
        $title = get_the_title() . ' | Apparel is How Eye Roll';
        $thumb = get_post_thumbnail_id();
        if ($thumb) {
            $src = wp_get_attachment_url($thumb);
            if ($src) { $image = $src; }
        } else {
            $content = get_post_field('post_content', get_the_ID());
            if (preg_match('/<img[^>]+src="([^"]+)"/', $content, $m)) { $image = $m[1]; }
        }
    } elseif (is_singular()) {
        $url   = get_permalink();
        $title = get_the_title() . ' | Apparel is How Eye Roll';
    }
    echo '<meta property="og:type" content="' . esc_attr($type) . '">' . "\n";
    echo '<meta property="og:site_name" content="Apparel is How Eye Roll">' . "\n";
    echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
    echo '<meta property="og:description" content="' . esc_attr($desc) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
    echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
    echo '<meta name="twitter:title" content="' . esc_attr($title) . '">' . "\n";
    echo '<meta name="twitter:description" content="' . esc_attr($desc) . '">' . "\n";
    echo '<meta name="twitter:image" content="' . esc_url($image) . '">' . "\n";
}
add_action('wp_head', 'era_social_tags', 5);

/* Self-referential canonical tags. Core's rel_canonical only covers
 * singular views, so the theme takes over: front page -> home URL,
 * singular posts/products/pages -> the permalink, product category
 * archives -> the term link, shop -> the shop permalink, blog index ->
 * the posts page permalink. Exactly one canonical per page. */
function era_canonical_url() {
    if (is_front_page()) {
        return home_url('/');
    }
    if (is_product_category()) {
        $term = get_queried_object();
        if ($term) {
            $link = get_term_link($term);
            if (!is_wp_error($link)) { return $link; }
        }
    }
    if (function_exists('is_shop') && is_shop()) {
        return get_permalink(wc_get_page_id('shop'));
    }
    if (is_home()) {
        $posts_page = (int) get_option('page_for_posts');
        if ($posts_page) { return get_permalink($posts_page); }
        return home_url('/');
    }
    if (is_singular()) {
        return get_permalink();
    }
    return null;
}

function era_canonical_tag() {
    $url = era_canonical_url();
    if ($url) {
        echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
    }
}
remove_action('wp_head', 'rel_canonical');
add_action('wp_head', 'era_canonical_tag', 2);

function era_json_ld() {
    if (!is_front_page()) { return; }
    $data = array(
        '@context' => 'https://schema.org',
        '@graph'   => array(
            array(
                '@type'  => 'Organization',
                'name'   => 'Apparel is How Eye Roll',
                'url'    => 'https://www.apparelishoweyeroll.com/',
                'logo'   => ERA_LOGO_URL,
                'sameAs' => array(
                    'https://www.etsy.com/shop/ApparelisHowEyeRollU',
                    'https://www.instagram.com/apparelishoweyer',
                    'https://www.tiktok.com/@chancemcox',
                    'https://www.facebook.com/apparelishoweyeroll',
                ),
            ),
            array(
                '@type' => 'WebSite',
                'name'  => 'Apparel is How Eye Roll',
                'url'   => 'https://www.apparelishoweyeroll.com/',
            ),
        ),
    );
    echo '<script type="application/ld+json">' . wp_json_encode($data) . '</script>' . "\n";
}
add_action('wp_head', 'era_json_ld', 6);
