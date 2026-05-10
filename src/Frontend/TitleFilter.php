<?php

declare(strict_types=1);

namespace Triptych\Frontend;

use Triptych\Fields;
use Triptych\Router;

/**
 * Swap `the_title` (and the raw `post_title` admin-list value) for the
 * language-specific value when rendering on the front end.
 */
final class TitleFilter
{
    public static function register(): void
    {
        add_filter('the_title', [self::class, 'filter'], 5, 2);
        add_filter('single_post_title', [self::class, 'filterSingle'], 5, 2);
    }

    public static function filter(string $title, ?int $post_id = null): string
    {
        if (is_admin() || $post_id === null || $post_id === 0) {
            return $title;
        }
        $value = Fields::get((int) $post_id, 'post_title', Router::currentLanguage());
        return $value !== '' ? $value : $title;
    }

    public static function filterSingle(string $title, $post): string
    {
        if (is_admin() || !$post instanceof \WP_Post) {
            return $title;
        }
        $value = Fields::get($post->ID, 'post_title', Router::currentLanguage());
        return $value !== '' ? $value : $title;
    }
}
