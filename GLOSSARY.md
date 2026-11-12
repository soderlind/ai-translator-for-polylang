# Glossary

Domain terms used in code, docs and reviews. Keep names in code consistent with these.

- **Source post**: the published post or page a translation is made from.
- **Translation draft**: the draft post in the target language, linked to the source post in Polylang, that receives the AI translation.
- **Translation job**: the background work that translates one source post into one translation draft (`TranslationJob`), run by WP-Cron, Action Scheduler or WP-CLI.
- **Job state**: what is known about a draft's translation job: status (pending, processing, done, failed), target language, error and last update (`JobState`).
- **Chunk**: a slice of post content, split between top-level blocks or freeform paragraphs, sent to the AI in one request.
- **Segment**: one piece of human-readable text in a chunk: a text node, a translatable HTML attribute value (e.g. `alt`) or a translatable block attribute string (e.g. `label`). Only segments are sent to the AI; markup never is.
- **Post content module**: `PostContent`, which chunks content, extracts segments with the HTML API, has them translated and writes them back.
- **Text generator**: the seam where prompts are sent to an AI model (`TextGenerator`); `AiClientGenerator` is the adapter for the WordPress AI Client.
