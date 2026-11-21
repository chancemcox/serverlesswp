<?php
/**
 * Plugin Name: ERA Dev Logger
 * Description: Dev-only request breadcrumb logger. Records one trail per request (plugin file load, plugins_loaded, setup_theme, init, wp_loaded, shutdown, plus PHP fatal capture) and writes it to the serverlesswp-db R2 bucket under dev-logs/<date>/<time>-<random>.log, re-writing the object at every milestone so a hung request leaves its partial trail behind. Also compiles a once-a-day health report to dev-logs/health/<date>.json. Inert on any host other than the dev deployment. Built so Phoenix can see where a dev request stops, since Vercel runtime logs are not reachable.
 * Version: 1.1.0
 * Author: Apparel is How Eye Roll
 * License: GPL-2.0-or-later
 * Text Domain: era-dev-logger
 */

if (!defined('ABSPATH')) { exit; }

/* -------------------------------------------------------------------------
 * Dev-only safeguard.
 *
 * This plugin must never log anywhere but the dev deployment. Unless the
 * request host is the dev preview host, the plugin stays completely inert:
 * no hooks, no shutdown function, no network calls.
 * ---------------------------------------------------------------------- */
$era_dev_logger_host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
if (strpos($era_dev_logger_host, 'serverlesswp-git-dev') === false) {
    return;
}
unset($era_dev_logger_host);

class ERA_Dev_Logger
{
    private static $started = 0.0;      // microtime(true) at request start
    private static $lines = array();    // accumulated trail lines
    private static $key = null;         // R2 object key for this request
    private static $config = null;      // resolved S3 config, or false when unusable
    private static $wp_shutdown_seen = false;

    /* ------------------------------------------------------------------
     * Bootstrap: called once when this plugin file is loaded by WordPress.
     * Records the "file loaded" line and the request-start line, then
     * hooks the remaining milestones. The very first flush happens here,
     * so an object with at least these two lines exists even if the
     * request dies before plugins_loaded.
     * --------------------------------------------------------------- */
    public static function boot()
    {
        self::$started = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : microtime(true);

        try {
            self::$key = 'dev-logs/' . gmdate('Y-m-d') . '/'
                . gmdate('His') . '-' . self::random_suffix() . '.log';
        } catch (Throwable $e) {
            self::$key = null;
            return;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '?';
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '?';
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '?';

        self::line('plugin_file_loaded', 'era-dev-logger.php included by WordPress');
        self::line('request_start', $method . ' ' . $uri . ' host=' . $host
            . ' php=' . PHP_VERSION
            . ' s3_env: ' . self::env_status());

        add_action('plugins_loaded', array(__CLASS__, 'on_plugins_loaded'), 9999);
        add_action('setup_theme', array(__CLASS__, 'on_setup_theme'), 9999);
        add_action('init', array(__CLASS__, 'on_init'), 9999);
        add_action('wp_loaded', array(__CLASS__, 'on_wp_loaded'), 9999);
        add_action('shutdown', array(__CLASS__, 'on_wp_shutdown'), 9999);
        register_shutdown_function(array(__CLASS__, 'on_php_shutdown'));

        self::flush();
    }

    public static function on_plugins_loaded()
    {
        self::line('plugins_loaded', self::active_plugin_note());
        self::flush();
    }

    public static function on_setup_theme()
    {
        $theme = function_exists('get_stylesheet') ? get_stylesheet() : '?';
        self::line('setup_theme', 'stylesheet=' . $theme);
        self::flush();
    }

    public static function on_init()
    {
        self::line('init', '');
        self::flush();
        self::maybe_self_test();
    }

    /* ------------------------------------------------------------------
     * Daily self-test / health report.
     *
     * Runs at most once per day (guarded by a WordPress transient keyed
     * to the UTC date; the guard is set BEFORE the work so a failure can
     * never turn into an every-request retry). Compiles a small JSON
     * health snapshot and PUTs it to dev-logs/health/<date>.json in the
     * same R2 bucket. Every step is best-effort: any error quietly ends
     * the self-test for the day.
     * --------------------------------------------------------------- */
    private static function maybe_self_test()
    {
        try {
            if (!function_exists('get_transient') || !function_exists('set_transient')) {
                return;
            }
            $today = gmdate('Y-m-d');
            if (get_transient('era_dev_logger_health_day') === $today) {
                return;
            }
            set_transient('era_dev_logger_health_day', $today, DAY_IN_SECONDS);

            $cfg = self::config();
            if ($cfg === false) {
                return;
            }

            global $wp_version;
            $report = array(
                'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'host' => isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : null,
                'php_version' => PHP_VERSION,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'loaded_extensions_count' => count(get_loaded_extensions()),
                'wp_version' => isset($wp_version) ? $wp_version : null,
                'theme_stylesheet' => function_exists('get_stylesheet') ? get_stylesheet() : null,
                'theme_name' => null,
                'active_plugins' => array(),
                'sqlite_db_bytes' => self::sqlite_db_bytes(),
                'bootstrap_timings_ms' => self::trail_timings(),
                's3_env' => self::env_status(),
            );
            try {
                if (function_exists('wp_get_theme')) {
                    $theme = wp_get_theme();
                    if ($theme && method_exists($theme, 'get')) {
                        $report['theme_name'] = $theme->get('Name');
                    }
                }
                $active = get_option('active_plugins', array());
                if (is_array($active)) {
                    $report['active_plugins'] = array_values($active);
                }
            } catch (Throwable $e) {
            }

            $body = json_encode($report, JSON_PRETTY_PRINT);
            if (is_string($body) && $body !== '') {
                self::put_object($cfg, 'dev-logs/health/' . $today . '.json', $body, 'application/json');
            }
        } catch (Throwable $e) {
            // Self-test must never disturb the request.
        }
    }

    /* Best-effort size of the SQLite database file backing this install.
     * Asks SQLite itself for its file path (PRAGMA database_list) via
     * $wpdb; returns null when anything about that is unavailable. */
    private static function sqlite_db_bytes()
    {
        try {
            global $wpdb;
            if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
                return null;
            }
            $rows = $wpdb->get_results('PRAGMA database_list');
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $file = isset($row->file) ? (string) $row->file : '';
                    if ($file !== '' && file_exists($file)) {
                        $size = filesize($file);
                        return $size === false ? null : $size;
                    }
                }
            }
        } catch (Throwable $e) {
        }
        return null;
    }

    /* Milestone timings from this request's own trail so far, parsed
     * back out of the accumulated lines: milestone => elapsed ms. */
    private static function trail_timings()
    {
        $timings = array();
        foreach (self::$lines as $line) {
            if (preg_match('/\[\+\s*(\d+)ms\]\s+\S+\s+\S+\s+(\S+)/', $line, $m)) {
                $timings[$m[2]] = (int) $m[1];
            }
        }
        return $timings;
    }

    public static function on_wp_loaded()
    {
        global $wp_version;
        self::line('wp_loaded', 'wp=' . (isset($wp_version) ? $wp_version : '?')
            . ' memory=' . self::bytes(memory_get_usage(true)));
        self::flush();
    }

    public static function on_wp_shutdown()
    {
        self::$wp_shutdown_seen = true;
        self::line('shutdown', 'WordPress shutdown action reached; memory_peak='
            . self::bytes(memory_get_peak_usage(true)));
        self::flush();
    }

    /* ------------------------------------------------------------------
     * Runs at real PHP shutdown, after the WordPress 'shutdown' action.
     * Captures fatals via error_get_last(). If the request hung and was
     * killed, this never runs — the trail simply stops at the last
     * milestone that did flush, which is the diagnostic.
     * --------------------------------------------------------------- */
    public static function on_php_shutdown()
    {
        try {
            $err = error_get_last();
            if (is_array($err) && isset($err['type'])
                && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)
            ) {
                self::line('php_fatal', $err['message']
                    . ' in ' . (isset($err['file']) ? $err['file'] : '?')
                    . ':' . (isset($err['line']) ? $err['line'] : '?'));
            }
            self::line('php_shutdown', 'wp_shutdown_action=' . (self::$wp_shutdown_seen ? 'yes' : 'no')
                . ' memory_peak=' . self::bytes(memory_get_peak_usage(true)));
            self::flush();
        } catch (Throwable $e) {
            // A logger must never take the site down with it.
        }
    }

    /* ------------------------------------------------------------------
     * Trail handling.
     * --------------------------------------------------------------- */
    private static function line($milestone, $detail)
    {
        $elapsed_ms = (int) round((microtime(true) - self::$started) * 1000);
        $stamp = gmdate('Y-m-d\TH:i:s') . sprintf('.%03dZ', (int) ((microtime(true) - floor(microtime(true))) * 1000));
        self::$lines[] = sprintf('[+%6dms] %s %-18s %s', $elapsed_ms, $stamp, $milestone, $detail);
    }

    private static function flush()
    {
        try {
            if (self::$key === null || empty(self::$lines)) {
                return;
            }
            $cfg = self::config();
            if ($cfg === false) {
                return; // No usable S3 env: silently no-op, per design.
            }
            self::put_object($cfg, self::$key, implode("\n", self::$lines) . "\n");
        } catch (Throwable $e) {
            // Silently no-op on any failure.
        }
    }

    private static function active_plugin_note()
    {
        try {
            $active = get_option('active_plugins', array());
            if (is_array($active) && !empty($active)) {
                return 'active_plugins=' . implode(',', $active);
            }
        } catch (Throwable $e) {
        }
        return '';
    }

    private static function bytes($n)
    {
        return number_format((float) $n / 1048576, 1) . 'MB';
    }

    private static function random_suffix()
    {
        try {
            return bin2hex(random_bytes(4));
        } catch (Throwable $e) {
            return substr(md5(uniqid((string) mt_rand(), true)), 0, 8);
        }
    }

    /* ------------------------------------------------------------------
     * S3 config from the ServerlessWP SQLITE_S3_* environment.
     *
     * The Node wrapper holds these as Vercel env vars; whether they are
     * visible to PHP depends on how the PHP process was spawned, so check
     * getenv(), $_SERVER and $_ENV, under a few plausible names. Only
     * presence is ever logged — never a value.
     * --------------------------------------------------------------- */
    private static function env_get($names)
    {
        foreach ($names as $name) {
            $v = getenv($name);
            if (is_string($v) && $v !== '') {
                return $v;
            }
            if (isset($_SERVER[$name]) && is_string($_SERVER[$name]) && $_SERVER[$name] !== '') {
                return $_SERVER[$name];
            }
            if (isset($_ENV[$name]) && is_string($_ENV[$name]) && $_ENV[$name] !== '') {
                return $_ENV[$name];
            }
        }
        return null;
    }

    private static function env_status()
    {
        $cfg = self::config();
        if ($cfg === false) {
            return 'incomplete (logger no-ops)';
        }
        return 'complete (bucket=' . $cfg['bucket'] . ')';
    }

    private static function config()
    {
        if (self::$config !== null) {
            return self::$config;
        }
        $endpoint = self::env_get(array('SQLITE_S3_ENDPOINT', 'SQLITE_S3_ENDPOINT_URL', 'S3_ENDPOINT'));
        $bucket = self::env_get(array('SQLITE_S3_BUCKET', 'S3_BUCKET'));
        $key_id = self::env_get(array('SQLITE_S3_ACCESS_KEY_ID', 'SQLITE_S3_KEY_ID', 'SQLITE_S3_ACCESS_KEY', 'S3_ACCESS_KEY_ID'));
        $secret = self::env_get(array('SQLITE_S3_SECRET_ACCESS_KEY', 'SQLITE_S3_SECRET', 'S3_SECRET_ACCESS_KEY'));
        $region = self::env_get(array('SQLITE_S3_REGION', 'S3_REGION'));
        if ($region === null || $region === '') {
            $region = 'auto'; // Cloudflare R2 convention.
        }
        if ($endpoint === null || $bucket === null || $key_id === null || $secret === null) {
            self::$config = false;
            return self::$config;
        }
        $parts = parse_url($endpoint);
        if (!is_array($parts) || empty($parts['host'])) {
            self::$config = false;
            return self::$config;
        }
        $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'https';
        $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        self::$config = array(
            'endpoint' => $scheme . '://' . $host,
            'host' => $host,
            'bucket' => $bucket,
            'key_id' => $key_id,
            'secret' => $secret,
            'region' => $region,
        );
        return self::$config;
    }

    /* ------------------------------------------------------------------
     * Minimal AWS SigV4 PUT (path-style), used for Cloudflare R2.
     * Small, bounded-timeout, and quiet: returns true/false, never throws
     * outward (callers also wrap in try/catch).
     * --------------------------------------------------------------- */
    private static function put_object($cfg, $key, $body, $content_type = 'text/plain; charset=utf-8')
    {
        $service = 's3';
        $now = time();
        $amz_date = gmdate('Ymd\THis\Z', $now);
        $date_stamp = gmdate('Ymd', $now);
        $payload_hash = hash('sha256', $body);
        $canonical_uri = '/' . $cfg['bucket'] . '/' . $key;

        $canonical_headers = 'content-type:' . $content_type . "\n"
            . 'host:' . $cfg['host'] . "\n"
            . 'x-amz-content-sha256:' . $payload_hash . "\n"
            . 'x-amz-date:' . $amz_date . "\n";
        $signed_headers = 'content-type;host;x-amz-content-sha256;x-amz-date';
        $canonical_request = "PUT\n" . $canonical_uri . "\n\n" . $canonical_headers . "\n"
            . $signed_headers . "\n" . $payload_hash;

        $scope = $date_stamp . '/' . $cfg['region'] . '/' . $service . '/aws4_request';
        $string_to_sign = "AWS4-HMAC-SHA256\n" . $amz_date . "\n" . $scope . "\n"
            . hash('sha256', $canonical_request);

        $k_date = hash_hmac('sha256', $date_stamp, 'AWS4' . $cfg['secret'], true);
        $k_region = hash_hmac('sha256', $cfg['region'], $k_date, true);
        $k_service = hash_hmac('sha256', $service, $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);

        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $cfg['key_id'] . '/' . $scope
            . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;

        $url = $cfg['endpoint'] . $canonical_uri;
        $headers = array(
            'Content-Type: ' . $content_type,
            'x-amz-content-sha256: ' . $payload_hash,
            'x-amz-date: ' . $amz_date,
            'Authorization: ' . $authorization,
        );

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return false;
            }
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return $code >= 200 && $code < 300;
        }

        $context = stream_context_create(array(
            'http' => array(
                'method' => 'PUT',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 3,
                'ignore_errors' => true,
            ),
        ));
        $result = @file_get_contents($url, false, $context);
        return $result !== false;
    }
}

ERA_Dev_Logger::boot();
