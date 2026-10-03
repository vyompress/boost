<?php
/**
 * Throttled cache preloading from WordPress sitemaps.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost\Cache;

use VyomPress\Boost\Operations\ActivityLog;
use VyomPress\Boost\Settings;

/**
 * Warms public page caches in small background batches.
 */
final class CachePreloader {
	public const CRON_HOOK     = 'vyompress_boost_preload_batch';
	public const STATUS_OPTION = 'vyompress_boost_preload_status';

	private const LOCK_KEY = 'vyompress_boost_preload_lock';

	/**
	 * Create the preloader.
	 *
	 * @param Settings    $settings Plugin settings.
	 * @param ActivityLog $log      Local activity log.
	 */
	public function __construct( private Settings $settings, private ActivityLog $log ) {
	}

	/**
	 * Register preload hooks.
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'runBatch' ) );
		add_action( 'vyompress_boost_cache_purged', array( $this, 'maybeStartAfterPurge' ) );
	}

	/**
	 * Start a new preload queue.
	 */
	public function start(): bool {
		if ( ! $this->settings->get( 'preload_enabled' ) ) {
			return false;
		}

		update_option(
			self::STATUS_OPTION,
			array(
				'state'     => 'discovering',
				'queue'     => array(),
				'total'     => 0,
				'processed' => 0,
				'failed'    => 0,
				'started'   => time(),
				'updated'   => time(),
			),
			false
		);
		$this->schedule();
		$this->log->add( 'preload', __( 'Cache preload queued.', 'vyompress-boost' ) );

		return true;
	}

	/**
	 * Stop queued work without deleting already-warmed pages.
	 */
	public function cancel(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		$status            = $this->status();
		$status['state']   = 'cancelled';
		$status['queue']   = array();
		$status['updated'] = time();
		update_option( self::STATUS_OPTION, $status, false );
		$this->log->add( 'preload', __( 'Cache preload cancelled.', 'vyompress-boost' ), 'warning' );
	}

	/**
	 * Queue a warmup after a local purge when enabled.
	 */
	public function maybeStartAfterPurge(): void {
		if ( $this->settings->get( 'preload_enabled' ) && $this->settings->get( 'preload_on_purge' ) ) {
			$this->start();
		}
	}

	/**
	 * Discover or warm one bounded batch.
	 */
	public function runBatch(): void {
		if ( get_transient( self::LOCK_KEY ) ) {
			$this->schedule();

			return;
		}

		set_transient( self::LOCK_KEY, 1, 2 * MINUTE_IN_SECONDS );
		$status = $this->status();

		if ( 'discovering' === $status['state'] ) {
			$status['queue']   = $this->discoverUrls();
			$status['total']   = count( $status['queue'] );
			$status['state']   = empty( $status['queue'] ) ? 'failed' : 'running';
			$status['updated'] = time();
			update_option( self::STATUS_OPTION, $status, false );
		}

		if ( 'running' === $status['state'] ) {
			$batch_size = max( 1, min( 4, (int) $this->settings->get( 'preload_concurrency' ) ) );
			$batch      = array_splice( $status['queue'], 0, $batch_size );

			foreach ( $batch as $url ) {
				$response = wp_safe_remote_get(
					(string) $url,
					array(
						'timeout'     => 15,
						'redirection' => 2,
						'headers'     => array( 'User-Agent' => 'VyomPress-Boost-Preloader/' . VYOMPRESS_BOOST_VERSION ),
					)
				);

				if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
					++$status['failed'];
				}
				++$status['processed'];
			}

			$status['updated'] = time();
			if ( empty( $status['queue'] ) ) {
				$status['state']    = 'complete';
				$status['finished'] = time();
				$this->log->add(
					'preload',
					sprintf(
						/* translators: 1: warmed URL count, 2: failed URL count. */
						__( 'Cache preload completed: %1$d warmed, %2$d failed.', 'vyompress-boost' ),
						$status['processed'] - $status['failed'],
						$status['failed']
					),
					0 === $status['failed'] ? 'success' : 'warning'
				);
			} else {
				$this->schedule();
			}

			update_option( self::STATUS_OPTION, $status, false );
		}

		delete_transient( self::LOCK_KEY );
	}

	/**
	 * Return normalized queue status.
	 *
	 * @return array{state:string,queue:list<string>,total:int,processed:int,failed:int,started:int,updated:int,finished?:int}
	 */
	public function status(): array {
		$status = get_option( self::STATUS_OPTION, array() );
		$status = is_array( $status ) ? $status : array();

		return array_merge(
			array(
				'state'     => 'idle',
				'queue'     => array(),
				'total'     => 0,
				'processed' => 0,
				'failed'    => 0,
				'started'   => 0,
				'updated'   => 0,
			),
			$status
		);
	}

	/**
	 * Find same-site URLs from the core sitemap index.
	 *
	 * @return list<string>
	 */
	private function discoverUrls(): array {
		$limit      = max( 10, min( 500, (int) $this->settings->get( 'preload_url_limit' ) ) );
		$home       = home_url( '/' );
		$home_host  = strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );
		$sitemap    = home_url( '/wp-sitemap.xml' );
		$discovered = array( $home );
		$documents  = array( $sitemap );
		$found      = 1;

		while ( ! empty( $documents ) && $found < $limit ) {
			$document = array_shift( $documents );
			$response = wp_safe_remote_get(
				(string) $document,
				array(
					'timeout'     => 15,
					'redirection' => 2,
				)
			);
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}

			$locations = $this->extractLocations( wp_remote_retrieve_body( $response ) );
			foreach ( $locations as $location ) {
				if ( strtolower( (string) wp_parse_url( $location, PHP_URL_HOST ) ) !== $home_host ) {
					continue;
				}

				if ( str_ends_with( strtolower( (string) wp_parse_url( $location, PHP_URL_PATH ) ), '.xml' ) ) {
					if ( 30 > count( $documents ) ) {
						$documents[] = $location;
					}
					continue;
				}

				$discovered[] = $location;
				++$found;
				if ( $limit <= $found ) {
					break 2;
				}
			}
		}

		return array_values( array_unique( $discovered ) );
	}

	/**
	 * Extract sitemap locations without requiring an XML extension.
	 *
	 * @param string $xml Sitemap document.
	 * @return list<string>
	 */
	private function extractLocations( string $xml ): array {
		if ( ! preg_match_all( '#<loc>\s*(.*?)\s*</loc>#is', $xml, $matches ) ) {
			return array();
		}

		$locations = array_map(
			static fn ( string $location ): string => esc_url_raw( html_entity_decode( wp_strip_all_tags( $location ), ENT_QUOTES | ENT_XML1, 'UTF-8' ) ),
			$matches[1]
		);

		return array_values( array_filter( $locations ) );
	}

	/**
	 * Ensure one future background event exists.
	 */
	private function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}
}
