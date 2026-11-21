<?php
/**
 * Plugin Name: ERA Meta Pixel
 * Description: Adds the Meta (Facebook) Pixel base code (PageView) to the front end of apparelishoweyeroll.com and fires WooCommerce events (ViewContent, AddToCart, InitiateCheckout, Purchase) when WooCommerce is active. Standalone and dependency-free: no external calls, no libraries, every hook guarded so a failure can never break the site.
 * Version: 1.0.0
 * Author: Apparel is How Eye Roll
 * License: GPL-2.0-or-later
 * Text Domain: era-meta-pixel
 */

if (!defined('ABSPATH')) { exit; }

if (!defined('ERA_META_PIXEL_ID')) {
    define('ERA_META_PIXEL_ID', '776112450676017');
}

/**
 * Build one fbq('track', ...) call with a JSON-encoded payload.
 * Returns an empty string when the payload cannot be encoded.
 */
function era_meta_pixel_track_js($event, $params = array())
{
    $json = !empty($params) ? wp_json_encode($params) : '';
    if (!empty($params) && $json === false) {
        return '';
    }
    if ($json === '' || $json === null) {
        return "fbq('track', '" . esc_js($event) . "');";
    }
    return "fbq('track', '" . esc_js($event) . "', " . $json . ");";
}

/**
 * The content id Meta should see for a product: SKU when set, else the ID.
 */
function era_meta_pixel_content_id($product)
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
 * Base pixel code in wp_head, front end only.
 */
function era_meta_pixel_head()
{
    if (is_admin()) {
        return;
    }
    $id = ERA_META_PIXEL_ID;
    ?>
<!-- Meta Pixel Code (ERA Meta Pixel) -->
<script>
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '<?php echo esc_js($id); ?>');
fbq('track', 'PageView');
</script>
<noscript><img height="1" width="1" style="display:none" alt=""
src="https://www.facebook.com/tr?id=<?php echo esc_attr($id); ?>&ev=PageView&noscript=1"
/></noscript>
<!-- End Meta Pixel Code -->
    <?php
}
add_action('wp_head', 'era_meta_pixel_head', 20);

/**
 * WooCommerce events, printed in the footer so page state (cart, order)
 * is fully resolved. Everything is guarded: no WooCommerce, no output.
 */
function era_meta_pixel_footer_events()
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
        // ViewContent on single product pages.
        if (function_exists('is_product') && is_product()) {
            global $product;
            $p = (isset($product) && is_object($product)) ? $product : null;
            if ($p === null && function_exists('wc_get_product')) {
                $p = wc_get_product(get_the_ID());
            }
            if ($p) {
                $cid = era_meta_pixel_content_id($p);
                if ($cid !== '') {
                    $params = array(
                        'content_ids'  => array($cid),
                        'content_type' => 'product',
                        'content_name' => $p->get_name(),
                        'value'        => (float) $p->get_price(),
                        'currency'     => $currency,
                    );
                    $js = era_meta_pixel_track_js('ViewContent', $params);
                    if ($js !== '') { $lines[] = $js; }
                }
            }
        }

        // InitiateCheckout on the checkout page (not the thank-you page).
        if (function_exists('is_checkout') && is_checkout()
            && !(function_exists('is_order_received_page') && is_order_received_page())
            && function_exists('WC') && WC() && WC()->cart) {
            $cart = WC()->cart;
            $ids = array();
            foreach ($cart->get_cart() as $item) {
                if (isset($item['data']) && is_object($item['data'])) {
                    $cid = era_meta_pixel_content_id($item['data']);
                    if ($cid !== '') { $ids[] = $cid; }
                }
            }
            $params = array(
                'content_ids'  => $ids,
                'content_type' => 'product',
                'value'        => (float) $cart->get_total('edit'),
                'currency'     => $currency,
                'num_items'    => (int) $cart->get_cart_contents_count(),
            );
            $js = era_meta_pixel_track_js('InitiateCheckout', $params);
            if ($js !== '') { $lines[] = $js; }
        }
    } catch (Throwable $e) {
        // Tracking must never break the page; fall through silently.
        $lines = array();
    }

    // AddToCart queued by the woocommerce_add_to_cart hook (non-AJAX flow).
    $pending = null;
    if (function_exists('WC') && WC() && isset(WC()->session) && WC()->session) {
        $pending = WC()->session->get('era_meta_pixel_add_to_cart');
        if ($pending) {
            WC()->session->set('era_meta_pixel_add_to_cart', null);
        }
    }

    if (!empty($lines) || $pending) {
        echo "\n<script>\n";
        echo "window.addEventListener('load', function () {\n";
        echo "if (typeof fbq !== 'function') { return; }\n";
        foreach ($lines as $js) {
            echo $js . "\n";
        }
        if ($pending && is_array($pending)) {
            $js = era_meta_pixel_track_js('AddToCart', $pending);
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
        if (typeof fbq !== 'function') { return; }
        var pid = '';
        if ($button && $button.length) {
            pid = $button.data('product_id') || $button.val() || '';
        }
        var params = { content_type: 'product' };
        if (pid) { params.content_ids = [String(pid)]; }
        fbq('track', 'AddToCart', params);
    });
})();
</script>
    <?php
}
add_action('wp_footer', 'era_meta_pixel_footer_events', 20);

/**
 * Queue an AddToCart payload in the WooCommerce session so the footer
 * script can fire it on the next full page load (classic add-to-cart).
 */
function era_meta_pixel_on_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data)
{
    try {
        if (!function_exists('WC') || !WC() || !isset(WC()->session) || !WC()->session) {
            return;
        }
        $product = function_exists('wc_get_product') ? wc_get_product($variation_id ? $variation_id : $product_id) : null;
        if (!$product) {
            return;
        }
        $cid = era_meta_pixel_content_id($product);
        if ($cid === '') {
            return;
        }
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
        WC()->session->set('era_meta_pixel_add_to_cart', array(
            'content_ids'  => array($cid),
            'content_type' => 'product',
            'content_name' => $product->get_name(),
            'value'        => (float) $product->get_price() * max(1, (int) $quantity),
            'currency'     => $currency,
        ));
    } catch (Throwable $e) {
        // Never interfere with adding to cart.
        return;
    }
}
add_action('woocommerce_add_to_cart', 'era_meta_pixel_on_add_to_cart', 20, 6);

/**
 * Purchase on the order-received / thank-you page. Fires once per order:
 * the _era_meta_pixel_purchase_fired order meta guards repeat views.
 */
function era_meta_pixel_on_thankyou($order_id)
{
    try {
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        if ($order->get_meta('_era_meta_pixel_purchase_fired')) {
            return;
        }
        $ids = array();
        foreach ($order->get_items() as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product')) {
                continue;
            }
            $p = $item->get_product();
            if ($p) {
                $cid = era_meta_pixel_content_id($p);
                if ($cid !== '') { $ids[] = $cid; }
            }
        }
        $params = array(
            'content_ids'  => $ids,
            'content_type' => 'product',
            'value'        => (float) $order->get_total(),
            'currency'     => $order->get_currency() ? $order->get_currency() : 'USD',
            'num_items'    => (int) $order->get_item_count(),
        );
        $js = era_meta_pixel_track_js('Purchase', $params);
        if ($js === '') {
            return;
        }
        $order->update_meta_data('_era_meta_pixel_purchase_fired', '1');
        $order->save();
        ?>
<script>
window.addEventListener('load', function () {
    if (typeof fbq !== 'function') { return; }
    <?php echo $js; ?>
});
</script>
        <?php
    } catch (Throwable $e) {
        // Never interfere with the thank-you page.
        return;
    }
}
add_action('woocommerce_thankyou', 'era_meta_pixel_on_thankyou', 20, 1);
