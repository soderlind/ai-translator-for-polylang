# AI Translator for Polylang

Click Polylang's **Add a translation** (+) button and get a linked draft that the WordPress AI Client translates in the background.

## Requirements

- WordPress 7.0 or later (includes the WordPress AI Client, `wp_ai_client_prompt()`)
- PHP 8.3 or later
- [Polylang](https://wordpress.org/plugins/polylang/)
- An AI provider configured for the WordPress AI Client

## How it works

1. In a post list, click the **+** next to a language that has no translation yet.
2. The plugin creates a **draft** in that language, linked to the source post. It copies what Polylang's own "Add a translation" copies: menu order, comment and ping status, translated parent, sticky state, and the taxonomies and custom fields Polylang syncs.
3. A WP-Cron job sends the title, excerpt and content to the AI and saves the translation into the draft.
4. While the job runs, the post list shows **Translating to {language}…** next to the title. If it fails, you see **Translation failed**. Hover over it to read the error.
5. Review the draft and publish it.

### What keeps the content safe

- Content goes to the AI in chunks (6000 characters by default). Chunks only split between top-level blocks, or between paragraphs in classic content.
- The plugin checks the block structure of each translated chunk. If the AI changed it, the plugin tries once more and then marks the job failed. It never saves broken block markup.
- If someone edits the draft while it is being translated, the plugin throws away the AI result instead of overwriting their changes.
- The job saves as the user who asked for the translation, so kses and capability checks work as they do in a normal edit.

### Retrying

A **Retry translation** row action shows up on translations that failed or have been stuck in processing for more than 15 minutes.

## WP-CLI

```bash
# Create a Norwegian translation of post 42 and translate it now.
wp aitfp translate 42 --language=nb --user=admin

# Create it, but let WP-Cron translate it.
wp aitfp translate 42 --language=nb --user=admin --async

# Run (or re-run) the AI translation of translation post 43.
wp aitfp run 43
```

`--user` is required by `translate` so it can check capabilities.

## Hooks

### Filters

| Filter | Arguments | Description |
| --- | --- | --- |
| `aitfp_prompt_builder` | `WP_AI_Client_Prompt_Builder $builder, string $field, array $language` | Change the prompt builder before a request is sent, for example to choose a provider or model. `$field` is `title`, `excerpt` or `content`. |
| `aitfp_content_chunk_size` | `int $chunk_size` | Maximum characters of content per AI request. Default `6000`, minimum `500`. |
| `aitfp_request_timeout` | `int $timeout` | AI request timeout in seconds. Default `180`. |
| `aitfp_translated_post_data` | `array $data, WP_Post $post, array $language` | Change the post data before it goes to `wp_update_post()`. |
| `aitfp_spawn_cron` | `bool $spawn, int $post_id` | Whether to start WP-Cron right after queuing. Default `true`, even when `DISABLE_WP_CRON` is set. |

### Actions

| Action | Arguments | Description |
| --- | --- | --- |
| `aitfp_translated` | `int $post_id, array $language, int $source_id` | Fires after a post has been translated. |
| `aitfp_translation_failed` | `int $post_id, WP_Error $error, array $language` | Fires when a translation fails. |

`$language` is an array with `code` (Polylang slug), `name` and `locale`.

### Example: choose a model

```php
add_filter( 'aitfp_prompt_builder', function ( $builder ) {
	return $builder->using_model_preference( 'gpt-5-mini' );
} );
```

## Uninstall

When you delete the plugin, it removes its post meta (`_aitfp_*`) and any scheduled `aitfp_translate_post` events, on every site in a multisite network. The translated posts stay.

## Development

```bash
composer install
composer test
```

To run the end-to-end test (WP-CLI, admin requests and real AI requests) against a running site:

```bash
AITFP_URL=https://example.test scripts/acceptance.sh
```

See [docs/acceptance-test.md](docs/acceptance-test.md).

## License

GPL v2 or later. Copyright Per Soderlind.
