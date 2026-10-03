<?php
/**
 * WordPress media offloading integration.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost\Media;

use VyomPress\Boost\Settings;
use WP_Error;

/**
 * Copies attachment originals and generated sizes to S3-compatible storage.
 */
final class MediaOffloader {
	public const META_KEY        = '_vyompress_boost_offload';
	private const ERROR_META_KEY = '_vyompress_boost_offload_error';

	/**
	 * Create the media offloader.
	 *
	 * @param Settings $settings Plugin settings.
	 * @param S3Client $client   S3-compatible object client.
	 */
	public function __construct( private Settings $settings, private S3Client $client ) {
	}

	/**
	 * Register upload, URL rewriting, and cleanup hooks.
	 */
	public function register(): void {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'offloadAttachment' ), 20, 3 );
		add_filter( 'wp_get_attachment_url', array( $this, 'filterAttachmentUrl' ), 20, 2 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'filterImageSrcset' ), 20, 5 );
		add_action( 'delete_attachment', array( $this, 'deleteRemoteAttachment' ) );
	}

	/**
	 * Whether the feature is enabled and fully configured.
	 */
	public function isReady(): bool {
		return (bool) $this->settings->get( 's3_enabled' ) && $this->client->isConfigured();
	}

	/**
	 * Offload an existing Media Library item and report the result.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return true|WP_Error
	 */
	public function offloadExisting( int $attachment_id ): bool|WP_Error {
		if ( ! $this->isReady() ) {
			return new WP_Error( 'vyompress_offload_not_ready', __( 'Enable and configure media offloading first.', 'vyompress-boost' ) );
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$this->offloadAttachment( $metadata, $attachment_id, 'update' );
		$error  = get_post_meta( $attachment_id, self::ERROR_META_KEY, true );
		$record = $this->record( $attachment_id );
		if ( '' === (string) $error && empty( $record['objects'] ) ) {
			return new WP_Error( 'vyompress_offload_no_files', __( 'No local attachment files were available to offload.', 'vyompress-boost' ) );
		}

		return '' === (string) $error ? true : new WP_Error( 'vyompress_offload_failed', (string) $error );
	}

	/**
	 * Return the validated offload record for one attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array{base_key?:string,objects?:list<string>,offloaded_at?:int}
	 */
	public function record( int $attachment_id ): array {
		$record = get_post_meta( $attachment_id, self::META_KEY, true );

		return is_array( $record ) ? $record : array();
	}

	/**
	 * Offload all generated files for an attachment after WordPress creates them.
	 *
	 * @param array<string,mixed> $metadata      Attachment metadata.
	 * @param int                 $attachment_id Attachment post ID.
	 * @param string              $context       Metadata generation context.
	 * @return array<string,mixed>
	 */
	public function offloadAttachment( array $metadata, int $attachment_id, string $context = 'create' ): array {
		if ( ! $this->isReady() || ! in_array( $context, array( 'create', 'update' ), true ) ) {
			return $metadata;
		}

		$files = $this->attachmentFiles( $attachment_id, $metadata );
		if ( array() === $files ) {
			return $metadata;
		}

		$uploaded = array();
		foreach ( $files as $file ) {
			$key    = $this->objectKeyForFile( $file );
			$type   = wp_check_filetype( $file );
			$mime   = ! empty( $type['type'] ) ? (string) $type['type'] : 'application/octet-stream';
			$result = '' === $key ? false : $this->client->putFile( $key, $file, $mime );

			if ( true !== $result ) {
				foreach ( $uploaded as $uploaded_key ) {
					$this->client->deleteObject( $uploaded_key );
				}

				$message = false === $result ? __( 'A media file was outside the WordPress uploads directory.', 'vyompress-boost' ) : $result->get_error_message();
				update_post_meta( $attachment_id, self::ERROR_META_KEY, sanitize_text_field( $message ) );

				return $metadata;
			}

			$uploaded[] = $key;
		}

		$previous = get_post_meta( $attachment_id, self::META_KEY, true );
		if ( is_array( $previous ) && isset( $previous['objects'] ) && is_array( $previous['objects'] ) ) {
			foreach ( array_diff( $previous['objects'], $uploaded ) as $stale_key ) {
				$this->client->deleteObject( (string) $stale_key );
			}
		}

		update_post_meta(
			$attachment_id,
			self::META_KEY,
			array(
				'base_key'     => $this->objectKeyForFile( (string) get_attached_file( $attachment_id, true ) ),
				'objects'      => array_values( $uploaded ),
				'offloaded_at' => time(),
			)
		);
		delete_post_meta( $attachment_id, self::ERROR_META_KEY );

		if ( ! $this->settings->get( 's3_keep_local' ) ) {
			foreach ( $files as $file ) {
				wp_delete_file( $file );
			}
		}

		return $metadata;
	}

	/**
	 * Replace the primary attachment URL when an offload record exists.
	 *
	 * @param string|false $url           Existing attachment URL.
	 * @param int          $attachment_id Attachment post ID.
	 * @return string|false
	 */
	public function filterAttachmentUrl( string|false $url, int $attachment_id ): string|false {
		$record = get_post_meta( $attachment_id, self::META_KEY, true );
		if ( ! is_array( $record ) || empty( $record['base_key'] ) ) {
			return $url;
		}

		return $this->client->publicUrl( (string) $record['base_key'] );
	}

	/**
	 * Rewrite generated image source candidates to their remote object URLs.
	 *
	 * @param array<int,array<string,mixed>>|false $sources       Source candidates.
	 * @param array<int,int>                       $size_array    Requested dimensions.
	 * @param string                               $image_src     Selected source URL.
	 * @param array<string,mixed>                  $image_meta    Attachment metadata.
	 * @param int                                  $attachment_id Attachment post ID.
	 * @return array<int,array<string,mixed>>|false
	 */
	public function filterImageSrcset( array|false $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id ): array|false {
		if ( false === $sources || ! is_array( get_post_meta( $attachment_id, self::META_KEY, true ) ) ) {
			return $sources;
		}

		$uploads = wp_get_upload_dir();
		$baseurl = trailingslashit( (string) $uploads['baseurl'] );

		foreach ( $sources as &$source ) {
			if ( isset( $source['url'] ) && str_starts_with( (string) $source['url'], $baseurl ) ) {
				$relative      = rawurldecode( substr( (string) $source['url'], strlen( $baseurl ) ) );
				$source['url'] = $this->client->publicUrl( $this->prefixedKey( $relative ) );
			}
		}
		unset( $source );

		return $sources;
	}

	/**
	 * Remove remote objects when WordPress permanently deletes an attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 */
	public function deleteRemoteAttachment( int $attachment_id ): void {
		$record = get_post_meta( $attachment_id, self::META_KEY, true );
		if ( ! is_array( $record ) || empty( $record['objects'] ) || ! is_array( $record['objects'] ) ) {
			return;
		}

		foreach ( $record['objects'] as $object_key ) {
			$this->client->deleteObject( (string) $object_key );
		}
	}

	/**
	 * Return every attachment-owned file that currently exists locally.
	 *
	 * @param int                 $attachment_id Attachment post ID.
	 * @param array<string,mixed> $metadata Attachment metadata.
	 * @return list<string>
	 */
	private function attachmentFiles( int $attachment_id, array $metadata ): array {
		$primary = (string) get_attached_file( $attachment_id, true );
		if ( '' === $primary ) {
			return array();
		}

		$directory = dirname( $primary );
		$files     = array( $primary );

		if ( ! empty( $metadata['original_image'] ) ) {
			$files[] = $directory . '/' . wp_basename( (string) $metadata['original_image'] );
		}

		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) ) {
					$files[] = $directory . '/' . wp_basename( (string) $size['file'] );
				}
			}
		}

		return array_values( array_unique( array_filter( $files, 'is_file' ) ) );
	}

	/**
	 * Convert one upload path into a bucket object key.
	 *
	 * @param string $file Absolute media file path.
	 */
	private function objectKeyForFile( string $file ): string {
		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) );
		$file    = wp_normalize_path( $file );

		if ( ! str_starts_with( $file, $base ) ) {
			return '';
		}

		return $this->prefixedKey( substr( $file, strlen( $base ) ) );
	}

	/**
	 * Apply the configured object prefix to a relative upload path.
	 *
	 * @param string $relative_path Path relative to the uploads directory.
	 */
	private function prefixedKey( string $relative_path ): string {
		$prefix = trim( (string) $this->settings->get( 's3_prefix' ), '/' );
		$path   = ltrim( str_replace( '\\', '/', $relative_path ), '/' );

		return '' === $prefix ? $path : $prefix . '/' . $path;
	}
}
