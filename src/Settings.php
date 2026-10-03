<?php
/**
 * Typed plugin settings.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost;

/**
 * Owns settings defaults, validation, credential overrides, and persistence.
 */
final class Settings {
	public const OPTION = 'vyompress_boost_settings';

	/**
	 * Return the default configuration.
	 *
	 * @return array<string, bool|int|string>
	 */
	public static function defaults(): array {
		return array(
			'page_cache'                  => true,
			'cache_ttl'                   => 3600,
			'browser_cache'               => false,
			'browser_ttl'                 => 3600,
			'separate_mobile_cache'       => false,
			'cache_query_strings'         => false,
			'preload_enabled'             => false,
			'preload_on_purge'            => true,
			'preload_concurrency'         => 1,
			'preload_url_limit'           => 200,
			'ignored_query_parameters'    => "utm_source\nutm_medium\nutm_campaign\nutm_term\nutm_content\ngclid\nfbclid",
			'excluded_paths'              => "/cart/\n/checkout/\n/my-account/",
			'disable_emojis'              => true,
			'disable_embeds'              => false,
			'reduce_heartbeat'            => false,
			'defer_javascript'            => false,
			'defer_javascript_exclusions' => "jquery\njquery-core\nwp-hooks\nwp-i18n\nwp-element",
			'delay_javascript'            => false,
			'delay_javascript_includes'   => "googletagmanager.com\ngoogle-analytics.com\nconnect.facebook.net",
			'delay_javascript_timeout'    => 5000,
			'lazy_load_images'            => true,
			'lazy_load_iframes'           => true,
			'lcp_image_priority'          => true,
			'disable_guest_dashicons'     => false,
			'remove_jquery_migrate'       => false,
			'speculation_mode'            => 'auto',
			'speculation_eagerness'       => 'auto',
			'preconnect_origins'          => '',
			'database_cleanup_enabled'    => false,
			'database_cleanup_revisions'  => true,
			'database_cleanup_trash'      => true,
			'database_cleanup_spam'       => true,
			'database_retention_days'     => 30,
			'cloudflare_enabled'          => false,
			'cloudflare_zone_id'          => '',
			'cloudflare_api_token'        => '',
			'cloudflare_auto_purge'       => true,
			'cloudflare_edge_cache'       => false,
			'cloudflare_edge_ttl'         => 7200,
			's3_enabled'                  => false,
			's3_endpoint'                 => '',
			's3_region'                   => 'us-east-1',
			's3_bucket'                   => '',
			's3_access_key'               => '',
			's3_secret_key'               => '',
			's3_public_url'               => '',
			's3_path_style'               => true,
			's3_prefix'                   => 'wp-content/uploads',
			's3_keep_local'               => true,
			's3_cache_control'            => 'public, max-age=31536000, immutable',
		);
	}

	/**
	 * Retrieve settings merged with defaults.
	 *
	 * @return array<string, bool|int|string>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key Settings key.
	 * @return bool|int|string|null
	 */
	public function get( string $key ): bool|int|string|null {
		$settings = $this->all();

		return $settings[ $key ] ?? null;
	}

	/**
	 * Return a credential, preferring a wp-config.php constant over the database.
	 *
	 * @param string $setting_key Settings key.
	 * @param string $constant    Optional constant name.
	 */
	public function credential( string $setting_key, string $constant ): string {
		if ( defined( $constant ) ) {
			$value = constant( $constant );

			return is_string( $value ) ? trim( $value ) : '';
		}

		return (string) $this->get( $setting_key );
	}

	/**
	 * Sanitize untrusted settings input while retaining values from other tabs.
	 *
	 * Password fields are intentionally blank after saving. A blank value keeps the
	 * existing credential unless its adjacent clear checkbox is submitted.
	 *
	 * @param mixed $input Raw settings value.
	 * @return array<string, bool|int|string>
	 */
	public static function sanitize( mixed $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = get_option( self::OPTION, self::defaults() );
		$current = is_array( $current ) ? array_merge( self::defaults(), $current ) : self::defaults();

		$boolean_keys = array(
			'page_cache',
			'browser_cache',
			'separate_mobile_cache',
			'cache_query_strings',
			'preload_enabled',
			'preload_on_purge',
			'disable_emojis',
			'disable_embeds',
			'reduce_heartbeat',
			'defer_javascript',
			'delay_javascript',
			'lazy_load_images',
			'lazy_load_iframes',
			'lcp_image_priority',
			'disable_guest_dashicons',
			'remove_jquery_migrate',
			'database_cleanup_enabled',
			'database_cleanup_revisions',
			'database_cleanup_trash',
			'database_cleanup_spam',
			'cloudflare_enabled',
			'cloudflare_auto_purge',
			'cloudflare_edge_cache',
			's3_enabled',
			's3_path_style',
			's3_keep_local',
		);

		foreach ( $boolean_keys as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$current[ $key ] = ! empty( $input[ $key ] );
			}
		}

		if ( isset( $input['cache_ttl'] ) ) {
			$current['cache_ttl'] = self::boundedInteger( $input['cache_ttl'], 60, WEEK_IN_SECONDS, 3600 );
		}

		if ( isset( $input['browser_ttl'] ) ) {
			$current['browser_ttl'] = self::boundedInteger( $input['browser_ttl'], 60, YEAR_IN_SECONDS, 3600 );
		}

		if ( isset( $input['preload_concurrency'] ) ) {
			$current['preload_concurrency'] = self::boundedInteger( $input['preload_concurrency'], 1, 4, 1 );
		}

		if ( isset( $input['preload_url_limit'] ) ) {
			$current['preload_url_limit'] = self::boundedInteger( $input['preload_url_limit'], 10, 500, 200 );
		}

		if ( isset( $input['cloudflare_edge_ttl'] ) ) {
			$current['cloudflare_edge_ttl'] = self::boundedInteger( $input['cloudflare_edge_ttl'], 7200, MONTH_IN_SECONDS, 7200 );
		}

		if ( isset( $input['delay_javascript_timeout'] ) ) {
			$current['delay_javascript_timeout'] = self::boundedInteger( $input['delay_javascript_timeout'], 1000, 15000, 5000 );
		}

		if ( isset( $input['database_retention_days'] ) ) {
			$current['database_retention_days'] = self::boundedInteger( $input['database_retention_days'], 7, 365, 30 );
		}

		if ( array_key_exists( 'ignored_query_parameters', $input ) ) {
			$current['ignored_query_parameters'] = self::sanitizeLines( $input['ignored_query_parameters'], '/^[A-Za-z0-9_.~-]+$/' );
		}

		if ( array_key_exists( 'excluded_paths', $input ) ) {
			$current['excluded_paths'] = self::sanitizePaths( $input['excluded_paths'] );
		}

		foreach ( array( 'defer_javascript_exclusions', 'delay_javascript_includes' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$current[ $key ] = self::sanitizeLines( $input[ $key ], '/^[A-Za-z0-9._\/?=&:%~-]+$/' );
			}
		}

		if ( array_key_exists( 'preconnect_origins', $input ) ) {
			$current['preconnect_origins'] = self::sanitizeOrigins( $input['preconnect_origins'] );
		}

		if ( isset( $input['speculation_mode'] ) ) {
			$mode                        = sanitize_key( wp_unslash( (string) $input['speculation_mode'] ) );
			$current['speculation_mode'] = in_array( $mode, array( 'off', 'auto', 'prefetch', 'prerender' ), true ) ? $mode : 'auto';
		}

		if ( isset( $input['speculation_eagerness'] ) ) {
			$eagerness                        = sanitize_key( wp_unslash( (string) $input['speculation_eagerness'] ) );
			$current['speculation_eagerness'] = in_array( $eagerness, array( 'auto', 'conservative', 'moderate', 'eager' ), true ) ? $eagerness : 'auto';
		}

		$text_fields = array(
			'cloudflare_zone_id',
			's3_region',
			's3_bucket',
			's3_access_key',
			's3_prefix',
			's3_cache_control',
		);

		foreach ( $text_fields as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$current[ $key ] = sanitize_text_field( wp_unslash( (string) $input[ $key ] ) );
			}
		}

		foreach ( array( 's3_endpoint', 's3_public_url' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$current[ $key ] = untrailingslashit( esc_url_raw( wp_unslash( (string) $input[ $key ] ), array( 'http', 'https' ) ) );
			}
		}

		$current['cloudflare_zone_id'] = preg_replace( '/[^a-f0-9]/i', '', (string) $current['cloudflare_zone_id'] ) ?? '';
		$current['s3_bucket']          = strtolower( preg_replace( '/[^a-zA-Z0-9._-]/', '', (string) $current['s3_bucket'] ) ?? '' );
		$current['s3_prefix']          = trim( preg_replace( '#[^A-Za-z0-9!_.*()/~-]#', '', (string) $current['s3_prefix'] ) ?? '', '/' );

		self::sanitizeSecret( $current, $input, 'cloudflare_api_token', 'clear_cloudflare_api_token' );
		self::sanitizeSecret( $current, $input, 's3_secret_key', 'clear_s3_secret_key' );

		return $current;
	}

	/**
	 * Clamp a user supplied integer.
	 *
	 * @param mixed $value    Submitted value.
	 * @param int   $minimum  Minimum accepted value.
	 * @param int   $maximum  Maximum accepted value.
	 * @param int   $fallback Fallback for empty values.
	 */
	private static function boundedInteger( mixed $value, int $minimum, int $maximum, int $fallback ): int {
		$value = absint( $value );

		return 0 === $value ? $fallback : max( $minimum, min( $maximum, $value ) );
	}

	/**
	 * Normalize a newline-delimited allowlist.
	 *
	 * @param mixed  $value   Submitted value.
	 * @param string $pattern Validation pattern.
	 */
	private static function sanitizeLines( mixed $value, string $pattern ): string {
		$lines = preg_split( '/\R/', wp_unslash( (string) $value ) );
		$lines = false === $lines ? array() : $lines;
		$lines = array_filter(
			array_map( 'trim', $lines ),
			static fn ( string $line ): bool => '' !== $line && 1 === preg_match( $pattern, $line )
		);

		return implode( "\n", array_values( array_unique( $lines ) ) );
	}

	/**
	 * Normalize safe, root-relative excluded paths.
	 *
	 * @param mixed $value Submitted value.
	 */
	private static function sanitizePaths( mixed $value ): string {
		$lines = preg_split( '/\R/', wp_unslash( (string) $value ) );
		$lines = false === $lines ? array() : $lines;
		$paths = array();

		foreach ( $lines as $line ) {
			$path = (string) wp_parse_url( trim( $line ), PHP_URL_PATH );
			if ( '' === $path ) {
				continue;
			}

			$paths[] = '/' . ltrim( sanitize_text_field( $path ), '/' );
		}

		return implode( "\n", array_values( array_unique( $paths ) ) );
	}

	/**
	 * Normalize newline-delimited HTTPS connection origins.
	 *
	 * @param mixed $value Submitted value.
	 */
	private static function sanitizeOrigins( mixed $value ): string {
		$lines   = preg_split( '/\R/', wp_unslash( (string) $value ) );
		$lines   = false === $lines ? array() : $lines;
		$origins = array();

		foreach ( $lines as $line ) {
			$url = esc_url_raw( trim( $line ), array( 'https' ) );
			if ( '' === $url ) {
				continue;
			}

			$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );
			$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
			$port   = wp_parse_url( $url, PHP_URL_PORT );
			if ( 'https' === $scheme && '' !== $host ) {
				$origins[] = 'https://' . strtolower( $host ) . ( is_int( $port ) ? ':' . $port : '' );
			}
		}

		return implode( "\n", array_values( array_unique( $origins ) ) );
	}

	/**
	 * Preserve, replace, or explicitly clear a stored secret.
	 *
	 * @param array<string, bool|int|string> $current Existing settings.
	 * @param array<string, mixed>           $input   Submitted settings.
	 * @param string                         $key     Credential settings key.
	 * @param string                         $clear_key Submitted clear-checkbox key.
	 */
	private static function sanitizeSecret( array &$current, array $input, string $key, string $clear_key ): void {
		if ( ! empty( $input[ $clear_key ] ) ) {
			$current[ $key ] = '';

			return;
		}

		if ( isset( $input[ $key ] ) && '' !== trim( (string) $input[ $key ] ) ) {
			$current[ $key ] = sanitize_text_field( wp_unslash( (string) $input[ $key ] ) );
		}
	}
}
