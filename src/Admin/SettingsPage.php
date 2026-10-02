<?php
/**
 * WordPress administration screen.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost\Admin;

use VyomPress\Boost\Cache\CacheStore;
use VyomPress\Boost\Settings;

/**
 * Registers settings, status, cache controls, and plugin action links.
 */
final class SettingsPage {
	private const PAGE_SLUG = 'vyompress-boost';

	/**
	 * Create the administration screen.
	 *
	 * @param Settings   $settings Plugin settings.
	 * @param CacheStore $store    Cache storage.
	 */
	public function __construct( private Settings $settings, private CacheStore $store ) {
	}

	/**
	 * Register administration hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_init', array( $this, 'registerSettings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
		add_action( 'admin_post_vyompress_boost_purge', array( $this, 'handlePurge' ) );
		add_action( 'admin_notices', array( $this, 'purgeNotice' ) );
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
	 * Register validated settings and fields.
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

		add_settings_section( 'vyompress_boost_cache', __( 'Page cache', 'vyompress-boost' ), array( $this, 'cacheSection' ), self::PAGE_SLUG );
		add_settings_field(
			'page_cache',
			__( 'Enable page cache', 'vyompress-boost' ),
			array( $this, 'checkboxField' ),
			self::PAGE_SLUG,
			'vyompress_boost_cache',
			array(
				'key'         => 'page_cache',
				'description' => __( 'Cache complete pages for anonymous visitors.', 'vyompress-boost' ),
			)
		);
		add_settings_field( 'cache_ttl', __( 'Cache lifetime', 'vyompress-boost' ), array( $this, 'ttlField' ), self::PAGE_SLUG, 'vyompress_boost_cache' );
		add_settings_field(
			'browser_cache',
			__( 'Browser cache', 'vyompress-boost' ),
			array( $this, 'checkboxField' ),
			self::PAGE_SLUG,
			'vyompress_boost_cache',
			array(
				'key'         => 'browser_cache',
				'description' => __( 'Let browsers reuse validated cache hits until the configured lifetime expires.', 'vyompress-boost' ),
			)
		);

		add_settings_section( 'vyompress_boost_assets', __( 'WordPress optimizations', 'vyompress-boost' ), array( $this, 'assetSection' ), self::PAGE_SLUG );
		add_settings_field(
			'disable_emojis',
			__( 'Emoji assets', 'vyompress-boost' ),
			array( $this, 'checkboxField' ),
			self::PAGE_SLUG,
			'vyompress_boost_assets',
			array(
				'key'         => 'disable_emojis',
				'description' => __( 'Remove WordPress emoji scripts and styles.', 'vyompress-boost' ),
			)
		);
		add_settings_field(
			'disable_embeds',
			__( 'Embed script', 'vyompress-boost' ),
			array( $this, 'checkboxField' ),
			self::PAGE_SLUG,
			'vyompress_boost_assets',
			array(
				'key'         => 'disable_embeds',
				'description' => __( 'Remove the front-end embed discovery links and helper script.', 'vyompress-boost' ),
			)
		);
		add_settings_field(
			'reduce_heartbeat',
			__( 'Heartbeat frequency', 'vyompress-boost' ),
			array( $this, 'checkboxField' ),
			self::PAGE_SLUG,
			'vyompress_boost_assets',
			array(
				'key'         => 'reduce_heartbeat',
				'description' => __( 'Limit WordPress Heartbeat requests to once per minute.', 'vyompress-boost' ),
			)
		);
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
	 * Render the settings and operational status screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap vyompress-boost">
			<h1><?php echo esc_html__( 'VyomPress Boost', 'vyompress-boost' ); ?></h1>
			<p class="description"><?php echo esc_html__( 'Fast defaults, conservative caching, and no external tracking.', 'vyompress-boost' ); ?></p>

			<div class="vyompress-boost__status" aria-label="<?php echo esc_attr__( 'Cache status', 'vyompress-boost' ); ?>">
				<div><strong><?php echo esc_html__( 'Page cache', 'vyompress-boost' ); ?></strong><span><?php echo $this->settings->get( 'page_cache' ) ? esc_html__( 'Enabled', 'vyompress-boost' ) : esc_html__( 'Disabled', 'vyompress-boost' ); ?></span></div>
				<div><strong><?php echo esc_html__( 'Cache storage', 'vyompress-boost' ); ?></strong><span><?php echo $this->store->isWritable() ? esc_html__( 'Writable', 'vyompress-boost' ) : esc_html__( 'Unavailable', 'vyompress-boost' ); ?></span></div>
				<div><strong><?php echo esc_html__( 'Cached pages', 'vyompress-boost' ); ?></strong><span><?php echo esc_html( number_format_i18n( $this->store->count() ) ); ?></span></div>
			</div>

			<form action="options.php" method="post">
				<?php
				settings_fields( 'vyompress_boost' );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<hr>
			<h2><?php echo esc_html__( 'Cache maintenance', 'vyompress-boost' ); ?></h2>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="vyompress_boost_purge">
				<?php wp_nonce_field( 'vyompress_boost_purge' ); ?>
				<?php submit_button( __( 'Purge page cache', 'vyompress-boost' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Describe page-cache behavior.
	 */
	public function cacheSection(): void {
		echo '<p>' . esc_html__( 'Only anonymous, successful HTML responses without query strings or session cookies are cached.', 'vyompress-boost' ) . '</p>';
	}

	/**
	 * Describe independently configurable optimization modules.
	 */
	public function assetSection(): void {
		echo '<p>' . esc_html__( 'Each optimization can be enabled independently and reverted immediately.', 'vyompress-boost' ) . '</p>';
	}

	/**
	 * Render a checkbox settings field.
	 *
	 * @param array{key: string, description: string} $arguments Field configuration.
	 */
	public function checkboxField( array $arguments ): void {
		$key = $arguments['key'];
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" value="1" <?php checked( (bool) $this->settings->get( $key ) ); ?>>
			<?php echo esc_html( $arguments['description'] ); ?>
		</label>
		<?php
	}

	/**
	 * Render the cache lifetime input.
	 */
	public function ttlField(): void {
		?>
		<input class="small-text" type="number" min="60" max="86400" step="60" name="<?php echo esc_attr( Settings::OPTION . '[cache_ttl]' ); ?>" value="<?php echo esc_attr( (string) $this->settings->get( 'cache_ttl' ) ); ?>">
		<span><?php echo esc_html__( 'seconds (60–86,400)', 'vyompress-boost' ); ?></span>
		<?php
	}

	/**
	 * Process the nonce- and capability-protected manual purge action.
	 */
	public function handlePurge(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to purge this cache.', 'vyompress-boost' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'vyompress_boost_purge' );
		$removed = $this->store->clear();
		$target  = add_query_arg(
			array(
				'page'             => self::PAGE_SLUG,
				'vyompress_purged' => $removed,
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Confirm a completed cache purge on the plugin screen.
	 */
	public function purgeNotice(): void {
		// This query string only selects a sanitized notice; it does not mutate state.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'], $_GET['vyompress_purged'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		$removed = absint( wp_unslash( $_GET['vyompress_purged'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$message = sprintf(
			/* translators: %s: number of deleted cache entries. */
			_n( '%s cached page removed.', '%s cached pages removed.', $removed, 'vyompress-boost' ),
			number_format_i18n( $removed )
		);

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * Add a direct Settings link to the Plugins screen.
	 *
	 * @param array<int, string> $links Existing links.
	 * @return array<int, string>
	 */
	public function actionLinks( array $links ): array {
		$settings = sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ), esc_html__( 'Settings', 'vyompress-boost' ) );

		array_unshift( $links, $settings );

		return $links;
	}

	/**
	 * Register a direct Site Health test for cache storage.
	 *
	 * @param array<string, mixed> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public function siteHealthTests( array $tests ): array {
		$tests['direct']['vyompress_boost_cache_storage'] = array(
			'label' => __( 'VyomPress Boost cache storage', 'vyompress-boost' ),
			'test'  => array( $this, 'cacheHealthResult' ),
		);

		return $tests;
	}

	/**
	 * Return the Site Health cache-storage result.
	 *
	 * @return array<string, mixed>
	 */
	public function cacheHealthResult(): array {
		$writable = $this->store->isWritable();

		return array(
			'label'       => $writable ? __( 'VyomPress Boost can write cached pages', 'vyompress-boost' ) : __( 'VyomPress Boost cannot write cached pages', 'vyompress-boost' ),
			'status'      => $writable ? 'good' : 'critical',
			'badge'       => array(
				'label' => __( 'Performance', 'vyompress-boost' ),
				'color' => 'blue',
			),
			'description' => sprintf( '<p>%s</p>', esc_html( $writable ? __( 'The page-cache directory is available.', 'vyompress-boost' ) : __( 'Check filesystem ownership and permissions for wp-content/cache.', 'vyompress-boost' ) ) ),
			'test'        => 'vyompress_boost_cache_storage',
		);
	}
}
