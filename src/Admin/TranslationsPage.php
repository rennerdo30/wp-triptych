<?php

declare(strict_types=1);

namespace Triptych\Admin;

use Triptych\Fields;
use Triptych\Languages;
use Triptych\Translation\Translator;
use Triptych\Editor\SidebarRest;

/**
 * Triptych → Translations submenu.
 *
 * Raw translation-coverage table for editors. Lists every translatable
 * post (publish-state, in any post type with at least one Triptych
 * field) and shows one status pill per registered language. Includes:
 *
 *   - Filter row: post type, language, search-by-title.
 *   - Per-row "Translate missing" button → fills empty post_title +
 *     post_content for every non-default language via Translator.
 *   - Bulk translate: serialized batches of 5 across the filtered set
 *     for a chosen language.
 *   - Collapsible raw data viewer: per-row grid of every registered
 *     field × every language showing the raw `_triptych_<field>_<lang>`
 *     postmeta values (or fallback resolution from Fields::get).
 *
 * Reuses SettingsPage::languageCoverage() for the header summary so the
 * coverage math has a single source of truth.
 */
final class TranslationsPage
{
    private const SLUG = 'triptych-translations';
    private const PAGE_SIZE = 200;

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('wp_ajax_triptych_translate_missing', [self::class, 'ajaxTranslateMissing']);
        add_action('wp_ajax_triptych_bulk_translate', [self::class, 'ajaxBulkTranslate']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            'triptych',
            __('Translations', 'triptych'),
            __('Translations', 'triptych'),
            'manage_options',
            self::SLUG,
            [self::class, 'render']
        );
    }

    public static function enqueueAssets(string $hook): void
    {
        // add_submenu_page returns a hook like "triptych_page_triptych-translations".
        if (!is_string($hook) || !str_ends_with($hook, self::SLUG)) {
            return;
        }
        wp_register_style('triptych-translations', false, [], TRIPTYCH_VERSION);
        wp_enqueue_style('triptych-translations');
        wp_add_inline_style('triptych-translations', self::inlineCss());
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        Languages::flushCache();
        $languages = Languages::all();
        $default = Languages::default();
        $post_types = SettingsPage::triptychPostTypes();

        $filter_pt   = isset($_GET['pt']) ? sanitize_key((string) $_GET['pt']) : '';
        $filter_lang = isset($_GET['lang']) ? sanitize_key((string) $_GET['lang']) : '';
        $search      = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '';
        $paged       = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;

        if ($filter_pt !== '' && !in_array($filter_pt, $post_types, true)) {
            $filter_pt = '';
        }
        if ($filter_lang !== '' && !isset($languages[$filter_lang])) {
            $filter_lang = '';
        }

        $coverage = SettingsPage::languageCoverage(array_keys($languages), $default);

        // Build the row dataset.
        [$rows, $total] = self::queryRows($post_types, $filter_pt, $filter_lang, $default, $search, $paged);

        $page_url_base = admin_url('admin.php?page=' . self::SLUG);
        $base_qs = array_filter([
            'page'  => self::SLUG,
            'pt'    => $filter_pt,
            'lang'  => $filter_lang,
            's'     => $search,
        ], static fn ($v) => $v !== '');

        ?>
        <div class="wrap triptych-translations">
            <h1><?php esc_html_e('Triptych — Translations', 'triptych'); ?></h1>
            <p class="description">
                <?php esc_html_e('Coverage table for every translatable post. Use the per-row buttons to fill missing translations, or the bulk button to fill an entire language at once.', 'triptych'); ?>
            </p>

            <?php self::renderCoverageHeader($coverage, $languages, $default); ?>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="triptych-tx-filters">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>" />
                <label>
                    <span class="screen-reader-text"><?php esc_html_e('Post type', 'triptych'); ?></span>
                    <select name="pt">
                        <option value=""><?php esc_html_e('All translatable post types', 'triptych'); ?></option>
                        <?php foreach ($post_types as $pt):
                            $obj = get_post_type_object($pt);
                            $label = $obj instanceof \WP_Post_Type ? (string) $obj->labels->singular_name : $pt;
                            ?>
                            <option value="<?php echo esc_attr($pt); ?>" <?php selected($filter_pt, $pt); ?>>
                                <?php echo esc_html($label . ' (' . $pt . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span class="screen-reader-text"><?php esc_html_e('Language', 'triptych'); ?></span>
                    <select name="lang">
                        <option value=""><?php esc_html_e('Any language with missing translations', 'triptych'); ?></option>
                        <?php foreach ($languages as $slug => $label):
                            if ($slug === $default) { continue; }
                            ?>
                            <option value="<?php echo esc_attr($slug); ?>" <?php selected($filter_lang, $slug); ?>>
                                <?php echo esc_html(strtoupper($slug) . ' — ' . $label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span class="screen-reader-text"><?php esc_html_e('Search', 'triptych'); ?></span>
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>"
                           placeholder="<?php esc_attr_e('Search title…', 'triptych'); ?>" />
                </label>
                <button type="submit" class="button"><?php esc_html_e('Filter', 'triptych'); ?></button>
                <?php if ($filter_pt !== '' || $filter_lang !== '' || $search !== ''): ?>
                    <a href="<?php echo esc_url($page_url_base); ?>" class="button-link">
                        <?php esc_html_e('Reset', 'triptych'); ?>
                    </a>
                <?php endif; ?>
            </form>

            <?php
            $bulk_disabled = ($filter_lang === '' || $filter_lang === $default);
            $bulk_label = $bulk_disabled
                ? __('Pick a non-default language to enable bulk translate', 'triptych')
                : sprintf(
                    /* translators: %s: language slug */
                    __('Translate all missing in %s', 'triptych'),
                    strtoupper($filter_lang)
                );
            ?>
            <div class="triptych-tx-bulk">
                <button type="button" class="button button-primary"
                        id="triptych-tx-bulk-btn"
                        data-lang="<?php echo esc_attr($filter_lang); ?>"
                        data-pt="<?php echo esc_attr($filter_pt); ?>"
                        <?php disabled($bulk_disabled); ?>>
                    <?php echo esc_html($bulk_label); ?>
                </button>
                <span id="triptych-tx-bulk-status" class="triptych-tx-bulk-status" aria-live="polite"></span>
                <progress id="triptych-tx-bulk-progress" max="100" value="0" hidden></progress>
            </div>

            <?php if ($rows === []): ?>
                <p><em><?php esc_html_e('No posts match the current filters.', 'triptych'); ?></em></p>
            <?php else: ?>
                <table class="wp-list-table widefat striped triptych-tx-table">
                    <thead>
                        <tr>
                            <th class="col-toggle"><span class="screen-reader-text"><?php esc_html_e('Expand', 'triptych'); ?></span></th>
                            <th class="col-title"><?php esc_html_e('Post', 'triptych'); ?></th>
                            <th class="col-pt"><?php esc_html_e('Type', 'triptych'); ?></th>
                            <th class="col-langs"><?php esc_html_e('Languages', 'triptych'); ?></th>
                            <th class="col-actions"><?php esc_html_e('Actions', 'triptych'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php self::renderRow($row, $languages, $default); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php self::renderPagination($total, $paged, $base_qs); ?>
            <?php endif; ?>

            <script type="application/json" id="triptych-tx-config"><?php
                echo wp_json_encode([
                    'ajax'      => admin_url('admin-ajax.php'),
                    'nonce'     => wp_create_nonce('triptych_tx'),
                    'languages' => $languages,
                    'default'   => $default,
                    'i18n'      => [
                        'translating'  => __('Translating…', 'triptych'),
                        'done'         => __('Done.', 'triptych'),
                        'error'        => __('Error', 'triptych'),
                        'nothing'      => __('Nothing to translate.', 'triptych'),
                        'progress'     => __('Translated %1$d of %2$d posts.', 'triptych'),
                        'searchEmpty'  => __('No rows match your search.', 'triptych'),
                    ],
                ]);
            ?></script>
            <?php self::renderInlineJs(); ?>
        </div>
        <?php
    }

    /**
     * @param array<string,int> $coverage
     * @param array<string,string> $languages
     */
    private static function renderCoverageHeader(array $coverage, array $languages, string $default): void
    {
        $source_total = (int) ($coverage[$default] ?? 0);
        $parts = [];
        foreach ($languages as $slug => $label) {
            $count = (int) ($coverage[$slug] ?? 0);
            if ($slug === $default) {
                $parts[] = sprintf(
                    '<strong>%s</strong>: %d %s',
                    esc_html(strtoupper($slug)),
                    $count,
                    esc_html__('sources', 'triptych')
                );
            } else {
                $missing = max(0, $source_total - $count);
                $parts[] = sprintf(
                    '<strong>%s</strong>: %d %s (%d %s)',
                    esc_html(strtoupper($slug)),
                    $count,
                    esc_html__('translated', 'triptych'),
                    $missing,
                    esc_html__('missing', 'triptych')
                );
            }
        }
        echo '<div class="triptych-tx-summary">' . wp_kses_post(implode(' &middot; ', $parts)) . '</div>';
    }

    /**
     * Pull rows for the table in a single SQL pass.
     *
     * Bound at PAGE_SIZE rows per page. If filter_lang is set, only rows
     * MISSING that language are returned. Otherwise rows missing ANY
     * non-default language. Search matches across all stored title
     * languages.
     *
     * @param string[] $post_types
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private static function queryRows(
        array $post_types,
        string $filter_pt,
        string $filter_lang,
        string $default,
        string $search,
        int $paged
    ): array {
        global $wpdb;
        if ($post_types === []) {
            return [[], 0];
        }
        $effective_pts = $filter_pt !== '' ? [$filter_pt] : $post_types;
        $placeholders = implode(',', array_fill(0, count($effective_pts), '%s'));

        $where = ["p.post_status = 'publish'", "p.post_type IN ({$placeholders})"];
        $params = $effective_pts;

        if ($search !== '') {
            // Match either native title or any stored translation title.
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = "(p.post_title LIKE %s OR EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} sm
                 WHERE sm.post_id = p.ID
                   AND sm.meta_key LIKE %s
                   AND sm.meta_value LIKE %s))";
            $params[] = $like;
            $params[] = '_triptych_post_title_%';
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where_sql}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));

        $offset = ($paged - 1) * self::PAGE_SIZE;
        $list_sql = "SELECT p.ID, p.post_title, p.post_type
                       FROM {$wpdb->posts} p
                      WHERE {$where_sql}
                      ORDER BY p.post_modified DESC
                      LIMIT %d OFFSET %d";
        $list_params = array_merge($params, [self::PAGE_SIZE, $offset]);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $posts = $wpdb->get_results($wpdb->prepare($list_sql, $list_params));
        if (!is_array($posts) || $posts === []) {
            return [[], $total];
        }

        $ids = array_map(static fn ($p) => (int) $p->ID, $posts);
        $id_placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // Fetch every _triptych_* meta (excluding sidecars) for the page in one query.
        $meta_sql = "SELECT post_id, meta_key, meta_value
                       FROM {$wpdb->postmeta}
                      WHERE post_id IN ({$id_placeholders})
                        AND meta_key LIKE %s
                        AND meta_key NOT LIKE %s
                        AND meta_key NOT LIKE %s";
        $meta_params = array_merge($ids, ['_triptych_%', '_triptych_%_updated', '_triptych_%_src_hashes']);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $meta_rows = $wpdb->get_results($wpdb->prepare($meta_sql, $meta_params));

        // Build a lookup: post_id => [field => [lang => raw_value]].
        $by_post = [];
        if (is_array($meta_rows)) {
            foreach ($meta_rows as $m) {
                $key = (string) $m->meta_key;
                if (!preg_match('/^_triptych_(.+)_([a-z0-9_-]+)$/', $key, $matches)) {
                    continue;
                }
                $field = $matches[1];
                $lang  = $matches[2];
                $by_post[(int) $m->post_id][$field][$lang] = (string) $m->meta_value;
            }
        }

        $rows = [];
        $languages = Languages::all();
        foreach ($posts as $p) {
            $pid = (int) $p->ID;
            $fields = Fields::forPostType((string) $p->post_type);
            // Always include title + content even if not registered for the type.
            $all_fields = Fields::all();
            foreach (['post_title', 'post_content'] as $must) {
                if (!isset($fields[$must]) && isset($all_fields[$must])) {
                    $fields = [$must => $all_fields[$must]] + $fields;
                }
            }

            $raw = $by_post[$pid] ?? [];
            $lang_state = [];
            foreach (array_keys($languages) as $slug) {
                if ($slug === $default) {
                    // Source language: present iff post_title (native) is non-empty.
                    $has_title = ((string) $p->post_title) !== ''
                        || !empty($raw['post_title'][$slug]);
                    $lang_state[$slug] = $has_title ? 'source' : 'empty';
                    continue;
                }
                // Translated iff ANY registered field has non-empty postmeta for $slug.
                $has = false;
                foreach (array_keys($fields) as $f) {
                    if (!empty($raw[$f][$slug])) {
                        $has = true;
                        break;
                    }
                }
                if ($has) {
                    $lang_state[$slug] = 'translated';
                    continue;
                }
                // Legacy fallback (ACF / Polylang flat keys).
                $legacy = Fields::readLegacy($pid, 'post_title', $slug);
                if ($legacy === '') {
                    $legacy = Fields::readLegacy($pid, 'post_content', $slug);
                }
                $lang_state[$slug] = $legacy !== '' ? 'legacy' : 'empty';
            }

            // Apply filter: when filter_lang set, only keep posts MISSING that lang.
            if ($filter_lang !== '') {
                if (($lang_state[$filter_lang] ?? 'empty') !== 'empty') {
                    continue;
                }
            }

            // Skip rows that are completely empty (no native title, no translations).
            if (((string) $p->post_title) === '' && $raw === []) {
                continue;
            }

            $title = (string) $p->post_title;
            if ($title === '' && !empty($raw['post_title'][$default])) {
                $title = (string) $raw['post_title'][$default];
            }
            if ($title === '') {
                $title = sprintf('#%d', $pid);
            }

            $rows[] = [
                'id'         => $pid,
                'title'      => $title,
                'post_type'  => (string) $p->post_type,
                'lang_state' => $lang_state,
                'raw'        => $raw,
                'fields'     => array_keys($fields),
            ];
        }

        return [$rows, $total];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string,string> $languages
     */
    private static function renderRow(array $row, array $languages, string $default): void
    {
        $pid       = (int) $row['id'];
        $title     = (string) $row['title'];
        $post_type = (string) $row['post_type'];
        /** @var array<string,string> $lang_state */
        $lang_state = $row['lang_state'];
        $missing_langs = [];
        foreach ($lang_state as $slug => $state) {
            if ($slug !== $default && $state === 'empty') {
                $missing_langs[] = $slug;
            }
        }

        $edit_link = (string) get_edit_post_link($pid);
        $pt_obj = get_post_type_object($post_type);
        $pt_label = $pt_obj instanceof \WP_Post_Type ? (string) $pt_obj->labels->singular_name : $post_type;

        ?>
        <tr class="triptych-tx-row" data-post-id="<?php echo (int) $pid; ?>"
            data-missing="<?php echo esc_attr(implode(',', $missing_langs)); ?>"
            data-search="<?php echo esc_attr(mb_strtolower($title)); ?>">
            <td class="col-toggle">
                <button type="button" class="triptych-tx-toggle" aria-expanded="false"
                        aria-controls="triptych-tx-detail-<?php echo (int) $pid; ?>"
                        title="<?php esc_attr_e('Show raw data', 'triptych'); ?>">
                    <span class="dashicons dashicons-arrow-right"></span>
                </button>
            </td>
            <td class="col-title">
                <strong>
                    <?php if ($edit_link !== ''): ?>
                        <a href="<?php echo esc_url($edit_link); ?>"><?php echo esc_html($title); ?></a>
                    <?php else: ?>
                        <?php echo esc_html($title); ?>
                    <?php endif; ?>
                </strong>
                <div class="row-id">#<?php echo (int) $pid; ?></div>
            </td>
            <td class="col-pt"><code><?php echo esc_html($pt_label); ?></code></td>
            <td class="col-langs">
                <span class="triptych-tx-pills">
                <?php foreach ($lang_state as $slug => $state): ?>
                    <?php
                    $cls = 'is-' . $state;
                    $title_attr = sprintf('%s — %s', strtoupper($slug), $state);
                    ?>
                    <span class="triptych-tx-pill <?php echo esc_attr($cls); ?>"
                          data-lang="<?php echo esc_attr($slug); ?>"
                          title="<?php echo esc_attr($title_attr); ?>">
                        <?php echo esc_html(strtoupper($slug)); ?>
                    </span>
                <?php endforeach; ?>
                </span>
            </td>
            <td class="col-actions">
                <?php if ($missing_langs !== []): ?>
                    <button type="button" class="button triptych-tx-translate-row"
                            data-post-id="<?php echo (int) $pid; ?>">
                        <?php esc_html_e('Translate missing', 'triptych'); ?>
                    </button>
                <?php else: ?>
                    <span class="triptych-tx-complete">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e('Complete', 'triptych'); ?>
                    </span>
                <?php endif; ?>
                <span class="triptych-tx-row-status" aria-live="polite"></span>
            </td>
        </tr>
        <tr class="triptych-tx-detail-row" id="triptych-tx-detail-<?php echo (int) $pid; ?>" hidden>
            <td colspan="5">
                <?php self::renderRawGrid($row, $languages, $default); ?>
            </td>
        </tr>
        <?php
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string,string> $languages
     */
    private static function renderRawGrid(array $row, array $languages, string $default): void
    {
        $pid    = (int) $row['id'];
        $fields = (array) $row['fields'];
        $raw    = (array) $row['raw'];
        $field_defs = Fields::all();

        echo '<div class="triptych-tx-detail">';
        echo '<table class="triptych-tx-grid"><thead><tr>';
        echo '<th>' . esc_html__('Field', 'triptych') . '</th>';
        foreach ($languages as $slug => $label) {
            $is_default = $slug === $default;
            printf(
                '<th class="lang-col%s"><code>%s</code><br><small>%s</small></th>',
                $is_default ? ' is-default' : '',
                esc_html(strtoupper($slug)),
                esc_html($label)
            );
        }
        echo '</tr></thead><tbody>';

        foreach ($fields as $field) {
            $field_label = isset($field_defs[$field]['label']) ? (string) $field_defs[$field]['label'] : $field;
            echo '<tr>';
            echo '<td><strong>' . esc_html($field_label) . '</strong><br><code>' . esc_html($field) . '</code></td>';
            foreach (array_keys($languages) as $slug) {
                $value = $raw[$field][$slug] ?? '';
                if ($value === '') {
                    // Surface fallback resolution so editors see what frontend would render.
                    $resolved = Fields::get($pid, $field, $slug);
                    $display = $resolved;
                    $cls = 'is-fallback';
                } else {
                    $display = $value;
                    $cls = 'is-set';
                }
                $excerpt = mb_substr($display, 0, 240);
                $truncated = mb_strlen($display) > 240 ? '…' : '';
                if ($display === '') {
                    echo '<td class="cell is-empty"><em>' . esc_html__('(empty)', 'triptych') . '</em></td>';
                } else {
                    printf(
                        '<td class="cell %s"><pre>%s%s</pre></td>',
                        esc_attr($cls),
                        esc_html($excerpt),
                        esc_html($truncated)
                    );
                }
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__('Cells with a dashed border show a value resolved through the legacy/source fallback chain (no canonical _triptych_<field>_<lang> postmeta yet).', 'triptych') . '</p>';
        echo '</div>';
    }

    /**
     * @param array<string,string|int> $base_qs
     */
    private static function renderPagination(int $total, int $paged, array $base_qs): void
    {
        $pages = (int) ceil($total / self::PAGE_SIZE);
        if ($pages <= 1) {
            return;
        }
        echo '<div class="tablenav bottom"><div class="tablenav-pages">';
        echo '<span class="displaying-num">' . esc_html(sprintf(
            /* translators: %d: total post count */
            _n('%d post', '%d posts', $total, 'triptych'),
            $total
        )) . '</span> ';
        for ($i = 1; $i <= $pages; $i++) {
            $url = add_query_arg(array_merge($base_qs, ['paged' => $i]), admin_url('admin.php'));
            if ($i === $paged) {
                echo '<span class="page-numbers current">' . (int) $i . '</span> ';
            } else {
                printf('<a class="page-numbers" href="%s">%d</a> ', esc_url($url), (int) $i);
            }
        }
        echo '</div></div>';
    }

    // ---------------------------------------------------------------
    // AJAX
    // ---------------------------------------------------------------

    /**
     * Per-row endpoint. Translates post_title + post_content into every
     * non-default language that's currently empty (or only into $lang
     * when supplied).
     *
     * Response shape:
     *   {
     *     translated: { lang: { field: value } },
     *     errors:     [ { lang, field, message } ],
     *     lang_state: { lang: state }   // refreshed for the row's pills
     *   }
     */
    public static function ajaxTranslateMissing(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Forbidden.', 'triptych')], 403);
        }
        check_ajax_referer('triptych_tx', '_wpnonce');

        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $only_lang = isset($_POST['lang']) ? sanitize_key((string) $_POST['lang']) : '';
        if ($post_id <= 0) {
            wp_send_json_error(['message' => __('Missing post_id.', 'triptych')]);
        }
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            wp_send_json_error(['message' => __('Post not found.', 'triptych')]);
        }

        $default = Languages::default();
        $languages = Languages::all();

        // Resolve target languages.
        $targets = [];
        foreach (array_keys($languages) as $slug) {
            if ($slug === $default) { continue; }
            if ($only_lang !== '' && $only_lang !== $slug) { continue; }
            $targets[] = $slug;
        }
        if ($targets === []) {
            wp_send_json_success(['translated' => new \stdClass(), 'errors' => [], 'lang_state' => []]);
        }

        $fields_to_fill = ['post_title', 'post_content'];

        $translated = [];
        $errors = [];
        foreach ($targets as $lang) {
            foreach ($fields_to_fill as $field) {
                $existing = (string) get_post_meta($post_id, Fields::metaKey($field, $lang), true);
                if ($existing !== '') {
                    continue; // never overwrite via the missing-only path
                }
                // Resolve source text from the default language.
                $source = Fields::get($post_id, $field, $default);
                if (trim($source) === '') {
                    continue;
                }
                $result = Translator::translate($default, $lang, $source, $field);
                if ($result instanceof \WP_Error) {
                    $errors[] = [
                        'lang'    => $lang,
                        'field'   => $field,
                        'message' => $result->get_error_message(),
                    ];
                    continue;
                }
                Fields::set($post_id, $field, $lang, (string) $result);
                update_post_meta(
                    $post_id,
                    Fields::metaKey($field, $lang) . SidebarRest::META_UPDATED_SUFFIX,
                    time()
                );
                $translated[$lang][$field] = (string) $result;
            }
        }

        // Refresh row-level lang_state for the response.
        $lang_state = [];
        $fields = Fields::forPostType($post->post_type);
        $all_fields = Fields::all();
        foreach (['post_title', 'post_content'] as $must) {
            if (!isset($fields[$must]) && isset($all_fields[$must])) {
                $fields = [$must => $all_fields[$must]] + $fields;
            }
        }
        foreach (array_keys($languages) as $slug) {
            if ($slug === $default) {
                $lang_state[$slug] = ((string) $post->post_title) !== '' ? 'source' : 'empty';
                continue;
            }
            $has = false;
            foreach (array_keys($fields) as $f) {
                if ((string) get_post_meta($post_id, Fields::metaKey($f, $slug), true) !== '') {
                    $has = true;
                    break;
                }
            }
            $lang_state[$slug] = $has ? 'translated' : 'empty';
        }

        wp_send_json_success([
            'translated' => $translated ?: new \stdClass(),
            'errors'     => $errors,
            'lang_state' => $lang_state,
        ]);
    }

    /**
     * Bulk endpoint: returns the list of post IDs that need translation
     * for the requested language. The client iterates 5 at a time
     * hitting the per-row endpoint to keep the upstream API serial.
     *
     * Response shape: { post_ids: [int, ...], total: int, lang: string }
     */
    public static function ajaxBulkTranslate(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Forbidden.', 'triptych')], 403);
        }
        check_ajax_referer('triptych_tx', '_wpnonce');

        $lang = isset($_POST['lang']) ? sanitize_key((string) $_POST['lang']) : '';
        $filter_pt = isset($_POST['post_type']) ? sanitize_key((string) $_POST['post_type']) : '';
        if ($lang === '' || !Languages::isValid($lang)) {
            wp_send_json_error(['message' => __('Invalid language.', 'triptych')]);
        }
        $default = Languages::default();
        if ($lang === $default) {
            wp_send_json_error(['message' => __('Cannot bulk-translate the default language.', 'triptych')]);
        }

        $post_types = SettingsPage::triptychPostTypes();
        if ($filter_pt !== '' && in_array($filter_pt, $post_types, true)) {
            $post_types = [$filter_pt];
        }
        if ($post_types === []) {
            wp_send_json_success(['post_ids' => [], 'total' => 0, 'lang' => $lang]);
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        // Posts of an eligible type, published, that DO NOT have either
        // _triptych_post_title_<lang> OR _triptych_post_content_<lang>
        // populated. We scan up to 1,000 candidates per call to keep the
        // upstream burst bounded.
        $sql = "SELECT p.ID
                  FROM {$wpdb->posts} p
                 WHERE p.post_status = 'publish'
                   AND p.post_type IN ({$placeholders})
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} m1
                        WHERE m1.post_id = p.ID
                          AND m1.meta_key = %s
                          AND m1.meta_value <> ''
                   )
                 ORDER BY p.post_modified DESC
                 LIMIT 1000";
        $params = array_merge($post_types, [Fields::metaKey('post_title', $lang)]);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_col($wpdb->prepare($sql, $params));
        $ids = is_array($rows) ? array_map('intval', $rows) : [];

        wp_send_json_success([
            'post_ids' => array_values($ids),
            'total'    => count($ids),
            'lang'     => $lang,
        ]);
    }

    // ---------------------------------------------------------------
    // Inline assets
    // ---------------------------------------------------------------

    private static function inlineCss(): string
    {
        return <<<CSS
.triptych-translations .triptych-tx-summary {
    margin: 12px 0 16px;
    padding: 10px 14px;
    background: #f6f7f7;
    border-left: 4px solid #2271b1;
    border-radius: 0 4px 4px 0;
    font-size: 13px;
}
.triptych-tx-filters {
    display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
    margin-bottom: 12px;
}
.triptych-tx-bulk {
    display: flex; gap: 12px; align-items: center; margin-bottom: 12px;
}
.triptych-tx-bulk-status { color: #50575e; font-size: 13px; }
.triptych-tx-table .col-toggle { width: 28px; text-align: center; }
.triptych-tx-table .col-pt { width: 120px; }
.triptych-tx-table .col-langs { width: 220px; }
.triptych-tx-table .col-actions { width: 220px; }
.triptych-tx-table .row-id { color: #8c8f94; font-size: 11px; font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; }
.triptych-tx-toggle {
    background: transparent; border: 0; cursor: pointer; padding: 2px;
    color: #50575e;
}
.triptych-tx-toggle .dashicons { transition: transform 0.15s ease; }
.triptych-tx-toggle[aria-expanded="true"] .dashicons { transform: rotate(90deg); }
.triptych-tx-pills { display: inline-flex; gap: 4px; flex-wrap: wrap; }
.triptych-tx-pill {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 26px; padding: 2px 7px;
    border-radius: 3px;
    font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
    font-size: 10px; font-weight: 700; letter-spacing: 0.06em;
    line-height: 1.5; border: 1px solid transparent;
}
.triptych-tx-pill.is-source { background: #1d2327; color: #fff; border-color: #1d2327; }
.triptych-tx-pill.is-translated { background: #16a34a; color: #fff; border-color: #16a34a; }
.triptych-tx-pill.is-legacy { background: transparent; color: #b58105; border-color: #f0b849; }
.triptych-tx-pill.is-empty { background: transparent; color: #8c8f94; border-color: #c3c4c7; }
.triptych-tx-pill.is-empty.is-pending { background: #f0f6fc; color: #2271b1; border-color: #72aee6; }
.triptych-tx-row-status { font-size: 12px; color: #50575e; margin-left: 6px; }
.triptych-tx-row-status.is-error { color: #b32d2e; }
.triptych-tx-row-status.is-success { color: #2a8b3a; }
.triptych-tx-complete { color: #2a8b3a; font-size: 12px; display: inline-flex; align-items: center; gap: 4px; }
.triptych-tx-detail-row td { background: #f6f7f7; }
.triptych-tx-grid {
    width: 100%; border-collapse: collapse; margin: 8px 0;
    background: #fff;
}
.triptych-tx-grid th, .triptych-tx-grid td {
    border: 1px solid #dcdcde; padding: 6px 8px; vertical-align: top;
    font-size: 12px;
}
.triptych-tx-grid th { background: #f0f0f1; text-align: left; }
.triptych-tx-grid th.lang-col.is-default { background: #1d2327; color: #fff; }
.triptych-tx-grid td.cell pre {
    margin: 0; max-height: 180px; overflow: auto;
    font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
    font-size: 11px; white-space: pre-wrap; word-break: break-word;
}
.triptych-tx-grid td.cell.is-fallback { border-style: dashed; color: #50575e; }
.triptych-tx-grid td.cell.is-empty { color: #8c8f94; }
@media (max-width: 782px) {
    .triptych-tx-table .col-pt { display: none; }
}
CSS;
    }

    private static function renderInlineJs(): void
    {
        ?>
        <script>
        (function() {
            const cfgEl = document.getElementById('triptych-tx-config');
            if (!cfgEl) return;
            const cfg = JSON.parse(cfgEl.textContent);
            const ajax = cfg.ajax;
            const nonce = cfg.nonce;
            const i18n = cfg.i18n;

            // Toggle raw-data viewer per row.
            document.querySelectorAll('.triptych-tx-toggle').forEach(btn => {
                btn.addEventListener('click', () => {
                    const expanded = btn.getAttribute('aria-expanded') === 'true';
                    btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                    const targetId = btn.getAttribute('aria-controls');
                    const target = document.getElementById(targetId);
                    if (target) target.hidden = expanded;
                });
            });

            // Per-row translate-missing.
            async function translateRow(row, lang) {
                const postId = row.getAttribute('data-post-id');
                const status = row.querySelector('.triptych-tx-row-status');
                const btn = row.querySelector('.triptych-tx-translate-row');
                if (btn) btn.disabled = true;
                if (status) {
                    status.classList.remove('is-error', 'is-success');
                    status.textContent = i18n.translating;
                }
                // Mark targeted pills as pending.
                const pills = row.querySelectorAll('.triptych-tx-pill');
                pills.forEach(p => {
                    if (p.classList.contains('is-empty') && (!lang || p.dataset.lang === lang)) {
                        p.classList.add('is-pending');
                    }
                });

                const body = new URLSearchParams();
                body.set('action', 'triptych_translate_missing');
                body.set('_wpnonce', nonce);
                body.set('post_id', postId);
                if (lang) body.set('lang', lang);

                try {
                    const r = await fetch(ajax, { method: 'POST', body });
                    const j = await r.json();
                    if (!j.success) {
                        throw new Error((j.data && j.data.message) || i18n.error);
                    }
                    // Refresh pills from lang_state.
                    const ls = j.data.lang_state || {};
                    pills.forEach(p => {
                        const slug = p.dataset.lang;
                        if (!ls[slug]) return;
                        p.classList.remove('is-pending', 'is-source', 'is-translated', 'is-empty', 'is-legacy');
                        p.classList.add('is-' + ls[slug]);
                    });
                    // Recompute missing list.
                    const stillMissing = Object.keys(ls).filter(s => ls[s] === 'empty' && s !== cfg.default);
                    row.setAttribute('data-missing', stillMissing.join(','));
                    if (status) {
                        const errs = (j.data.errors || []);
                        if (errs.length) {
                            status.classList.add('is-error');
                            status.textContent = errs.map(e => `${e.lang}/${e.field}: ${e.message}`).join('; ');
                        } else {
                            status.classList.add('is-success');
                            status.textContent = i18n.done;
                        }
                    }
                    if (btn && stillMissing.length === 0) {
                        btn.remove();
                        const td = row.querySelector('.col-actions');
                        if (td) {
                            const span = document.createElement('span');
                            span.className = 'triptych-tx-complete';
                            span.innerHTML = '<span class="dashicons dashicons-yes-alt"></span>' + i18n.done;
                            td.prepend(span);
                        }
                    }
                    return { ok: true, errors: (j.data.errors || []) };
                } catch (err) {
                    pills.forEach(p => p.classList.remove('is-pending'));
                    if (status) {
                        status.classList.add('is-error');
                        status.textContent = String(err && err.message ? err.message : err);
                    }
                    return { ok: false, error: String(err) };
                } finally {
                    if (btn) btn.disabled = false;
                }
            }

            document.querySelectorAll('.triptych-tx-translate-row').forEach(btn => {
                btn.addEventListener('click', () => {
                    const row = btn.closest('tr.triptych-tx-row');
                    if (row) translateRow(row, '');
                });
            });

            // Bulk translate.
            const bulkBtn = document.getElementById('triptych-tx-bulk-btn');
            const bulkStatus = document.getElementById('triptych-tx-bulk-status');
            const bulkProgress = document.getElementById('triptych-tx-bulk-progress');
            if (bulkBtn) {
                bulkBtn.addEventListener('click', async () => {
                    const lang = bulkBtn.dataset.lang;
                    const pt = bulkBtn.dataset.pt || '';
                    if (!lang) return;
                    bulkBtn.disabled = true;
                    if (bulkStatus) bulkStatus.textContent = i18n.translating;
                    if (bulkProgress) {
                        bulkProgress.hidden = false;
                        bulkProgress.value = 0;
                    }

                    // Step 1: fetch list.
                    const body = new URLSearchParams();
                    body.set('action', 'triptych_bulk_translate');
                    body.set('_wpnonce', nonce);
                    body.set('lang', lang);
                    if (pt) body.set('post_type', pt);

                    let ids = [];
                    try {
                        const r = await fetch(ajax, { method: 'POST', body });
                        const j = await r.json();
                        if (!j.success) throw new Error((j.data && j.data.message) || i18n.error);
                        ids = j.data.post_ids || [];
                    } catch (err) {
                        if (bulkStatus) bulkStatus.textContent = String(err && err.message ? err.message : err);
                        bulkBtn.disabled = false;
                        return;
                    }

                    if (ids.length === 0) {
                        if (bulkStatus) bulkStatus.textContent = i18n.nothing;
                        if (bulkProgress) bulkProgress.hidden = true;
                        bulkBtn.disabled = false;
                        return;
                    }

                    if (bulkProgress) bulkProgress.max = ids.length;

                    // Step 2: serialize through per-row endpoint, 5 at a time.
                    let done = 0;
                    const batchSize = 5;
                    for (let i = 0; i < ids.length; i += batchSize) {
                        const batch = ids.slice(i, i + batchSize);
                        // Serial within the batch — upstream API is rate-sensitive.
                        for (const pid of batch) {
                            const row = document.querySelector(`tr.triptych-tx-row[data-post-id="${pid}"]`);
                            const innerBody = new URLSearchParams();
                            innerBody.set('action', 'triptych_translate_missing');
                            innerBody.set('_wpnonce', nonce);
                            innerBody.set('post_id', String(pid));
                            innerBody.set('lang', lang);
                            try {
                                const r = await fetch(ajax, { method: 'POST', body: innerBody });
                                const j = await r.json();
                                if (j.success && row) {
                                    const ls = j.data.lang_state || {};
                                    row.querySelectorAll('.triptych-tx-pill').forEach(p => {
                                        const slug = p.dataset.lang;
                                        if (!ls[slug]) return;
                                        p.classList.remove('is-pending', 'is-source', 'is-translated', 'is-empty', 'is-legacy');
                                        p.classList.add('is-' + ls[slug]);
                                    });
                                }
                            } catch (e) {
                                // continue; partial progress is useful
                            }
                            done++;
                            if (bulkProgress) bulkProgress.value = done;
                            if (bulkStatus) {
                                bulkStatus.textContent = i18n.progress
                                    .replace('%1$d', done)
                                    .replace('%2$d', ids.length);
                            }
                        }
                    }
                    if (bulkStatus) bulkStatus.textContent = i18n.done + ' ' +
                        i18n.progress.replace('%1$d', done).replace('%2$d', ids.length);
                    bulkBtn.disabled = false;
                });
            }

            // Client-side title search highlight (works on the rendered page).
            // The server-side `s` filter handles deep matches across stored
            // translation titles; this client-side layer just hides rows
            // that don't match a refined query without round-tripping.
            const searchInput = document.querySelector('.triptych-tx-filters input[name="s"]');
            if (searchInput) {
                searchInput.addEventListener('input', () => {
                    const q = (searchInput.value || '').toLowerCase().trim();
                    document.querySelectorAll('tr.triptych-tx-row').forEach(row => {
                        if (!q) {
                            row.style.display = '';
                            return;
                        }
                        const hay = row.getAttribute('data-search') || '';
                        row.style.display = hay.indexOf(q) === -1 ? 'none' : '';
                        // Hide the corresponding detail row too.
                        const detailId = row.querySelector('.triptych-tx-toggle')
                            ?.getAttribute('aria-controls');
                        if (detailId) {
                            const dr = document.getElementById(detailId);
                            if (dr) dr.style.display = hay.indexOf(q) === -1 ? 'none' : '';
                        }
                    });
                });
            }
        })();
        </script>
        <?php
    }
}
