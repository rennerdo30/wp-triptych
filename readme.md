# Triptych

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP 8.1+](https://img.shields.io/badge/php-8.1%2B-777bb4.svg)](https://www.php.net/)
[![WordPress 6.5+](https://img.shields.io/badge/wordpress-6.5%2B-21759b.svg)](https://wordpress.org/)
[![Status: alpha](https://img.shields.io/badge/status-alpha-orange.svg)](#roadmap)

> One canonical post. N languages. In-canvas tab bar. Per-block AI translate. No per-language post twins.

Triptych is a standalone WordPress plugin that takes a different stance from Polylang and WPML on multilingual content: instead of duplicating posts per language, it stores **every language value as a field on a single canonical post**. Switching languages while editing never takes you to a different post — you click a pill at the top of the canvas.

## Why

Polylang and WPML use a **per-post-per-language** model: editing the Japanese version of an article means navigating to a *different* post entity. That makes sense for SEO scaffolding, but it's painful to author with — translating a hero block, a CTA, or a sidebar means hopping between posts and constantly losing your place. ACF + Polylang's free tier compounds the issue: ACF field values silently belong to the post they live on, not the translation set.

Triptych takes the opposite stance: **one canonical post entity, multiple language fields**.

| | Polylang / WPML | **Triptych** |
|---|---|---|
| Posts per article | N (one per language) | 1 |
| Editing UI | Switch posts | Click a pill at the top of the canvas |
| Storage | Separate `wp_posts` rows | `_triptych_{field}_{lang}` postmeta |
| URL routing | `/ja/foo-ja-slug/` | `/ja/foo/` (same canonical post) |
| Field translation | One copy of ACF per twin | One copy, language-keyed |
| AI translation | Bring your own | Built in, per-block, OpenAI-compatible |

This is opinionated. If you need fully independent slugs / categories / SEO meta per language, Polylang Pro or WPML are still the right tools. If you want a clean per-field editor and a tiny dependency footprint, Triptych is built for you.

## Quick start

```bash
# Inside wp-content/plugins/
git clone https://github.com/rennerdo30/wp-triptych.git
```

Activate the plugin in **Plugins → Installed Plugins**, then visit **Settings → Triptych** to:

1. Configure your language list (default `zh:中文,ja:日本語,en:English`).
2. Pick a default language.
3. (Optional) Point the AI translator at DeepSeek / OpenAI / Anthropic / Ollama / etc.

Edit any post — a pill bar (`CN | JP | EN`) sits across the top of the canvas. Click a pill to swap title + content + every registered field to that language.

## In-canvas editing (v0.3.x)

The Block Editor is the primary surface. Triptych enqueues an `admin-editor.js` bundle that:

- **Mounts a pill bar at the top of the editor canvas** with one button per configured language. The active pill is filled; non-default pills carry a status dot — green for translated, amber for legacy postmeta, hollow for empty.
- **Adds a *Translate* toolbar button** to standard block types (paragraph, heading, list, list-item, quote, code, preformatted, verse). Click it on a block in a non-default language and Triptych translates that single block's RichText payload from the source language and replaces the block content in place.
- **Tracks per-block source-drift** — when you translate, Triptych snapshots the source block's hash (stored under `_triptych_<field>_<lang>_src_hashes`). On reload, blocks whose source has changed since translation are flagged stale so editors know which blocks to retranslate.
- **Falls back to a classic-editor metabox** automatically on post types that opt out of the Block Editor.

The classic metabox stays registered as a fallback and self-disables when `use_block_editor_for_post_type()` is true.

## Field registration API

By default, Triptych makes `post_title` and `post_content` multilingual. Theme authors register additional fields:

```php
add_action('plugins_loaded', function () {
    triptych_register_multilingual_field('post_title');
    triptych_register_multilingual_field('post_content');

    triptych_register_multilingual_field('event_subtitle', [
        'type'       => 'text',
        'post_types' => ['event'],
        'label'      => 'Subtitle',
    ]);

    triptych_register_multilingual_field('event_description', [
        'type'       => 'textarea',
        'post_types' => ['event'],
        'label'      => 'Description',
    ]);
});
```

Read values in templates:

```php
echo esc_html(triptych_get_field(get_the_ID(), 'event_subtitle'));
// → reads _triptych_event_subtitle_<current_url_lang>, falls back to default lang.
```

The `the_title()` and `the_content()` filters are wired automatically; you only need `triptych_get_field()` for custom fields.

### Public procedural API

```php
triptych_register_multilingual_field(string $field, array $args = []): void
triptych_get_field(int $post_id, string $field, ?string $lang = null): string
triptych_current_language(): string
triptych_languages(): array  // ['zh' => '中文', 'ja' => '日本語', 'en' => 'English']
triptych_default_language(): string
```

## Storage

Storage convention: **`_triptych_{field}_{lang}` postmeta** as a plain scalar (string) — no envelope, no JSON, no serialization. This keeps every language value addressable from any tool that already speaks postmeta (WP-CLI, WP REST API, MySQL backups, search/replace).

Per-block source-drift hashes are stored alongside under `_triptych_{field}_{lang}_src_hashes` as a flat array of hash strings, one per top-level block at translate time.

`Fields::get()` resolves through this fallback chain so themes migrating off other systems surface existing data without a forced bulk rewrite:

1. `_triptych_<field>_<lang>` postmeta (canonical Triptych storage).
2. Legacy flat per-language postmeta (`<field>_cn`, `<field>_jp`, `<field>_en`) and ACF serialised group arrays.
3. Same chain in the default language, for posts whose translation is missing.
4. Native post column (`post_title` / `post_content` / `post_excerpt`).

## REST endpoints

Auth on every route is `current_user_can('edit_posts')`.

| Route | Method | Purpose |
|---|---|---|
| `/wp-json/triptych/v1/post/<id>` | `GET` | Snapshot of every registered field × language for the post, plus per-field source-hash arrays. |
| `/wp-json/triptych/v1/save` | `POST` | Write one `{field, lang, value, source_hashes?}` tuple. Pass an empty `source_hashes` array to clear the drift snapshot. |
| `/wp-json/triptych/v1/translate` | `POST` | Translate a string from one language to another via the configured endpoint. |

## AI auto-translate

Triptych speaks the **OpenAI Chat Completions** schema, the lingua franca of LLM endpoints. The default endpoint is **DeepSeek** (`https://api.deepseek.com/v1`, model `deepseek-v4-pro`) because its pricing is sane for per-block translation calls. Swap to anything compatible:

| Provider | Endpoint URL | Example model |
|---|---|---|
| DeepSeek (default) | `https://api.deepseek.com/v1` | `deepseek-v4-pro` |
| OpenAI | `https://api.openai.com/v1` | `gpt-4o-mini` |
| Anthropic (Claude) | `https://api.anthropic.com/v1` | `claude-haiku-4.5` |
| Mistral | `https://api.mistral.ai/v1` | `mistral-small-latest` |
| Together | `https://api.together.xyz/v1` | `meta-llama/Llama-3.3-70B` |
| Groq | `https://api.groq.com/openai/v1` | `llama-3.3-70b-versatile` |
| Ollama (local) | `http://localhost:11434/v1` | `llama3.2` |
| vLLM / llama.cpp | your own | your own |

Translation calls run per block from the toolbar button. The classic-editor metabox keeps a per-pane *AI translate* button that fills the entire field at once.

## URL routing

Triptych registers a request-time Router that strips the language prefix from `REQUEST_URI` before WordPress parses the request, so the same WP_Query resolves the same post regardless of language. Outbound `home_url()` and permalink filters prefix the language back on. The `TitleFilter` and `ContentFilter` then read the language-keyed postmeta at render time. `HreflangEmitter` emits `<link rel="alternate" hreflang>` tags for every configured language.

```
                ┌──────────────────────────────────────────────┐
                │  ONE canonical wp_posts row (id = 42)        │
                │  ─ post_title:   "Untitled"  (legacy)        │
                │  ─ post_content: ""          (legacy)        │
                │                                              │
                │  postmeta:                                   │
                │   _triptych_post_title_zh   → "三联画"        │
                │   _triptych_post_title_ja   → "三連画"        │
                │   _triptych_post_title_en   → "Triptych"     │
                │   _triptych_post_content_zh → "<p>…</p>"     │
                │   _triptych_post_content_ja → "<p>…</p>"     │
                │   _triptych_post_content_en → "<p>…</p>"     │
                │   _triptych_post_content_ja_src_hashes →     │
                │      ["a1b2…", "c3d4…", "e5f6…"]             │
                └────────────────────┬─────────────────────────┘
                                     │
       inbound URL routing           │          outbound rendering
       ┌─────────────────┐           │          ┌────────────────────┐
   /zh/triptych/  ──────►│ Router  │◄──────────│ TitleFilter        │
   /ja/triptych/  ──────►│ strips  │           │ ContentFilter      │
   /en/triptych/  ──────►│ /lang/  │           │ PermalinkFilter    │
                         └────┬────┘           │ HreflangEmitter    │
                              │                └────────────────────┘
                              ▼
                       same WP query →
                       same post ID 42 →
                       fields swap by current language
```

## Known limitations

- **One canonical slug.** Per-language slugs are not supported. `/zh/foo/` and `/ja/foo/` resolve the same post; the slug is whatever the canonical row has. Per-language slugs are on the roadmap as opt-in.
- **One canonical taxonomy term set.** Categories and tags belong to the canonical post; they don't translate. Use a translatable taxonomy plugin or maintain term labels via `single_term_title` filters in your theme.
- **One canonical SEO meta set.** If you need per-language `description` / OpenGraph payloads, Triptych won't give them to you out of the box — wire your SEO plugin's filters through `triptych_current_language()`.
- **Block-level translate covers core RichText blocks.** Nested blocks (groups, columns) translate their inner blocks individually. Custom blocks aren't auto-detected; their content survives the translate flow but you'll have to invoke translation from the field-level pane.
- **Source-drift hashes are top-level only.** The snapshot tracks each top-level block; reordering blocks in the source language without retranslating will report drift on every changed slot.

## Roadmap

- [ ] **Taxonomy term translation** — categories, tags, and custom taxonomies.
- [ ] **REST API per-language** — accept `?triptych_lang=ja` on standard `/wp/v2/posts` and swap fields server-side.
- [ ] **Bulk translate** — translate all empty fields on a post in a single call.
- [ ] **Translation memory** — cache common phrases to skip duplicate API calls.
- [ ] **Per-language slugs** — opt-in, since most sites won't need it.
- [ ] **Polylang import tool** — migrate post-twin sites onto Triptych in one shot.

## License

[MIT](LICENSE) © 2026 Renner
