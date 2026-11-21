<?php
/**
 * Plugin Name: ERA Sale Sync
 * Description: Keeps the WordPress storefront in step with the current Etsy sale. Auto-applies the one designated sale coupon in the cart (so WordPress matches Etsy's automatic sale, no code typing) and renders the top sale bar from that coupon's live data. The designated coupon code lives in the era_sale_coupon_code option, managed by Phoenix MCP (wc_set_sale_coupon) and the daily Etsy <-> WooCommerce sync. When no sale is designated, or the designated coupon has expired, nothing is applied and no bar is shown.
 * Version: 1.0.0
 * Author: Apparel is How Eye Roll
 * License: GPL-2.0-or-later
 * Text Domain: era-sale-sync
 */

if (!defined('ABSPATH')) { exit; }

define('ERA_SALE_SYNC_OPTION', 'era_sale_coupon_code');
define('ERA_SALE_SYNC_FALLBACK_CODE', 'novembersale');
define('ERA_SALE_SYNC_CRON_HOOK', 'era_sale_sync_daily_check');

/* -------------------------------------------------------------------------
 * Designated sale coupon
 *
 * The era_sale_coupon_code option designates the single coupon that mirrors
 * the currently active Etsy sale:
 *   - non-empty code -> that coupon is the current sale
 *   - empty string   -> explicitly no sale is active
 *   - option unset   -> fall back to novembersale (preserves the behaviour
 *                       this plugin inherited from the theme)
 * No other coupon is ever auto-applied or advertised by this plugin.
 * ---------------------------------------------------------------------- */
function era_sale_sync_designated_code()
{
    $value = get_option(ERA_SALE_SYNC_OPTION, false);
    if ($value === false) {
        return ERA_SALE_SYNC_FALLBACK_CODE;
    }
    return strtolower(trim((string) $value));
}

/**
 * The designated coupon if it currently exists and is usable, else null.
 *
 * Cart-free validity checks (exists, published, not expired, usage limit
 * not exhausted) so this can run anywhere, including page rendering and
 * WP-Cron. Returns a WC_Coupon or null. Never throws.
 */
function era_sale_sync_current_coupon()
{
    $code = era_sale_sync_designated_code();
    if ($code === '' || !class_exists('WC_Coupon')) {
        return null;
    }
    try {
        $coupon = new WC_Coupon($code);
        if (!$coupon->get_id()) {
            return null;
        }
        if (get_post_status($coupon->get_id()) !== 'publish') {
            return null;
        }
        $expires = $coupon->get_date_expires();
        if ($expires && $expires->getTimestamp() < time()) {
            return null;
        }
        $limit = (int) $coupon->get_usage_limit();
        if ($limit > 0 && (int) $coupon->get_usage_count() >= $limit) {
            return null;
        }
        return $coupon;
    } catch (Throwable $e) {
        return null;
    }
}

/* -------------------------------------------------------------------------
 * 1. Auto-apply the designated sale coupon in the cart.
 *
 * Validity in the cart is delegated to WooCommerce itself
 * (WC_Coupon::is_valid()), so the moment the designated coupon expires or
 * is deleted/unpublished this stops applying it on its own. No dates are
 * hard-coded.
 * ---------------------------------------------------------------------- */
function era_sale_sync_auto_apply($cart)
{
    static $era_running = false;
    if ($era_running) { return; } // add_discount() re-triggers calculate_totals
    if (!is_object($cart) || !method_exists($cart, 'add_discount')) { return; }
    if (is_admin() && !wp_doing_ajax()) { return; }
    if (!class_exists('WC_Coupon')) { return; }
    if ($cart->is_empty()) { return; }
    $code = era_sale_sync_designated_code();
    if ($code === '') { return; } // No sale designated: apply nothing.
    foreach ($cart->get_applied_coupons() as $applied) {
        if (strtolower($applied) === $code) { return; }
    }
    try {
        $coupon = new WC_Coupon($code);
        if (!$coupon->get_id() || !$coupon->is_valid()) { return; }
    } catch (Throwable $e) {
        return;
    }
    $era_running = true;
    $cart->add_discount($code);
    $era_running = false;
}
add_action('woocommerce_before_calculate_totals', 'era_sale_sync_auto_apply', 20, 1);

/* -------------------------------------------------------------------------
 * 2. Sale bar.
 *
 * Rendered at the very top of the page (wp_body_open, where the theme's
 * old hard-coded bar used to sit) using the theme's existing .sale-bar
 * styling. Text is built from the designated coupon's live data: its
 * discount, its code, and its expiry. The expiry instant is the first
 * moment the sale is over, so the "ENDS" day shown is one second before
 * it, formatted in America/Chicago (e.g. ENDS NOV 30). When there is no
 * valid designated coupon, nothing is rendered at all.
 * ---------------------------------------------------------------------- */
function era_sale_sync_render_sale_bar()
{
    if (is_admin()) { return; }
    $coupon = era_sale_sync_current_coupon();
    if (!$coupon) { return; }

    $amount = (string) $coupon->get_amount();
    if (strpos($amount, '.') !== false) {
        $amount = rtrim(rtrim($amount, '0'), '.');
    }
    $type = $coupon->get_discount_type();
    if ($type === 'percent') {
        $offer = $amount . '% OFF EVERYTHING';
    } elseif ($type === 'fixed_cart' || $type === 'fixed_product') {
        $offer = '$' . $amount . ' OFF EVERYTHING';
    } else {
        $offer = 'SALE';
    }

    $ends = '';
    $expires = $coupon->get_date_expires();
    if ($expires) {
        $last_day = $expires->getTimestamp() - 1;
        $ends = ' ENDS ' . strtoupper(wp_date('M j', $last_day, new DateTimeZone('America/Chicago')));
    }

    echo '<div class="sale-bar">' . esc_html($offer) . ' &mdash; CODE <strong>'
        . esc_html(strtoupper($coupon->get_code())) . '</strong>' . esc_html($ends)
        . '</div>' . "\n";
}
add_action('wp_body_open', 'era_sale_sync_render_sale_bar', 1);

/* -------------------------------------------------------------------------
 * 3. Self-cleanup: a daily WP-Cron check inside the plugin.
 *
 * If the designated coupon has expired, been deleted/unpublished, or hit
 * its usage limit, clear the designation to an explicit "no sale" so the
 * sale bar and the auto-apply stop on their own, even if no external
 * sync ever runs again.
 * ---------------------------------------------------------------------- */
function era_sale_sync_daily_check()
{
    if (!class_exists('WC_Coupon')) { return; } // WooCommerce inactive: do nothing.
    if (era_sale_sync_designated_code() === '') { return; } // Already no-sale.
    if (era_sale_sync_current_coupon() === null) {
        update_option(ERA_SALE_SYNC_OPTION, '');
    }
}
add_action(ERA_SALE_SYNC_CRON_HOOK, 'era_sale_sync_daily_check');

function era_sale_sync_schedule_daily_check()
{
    if (!wp_next_scheduled(ERA_SALE_SYNC_CRON_HOOK)) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', ERA_SALE_SYNC_CRON_HOOK);
    }
}
add_action('init', 'era_sale_sync_schedule_daily_check');

/* -------------------------------------------------------------------------
 * Activation / deactivation
 * ---------------------------------------------------------------------- */
function era_sale_sync_activate()
{
    // Seed the designation ONLY if it has never been set, so the sale that
    // was live when this plugin took over keeps working unchanged.
    if (get_option(ERA_SALE_SYNC_OPTION, false) === false) {
        update_option(ERA_SALE_SYNC_OPTION, ERA_SALE_SYNC_FALLBACK_CODE);
    }
    era_sale_sync_schedule_daily_check();
}
register_activation_hook(__FILE__, 'era_sale_sync_activate');

function era_sale_sync_deactivate()
{
    wp_clear_scheduled_hook(ERA_SALE_SYNC_CRON_HOOK);
    // The era_sale_coupon_code option is intentionally left in place.
}
register_deactivation_hook(__FILE__, 'era_sale_sync_deactivate');
