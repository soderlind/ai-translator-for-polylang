# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- The AI now gets only the text of the content, never the markup. The WordPress HTML API pulls out text nodes, `alt`, `title`, `aria-label` and `placeholder` values, and text in block attributes, then writes the translations back into the original markup.
- New filters `aitfp_translatable_html_attributes` and `aitfp_translatable_block_attributes` choose which attributes are translated.
- Text inside `<code>`, `<kbd>` and `<samp>` is no longer translated.

### Fixed

- Images could break in translated drafts because the AI could change an image's `src`, `class` or block `id`. Markup is now never sent to the AI.

## [0.1.2] - 2026-11-11

### Added

- The translation status updates on its own, with no page reload, and the post list shows "AI translation complete" when the draft is ready.
- The editor warns you while the draft is being translated and asks you to reload when it finishes.

### Changed

- Failure details are visible and keyboard accessible, with a retry link.
- The "being translated" notice disappears once the job ends instead of staying after the draft is done.

### Fixed

- When no AI provider can be used ("No models found…"), the plugin tries once more, then shows: "The AI provider could not be reached. Check Settings → Connectors and retry."

## [0.1.1] - 2026-11-11

### Added

- Add a translation template and WP-CLI internationalization tooling.

## [0.1.0] - 2026-11-11

### Added

- Polylang's **Add a translation** (+) link now creates a linked draft and queues an AI translation through the WordPress AI Client.
- The draft is created the way Polylang creates one: menu order, comment and ping status, translated parent, sticky state, and synced taxonomies and custom fields are copied.
- Title, excerpt and content are translated in the background with WP-Cron. WP-Cron starts right away, even when `DISABLE_WP_CRON` is set.
- Content is sent in chunks that only split between blocks. Each result's block delimiters, HTML tags and block JSON are checked, and a damaged result is retried once.
- Only one worker translates a post at a time. A per-post lock with a heartbeat is refreshed before every AI request, and a worker that loses its lock does not save.
- Synchronous `wp aitfp translate` runs in the foreground only and never schedules a cron event.
- An AI result is not saved if the draft was edited during translation.
- The post list shows **Translating to {language}…** and **Translation failed** labels. Hover over the failed label to see the error.
- **Retry translation** row action for failed jobs and for jobs stuck in processing for more than 15 minutes.
- WP-CLI commands: `wp aitfp translate <post_id> --language=<slug> [--async]` and `wp aitfp run <post_id>`.
- Filters: `aitfp_prompt_builder`, `aitfp_content_chunk_size`, `aitfp_request_timeout`, `aitfp_translated_post_data`, `aitfp_spawn_cron`.
- Actions: `aitfp_translated`, `aitfp_translation_failed`.
- End-to-end acceptance test, `scripts/acceptance.sh`, documented in `docs/acceptance-test.md`.
- Uninstall removes the plugin's post meta and scheduled events on every site in a multisite network.

[0.1.2]: https://github.com/soderlind/ai-translator-for-polylang/releases/tag/0.1.2
[0.1.1]: https://github.com/soderlind/ai-translator-for-polylang/releases/tag/0.1.1
[0.1.0]: https://github.com/soderlind/ai-translator-for-polylang/releases/tag/0.1.0
