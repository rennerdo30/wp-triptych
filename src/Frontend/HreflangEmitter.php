<?php

declare(strict_types=1);

namespace Triptych\Frontend;

use Triptych\Languages;

/**
 * Emits `<link rel="alternate" hreflang>` tags for all configured languages
 * pointing at the same canonical post under each language prefix.
 */
final class HreflangEmitter
{
    public static function register(): void
    {
        add_action('wp_head', [self::class, 'emit'], 5);
    }

    public static function emit(): void
    {
        if (!is_singular()) {
            return;
        }
        $post = get_post();
        if (!$post instanceof \WP_Post) {
            return;
        }
        // Use a clean permalink without the prefix-injection filter so we can
        // inject every language ourselves.
        remove_filter('post_link', [PermalinkFilter::class, 'prefix']);
        remove_filter('page_link', [PermalinkFilter::class, 'prefix']);
        remove_filter('post_type_link', [PermalinkFilter::class, 'prefix']);

        $base = get_permalink($post);

        add_filter('post_link', [PermalinkFilter::class, 'prefix']);
        add_filter('page_link', [PermalinkFilter::class, 'prefix']);
        add_filter('post_type_link', [PermalinkFilter::class, 'prefix']);

        if (!is_string($base) || $base === '') {
            return;
        }

        foreach (Languages::all() as $slug => $label) {
            $url = PermalinkFilter::injectLang($base, $slug);
            printf(
                '<link rel="alternate" hreflang="%1$s" href="%2$s" />' . "\n",
                esc_attr($slug),
                esc_url($url)
            );
        }

        $default = Languages::default();
        $default_url = PermalinkFilter::injectLang($base, $default);
        printf(
            '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
            esc_url($default_url)
        );
    }
}
