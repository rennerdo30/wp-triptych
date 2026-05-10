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
    }
}
