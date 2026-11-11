<?php
/**
 * Tests for PostContent.
 *
 * @package AiTranslatorForPolylang\Tests
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang\Tests;

use AiTranslatorForPolylang\Translation\PostContent;
use WP_Error;

final class PostContentTest extends TestCase {

	private const PARAGRAPH = "<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->";

	private const IMAGE = '<!-- wp:image {"id":114,"sizeSlug":"large","linkDestination":"none"} -->' . "\n"
		. '<figure class="wp-block-image size-large"><img src="http://example.test/garden-1024x562.png" alt="A sunny vegetable garden" class="wp-image-114"/><figcaption class="wp-element-caption">Our garden &amp; greenhouse</figcaption></figure>' . "\n"
		. '<!-- /wp:image -->';

	/**
	 * Segment lists the callback received, one per call.
	 *
	 * @var list<list<string>>
	 */
	private array $calls = [];

	public function test_translates_text_and_alt_but_keeps_all_other_markup(): void {
		$translated = $this->translate( self::IMAGE, [ 'A sunny vegetable garden' => 'Ein sonniger Gemüsegarten', 'Our garden & greenhouse' => 'Unser Garten & Gewächshaus' ] );

		$this->assertSame( [ [ 'A sunny vegetable garden', 'Our garden & greenhouse' ] ], $this->calls );
		$this->assertSame(
			str_replace(
				[ 'alt="A sunny vegetable garden"', 'Our garden &amp; greenhouse' ],
				[ 'alt="Ein sonniger Gemüsegarten"', 'Unser Garten &amp; Gewächshaus' ],
				self::IMAGE
			),
			$translated
		);
	}

	public function test_keeps_whitespace_and_inline_markup_around_fragments(): void {
		$html = "<!-- wp:paragraph -->\n<p>Read <a href=\"/guide\" title=\"Planting guide\">the guide</a> first. </p>\n<!-- /wp:paragraph -->";
		$map  = [ 'Read' => 'Les', 'Planting guide' => 'Plantevegleder', 'the guide' => 'veilederen', 'first.' => 'først.' ];

		$this->assertSame(
			"<!-- wp:paragraph -->\n<p>Les <a href=\"/guide\" title=\"Plantevegleder\">veilederen</a> først. </p>\n<!-- /wp:paragraph -->",
			$this->translate( $html, $map )
		);
		$this->assertSame( [ [ 'Read', 'Planting guide', 'the guide', 'first.' ] ], $this->calls );
		$this->assertStringContainsString( '</a>, først.', $this->translate( $html, [ 'first.' => ', først.' ] + $map ) );
	}

	public function test_translates_text_in_block_attributes_only(): void {
		$html = '<!-- wp:navigation-link {"label":"About us","url":"/about","kind":"post-type","style":{}} /-->';

		$this->assertSame(
			'<!-- wp:navigation-link {"label":"Om oss \u0026 \u0022meg\u0022","url":"/about","kind":"post-type","style":{}} /-->',
			$this->translate( $html, [ 'About us' => 'Om oss & "meg"' ] )
		);
	}

	public function test_skips_code_symbols_and_empty_markup_without_asking(): void {
		$html = "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>echo 'Hello';</code></pre>\n<!-- /wp:code -->"
			. "<!-- wp:separator -->\n<hr class=\"wp-block-separator\"/>\n<!-- /wp:separator -->\n\n&nbsp;<p>42 – 7</p><img src=\"a.jpg\" alt=\"\"/>";

		$this->assertSame( $html, $this->translate( $html, [] ) );
		$this->assertSame( [], $this->calls );
	}

	public function test_chunks_never_break_nested_blocks(): void {
		$group   = "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\">"
			. sprintf( self::PARAGRAPH, 'Inside one' )
			. '<!-- wp:image {"id":1} /-->'
			. sprintf( self::PARAGRAPH, 'Inside two' )
			. "</div>\n<!-- /wp:group -->";
		$content = sprintf( self::PARAGRAPH, 'First' ) . "\n\n" . $group . "\n\n" . sprintf( self::PARAGRAPH, 'Last' );

		$this->assertSame( $content, $this->translate( $content, [], 60 ) );
		$this->assertSame( [ [ 'First' ], [ 'Inside one', 'Inside two' ], [ 'Last' ] ], $this->calls );
	}

	public function test_packs_small_blocks_into_chunks(): void {
		$content = implode( "\n\n", array_map( static fn( $i ) => sprintf( self::PARAGRAPH, "Paragraph $i" ), range( 1, 6 ) ) );

		$this->assertSame( $content, $this->translate( $content, [], 140 ) );
		$this->assertSame( [ [ 'Paragraph 1', 'Paragraph 2' ], [ 'Paragraph 3', 'Paragraph 4' ], [ 'Paragraph 5', 'Paragraph 6' ] ], $this->calls );
	}

	public function test_chunks_classic_content_on_blank_lines(): void {
		$content = "First paragraph.\n\nSecond <strong>paragraph</strong>.\n\nThird paragraph.";

		$this->assertSame( $content, $this->translate( $content, [], 25 ) );
		$this->assertSame( [ [ 'First paragraph.' ], [ 'Second', 'paragraph' ], [ 'Third paragraph.' ] ], $this->calls );
	}

	public function test_chunks_keep_classic_code_whole(): void {
		$content = "<p>Intro text</p>\n\n<pre><code>first word\n\nsecond word</code></pre>\n\n<p>Outro text</p>";

		$this->assertSame( $content, $this->translate( $content, [], 20 ) );
		$this->assertSame( [ [ 'Intro text' ], [ 'Outro text' ] ], $this->calls );
	}

	public function test_keeps_quotes_in_text_so_shortcodes_still_work(): void {
		$this->assertSame(
			'<p>Se [gallery ids="1,2"] nedenfor, Pers \'hage\' &amp; drivhus</p>',
			$this->translate( '<p>See [gallery ids="1,2"] below</p>', [ 'See [gallery ids="1,2"] below' => 'Se [gallery ids="1,2"] nedenfor, Pers \'hage\' & drivhus' ] )
		);
	}

	public function test_translates_textarea_text(): void {
		$this->assertSame(
			'<textarea placeholder="Din melding">Skriv her</textarea>',
			$this->translate( '<textarea placeholder="Your message">Write here</textarea>', [ 'Your message' => 'Din melding', 'Write here' => 'Skriv her' ] )
		);
	}

	public function test_translates_only_the_text_of_rich_text_block_attributes(): void {
		$this->assertSame(
			'<!-- wp:example/card {"content":"\u003cstrong\u003eOm oss\u003c/strong\u003e"} /-->',
			$this->translate( '<!-- wp:example/card {"content":"\u003cstrong\u003eAbout us\u003c/strong\u003e"} /-->', [ 'About us' => 'Om oss' ] )
		);
		$this->assertSame( [ [ 'About us' ] ], $this->calls );
	}

	public function test_skips_style_attributes(): void {
		$this->translate( '<!-- wp:example/button {"text":"Click me","style":{"color":{"text":"var:preset|color|contrast"}}} /-->', [] );

		$this->assertSame( [ [ 'Click me' ] ], $this->calls );
	}

	public function test_asks_again_once_when_the_answer_does_not_fit(): void {
		$answers = [ [], [ ' Hei ' ] ];
		$result  = PostContent::translate(
			'<p>Hello</p>',
			static function () use ( &$answers ) {
				return array_shift( $answers );
			},
			6000
		);

		$this->assertSame( '<p>Hei</p>', $result );

		foreach ( [ [ 'Hei', 'ekstra' ], [ 1 ], [ 'a' => 'Hei' ] ] as $wrong ) {
			$calls  = 0;
			$result = PostContent::translate(
				'<p>Hello</p>',
				static function () use ( $wrong, &$calls ) {
					++$calls;
					return $wrong;
				},
				6000
			);

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'aitfp_segment_mismatch', $result->get_error_code() );
			$this->assertSame( 2, $calls );
		}
	}

	public function test_returns_the_callback_error(): void {
		$error = new WP_Error( 'aitfp_provider_unavailable', 'Down.' );

		$this->assertSame( $error, PostContent::translate( '<p>Hello</p>', static fn() => $error, 6000 ) );
	}

	/**
	 * Translates with a map of known translations; unknown segments are kept.
	 *
	 * @param array<string,string> $map        Source => translation.
	 * @param int                  $chunk_size Chunk size.
	 */
	private function translate( string $html, array $map, int $chunk_size = 6000 ): string {
		$this->calls = [];
		$result      = PostContent::translate(
			$html,
			function ( array $segments ) use ( $map ): array {
				$this->calls[] = $segments;
				return array_map( static fn( $s ) => $map[ $s ] ?? $s, $segments );
			},
			$chunk_size
		);
		$this->assertIsString( $result );

		return $result;
	}
}
