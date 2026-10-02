<?php
/**
 * Safe opt-in front-end optimizations.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost\Optimization;

use VyomPress\Boost\Settings;

/**
 * Removes optional WordPress assets and reduces admin heartbeat frequency.
 */
final class AssetOptimizer {
	/**
	 * Create the asset optimizer.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Register enabled optimization modules.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'configure' ) );

		if ( $this->settings->get( 'reduce_heartbeat' ) ) {
			add_filter( 'heartbeat_settings', array( $this, 'reduceHeartbeat' ) );
		}
	}

	/**
	 * Apply safe asset-removal hooks after WordPress has registered defaults.
	 */
	public function configure(): void {
		if ( $this->settings->get( 'disable_emojis' ) ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_action( 'admin_print_styles', 'print_emoji_styles' );
			remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
			remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
			remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
			add_filter( 'tiny_mce_plugins', array( $this, 'removeEmojiEditorPlugin' ) );
			add_filter( 'wp_resource_hints', array( $this, 'removeEmojiDnsPrefetch' ), 10, 2 );
		}

		if ( $this->settings->get( 'disable_embeds' ) ) {
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
			add_action( 'wp_enqueue_scripts', array( $this, 'removeEmbedScript' ), 100 );
		}
	}

	/**
	 * Remove the TinyMCE emoji plugin.
	 *
	 * @param mixed $plugins TinyMCE plugin list.
	 * @return mixed
	 */
	public function removeEmojiEditorPlugin( mixed $plugins ): mixed {
		return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : $plugins;
	}

	/**
	 * Remove the emoji CDN DNS hint without disturbing other hints.
	 *
	 * @param array<int, string|array<string, string>> $urls Hint URLs.
	 * @param string                                   $relation_type Hint relation.
	 * @return array<int, string|array<string, string>>
	 */
	public function removeEmojiDnsPrefetch( array $urls, string $relation_type ): array {
		if ( 'dns-prefetch' !== $relation_type ) {
			return $urls;
		}

		return array_values(
			array_filter(
				$urls,
				static function ( string|array $url ): bool {
					$value = is_array( $url ) ? ( $url['href'] ?? '' ) : $url;

					return ! str_contains( (string) $value, 's.w.org/images/core/emoji/' );
				}
			)
		);
	}

	/**
	 * Remove WordPress' front-end embed helper.
	 */
	public function removeEmbedScript(): void {
		wp_dequeue_script( 'wp-embed' );
	}

	/**
	 * Cap Heartbeat API polling at once per minute.
	 *
	 * @param array<string, mixed> $settings Heartbeat settings.
	 * @return array<string, mixed>
	 */
	public function reduceHeartbeat( array $settings ): array {
		$settings['interval'] = 60;

		return $settings;
	}
}
