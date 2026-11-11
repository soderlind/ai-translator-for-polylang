<?php
/**
 * Removes the plugin's post meta on uninstall.
 *
 * @package AiTranslatorForPolylang
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$aitfp_cleanup = static function (): void {
	global $wpdb;

	foreach ( [ '_aitfp_status', '_aitfp_language', '_aitfp_error', '_aitfp_user', '_aitfp_source', '_aitfp_updated' ] as $key ) {
		delete_metadata( 'post', 0, $key, '', true );
	}
	wp_unschedule_hook( 'aitfp_translate_post' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Worker locks are written with direct queries too.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_aitfp_lock_' ) . '%' ) );
};

if ( is_multisite() ) {
	foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $aitfp_site_id ) {
		switch_to_blog( (int) $aitfp_site_id );
		$aitfp_cleanup();
		restore_current_blog();
	}
} else {
	$aitfp_cleanup();
}
