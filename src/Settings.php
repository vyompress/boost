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
			'page_cache'               => true,
			'cache_ttl'                => 3600,
			'browser_cache'            => false,
			'browser_ttl'              => 3600,
			'separate_mobile_cache'    => false,
			'cache_query_strings'      => false,
			'ignored_query_parameters' => "utm_source\nutm_medium\nutm_campaign\nutm_term\nutm_content\ngclid\nfbclid",
			'excluded_paths'           => "/cart/\n/checkout/\n/my-account/",
			'disable_emojis'           => true,
			'disable_embeds'           => false,
			'reduce_heartbeat'         => false,
			'cloudflare_enabled'       => false,
			'cloudflare_zone_id'       => '',
			'cloudflare_api_token'     => '',
			'cloudflare_auto_purge'    => true,
			's3_enabled'               => false,
			's3_endpoint'              => '',
			's3_region'                => 'us-east-1',
			's3_bucket'                => '',
			's3_access_key'            => '',
			's3_secret_key'            => '',
			's3_public_url'            => '',
			's3_path_style'            => true,
			's3_prefix'                => 'wp-content/uploads',
			's3_keep_local'            => true,
			's3_cache_control'         => 'public, max-age=31536000, immutable',
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
			'disable_emojis',
			'disable_embeds',
			'reduce_heartbeat',
			'cloudflare_enabled',
			'cloudflare_auto_purge',
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

		if ( array_key_exists( 'ignored_query_parameters', $input ) ) {
			$current['ignored_query_parameters'] = self::sanitizeLines( $input['ignored_query_parameters'], '/^[A-Za-z0-9_.~-]+$/' );
		}

		if ( array_key_exists( 'excluded_paths', $input ) ) {
			$current['excluded_paths'] = self::sanitizePaths( $input['excluded_paths'] );
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
