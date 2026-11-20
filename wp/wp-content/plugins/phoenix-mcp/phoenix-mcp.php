<?php
/**
 * Plugin Name: Phoenix MCP
 * Description: A small, self-contained MCP (Model Context Protocol) server for this site, built for Phoenix. Exposes site, content and WooCommerce tools over a single REST endpoint, authenticated by a Bearer token (stored as a SHA-256 hash, revocable in Settings -> Phoenix MCP).
 * Version: 1.0.0
 * Author: Phoenix
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PHOENIX_MCP_HASH_OPTION', 'phoenix_mcp_token_hash');
define('PHOENIX_MCP_USER_OPTION', 'phoenix_mcp_user_id');
define('PHOENIX_MCP_PROTOCOL_VERSION', '2025-03-26');

/* -------------------------------------------------------------------------
 * REST route: POST /wp-json/phoenix-mcp/v1/server
 * ---------------------------------------------------------------------- */

add_action('rest_api_init', function () {
    register_rest_route('phoenix-mcp/v1', '/server', array(
        'methods'             => 'POST',
        'callback'            => 'phoenix_mcp_handle_request',
        'permission_callback' => 'phoenix_mcp_check_token',
    ));
});

/**
 * Bearer-token check. Runs only for this route, so the token is useless
 * anywhere else on the site. On success the request runs as the user who
 * generated the token, so normal WordPress capabilities apply to tools.
 */
function phoenix_mcp_check_token($request)
{
    $auth = $request->get_header('authorization');
    if (!$auth && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!$auth && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    if (!$auth || stripos($auth, 'Bearer ') !== 0) {
        return new WP_Error('phoenix_mcp_unauthorized', 'Missing or invalid Bearer token.', array('status' => 401));
    }

    $token       = trim(substr($auth, 7));
    $stored_hash = get_option(PHOENIX_MCP_HASH_OPTION, '');
    if ($token === '' || !$stored_hash || !hash_equals($stored_hash, hash('sha256', $token))) {
        return new WP_Error('phoenix_mcp_unauthorized', 'Missing or invalid Bearer token.', array('status' => 401));
    }

    $user_id = (int) get_option(PHOENIX_MCP_USER_OPTION, 0);
    if ($user_id > 0) {
        wp_set_current_user($user_id);
    }
    return true;
}

/* -------------------------------------------------------------------------
 * JSON-RPC / MCP handling
 * ---------------------------------------------------------------------- */

function phoenix_mcp_handle_request($request)
{
    $raw  = $request->get_body();
    $data = json_decode($raw, true);

    if (!is_array($data) || !isset($data['method'])) {
        return phoenix_mcp_error_response(null, -32700, 'Parse error: invalid JSON-RPC request.');
    }

    $method = $data['method'];
    $params = isset($data['params']) && is_array($data['params']) ? $data['params'] : array();
    $is_notification = !array_key_exists('id', $data) || $data['id'] === null;
    $id = $is_notification ? null : $data['id'];

    // Notifications (e.g. notifications/initialized) need no response body.
    if ($is_notification) {
        return new WP_REST_Response(null, 202);
    }

    switch ($method) {
        case 'initialize':
            $client_version = isset($params['protocolVersion']) ? $params['protocolVersion'] : PHOENIX_MCP_PROTOCOL_VERSION;
            return phoenix_mcp_result_response($id, array(
                'protocolVersion' => $client_version,
                'capabilities'    => array('tools' => array('listChanged' => false)),
                'serverInfo'      => array('name' => 'phoenix-mcp', 'version' => '1.0.0'),
            ));

        case 'ping':
            return phoenix_mcp_result_response($id, new stdClass());

        case 'tools/list':
            $tools = array();
            foreach (phoenix_mcp_tools() as $name => $tool) {
                $tools[] = array(
                    'name'        => $name,
                    'description' => $tool['description'],
                    'inputSchema' => $tool['inputSchema'],
                );
            }
            return phoenix_mcp_result_response($id, array('tools' => $tools));

        case 'tools/call':
            $name = isset($params['name']) ? $params['name'] : '';
            $args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : array();
            $tools = phoenix_mcp_tools();
            if (!isset($tools[$name])) {
                return phoenix_mcp_error_response($id, -32602, 'Unknown tool: ' . $name);
            }
            try {
                $result = call_user_func($tools[$name]['callback'], $args);
                if (is_wp_error($result)) {
                    return phoenix_mcp_tool_error_response($id, $result->get_error_message());
                }
                return phoenix_mcp_result_response($id, array(
                    'content' => array(array(
                        'type' => 'text',
                        'text' => wp_json_encode($result),
                    )),
                    'isError' => false,
                ));
            } catch (Throwable $e) {
                return phoenix_mcp_tool_error_response($id, $e->getMessage());
            }

        default:
            return phoenix_mcp_error_response($id, -32601, 'Method not found: ' . $method);
    }
}

function phoenix_mcp_result_response($id, $result)
{
    return new WP_REST_Response(array(
        'jsonrpc' => '2.0',
        'id'      => $id,
        'result'  => $result,
    ), 200);
}

function phoenix_mcp_error_response($id, $code, $message)
{
    return new WP_REST_Response(array(
        'jsonrpc' => '2.0',
        'id'      => $id,
        'error'   => array('code' => $code, 'message' => $message),
    ), 200);
}

function phoenix_mcp_tool_error_response($id, $message)
{
    return phoenix_mcp_result_response($id, array(
        'content' => array(array('type' => 'text', 'text' => $message)),
        'isError' => true,
    ));
}

/* -------------------------------------------------------------------------
 * Tool registry
 * ---------------------------------------------------------------------- */

function phoenix_mcp_tools()
{
    $empty_schema = array('type' => 'object', 'properties' => new stdClass());

    return array(
        'site_info' => array(
            'description' => 'Basic facts about this WordPress site: name, URL, WordPress and PHP versions, active theme, whether WooCommerce is active.',
            'inputSchema' => $empty_schema,
            'callback'    => 'phoenix_mcp_tool_site_info',
        ),
        'list_posts' => array(
            'description' => 'List blog posts (newest first).',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'status'   => array('type' => 'string', 'description' => 'Post status, e.g. publish, draft. Default publish.'),
                    'per_page' => array('type' => 'integer', 'description' => 'How many to return (1-50). Default 10.'),
                    'search'   => array('type' => 'string', 'description' => 'Optional search term.'),
                ),
            ),
            'callback'    => 'phoenix_mcp_tool_list_posts',
        ),
        'create_post' => array(
            'description' => 'Create a blog post. Defaults to draft.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'title'   => array('type' => 'string'),
                    'content' => array('type' => 'string'),
                    'status'  => array('type' => 'string', 'description' => 'draft or publish. Default draft.'),
                ),
                'required' => array('title'),
            ),
            'callback'    => 'phoenix_mcp_tool_create_post',
        ),
        'list_pages' => array(
            'description' => 'List pages (newest first).',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'status'   => array('type' => 'string'),
                    'per_page' => array('type' => 'integer'),
                ),
            ),
            'callback'    => 'phoenix_mcp_tool_list_pages',
        ),
        'list_plugins' => array(
            'description' => 'List installed plugins with version and active status.',
            'inputSchema' => $empty_schema,
            'callback'    => 'phoenix_mcp_tool_list_plugins',
        ),
        'wc_list_products' => array(
            'description' => 'List WooCommerce products with price and stock.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'status'   => array('type' => 'string', 'description' => 'publish, draft, or any. Default publish.'),
                    'per_page' => array('type' => 'integer'),
                    'search'   => array('type' => 'string'),
                ),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_list_products',
        ),
        'wc_get_product' => array(
            'description' => 'Get one WooCommerce product in detail by ID.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array('id' => array('type' => 'integer')),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_get_product',
        ),
        'wc_update_product' => array(
            'description' => 'Update a WooCommerce product: regular price, sale price, stock quantity, and/or status.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'id'             => array('type' => 'integer'),
                    'regular_price'  => array('type' => 'string'),
                    'sale_price'     => array('type' => 'string'),
                    'stock_quantity' => array('type' => 'integer'),
                    'status'         => array('type' => 'string', 'description' => 'publish or draft.'),
                ),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_update_product',
        ),
        'wc_list_orders' => array(
            'description' => 'List recent WooCommerce orders (newest first).',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'per_page' => array('type' => 'integer'),
                    'status'   => array('type' => 'string', 'description' => 'Order status, e.g. completed, processing. Omit for all.'),
                ),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_list_orders',
        ),
    );
}

/* -------------------------------------------------------------------------
 * Tool implementations
 * ---------------------------------------------------------------------- */

function phoenix_mcp_tool_site_info($args)
{
    global $wp_version;
    $theme = wp_get_theme();
    return array(
        'name'               => get_bloginfo('name'),
        'description'        => get_bloginfo('description'),
        'url'                => home_url(),
        'wordpress_version'  => $wp_version,
        'php_version'        => PHP_VERSION,
        'active_theme'       => $theme ? $theme->get('Name') : null,
        'woocommerce_active' => class_exists('WooCommerce'),
        'timezone'           => wp_timezone_string(),
    );
}

function phoenix_mcp_post_summary($post)
{
    return array(
        'id'     => $post->ID,
        'title'  => $post->post_title,
        'status' => $post->post_status,
        'date'   => $post->post_date,
        'link'   => get_permalink($post),
    );
}

function phoenix_mcp_tool_list_posts($args)
{
    $query = array(
        'post_type'      => 'post',
        'post_status'    => isset($args['status']) ? sanitize_key($args['status']) : 'publish',
        'posts_per_page' => isset($args['per_page']) ? max(1, min(50, (int) $args['per_page'])) : 10,
        'orderby'        => 'date',
        'order'          => 'DESC',
    );
    if (!empty($args['search'])) {
        $query['s'] = sanitize_text_field($args['search']);
    }
    return array_map('phoenix_mcp_post_summary', get_posts($query));
}

function phoenix_mcp_tool_list_pages($args)
{
    $query = array(
        'post_type'      => 'page',
        'post_status'    => isset($args['status']) ? sanitize_key($args['status']) : 'publish',
        'posts_per_page' => isset($args['per_page']) ? max(1, min(50, (int) $args['per_page'])) : 10,
        'orderby'        => 'date',
        'order'          => 'DESC',
    );
    return array_map('phoenix_mcp_post_summary', get_posts($query));
}

function phoenix_mcp_tool_create_post($args)
{
    if (!current_user_can('edit_posts')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot create posts.');
    }
    $status = isset($args['status']) && $args['status'] === 'publish' ? 'publish' : 'draft';
    if ($status === 'publish' && !current_user_can('publish_posts')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot publish posts.');
    }
    $post_id = wp_insert_post(array(
        'post_title'   => sanitize_text_field($args['title']),
        'post_content' => isset($args['content']) ? wp_kses_post($args['content']) : '',
        'post_status'  => $status,
        'post_author'  => get_current_user_id(),
    ), true);
    if (is_wp_error($post_id)) {
        return $post_id;
    }
    return array(
        'id'     => $post_id,
        'title'  => get_the_title($post_id),
        'status' => get_post_status($post_id),
        'link'   => get_permalink($post_id),
    );
}

function phoenix_mcp_tool_list_plugins($args)
{
    if (!function_exists('get_plugins')) {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $out = array();
    foreach (get_plugins() as $file => $data) {
        $out[] = array(
            'name'    => $data['Name'],
            'version' => $data['Version'],
            'active'  => is_plugin_active($file),
            'file'    => $file,
        );
    }
    return $out;
}

function phoenix_mcp_require_woo()
{
    return class_exists('WooCommerce') && function_exists('wc_get_products');
}

function phoenix_mcp_product_summary($product)
{
    return array(
        'id'             => $product->get_id(),
        'name'           => $product->get_name(),
        'status'         => $product->get_status(),
        'regular_price'  => $product->get_regular_price(),
        'sale_price'     => $product->get_sale_price(),
        'price'          => $product->get_price(),
        'stock_quantity' => $product->get_stock_quantity(),
        'stock_status'   => $product->get_stock_status(),
        'sku'            => $product->get_sku(),
        'link'           => get_permalink($product->get_id()),
    );
}

function phoenix_mcp_tool_wc_list_products($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    $query = array(
        'limit'   => isset($args['per_page']) ? max(1, min(50, (int) $args['per_page'])) : 10,
        'status'  => isset($args['status']) ? sanitize_key($args['status']) : 'publish',
        'orderby' => 'date',
        'order'   => 'DESC',
    );
    if (!empty($args['search'])) {
        $query['s'] = sanitize_text_field($args['search']);
    }
    $out = array();
    foreach (wc_get_products($query) as $product) {
        $out[] = phoenix_mcp_product_summary($product);
    }
    return $out;
}

function phoenix_mcp_tool_wc_get_product($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    $product = wc_get_product((int) $args['id']);
    if (!$product) {
        return new WP_Error('phoenix_mcp_not_found', 'No product with that ID.');
    }
    $data = phoenix_mcp_product_summary($product);
    $data['description']       = $product->get_description();
    $data['short_description'] = $product->get_short_description();
    $data['categories']        = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
    $image_id                  = $product->get_image_id();
    $data['image']             = $image_id ? wp_get_attachment_url($image_id) : null;
    return $data;
}

function phoenix_mcp_tool_wc_update_product($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('edit_products')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot edit products.');
    }
    $product = wc_get_product((int) $args['id']);
    if (!$product) {
        return new WP_Error('phoenix_mcp_not_found', 'No product with that ID.');
    }
    if (isset($args['regular_price'])) {
        $product->set_regular_price(wc_format_decimal($args['regular_price']));
    }
    if (isset($args['sale_price'])) {
        $product->set_sale_price($args['sale_price'] === '' ? '' : wc_format_decimal($args['sale_price']));
    }
    if (isset($args['stock_quantity'])) {
        $product->set_manage_stock(true);
        $product->set_stock_quantity((int) $args['stock_quantity']);
    }
    if (isset($args['status']) && in_array($args['status'], array('publish', 'draft'), true)) {
        $product->set_status($args['status']);
    }
    $product->save();
    return phoenix_mcp_product_summary($product);
}

function phoenix_mcp_tool_wc_list_orders($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    $query = array(
        'limit'   => isset($args['per_page']) ? max(1, min(50, (int) $args['per_page'])) : 10,
        'orderby' => 'date',
        'order'   => 'DESC',
    );
    if (!empty($args['status'])) {
        $query['status'] = sanitize_key($args['status']);
    }
    $out = array();
    foreach (wc_get_orders($query) as $order) {
        $out[] = array(
            'id'             => $order->get_id(),
            'status'         => $order->get_status(),
            'total'          => $order->get_total(),
            'currency'       => $order->get_currency(),
            'date'           => $order->get_date_created() ? $order->get_date_created()->date('c') : null,
            'customer_email' => $order->get_billing_email(),
            'items'          => $order->get_item_count(),
        );
    }
    return $out;
}

/* -------------------------------------------------------------------------
 * Settings page: Settings -> Phoenix MCP (token management)
 * ---------------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_options_page(
        'Phoenix MCP',
        'Phoenix MCP',
        'manage_options',
        'phoenix-mcp',
        'phoenix_mcp_render_settings'
    );
});

function phoenix_mcp_render_settings()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $new_token = null;

    if (isset($_POST['phoenix_mcp_action']) && check_admin_referer('phoenix_mcp_settings')) {
        if ($_POST['phoenix_mcp_action'] === 'generate') {
            $new_token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            update_option(PHOENIX_MCP_HASH_OPTION, hash('sha256', $new_token));
            update_option(PHOENIX_MCP_USER_OPTION, get_current_user_id());
        } elseif ($_POST['phoenix_mcp_action'] === 'revoke') {
            delete_option(PHOENIX_MCP_HASH_OPTION);
            delete_option(PHOENIX_MCP_USER_OPTION);
        }
    }

    $has_token = (bool) get_option(PHOENIX_MCP_HASH_OPTION, '');

    echo '<div class="wrap"><h1>Phoenix MCP</h1>';

    if ($new_token !== null) {
        echo '<div class="notice notice-success"><p><strong>New token generated.</strong> Copy it now &mdash; it is shown only once. Paste it into the secure card Phoenix sent you.</p></div>';
        echo '<p><input type="text" readonly class="large-text code" value="' . esc_attr($new_token) . '" onclick="this.select()"></p>';
    }

    echo '<p>Status: ' . ($has_token ? '<strong>Active</strong> &mdash; a token is set.' : 'No token set.') . '</p>';

    echo '<form method="post">';
    wp_nonce_field('phoenix_mcp_settings');
    echo '<button type="submit" class="button button-primary" name="phoenix_mcp_action" value="generate">'
        . ($has_token ? 'Generate new token (replaces the old one)' : 'Generate token') . '</button> ';
    if ($has_token) {
        echo '<button type="submit" class="button" name="phoenix_mcp_action" value="revoke">Revoke token</button>';
    }
    echo '</form>';

    echo '<p style="margin-top:1em">MCP endpoint: <code>' . esc_html(rest_url('phoenix-mcp/v1/server')) . '</code></p>';
    echo '<p>The token authenticates as your user account and works only on this endpoint. Revoke it here any time to cut off access.</p>';
    echo '</div>';
}
