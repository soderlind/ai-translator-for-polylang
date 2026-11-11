<?php
/**
 * Polylang integration.
 *
 * @package AiTranslatorForPolylang
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang;

use WP_Error;
use WP_Post;

/**
 * Creates linked Polylang translations the same way Polylang's "Add a translation" does.
 */
class Polylang {

	/**
	 * Whether Polylang is active.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return function_exists( 'pll_languages_list' )
			&& function_exists( 'pll_set_post_language' )
			&& function_exists( 'pll_save_post_translations' );
	}

	/**
	 * Whether Polylang manages languages for the post's type.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function applies_to( WP_Post $post ): bool {
		return self::is_active() && 'attachment' !== $post->post_type && pll_is_translated_post_type( $post->post_type );
	}

	/**
	 * Returns the languages the post has no translation in yet.
	 *
	 * @param WP_Post $source Source post.
	 * @return array<string, array{code:string, name:string, locale:string}> Keyed by Polylang slug.
	 */
	public function missing_languages( WP_Post $source ): array {
		$source_language = (string) pll_get_post_language( $source->ID );
		$translations    = pll_get_post_translations( $source->ID );

		$languages = [];
		foreach ( pll_languages_list( [ 'fields' => '' ] ) as $language ) {
			if ( $language->slug === $source_language || isset( $translations[ $language->slug ] ) ) {
				continue;
			}
			$languages[ $language->slug ] = [
				'code'   => $language->slug,
				'name'   => $language->name,
				'locale' => $language->locale,
			];
		}

		return $languages;
	}

	/**
	 * Creates a draft translation of the post, linked to it, with the source's content.
	 *
	 * Mirrors Polylang's "Add a translation": copies menu order, comment and ping status,
	 * the translated parent, sticky state, and the taxonomies and custom fields Polylang copies
	 * to new translations (terms are swapped for their translations).
	 *
	 * @param WP_Post $source   Source post.
	 * @param string  $language Polylang language slug.
	 * @return int|WP_Error New post ID.
	 */
	public function create_translation( WP_Post $source, string $language ) {
		$parent = $source->post_parent ? (int) pll_get_post( $source->post_parent, $language ) : 0;

		$post_id = wp_insert_post(
			wp_slash(
				[
					'post_type'      => $source->post_type,
					'post_status'    => 'draft',
					'post_author'    => get_current_user_id(),
					'post_title'     => $source->post_title,
					'post_content'   => $source->post_content,
					'post_excerpt'   => $source->post_excerpt,
					'post_parent'    => $parent,
					'menu_order'     => $source->menu_order,
					'comment_status' => $source->comment_status,
					'ping_status'    => $source->ping_status,
				]
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		pll_set_post_language( $post_id, $language );

		$translations = pll_get_post_translations( $source->ID );
		$source_lang  = pll_get_post_language( $source->ID );
		if ( $source_lang ) {
			$translations[ $source_lang ] = $source->ID;
		}
		$translations[ $language ] = $post_id;
		pll_save_post_translations( $translations );

		$sync = function_exists( 'PLL' ) ? ( PLL()->sync ?? null ) : null;
		if ( $sync ) {
			$sync->taxonomies->copy( $source->ID, $post_id, $language );
			$sync->post_metas->copy( $source->ID, $post_id, $language );
		}

		if ( 'post' === $source->post_type && is_sticky( $source->ID ) ) {
			stick_post( $post_id );
		}

		return $post_id;
	}
}
