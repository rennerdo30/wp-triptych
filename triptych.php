<?php
/**
 * Plugin Name: Triptych
 * Plugin URI: https://github.com/rennerdo30/wp-triptych
 * Description: Single-post multilingual editor — Block Editor language switcher in the canvas, per-block translate button, per-block source-drift detection, one-click DeepSeek/OpenAI translation per non-default language. One canonical post, multiple language fields, no per-language post twins.
 * Version: 0.3.6
 * Author: renner.dev
 * Author URI: https://renner.dev
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: triptych
 * Requires at least: 6.5
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('TRIPTYCH_VERSION', '0.3.6');
define('TRIPTYCH_FILE', __FILE__);
define('TRIPTYCH_DIR', plugin_dir_path(__FILE__));
define('TRIPTYCH_URL', plugin_dir_url(__FILE__));

// Lightweight PSR-4-style autoloader for the Triptych\ namespace.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Triptych\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = TRIPTYCH_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

// Public procedural API — thin wrappers over the OO core.
require_once TRIPTYCH_DIR . 'src/api.php';

// Boot the plugin.
add_action('plugins_loaded', static function (): void {
    \Triptych\Plugin::instance()->boot();
});

// Activation: no schema changes — postmeta + options only.
register_activation_hook(__FILE__, static function (): void {
    if (false === get_option('triptych_languages')) {
        update_option('triptych_languages', 'zh:中文,ja:日本語,en:English');
    }
    if (false === get_option('triptych_default_language')) {
        update_option('triptych_default_language', 'en');
    }
    if (false === get_option('triptych_endpoint')) {
        update_option('triptych_endpoint', 'https://api.openai.com/v1');
    }
    if (false === get_option('triptych_model')) {
        update_option('triptych_model', 'gpt-4o-mini');
    }
    flush_rewrite_rules();
});

// Deactivation: clear scheduled events but preserve content data.
register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('triptych_cron_translate_queue');
    flush_rewrite_rules();
});
