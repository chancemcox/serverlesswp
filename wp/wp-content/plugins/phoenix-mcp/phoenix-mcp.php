<?php
/**
 * Plugin Name: Phoenix MCP
 * Description: A small, self-contained MCP (Model Context Protocol) server for this site, built for Phoenix. Exposes site, content and WooCommerce tools over a single REST endpoint, authenticated by a Bearer token (stored as a SHA-256 hash, revocable in Settings -> Phoenix MCP).
 * Version: 1.3.8
 * Author: Phoenix
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PHOENIX_MCP_HASH_OPTION', 'phoenix_mcp_token_hash');
define('PHOENIX_MCP_USER_OPTION', 'phoenix_mcp_user_id');
define('PHOENIX_MCP_PROTOCOL_VERSION', '2025-03-26');
define('PHOENIX_MCP_SALE_COUPON_CODE_OPTION', 'era_sale_coupon_code');
define('PHOENIX_MCP_TERMS_PAGE_OPTION', 'woocommerce_terms_page_id');

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
                'serverInfo'      => array('name' => 'phoenix-mcp', 'version' => '1.3.8'),
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
        'create_page' => array(
            'description' => 'Create a WordPress page with an explicit slug. Defaults to draft. Set make_posts_page to also designate the new page as the posts index (page_for_posts option).',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'title'           => array('type' => 'string'),
                    'content'         => array('type' => 'string'),
                    'slug'            => array('type' => 'string', 'description' => 'URL slug, e.g. about.'),
                    'status'          => array('type' => 'string', 'description' => 'draft or publish. Default draft.'),
                    'make_posts_page' => array('type' => 'boolean', 'description' => 'When true, set this page as the posts page (page_for_posts). Does not change the front page setting.'),
                ),
                'required' => array('title', 'slug'),
            ),
            'callback'    => 'phoenix_mcp_tool_create_page',
        ),
        'update_page' => array(
            'description' => 'Update an existing page: title, content, and/or status (publish, draft, private).',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'id'      => array('type' => 'integer'),
                    'title'   => array('type' => 'string'),
                    'content' => array('type' => 'string'),
                    'status'  => array('type' => 'string', 'description' => 'publish, draft, or private.'),
                ),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_update_page',
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
            'description' => 'Update a WooCommerce product: regular price, sale price, stock quantity, status, description, short description, and/or categories (replaces the category list).',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'id'                => array('type' => 'integer'),
                    'regular_price'     => array('type' => 'string'),
                    'sale_price'        => array('type' => 'string'),
                    'stock_quantity'    => array('type' => 'integer'),
                    'status'            => array('type' => 'string', 'description' => 'publish or draft.'),
                    'description'       => array('type' => 'string', 'description' => 'Full product description (HTML allowed).'),
                    'short_description' => array('type' => 'string', 'description' => 'Short product description (HTML allowed).'),
                    'categories'        => array(
                        'type'        => 'array',
                        'items'       => array('type' => 'integer'),
                        'description' => 'Product category IDs; replaces the current categories.',
                    ),
                ),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_update_product',
        ),
        'wc_set_product_images' => array(
            'description' => 'Download images from public URLs into the media library and set them on a WooCommerce product: the first image becomes the featured image, the rest become the gallery. Replaces the current images. Uploads flow through WP Offload Media, so files land in durable object storage.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'         => array('type' => 'integer'),
                    'image_urls' => array(
                        'type'        => 'array',
                        'items'       => array('type' => 'string'),
                        'description' => 'Public image URLs, featured image first. Max 4 per call.',
                    ),
                    'alt'        => array('type' => 'string', 'description' => 'Alt text for the featured image. Defaults to the product name.'),
                ),
                'required' => array('id', 'image_urls'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_set_product_images',
        ),
        'wc_rebuild_variable_product' => array(
            'description' => 'Rebuild a WooCommerce product as a variable product: set product-level variation attributes, delete any existing variations, and create one purchasable variation per entry (regular price, in stock, stock not managed), storing the Printify variant ID in variation meta key printify_variant_id. With append=true, existing variations are kept and entries whose printify_variant_id is already present are skipped (for chunked rebuilds).',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'         => array('type' => 'integer'),
                    'attributes' => array(
                        'type'        => 'array',
                        'description' => 'Variation attributes: [{name, options: [..]}].',
                        'items'       => array(
                            'type'       => 'object',
                            'properties' => array(
                                'name'    => array('type' => 'string'),
                                'options' => array('type' => 'array', 'items' => array('type' => 'string')),
                            ),
                            'required' => array('name', 'options'),
                        ),
                    ),
                    'variations' => array(
                        'type'        => 'array',
                        'description' => 'One entry per variation: {attributes: {AttributeName: value, ..}, price: "12.34", printify_variant_id: 123}.',
                        'items'       => array(
                            'type'       => 'object',
                            'properties' => array(
                                'attributes'          => array('type' => 'object'),
                                'price'               => array('type' => 'string'),
                                'printify_variant_id' => array('type' => 'integer'),
                            ),
                            'required' => array('attributes', 'price'),
                        ),
                    ),
                    'append'     => array('type' => 'boolean', 'description' => 'Keep existing variations and only add missing ones (matched by printify_variant_id). Default false.'),
                ),
                'required' => array('id', 'attributes', 'variations'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_rebuild_variable_product',
        ),
        'wc_list_categories' => array(
            'description' => 'List WooCommerce product categories (including empty ones), paginated.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'per_page' => array('type' => 'integer', 'description' => 'How many to return (1-100). Default 100.'),
                    'page'     => array('type' => 'integer', 'description' => 'Page number. Default 1.'),
                ),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_list_categories',
        ),
        'wc_create_category' => array(
            'description' => 'Create a WooCommerce product category.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'name'        => array('type' => 'string'),
                    'slug'        => array('type' => 'string'),
                    'description' => array('type' => 'string'),
                ),
                'required' => array('name', 'slug'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_create_category',
        ),
        'wc_update_category' => array(
            'description' => 'Update a WooCommerce product category: name, slug, and/or description.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'id'          => array('type' => 'integer'),
                    'name'        => array('type' => 'string'),
                    'slug'        => array('type' => 'string'),
                    'description' => array('type' => 'string'),
                ),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_update_category',
        ),
        'trash_post' => array(
            'description' => 'Move a blog post to the trash by post ID.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array('id' => array('type' => 'integer')),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_trash_post',
        ),
        'trash_page' => array(
            'description' => 'Move a page to the trash by page ID.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array('id' => array('type' => 'integer')),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_trash_page',
        ),
        'wc_create_api_key' => array(
            'description' => 'Create a WooCommerce REST API key (read_write) for the token user and stage it server-side for delivery to Printify. The key material never leaves the server. Returns only the key ID and its last 7 characters.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'description' => array('type' => 'string', 'description' => 'Key description shown in wp-admin. Default is Printify (via Phoenix).'),
                ),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_create_api_key',
        ),
        'wc_deliver_keys' => array(
            'description' => 'Deliver the staged WooCommerce API key to Printify by POSTing it to the callback URL from a Printify connect attempt, doing the step WooCommerce normally does. Returns Printify response status and body. Clears the staged copy only on success.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'callback_url' => array('type' => 'string', 'description' => 'The full callback_url value from the Printify authorize URL.'),
                    'user_id'      => array('type' => 'string', 'description' => 'The user_id value from the Printify authorize URL.'),
                ),
                'required' => array('callback_url', 'user_id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_deliver_keys',
        ),
        'http_test' => array(
            'description' => 'Test outbound HTTPS from this server (the same mechanism WooCommerce uses to deliver API keys to Printify). Returns status code, response time and any transport error.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'url'    => array('type' => 'string', 'description' => 'HTTPS URL to request. Default is the Printify API.'),
                    'method' => array('type' => 'string', 'description' => 'GET or POST. Default POST.'),
                ),
            ),
            'callback'    => 'phoenix_mcp_tool_http_test',
        ),
        'wc_list_coupons' => array(
            'description' => 'List WooCommerce coupons, optionally filtered by an exact code.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'code'     => array('type' => 'string', 'description' => 'Optional exact coupon code to look up.'),
                    'per_page' => array('type' => 'integer'),
                ),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_list_coupons',
        ),
        'wc_create_coupon' => array(
            'description' => 'Create a WooCommerce coupon. Fails if a coupon with the same code already exists. Shop-wide by default: no product/category restrictions and no minimum spend unless given.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'code'          => array('type' => 'string'),
                    'discount_type' => array('type' => 'string', 'description' => 'percent, fixed_cart, or fixed_product. Default percent.'),
                    'amount'        => array('type' => 'string', 'description' => 'Discount amount, e.g. "15" for 15%.'),
                    'date_expires'  => array('type' => 'string', 'description' => 'Expiry in site timezone, format Y-m-d H:i:s, e.g. "2026-12-01 00:00:00". Omit for no expiry.'),
                    'minimum_amount' => array('type' => 'string', 'description' => 'Minimum spend. Omit or empty for none.'),
                    'description'   => array('type' => 'string'),
                ),
                'required' => array('code', 'amount'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_create_coupon',
        ),
        'wc_set_sale_coupon' => array(
            'description' => 'Designate the single WooCommerce coupon code the ERA Sale Sync plugin auto-applies for the current mirrored Etsy sale. Writes only the era_sale_coupon_code WordPress option. A non-empty code must be an existing WooCommerce coupon; an empty string explicitly means no sale is active and disables sale auto-apply.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'code' => array('type' => 'string', 'description' => 'Existing WooCommerce coupon code to designate as the current sale, or an empty string for no current sale.'),
                ),
                'required' => array('code'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_set_sale_coupon',
        ),
        'wc_set_terms_page' => array(
            'description' => 'Set the WooCommerce Terms and Conditions page (the woocommerce_terms_page_id option, shown in wp-admin under WooCommerce > Settings > Advanced > Page setup). The target must be an existing published WordPress page. Writes only that one option, then reads it back to verify.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'page_id' => array('type' => 'integer', 'description' => 'ID of the published page to designate as the Terms and Conditions page.'),
                ),
                'required' => array('page_id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_set_terms_page',
        ),
        'activate_plugin' => array(
            'description' => 'Activate an already-installed WordPress plugin by plugin file (e.g. "era-sale-sync/era-sale-sync.php") or folder slug (e.g. "era-sale-sync"). Activation only: it cannot install, upload, deactivate, or delete plugins.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'plugin' => array('type' => 'string', 'description' => 'Plugin file (slug/slug.php) or folder slug of an installed plugin.'),
                ),
                'required' => array('plugin'),
            ),
            'callback'    => 'phoenix_mcp_tool_activate_plugin',
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

function phoenix_mcp_tool_create_page($args)
{
    if (!current_user_can('edit_pages')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot create pages.');
    }
    $status = isset($args['status']) && $args['status'] === 'publish' ? 'publish' : 'draft';
    if ($status === 'publish' && !current_user_can('publish_pages')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot publish pages.');
    }
    $slug = sanitize_title($args['slug']);
    $existing = get_page_by_path($slug);
    if ($existing) {
        return new WP_Error('phoenix_mcp_exists', 'A page with slug "' . $slug . '" already exists (ID ' . $existing->ID . ').');
    }
    $post_id = wp_insert_post(array(
        'post_title'   => sanitize_text_field($args['title']),
        'post_content' => isset($args['content']) ? wp_kses_post($args['content']) : '',
        'post_status'  => $status,
        'post_type'    => 'page',
        'post_name'    => $slug,
        'post_author'  => get_current_user_id(),
    ), true);
    if (is_wp_error($post_id)) {
        return $post_id;
    }
    $posts_page = false;
    if (!empty($args['make_posts_page'])) {
        update_option('page_for_posts', $post_id);
        $posts_page = ((int) get_option('page_for_posts') === (int) $post_id);
    }
    return array(
        'id'         => $post_id,
        'title'      => get_the_title($post_id),
        'status'     => get_post_status($post_id),
        'link'       => get_permalink($post_id),
        'posts_page' => $posts_page,
    );
}

function phoenix_mcp_tool_update_page($args)
{
    $post_id = (int) $args['id'];
    $post    = get_post($post_id);
    if (!$post || $post->post_type !== 'page') {
        return new WP_Error('phoenix_mcp_not_found', 'No page with that ID.');
    }
    if (!current_user_can('edit_post', $post_id)) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot edit pages.');
    }
    $update = array('ID' => $post_id);
    if (isset($args['title'])) {
        $update['post_title'] = sanitize_text_field($args['title']);
    }
    if (isset($args['content'])) {
        $update['post_content'] = wp_kses_post($args['content']);
    }
    if (isset($args['status'])) {
        $status = sanitize_key($args['status']);
        if (!in_array($status, array('publish', 'draft', 'private'), true)) {
            return new WP_Error('phoenix_mcp_bad_status', 'Status must be publish, draft, or private.');
        }
        if ($status === 'publish' && !current_user_can('publish_pages')) {
            return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot publish pages.');
        }
        $update['post_status'] = $status;
    }
    $result = wp_update_post($update, true);
    if (is_wp_error($result)) {
        return $result;
    }
    return array(
        'id'     => $post_id,
        'title'  => get_the_title($post_id),
        'status' => get_post_status($post_id),
        'link'   => get_permalink($post_id),
    );
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

function phoenix_mcp_tool_activate_plugin($args)
{
    if (!current_user_can('activate_plugins') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot activate plugins.');
    }
    if (empty($args['plugin']) || !is_string($args['plugin'])) {
        return new WP_Error('phoenix_mcp_bad_args', 'plugin is required: a plugin file (slug/slug.php) or a folder slug.');
    }
    $plugin = sanitize_text_field($args['plugin']);
    if (strpos($plugin, '..') !== false || strpos($plugin, '\\') !== false || $plugin === '' || $plugin[0] === '/') {
        return new WP_Error('phoenix_mcp_bad_args', 'Invalid plugin path.');
    }
    if (substr($plugin, -4) !== '.php') {
        // Treat the value as a folder slug: slug/slug.php
        $slug = trim($plugin, '/');
        if (!preg_match('#^[a-z0-9\-_]+$#i', $slug)) {
            return new WP_Error('phoenix_mcp_bad_args', 'Invalid plugin slug.');
        }
        $plugin = $slug . '/' . $slug . '.php';
    }
    if (!function_exists('get_plugins') || !function_exists('activate_plugin')) {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $installed = get_plugins();
    if (!isset($installed[$plugin])) {
        return new WP_Error('phoenix_mcp_not_installed', 'Plugin "' . $plugin . '" is not installed.');
    }
    $summary = array(
        'plugin'  => $plugin,
        'name'    => $installed[$plugin]['Name'],
        'version' => $installed[$plugin]['Version'],
    );
    if (is_plugin_active($plugin)) {
        return array_merge($summary, array('active' => true, 'already_active' => true));
    }
    $result = activate_plugin($plugin);
    if (is_wp_error($result)) {
        return $result;
    }
    if (!is_plugin_active($plugin)) {
        return new WP_Error('phoenix_mcp_activation_failed', 'activate_plugin() ran but "' . $plugin . '" does not report active.');
    }
    return array_merge($summary, array('active' => true, 'already_active' => false));
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
    if (isset($args['description'])) {
        $product->set_description(wp_kses_post($args['description']));
    }
    if (isset($args['short_description'])) {
        $product->set_short_description(wp_kses_post($args['short_description']));
    }
    if (isset($args['categories']) && is_array($args['categories'])) {
        $product->set_category_ids(array_map('intval', $args['categories']));
    }
    $product->save();
    return phoenix_mcp_product_summary($product);
}

function phoenix_mcp_tool_wc_rebuild_variable_product($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('edit_products')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot edit products.');
    }
    $product_id = (int) $args['id'];
    $existing   = wc_get_product($product_id);
    if (!$existing) {
        return new WP_Error('phoenix_mcp_not_found', 'No product with that ID.');
    }
    if (empty($args['attributes']) || !is_array($args['attributes'])) {
        return new WP_Error('phoenix_mcp_bad_args', 'attributes must be a non-empty array.');
    }
    if (empty($args['variations']) || !is_array($args['variations'])) {
        return new WP_Error('phoenix_mcp_bad_args', 'variations must be a non-empty array.');
    }
    $append = !empty($args['append']);

    // Build product-level attributes used for variations.
    $attributes = array();
    $position   = 0;
    foreach ($args['attributes'] as $attr) {
        $name    = isset($attr['name']) ? sanitize_text_field($attr['name']) : '';
        $options = array();
        if (!empty($attr['options']) && is_array($attr['options'])) {
            foreach ($attr['options'] as $opt) {
                $opt = sanitize_text_field($opt);
                if ($opt !== '') {
                    $options[] = $opt;
                }
            }
        }
        if ($name === '' || !$options) {
            continue;
        }
        $attribute = new WC_Product_Attribute();
        $attribute->set_id(0);
        $attribute->set_name($name);
        $attribute->set_options($options);
        $attribute->set_position($position++);
        $attribute->set_visible(true);
        $attribute->set_variation(true);
        $attributes[] = $attribute;
    }
    if (!$attributes) {
        return new WP_Error('phoenix_mcp_bad_args', 'No usable attributes.');
    }

    // Convert to a variable product and save attributes first so variation
    // attribute keys resolve against the parent.
    $product = new WC_Product_Variable($product_id);
    $product->set_attributes($attributes);
    $product->save();
    wp_set_object_terms($product_id, 'variable', 'product_type');

    $existing_variation_ids = get_posts(array(
        'post_type'      => 'product_variation',
        'post_parent'    => $product_id,
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'fields'         => 'ids',
    ));

    $seen_printify_ids = array();
    if ($append) {
        foreach ($existing_variation_ids as $old_id) {
            $pf = get_post_meta($old_id, 'printify_variant_id', true);
            if ($pf !== '' && $pf !== false) {
                $seen_printify_ids[(int) $pf] = true;
            }
        }
    } else {
        foreach ($existing_variation_ids as $old_id) {
            wp_delete_post($old_id, true);
        }
    }

    $created = 0;
    $skipped = 0;
    foreach ($args['variations'] as $v) {
        if (empty($v['attributes']) || !is_array($v['attributes']) || !isset($v['price'])) {
            continue;
        }
        $pf_id = isset($v['printify_variant_id']) ? (int) $v['printify_variant_id'] : 0;
        if ($append && $pf_id && isset($seen_printify_ids[$pf_id])) {
            $skipped++;
            continue;
        }
        $variation_attributes = array();
        foreach ($v['attributes'] as $attr_name => $attr_value) {
            $variation_attributes[sanitize_title($attr_name)] = sanitize_text_field($attr_value);
        }
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($product_id);
        $variation->set_status('publish');
        $variation->set_attributes($variation_attributes);
        $variation->set_regular_price(wc_format_decimal($v['price']));
        $variation->set_manage_stock(false);
        $variation->set_stock_status('instock');
        $variation_id = $variation->save();
        if ($variation_id) {
            $created++;
            if ($pf_id) {
                update_post_meta($variation_id, 'printify_variant_id', $pf_id);
                $seen_printify_ids[$pf_id] = true;
            }
        }
    }

    // Re-sync the parent (price range and stock status come from variations).
    $fresh = wc_get_product($product_id);
    if ($fresh) {
        $fresh->save();
    }
    $count = get_posts(array(
        'post_type'      => 'product_variation',
        'post_parent'    => $product_id,
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ));
    $summary                   = phoenix_mcp_product_summary(wc_get_product($product_id));
    $summary['type']           = 'variable';
    $summary['variation_count'] = count($count);
    $summary['created']        = $created;
    $summary['skipped']        = $skipped;
    return $summary;
}

function phoenix_mcp_tool_wc_set_product_images($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('edit_products') || !current_user_can('upload_files')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot edit products or upload files.');
    }
    $product = wc_get_product((int) $args['id']);
    if (!$product) {
        return new WP_Error('phoenix_mcp_not_found', 'No product with that ID.');
    }
    $urls = array();
    foreach ((array) $args['image_urls'] as $u) {
        $u = esc_url_raw($u);
        if ($u !== '') {
            $urls[] = $u;
        }
    }
    $urls = array_slice(array_values(array_unique($urls)), 0, 4);
    if (!$urls) {
        return new WP_Error('phoenix_mcp_bad_args', 'No usable image URLs.');
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $alt      = isset($args['alt']) && $args['alt'] !== '' ? sanitize_text_field($args['alt']) : $product->get_name();
    $attached = array();
    $errors   = array();
    foreach ($urls as $url) {
        $name = basename(parse_url($url, PHP_URL_PATH));
        if ($name === '' || strpos($name, '.') === false) {
            $name = 'product-' . $product->get_id() . '-' . (count($attached) + 1) . '.jpg';
        }
        $tmp = download_url($url, 60);
        if (is_wp_error($tmp)) {
            $errors[] = $url . ' download failed: ' . $tmp->get_error_message();
            continue;
        }
        $file   = array('name' => sanitize_file_name($name), 'tmp_name' => $tmp);
        $att_id = media_handle_sideload($file, $product->get_id());
        if (is_wp_error($att_id)) {
            $errors[] = $url . ' sideload failed: ' . $att_id->get_error_message();
            continue;
        }
        $attached[] = (int) $att_id;
    }
    if (!$attached) {
        return new WP_Error('phoenix_mcp_upload_failed', 'Every image failed. ' . implode(' | ', $errors));
    }
    update_post_meta($attached[0], '_wp_attachment_image_alt', $alt);
    $product->set_image_id($attached[0]);
    $product->set_gallery_image_ids(array_slice($attached, 1));
    $product->save();
    $urls_out = array();
    foreach ($attached as $att_id) {
        $urls_out[] = wp_get_attachment_url($att_id);
    }
    return array(
        'id'      => $product->get_id(),
        'image'   => $urls_out[0],
        'gallery' => array_slice($urls_out, 1),
        'errors'  => $errors,
    );
}

function phoenix_mcp_tool_wc_list_categories($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    $per_page = isset($args['per_page']) ? max(1, min(100, (int) $args['per_page'])) : 100;
    $page     = isset($args['page']) ? max(1, (int) $args['page']) : 1;
    $terms = get_terms(array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'number'     => $per_page,
        'offset'     => ($page - 1) * $per_page,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ));
    if (is_wp_error($terms)) {
        return $terms;
    }
    $out = array();
    foreach ($terms as $term) {
        $out[] = array(
            'id'          => $term->term_id,
            'name'        => $term->name,
            'slug'        => $term->slug,
            'description' => $term->description,
            'parent'      => $term->parent,
            'count'       => $term->count,
        );
    }
    return $out;
}

function phoenix_mcp_tool_wc_create_category($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('manage_product_terms') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage product categories.');
    }
    $name = sanitize_text_field($args['name']);
    $slug = sanitize_title($args['slug']);
    $term_args = array('slug' => $slug);
    if (isset($args['description'])) {
        $term_args['description'] = wp_kses_post($args['description']);
    }
    $result = wp_insert_term($name, 'product_cat', $term_args);
    if (is_wp_error($result)) {
        return $result;
    }
    $term = get_term((int) $result['term_id'], 'product_cat');
    return array(
        'id'          => $term->term_id,
        'name'        => $term->name,
        'slug'        => $term->slug,
        'description' => $term->description,
        'parent'      => $term->parent,
        'count'       => $term->count,
    );
}

function phoenix_mcp_tool_wc_update_category($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('manage_product_terms') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage product categories.');
    }
    $term_id = (int) $args['id'];
    $term    = get_term($term_id, 'product_cat');
    if (!$term || is_wp_error($term)) {
        return new WP_Error('phoenix_mcp_not_found', 'No product category with that ID.');
    }
    $update = array();
    if (isset($args['name'])) {
        $update['name'] = sanitize_text_field($args['name']);
    }
    if (isset($args['slug'])) {
        $update['slug'] = sanitize_title($args['slug']);
    }
    if (isset($args['description'])) {
        $update['description'] = wp_kses_post($args['description']);
    }
    if (!$update) {
        return new WP_Error('phoenix_mcp_no_changes', 'Nothing to update.');
    }
    $result = wp_update_term($term_id, 'product_cat', $update);
    if (is_wp_error($result)) {
        return $result;
    }
    $term = get_term($term_id, 'product_cat');
    return array(
        'id'          => $term->term_id,
        'name'        => $term->name,
        'slug'        => $term->slug,
        'description' => $term->description,
        'parent'      => $term->parent,
        'count'       => $term->count,
    );
}

function phoenix_mcp_tool_trash_post($args)
{
    $post_id = (int) $args['id'];
    $post    = get_post($post_id);
    if (!$post || $post->post_type !== 'post') {
        return new WP_Error('phoenix_mcp_not_found', 'No blog post with that ID.');
    }
    if (!current_user_can('delete_post', $post_id)) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot delete posts.');
    }
    if (!wp_trash_post($post_id)) {
        return new WP_Error('phoenix_mcp_trash_failed', 'Could not trash the post.');
    }
    return array(
        'id'      => $post_id,
        'title'   => $post->post_title,
        'status'  => get_post_status($post_id),
        'trashed' => true,
    );
}

function phoenix_mcp_tool_trash_page($args)
{
    $post_id = (int) $args['id'];
    $post    = get_post($post_id);
    if (!$post || $post->post_type !== 'page') {
        return new WP_Error('phoenix_mcp_not_found', 'No page with that ID.');
    }
    if (!current_user_can('delete_post', $post_id)) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot delete pages.');
    }
    if (!wp_trash_post($post_id)) {
        return new WP_Error('phoenix_mcp_trash_failed', 'Could not trash the page.');
    }
    return array(
        'id'      => $post_id,
        'title'   => $post->post_title,
        'status'  => get_post_status($post_id),
        'trashed' => true,
    );
}

function phoenix_mcp_coupon_summary($coupon)
{
    $expires = $coupon->get_date_expires();
    return array(
        'id'             => $coupon->get_id(),
        'code'           => $coupon->get_code(),
        'amount'         => $coupon->get_amount(),
        'discount_type'  => $coupon->get_discount_type(),
        'description'    => $coupon->get_description(),
        'status'         => get_post_status($coupon->get_id()),
        'date_expires'   => $expires ? $expires->date('Y-m-d H:i:s') : null,
        'date_expires_timezone' => $expires ? $expires->getTimezone()->getName() : null,
        'minimum_amount' => $coupon->get_minimum_amount(),
        'maximum_amount' => $coupon->get_maximum_amount(),
        'individual_use' => $coupon->get_individual_use(),
        'product_ids'    => $coupon->get_product_ids(),
        'excluded_product_ids' => $coupon->get_excluded_product_ids(),
        'product_categories' => $coupon->get_product_categories(),
        'usage_limit'    => $coupon->get_usage_limit(),
        'usage_count'    => $coupon->get_usage_count(),
    );
}

function phoenix_mcp_tool_wc_list_coupons($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!empty($args['code'])) {
        $code = sanitize_text_field($args['code']);
        $id = wc_get_coupon_id_by_code($code);
        if (!$id) {
            return array();
        }
        $coupon = new WC_Coupon($id);
        return array(phoenix_mcp_coupon_summary($coupon));
    }
    $per_page = isset($args['per_page']) ? max(1, min(100, (int) $args['per_page'])) : 50;
    $posts = get_posts(array(
        'post_type'      => 'shop_coupon',
        'post_status'    => 'any',
        'posts_per_page' => $per_page,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ));
    $out = array();
    foreach ($posts as $post) {
        $out[] = phoenix_mcp_coupon_summary(new WC_Coupon($post->ID));
    }
    return $out;
}

function phoenix_mcp_tool_wc_create_coupon($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('edit_shop_coupons') && !current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot create coupons.');
    }
    $code = isset($args['code']) ? sanitize_text_field($args['code']) : '';
    if ($code === '' || !isset($args['amount'])) {
        return new WP_Error('phoenix_mcp_bad_args', 'code and amount are required.');
    }
    if (wc_get_coupon_id_by_code($code)) {
        return new WP_Error('phoenix_mcp_exists', 'A coupon with code "' . $code . '" already exists.');
    }
    $type = isset($args['discount_type']) ? sanitize_key($args['discount_type']) : 'percent';
    if (!in_array($type, array('percent', 'fixed_cart', 'fixed_product'), true)) {
        return new WP_Error('phoenix_mcp_bad_args', 'discount_type must be percent, fixed_cart, or fixed_product.');
    }
    $coupon = new WC_Coupon();
    $coupon->set_code($code);
    $coupon->set_discount_type($type);
    $coupon->set_amount(wc_format_decimal($args['amount']));
    $coupon->set_individual_use(false);
    if (isset($args['description'])) {
        $coupon->set_description(sanitize_text_field($args['description']));
    }
    if (!empty($args['minimum_amount'])) {
        $coupon->set_minimum_amount(wc_format_decimal($args['minimum_amount']));
    }
    if (!empty($args['date_expires'])) {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', sanitize_text_field($args['date_expires']), wp_timezone());
        if (!$dt) {
            return new WP_Error('phoenix_mcp_bad_args', 'date_expires must be Y-m-d H:i:s in the site timezone.');
        }
        $coupon->set_date_expires($dt->getTimestamp());
    }
    $coupon->save();
    if (!$coupon->get_id()) {
        return new WP_Error('phoenix_mcp_create_failed', 'Coupon save did not return an ID.');
    }
    return phoenix_mcp_coupon_summary(new WC_Coupon($coupon->get_id()));
}

function phoenix_mcp_tool_wc_set_sale_coupon($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('edit_shop_coupons') && !current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage coupons.');
    }
    if (!isset($args['code']) || !is_string($args['code'])) {
        return new WP_Error('phoenix_mcp_bad_args', 'code is required (use an empty string for no current sale).');
    }
    $input = trim(sanitize_text_field($args['code']));
    if ($input === '') {
        // Empty string explicitly means: no sale is active right now.
        update_option(PHOENIX_MCP_SALE_COUPON_CODE_OPTION, '');
        return array(
            'option'          => PHOENIX_MCP_SALE_COUPON_CODE_OPTION,
            'code'            => '',
            'sale_designated' => false,
            'coupon'          => null,
        );
    }
    $id = wc_get_coupon_id_by_code($input);
    if (!$id) {
        $id = wc_get_coupon_id_by_code(strtolower($input));
    }
    if (!$id) {
        return new WP_Error('phoenix_mcp_no_coupon', 'No WooCommerce coupon exists with code "' . $input . '".');
    }
    $coupon = new WC_Coupon($id);
    // Store the coupon's canonical code, exactly as WooCommerce has it.
    update_option(PHOENIX_MCP_SALE_COUPON_CODE_OPTION, $coupon->get_code());
    $stored = get_option(PHOENIX_MCP_SALE_COUPON_CODE_OPTION, '');
    return array(
        'option'          => PHOENIX_MCP_SALE_COUPON_CODE_OPTION,
        'code'            => is_string($stored) ? $stored : '',
        'sale_designated' => $stored !== '' && $stored !== false,
        'coupon'          => phoenix_mcp_coupon_summary(new WC_Coupon($id)),
    );
}

function phoenix_mcp_tool_wc_set_terms_page($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage WooCommerce settings.');
    }
    if (!isset($args['page_id']) || !is_numeric($args['page_id'])) {
        return new WP_Error('phoenix_mcp_bad_args', 'page_id is required.');
    }
    $page_id = (int) $args['page_id'];
    $page = get_post($page_id);
    if (!$page || $page->post_type !== 'page') {
        return new WP_Error('phoenix_mcp_no_page', 'No WordPress page exists with ID ' . $page_id . '.');
    }
    if ($page->post_status !== 'publish') {
        return new WP_Error('phoenix_mcp_page_not_published', 'Page ' . $page_id . ' is not published (status: ' . $page->post_status . ').');
    }
    update_option(PHOENIX_MCP_TERMS_PAGE_OPTION, $page_id);
    $stored = (int) get_option(PHOENIX_MCP_TERMS_PAGE_OPTION, 0);
    if ($stored !== $page_id) {
        return new WP_Error('phoenix_mcp_terms_verify_failed', 'woocommerce_terms_page_id did not read back as ' . $page_id . ' (got ' . $stored . ').');
    }
    return array(
        'option'  => PHOENIX_MCP_TERMS_PAGE_OPTION,
        'page_id' => $stored,
        'page'    => array(
            'id'     => $page->ID,
            'title'  => $page->post_title,
            'slug'   => $page->post_name,
            'status' => $page->post_status,
            'url'    => get_permalink($page->ID),
        ),
    );
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

function phoenix_mcp_tool_wc_create_api_key($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage WooCommerce API keys.');
    }

    global $wpdb;
    $consumer_key    = 'ck_' . wc_rand_hash();
    $consumer_secret = 'cs_' . wc_rand_hash();

    $inserted = $wpdb->insert(
        $wpdb->prefix . 'woocommerce_api_keys',
        array(
            'user_id'         => get_current_user_id(),
            'description'     => isset($args['description']) ? sanitize_text_field($args['description']) : 'Printify (via Phoenix)',
            'permissions'     => 'read_write',
            'consumer_key'    => wc_api_hash($consumer_key),
            'consumer_secret' => $consumer_secret,
            'truncated_key'   => substr($consumer_key, -7),
        ),
        array('%d', '%s', '%s', '%s', '%s', '%s')
    );

    if (!$inserted) {
        return new WP_Error('phoenix_mcp_db', 'Could not create the API key: ' . $wpdb->last_error);
    }

    $key_id = (int) $wpdb->insert_id;

    // Stage the one-time payload server-side so the delivery can happen in a
    // later request, after this request's database changes have synced.
    update_option('phoenix_mcp_pending_keys', array(
        'key_id'          => $key_id,
        'consumer_key'    => $consumer_key,
        'consumer_secret' => $consumer_secret,
    ), false);

    return array(
        'key_id'        => $key_id,
        'truncated_key' => substr($consumer_key, -7),
        'note'          => 'Key created and staged. Call wc_deliver_keys with the Printify callback URL and user ID.',
    );
}

function phoenix_mcp_tool_wc_deliver_keys($args)
{
    $pending = get_option('phoenix_mcp_pending_keys');
    if (!is_array($pending) || empty($pending['consumer_key'])) {
        return new WP_Error('phoenix_mcp_no_pending', 'No staged key. Run wc_create_api_key first.');
    }

    $callback_url = esc_url_raw($args['callback_url']);
    $payload = array(
        'user_id'         => (string) $args['user_id'],
        'consumer_key'    => $pending['consumer_key'],
        'consumer_secret' => $pending['consumer_secret'],
        'key_permissions' => 'read_write',
    );

    $start    = microtime(true);
    $response = wp_remote_post($callback_url, array(
        'timeout' => 30,
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode($payload),
    ));
    $elapsed = round(microtime(true) - $start, 2);

    if (is_wp_error($response)) {
        return array(
            'ok'           => false,
            'error'        => $response->get_error_message(),
            'time_seconds' => $elapsed,
            'key_id'       => $pending['key_id'],
        );
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $ok   = $code >= 200 && $code < 300;

    if ($ok) {
        delete_option('phoenix_mcp_pending_keys');
    }

    return array(
        'ok'           => $ok,
        'status_code'  => $code,
        'time_seconds' => $elapsed,
        'body'         => substr($body, 0, 500),
        'key_id'       => $pending['key_id'],
    );
}

function phoenix_mcp_tool_http_test($args)
{
    $url = isset($args['url']) ? esc_url_raw($args['url']) : 'https://api-prod.printify.com/v1/shops.json';
    if (strpos($url, 'https://') !== 0) {
        return new WP_Error('phoenix_mcp_bad_url', 'Only https:// URLs are allowed.');
    }
    $method = isset($args['method']) && strtoupper($args['method']) === 'GET' ? 'GET' : 'POST';

    $request_args = array(
        'timeout' => 20,
        'method'  => $method,
        'headers' => array('Content-Type' => 'application/json'),
    );
    if ($method === 'POST') {
        $request_args['body'] = wp_json_encode(array('phoenix_test' => true));
    }

    $start    = microtime(true);
    $response = wp_remote_request($url, $request_args);
    $elapsed  = round(microtime(true) - $start, 2);

    if (is_wp_error($response)) {
        return array(
            'url'          => $url,
            'method'       => $method,
            'ok'           => false,
            'error'        => $response->get_error_message(),
            'time_seconds' => $elapsed,
        );
    }

    return array(
        'url'           => $url,
        'method'        => $method,
        'ok'            => true,
        'status_code'   => wp_remote_retrieve_response_code($response),
        'time_seconds'  => $elapsed,
        'body_snippet'  => substr(wp_remote_retrieve_body($response), 0, 200),
    );
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
