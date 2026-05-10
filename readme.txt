=== Triptych ===
Contributors: rennerdo30
Tags: multilingual, translation, i18n, openai, ai
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Single-post multilingual editor with inline 3-language tabs and OpenAI auto-translate. One canonical post, multiple language fields, no per-language post twins.

== Description ==

Triptych is a standalone multilingual plugin for WordPress that takes a different stance from Polylang and WPML: instead of one post per language, it stores **all language values as fields on a single canonical post**. Switching languages while editing never takes you to a different post — you just click a tab.

Features:

* Inline tabbed editor for every multilingual field (title, content, custom fields)
* AI translate button next to every field — pluggable, OpenAI Chat-Completions-compatible
* URL routing: `/zh/foo/`, `/ja/foo/`, `/en/foo/` all resolve to the same post
* Hreflang tags emitted automatically
* Pluggable field registration: `triptych_register_multilingual_field('event_subtitle', ['post_types' => ['event']])`
* Works with OpenAI, Anthropic Claude API, Mistral, Together, Groq, Ollama, vLLM — anything that speaks Chat Completions
* No dependencies (no Polylang, no ACF)

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it via the Plugins menu.
3. Go to **Settings → Triptych** to configure languages and (optionally) the AI endpoint.
4. Edit any post — the **Triptych — Multilingual Fields** metabox appears with one tab per language.

== Frequently Asked Questions ==

= Will it conflict with Polylang or WPML? =

It is independent. Use one or the other on a given site.

= Where is the data stored? =

As `_triptych_{field}_{lang}` postmeta on the canonical post.

= Does the AI button send my content to a third party? =

Only if you configure an endpoint and provide an API key. With those blank, the plugin works fine without AI features.

== Changelog ==

= 0.1.0 =
* Initial release.
