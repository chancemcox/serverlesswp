<?php
/**
 * Plugin Name: ERA Google Analytics
 * Description: Adds the Google Analytics 4 (gtag.js) base code to the front end of apparelishoweyeroll.com and fires GA4 recommended events when WooCommerce is active: view_item_list, select_item, view_item, add_to_cart, remove_from_cart, view_cart, begin_checkout, add_shipping_info, add_payment_info, purchase, view_search_results, login and sign_up. Standalone and dependency-free: no settings page, no libraries, every hook guarded so a failure can never break the site.
 * Version: 1.1.0
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
 * Describe the current listing page (shop / category / tag archive) for
 * view_item_list and select_item, or return null when this is not one.
 * Items are capped at 20, matching what a listing page shows at once.
 */
function era_ga4_listing_context()
{
    $is_listing = (function_exists('is_shop') && is_shop())
        || (function_exists('is_product_category') && is_product_category())
        || (function_exists('is_product_tag') && is_product_tag());
    if (!$is_listing) {
        return null;
    }

    $list_id = 'shop';
    $list_name = 'Shop';
    if (function_exists('is_product_category') && is_product_category()) {
        $term = get_queried_object();
        if ($term && isset($term->slug)) {
            $list_id = 'category_' . $term->slug;
            $list_name = $term->name;
        }
    } elseif (function_exists('is_product_tag') && is_product_tag()) {
        $term = get_queried_object();
        if ($term && isset($term->slug)) {
            $list_id = 'tag_' . $term->slug;
            $list_name = $term->name;
        }
    }

    global $wp_query;
    $items = array();
    $by_link = array();
    if (isset($wp_query) && is_object($wp_query) && !empty($wp_query->posts)) {
        foreach ($wp_query->posts as $post) {
            if (count($items) >= 20) {
                break;
            }
            if (!is_object($post) || !function_exists('wc_get_product')) {
                continue;
            }
            $p = wc_get_product($post->ID);
            if (!$p) {
                continue;
            }
            $item = era_ga4_item($p, 1);
            if ($item === null) {
                continue;
            }
            $item['index'] = count($items);
            $items[] = $item;
            $link = get_permalink($post->ID);
            if ($link) {
                $by_link[$link] = $item;
            }
        }
    }

    return array(
        'item_list_id'   => $list_id,
        'item_list_name' => $list_name,
        'items'          => $items,
        'by_link'        => $by_link,
    );
}

/**
 * Items + total for the current cart, for view_cart / checkout events.
 * Returns null when there is no cart.
 */
function era_ga4_cart_payload()
{
    if (!function_exists('WC') || !WC() || !WC()->cart) {
        return null;
    }
    $cart = WC()->cart;
    $items = array();
    foreach ($cart->get_cart() as $cart_item) {
        if (isset($cart_item['data']) && is_object($cart_item['data'])) {
            $qty = isset($cart_item['quantity']) ? (int) $cart_item['quantity'] : 1;
            $item = era_ga4_item($cart_item['data'], $qty);
            if ($item !== null) { $items[] = $item; }
        }
    }
    return array(
        'value' => (float) $cart->get_total('edit'),
        'items' => $items,
    );
}

/**
 * Read and clear one queued session payload (set by action hooks such
 * as add-to-cart or login so the event can fire on the next page load).
 */
function era_ga4_take_queued($key)
{
    try {
        if (function_exists('WC') && WC() && isset(WC()->session) && WC()->session) {
            $value = WC()->session->get($key);
            if ($value) {
                WC()->session->set($key, null);
                return $value;
            }
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
}

/**
 * Queue a session payload for the next page load. Silent on failure.
 */
function era_ga4_queue($key, $value)
{
    try {
        if (function_exists('WC') && WC() && isset(WC()->session) && WC()->session) {
            WC()->session->set($key, $value);
        }
    } catch (Throwable $e) {
        return;
    }
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
 * WooCommerce / site events, printed in the footer so page state (cart,
 * order, query) is fully resolved. Everything is guarded: no
 * WooCommerce, no output.
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
    $listing = null;
    $is_checkout_page = false;

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

        // view_item_list on shop / category / tag listing pages.
        $listing = era_ga4_listing_context();
        if ($listing !== null && !empty($listing['items'])) {
            $params = array(
                'item_list_id'   => $listing['item_list_id'],
                'item_list_name' => $listing['item_list_name'],
                'items'          => $listing['items'],
            );
            $js = era_ga4_event_js('view_item_list', $params);
            if ($js !== '') { $lines[] = $js; }
        }

        // view_search_results on site search pages.
        if (is_search()) {
            $params = array('search_term' => get_search_query());
            $js = era_ga4_event_js('view_search_results', $params);
            if ($js !== '') { $lines[] = $js; }
        }

        // view_cart on the cart page.
        if (function_exists('is_cart') && is_cart()) {
            $cart_payload = era_ga4_cart_payload();
            if ($cart_payload !== null) {
                $params = array(
                    'currency' => $currency,
                    'value'    => $cart_payload['value'],
                    'items'    => $cart_payload['items'],
                );
                $js = era_ga4_event_js('view_cart', $params);
                if ($js !== '') { $lines[] = $js; }
            }
        }

        // begin_checkout on the checkout page (not the thank-you page).
        if (function_exists('is_checkout') && is_checkout()
            && !(function_exists('is_order_received_page') && is_order_received_page())) {
            $is_checkout_page = true;
            $cart_payload = era_ga4_cart_payload();
            if ($cart_payload !== null) {
                $params = array(
                    'currency' => $currency,
                    'value'    => $cart_payload['value'],
                    'items'    => $cart_payload['items'],
                );
                $js = era_ga4_event_js('begin_checkout', $params);
                if ($js !== '') { $lines[] = $js; }
            }
        }
    } catch (Throwable $e) {
        // Tracking must never break the page; fall through silently.
        $lines = array();
        $listing = null;
    }

    // Events queued by action hooks on the previous request.
    $pending_add = era_ga4_take_queued('era_ga4_add_to_cart');
    if ($pending_add && is_array($pending_add)) {
        $js = era_ga4_event_js('add_to_cart', $pending_add);
        if ($js !== '') { $lines[] = $js; }
    }
    $pending_removes = era_ga4_take_queued('era_ga4_remove_from_cart');
    if ($pending_removes && is_array($pending_removes)) {
        foreach ($pending_removes as $payload) {
            if (is_array($payload)) {
                $js = era_ga4_event_js('remove_from_cart', $payload);
                if ($js !== '') { $lines[] = $js; }
            }
        }
    }
    if (era_ga4_take_queued('era_ga4_login')) {
        $js = era_ga4_event_js('login', array('method' => 'WooCommerce'));
        if ($js !== '') { $lines[] = $js; }
    }
    if (era_ga4_take_queued('era_ga4_sign_up')) {
        $js = era_ga4_event_js('sign_up', array('method' => 'WooCommerce'));
        if ($js !== '') { $lines[] = $js; }
    }

    if (!empty($lines)) {
        echo "\n<script>\n";
        echo "window.addEventListener('load', function () {\n";
        echo "if (typeof gtag !== 'function') { return; }\n";
        foreach ($lines as $js) {
            echo $js . "\n";
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

    // select_item on listing pages: a non-blocking click listener on
    // product links. It only fires the event — it never prevents or
    // delays navigation, so it cannot break the click.
    if ($listing !== null && !empty($listing['by_link'])) {
        $map_json = wp_json_encode($listing['by_link']);
        $list_json = wp_json_encode(array(
            'item_list_id'   => $listing['item_list_id'],
            'item_list_name' => $listing['item_list_name'],
        ));
        if ($map_json !== false && $list_json !== false) {
            ?>
<script>
(function () {
    var itemsByLink = <?php echo $map_json; ?>;
    var listInfo = <?php echo $list_json; ?>;
    document.addEventListener('click', function (e) {
        if (typeof gtag !== 'function') { return; }
        var a = e.target && e.target.closest ? e.target.closest('a') : null;
        if (!a || !a.href) { return; }
        var item = itemsByLink[a.href];
        if (!item) { return; }
        var params = {
            item_list_id: listInfo.item_list_id,
            item_list_name: listInfo.item_list_name,
            items: [item]
        };
        gtag('event', 'select_item', params);
    });
})();
</script>
            <?php
        }
    }

    // add_shipping_info / add_payment_info on the checkout page: fire
    // when the shopper changes the shipping or payment method. The
    // listeners only observe the change; checkout behaviour is untouched.
    if ($is_checkout_page) {
        $cart_payload = era_ga4_cart_payload();
        if ($cart_payload !== null) {
            $checkout_json = wp_json_encode(array(
                'currency' => $currency,
                'value'    => $cart_payload['value'],
                'items'    => $cart_payload['items'],
            ));
            if ($checkout_json !== false) {
                ?>
<script>
(function () {
    if (typeof jQuery === 'undefined') { return; }
    var checkoutData = <?php echo $checkout_json; ?>;
    jQuery(document.body).on('change', 'input[name^="shipping_method"]', function () {
        if (typeof gtag !== 'function') { return; }
        var params = {
            currency: checkoutData.currency,
            value: checkoutData.value,
            items: checkoutData.items,
            shipping_tier: String(jQuery(this).val() || '')
        };
        gtag('event', 'add_shipping_info', params);
    });
    jQuery(document.body).on('change', 'input[name="payment_method"]', function () {
        if (typeof gtag !== 'function') { return; }
        var params = {
            currency: checkoutData.currency,
            value: checkoutData.value,
            items: checkoutData.items,
            payment_type: String(jQuery(this).val() || '')
        };
        gtag('event', 'add_payment_info', params);
    });
})();
</script>
                <?php
            }
        }
    }
}
add_action('wp_footer', 'era_ga4_footer_events', 20);

/**
 * Queue an add_to_cart payload in the WooCommerce session so the footer
 * script can fire it on the next full page load (classic add-to-cart).
 */
function era_ga4_on_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data)
{
    try {
        $product = function_exists('wc_get_product') ? wc_get_product($variation_id ? $variation_id : $product_id) : null;
        if (!$product) {
            return;
        }
        $item = era_ga4_item($product, $quantity);
        if ($item === null) {
            return;
        }
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
        era_ga4_queue('era_ga4_add_to_cart', array(
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
 * Queue a remove_from_cart payload when an item is removed (the cart
 * page reloads after removal, so the footer fires it there). Removals
 * accumulate so several removals each get their own event.
 */
function era_ga4_on_cart_item_removed($cart_item_key, $cart)
{
    try {
        if (!is_object($cart) || !isset($cart->removed_cart_contents[$cart_item_key])) {
            return;
        }
        $removed = $cart->removed_cart_contents[$cart_item_key];
        if (!isset($removed['data']) || !is_object($removed['data'])) {
            return;
        }
        $qty = isset($removed['quantity']) ? (int) $removed['quantity'] : 1;
        $item = era_ga4_item($removed['data'], $qty);
        if ($item === null) {
            return;
        }
        $value = isset($removed['line_total']) ? (float) $removed['line_total'] : (float) $removed['data']->get_price() * max(1, $qty);
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
        $payload = array(
            'currency' => $currency,
            'value'    => $value,
            'items'    => array($item),
        );
        $existing = null;
        if (function_exists('WC') && WC() && isset(WC()->session) && WC()->session) {
            $existing = WC()->session->get('era_ga4_remove_from_cart');
        }
        if (!is_array($existing)) {
            $existing = array();
        }
        $existing[] = $payload;
        era_ga4_queue('era_ga4_remove_from_cart', $existing);
    } catch (Throwable $e) {
        // Never interfere with cart removal.
        return;
    }
}
add_action('woocommerce_cart_item_removed', 'era_ga4_on_cart_item_removed', 20, 2);

/**
 * Queue a login event on a successful front-end login (wp-admin
 * logins and admin/shop-manager accounts are excluded).
 */
function era_ga4_on_login($user_login, $user)
{
    try {
        if (is_admin()) {
            return;
        }
        if (!is_object($user) || user_can($user, 'manage_options')) {
            return;
        }
        era_ga4_queue('era_ga4_login', 1);
    } catch (Throwable $e) {
        return;
    }
}
add_action('wp_login', 'era_ga4_on_login', 20, 2);

/**
 * Queue a sign_up event when a customer registers on the front end
 * (registrations created in wp-admin are excluded).
 */
function era_ga4_on_created_customer($customer_id, $new_customer_data = array(), $password_generated = false)
{
    try {
        if (is_admin()) {
            return;
        }
        era_ga4_queue('era_ga4_sign_up', 1);
    } catch (Throwable $e) {
        return;
    }
}
add_action('woocommerce_created_customer', 'era_ga4_on_created_customer', 20, 3);

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
