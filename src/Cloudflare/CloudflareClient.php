<?php
/**
 * Cloudflare API client.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost\Cloudflare;

use WP_Error;

/**
 * Performs the smallest possible authenticated Cloudflare cache request.
 */
final class CloudflareClient {
	private const API_BASE = 'https://api.cloudflare.com/client/v4';

	/**
	 * Purge all cached content for one zone.
	 *
	 * @param string $zone_id   Cloudflare zone identifier.
	 * @param string $api_token Scoped Cloudflare API token.
	 * @return true|WP_Error
	 */
	public function purgeEverything( string $zone_id, string $api_token ): bool|WP_Error {
		if ( '' === $zone_id || '' === $api_token ) {
			return new WP_Error( 'vyompress_cloudflare_missing_credentials', __( 'Add a zone ID and API token first.', 'vyompress-boost' ) );
		}

		$response = wp_remote_post(
			self::API_BASE . '/zones/' . rawurlencode( $zone_id ) . '/purge_cache',
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $api_token,
					'Content-Type'  => 'application/json',
				),
				'body'        => wp_json_encode( array( 'purge_everything' => true ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $status && is_array( $body ) && ! empty( $body['success'] ) ) {
			return true;
		}

		$message = __( 'Cloudflare rejected the cache purge.', 'vyompress-boost' );
		if ( is_array( $body ) && isset( $body['errors'][0]['message'] ) ) {
			$message = sanitize_text_field( (string) $body['errors'][0]['message'] );
		}

		return new WP_Error( 'vyompress_cloudflare_api_error', $message, array( 'status' => $status ) );
	}
}
