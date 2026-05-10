<?php
/**
 * Triptych uninstall handler.
 *
 * Removes options + transients. Postmeta is intentionally preserved so users
 * who reinstall the plugin keep their translations.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$options = [
    'triptych_languages',
    'triptych_default_language',
    'triptych_endpoint',
    'triptych_api_key',
    'triptych_model',
];

foreach ($options as $option) {
    delete_option($option);
}

// Best-effort transient sweep.
global $wpdb;
$like = $wpdb->esc_like('_transient_triptych_') . '%';
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
$like = $wpdb->esc_like('_transient_timeout_triptych_') . '%';
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
