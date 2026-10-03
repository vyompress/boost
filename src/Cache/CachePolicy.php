<?php
/**
 * Pure cache eligibility rules.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost\Cache;

/**
 * Applies conservative cache-bypass rules to request metadata.
 */
final class CachePolicy {
	/**
	 * Cookie prefixes that identify private or stateful sessions.
	 *
	 * @var list<string>
	 */
	private const BYPASS_COOKIE_PREFIXES = array(
		'wordpress_logged_in_',
		'wp-postpass_',
		'comment_author_',
		'woocommerce_items_in_cart',
		'woocommerce_cart_hash',
		'wp_woocommerce_session_',
		'edd_items_in_cart',
		'PHPSESSID',
	);

	/**
	 * Whether an HTTP method can safely read or populate the cache.
	 *
	 * @param string $method HTTP request method.
	 */
	public function allowsMethod( string $method ): bool {
		return in_array( strtoupper( $method ), array( 'GET', 'HEAD' ), true );
	}

	/**
	 * Check for query parameters. Dynamic query URLs are never cached.
	 *
	 * @param string $request_uri Request URI.
	 */
	public function hasQueryString( string $request_uri ): bool {
		return '' !== (string) wp_parse_url( $request_uri, PHP_URL_QUERY );
	}

	/**
	 * Normalize an eligible request URI or return null when it must bypass cache.
	 *
	 * Tracking parameters are discarded before deciding whether meaningful query
	 * parameters remain. Remaining parameters are sorted for stable cache keys.
	 *
	 * @param string            $request_uri       Request path and query string.
	 * @param bool              $cache_query       Whether non-tracking query strings may be cached.
	 * @param array<int,string> $ignored_parameters Query parameter names to ignore.
	 */
	public function normalizedUri( string $request_uri, bool $cache_query, array $ignored_parameters ): ?string {
		$path  = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$query = (string) wp_parse_url( $request_uri, PHP_URL_QUERY );

		if ( '' === $query ) {
			return '' === $path ? '/' : $path;
		}

		parse_str( $query, $parameters );
		foreach ( $ignored_parameters as $ignored ) {
			unset( $parameters[ $ignored ] );
		}

		if ( array() === $parameters ) {
			return '' === $path ? '/' : $path;
		}

		if ( ! $cache_query ) {
			return null;
		}

		ksort( $parameters );

		return ( '' === $path ? '/' : $path ) . '?' . http_build_query( $parameters, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Whether the request path begins with a configured exclusion.
	 *
	 * @param string            $request_uri Request URI.
	 * @param array<int,string> $excluded_paths Root-relative excluded paths.
	 */
	public function isExcludedPath( string $request_uri, array $excluded_paths ): bool {
		$path = '/' . ltrim( (string) wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );

		foreach ( $excluded_paths as $excluded ) {
			$excluded = '/' . ltrim( trim( $excluded ), '/' );
			if ( '/' !== $excluded && str_starts_with( $path, $excluded ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check for login, session, cart, or commenter cookies.
	 *
	 * @param array<string, mixed> $cookies Request cookies.
	 */
	public function hasBypassCookie( array $cookies ): bool {
		foreach ( array_keys( $cookies ) as $name ) {
			foreach ( self::BYPASS_COOKIE_PREFIXES as $prefix ) {
				if ( str_starts_with( (string) $name, $prefix ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
