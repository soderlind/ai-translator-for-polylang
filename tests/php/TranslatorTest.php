<?php
/**
 * Tests for Translator.
 *
 * @package AiTranslatorForPolylang\Tests
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang\Tests;

use AiTranslatorForPolylang\Translation\Translator;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/wp-stubs.php';

final class TranslatorTest extends TestCase {

	private const LANGUAGE = [ 'code' => 'nb', 'name' => 'Norsk bokmål', 'locale' => 'nb_NO' ];

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

			public function __construct( private array $responses ) {}

			protected function request( string $text, string $instruction, string $field, array $language ) {
				++$this->requests;
				return array_shift( $this->responses );
			}

			protected function pause( int $seconds ): void {
				++$this->pauses;
			}
		};
	}
}
