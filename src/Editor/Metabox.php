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

        $isBlockEditor = function_exists('use_block_editor_for_post_type')
            && use_block_editor_for_post_type($post_type);

        // Split fields into "scalar" (text/textarea/wysiwyg) and "structured"
        // (repeater). On the Block Editor screen the scalar fields are
        // already covered by the in-canvas language UI (admin-editor.js
        // swaps post_title / post_content / etc. when the language pill
        // changes), so we suppress the redundant scalar metabox there.
        // Repeater fields have no in-canvas equivalent and therefore
        // ALWAYS render via this metabox — Gutenberg shows registered
        // metaboxes in a "Meta boxes" pane below the canvas, and the
        // legacy metabox-bridge handles save_post submission.
        $scalars = [];
        $structured = [];
        foreach ($fields as $key => $def) {
            if (($def['type'] ?? 'text') === 'repeater') {
                $structured[$key] = $def;
            } else {
                $scalars[$key] = $def;
            }
        }

        if ($scalars !== [] && !$isBlockEditor) {
            add_meta_box(
                'triptych-multilingual',
                __('Triptych — Multilingual Fields', 'triptych'),
                [self::class, 'renderScalars'],
                $post_type,
                'normal',
                'high'
            );
        }

        if ($structured !== []) {
            add_meta_box(
                'triptych-multilingual-structured',
                __('Triptych — Structured Fields', 'triptych'),
                [self::class, 'renderStructured'],
                $post_type,
                'normal',
                'high'
            );
        }
    }

    /**
     * Public alias for backward-compatibility. Older callers (or sites
     * that hot-link to ::render) still get the scalar tabbed metabox.
     */
    public static function render(\WP_Post $post): void
    {
        self::renderScalars($post);
    }

    public static function renderScalars(\WP_Post $post): void
    {
        $allFields = Fields::forPostType($post->post_type);
        $fields = [];
        foreach ($allFields as $k => $def) {
            if (($def['type'] ?? 'text') !== 'repeater') {
                $fields[$k] = $def;
            }
        }
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

    /**
     * Render structured (repeater) multilingual fields.
     *
     * Each field gets:
     *   - One language-tab strip (zh / ja / en).
     *   - One row container per language.
     *   - A hidden textarea per language whose value mirrors the
     *     newline-joined `A | B | C` shorthand on every row mutation.
     *
     * The actual row UI (add/remove/drag-reorder, sub-field inputs) is
     * built by `assets/js/admin-metabox-repeater.js`. PHP just emits the
     * skeleton + a JSON config blob the JS hydrates from.
     */
    public static function renderStructured(\WP_Post $post): void
    {
        $allFields = Fields::forPostType($post->post_type);
        $fields = [];
        foreach ($allFields as $k => $def) {
            if (($def['type'] ?? 'text') === 'repeater') {
                $fields[$k] = $def;
            }
        }
        if ($fields === []) {
            return;
        }
        $languages = Languages::all();
        $default = Languages::default();
        wp_nonce_field(self::NONCE, self::NONCE);
        ?>
        <div class="triptych-mb triptych-mb-structured" data-default-lang="<?php echo esc_attr($default); ?>">
            <?php foreach ($fields as $key => $def):
                $separator = $def['separator'] ?? '|';
                $subfields = $def['subfields'] ?? [];
                $field_dom_id = 'triptych-rep-' . $key;
                $config = [
                    'field'     => $key,
                    'separator' => $separator,
                    'subfields' => array_values($subfields),
                    'languages' => array_keys($languages),
                    'default'   => $default,
                    'i18n'      => [
                        'addRow'    => __('Add row', 'triptych'),
                        'removeRow' => __('Remove row', 'triptych'),
                        'dragRow'   => __('Drag to reorder', 'triptych'),
                        'noRows'    => __('No rows yet — click "Add row" to start.', 'triptych'),
                    ],
                ];
            ?>
                <fieldset class="triptych-field triptych-field-repeater"
                          data-field="<?php echo esc_attr($key); ?>"
                          id="<?php echo esc_attr($field_dom_id); ?>">
                    <legend><?php echo esc_html($def['label']); ?></legend>

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

                    <?php foreach ($languages as $slug => $label):
                        $meta_key = Fields::metaKey($key, $slug);
                        $value = (string) get_post_meta($post->ID, $meta_key, true);
                        // Fall back through Fields::readLegacy so editors
                        // see schedule rows that were imported under the
                        // bare `<field>` postmeta key (older seeders, ACF
                        // group arrays, flat per-lang keys). Without this
                        // the repeater hydrates empty even though the
                        // front-end already renders the data correctly.
                        if ($value === '') {
                            $value = Fields::readLegacy($post->ID, $key, $slug);
                        }
                        $hidden = $slug === $default ? '' : ' hidden';
                    ?>
                        <div class="triptych-pane<?php echo esc_attr($hidden); ?>"
                             data-lang="<?php echo esc_attr($slug); ?>">
                            <div class="triptych-rep-rows" data-lang="<?php echo esc_attr($slug); ?>"></div>
                            <div class="triptych-rep-actions">
                                <button type="button"
                                        class="button button-secondary triptych-rep-add"
                                        data-lang="<?php echo esc_attr($slug); ?>">
                                    + <?php esc_html_e('Add row', 'triptych'); ?>
                                </button>
                                <?php if ($slug !== $default): ?>
                                    <button type="button"
                                            class="button triptych-rep-translate"
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
                            <textarea
                                class="triptych-rep-shadow"
                                name="<?php echo esc_attr("triptych[{$key}][{$slug}]"); ?>"
                                data-field="<?php echo esc_attr($key); ?>"
                                data-lang="<?php echo esc_attr($slug); ?>"
                                aria-hidden="true"
                                tabindex="-1"
                                hidden><?php echo esc_textarea($value); ?></textarea>
                        </div>
                    <?php endforeach; ?>

                    <script type="application/json" class="triptych-rep-config">
                        <?php echo wp_json_encode($config); ?>
                    </script>
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
                $type = $fields[$field]['type'] ?? 'text';
                if ($type === 'text') {
                    $sanitized = sanitize_text_field($raw);
                } elseif ($type === 'repeater') {
                    // Repeater shadow value is a newline-joined string of
                    // separator-delimited cells. Sanitize per-line, drop
                    // empty lines, and re-join. Keeps the canonical shape
                    // the consumer parses against.
                    $lines = preg_split('/\r?\n/', $raw) ?: [];
                    $clean = [];
                    foreach ($lines as $line) {
                        $line = sanitize_text_field((string) $line);
                        if ($line !== '') {
                            $clean[] = $line;
                        }
                    }
                    $sanitized = implode("\n", $clean);
                } else {
                    $sanitized = wp_kses_post($raw);
                }
                Fields::set($post_id, $field, $lang, $sanitized);
            }
        }
        if ($remove_post_filters) {
            add_filter('content_save_pre', 'wp_filter_post_kses');
        }
    }
}
