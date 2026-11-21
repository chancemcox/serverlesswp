<?php
/**
 * Plugin Name: ERA Google Shopping Feed
 * Description: Generates a Google Merchant Center product feed from WooCommerce products so Google Shopping stays in sync with the store on a scheduled fetch. Exposes the feed as RSS 2.0 XML (g: namespace) at /?era_google_feed=xml and at the pretty path /google-shopping-feed.xml. Read-only: this plugin never changes products, orders, or settings.
 * Version: 1.0.1
 * Author: Apparel is How Eye Roll
 * License: GPL-2.0-or-later
 * Text Domain: era-google-feed
 */

if (!defined('ABSPATH')) { exit; }

define('ERA_GOOGLE_FEED_BRAND', 'Apparel is How Eye Roll');
define('ERA_GOOGLE_FEED_QUERY_VAR', 'era_google_feed');

/* -------------------------------------------------------------------------
 * Feed routing.
 *
 * Two stable URLs serve the same XML:
 *   - https://<site>/?era_google_feed=xml   (works with no rewrite flush)
 *   - https://<site>/google-shopping-feed.xml (pretty path, after activation)
 * The query-var URL is the canonical one to paste into Google Merchant
 * Center, because it works regardless of permalink/rewrite state.
 * ---------------------------------------------------------------------- */
function era_google_feed_query_vars($vars)
{
    $vars[] = ERA_GOOGLE_FEED_QUERY_VAR;
    return $vars;
}
add_filter('query_vars', 'era_google_feed_query_vars');

function era_google_feed_rewrite_rules()
{
    add_rewrite_rule('^google-shopping-feed\.xml$', 'index.php?era_google_feed=xml', 'top');
}
add_action('init', 'era_google_feed_rewrite_rules');

function era_google_feed_activate()
{
    era_google_feed_rewrite_rules();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'era_google_feed_activate');

function era_google_feed_maybe_serve()
{
    if (get_query_var(ERA_GOOGLE_FEED_QUERY_VAR) !== 'xml') {
        return;
    }
    era_google_feed_serve();
    exit;
}
add_action('template_redirect', 'era_google_feed_maybe_serve');

/* -------------------------------------------------------------------------
 * Feed generation.
 *
 * One <item> per purchasable item: simple products get one item; variable
 * products get one item per enabled variation, grouped with
 * g:item_group_id. Field set follows Google Merchant Center's product
 * feed spec: g:id, g:title, g:description, g:link, g:image_link,
 * g:availability, g:price, g:sale_price (only when on sale), g:condition,
 * g:brand, g:identifier_exists (these are print-on-demand goods with no
 * GTIN/MPN), g:product_type (when categories exist), g:item_group_id
 * (variations only), and variant attributes (g:color, g:size, g:gender,
 * g:age_group, g:material, g:pattern) when the variation carries them.
 * ---------------------------------------------------------------------- */
function era_google_feed_money($amount)
{
    if ($amount === '' || $amount === null) {
        return '';
    }
    return number_format((float) $amount, 2, '.', '') . ' ' . get_woocommerce_currency();
}

function era_google_feed_clean_text($html, $limit = 5000)
{
    $text = wp_strip_all_tags((string) $html);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', trim($text));
    // Strip characters that are invalid in XML 1.0.
    $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $text);
    if (strlen($text) > $limit) {
        $text = substr($text, 0, $limit);
    }
    return $text;
}

function era_google_feed_esc($value)
{
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Map a variation's attributes onto Google attribute elements.
 * WooCommerce attribute keys arrive as taxonomy names (pa_color) or plain
 * slugs (size); values for taxonomy attributes are term slugs, which we
 * resolve back to term names for display.
 */
function era_google_feed_variant_attributes($variation)
{
    $map = array(
        'color'     => 'g:color',
        'colour'    => 'g:color',
        'size'      => 'g:size',
        'gender'    => 'g:gender',
        'age_group' => 'g:age_group',
        'age'       => 'g:age_group',
        'material'  => 'g:material',
        'pattern'   => 'g:pattern',
    );
    $out = array();
    foreach ((array) $variation->get_attributes() as $taxonomy => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $key = strtolower((string) $taxonomy);
        if (strpos($key, 'pa_') === 0) {
            $key = substr($key, 3);
        }
        $key = str_replace(array('-', ' '), '_', $key);
        if (!isset($map[$key])) {
            continue;
        }
        $label = $map[$key];
        if (isset($out[$label])) {
            continue;
        }
        $display = (string) $value;
        if (taxonomy_exists($taxonomy)) {
            $term = get_term_by('slug', $value, $taxonomy);
            if ($term && !is_wp_error($term)) {
                $display = $term->name;
            }
        }
        $display = era_google_feed_clean_text(str_replace('-', ' ', $display), 100);
        if ($display !== '') {
            $out[$label] = $display;
        }
    }
    return $out;
}

function era_google_feed_product_type($product)
{
    $terms = get_the_terms($product->get_id(), 'product_cat');
    if (!$terms || is_wp_error($terms)) {
        return '';
    }
    $names = array();
    foreach ($terms as $term) {
        if (strtolower($term->name) === 'uncategorized') {
            continue;
        }
        $names[] = era_google_feed_clean_text($term->name, 100);
    }
    return implode(' > ', array_filter($names));
}

function era_google_feed_item($id, $title, $description, $product, $group_id = '', $product_type = '', $variant_attrs = array())
{
    $sale = $product->get_sale_price();
    $regular = $product->get_regular_price();
    $price = $product->get_price();
    $on_sale = $product->is_on_sale() && $sale !== '' && $sale !== null
        && $regular !== '' && $regular !== null && (float) $sale < (float) $regular;

    $image_id = $product->get_image_id();
    $image = $image_id ? wp_get_attachment_image_url($image_id, 'full') : '';

    $item = array(
        'g:id' => (string) $id,
        'g:title' => $title,
        'g:description' => $description,
        'g:link' => get_permalink($product->get_id()) ?: $product->get_permalink(),
        'g:image_link' => $image ? $image : '',
        'g:availability' => $product->is_in_stock() ? 'in_stock' : 'out_of_stock',
        'g:price' => era_google_feed_money($on_sale ? $regular : $price),
    );
    if ($on_sale) {
        $item['g:sale_price'] = era_google_feed_money($sale);
    }
    $item['g:condition'] = 'new';
    $item['g:brand'] = ERA_GOOGLE_FEED_BRAND;
    $item['g:identifier_exists'] = 'no';
    if ($product_type !== '') {
        $item['g:product_type'] = $product_type;
    }
    if ($group_id !== '') {
        $item['g:item_group_id'] = (string) $group_id;
    }
    foreach ($variant_attrs as $label => $value) {
        $item[$label] = $value;
    }
    return $item;
}

function era_google_feed_items()
{
    $items = array();
    if (!function_exists('wc_get_products')) {
        return $items;
    }
    $products = wc_get_products(array(
        'status' => 'publish',
        'limit' => -1,
        'orderby' => 'ID',
        'order' => 'ASC',
        'return' => 'objects',
    ));
    foreach ($products as $product) {
        try {
            $title = era_google_feed_clean_text($product->get_name(), 150);
            // Descriptions are capped at 750 chars: the full WooCommerce
            // text across ~3,100 items pushes the XML past the serverless
            // function's ~6 MB response payload limit (verified on dev —
            // the uncapped feed failed with FUNCTION_RESPONSE_PAYLOAD_TOO_LARGE).
            $description = era_google_feed_clean_text(
                $product->get_description() !== '' ? $product->get_description() : $product->get_short_description(),
                750
            );
            if ($description === '') {
                $description = $title;
            }
            $product_type = era_google_feed_product_type($product);
            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $child_id) {
                    $variation = wc_get_product($child_id);
                    if (!$variation || $variation->get_status() !== 'publish') {
                        continue;
                    }
                    $vtitle = $title;
                    $attrs = $variation->get_attributes();
                    if (!empty($attrs)) {
                        $vtitle .= ' - ' . era_google_feed_clean_text(implode(' / ', array_values(array_filter($attrs))), 100);
                    }
                    if (strlen($vtitle) > 150) {
                        $vtitle = substr($vtitle, 0, 150);
                    }
                    $items[] = era_google_feed_item(
                        $child_id,
                        $vtitle,
                        $description,
                        $variation,
                        $product->get_id(),
                        $product_type,
                        era_google_feed_variant_attributes($variation)
                    );
                }
            } elseif ($product->is_type('simple') || $product->is_type('external')) {
                $items[] = era_google_feed_item($product->get_id(), $title, $description, $product, '', $product_type);
            }
        } catch (Throwable $e) {
            continue; // One bad product must never break the whole feed.
        }
    }
    return $items;
}

function era_google_feed_serve()
{
    if (!class_exists('WooCommerce')) {
        status_header(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "WooCommerce is not active.\n";
        return;
    }
    $items = era_google_feed_items();

    status_header(200);
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: inline; filename="google-shopping-feed.xml"');
    header('Cache-Control: no-cache, must-revalidate');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
    echo '<channel>' . "\n";
    echo '<title>' . era_google_feed_esc(get_bloginfo('name') . ' - Google Shopping Feed') . '</title>' . "\n";
    echo '<link>' . era_google_feed_esc(home_url('/')) . '</link>' . "\n";
    echo '<description>' . era_google_feed_esc('Product feed for Google Merchant Center, generated from WooCommerce.') . '</description>' . "\n";
    foreach ($items as $item) {
        // Compact per-item markup (no newlines between tags) keeps the
        // whole feed comfortably under the response payload limit.
        echo '<item>';
        foreach ($item as $tag => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            echo '<' . $tag . '>' . era_google_feed_esc($value) . '</' . $tag . '>';
        }
        echo '</item>' . "\n";
    }
    echo '</channel>' . "\n";
    echo '</rss>' . "\n";
}
