<?php

declare(strict_types=1);

namespace Triptych;

/**
 * Multilingual field registry + storage.
 *
 * Storage convention: `_triptych_{field}_{lang}` postmeta. This keeps every
 * language value addressable from any tool that already speaks postmeta
 * (WP-CLI, WP REST API, MySQL backups), while remaining invisible to
 * WordPress core's main fields.
 *
 * Supported `type` values:
 *   - text      single-line input
 *   - textarea  multi-line input
 *   - wysiwyg   rich-text editor
 *   - repeater  per-row UI (add/remove/drag-reorder) that serializes back
 *               to a single string under the same postmeta key. Each row
 *               is rendered as N inline inputs (one per declared sub-field)
 *               joined by a separator (default `|`); rows are joined by
 *               newlines. Storage shape stays compatible with consumers
 *               that read the raw string with explode("\n") + explode("|").
 *
 * Repeater `args` extras:
 *   - subfields  array<int, array{key:string, label:string, placeholder?:string, width?:string}>
 *   - separator  string (single char), default '|'
 */
final class Fields
{
    /** @var array<string, array{type:string, post_types:array<int,string>, label:string, subfields:array<int, array<string,string>>, separator:string}> */
    private static array $registry = [];

    /**
     * @param array<string, mixed> $args
     */
    public static function register(string $field, array $args = []): void
    {
        $field = sanitize_key($field);
        if ($field === '') {
            return;
        }

        $type = isset($args['type']) ? (string) $args['type'] : 'text';
        if (!in_array($type, ['text', 'textarea', 'wysiwyg', 'repeater'], true)) {
            $type = 'text';
        }

        $post_types = [];
        if (isset($args['post_types']) && is_array($args['post_types'])) {
            foreach ($args['post_types'] as $pt) {
                $pt = sanitize_key((string) $pt);
                if ($pt !== '') {
                    $post_types[] = $pt;
                }
            }
        }

        $label = isset($args['label']) ? (string) $args['label'] : ucwords(str_replace('_', ' ', $field));

        $subfields = [];
        if ($type === 'repeater' && isset($args['subfields']) && is_array($args['subfields'])) {
            foreach ($args['subfields'] as $sf) {
                if (!is_array($sf) || empty($sf['key'])) {
                    continue;
                }
                $sk = sanitize_key((string) $sf['key']);
                if ($sk === '') {
                    continue;
                }
                $subfields[] = [
                    'key'         => $sk,
                    'label'       => isset($sf['label']) ? (string) $sf['label'] : ucwords(str_replace('_', ' ', $sk)),
                    'placeholder' => isset($sf['placeholder']) ? (string) $sf['placeholder'] : '',
                    'width'       => isset($sf['width']) ? (string) $sf['width'] : '',
                ];
            }
        }
        // Repeater with no declared sub-fields would render nothing useful,
        // so default to a single full-width "value" column.
        if ($type === 'repeater' && $subfields === []) {
            $subfields = [['key' => 'value', 'label' => __('Value', 'triptych'), 'placeholder' => '', 'width' => '']];
        }

        $separator = isset($args['separator']) ? (string) $args['separator'] : '|';
        if ($separator === '') {
            $separator = '|';
        }

        self::$registry[$field] = [
            'type'       => $type,
            'post_types' => $post_types,
            'label'      => $label,
            'subfields'  => $subfields,
            'separator'  => $separator,
        ];
    }

    /**
     * @return array<string, array{type:string, post_types:array<int,string>, label:string}>
     */
    public static function all(): array
    {
        return self::$registry;
    }

    /**
     * @return array<string, array{type:string, post_types:array<int,string>, label:string}>
     */
    public static function forPostType(string $post_type): array
    {
        $out = [];
        foreach (self::$registry as $key => $def) {
            if ($def['post_types'] === [] || in_array($post_type, $def['post_types'], true)) {
                $out[$key] = $def;
            }
        }
        return $out;
    }

    public static function metaKey(string $field, string $lang): string
    {
        return '_triptych_' . sanitize_key($field) . '_' . sanitize_key($lang);
    }

    /**
     * Read a multilingual field.
     *
     * Resolution order:
     *   1. `_triptych_<field>_<lang>` postmeta (canonical storage)
     *   2. Legacy multilingual postmeta — flat per-lang keys
     *      (`<field>_cn`, `<field>_jp`, `<field>_en`) + ACF serialised
     *      group arrays. Lets sites migrating off ACF / Polylang surface
     *      their existing data through Triptych without a forced
     *      bulk rewrite.
     *   3. Same chain in the default language (for posts whose
     *      translation is missing).
     *   4. Native post column (post_title / post_content / post_excerpt).
     */
    public static function get(int $post_id, string $field, ?string $lang = null): string
    {
        $lang ??= Router::currentLanguage();
        $field = sanitize_key($field);

        $value = (string) get_post_meta($post_id, self::metaKey($field, $lang), true);
        if ($value !== '') {
            return $value;
        }
        $legacy = self::readLegacy($post_id, $field, $lang);
        if ($legacy !== '') {
            return $legacy;
        }

        $default = Languages::default();
        if ($lang !== $default) {
            $value = (string) get_post_meta($post_id, self::metaKey($field, $default), true);
            if ($value !== '') {
                return $value;
            }
            $legacy = self::readLegacy($post_id, $field, $default);
            if ($legacy !== '') {
                return $legacy;
            }
        }

        // Final fallback: native post field, where applicable.
        $post = get_post($post_id);
        if ($post instanceof \WP_Post) {
            return match ($field) {
                'post_title' => (string) $post->post_title,
                'post_content' => (string) $post->post_content,
                'post_excerpt' => (string) $post->post_excerpt,
                default => '',
            };
        }
        return '';
    }

    /**
     * Look up legacy ACF / Polylang-era multilingual postmeta.
     *
     * Tries:
     *   1. Flat per-lang keys (`<field>_<slug>`) — using BOTH the
     *      Triptych slug AND the legacy short code (zh→cn, ja→jp).
     *   2. ACF-style serialised group array at the bare `<field>` key.
     *   3. A bare-key plain string as the source-language value —
     *      catches single-language plugins (or older seed scripts) that
     *      stored content directly as `<field> = "<source content>"`
     *      without a language suffix or grouping. Only returned when
     *      the requested $lang is the default — non-default langs
     *      should NOT inherit the source string verbatim, that's the
     *      job of the get() default-lang fallback step.
     */
    public static function readLegacy(int $post_id, string $field, string $lang): string
    {
        static $shortMap = [ 'zh' => 'cn', 'ja' => 'jp', 'en' => 'en' ];
        $slugs = array_unique([ $lang, $shortMap[$lang] ?? $lang ]);

        foreach ($slugs as $slug) {
            $flat = (string) get_post_meta($post_id, $field . '_' . $slug, true);
            if ($flat !== '') {
                return $flat;
            }
        }

        $bare = get_post_meta($post_id, $field, true);
        if (is_array($bare)) {
            foreach ($slugs as $slug) {
                if (! empty($bare[$slug]) && is_string($bare[$slug])) {
                    return $bare[$slug];
                }
            }
        } elseif (is_string($bare) && $bare !== '' && $lang === Languages::default()) {
            return $bare;
        }
        return '';
    }

    public static function set(int $post_id, string $field, string $lang, string $value): void
    {
        $field = sanitize_key($field);
        $lang = sanitize_key($lang);
        if ($field === '' || !Languages::isValid($lang)) {
            return;
        }
        if ($value === '') {
            delete_post_meta($post_id, self::metaKey($field, $lang));
            return;
        }
        update_post_meta($post_id, self::metaKey($field, $lang), $value);
    }
}
