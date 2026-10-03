<?php
/**
 * Cloudflare cache integration.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost\Cloudflare;

use VyomPress\Boost\Settings;
use VyomPress\Boost\Operations\ActivityLog;
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
	 * @param ActivityLog      $log      Local activity log.
	 */
	public function __construct( private Settings $settings, private CloudflareClient $client, private ActivityLog $log ) {
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
	public function purgeNow(): bool|WP_Error {
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
		$this->log->add( 'cloudflare', $status['message'], true === $result ? 'success' : 'error' );

		return $result;
	}

	/**
	 * Install or refresh the plugin-owned Cloudflare edge cache rule.
	 *
	 * @return true|WP_Error
	 */
	public function syncEdgeRule(): bool|WP_Error {
		$excluded = preg_split( '/\R/', (string) $this->settings->get( 'excluded_paths' ) );
		$excluded = false === $excluded ? array() : array_values( array_filter( array_map( 'trim', $excluded ) ) );
		$result   = $this->client->syncEdgeRule(
			(string) $this->settings->get( 'cloudflare_zone_id' ),
			$this->settings->credential( 'cloudflare_api_token', 'VYOMPRESS_BOOST_CLOUDFLARE_API_TOKEN' ),
			(string) wp_parse_url( home_url(), PHP_URL_HOST ),
			(int) $this->settings->get( 'cloudflare_edge_ttl' ),
			$excluded,
			(bool) $this->settings->get( 'cache_query_strings' ),
			(bool) $this->settings->get( 'separate_mobile_cache' )
		);

		$message = true === $result ? __( 'Cloudflare edge cache rule synchronized.', 'vyompress-boost' ) : $result->get_error_message();
		$this->log->add( 'cloudflare_rule', $message, true === $result ? 'success' : 'error' );

		return $result;
	}

	/**
	 * Remove the plugin-owned Cloudflare edge cache rule.
	 *
	 * @return true|WP_Error
	 */
	public function removeEdgeRule(): bool|WP_Error {
		$result = $this->client->removeEdgeRule(
			(string) $this->settings->get( 'cloudflare_zone_id' ),
			$this->settings->credential( 'cloudflare_api_token', 'VYOMPRESS_BOOST_CLOUDFLARE_API_TOKEN' )
		);

		$message = true === $result ? __( 'VyomPress Boost edge cache rule removed.', 'vyompress-boost' ) : $result->get_error_message();
		$this->log->add( 'cloudflare_rule', $message, true === $result ? 'success' : 'error' );

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
