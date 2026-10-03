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
 * Manages cache purges and one plugin-owned Cloudflare Cache Rule.
 */
final class CloudflareClient {
	private const API_BASE         = 'https://api.cloudflare.com/client/v4';
	private const RULE_DESCRIPTION = 'VyomPress Boost edge page cache';
	private const CACHE_PHASE      = 'http_request_cache_settings';

	/**
	 * Purge all cached content for one zone.
	 *
	 * @param string $zone_id   Cloudflare zone identifier.
	 * @param string $api_token Scoped Cloudflare API token.
	 * @return true|WP_Error
	 */
	public function purgeEverything( string $zone_id, string $api_token ): bool|WP_Error {
		$result = $this->apiRequest( 'POST', '/zones/' . rawurlencode( $zone_id ) . '/purge_cache', $api_token, array( 'purge_everything' => true ) );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Purge a bounded list of absolute URLs.
	 *
	 * @param string        $zone_id   Cloudflare zone identifier.
	 * @param string        $api_token Scoped Cloudflare API token.
	 * @param array<string> $urls      Absolute public URLs.
	 * @return true|WP_Error
	 */
	public function purgeUrls( string $zone_id, string $api_token, array $urls ): bool|WP_Error {
		$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
		if ( array() === $urls ) {
			return true;
		}

		foreach ( array_chunk( $urls, 30 ) as $chunk ) {
			$result = $this->apiRequest( 'POST', '/zones/' . rawurlencode( $zone_id ) . '/purge_cache', $api_token, array( 'files' => $chunk ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Create or update the plugin-owned edge cache rule without replacing other rules.
	 *
	 * @param string        $zone_id     Cloudflare zone identifier.
	 * @param string        $api_token   Scoped Cloudflare API token.
	 * @param string        $hostname    Site hostname.
	 * @param int           $edge_ttl    Edge cache lifetime.
	 * @param array<string> $excluded    Root-relative excluded paths.
	 * @param bool          $cache_query Whether meaningful query strings are cacheable.
	 * @param bool          $mobile      Whether the cache key varies by device type.
	 * @return true|WP_Error
	 */
	public function syncEdgeRule(
		string $zone_id,
		string $api_token,
		string $hostname,
		int $edge_ttl,
		array $excluded,
		bool $cache_query,
		bool $mobile
	): bool|WP_Error {
		if ( '' === $zone_id || '' === $api_token || '' === $hostname ) {
			return new WP_Error( 'vyompress_cloudflare_missing_credentials', __( 'Add a zone ID and API token first.', 'vyompress-boost' ) );
		}

		$rule      = $this->edgeRule( $hostname, $edge_ttl, $excluded, $cache_query, $mobile );
		$ruleset   = $this->entrypoint( $zone_id, $api_token );
		$not_found = is_wp_error( $ruleset ) && 404 === (int) $ruleset->get_error_data( 'vyompress_cloudflare_api_error' );

		if ( $not_found ) {
			$result = $this->apiRequest(
				'POST',
				'/zones/' . rawurlencode( $zone_id ) . '/rulesets',
				$api_token,
				array(
					'name'        => 'VyomPress Boost cache rules',
					'description' => 'Rules managed by the VyomPress Boost WordPress plugin.',
					'kind'        => 'zone',
					'phase'       => self::CACHE_PHASE,
					'rules'       => array( $rule ),
				)
			);

			return is_wp_error( $result ) ? $result : true;
		}

		if ( is_wp_error( $ruleset ) ) {
			return $ruleset;
		}

		$ruleset_id = sanitize_text_field( (string) ( $ruleset['id'] ?? '' ) );
		if ( '' === $ruleset_id ) {
			return new WP_Error( 'vyompress_cloudflare_ruleset_missing', __( 'Cloudflare returned an invalid cache ruleset.', 'vyompress-boost' ) );
		}

		$rule_id = $this->managedRuleId( $ruleset );
		$path    = '/zones/' . rawurlencode( $zone_id ) . '/rulesets/' . rawurlencode( $ruleset_id ) . '/rules';
		$method  = 'POST';
		if ( '' !== $rule_id ) {
			$method = 'PATCH';
			$path  .= '/' . rawurlencode( $rule_id );
		}

		$result = $this->apiRequest( $method, $path, $api_token, $rule );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Remove only the cache rule owned by this plugin.
	 *
	 * @param string $zone_id   Cloudflare zone identifier.
	 * @param string $api_token Scoped Cloudflare API token.
	 * @return true|WP_Error
	 */
	public function removeEdgeRule( string $zone_id, string $api_token ): bool|WP_Error {
		$ruleset = $this->entrypoint( $zone_id, $api_token );
		if ( is_wp_error( $ruleset ) ) {
			return 404 === (int) $ruleset->get_error_data( 'vyompress_cloudflare_api_error' ) ? true : $ruleset;
		}

		$rule_id = $this->managedRuleId( $ruleset );
		if ( '' === $rule_id ) {
			return true;
		}

		$result = $this->apiRequest(
			'DELETE',
			'/zones/' . rawurlencode( $zone_id ) . '/rulesets/' . rawurlencode( (string) $ruleset['id'] ) . '/rules/' . rawurlencode( $rule_id ),
			$api_token
		);

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Fetch the cache-phase entry point.
	 *
	 * @param string $zone_id   Cloudflare zone identifier.
	 * @param string $api_token Scoped Cloudflare API token.
	 * @return array<string,mixed>|WP_Error
	 */
	private function entrypoint( string $zone_id, string $api_token ): array|WP_Error {
		return $this->apiRequest( 'GET', '/zones/' . rawurlencode( $zone_id ) . '/rulesets/phases/' . self::CACHE_PHASE . '/entrypoint', $api_token );
	}

	/**
	 * Build a conservative WordPress edge cache rule.
	 *
	 * @param string        $hostname    Site hostname.
	 * @param int           $edge_ttl    Edge cache lifetime.
	 * @param array<string> $excluded    Root-relative excluded paths.
	 * @param bool          $cache_query Whether meaningful query strings are cacheable.
	 * @param bool          $mobile      Whether the cache key varies by device type.
	 * @return array<string,mixed>
	 */
	private function edgeRule( string $hostname, int $edge_ttl, array $excluded, bool $cache_query, bool $mobile ): array {
		$conditions = array(
			'http.host eq ' . $this->expressionString( strtolower( $hostname ) ),
			'http.request.method in {"GET" "HEAD"}',
			'not starts_with(http.request.uri.path, "/wp-admin")',
			'not starts_with(http.request.uri.path, "/wp-login.php")',
			'not starts_with(http.request.uri.path, "/wp-json/")',
			'not http.cookie contains "wordpress_logged_in_"',
			'not http.cookie contains "comment_author_"',
			'not http.cookie contains "woocommerce_items_in_cart"',
			'not http.cookie contains "wp_woocommerce_session_"',
			'not http.cookie contains "PHPSESSID"',
		);

		if ( ! $cache_query ) {
			$conditions[] = 'http.request.uri.query eq ""';
		}

		foreach ( $excluded as $path ) {
			$path = '/' . ltrim( (string) $path, '/' );
			if ( '/' !== $path ) {
				$conditions[] = 'not starts_with(http.request.uri.path, ' . $this->expressionString( $path ) . ')';
			}
		}

		$ttl = max( 7200, $edge_ttl );

		return array(
			'action'            => 'set_cache_settings',
			'action_parameters' => array(
				'cache'       => true,
				'edge_ttl'    => array(
					'mode'            => 'override_origin',
					'default'         => $ttl,
					'status_code_ttl' => array(
						array(
							'status_code_range' => array( 'to' => 299 ),
							'value'             => $ttl,
						),
						array(
							'status_code_range' => array(
								'from' => 300,
								'to'   => 499,
							),
							'value'             => 0,
						),
						array(
							'status_code_range' => array( 'from' => 500 ),
							'value'             => -1,
						),
					),
				),
				'browser_ttl' => array( 'mode' => 'respect_origin' ),
				'cache_key'   => array(
					'cache_by_device_type'       => $mobile,
					'cache_deception_armor'      => true,
					'ignore_query_strings_order' => true,
				),
			),
			'expression'        => '(' . implode( ' and ', $conditions ) . ')',
			'description'       => self::RULE_DESCRIPTION,
			'enabled'           => true,
		);
	}

	/**
	 * Locate the plugin-owned rule in an entry point response.
	 *
	 * @param array<string,mixed> $ruleset Cloudflare ruleset.
	 */
	private function managedRuleId( array $ruleset ): string {
		$rules = isset( $ruleset['rules'] ) && is_array( $ruleset['rules'] ) ? $ruleset['rules'] : array();
		foreach ( $rules as $rule ) {
			if ( is_array( $rule ) && self::RULE_DESCRIPTION === ( $rule['description'] ?? '' ) ) {
				return sanitize_text_field( (string) ( $rule['id'] ?? '' ) );
			}
		}

		return '';
	}

	/**
	 * Quote a string for a Cloudflare rules expression.
	 *
	 * @param string $value Raw string.
	 */
	private function expressionString( string $value ): string {
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value ) . '"';
	}

	/**
	 * Perform an authenticated JSON request and return its result object.
	 *
	 * @param string              $method    HTTP method.
	 * @param string              $path      API path.
	 * @param string              $api_token Scoped API token.
	 * @param array<string,mixed> $payload   Optional JSON body.
	 * @return array<string,mixed>|WP_Error
	 */
	private function apiRequest( string $method, string $path, string $api_token, array $payload = array() ): array|WP_Error {
		if ( '' === $api_token ) {
			return new WP_Error( 'vyompress_cloudflare_missing_credentials', __( 'Add a zone ID and API token first.', 'vyompress-boost' ) );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => 20,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $api_token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( array() !== $payload ) {
			$args['body'] = wp_json_encode( $payload );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status >= 200 && $status < 300 && is_array( $body ) && ! empty( $body['success'] ) ) {
			return isset( $body['result'] ) && is_array( $body['result'] ) ? $body['result'] : array();
		}

		$message = __( 'Cloudflare rejected the request.', 'vyompress-boost' );
		if ( is_array( $body ) && isset( $body['errors'][0]['message'] ) ) {
			$message = sanitize_text_field( (string) $body['errors'][0]['message'] );
		}

		return new WP_Error( 'vyompress_cloudflare_api_error', $message, $status );
	}
}
