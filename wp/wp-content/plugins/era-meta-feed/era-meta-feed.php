<?php
/**
 * Plugin Name: ERA Meta Catalog Feed
 * Description: Generates a Meta (Facebook/Instagram) catalog product feed from WooCommerce products so Meta Commerce Manager can keep the Facebook Shop in sync with the store on a scheduled fetch. Exposes the feed as CSV at /?era_meta_feed=csv and at the pretty path /meta-catalog-feed.csv. Read-only: this plugin never changes products, orders, or settings.
 * Version: 1.0.0
 * Author: Apparel is How Eye Roll
 * License: GPL-2.0-or-later
 * Text Domain: era-meta-feed
 */

if (!defined('ABSPATH')) { exit; }

define('ERA_META_FEED_BRAND', 'Apparel is How Eye Roll');
define('ERA_META_FEED_QUERY_VAR', 'era_meta_feed');

/* -------------------------------------------------------------------------
 * Feed routing.
 *
 * Two stable URLs serve the same CSV:
 *   - https://<site>/?era_meta_feed=csv   (works with no rewrite flush)
 *   - https://<site>/meta-catalog-feed.csv (pretty path, after activation)
 * The query-var URL is the canonical one to paste into Meta Commerce
 * Manager, because it works regardless of permalink/rewrite state.
 * ---------------------------------------------------------------------- */
function era_meta_feed_query_vars($vars)
{
    $vars[] = ERA_META_FEED_QUERY_VAR;
    return $vars;
}
add_filter('query_vars', 'era_meta_feed_query_vars');

function era_meta_feed_rewrite_rules()
{
    add_rewrite_rule('^meta-catalog-feed\.csv$', 'index.php?era_meta_feed=csv', 'top');
}
add_action('init', 'era_meta_feed_rewrite_rules');

function era_meta_feed_activate()
{
    era_meta_feed_rewrite_rules();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'era_meta_feed_activate');

function era_meta_feed_maybe_serve()
{
    if (get_query_var(ERA_META_FEED_QUERY_VAR) !== 'csv') {
        return;
    }
    era_meta_feed_serve();
    exit;
}
add_action('template_redirect', 'era_meta_feed_maybe_serve');

/* -------------------------------------------------------------------------
 * Feed generation.
 *
 * One CSV row per purchasable item: simple products get one row; variable
 * products get one row per enabled variation, grouped with item_group_id.
 * Column set follows Meta's catalog feed spec: id, title, description,
 * availability, condition, price, sale_price (only when on sale), link,
 * image_link, brand, item_group_id (variations only).
 * ---------------------------------------------------------------------- */
function era_meta_feed_money($amount)
{
    if ($amount === '' || $amount === null) {
        return '';
    }
    return number_format((float) $amount, 2, '.', '') . ' ' . get_woocommerce_currency();
}

function era_meta_feed_clean_text($html, $limit = 5000)
{
    $text = wp_strip_all_tags((string) $html);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', trim($text));
    if (strlen($text) > $limit) {
        $text = substr($text, 0, $limit);
    }
    return $text;
}

function era_meta_feed_row($id, $title, $description, $product, $group_id = '')
{
    $sale = $product->get_sale_price();
    $regular = $product->get_regular_price();
    $price = $product->get_price();
    $on_sale = $product->is_on_sale() && $sale !== '' && $sale !== null
        && $regular !== '' && $regular !== null && (float) $sale < (float) $regular;

    $image_id = $product->get_image_id();
    $image = $image_id ? wp_get_attachment_image_url($image_id, 'full') : '';

    $row = array(
        'id' => (string) $id,
        'title' => $title,
        'description' => $description,
        'availability' => $product->is_in_stock() ? 'in stock' : 'out of stock',
        'condition' => 'new',
        'price' => era_meta_feed_money($on_sale ? $regular : $price),
        'link' => get_permalink($product->get_id()) ?: $product->get_permalink(),
        'image_link' => $image ? $image : '',
        'brand' => ERA_META_FEED_BRAND,
    );
    if ($on_sale) {
        $row['sale_price'] = era_meta_feed_money($sale);
    }
    if ($group_id !== '') {
        $row['item_group_id'] = (string) $group_id;
    }
    return $row;
}

function era_meta_feed_rows()
{
    $rows = array();
    if (!function_exists('wc_get_products')) {
        return $rows;
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
            $title = era_meta_feed_clean_text($product->get_name(), 200);
            $description = era_meta_feed_clean_text(
                $product->get_description() !== '' ? $product->get_description() : $product->get_short_description()
            );
            if ($description === '') {
                $description = $title;
            }
            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $child_id) {
                    $variation = wc_get_product($child_id);
                    if (!$variation || $variation->get_status() !== 'publish') {
                        continue;
                    }
                    $vtitle = $title;
                    $attrs = $variation->get_attributes();
                    if (!empty($attrs)) {
                        $vtitle .= ' - ' . era_meta_feed_clean_text(implode(' / ', array_values(array_filter($attrs))), 100);
                    }
                    $rows[] = era_meta_feed_row($child_id, $vtitle, $description, $variation, $product->get_id());
                }
            } elseif ($product->is_type('simple') || $product->is_type('external')) {
                $rows[] = era_meta_feed_row($product->get_id(), $title, $description, $product);
            }
        } catch (Throwable $e) {
            continue; // One bad product must never break the whole feed.
        }
    }
    return $rows;
}

function era_meta_feed_serve()
{
    if (!class_exists('WooCommerce')) {
        status_header(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "WooCommerce is not active.\n";
        return;
    }
    $rows = era_meta_feed_rows();
    $columns = array('id', 'title', 'description', 'availability', 'condition', 'price', 'sale_price', 'link', 'image_link', 'brand', 'item_group_id');

    status_header(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: inline; filename="meta-catalog-feed.csv"');
    header('Cache-Control: no-cache, must-revalidate');

    $out = fopen('php://output', 'w');
    fputcsv($out, $columns);
    foreach ($rows as $row) {
        $line = array();
        foreach ($columns as $col) {
            $line[] = isset($row[$col]) ? $row[$col] : '';
        }
        fputcsv($out, $line);
    }
    fclose($out);
}
