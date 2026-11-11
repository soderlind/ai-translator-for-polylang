<?php
/**
 * Minimal WordPress stubs for unit tests that run without WordPress.
 *
 * @package AiTranslatorForPolylang\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
	function wp_ai_client_prompt( string $prompt = '' ): never {
		throw new LogicException( 'Unit tests must override Translator::request().' );
	}
}
