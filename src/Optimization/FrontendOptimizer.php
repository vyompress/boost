<?php
/**
 * Core Web Vitals front-end optimizations.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost\Optimization;

use VyomPress\Boost\Settings;
use WP_HTML_Tag_Processor;

/**
 * Applies explicit, independently reversible browser optimizations.
 */
final class FrontendOptimizer {
	/**
	 * Whether an above-the-fold image has already been prioritized.
	 *
	 * @var bool
	 */
	private bool $priority_image_selected = false;

	/**
	 * Create the optimizer.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Register enabled optimization hooks.
	 */
	public function register(): void {
		if ( $this->settings->get( 'defer_javascript' ) || $this->settings->get( 'delay_javascript' ) ) {
			add_filter( 'script_loader_tag', array( $this, 'filterScriptTag' ), 20, 3 );
		}

		if ( $this->settings->get( 'delay_javascript' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueueDelayLoader' ), 100 );
		}

		if ( $this->settings->get( 'lcp_image_priority' ) ) {
			add_filter( 'wp_get_attachment_image_attributes', array( $this, 'prioritizeFirstImage' ), 20 );
		}

		add_filter( 'wp_lazy_loading_enabled', array( $this, 'filterLazyLoading' ), 10, 3 );
		add_filter( 'wp_speculation_rules_configuration', array( $this, 'filterSpeculationConfiguration' ) );
		add_filter( 'wp_speculation_rules_href_exclude_paths', array( $this, 'filterSpeculationExclusions' ) );
		add_filter( 'wp_resource_hints', array( $this, 'filterResourceHints' ), 10, 2 );

		if ( $this->settings->get( 'disable_guest_dashicons' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'removeGuestDashicons' ), 100 );
		}

		if ( $this->settings->get( 'remove_jquery_migrate' ) ) {
			add_action( 'wp_default_scripts', array( $this, 'removeJqueryMigrate' ) );
		}
	}

	/**
	 * Add defer or convert explicitly matched third-party scripts into delayed scripts.
	 *
	 * @param string $tag    Complete script tag.
	 * @param string $handle Registered WordPress script handle.
	 * @param string $src    Script source URL.
	 */
	public function filterScriptTag( string $tag, string $handle, string $src ): string {
		if ( is_admin() || '' === $src || $this->matchesAny( $handle . ' ' . $src, (string) $this->settings->get( 'defer_javascript_exclusions' ) ) ) {
			return $tag;
		}

		$processor = new WP_HTML_Tag_Processor( $tag );
		if ( ! $processor->next_tag( 'script' ) ) {
			return $tag;
		}

		if ( $this->settings->get( 'delay_javascript' ) && $this->matchesAny( $src, (string) $this->settings->get( 'delay_javascript_includes' ) ) ) {
			$processor->set_attribute( 'type', 'text/vyompress-delayed' );
			$processor->set_attribute( 'data-vyompress-src', $src );
			$processor->remove_attribute( 'src' );

			return $processor->get_updated_html();
		}

		if ( $this->settings->get( 'defer_javascript' ) && null === $processor->get_attribute( 'async' ) && 'module' !== $processor->get_attribute( 'type' ) ) {
			$processor->set_attribute( 'defer', '' );
		}

		return $processor->get_updated_html();
	}

	/**
	 * Load the tiny delayed-script activator only when the feature is enabled.
	 */
	public function enqueueDelayLoader(): void {
		wp_enqueue_script( 'vyompress-boost-delay', VYOMPRESS_BOOST_URL . 'assets/frontend.js', array(), VYOMPRESS_BOOST_VERSION, true );
		wp_localize_script(
			'vyompress-boost-delay',
			'vyompressBoostDelay',
			array( 'timeout' => (int) $this->settings->get( 'delay_javascript_timeout' ) )
		);
	}

	/**
	 * Prioritize only the first WordPress attachment image in the document.
	 *
	 * @param array<string,string> $attributes Image attributes.
	 * @return array<string,string>
	 */
	public function prioritizeFirstImage( array $attributes ): array {
		if ( $this->priority_image_selected || is_admin() || is_feed() ) {
			return $attributes;
		}

		$this->priority_image_selected    = true;
		$attributes['fetchpriority']      = 'high';
		$attributes['data-vyompress-lcp'] = '1';
		unset( $attributes['loading'] );

		return $attributes;
	}

	/**
	 * Respect independent image and iframe lazy-loading toggles.
	 *
	 * @param bool   $enabled Default decision.
	 * @param string $tag_name Element name.
	 * @param string $context  WordPress rendering context.
	 */
	public function filterLazyLoading( bool $enabled, string $tag_name, string $context ): bool {
		unset( $context );

		if ( 'img' === $tag_name ) {
			return (bool) $this->settings->get( 'lazy_load_images' );
		}

		if ( 'iframe' === $tag_name ) {
			return (bool) $this->settings->get( 'lazy_load_iframes' );
		}

		return $enabled;
	}

	/**
	 * Configure the WordPress 6.8+ native Speculation Rules API.
	 *
	 * @param array<string,string>|null $configuration Core configuration.
	 * @return array<string,string>|null
	 */
	public function filterSpeculationConfiguration( ?array $configuration ): ?array {
		if ( null === $configuration ) {
			return null;
		}

		$mode      = (string) $this->settings->get( 'speculation_mode' );
		$eagerness = (string) $this->settings->get( 'speculation_eagerness' );
		if ( 'off' === $mode ) {
			return null;
		}

		$configuration['mode']      = $mode;
		$configuration['eagerness'] = $eagerness;

		return $configuration;
	}

	/**
	 * Reuse cache exclusions for speculative navigation safety.
	 *
	 * @param array<string> $paths Existing URL patterns.
	 * @return array<string>
	 */
	public function filterSpeculationExclusions( array $paths ): array {
		foreach ( $this->lines( (string) $this->settings->get( 'excluded_paths' ) ) as $path ) {
			$paths[] = trailingslashit( $path ) . '*';
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Add administrator-selected preconnect origins.
	 *
	 * @param array<int,string|array<string,string>> $urls          Existing hints.
	 * @param string                                 $relation_type Hint relation.
	 * @return array<int,string|array<string,string>>
	 */
	public function filterResourceHints( array $urls, string $relation_type ): array {
		if ( 'preconnect' !== $relation_type ) {
			return $urls;
		}

		foreach ( $this->lines( (string) $this->settings->get( 'preconnect_origins' ) ) as $origin ) {
			$urls[] = array(
				'href'        => $origin,
				'crossorigin' => 'anonymous',
			);
		}

		return $urls;
	}

	/**
	 * Avoid front-end Dashicons for visitors who cannot use the admin toolbar.
	 */
	public function removeGuestDashicons(): void {
		if ( ! is_user_logged_in() ) {
			wp_dequeue_style( 'dashicons' );
		}
	}

	/**
	 * Remove the jQuery Migrate dependency on the public site.
	 *
	 * @param \WP_Scripts $scripts WordPress script registry.
	 */
	public function removeJqueryMigrate( \WP_Scripts $scripts ): void {
		if ( is_admin() || ! isset( $scripts->registered['jquery'] ) ) {
			return;
		}

		$jquery       = $scripts->registered['jquery'];
		$jquery->deps = array_values( array_diff( $jquery->deps, array( 'jquery-migrate' ) ) );
	}

	/**
	 * Whether a value contains any newline-delimited token.
	 *
	 * @param string $value  Searchable value.
	 * @param string $tokens Newline-delimited tokens.
	 */
	private function matchesAny( string $value, string $tokens ): bool {
		foreach ( $this->lines( $tokens ) as $token ) {
			if ( str_contains( $value, $token ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert newline-delimited settings into a compact list.
	 *
	 * @param string $value Newline-delimited value.
	 * @return list<string>
	 */
	private function lines( string $value ): array {
		$lines = preg_split( '/\R/', $value );

		return array_values( array_filter( array_map( 'trim', false === $lines ? array() : $lines ) ) );
	}
}
