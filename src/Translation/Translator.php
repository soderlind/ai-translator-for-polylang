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
	 * Translates one content chunk, retrying once if block markup was damaged.
	 *
	 * @param string               $chunk    Content chunk.
	 * @param array<string,string> $language Target language.
	 * @return string|WP_Error
	 */
	private function translate_chunk( string $chunk, array $language ) {
		if ( '' === trim( wp_strip_all_tags( $chunk ) ) ) {
			// Nothing human-readable to translate (whitespace, empty blocks, separators).
			return $chunk;
		}

		$instruction = sprintf(
			'You are a professional translator. Translate the WordPress post content provided by the user into %1$s (%2$s). '
			. 'The content contains HTML and WordPress block comments such as <!-- wp:paragraph --> and <!-- /wp:paragraph -->. '
			. 'Rules: keep every HTML tag, attribute, block comment (including its JSON attributes), shortcode, URL and line break exactly as is, in the same order; '
			. 'translate only human-readable text, including alt and title attribute values; '
			. 'do not add, remove or reorder anything; do not wrap the answer in code fences. Reply with the translated content only.',
			$language['name'],
			$language['locale']
		);

		$expected = ContentChunker::block_signature( $chunk );
		$attempts = 2;
		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			$result = $this->generate( $chunk, $instruction, 'content', $language );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$result = ContentChunker::strip_code_fences( $result );
			if ( ContentChunker::block_signature( $result ) === $expected ) {
				return $this->preserve_outer_whitespace( $chunk, $result );
			}
		}

		return new WP_Error(
			'aitfp_block_markup_mismatch',
			__( 'The AI response changed the block structure of the content, so it was not saved.', 'ai-translator-for-polylang' )
		);
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
	 * Restores leading/trailing whitespace that models tend to trim.
	 *
	 * @param string $source     Source chunk.
	 * @param string $translated Translated chunk.
	 * @return string
	 */
	private function preserve_outer_whitespace( string $source, string $translated ): string {
		preg_match( '/^\s*/', $source, $leading );
		preg_match( '/\s*$/', $source, $trailing );

		return $leading[0] . trim( $translated ) . $trailing[0];
	}
}
