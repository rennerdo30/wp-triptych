<?php
/**
 * Minimal phpunit bootstrap. We don't load WordPress core — instead we stub the
 * handful of WP functions that the unit-tested code actually calls.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('TRIPTYCH_VERSION')) {
    define('TRIPTYCH_VERSION', '0.1.0-test');
}
if (!defined('TRIPTYCH_FILE')) {
    define('TRIPTYCH_FILE', dirname(__DIR__) . '/triptych.php');
}
if (!defined('TRIPTYCH_DIR')) {
    define('TRIPTYCH_DIR', dirname(__DIR__) . '/');
}
if (!defined('TRIPTYCH_URL')) {
    define('TRIPTYCH_URL', 'http://example.test/wp-content/plugins/triptych/');
}

// In-memory option store.
$GLOBALS['triptych_test_options'] = [];

if (!function_exists('get_option')) {
    function get_option(string $key, $default = false)
    {
        return $GLOBALS['triptych_test_options'][$key] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $key, $value): bool
    {
        $GLOBALS['triptych_test_options'][$key] = $value;
        return true;
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $key) ?? '');
    }
}
if (!function_exists('home_url')) {
    function home_url(string $path = '/'): string
    {
        return 'http://example.test' . $path;
    }
}

require_once dirname(__DIR__) . '/src/Languages.php';
require_once dirname(__DIR__) . '/src/Router.php';
require_once dirname(__DIR__) . '/src/Fields.php';
