<?php
/**
 * Scripted text generator for tests.
 *
 * @package AiTranslatorForPolylang\Tests
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang\Tests;

use AiTranslatorForPolylang\Translation\TextGenerator;
use WP_Error;

/**
 * Returns scripted replies in order and records the prompts it received.
 */
final class FakeGenerator implements TextGenerator {

	/**
	 * Prompts received, in order.
	 *
	 * @var list<string>
	 */
	public array $prompts = [];

	/**
	 * Current before-request callback.
	 *
	 * @var (callable(): bool)|null
	 */
	public $before_request = null;

	/**
	 * @param list<string|WP_Error> $replies Replies returned in order.
	 */
	public function __construct( private array $replies = [] ) {}

	public function generate( string $prompt, string $instruction, string $field, array $language ): string|WP_Error {
		$this->prompts[] = $prompt;
		return array_shift( $this->replies ) ?? new WP_Error( 'fake_exhausted', 'No scripted reply left.' );
	}

	public function before_request( ?callable $callback ): void {
		$this->before_request = $callback;
	}
}
