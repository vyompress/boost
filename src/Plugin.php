<?php
/**
 * Plugin composition root.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost;

use VyomPress\Boost\Admin\SettingsPage;
use VyomPress\Boost\Cache\CacheStore;
use VyomPress\Boost\Cache\PageCache;
use VyomPress\Boost\Cloudflare\CloudflareClient;
use VyomPress\Boost\Cloudflare\CloudflareIntegration;
use VyomPress\Boost\Media\MediaOffloader;
use VyomPress\Boost\Media\S3Client;
use VyomPress\Boost\Media\S3Signer;
use VyomPress\Boost\Optimization\AssetOptimizer;

/**
 * Wires the plugin's independently testable modules to WordPress.
 */
final class Plugin {
	/**
	 * Singleton plugin instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether runtime hooks have already been registered.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Return the singleton plugin instance.
	 */
	public static function instance(): self {
		self::$instance ??= new self();

		return self::$instance;
	}

	/**
	 * Register all runtime modules exactly once.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$settings   = new Settings();
		$store      = new CacheStore();
		$cloudflare = new CloudflareIntegration( $settings, new CloudflareClient() );
		$s3_client  = new S3Client( $settings, new S3Signer() );
		$offloader  = new MediaOffloader( $settings, $s3_client );

		( new PageCache( $settings, $store ) )->register();
		( new AssetOptimizer( $settings ) )->register();
		$cloudflare->register();
		$offloader->register();

		if ( is_admin() ) {
			( new SettingsPage( $settings, $store, $cloudflare, $s3_client ) )->register();
		}
	}

	/**
	 * Install defaults and verify cache storage on activation.
	 */
	public static function activate(): void {
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}

		( new CacheStore() )->install();
	}

	/**
	 * Prevent stale pages after the plugin stops controlling delivery.
	 */
	public static function deactivate(): void {
		( new CacheStore() )->clear();
		wp_clear_scheduled_hook( CloudflareIntegration::CRON_HOOK );
	}

	/**
	 * Prevent direct construction outside the composition root.
	 */
	private function __construct() {
	}
}
