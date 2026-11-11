<?php
/**
 * Builds translation prompts and reads the replies.
 *
 * @package AiTranslatorForPolylang
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang\Translation;

use WP_Error;

/**
 * Translates titles, excerpts and post content through a text generator.
 */
final class Translator {

	/**
	 * Default maximum characters of post content sent per request.
	 */
	public const DEFAULT_CHUNK_SIZE = 6000;

	/**
	 * Constructor.
	 *
	 * @param TextGenerator $generator Sends prompts to the AI.
	 */
	public function __construct( private TextGenerator $generator ) {}

	/**
	 * Translates post content, changing only its text segments.
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
		/**
		 * Filters the HTML attributes whose values are translated, e.g. alt and title.
		 *
		 * @param list<string> $attributes Attribute names.
		 */
		$html_attributes = (array) apply_filters( 'aitfp_translatable_html_attributes', PostContent::HTML_ATTRIBUTES );
		/**
		 * Filters the block attribute keys whose string values are translated, e.g. label and caption.
		 *
		 * @param list<string> $attributes Block attribute keys.
		 */
		$block_attributes = (array) apply_filters( 'aitfp_translatable_block_attributes', PostContent::BLOCK_ATTRIBUTES );

		return PostContent::translate(
			$content,
			fn( array $segments ) => $this->translate_segments( $segments, $language ),
			max( 500, $chunk_size ),
			$html_attributes,
			$block_attributes
		);
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

		$result = $this->generator->generate( $text, $instruction, $field, $language );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return trim( self::strip_code_fences( $result ) );
	}

	/**
	 * Asks the AI to translate a list of text segments.
	 *
	 * @param list<string>         $segments Segments in document order.
	 * @param array<string,string> $language Target language.
	 * @return array<mixed>|WP_Error The decoded reply, or an empty list when it is not JSON; PostContent checks its shape.
	 */
	private function translate_segments( array $segments, array $language ) {
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

		$result = $this->generator->generate( $payload, $instruction, 'content', $language );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$translations = json_decode( trim( self::strip_code_fences( $result ) ), true );
		return is_array( $translations ) ? $translations : [];
	}

	/**
	 * Removes a Markdown code fence that some models wrap their answer in.
	 *
	 * @param string $text Model output.
	 * @return string
	 */
	private static function strip_code_fences( string $text ): string {
		if ( preg_match( '/^\s*```[a-zA-Z0-9_-]*[ \t]*\n(.*)\n\s*```\s*$/s', $text, $match ) ) {
			return $match[1];
		}
		return $text;
	}
}
