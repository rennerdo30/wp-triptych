<?php

declare(strict_types=1);

namespace Triptych\Admin;

use Triptych\Languages;
use Triptych\Translation\Translator;

/**
 * Settings → Triptych. Languages, default language, and AI endpoint config.
 */
final class SettingsPage
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('wp_ajax_triptych_test_translate', [self::class, 'ajaxTest']);
    }

    public static function menu(): void
    {
        add_options_page(
            __('Triptych', 'triptych'),
            __('Triptych', 'triptych'),
            'manage_options',
            'triptych',
            [self::class, 'render']
        );
    }

    public static function registerSettings(): void
    {
        register_setting('triptych', 'triptych_languages', [
            'type' => 'string',
            'sanitize_callback' => static fn($v) => sanitize_text_field((string) $v),
            'default' => 'zh:中文,ja:日本語,en:English',
        ]);
        register_setting('triptych', 'triptych_default_language', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_key',
            'default' => 'en',
        ]);
        register_setting('triptych', 'triptych_endpoint', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => 'https://api.openai.com/v1',
        ]);
        register_setting('triptych', 'triptych_api_key', [
            'type' => 'string',
            'sanitize_callback' => static fn($v) => trim((string) $v),
            'default' => '',
            'show_in_rest' => false,
        ]);
        register_setting('triptych', 'triptych_model', [
            'type' => 'string',
            'sanitize_callback' => static fn($v) => sanitize_text_field((string) $v),
            'default' => 'gpt-4o-mini',
        ]);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $languages = (string) get_option('triptych_languages', 'zh:中文,ja:日本語,en:English');
        $default = (string) get_option('triptych_default_language', 'en');
        $endpoint = (string) get_option('triptych_endpoint', 'https://api.openai.com/v1');
        $api_key = (string) get_option('triptych_api_key', '');
        $model = (string) get_option('triptych_model', 'gpt-4o-mini');

        $masked_key = $api_key === '' ? '' : str_repeat('•', max(8, min(24, strlen($api_key))));
        Languages::flushCache();
        $configured = Languages::all();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Triptych — Multilingual Settings', 'triptych'); ?></h1>
            <p class="description">
                <?php esc_html_e('One canonical post, multiple language fields. Configure your languages, default, and AI translation endpoint here.', 'triptych'); ?>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields('triptych'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="triptych_languages"><?php esc_html_e('Languages', 'triptych'); ?></label></th>
                        <td>
                            <input type="text" id="triptych_languages" name="triptych_languages"
                                   value="<?php echo esc_attr($languages); ?>" class="regular-text" style="width: 480px;" />
                            <p class="description">
                                <?php esc_html_e('Comma-separated slug:Label pairs. Example: zh:中文,ja:日本語,en:English', 'triptych'); ?>
                            </p>
                            <p class="description">
                                <?php
                                /* translators: %s: comma-separated list of currently configured language slugs */
                                printf(esc_html__('Currently configured: %s', 'triptych'), esc_html(implode(', ', array_keys($configured))));
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="triptych_default_language"><?php esc_html_e('Default language', 'triptych'); ?></label></th>
                        <td>
                            <select id="triptych_default_language" name="triptych_default_language">
                                <?php foreach ($configured as $slug => $label): ?>
                                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($default, $slug); ?>>
                                        <?php echo esc_html($slug . ' — ' . $label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Source language for AI translation, and fallback when a field is empty.', 'triptych'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="triptych_endpoint"><?php esc_html_e('Endpoint URL', 'triptych'); ?></label></th>
                        <td>
                            <input type="url" id="triptych_endpoint" name="triptych_endpoint"
                                   value="<?php echo esc_attr($endpoint); ?>" class="regular-text" />
                            <p class="description">
                                <?php esc_html_e('OpenAI-compatible base URL. Examples: https://api.openai.com/v1, https://api.anthropic.com/v1, http://localhost:11434/v1 (Ollama).', 'triptych'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="triptych_api_key"><?php esc_html_e('API key', 'triptych'); ?></label></th>
                        <td>
                            <input type="password" id="triptych_api_key" name="triptych_api_key"
                                   value="<?php echo esc_attr($api_key); ?>"
                                   placeholder="<?php echo esc_attr($masked_key); ?>"
                                   class="regular-text" autocomplete="new-password" />
                            <p class="description"><?php esc_html_e('Stored as a wp_option; visible only to admins.', 'triptych'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="triptych_model"><?php esc_html_e('Model name', 'triptych'); ?></label></th>
                        <td>
                            <input type="text" id="triptych_model" name="triptych_model"
                                   value="<?php echo esc_attr($model); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('Examples: gpt-4o-mini, claude-haiku-4.5, mistral-small-latest, llama3.2.', 'triptych'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr />
            <h2><?php esc_html_e('Test translation', 'triptych'); ?></h2>
            <p>
                <input type="text" id="triptych-test-text" class="regular-text"
                       value="<?php echo esc_attr__('Hello, world.', 'triptych'); ?>" />
                <button type="button" class="button" id="triptych-test-btn">
                    <?php esc_html_e('Translate to first non-default language', 'triptych'); ?>
                </button>
            </p>
            <pre id="triptych-test-output" style="background:#f6f7f7;padding:12px;border:1px solid #dcdcde;min-height:48px;"></pre>

            <script>
            (function() {
                const btn = document.getElementById('triptych-test-btn');
                const out = document.getElementById('triptych-test-output');
                if (!btn) return;
                btn.addEventListener('click', async () => {
                    const text = document.getElementById('triptych-test-text').value;
                    out.textContent = <?php echo wp_json_encode(__('Translating…', 'triptych')); ?>;
                    try {
                        const r = await fetch(ajaxurl + '?action=triptych_test_translate', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: 'text=' + encodeURIComponent(text) + '&_wpnonce=' + <?php echo wp_json_encode(wp_create_nonce('triptych_test')); ?>
                        });
                        const j = await r.json();
                        out.textContent = j.success ? j.data.translated : (j.data && j.data.message) || 'Error';
                    } catch (e) {
                        out.textContent = String(e);
                    }
                });
            })();
            </script>
        </div>
        <?php
    }

    public static function ajaxTest(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Forbidden.', 'triptych')], 403);
        }
        check_ajax_referer('triptych_test', '_wpnonce');

        $text = isset($_POST['text']) ? wp_unslash((string) $_POST['text']) : '';
        $default = Languages::default();
        $other = null;
        foreach (Languages::slugs() as $slug) {
            if ($slug !== $default) {
                $other = $slug;
                break;
            }
        }
        if ($other === null) {
            wp_send_json_error(['message' => __('Configure at least two languages first.', 'triptych')]);
        }

        $result = Translator::translate($default, $other, $text);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['translated' => $result, 'from' => $default, 'to' => $other]);
    }
}
