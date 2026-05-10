<?php

declare(strict_types=1);

namespace Triptych\Frontend;

use Triptych\Fields;
use Triptych\Router;

/**
 * Swap `the_content` for the language-specific value at render time.
 *
 * We hook on `the_content` (not `post_content`) to keep autosaves, revisions,
 * and the editor unaffected — only the rendered front-end content gets
 * substituted.
 */
final class ContentFilter
{
    public static function register(): void
    {
        add_filter('the_content', [self::class, 'filter'], 5);
    }

    public static function filter(string $content): string
    {
        if (is_admin() || !in_the_loop()) {
            return $content;
        }
        $post = get_post();
        if (!$post instanceof \WP_Post) {
            return $content;
        }
        $value = Fields::get($post->ID, 'post_content', Router::currentLanguage());
        return $value !== '' ? $value : $content;
    }
}
