<?php
/**
 * Tests for ContentChunker.
 *
 * @package AiTranslatorForPolylang\Tests
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang\Tests;

use AiTranslatorForPolylang\Translation\ContentChunker;
use PHPUnit\Framework\TestCase;

final class ContentChunkerTest extends TestCase {

	private const PARAGRAPH = "<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->";

	public function test_split_keeps_classic_code_in_one_chunk(): void {
		$code    = "<pre><code>first()\n\nsecond()</code></pre>\n\n";
		$content = "<p>Intro text</p>\n\n" . $code . '<p>Outro text</p>';

		$chunks = ContentChunker::split( $content, 20 );

		$this->assertSame( $content, implode( '', $chunks ) );
		$this->assertContains( $code, $chunks );
	}

	public function test_split_never_breaks_nested_blocks(): void {
		$group   = "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\">"
			. sprintf( self::PARAGRAPH, str_repeat( 'a', 40 ) )
			. "<!-- wp:image {\"id\":1} /-->"
			. sprintf( self::PARAGRAPH, str_repeat( 'b', 40 ) )
			. "</div>\n<!-- /wp:group -->";
		$content = sprintf( self::PARAGRAPH, 'First' ) . "\n\n" . $group . "\n\n" . sprintf( self::PARAGRAPH, 'Last' );

		$chunks = ContentChunker::split( $content, 60 );

		$this->assertSame( $content, implode( '', $chunks ) );
		$group_chunks = array_values( array_filter( $chunks, static fn( $c ) => str_contains( $c, 'wp:group' ) ) );
		$this->assertCount( 1, $group_chunks, 'The group block must stay in one chunk.' );
		$this->assertStringContainsString( '<!-- /wp:group -->', $group_chunks[0] );
		foreach ( $chunks as $chunk ) {
			$this->assertSame(
				substr_count( $chunk, '<!-- wp:' ) - substr_count( $chunk, '/-->' ),
				substr_count( $chunk, '<!-- /wp:' ),
				'Each chunk must contain balanced block delimiters.'
			);
		}
	}

	public function test_split_packs_small_blocks_and_respects_max_length(): void {
		$blocks  = array_map( static fn( $i ) => sprintf( self::PARAGRAPH, "Paragraph $i" ), range( 1, 6 ) );
		$content = implode( "\n\n", $blocks );

		$chunks = ContentChunker::split( $content, 140 );

		$this->assertSame( $content, implode( '', $chunks ) );
		$this->assertGreaterThan( 1, count( $chunks ) );
		$this->assertLessThan( count( $blocks ), count( $chunks ), 'Small blocks should be packed together.' );
		foreach ( $chunks as $chunk ) {
			$this->assertLessThanOrEqual( 140, mb_strlen( $chunk ) );
		}
	}

	public function test_split_classic_content_on_blank_lines(): void {
		$content = "First paragraph.\n\nSecond <strong>paragraph</strong>.\n\nThird paragraph.";

		$chunks = ContentChunker::split( $content, 25 );

		$this->assertSame( [ "First paragraph.\n\n", "Second <strong>paragraph</strong>.\n\n", 'Third paragraph.' ], $chunks );
	}

	public function test_block_signature_ignores_translated_attributes(): void {
		$source     = '<!-- wp:button {"text":"Read more"} --><a>Read more</a><!-- /wp:button --><!-- wp:core/spacer /-->';
		$translated = '<!-- wp:button {"text":"Les mer"} --><a>Les mer</a><!-- /wp:button --><!-- wp:core/spacer /-->';

		$this->assertSame( [ 'open:button', 'close:button', 'void:core/spacer' ], ContentChunker::block_signature( $source ) );
		$this->assertSame( ContentChunker::block_signature( $source ), ContentChunker::block_signature( $translated ) );
		$this->assertNotSame(
			ContentChunker::block_signature( $source ),
			ContentChunker::block_signature( '<!-- wp:button --><a>Les mer</a>' )
		);
	}

	public function test_strip_code_fences(): void {
		$this->assertSame( '<p>Hei</p>', ContentChunker::strip_code_fences( "```html\n<p>Hei</p>\n```" ) );
		$this->assertSame( 'Plain `code` text', ContentChunker::strip_code_fences( 'Plain `code` text' ) );
	}
}
