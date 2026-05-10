<?php

declare(strict_types=1);

namespace Triptych\Admin;

use Triptych\Fields;
use Triptych\Languages;

/**
 * Adds a "Languages" column to wp-admin edit.php list tables for every
 * post type that has at least one Triptych field registered.
 *
 * Each row renders one tiny pill per configured language (two-letter
 * uppercase slug, monospace) showing whether the post has content for
 * that language:
 *
 *   - default-lang pill   → always filled dark (the source always exists
 *     for any post that exists in the DB)
 *   - translated pill     → green-filled when ANY registered Triptych
 *     field has non-empty `_triptych_<field>_<lang>` postmeta
 *   - missing translation → grey outlined
 *
 * The column is appended right before the Date column so it doesn't
 * disrupt existing column orderings.
 */
final class PostListColumn
{
    private const COLUMN_KEY = 'triptych_languages';

    public static function register(): void
    {
        // Wait until all post types are registered. Most CPTs register on
        // `init` priority 10; running at priority 99 catches plugins and
        // themes that register late without holding up the boot sequence.
        add_action('init', [self::class, 'hookPostTypes'], 99);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueStyles']);
    }

    /**
     * Wire column filters once admin context is ready and post types are
     * known. Skips `attachment` and any post type that has no UI.
     */
    public static function hookPostTypes(): void
    {
        if (!is_admin()) {
            return;
        }
        foreach (self::eligiblePostTypes() as $post_type) {
            add_filter(
                "manage_{$post_type}_posts_columns",
                [self::class, 'addColumn']
            );
            add_action(
                "manage_{$post_type}_posts_custom_column",
                [self::class, 'renderColumn'],
                10,
                2
            );
        }
    }

    /**
     * Inline a small stylesheet on edit.php screens for any eligible
     * post type. Avoids loading on every admin page.
     */
    public static function enqueueStyles(string $hook): void
    {
        if ($hook !== 'edit.php') {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $post_type = $screen instanceof \WP_Screen ? (string) $screen->post_type : '';
        if ($post_type === '' || !in_array($post_type, self::eligiblePostTypes(), true)) {
            return;
        }

        wp_register_style('triptych-list-column', false, [], TRIPTYCH_VERSION);
        wp_enqueue_style('triptych-list-column');
        wp_add_inline_style('triptych-list-column', self::inlineCss());
    }

    /**
     * Append the Languages column right before the Date column.
     *
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function addColumn(array $columns): array
    {
        $label = __('Languages', 'triptych');

        if (!array_key_exists('date', $columns)) {
            $columns[self::COLUMN_KEY] = $label;
            return $columns;
        }

        $out = [];
        foreach ($columns as $key => $value) {
            if ($key === 'date') {
                $out[self::COLUMN_KEY] = $label;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    /**
     * Render the per-row pill row for the Languages column.
     */
    public static function renderColumn(string $column, int $post_id): void
    {
        if ($column !== self::COLUMN_KEY) {
            return;
        }

        $languages = Languages::all();
        $default = Languages::default();
        if ($languages === []) {
            return;
        }

        $post_type = (string) get_post_type($post_id);
        $fields = Fields::forPostType($post_type);
        if ($fields === []) {
            return;
        }

        echo '<span class="triptych-langcol" role="list">';
        foreach ($languages as $slug => $label) {
            $is_default = ($slug === $default);
            $has = $is_default
                ? true
                : self::hasTranslation($post_id, $slug, $fields);

            $state = $is_default ? 'source' : ($has ? 'translated' : 'empty');

            $aria_state = $is_default
                /* translators: %s: language name */
                ? sprintf(__('%s — source language', 'triptych'), $label)
                : ($has
                    /* translators: %s: language name */
                    ? sprintf(__('%s — translated', 'triptych'), $label)
                    /* translators: %s: language name */
                    : sprintf(__('%s — not translated', 'triptych'), $label));

            printf(
                '<span class="triptych-langcol-pill is-%1$s" role="listitem" aria-label="%2$s" title="%2$s">%3$s</span>',
                esc_attr($state),
                esc_attr($aria_state),
                esc_html(self::shortSlug($slug))
            );
        }
        echo '</span>';
    }

    /**
     * Does the post have any non-empty Triptych field data for $lang?
     *
     * Checks `_triptych_post_title_<lang>` and `_triptych_post_content_<lang>`
     * first as the common case, then any other registered Triptych field.
     *
     * @param array<string, array{type:string, post_types:array<int,string>, label:string}> $fields
     */
    private static function hasTranslation(int $post_id, string $lang, array $fields): bool
    {
        foreach ($fields as $field => $_def) {
            $value = (string) get_post_meta($post_id, Fields::metaKey($field, $lang), true);
            if ($value !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Two-character uppercase slug for pill display. Falls back to first
     * three chars uppercased for slugs that aren't 2-letter ISO codes.
     */
    private static function shortSlug(string $slug): string
    {
        $slug = strtoupper($slug);
        if (strlen($slug) <= 3) {
            return $slug;
        }
        return substr($slug, 0, 2);
    }

    /**
     * Post types that should receive the column. Cached per request via
     * a static so repeat calls during a page load are cheap.
     *
     * @return array<int, string>
     */
    private static function eligiblePostTypes(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $out = [];
        $candidates = get_post_types(['show_ui' => true], 'names');
        foreach ($candidates as $post_type) {
            // Skip media library — its list table is upload.php and the
            // file/title coupling makes per-language pills meaningless.
            if ($post_type === 'attachment') {
                continue;
            }
            if (Fields::forPostType((string) $post_type) === []) {
                continue;
            }
            $out[] = (string) $post_type;
        }
        return $cache = $out;
    }

    /**
     * Scoped CSS for the column. Tiny enough to inline so we don't pay
     * for an extra HTTP request on every list table view.
     */
    private static function inlineCss(): string
    {
        return <<<CSS
.column-triptych_languages { width: 110px; }
.triptych-langcol {
    display: inline-flex;
    gap: 3px;
    align-items: center;
}
.triptych-langcol-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 22px;
    padding: 1px 5px;
    border-radius: 3px;
    font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.06em;
    line-height: 1.5;
    border: 1px solid transparent;
    user-select: none;
}
.triptych-langcol-pill.is-source {
    background: #1d2327;
    color: #fff;
    border-color: #1d2327;
}
.triptych-langcol-pill.is-translated {
    background: #16a34a;
    color: #fff;
    border-color: #16a34a;
}
.triptych-langcol-pill.is-empty {
    background: transparent;
    color: #8c8f94;
    border-color: #c3c4c7;
}
CSS;
    }
}
