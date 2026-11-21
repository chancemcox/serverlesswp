<?php
/**
 * Plugin Name: Phoenix MCP
 * Description: A small, self-contained MCP (Model Context Protocol) server for this site, built for Phoenix. Exposes site, content and WooCommerce tools over a single REST endpoint, authenticated by a Bearer token (stored as a SHA-256 hash, revocable in Settings -> Phoenix MCP).
 * Version: 1.4.2
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
                'serverInfo'      => array('name' => 'phoenix-mcp', 'version' => '1.4.2'),
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
            'description' => 'Update a WooCommerce product: name, slug, SKU, regular price, sale price, stock quantity, stock status, status, description, short description, categories (replaces the category list), tags, featured flag, and/or catalog visibility.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'id'                => array('type' => 'integer'),
                    'name'              => array('type' => 'string', 'description' => 'Product name/title.'),
                    'slug'              => array('type' => 'string', 'description' => 'URL slug.'),
                    'sku'               => array('type' => 'string'),
                    'regular_price'     => array('type' => 'string'),
                    'sale_price'        => array('type' => 'string'),
                    'stock_quantity'    => array('type' => 'integer'),
                    'stock_status'      => array('type' => 'string', 'description' => 'instock, outofstock, or onbackorder.'),
                    'manage_stock'      => array('type' => 'boolean'),
                    'status'            => array('type' => 'string', 'description' => 'publish, draft, private, or pending.'),
                    'description'       => array('type' => 'string', 'description' => 'Full product description (HTML allowed).'),
                    'short_description' => array('type' => 'string', 'description' => 'Short product description (HTML allowed).'),
                    'categories'        => array(
                        'type'        => 'array',
                        'items'       => array('type' => 'integer'),
                        'description' => 'Product category IDs; replaces the current categories.',
                    ),
                    'tag_ids'           => array(
                        'type'        => 'array',
                        'items'       => array('type' => 'integer'),
                        'description' => 'Product tag IDs; replaces the current tags.',
                    ),
                    'featured'          => array('type' => 'boolean'),
                    'catalog_visibility' => array('type' => 'string', 'description' => 'visible, catalog, search, or hidden.'),
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
        // --- Products: create / trash / delete / variations (1.4.0) ---
        'wc_create_product' => array(
            'description' => 'Create a simple WooCommerce product. Defaults to draft. Use wc_rebuild_variable_product afterwards if it needs variations, and wc_set_product_images for images.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'name'              => array('type' => 'string'),
                    'status'            => array('type' => 'string', 'description' => 'publish, draft, private, or pending. Default draft.'),
                    'regular_price'     => array('type' => 'string'),
                    'sale_price'        => array('type' => 'string'),
                    'sku'               => array('type' => 'string'),
                    'description'       => array('type' => 'string', 'description' => 'Full description (HTML allowed).'),
                    'short_description' => array('type' => 'string', 'description' => 'Short description (HTML allowed).'),
                    'categories'        => array('type' => 'array', 'items' => array('type' => 'integer'), 'description' => 'Product category IDs.'),
                    'tag_ids'           => array('type' => 'array', 'items' => array('type' => 'integer'), 'description' => 'Product tag IDs.'),
                    'stock_quantity'    => array('type' => 'integer', 'description' => 'If given, stock management is enabled with this quantity.'),
                    'featured'          => array('type' => 'boolean'),
                ),
                'required' => array('name'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_create_product',
        ),
        'wc_trash_product' => array(
            'description' => 'Move a WooCommerce product to the trash by product ID (recoverable).',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array('id' => array('type' => 'integer')),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_trash_product',
        ),
        'wc_delete_product' => array(
            'description' => 'PERMANENTLY delete a WooCommerce product by ID, including its variations. This cannot be undone.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array('id' => array('type' => 'integer')),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_delete_product',
        ),
        'wc_list_variations' => array(
            'description' => 'List the variations of a variable WooCommerce product with price, stock, status, SKU and attributes.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array('id' => array('type' => 'integer', 'description' => 'Parent product ID.')),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_list_variations',
        ),
        'wc_update_variation' => array(
            'description' => 'Update a single product variation: regular price, sale price, stock quantity, stock status, status, SKU, and/or description.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'id'             => array('type' => 'integer', 'description' => 'Variation ID.'),
                    'regular_price'  => array('type' => 'string'),
                    'sale_price'     => array('type' => 'string'),
                    'stock_quantity' => array('type' => 'integer'),
                    'stock_status'   => array('type' => 'string', 'description' => 'instock, outofstock, or onbackorder.'),
                    'status'         => array('type' => 'string', 'description' => 'publish (enabled) or private (disabled).'),
                    'sku'            => array('type' => 'string'),
                    'description'    => array('type' => 'string'),
                ),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_update_variation',
        ),
        // --- Product tags (1.4.0) ---
        'wc_list_tags' => array(
            'description' => 'List WooCommerce product tags (including empty ones), paginated.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'per_page' => array('type' => 'integer', 'description' => 'How many to return (1-100). Default 100.'),
                    'page'     => array('type' => 'integer', 'description' => 'Page number. Default 1.'),
                ),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_list_tags',
        ),
        'wc_create_tag' => array(
            'description' => 'Create a WooCommerce product tag.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'name'        => array('type' => 'string'),
                    'slug'        => array('type' => 'string'),
                    'description' => array('type' => 'string'),
                ),
                'required' => array('name', 'slug'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_create_tag',
        ),
        'wc_update_tag' => array(
            'description' => 'Update a WooCommerce product tag: name, slug, and/or description.',
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
            'callback'    => 'phoenix_mcp_tool_wc_update_tag',
        ),
        'wc_delete_tag' => array(
            'description' => 'Delete a WooCommerce product tag by ID. Products keep existing; only the tag is removed.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array('id' => array('type' => 'integer')),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_delete_tag',
        ),
        'wc_delete_category' => array(
            'description' => 'Delete a WooCommerce product category by ID. Products in it are not deleted.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array('id' => array('type' => 'integer')),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_delete_category',
        ),
        // --- Product attributes (global pa_* taxonomies) (1.4.0) ---
        'wc_list_attributes' => array(
            'description' => 'List global WooCommerce product attributes (name, slug, term count).',
            'inputSchema' => $empty_schema,
            'callback'    => 'phoenix_mcp_tool_wc_list_attributes',
        ),
        'wc_create_attribute' => array(
            'description' => 'Create a global WooCommerce product attribute (creates its pa_* taxonomy).',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'name' => array('type' => 'string'),
                    'slug' => array('type' => 'string', 'description' => 'Slug without the pa_ prefix. Max 28 chars.'),
                ),
                'required' => array('name', 'slug'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_create_attribute',
        ),
        'wc_update_attribute' => array(
            'description' => 'Rename a global WooCommerce product attribute (name and/or slug).',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'id'   => array('type' => 'integer', 'description' => 'Attribute ID (not a term ID).'),
                    'name' => array('type' => 'string'),
                    'slug' => array('type' => 'string'),
                ),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_update_attribute',
        ),
        'wc_delete_attribute' => array(
            'description' => 'Delete a global WooCommerce product attribute and its taxonomy by attribute ID.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array('id' => array('type' => 'integer')),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_delete_attribute',
        ),
        'wc_list_attribute_terms' => array(
            'description' => 'List the terms of one global product attribute, by attribute slug (without pa_ prefix).',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array('attribute' => array('type' => 'string')),
                'required' => array('attribute'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_list_attribute_terms',
        ),
        'wc_create_attribute_term' => array(
            'description' => 'Add a term to a global product attribute.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'attribute' => array('type' => 'string', 'description' => 'Attribute slug without pa_ prefix.'),
                    'name'      => array('type' => 'string'),
                    'slug'      => array('type' => 'string'),
                ),
                'required' => array('attribute', 'name'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_create_attribute_term',
        ),
        'wc_delete_attribute_term' => array(
            'description' => 'Delete a term from a global product attribute by term ID.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'attribute' => array('type' => 'string', 'description' => 'Attribute slug without pa_ prefix.'),
                    'id'        => array('type' => 'integer', 'description' => 'Term ID.'),
                ),
                'required' => array('attribute', 'id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_delete_attribute_term',
        ),
        // --- Coupons: update / delete (1.4.0) ---
        'wc_update_coupon' => array(
            'description' => 'Update a WooCommerce coupon by ID: amount, discount type, expiry, min/max spend, individual use, product/category restrictions, usage limit, description, and/or status (publish, draft).',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'id'                          => array('type' => 'integer'),
                    'amount'                      => array('type' => 'string'),
                    'discount_type'               => array('type' => 'string', 'description' => 'percent, fixed_cart, or fixed_product.'),
                    'date_expires'                => array('type' => 'string', 'description' => 'Y-m-d H:i:s in the site timezone, or empty string to clear.'),
                    'minimum_amount'              => array('type' => 'string'),
                    'maximum_amount'              => array('type' => 'string'),
                    'individual_use'              => array('type' => 'boolean'),
                    'product_ids'                 => array('type' => 'array', 'items' => array('type' => 'integer')),
                    'excluded_product_ids'        => array('type' => 'array', 'items' => array('type' => 'integer')),
                    'product_categories'          => array('type' => 'array', 'items' => array('type' => 'integer')),
                    'excluded_product_categories' => array('type' => 'array', 'items' => array('type' => 'integer')),
                    'usage_limit'                 => array('type' => 'integer', 'description' => '0 means unlimited.'),
                    'description'                 => array('type' => 'string'),
                    'status'                      => array('type' => 'string', 'description' => 'publish or draft.'),
                ),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_update_coupon',
        ),
        'wc_delete_coupon' => array(
            'description' => 'PERMANENTLY delete a WooCommerce coupon by ID. This cannot be undone.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array('id' => array('type' => 'integer')),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_delete_coupon',
        ),
        // --- Orders: detail / update / notes / refund (1.4.0) ---
        'wc_get_order' => array(
            'description' => 'Get one WooCommerce order in full: status, line items, billing/shipping addresses, payment method, totals breakdown, customer note, and order notes.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array('id' => array('type' => 'integer')),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_get_order',
        ),
        'wc_update_order' => array(
            'description' => 'Update a WooCommerce order: status (e.g. processing, completed, on-hold, cancelled, refunded, pending, failed) and/or the customer note. Changing status can trigger WooCommerce status emails, as in wp-admin.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'id'            => array('type' => 'integer'),
                    'status'        => array('type' => 'string'),
                    'customer_note' => array('type' => 'string'),
                ),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_update_order',
        ),
        'wc_add_order_note' => array(
            'description' => 'Add a note to a WooCommerce order. Private by default; set customer_note true for a note emailed/shown to the customer.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'id'            => array('type' => 'integer', 'description' => 'Order ID.'),
                    'note'          => array('type' => 'string'),
                    'customer_note' => array('type' => 'boolean', 'description' => 'Default false (private note).'),
                ),
                'required' => array('id', 'note'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_add_order_note',
        ),
        'wc_create_refund' => array(
            'description' => 'Create a refund on a WooCommerce order. By default this is a manual refund record only (refund_payment=false): no money moves through the payment gateway. Pass line_items to refund specific items; omit for a full-amount manual refund of the given amount.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'id'             => array('type' => 'integer', 'description' => 'Order ID.'),
                    'amount'         => array('type' => 'string', 'description' => 'Refund amount, e.g. "10.00".'),
                    'reason'         => array('type' => 'string'),
                    'refund_payment' => array('type' => 'boolean', 'description' => 'Default false. True attempts a gateway refund (real money movement).'),
                    'line_items'     => array(
                        'type' => 'array',
                        'description' => 'Optional: [{id: order item id, qty: n, refund_total: "x.xx"}].',
                        'items' => array(
                            'type' => 'object',
                            'properties' => array(
                                'id'           => array('type' => 'integer'),
                                'qty'          => array('type' => 'integer'),
                                'refund_total' => array('type' => 'string'),
                            ),
                            'required' => array('id'),
                        ),
                    ),
                ),
                'required' => array('id', 'amount'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_create_refund',
        ),
        // --- Posts & pages: get / update post / permanent delete (1.4.0) ---
        'get_post' => array(
            'description' => 'Get one blog post in full by ID, including content.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array('id' => array('type' => 'integer')),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_get_post',
        ),
        'get_page' => array(
            'description' => 'Get one page in full by ID, including content.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array('id' => array('type' => 'integer')),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_get_page',
        ),
        'update_post' => array(
            'description' => 'Update an existing blog post: title, content, status (publish, draft, private), and/or slug.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'id'      => array('type' => 'integer'),
                    'title'   => array('type' => 'string'),
                    'content' => array('type' => 'string'),
                    'status'  => array('type' => 'string', 'description' => 'publish, draft, or private.'),
                    'slug'    => array('type' => 'string'),
                ),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_update_post',
        ),
        'delete_post' => array(
            'description' => 'PERMANENTLY delete a blog post by ID (skips the trash). This cannot be undone.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array('id' => array('type' => 'integer')),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_delete_post',
        ),
        'delete_page' => array(
            'description' => 'PERMANENTLY delete a page by ID (skips the trash). This cannot be undone.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array('id' => array('type' => 'integer')),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_delete_page',
        ),
        // --- Media library (1.4.0) ---
        'list_media' => array(
            'description' => 'List media library attachments (newest first), optionally filtered by a search term or mime type (e.g. image).',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'per_page' => array('type' => 'integer', 'description' => 'How many to return (1-50). Default 20.'),
                    'search'   => array('type' => 'string'),
                    'mime'     => array('type' => 'string', 'description' => 'Mime filter, e.g. "image" or "image/png".'),
                ),
            ),
            'callback'    => 'phoenix_mcp_tool_list_media',
        ),
        'upload_media' => array(
            'description' => 'Download a file from a public URL into the media library as a standalone attachment. Files flow through WP Offload Media to durable storage. Optionally set title, alt text and caption.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'url'     => array('type' => 'string'),
                    'title'   => array('type' => 'string'),
                    'alt'     => array('type' => 'string'),
                    'caption' => array('type' => 'string'),
                ),
                'required' => array('url'),
            ),
            'callback'    => 'phoenix_mcp_tool_upload_media',
        ),
        'update_media' => array(
            'description' => 'Update a media attachment: title, alt text, caption, and/or description.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'id'          => array('type' => 'integer'),
                    'title'       => array('type' => 'string'),
                    'alt'         => array('type' => 'string'),
                    'caption'     => array('type' => 'string'),
                    'description' => array('type' => 'string'),
                ),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_update_media',
        ),
        'delete_media' => array(
            'description' => 'PERMANENTLY delete a media attachment by ID, including its file. This cannot be undone.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array('id' => array('type' => 'integer')),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_delete_media',
        ),
        // --- Plugins: deactivate (1.4.0) ---
        'deactivate_plugin' => array(
            'description' => 'Deactivate an installed WordPress plugin by plugin file (e.g. "hello-dolly/hello.php") or folder slug. Refuses to deactivate Phoenix MCP itself, which would cut off this connection.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'plugin' => array('type' => 'string', 'description' => 'Plugin file (slug/slug.php) or folder slug of an installed plugin.'),
                ),
                'required' => array('plugin'),
            ),
            'callback'    => 'phoenix_mcp_tool_deactivate_plugin',
        ),
        // --- Options (1.4.0) ---
        'get_option' => array(
            'description' => 'Read a WordPress option by name (most WooCommerce and site settings live in options). The Phoenix MCP token hash is never returned.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array('name' => array('type' => 'string')),
                'required' => array('name'),
            ),
            'callback'    => 'phoenix_mcp_tool_get_option',
        ),
        'set_option' => array(
            'description' => 'Write a WordPress option. Blocked for values that would break the site or lock Phoenix out: active_plugins, siteurl, home, template, stylesheet, and the phoenix_mcp_* token/staging options.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'name'  => array('type' => 'string'),
                    'value' => array('description' => 'String, number, boolean, array, or object value to store.'),
                ),
                'required' => array('name', 'value'),
            ),
            'callback'    => 'phoenix_mcp_tool_set_option',
        ),
        // --- Menus (1.4.0) ---
        'list_menus' => array(
            'description' => 'List navigation menus with their items and the theme locations each menu is assigned to.',
            'inputSchema' => $empty_schema,
            'callback'    => 'phoenix_mcp_tool_list_menus',
        ),
        'create_menu' => array(
            'description' => 'Create an empty navigation menu by name.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array('name' => array('type' => 'string')),
                'required' => array('name'),
            ),
            'callback'    => 'phoenix_mcp_tool_create_menu',
        ),
        'update_menu' => array(
            'description' => 'Replace all items of a navigation menu. Each item: {title, url} for a custom link, or {title, object_id, object_type} for a post/page/category link (object_type e.g. page, post, product_cat). Optional parent (index of the parent item in this list) and order.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'id'    => array('type' => 'integer', 'description' => 'Menu ID.'),
                    'items' => array(
                        'type' => 'array',
                        'items' => array(
                            'type' => 'object',
                            'properties' => array(
                                'title'       => array('type' => 'string'),
                                'url'         => array('type' => 'string'),
                                'object_id'   => array('type' => 'integer'),
                                'object_type' => array('type' => 'string'),
                                'parent'      => array('type' => 'integer', 'description' => 'Index within this items list of the parent item.'),
                            ),
                            'required' => array('title'),
                        ),
                    ),
                ),
                'required' => array('id', 'items'),
            ),
            'callback'    => 'phoenix_mcp_tool_update_menu',
        ),
        'delete_menu' => array(
            'description' => 'Delete a navigation menu by ID.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array('id' => array('type' => 'integer')),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_delete_menu',
        ),
        'set_menu_location' => array(
            'description' => 'Assign a menu to a theme location (e.g. primary), or unassign with menu_id 0.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'location' => array('type' => 'string'),
                    'menu_id'  => array('type' => 'integer'),
                ),
                'required' => array('location', 'menu_id'),
            ),
            'callback'    => 'phoenix_mcp_tool_set_menu_location',
        ),
        // --- Shipping zones & methods (1.4.0) ---
        'wc_list_shipping_zones' => array(
            'description' => 'List WooCommerce shipping zones with their locations and shipping methods (instance id, method, title, enabled, settings).',
            'inputSchema' => $empty_schema,
            'callback'    => 'phoenix_mcp_tool_wc_list_shipping_zones',
        ),
        'wc_create_shipping_zone' => array(
            'description' => 'Create a WooCommerce shipping zone. Locations: [{code, type}] where type is country, state, postcode, or continent.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'name'      => array('type' => 'string'),
                    'locations' => array(
                        'type' => 'array',
                        'items' => array(
                            'type' => 'object',
                            'properties' => array(
                                'code' => array('type' => 'string'),
                                'type' => array('type' => 'string'),
                            ),
                            'required' => array('code', 'type'),
                        ),
                    ),
                ),
                'required' => array('name'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_create_shipping_zone',
        ),
        'wc_update_shipping_zone' => array(
            'description' => 'Update a shipping zone: name and/or locations (locations replace the current list). Zone 0 (locations not covered by other zones) cannot take locations.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'id'        => array('type' => 'integer', 'description' => 'Zone ID.'),
                    'name'      => array('type' => 'string'),
                    'locations' => array(
                        'type' => 'array',
                        'items' => array(
                            'type' => 'object',
                            'properties' => array(
                                'code' => array('type' => 'string'),
                                'type' => array('type' => 'string'),
                            ),
                            'required' => array('code', 'type'),
                        ),
                    ),
                ),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_update_shipping_zone',
        ),
        'wc_delete_shipping_zone' => array(
            'description' => 'Delete a WooCommerce shipping zone by ID (zone 0 cannot be deleted). Its methods are removed with it.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array('id' => array('type' => 'integer')),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_delete_shipping_zone',
        ),
        'wc_add_shipping_method' => array(
            'description' => 'Add a shipping method (flat_rate, free_shipping, local_pickup) to a zone, optionally with initial settings such as title and cost.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'zone_id'   => array('type' => 'integer'),
                    'method_id' => array('type' => 'string'),
                    'settings'  => array('type' => 'object', 'description' => 'Optional instance settings, e.g. {title: "Flat rate", cost: "5.00"}.'),
                ),
                'required' => array('zone_id', 'method_id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_add_shipping_method',
        ),
        'wc_update_shipping_method' => array(
            'description' => 'Update a shipping method instance in a zone: title, enabled (yes/no), and method settings (e.g. cost for flat_rate).',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'zone_id'     => array('type' => 'integer'),
                    'instance_id' => array('type' => 'integer'),
                    'title'       => array('type' => 'string'),
                    'enabled'     => array('type' => 'string', 'description' => 'yes or no.'),
                    'settings'    => array('type' => 'object', 'description' => 'Settings to merge, e.g. {cost: "4.50"}.'),
                ),
                'required' => array('zone_id', 'instance_id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_update_shipping_method',
        ),
        'wc_delete_shipping_method' => array(
            'description' => 'Remove a shipping method instance from a zone.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'zone_id'     => array('type' => 'integer'),
                    'instance_id' => array('type' => 'integer'),
                ),
                'required' => array('zone_id', 'instance_id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_delete_shipping_method',
        ),
        // --- Tax rates (1.4.0) ---
        'wc_list_tax_rates' => array(
            'description' => 'List WooCommerce tax rates (all classes).',
            'inputSchema' => $empty_schema,
            'callback'    => 'phoenix_mcp_tool_wc_list_tax_rates',
        ),
        'wc_create_tax_rate' => array(
            'description' => 'Create a WooCommerce tax rate. Country/state/postcode/city default to wildcards (all). Rate is a percent string like "8.25".',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'rate'     => array('type' => 'string'),
                    'name'     => array('type' => 'string'),
                    'country'  => array('type' => 'string'),
                    'state'    => array('type' => 'string'),
                    'postcode' => array('type' => 'string'),
                    'city'     => array('type' => 'string'),
                    'priority' => array('type' => 'integer'),
                    'compound' => array('type' => 'boolean'),
                    'shipping' => array('type' => 'boolean', 'description' => 'Apply to shipping too. Default true.'),
                    'class'    => array('type' => 'string', 'description' => 'Tax class slug. Default standard.'),
                ),
                'required' => array('rate'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_create_tax_rate',
        ),
        'wc_update_tax_rate' => array(
            'description' => 'Update a WooCommerce tax rate by rate ID (same fields as create).',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'id'       => array('type' => 'integer'),
                    'rate'     => array('type' => 'string'),
                    'name'     => array('type' => 'string'),
                    'country'  => array('type' => 'string'),
                    'state'    => array('type' => 'string'),
                    'postcode' => array('type' => 'string'),
                    'city'     => array('type' => 'string'),
                    'priority' => array('type' => 'integer'),
                    'compound' => array('type' => 'boolean'),
                    'shipping' => array('type' => 'boolean'),
                    'class'    => array('type' => 'string'),
                ),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_update_tax_rate',
        ),
        'wc_delete_tax_rate' => array(
            'description' => 'Delete a WooCommerce tax rate by rate ID.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array('id' => array('type' => 'integer')),
                'required' => array('id'),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_delete_tax_rate',
        ),
        // --- Customers, gateways, comments, site (1.4.0) ---
        'wc_list_customers' => array(
            'description' => 'List store customers (WordPress users with the customer role) with order counts and total spent.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'per_page' => array('type' => 'integer', 'description' => 'How many to return (1-50). Default 20.'),
                    'search'   => array('type' => 'string'),
                ),
            ),
            'callback'    => 'phoenix_mcp_tool_wc_list_customers',
        ),
        'wc_list_payment_gateways' => array(
            'description' => 'List registered WooCommerce payment gateways with enabled status (e.g. to verify Stripe is on).',
            'inputSchema' => $empty_schema,
            'callback'    => 'phoenix_mcp_tool_wc_list_payment_gateways',
        ),
        'list_comments' => array(
            'description' => 'List comments for moderation (newest first). Status: approve (approved), hold (pending), spam, trash, or all. Optionally filter by post ID.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'status'   => array('type' => 'string', 'description' => 'Default hold (pending).'),
                    'post_id'  => array('type' => 'integer'),
                    'per_page' => array('type' => 'integer', 'description' => 'How many to return (1-50). Default 20.'),
                ),
            ),
            'callback'    => 'phoenix_mcp_tool_list_comments',
        ),
        'moderate_comment' => array(
            'description' => 'Moderate a comment: action approve, hold, spam, unspam, trash, untrash, or delete (permanent).',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'id'     => array('type' => 'integer'),
                    'action' => array('type' => 'string'),
                ),
                'required' => array('id', 'action'),
            ),
            'callback'    => 'phoenix_mcp_tool_moderate_comment',
        ),
        'update_site_settings' => array(
            'description' => 'Update core site settings (only the fields given): title, tagline, timezone, date_format, time_format, posts_per_page, permalink_structure, show_on_front (posts or page), page_on_front, page_for_posts. Returns the resulting values. A permalink change flushes rewrite rules.',
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'title'               => array('type' => 'string'),
                    'tagline'             => array('type' => 'string'),
                    'timezone'            => array('type' => 'string', 'description' => 'Timezone string, e.g. America/Chicago or UTC.'),
                    'date_format'         => array('type' => 'string'),
                    'time_format'         => array('type' => 'string'),
                    'posts_per_page'      => array('type' => 'integer'),
                    'permalink_structure' => array('type' => 'string', 'description' => 'e.g. /%postname%/.'),
                    'show_on_front'       => array('type' => 'string', 'description' => 'posts or page.'),
                    'page_on_front'       => array('type' => 'integer'),
                    'page_for_posts'      => array('type' => 'integer'),
                ),
            ),
            'callback'    => 'phoenix_mcp_tool_update_site_settings',
        ),
        'flush_rules' => array(
            'description' => 'Flush WordPress rewrite rules and the object cache. Safe housekeeping after permalink, menu, or post-type changes.',
            'inputSchema' => $empty_schema,
            'callback'    => 'phoenix_mcp_tool_flush_rules',
        ),
        'wc_system_status' => array(
            'description' => 'WooCommerce system status summary: versions, product/order counts, active gateways, shipping zones, tax classes, and store currency/base location.',
            'inputSchema' => $empty_schema,
            'callback'    => 'phoenix_mcp_tool_wc_system_status',
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
        'id'                 => $product->get_id(),
        'name'               => $product->get_name(),
        'slug'               => $product->get_slug(),
        'type'               => $product->get_type(),
        'status'             => $product->get_status(),
        'regular_price'      => $product->get_regular_price(),
        'sale_price'         => $product->get_sale_price(),
        'price'              => $product->get_price(),
        'stock_quantity'     => $product->get_stock_quantity(),
        'stock_status'       => $product->get_stock_status(),
        'sku'                => $product->get_sku(),
        'featured'           => $product->get_featured(),
        'catalog_visibility' => $product->get_catalog_visibility(),
        'tag_ids'            => $product->get_tag_ids(),
        'category_ids'       => $product->get_category_ids(),
        'link'               => get_permalink($product->get_id()),
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
    if (isset($args['name'])) {
        $product->set_name(sanitize_text_field($args['name']));
    }
    if (isset($args['slug'])) {
        $product->set_slug(sanitize_title($args['slug']));
    }
    if (isset($args['sku'])) {
        $product->set_sku(sanitize_text_field($args['sku']));
    }
    if (isset($args['regular_price'])) {
        $product->set_regular_price(wc_format_decimal($args['regular_price']));
    }
    if (isset($args['sale_price'])) {
        $product->set_sale_price($args['sale_price'] === '' ? '' : wc_format_decimal($args['sale_price']));
    }
    if (isset($args['manage_stock'])) {
        $product->set_manage_stock((bool) $args['manage_stock']);
    }
    if (isset($args['stock_quantity'])) {
        $product->set_manage_stock(true);
        $product->set_stock_quantity((int) $args['stock_quantity']);
    }
    if (isset($args['stock_status']) && in_array($args['stock_status'], array('instock', 'outofstock', 'onbackorder'), true)) {
        $product->set_stock_status($args['stock_status']);
    }
    if (isset($args['status']) && in_array($args['status'], array('publish', 'draft', 'private', 'pending'), true)) {
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
    if (isset($args['tag_ids']) && is_array($args['tag_ids'])) {
        $product->set_tag_ids(array_map('intval', $args['tag_ids']));
    }
    if (isset($args['featured'])) {
        $product->set_featured((bool) $args['featured']);
    }
    if (isset($args['catalog_visibility']) && in_array($args['catalog_visibility'], array('visible', 'catalog', 'search', 'hidden'), true)) {
        $product->set_catalog_visibility($args['catalog_visibility']);
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

// --- Products: create / trash / delete / variations ---

function phoenix_mcp_tool_wc_create_product($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('create_products') && !current_user_can('edit_products')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot create products.');
    }
    $name = isset($args['name']) ? sanitize_text_field($args['name']) : '';
    if ($name === '') {
        return new WP_Error('phoenix_mcp_bad_args', 'name is required.');
    }
    $status = isset($args['status']) ? sanitize_key($args['status']) : 'draft';
    if (!in_array($status, array('publish', 'draft', 'private', 'pending'), true)) {
        return new WP_Error('phoenix_mcp_bad_status', 'Status must be publish, draft, private, or pending.');
    }
    if ($status === 'publish' && !current_user_can('publish_products')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot publish products.');
    }
    $product = new WC_Product_Simple();
    $product->set_name($name);
    $product->set_status($status);
    if (isset($args['regular_price'])) {
        $product->set_regular_price(wc_format_decimal($args['regular_price']));
    }
    if (isset($args['sale_price'])) {
        $product->set_sale_price($args['sale_price'] === '' ? '' : wc_format_decimal($args['sale_price']));
    }
    if (isset($args['sku'])) {
        $product->set_sku(sanitize_text_field($args['sku']));
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
    if (isset($args['tag_ids']) && is_array($args['tag_ids'])) {
        $product->set_tag_ids(array_map('intval', $args['tag_ids']));
    }
    if (isset($args['stock_quantity'])) {
        $product->set_manage_stock(true);
        $product->set_stock_quantity((int) $args['stock_quantity']);
    }
    if (isset($args['featured'])) {
        $product->set_featured((bool) $args['featured']);
    }
    $id = $product->save();
    if (!$id) {
        return new WP_Error('phoenix_mcp_create_failed', 'Product save did not return an ID.');
    }
    return phoenix_mcp_product_summary(wc_get_product($id));
}

function phoenix_mcp_tool_wc_trash_product($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    $product_id = (int) $args['id'];
    $product    = wc_get_product($product_id);
    if (!$product) {
        return new WP_Error('phoenix_mcp_not_found', 'No product with that ID.');
    }
    if (!current_user_can('delete_post', $product_id)) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot delete products.');
    }
    if (!wp_trash_post($product_id)) {
        return new WP_Error('phoenix_mcp_trash_failed', 'Could not trash the product.');
    }
    return array(
        'id'      => $product_id,
        'name'    => $product->get_name(),
        'status'  => get_post_status($product_id),
        'trashed' => true,
    );
}

function phoenix_mcp_tool_wc_delete_product($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    $product_id = (int) $args['id'];
    $product    = wc_get_product($product_id);
    if (!$product) {
        return new WP_Error('phoenix_mcp_not_found', 'No product with that ID.');
    }
    if (!current_user_can('delete_post', $product_id)) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot delete products.');
    }
    $name = $product->get_name();
    // WC_Product::delete(true) removes the product and its variations.
    if (!$product->delete(true)) {
        return new WP_Error('phoenix_mcp_delete_failed', 'Could not delete the product.');
    }
    if (wc_get_product($product_id)) {
        return new WP_Error('phoenix_mcp_delete_failed', 'Product still exists after delete.');
    }
    return array('id' => $product_id, 'name' => $name, 'deleted' => true);
}

function phoenix_mcp_variation_summary($variation)
{
    return array(
        'id'             => $variation->get_id(),
        'parent_id'      => $variation->get_parent_id(),
        'attributes'     => $variation->get_attributes(),
        'regular_price'  => $variation->get_regular_price(),
        'sale_price'     => $variation->get_sale_price(),
        'price'          => $variation->get_price(),
        'stock_quantity' => $variation->get_stock_quantity(),
        'stock_status'   => $variation->get_stock_status(),
        'status'         => $variation->get_status(),
        'sku'            => $variation->get_sku(),
    );
}

function phoenix_mcp_tool_wc_list_variations($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    $product = wc_get_product((int) $args['id']);
    if (!$product) {
        return new WP_Error('phoenix_mcp_not_found', 'No product with that ID.');
    }
    if (!$product->is_type('variable')) {
        return new WP_Error('phoenix_mcp_not_variable', 'Product is not a variable product.');
    }
    $out = array();
    foreach ($product->get_children() as $child_id) {
        $variation = wc_get_product($child_id);
        if ($variation) {
            $out[] = phoenix_mcp_variation_summary($variation);
        }
    }
    return $out;
}

function phoenix_mcp_tool_wc_update_variation($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('edit_products')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot edit products.');
    }
    $variation = wc_get_product((int) $args['id']);
    if (!$variation || !$variation->is_type('variation')) {
        return new WP_Error('phoenix_mcp_not_found', 'No variation with that ID.');
    }
    if (isset($args['regular_price'])) {
        $variation->set_regular_price(wc_format_decimal($args['regular_price']));
    }
    if (isset($args['sale_price'])) {
        $variation->set_sale_price($args['sale_price'] === '' ? '' : wc_format_decimal($args['sale_price']));
    }
    if (isset($args['stock_quantity'])) {
        $variation->set_manage_stock(true);
        $variation->set_stock_quantity((int) $args['stock_quantity']);
    }
    if (isset($args['stock_status']) && in_array($args['stock_status'], array('instock', 'outofstock', 'onbackorder'), true)) {
        $variation->set_stock_status(sanitize_key($args['stock_status']));
    }
    if (isset($args['status']) && in_array($args['status'], array('publish', 'private'), true)) {
        $variation->set_status($args['status']);
    }
    if (isset($args['sku'])) {
        $variation->set_sku(sanitize_text_field($args['sku']));
    }
    if (isset($args['description'])) {
        $variation->set_description(wp_kses_post($args['description']));
    }
    $variation->save();
    // Keep the parent's price range in sync.
    $parent = wc_get_product($variation->get_parent_id());
    if ($parent) {
        $parent->save();
    }
    return phoenix_mcp_variation_summary(wc_get_product($variation->get_id()));
}

// --- Product tags & category delete (term helpers shared) ---

function phoenix_mcp_term_summary($term)
{
    return array(
        'id'          => $term->term_id,
        'name'        => $term->name,
        'slug'        => $term->slug,
        'description' => $term->description,
        'parent'      => $term->parent,
        'count'       => $term->count,
    );
}

function phoenix_mcp_list_terms_tool($args, $taxonomy)
{
    $per_page = isset($args['per_page']) ? max(1, min(100, (int) $args['per_page'])) : 100;
    $page     = isset($args['page']) ? max(1, (int) $args['page']) : 1;
    $terms = get_terms(array(
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'number'     => $per_page,
        'offset'     => ($page - 1) * $per_page,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ));
    if (is_wp_error($terms)) {
        return $terms;
    }
    return array_map('phoenix_mcp_term_summary', $terms);
}

function phoenix_mcp_create_term_tool($args, $taxonomy)
{
    if (!current_user_can('manage_product_terms') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage product terms.');
    }
    $term_args = array('slug' => sanitize_title($args['slug']));
    if (isset($args['description'])) {
        $term_args['description'] = wp_kses_post($args['description']);
    }
    $result = wp_insert_term(sanitize_text_field($args['name']), $taxonomy, $term_args);
    if (is_wp_error($result)) {
        return $result;
    }
    return phoenix_mcp_term_summary(get_term((int) $result['term_id'], $taxonomy));
}

function phoenix_mcp_update_term_tool($args, $taxonomy)
{
    if (!current_user_can('manage_product_terms') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage product terms.');
    }
    $term_id = (int) $args['id'];
    $term    = get_term($term_id, $taxonomy);
    if (!$term || is_wp_error($term)) {
        return new WP_Error('phoenix_mcp_not_found', 'No term with that ID in ' . $taxonomy . '.');
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
    $result = wp_update_term($term_id, $taxonomy, $update);
    if (is_wp_error($result)) {
        return $result;
    }
    return phoenix_mcp_term_summary(get_term($term_id, $taxonomy));
}

function phoenix_mcp_delete_term_tool($args, $taxonomy)
{
    if (!current_user_can('manage_product_terms') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage product terms.');
    }
    $term_id = (int) $args['id'];
    $term    = get_term($term_id, $taxonomy);
    if (!$term || is_wp_error($term)) {
        return new WP_Error('phoenix_mcp_not_found', 'No term with that ID in ' . $taxonomy . '.');
    }
    $name   = $term->name;
    $result = wp_delete_term($term_id, $taxonomy);
    if (is_wp_error($result) || !$result) {
        return new WP_Error('phoenix_mcp_delete_failed', 'Could not delete the term.');
    }
    return array('id' => $term_id, 'name' => $name, 'deleted' => true);
}

function phoenix_mcp_tool_wc_list_tags($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    return phoenix_mcp_list_terms_tool($args, 'product_tag');
}

function phoenix_mcp_tool_wc_create_tag($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    return phoenix_mcp_create_term_tool($args, 'product_tag');
}

function phoenix_mcp_tool_wc_update_tag($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    return phoenix_mcp_update_term_tool($args, 'product_tag');
}

function phoenix_mcp_tool_wc_delete_tag($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    return phoenix_mcp_delete_term_tool($args, 'product_tag');
}

function phoenix_mcp_tool_wc_delete_category($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    return phoenix_mcp_delete_term_tool($args, 'product_cat');
}

// --- Product attributes ---

function phoenix_mcp_attribute_summary($attribute)
{
    // Accepts both shapes: wc_get_attribute_taxonomies() items
    // (attribute_id/attribute_label/...) and wc_get_attribute() results
    // (id/name/slug/...).
    $id       = isset($attribute->attribute_id) ? (int) $attribute->attribute_id : (int) $attribute->id;
    $taxonomy = wc_attribute_taxonomy_name_by_id($id);
    $count    = $taxonomy ? wp_count_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false)) : 0;
    return array(
        'id'        => $id,
        'name'      => isset($attribute->attribute_label) ? $attribute->attribute_label : $attribute->name,
        'slug'      => isset($attribute->attribute_name) ? $attribute->attribute_name : $attribute->slug,
        'taxonomy'  => $taxonomy,
        'type'      => isset($attribute->attribute_type) ? $attribute->attribute_type : $attribute->type,
        'order_by'  => isset($attribute->attribute_orderby) ? $attribute->attribute_orderby : $attribute->order_by,
        'terms'     => is_wp_error($count) ? 0 : (int) $count,
    );
}

function phoenix_mcp_attribute_by_id($attribute_id)
{
    foreach (wc_get_attribute_taxonomies() as $attribute) {
        if ((int) $attribute->attribute_id === (int) $attribute_id) {
            return $attribute;
        }
    }
    return null;
}

function phoenix_mcp_tool_wc_list_attributes($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    $out = array();
    foreach (wc_get_attribute_taxonomies() as $attribute) {
        $out[] = phoenix_mcp_attribute_summary($attribute);
    }
    return $out;
}

function phoenix_mcp_tool_wc_create_attribute($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('manage_product_terms') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage product attributes.');
    }
    $id = wc_create_attribute(array(
        'name'         => sanitize_text_field($args['name']),
        'slug'         => sanitize_title($args['slug']),
        'type'         => 'select',
        'order_by'     => 'menu_order',
        'has_archives' => false,
    ));
    if (is_wp_error($id)) {
        return $id;
    }
    // Register the taxonomy right away so terms can be added in this process too.
    if (function_exists('wc_register_attribute_taxonomy')) {
        wc_register_attribute_taxonomy();
    }
    $attribute = phoenix_mcp_attribute_by_id($id);
    return $attribute ? phoenix_mcp_attribute_summary($attribute) : array('id' => (int) $id, 'created' => true);
}

function phoenix_mcp_tool_wc_update_attribute($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('manage_product_terms') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage product attributes.');
    }
    $id        = (int) $args['id'];
    $attribute = wc_get_attribute($id);
    if (!$attribute) {
        return new WP_Error('phoenix_mcp_not_found', 'No attribute with that ID.');
    }
    $result = wc_update_attribute($id, array(
        'name'         => isset($args['name']) ? sanitize_text_field($args['name']) : $attribute->name,
        'slug'         => isset($args['slug']) ? sanitize_title($args['slug']) : $attribute->slug,
        'type'         => $attribute->type,
        'order_by'     => $attribute->order_by,
        'has_archives' => $attribute->has_archives,
    ));
    if (is_wp_error($result)) {
        return $result;
    }
    $fresh = phoenix_mcp_attribute_by_id($id);
    return $fresh ? phoenix_mcp_attribute_summary($fresh) : array('id' => $id, 'updated' => true);
}

function phoenix_mcp_tool_wc_delete_attribute($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('manage_product_terms') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage product attributes.');
    }
    $id = (int) $args['id'];
    if (!phoenix_mcp_attribute_by_id($id)) {
        return new WP_Error('phoenix_mcp_not_found', 'No attribute with that ID.');
    }
    if (!wc_delete_attribute($id)) {
        return new WP_Error('phoenix_mcp_delete_failed', 'Could not delete the attribute.');
    }
    return array('id' => $id, 'deleted' => true);
}

function phoenix_mcp_attribute_taxonomy($args)
{
    $slug = isset($args['attribute']) ? sanitize_title($args['attribute']) : '';
    if ($slug === '') {
        return new WP_Error('phoenix_mcp_bad_args', 'attribute slug is required.');
    }
    $taxonomy = wc_attribute_taxonomy_name($slug);
    if (!taxonomy_exists($taxonomy)) {
        return new WP_Error('phoenix_mcp_not_found', 'No attribute with slug "' . $slug . '".');
    }
    return $taxonomy;
}

function phoenix_mcp_tool_wc_list_attribute_terms($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    $taxonomy = phoenix_mcp_attribute_taxonomy($args);
    if (is_wp_error($taxonomy)) {
        return $taxonomy;
    }
    return phoenix_mcp_list_terms_tool(array('per_page' => 100), $taxonomy);
}

function phoenix_mcp_tool_wc_create_attribute_term($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    $taxonomy = phoenix_mcp_attribute_taxonomy($args);
    if (is_wp_error($taxonomy)) {
        return $taxonomy;
    }
    $slug = isset($args['slug']) && $args['slug'] !== '' ? sanitize_title($args['slug']) : sanitize_title($args['name']);
    return phoenix_mcp_create_term_tool(array(
        'name' => $args['name'],
        'slug' => $slug,
    ), $taxonomy);
}

function phoenix_mcp_tool_wc_delete_attribute_term($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    $taxonomy = phoenix_mcp_attribute_taxonomy($args);
    if (is_wp_error($taxonomy)) {
        return $taxonomy;
    }
    return phoenix_mcp_delete_term_tool($args, $taxonomy);
}
// --- Coupons: update / delete ---

function phoenix_mcp_tool_wc_update_coupon($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('edit_shop_coupons') && !current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot edit coupons.');
    }
    $coupon_id = (int) $args['id'];
    $coupon    = new WC_Coupon($coupon_id);
    if (!$coupon->get_id() || get_post_type($coupon_id) !== 'shop_coupon') {
        return new WP_Error('phoenix_mcp_not_found', 'No coupon with that ID.');
    }
    if (isset($args['amount'])) {
        $coupon->set_amount(wc_format_decimal($args['amount']));
    }
    if (isset($args['discount_type'])) {
        $type = sanitize_key($args['discount_type']);
        if (!in_array($type, array('percent', 'fixed_cart', 'fixed_product'), true)) {
            return new WP_Error('phoenix_mcp_bad_args', 'discount_type must be percent, fixed_cart, or fixed_product.');
        }
        $coupon->set_discount_type($type);
    }
    if (isset($args['date_expires'])) {
        if ($args['date_expires'] === '' || $args['date_expires'] === null) {
            $coupon->set_date_expires(null);
        } else {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', sanitize_text_field($args['date_expires']), wp_timezone());
            if (!$dt) {
                return new WP_Error('phoenix_mcp_bad_args', 'date_expires must be Y-m-d H:i:s in the site timezone, or empty to clear.');
            }
            $coupon->set_date_expires($dt->getTimestamp());
        }
    }
    if (isset($args['minimum_amount'])) {
        $coupon->set_minimum_amount($args['minimum_amount'] === '' ? '' : wc_format_decimal($args['minimum_amount']));
    }
    if (isset($args['maximum_amount'])) {
        $coupon->set_maximum_amount($args['maximum_amount'] === '' ? '' : wc_format_decimal($args['maximum_amount']));
    }
    if (isset($args['individual_use'])) {
        $coupon->set_individual_use((bool) $args['individual_use']);
    }
    if (isset($args['product_ids']) && is_array($args['product_ids'])) {
        $coupon->set_product_ids(array_map('intval', $args['product_ids']));
    }
    if (isset($args['excluded_product_ids']) && is_array($args['excluded_product_ids'])) {
        $coupon->set_excluded_product_ids(array_map('intval', $args['excluded_product_ids']));
    }
    if (isset($args['product_categories']) && is_array($args['product_categories'])) {
        $coupon->set_product_categories(array_map('intval', $args['product_categories']));
    }
    if (isset($args['excluded_product_categories']) && is_array($args['excluded_product_categories'])) {
        $coupon->set_excluded_product_categories(array_map('intval', $args['excluded_product_categories']));
    }
    if (isset($args['usage_limit'])) {
        $coupon->set_usage_limit((int) $args['usage_limit']);
    }
    if (isset($args['description'])) {
        $coupon->set_description(sanitize_text_field($args['description']));
    }
    if (isset($args['status']) && in_array($args['status'], array('publish', 'draft'), true)) {
        $coupon->set_status($args['status']);
    }
    $coupon->save();
    return phoenix_mcp_coupon_summary(new WC_Coupon($coupon_id));
}

function phoenix_mcp_tool_wc_delete_coupon($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('delete_shop_coupons') && !current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot delete coupons.');
    }
    $coupon_id = (int) $args['id'];
    if (get_post_type($coupon_id) !== 'shop_coupon') {
        return new WP_Error('phoenix_mcp_not_found', 'No coupon with that ID.');
    }
    $code = get_the_title($coupon_id);
    if (!wp_delete_post($coupon_id, true)) {
        return new WP_Error('phoenix_mcp_delete_failed', 'Could not delete the coupon.');
    }
    return array('id' => $coupon_id, 'code' => $code, 'deleted' => true);
}

// --- Orders ---

function phoenix_mcp_order_cap()
{
    return current_user_can('edit_shop_orders') || current_user_can('manage_woocommerce') || current_user_can('manage_options');
}

function phoenix_mcp_tool_wc_get_order($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!phoenix_mcp_order_cap()) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot view orders.');
    }
    $order = wc_get_order((int) $args['id']);
    if (!$order) {
        return new WP_Error('phoenix_mcp_not_found', 'No order with that ID.');
    }
    $items = array();
    foreach ($order->get_items() as $item_id => $item) {
        $items[] = array(
            'item_id'    => $item_id,
            'name'       => $item->get_name(),
            'product_id' => $item->get_product_id(),
            'variation_id' => $item->get_variation_id(),
            'quantity'   => $item->get_quantity(),
            'subtotal'   => $item->get_subtotal(),
            'total'      => $item->get_total(),
        );
    }
    $notes = array();
    foreach (wc_get_order_notes(array('order_id' => $order->get_id(), 'orderby' => 'date_created', 'order' => 'ASC')) as $note) {
        $notes[] = array(
            'id'            => (int) $note->comment_ID,
            'author'        => $note->comment_author,
            'date'          => $note->comment_date,
            'content'       => $note->comment_content,
            'customer_note' => (bool) get_comment_meta($note->comment_ID, 'is_customer_note', true),
        );
    }
    $format_address = function ($address) {
        return array_filter((array) $address, function ($v) { return $v !== '' && $v !== null; });
    };
    return array(
        'id'               => $order->get_id(),
        'order_number'     => $order->get_order_number(),
        'status'           => $order->get_status(),
        'currency'         => $order->get_currency(),
        'date_created'     => $order->get_date_created() ? $order->get_date_created()->date('c') : null,
        'date_modified'    => $order->get_date_modified() ? $order->get_date_modified()->date('c') : null,
        'date_paid'        => $order->get_date_paid() ? $order->get_date_paid()->date('c') : null,
        'payment_method'   => $order->get_payment_method(),
        'payment_method_title' => $order->get_payment_method_title(),
        'transaction_id'   => $order->get_transaction_id(),
        'customer_id'      => $order->get_customer_id(),
        'customer_note'    => $order->get_customer_note(),
        'billing'          => $format_address($order->get_address('billing')),
        'shipping'         => $format_address($order->get_address('shipping')),
        'items'            => $items,
        'subtotal'         => $order->get_subtotal(),
        'shipping_total'   => $order->get_shipping_total(),
        'discount_total'   => $order->get_discount_total(),
        'tax_total'        => $order->get_total_tax(),
        'total'            => $order->get_total(),
        'total_refunded'   => $order->get_total_refunded(),
        'notes'            => $notes,
    );
}

function phoenix_mcp_tool_wc_update_order($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!phoenix_mcp_order_cap()) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot edit orders.');
    }
    $order = wc_get_order((int) $args['id']);
    if (!$order) {
        return new WP_Error('phoenix_mcp_not_found', 'No order with that ID.');
    }
    if (isset($args['customer_note'])) {
        $order->set_customer_note(wp_kses_post($args['customer_note']));
        $order->save();
    }
    if (isset($args['status'])) {
        $status = sanitize_key($args['status']);
        $valid  = array_keys(wc_get_order_statuses());
        $valid  = array_map(function ($s) { return preg_replace('/^wc-/', '', $s); }, $valid);
        if (!in_array($status, $valid, true)) {
            return new WP_Error('phoenix_mcp_bad_status', 'Unknown order status "' . $status . '".');
        }
        $order->set_status($status);
        $order->save();
    }
    $fresh = wc_get_order($order->get_id());
    return array(
        'id'            => $fresh->get_id(),
        'status'        => $fresh->get_status(),
        'customer_note' => $fresh->get_customer_note(),
        'total'         => $fresh->get_total(),
    );
}

function phoenix_mcp_tool_wc_add_order_note($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!phoenix_mcp_order_cap()) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot edit orders.');
    }
    $order = wc_get_order((int) $args['id']);
    if (!$order) {
        return new WP_Error('phoenix_mcp_not_found', 'No order with that ID.');
    }
    $note = isset($args['note']) ? wp_kses_post($args['note']) : '';
    if ($note === '') {
        return new WP_Error('phoenix_mcp_bad_args', 'note is required.');
    }
    $note_id = $order->add_order_note($note, !empty($args['customer_note']) ? 1 : 0, true);
    if (!$note_id) {
        return new WP_Error('phoenix_mcp_note_failed', 'Could not add the note.');
    }
    return array('order_id' => $order->get_id(), 'note_id' => (int) $note_id, 'added' => true);
}

function phoenix_mcp_tool_wc_create_refund($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!phoenix_mcp_order_cap()) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot refund orders.');
    }
    $order = wc_get_order((int) $args['id']);
    if (!$order) {
        return new WP_Error('phoenix_mcp_not_found', 'No order with that ID.');
    }
    $refund_args = array(
        'amount'         => wc_format_decimal($args['amount']),
        'reason'         => isset($args['reason']) ? sanitize_text_field($args['reason']) : '',
        // Default false: manual refund record only, no gateway money movement.
        'refund_payment' => !empty($args['refund_payment']),
    );
    if (!empty($args['line_items']) && is_array($args['line_items'])) {
        $line_items = array();
        foreach ($args['line_items'] as $li) {
            if (!isset($li['id'])) {
                continue;
            }
            $line_items[(int) $li['id']] = array(
                'qty'          => isset($li['qty']) ? (int) $li['qty'] : 0,
                'refund_total' => isset($li['refund_total']) ? wc_format_decimal($li['refund_total']) : 0,
            );
        }
        $refund_args['line_items'] = $line_items;
    }
    $refund = wc_create_refund($refund_args + array('order_id' => $order->get_id()));
    if (is_wp_error($refund)) {
        return $refund;
    }
    return array(
        'order_id'       => $order->get_id(),
        'refund_id'      => $refund->get_id(),
        'amount'         => $refund->get_amount(),
        'reason'         => $refund->get_reason(),
        'refund_payment' => $refund_args['refund_payment'],
        'total_refunded' => wc_get_order($order->get_id())->get_total_refunded(),
    );
}
// --- Posts & pages: get / update post / permanent delete ---

function phoenix_mcp_full_post_summary($post)
{
    return array(
        'id'      => $post->ID,
        'title'   => $post->post_title,
        'slug'    => $post->post_name,
        'status'  => $post->post_status,
        'type'    => $post->post_type,
        'date'    => $post->post_date,
        'link'    => get_permalink($post),
        'content' => $post->post_content,
        'excerpt' => $post->post_excerpt,
    );
}

function phoenix_mcp_get_typed_post($args, $type)
{
    $post = get_post((int) $args['id']);
    if (!$post || $post->post_type !== $type) {
        return new WP_Error('phoenix_mcp_not_found', 'No ' . $type . ' with that ID.');
    }
    return phoenix_mcp_full_post_summary($post);
}

function phoenix_mcp_tool_get_post($args)
{
    return phoenix_mcp_get_typed_post($args, 'post');
}

function phoenix_mcp_tool_get_page($args)
{
    return phoenix_mcp_get_typed_post($args, 'page');
}

function phoenix_mcp_tool_update_post($args)
{
    $post_id = (int) $args['id'];
    $post    = get_post($post_id);
    if (!$post || $post->post_type !== 'post') {
        return new WP_Error('phoenix_mcp_not_found', 'No blog post with that ID.');
    }
    if (!current_user_can('edit_post', $post_id)) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot edit posts.');
    }
    $update = array('ID' => $post_id);
    if (isset($args['title'])) {
        $update['post_title'] = sanitize_text_field($args['title']);
    }
    if (isset($args['content'])) {
        $update['post_content'] = wp_kses_post($args['content']);
    }
    if (isset($args['slug'])) {
        $update['post_name'] = sanitize_title($args['slug']);
    }
    if (isset($args['status'])) {
        $status = sanitize_key($args['status']);
        if (!in_array($status, array('publish', 'draft', 'private'), true)) {
            return new WP_Error('phoenix_mcp_bad_status', 'Status must be publish, draft, or private.');
        }
        if ($status === 'publish' && !current_user_can('publish_posts')) {
            return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot publish posts.');
        }
        $update['post_status'] = $status;
    }
    $result = wp_update_post($update, true);
    if (is_wp_error($result)) {
        return $result;
    }
    return phoenix_mcp_post_summary(get_post($post_id));
}

function phoenix_mcp_delete_typed_post($args, $type, $label)
{
    $post_id = (int) $args['id'];
    $post    = get_post($post_id);
    if (!$post || $post->post_type !== $type) {
        return new WP_Error('phoenix_mcp_not_found', 'No ' . $label . ' with that ID.');
    }
    if (!current_user_can('delete_post', $post_id)) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot delete this ' . $label . '.');
    }
    $title = $post->post_title;
    if (!wp_delete_post($post_id, true)) {
        return new WP_Error('phoenix_mcp_delete_failed', 'Could not delete the ' . $label . '.');
    }
    return array('id' => $post_id, 'title' => $title, 'deleted' => true);
}

function phoenix_mcp_tool_delete_post($args)
{
    return phoenix_mcp_delete_typed_post($args, 'post', 'blog post');
}

function phoenix_mcp_tool_delete_page($args)
{
    return phoenix_mcp_delete_typed_post($args, 'page', 'page');
}

// --- Media library ---

function phoenix_mcp_media_summary($post)
{
    return array(
        'id'       => $post->ID,
        'title'    => $post->post_title,
        'url'      => wp_get_attachment_url($post->ID),
        'mime'     => $post->post_mime_type,
        'alt'      => get_post_meta($post->ID, '_wp_attachment_image_alt', true),
        'caption'  => $post->post_excerpt,
        'date'     => $post->post_date,
        'parent'   => (int) $post->post_parent,
    );
}

function phoenix_mcp_tool_list_media($args)
{
    if (!current_user_can('upload_files')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot access the media library.');
    }
    $query = array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => isset($args['per_page']) ? max(1, min(50, (int) $args['per_page'])) : 20,
        'orderby'        => 'date',
        'order'          => 'DESC',
    );
    if (!empty($args['search'])) {
        $query['s'] = sanitize_text_field($args['search']);
    }
    if (!empty($args['mime'])) {
        $query['post_mime_type'] = sanitize_mime_type($args['mime']) ?: sanitize_text_field($args['mime']);
    }
    return array_map('phoenix_mcp_media_summary', get_posts($query));
}

function phoenix_mcp_tool_upload_media($args)
{
    if (!current_user_can('upload_files')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot upload files.');
    }
    $url = isset($args['url']) ? esc_url_raw($args['url']) : '';
    if ($url === '') {
        return new WP_Error('phoenix_mcp_bad_args', 'url is required.');
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $name = basename(parse_url($url, PHP_URL_PATH));
    if ($name === '' || strpos($name, '.') === false) {
        $name = 'upload-' . gmdate('Ymd-His') . '.jpg';
    }
    $tmp = download_url($url, 60);
    if (is_wp_error($tmp)) {
        return new WP_Error('phoenix_mcp_upload_failed', 'Download failed: ' . $tmp->get_error_message());
    }
    $file   = array('name' => sanitize_file_name($name), 'tmp_name' => $tmp);
    $att_id = media_handle_sideload($file, 0);
    if (is_wp_error($att_id)) {
        return new WP_Error('phoenix_mcp_upload_failed', 'Sideload failed: ' . $att_id->get_error_message());
    }
    $update = array('ID' => $att_id);
    if (isset($args['title'])) {
        $update['post_title'] = sanitize_text_field($args['title']);
    }
    if (isset($args['caption'])) {
        $update['post_excerpt'] = sanitize_text_field($args['caption']);
    }
    if (count($update) > 1) {
        wp_update_post($update);
    }
    if (isset($args['alt'])) {
        update_post_meta($att_id, '_wp_attachment_image_alt', sanitize_text_field($args['alt']));
    }
    return phoenix_mcp_media_summary(get_post($att_id));
}

function phoenix_mcp_tool_update_media($args)
{
    $att_id = (int) $args['id'];
    $post   = get_post($att_id);
    if (!$post || $post->post_type !== 'attachment') {
        return new WP_Error('phoenix_mcp_not_found', 'No media attachment with that ID.');
    }
    if (!current_user_can('edit_post', $att_id)) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot edit media.');
    }
    $update = array('ID' => $att_id);
    if (isset($args['title'])) {
        $update['post_title'] = sanitize_text_field($args['title']);
    }
    if (isset($args['caption'])) {
        $update['post_excerpt'] = sanitize_text_field($args['caption']);
    }
    if (isset($args['description'])) {
        $update['post_content'] = wp_kses_post($args['description']);
    }
    if (count($update) > 1) {
        $result = wp_update_post($update, true);
        if (is_wp_error($result)) {
            return $result;
        }
    }
    if (isset($args['alt'])) {
        update_post_meta($att_id, '_wp_attachment_image_alt', sanitize_text_field($args['alt']));
    }
    return phoenix_mcp_media_summary(get_post($att_id));
}

function phoenix_mcp_tool_delete_media($args)
{
    $att_id = (int) $args['id'];
    $post   = get_post($att_id);
    if (!$post || $post->post_type !== 'attachment') {
        return new WP_Error('phoenix_mcp_not_found', 'No media attachment with that ID.');
    }
    if (!current_user_can('delete_post', $att_id)) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot delete media.');
    }
    $title = $post->post_title;
    $url   = wp_get_attachment_url($att_id);
    if (!wp_delete_attachment($att_id, true)) {
        return new WP_Error('phoenix_mcp_delete_failed', 'Could not delete the attachment.');
    }
    return array('id' => $att_id, 'title' => $title, 'url' => $url, 'deleted' => true);
}

// --- Plugins: deactivate ---

function phoenix_mcp_resolve_plugin_file($input)
{
    if (!is_string($input) || $input === '') {
        return new WP_Error('phoenix_mcp_bad_args', 'plugin is required: a plugin file (slug/slug.php) or a folder slug.');
    }
    $plugin = sanitize_text_field($input);
    if (strpos($plugin, '..') !== false || strpos($plugin, '\\') !== false || $plugin[0] === '/') {
        return new WP_Error('phoenix_mcp_bad_args', 'Invalid plugin path.');
    }
    if (substr($plugin, -4) !== '.php') {
        $slug = trim($plugin, '/');
        if (!preg_match('#^[a-z0-9\-_]+$#i', $slug)) {
            return new WP_Error('phoenix_mcp_bad_args', 'Invalid plugin slug.');
        }
        $plugin = $slug . '/' . $slug . '.php';
    }
    return $plugin;
}

function phoenix_mcp_tool_deactivate_plugin($args)
{
    if (!current_user_can('deactivate_plugins') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot deactivate plugins.');
    }
    $plugin = phoenix_mcp_resolve_plugin_file(isset($args['plugin']) ? $args['plugin'] : '');
    if (is_wp_error($plugin)) {
        return $plugin;
    }
    if ($plugin === 'phoenix-mcp/phoenix-mcp.php') {
        return new WP_Error('phoenix_mcp_self_protected', 'Refusing to deactivate Phoenix MCP: it would cut off this connection. Use wp-admin if you really mean to.');
    }
    if (!function_exists('get_plugins') || !function_exists('deactivate_plugins')) {
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
    if (!is_plugin_active($plugin)) {
        return array_merge($summary, array('active' => false, 'already_inactive' => true));
    }
    deactivate_plugins($plugin);
    if (is_plugin_active($plugin)) {
        return new WP_Error('phoenix_mcp_deactivation_failed', 'deactivate_plugins() ran but "' . $plugin . '" still reports active.');
    }
    return array_merge($summary, array('active' => false, 'already_inactive' => false));
}

// --- Options ---

function phoenix_mcp_option_denied($name, $for_write)
{
    // Values that would break the site or cut off Phoenix's own access.
    $denied = array(
        'active_plugins',
        'siteurl',
        'home',
        'template',
        'stylesheet',
        PHOENIX_MCP_HASH_OPTION,
        PHOENIX_MCP_USER_OPTION,
        'phoenix_mcp_pending_keys',
    );
    if (in_array($name, $denied, true)) {
        return $for_write
            ? 'Option "' . $name . '" is protected and cannot be written through Phoenix MCP.'
            : 'Option "' . $name . '" is protected and cannot be read through Phoenix MCP.';
    }
    return null;
}

function phoenix_mcp_tool_get_option($args)
{
    if (!current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage options.');
    }
    $name = isset($args['name']) ? sanitize_key($args['name']) : '';
    if ($name === '') {
        return new WP_Error('phoenix_mcp_bad_args', 'name is required.');
    }
    if ($denied = phoenix_mcp_option_denied($name, false)) {
        return new WP_Error('phoenix_mcp_protected_option', $denied);
    }
    $exists = get_option($name, "\0phoenix_missing\0");
    if ($exists === "\0phoenix_missing\0") {
        return array('name' => $name, 'exists' => false, 'value' => null);
    }
    return array('name' => $name, 'exists' => true, 'value' => $exists);
}

function phoenix_mcp_tool_set_option($args)
{
    if (!current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage options.');
    }
    $name = isset($args['name']) ? sanitize_key($args['name']) : '';
    if ($name === '' || !array_key_exists('value', $args)) {
        return new WP_Error('phoenix_mcp_bad_args', 'name and value are required.');
    }
    if ($denied = phoenix_mcp_option_denied($name, true)) {
        return new WP_Error('phoenix_mcp_protected_option', $denied);
    }
    update_option($name, $args['value']);
    $stored = get_option($name);
    return array('name' => $name, 'value' => $stored, 'updated' => true);
}

// --- Menus ---

function phoenix_mcp_tool_list_menus($args)
{
    if (!current_user_can('edit_theme_options') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage menus.');
    }
    $locations = get_theme_mod('nav_menu_locations');
    $locations = is_array($locations) ? $locations : array();
    $out = array();
    foreach (wp_get_nav_menus() as $menu) {
        $items = array();
        foreach ((array) wp_get_nav_menu_items($menu->term_id) as $item) {
            $items[] = array(
                'id'         => (int) $item->ID,
                'title'      => $item->title,
                'url'        => $item->url,
                'type'       => $item->type,
                'object'     => $item->object,
                'object_id'  => (int) $item->object_id,
                'parent'     => (int) $item->menu_item_parent,
                'order'      => (int) $item->menu_order,
            );
        }
        $assigned = array();
        foreach ($locations as $loc => $menu_id) {
            if ((int) $menu_id === (int) $menu->term_id) {
                $assigned[] = $loc;
            }
        }
        $out[] = array(
            'id'        => (int) $menu->term_id,
            'name'      => $menu->name,
            'slug'      => $menu->slug,
            'count'     => (int) $menu->count,
            'locations' => $assigned,
            'items'     => $items,
        );
    }
    return array('menus' => $out, 'registered_locations' => array_keys(get_registered_nav_menus()));
}

function phoenix_mcp_tool_create_menu($args)
{
    if (!current_user_can('edit_theme_options') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage menus.');
    }
    $name = isset($args['name']) ? sanitize_text_field($args['name']) : '';
    if ($name === '') {
        return new WP_Error('phoenix_mcp_bad_args', 'name is required.');
    }
    $menu_id = wp_create_nav_menu($name);
    if (is_wp_error($menu_id)) {
        return $menu_id;
    }
    $menu = wp_get_nav_menu_object($menu_id);
    return array('id' => (int) $menu_id, 'name' => $menu->name, 'slug' => $menu->slug);
}

function phoenix_mcp_tool_update_menu($args)
{
    if (!current_user_can('edit_theme_options') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage menus.');
    }
    $menu_id = (int) $args['id'];
    $menu    = wp_get_nav_menu_object($menu_id);
    if (!$menu) {
        return new WP_Error('phoenix_mcp_not_found', 'No menu with that ID.');
    }
    if (!isset($args['items']) || !is_array($args['items'])) {
        return new WP_Error('phoenix_mcp_bad_args', 'items must be an array.');
    }
    // Remove existing items first: this tool replaces the whole menu.
    foreach ((array) wp_get_nav_menu_items($menu_id) as $item) {
        wp_delete_post($item->ID, true);
    }
    $created_ids = array();
    $position    = 0;
    foreach ($args['items'] as $item) {
        if (empty($item['title'])) {
            continue;
        }
        $item_args = array(
            'menu-item-title'     => sanitize_text_field($item['title']),
            'menu-item-status'    => 'publish',
            'menu-item-position'  => ++$position,
        );
        if (isset($item['parent']) && isset($created_ids[(int) $item['parent']])) {
            $item_args['menu-item-parent-id'] = $created_ids[(int) $item['parent']];
        }
        if (!empty($item['object_id']) && !empty($item['object_type'])) {
            $item_args['menu-item-type']      = 'post_type';
            $item_args['menu-item-object']    = sanitize_key($item['object_type']);
            $item_args['menu-item-object-id'] = (int) $item['object_id'];
        } else {
            $item_args['menu-item-type'] = 'custom';
            $item_args['menu-item-url']  = isset($item['url']) ? esc_url_raw($item['url']) : '#';
        }
        $new_id = wp_update_nav_menu_item($menu_id, 0, $item_args);
        if (is_wp_error($new_id)) {
            return $new_id;
        }
        $created_ids[] = (int) $new_id;
    }
    $fresh = wp_get_nav_menu_object($menu_id);
    return array(
        'id'    => $menu_id,
        'name'  => $fresh->name,
        'count' => count($created_ids),
        'items' => $created_ids,
    );
}

function phoenix_mcp_tool_delete_menu($args)
{
    if (!current_user_can('edit_theme_options') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage menus.');
    }
    $menu_id = (int) $args['id'];
    $menu    = wp_get_nav_menu_object($menu_id);
    if (!$menu) {
        return new WP_Error('phoenix_mcp_not_found', 'No menu with that ID.');
    }
    $name = $menu->name;
    $result = wp_delete_nav_menu($menu_id);
    if (is_wp_error($result) || !$result) {
        return new WP_Error('phoenix_mcp_delete_failed', 'Could not delete the menu.');
    }
    return array('id' => $menu_id, 'name' => $name, 'deleted' => true);
}

function phoenix_mcp_tool_set_menu_location($args)
{
    if (!current_user_can('edit_theme_options') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage menus.');
    }
    $location = sanitize_key($args['location']);
    $menu_id  = (int) $args['menu_id'];
    $registered = get_registered_nav_menus();
    if (!isset($registered[$location])) {
        return new WP_Error('phoenix_mcp_bad_location', 'Location "' . $location . '" is not registered. Registered: ' . implode(', ', array_keys($registered)) . '.');
    }
    if ($menu_id !== 0 && !wp_get_nav_menu_object($menu_id)) {
        return new WP_Error('phoenix_mcp_not_found', 'No menu with that ID.');
    }
    $locations = get_theme_mod('nav_menu_locations');
    $locations = is_array($locations) ? $locations : array();
    $locations[$location] = $menu_id;
    set_theme_mod('nav_menu_locations', $locations);
    $stored = get_theme_mod('nav_menu_locations');
    return array(
        'location' => $location,
        'menu_id'  => isset($stored[$location]) ? (int) $stored[$location] : null,
        'assigned' => isset($stored[$location]) && (int) $stored[$location] === $menu_id,
    );
}
// --- Shipping zones & methods ---

function phoenix_mcp_zone_summary($zone)
{
    $methods = array();
    foreach ($zone->get_shipping_methods() as $instance_id => $method) {
        $methods[] = array(
            'instance_id' => (int) $instance_id,
            'method_id'   => $method->id,
            'title'       => $method->get_title(),
            'enabled'     => $method->is_enabled(),
            'settings'    => $method->instance_settings,
        );
    }
    $locations = array();
    foreach ($zone->get_zone_locations() as $loc) {
        $locations[] = array('code' => $loc->code, 'type' => $loc->type);
    }
    return array(
        'id'        => $zone->get_id(),
        'name'      => $zone->get_zone_name(),
        'order'     => $zone->get_zone_order(),
        'locations' => $locations,
        'methods'   => $methods,
    );
}

function phoenix_mcp_tool_wc_list_shipping_zones($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    $out = array();
    foreach (WC_Shipping_Zones::get_zones() as $zone_data) {
        $zone = WC_Shipping_Zones::get_zone($zone_data['id']);
        if ($zone) {
            $out[] = phoenix_mcp_zone_summary($zone);
        }
    }
    // Zone 0: locations not covered by any other zone.
    $zone0 = WC_Shipping_Zones::get_zone(0);
    if ($zone0) {
        $out[] = phoenix_mcp_zone_summary($zone0);
    }
    return $out;
}

function phoenix_mcp_zone_locations_from_args($args)
{
    $locations = array();
    if (!empty($args['locations']) && is_array($args['locations'])) {
        foreach ($args['locations'] as $loc) {
            if (empty($loc['code']) || empty($loc['type'])) {
                continue;
            }
            $type = sanitize_key($loc['type']);
            if (!in_array($type, array('country', 'state', 'postcode', 'continent'), true)) {
                continue;
            }
            $locations[] = (object) array('code' => sanitize_text_field($loc['code']), 'type' => $type);
        }
    }
    return $locations;
}

function phoenix_mcp_tool_wc_create_shipping_zone($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage shipping.');
    }
    $name = isset($args['name']) ? sanitize_text_field($args['name']) : '';
    if ($name === '') {
        return new WP_Error('phoenix_mcp_bad_args', 'name is required.');
    }
    $zone = new WC_Shipping_Zone();
    $zone->set_zone_name($name);
    $locations = phoenix_mcp_zone_locations_from_args($args);
    if ($locations) {
        $zone->set_zone_locations($locations);
    }
    $zone->save();
    if (!$zone->get_id()) {
        return new WP_Error('phoenix_mcp_create_failed', 'Zone save did not return an ID.');
    }
    return phoenix_mcp_zone_summary(WC_Shipping_Zones::get_zone($zone->get_id()));
}

function phoenix_mcp_tool_wc_update_shipping_zone($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage shipping.');
    }
    $zone = WC_Shipping_Zones::get_zone((int) $args['id']);
    if (!$zone) {
        return new WP_Error('phoenix_mcp_not_found', 'No shipping zone with that ID.');
    }
    if (isset($args['name'])) {
        $zone->set_zone_name(sanitize_text_field($args['name']));
    }
    if (isset($args['locations'])) {
        if ($zone->get_id() === 0) {
            return new WP_Error('phoenix_mcp_bad_args', 'Zone 0 (rest of the world) cannot have locations.');
        }
        $zone->set_zone_locations(phoenix_mcp_zone_locations_from_args($args));
    }
    $zone->save();
    return phoenix_mcp_zone_summary(WC_Shipping_Zones::get_zone($zone->get_id()));
}

function phoenix_mcp_tool_wc_delete_shipping_zone($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage shipping.');
    }
    $zone_id = (int) $args['id'];
    if ($zone_id === 0) {
        return new WP_Error('phoenix_mcp_bad_args', 'Zone 0 cannot be deleted.');
    }
    $zone = WC_Shipping_Zones::get_zone($zone_id);
    if (!$zone) {
        return new WP_Error('phoenix_mcp_not_found', 'No shipping zone with that ID.');
    }
    $name = $zone->get_zone_name();
    WC_Shipping_Zones::delete_zone($zone_id);
    if (WC_Shipping_Zones::get_zone($zone_id)) {
        return new WP_Error('phoenix_mcp_delete_failed', 'Zone still exists after delete.');
    }
    return array('id' => $zone_id, 'name' => $name, 'deleted' => true);
}

function phoenix_mcp_get_zone_method($zone_id, $instance_id)
{
    $zone = WC_Shipping_Zones::get_zone($zone_id);
    if (!$zone) {
        return new WP_Error('phoenix_mcp_not_found', 'No shipping zone with that ID.');
    }
    $methods = $zone->get_shipping_methods();
    if (!isset($methods[$instance_id])) {
        return new WP_Error('phoenix_mcp_not_found', 'No shipping method instance with that ID in this zone.');
    }
    return array($zone, $methods[$instance_id]);
}

function phoenix_mcp_tool_wc_add_shipping_method($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage shipping.');
    }
    $zone = WC_Shipping_Zones::get_zone((int) $args['zone_id']);
    if (!$zone) {
        return new WP_Error('phoenix_mcp_not_found', 'No shipping zone with that ID.');
    }
    $method_id = sanitize_key($args['method_id']);
    if (!in_array($method_id, array('flat_rate', 'free_shipping', 'local_pickup'), true)) {
        return new WP_Error('phoenix_mcp_bad_args', 'method_id must be flat_rate, free_shipping, or local_pickup.');
    }
    $instance_id = $zone->add_shipping_method($method_id);
    if (!$instance_id) {
        return new WP_Error('phoenix_mcp_create_failed', 'Could not add the shipping method.');
    }
    if (!empty($args['settings']) && is_array($args['settings'])) {
        $option_key = 'woocommerce_' . $method_id . '_' . $instance_id . '_settings';
        $settings   = get_option($option_key, array());
        foreach ($args['settings'] as $k => $v) {
            $settings[sanitize_key($k)] = is_scalar($v) ? sanitize_text_field((string) $v) : $v;
        }
        update_option($option_key, $settings);
    }
    return phoenix_mcp_zone_summary(WC_Shipping_Zones::get_zone($zone->get_id()));
}

function phoenix_mcp_tool_wc_update_shipping_method($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage shipping.');
    }
    $found = phoenix_mcp_get_zone_method((int) $args['zone_id'], (int) $args['instance_id']);
    if (is_wp_error($found)) {
        return $found;
    }
    list($zone, $method) = $found;
    $option_key = 'woocommerce_' . $method->id . '_' . (int) $args['instance_id'] . '_settings';
    $settings   = get_option($option_key, array());
    if (!is_array($settings)) {
        $settings = array();
    }
    if (isset($args['title'])) {
        $settings['title'] = sanitize_text_field($args['title']);
    }
    if (isset($args['enabled']) && in_array($args['enabled'], array('yes', 'no'), true)) {
        $settings['enabled'] = $args['enabled'];
    }
    if (!empty($args['settings']) && is_array($args['settings'])) {
        foreach ($args['settings'] as $k => $v) {
            $settings[sanitize_key($k)] = is_scalar($v) ? sanitize_text_field((string) $v) : $v;
        }
    }
    update_option($option_key, $settings);
    return phoenix_mcp_zone_summary(WC_Shipping_Zones::get_zone($zone->get_id()));
}

function phoenix_mcp_tool_wc_delete_shipping_method($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage shipping.');
    }
    $found = phoenix_mcp_get_zone_method((int) $args['zone_id'], (int) $args['instance_id']);
    if (is_wp_error($found)) {
        return $found;
    }
    list($zone) = $found;
    $zone->delete_shipping_method((int) $args['instance_id']);
    $methods = $zone->get_shipping_methods();
    if (isset($methods[(int) $args['instance_id']])) {
        return new WP_Error('phoenix_mcp_delete_failed', 'Method still present after delete.');
    }
    return array('zone_id' => $zone->get_id(), 'instance_id' => (int) $args['instance_id'], 'deleted' => true);
}

// --- Tax rates ---

function phoenix_mcp_tax_rate_locations($rate_id)
{
    // Postcodes and cities live in the locations table, not the rates table.
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT location_code, location_type FROM {$wpdb->prefix}woocommerce_tax_rate_locations WHERE tax_rate_id = %d",
        $rate_id
    ));
    $found = array('postcode' => array(), 'city' => array());
    foreach ($rows ?: array() as $loc) {
        if (isset($found[$loc->location_type])) {
            $found[$loc->location_type][] = $loc->location_code;
        }
    }
    return array(
        'postcode' => implode('; ', $found['postcode']),
        'city'     => implode('; ', $found['city']),
    );
}

function phoenix_mcp_tax_rate_summary($row)
{
    $loc = phoenix_mcp_tax_rate_locations((int) $row->tax_rate_id);
    return array(
        'id'       => (int) $row->tax_rate_id,
        'country'  => $row->tax_rate_country,
        'state'    => $row->tax_rate_state,
        'postcode' => $loc['postcode'],
        'city'     => $loc['city'],
        'rate'     => $row->tax_rate,
        'name'     => $row->tax_rate_name,
        'priority' => (int) $row->tax_rate_priority,
        'compound' => (bool) $row->tax_rate_compound,
        'shipping' => (bool) $row->tax_rate_shipping,
        'class'    => $row->tax_rate_class === '' ? 'standard' : $row->tax_rate_class,
    );
}

function phoenix_mcp_tool_wc_list_tax_rates($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    global $wpdb;
    $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_order ASC");
    return array_map('phoenix_mcp_tax_rate_summary', $rows ?: array());
}

function phoenix_mcp_tax_rate_data_from_args($args, $existing = null)
{
    $get = function ($key, $field) use ($args, $existing) {
        if (isset($args[$key])) {
            return $args[$key];
        }
        return $existing ? $existing[$field] : null;
    };
    $data = array();
    $country = $get('country', 'tax_rate_country');
    if ($country !== null) $data['tax_rate_country'] = strtoupper(sanitize_text_field($country));
    $state = $get('state', 'tax_rate_state');
    if ($state !== null) $data['tax_rate_state'] = strtoupper(sanitize_text_field($state));
    $postcode = $get('postcode', 'tax_rate_postcode');
    if ($postcode !== null) $data['tax_rate_postcode'] = sanitize_text_field($postcode);
    $city = $get('city', 'tax_rate_city');
    if ($city !== null) $data['tax_rate_city'] = sanitize_text_field($city);
    $rate = $get('rate', 'tax_rate');
    if ($rate !== null) $data['tax_rate'] = wc_format_decimal($rate);
    $name = $get('name', 'tax_rate_name');
    if ($name !== null) $data['tax_rate_name'] = sanitize_text_field($name);
    $priority = $get('priority', 'tax_rate_priority');
    if ($priority !== null) $data['tax_rate_priority'] = (int) $priority;
    $compound = $get('compound', 'tax_rate_compound');
    if ($compound !== null) $data['tax_rate_compound'] = $compound ? 1 : 0;
    $shipping = $get('shipping', 'tax_rate_shipping');
    if ($shipping !== null) $data['tax_rate_shipping'] = $shipping ? 1 : 0;
    $class = $get('class', 'tax_rate_class');
    if ($class !== null) $data['tax_rate_class'] = $class === 'standard' ? '' : sanitize_title($class);
    return $data;
}

function phoenix_mcp_tool_wc_create_tax_rate($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage tax rates.');
    }
    $data = phoenix_mcp_tax_rate_data_from_args($args);
    $data = array_merge(array(
        'tax_rate_country'  => '',
        'tax_rate_state'    => '',
        'tax_rate_postcode' => '',
        'tax_rate_city'     => '',
        'tax_rate_name'     => '',
        'tax_rate_priority' => 1,
        'tax_rate_compound' => 0,
        'tax_rate_shipping' => 1,
        'tax_rate_class'    => '',
    ), $data);
    if (!method_exists('WC_Tax', '_insert_tax_rate')) {
        return new WP_Error('phoenix_mcp_unavailable', 'This WooCommerce version cannot insert tax rates programmatically.');
    }
    // Empty postcode/city would create empty location rows; omit instead.
    if (isset($data['tax_rate_postcode']) && $data['tax_rate_postcode'] === '') {
        unset($data['tax_rate_postcode']);
    }
    if (isset($data['tax_rate_city']) && $data['tax_rate_city'] === '') {
        unset($data['tax_rate_city']);
    }
    $id = WC_Tax::_insert_tax_rate($data);
    if (!$id) {
        return new WP_Error('phoenix_mcp_create_failed', 'Could not insert the tax rate.');
    }
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id = %d", $id));
    return phoenix_mcp_tax_rate_summary($row);
}

function phoenix_mcp_tool_wc_update_tax_rate($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage tax rates.');
    }
    global $wpdb;
    $id  = (int) $args['id'];
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id = %d", $id), ARRAY_A);
    if (!$row) {
        return new WP_Error('phoenix_mcp_not_found', 'No tax rate with that ID.');
    }
    $data = phoenix_mcp_tax_rate_data_from_args($args, $row);
    if (!$data) {
        return new WP_Error('phoenix_mcp_no_changes', 'Nothing to update.');
    }
    if (!method_exists('WC_Tax', '_update_tax_rate')) {
        return new WP_Error('phoenix_mcp_unavailable', 'This WooCommerce version cannot update tax rates programmatically.');
    }
    WC_Tax::_update_tax_rate($id, array_merge($row, $data));
    $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id = %d", $id));
    return phoenix_mcp_tax_rate_summary($fresh);
}

function phoenix_mcp_tool_wc_delete_tax_rate($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage tax rates.');
    }
    global $wpdb;
    $id  = (int) $args['id'];
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id = %d", $id));
    if (!$row) {
        return new WP_Error('phoenix_mcp_not_found', 'No tax rate with that ID.');
    }
    if (!method_exists('WC_Tax', '_delete_tax_rate')) {
        return new WP_Error('phoenix_mcp_unavailable', 'This WooCommerce version cannot delete tax rates programmatically.');
    }
    WC_Tax::_delete_tax_rate($id);
    $gone = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id = %d", $id));
    if ($gone) {
        return new WP_Error('phoenix_mcp_delete_failed', 'Tax rate still exists after delete.');
    }
    return array('id' => $id, 'deleted' => true);
}

// --- Customers / gateways / comments / site ---

function phoenix_mcp_tool_wc_list_customers($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    if (!current_user_can('list_users') && !current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot list users.');
    }
    $query = array(
        'role'   => 'customer',
        'number' => isset($args['per_page']) ? max(1, min(50, (int) $args['per_page'])) : 20,
        'orderby' => 'registered',
        'order'   => 'DESC',
    );
    if (!empty($args['search'])) {
        $query['search'] = '*' . sanitize_text_field($args['search']) . '*';
    }
    $out = array();
    foreach (get_users($query) as $user) {
        $out[] = array(
            'id'          => $user->ID,
            'email'       => $user->user_email,
            'name'        => $user->display_name,
            'registered'  => $user->user_registered,
            'orders'      => function_exists('wc_get_customer_order_count') ? wc_get_customer_order_count($user->ID) : null,
            'total_spent' => function_exists('wc_get_customer_total_spent') ? wc_get_customer_total_spent($user->ID) : null,
        );
    }
    return $out;
}

function phoenix_mcp_tool_wc_list_payment_gateways($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    $out = array();
    foreach (WC_Payment_Gateways::instance()->payment_gateways() as $gateway) {
        $out[] = array(
            'id'          => $gateway->id,
            'title'       => $gateway->get_title(),
            'enabled'     => $gateway->enabled === 'yes',
            'description' => $gateway->get_description(),
        );
    }
    return $out;
}

function phoenix_mcp_tool_list_comments($args)
{
    if (!current_user_can('moderate_comments')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot moderate comments.');
    }
    $status_map = array(
        'approve' => 'approve', 'approved' => 'approve',
        'hold' => 'hold', 'pending' => 'hold',
        'spam' => 'spam', 'trash' => 'trash', 'all' => 'all',
    );
    $status = isset($args['status']) ? sanitize_key($args['status']) : 'hold';
    $status = isset($status_map[$status]) ? $status_map[$status] : 'hold';
    $query = array(
        'status'  => $status,
        'number'  => isset($args['per_page']) ? max(1, min(50, (int) $args['per_page'])) : 20,
        'orderby' => 'comment_date',
        'order'   => 'DESC',
    );
    if (!empty($args['post_id'])) {
        $query['post_id'] = (int) $args['post_id'];
    }
    $out = array();
    foreach (get_comments($query) as $comment) {
        $out[] = array(
            'id'      => (int) $comment->comment_ID,
            'post_id' => (int) $comment->comment_post_ID,
            'post_title' => get_the_title((int) $comment->comment_post_ID),
            'author'  => $comment->comment_author,
            'email'   => $comment->comment_author_email,
            'date'    => $comment->comment_date,
            'content' => $comment->comment_content,
            'status'  => wp_get_comment_status($comment->comment_ID),
        );
    }
    return $out;
}

function phoenix_mcp_tool_moderate_comment($args)
{
    if (!current_user_can('moderate_comments')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot moderate comments.');
    }
    $comment = get_comment((int) $args['id']);
    if (!$comment) {
        return new WP_Error('phoenix_mcp_not_found', 'No comment with that ID.');
    }
    $action = isset($args['action']) ? sanitize_key($args['action']) : '';
    $id     = (int) $comment->comment_ID;
    switch ($action) {
        case 'approve': $ok = wp_set_comment_status($id, 'approve'); break;
        case 'hold':    $ok = wp_set_comment_status($id, 'hold'); break;
        case 'spam':    $ok = wp_spam_comment($id); break;
        case 'unspam':  $ok = wp_unspam_comment($id); break;
        case 'trash':   $ok = wp_trash_comment($id); break;
        case 'untrash': $ok = wp_untrash_comment($id); break;
        case 'delete':  $ok = wp_delete_comment($id, true); break;
        default:
            return new WP_Error('phoenix_mcp_bad_action', 'action must be approve, hold, spam, unspam, trash, untrash, or delete.');
    }
    if (!$ok) {
        return new WP_Error('phoenix_mcp_moderation_failed', 'The moderation action failed.');
    }
    $status = $action === 'delete' ? 'deleted' : wp_get_comment_status($id);
    return array('id' => $id, 'action' => $action, 'status' => $status);
}

function phoenix_mcp_site_settings_snapshot()
{
    return array(
        'title'               => get_option('blogname'),
        'tagline'             => get_option('blogdescription'),
        'timezone'            => get_option('timezone_string'),
        'date_format'         => get_option('date_format'),
        'time_format'         => get_option('time_format'),
        'posts_per_page'      => (int) get_option('posts_per_page'),
        'permalink_structure' => get_option('permalink_structure'),
        'show_on_front'       => get_option('show_on_front'),
        'page_on_front'       => (int) get_option('page_on_front'),
        'page_for_posts'      => (int) get_option('page_for_posts'),
    );
}

function phoenix_mcp_tool_update_site_settings($args)
{
    if (!current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage site settings.');
    }
    $map = array(
        'title'          => 'blogname',
        'tagline'        => 'blogdescription',
        'timezone'       => 'timezone_string',
        'date_format'    => 'date_format',
        'time_format'    => 'time_format',
    );
    foreach ($map as $arg => $option) {
        if (isset($args[$arg])) {
            update_option($option, sanitize_text_field($args[$arg]));
        }
    }
    if (isset($args['posts_per_page'])) {
        update_option('posts_per_page', max(1, (int) $args['posts_per_page']));
    }
    if (isset($args['show_on_front'])) {
        $value = sanitize_key($args['show_on_front']);
        if (!in_array($value, array('posts', 'page'), true)) {
            return new WP_Error('phoenix_mcp_bad_args', 'show_on_front must be posts or page.');
        }
        update_option('show_on_front', $value);
    }
    if (isset($args['page_on_front'])) {
        update_option('page_on_front', (int) $args['page_on_front']);
    }
    if (isset($args['page_for_posts'])) {
        update_option('page_for_posts', (int) $args['page_for_posts']);
    }
    if (isset($args['permalink_structure'])) {
        global $wp_rewrite;
        $structure = sanitize_text_field($args['permalink_structure']);
        if ($wp_rewrite) {
            $wp_rewrite->set_permalink_structure($structure);
        } else {
            update_option('permalink_structure', $structure);
        }
        flush_rewrite_rules();
    }
    return phoenix_mcp_site_settings_snapshot();
}

function phoenix_mcp_tool_flush_rules($args)
{
    if (!current_user_can('manage_options')) {
        return new WP_Error('phoenix_mcp_forbidden', 'The token user cannot manage options.');
    }
    flush_rewrite_rules();
    $cache = wp_cache_flush();
    return array('rewrite_flushed' => true, 'cache_flushed' => (bool) $cache);
}

function phoenix_mcp_tool_wc_system_status($args)
{
    if (!phoenix_mcp_require_woo()) {
        return new WP_Error('phoenix_mcp_no_woo', 'WooCommerce is not active.');
    }
    global $wp_version;
    $counts = wp_count_posts('product');
    $order_counts = array();
    foreach (array_keys(wc_get_order_statuses()) as $status) {
        $key = preg_replace('/^wc-/', '', $status);
        $found = wc_get_orders(array('status' => $key, 'limit' => 1, 'paginate' => true));
        $order_counts[$key] = isset($found->total) ? (int) $found->total : 0;
    }
    $gateways = array();
    foreach (WC_Payment_Gateways::instance()->payment_gateways() as $gateway) {
        if ($gateway->enabled === 'yes') {
            $gateways[] = $gateway->id;
        }
    }
    $tax_classes = array_merge(array('standard'), WC_Tax::get_tax_classes());
    return array(
        'wordpress_version'   => $wp_version,
        'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : null,
        'php_version'         => PHP_VERSION,
        'site_url'            => home_url(),
        'currency'            => get_woocommerce_currency(),
        'base_country'        => WC()->countries ? WC()->countries->get_base_country() : null,
        'products_published'  => isset($counts->publish) ? (int) $counts->publish : 0,
        'products_draft'      => isset($counts->draft) ? (int) $counts->draft : 0,
        'orders_by_status'    => $order_counts,
        'active_gateways'     => $gateways,
        'shipping_zones'      => count(WC_Shipping_Zones::get_zones()) + 1,
        'tax_classes'         => $tax_classes,
        'permalink_structure' => get_option('permalink_structure'),
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
