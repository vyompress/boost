<?php
/**
 * Filesystem-backed HTML cache storage.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost\Cache;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Stores cache entries under a dedicated, guarded wp-content directory.
 */
final class CacheStore {
	/**
	 * Absolute cache root.
	 *
	 * @var string
	 */
	private string $directory;

	/**
	 * Create a cache store.
	 *
	 * @param string|null $directory Optional path override for tests.
	 */
	public function __construct( ?string $directory = null ) {
		$this->directory = $directory ?? WP_CONTENT_DIR . '/cache/vyompress-boost';
	}

	/**
	 * Create and protect the cache directory.
	 */
	public function install(): bool {
		if ( ! wp_mkdir_p( $this->directory ) ) {
			return false;
		}

		$guards = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></system.webServer></configuration>\n",
		);

		foreach ( $guards as $filename => $contents ) {
			$path = $this->directory . '/' . $filename;

			if ( ! is_file( $path ) && false === file_put_contents( $path, $contents, LOCK_EX ) ) {
				return false;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Runtime cache writes require direct, non-interactive filesystem access.
		return is_writable( $this->directory );
	}

	/**
	 * Return a fresh cache entry or null.
	 *
	 * @param string $key Opaque cache key.
	 * @param int    $ttl Maximum entry age in seconds.
	 */
	public function read( string $key, int $ttl ): ?string {
		$path = $this->path( $key );

		if ( ! is_file( $path ) ) {
			return null;
		}

		$modified = filemtime( $path );
		if ( false === $modified || $modified + $ttl < time() ) {
			$this->deleteFile( $path );

			return null;
		}

		$contents = file_get_contents( $path );

		return false === $contents ? null : $contents;
	}

	/**
	 * Atomically persist an HTML response.
	 *
	 * @param string $key      Opaque cache key.
	 * @param string $contents Rendered public HTML.
	 */
	public function write( string $key, string $contents ): bool {
		if ( ! $this->install() ) {
			return false;
		}

		$path      = $this->path( $key );
		$directory = dirname( $path );

		if ( ! wp_mkdir_p( $directory ) ) {
			return false;
		}

		$temporary = $path . '.' . wp_generate_password( 12, false, false ) . '.tmp';
		$written   = file_put_contents( $temporary, $contents, LOCK_EX );

		if ( false === $written || strlen( $contents ) !== $written ) {
			$this->deleteFile( $temporary );

			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-filesystem rename provides the atomic cache write guarantee.
		if ( ! rename( $temporary, $path ) ) {
			$this->deleteFile( $temporary );

			return false;
		}

		return true;
	}

	/**
	 * Remove all cache entries while preserving directory guard files.
	 */
	public function clear(): int {
		if ( ! is_dir( $this->directory ) ) {
			return 0;
		}

		$removed  = 0;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			$path = $item->getPathname();

			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes only empty plugin-owned cache shards.
				rmdir( $path );
				continue;
			}

			if ( in_array( $item->getFilename(), array( 'index.php', '.htaccess', 'web.config' ), true ) ) {
				continue;
			}

			if ( $this->deleteFile( $path ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Count stored HTML entries.
	 */
	public function count(): int {
		if ( ! is_dir( $this->directory ) ) {
			return 0;
		}

		$count    = 0;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->directory, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( $item->isFile() && 'html' === $item->getExtension() ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Whether the cache location is operational.
	 */
	public function isWritable(): bool {
		return $this->install();
	}

	/**
	 * Resolve a validated cache key to its sharded path.
	 *
	 * @param string $key Opaque cache key.
	 * @throws \InvalidArgumentException When the cache key is malformed.
	 */
	private function path( string $key ): string {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $key ) ) {
			throw new \InvalidArgumentException( 'Invalid cache key.' );
		}

		return $this->directory . '/' . substr( $key, 0, 2 ) . '/' . $key . '.html';
	}

	/**
	 * Delete one cache-owned file.
	 *
	 * @param string $path Absolute cache-owned path.
	 */
	private function deleteFile( string $path ): bool {
		if ( ! is_file( $path ) ) {
			return true;
		}

		wp_delete_file( $path );

		return ! is_file( $path );
	}
}
