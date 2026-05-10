<?php

declare(strict_types=1);

namespace Triptych\Frontend;

use Triptych\Languages;
use Triptych\Router;

/**
 * Prefixes outgoing permalinks with the current language slug.
 *
 * The Router strips the slug back off on the inbound side, so the round-trip
 * is `home_url('/foo/')` → `/zh/foo/` → request resolves to `/foo/`.
 */
final class PermalinkFilter
{
    public static function register(): void
    {
        add_filter('post_link', [self::class, 'prefix'], 10, 1);
        add_filter('page_link', [self::class, 'prefix'], 10, 1);
        add_filter('post_type_link', [self::class, 'prefix'], 10, 1);
        add_filter('home_url', [self::class, 'homeUrl'], 10, 4);
    }

    public static function prefix(string $url): string
    {
        return self::injectLang($url, Router::currentLanguage());
    }

    /**
     * @param string      $url
     * @param string      $path
     * @param string|null $orig_scheme
     * @param int|null    $blog_id
     */
    public static function homeUrl(string $url, string $path = '', $orig_scheme = null, $blog_id = null): string
    {
        // Don't rewrite admin / login / REST URLs.
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return $url;
        }
        if (str_contains($url, '/wp-admin') || str_contains($url, '/wp-login') || str_contains($url, '/wp-json')) {
            return $url;
        }
        if ($path === '' || str_starts_with($path, '?') || str_starts_with($path, '#')) {
            return self::injectLang($url, Router::currentLanguage());
        }
        return self::injectLang($url, Router::currentLanguage());
    }

    public static function injectLang(string $url, string $lang): string
    {
        if (!Languages::isValid($lang)) {
            return $url;
        }
        // Critical: home_url() would re-trigger the homeUrl filter we're
        // INSIDE, causing infinite recursion → OOM. site_url() is the
        // unfiltered alternative — same value on a typical WP install.
        $home = self::siteHome();
        if (!str_starts_with($url, $home)) {
            return $url;
        }
        $remainder = substr($url, strlen($home));
        // Already prefixed?
        foreach (Languages::slugs() as $slug) {
            if ($remainder === $slug || str_starts_with($remainder, "{$slug}/")) {
                return $url;
            }
        }
        return $home . $lang . '/' . $remainder;
    }

    private static ?string $cachedHome = null;

    private static function siteHome(): string
    {
        return self::$cachedHome ??= rtrim((string) site_url('/'), '/') . '/';
    }
}
