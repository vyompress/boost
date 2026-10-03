<?php
/**
 * Cloudflare cache integration.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost\Cloudflare;

use VyomPress\Boost\Settings;
use WP_Error;

/**
 * Debounces local invalidations into asynchronous Cloudflare purges.
 */
final class CloudflareIntegration {
	public const CRON_HOOK     = 'vyompress_boost_cloudflare_purge';
	public const STATUS_OPTION = 'vyompress_boost_cloudflare_status';

	/**
	 * Create the Cloudflare integration.
	 *
	 * @param Settings         $settings Plugin settings.
	 * @param CloudflareClient $client   Cloudflare API client.
	 */
	public function __construct( private Settings $settings, private CloudflareClient $client ) {
	}

	/**
	 * Register automatic purge hooks.
	 */
	public function register(): void {
		add_action( 'vyompress_boost_cache_purged', array( $this, 'queuePurge' ) );
		add_action( self::CRON_HOOK, array( $this, 'runScheduledPurge' ) );
	}

	/**
	 * Whether all required settings are available.
	 */
	public function isConfigured(): bool {
		return '' !== (string) $this->settings->get( 'cloudflare_zone_id' )
			&& '' !== $this->settings->credential( 'cloudflare_api_token', 'VYOMPRESS_BOOST_CLOUDFLARE_API_TOKEN' );
	}

	/**
	 * Schedule one purge after clustered WordPress update events settle.
	 */
	public function queuePurge(): void {
		if ( ! $this->settings->get( 'cloudflare_enabled' ) || ! $this->settings->get( 'cloudflare_auto_purge' ) || ! $this->isConfigured() ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::CRON_HOOK );
		}
	}

	/**
	 * Execute a purge immediately and retain only non-sensitive diagnostics.
	 *
	 * @return true|WP_Error
	 */
	public function purgeNow(): true|WP_Error {
		$result = $this->client->purgeEverything(
			(string) $this->settings->get( 'cloudflare_zone_id' ),
			$this->settings->credential( 'cloudflare_api_token', 'VYOMPRESS_BOOST_CLOUDFLARE_API_TOKEN' )
		);

		$status = array(
			'success' => true === $result,
			'time'    => time(),
			'message' => true === $result ? __( 'Cloudflare cache purged successfully.', 'vyompress-boost' ) : $result->get_error_message(),
		);
		update_option( self::STATUS_OPTION, $status, false );

		return $result;
	}

	/**
	 * Run the debounced purge event.
	 */
	public function runScheduledPurge(): void {
		if ( $this->settings->get( 'cloudflare_enabled' ) && $this->isConfigured() ) {
			$this->purgeNow();
		}
	}
}
