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
	private const ACTION_STATUS    = 'aitfp_translation_status';

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
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_status_script' ] );
		add_action( 'wp_ajax_' . self::ACTION_STATUS, [ $this, 'ajax_translation_status' ] );
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
			$label = esc_html(
				/* translators: %s: Language name. */
				sprintf( __( 'Translating to %s…', 'ai-translator-for-polylang' ), $this->language_name( $post->ID ) )
			);
			$states['aitfp'] = $this->post_state_markup( $post->ID, $label );
		} elseif ( TranslationJob::STATUS_DONE === $status ) {
			$states['aitfp'] = $this->post_state_markup(
				$post->ID,
				esc_html__( 'AI translation complete', 'ai-translator-for-polylang' )
			);
		} elseif ( TranslationJob::STATUS_FAILED === $status ) {
			$error           = (string) get_post_meta( $post->ID, TranslationJob::META_ERROR, true );
			$states['aitfp'] = $this->post_state_markup(
				$post->ID,
				sprintf(
					'<details class="aitfp-translation-error"><summary>%1$s</summary><p>%2$s</p></details>',
					esc_html__( 'Translation failed — view error', 'ai-translator-for-polylang' ),
					esc_html( $error )
				)
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
	 * Enqueues the status poller only for a queued translation or its editor.
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function enqueue_status_script( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, [ 'edit.php', 'post.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$is_editor = 'post' === $screen->base;
		if ( $is_editor ) {
			$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status display.
		} elseif ( 'edit' === $screen->base ) {
			$post_id = isset( $_GET['aitfp_queued'] ) ? absint( $_GET['aitfp_queued'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status display.
		} else {
			return;
		}

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$status = TranslationJob::status( $post_id );
		if ( $is_editor && ! $this->is_active( $post_id ) ) {
			return;
		}

		wp_enqueue_script(
			'aitfp-admin-status',
			plugins_url( 'assets/admin-status.js', dirname( __DIR__, 2 ) . '/ai-translator-for-polylang.php' ),
			[],
			defined( 'AITFP_VERSION' ) ? AITFP_VERSION : '0.1.2',
			true
		);
		wp_localize_script(
			'aitfp-admin-status',
			'aitfpTranslationStatus',
			[
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::ACTION_STATUS ),
				'postId'   => $post_id,
				'status'   => $status,
				'interval' => 10000,
				'strings'  => [
					'pending'       => __( 'Translation to %s is queued.', 'ai-translator-for-polylang' ),
					'processing'    => __( 'Translating to %s in the background.', 'ai-translator-for-polylang' ),
					'autoUpdate'    => __( 'This notice will update automatically.', 'ai-translator-for-polylang' ),
					'editorWarning' => __( 'Avoid editing or publishing this draft until translation finishes. Edits made while the AI is working will discard its result.', 'ai-translator-for-polylang' ),
					'done'          => __( 'AI translation complete', 'ai-translator-for-polylang' ),
					'ready'         => __( 'Translation to %s is ready to review.', 'ai-translator-for-polylang' ),
					'failedMessage' => __( 'Translation to %1$s failed: %2$s', 'ai-translator-for-polylang' ),
					'failed'        => __( 'Translation failed', 'ai-translator-for-polylang' ),
					'viewError'     => __( 'View error', 'ai-translator-for-polylang' ),
					'openDraft'     => __( 'Open draft', 'ai-translator-for-polylang' ),
					'retry'         => __( 'Retry translation', 'ai-translator-for-polylang' ),
					'reloadEditor'  => __( 'Reload this editor to see the translated draft.', 'ai-translator-for-polylang' ),
					'reload'        => __( 'Reload editor', 'ai-translator-for-polylang' ),
					'pollError'     => __( 'Could not refresh translation status. Retrying…', 'ai-translator-for-polylang' ),
					'statusError'   => __( 'Translation status is unavailable. Reload the page to check again.', 'ai-translator-for-polylang' ),
				],
			]
		);
	}

	/**
	 * Returns the translation status for an authorized editor.
	 */
	public function ajax_translation_status(): void {
		check_ajax_referer( self::ACTION_STATUS, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error(
				[ 'message' => __( 'You are not allowed to view this translation status.', 'ai-translator-for-polylang' ) ],
				403
			);
		}

		$status = TranslationJob::status( $post_id );
		if ( ! in_array( $status, [ TranslationJob::STATUS_PENDING, TranslationJob::STATUS_PROCESSING, TranslationJob::STATUS_DONE, TranslationJob::STATUS_FAILED ], true ) ) {
			wp_send_json_error(
				[ 'message' => __( 'Translation status is unavailable.', 'ai-translator-for-polylang' ) ],
				404
			);
		}

		wp_send_json_success(
			[
				'status'   => $status,
				'language' => $this->language_name( $post_id ),
				'error'    => (string) get_post_meta( $post_id, TranslationJob::META_ERROR, true ),
				'editUrl'  => get_edit_post_link( $post_id, 'raw' ) ?: '',
				'retryUrl' => TranslationJob::STATUS_FAILED === $status ? $this->retry_url( $post_id ) : '',
			]
		);
	}

	/**
	 * Shows the current status after creating or retrying a translation.
	 */
	public function notices(): void {
		$post_id = isset( $_GET['aitfp_queued'] ) ? absint( $_GET['aitfp_queued'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( $post_id ) {
			if ( current_user_can( 'edit_post', $post_id ) ) {
				echo $this->status_notice( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in status_notice().
			}
			return;
		}

		$screen  = get_current_screen();
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status display.
		if ( $screen && 'post' === $screen->base && $post_id && $this->is_active( $post_id ) && current_user_can( 'edit_post', $post_id ) ) {
			echo $this->status_notice( $post_id, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in status_notice().
		}
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
	 * Whether a translation is waiting or actively being translated.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_active( int $post_id ): bool {
		return in_array(
			TranslationJob::status( $post_id ),
			[ TranslationJob::STATUS_PENDING, TranslationJob::STATUS_PROCESSING ],
			true
		);
	}

	/**
	 * Builds a status-aware notice for the post list or editor.
	 *
	 * @param int  $post_id  Translation post ID.
	 * @param bool $is_editor Whether the notice is in the editor.
	 * @return string
	 */
	private function status_notice( int $post_id, bool $is_editor = false ): string {
		$status       = TranslationJob::status( $post_id );
		$language     = $this->language_name( $post_id );
		$notice_class = 'notice-info';

		if ( TranslationJob::STATUS_PENDING === $status ) {
			$message = esc_html(
				sprintf(
					/* translators: %s: Language name. */
					__( 'Translation to %s is queued.', 'ai-translator-for-polylang' ),
					$language
				)
			);
			if ( $is_editor ) {
				$message .= ' ' . esc_html__( 'Avoid editing or publishing this draft until translation finishes. Edits made while the AI is working will discard its result.', 'ai-translator-for-polylang' );
			} else {
				$message .= ' ' . esc_html__( 'This notice will update automatically.', 'ai-translator-for-polylang' );
			}
		} elseif ( TranslationJob::STATUS_PROCESSING === $status ) {
			$message = esc_html(
				sprintf(
					/* translators: %s: Language name. */
					__( 'Translating to %s in the background.', 'ai-translator-for-polylang' ),
					$language
				)
			);
			if ( $is_editor ) {
				$message .= ' ' . esc_html__( 'Avoid editing or publishing this draft until translation finishes. Edits made while the AI is working will discard its result.', 'ai-translator-for-polylang' );
			} else {
				$message .= ' ' . esc_html__( 'This notice will update automatically.', 'ai-translator-for-polylang' );
			}
		} elseif ( TranslationJob::STATUS_DONE === $status ) {
			$notice_class = 'notice-success';
			if ( $is_editor ) {
				$message = esc_html__( 'Translation is complete. Reload this editor to see the translated draft.', 'ai-translator-for-polylang' );
			} else {
				$message = esc_html(
					sprintf(
						/* translators: %s: Language name. */
						__( 'Translation to %s is ready to review.', 'ai-translator-for-polylang' ),
						$language
					)
				);
				$edit_url = get_edit_post_link( $post_id, 'raw' );
				if ( $edit_url ) {
					$message .= ' ' . sprintf(
						'<a href="%1$s">%2$s</a>',
						esc_url( $edit_url ),
						esc_html__( 'Open draft', 'ai-translator-for-polylang' )
					);
				}
			}
		} elseif ( TranslationJob::STATUS_FAILED === $status ) {
			$notice_class = 'notice-error';
			$message      = esc_html(
				sprintf(
					/* translators: 1: Language name, 2: Error message. */
					__( 'Translation to %1$s failed: %2$s', 'ai-translator-for-polylang' ),
					$language,
					(string) get_post_meta( $post_id, TranslationJob::META_ERROR, true )
				)
			);
			$message     .= ' ' . sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $this->retry_url( $post_id ) ),
				esc_html__( 'Retry translation', 'ai-translator-for-polylang' )
			);
		} else {
			$notice_class = 'notice-warning';
			$message      = esc_html__( 'Translation status is unavailable. Check the post list for its current state.', 'ai-translator-for-polylang' );
		}

		return sprintf(
			'<div class="notice %1$s is-dismissible aitfp-status-notice" role="status" aria-live="polite" data-aitfp-status-notice data-aitfp-post-id="%2$d" data-aitfp-editor="%3$s"><p data-aitfp-status-message>%4$s</p></div>',
			esc_attr( $notice_class ),
			$post_id,
			$is_editor ? 'true' : 'false',
			$message
		);
	}

	/**
	 * Marks a post-list state so polling can update it without reloading the page.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content Escaped text or markup.
	 * @return string
	 */
	private function post_state_markup( int $post_id, string $content ): string {
		return sprintf(
			'<span data-aitfp-post-state data-aitfp-post-id="%1$d">%2$s</span>',
			$post_id,
			$content
		);
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
