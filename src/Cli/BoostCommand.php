<?php
/**
 * WP-CLI operations for automation and managed hosting workflows.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost\Cli;

use VyomPress\Boost\Cache\CachePreloader;
use VyomPress\Boost\Cache\CacheStore;
use VyomPress\Boost\Cloudflare\CloudflareIntegration;
use VyomPress\Boost\Media\MediaMigrator;
use VyomPress\Boost\Optimization\DatabaseOptimizer;
use VyomPress\Boost\Settings;

/**
 * Exposes safe cache, preload, media, database, and status commands.
 */
final class BoostCommand {
	/**
	 * Create the root CLI command.
	 *
	 * @param Settings              $settings   Plugin settings.
	 * @param CacheStore            $store      Page cache store.
	 * @param CloudflareIntegration $cloudflare Cloudflare integration.
	 * @param CachePreloader        $preloader  Cache preloader.
	 * @param MediaMigrator         $migrator   Media migration worker.
	 * @param DatabaseOptimizer     $database   Database optimizer.
	 */
	public function __construct(
		private Settings $settings,
		private CacheStore $store,
		private CloudflareIntegration $cloudflare,
		private CachePreloader $preloader,
		private MediaMigrator $migrator,
		private DatabaseOptimizer $database
	) {
	}

	/**
	 * Show a concise operational summary.
	 */
	public function status(): void {
		$preload = $this->preloader->status();
		$media   = $this->migrator->status();
		\WP_CLI\Utils\format_items(
			'table',
			array(
				array(
					'component' => 'Page cache',
					'state'     => $this->settings->get( 'page_cache' ) ? 'enabled' : 'disabled',
					'detail'    => $this->store->count() . ' entries',
				),
				array(
					'component' => 'Cloudflare',
					'state'     => $this->settings->get( 'cloudflare_enabled' ) ? 'enabled' : 'disabled',
					'detail'    => $this->cloudflare->isConfigured() ? 'configured' : 'not configured',
				),
				array(
					'component' => 'Preloader',
					'state'     => $preload['state'],
					'detail'    => $preload['processed'] . ' processed',
				),
				array(
					'component' => 'Media job',
					'state'     => $media['state'],
					'detail'    => $media['processed'] . ' processed; ' . $media['failed'] . ' failed',
				),
			),
			array( 'component', 'state', 'detail' )
		);
	}

	/**
	 * Manage cached pages: `wp vyompress-boost cache purge|preload|cancel|status`.
	 *
	 * @param array<string> $args Positional arguments.
	 */
	public function cache( array $args ): void {
		$operation = sanitize_key( (string) ( $args[0] ?? 'status' ) );
		if ( 'purge' === $operation ) {
			$removed = $this->store->clear();
			if ( $this->settings->get( 'cloudflare_enabled' ) && $this->cloudflare->isConfigured() ) {
				$result = $this->cloudflare->purgeNow();
				if ( is_wp_error( $result ) ) {
					\WP_CLI::error( $result->get_error_message() );
				}
			}
			\WP_CLI::success( sprintf( '%d local cache entries removed.', $removed ) );

			return;
		}

		if ( 'preload' === $operation ) {
			$this->preloader->start() ? \WP_CLI::success( 'Cache preload queued.' ) : \WP_CLI::error( 'Cache preloading is disabled.' );

			return;
		}

		if ( 'cancel' === $operation ) {
			$this->preloader->cancel();
			\WP_CLI::success( 'Cache preload cancelled.' );

			return;
		}

		\WP_CLI::log( wp_json_encode( $this->preloader->status(), JSON_PRETTY_PRINT ) );
	}

	/**
	 * Manage existing media: `wp vyompress-boost media offload|verify|restore|regenerate|pause|resume|status`.
	 *
	 * @param array<string> $args Positional arguments.
	 */
	public function media( array $args ): void {
		$operation = sanitize_key( (string) ( $args[0] ?? 'status' ) );
		if ( 'pause' === $operation ) {
			$this->migrator->pause();
			\WP_CLI::success( 'Media job paused.' );

			return;
		}

		if ( 'resume' === $operation ) {
			$this->migrator->resume() ? \WP_CLI::success( 'Media job resumed.' ) : \WP_CLI::error( 'No paused media job is available.' );

			return;
		}

		if ( in_array( $operation, array( 'offload', 'verify', 'restore', 'regenerate' ), true ) ) {
			$this->migrator->start( $operation ) ? \WP_CLI::success( 'Media job queued.' ) : \WP_CLI::error( 'The requested media job is not configured.' );

			return;
		}

		\WP_CLI::log( wp_json_encode( $this->migrator->status(), JSON_PRETTY_PRINT ) );
	}

	/**
	 * Run one bounded database cleanup pass.
	 */
	public function database(): void {
		$result = $this->database->run();
		\WP_CLI::success( sprintf( '%d records removed.', array_sum( $result ) ) );
	}
}
