<?php
/**
 * Base test case that mocks WordPress functions with Brain Monkey.
 *
 * @package AiTranslatorForPolylang\Tests
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\stubs(
			[
				'is_wp_error'            => static fn( $thing ): bool => $thing instanceof \WP_Error,
				'wp_kses_uri_attributes' => static fn(): array => [ 'href', 'src', 'action', 'cite', 'srcset' ],
				'wp_has_noncharacters'   => false,
				'_doing_it_wrong'        => static function (): void {},
			]
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
