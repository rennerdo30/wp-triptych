<?php

declare(strict_types=1);

namespace Triptych\Editor;

use Triptych\Fields;
use Triptych\Languages;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST endpoints behind the Block Editor language UI.
 *
 *   GET  /wp-json/triptych/v1/post/<id>          → translation snapshot
 *                                                 (status + values for
 *                                                  every registered field)
 *   POST /wp-json/triptych/v1/save               → write one field/lang
 *
 * The translate route lives in Translation\Translator (it predates this
 * file). Endpoints here only deal with reading + persisting translation
 * state, not with calling the AI provider.
 */
final class SidebarRest
{
    public const NAMESPACE             = 'triptych/v1';
    public const META_UPDATED_SUFFIX   = '_updated';
    public const META_SRC_HASHES_SUFFIX = '_src_hashes';

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    public static function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/post/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => static fn (WP_REST_Request $r): bool =>
                current_user_can('edit_post', (int) $r['id']),
            'callback'            => [self::class, 'getState'],
        ]);

        register_rest_route(self::NAMESPACE, '/save', [
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => static fn (WP_REST_Request $r): bool =>
                current_user_can('edit_post', (int) $r['post_id']),
            'callback'            => [self::class, 'saveValue'],
            'args' => [
                'post_id'       => ['type' => 'integer', 'required' => true],
                'field'         => ['type' => 'string',  'required' => true],
                'lang'          => ['type' => 'string',  'required' => true],
                'value'         => ['type' => 'string',  'required' => true],
                // Optional: per-block source hashes captured at translate
                // time. Stored under `_triptych_<field>_<lang>_src_hashes`
                // and surfaced from /post/<id>; the editor compares
                // against the live source to mark drifted blocks stale.
                'source_hashes' => ['type' => 'array',   'required' => false, 'default' => []],
            ],
        ]);
    }

    public static function getState(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post_id = (int) $request['id'];
        $post    = get_post($post_id);
        if (!$post instanceof WP_Post) {
            return new WP_Error('triptych_no_post', __('Post not found.', 'triptych'), ['status' => 404]);
        }

        $languages   = Languages::all();
        $default     = Languages::default();
        $registry    = Fields::forPostType($post->post_type);

        // Always surface post_title + post_content even if the registry has
        // post-type-specific overrides, because the source-of-truth is the
        // editor — those two fields are always relevant.
        if (!isset($registry['post_title']) && Fields::all() !== []) {
            $registry = ['post_title' => Fields::all()['post_title']]
                + (isset(Fields::all()['post_content']) ? ['post_content' => Fields::all()['post_content']] : [])
                + $registry;
        }

        $fields = [];
        foreach ($registry as $field_name => $def) {
            $values = [];
            foreach (array_keys($languages) as $lang) {
                $envelope_key = Fields::metaKey($field_name, $lang);
                $envelope_val = (string) get_post_meta($post_id, $envelope_key, true);

                // Display value resolves through Fields::get() so legacy
                // ACF/Polylang flat postmeta and serialized groups surface
                // in the editor exactly like canonical Triptych storage.
                // The "envelope present?" bit drives the status pill so we
                // can still tell whether this came from Triptych or from
                // legacy storage that hasn't been written through yet.
                $val = $envelope_val !== ''
                    ? $envelope_val
                    : Fields::get($post_id, $field_name, $lang);

                $upat = (int) get_post_meta($post_id, $envelope_key . self::META_UPDATED_SUFFIX, true);

                $hashes = get_post_meta($post_id, $envelope_key . self::META_SRC_HASHES_SUFFIX, true);
                if (!is_array($hashes)) {
                    $hashes = [];
                }

                $values[$lang] = [
                    'value'         => $val,
                    'updated_at'    => $upat ?: null,
                    'has_value'     => $val !== '',
                    'has_envelope'  => $envelope_val !== '',
                    'source_hashes' => array_values(array_map('strval', $hashes)),
                ];
            }
            $fields[$field_name] = [
                'type'    => $def['type'] ?? 'text',
                'label'   => $def['label'] ?? $field_name,
                'values'  => $values,
            ];
        }

        return new WP_REST_Response([
            'post_id'   => $post_id,
            'post_type' => $post->post_type,
            'languages' => $languages,
            'default'   => $default,
            'fields'    => $fields,
        ], 200);
    }

    public static function saveValue(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post_id = (int) $request->get_param('post_id');
        $field   = sanitize_key((string) $request->get_param('field'));
        $lang    = sanitize_key((string) $request->get_param('lang'));
        $value   = (string) $request->get_param('value');

        if (!Languages::isValid($lang)) {
            return new WP_Error('triptych_bad_lang', __('Unknown language.', 'triptych'), ['status' => 400]);
        }
        if ($field === '') {
            return new WP_Error('triptych_bad_field', __('Field name required.', 'triptych'), ['status' => 400]);
        }

        // Native post columns get written via wp_update_post so revisions
        // and post-meta-cache invalidation flow through the usual path.
        // Translations of post_title/post_content for the DEFAULT language
        // also mirror to the native column so they remain visible to
        // queries that don't know about Triptych.
        $default = Languages::default();
        if (in_array($field, ['post_title', 'post_content', 'post_excerpt'], true) && $lang === $default) {
            $col = match ($field) {
                'post_title'   => 'post_title',
                'post_content' => 'post_content',
                'post_excerpt' => 'post_excerpt',
            };
            wp_update_post(['ID' => $post_id, $col => $value]);
        }

        Fields::set($post_id, $field, $lang, $value);

        // Track when each translation was last touched so the editor can
        // show "translated 2 days ago" / "stale, source has changed".
        $updated_key = Fields::metaKey($field, $lang) . self::META_UPDATED_SUFFIX;
        if ($value === '') {
            delete_post_meta($post_id, $updated_key);
        } else {
            update_post_meta($post_id, $updated_key, time());
        }

        // Per-block source-hash snapshot — captured at translate time so
        // the editor can compare against live source on later loads and
        // mark drifted blocks stale. Only persist when the caller
        // supplied an array; an empty list means "leave existing
        // snapshot alone" rather than "no hashes".
        $src_hashes = $request->get_param('source_hashes');
        $hashes_key = Fields::metaKey($field, $lang) . self::META_SRC_HASHES_SUFFIX;
        if (is_array($src_hashes)) {
            if ($value === '' || $src_hashes === []) {
                delete_post_meta($post_id, $hashes_key);
            } else {
                $clean = array_values(array_map('strval', $src_hashes));
                update_post_meta($post_id, $hashes_key, $clean);
            }
        } elseif ($value === '') {
            // Empty value implies the translation went away — drop the
            // hash snapshot too.
            delete_post_meta($post_id, $hashes_key);
        }

        return new WP_REST_Response([
            'saved'      => true,
            'field'      => $field,
            'lang'       => $lang,
            'updated_at' => $value === '' ? null : time(),
        ], 200);
    }
}
