<?php
/**
 * Per-content cache and optimization controls.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost\Admin;

/**
 * Adds simple editor-level escape hatches for sensitive pages.
 */
final class ContentControls {
	public const NO_CACHE_META    = '_vyompress_boost_no_cache';
	public const NO_OPTIMIZE_META = '_vyompress_boost_no_optimize';

	/**
	 * Register editor and front-end policy hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'addMetaBox' ) );
		add_action( 'save_post', array( $this, 'save' ) );
		add_filter( 'vyompress_boost_is_cacheable_request', array( $this, 'filterCacheable' ) );
	}

	/**
	 * Add controls to public post types.
	 */
	public function addMetaBox(): void {
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			add_meta_box(
				'vyompress-boost-controls',
				__( 'VyomPress Boost', 'vyompress-boost' ),
				array( $this, 'renderMetaBox' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render two plain-language per-content controls.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function renderMetaBox( \WP_Post $post ): void {
		wp_nonce_field( 'vyompress_boost_content_controls', 'vyompress_boost_content_nonce' );
		?>
		<p><label><input type="checkbox" name="vyompress_boost_no_cache" value="1" <?php checked( '1', get_post_meta( $post->ID, self::NO_CACHE_META, true ) ); ?>> <?php echo esc_html__( 'Never cache this page', 'vyompress-boost' ); ?></label></p>
		<p><label><input type="checkbox" name="vyompress_boost_no_optimize" value="1" <?php checked( '1', get_post_meta( $post->ID, self::NO_OPTIMIZE_META, true ) ); ?>> <?php echo esc_html__( 'Skip front-end optimizations', 'vyompress-boost' ); ?></label></p>
		<p class="description"><?php echo esc_html__( 'Useful for interactive, personalized, or compatibility-sensitive pages.', 'vyompress-boost' ); ?></p>
		<?php
	}

	/**
	 * Save controls after normal WordPress capability and nonce checks.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is compared as an opaque token.
		$nonce = isset( $_POST['vyompress_boost_content_nonce'] ) ? wp_unslash( $_POST['vyompress_boost_content_nonce'] ) : '';
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'vyompress_boost_content_controls' ) ) {
			return;
		}

		$this->saveBooleanMeta( $post_id, self::NO_CACHE_META, isset( $_POST['vyompress_boost_no_cache'] ) );
		$this->saveBooleanMeta( $post_id, self::NO_OPTIMIZE_META, isset( $_POST['vyompress_boost_no_optimize'] ) );
	}

	/**
	 * Bypass page caching for content explicitly excluded in the editor.
	 *
	 * @param bool $cacheable Existing policy result.
	 */
	public function filterCacheable( bool $cacheable ): bool {
		return $cacheable && ! self::currentPostHas( self::NO_CACHE_META );
	}

	/**
	 * Whether front-end optimization is disabled for the queried item.
	 */
	public static function optimizationsDisabled(): bool {
		return self::currentPostHas( self::NO_OPTIMIZE_META );
	}

	/**
	 * Check a boolean meta flag on the current singular item.
	 *
	 * @param string $key Meta key.
	 */
	private static function currentPostHas( string $key ): bool {
		return is_singular() && '1' === get_post_meta( get_queried_object_id(), $key, true );
	}

	/**
	 * Store or remove a boolean meta flag.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param bool   $enabled Submitted state.
	 */
	private function saveBooleanMeta( int $post_id, string $key, bool $enabled ): void {
		if ( $enabled ) {
			update_post_meta( $post_id, $key, '1' );
		} else {
			delete_post_meta( $post_id, $key );
		}
	}
}
