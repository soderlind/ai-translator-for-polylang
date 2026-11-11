<?php
/**
 * Finds and replaces the translatable text in post content with the HTML API.
 *
 * @package AiTranslatorForPolylang
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang\Translation;

use WP_HTML_Tag_Processor;

/**
 * Pure helpers that read and write text segments without touching markup.
 *
 * A segment is a text node, a translatable HTML attribute value (e.g. alt) or
 * a translatable string in a block delimiter's JSON attributes (e.g. label).
 * Tags, other attributes, URLs and block structure are never exposed, so they
 * cannot be changed by the translation.
 */
final class ContentSegments {

	/**
	 * HTML attributes whose values are translated by default.
	 */
	public const HTML_ATTRIBUTES = [ 'alt', 'title', 'aria-label', 'placeholder' ];

	/**
	 * Block attribute keys whose string values are translated by default.
	 */
	public const BLOCK_ATTRIBUTES = [ 'alt', 'caption', 'label', 'placeholder', 'title', 'buttonText', 'text', 'content', 'citation' ];

	/**
	 * Elements whose text is left as is.
	 */
	private const SKIPPED_ELEMENTS = [ 'CODE', 'KBD', 'SAMP' ];

	/**
	 * Matches the inside of a block delimiter comment that has JSON attributes.
	 */
	private const DELIMITER_PATTERN = '/^(\s+wp:[a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?\s+)(\{.*\})(\s*\/?\s*)$/s';

	/**
	 * Returns the translatable segments of a piece of markup, in document order.
	 *
	 * @param string       $html             Markup.
	 * @param list<string> $html_attributes  Translatable HTML attribute names.
	 * @param list<string> $block_attributes Translatable block attribute keys.
	 * @return list<string>
	 */
	public static function extract( string $html, array $html_attributes = self::HTML_ATTRIBUTES, array $block_attributes = self::BLOCK_ATTRIBUTES ): array {
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
	public static function replace( string $html, array $translations, array $html_attributes = self::HTML_ATTRIBUTES, array $block_attributes = self::BLOCK_ATTRIBUTES ): string {
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
		$processor       = new WP_HTML_Tag_Processor( $html );
		$html_attributes = array_map( 'strtolower', $html_attributes );
		$skipped_depth   = 0;

		while ( $processor->next_token() ) {
			switch ( $processor->get_token_type() ) {
				case '#text':
					if ( 0 === $skipped_depth ) {
						$text        = $processor->get_modifiable_text();
						$replacement = self::map_text( $text, $callback );
						if ( $replacement !== $text ) {
							$processor->set_modifiable_text( $replacement );
						}
					}
					break;

				case '#comment':
					if ( WP_HTML_Tag_Processor::COMMENT_AS_HTML_COMMENT === $processor->get_comment_type() ) {
						$comment     = $processor->get_modifiable_text();
						$replacement = self::map_delimiter( $comment, $callback, $block_attributes );
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
					}
					break;
			}
		}

		return $processor->get_updated_html();
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
	 * @param list<string>             $block_attributes Translatable keys.
	 * @return string
	 */
	private static function map_delimiter( string $comment, callable $callback, array $block_attributes ): string {
		if ( ! preg_match( self::DELIMITER_PATTERN, $comment, $parts ) ) {
			return $comment;
		}

		$attributes = json_decode( $parts[2] );
		if ( ! $attributes instanceof \stdClass ) {
			return $comment;
		}

		$changed    = false;
		$attributes = self::map_json( $attributes, $callback, $block_attributes, $changed );

		return $changed ? $parts[1] . self::serialize_block_attributes( $attributes ) . $parts[3] : $comment;
	}

	/**
	 * Recursively maps string values stored under translatable keys.
	 *
	 * @param mixed                    $value            Decoded JSON value.
	 * @param callable(string): string $callback         Callback.
	 * @param list<string>             $block_attributes Translatable keys.
	 * @param bool                     $changed          Set to true when a value changes.
	 * @return mixed
	 */
	private static function map_json( mixed $value, callable $callback, array $block_attributes, bool &$changed ): mixed {
		if ( is_array( $value ) ) {
			foreach ( $value as $index => $item ) {
				$value[ $index ] = self::map_json( $item, $callback, $block_attributes, $changed );
			}
			return $value;
		}

		if ( ! $value instanceof \stdClass ) {
			return $value;
		}

		foreach ( get_object_vars( $value ) as $key => $item ) {
			if ( is_string( $item ) && in_array( (string) $key, $block_attributes, true ) ) {
				$replacement = self::map_text( $item, $callback );
				if ( $replacement !== $item ) {
					$value->$key = $replacement;
					$changed     = true;
				}
			} elseif ( is_array( $item ) || $item instanceof \stdClass ) {
				$value->$key = self::map_json( $item, $callback, $block_attributes, $changed );
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
