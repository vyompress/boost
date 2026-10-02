<?php
/**
 * Stable page-cache key generation.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost\Cache;

/**
 * Generates opaque cache keys without exposing request paths on disk.
 */
final class CacheKey {
	/**
	 * Build a cache key for one public representation.
	 *
	 * @param string $scheme      Request scheme.
	 * @param string $host        Request host.
	 * @param string $request_uri Request path and query.
	 * @param string $variant     Representation variant.
	 */
	public static function make( string $scheme, string $host, string $request_uri, string $variant ): string {
		$identity = strtolower( $scheme ) . '://' . strtolower( $host ) . $request_uri . '|' . strtolower( $variant );

		return hash( 'sha256', $identity );
	}
}
