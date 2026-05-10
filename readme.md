# Triptych

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP 8.1+](https://img.shields.io/badge/php-8.1%2B-777bb4.svg)](https://www.php.net/)
[![WordPress 6.5+](https://img.shields.io/badge/wordpress-6.5%2B-21759b.svg)](https://wordpress.org/)
[![Status: alpha](https://img.shields.io/badge/status-alpha-orange.svg)](#roadmap)

> One canonical post. Three languages. Inline tabs. AI auto-translate. No per-language post twins.

Triptych is a standalone WordPress plugin that takes a different stance from Polylang and WPML on multilingual content: instead of duplicating posts per language, it stores **every language value as a field on a single canonical post**. Switching languages while editing never takes you to a different post — you just click a tab.

## Why

Polylang and WPML use a **per-post-per-language** model: editing the Japanese version of an article means navigating to a *different* post entity. That makes sense for SEO scaffolding, but it's painful to author with — translating a hero block, a CTA, or a sidebar means hopping between posts and constantly losing your place. ACF + Polylang's free tier compounds the issue: ACF field values silently belong to the post they live on, not the translation set.

Triptych takes the opposite stance: **one canonical post entity, multiple language fields**.

| | Polylang / WPML | **Triptych** |
|---|---|---|
| Posts per article | N (one per language) | 1 |
| Editing UI | Switch posts | Click a tab |
| Storage | Separate `wp_posts` rows | `_triptych_{field}_{lang}` postmeta |
| URL routing | `/ja/foo-ja-slug/` | `/ja/foo/` (same canonical post) |
| Field translation | One copy of ACF per twin | One copy, language-keyed |
| AI translation | Bring your own | Built in, pluggable endpoint |

This is opinionated. If you need fully independent slugs / categories / SEO meta per language, Polylang Pro or WPML are still the right tools. If you want a clean per-field editor and a tiny dependency footprint, Triptych is built for you.

## Quick start

```bash
# Inside wp-content/plugins/
git clone https://github.com/rennerdo30/wp-triptych.git
```

Activate the plugin in **Plugins → Installed Plugins**, then visit **Settings → Triptych** to:

1. Configure your language list (default `zh:中文,ja:日本語,en:English`).
2. Pick a default language.
3. (Optional) Point the AI translator at OpenAI / Anthropic / Ollama / etc.

Edit any post — the **Triptych — Multilingual Fields** metabox appears below the editor with one tab per configured language.

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
// → reads _triptych_event_subtitle_{current_url_lang}, falls back to default lang.
```

The `the_title()` and `the_content()` filters are wired automatically; you only need `triptych_get_field()` for custom fields.

## AI auto-translate

Triptych speaks the **OpenAI Chat Completions** schema, which is the lingua franca of LLM endpoints. Configure any of:

| Provider | Endpoint URL | Example model |
|---|---|---|
| OpenAI | `https://api.openai.com/v1` | `gpt-4o-mini` |
| Anthropic (Claude) | `https://api.anthropic.com/v1` | `claude-haiku-4.5` |
| Mistral | `https://api.mistral.ai/v1` | `mistral-small-latest` |
| Together | `https://api.together.xyz/v1` | `meta-llama/Llama-3.3-70B` |
| Groq | `https://api.groq.com/openai/v1` | `llama-3.3-70b-versatile` |
| Ollama (local) | `http://localhost:11434/v1` | `llama3.2` |
| vLLM / llama.cpp | your own | your own |

Each non-default-language pane in the editor gets an **AI translate** button. Click it; the source-language value is translated and dropped into the field. POSTs hit `wp-json/triptych/v1/translate` (auth: `edit_posts`).

## Architecture

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
                └────────────────────┬─────────────────────────┘
                                     │
       inbound URL routing          │          outbound rendering
       ┌─────────────────┐          │          ┌────────────────────┐
   /zh/triptych/  ──────►│ Router  │◄──────── │ TitleFilter        │
   /ja/triptych/  ──────►│ strips  │          │ ContentFilter      │
   /en/triptych/  ──────►│ /lang/  │          │ PermalinkFilter    │
                         └────┬────┘          │ HreflangEmitter    │
                              │               └────────────────────┘
                              │
                              ▼
                       same WP query →
                       same post ID 42 →
                       fields swap by current language
```

The Router strips the language prefix from `REQUEST_URI` before WordPress parses the request, so the same WP_Query resolves the same post regardless of language. Every outbound `home_url()` / permalink filter prefixes the language back on. The TitleFilter and ContentFilter then read the language-keyed postmeta at render time.

## Roadmap

- [ ] **Block editor (Gutenberg)** — currently classic editor only; Gutenberg sidebar panel coming next.
- [ ] **Taxonomy term translation** — categories, tags, and custom taxonomies.
- [ ] **REST API per-language** — accept `?triptych_lang=ja` on standard `/wp/v2/posts` and swap fields server-side.
- [ ] **Bulk translate** — translate all empty fields on a post in a single click.
- [ ] **Translation memory** — cache common phrases to skip duplicate API calls.
- [ ] **Per-language slugs** — opt-in, since most users won't need it.
- [ ] **Polylang import tool** — migrate post-twin sites onto Triptych in one shot.

## Author

[Renner](https://renner.dev) — built alongside [example.com](https://example.com), a WordPress demo site that needed multilingual content without the per-post-twin model.

## License

[MIT](LICENSE) © 2026 Renner
