<?php

declare(strict_types=1);

namespace Triptych\Editor;

use Triptych\Fields;
use Triptych\Languages;

/**
 * Loads admin CSS/JS only on post edit screens where Triptych metabox renders.
 */
final class AssetsEnqueue
{
    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function enqueue(string $hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen instanceof \WP_Screen) {
            return;
        }
        $post_type = $screen->post_type;
        if ($post_type === '' || Fields::forPostType($post_type) === []) {
            return;
        }

        wp_enqueue_style(
            'triptych-admin-editor',
            TRIPTYCH_URL . 'assets/css/admin-editor.css',
            [],
            TRIPTYCH_VERSION
        );

        wp_enqueue_script(
            'triptych-admin-editor',
            TRIPTYCH_URL . 'assets/js/admin-editor.js',
            [],
            TRIPTYCH_VERSION,
            true
        );

        wp_localize_script('triptych-admin-editor', 'TriptychEditor', [
            'restUrl' => esc_url_raw(rest_url('triptych/v1/translate')),
            'nonce' => wp_create_nonce('wp_rest'),
            'defaultLang' => Languages::default(),
            'i18n' => [
                'translating' => __('Translating…', 'triptych'),
                'done' => __('Translated', 'triptych'),
                'error' => __('Translation failed', 'triptych'),
                'empty' => __('Source field is empty', 'triptych'),
            ],
        ]);
    }
}
