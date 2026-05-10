<?php

declare(strict_types=1);

namespace Triptych\Translation;

use Triptych\Languages;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * OpenAI-compatible Chat Completions client.
 *
 * Speaks the OpenAI `/v1/chat/completions` schema, which means it works with
 * OpenAI, Anthropic via Claude API, Mistral, Together, Groq, local Ollama,
 * llama.cpp server, vLLM, and any other endpoint that implements the same
 * surface. Endpoint URL, API key, and model name are all configurable.
 */
final class Translator
{
    public static function registerRest(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoute']);
    }

    public static function registerRoute(): void
    {
        register_rest_route('triptych/v1', '/translate', [
            'methods' => WP_REST_Server::CREATABLE,
            'permission_callback' => static fn(): bool => current_user_can('edit_posts'),
            'callback' => [self::class, 'handle'],
            'args' => [
                'from' => ['type' => 'string', 'required' => true],
                'to' => ['type' => 'string', 'required' => true],
                'text' => ['type' => 'string', 'required' => true],
                'field' => ['type' => 'string', 'required' => false],
            ],
        ]);
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $from = sanitize_key((string) $request->get_param('from'));
        $to = sanitize_key((string) $request->get_param('to'));
        $text = (string) $request->get_param('text');
        $field = sanitize_key((string) ($request->get_param('field') ?? ''));

        if (!Languages::isValid($from) || !Languages::isValid($to)) {
            return new WP_Error('triptych_bad_lang', __('Unknown language.', 'triptych'), ['status' => 400]);
        }
        if (trim($text) === '') {
            return new WP_Error('triptych_empty', __('Source text is empty.', 'triptych'), ['status' => 400]);
        }

        $result = self::translate($from, $to, $text, $field);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return new WP_REST_Response(['translated' => $result], 200);
    }

    /**
     * Run a translation against the configured Chat Completions endpoint.
     */
    public static function translate(string $from, string $to, string $text, string $field = ''): string|WP_Error
    {
        $endpoint = rtrim((string) get_option('triptych_endpoint', 'https://api.deepseek.com/v1'), '/');
        $api_key = (string) get_option('triptych_api_key', '');
        $model = (string) get_option('triptych_model', 'deepseek-v4-pro');

        if ($endpoint === '') {
            return new WP_Error('triptych_no_endpoint', __('Translation endpoint is not configured.', 'triptych'), ['status' => 500]);
        }
        if ($api_key === '') {
            return new WP_Error('triptych_no_key', __('Translation API key is not configured.', 'triptych'), ['status' => 500]);
        }

        $languages = Languages::all();
        $from_label = $languages[$from] ?? $from;
        $to_label = $languages[$to] ?? $to;

        $system = sprintf(
            'You are a professional translator. Translate from %1$s to %2$s. Preserve markup, line breaks, and formatting. Return only the translation, no commentary.',
            $from_label,
            $to_label
        );
        if ($field === 'post_title') {
            $system .= ' This is a page title — keep it concise and natural.';
        }

        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $text],
            ],
            'temperature' => 0.2,
        ];

        $response = wp_remote_post($endpoint . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
            'timeout' => 60,
        ]);

        if ($response instanceof WP_Error) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            $msg = is_array($decoded) && isset($decoded['error']['message'])
                ? (string) $decoded['error']['message']
                : sprintf(__('HTTP %d from translation endpoint.', 'triptych'), (int) $code);
            return new WP_Error('triptych_http_error', $msg, ['status' => 502]);
        }

        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || $content === '') {
            return new WP_Error('triptych_bad_response', __('Translation endpoint returned no content.', 'triptych'), ['status' => 502]);
        }

        return trim($content);
    }
}
