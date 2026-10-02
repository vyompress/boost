<?php
/**
 * Minimal PSR-4-style autoloader for the plugin namespace.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost;

/**
 * Loads first-party classes without shipping a production Composer dependency.
 */
final class Autoloader {
	private const PREFIX = 'VyomPress\\Boost\\';

	/**
	 * Register the class loader.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Load a class belonging to this plugin.
	 *
	 * @param string $class_name Fully-qualified class name.
	 */
	private static function load( string $class_name ): void {
		if ( ! str_starts_with( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( self::PREFIX ) );
		$file           = VYOMPRESS_BOOST_PATH . 'src/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
}
