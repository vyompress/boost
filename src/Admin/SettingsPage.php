<?php
/**
 * WordPress administration screen.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost\Admin;

use VyomPress\Boost\Cache\CachePreloader;
use VyomPress\Boost\Cache\CacheStore;
use VyomPress\Boost\Cloudflare\CloudflareIntegration;
use VyomPress\Boost\Media\MediaMigrator;
use VyomPress\Boost\Media\S3Client;
use VyomPress\Boost\Operations\ActivityLog;
use VyomPress\Boost\Settings;

/**
 * Registers the guided settings UI, diagnostics, and protected actions.
 */
final class SettingsPage {
	private const PAGE_SLUG     = 'vyompress-boost';
	private const NOTICE_PREFIX = 'vyompress_boost_notice_';

	/**
	 * Create the administration controller.
	 *
	 * @param Settings              $settings   Plugin settings.
	 * @param CacheStore            $store      Local page-cache storage.
	 * @param CloudflareIntegration $cloudflare Cloudflare integration.
	 * @param S3Client              $s3         S3-compatible storage client.
	 * @param CachePreloader        $preloader  Cache preloader.
	 * @param MediaMigrator         $migrator   Existing-media migration worker.
	 * @param ActivityLog           $log        Local activity log.
	 */
	public function __construct(
		private Settings $settings,
		private CacheStore $store,
		private CloudflareIntegration $cloudflare,
		private S3Client $s3,
		private CachePreloader $preloader,
		private MediaMigrator $migrator,
		private ActivityLog $log
	) {
	}

	/**
	 * Register administration hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_init', array( $this, 'registerSettings' ) );
		add_action( 'admin_init', array( $this, 'addPrivacyPolicyContent' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
		add_action( 'admin_post_vyompress_boost_purge', array( $this, 'handlePurge' ) );
		add_action( 'admin_post_vyompress_boost_test_cloudflare', array( $this, 'handleCloudflareTest' ) );
		add_action( 'admin_post_vyompress_boost_test_s3', array( $this, 'handleS3Test' ) );
		add_action( 'admin_post_vyompress_boost_cloudflare_rule', array( $this, 'handleCloudflareRule' ) );
		add_action( 'admin_post_vyompress_boost_preload', array( $this, 'handlePreload' ) );
		add_action( 'admin_post_vyompress_boost_media_job', array( $this, 'handleMediaJob' ) );
		add_action( 'admin_notices', array( $this, 'renderNotice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( VYOMPRESS_BOOST_FILE ), array( $this, 'actionLinks' ) );
		add_filter( 'site_status_tests', array( $this, 'siteHealthTests' ) );
	}

	/**
	 * Add the plugin settings screen.
	 */
	public function addMenu(): void {
		add_options_page(
			esc_html__( 'VyomPress Boost', 'vyompress-boost' ),
			esc_html__( 'VyomPress Boost', 'vyompress-boost' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Register one validated, non-autoloaded settings record.
	 */
	public function registerSettings(): void {
		register_setting(
			'vyompress_boost',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'default'           => Settings::defaults(),
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
			)
		);
	}

	/**
	 * Document optional external data transfers for WordPress privacy tooling.
	 */
	public function addPrivacyPolicyContent(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . esc_html__( 'VyomPress Boost does not collect telemetry. When Cloudflare integration is enabled, authenticated cache-purge and optional cache-rule management requests are sent to Cloudflare. When media offloading is enabled, WordPress media files are copied to the S3-compatible endpoint selected by the site administrator. The applicable provider receives the request metadata and uploaded media according to that provider’s terms and privacy policy.', 'vyompress-boost' ) . '</p>';
		wp_add_privacy_policy_content( __( 'VyomPress Boost', 'vyompress-boost' ), wp_kses_post( wpautop( $content, false ) ) );
	}

	/**
	 * Enqueue plugin-owned styling only on its settings screen.
	 *
	 * @param string $hook_suffix Current administration screen hook.
	 */
	public function enqueueAssets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'vyompress-boost-admin', VYOMPRESS_BOOST_URL . 'assets/admin.css', array(), VYOMPRESS_BOOST_VERSION );
	}

	/**
	 * Render the guided settings screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab  = $this->currentTab();
		$tabs = array(
			'overview'      => __( 'Overview', 'vyompress-boost' ),
			'cache'         => __( 'Page cache', 'vyompress-boost' ),
			'cloudflare'    => __( 'Cloudflare', 'vyompress-boost' ),
			'media'         => __( 'Media storage', 'vyompress-boost' ),
			'optimizations' => __( 'Optimizations', 'vyompress-boost' ),
		);
		?>
		<div class="wrap vyompress-boost">
			<header class="vyompress-boost__header">
				<div>
					<h1><?php echo esc_html__( 'VyomPress Boost', 'vyompress-boost' ); ?></h1>
					<p><?php echo esc_html__( 'Faster delivery and simpler cloud storage—with safe defaults.', 'vyompress-boost' ); ?></p>
				</div>
				<span class="vyompress-boost__version"><?php echo esc_html( 'v' . VYOMPRESS_BOOST_VERSION ); ?></span>
			</header>

			<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'VyomPress Boost settings', 'vyompress-boost' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $this->pageUrl( $slug ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php if ( 'overview' === $tab ) : ?>
				<?php $this->renderOverview(); ?>
			<?php else : ?>
				<form action="options.php" method="post" class="vyompress-boost__form">
					<?php settings_fields( 'vyompress_boost' ); ?>
					<?php $this->renderTab( $tab ); ?>
					<?php submit_button( __( 'Save changes', 'vyompress-boost' ) ); ?>
				</form>
				<?php if ( 'cache' === $tab ) : ?>
					<?php $this->renderPreloadActions(); ?>
				<?php elseif ( 'cloudflare' === $tab ) : ?>
					<?php $this->actionPanel( 'vyompress_boost_test_cloudflare', 'vyompress_boost_test_cloudflare', __( 'Test and purge Cloudflare', 'vyompress-boost' ), __( 'This validates the saved credentials by clearing the connected zone cache.', 'vyompress-boost' ) ); ?>
					<?php $this->actionPanel( 'vyompress_boost_cloudflare_rule', 'vyompress_boost_cloudflare_rule', __( 'Install or update edge rule', 'vyompress-boost' ), __( 'Creates or updates only the Cloudflare Cache Rule owned by VyomPress Boost. Save settings first.', 'vyompress-boost' ), array( 'operation' => 'sync' ) ); ?>
					<?php $this->actionPanel( 'vyompress_boost_cloudflare_rule', 'vyompress_boost_cloudflare_rule', __( 'Remove edge rule', 'vyompress-boost' ), __( 'Removes only the VyomPress Boost rule and leaves other Cloudflare rules unchanged.', 'vyompress-boost' ), array( 'operation' => 'remove' ) ); ?>
				<?php elseif ( 'media' === $tab ) : ?>
					<?php $this->actionPanel( 'vyompress_boost_test_s3', 'vyompress_boost_test_s3', __( 'Test storage connection', 'vyompress-boost' ), __( 'Creates and immediately removes a tiny test object. Save changes first.', 'vyompress-boost' ) ); ?>
					<?php $this->renderMediaJobActions(); ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render status, recommended next actions, and maintenance controls.
	 */
	private function renderOverview(): void {
		$cloudflare_ready = $this->settings->get( 'cloudflare_enabled' ) && $this->cloudflare->isConfigured();
		$s3_ready         = $this->settings->get( 's3_enabled' ) && $this->s3->isConfigured();
		$preload_status   = $this->preloader->status();
		$migration_status = $this->migrator->status();
		?>
		<section class="vyompress-boost__status" aria-label="<?php echo esc_attr__( 'Boost status', 'vyompress-boost' ); ?>">
			<?php $this->statusCard( __( 'Page cache', 'vyompress-boost' ), $this->settings->get( 'page_cache' ) ? __( 'Active', 'vyompress-boost' ) : __( 'Off', 'vyompress-boost' ), (bool) $this->settings->get( 'page_cache' ) ); ?>
			<?php $this->statusCard( __( 'Cache storage', 'vyompress-boost' ), $this->store->isWritable() ? __( 'Ready', 'vyompress-boost' ) : __( 'Needs attention', 'vyompress-boost' ), $this->store->isWritable() ); ?>
			<?php $this->statusCard( __( 'Cloudflare', 'vyompress-boost' ), $cloudflare_ready ? __( 'Connected', 'vyompress-boost' ) : __( 'Optional', 'vyompress-boost' ), (bool) $cloudflare_ready ); ?>
			<?php $this->statusCard( __( 'Media storage', 'vyompress-boost' ), $s3_ready ? __( 'Connected', 'vyompress-boost' ) : __( 'Optional', 'vyompress-boost' ), (bool) $s3_ready ); ?>
		</section>

		<div class="vyompress-boost__grid">
			<section class="vyompress-boost__panel">
				<h2><?php echo esc_html__( 'Your performance setup', 'vyompress-boost' ); ?></h2>
				<ol class="vyompress-boost__steps">
					<li class="is-complete"><strong><?php echo esc_html__( 'Local page cache', 'vyompress-boost' ); ?></strong><span><?php echo esc_html( number_format_i18n( $this->store->count() ) . ' ' . __( 'pages cached', 'vyompress-boost' ) ); ?></span></li>
					<li class="<?php echo $cloudflare_ready ? 'is-complete' : ''; ?>"><strong><?php echo esc_html__( 'Connect Cloudflare', 'vyompress-boost' ); ?></strong><span><?php echo esc_html__( 'Automatically clear edge cache when content changes.', 'vyompress-boost' ); ?></span></li>
					<li class="<?php echo $s3_ready ? 'is-complete' : ''; ?>"><strong><?php echo esc_html__( 'Connect media storage', 'vyompress-boost' ); ?></strong><span><?php echo esc_html__( 'Copy new uploads to any S3-compatible provider.', 'vyompress-boost' ); ?></span></li>
				</ol>
			</section>

			<section class="vyompress-boost__panel">
				<h2><?php echo esc_html__( 'Cache maintenance', 'vyompress-boost' ); ?></h2>
				<p><?php echo esc_html__( 'Use this after a major design or content change. Connected Cloudflare cache is cleared too.', 'vyompress-boost' ); ?></p>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="vyompress_boost_purge">
					<?php wp_nonce_field( 'vyompress_boost_purge' ); ?>
					<?php submit_button( __( 'Purge connected caches', 'vyompress-boost' ), 'secondary', 'submit', false ); ?>
				</form>
			</section>
		</div>

		<div class="vyompress-boost__grid">
			<section class="vyompress-boost__panel">
				<h2><?php echo esc_html__( 'Background jobs', 'vyompress-boost' ); ?></h2>
				<p><?php echo esc_html( sprintf( /* translators: 1: preload state, 2: processed URL count. */ __( 'Cache preload: %1$s (%2$d processed)', 'vyompress-boost' ), $preload_status['state'], $preload_status['processed'] ) ); ?></p>
				<p><?php echo esc_html( sprintf( /* translators: 1: media job state, 2: processed attachment count. */ __( 'Media job: %1$s (%2$d processed)', 'vyompress-boost' ), $migration_status['state'], $migration_status['processed'] ) ); ?></p>
			</section>

			<section class="vyompress-boost__panel">
				<h2><?php echo esc_html__( 'Recent activity', 'vyompress-boost' ); ?></h2>
				<?php $events = $this->log->all( 5 ); ?>
				<?php if ( array() === $events ) : ?>
					<p><?php echo esc_html__( 'No background operations recorded yet.', 'vyompress-boost' ); ?></p>
				<?php else : ?>
					<ul class="vyompress-boost__activity">
						<?php foreach ( $events as $event ) : ?>
							<li><time datetime="<?php echo esc_attr( gmdate( 'c', $event['time'] ) ); ?>"><?php echo esc_html( human_time_diff( $event['time'], time() ) . ' ' . __( 'ago', 'vyompress-boost' ) ); ?></time><span><?php echo esc_html( $event['message'] ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Render one settings tab.
	 *
	 * @param string $tab Validated tab slug.
	 */
	private function renderTab( string $tab ): void {
		if ( 'cache' === $tab ) {
			$this->renderCacheTab();
		} elseif ( 'cloudflare' === $tab ) {
			$this->renderCloudflareTab();
		} elseif ( 'media' === $tab ) {
			$this->renderMediaTab();
		} else {
			$this->renderOptimizationsTab();
		}
	}

	/**
	 * Render page-cache essentials and advanced controls.
	 */
	private function renderCacheTab(): void {
		?>
		<?php $this->panelStart( __( 'Page cache', 'vyompress-boost' ), __( 'Safe for blogs, business sites, and stores. Private sessions and checkout paths are bypassed automatically.', 'vyompress-boost' ) ); ?>
		<?php $this->checkbox( 'page_cache', __( 'Enable page cache', 'vyompress-boost' ), __( 'Serve saved HTML to anonymous visitors for much faster repeat requests.', 'vyompress-boost' ) ); ?>
		<?php $this->number( 'cache_ttl', __( 'Saved page lifetime', 'vyompress-boost' ), 60, WEEK_IN_SECONDS, 60, __( 'seconds', 'vyompress-boost' ), __( '3,600 seconds (one hour) is a good default.', 'vyompress-boost' ) ); ?>
		<?php $this->checkbox( 'browser_cache', __( 'Browser caching', 'vyompress-boost' ), __( 'Allow a visitor’s browser to reuse verified cache hits.', 'vyompress-boost' ) ); ?>
		<?php $this->number( 'browser_ttl', __( 'Browser cache lifetime', 'vyompress-boost' ), 60, YEAR_IN_SECONDS, 60, __( 'seconds', 'vyompress-boost' ), __( 'This only applies when browser caching is enabled.', 'vyompress-boost' ) ); ?>
		<?php $this->panelEnd(); ?>

		<?php $this->panelStart( __( 'Advanced cache rules', 'vyompress-boost' ), __( 'The defaults are conservative. Change these only when your site needs them.', 'vyompress-boost' ) ); ?>
		<?php $this->checkbox( 'separate_mobile_cache', __( 'Separate mobile cache', 'vyompress-boost' ), __( 'Use a different saved page for phones when your theme renders different HTML by device.', 'vyompress-boost' ) ); ?>
		<?php $this->checkbox( 'cache_query_strings', __( 'Cache query-string pages', 'vyompress-boost' ), __( 'Cache URLs containing meaningful query parameters. Tracking parameters listed below are always ignored.', 'vyompress-boost' ) ); ?>
		<?php $this->textarea( 'ignored_query_parameters', __( 'Ignored query parameters', 'vyompress-boost' ), __( 'One parameter name per line. These are removed from cache keys.', 'vyompress-boost' ) ); ?>
		<?php $this->textarea( 'excluded_paths', __( 'Never cache these paths', 'vyompress-boost' ), __( 'One root-relative path per line. Matching subpaths are excluded too.', 'vyompress-boost' ) ); ?>
		<?php $this->panelEnd(); ?>

		<?php $this->panelStart( __( 'Cache preloading', 'vyompress-boost' ), __( 'Warms public pages gradually from the WordPress sitemap without creating traffic spikes.', 'vyompress-boost' ) ); ?>
		<?php $this->checkbox( 'preload_enabled', __( 'Enable cache preloading', 'vyompress-boost' ), __( 'Allow background requests to prepare pages before visitors arrive.', 'vyompress-boost' ) ); ?>
		<?php $this->checkbox( 'preload_on_purge', __( 'Preload after cache purges', 'vyompress-boost' ), __( 'Automatically rebuild the cache after content or settings changes.', 'vyompress-boost' ) ); ?>
		<?php $this->number( 'preload_concurrency', __( 'URLs per batch', 'vyompress-boost' ), 1, 4, 1, __( 'URLs', 'vyompress-boost' ), __( 'Use 1 on shared hosting. Higher values warm faster but use more server resources.', 'vyompress-boost' ) ); ?>
		<?php $this->number( 'preload_url_limit', __( 'Maximum URLs per run', 'vyompress-boost' ), 10, 500, 10, __( 'URLs', 'vyompress-boost' ), __( 'Limits work discovered from the WordPress sitemap.', 'vyompress-boost' ) ); ?>
		<?php $this->panelEnd(); ?>
		<?php
	}

	/**
	 * Render Cloudflare connection settings.
	 */
	private function renderCloudflareTab(): void {
		?>
		<?php $this->panelStart( __( 'Cloudflare cache', 'vyompress-boost' ), __( 'Connect an API token with Zone → Cache Purge permission. No Cloudflare account data is sent to VyomPress.', 'vyompress-boost' ) ); ?>
		<?php $this->checkbox( 'cloudflare_enabled', __( 'Enable Cloudflare integration', 'vyompress-boost' ), __( 'Allow this site to send cache-purge requests to Cloudflare.', 'vyompress-boost' ) ); ?>
		<?php $this->text( 'cloudflare_zone_id', __( 'Zone ID', 'vyompress-boost' ), __( 'Found on the Cloudflare domain overview page.', 'vyompress-boost' ), '32 hexadecimal characters' ); ?>
		<?php $this->password( 'cloudflare_api_token', 'VYOMPRESS_BOOST_CLOUDFLARE_API_TOKEN', __( 'API token', 'vyompress-boost' ), __( 'Use a scoped token with Cache Purge permission only.', 'vyompress-boost' ), 'clear_cloudflare_api_token' ); ?>
		<?php $this->checkbox( 'cloudflare_auto_purge', __( 'Automatic purge', 'vyompress-boost' ), __( 'Clear Cloudflare shortly after content, comments, themes, plugins, or cache settings change.', 'vyompress-boost' ) ); ?>
		<?php $this->checkbox( 'cloudflare_edge_cache', __( 'Manage full-page edge caching', 'vyompress-boost' ), __( 'Lets the setup action create one safe, plugin-owned Cloudflare Cache Rule.', 'vyompress-boost' ) ); ?>
		<?php $this->number( 'cloudflare_edge_ttl', __( 'Edge cache lifetime', 'vyompress-boost' ), 7200, MONTH_IN_SECONDS, 3600, __( 'seconds', 'vyompress-boost' ), __( 'Cloudflare Free requires at least 7,200 seconds. Purges still publish changes immediately.', 'vyompress-boost' ) ); ?>
		<p class="vyompress-boost__help"><?php echo esc_html__( 'Create a scoped token from the API Tokens page in your Cloudflare dashboard.', 'vyompress-boost' ); ?></p>
		<?php $this->panelEnd(); ?>
		<?php
	}

	/**
	 * Render S3-compatible media connection settings.
	 */
	private function renderMediaTab(): void {
		?>
		<div class="notice notice-info inline"><p><?php echo esc_html__( 'Works with AWS S3, Cloudflare R2, DigitalOcean Spaces, Wasabi, Backblaze B2 S3, and compatible MinIO endpoints.', 'vyompress-boost' ); ?></p></div>
		<?php $this->panelStart( __( 'S3-compatible media storage', 'vyompress-boost' ), __( 'New uploads are copied automatically. Existing files can be migrated, verified, or restored with resumable background jobs below.', 'vyompress-boost' ) ); ?>
		<?php $this->checkbox( 's3_enabled', __( 'Enable media offloading', 'vyompress-boost' ), __( 'Send new Media Library files to the configured storage provider.', 'vyompress-boost' ) ); ?>
		<?php $this->text( 's3_endpoint', __( 'S3 endpoint', 'vyompress-boost' ), __( 'Enter the HTTPS endpoint shown by your storage provider.', 'vyompress-boost' ), __( 'Provider endpoint', 'vyompress-boost' ), 'url' ); ?>
		<?php $this->text( 's3_region', __( 'Region', 'vyompress-boost' ), __( 'Use auto for Cloudflare R2, or your provider’s region name.', 'vyompress-boost' ), 'us-east-1' ); ?>
		<?php $this->text( 's3_bucket', __( 'Bucket', 'vyompress-boost' ), __( 'The existing bucket that will hold WordPress media.', 'vyompress-boost' ), 'my-media-bucket' ); ?>
		<?php $this->text( 's3_access_key', __( 'Access key', 'vyompress-boost' ), __( 'Use credentials limited to this bucket.', 'vyompress-boost' ), '' ); ?>
		<?php $this->password( 's3_secret_key', 'VYOMPRESS_BOOST_S3_SECRET_KEY', __( 'Secret key', 'vyompress-boost' ), __( 'Stored in the WordPress options table unless supplied through wp-config.php.', 'vyompress-boost' ), 'clear_s3_secret_key' ); ?>
		<?php $this->text( 's3_public_url', __( 'Public or CDN URL', 'vyompress-boost' ), __( 'Optional. Use the public bucket URL or your custom CDN domain.', 'vyompress-boost' ), __( 'Public media URL', 'vyompress-boost' ), 'url' ); ?>
		<?php $this->text( 's3_prefix', __( 'Folder prefix', 'vyompress-boost' ), __( 'Keeps this site’s objects organized inside the bucket.', 'vyompress-boost' ), 'wp-content/uploads' ); ?>
		<?php $this->checkbox( 's3_path_style', __( 'Path-style endpoint', 'vyompress-boost' ), __( 'Recommended for R2, MinIO, and most compatible providers. AWS users can turn this off.', 'vyompress-boost' ) ); ?>
		<?php $this->checkbox( 's3_keep_local', __( 'Keep local copies', 'vyompress-boost' ), __( 'Recommended. Keeps Media Library editing and provider outages recoverable.', 'vyompress-boost' ) ); ?>
		<?php $this->text( 's3_cache_control', __( 'Remote cache policy', 'vyompress-boost' ), __( 'Cache-Control metadata attached to uploaded objects.', 'vyompress-boost' ), 'public, max-age=31536000, immutable' ); ?>
		<?php $this->panelEnd(); ?>
		<?php
	}

	/**
	 * Render safe WordPress optimization toggles.
	 */
	private function renderOptimizationsTab(): void {
		$this->panelStart( __( 'WordPress optimizations', 'vyompress-boost' ), __( 'Each change is independent and immediately reversible.', 'vyompress-boost' ) );
		$this->checkbox( 'disable_emojis', __( 'Remove emoji assets', 'vyompress-boost' ), __( 'Stops WordPress loading its emoji detection script and styles.', 'vyompress-boost' ) );
		$this->checkbox( 'disable_embeds', __( 'Remove embed helper', 'vyompress-boost' ), __( 'Removes the front-end oEmbed discovery links and helper script.', 'vyompress-boost' ) );
		$this->checkbox( 'reduce_heartbeat', __( 'Reduce Heartbeat frequency', 'vyompress-boost' ), __( 'Limits background Heartbeat requests to once per minute.', 'vyompress-boost' ) );
		$this->panelEnd();
	}

	/**
	 * Render a consistent panel opening.
	 *
	 * @param string $title       Panel heading.
	 * @param string $description Panel guidance.
	 */
	private function panelStart( string $title, string $description ): void {
		printf( '<section class="vyompress-boost__panel"><h2>%s</h2><p class="description">%s</p><div class="vyompress-boost__fields">', esc_html( $title ), esc_html( $description ) );
	}

	/**
	 * Close a settings panel.
	 */
	private function panelEnd(): void {
		echo '</div></section>';
	}

	/**
	 * Render a boolean setting as a plain-language switch.
	 *
	 * @param string $key         Settings key.
	 * @param string $label       Field label.
	 * @param string $description Field guidance.
	 */
	private function checkbox( string $key, string $label, string $description ): void {
		$name = Settings::OPTION . '[' . $key . ']';
		?>
		<div class="vyompress-boost__field vyompress-boost__field--toggle">
			<div><strong><?php echo esc_html( $label ); ?></strong><p><?php echo esc_html( $description ); ?></p></div>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
			<label class="vyompress-boost__switch"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( (bool) $this->settings->get( $key ) ); ?>><span aria-hidden="true"></span><span class="screen-reader-text"><?php echo esc_html( $label ); ?></span></label>
		</div>
		<?php
	}

	/**
	 * Render a bounded number field.
	 *
	 * @param string $key         Settings key.
	 * @param string $label       Field label.
	 * @param int    $min         Minimum accepted value.
	 * @param int    $max         Maximum accepted value.
	 * @param int    $step        Input increment.
	 * @param string $suffix      Human-readable unit.
	 * @param string $description Field guidance.
	 */
	private function number( string $key, string $label, int $min, int $max, int $step, string $suffix, string $description ): void {
		?>
		<label class="vyompress-boost__field"><strong><?php echo esc_html( $label ); ?></strong><span class="vyompress-boost__input"><input type="number" min="<?php echo esc_attr( (string) $min ); ?>" max="<?php echo esc_attr( (string) $max ); ?>" step="<?php echo esc_attr( (string) $step ); ?>" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( (string) $this->settings->get( $key ) ); ?>"> <?php echo esc_html( $suffix ); ?></span><small><?php echo esc_html( $description ); ?></small></label>
		<?php
	}

	/**
	 * Render a text or URL setting.
	 *
	 * @param string $key         Settings key.
	 * @param string $label       Field label.
	 * @param string $description Field guidance.
	 * @param string $placeholder Optional example.
	 * @param string $type        HTML input type.
	 */
	private function text( string $key, string $label, string $description, string $placeholder = '', string $type = 'text' ): void {
		?>
		<label class="vyompress-boost__field"><strong><?php echo esc_html( $label ); ?></strong><input class="regular-text" type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( (string) $this->settings->get( $key ) ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="off"><small><?php echo esc_html( $description ); ?></small></label>
		<?php
	}

	/**
	 * Render a secret without returning its saved value to the browser.
	 *
	 * @param string $key         Settings key.
	 * @param string $constant    Optional wp-config.php constant.
	 * @param string $label       Field label.
	 * @param string $description Field guidance.
	 * @param string $clear_key   Submitted clear-checkbox key.
	 */
	private function password( string $key, string $constant, string $label, string $description, string $clear_key ): void {
		$has_secret  = '' !== $this->settings->credential( $key, $constant );
		$is_constant = defined( $constant );
		?>
		<div class="vyompress-boost__field">
			<label><strong><?php echo esc_html( $label ); ?></strong><input class="regular-text" type="password" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" value="" placeholder="<?php echo $has_secret ? esc_attr__( 'Saved—leave blank to keep', 'vyompress-boost' ) : ''; ?>" autocomplete="new-password" <?php disabled( $is_constant ); ?>><small><?php echo esc_html( $description ); ?></small></label>
			<?php
			if ( $is_constant ) :
				?>
				<p class="vyompress-boost__credential-state"><?php echo esc_html( sprintf( /* translators: %s: wp-config.php constant name. */ __( 'Managed by %s.', 'vyompress-boost' ), $constant ) ); ?></p>
				<?php
elseif ( $has_secret ) :
	?>
				<label class="vyompress-boost__clear"><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION . '[' . $clear_key . ']' ); ?>" value="1"> <?php echo esc_html__( 'Remove saved credential', 'vyompress-boost' ); ?></label><?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a newline-delimited setting.
	 *
	 * @param string $key         Settings key.
	 * @param string $label       Field label.
	 * @param string $description Field guidance.
	 */
	private function textarea( string $key, string $label, string $description ): void {
		?>
		<label class="vyompress-boost__field"><strong><?php echo esc_html( $label ); ?></strong><textarea class="large-text code" rows="5" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>"><?php echo esc_textarea( (string) $this->settings->get( $key ) ); ?></textarea><small><?php echo esc_html( $description ); ?></small></label>
		<?php
	}

	/**
	 * Render a protected connection-test action.
	 *
	 * @param string               $action      Admin-post action.
	 * @param string               $nonce       Nonce action.
	 * @param string               $button      Button and panel label.
	 * @param string               $description Action guidance.
	 * @param array<string,string> $fields      Additional hidden fields.
	 */
	private function actionPanel( string $action, string $nonce, string $button, string $description, array $fields = array() ): void {
		?>
		<section class="vyompress-boost__panel vyompress-boost__panel--action"><div><h2><?php echo esc_html( $button ); ?></h2><p><?php echo esc_html( $description ); ?></p></div><form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
		<?php
		foreach ( $fields as $name => $value ) :
			?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>"><?php endforeach; ?><?php wp_nonce_field( $nonce ); ?><?php submit_button( $button, 'secondary', 'submit', false ); ?></form></section>
		<?php
	}

	/**
	 * Render cache-preload controls and current progress.
	 */
	private function renderPreloadActions(): void {
		$status = $this->preloader->status();
		$this->actionPanel( 'vyompress_boost_preload', 'vyompress_boost_preload', __( 'Start cache preload', 'vyompress-boost' ), sprintf( /* translators: 1: job state, 2: processed URL count. */ __( 'Current state: %1$s. Processed URLs: %2$d.', 'vyompress-boost' ), $status['state'], $status['processed'] ), array( 'operation' => 'start' ) );
		if ( in_array( $status['state'], array( 'discovering', 'running' ), true ) ) {
			$this->actionPanel( 'vyompress_boost_preload', 'vyompress_boost_preload', __( 'Cancel preload', 'vyompress-boost' ), __( 'Stops queued requests without deleting pages already warmed.', 'vyompress-boost' ), array( 'operation' => 'cancel' ) );
		}
	}

	/**
	 * Render existing-library media migration controls.
	 */
	private function renderMediaJobActions(): void {
		$status      = $this->migrator->status();
		$description = sprintf(
			/* translators: 1: job state, 2: processed count, 3: failed count. */
			__( 'Current job: %1$s. Processed: %2$d. Failed: %3$d.', 'vyompress-boost' ),
			$status['state'],
			$status['processed'],
			$status['failed']
		);

		if ( 'running' === $status['state'] ) {
			$this->actionPanel( 'vyompress_boost_media_job', 'vyompress_boost_media_job', __( 'Pause media job', 'vyompress-boost' ), $description, array( 'operation' => 'pause' ) );

			return;
		}

		if ( 'paused' === $status['state'] ) {
			$this->actionPanel( 'vyompress_boost_media_job', 'vyompress_boost_media_job', __( 'Resume media job', 'vyompress-boost' ), $description, array( 'operation' => 'resume' ) );
		}

		$this->actionPanel( 'vyompress_boost_media_job', 'vyompress_boost_media_job', __( 'Offload existing media', 'vyompress-boost' ), __( 'Copies existing Media Library files in small resumable batches.', 'vyompress-boost' ), array( 'operation' => 'offload' ) );
		$this->actionPanel( 'vyompress_boost_media_job', 'vyompress_boost_media_job', __( 'Verify remote media', 'vyompress-boost' ), __( 'Checks every registered remote object without downloading it.', 'vyompress-boost' ), array( 'operation' => 'verify' ) );
		$this->actionPanel( 'vyompress_boost_media_job', 'vyompress_boost_media_job', __( 'Restore local copies', 'vyompress-boost' ), __( 'Downloads missing local files while continuing to serve configured remote URLs.', 'vyompress-boost' ), array( 'operation' => 'restore' ) );
	}

	/**
	 * Render one compact status card.
	 *
	 * @param string $label  Status label.
	 * @param string $status Human-readable state.
	 * @param bool   $ready  Whether the integration is ready.
	 */
	private function statusCard( string $label, string $status, bool $ready ): void {
		printf( '<div><strong>%s</strong><span class="%s">%s</span></div>', esc_html( $label ), $ready ? 'is-ready' : 'is-muted', esc_html( $status ) );
	}

	/**
	 * Purge local and connected edge caches.
	 */
	public function handlePurge(): void {
		$this->authorizeAction( 'vyompress_boost_purge' );
		$removed = $this->store->clear();
		$message = sprintf(
			/* translators: %s: number of removed local cache entries. */
			_n( '%s local cached page removed.', '%s local cached pages removed.', $removed, 'vyompress-boost' ),
			number_format_i18n( $removed )
		);

		if ( $this->settings->get( 'cloudflare_enabled' ) && $this->cloudflare->isConfigured() ) {
			$result   = $this->cloudflare->purgeNow();
			$message .= ' ' . ( true === $result ? __( 'Cloudflare was purged too.', 'vyompress-boost' ) : $result->get_error_message() );
		}
		$this->log->add( 'cache', $message, 'success' );

		$this->redirectWithNotice( 'success', $message, 'overview' );
	}

	/**
	 * Validate Cloudflare credentials with an explicit purge.
	 */
	public function handleCloudflareTest(): void {
		$this->authorizeAction( 'vyompress_boost_test_cloudflare' );
		$result = $this->cloudflare->purgeNow();
		$this->redirectWithNotice( true === $result ? 'success' : 'error', true === $result ? __( 'Cloudflare connected successfully and its cache was purged.', 'vyompress-boost' ) : $result->get_error_message(), 'cloudflare' );
	}

	/**
	 * Install, refresh, or remove the plugin-owned Cloudflare rule.
	 */
	public function handleCloudflareRule(): void {
		$this->authorizeAction( 'vyompress_boost_cloudflare_rule' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		if ( 'sync' === $operation && ! $this->settings->get( 'cloudflare_edge_cache' ) ) {
			$this->redirectWithNotice( 'warning', __( 'Enable full-page edge caching and save changes first.', 'vyompress-boost' ), 'cloudflare' );
		}

		$result  = 'remove' === $operation ? $this->cloudflare->removeEdgeRule() : $this->cloudflare->syncEdgeRule();
		$message = true === $result ? ( 'remove' === $operation ? __( 'The VyomPress Boost edge rule was removed.', 'vyompress-boost' ) : __( 'The Cloudflare edge cache rule is active.', 'vyompress-boost' ) ) : $result->get_error_message();
		$this->redirectWithNotice( true === $result ? 'success' : 'error', $message, 'cloudflare' );
	}

	/**
	 * Start or cancel cache preloading.
	 */
	public function handlePreload(): void {
		$this->authorizeAction( 'vyompress_boost_preload' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		if ( 'cancel' === $operation ) {
			$this->preloader->cancel();
			$this->redirectWithNotice( 'success', __( 'Cache preloading was cancelled.', 'vyompress-boost' ), 'cache' );
		}

		$started = $this->preloader->start();
		$this->redirectWithNotice( $started ? 'success' : 'warning', $started ? __( 'Cache preloading started in the background.', 'vyompress-boost' ) : __( 'Enable cache preloading and save changes first.', 'vyompress-boost' ), 'cache' );
	}

	/**
	 * Start, pause, or resume an existing-media job.
	 */
	public function handleMediaJob(): void {
		$this->authorizeAction( 'vyompress_boost_media_job' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		if ( 'pause' === $operation ) {
			$this->migrator->pause();
			$this->redirectWithNotice( 'success', __( 'The media job was paused.', 'vyompress-boost' ), 'media' );
		}

		if ( 'resume' === $operation ) {
			$resumed = $this->migrator->resume();
			$this->redirectWithNotice( $resumed ? 'success' : 'warning', $resumed ? __( 'The media job resumed.', 'vyompress-boost' ) : __( 'There is no paused media job to resume.', 'vyompress-boost' ), 'media' );
		}

		$started = $this->migrator->start( $operation );
		$this->redirectWithNotice( $started ? 'success' : 'warning', $started ? __( 'The media job started in the background.', 'vyompress-boost' ) : __( 'Enable and test media storage first.', 'vyompress-boost' ), 'media' );
	}

	/**
	 * Validate S3-compatible write and delete permissions.
	 */
	public function handleS3Test(): void {
		$this->authorizeAction( 'vyompress_boost_test_s3' );
		$result = $this->s3->testConnection();
		$this->log->add( 'media_storage', true === $result ? __( 'Storage connection test passed.', 'vyompress-boost' ) : $result->get_error_message(), true === $result ? 'success' : 'error' );
		$this->redirectWithNotice( true === $result ? 'success' : 'error', true === $result ? __( 'Storage connected successfully. The test object was removed.', 'vyompress-boost' ) : $result->get_error_message(), 'media' );
	}

	/**
	 * Require administrator capability and a matching action nonce.
	 *
	 * @param string $nonce Nonce action.
	 */
	private function authorizeAction( string $nonce ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'vyompress-boost' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $nonce );
	}

	/**
	 * Store a user-specific notice and return to the relevant tab.
	 *
	 * @param string $type    WordPress notice type.
	 * @param string $message Notice message.
	 * @param string $tab     Destination tab.
	 */
	private function redirectWithNotice( string $type, string $message, string $tab ): never {
		set_transient(
			self::NOTICE_PREFIX . get_current_user_id(),
			array(
				'type'    => in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info',
				'message' => sanitize_text_field( $message ),
			),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( $this->pageUrl( $tab ) );
		exit;
	}

	/**
	 * Render and consume the current administrator's operation notice.
	 */
	public function renderNotice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$key    = self::NOTICE_PREFIX . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( $key );
		$type = in_array( $notice['type'] ?? '', array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( (string) $notice['message'] ) );
	}

	/**
	 * Add a direct Settings link to the Plugins screen.
	 *
	 * @param array<int,string> $links Existing links.
	 * @return array<int,string>
	 */
	public function actionLinks( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( $this->pageUrl() ), esc_html__( 'Settings', 'vyompress-boost' ) ) );

		return $links;
	}

	/**
	 * Register local and integration configuration checks in Site Health.
	 *
	 * @param array<string,mixed> $tests Existing tests.
	 * @return array<string,mixed>
	 */
	public function siteHealthTests( array $tests ): array {
		$tests['direct']['vyompress_boost_cache_storage'] = array(
			'label' => __( 'VyomPress Boost cache storage', 'vyompress-boost' ),
			'test'  => array( $this, 'cacheHealthResult' ),
		);
		$tests['direct']['vyompress_boost_cloudflare']    = array(
			'label' => __( 'VyomPress Boost Cloudflare setup', 'vyompress-boost' ),
			'test'  => array( $this, 'cloudflareHealthResult' ),
		);
		$tests['direct']['vyompress_boost_s3']            = array(
			'label' => __( 'VyomPress Boost media storage setup', 'vyompress-boost' ),
			'test'  => array( $this, 's3HealthResult' ),
		);

		return $tests;
	}

	/**
	 * Return the cache-storage Site Health result.
	 *
	 * @return array<string,mixed>
	 */
	public function cacheHealthResult(): array {
		$ready = $this->store->isWritable();

		return $this->healthResult( 'vyompress_boost_cache_storage', $ready, __( 'VyomPress Boost can write cached pages', 'vyompress-boost' ), __( 'VyomPress Boost cannot write cached pages', 'vyompress-boost' ), __( 'The page-cache directory is available.', 'vyompress-boost' ), __( 'Check filesystem ownership and permissions for wp-content/cache.', 'vyompress-boost' ) );
	}

	/**
	 * Return the Cloudflare configuration Site Health result.
	 *
	 * @return array<string,mixed>
	 */
	public function cloudflareHealthResult(): array {
		$enabled = (bool) $this->settings->get( 'cloudflare_enabled' );
		$ready   = ! $enabled || $this->cloudflare->isConfigured();

		return $this->healthResult( 'vyompress_boost_cloudflare', $ready, __( 'Cloudflare configuration is complete', 'vyompress-boost' ), __( 'Cloudflare configuration is incomplete', 'vyompress-boost' ), $enabled ? __( 'Automatic edge-cache purging is configured.', 'vyompress-boost' ) : __( 'Cloudflare integration is optional and currently disabled.', 'vyompress-boost' ), __( 'Add both the zone ID and scoped API token, or disable the integration.', 'vyompress-boost' ) );
	}

	/**
	 * Return the S3-compatible storage Site Health result.
	 *
	 * @return array<string,mixed>
	 */
	public function s3HealthResult(): array {
		$enabled = (bool) $this->settings->get( 's3_enabled' );
		$ready   = ! $enabled || $this->s3->isConfigured();

		return $this->healthResult( 'vyompress_boost_s3', $ready, __( 'Media storage configuration is complete', 'vyompress-boost' ), __( 'Media storage configuration is incomplete', 'vyompress-boost' ), $enabled ? __( 'New uploads can be sent to the configured provider.', 'vyompress-boost' ) : __( 'Media offloading is optional and currently disabled.', 'vyompress-boost' ), __( 'Complete the endpoint, bucket, region, and access credentials, or disable offloading.', 'vyompress-boost' ) );
	}

	/**
	 * Build a consistent Site Health response.
	 *
	 * @param string $test             Test identifier.
	 * @param bool   $ready            Whether the test passed.
	 * @param string $good_label       Passing label.
	 * @param string $bad_label        Failing label.
	 * @param string $good_description Passing description.
	 * @param string $bad_description  Failing description.
	 * @return array<string,mixed>
	 */
	private function healthResult( string $test, bool $ready, string $good_label, string $bad_label, string $good_description, string $bad_description ): array {
		return array(
			'label'       => $ready ? $good_label : $bad_label,
			'status'      => $ready ? 'good' : 'critical',
			'badge'       => array(
				'label' => __( 'Performance', 'vyompress-boost' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $ready ? $good_description : $bad_description ) . '</p>',
			'actions'     => sprintf( '<p><a href="%s">%s</a></p>', esc_url( $this->pageUrl() ), esc_html__( 'Review VyomPress Boost settings', 'vyompress-boost' ) ),
			'test'        => $test,
		);
	}

	/**
	 * Return the selected tab from a read-only query argument.
	 */
	private function currentTab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Selects view state only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';

		return in_array( $tab, array( 'overview', 'cache', 'cloudflare', 'media', 'optimizations' ), true ) ? $tab : 'overview';
	}

	/**
	 * Build an internal settings-page URL.
	 *
	 * @param string $tab Destination tab.
	 */
	private function pageUrl( string $tab = 'overview' ): string {
		return add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'tab'  => $tab,
			),
			admin_url( 'options-general.php' )
		);
	}
}
