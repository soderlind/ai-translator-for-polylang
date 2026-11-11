<?php
/**
 * Text generator adapter for the WordPress AI Client.
 *
 * @package AiTranslatorForPolylang
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang\Translation;

use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WP_AI_Client_Prompt_Builder;
use WP_Error;

/**
 * Sends prompts through wp_ai_client_prompt(), with a request timeout and a
 * retry when no AI provider is available.
 */
final class AiClientGenerator implements TextGenerator {

	/**
	 * Default request timeout in seconds.
	 */
	public const DEFAULT_TIMEOUT = 180;

	/**
	 * Attempts made when no AI provider is available.
	 */
	private const PROVIDER_ATTEMPTS = 2;

	/**
	 * Seconds to wait before retrying when no AI provider is available.
	 */
	private const PROVIDER_RETRY_DELAY = 5;

	/**
	 * Called before every AI request. Returning false aborts the request.
	 *
	 * @var (callable(): bool)|null
	 */
	private $before_request = null;

	/**
	 * Waits between retries.
	 *
	 * @var callable(int): mixed
	 */
	private $pause;

	/**
	 * Constructor.
	 *
	 * @param (callable(int): mixed)|null $pause Waits the given seconds between retries. Defaults to sleep().
	 */
	public function __construct( ?callable $pause = null ) {
		$this->pause = $pause ?? 'sleep';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param (callable(): bool)|null $callback Callback, or null to remove it.
	 */
	public function before_request( ?callable $callback ): void {
		$this->before_request = $callback;
	}

	/**
	 * Runs a prompt, retrying once when no provider is available.
	 *
	 * @param string               $prompt      User prompt.
	 * @param string               $instruction System instruction.
	 * @param string               $field       Field being translated.
	 * @param array<string,string> $language    Target language.
	 * @return string|WP_Error
	 */
	public function generate( string $prompt, string $instruction, string $field, array $language ): string|WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'aitfp_ai_client_missing',
				__( 'The WordPress AI Client is not available. WordPress 7.0 or later is required.', 'ai-translator-for-polylang' )
			);
		}

		for ( $attempt = 1; ; $attempt++ ) {
			if ( $this->before_request && false === ( $this->before_request )() ) {
				return new WP_Error(
					'aitfp_lock_lost',
					__( 'Another worker took over this translation.', 'ai-translator-for-polylang' )
				);
			}

			$result = $this->request( $prompt, $instruction, $field, $language );
			if ( ! is_wp_error( $result ) ) {
				return (string) $result;
			}

			if ( ! self::is_provider_unavailable( $result ) ) {
				return $result;
			}

			if ( $attempt >= self::PROVIDER_ATTEMPTS ) {
				return new WP_Error(
					'aitfp_provider_unavailable',
					__( 'The AI provider could not be reached. Check Settings → Connectors and retry.', 'ai-translator-for-polylang' ),
					[ 'original_message' => $result->get_error_message() ]
				);
			}

			( $this->pause )( self::PROVIDER_RETRY_DELAY );
		}
	}

	/**
	 * Sends one prompt to the AI Client.
	 *
	 * @param string               $prompt      User prompt.
	 * @param string               $instruction System instruction.
	 * @param string               $field       Field being translated.
	 * @param array<string,string> $language    Target language.
	 * @return string|WP_Error
	 */
	private function request( string $prompt, string $instruction, string $field, array $language ) {
		$request_options = new RequestOptions();
		/**
		 * Filters the AI request timeout in seconds.
		 *
		 * @param int $timeout Timeout in seconds.
		 */
		$request_options->setTimeout( (float) apply_filters( 'aitfp_request_timeout', self::DEFAULT_TIMEOUT ) );

		$builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $instruction )
			->using_request_options( $request_options );

		/**
		 * Filters the prompt builder before a translation request is sent.
		 *
		 * Use this to pick a provider or model, e.g. `$builder->using_model_preference( 'gpt-5-mini' )`.
		 *
		 * @param WP_AI_Client_Prompt_Builder $builder  Prompt builder.
		 * @param string                      $field    Field being translated: title, excerpt or content.
		 * @param array<string,string>        $language Target language ( code, name, locale ).
		 */
		$builder = apply_filters( 'aitfp_prompt_builder', $builder, $field, $language );

		$result = $builder->generate_text();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return (string) $result;
	}

	/**
	 * Whether an AI Client error means no configured provider was usable.
	 *
	 * Core reports a failed provider availability check (network error, timeout,
	 * rate limit) as "No models found …", which is often transient.
	 *
	 * @param WP_Error $error AI Client error.
	 * @return bool
	 */
	private static function is_provider_unavailable( WP_Error $error ): bool {
		return 'prompt_invalid_argument' === $error->get_error_code()
			&& str_starts_with( $error->get_error_message(), 'No models found' );
	}
}
