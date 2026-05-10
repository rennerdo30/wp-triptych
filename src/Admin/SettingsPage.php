<?php

declare(strict_types=1);

namespace Triptych\Admin;

use Triptych\Fields;
use Triptych\Languages;
use Triptych\Translation\Translator;

/**
 * Triptych → top-level admin menu.
 *
 * Adds an "Overview" panel summarising configured languages, per-language
 * translation coverage across the canonical post storage, the active AI
 * endpoint/model (key redacted), and the registered Triptych fields with
 * their post-type assignments. The original settings form lives below.
 */
final class SettingsPage
{
    private const SLUG = 'triptych';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('wp_ajax_triptych_test_translate', [self::class, 'ajaxTest']);
    }

    public static function menu(): void
    {
        add_menu_page(
            __('Triptych', 'triptych'),
            __('Triptych', 'triptych'),
            'manage_options',
            self::SLUG,
            [self::class, 'render'],
            'dashicons-translation',
            80
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
            'default' => 'https://api.deepseek.com/v1',
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
            'default' => 'deepseek-v4-pro',
        ]);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $languages = (string) get_option('triptych_languages', 'zh:中文,ja:日本語,en:English');
        $default = (string) get_option('triptych_default_language', 'en');
        $endpoint = (string) get_option('triptych_endpoint', 'https://api.deepseek.com/v1');
        $api_key = (string) get_option('triptych_api_key', '');
        $model = (string) get_option('triptych_model', 'deepseek-v4-pro');

        $masked_key = $api_key === '' ? '' : str_repeat('•', max(8, min(24, strlen($api_key))));
        Languages::flushCache();
        $configured = Languages::all();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Triptych — Multilingual', 'triptych'); ?></h1>
            <p class="description">
                <?php esc_html_e('One canonical post, multiple language fields. AI translation per non-default language, no per-language post twins.', 'triptych'); ?>
            </p>

            <?php self::renderOverview($configured, $default, $endpoint, $model, $masked_key); ?>

            <hr>
            <h2 class="title"><?php esc_html_e('Settings', 'triptych'); ?></h2>
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
                                <?php esc_html_e('OpenAI-compatible base URL. Examples: https://api.deepseek.com/v1 (default), https://api.openai.com/v1, http://localhost:11434/v1 (Ollama).', 'triptych'); ?>
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

    /**
     * Overview panel: configured languages, translation coverage per
     * language (computed from `_triptych_post_title_<lang>` postmeta),
     * AI endpoint/model + redacted key, registered fields list.
     *
     * @param array<string, string> $configured
     */
    private static function renderOverview(array $configured, string $default, string $endpoint, string $model, string $masked_key): void
    {
        $coverage = self::languageCoverage(array_keys($configured), $default);
        $endpoint_display = $endpoint !== '' ? $endpoint : __('(not configured)', 'triptych');
        $key_display = $masked_key !== ''
            ? $masked_key
            : '<em>' . esc_html__('(not set)', 'triptych') . '</em>';
        $fields = Fields::all();

        echo '<h2 class="title">' . esc_html__('Overview', 'triptych') . '</h2>';

        echo '<table class="widefat striped" style="max-width:920px;"><tbody>';
        echo '<tr><th scope="row" style="width:240px;">' . esc_html__('Languages', 'triptych') . '</th><td>';
        $chips = [];
        foreach ($configured as $slug => $label) {
            $is_default = $slug === $default;
            $count = $coverage[$slug] ?? 0;
            $chip = sprintf(
                '<span style="display:inline-block;margin:2px 6px 2px 0;padding:3px 10px;background:%s;color:%s;border-radius:12px;font-size:12px;"><strong>%s</strong> %s · %d %s</span>',
                $is_default ? '#2271b1' : '#f0f0f1',
                $is_default ? '#fff' : '#1d2327',
                esc_html(strtoupper($slug)),
                esc_html($label),
                (int) $count,
                esc_html__('posts', 'triptych')
            );
            $chips[] = $chip;
        }
        echo wp_kses_post(implode(' ', $chips));
        echo '<p class="description" style="margin-top:6px;">' . esc_html__('Counts reflect canonical posts with at least one stored value in `_triptych_post_title_<lang>` (or its legacy fallbacks). The default language counts every published post.', 'triptych') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('AI endpoint', 'triptych') . '</th><td><code>' . esc_html($endpoint_display) . '</code></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Model', 'triptych') . '</th><td><code>' . esc_html($model !== '' ? $model : '(not set)') . '</code></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('API key', 'triptych') . '</th><td>' . wp_kses_post($key_display) . '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Last translated', 'triptych') . '</th><td><em>' . esc_html__('Per-translation timestamps are not yet tracked. Use the "Test translation" panel below to verify the endpoint is reachable.', 'triptych') . '</em></td></tr>';

        $tour_url = 'https://github.com/rennerdo30/wp-triptych#multilingual-sidebar';
        echo '<tr><th scope="row">' . esc_html__('Editor tour', 'triptych') . '</th><td><a href="' . esc_url($tour_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Multilingual sidebar walkthrough', 'triptych') . ' →</a></td></tr>';
        echo '</tbody></table>';

        echo '<h3 style="margin-top:24px;">' . esc_html__('Registered Triptych fields', 'triptych') . '</h3>';
        if ($fields === []) {
            echo '<p class="description">' . esc_html__('No fields registered — Triptych boots post_title and post_content automatically; these will appear once init has fired.', 'triptych') . '</p>';
        } else {
            echo '<table class="widefat striped" style="max-width:920px;"><thead><tr>';
            echo '<th style="width:240px;">' . esc_html__('Field', 'triptych') . '</th>';
            echo '<th style="width:140px;">' . esc_html__('Type', 'triptych') . '</th>';
            echo '<th>' . esc_html__('Label', 'triptych') . '</th>';
            echo '<th>' . esc_html__('Post types', 'triptych') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($fields as $name => $def) {
                $type = (string) ($def['type'] ?? 'text');
                $label = (string) ($def['label'] ?? $name);
                $pts = $def['post_types'] ?? [];
                $pt_display = (is_array($pts) && $pts !== [])
                    ? implode(', ', array_map(static fn ($p) => (string) $p, $pts))
                    : __('(all)', 'triptych');
                echo '<tr>';
                echo '<td><code>' . esc_html($name) . '</code></td>';
                echo '<td><code>' . esc_html($type) . '</code></td>';
                echo '<td>' . esc_html($label) . '</td>';
                echo '<td>' . esc_html($pt_display) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
    }

    /**
     * Compute how many published posts have a stored Triptych title per
     * language. The default language counts every published post (the
     * source of truth lives in post_title for that slug).
     *
     * Bounded at 5,000 posts and cached for 5 minutes via a transient
     * keyed off the languages list — site editors care about ballpark
     * coverage, not real-time numbers.
     *
     * @param string[] $slugs
     * @return array<string, int>
     */
    public static function languageCoverage(array $slugs, string $default): array
    {
        $cache_key = 'triptych_overview_coverage_' . md5(implode(',', $slugs) . '|' . $default);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        // Walk every post type that has at least one Triptych field
        // registered — those are the post types whose translation status
        // is meaningful. Filter to publish-state.
        $post_types = self::triptychPostTypes();

        $total_published = 0;
        foreach ($post_types as $pt) {
            $counts = wp_count_posts($pt);
            if (is_object($counts) && isset($counts->publish)) {
                $total_published += (int) $counts->publish;
            }
        }

        $totals = [];
        foreach ($slugs as $slug) {
            if ($slug === $default) {
                // Source language — every published post in a translatable
                // post type "has source" by definition.
                $totals[$slug] = $total_published;
                continue;
            }
            $totals[$slug] = self::countPostsWithLang($slug, $post_types);
        }

        set_transient($cache_key, $totals, 5 * MINUTE_IN_SECONDS);
        return $totals;
    }

    /**
     * Resolve the unique post types that have at least one Triptych field.
     * Empty `post_types` (i.e. fields that apply to ALL post types like
     * post_title / post_content) expand to every public post type that
     * supports an editor — same surface the wp-admin pill column targets.
     */
    public static function triptychPostTypes(): array
    {
        $explicit = [];
        $applies_to_all = false;
        foreach (Fields::all() as $def) {
            $allowed = (array) ($def['post_types'] ?? []);
            if ($allowed === []) {
                $applies_to_all = true;
                continue;
            }
            foreach ($allowed as $pt) {
                $explicit[(string) $pt] = true;
            }
        }
        if (!$applies_to_all) {
            return array_keys($explicit);
        }
        $all = get_post_types(['public' => true], 'names');
        unset($all['attachment']);  // never translated
        return array_values(array_unique(array_merge(array_keys($all), array_keys($explicit))));
    }

    /**
     * Count distinct posts that have ANY non-empty Triptych translation
     * for the given language slug — title, content, OR any custom field
     * the host theme registered. Direct SQL with a LIKE on the meta_key
     * prefix is the cheapest way to get a real coverage figure.
     */
    private static function countPostsWithLang(string $slug, array $post_types): int
    {
        if ($post_types === []) {
            return 0;
        }
        global $wpdb;
        $like = '_triptych_%_' . sanitize_key($slug);
        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        $params = array_merge([$like], $post_types);

        $sql = "SELECT COUNT(DISTINCT pm.post_id)
                  FROM {$wpdb->postmeta} pm
                  JOIN {$wpdb->posts}    p ON p.ID = pm.post_id
                 WHERE pm.meta_key LIKE %s
                   AND pm.meta_key NOT LIKE %s   /* skip _updated and _src_hashes sidecars */
                   AND pm.meta_key NOT LIKE %s
                   AND pm.meta_value <> ''
                   AND p.post_status = 'publish'
                   AND p.post_type IN ({$placeholders})";
        $params = array_merge(
            [$like, '_triptych_%_updated', '_triptych_%_src_hashes'],
            $post_types
        );

        $count = $wpdb->get_var($wpdb->prepare($sql, $params));
        return (int) $count;
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
