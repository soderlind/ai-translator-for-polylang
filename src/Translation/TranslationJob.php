<?php
/**
 * Background translation job.
 *
 * @package AiTranslatorForPolylang
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang\Translation;

use WP_Error;
use WP_Post;

/**
 * Queues and runs the translation of a new Polylang translation.
 */
class TranslationJob {

	public const HOOK = 'aitfp_translate_post';

	public const META_STATUS   = '_aitfp_status';
	public const META_LANGUAGE = '_aitfp_language';
	public const META_ERROR    = '_aitfp_error';
	public const META_USER     = '_aitfp_user';
	public const META_SOURCE   = '_aitfp_source';
	public const META_UPDATED  = '_aitfp_updated';

	public const STATUS_PENDING    = 'pending';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_DONE       = 'done';
	public const STATUS_FAILED     = 'failed';

	/**
	 * Seconds without a heartbeat after which a job in "processing" is considered dead.
	 *
	 * A heartbeat is written before every AI request, so a live job is only this quiet
	 * if a single request hangs far beyond the request timeout.
	 */
	public const STALE_AFTER = 15 * MINUTE_IN_SECONDS;

	/**
	 * Option name prefix of the per-post worker lock.
	 */
	public const LOCK_PREFIX = '_aitfp_lock_';

	/**
	 * Constructor.
	 *
	 * @param Translator    $translator Translator.
	 * @param TextGenerator $generator  The translator's text generator, whose requests refresh the job heartbeat.
	 */
	public function __construct( private Translator $translator, private TextGenerator $generator ) {}

	/**
	 * Registers the cron hook.
	 */
	public function register(): void {
		add_action( self::HOOK, [ $this, 'handle_cron' ] );
		add_action( 'before_delete_post', [ $this, 'unschedule' ] );
	}

	/**
	 * Removes pending jobs for a post that is being deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public function unschedule( $post_id ): void {
		wp_clear_scheduled_hook( self::HOOK, [ (int) $post_id ] );
	}

	/**
	 * Cron callback.
	 *
	 * @param int $post_id Translation post ID.
	 */
	public function handle_cron( $post_id ): void {
		$this->run( (int) $post_id );
	}

	/**
	 * Queues a translation for background translation.
	 *
	 * @param int                  $post_id   Translation post ID.
	 * @param array<string,string> $language  Target language ( code, name, locale ).
	 * @param int                  $source_id Source post ID.
	 * @param int                  $user_id   User who requested the translation.
	 * @param bool                 $schedule  Whether to schedule a WP-Cron event. Pass false when the caller runs the job itself.
	 */
	public function queue( int $post_id, array $language, int $source_id, int $user_id, bool $schedule = true ): void {
		update_post_meta( $post_id, self::META_LANGUAGE, $language );
		update_post_meta( $post_id, self::META_SOURCE, $source_id );
		update_post_meta( $post_id, self::META_USER, $user_id );
		delete_post_meta( $post_id, self::META_ERROR );
		$this->set_status( $post_id, self::STATUS_PENDING );

		wp_clear_scheduled_hook( self::HOOK, [ $post_id ] );
		if ( ! $schedule ) {
			return;
		}
		wp_schedule_single_event( time(), self::HOOK, [ $post_id ] );

		/**
		 * Filters whether to start WP-Cron right away so the translation begins immediately.
		 *
		 * Runs even when DISABLE_WP_CRON is set, since the user is waiting for the result.
		 * Return false to leave the job to the regular cron runner.
		 *
		 * @param bool $spawn   Whether to spawn WP-Cron. Default true.
		 * @param int  $post_id Translation post ID.
		 */
		if ( apply_filters( 'aitfp_spawn_cron', true, $post_id ) ) {
			spawn_cron();
		}
	}

	/**
	 * Translates the post in place.
	 *
	 * @param int $post_id Translation post ID.
	 * @return true|WP_Error
	 */
	public function run( int $post_id ) {
		$token = $this->acquire_lock( $post_id );
		if ( null === $token ) {
			return new WP_Error( 'aitfp_in_progress', __( 'This post is already being translated.', 'ai-translator-for-polylang' ) );
		}

		try {
			return $this->run_locked( $post_id, $token );
		} finally {
			$this->generator->before_request( null );
			$this->release_lock( $post_id, $token );
		}
	}

	/**
	 * Translates the post while holding its worker lock.
	 *
	 * @param int    $post_id Translation post ID.
	 * @param string $token   Lock token.
	 * @return true|WP_Error
	 */
	private function run_locked( int $post_id, string $token ) {
		clean_post_cache( $post_id );
		$post     = get_post( $post_id );
		$language = get_post_meta( $post_id, self::META_LANGUAGE, true );
		if ( ! $post instanceof WP_Post || ! is_array( $language ) || empty( $language['locale'] ) ) {
			return new WP_Error( 'aitfp_invalid_job', __( 'Nothing to translate for this post.', 'ai-translator-for-polylang' ) );
		}

		$this->set_status( $post_id, self::STATUS_PROCESSING );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		$this->generator->before_request( fn(): bool => $this->heartbeat( $post_id, $token ) );

		// Save as the requesting user so kses and capabilities match a normal edit.
		$previous_user = get_current_user_id();
		$user_id       = (int) get_post_meta( $post_id, self::META_USER, true );
		wp_set_current_user( $user_id ? $user_id : (int) $post->post_author );

		$result = $this->translate_and_save( $post, $language, $token );

		wp_set_current_user( $previous_user );

		// A newer worker owns the job now; leave its status alone.
		if ( ! $this->owns_lock( $post_id, $token ) ) {
			return is_wp_error( $result ) && 'aitfp_lock_lost' === $result->get_error_code()
				? $result
				: new WP_Error( 'aitfp_lock_lost', __( 'Another worker took over this translation.', 'ai-translator-for-polylang' ) );
		}

		if ( is_wp_error( $result ) ) {
			update_post_meta( $post_id, self::META_ERROR, $result->get_error_message() );
			$this->set_status( $post_id, self::STATUS_FAILED );

			/**
			 * Fires when translating a post failed.
			 *
			 * @param int                  $post_id  Translation post ID.
			 * @param WP_Error             $result   Error.
			 * @param array<string,string> $language Target language.
			 */
			do_action( 'aitfp_translation_failed', $post_id, $result, $language );
			return $result;
		}

		delete_post_meta( $post_id, self::META_ERROR );
		$this->set_status( $post_id, self::STATUS_DONE );

		/**
		 * Fires after a post has been translated.
		 *
		 * @param int                  $post_id   Translation post ID.
		 * @param array<string,string> $language  Target language.
		 * @param int                  $source_id Source post ID.
		 */
		do_action( 'aitfp_translated', $post_id, $language, (int) get_post_meta( $post_id, self::META_SOURCE, true ) );
		return true;
	}

	/**
	 * Returns the job status of a post, or an empty string when it has none.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function status( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::META_STATUS, true );
	}

	/**
	 * Whether a job has had no heartbeat for long enough to be considered dead.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function is_stale( int $post_id ): bool {
		$updated = (int) get_post_meta( $post_id, self::META_UPDATED, true );
		return $updated > 0 && ( time() - $updated ) > self::STALE_AFTER;
	}

	/**
	 * Translates the fields and saves the post.
	 *
	 * @param WP_Post              $post     Translation post.
	 * @param array<string,string> $language Target language.
	 * @param string               $token    Lock token.
	 * @return true|WP_Error
	 */
	private function translate_and_save( WP_Post $post, array $language, string $token ) {
		$snapshot = [ $post->post_modified_gmt, $post->post_title, $post->post_excerpt, $post->post_content ];

		$title = $this->translator->translate_text( $post->post_title, $language, 'title' );
		if ( is_wp_error( $title ) ) {
			return $title;
		}

		$excerpt = $this->translator->translate_text( $post->post_excerpt, $language, 'excerpt' );
		if ( is_wp_error( $excerpt ) ) {
			return $excerpt;
		}

		$content = $this->translator->translate_content( $post->post_content, $language );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		if ( ! $this->heartbeat( $post->ID, $token ) ) {
			return new WP_Error( 'aitfp_lock_lost', __( 'Another worker took over this translation.', 'ai-translator-for-polylang' ) );
		}

		// Modification times have one-second precision, so compare the fields too.
		clean_post_cache( $post->ID );
		$current = get_post( $post->ID );
		if ( ! $current instanceof WP_Post
			|| [ $current->post_modified_gmt, $current->post_title, $current->post_excerpt, $current->post_content ] !== $snapshot
		) {
			return new WP_Error(
				'aitfp_post_changed',
				__( 'The post was edited while it was being translated, so the translation was not saved. Retry to translate the current version.', 'ai-translator-for-polylang' )
			);
		}

		$data = [
			'ID'           => $post->ID,
			'post_title'   => $title,
			'post_excerpt' => $excerpt,
			'post_content' => $content,
		];
		if ( '' !== $title ) {
			$data['post_name'] = sanitize_title( $title );
		}

		/**
		 * Filters the translated post data before it is saved.
		 *
		 * @param array                $data     Post data passed to wp_update_post().
		 * @param WP_Post              $post     Post before translation.
		 * @param array<string,string> $language Target language.
		 */
		$data = apply_filters( 'aitfp_translated_post_data', $data, $post, $language );

		$updated = wp_update_post( wp_slash( $data ), true );
		return is_wp_error( $updated ) ? $updated : true;
	}

	/**
	 * Stores a job status and its timestamp.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  Status.
	 */
	private function set_status( int $post_id, string $status ): void {
		update_post_meta( $post_id, self::META_STATUS, $status );
		update_post_meta( $post_id, self::META_UPDATED, time() );
	}

	/**
	 * Atomically claims the post's worker lock, taking over a lock whose heartbeat is stale.
	 *
	 * The lock is a row in the options table, read and written with direct queries so the
	 * unique option_name index makes the claim atomic and no object cache can serve stale values.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null Lock token, or null when another worker holds the lock.
	 */
	private function acquire_lock( int $post_id ): ?string {
		global $wpdb;

		$name  = self::LOCK_PREFIX . $post_id;
		$token = wp_generate_password( 20, false );
		$value = $token . '|' . time();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$claimed = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $value ) );
		if ( 1 === $claimed ) {
			return $token;
		}

		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( null === $current ) {
			// Released in the meantime; try once more.
			$claimed = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $value ) );
			return 1 === $claimed ? $token : null;
		}

		$heartbeat = (int) substr( (string) strrchr( (string) $current, '|' ), 1 );
		if ( time() - $heartbeat <= self::STALE_AFTER ) {
			return null;
		}

		// Compare-and-swap so only one worker can take over a stale lock.
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $current ) );
		// phpcs:enable

		return 1 === $taken ? $token : null;
	}

	/**
	 * Whether the given token still holds the post's lock.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $token   Lock token.
	 * @return bool
	 */
	private function owns_lock( int $post_id, string $token ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_PREFIX . $post_id ) );

		return is_string( $current ) && str_starts_with( $current, $token . '|' );
	}

	/**
	 * Refreshes the lock and job heartbeat, if the token still holds the lock.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $token   Lock token.
	 * @return bool False when another worker has taken over.
	 */
	private function heartbeat( int $post_id, string $token ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s", $token . '|' . time(), self::LOCK_PREFIX . $post_id, $wpdb->esc_like( $token . '|' ) . '%' ) );
		if ( ! $this->owns_lock( $post_id, $token ) ) {
			return false;
		}

		update_post_meta( $post_id, self::META_UPDATED, time() );
		return true;
	}

	/**
	 * Releases the post's lock, if the token still holds it.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $token   Lock token.
	 */
	private function release_lock( int $post_id, string $token ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::LOCK_PREFIX . $post_id, $wpdb->esc_like( $token . '|' ) . '%' ) );
	}
}
