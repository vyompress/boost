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
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		vyompress_boost_uninstall_site();
		restore_current_blog();
	}
} else {
	vyompress_boost_uninstall_site();
}

$cache_directory = WP_CONTENT_DIR . '/cache/vyompress-boost';

if ( is_dir( $cache_directory ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $cache_directory, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $item ) {
		if ( $item->isDir() ) {
			rmdir( $item->getPathname() );
		} else {
			unlink( $item->getPathname() );
		}
	}

	rmdir( $cache_directory );
}
