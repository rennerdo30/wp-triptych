<?php

declare(strict_types=1);

namespace Triptych;

/**
 * Configured language list and default language.
 *
 * Stored as a single comma-separated option `triptych_languages` of `slug:Label` pairs,
 * e.g. `zh:中文,ja:日本語,en:English`. Cheap to store, easy to edit in Settings.
 */
final class Languages
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, string> slug => label, preserving config order.
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $raw = (string) get_option('triptych_languages', 'zh:中文,ja:日本語,en:English');
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $raw))) as $pair) {
            if (!str_contains($pair, ':')) {
                $slug = sanitize_key($pair);
                $out[$slug] = $slug;
                continue;
            }
            [$slug, $label] = array_map('trim', explode(':', $pair, 2));
            $slug = sanitize_key($slug);
            if ($slug === '') {
                continue;
            }
            $out[$slug] = $label !== '' ? $label : $slug;
        }

        if ($out === []) {
            $out = ['en' => 'English'];
        }

        return self::$cache = $out;
    }

    public static function default(): string
    {
        $default = sanitize_key((string) get_option('triptych_default_language', 'en'));
        $all = self::all();
        if (isset($all[$default])) {
            return $default;
        }
        return (string) array_key_first($all);
    }

    public static function isValid(string $slug): bool
    {
        return isset(self::all()[$slug]);
    }

    /** @return string[] */
    public static function slugs(): array
    {
        return array_keys(self::all());
    }

    public static function flushCache(): void
    {
        self::$cache = null;
    }
}
