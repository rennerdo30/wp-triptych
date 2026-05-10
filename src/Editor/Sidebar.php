<?php

declare(strict_types=1);

namespace Triptych\Editor;

use Triptych\Fields;
use Triptych\Languages;

/**
 * Block-editor sidebar plugin enqueuer.
 *
 * Replaces the classic-metabox UX (Editor/Metabox.php) with a Gutenberg
 * sidebar pinned to the top of the post-edit screen. The Block Editor
 * itself stays the source-language editor — Gutenberg, blocks, images,
 * tables, embeds. The sidebar adds:
 *
 *   • Status row per non-default language ("translated 2 days ago",
 *     "stale, source has changed", "needs translate").
 *   • One-click "Translate from <default>" buttons that POST to the
 *     existing /triptych/v1/translate endpoint and persist via
 *     SidebarRest::saveValue().
 *   • Inline review/edit of any custom multilingual field (event_venue,
 *     news_tag, …) via plain text inputs per language.
 */
final class Sidebar
{
    public static function register(): void
    {
        add_action('enqueue_block_editor_assets', [self::class, 'enqueue']);
    }

    public static function enqueue(): void
    {
        global $post;
        $post_type = $post instanceof \WP_Post ? $post->post_type : '';

        // Skip post types that have no Triptych-registered fields
        // (post_title + post_content always count, but only for post types
        // that opt into them via empty post_types array).
        $has_default_fields = false;
        foreach (Fields::all() as $name => $def) {
            $allowed = (array) ($def['post_types'] ?? []);
            if ($allowed === [] || in_array($post_type, $allowed, true)) {
                $has_default_fields = true;
                break;
            }
        }
        if (!$has_default_fields) {
            return;
        }

        wp_enqueue_script(
            'triptych-sidebar',
            TRIPTYCH_URL . 'assets/js/admin-sidebar.js',
            [
                'wp-plugins',
                'wp-edit-post',
                'wp-element',
                'wp-components',
                'wp-data',
                'wp-i18n',
                'wp-api-fetch',
                'wp-compose',
            ],
            TRIPTYCH_VERSION,
            true
        );

        wp_enqueue_style(
            'triptych-sidebar',
            TRIPTYCH_URL . 'assets/css/admin-sidebar.css',
            ['wp-edit-post'],
            TRIPTYCH_VERSION
        );

        wp_localize_script('triptych-sidebar', 'TriptychSidebar', [
            'languages' => Languages::all(),
            'default'   => Languages::default(),
            'i18n'      => [
                'panelTitle'        => __('Translations', 'triptych'),
                'sidebarLabel'      => __('Triptych', 'triptych'),
                'sourceLabel'       => __('Source', 'triptych'),
                'translateBtn'      => __('Translate from %s', 'triptych'),
                'retranslateBtn'    => __('Re-translate', 'triptych'),
                'translatingState' => __('Translating…', 'triptych'),
                'savedJustNow'      => __('Saved just now', 'triptych'),
                'translated'        => __('Translated %s', 'triptych'),
                'notTranslated'     => __('Not translated', 'triptych'),
                'stale'             => __('Source has changed', 'triptych'),
                'editLabel'         => __('Edit translation', 'triptych'),
                'doneLabel'         => __('Done', 'triptych'),
                'errorPrefix'       => __('Translation failed: %s', 'triptych'),
                'savePostFirst'     => __('Save this post as a draft to start translating.', 'triptych'),
                'fieldsHeading'     => __('Multilingual fields', 'triptych'),
            ],
        ]);
    }
}
