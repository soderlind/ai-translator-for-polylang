<?php
/**
 * Removes the plugin's post meta on uninstall.
 *
 * @package TranslateWithAiForPolylang
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/Translation/JobState.php';
require_once __DIR__ . '/src/Translation/TranslationJob.php';

$twaifp_cleanup = static function (): void {
	global $wpdb;

	foreach ( Soderlind\TranslateWithAiForPolylang\Translation\TranslationJob::META_KEYS as $key ) {
		delete_metadata( 'post', 0, $key, '', true );
	}
	wp_unschedule_hook( Soderlind\TranslateWithAiForPolylang\Translation\TranslationJob::HOOK );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Worker locks are written with direct queries too.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( Soderlind\TranslateWithAiForPolylang\Translation\TranslationJob::LOCK_PREFIX ) . '%' ) );
};

if ( is_multisite() ) {
	foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $twaifp_site_id ) {
		switch_to_blog( (int) $twaifp_site_id );
		$twaifp_cleanup();
		restore_current_blog();
	}
} else {
	$twaifp_cleanup();
}
