<?php
/**
 * Admin UI: Polylang "Add a translation" link, retry action and status display.
 *
 * @package AiTranslatorForPolylang
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang\Admin;

use AiTranslatorForPolylang\Creator;
use AiTranslatorForPolylang\Translation\TranslationJob;
use WP_Post;

/**
 * Wires AI translation into Polylang's translation links in wp-admin.
 */
class Admin {

	private const ACTION_TRANSLATE = 'aitfp_translate';
	private const ACTION_RETRY     = 'aitfp_retry';

	/**
	 * Constructor.
	 *
	 * @param Creator        $creator Translation creator.
	 * @param TranslationJob $job     Translation job.
	 */
	public function __construct( private Creator $creator, private TranslationJob $job ) {}

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_filter( 'pll_get_new_post_translation_link', [ $this, 'new_translation_link' ], 10, 3 );
		add_filter( 'post_row_actions', [ $this, 'row_actions' ], 20, 2 );
		add_filter( 'page_row_actions', [ $this, 'row_actions' ], 20, 2 );
		add_filter( 'display_post_states', [ $this, 'post_states' ], 10, 2 );
		add_action( 'admin_action_' . self::ACTION_TRANSLATE, [ $this, 'handle_translate' ] );
		add_action( 'admin_action_' . self::ACTION_RETRY, [ $this, 'handle_retry' ] );
		add_action( 'admin_notices', [ $this, 'notices' ] );
	}

	/**
	 * Points Polylang's "Add a translation" (+) link at an AI translation.
	 *
	 * @param string $link     Polylang's link to create an empty translation.
	 * @param object $language Target PLL_Language.
	 * @param int    $post_id  Source post ID.
	 * @return string
	 */
	public function new_translation_link( $link, $language, $post_id ) {
		$post = get_post( (int) $post_id );
		if ( empty( $link ) || ! $post instanceof WP_Post || empty( $language->slug ) ) {
			return $link;
		}

		if ( ! $this->creator->can_translate( $post ) || ! $this->creator->language( $post, (string) $language->slug ) ) {
			return $link;
		}

		return add_query_arg(
			[
				'action'   => self::ACTION_TRANSLATE,
				'post'     => $post->ID,
				'language' => $language->slug,
				'_wpnonce' => wp_create_nonce( self::ACTION_TRANSLATE . '_' . $post->ID ),
			],
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Creates the translation, queues the AI translation and returns to the post list.
	 */
	public function handle_translate(): void {
		$post = $this->requested_post();
		if ( ! $post ) {
			wp_die( esc_html__( 'Post not found.', 'ai-translator-for-polylang' ), '', [ 'response' => 404 ] );
		}

		check_admin_referer( self::ACTION_TRANSLATE . '_' . $post->ID );

		if ( ! $this->creator->can_translate( $post ) ) {
			wp_die( esc_html__( 'You are not allowed to translate this post.', 'ai-translator-for-polylang' ), '', [ 'response' => 403 ] );
		}

		$code     = isset( $_GET['language'] ) ? sanitize_key( wp_unslash( $_GET['language'] ) ) : '';
		$language = $this->creator->language( $post, $code );
		if ( ! $language ) {
			wp_die( esc_html__( 'This post already has a translation in that language.', 'ai-translator-for-polylang' ), '', [ 'response' => 400, 'back_link' => true ] );
		}

		$translation_id = $this->creator->create( $post, $language );
		if ( is_wp_error( $translation_id ) ) {
			wp_die( esc_html( $translation_id->get_error_message() ), '', [ 'response' => 500, 'back_link' => true ] );
		}

		$this->redirect_to_list( $post->post_type, $translation_id );
	}

	/**
	 * Adds a "Retry translation" row action to failed or stuck translations.
	 *
	 * @param array<string,string> $actions Row actions.
	 * @param WP_Post              $post    Post.
	 * @return array<string,string>
	 */
	public function row_actions( $actions, $post ) {
		if ( $post instanceof WP_Post && $this->can_retry( $post ) ) {
			$actions['aitfp_retry'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $this->retry_url( $post->ID ) ),
				esc_html__( 'Retry translation', 'ai-translator-for-polylang' )
			);
		}

		return $actions;
	}

	/**
	 * Shows translation progress next to the title in post lists.
	 *
	 * @param array<string,string> $states Post states.
	 * @param WP_Post              $post   Post.
	 * @return array<string,string>
	 */
	public function post_states( $states, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return $states;
		}

		$status = TranslationJob::status( $post->ID );

		if ( TranslationJob::STATUS_PENDING === $status || TranslationJob::STATUS_PROCESSING === $status ) {
			$states['aitfp'] = esc_html(
				/* translators: %s: Language name. */
				sprintf( __( 'Translating to %s…', 'ai-translator-for-polylang' ), $this->language_name( $post->ID ) )
			);
		} elseif ( TranslationJob::STATUS_FAILED === $status ) {
			$states['aitfp'] = sprintf(
				'<span title="%1$s">%2$s</span>',
				esc_attr( (string) get_post_meta( $post->ID, TranslationJob::META_ERROR, true ) ),
				esc_html__( 'Translation failed', 'ai-translator-for-polylang' )
			);
		}

		return $states;
	}

	/**
	 * Re-queues a failed or stuck translation.
	 */
	public function handle_retry(): void {
		$post = $this->requested_post();
		if ( ! $post ) {
			wp_die( esc_html__( 'Post not found.', 'ai-translator-for-polylang' ), '', [ 'response' => 404 ] );
		}

		check_admin_referer( self::ACTION_RETRY . '_' . $post->ID );

		if ( ! $this->can_retry( $post ) ) {
			wp_die( esc_html__( 'This translation cannot be retried.', 'ai-translator-for-polylang' ), '', [ 'response' => 403 ] );
		}

		$this->job->queue(
			$post->ID,
			(array) get_post_meta( $post->ID, TranslationJob::META_LANGUAGE, true ),
			(int) get_post_meta( $post->ID, TranslationJob::META_SOURCE, true ),
			get_current_user_id()
		);

		$this->redirect_to_list( $post->post_type, $post->ID );
	}

	/**
	 * Shows a confirmation after queuing a translation.
	 */
	public function notices(): void {
		$post_id = isset( $_GET['aitfp_queued'] ) ? absint( $_GET['aitfp_queued'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %s: Language name. */
					esc_html__( 'A draft translation was created and is being translated to %s with AI in the background. Avoid editing it until it is done, or the AI translation will be discarded. Reload this page to see when it is done.', 'ai-translator-for-polylang' ),
					esc_html( $this->language_name( $post_id ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Redirects back to the post list the user came from.
	 *
	 * @param string $post_type Post type.
	 * @param int    $post_id   Queued post ID.
	 */
	private function redirect_to_list( string $post_type, int $post_id ): void {
		$referer = wp_get_referer();
		$back    = $referer && str_contains( $referer, '/wp-admin/edit.php' )
			? remove_query_arg( 'aitfp_queued', $referer )
			: admin_url( 'post' === $post_type ? 'edit.php' : 'edit.php?post_type=' . $post_type );

		wp_safe_redirect( add_query_arg( 'aitfp_queued', $post_id, $back ) );
		exit;
	}

	/**
	 * Whether the current user may retry the post's translation.
	 *
	 * @param WP_Post $post Translation post.
	 * @return bool
	 */
	private function can_retry( WP_Post $post ): bool {
		$status = TranslationJob::status( $post->ID );
		if ( '' === $status || TranslationJob::STATUS_DONE === $status || ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}

		return TranslationJob::STATUS_FAILED === $status || $this->job->is_stale( $post->ID );
	}

	/**
	 * Name of the language a post is being translated to.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function language_name( int $post_id ): string {
		$language = get_post_meta( $post_id, TranslationJob::META_LANGUAGE, true );
		return is_array( $language ) ? (string) ( $language['name'] ?? '' ) : '';
	}

	/**
	 * Returns the post referenced by the `post` request parameter.
	 *
	 * @return WP_Post|null
	 */
	private function requested_post(): ?WP_Post {
		// phpcs:ignore WordPress.Security.NonceVerification -- Nonces are verified by the handlers.
		$post_id = isset( $_REQUEST['post'] ) ? absint( $_REQUEST['post'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Nonced URL for retrying a translation.
	 *
	 * @param int $post_id Translation post ID.
	 * @return string
	 */
	private function retry_url( int $post_id ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action' => self::ACTION_RETRY,
					'post'   => $post_id,
				],
				admin_url( 'admin.php' )
			),
			self::ACTION_RETRY . '_' . $post_id
		);
	}
}
