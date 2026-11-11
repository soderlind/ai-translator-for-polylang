<?php
/**
 * WP-CLI commands.
 *
 * @package AiTranslatorForPolylang
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang\Cli;

use AiTranslatorForPolylang\Creator;
use AiTranslatorForPolylang\Translation\TranslationJob;
use WP_CLI;
use WP_Post;

/**
 * Create Polylang translations with AI.
 */
class Command {

	/**
	 * Constructor.
	 *
	 * @param Creator        $creator Translation creator.
	 * @param TranslationJob $job     Translation job.
	 */
	public function __construct( private Creator $creator, private TranslationJob $job ) {}

	/**
	 * Creates a linked translation of a post and translates it with AI right away.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : ID of the source post.
	 *
	 * --language=<slug>
	 * : Polylang language slug, e.g. nb.
	 *
	 * [--async]
	 * : Queue the translation in WP-Cron instead of running it now.
	 *
	 * ## EXAMPLES
	 *
	 *     wp aitfp translate 42 --language=nb --user=admin
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Associative arguments.
	 */
	public function translate( array $args, array $assoc_args ): void {
		$post = get_post( (int) $args[0] );
		if ( ! $post instanceof WP_Post ) {
			WP_CLI::error( 'Post not found.' );
		}
		if ( ! $this->creator->can_translate( $post ) ) {
			WP_CLI::error( 'You are not allowed to translate this post, or Polylang does not translate its post type. Pass --user=<admin>.' );
		}

		$language = $this->creator->language( $post, (string) ( $assoc_args['language'] ?? '' ) );
		if ( ! $language ) {
			$missing = array_keys( $this->creator->languages( $post ) );
			WP_CLI::error( $missing ? sprintf( 'Unknown language or translation already exists. Choose one of: %s', implode( ', ', $missing ) ) : 'The post is already translated into every language.' );
		}

		$translation_id = $this->creator->create( $post, $language );
		if ( is_wp_error( $translation_id ) ) {
			WP_CLI::error( $translation_id );
		}

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'async', false ) ) {
			WP_CLI::success( sprintf( 'Created translation %d and queued AI translation to %s.', $translation_id, $language['name'] ) );
			return;
		}

		$this->run( [ (string) $translation_id ], [] );
	}

	/**
	 * Runs (or re-runs) the AI translation of a translation post now.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : ID of the translation post.
	 *
	 * ## EXAMPLES
	 *
	 *     wp aitfp run 43
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Associative arguments.
	 */
	public function run( array $args, array $assoc_args ): void {
		$post_id = (int) $args[0];
		WP_CLI::log( sprintf( 'Translating post %d…', $post_id ) );

		wp_clear_scheduled_hook( TranslationJob::HOOK, [ $post_id ] );
		$result = $this->job->run( $post_id );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result );
		}

		WP_CLI::success( sprintf( 'Translated post %d: %s', $post_id, get_the_title( $post_id ) ) );
	}
}
