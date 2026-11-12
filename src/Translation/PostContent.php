<?php
/**
 * Translates post content without letting the translation touch its markup.
 *
 * @package AiTranslatorForPolylang
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang\Translation;

use WP_Error;
use WP_HTML_Tag_Processor;

/**
 * The post content module: chunks content, extracts its text segments with the
 * HTML API, has them translated, and writes the translations back.
 *
 * A segment is a text node, a translatable HTML attribute value (e.g. alt) or
 * a translatable string in a block delimiter's JSON attributes (e.g. label).
 * Tags, other attributes, URLs and block structure are never exposed, so they
 * cannot be changed by the translation.
 */
final class PostContent {

	/**
	 * HTML attributes whose values are translated by default.
	 */
	public const HTML_ATTRIBUTES = [ 'alt', 'title', 'aria-label', 'placeholder' ];

	/**
	 * Block attribute keys whose string values are translated by default.
	 */
	public const BLOCK_ATTRIBUTES = [ 'alt', 'caption', 'label', 'placeholder', 'title', 'buttonText', 'text', 'content', 'citation' ];

	/**
	 * Times the segments of a chunk are sent before a wrong answer is an error.
	 */
	private const ATTEMPTS = 2;

	/**
	 * Elements whose text is left as is.
	 */
	private const SKIPPED_ELEMENTS = [ 'CODE', 'KBD', 'SAMP' ];

	/**
	 * Elements whose content is a single plain-text token that is translated.
	 */
	private const TEXT_ELEMENTS = [ 'TEXTAREA', 'TITLE' ];

	/**
	 * Block attribute keys whose subtrees hold design settings, never text.
	 */
	private const STRUCTURAL_BLOCK_ATTRIBUTES = [ 'style' ];

	/**
	 * Matches the inside of a block delimiter comment that has JSON attributes.
	 */
	private const DELIMITER_PATTERN = '/^(\s+wp:[a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?\s+)(\{.*\})(\s*\/?\s*)$/s';

	/**
	 * Matches a whole block delimiter comment in content: opener, closer or self-closing.
	 */
	private const BLOCK_PATTERN = '/<!--\s+(?P<closer>\/)?wp:[a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?(?:\s+\{(?:(?!-->).)*?\})?\s*(?P<void>\/)?-->/s';

	/**
	 * Translates post content chunk by chunk, changing only its text segments.
	 *
	 * Chunks are split between top-level blocks, or between paragraphs in
	 * freeform content, and hold at most $chunk_size characters unless a single
	 * block is longer. Chunks without text are not sent.
	 *
	 * @param string                                          $html               Post content.
	 * @param callable(list<string>): (list<string>|WP_Error) $translate_segments Translates one chunk's segments, in order.
	 * @param int                                             $chunk_size         Maximum characters per chunk.
	 * @param list<string>                                    $html_attributes    Translatable HTML attribute names.
	 * @param list<string>                                    $block_attributes   Translatable block attribute keys.
	 * @return string|WP_Error The translated content, the callback's error, or
	 *                         aitfp_segment_mismatch when the callback twice fails
	 *                         to return one string per segment.
	 */
	public static function translate( string $html, callable $translate_segments, int $chunk_size, array $html_attributes = self::HTML_ATTRIBUTES, array $block_attributes = self::BLOCK_ATTRIBUTES ): string|WP_Error {
		$translated = '';
		foreach ( self::split( $html, $chunk_size ) as $chunk ) {
			$result = self::translate_chunk( $chunk, $translate_segments, $html_attributes, $block_attributes );
			if ( $result instanceof WP_Error ) {
				return $result;
			}
			$translated .= $result;
		}

		return $translated;
	}

	/**
	 * Translates the segments of one chunk, re-asking once when the answer does not fit.
	 *
	 * @param string                                          $chunk              Content chunk.
	 * @param callable(list<string>): (list<string>|WP_Error) $translate_segments Callback.
	 * @param list<string>                                    $html_attributes    Translatable HTML attribute names.
	 * @param list<string>                                    $block_attributes   Translatable block attribute keys.
	 * @return string|WP_Error
	 */
	private static function translate_chunk( string $chunk, callable $translate_segments, array $html_attributes, array $block_attributes ): string|WP_Error {
		$segments = self::extract( $chunk, $html_attributes, $block_attributes );
		if ( [] === $segments ) {
			// Nothing human-readable to translate (whitespace, empty blocks, separators).
			return $chunk;
		}

		for ( $attempt = 1; $attempt <= self::ATTEMPTS; $attempt++ ) {
			$translations = $translate_segments( $segments );
			if ( $translations instanceof WP_Error ) {
				return $translations;
			}

			if ( self::fits( $translations, count( $segments ) ) ) {
				return self::replace( $chunk, array_map( 'trim', $translations ), $html_attributes, $block_attributes );
			}
		}

		return new WP_Error(
			'aitfp_segment_mismatch',
			__( 'The AI response did not return one translation per text segment, so it was not saved.', 'ai-translator-for-polylang' )
		);
	}

	/**
	 * Whether translations are a list of exactly $expected strings.
	 *
	 * @param mixed $translations Callback result.
	 * @param int   $expected     Number of segments sent.
	 * @return bool
	 */
	private static function fits( mixed $translations, int $expected ): bool {
		if ( ! is_array( $translations ) || ! array_is_list( $translations ) || count( $translations ) !== $expected ) {
			return false;
		}

		foreach ( $translations as $translation ) {
			if ( ! is_string( $translation ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Splits content into chunks of at most $max_length characters without breaking blocks.
	 *
	 * @param string $content    Post content.
	 * @param int    $max_length Maximum chunk length in characters.
	 * @return list<string> Chunks which, concatenated, equal the original content.
	 */
	private static function split( string $content, int $max_length ): array {
		$chunks  = [];
		$current = '';
		foreach ( self::top_level_parts( $content ) as $part ) {
			if ( '' !== $current && mb_strlen( $current ) + mb_strlen( $part ) > $max_length ) {
				$chunks[] = $current;
				$current  = '';
			}
			$current .= $part;
		}
		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * Splits content into top-level blocks and paragraph-level freeform parts.
	 *
	 * @param string $content Post content.
	 * @return list<string>
	 */
	private static function top_level_parts( string $content ): array {
		preg_match_all( self::BLOCK_PATTERN, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );

		$parts = [];
		$depth = 0;
		$start = 0;
		foreach ( $matches as $match ) {
			$offset = $match[0][1];
			$end    = $offset + strlen( $match[0][0] );

			if ( 0 === $depth && $offset > $start ) {
				array_push( $parts, ...self::split_freeform( substr( $content, $start, $offset - $start ) ) );
				$start = $offset;
			}

			if ( isset( $match['closer'] ) && '' !== $match['closer'][0] ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( ! isset( $match['void'] ) || '' === $match['void'][0] ) {
				++$depth;
			}

			if ( 0 === $depth ) {
				$parts[] = substr( $content, $start, $end - $start );
				$start   = $end;
			}
		}

		if ( $start < strlen( $content ) ) {
			array_push( $parts, ...self::split_freeform( substr( $content, $start ) ) );
		}

		return $parts;
	}

	/**
	 * Splits freeform (non-block) text after each blank line, keeping separators.
	 *
	 * @param string $text Freeform text.
	 * @return list<string>
	 */
	private static function split_freeform( string $text ): array {
		$parts = preg_split( '/(?<=\n\n)/', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $parts ) {
			return [ $text ];
		}

		// Keep elements whose text is not translated in one segment, so a later
		// chunk never starts inside them.
		$segments = [];
		$current  = '';
		$depth    = 0;
		foreach ( $parts as $part ) {
			$current .= $part;
			preg_match_all( '/<(\/?)(?:pre|code|kbd|samp|script|style|textarea)\b/i', $part, $tags );
			foreach ( $tags[1] as $closer ) {
				$depth = max( 0, $depth + ( '' === $closer ? 1 : -1 ) );
			}
			if ( 0 === $depth ) {
				$segments[] = $current;
				$current    = '';
			}
		}
		if ( '' !== $current ) {
			$segments[] = $current;
		}

		return $segments;
	}

	/**
	 * Returns the translatable segments of a piece of markup, in document order.
	 *
	 * @param string       $html             Markup.
	 * @param list<string> $html_attributes  Translatable HTML attribute names.
	 * @param list<string> $block_attributes Translatable block attribute keys.
	 * @return list<string>
	 */
	private static function extract( string $html, array $html_attributes, array $block_attributes ): array {
		$segments = [];
		self::map(
			$html,
			static function ( string $text ) use ( &$segments ): string {
				$segments[] = $text;
				return $text;
			},
			$html_attributes,
			$block_attributes
		);

		return $segments;
	}

	/**
	 * Replaces the translatable segments of a piece of markup.
	 *
	 * @param string       $html             Markup.
	 * @param list<string> $translations     One replacement per segment returned by extract(), in the same order.
	 * @param list<string> $html_attributes  Translatable HTML attribute names.
	 * @param list<string> $block_attributes Translatable block attribute keys.
	 * @return string
	 */
	private static function replace( string $html, array $translations, array $html_attributes, array $block_attributes ): string {
		return self::map(
			$html,
			static function ( string $text ) use ( &$translations ): string {
				return array_shift( $translations ) ?? $text;
			},
			$html_attributes,
			$block_attributes
		);
	}

	/**
	 * Walks every segment in document order and replaces it with the callback's result.
	 *
	 * @param string                   $html             Markup.
	 * @param callable(string): string $callback         Receives a trimmed segment, returns its replacement.
	 * @param list<string>             $html_attributes  Translatable HTML attribute names.
	 * @param list<string>             $block_attributes Translatable block attribute keys.
	 * @return string
	 */
	private static function map( string $html, callable $callback, array $html_attributes, array $block_attributes ): string {
		$processor       = self::processor( $html );
		$html_attributes = array_map( 'strtolower', $html_attributes );
		$skipped_depth   = 0;

		while ( $processor->next_token() ) {
			switch ( $processor->get_token_type() ) {
				case '#text':
					if ( 0 === $skipped_depth ) {
						$text        = $processor->get_modifiable_text();
						$replacement = self::map_text( $text, $callback );
						if ( $replacement !== $text ) {
							$processor->set_text( $replacement );
						}
					}
					break;

				case '#comment':
					if ( WP_HTML_Tag_Processor::COMMENT_AS_HTML_COMMENT === $processor->get_comment_type() ) {
						$comment     = $processor->get_modifiable_text();
						$replacement = self::map_delimiter( $comment, $callback, $html_attributes, $block_attributes );
						if ( $replacement !== $comment ) {
							$processor->set_modifiable_text( $replacement );
						}
					}
					break;

				case '#tag':
					if ( in_array( $processor->get_tag(), self::SKIPPED_ELEMENTS, true ) ) {
						$skipped_depth = max( 0, $skipped_depth + ( $processor->is_tag_closer() ? -1 : 1 ) );
					}
					if ( ! $processor->is_tag_closer() ) {
						self::map_attributes( $processor, $callback, $html_attributes );
						if ( 0 === $skipped_depth && in_array( $processor->get_tag(), self::TEXT_ELEMENTS, true ) ) {
							$text        = $processor->get_modifiable_text();
							$replacement = self::map_text( $text, $callback );
							if ( $replacement !== $text ) {
								$processor->set_modifiable_text( $replacement );
							}
						}
					}
					break;
			}
		}

		return $processor->get_updated_html();
	}

	/**
	 * Returns a tag processor that can write text nodes without encoding quotes.
	 *
	 * set_modifiable_text() encodes " and ' in text nodes, which breaks shortcode
	 * attributes such as [gallery ids="1,2"] and stops wptexturize() from curling
	 * quotes. Quotes are safe in text nodes, so only < > & are encoded.
	 *
	 * @param string $html Markup.
	 * @return WP_HTML_Tag_Processor
	 */
	private static function processor( string $html ): WP_HTML_Tag_Processor {
		return new class( $html ) extends WP_HTML_Tag_Processor {
			/**
			 * Replaces the current text node, encoding only < > &.
			 *
			 * @param string $text Plain text.
			 */
			public function set_text( string $text ): void {
				if ( ! $this->set_modifiable_text( $text ) ) {
					return;
				}

				$update = $this->lexical_updates['modifiable text'] ?? null;
				if ( $update instanceof \WP_HTML_Text_Replacement ) {
					$update->text = strtr( $text, [ '<' => '&lt;', '>' => '&gt;', '&' => '&amp;' ] );
				}
			}
		};
	}

	/**
	 * Passes the text between leading and trailing whitespace to the callback.
	 *
	 * @param string                   $text     Plain text.
	 * @param callable(string): string $callback Callback.
	 * @return string
	 */
	private static function map_text( string $text, callable $callback ): string {
		if ( ! preg_match( '/^(\s*)(.*?)(\s*)$/su', $text, $parts ) || ! preg_match( '/\p{L}/u', $parts[2] ) ) {
			return $text;
		}

		$replacement = $callback( $parts[2] );
		if ( preg_match( '/^[,.;:!?…)\]]/u', $replacement ) ) {
			// The translation attached punctuation to the previous fragment, e.g. "</a>, bevor".
			$parts[1] = '';
		}

		return $parts[1] . $replacement . $parts[3];
	}

	/**
	 * Maps the translatable attribute values of the current tag.
	 *
	 * @param WP_HTML_Tag_Processor    $processor       Processor positioned on a tag opener.
	 * @param callable(string): string $callback        Callback.
	 * @param list<string>             $html_attributes Lower-case attribute names.
	 */
	private static function map_attributes( WP_HTML_Tag_Processor $processor, callable $callback, array $html_attributes ): void {
		foreach ( $html_attributes as $name ) {
			$value = $processor->get_attribute( $name );
			if ( ! is_string( $value ) ) {
				continue;
			}

			$replacement = self::map_text( $value, $callback );
			if ( $replacement !== $value ) {
				$processor->set_attribute( $name, $replacement );
			}
		}
	}

	/**
	 * Maps the translatable strings in a block delimiter's JSON attributes.
	 *
	 * @param string                   $comment          Comment text, e.g. ` wp:image {"id":1} /`.
	 * @param callable(string): string $callback         Callback.
	 * @param list<string>             $html_attributes  Lower-case translatable HTML attribute names.
	 * @param list<string>             $block_attributes Translatable keys.
	 * @return string
	 */
	private static function map_delimiter( string $comment, callable $callback, array $html_attributes, array $block_attributes ): string {
		if ( ! preg_match( self::DELIMITER_PATTERN, $comment, $parts ) ) {
			return $comment;
		}

		$attributes = json_decode( $parts[2] );
		if ( ! $attributes instanceof \stdClass ) {
			return $comment;
		}

		$changed    = false;
		$attributes = self::map_json( $attributes, $callback, $html_attributes, $block_attributes, $changed );

		return $changed ? $parts[1] . self::serialize_block_attributes( $attributes ) . $parts[3] : $comment;
	}

	/**
	 * Recursively maps string values stored under translatable keys.
	 *
	 * Rich-text values (containing markup) are mapped like post content, so only
	 * their text reaches the callback. Design settings under "style" are skipped.
	 *
	 * @param mixed                    $value            Decoded JSON value.
	 * @param callable(string): string $callback         Callback.
	 * @param list<string>             $html_attributes  Lower-case translatable HTML attribute names.
	 * @param list<string>             $block_attributes Translatable keys.
	 * @param bool                     $changed          Set to true when a value changes.
	 * @return mixed
	 */
	private static function map_json( mixed $value, callable $callback, array $html_attributes, array $block_attributes, bool &$changed ): mixed {
		if ( is_array( $value ) ) {
			foreach ( $value as $index => $item ) {
				$value[ $index ] = self::map_json( $item, $callback, $html_attributes, $block_attributes, $changed );
			}
			return $value;
		}

		if ( ! $value instanceof \stdClass ) {
			return $value;
		}

		foreach ( get_object_vars( $value ) as $key => $item ) {
			if ( in_array( (string) $key, self::STRUCTURAL_BLOCK_ATTRIBUTES, true ) ) {
				continue;
			}

			if ( is_string( $item ) && in_array( (string) $key, $block_attributes, true ) ) {
				$replacement = str_contains( $item, '<' )
					? self::map( $item, $callback, $html_attributes, $block_attributes )
					: self::map_text( $item, $callback );
				if ( $replacement !== $item ) {
					$value->$key = $replacement;
					$changed     = true;
				}
			} elseif ( is_array( $item ) || $item instanceof \stdClass ) {
				$value->$key = self::map_json( $item, $callback, $html_attributes, $block_attributes, $changed );
			}
		}

		return $value;
	}

	/**
	 * Encodes block attributes the way core's serialize_block_attributes() does.
	 *
	 * @param \stdClass $attributes Block attributes.
	 * @return string
	 */
	private static function serialize_block_attributes( \stdClass $attributes ): string {
		$json = (string) json_encode( $attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return strtr(
			$json,
			[
				'--'   => '\\u002d\\u002d',
				'<'    => '\\u003c',
				'>'    => '\\u003e',
				'&'    => '\\u0026',
				'\\"'  => '\\u0022',
			]
		);
	}
}
