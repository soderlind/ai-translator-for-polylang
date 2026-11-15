=== Translate with AI for Polylang ===
Contributors: PerS
Tags: polylang, translation, ai, multilingual, translate
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.3
Requires Plugins: polylang
Stable tag: 1.0.0
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Click Polylang's "Add a translation" (+) button to create a linked draft that the WordPress AI Client translates in the background.

== Description ==

Translate with AI for Polylang changes what Polylang's "Add a translation" (+) button does. Clicking it now creates a linked draft in the target language, and a background job translates its title, excerpt and content with the WordPress AI Client.

= Features =

* Works from the + button you already use in post lists.
* Creates the draft the way Polylang does: it copies menu order, comment and ping status, translated parent, sticky state, and the taxonomies and custom fields Polylang syncs.
* Translates in the background with WP-Cron. The post list shows the progress.
* Keeps block markup intact. The WordPress HTML API pulls out only the text (text nodes, alt and title text, and text in block attributes), so the AI never sees or changes tags, URLs, image IDs or block structure.
* Only one worker translates a post at a time, so retries and CLI runs can't race each other.
* Never overwrites manual edits. If the draft is edited during translation, the AI result is thrown away.
* A "Retry translation" row action for failed or stuck jobs.
* WP-CLI commands: `wp twaifp translate` and `wp twaifp run`.
* Filters and actions for choosing a model, tuning chunk size and timeout, and changing the saved data.

= Requirements =

* WordPress 7.0 or later, which includes the WordPress AI Client
* An AI provider configured for the WordPress AI Client
* Polylang

== Installation ==

1. Install and activate Polylang, and set up your languages.
2. Configure an AI provider for the WordPress AI Client.
3. Upload the plugin to `/wp-content/plugins/translate-with-ai-for-polylang/` and activate it.
4. In a post list, click the + next to a language to create an AI translation.

== Frequently Asked Questions ==

= How do I choose the AI model? =

Use the `twaifp_prompt_builder` filter:

`add_filter( 'twaifp_prompt_builder', fn( $builder ) => $builder->using_model_preference( 'gpt-5-mini' ) );`

= The translation is stuck. What do I do? =

The plugin runs WP-Cron right after it queues a job. If your site blocks loopback requests, run `wp cron event run --due-now`, or translate right away with `wp twaifp run <post_id>`. Jobs stuck in processing for more than 15 minutes get a "Retry translation" link.

= Why did the translation fail? =

Hover over "Translation failed" in the post list to read the error. Common causes: the AI didn't return one translation per text segment, someone edited the draft during translation, or the AI request timed out. For long posts, try raising `twaifp_request_timeout` or lowering `twaifp_content_chunk_size`.

= Is the translation published automatically? =

No. Translations are saved as drafts so you can review them before you publish.

= What hooks are available? =

Filters: `twaifp_prompt_builder`, `twaifp_content_chunk_size`, `twaifp_request_timeout`, `twaifp_translatable_html_attributes`, `twaifp_translatable_block_attributes`, `twaifp_translated_post_data`, `twaifp_spawn_cron`.
Actions: `twaifp_translated`, `twaifp_translation_failed`.

See README.md for arguments.

== Changelog ==

= 1.0.0 =
* Renamed the plugin to "Translate with AI for Polylang" (slug and text domain `translate-with-ai-for-polylang`), because the old slug is already taken on WordPress.org.
* The main plugin file is now `translate-with-ai-for-polylang.php`, and the PHP namespace is `Soderlind\TranslateWithAiForPolylang`.
* The prefix for hooks, post meta, options, constants and the WP-CLI command changed from `aitfp` to `twaifp` (`wp twaifp`). Translation status saved by earlier versions is not carried over.

= 0.2.0 =
* The AI now gets only the text of the content, never the markup. Translations are written back into the original markup with the WordPress HTML API.
* New filters `twaifp_translatable_html_attributes` and `twaifp_translatable_block_attributes` choose which attributes are translated.
* Text inside `<code>`, `<kbd>` and `<samp>` is no longer translated. Text in `<textarea>` and `<title>` now is.
* A translation that has stopped responding shows a retry link in the notice.
* Fix: images could break in translated drafts.
* Fix: shortcodes such as `[gallery ids="1,2"]` keep their quotes after translation.
* Fix: block colour settings are no longer sent for translation.
* Fix: code blocks in long classic posts are never split, so code is never translated.

= 0.1.2 =
* The translation status updates on its own, with no page reload, and the post list shows "AI translation complete" when the draft is ready.
* The editor warns you while the draft is being translated and asks you to reload when it finishes.
* Failure details are visible and keyboard accessible, with a retry link.
* The "being translated" notice disappears once the job ends instead of staying after the draft is done.
* When no AI provider can be used ("No models found…"), the plugin tries once more, then shows: "The AI provider could not be reached. Check Settings → Connectors and retry."

= 0.1.1 =
* Add a translation template and WP-CLI internationalization tooling.

= 0.1.0 =
* Initial release.
