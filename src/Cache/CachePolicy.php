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
