<?php
/**
 * The seam where translation prompts are sent to an AI model.
 *
 * @package AiTranslatorForPolylang
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang\Translation;

use WP_Error;

/**
 * Generates text from a prompt and a system instruction.
 */
interface TextGenerator {

	/**
	 * Generates text.
	 *
	 * @param string               $prompt      User prompt.
	 * @param string               $instruction System instruction.
	 * @param string               $field       Field being translated: title, excerpt or content.
	 * @param array<string,string> $language    Target language ( code, name, locale ).
	 * @return string|WP_Error The generated text, aitfp_lock_lost when the before-request
	 *                         callback returns false, or a provider error.
	 */
	public function generate( string $prompt, string $instruction, string $field, array $language ): string|WP_Error;

	/**
	 * Sets a callback that runs before every request, e.g. to refresh a job heartbeat.
	 *
	 * @param (callable(): bool)|null $callback Returning false aborts the request; null removes the callback.
	 */
	public function before_request( ?callable $callback ): void;
}
