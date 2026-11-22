<?php
/**
 * Plugin Name: ERA Google Analytics
 * Description: Adds the Google Analytics 4 (gtag.js) base code to the front end of apparelishoweyeroll.com and fires WooCommerce ecommerce events (view_item, add_to_cart, begin_checkout, purchase) when WooCommerce is active. Standalone and dependency-free: no settings page, no libraries, every hook guarded so a failure can never break the site.
 * Version: 1.0.0
 * Author: Apparel is How Eye Roll
 * License: GPL-2.0-or-later
 * Text Domain: era-ga4
 */

if (!defined('ABSPATH')) { exit; }

if (!defined('ERA_GA4_MEASUREMENT_ID')) {
    define('ERA_GA4_MEASUREMENT_ID', 'G-NKQMGNF08K');
}

/**
 * Build one gtag('event', ...) call with a JSON-encoded payload.
 * Returns an empty string when the payload cannot be encoded.
 */
function era_ga4_event_js($event, $params = array())
{
    $json = !empty($params) ? wp_json_encode($params) : '';
    if (!empty($params) && $json === false) {
        return '';
    }
    if ($json === '' || $json === null) {
        return "gtag('event', '" . esc_js($event) . "');";
    }
    return "gtag('event', '" . esc_js($event) . "', " . $json . ");";
}

/**
 * The item id GA4 should see for a product: SKU when set, else the ID.
 */
function era_ga4_item_id($product)
{
    if (!is_object($product) || !method_exists($product, 'get_id')) {
        return '';
    }
    $sku = method_exists($product, 'get_sku') ? (string) $product->get_sku() : '';
    if ($sku !== '') {
        return $sku;
    }
    return (string) $product->get_id();
}

/**
 * Build one GA4 item payload for a product. Returns null when the
 * product has no usable id.
 */
function era_ga4_item($product, $quantity = 1)
{
    $id = era_ga4_item_id($product);
    if ($id === '') {
        return null;
    }
    return array(
        'item_id'   => $id,
        'item_name' => $product->get_name(),
        'price'     => (float) $product->get_price(),
        'quantity'  => max(1, (int) $quantity),
    );
}

/**
 * Base gtag.js code in wp_head, front end only.
 */
function era_ga4_head()
{
    if (is_admin()) {
        return;
    }
    $id = ERA_GA4_MEASUREMENT_ID;
    ?>
<!-- Google tag (gtag.js) (ERA Google Analytics) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr($id); ?>"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', '<?php echo esc_js($id); ?>');
</script>
<!-- End Google tag (ERA Google Analytics) -->
    <?php
}
add_action('wp_head', 'era_ga4_head', 20);

/**
 * WooCommerce events, printed in the footer so page state (cart, order)
 * is fully resolved. Everything is guarded: no WooCommerce, no output.
 */
function era_ga4_footer_events()
{
    if (is_admin()) {
        return;
    }
    if (!class_exists('WooCommerce')) {
        return;
    }

    $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
    $lines = array();

    try {
        // view_item on single product pages.
        if (function_exists('is_product') && is_product()) {
            global $product;
            $p = (isset($product) && is_object($product)) ? $product : null;
            if ($p === null && function_exists('wc_get_product')) {
                $p = wc_get_product(get_the_ID());
            }
            if ($p) {
                $item = era_ga4_item($p, 1);
                if ($item !== null) {
                    $params = array(
                        'currency' => $currency,
                        'value'    => (float) $p->get_price(),
                        'items'    => array($item),
                    );
                    $js = era_ga4_event_js('view_item', $params);
                    if ($js !== '') { $lines[] = $js; }
                }
            }
        }

        // begin_checkout on the checkout page (not the thank-you page).
        if (function_exists('is_checkout') && is_checkout()
            && !(function_exists('is_order_received_page') && is_order_received_page())
            && function_exists('WC') && WC() && WC()->cart) {
            $cart = WC()->cart;
            $items = array();
            foreach ($cart->get_cart() as $cart_item) {
                if (isset($cart_item['data']) && is_object($cart_item['data'])) {
                    $qty = isset($cart_item['quantity']) ? (int) $cart_item['quantity'] : 1;
                    $item = era_ga4_item($cart_item['data'], $qty);
                    if ($item !== null) { $items[] = $item; }
                }
            }
            $params = array(
                'currency' => $currency,
                'value'    => (float) $cart->get_total('edit'),
                'items'    => $items,
            );
            $js = era_ga4_event_js('begin_checkout', $params);
            if ($js !== '') { $lines[] = $js; }
        }
    } catch (Throwable $e) {
        // Tracking must never break the page; fall through silently.
        $lines = array();
    }

    // add_to_cart queued by the woocommerce_add_to_cart hook (non-AJAX flow).
    $pending = null;
    if (function_exists('WC') && WC() && isset(WC()->session) && WC()->session) {
        $pending = WC()->session->get('era_ga4_add_to_cart');
        if ($pending) {
            WC()->session->set('era_ga4_add_to_cart', null);
        }
    }

    if (!empty($lines) || $pending) {
        echo "\n<script>\n";
        echo "window.addEventListener('load', function () {\n";
        echo "if (typeof gtag !== 'function') { return; }\n";
        foreach ($lines as $js) {
            echo $js . "\n";
        }
        if ($pending && is_array($pending)) {
            $js = era_ga4_event_js('add_to_cart', $pending);
            if ($js !== '') { echo $js . "\n"; }
        }
        echo "});\n</script>\n";
    }

    // AJAX add-to-cart: WooCommerce fires 'added_to_cart' on the body with
    // the clicked button, which carries the product id. Listen and forward.
    ?>
<script>
(function () {
    if (typeof jQuery === 'undefined') { return; }
    jQuery(document.body).on('added_to_cart', function (event, fragments, cartHash, $button) {
        if (typeof gtag !== 'function') { return; }
        var pid = '';
        if ($button && $button.length) {
            pid = $button.data('product_id') || $button.val() || '';
        }
        if (!pid) { return; }
        gtag('event', 'add_to_cart', {
            items: [{ item_id: String(pid), quantity: 1 }]
        });
    });
})();
</script>
    <?php
}
add_action('wp_footer', 'era_ga4_footer_events', 20);

/**
 * Queue an add_to_cart payload in the WooCommerce session so the footer
 * script can fire it on the next full page load (classic add-to-cart).
 */
function era_ga4_on_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data)
{
    try {
        if (!function_exists('WC') || !WC() || !isset(WC()->session) || !WC()->session) {
            return;
        }
        $product = function_exists('wc_get_product') ? wc_get_product($variation_id ? $variation_id : $product_id) : null;
        if (!$product) {
            return;
        }
        $item = era_ga4_item($product, $quantity);
        if ($item === null) {
            return;
        }
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
        WC()->session->set('era_ga4_add_to_cart', array(
            'currency' => $currency,
            'value'    => (float) $product->get_price() * max(1, (int) $quantity),
            'items'    => array($item),
        ));
    } catch (Throwable $e) {
        // Never interfere with adding to cart.
        return;
    }
}
add_action('woocommerce_add_to_cart', 'era_ga4_on_add_to_cart', 20, 6);

/**
 * Purchase on the order-received / thank-you page. Fires once per order:
 * the _era_ga4_purchase_fired order meta guards repeat views.
 */
function era_ga4_on_thankyou($order_id)
{
    try {
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        if ($order->get_meta('_era_ga4_purchase_fired')) {
            return;
        }
        $items = array();
        foreach ($order->get_items() as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product')) {
                continue;
            }
            $p = $item->get_product();
            if ($p) {
                $qty = method_exists($item, 'get_quantity') ? (int) $item->get_quantity() : 1;
                $ga_item = era_ga4_item($p, $qty);
                if ($ga_item !== null) { $items[] = $ga_item; }
            }
        }
        $params = array(
            'transaction_id' => (string) $order->get_id(),
            'value'          => (float) $order->get_total(),
            'currency'       => $order->get_currency() ? $order->get_currency() : 'USD',
            'items'          => $items,
        );
        $js = era_ga4_event_js('purchase', $params);
        if ($js === '') {
            return;
        }
        $order->update_meta_data('_era_ga4_purchase_fired', '1');
        $order->save();
        ?>
<script>
window.addEventListener('load', function () {
    if (typeof gtag !== 'function') { return; }
    <?php echo $js; ?>
});
</script>
        <?php
    } catch (Throwable $e) {
        // Never interfere with the thank-you page.
        return;
    }
}
add_action('woocommerce_thankyou', 'era_ga4_on_thankyou', 20, 1);
