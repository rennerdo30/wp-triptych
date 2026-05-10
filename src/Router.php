<?php

declare(strict_types=1);

namespace Triptych;

/**
 * URL prefix routing — `/{lang}/...`.
 *
 * Strategy: rather than registering a real rewrite tag (which would require
 * declaring every CPT), we strip the prefix from the request URI very early
 * (`do_parse_request`) and remember the language for the rest of the request.
 * WordPress then resolves the post normally, so a single canonical post is
 * reachable at `/zh/foo/`, `/ja/foo/`, `/en/foo/`.
 */
final class Router
{
    private static ?string $currentLang = null;

    public static function register(): void
    {
        // `do_parse_request` fires before the main query, lets us mutate REQUEST_URI.
        add_filter('do_parse_request', [self::class, 'stripLanguagePrefix'], 1, 3);
        // `init` lets us also detect language for non-routed requests (REST, AJAX).
        add_action('init', [self::class, 'detectFromRequest'], 1);
    }

    /**
     * @param bool   $do_parse
     * @param mixed  $wp
     * @param array  $extra_query_vars
     */
    public static function stripLanguagePrefix($do_parse, $wp, $extra_query_vars): bool
    {
        if (!isset($_SERVER['REQUEST_URI'])) {
            return (bool) $do_parse;
        }

        $uri = (string) $_SERVER['REQUEST_URI'];
        $home_path = (string) parse_url(home_url('/'), PHP_URL_PATH);
        $home_path = rtrim($home_path, '/');

        $path = $uri;
        if ($home_path !== '' && str_starts_with($path, $home_path)) {
            $path = substr($path, strlen($home_path));
        }
        $path = '/' . ltrim($path, '/');

        foreach (Languages::slugs() as $slug) {
            if ($path === "/{$slug}" || str_starts_with($path, "/{$slug}/")) {
                self::$currentLang = $slug;
                $rewritten = $path === "/{$slug}" ? '/' : substr($path, strlen($slug) + 1);
                $_SERVER['REQUEST_URI'] = ($home_path !== '' ? $home_path : '') . $rewritten;
                return (bool) $do_parse;
            }
        }

        return (bool) $do_parse;
    }

    public static function detectFromRequest(): void
    {
        if (self::$currentLang !== null) {
            return;
        }
        // Allow REST clients to pass `?triptych_lang=ja`.
        if (isset($_REQUEST['triptych_lang'])) {
            $maybe = sanitize_key((string) $_REQUEST['triptych_lang']);
            if (Languages::isValid($maybe)) {
                self::$currentLang = $maybe;
                return;
            }
        }
        self::$currentLang = Languages::default();
    }

    public static function currentLanguage(): string
    {
        if (self::$currentLang !== null && Languages::isValid(self::$currentLang)) {
            return self::$currentLang;
        }
        return Languages::default();
    }

    public static function setCurrentLanguage(string $slug): void
    {
        if (Languages::isValid($slug)) {
            self::$currentLang = $slug;
        }
    }
}
