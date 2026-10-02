<?php
/**
 * Typed plugin settings.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost;

/**
 * Owns settings defaults, validation, and persistence.
 */
final class Settings {
	public const OPTION = 'vyompress_boost_settings';

	/**
	 * Return the default configuration.
	 *
	 * @return array{page_cache: bool, cache_ttl: int, browser_cache: bool, disable_emojis: bool, disable_embeds: bool, reduce_heartbeat: bool}
	 */
	public static function defaults(): array {
		return array(
			'page_cache'       => true,
			'cache_ttl'        => 3600,
			'browser_cache'    => false,
			'disable_emojis'   => true,
			'disable_embeds'   => false,
			'reduce_heartbeat' => false,
		);
	}

	/**
	 * Retrieve settings merged with defaults.
	 *
	 * @return array{page_cache: bool, cache_ttl: int, browser_cache: bool, disable_emojis: bool, disable_embeds: bool, reduce_heartbeat: bool}
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return self::sanitize( array_merge( self::defaults(), $stored ) );
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key Settings key.
	 * @return bool|int|null
	 */
	public function get( string $key ): bool|int|null {
		$settings = $this->all();

		return $settings[ $key ] ?? null;
	}

	/**
	 * Sanitize untrusted settings input.
	 *
	 * @param mixed $input Raw settings value.
	 * @return array{page_cache: bool, cache_ttl: int, browser_cache: bool, disable_emojis: bool, disable_embeds: bool, reduce_heartbeat: bool}
	 */
	public static function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : array();
		$ttl   = isset( $input['cache_ttl'] ) ? absint( $input['cache_ttl'] ) : self::defaults()['cache_ttl'];

		return array(
			'page_cache'       => ! empty( $input['page_cache'] ),
			'cache_ttl'        => max( 60, min( DAY_IN_SECONDS, $ttl ) ),
			'browser_cache'    => ! empty( $input['browser_cache'] ),
			'disable_emojis'   => ! empty( $input['disable_emojis'] ),
			'disable_embeds'   => ! empty( $input['disable_embeds'] ),
			'reduce_heartbeat' => ! empty( $input['reduce_heartbeat'] ),
		);
	}
}
