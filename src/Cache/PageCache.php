<?php
/**
 * Conservative anonymous page cache.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost\Cache;

use VyomPress\Boost\Settings;

/**
 * Serves and captures cacheable front-end HTML responses.
 */
final class PageCache {
	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Filesystem cache storage.
	 *
	 * @var CacheStore
	 */
	private CacheStore $store;

	/**
	 * Request eligibility policy.
	 *
	 * @var CachePolicy
	 */
	private CachePolicy $policy;

	/**
	 * Cache key selected for the active cache miss.
	 *
	 * @var string|null
	 */
	private ?string $active_key = null;

	/**
	 * Normalized URI selected by the eligibility policy.
	 *
	 * @var string|null
	 */
	private ?string $normalized_uri = null;

	/**
	 * Create the page-cache service.
	 *
	 * @param Settings         $settings Plugin settings.
	 * @param CacheStore       $store    Cache storage.
	 * @param CachePolicy|null $policy   Optional policy override.
	 */
	public function __construct( Settings $settings, CacheStore $store, ?CachePolicy $policy = null ) {
		$this->settings = $settings;
		$this->store    = $store;
		$this->policy   = $policy ?? new CachePolicy();
	}

	/**
	 * Register cache delivery, capture, and invalidation hooks.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybeServe' ), -9999 );
		add_action( 'template_redirect', array( $this, 'maybeCapture' ), 0 );
		add_filter( 'wp_headers', array( $this, 'filterHeaders' ) );

		add_action( 'save_post', array( $this, 'invalidatePost' ) );
		add_action( 'deleted_post', array( $this, 'purge' ) );
		add_action( 'trashed_post', array( $this, 'purge' ) );
		add_action( 'comment_post', array( $this, 'purge' ) );
		add_action( 'edit_comment', array( $this, 'purge' ) );
		add_action( 'transition_comment_status', array( $this, 'purge' ) );
		add_action( 'switch_theme', array( $this, 'purge' ) );
		add_action( 'upgrader_process_complete', array( $this, 'purge' ) );
		add_action( 'customize_save_after', array( $this, 'purge' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'purge' ) );
	}

	/**
	 * Serve a fresh anonymous cache entry and end request processing.
	 */
	public function maybeServe(): void {
		if ( ! $this->isCacheableRequest() ) {
			return;
		}

		$key   = $this->requestKey();
		$entry = $this->store->read( $key, (int) $this->settings->get( 'cache_ttl' ) );

		if ( null === $entry ) {
			$this->active_key = $key;

			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		header( 'X-VyomPress-Cache: HIT' );
		$this->sendCacheControlHeader();

		if ( 'HEAD' !== strtoupper( $this->requestMethod() ) ) {
			echo $entry; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Previously rendered public HTML.
		}

		exit;
	}

	/**
	 * Start output capture on eligible cache misses.
	 */
	public function maybeCapture(): void {
		if ( ! $this->isCacheableRequest() ) {
			return;
		}

		$this->active_key ??= $this->requestKey();
		ob_start( array( $this, 'capture' ) );
	}

	/**
	 * Persist a complete successful HTML document and return it unchanged.
	 *
	 * @param string $html Rendered response body.
	 */
	public function capture( string $html ): string {
		if (
			null !== $this->active_key
			&& 200 === http_response_code()
			&& str_contains( strtolower( $html ), '</html>' )
			&& ! $this->responseForbidsCaching()
		) {
			$this->store->write( $this->active_key, $html );
		}

		return $html;
	}

	/**
	 * Add observability and browser-cache policy to generated responses.
	 *
	 * @param array<string, string> $headers WordPress response headers.
	 * @return array<string, string>
	 */
	public function filterHeaders( array $headers ): array {
		if ( $this->isCacheableRequest() ) {
			$headers['X-VyomPress-Cache'] = 'MISS';
		}

		return $headers;
	}

	/**
	 * Clear cached pages after a meaningful content update.
	 *
	 * @param int $post_id Post identifier.
	 */
	public function invalidatePost( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$this->purge();
	}

	/**
	 * Clear every cache entry.
	 */
	public function purge(): void {
		$removed = $this->store->clear();

		/**
		 * Fires after the local page cache is purged.
		 *
		 * @param int $removed Number of local cache entries removed.
		 */
		do_action( 'vyompress_boost_cache_purged', $removed );
	}

	/**
	 * Determine whether the current request is safe to cache.
	 */
	private function isCacheableRequest(): bool {
		if ( ! $this->settings->get( 'page_cache' ) ) {
			return false;
		}

		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}

		if ( ! $this->policy->allowsMethod( $this->requestMethod() ) ) {
			return false;
		}

		$request_uri = $this->requestUri();
		$ignored     = $this->lines( (string) $this->settings->get( 'ignored_query_parameters' ) );
		$excluded    = $this->lines( (string) $this->settings->get( 'excluded_paths' ) );

		$this->normalized_uri = $this->policy->normalizedUri( $request_uri, (bool) $this->settings->get( 'cache_query_strings' ), $ignored );
		if ( null === $this->normalized_uri || $this->policy->isExcludedPath( $request_uri, $excluded ) || $this->policy->hasBypassCookie( $_COOKIE ) ) {
			return false;
		}

		if (
			is_admin()
			|| wp_doing_ajax()
			|| wp_doing_cron()
			|| is_user_logged_in()
			|| is_preview()
			|| is_search()
			|| is_feed()
			|| is_robots()
			|| is_404()
			|| str_starts_with( $request_uri, '/wp-json/' )
			|| str_contains( strtolower( $this->requestCacheControl() ), 'no-cache' )
		) {
			return false;
		}

		/**
		 * Filter whether VyomPress Boost may cache the current request.
		 *
		 * @param bool $cacheable Whether the request is cacheable.
		 */
		return (bool) apply_filters( 'vyompress_boost_is_cacheable_request', true );
	}

	/**
	 * Build the active request's opaque cache key.
	 */
	private function requestKey(): string {
		$scheme  = is_ssl() ? 'https' : 'http';
		$host    = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$variant = $this->settings->get( 'separate_mobile_cache' ) && wp_is_mobile() ? 'mobile' : 'shared';

		return CacheKey::make( $scheme, $host, $this->normalized_uri ?? $this->requestUri(), $variant );
	}

	/**
	 * Return the normalized request method.
	 */
	private function requestMethod(): string {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
	}

	/**
	 * Return the normalized request URI.
	 */
	private function requestUri(): string {
		return isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
	}

	/**
	 * Return the incoming Cache-Control header.
	 */
	private function requestCacheControl(): string {
		return isset( $_SERVER['HTTP_CACHE_CONTROL'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CACHE_CONTROL'] ) ) : '';
	}

	/**
	 * Check response headers for explicit private/no-store directives.
	 */
	private function responseForbidsCaching(): bool {
		foreach ( headers_list() as $header ) {
			$header = strtolower( $header );
			if ( str_starts_with( $header, 'set-cookie:' ) ) {
				return true;
			}

			if ( str_starts_with( $header, 'cache-control:' ) && ( str_contains( $header, 'private' ) || str_contains( $header, 'no-store' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Send browser-cache policy for a validated cache hit.
	 */
	private function sendCacheControlHeader(): void {
		if ( $this->settings->get( 'browser_cache' ) ) {
			$ttl = (int) $this->settings->get( 'browser_ttl' );
			header( 'Cache-Control: public, max-age=' . $ttl . ', stale-while-revalidate=30' );
		}
	}

	/**
	 * Convert a newline-delimited setting to a compact list.
	 *
	 * @param string $value Newline-delimited setting.
	 * @return list<string>
	 */
	private function lines( string $value ): array {
		$lines = preg_split( '/\R/', $value );
		$lines = false === $lines ? array() : $lines;

		return array_values( array_filter( array_map( 'trim', $lines ) ) );
	}
}
