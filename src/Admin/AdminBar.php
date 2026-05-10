<?php

declare(strict_types=1);

namespace Triptych\Admin;

use Triptych\Languages;

/**
 * Admin-bar language switcher (Polylang-style).
 *
 * Adds a top-bar item to the wp-admin toolbar that lets the current
 * user pick which Triptych language they want to view/edit across the
 * entire admin UI. Selection is persisted as a per-user meta value
 * (`triptych_admin_lang`) so the choice survives page loads but does
 * NOT bleed across users or sessions on logout.
 *
 * The chosen language is consumed by:
 *   - Editor\AssetsEnqueue, which forwards it to the Block Editor JS
 *     (window.TriptychEditor.adminLang) so the in-canvas pill bar
 *     opens on the user's preferred language.
 *   - Optional list-table title override (the_title filter) — replaces
 *     the native post_title with the per-language Triptych value when
 *     viewing edit.php.
 */
final class AdminBar
{
    public const META_KEY = 'triptych_admin_lang';
    private const QUERY_ARG = 'triptych_admin_lang';
    private const NONCE_ACTION = 'triptych_admin_lang_switch';
    private const NONCE_QUERY_ARG = '_triptych_nonce';

    public static function register(): void
    {
        add_action('admin_bar_menu', [self::class, 'render'], 80);
        // Handle the language-switch click as early as possible so the
        // redirect fires before any other admin code emits output.
        // admin_init's default priority of 10 leaves room for plugins
        // that legitimately need to run before us; priority 1 keeps the
        // user-meta write and redirect on the critical path.
        add_action('admin_init', [self::class, 'handleSwitch'], 1);
        add_filter('the_title', [self::class, 'filterListTableTitle'], 10, 2);
    }

    /**
     * Get the user's preferred admin language. Falls back to the
     * configured default when no preference is set or the stored value
     * is no longer valid (e.g. a language was removed from settings).
     */
    public static function getAdminLang(): string
    {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return Languages::default();
        }
        $stored = (string) get_user_meta($user_id, self::META_KEY, true);
        if ($stored !== '' && Languages::isValid($stored)) {
            return $stored;
        }
        return Languages::default();
    }

    public static function render(\WP_Admin_Bar $bar): void
    {
        if (!is_user_logged_in() || !is_admin_bar_showing()) {
            return;
        }

        $languages = Languages::all();
        if ($languages === []) {
            return;
        }

        $active = self::getAdminLang();
        $active_label = $languages[$active] ?? $active;

        $title = sprintf(
            '<span class="ab-icon dashicons dashicons-translation" aria-hidden="true" style="top:2px"></span>'
                . '<span class="ab-label">%s: <strong>%s</strong></span>',
            esc_html__('Triptych', 'triptych'),
            esc_html(strtoupper($active))
        );

        $bar->add_node([
            'id'    => 'triptych-admin-lang',
            'title' => $title,
            'href'  => false,
            'meta'  => [
                'title' => sprintf(
                    /* translators: %s: active language label */
                    __('Triptych admin language: %s', 'triptych'),
                    $active_label
                ),
            ],
        ]);

        foreach ($languages as $slug => $label) {
            $is_active = ($slug === $active);
            $node_title = sprintf(
                '<span class="triptych-ab-slug" style="font-family:ui-monospace,Menlo,Consolas,monospace;letter-spacing:.08em">%s</span> &nbsp; %s%s',
                esc_html(strtoupper($slug)),
                esc_html($label),
                $is_active ? ' &nbsp;<span aria-hidden="true">✓</span>' : ''
            );

            $bar->add_node([
                'parent' => 'triptych-admin-lang',
                'id'     => 'triptych-admin-lang-' . $slug,
                'title'  => $node_title,
                'href'   => $is_active ? '#' : self::switchUrl($slug),
                'meta'   => [
                    'class' => $is_active ? 'triptych-admin-lang-active' : '',
                ],
            ]);
        }
    }

    /**
     * Handle ?triptych_admin_lang=ja&_triptych_nonce=… on any admin
     * request. Updates user meta then redirects back to the originating
     * page (or the dashboard if no referer).
     */
    public static function handleSwitch(): void
    {
        if (!is_admin() || !is_user_logged_in()) {
            return;
        }
        if (!isset($_GET[self::QUERY_ARG], $_GET[self::NONCE_QUERY_ARG])) {
            return;
        }
        if (!current_user_can('read')) {
            return;
        }

        $nonce = (string) wp_unslash($_GET[self::NONCE_QUERY_ARG]);
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        $requested = sanitize_key((string) wp_unslash($_GET[self::QUERY_ARG]));
        if ($requested !== '' && Languages::isValid($requested)) {
            update_user_meta(get_current_user_id(), self::META_KEY, $requested);
        }

        // Prefer the current request URI (minus our params) over the HTTP
        // Referer — that way the user stays on the screen they clicked
        // from, even when the browser stripped the Referer header (privacy
        // settings, cross-origin redirects, etc).
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $target = '';
        if ($request_uri !== '') {
            $target = remove_query_arg([self::QUERY_ARG, self::NONCE_QUERY_ARG], $request_uri);
        }
        if ($target === '') {
            $referer = wp_get_referer();
            if (is_string($referer) && $referer !== '') {
                $target = remove_query_arg([self::QUERY_ARG, self::NONCE_QUERY_ARG], $referer);
            }
        }
        if ($target === '') {
            $target = admin_url();
        }

        wp_safe_redirect($target);
        exit;
    }

    /**
     * Override the post-list title column with the user's preferred
     * language value, when one is stored under Triptych. Skips the
     * source language to avoid wasted lookups, and skips contexts
     * outside edit.php.
     */
    public static function filterListTableTitle(string $title, $post_id = 0): string
    {
        if (!is_admin()) {
            return $title;
        }
        // Restrict to list-table screens. edit.php is the standard CPT
        // list table; some screens (e.g. attachments) use upload.php.
        // The list-table inline-edit AJAX action also uses 'inline-save',
        // which goes through admin-ajax.php — pagenow there is
        // 'admin-ajax.php'. We handle that case via wp_doing_ajax() so
        // the inline-save response reflects the user's chosen language.
        $pagenow = isset($GLOBALS['pagenow']) ? (string) $GLOBALS['pagenow'] : '';
        $on_list_table = ($pagenow === 'edit.php');
        $on_ajax_inline = function_exists('wp_doing_ajax') && wp_doing_ajax()
            && isset($_REQUEST['action']) && $_REQUEST['action'] === 'inline-save';
        if (!$on_list_table && !$on_ajax_inline) {
            return $title;
        }
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return $title;
        }

        $lang = self::getAdminLang();
        if ($lang === Languages::default()) {
            return $title;
        }

        // Direct postmeta read — calling Fields::get() here would
        // trigger a fallback chain that returns the source title for
        // posts without a translation, which would just look like a
        // no-op. Reading the canonical key lets us keep the source
        // title visible (with no override) when no translation exists.
        $meta_key = '_triptych_post_title_' . sanitize_key($lang);
        $value = (string) get_post_meta($post_id, $meta_key, true);
        if ($value === '') {
            return $title;
        }
        return $value;
    }

    private static function switchUrl(string $slug): string
    {
        $base = self::currentUrl();
        $url = add_query_arg([
            self::QUERY_ARG       => $slug,
            self::NONCE_QUERY_ARG => wp_create_nonce(self::NONCE_ACTION),
        ], $base);
        return $url;
    }

    /**
     * Build a same-host URL for the page the admin bar is currently
     * rendering on. We deliberately avoid home_url() / site_url() here
     * because companion plugins (Polylang, WPML, multilingual themes)
     * filter both to inject language prefixes — that would send the
     * switch link to a front-end URL, which never fires admin_init and
     * silently swallows the click.
     *
     * Strategy:
     *   - If we're in admin: build the URL from admin_url() + the path
     *     under wp-admin/ that the user is currently viewing.
     *   - Otherwise: fall back to REQUEST_URI as a relative URL, which
     *     add_query_arg() treats as same-page.
     */
    private static function currentUrl(): string
    {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ($request_uri === '') {
            return admin_url();
        }
        // Strip our own params from the round-trip URL so repeat clicks
        // don't accumulate stale query args.
        $request_uri = remove_query_arg([self::QUERY_ARG, self::NONCE_QUERY_ARG], $request_uri);

        if (is_admin()) {
            // Extract the path under /wp-admin/ from the current request
            // and rebuild against admin_url() — bypasses any home_url
            // filter that would point the link at the front end.
            $admin_root = parse_url(admin_url(), PHP_URL_PATH) ?: '/wp-admin/';
            $pos = strpos($request_uri, $admin_root);
            if ($pos !== false) {
                $tail = substr($request_uri, $pos + strlen($admin_root));
                return admin_url(ltrim($tail, '/'));
            }
            // No /wp-admin/ in REQUEST_URI (shouldn't happen on admin
            // pages, but possible under reverse proxies that rewrite
            // the path). Fall back to a relative URL.
        }

        return $request_uri;
    }
}
