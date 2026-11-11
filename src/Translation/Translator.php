<?php
/**
 * Translates text with the WordPress AI Client.
 *
 * @package AiTranslatorForPolylang
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang\Translation;

use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WP_AI_Client_Prompt_Builder;
use WP_Error;

/**
 * Sends translation prompts through wp_ai_client_prompt().
 */
class Translator {

	/**
	 * Default maximum characters of post content sent per request.
	 */
	public const DEFAULT_CHUNK_SIZE = 6000;

	/**
	 * Default request timeout in seconds.
	 */
	public const DEFAULT_TIMEOUT = 180;

	/**
	 * Attempts made when no AI provider is available.
	 */
	private const PROVIDER_ATTEMPTS = 2;

	/**
	 * Seconds to wait before retrying when no AI provider is available.
	 */
	private const PROVIDER_RETRY_DELAY = 5;

	/**
	 * Called before every AI request. Returning false aborts the translation.
	 *
	 * @var (callable(): bool)|null
	 */
	private $before_request = null;

	/**
	 * Sets a callback that runs before every AI request, e.g. to refresh a job heartbeat.
	 *
	 * @param (callable(): bool)|null $callback Callback, or null to remove it.
	 */
	public function before_request( ?callable $callback ): void {
		$this->before_request = $callback;
	}

	/**
	 * Translates post content, chunk by chunk, preserving block markup.
	 *
	 * @param string               $content  Post content.
	 * @param array<string,string> $language Target language ( name, locale ).
	 * @return string|WP_Error
	 */
	public function translate_content( string $content, array $language ) {
		/**
		 * Filters the maximum number of characters of content sent per AI request.
		 *
		 * @param int $chunk_size Characters per chunk.
		 */
		$chunk_size = (int) apply_filters( 'aitfp_content_chunk_size', self::DEFAULT_CHUNK_SIZE );
		$translated = '';

		foreach ( ContentChunker::split( $content, max( 500, $chunk_size ) ) as $chunk ) {
			$result = $this->translate_chunk( $chunk, $language );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$translated .= $result;
		}

		return $translated;
	}

	/**
	 * Translates a single piece of plain text (title, excerpt).
	 *
	 * @param string               $text     Text.
	 * @param array<string,string> $language Target language.
	 * @param string               $field    Field being translated, e.g. 'title' or 'excerpt'.
	 * @return string|WP_Error
	 */
	public function translate_text( string $text, array $language, string $field ) {
		if ( '' === trim( $text ) ) {
			return $text;
		}

		$instruction = sprintf(
			'You are a professional translator. Translate the post %3$s provided by the user into %1$s (%2$s). Reply with the translated text only, without quotes, notes or explanations. Keep any HTML tags unchanged.',
			$language['name'],
			$language['locale'],
			$field
		);

		$result = $this->generate( $text, $instruction, $field, $language );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return trim( ContentChunker::strip_code_fences( $result ) );
	}

	/**
	 * Translates the text segments of one content chunk, leaving all markup untouched.
	 *
	 * @param string               $chunk    Content chunk.
	 * @param array<string,string> $language Target language.
	 * @return string|WP_Error
	 */
	private function translate_chunk( string $chunk, array $language ) {
		/**
		 * Filters the HTML attributes whose values are translated, e.g. alt and title.
		 *
		 * @param list<string> $attributes Attribute names.
		 */
		$html_attributes = (array) apply_filters( 'aitfp_translatable_html_attributes', ContentSegments::HTML_ATTRIBUTES );
		/**
		 * Filters the block attribute keys whose string values are translated, e.g. label and caption.
		 *
		 * @param list<string> $attributes Block attribute keys.
		 */
		$block_attributes = (array) apply_filters( 'aitfp_translatable_block_attributes', ContentSegments::BLOCK_ATTRIBUTES );

		$segments = ContentSegments::extract( $chunk, $html_attributes, $block_attributes );
		if ( [] === $segments ) {
			// Nothing human-readable to translate (whitespace, empty blocks, separators).
			return $chunk;
		}

		$instruction = sprintf(
			'You are a professional translator. The user sends a JSON array of text segments from a WordPress post, in document order. '
			. 'Translate every segment into %1$s (%2$s). '
			. 'Consecutive segments can be parts of one sentence split by inline formatting or links, so translate them so they read naturally when joined. '
			. 'Keep shortcodes, URLs, email addresses, placeholders and code unchanged, and do not add HTML. '
			. 'Reply with a JSON array of exactly %3$d strings, one translation per segment, in the same order, without code fences or explanations.',
			$language['name'],
			$language['locale'],
			count( $segments )
		);
		$payload = (string) json_encode( $segments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		$attempts = 2;
		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			$result = $this->generate( $payload, $instruction, 'content', $language );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$translations = self::parse_segments( $result, count( $segments ) );
			if ( null !== $translations ) {
				return ContentSegments::replace( $chunk, $translations, $html_attributes, $block_attributes );
			}
		}

		return new WP_Error(
			'aitfp_segment_mismatch',
			__( 'The AI response did not return one translation per text segment, so it was not saved.', 'ai-translator-for-polylang' )
		);
	}

	/**
	 * Parses the model's JSON array of translated segments.
	 *
	 * @param string $response Model output.
	 * @param int    $expected Number of segments sent.
	 * @return list<string>|null Null when the response is not a list of $expected strings.
	 */
	private static function parse_segments( string $response, int $expected ): ?array {
		$translations = json_decode( trim( ContentChunker::strip_code_fences( $response ) ), true );
		if ( ! is_array( $translations ) || ! array_is_list( $translations ) || count( $translations ) !== $expected ) {
			return null;
		}

		foreach ( $translations as $translation ) {
			if ( ! is_string( $translation ) ) {
				return null;
			}
		}

		return array_map( 'trim', $translations );
	}

	/**
	 * Runs a prompt and returns the generated text.
	 *
	 * @param string               $text        User prompt.
	 * @param string               $instruction System instruction.
	 * @param string               $field       Field being translated.
	 * @param array<string,string> $language    Target language.
	 * @return string|WP_Error
	 */
	protected function generate( string $text, string $instruction, string $field, array $language ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'aitfp_ai_client_missing',
				__( 'The WordPress AI Client is not available. WordPress 7.0 or later is required.', 'ai-translator-for-polylang' )
			);
		}

		for ( $attempt = 1; ; $attempt++ ) {
			if ( $this->before_request && false === ( $this->before_request )() ) {
				return new WP_Error(
					'aitfp_lock_lost',
					__( 'Another worker took over this translation.', 'ai-translator-for-polylang' )
				);
			}

			$result = $this->request( $text, $instruction, $field, $language );
			if ( ! is_wp_error( $result ) ) {
				return (string) $result;
			}

			if ( ! self::is_provider_unavailable( $result ) ) {
				return $result;
			}

			if ( $attempt >= self::PROVIDER_ATTEMPTS ) {
				return new WP_Error(
					'aitfp_provider_unavailable',
					__( 'The AI provider could not be reached. Check Settings → Connectors and retry.', 'ai-translator-for-polylang' ),
					[ 'original_message' => $result->get_error_message() ]
				);
			}

			$this->pause( self::PROVIDER_RETRY_DELAY );
		}
	}

	/**
	 * Sends one prompt to the AI Client.
	 *
	 * @param string               $text        User prompt.
	 * @param string               $instruction System instruction.
	 * @param string               $field       Field being translated.
	 * @param array<string,string> $language    Target language.
	 * @return string|WP_Error
	 */
	protected function request( string $text, string $instruction, string $field, array $language ) {
		$request_options = new RequestOptions();
		/**
		 * Filters the AI request timeout in seconds.
		 *
		 * @param int $timeout Timeout in seconds.
		 */
		$request_options->setTimeout( (float) apply_filters( 'aitfp_request_timeout', self::DEFAULT_TIMEOUT ) );

		$builder = wp_ai_client_prompt( $text )
			->using_system_instruction( $instruction )
			->using_request_options( $request_options );

		/**
		 * Filters the prompt builder before a translation request is sent.
		 *
		 * Use this to pick a provider or model, e.g. `$builder->using_model_preference( 'gpt-5-mini' )`.
		 *
		 * @param WP_AI_Client_Prompt_Builder $builder  Prompt builder.
		 * @param string                      $field    Field being translated: title, excerpt or content.
		 * @param array<string,string>        $language Target language ( code, name, locale ).
		 */
		$builder = apply_filters( 'aitfp_prompt_builder', $builder, $field, $language );

		$result = $builder->generate_text();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return (string) $result;
	}

	/**
	 * Whether an AI Client error means no configured provider was usable.
	 *
	 * Core reports a failed provider availability check (network error, timeout,
	 * rate limit) as "No models found …", which is often transient.
	 *
	 * @param WP_Error $error AI Client error.
	 * @return bool
	 */
	private static function is_provider_unavailable( WP_Error $error ): bool {
		return 'prompt_invalid_argument' === $error->get_error_code()
			&& str_starts_with( $error->get_error_message(), 'No models found' );
	}

	/**
	 * Waits before retrying a request.
	 *
	 * @param int $seconds Seconds to wait.
	 */
	protected function pause( int $seconds ): void {
		sleep( $seconds );
	}
}
