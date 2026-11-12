<?php
/**
 * Tests for Translator.
 *
 * @package AiTranslatorForPolylang\Tests
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang\Tests;

use AiTranslatorForPolylang\Translation\Translator;
use WP_Error;

final class TranslatorTest extends TestCase {

	private const LANGUAGE = [ 'code' => 'nb', 'name' => 'Norsk bokmål', 'locale' => 'nb_NO' ];

	public function test_text_reply_is_unwrapped_and_errors_are_passed_on(): void {
		$error     = new WP_Error( 'prompt_client_error', 'Invalid API key.' );
		$generator = new FakeGenerator( [ "```\n Hei \n```", $error ] );
		$translator = new Translator( $generator );

		$this->assertSame( 'Hei', $translator->translate_text( 'Hello', self::LANGUAGE, 'title' ) );
		$this->assertSame( $error, $translator->translate_text( 'Hello', self::LANGUAGE, 'title' ) );
		$this->assertSame( '', $translator->translate_text( '', self::LANGUAGE, 'excerpt' ) );
		$this->assertCount( 2, $generator->prompts );
	}

	public function test_content_is_translated_as_segments_without_sending_markup(): void {
		$content   = '<!-- wp:image {"id":114} --><figure class="wp-block-image"><img src="http://example.test/garden.png" alt="A garden" class="wp-image-114"/><figcaption>Our garden</figcaption></figure><!-- /wp:image -->';
		$generator = new FakeGenerator( [ "```json\n[\"Ein Garten\", \"Unser Garten\"]\n```" ] );

		$result = ( new Translator( $generator ) )->translate_content( $content, self::LANGUAGE );

		$this->assertSame( str_replace( [ 'A garden', 'Our garden' ], [ 'Ein Garten', 'Unser Garten' ], $content ), $result );
		$this->assertSame( [ '["A garden","Our garden"]' ], $generator->prompts );
	}

	public function test_unparseable_content_reply_is_asked_again_then_rejected(): void {
		$generator = new FakeGenerator( [ 'not json', '["Hei"]' ] );
		$this->assertSame( '<p>Hei</p>', ( new Translator( $generator ) )->translate_content( '<p>Hello</p>', self::LANGUAGE ) );

		$generator = new FakeGenerator( [ '{"text":"Hei"}', '["Hei","ekstra"]' ] );
		$result    = ( new Translator( $generator ) )->translate_content( '<p>Hello</p>', self::LANGUAGE );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aitfp_segment_mismatch', $result->get_error_code() );
	}
}
