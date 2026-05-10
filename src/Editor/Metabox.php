<?php

declare(strict_types=1);

namespace Triptych\Editor;

use Triptych\Fields;
use Triptych\Languages;

/**
 * Classic-editor metabox: renders one tabbed panel per registered multilingual field.
 *
 * Block-editor support is on the roadmap; for now the metabox lives under the
 * editor in the classic UI and gracefully no-ops in Gutenberg (it still shows up
 * in the sidebar as a fallback metabox, just without the tab JS niceties).
 */
final class Metabox
{
    private const NONCE = 'triptych_save_fields';

    public static function register(): void
    {
        add_action('add_meta_boxes', [self::class, 'add'], 10, 2);
        add_action('save_post', [self::class, 'save'], 10, 2);
    }

    public static function add(string $post_type, \WP_Post $post): void
    {
        $fields = Fields::forPostType($post_type);
        if ($fields === []) {
            return;
        }
        add_meta_box(
            'triptych-multilingual',
            __('Triptych — Multilingual Fields', 'triptych'),
            [self::class, 'render'],
            $post_type,
            'normal',
            'high'
        );
    }

    public static function render(\WP_Post $post): void
    {
        $fields = Fields::forPostType($post->post_type);
        $languages = Languages::all();
        $default = Languages::default();
        wp_nonce_field(self::NONCE, self::NONCE);
        ?>
        <div class="triptych-mb" data-default-lang="<?php echo esc_attr($default); ?>">
            <div class="triptych-tabs" role="tablist">
                <?php foreach ($languages as $slug => $label): ?>
                    <button type="button"
                            class="triptych-tab<?php echo $slug === $default ? ' is-active' : ''; ?>"
                            role="tab"
                            data-lang="<?php echo esc_attr($slug); ?>"
                            aria-selected="<?php echo $slug === $default ? 'true' : 'false'; ?>">
                        <span class="triptych-tab-slug"><?php echo esc_html(strtoupper($slug)); ?></span>
                        <span class="triptych-tab-label"><?php echo esc_html($label); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($fields as $key => $def): ?>
                <fieldset class="triptych-field" data-field="<?php echo esc_attr($key); ?>">
                    <legend><?php echo esc_html($def['label']); ?></legend>
                    <?php foreach ($languages as $slug => $label):
                        $meta_key = Fields::metaKey($key, $slug);
                        $value = (string) get_post_meta($post->ID, $meta_key, true);
                        if ($value === '' && $key === 'post_title' && $slug === $default) {
                            $value = (string) $post->post_title;
                        } elseif ($value === '' && $key === 'post_content' && $slug === $default) {
                            $value = (string) $post->post_content;
                        }
                        $input_id = "triptych-{$key}-{$slug}";
                        $input_name = "triptych[{$key}][{$slug}]";
                        $hidden = $slug === $default ? '' : ' hidden';
                    ?>
                        <div class="triptych-pane<?php echo esc_attr($hidden); ?>" data-lang="<?php echo esc_attr($slug); ?>">
                            <label for="<?php echo esc_attr($input_id); ?>" class="screen-reader-text">
                                <?php echo esc_html(sprintf('%s — %s', $def['label'], $label)); ?>
                            </label>
                            <?php if ($def['type'] === 'textarea' || $def['type'] === 'wysiwyg'): ?>
                                <textarea id="<?php echo esc_attr($input_id); ?>"
                                          name="<?php echo esc_attr($input_name); ?>"
                                          rows="8"
                                          class="large-text triptych-input"
                                          data-field="<?php echo esc_attr($key); ?>"
                                          data-lang="<?php echo esc_attr($slug); ?>"><?php echo esc_textarea($value); ?></textarea>
                            <?php else: ?>
                                <input type="text"
                                       id="<?php echo esc_attr($input_id); ?>"
                                       name="<?php echo esc_attr($input_name); ?>"
                                       value="<?php echo esc_attr($value); ?>"
                                       class="large-text triptych-input"
                                       data-field="<?php echo esc_attr($key); ?>"
                                       data-lang="<?php echo esc_attr($slug); ?>" />
                            <?php endif; ?>
                            <div class="triptych-actions">
                                <?php if ($slug !== $default): ?>
                                    <button type="button"
                                            class="button triptych-translate"
                                            data-from="<?php echo esc_attr($default); ?>"
                                            data-to="<?php echo esc_attr($slug); ?>"
                                            data-field="<?php echo esc_attr($key); ?>">
                                        <?php
                                        printf(
                                            /* translators: 1: source lang, 2: target lang */
                                            esc_html__('AI translate %1$s → %2$s', 'triptych'),
                                            esc_html(strtoupper($default)),
                                            esc_html(strtoupper($slug))
                                        );
                                        ?>
                                    </button>
                                <?php endif; ?>
                                <span class="triptych-status" aria-live="polite"></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </fieldset>
            <?php endforeach; ?>
        </div>
        <?php
    }

    public static function save(int $post_id, \WP_Post $post): void
    {
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce((string) $_POST[self::NONCE], self::NONCE)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        if (!isset($_POST['triptych']) || !is_array($_POST['triptych'])) {
            return;
        }

        $fields = Fields::forPostType($post->post_type);
        $languages = Languages::all();

        $remove_post_filters = remove_filter('content_save_pre', 'wp_filter_post_kses');
        foreach ($_POST['triptych'] as $field => $by_lang) {
            $field = sanitize_key((string) $field);
            if (!isset($fields[$field]) || !is_array($by_lang)) {
                continue;
            }
            foreach ($by_lang as $lang => $value) {
                $lang = sanitize_key((string) $lang);
                if (!isset($languages[$lang])) {
                    continue;
                }
                $raw = wp_unslash((string) $value);
                $sanitized = $fields[$field]['type'] === 'text'
                    ? sanitize_text_field($raw)
                    : wp_kses_post($raw);
                Fields::set($post_id, $field, $lang, $sanitized);
            }
        }
        if ($remove_post_filters) {
            add_filter('content_save_pre', 'wp_filter_post_kses');
        }
    }
}
