<?php
/**
 * Plugin Name: ERA Dev Gate
 * Description: Password gate + noindex for the DEV deployment only. Inert on any other host.
 * Version: 1.0.0
 */
if (!isset($_SERVER['HTTP_HOST']) || strpos($_SERVER['HTTP_HOST'], 'serverlesswp-git-dev') === false) {
    return;
}

define('ERA_DEV_GATE_PW_HASH', '23d60b202c0c89094b7d011621318a96ca4e556085121d8be94a9e3826450839');
define('ERA_DEV_GATE_TOKEN', '72ff76f33c120da769bc68240309c70d0fa746153bbf67238a60433786f1f906');

function era_dev_gate_expected_token() {
    return ERA_DEV_GATE_TOKEN;
}

function era_dev_gate_is_open() {
    if (isset($_COOKIE['era_dev_gate']) && hash_equals(era_dev_gate_expected_token(), $_COOKIE['era_dev_gate'])) {
        return true;
    }
    if (isset($_SERVER['HTTP_X_DEV_GATE']) && hash_equals(ERA_DEV_GATE_PW_HASH, hash('sha256', 'era-pw:' . $_SERVER['HTTP_X_DEV_GATE']))) {
        return true;
    }
    return false;
}

add_filter('robots_txt', function ($output) {
    return "User-agent: *\nDisallow: /\n";
}, 999);

add_action('init', function () {
    if (!headers_sent()) {
        header('X-Robots-Tag: noindex, nofollow', true);
    }
    if (era_dev_gate_is_open()) {
        return;
    }
    if (isset($_POST['era_gate_password']) && hash_equals(ERA_DEV_GATE_PW_HASH, hash('sha256', 'era-pw:' . $_POST['era_gate_password']))) {
        setcookie('era_dev_gate', era_dev_gate_expected_token(), time() + 30 * DAY_IN_SECONDS, '/', '', true, true);
        wp_safe_redirect(esc_url_raw($_SERVER['REQUEST_URI']));
        exit;
    }
    status_header(401);
    nocache_headers();
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow"><title>Private</title></head>';
    echo '<body style="font-family:sans-serif;background:#111;color:#eee;display:flex;align-items:center;justify-content:center;height:100vh;margin:0">';
    echo '<form method="post" style="text-align:center"><p>Private development site.</p>';
    echo '<input type="password" name="era_gate_password" autofocus style="padding:8px;font-size:16px"> ';
    echo '<button type="submit" style="padding:8px 16px;font-size:16px">Enter</button></form>';
    echo '</body></html>';
    exit;
}, 1);
