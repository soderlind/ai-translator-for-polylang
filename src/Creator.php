<?php
/**
 * Creates a translation and queues its AI translation.
 *
 * @package AiTranslatorForPolylang
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang;

use AiTranslatorForPolylang\Translation\TranslationJob;
use WP_Error;
use WP_Post;

/**
 * Glue between Polylang and the translation job.
 */
class Creator {

	/**
	 * Constructor.
	 *
	 * @param Polylang       $polylang Polylang integration.
	 * @param TranslationJob $job      Translation job.
	 */
	public function __construct( private Polylang $polylang, private TranslationJob $job ) {}

	/**
	 * Whether the current user may create an AI translation of the post.
	 *
	 * @param WP_Post $post Source post.
	 * @return bool
	 */
	public function can_translate( WP_Post $post ): bool {
		$post_type = get_post_type_object( $post->post_type );

		return $post_type
			&& $this->polylang->applies_to( $post )
			&& current_user_can( $post_type->cap->create_posts )
			&& current_user_can( 'read_post', $post->ID );
	}

	/**
	 * Returns a language the post can be translated into, or null.
	 *
	 * @param WP_Post $post Source post.
	 * @param string  $code Polylang language slug.
	 * @return array{code:string, name:string, locale:string}|null
	 */
	public function language( WP_Post $post, string $code ): ?array {
		return $this->languages( $post )[ $code ] ?? null;
	}

	/**
	 * Languages the post has no translation in yet.
	 *
	 * @param WP_Post $post Source post.
	 * @return array<string, array{code:string, name:string, locale:string}>
	 */
	public function languages( WP_Post $post ): array {
		return $this->polylang->applies_to( $post ) ? $this->polylang->missing_languages( $post ) : [];
	}

	/**
	 * Creates the linked translation as a draft and queues its AI translation.
	 *
	 * @param WP_Post                                        $post     Source post.
	 * @param array{code:string, name:string, locale:string} $language Target language.
	 * @return int|WP_Error New post ID.
	 */
	public function create( WP_Post $post, array $language ) {
		$post_id = $this->polylang->create_translation( $post, $language['code'] );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->job->queue( $post_id, $language, $post->ID, get_current_user_id() );

		return $post_id;
	}
}
