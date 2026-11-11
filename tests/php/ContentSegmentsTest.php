<?php
/**
 * Tests for ContentSegments.
 *
 * @package AiTranslatorForPolylang\Tests
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang\Tests;

use AiTranslatorForPolylang\Translation\ContentSegments;

final class ContentSegmentsTest extends TestCase {

	private const IMAGE = '<!-- wp:image {"id":114,"sizeSlug":"large","linkDestination":"none"} -->' . "\n"
		. '<figure class="wp-block-image size-large"><img src="http://example.test/garden-1024x562.png" alt="A sunny vegetable garden" class="wp-image-114"/><figcaption class="wp-element-caption">Our garden &amp; greenhouse</figcaption></figure>' . "\n"
		. '<!-- /wp:image -->';

	public function test_translates_text_and_alt_but_keeps_all_other_markup(): void {
		$this->assertSame( [ 'A sunny vegetable garden', 'Our garden & greenhouse' ], ContentSegments::extract( self::IMAGE ) );

		$translated = ContentSegments::replace( self::IMAGE, [ 'Ein sonniger Gemüsegarten', 'Unser Garten & Gewächshaus' ] );

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

		$this->assertSame( [ 'Read', 'Planting guide', 'the guide', 'first.' ], ContentSegments::extract( $html ) );
		$this->assertSame(
			"<!-- wp:paragraph -->\n<p>Les <a href=\"/guide\" title=\"Plantevegleder\">veilederen</a> først. </p>\n<!-- /wp:paragraph -->",
			ContentSegments::replace( $html, [ 'Les', 'Plantevegleder', 'veilederen', 'først.' ] )
		);
		$this->assertStringContainsString( '</a>, først.', ContentSegments::replace( $html, [ 'Les', 'Plantevegleder', 'veilederen', ', først.' ] ) );
	}

	public function test_translates_text_in_block_attributes_only(): void {
		$html = '<!-- wp:navigation-link {"label":"About us","url":"/about","kind":"post-type","style":{}} /-->';

		$this->assertSame( [ 'About us' ], ContentSegments::extract( $html ) );
		$this->assertSame(
			'<!-- wp:navigation-link {"label":"Om oss \u0026 \u0022meg\u0022","url":"/about","kind":"post-type","style":{}} /-->',
			ContentSegments::replace( $html, [ 'Om oss & "meg"' ] )
		);
	}

	public function test_skips_code_symbols_and_empty_markup(): void {
		$html = "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>echo 'Hello';</code></pre>\n<!-- /wp:code -->"
			. "<!-- wp:separator -->\n<hr class=\"wp-block-separator\"/>\n<!-- /wp:separator -->\n\n&nbsp;<p>42 – 7</p><img src=\"a.jpg\" alt=\"\"/>";

		$this->assertSame( [], ContentSegments::extract( $html ) );
	}
}
