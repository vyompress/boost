<?php
/**
 * WordPress-native modern image generation.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost\Optimization;

use VyomPress\Boost\Settings;

/**
 * Selects a modern output format for newly generated image sub-sizes.
 */
final class ImageOptimizer {
	/**
	 * Create the image optimizer.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Register image editor filters when a format is selected.
	 */
	public function register(): void {
		if ( 'off' === $this->format() ) {
			return;
		}

		add_filter( 'image_editor_output_format', array( $this, 'filterOutputFormat' ) );
		add_filter( 'wp_editor_set_quality', array( $this, 'filterQuality' ), 10, 2 );
	}

	/**
	 * Convert JPEG and non-transparent PNG sub-sizes to the selected format.
	 *
	 * @param array<string,string> $formats Source-to-output MIME mapping.
	 * @return array<string,string>
	 */
	public function filterOutputFormat( array $formats ): array {
		$mime = 'image/' . $this->format();
		if ( ! $this->isSupported() ) {
			return $formats;
		}

		$formats['image/jpeg'] = $mime;
		$formats['image/png']  = $mime;

		return $formats;
	}

	/**
	 * Apply the selected quality only to the generated modern format.
	 *
	 * @param int    $quality   Core-selected quality.
	 * @param string $mime_type Output MIME type.
	 */
	public function filterQuality( int $quality, string $mime_type ): int {
		return 'image/' . $this->format() === $mime_type ? (int) $this->settings->get( 'modern_image_quality' ) : $quality;
	}

	/**
	 * Whether the active WordPress image editor supports the selected format.
	 */
	public function isSupported(): bool {
		return 'off' === $this->format() || wp_image_editor_supports( array( 'mime_type' => 'image/' . $this->format() ) );
	}

	/**
	 * Return the validated selected format.
	 */
	private function format(): string {
		$format = (string) $this->settings->get( 'modern_image_format' );

		return in_array( $format, array( 'webp', 'avif' ), true ) ? $format : 'off';
	}
}
