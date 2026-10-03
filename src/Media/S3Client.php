<?php
/**
 * Provider-neutral S3 object client.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost\Media;

use VyomPress\Boost\Settings;
use WP_Error;

/**
 * Uploads and removes objects using the common S3 REST protocol.
 */
final class S3Client {
	/**
	 * Create the S3-compatible client.
	 *
	 * @param Settings $settings Plugin settings.
	 * @param S3Signer $signer   Signature Version 4 signer.
	 */
	public function __construct( private Settings $settings, private S3Signer $signer ) {
	}

	/**
	 * Whether all required connection fields are present.
	 */
	public function isConfigured(): bool {
		return '' !== (string) $this->settings->get( 's3_endpoint' )
			&& '' !== (string) $this->settings->get( 's3_bucket' )
			&& '' !== $this->settings->credential( 's3_access_key', 'VYOMPRESS_BOOST_S3_ACCESS_KEY' )
			&& '' !== $this->settings->credential( 's3_secret_key', 'VYOMPRESS_BOOST_S3_SECRET_KEY' );
	}

	/**
	 * Upload one local file.
	 *
	 * @param string $object_key  Destination object key.
	 * @param string $file_path   Absolute local file path.
	 * @param string $content_type Media MIME type.
	 * @return true|WP_Error
	 */
	public function putFile( string $object_key, string $file_path, string $content_type ): true|WP_Error {
		if ( ! is_readable( $file_path ) ) {
			return new WP_Error( 'vyompress_s3_unreadable_file', __( 'A media file could not be read for offloading.', 'vyompress-boost' ) );
		}

		$body = file_get_contents( $file_path );
		if ( false === $body ) {
			return new WP_Error( 'vyompress_s3_read_failed', __( 'A media file could not be loaded for offloading.', 'vyompress-boost' ) );
		}

		return $this->request(
			'PUT',
			$object_key,
			$body,
			array(
				'Content-Type'  => $content_type,
				'Cache-Control' => (string) $this->settings->get( 's3_cache_control' ),
			)
		);
	}

	/**
	 * Delete one remote object.
	 *
	 * @param string $object_key Object key to delete.
	 * @return true|WP_Error
	 */
	public function deleteObject( string $object_key ): true|WP_Error {
		return $this->request( 'DELETE', $object_key, '', array() );
	}

	/**
	 * Verify write and delete permissions using a short-lived probe object.
	 *
	 * @return true|WP_Error
	 */
	public function testConnection(): true|WP_Error {
		$key    = '.vyompress-boost-test-' . wp_generate_uuid4() . '.txt';
		$result = $this->request( 'PUT', $key, 'VyomPress Boost connection test.', array( 'Content-Type' => 'text/plain' ) );

		if ( true !== $result ) {
			return $result;
		}

		return $this->deleteObject( $key );
	}

	/**
	 * Return the public URL for an uploaded object.
	 *
	 * @param string $object_key Uploaded object key.
	 */
	public function publicUrl( string $object_key ): string {
		$public_base = (string) $this->settings->get( 's3_public_url' );
		if ( '' !== $public_base ) {
			return untrailingslashit( $public_base ) . '/' . $this->encodeKey( $object_key );
		}

		return $this->objectUrl( $object_key );
	}

	/**
	 * Execute a signed object request.
	 *
	 * @param string               $method     HTTP method.
	 * @param string               $object_key Destination object key.
	 * @param string               $body       Request body.
	 * @param array<string,string> $headers Request headers.
	 * @return true|WP_Error
	 */
	private function request( string $method, string $object_key, string $body, array $headers ): true|WP_Error {
		if ( ! $this->isConfigured() ) {
			return new WP_Error( 'vyompress_s3_missing_credentials', __( 'Complete the storage connection settings first.', 'vyompress-boost' ) );
		}

		$url           = $this->objectUrl( $object_key );
		$allow_private = (bool) apply_filters( 'vyompress_boost_allow_private_s3_endpoint', false, $url );
		if ( ! $this->isAllowedEndpoint( $url, $allow_private ) ) {
			return new WP_Error( 'vyompress_s3_unsafe_endpoint', __( 'The storage endpoint must use HTTPS and resolve to a public host.', 'vyompress-boost' ) );
		}

		$headers = $this->signer->sign(
			$method,
			$url,
			$headers,
			hash( 'sha256', $body ),
			$this->settings->credential( 's3_access_key', 'VYOMPRESS_BOOST_S3_ACCESS_KEY' ),
			$this->settings->credential( 's3_secret_key', 'VYOMPRESS_BOOST_S3_SECRET_KEY' ),
			(string) $this->settings->get( 's3_region' )
		);

		$args = array(
			'method'      => $method,
			'timeout'     => 60,
			'redirection' => 0,
			'headers'     => $headers,
			'body'        => $body,
		);

		$response = $allow_private ? wp_remote_request( $url, $args ) : wp_safe_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			return true;
		}

		$message = wp_remote_retrieve_response_message( $response );
		$message = '' !== $message ? $message : __( 'The storage provider rejected the request.', 'vyompress-boost' );

		return new WP_Error( 'vyompress_s3_api_error', sanitize_text_field( $message ), array( 'status' => $status ) );
	}

	/**
	 * Build a path-style or virtual-host-style object URL.
	 *
	 * @param string $object_key Destination object key.
	 */
	private function objectUrl( string $object_key ): string {
		$endpoint = untrailingslashit( (string) $this->settings->get( 's3_endpoint' ) );
		$bucket   = (string) $this->settings->get( 's3_bucket' );
		$key      = $this->encodeKey( $object_key );

		if ( $this->settings->get( 's3_path_style' ) ) {
			return $endpoint . '/' . rawurlencode( $bucket ) . '/' . $key;
		}

		$parts = wp_parse_url( $endpoint );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path = isset( $parts['path'] ) ? '/' . trim( (string) $parts['path'], '/' ) : '';

		return $parts['scheme'] . '://' . $bucket . '.' . $parts['host'] . $port . $path . '/' . $key;
	}

	/**
	 * Encode every key segment without encoding path separators.
	 *
	 * @param string $object_key Unencoded object key.
	 */
	private function encodeKey( string $object_key ): string {
		$segments = array_map( 'rawurlencode', explode( '/', ltrim( $object_key, '/' ) ) );

		return implode( '/', $segments );
	}

	/**
	 * Enforce safe remote endpoints by default.
	 *
	 * @param string $url           Request URL.
	 * @param bool   $allow_private Whether the site owner explicitly allowed a private host.
	 */
	private function isAllowedEndpoint( string $url, bool $allow_private ): bool {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && 'local' === wp_get_environment_type() ) ) {
			return false;
		}

		if ( $allow_private ) {
			return '' !== (string) wp_parse_url( $url, PHP_URL_HOST );
		}

		return false !== wp_http_validate_url( $url );
	}
}
