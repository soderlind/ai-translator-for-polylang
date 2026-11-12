<?php
/**
 * Tests for AiClientGenerator.
 *
 * @package AiTranslatorForPolylang\Tests
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang\Tests;

use AiTranslatorForPolylang\Translation\AiClientGenerator;
use Brain\Monkey\Functions;
use WP_Error;

final class AiClientGeneratorTest extends TestCase {

	private const LANGUAGE = [ 'code' => 'nb', 'name' => 'Norsk bokmål', 'locale' => 'nb_NO' ];

	/**
	 * Results the fake prompt builder returns, in order.
	 *
	 * @var list<string|WP_Error>
	 */
	private array $results = [];

	private int $requests = 0;

	/**
	 * Seconds paused between retries.
	 *
	 * @var list<int>
	 */
	private array $pauses = [];

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_ai_client_prompt' )->alias(
			function (): object {
				++$this->requests;
				$results = &$this->results;
				return new class( $results ) {
					public function __construct( private array &$results ) {}

					public function __call( string $name, array $args ): self {
						return $this;
					}

					public function generate_text(): string|WP_Error {
						return array_shift( $this->results );
					}
				};
			}
		);
	}

	public function test_retries_once_when_no_provider_is_available(): void {
		$this->results = [ $this->no_models(), 'Hei' ];

		$this->assertSame( 'Hei', $this->generator()->generate( 'Hello', 'Translate', 'title', self::LANGUAGE ) );
		$this->assertSame( 2, $this->requests );
		$this->assertSame( [ 5 ], $this->pauses );
	}

	public function test_reports_unreachable_provider_after_retry(): void {
		$this->results = [ $this->no_models(), $this->no_models() ];

		$result = $this->generator()->generate( 'Hello', 'Translate', 'title', self::LANGUAGE );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aitfp_provider_unavailable', $result->get_error_code() );
		$this->assertSame( 'The AI provider could not be reached. Check Settings → Connectors and retry.', $result->get_error_message() );
		$this->assertSame( 2, $this->requests );
	}

	public function test_does_not_retry_other_errors(): void {
		$error         = new WP_Error( 'prompt_client_error', 'Invalid API key.' );
		$this->results = [ $error, 'Hei' ];

		$this->assertSame( $error, $this->generator()->generate( 'Hello', 'Translate', 'title', self::LANGUAGE ) );
		$this->assertSame( 1, $this->requests );
		$this->assertSame( [], $this->pauses );
	}

	public function test_before_request_runs_before_each_attempt_and_can_abort(): void {
		$this->results = [ $this->no_models(), 'Hei' ];
		$generator     = $this->generator();
		$beats         = 0;
		$generator->before_request(
			static function () use ( &$beats ): bool {
				++$beats;
				return true;
			}
		);

		$this->assertSame( 'Hei', $generator->generate( 'Hello', 'Translate', 'title', self::LANGUAGE ) );
		$this->assertSame( 2, $beats );

		$generator->before_request( static fn(): bool => false );
		$result = $generator->generate( 'Hello', 'Translate', 'title', self::LANGUAGE );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aitfp_lock_lost', $result->get_error_code() );
		$this->assertSame( 2, $this->requests );
	}

	private function generator(): AiClientGenerator {
		return new AiClientGenerator(
			function ( int $seconds ): void {
				$this->pauses[] = $seconds;
			}
		);
	}

	private function no_models(): WP_Error {
		return new WP_Error( 'prompt_invalid_argument', 'No models found that support text_generation for this prompt.' );
	}
}
