<?php
/**
 * Remove plugin-owned options and cache entries.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove settings for the currently selected site.
 */
function vyompress_boost_uninstall_site(): void {
	delete_option( 'vyompress_boost_settings' );
	delete_option( 'vyompress_boost_cloudflare_status' );
	delete_option( 'vyompress_boost_activity_log' );
	delete_option( 'vyompress_boost_preload_status' );
	delete_option( 'vyompress_boost_media_migration_status' );
	delete_post_meta_by_key( '_vyompress_boost_offload' );
	delete_post_meta_by_key( '_vyompress_boost_offload_error' );
	wp_clear_scheduled_hook( 'vyompress_boost_cloudflare_purge' );
	wp_clear_scheduled_hook( 'vyompress_boost_preload_batch' );
	wp_clear_scheduled_hook( 'vyompress_boost_media_migration_batch' );
	wp_clear_scheduled_hook( 'vyompress_boost_database_cleanup' );
}

if ( is_multisite() ) {
	$vyompress_boost_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $vyompress_boost_site_ids as $vyompress_boost_site_id ) {
		switch_to_blog( (int) $vyompress_boost_site_id );
		vyompress_boost_uninstall_site();
		restore_current_blog();
	}
} else {
	vyompress_boost_uninstall_site();
}

$vyompress_boost_cache_directory = WP_CONTENT_DIR . '/cache/vyompress-boost';

if ( is_dir( $vyompress_boost_cache_directory ) ) {
	$vyompress_boost_iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $vyompress_boost_cache_directory, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $vyompress_boost_iterator as $vyompress_boost_item ) {
		if ( $vyompress_boost_item->isDir() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes only empty plugin-owned cache shards.
			rmdir( $vyompress_boost_item->getPathname() );
		} else {
			wp_delete_file( $vyompress_boost_item->getPathname() );
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes the now-empty plugin-owned cache root.
	rmdir( $vyompress_boost_cache_directory );
}
