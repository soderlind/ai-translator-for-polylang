<?php
/**
 * Splits post content into translatable chunks without breaking block markup.
 *
 * @package AiTranslatorForPolylang
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang\Translation;

/**
 * Pure helpers for chunking content and cleaning AI output.
 */
final class ContentChunker {

	/**
	 * Matches a block delimiter comment: opener, closer or self-closing.
	 */
	private const DELIMITER_PATTERN = '/<!--\s+(?P<closer>\/)?wp:(?P<name>[a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)(?:\s+(?P<attrs>\{(?:(?!-->).)*?\}))?\s*(?P<void>\/)?-->/s';

	/**
	 * Splits content into chunks of at most $max_length characters.
	 *
	 * Chunks are only split between top-level blocks, or between paragraphs
	 * (blank lines) in freeform content, so block markup is never broken. A
	 * single top-level block longer than $max_length becomes its own chunk.
	 *
	 * @param string $content    Post content.
	 * @param int    $max_length Maximum chunk length in characters.
	 * @return list<string> Chunks which, concatenated, equal the original content.
	 */
	public static function split( string $content, int $max_length ): array {
		if ( '' === $content ) {
			return [];
		}

		$chunks  = [];
		$current = '';
		foreach ( self::segments( $content ) as $segment ) {
			if ( '' !== $current && mb_strlen( $current ) + mb_strlen( $segment ) > $max_length ) {
				$chunks[] = $current;
				$current  = '';
			}
			$current .= $segment;
		}
		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * Returns the block structure of a piece of markup, ignoring attributes.
	 *
	 * @param string $html Markup.
	 * @return list<string> E.g. [ 'open:paragraph', 'close:paragraph', 'void:image' ].
	 */
	public static function block_signature( string $html ): array {
		preg_match_all( self::DELIMITER_PATTERN, $html, $matches, PREG_SET_ORDER );

		return array_map(
			static function ( array $match ): string {
				if ( ! empty( $match['closer'] ) ) {
					return 'close:' . $match['name'];
				}
				return ( ! empty( $match['void'] ) ? 'void:' : 'open:' ) . $match['name'];
			},
			$matches
		);
	}

	/**
	 * Removes a Markdown code fence that some models wrap their answer in.
	 *
	 * @param string $text Model output.
	 * @return string
	 */
	public static function strip_code_fences( string $text ): string {
		if ( preg_match( '/^\s*```[a-zA-Z0-9_-]*[ \t]*\n(.*)\n\s*```\s*$/s', $text, $match ) ) {
			return $match[1];
		}
		return $text;
	}

	/**
	 * Splits content into top-level blocks and paragraph-level freeform segments.
	 *
	 * @param string $content Post content.
	 * @return list<string>
	 */
	private static function segments( string $content ): array {
		preg_match_all( self::DELIMITER_PATTERN, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );

		$segments = [];
		$depth    = 0;
		$start    = 0;
		foreach ( $matches as $match ) {
			$offset = $match[0][1];
			$end    = $offset + strlen( $match[0][0] );

			if ( 0 === $depth && $offset > $start ) {
				array_push( $segments, ...self::split_freeform( substr( $content, $start, $offset - $start ) ) );
				$start = $offset;
			}

			if ( isset( $match['closer'] ) && '' !== $match['closer'][0] ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( ! isset( $match['void'] ) || '' === $match['void'][0] ) {
				++$depth;
			}

			if ( 0 === $depth ) {
				$segments[] = substr( $content, $start, $end - $start );
				$start      = $end;
			}
		}

		if ( $start < strlen( $content ) ) {
			array_push( $segments, ...self::split_freeform( substr( $content, $start ) ) );
		}

		return $segments;
	}

	/**
	 * Splits freeform (non-block) text after each blank line, keeping separators.
	 *
	 * @param string $text Freeform text.
	 * @return list<string>
	 */
	private static function split_freeform( string $text ): array {
		$parts = preg_split( '/(?<=\n\n)/', $text, -1, PREG_SPLIT_NO_EMPTY );
		return false === $parts ? [ $text ] : $parts;
	}
}
