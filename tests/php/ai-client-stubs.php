<?php
/**
 * AI Client classes needed by unit tests (WordPress 7.0 is not a dev dependency).
 *
 * @package AiTranslatorForPolylang\Tests
 */

declare(strict_types=1);

namespace WordPress\AiClient\Providers\Http\DTO;

if ( ! class_exists( RequestOptions::class ) ) {
	class RequestOptions {
		public ?float $timeout = null;

		public function setTimeout( float $timeout ): void {
			$this->timeout = $timeout;
		}
	}
}
