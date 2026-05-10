<?php
/**
 * Triptych public procedural API.
 *
 * Theme + plugin authors call these functions; everything else is internal.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('triptych_register_multilingual_field')) {
    /**
     * Register a multilingual field.
     *
     * @param string               $field Field key (e.g. 'post_title', 'post_content', 'event_subtitle').
     * @param array<string, mixed> $args  Optional. {type: 'text'|'textarea'|'wysiwyg', post_types: string[], label: string}.
     */
    function triptych_register_multilingual_field(string $field, array $args = []): void
    {
        \Triptych\Fields::register($field, $args);
    }
}

if (!function_exists('triptych_get_field')) {
    /**
     * Read a multilingual field for a post, with fallback to default language and native field.
     *
     * @param int         $post_id Post ID.
     * @param string      $field   Field key.
     * @param string|null $lang    Language slug. Null = current request language.
     */
    function triptych_get_field(int $post_id, string $field, ?string $lang = null): string
    {
        return \Triptych\Fields::get($post_id, $field, $lang);
    }
}

if (!function_exists('triptych_current_language')) {
    /**
     * Current request language, derived from URL prefix.
     */
    function triptych_current_language(): string
    {
        return \Triptych\Router::currentLanguage();
    }
}

if (!function_exists('triptych_languages')) {
    /**
     * Configured languages as [slug => label].
     *
     * @return array<string, string>
     */
    function triptych_languages(): array
    {
        return \Triptych\Languages::all();
    }
}

if (!function_exists('triptych_default_language')) {
    /**
     * Default language slug.
     */
    function triptych_default_language(): string
    {
        return \Triptych\Languages::default();
    }
}
