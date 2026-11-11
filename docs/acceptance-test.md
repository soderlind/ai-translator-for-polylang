# Acceptance test

[`scripts/acceptance.sh`](../scripts/acceptance.sh) tests the whole plugin against a running WordPress site. It uses the WP-CLI commands, real admin HTTP requests and real AI requests.

The PHPUnit tests (`composer test`) only cover the pure helpers. Run this script before a release, or after changing how translations are created, queued, translated or saved.

## Requirements

- A running WordPress 7.0+ site with Polylang and this plugin active. On multisite, the plugin and Polylang must be active on the site you test.
- At least two Polylang languages: the source (default `en`) and the target (default `nb`). Change them with `AITFP_SOURCE_LANG` and `AITFP_TARGET_LANG`.
- A working AI provider for the WordPress AI Client. Test that it works with `wp eval 'var_dump( wp_ai_client_prompt( "Say OK" )->generate_text() );'`.
- An administrator account.
- The site must be able to send HTTP requests to itself, which WordPress uses to start WP-Cron. The test checks that background jobs start even when `DISABLE_WP_CRON` is set.
- `bash`, `curl` and WP-CLI.

## Running

```bash
# From the plugin folder, with WP-CLI as `wp`:
AITFP_URL=https://example.test scripts/acceptance.sh

# With Local by Flywheel, use the Local-aware WP-CLI wrapper:
AITFP_WP="bash ~/.agents/skills/wp-cli-local/scripts/wp" \
AITFP_URL=http://plugins.local/translate \
scripts/acceptance.sh
```

The script takes 2 to 5 minutes, depending on the AI provider. It exits when it finishes and prints a `PASS`/`FAIL` line per check and a summary:

```text
RESULT: 68 passed, 0 failed
```

## Configuration

| Variable | Default | Description |
| --- | --- | --- |
| `AITFP_URL` | `http://plugins.local/translate` | Site URL. Passed to WP-CLI as `--url` and used for the HTTP requests. |
| `AITFP_WP_PATH` | Three folders above the plugin | WordPress root. |
| `AITFP_WP` | `wp` | The WP-CLI command. It may include arguments, e.g. `bash /path/to/wrapper`. |
| `AITFP_USER` | First administrator | Login of the admin user that runs the tests. |
| `AITFP_SOURCE_LANG` | `en` | Polylang slug of the source posts. |
| `AITFP_TARGET_LANG` | `nb` | Polylang slug to translate into. |

## What it tests

The script creates five published source posts in the source language, built from Gutenberg blocks: heading, paragraph with link, group with nested list, image, separator and shortcode. It also creates a category and its translation in the target language. Then it runs these checks:

1. **`wp aitfp translate` (synchronous)**: creates a linked draft in the target language. Title, excerpt and content are translated, and the slug follows the translated title. The block structure is unchanged, URLs and shortcodes are untouched, and the category is swapped for its translation. No cron event is left behind.
2. **CLI errors**: the right message for a language that is already translated, an unknown language (which lists valid ones), a missing `--user`, a missing post, and `wp aitfp run` on a post with no job. None of these create a draft.
3. **`wp aitfp translate --async`**: the draft is linked right away, and the job queued with WP-Cron starts at once and finishes.
4. **`wp aitfp run`**: re-running a finished translation works.
5. **Worker lock**:
   - While another worker holds the post's lock, a second run is refused and changes nothing.
   - A lock with no heartbeat for 15 minutes is taken over, and released when the run ends.
   - A worker whose lock is taken over mid-run stops and doesn't save.
   - A synchronous `wp aitfp translate` never schedules a cron event.
6. **Edit during translation**: a helper simulates an editor saving the draft while the content is being translated. The AI result is thrown away, the manual edit is kept, and the job is marked `failed` with the error stored.
7. **Post list labels and actions**: a failed translation shows **Translation failed** and a **Retry translation** row action. Source posts have no retry action.
8. **Admin over HTTP**, logged in as the admin user with real auth cookies:
   - Polylang's **+** link points to the plugin. Clicking it creates and queues a draft and goes back to the post list, which shows a status notice and loads the status poller. The background job finishes and the post list shows **AI translation complete**.
   - The status endpoint reports both pending and completed jobs. The editor warns against editing a draft while its translation is pending.
   - A second **+** for the same language is rejected with a 400. A bad security token is rejected with a 403. A logged-out request is sent to the login page. None of these create a draft.
   - The post list shows the failed label and retry link. Retry re-queues the job, which then finishes. Retry is refused once the job is done.
9. **Delete a queued draft**: with `aitfp_spawn_cron` returning false, the job stays queued. Deleting the draft removes its scheduled event.
10. **PHP log**: no new plugin errors or notices in `wp-content/debug.log` during the run.

## Fixtures and cleanup

All test posts start with `AITFP AT`, and the categories are named `AITFP AT Garden` and `AITFP AT Hage`. When the script exits, even after a failure or Ctrl-C, it deletes:
- the source posts and every translation of them,
- both categories,
- the login session it created.

If a run is killed with `kill -9`, remove the leftovers with:

```bash
wp eval '
foreach ( get_posts( [ "post_type" => "any", "post_status" => "any", "s" => "AITFP AT", "posts_per_page" => -1, "lang" => "" ] ) as $p ) { wp_delete_post( $p->ID, true ); }
foreach ( get_terms( [ "taxonomy" => "category", "hide_empty" => false, "search" => "AITFP AT", "lang" => "" ] ) as $t ) { wp_delete_term( $t->term_id, "category" ); }
' --url=<site>
```

## Troubleshooting

- **"spawned cron finished the job" fails**: the site can't send HTTP requests to itself. The script then runs the cron event by hand so the other checks can go on. Fix loopback requests, or rely on a system cron.
- **Block structure or translation checks fail**: check the AI provider and model. The `aitfp_prompt_builder` filter selects the model.
- **"Could not resolve admin user"**: set `AITFP_USER` to an administrator's login.
