<?php

declare(strict_types=1);

namespace Triptych\Editor;

use Triptych\Admin\AdminBar;
use Triptych\Fields;
use Triptych\Languages;

/**
 * Block Editor asset loader for the v0.3.0 in-canvas language UI.
 *
 * Fires on `enqueue_block_editor_assets`. Loads:
 *   - admin-editor.js — language switcher, per-block translate button,
 *     content/title swap on language change, save routing, diff banner.
 *   - admin-editor.css — the language-bar styling, badges, popovers.
 *
 * Skips post types whose registry has no registered Triptych fields, so
 * unrelated CPTs don't pay the cost of loading the Gutenberg integration.
 *
 * For classic-editor screens the Editor\Metabox tabbed UI still loads via
 * its own admin_enqueue_scripts path — we don't touch that here.
 */
final class AssetsEnqueue
{
    public static function register(): void
    {
        add_action('enqueue_block_editor_assets', [self::class, 'enqueueBlock']);
        // Repeater metabox runs on every post-edit screen (classic AND
        // block editor). Hook the universal admin enqueue so both surfaces
        // get the row-UI script.
        add_action('admin_enqueue_scripts', [self::class, 'enqueueRepeater']);
    }

    public static function enqueueBlock(): void
    {
        global $post;
        $post_type = $post instanceof \WP_Post ? $post->post_type : '';

        $applies = false;
        foreach (Fields::all() as $name => $def) {
            $allowed = (array) ($def['post_types'] ?? []);
            if ($allowed === [] || in_array($post_type, $allowed, true)) {
                $applies = true;
                break;
            }
        }
        if (!$applies) {
            return;
        }

        wp_enqueue_script(
            'triptych-editor',
            TRIPTYCH_URL . 'assets/js/admin-editor.js',
            [
                'wp-plugins',
                'wp-edit-post',
                'wp-editor',
                'wp-element',
                'wp-components',
                'wp-data',
                'wp-blocks',
                'wp-block-editor',
                'wp-i18n',
                'wp-api-fetch',
                'wp-hooks',
                'wp-compose',
                'wp-notices',
            ],
            TRIPTYCH_VERSION,
            true
        );

        wp_enqueue_style(
            'triptych-editor',
            TRIPTYCH_URL . 'assets/css/admin-editor.css',
            ['wp-edit-post'],
            TRIPTYCH_VERSION
        );

        wp_localize_script('triptych-editor', 'TriptychEditor', [
            'languages' => Languages::all(),
            'default'   => Languages::default(),
            'adminLang' => AdminBar::getAdminLang(),
            'i18n'      => [
                'switchLabel'      => __('Language', 'triptych'),
                'sourceLang'       => __('Source', 'triptych'),
                'translateBlock'   => __('Translate block', 'triptych'),
                'translatePost'    => __('Translate post', 'triptych'),
                'saveTranslation'  => __('Save translation', 'triptych'),
                'savedTranslation' => __('Translation saved', 'triptych'),
                'translating'      => __('Translating…', 'triptych'),
                'sourceChanged'    => __('Source has changed since this translation was made.', 'triptych'),
                'reTranslate'      => __('Re-translate', 'triptych'),
                'translatedAgo'    => __('Translated %s', 'triptych'),
                'notTranslated'    => __('Not translated', 'triptych'),
                'translateError'   => __('Translation failed: %s', 'triptych'),
                'savePostFirst'    => __('Save the post once before translating.', 'triptych'),
                'untranslatable'   => __('This block has no translatable text.', 'triptych'),
            ],
        ]);

        // If the post type also has any structured (repeater) fields,
        // push the repeater UI script onto the same screen. Gutenberg
        // renders metaboxes in a hidden meta-box pane below the canvas;
        // the repeater script binds to those DOM nodes there.
        self::maybeEnqueueRepeaterAssets($post_type);
    }

    /**
     * Enqueue the repeater UI on classic-editor post screens (and as a
     * fallback for block-editor screens whose `enqueue_block_editor_assets`
     * fires too early to detect the post type — `admin_enqueue_scripts`
     * always sees the resolved screen).
     *
     * Cheap idempotent: wp_enqueue_script de-duplicates by handle so
     * calling it twice (once here, once from enqueueBlock) is a no-op.
     */
    public static function enqueueRepeater(string $hook = ''): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $post_type = $screen ? (string) $screen->post_type : '';
        self::maybeEnqueueRepeaterAssets($post_type);
    }

    private static function maybeEnqueueRepeaterAssets(string $post_type): void
    {
        $hasRepeater = false;
        foreach (Fields::all() as $def) {
            if (($def['type'] ?? '') !== 'repeater') {
                continue;
            }
            $allowed = (array) ($def['post_types'] ?? []);
            if ($allowed === [] || in_array($post_type, $allowed, true)) {
                $hasRepeater = true;
                break;
            }
        }
        if (!$hasRepeater) {
            return;
        }

        wp_enqueue_script(
            'triptych-repeater',
            TRIPTYCH_URL . 'assets/js/admin-metabox-repeater.js',
            ['wp-api-fetch'],
            TRIPTYCH_VERSION,
            true
        );
        wp_enqueue_style(
            'triptych-repeater',
            TRIPTYCH_URL . 'assets/css/admin-metabox-repeater.css',
            [],
            TRIPTYCH_VERSION
        );
    }
}
