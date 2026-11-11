# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-11-11

### Added

- Polylang's **Add a translation** (+) link now creates a linked draft and queues an AI translation through the WordPress AI Client.
- The draft is created the way Polylang creates one: menu order, comment and ping status, translated parent, sticky state, and synced taxonomies and custom fields are copied.
- Title, excerpt and content are translated in the background with WP-Cron. WP-Cron starts right away, even when `DISABLE_WP_CRON` is set.
- Content is sent in chunks that only split between blocks. The block structure of each result is checked and a damaged result is retried once.
- An AI result is not saved if the draft was edited during translation.
- The post list shows **Translating to {language}…** and **Translation failed** labels. Hover over the failed label to see the error.
- **Retry translation** row action for failed jobs and for jobs stuck in processing for more than 15 minutes.
- WP-CLI commands: `wp aitfp translate <post_id> --language=<slug> [--async]` and `wp aitfp run <post_id>`.
- Filters: `aitfp_prompt_builder`, `aitfp_content_chunk_size`, `aitfp_request_timeout`, `aitfp_translated_post_data`, `aitfp_spawn_cron`.
- Actions: `aitfp_translated`, `aitfp_translation_failed`.
- End-to-end acceptance test, `scripts/acceptance.sh`, documented in `docs/acceptance-test.md`.
- Uninstall removes the plugin's post meta and scheduled events on every site in a multisite network.

[0.1.0]: https://github.com/soderlind/ai-translator-for-polylang/releases/tag/0.1.0
