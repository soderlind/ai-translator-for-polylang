<?php
/**
 * Tests for Translator.
 *
 * @package AiTranslatorForPolylang\Tests
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang\Tests;

use AiTranslatorForPolylang\Translation\Translator;
use Brain\Monkey\Functions;
use WP_Error;

final class TranslatorTest extends TestCase {

	private const LANGUAGE = [ 'code' => 'nb', 'name' => 'Norsk bokmål', 'locale' => 'nb_NO' ];

	protected function setUp(): void {
		parent::setUp();
		// Translator::request() is overridden; the AI Client only has to exist.
		Functions\when( 'wp_ai_client_prompt' )->justReturn( null );
	}

	public function test_retries_once_when_no_provider_is_available(): void {
		$translator = $this->translator( [ $this->no_models(), 'Hei' ] );

		$this->assertSame( 'Hei', $translator->translate_text( 'Hello', self::LANGUAGE, 'title' ) );
		$this->assertSame( 2, $translator->requests );
		$this->assertSame( 1, $translator->pauses );
	}

	public function test_reports_unreachable_provider_after_retry(): void {
		$translator = $this->translator( [ $this->no_models(), $this->no_models() ] );

		$result = $translator->translate_text( 'Hello', self::LANGUAGE, 'title' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aitfp_provider_unavailable', $result->get_error_code() );
		$this->assertSame( 'The AI provider could not be reached. Check Settings → Connectors and retry.', $result->get_error_message() );
		$this->assertSame( 2, $translator->requests );
	}

	public function test_does_not_retry_other_errors(): void {
		$error      = new WP_Error( 'prompt_client_error', 'Invalid API key.' );
		$translator = $this->translator( [ $error, 'Hei' ] );

		$this->assertSame( $error, $translator->translate_text( 'Hello', self::LANGUAGE, 'title' ) );
		$this->assertSame( 1, $translator->requests );
		$this->assertSame( 0, $translator->pauses );
	}

	public function test_content_is_translated_as_segments_without_sending_markup(): void {
		$content    = '<!-- wp:image {"id":114} --><figure class="wp-block-image"><img src="http://example.test/garden.png" alt="A garden" class="wp-image-114"/><figcaption>Our garden</figcaption></figure><!-- /wp:image -->';
		$translator = $this->translator( [ "```json\n[\"Ein Garten\", \"Unser Garten\"]\n```" ] );

		$result = $translator->translate_content( $content, self::LANGUAGE );

		$this->assertSame( str_replace( [ 'A garden', 'Our garden' ], [ 'Ein Garten', 'Unser Garten' ], $content ), $result );
		$this->assertSame( [ '["A garden","Our garden"]' ], $translator->prompts );
	}

	public function test_retries_when_segment_count_does_not_match(): void {
		$translator = $this->translator( [ 'not json', '["Hei"]' ] );

		$this->assertSame( '<p>Hei</p>', $translator->translate_content( '<p>Hello</p>', self::LANGUAGE ) );
		$this->assertSame( 2, $translator->requests );

		$translator = $this->translator( [ '["Hei","ekstra"]', '[1]' ] );
		$result     = $translator->translate_content( '<p>Hello</p>', self::LANGUAGE );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aitfp_segment_mismatch', $result->get_error_code() );
	}

	public function test_skips_the_ai_when_there_is_no_text(): void {
		$content    = '<!-- wp:separator --><hr class="wp-block-separator"/><!-- /wp:separator -->';
		$translator = $this->translator( [] );

		$this->assertSame( $content, $translator->translate_content( $content, self::LANGUAGE ) );
		$this->assertSame( 0, $translator->requests );
	}

	private function no_models(): WP_Error {
		return new WP_Error( 'prompt_invalid_argument', 'No models found that support text_generation for this prompt.' );
	}

	/**
	 * @param list<string|WP_Error> $responses Responses returned in order.
	 */
	private function translator( array $responses ): Translator {
		return new class( $responses ) extends Translator {
			public int $requests = 0;
			public int $pauses   = 0;
			public array $prompts = [];

			public function __construct( private array $responses ) {}

			protected function request( string $text, string $instruction, string $field, array $language ) {
				++$this->requests;
				$this->prompts[] = $text;
				return array_shift( $this->responses );
			}

			protected function pause( int $seconds ): void {
				++$this->pauses;
			}
		};
	}
}
